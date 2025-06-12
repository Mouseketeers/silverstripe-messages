<?php
class MessageDetailFormExtension extends Extension {
    
    public function doSend($data, $form) {
        
        $record = $form->getRecord();

        if ($record && $record->hasMethod('process')) {
            $result = $record->process($data, $form);
            if ($result) {
                $form->sessionMessage('Message sent successfully!', 'good');
            } else {
                $form->sessionMessage('Failed to send message.', 'bad');
            }
        }
        return Controller::curr()->redirect($form->controller->Link());
    }
}