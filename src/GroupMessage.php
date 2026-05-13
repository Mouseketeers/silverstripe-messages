<?php
class GroupMessage extends DataObject
{

	private static $table_name = 'GroupMessage';

	private static $singular_name = 'Group Message';
	private static $plural_name = 'Group Messages';

	private static $db = [
		'Title' => 'Varchar(100)',
		'Body' => 'HTMLText',
		'SendAsEmail' => 'Boolean',
		'SendPushNotification' => 'Boolean',
		'IsSent' => 'Boolean',
		'Label' => 'Varchar(50)',
		'FromEmail' => 'Varchar(255)'
	];

	private static $has_one = [
		'Image' => 'Image',
		'Video' => 'File',
		'Subsite' => 'Subsite'
	];

	private static $many_many = [
		'Groups' => 'Group',
	];

	private static $has_many = [
		'Messages' => 'Message',
	];

	private static $defaults = [
		'SendAsEmail' => true,
		'SendPushNotification' => false,
		'IsSent' => false,
	];

	private static $field_labels = [
		'SendAsEmail' => 'Send Messages as Emails',
		'SendPushNotification' => 'Send Push Notifications to App Users',
	];

	private static $default_sort = 'Created DESC';

	private static $summary_fields = [
		'SummarySubsiteTitle' => 'Site',
		'Title',
		'Created.Nice' => 'Created'
	];

	private static $indexes = [
		'Label' => true,
	];

	public function populateDefaults()
	{
		if (class_exists('Subsite')) {
			$this->SubsiteID = Subsite::currentSubsiteID();
		}
		parent::populateDefaults();
	}

	public function getSummarySubsiteTitle()
	{
		if ($this->SubsiteID == 0) {
			return 'Main Site';
		}
		return $this->Subsite()->Title;
	}	

	public function getCMSFields()
	{

		$fields = parent::getCMSFields();

		$fields->removeByName('Groups');
		$fields->removeByName('IsSent');
		$fields->removeByName('Label');


		$groups = Group::get();
		if ($groups) {
			$groupsMap = [];
			foreach ($groups as $group) {
				$groupsMap[$group->ID] = $group->getBreadcrumbs(' > ');
			}
			asort($groupsMap);
			$fields->insertBefore(
				'Title',
				ListboxField::create('Groups', 'Recipients')
					->setMultiple(true)
					->setSource($groupsMap)
					->setAttribute(
						'data-placeholder',
						'Add group'
					)
			);
		}

		if (class_exists('Subsite')) {
			$subsites = Subsite::all_sites();
			$fields->insertAfter(
				DropdownField::create(
					'SubsiteID',
					'Site',
					$subsites->map('ID', 'Title')
				),
				'Groups'
			);
		} else {
			$fields->removeByName('SubsiteID');
		}

		// Modify the related Messages GridField
		if ($messagesField = $fields->dataFieldByName('Messages')) {
			$config = $messagesField->getConfig();
			// Remove the "Add New" button
			$config->removeComponentsByType('GridFieldAddNewButton');
			// Remove the "Add Existing" button
			$config->removeComponentsByType('GridFieldAddExistingAutocompleter');
			// Remove the unlink action
			$config->removeComponentsByType('GridFieldDeleteAction');
			// Add the delete action
			$config->addComponent(new GridFieldDeleteAction());
		}

		// Add FromEmail dropdown
		$fromEmails = Config::inst()->get('Messages', 'from_emails');
		if ($fromEmails && count($fromEmails) > 0) {
			// Convert array to key-value pairs for dropdown
			$emailOptions = [];
			foreach ($fromEmails as $email) {
				$emailOptions[$email] = $email;
			}
			$fields->insertAfter(
				'SubsiteID',
				DropdownField::create(
					'FromEmail',
					'From Email',
					$emailOptions
				)->setEmptyString('None')
			);
		}

		// Hide push notification toggle if Firebase is not configured
		if (!Message::isPushNotificationsConfigured()) {
			$fields->removeByName('SendPushNotification');
		}

		$fields->addFieldToTab(
			'Root.Main',
			TextField::create(
				'Label',
				'Label (optional)',
				$this->Label
			)->setDescription('Used for automated messages sent on specific occasions, such as a new user registration.')
		);
		return $fields;
	}
	public function getCMSValidator()
	{
		return MessageFormValidator::create(
			[
				'Title',
				'Body',
				'Groups'
			]
		);
	}

	public function process($data = null, $form = null)
	{
		if ($form) {
			$form->saveInto($this);
		}
		$this->IsSent = true;
		$this->write();
		if ($this->Groups()->exists()) {
			$this->createJob();
			return true;
		}
		return false;
	}

	public function processMessages()
	{
		$stats = [
			'messages_created' => 0,
			'emails_sent' => 0,
			'emails_failed' => 0,
			'push_notifications_sent' => 0,
			'existing_messages' => 0
		];

		if ($this->Groups()->exists()) {
			$groups = $this->Groups();
			$existingMessages = $this->Messages()->column('MemberID');
			foreach ($groups as $group) {
				$members = $group->Members();
				foreach ($members as $member) {
					if (!$existingMessages || !in_array($member->ID, $existingMessages)) {
						$result = $this->processMessageToMember($member);
						if ($result) {
							$stats['messages_created']++;
							if ($result['email_sent']) {
								$stats['emails_sent']++;
							} elseif ($result['email_attempted']) {
								$stats['emails_failed']++;
							}
							if ($result['push_sent']) {
								$stats['push_notifications_sent']++;
							}
							$existingMessages[] = $member->ID; // Add to existing messages to prevent duplicates in the same run
						}
						sleep(1);
					} else {
						$stats['existing_messages']++;
					}
				}
			}
		}
		return $stats;
	}

	public function processMessageToMember($member)
	{
		$message = $this->createMessage($member);
		if ($message) {
			$emailResult = null;
			$pushResult = null;
			$emailAttempted = false;
			$pushAttempted = false;

			// Process the message and capture results
			if ($message->SendAsEmail) {
				$emailAttempted = true;
				$emailResult = $message->sendEmail();
			}

			if ($message->SendPushNotification) {
				$pushAttempted = true;
				$pushResult = $message->sendPushNotification();
			}

			// Mark as sent and save
			$message->IsSent = true;
			$message->DateSent = SS_Datetime::now()->Rfc2822();
			$message->write();

			return [
				'message' => $message,
				'email_sent' => $emailResult === true,
				'email_attempted' => $emailAttempted,
				'email_error' => $emailResult !== true ? $emailResult : null,
				'push_sent' => $pushResult === true,
				'push_attempted' => $pushAttempted
			];
		}
		return null;
	}

	public function createMessage($member)
	{
		if (!$member || !$member->ID) {
			return null;
		}

		$message = new Message();
		$message->MemberID = $member->ID;
		$message->GroupMessageID = $this->ID;
		$message->Title = $this->Title;
		$message->ImageID = $this->ImageID;
		$message->VideoID = $this->VideoID;
		$message->SendAsEmail = $this->SendAsEmail;
		$message->SendPushNotification = $this->SendPushNotification;
		$message->SubsiteID = $this->SubsiteID;
		$message->FromEmail = $this->FromEmail;
		$message->Body = SSViewer::execute_string(
			ShortcodeParser::get_active()->parse($this->Body),
			ArrayData::create([
				'FirstName' => $member->FirstName,
				'Surname' => $member->Surname
			])
		);
		$message->write();
		return $message;
	}

	private function createJob()
	{
		$job = new ProcessMessagesJob($this);
		singleton(QueuedJobService::class)->queueJob($job, date('Y-m-d H:i:s', time()));
	}
}
