<?php

namespace Mouseketeers\Messages;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\Forms\GridField\GridFieldAddExistingAutocompleter;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\View\SSViewer;
use SilverStripe\View\Parsers\ShortcodeParser;
use SilverStripe\View\ArrayData;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\QueuedJobService;



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
		'Image' => Image::class,
		'Video' => File::class
	];

	private static $many_many = [
		'Groups' => Group::class,
	];

	private static $has_many = [
		'Messages' => Message::class,
	];

	private static $default_sort = 'Created DESC';

	private static $summary_fields = [
		'Title',
		'Created.Nice' => 'Created'
	];

	private static $indexes = [
		'Label' => true,
	];

	public function summaryFields()
	{
		$fields = parent::summaryFields();

		if (class_exists(Subsite::class) && $this->hasMethod('Subsite')) {
			$fields['Subsite.Title'] = 'Site';
		}

		return $fields;
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
					->setSource($groupsMap)
					->setAttribute(
						'data-placeholder',
						'Add group'
					)
			);
		}

		if (class_exists('SilverStripe\Subsites\Model\Subsite')) {
			$subsites = Subsite::get();
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
			$config->removeComponentsByType(GridFieldAddNewButton::class);
			// Remove the "Add Existing" button
			$config->removeComponentsByType(GridFieldAddExistingAutocompleter::class);
			// Remove the unlink action
			$config->removeComponentsByType(GridFieldDeleteAction::class);
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
		if (class_exists('SilverStripe\Subsites\Model\Subsite')) {
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
