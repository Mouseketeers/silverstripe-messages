<?php

namespace Mouseketeers\Messages;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Control\Email\Email;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Injector\Injector;
use Psr\Log\LoggerInterface;
use Mouseketeers\Messages\GroupMessage;

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
		'DateSent' => 'Datetime' // Change from 'SS_Datetime'
	);

	private static $has_one = array(
        'Member' => Member::class,
		'GroupMessage' => GroupMessage::class, // Make sure GroupMessage is also namespaced
		'Image' => Image::class,
		'Video' => File::class,
		'Subsite' => Subsite::class
	);

    private static $summary_fields = [
		'Recipient' => 'Recipient',
		'Title' => 'Title',
		'DateSent.Nice' => 'Sent',
		'Subsite.Title' => 'Site'
	];

	private static $default_sort = 'DateSent DESC, Created DESC';

    public function populateDefaults()
    {
        if(class_exists('SilverStripe\Subsites\Model\Subsite')) {
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


        if(class_exists('SilverStripe\Subsites\Model\Subsite')) {
            $subsites = Subsite::get();
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
        if(class_exists('SilverStripe\Subsites\Model\Subsite')) {
            $requiredFields->addRequiredField('SubsiteID');
        }
        return $requiredFields;
	}
	
	public function process($data = null, $form = null) {

		if($form) {
			$form->saveInto($this);
		}
		$this->IsSent = true;
		$this->DateSent = DBDatetime::now()->Rfc2822();
		$this->write();

		if($this->Member()->exists()) {
			if($this->SendEmail) {
				$this->SendEmail();
			}
			if($this->SendPushNoticification) {
				$this->sendPushNotification();
			}			
			return true;
		}
		return false;
	}	
	
	public function sendEmail() {

		$siteConfig = SiteConfig::current_site_config();
		$defaultFromEmail = $this->extractEmailAddress($siteConfig->DefaultFromEmail);
		$recipient = $this->Member();
		$recipientEmail = $this->extractEmailAddress($recipient->Email);

		if(!$defaultFromEmail || !$recipientEmail) {
			Injector::inst()->get(LoggerInterface::class)->warning(
				'Message email could not be sent due to invalid sender or recipient email address',
				[
					'MessageID' => $this->ID,
					'DefaultFromEmail' => $siteConfig->DefaultFromEmail,
					'RecipientEmail' => $recipient ? $recipient->Email : null,
				]
			);
			return false;
		}

		if($defaultFromEmail) {
			$email = Email::create()
				->setFrom($defaultFromEmail)
				->setTo($recipientEmail)
				->setSubject($this->Title)
				->setHTMLTemplate('Email/MessageEmail');

			$templateData = array(
				'FirstName' => $recipient->FirstName,
				'Surname' => $recipient->Surname,
				'Body' => $this->Body,
				'Image' => $this->Image(),
				'Video' => $this->Video()
			);

			$email->setData($templateData); // Changed from populateTemplate

			try {
				return $email->send();
			}
			catch (\Throwable $exception) {
				Injector::inst()->get(LoggerInterface::class)->error(
					'Failed sending message email',
					[
						'MessageID' => $this->ID,
						'Exception' => $exception->getMessage(),
					]
				);
				return false;
			}
		}

		return false;
	}

	protected function extractEmailAddress($address)
	{
		if (!$address) {
			return null;
		}

		$address = trim((string)$address);

		if (preg_match('/<([^>]+)>/', $address, $matches)) {
			$address = trim($matches[1]);
		}

		if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
			return $address;
		}

		return null;
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
			Injector::inst()->get(LoggerInterface::class)->warning('No access token for FCM push notification');
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
			Injector::inst()->get(LoggerInterface::class)->warning('FCM response error: ' . $response);
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