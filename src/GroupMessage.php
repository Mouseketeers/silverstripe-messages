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

	private static $default_sort = 'Created DESC';

	private static $summary_fields = [
		'Title',
		'Created.Nice' => 'Created',
		'Subsite.Title' => 'Site',
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
		if ($this->Groups()->exists()) {
			$groups = $this->Groups();
			$existingMessages = $this->Messages()->column('MemberID');
			foreach ($groups as $group) {
				$members = $group->Members();
				foreach ($members as $member) {
					if (!$existingMessages || !in_array($member->ID, $existingMessages)) {
						$this->processMessageToMember($member);
						sleep(1);
					}
				}
			}
		}
	}

	public function processMessageToMember($member)
	{
		$message = $this->createMessage($member);
		if ($message) {
			$message->process();
		}
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
		$job = Injector::inst()->create(ProcessMessagesJob::class);
		$job->GroupMessage = $this;
		singleton(QueuedJobService::class)->queueJob($job, date('Y-m-d H:i:s', time()));
	}
}
