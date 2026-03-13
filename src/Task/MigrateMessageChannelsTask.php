<?php

namespace Mouseketeers\Messages\Task;

use Mouseketeers\Messages\GroupMessage;
use Mouseketeers\Messages\Message;
use SilverStripe\Dev\BuildTask;

/**
 * One-time migration task: populates the Channels Varchar field on existing
 * Message and GroupMessage records from the legacy SendEmail /
 * SendPushNotification boolean fields.
 *
 * Run via: /dev/tasks/MigrateMessageChannelsTask
 */
class MigrateMessageChannelsTask extends BuildTask
{
    private static $segment = 'MigrateMessageChannelsTask';

    protected $title = 'Migrate Message Channels';

    protected $description = 'Populates the Channels field from the legacy SendEmail '
        . 'and SendPushNotification boolean fields on Message and GroupMessage records.';

    public function run($request)
    {
        $messageCount = 0;
        foreach (Message::get() as $message) {
            if (!empty($message->Channels)) {
                continue;
            }
            $channels = [];
            if ($message->SendEmail) {
                $channels[] = 'email';
            }
            if ($message->SendPushNotification) {
                $channels[] = 'push_message';
            }
            $message->Channels = $channels ? json_encode($channels) : null;
            $message->write();
            $messageCount++;
        }

        $groupCount = 0;
        foreach (GroupMessage::get() as $groupMessage) {
            if (!empty($groupMessage->Channels)) {
                continue;
            }
            $channels = [];
            if ($groupMessage->SendEmail) {
                $channels[] = 'email';
            }
            if ($groupMessage->SendPushNotification) {
                $channels[] = 'push_message';
            }
            $groupMessage->Channels = $channels ? json_encode($channels) : null;
            $groupMessage->write();
            $groupCount++;
        }

        echo "Migrated {$messageCount} Message records and {$groupCount} GroupMessage records.\n";
    }
}
