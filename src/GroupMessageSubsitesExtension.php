<?php

namespace Mouseketeers\Messages;

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
        if (!$this->owner->SubsiteID) {
            $this->owner->SubsiteID = (int) Subsite::currentSubsiteID();
            if (!$this->owner->SubsiteID) {
                $this->owner->SubsiteID = 0;
            }
        }
    }
}