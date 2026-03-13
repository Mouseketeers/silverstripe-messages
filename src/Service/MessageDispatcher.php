<?php

namespace Mouseketeers\Messages\Service;

use Mouseketeers\Messages\Message;
use Mouseketeers\Messages\Channel\MessageChannelInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Throwable;

class MessageDispatcher
{
    use Configurable;
    use Injectable;

    /**
     * Map of channel code => fully-qualified class name.
     * External modules append to this array via their own _config YAML.
     *
     * @config
     * @var array
     */
    private static $channel_classes = [];

    /**
     * Returns all registered channel instances, keyed by channel code.
     *
     * @return MessageChannelInterface[]
     */
    public function getChannels(): array
    {
        $channels = [];
        $channelClasses = (array) self::config()->get('channel_classes');
        if (empty($channelClasses) && class_exists('Mouseketeers\\Messages\\Channel\\EmailChannel')) {
            // Safe fallback during transition if config cache/build is stale.
            $channelClasses = [
                'email' => 'Mouseketeers\\Messages\\Channel\\EmailChannel',
            ];
        }

        foreach ($channelClasses as $code => $class) {
            $channel = Injector::inst()->get($class);
            if ($channel instanceof MessageChannelInterface) {
                $channels[$code] = $channel;
            }
        }
        return $channels;
    }

    /**
     * Returns a code => label map suitable for a CheckboxSetField source.
     *
     * @return array
     */
    public function getChannelsMap(): array
    {
        $map = [];
        foreach ($this->getChannels() as $code => $channel) {
            $map[$code] = $channel->label();
        }
        return $map;
    }

    /**
     * Dispatches the message to every selected channel that reports canSend().
     */
    public function dispatch(Message $message): bool
    {
        $report = $this->dispatchWithReport($message);
        return $report['sent'];
    }

    /**
     * Dispatches with diagnostics for CMS feedback and troubleshooting.
     *
     * @return array{sent: bool, reason: string}
     * @throws Throwable
     */
    public function dispatchWithReport(Message $message, ?array $selected = null): array
    {
        if ($selected === null) {
            $selected = is_array($message->Channels)
                ? $message->Channels
                : (json_decode((string) $message->Channels, true) ?: []);
        }
        if (empty($selected)) {
            return [
                'sent' => false,
                'reason' => 'No send channels are selected.',
            ];
        }

        $channels = $this->getChannels();
        if (empty($channels)) {
            return [
                'sent' => false,
                'reason' => 'No channel handlers are registered.',
            ];
        }

        $sent = false;
        $blocked = [];
        $failed = [];
        $missing = [];

        foreach ($selected as $code) {
            if (!isset($channels[$code])) {
                $missing[] = $code;
                continue;
            }

            $channel = $channels[$code];
            if (!$channel->canSend($message)) {
                $blocked[] = $code;
                continue;
            }

            $sentOnChannel = $channel->send($message);
            if ($sentOnChannel) {
                $sent = true;
            } else {
                $failed[] = $code;
            }
        }

        if ($sent) {
            return [
                'sent' => true,
                'reason' => '',
            ];
        }

        if (!empty($failed)) {
            return [
                'sent' => false,
                'reason' => 'Selected channel(s) failed to send: ' . implode(', ', $failed) . '.',
            ];
        }

        if (!empty($blocked)) {
            return [
                'sent' => false,
                'reason' => 'Selected channel(s) are not sendable for this recipient: ' . implode(', ', $blocked) . '.',
            ];
        }

        if (!empty($missing)) {
            return [
                'sent' => false,
                'reason' => 'No registered handler for selected channel(s): ' . implode(', ', $missing) . '.',
            ];
        }

        return [
            'sent' => false,
            'reason' => 'No channel reported a successful send. Check mail transport and logs.',
        ];
    }
}
