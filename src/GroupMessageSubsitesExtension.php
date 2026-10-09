<?php

namespace Mouseketeers\Messages;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Subsites\Model\Subsite;

class GroupMessageSubsitesExtension extends DataExtension
{
    private static $has_one = [
        'Subsite' => Subsite::class,
    ];

    private static $defaults = [
        'SubsiteID' => 0,
    ];

    public function populateDefaults()
    {
        if (!$this->getOwner()->SubsiteID) {
            $this->getOwner()->SubsiteID = (int) Subsite::currentSubsiteID();
            if (!$this->getOwner()->SubsiteID) {
                $this->getOwner()->SubsiteID = 0;
            }
        }
    }

    public function updateSummaryFields(&$fields)
    {
        $fields['Subsite.Title'] = 'Site';
    }

    public function updateCMSFields(FieldList $fields)
    {

        $subsiteField = DropdownField::create(
            'SubsiteID',
            'Send from Site',
            Subsite::all_sites()->map('ID', 'Title')
        )->setEmptyString('Select site...');

        if ($fields->dataFieldByName('Groups')) {
            $fields->insertAfter('Groups', $subsiteField);
            return;
        }

        $fields->addFieldToTab('Root.Main', $subsiteField);
    }
}