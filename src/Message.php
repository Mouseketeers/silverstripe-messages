<?php

class Message extends DataObject
{

	private static $table_name = 'Message';

	private static $singular_name = 'Message';
	private static $plural_name = 'Messages';

	/**
	 * Firebase configuration
	 */
	private static $firebase_project_id = '';
	private static $firebase_service_account_path = '';
	private static $push_notification_title = 'New Message';

	private static $db = [
		'Title' => 'Varchar(255)',
		'Body' => 'HTMLText',
		'IsRead' => 'Boolean',
		'SendAsEmail' => 'Boolean',
		'SendPushNotification' => 'Boolean',
		'IsSent' => 'Boolean',
		'DateSent' => 'SS_Datetime',
		'FromEmail' => 'Varchar(255)'
	];

	private static $has_one = [
		'Member' => 'Member',
		'GroupMessage' => 'GroupMessage',
		'Image' => 'Image',
		'Video' => 'File',
		'Subsite' => 'Subsite'
	];

	private static $summary_fields = [
		'SummarySubsiteTitle' => 'Site',
		'Recipient' => 'Recipient',
		'Title' => 'Title',
		'DateSent.Nice' => 'Sent',
	];

	private static $default_sort = 'DateSent DESC, Created DESC';

	private static $indexes = [
		'IsSent' => true,
		'IsRead' => true,
		'DateSent' => true
	];

	private static $defaults = [
		'SendAsEmail' => true,
		'SendPushNotification' => false,
		'IsSent' => false,
	];

	private static $field_labels = [
		'SendAsEmail' => 'Send Message as Email',
		'SendPushNotification' => 'Send Push Notification to App Users',
	];

	public function populateDefaults()
	{
		if (class_exists('Subsite')) {
			$this->SubsiteID = Subsite::currentSubsiteID();
		}
		$this->IsRead = false;
		parent::populateDefaults();
	}

	public function getSummarySubsiteTitle()
	{
		if ($this->SubsiteID == 0) {
			return 'Main Site';
		}
		return $this->Subsite()->Title;
	}

	public function getRecipient()
	{
		if (!$this->Member()->exists()) {
			return 'Recipient not found';
		}
		return $this->Member()->Name . ' (' . $this->Member()->Email . ')';
	}


	public function getCMSFields()
	{

		$fields = parent::getCMSFields();

		$fields->removeByName('IsSent');
		$fields->removeByName('GroupMessageID');
		$fields->removeByName('MemberID');

		// // Configure HTMLEditor to disable default editor CSS
		// $bodyField = $fields->dataFieldByName('Body');
		// if ($bodyField) {
		// 	$config = HtmlEditorConfig::get('message_editor');
		// 	$config->setOption('content_css', '');
		// 	// $bodyField->setRows(20);
		// 	$bodyField->setAttribute('data-config', 'message_editor');
		// }

		if ($this->IsSent) {
			$fields->replaceField('IsRead', ReadonlyField::create('IsRead', 'Is Read'));
		} else {
			$fields->removeByName('IsRead');
		}

		if ($this->DateSent) {
			$fields->insertBefore(
				'Title',
				ReadonlyField::create('DateSent', 'Sent')
			);
		} else {
			$fields->removeByName('DateSent');
		}

		if (!$this->IsSent) {
			$members = Member::get()->sort('Created DESC');
			if ($members) {
				$membersMap = [];
				foreach ($members as $member) {
					$membersMap[$member->ID] = $member->Email . ' (' . $member->FirstName . ' ' . $member->Surname . ')';
				}
				$fields->insertBefore(
					'Title',
					DropdownField::create('MemberID', 'Recipient')
						->setSource($membersMap)
						->setEmptyString('None')
						->setDisabled(false)
				);
			}
		} else {
			$fields->insertBefore(
				'Title',
				ReadonlyField::create('Recipient', 'Recipient', $this->getRecipient())
			);
		}

		if (class_exists('Subsite')) {
			$subsites = Subsite::all_sites();
			$fields->insertBefore(
				DropdownField::create(
					'SubsiteID',
					'Send from Site',
					$subsites->map('ID', 'Title')
				),
				'Title'
			);
		} else {
			$fields->removeByName('SubsiteID');
		}

		// Add FromEmail dropdown
		$fromEmails = Config::inst()->get('Messages', 'from_emails');
		if ($fromEmails && count($fromEmails) > 0) {
			// Convert array to key-value pairs for dropdown
			$emailOptions = [];
			foreach ($fromEmails as $email) {
				$emailOptions[$email] = $email;
			}
			$fields->insertBefore(
				'SubsiteID',
				DropdownField::create(
					'FromEmail',
					'From Email',
					$emailOptions
				)->setEmptyString('None')
			);
		}

		// Hide push notification toggle if Firebase is not configured
		if (!self::isPushNotificationsConfigured()) {
			$fields->removeByName('SendPushNotification');
		}

		return $fields;
	}

	public function getCMSValidator()
	{
		return MessageFormValidator::create(
			array(
				'Title',
				'Body',
				'MemberID'
			)
		);
	}

	public function process($data = null, $form = null)
	{

		if ($form) {
			$form->saveInto($this);
		}
		$this->IsSent = true;
		$this->DateSent = SS_Datetime::now()->Rfc2822();
		$this->write();

		if ($this->Member()->exists()) {
			if ($this->SendAsEmail) {
				$emailResult = $this->sendEmail();
				if ($emailResult !== true) {
					// Log error and potentially show user feedback
					SS_Log::log('Failed to send email for Message ID ' . $this->ID . ': ' . $emailResult, SS_Log::ERR);
					if ($form) {
						$form->sessionMessage('Message saved but email failed to send: ' . $emailResult, 'warning');
					}
				} else {
					if ($form) {
						$form->sessionMessage('Message sent successfully via email', 'good');
					}
				}
			}
			if ($this->SendPushNotification) {
				$pushResult = $this->sendPushNotification();
				if ($pushResult !== true) {
					SS_Log::log('Failed to send push notification for Message ID ' . $this->ID, SS_Log::ERR);
					if ($form) {
						$form->sessionMessage('Push notification failed to send', 'warning');
					}
				}
			}
			return true;
		}
		return false;
	}

	public function sendEmail()
	{
		try {
			// Validate all requirements
			$validation = $this->validateEmailRequirements();
			if ($validation !== true) {
				return $validation;
			}

			// Get validated data
			$recipient = $this->Member();

			// Create and configure email
			$email = new Email();
			$email->setFrom($this->FromEmail);
			$email->setTo($recipient->Email);
			$email->setSubject($this->Title);

			// Get template data
			$templateData = $this->buildEmailTemplateData($recipient);

			// Set theme for email templates if one is determined
			$theme = $this->getTheme();
			if ($theme) {
				SSViewer::set_theme($theme);
				Config::inst()->update('SSViewer', 'theme_enabled', true);
			}

			// Set template and populate
			$email->setTemplate('MessageEmail');
			$email->populateTemplate($templateData);

			// Send email and handle result
			$result = $email->send();
			if ($result) {
				return true;
			} else {
				return 'Failed to send email - email service returned false';
			}
		} catch (Exception $e) {
			return 'Email sending failed with exception: ' . $e->getMessage();
		}
	}

	/**
	 * Validate all requirements for sending email
	 * @return true|string Returns true if valid, error message if not
	 */
	protected function validateEmailRequirements()
	{
		// Validate SubsiteID exists (0 is valid for main site)
		if ($this->SubsiteID === null || $this->SubsiteID === '') {
			return 'Cannot send email: No Subsite selected for this message';
		}

		// Validate FromEmail is set
		if (!$this->FromEmail) {
			return 'Cannot send email: No from email address specified';
		}

		// Validate recipient
		$recipient = $this->Member();
		if (!$recipient || !$recipient->exists()) {
			return 'Cannot send email: No valid recipient found';
		}

		if (!$recipient->Email) {
			return 'Cannot send email: No recipient email address';
		}

		return true;
	}

	/**
	 * Build template data array for email
	 * @param Member $recipient
	 * @return array
	 */
	protected function buildEmailTemplateData($recipient)
	{
		// Parse shortcodes with correct domain for emails
		$parsedBody = $this->Body;
		if (class_exists('Subsite')) {
			$subsite = DataObject::get_by_id('Subsite', $this->SubsiteID);
			if ($subsite && $subsite->PrimaryDomain) {
				// Temporarily set the base URL for shortcode parsing
				$originalBaseURL = Director::baseURL();
				Director::setBaseURL('http://' . $subsite->PrimaryDomain . '/');
				$parsedBody = ShortcodeParser::get_active()->parse($this->Body);
				Director::setBaseURL($originalBaseURL);
			} else {
				$parsedBody = ShortcodeParser::get_active()->parse($this->Body);
			}
		} else {
			$parsedBody = ShortcodeParser::get_active()->parse($this->Body);
		}

		return [
			'FirstName' => $recipient->FirstName,
			'Surname' => $recipient->Surname,
			'Body' => $parsedBody,
			'Image' => $this->Image(),
			'Video' => $this->Video()
		];
	}

	/**
	 * Get the appropriate theme for email rendering
	 * @return string
	 */
	protected function getTheme()
	{
		// First check if subsite has a specific theme
		$subsite = DataObject::get_by_id('Subsite', $this->SubsiteID);
		if ($subsite && $subsite->Theme) {
			return $subsite->Theme;
		}

		// Try to get the currently active theme
		$currentTheme = Config::inst()->get('SSViewer', 'theme');
		if ($currentTheme) {
			return $currentTheme;
		}

		// If no theme found, return null to use framework defaults
		return null;
	}

	public function sendPushNotification()
	{

		$recipient = $this->Member();

		if (!$recipient || !$recipient->PushNotificationToken) {
			return false;
		}

		// Get Firebase configuration
		$projectId = $this->config()->get('firebase_project_id');
		$serviceAccountFile = $this->config()->get('firebase_service_account_path');

		if (!$projectId || !$serviceAccountFile) {
			return false;
		}

		// Fallback to BASE_PATH if relative path
		if (substr($serviceAccountFile, 0, 1) !== '/') {
			$serviceAccountFile = BASE_PATH . '/' . $serviceAccountFile;
		}

		if (!file_exists($serviceAccountFile)) {
			return false;
		}

		// Get Google OAuth2 access token
		$accessToken = $this->getGoogleAccessToken($serviceAccountFile);

		if (!$accessToken) {
			return false;
		}

		$url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

		$unreadMessages = Message::get()
			->filter([
				'MemberID' => $recipient->ID,
				'IsRead' => false
			])
			->count();

		$notificationTitle = $this->config()->get('push_notification_title');

		$message = [
			'message' => [
				'token' => $recipient->PushNotificationToken,
				'notification' => [
					'title' => $notificationTitle,
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
	 * Determine if Firebase push is configured
	 */
	public static function isPushNotificationsConfigured()
	{
		$projectId = Config::inst()->get('Message', 'firebase_project_id');
		$serviceAccountFile = Config::inst()->get('Message', 'firebase_service_account_path');

		// Check if values exist and are not placeholder values
		return !empty($projectId) &&
			!empty($serviceAccountFile) &&
			$projectId !== 'your-firebase-project-id' &&
			$projectId !== 'your-project-id';
	}

	/**
	 * Get Google OAuth2 access token from service account JSON
	 */
	protected function getGoogleAccessToken($serviceAccountFile)
	{
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

		$base64UrlEncode = function ($data) {
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
	public function onAfterSerialize(&$formattedDataObjectMap)
	{
		$formattedDataObjectMap['SentAgo'] = $this->dbObject('DateSent')->Ago();
		$formattedDataObjectMap['SentShort'] = $this->dbObject('DateSent')->Ago();
	}
}
