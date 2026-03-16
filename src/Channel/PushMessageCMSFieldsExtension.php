<?php

namespace Mouseketeers\Messages\Channel;

use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataExtension;

class PushMessageCMSFieldsExtension extends DataExtension
{
    public function updateCMSFields(FieldList $fields)
    {
        // Legacy boolean belongs to push-specific concerns.
        $fields->removeByName('SendPushNotification');
    }
}
