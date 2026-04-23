<?php

namespace Mouseketeers\Messages;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Injector\Injector;
use Mouseketeers\Messages\GroupMessage;
use Mouseketeers\Messages\Service\MessageDispatcher;

class Message extends DataObject
{
	/**
	 * Runtime template data for email rendering.
	 *
	 * @var array
	 */
	protected $data = [];

	/**
	 * Runtime email attachments.
	 *
	 * @var array
	 */
	protected $attachments = [];

	/**
	 * Runtime diagnostic, not persisted.
	 *
	 * @var string
	 */
	protected $sendFailureReason = '';

	private static $table_name = 'Message';

	private static $singular_name = 'Message';
	private static $plural_name = 'Messages';

	private static $db = [
		'Title' => 'Varchar(255)',
		'Body' => 'HTMLText',
		'IsRead' => 'Boolean',
		'IsSent' => 'Boolean',
		'DateSent' => 'Datetime',
		'Channels' => 'Varchar(255)',
	];

	private static $has_one = [
		'Member' => Member::class,
		'GroupMessage' => GroupMessage::class,
		'Image' => Image::class,
		'Video' => File::class,
	];

	private static $summary_fields = [
		'Recipient' => 'Recipient',
		'Title' => 'Title',
		'DateSent.Nice' => 'Sent',
	];

	private static $searchable_fields = [
		'Title',
		'Recipient'
	];
	private static $default_sort = 'DateSent DESC, Created DESC';

	private static $default_channels = [];

	public function __construct($record = [], $creationType = self::CREATE_OBJECT, $queryParams = [], $body = null)
	{
		if (is_string($record) && is_string($creationType)) {
			parent::__construct([], self::CREATE_OBJECT, []);
			if ($this->hasMethod('configureEmailMessage')) {
				$this->configureEmailMessage(
					$record,
					$creationType,
					is_string($queryParams) ? $queryParams : null,
					is_string($body) ? $body : null
				);
			}
			return;
		}

		parent::__construct($record, $creationType, $queryParams);
	}

	public function populateDefaults()
	{
		$this->IsRead = false;
		parent::populateDefaults();
	}

	public function getRecipient()
	{
		if (!$this->Member()->exists()) {
			if ($this->hasMethod('getResolvedRecipientEmail')) {
				$recipientEmail = $this->getResolvedRecipientEmail();
				if (!empty($recipientEmail)) {
					return $recipientEmail;
				}
			}
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
		$fields->removeByName('Channels');

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

		$dispatcher = Injector::inst()->get(MessageDispatcher::class);
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

			$channelsMap = $dispatcher->getChannelsMap();
			if ($channelsMap) {
				$channelField = CheckboxSetField::create('Channels', 'Send as', $channelsMap);
				$defaultChannels = self::config()->get('default_channels') ?: [];
				if (empty($this->Channels) && !empty($defaultChannels)) {
					$channelField->setValue($defaultChannels);
				}
				$fields->insertBefore(
					'Title',
					$channelField
				);
			}
		} else {
			$fields->insertBefore(
				'Title',
				ReadonlyField::create('Recipient', 'Recipient', $this->getRecipient())
			);

			$channels = $this->getChannelsArray();
			if ($channels) {
				$map = $dispatcher->getChannelsMap();
				$labels = [];
				foreach ($channels as $code) {
					if (isset($map[$code])) {
						$labels[] = (string) $map[$code];
					}
				}
				$fields->insertBefore(
					'Title',
					ReadonlyField::create('ChannelsDisplay', 'Sent via', implode(', ', $labels))
				);
			}
		}

		return $fields;
	}

	public function getCMSValidator()
	{
		$requiredFields = RequiredFields::create([
			'Title',
			'Body',
		]);
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

		if (!$this->exists()) {
			$this->write();
		}

		$channels = $this->getChannelsArray();

		if (!empty($channels)) {
			$report = Injector::inst()->get(MessageDispatcher::class)->dispatchWithReport($this, $channels);
			if (!$report['sent']) {
				$this->sendFailureReason = $report['reason'] ?? '';
				return false;
			}
		}

		$this->IsSent = true;
		$this->DateSent = DBDatetime::now()->Rfc2822();
		$this->write();
		return true;
	}

	public function send(): bool
	{
		$this->ensureSendChannels();
		return $this->process();
	}

	public function setData(array $data)
	{
		$this->data = $data;
		return $this;
	}

	public function getData(): array
	{
		return $this->data;
	}

	public function addAttachment(string $path, ?string $name = null, ?string $mimeType = null)
	{
		$this->attachments[] = [
			'path' => $path,
			'name' => $name,
			'mimeType' => $mimeType,
		];

		return $this;
	}

	public function getAttachments(): array
	{
		return $this->attachments;
	}

	public function getSendFailureReason(): string
	{
		if (!empty($this->sendFailureReason)) {
			return $this->sendFailureReason;
		}

		if ($this->hasMethod('getResolvedRecipientEmail') && empty($this->getResolvedRecipientEmail())) {
			return 'No recipient selected.';
		}

		return 'No channel reported a successful send.';
	}

	private function getChannelsArray(): array
	{
		if (is_array($this->Channels)) {
			return $this->Channels;
		}

		$decoded = json_decode((string) $this->Channels, true);
		return is_array($decoded) ? $decoded : [];
	}

	private function ensureSendChannels(): void
	{
		$channels = $this->getChannelsArray();
		if (empty($channels)) {
			$configured = self::config()->get('default_channels') ?: [];
			$channels = !empty($configured) ? $configured : ['email'];
			$this->Channels = json_encode(array_values(array_unique($channels)));
		}
	}

	public function onAfterSerialize(&$formattedDataObjectMap)
	{
		$formattedDataObjectMap['SentAgo'] = $this->dbObject('DateSent')->Ago();
		$formattedDataObjectMap['SentShort'] = $this->dbObject('DateSent')->Ago();
	}
}
