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

class Message extends DataObject {

	/**
	 * Runtime diagnostic, not persisted.
	 *
	 * @var string
	 */
	protected $sendFailureReason = '';

	private static $table_name = 'Message';

	private static $singular_name = 'Message';
	private static $plural_name = 'Messages';

	private static $db = array(
		'Title' => 'Varchar(255)',
		'Body' => 'HTMLText',
        'IsRead' => 'Boolean',
        'SendEmail' => 'Boolean',
        'SendPushNotification' => 'Boolean',
		'IsSent' => 'Boolean',
		'DateSent' => 'Datetime',
		'Channels' => 'Varchar(255)'
	);

	private static $has_one = array(
        'Member' => Member::class,
		'GroupMessage' => GroupMessage::class,
		'Image' => Image::class,
		'Video' => File::class
	);

    private static $summary_fields = [
		'Recipient' => 'Recipient',
		'Title' => 'Title',
		'DateSent.Nice' => 'Sent'
	];

	private static $default_sort = 'DateSent DESC, Created DESC';

	private static $default_channels = [];

    public function populateDefaults()
    {
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
		$fields->removeByName('SendEmail');
		$fields->removeByName('SendPushNotification');
		$fields->removeByName('Channels');

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


		$dispatcher = Injector::inst()->get(MessageDispatcher::class);
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
			$channelsMap = $dispatcher->getChannelsMap();
			if ($channelsMap) {
				$channelField = CheckboxSetField::create('Channels', 'Send as', $channelsMap);
				$defaultChannels = self::config()->get('default_channels') ?: [];
				if (empty($this->Channels) && !empty($defaultChannels)) {
					$channelField->setValue($defaultChannels);
				}
				$fields->insertBefore('Title',
					$channelField
				);
			}
		} else {
			$fields->insertBefore('Title',
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
				$fields->insertBefore('Title',
					ReadonlyField::create('ChannelsDisplay', 'Sent via', implode(', ', $labels))
				);
			}
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
		if (class_exists(Subsite::class)) {
			$requiredFields->addRequiredField('SubsiteID');
		}
        return $requiredFields;
	}
	
	public function process($data = null, $form = null) {

		if($form) {
			$form->saveInto($this);
		}
		$channels = $this->getChannelsArray();

		if($this->Member()->exists()) {
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
		$this->sendFailureReason = 'No recipient selected.';
		return false;
	}

	public function getSendFailureReason(): string
	{
		if (!empty($this->sendFailureReason)) {
			return $this->sendFailureReason;
		}

		$member = $this->Member();
		if (!$member || !$member->exists()) {
			return 'No recipient selected.';
		}
		if (!$member->Email) {
			return 'Recipient has no email address.';
		}
		if (!filter_var($member->Email, FILTER_VALIDATE_EMAIL)) {
			return 'Recipient email address is invalid: ' . $member->Email;
		}

		$channels = $this->getChannelsArray();
		if (empty($channels)) {
			return 'No send channels are selected.';
		}

		if (in_array('email', $channels, true)) {
			$fromEmail = self::config()->get('default_from_email');
			if (!$fromEmail) {
				$siteConfig = \SilverStripe\SiteConfig\SiteConfig::current_site_config();
				$fromEmail = $siteConfig->DefaultFromEmail ?? null;
			}
			if (!$fromEmail) {
				$fromEmail = \SilverStripe\Control\Email\Email::config()->get('admin_email');
			}
			if (!$fromEmail) {
				return 'No sender email configured. Set Message.default_from_email, SiteConfig.DefaultFromEmail, or Email.admin_email.';
			}
			$fromEmailAddress = trim($fromEmail);
			if (preg_match('/.*<([^>]+)>/', $fromEmailAddress, $matches)) {
				$fromEmailAddress = trim($matches[1]);
			}
			if (!filter_var($fromEmailAddress, FILTER_VALIDATE_EMAIL)) {
				return 'Sender email address is invalid: ' . $fromEmailAddress;
			}
		}

		return 'No channel reported a successful send. Check mail transport and logs.';
	}

	private function getChannelsArray(): array
	{
		if (is_array($this->Channels)) {
			return $this->Channels;
		}

		$decoded = json_decode((string) $this->Channels, true);
		return is_array($decoded) ? $decoded : [];
	}




	public function onAfterSerialize(&$formattedDataObjectMap) {
		$formattedDataObjectMap['SentAgo'] = $this->dbObject('DateSent')->Ago();
		$formattedDataObjectMap['SentShort'] = $this->dbObject('DateSent')->Ago();
	}
}