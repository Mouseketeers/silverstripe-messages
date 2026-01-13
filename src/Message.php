<?php

class Message extends DataObject {

	private static $table_name = 'Message';

	private static $singular_name = 'Message';
	private static $plural_name = 'Messages';

	private static $db = array(
		'Title' => 'Varchar(255)',
		'Body' => 'HTMLText',
        'IsRead' => 'Boolean',
        'SendEmail' => 'Boolean',
        'SendPushNoticification' => 'Boolean',
		'IsSent' => 'Boolean',
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
		'Subsite.Title' => 'Site',
		'Recipient' => 'Recipient',
		'Title' => 'Title',
		'DateSent.Nice' => 'Sent',
	];

	private static $default_sort = 'DateSent DESC, Created DESC';

	private static $defaults = [
		'SendEmail' => true,
		'SendPushNoticification' => false,
		'IsSent' => false,
	];

	private static $field_labels = [
		'SendEmail' => 'Send Message as Email',
		'SendPushNoticification' => 'Send Message as Push Notification',
	];		

    public function populateDefaults()
    {
        if(class_exists('Subsite')) {
            $this->SubsiteID = Subsite::currentSubsiteID();
        }
		$this->IsRead = false;
		parent::populateDefaults();
	}	

	public function getRecipient() {
		if(!$this->Member()->exists()) {
			return 'Recipient not found';
		}
		return $this->Member()->Name . ' (' . $this->Member()->Email . ')';
	}
	public function getCMSFields() {
		
		$fields = parent::getCMSFields();

		$fields->removeByName('IsSent');
		$fields->removeByName('GroupMessageID');
		$fields->removeByName('MemberID');

		if($this->IsSent) {
			$fields->replaceField('IsRead', ReadonlyField::create('IsRead', 'Is Read'));
		}
		else {
			$fields->removeByName('IsRead');
		}		

		if ($this->DateSent) {
			$fields->insertBefore(
				'Title',
				ReadonlyField::create('DateSent', 'Sent')
			);
		}
		else {
			$fields->removeByName('DateSent');
		}


		if(!$this->IsSent) {
			$members = Member::get()->sort('Created DESC');
			if($members) {
				$membersMap = [];
				foreach ($members as $member) {
					$membersMap[$member->ID] = $member->Email . ' (' . $member->FirstName . ' ' . $member->Surname . ')';
				}
				$fields->insertBefore('Title',
					DropdownField::create('MemberID', 'Recipient')
						->setSource($membersMap)
						->setEmptyString('None')
						->setDisabled(false)
				);
			}
		}
		else {
			$fields->insertBefore('Title',
				ReadonlyField::create('Recipient', 'Recipient', $this->getRecipient())
			);	

		}


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
	
	public function process($data = null, $form = null) {

		if($form) {
			$form->saveInto($this);
		}
		$this->IsSent = true;
		$this->DateSent = SS_Datetime::now()->Rfc2822();
		$this->write();

		if($this->Member()->exists()) {
			if($this->SendEmail) {
				$emailResult = $this->sendEmail();
				if($emailResult !== true) {
					// Log error and potentially show user feedback
					SS_Log::log('Failed to send email for Message ID ' . $this->ID . ': ' . $emailResult, SS_Log::ERR);
					if($form) {
						$form->sessionMessage('Message saved but email failed to send: ' . $emailResult, 'warning');
					}
				} else {
					if($form) {
						$form->sessionMessage('Message sent successfully via email', 'good');
					}
				}
			}
			if($this->SendPushNoticification) {
				$pushResult = $this->sendPushNotification();
				if($pushResult !== true) {
					SS_Log::log('Failed to send push notification for Message ID ' . $this->ID, SS_Log::ERR);
					if($form) {
						$form->sessionMessage('Push notification failed to send', 'warning');
					}
				}
			}			
			return true;
		}
		return false;
	}	
	
	public function sendEmail() {
		try {
			// Validate SubsiteID exists
			if(!$this->SubsiteID) {
				return 'Cannot send email: No Subsite selected for this message';
			}

			// Get site configuration
			$siteConfig = SiteConfig::get()->filter('SubsiteID', $this->SubsiteID)->first();
			
			if(!$siteConfig) {
				return 'Cannot send email: Site configuration not found for SubsiteID ' . $this->SubsiteID;
			}

			// Validate DefaultFromEmail exists
			$defaultFromEmail = $siteConfig->DefaultFromEmail;
			if(!$defaultFromEmail) {
				return 'Cannot send email: No default from email address configured for this site';
			}

			// Validate recipient
			$recipient = $this->Member();
			if(!$recipient || !$recipient->exists()) {
				return 'Cannot send email: No valid recipient found';
			}
			
			if(!$recipient->Email) {
				return 'Cannot send email: No recipient email address';
			}

			// Create and configure email
			$email = new Email();
			$email->setFrom($defaultFromEmail);
			$email->setTo($recipient->Email);
			$email->setSubject($this->Title);

			$templateData = array(
				'FirstName' => $recipient->FirstName,
				'Surname' => $recipient->Surname,
				'Body' => $this->Body,
				'Image' => $this->Image(),
				'Video' => $this->Video()
			);

			// Set theme based on subsite
			$theme = 'garia'; // Default theme
			$subsite = DataObject::get_by_id('Subsite', $this->SubsiteID);
			if ($subsite && $subsite->Theme) {
				$theme = $subsite->Theme;
			}
			SSViewer::set_theme($theme);
			Config::inst()->update('SSViewer', 'theme_enabled', true);

			$email->setTemplate('MessageEmail');
			$email->populateTemplate($templateData);

			// Send email and handle result
			$result = $email->send();
			if($result) {
				return true;
			} else {
				return 'Failed to send email - email service returned false';
			}
			
		} catch(Exception $e) {
			return 'Email sending failed with exception: ' . $e->getMessage();
		}
	}
	public function sendPushNotification() {
		
		$recipient = $this->Member();

		if (!$recipient || !$recipient->PushNotificationToken) {
			return false;
		}

		// Path to your service account JSON file
		$serviceAccountFile = BASE_PATH . '/app/garia-app-4e6f084e2a08.json';

		// Get Google OAuth2 access token
		$accessToken = $this->getGoogleAccessToken($serviceAccountFile);

		if (!$accessToken) {
			return false;
		}

		// Your Firebase project ID
		$projectId = 'garia-app'; // Replace with your project ID

		$url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

		$unreadMessages = Message::get()
			->filter([
				'MemberID' => $recipient->ID,
				'IsRead' => false
			])
			->count();

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
				],
				'apns' => [
					'payload' => [
						'aps' => [
							'badge' => $unreadMessages
						]
					]
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

		if ($httpCode != 200) {
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

		$json = json_decode($result, true);
		return isset($json['access_token']) ? $json['access_token'] : null;
	}
	public function onAfterSerialize(&$formattedDataObjectMap) {
		$formattedDataObjectMap['SentAgo'] = $this->dbObject('DateSent')->Ago();
		$formattedDataObjectMap['SentShort'] = $this->dbObject('DateSent')->Ago();
	}
}