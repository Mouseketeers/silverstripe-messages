<?php

namespace Mouseketeers\Messages;

use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\RequiredFields;
use Mouseketeers\Messages\Service\MessageDispatcher;
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
		'IsSent' => 'Boolean',
		'Channels' => 'Varchar(255)',
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

	private static $default_channels = [];

	private static $summary_fields = [
		'Title',
		'Created.Nice' => 'Created'
	];

	private static $indexes = [
		'Label' => true,
	];

	public function getCMSFields()
	{
		// Build our fields before extensions run, so updateCMSFields() (e.g. the
		// subsites extension) sees the Recipients listbox rather than the
		// scaffolded Groups tab, which we remove.
		$this->beforeUpdateCMSFields(function (FieldList $fields) {
			$fields->removeByName('Groups');
			$fields->removeByName('IsSent');
			$fields->removeByName('Label');
			$fields->removeByName('Channels');


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
						->setSource($groupsMap)
						->setAttribute(
							'data-placeholder',
							'Add group'
						)
				);
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
				$config->addComponent(GridFieldDeleteAction::create());
			}

			$dispatcher = Injector::inst()->get(MessageDispatcher::class);
			$channelsMap = $dispatcher->getChannelsMap();
			if ($channelsMap) {
				$channelField = CheckboxSetField::create('Channels', 'Send as', $channelsMap);
				$defaultChannels = $this->getConfiguredDefaultChannels();
				if (empty($this->getChannelsArray()) && !empty($defaultChannels)) {
					$channelField->setValue($defaultChannels);
				}
				$fields->insertBefore(
					'Title',
					$channelField
				);
			}

			$fields->addFieldToTab(
				'Root.Main',
				TextField::create(
					'Label',
					'Label (optional)',
					$this->Label
				)->setDescription('Used for automated messages sent on specific occasions, such as a new user registration.')
			);
		});

		return parent::getCMSFields();
	}
	public function getCMSValidator()
	{
		$requiredFields = RequiredFields::create(
			[
				'Title',
				'Body',
				'Groups'
			]
		);
		if (class_exists(Subsite::class)) {
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

	protected function getConfiguredDefaultChannels(): array
	{
		$configured = self::config()->get('default_channels') ?: [];
		if (empty($configured)) {
			$configured = Message::config()->get('default_channels') ?: [];
		}

		return $configured;
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

		$message = Message::create();
		$message->MemberID = $member->ID;
		$message->GroupMessageID = $this->ID;
		$message->Title = $this->Title;
		$message->ImageID = $this->ImageID;
		$message->VideoID = $this->VideoID;
		$channels = $this->getChannelsArray();
		$message->Channels = !empty($channels) ? json_encode($channels) : null;
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

	private function getChannelsArray(): array
	{
		if (is_array($this->Channels)) {
			return $this->Channels;
		}

		$decoded = json_decode((string) $this->Channels, true);
		return is_array($decoded) ? $decoded : [];
	}
}
