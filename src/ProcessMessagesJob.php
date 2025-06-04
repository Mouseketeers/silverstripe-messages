<?php

class ProcessMessagesJob extends AbstractQueuedJob
{
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