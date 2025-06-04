<?php

// use Kreait\Firebase\Factory;
// use Kreait\Firebase\Messaging\CloudMessage;

// require '/vendor/autoload.php';


class Message extends DataObject {




	private static $table_name = 'Message';

	private static $singular_name = 'Message';
	private static $plural_name = 'Messages';

	private static $db = array(
		'Title' => 'Varchar(255)',
		'Body' => 'HTMLText',
        'IsRead' => 'Boolean',
        'SendAsEmail' => 'Boolean',
        'SendAsPushNoticification' => 'Boolean',
		'DateSent' => 'SS_Datetime'
	);

	private static $has_one = array(
        'Member' => 'Member',
		'GroupMessage' => 'GroupMessage',
		'Image' => 'Image',
		'Video' => 'File',
		'Subsite' => 'Subsite'
	);

    private static $summary_fields = [
		'Recipient' => 'Recipient',
		'Title' => 'Title',
		'DateSent.Nice' => 'Sent',
		'Subsite.Title' => 'Site'
	];

	private static $default_sort = 'Created DESC';

    public function populateDefaults()
    {
        if(class_exists('Subsite')) {
            $this->SubsiteID = Subsite::currentSubsiteID();
        }
		parent::populateDefaults();
	}	

	public function getRecipient() {
		return $this->Member()->Name . ' (' . $this->Member()->Email . ')';
	}
	public function getCMSFields() {
		
		$fields = parent::getCMSFields();

		$isSent = $this->DateSent ? true : false;

		if ($this->DateSent) {
			$fields->insertBefore(
				'Title',
				ReadonlyField::create('DateSent', 'Sent')
			);
		}
		else {
			$fields->removeByName('DateSent');
		}		

		$members = Member::get()->sort('Created DESC');
		if($members) {
			$membersMap = [];
			foreach ($members as $member) {
				$membersMap[$member->ID] = $member->FirstName . ' ' . $member->Surname . ' (' . $member->Email . ')';
			}
			$fields->insertBefore('Title',
				DropdownField::create('MemberID', 'Recipient')
					->setSource($membersMap)
					->setEmptyString('None')
					->setDisabled(false)
			);
		}
		// if($isSent) {
		// 	$fields->replaceField('IsRead', ReadonlyField::create('IsRead', 'Is Read'));
		// }
		// else {
		// 	$fields->removeByName('IsRead');
		// }

        if(class_exists('Subsite')) {
            $subsites = Subsite::all_sites();
            $fields->insertBefore(
                DropdownField::create(
                    'SubsiteID', 
                    'Send from Site', 
                    $subsites->map('ID', 'Title')
                ),
                'MemberID'
            );
        }
        else {
            $fields->removeByName('SubsiteID');
        }		

		$fields->removeByName('GroupMessageID');

		return $fields;
	}
	public function getCMSValidator() {
        $requiredFields = RequiredFields::create(
            array(
				'Title',
				'Body',
				'MemberID'
            )
        );
        if(class_exists('Subsite')) {
            $requiredFields->addRequiredField('SubsiteID');
        }
        return $requiredFields;
	}	
	
	public function sendAsEmail() {

		$siteConfig = SiteConfig::current_site_config();
		$defaultFromEmail = $siteConfig->DefaultFromEmail;
		$receipient = $this->Member();

		if($defaultFromEmail)	{
			$email = new Email();
			$email->setFrom($defaultFromEmail);
			$email->setTo($receipient->Email);
			$email->setSubject($this->Title);

			$templateData = array(
				'FirstName' => $receipient->FirstName,
				'Surname' => $receipient->Surname,
				'Body' => $this->Body,
				'Image' => $this->Image(),
				'Video' => $this->Video()
			);			

			$theme = 'garia';
			$subsite = DataObject::get_by_id('Subsite', $this->SubsiteID);
            if ($subsite && $subsite->Theme) {
				$theme = $subsite->Theme;
            }
			SSViewer::set_theme($theme);
			Config::inst()->update('SSViewer', 'theme_enabled', true); // otherwise ss will fail

			$email->setTemplate('MessageEmail');
			$email->populateTemplate($templateData);			

			return $email->send();
		}
	}
	public function sendAsPushNotification() {
		$recipient = $this->Member();

		if (!$recipient || !$recipient->PushNotificationToken) {
			return false;
		}

		// Path to your service account JSON file
		$serviceAccountFile = BASE_PATH . '/app/garia-app-4e6f084e2a08.json';

		// Get Google OAuth2 access token
		$accessToken = $this->getGoogleAccessToken($serviceAccountFile);

		if (!$accessToken) {
			SS_Log::warn('No access token for FCM push notification');
			return false;
		}

		// Your Firebase project ID
		$projectId = 'garia-app'; // Replace with your project ID

		$url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

		$message = [
			'message' => [
				'token' => $recipient->PushNotificationToken,
				'notification' => [
					'title' => 'New Message from Garia',
					'body' => $this->Title,
				],
				'data' => [
					'message_id' => (string)$this->ID,
					'url' => '/message/' . $this->ID
				]
			]
		];

		$payload = json_encode($message);

		$headers = [
			"Authorization: Bearer $accessToken",
			"Content-Type: application/json"
		];

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		SS_Log::log("Bearer $accessToken", SS_Log::INFO);
		SS_Log::log("Sending FCM push to: $url", SS_Log::INFO);
		SS_Log::log("Payload: " . $payload, SS_Log::INFO);
		SS_Log::log("FCM response: " . $response, SS_Log::INFO);

		if ($httpCode != 200) {
			// SS_Log::warn('Push notification failed: ' . $response);
			return false;
		}

		return true;
	}

	

	/**
	 * Get Google OAuth2 access token from service account JSON
	 */
	protected function getGoogleAccessToken($serviceAccountFile) {
		$jwtHeader = ['alg' => 'RS256', 'typ' => 'JWT'];
		$now = time();
		$serviceAccount = json_decode(file_get_contents($serviceAccountFile), true);

		$jwtClaimSet = [
			'iss' => $serviceAccount['client_email'],
			'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
			'aud' => 'https://oauth2.googleapis.com/token',
			'iat' => $now,
			'exp' => $now + 3600,
		];

		$base64UrlEncode = function($data) {
			return rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
		};

		$header = $base64UrlEncode($jwtHeader);
		$claims = $base64UrlEncode($jwtClaimSet);
		openssl_sign("{$header}.{$claims}", $signature, $serviceAccount['private_key'], 'SHA256');
		$jwt = "{$header}.{$claims}." . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

		// Exchange JWT for access token
		$postFields = http_build_query([
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'assertion' => $jwt,
		]);

		$ch = curl_init('https://oauth2.googleapis.com/token');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
		$result = curl_exec($ch);
		curl_close($ch);

		SS_Log::log("Google OAuth token response: " . $result, SS_Log::INFO);

		$json = json_decode($result, true);
		return isset($json['access_token']) ? $json['access_token'] : null;
	}
	public function onAfterSerialize(&$formattedDataObjectMap) {
		$formattedDataObjectMap['SentAgo'] = $this->dbObject('DateSent')->Ago();
	}
	public function process($data = null, $form = null) {


		if($form) {
			$form->saveInto($this);
		}
		$this->DateSent = SS_Datetime::now()->Rfc2822();
		$this->write();

		if($this->Member()->exists()) {
			if($this->SendAsEmail) {
				$this->SendAsEmail();
			}
			if($this->SendAsPushNoticification) {
				$this->sendAsPushNotification();
			}			
			return true;
		}
		return false;
	}

}