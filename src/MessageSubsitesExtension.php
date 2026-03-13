<?php

namespace Mouseketeers\Messages;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\View\SSViewer;

class MessageSubsitesExtension extends DataExtension
{
    private static $has_one = [
        'Subsite' => Subsite::class,
    ];

    private static $defaults = [
        'SubsiteID' => 0,
    ];

    public function populateDefaults()
    {
        if (!$this->owner->SubsiteID) {
            $this->owner->SubsiteID = (int) Subsite::currentSubsiteID();
            if (!$this->owner->SubsiteID) {
                $this->owner->SubsiteID = 0;
            }
        }
    }

    public function updateSummaryFields(&$fields)
    {
        $fields['Subsite.Title'] = 'Site';
    }

    public function updateCMSValidator(RequiredFields $requiredFields)
    {
        $requiredFields->addRequiredField('SubsiteID');
    }

    public function updateCMSFields(FieldList $fields)
    {

        $subsiteField = DropdownField::create(
            'SubsiteID',
            'Send from Site',
            Subsite::all_sites()->map('ID', 'Title')
        )->setEmptyString('Select site...');

        if ($fields->dataFieldByName('MemberID')) {
            $fields->insertBefore($subsiteField, 'MemberID');
            return;
        }

        $fields->addFieldToTab('Root.Main', $subsiteField);
    }

    public function beforeSendMessageEmail()
    {
        if (!$this->owner->SubsiteID) {
            return;
        }

        $subsite = Subsite::get()->byID((int) $this->owner->SubsiteID);
        if ($subsite && $subsite->Theme) {
            SSViewer::set_themes([$subsite->Theme, '$default']);
        }
    }
}