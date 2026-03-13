<?php

namespace Mouseketeers\Messages\Channel;

use Mouseketeers\Messages\Message;

interface MessageChannelInterface
{
    public function code(): string;

    public function label(): string;

    public function canSend(Message $message): bool;

    public function send(Message $message): bool;
}
