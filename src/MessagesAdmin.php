<?php

namespace Mouseketeers\Messages;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\FormAction;

class MessagesAdmin extends ModelAdmin {

	// private static $menu_icon = 'app/images/cms/order-admin.png';
	
	private static $managed_models = array(
		Message::class,
		GroupMessage::class
	);
	private static $url_segment = 'messages';

	private static $menu_title = 'Messages';

	public function getEditForm($id = null, $fields = null) {
	    $form = parent::getEditForm($id, $fields);

	    foreach (array(Message::class, GroupMessage::class) as $class) {
	        $gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName($class));
	        if ($gridField) {
	            $config = $gridField->getConfig();
	            $detailForm = $config->getComponentByType(GridFieldDetailForm::class);
	            if ($detailForm) {
	                $detailForm->setItemEditFormCallback(function($form, $controller) use ($class) {
	                    $record = $form->getRecord();
						$buttonLabel = 'Send';
						if($record->IsSent) {
							$buttonLabel = 'Resend';
						}
						$form->Actions()->push(
							FormAction::create('doSend', $buttonLabel)
								->setUseButtonTag(true)
								->addExtraClass('btn btn-primary')
						);
	                });
	            }
	        }
	    }

	    return $form;
	}
}