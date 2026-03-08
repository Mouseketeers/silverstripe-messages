<?php

namespace Mouseketeers\Messages;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;

class ProcessMessagesJob extends AbstractQueuedJob
{
    public $GroupMessage;
    private $title = 'Process Messages';
    public function getTitle()
    {
        return $this->title;
    }

    public function process()
    {
        $groupMessage = $this->GroupMessage;
        if(!$this->GroupMessage) {
            $this->addMessage('Message not loaded');
        }
        if($groupMessage instanceof GroupMessage) {
            $groupMessage->processMessages();
        }
        $this->isComplete = true;
    }
}