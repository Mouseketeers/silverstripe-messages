<?php
class GroupMessage extends DataObject
{

	private static $table_name = 'GroupMessage';

	private static $singular_name = 'Group Message';
	private static $plural_name = 'Group Messages';

	private static $db = [
		'Title' => 'Varchar(100)',
		'Body' => 'HTMLText',
		'SendEmail' => 'Boolean',
		'SendPushNoticification' => 'Boolean',
		'IsSent' => 'Boolean',
		'Label' => 'Varchar(50)'
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
		'SendEmail' => true,
		'SendPushNoticification' => false,
		'IsSent' => false,
	];

	private static $field_labels = [
		'SendEmail' => 'Send Messages as Emails',
		'SendPushNoticification' => 'Send Messages as Push Notifications',
	];	

	private static $default_sort = 'Created DESC';

	private static $summary_fields = [
		'Subsite.Title' => 'Site',
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

	public function getCMSFields()
	{

		$fields = parent::getCMSFields();

		$fields->removeByName('Groups');
		$fields->removeByName('IsSent');
		$fields->removeByName('Label');


		$groups = Group::get();
		if ($groups) {
			$groupsMap = array();
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

		$fields->addFieldToTab(
			'Root.Main',
			TextField::create(
				'Label',
				'Label (optional)',
				$this->Label
			)->setDescription('Used for automated messages sent on specific occations, such as a new user registration.')
		);
		return $fields;
	}
	public function getCMSValidator()
	{
		$requiredFields = RequiredFields::create(
			array(
				'Title',
				'Body',
				'Groups'
			)
		);
		if (class_exists('Subsite')) {
			$requiredFields->addRequiredField('SubsiteID');
		}
		return $requiredFields;
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
			'push_notifications_sent' => 0
		];
		
		if ($this->Groups()->exists()) {
			$groups = $this->Groups();
			$existingMessages = $this->Messages()->column('MemberID');
			foreach ($groups as $group) {
				$members = $group->Members();
				foreach ($members as $member) {
					if (!$existingMessages || !in_array($member->ID, $existingMessages)) {
						$result = $this->processMessageToMember($member);
						if($result) {
							$stats['messages_created']++;
							if($result['email_sent']) {
								$stats['emails_sent']++;
							} elseif($result['email_attempted']) {
								$stats['emails_failed']++;
							}
							if($result['push_sent']) {
								$stats['push_notifications_sent']++;
							}
						}
						sleep(1);
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
			if($message->SendEmail) {
				$emailAttempted = true;
				$emailResult = $message->sendEmail();
			}
			
			if($message->SendPushNoticification) {
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
		$message->SendEmail = $this->SendEmail;
		$message->SendPushNoticification = $this->SendPushNoticification;
		$message->SubsiteID = $this->SubsiteID;
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
