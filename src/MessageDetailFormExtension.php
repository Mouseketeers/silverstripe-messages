<?php

namespace Mouseketeers\Messages;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;

class MessageDetailFormExtension extends Extension {

    public function updateAllowedActions(&$actions) {
        $actions[] = 'doSend';
    }
    
    public function doSend($data, $form) {
        
        $record = $form->getRecord();

        try {
            $result = $record->process($data, $form);
            if ($result) {
                $form->sessionMessage('Message sent successfully!', 'good');
            } else {
                $form->sessionMessage('Failed to send message.', 'bad');
            }
        }
        catch (\Throwable $exception) {
            $form->sessionMessage('Failed to send message: ' . $exception->getMessage(), 'bad');
        }
        return $form->getController()->redirect($form->getController()->Link());
    }
}