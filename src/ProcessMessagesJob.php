<?php

/**
 * Job for processing group messages and sending emails/push notifications to members
 */
class ProcessMessagesJob extends AbstractQueuedJob
{
    private $title = 'Process Messages';
    
    /**
     * Constructor
     */
    public function __construct($groupMessage = null) {
        parent::__construct();
        
        if ($groupMessage && $groupMessage instanceof GroupMessage) {
            $this->setObject($groupMessage, 'GroupMessage');
            $this->title = 'Process Messages: ' . $groupMessage->Title;
        }
    }
    
    public function getTitle()
    {
        return $this->title;
    }
    
    public function getJobType() {
        return QueuedJob::QUEUED;
    }
    
    public function setup() {
        parent::setup();
        
        $groupMessage = $this->getObject('GroupMessage');
        if (!$groupMessage) {
            $this->isComplete = true;
            return;
        }
        
        // Calculate total steps for progress tracking
        $totalMembers = 0;
        foreach ($groupMessage->Groups() as $group) {
            $totalMembers += $group->Members()->count();
        }
        
        $this->totalSteps = $totalMembers;
        $this->currentStep = 0;
    }

    public function process()
    {
        $groupMessage = $this->getObject('GroupMessage');
        
        if (!$groupMessage) {
            $this->addMessage('GroupMessage not found', 'ERROR');
            $this->isComplete = true;
            return;
        }
        
        // Process the messages and get statistics
        $stats = $groupMessage->processMessages();
        
        // Update progress
        $this->currentStep = $this->totalSteps;
        
        // Build completion message
        $messages = [];
        if ($stats['emails_sent'] > 0) {
            $messages[] = sprintf('%d emails sent successfully', $stats['emails_sent']);
        }
        if ($stats['emails_failed'] > 0) {
            $messages[] = sprintf('%d emails failed to send', $stats['emails_failed']);
        }
        if ($stats['push_notifications_sent'] > 0) {
            $messages[] = sprintf('%d push notifications sent', $stats['push_notifications_sent']);
        }
        
        if (!empty($messages)) {
            $completionMessage = implode(', ', $messages) . '.';
            $this->addMessage($completionMessage, 'INFO');
        }
        
        $this->isComplete = true;
    }
}