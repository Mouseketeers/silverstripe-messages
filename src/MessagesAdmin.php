<?php

namespace Mouseketeers\Messages;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\FormAction;

class MessagesAdmin extends ModelAdmin {

	private static $menu_icon = 'mouseketeers/silverstripe-messages:client/icons/message.svg';
	
	private static $managed_models = array(
		Message::class,
		GroupMessage::class
	);
	private static $url_segment = 'messages';

	private static $menu_title = 'Messages';

	public function subsiteCMSShowInMenu()
	{
		return true;
	}

	public function getEditForm($id = null, $fields = null) {
	    $form = parent::getEditForm($id, $fields);

	    foreach ([Message::class, GroupMessage::class] as $class) {
	        $gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName($class));
	        if ($gridField) {
	            $config = $gridField->getConfig();
	            $detailForm = $config->getComponentByType(GridFieldDetailForm::class);
	            if ($detailForm) {
	                $detailForm->setItemEditFormCallback(function($form) {
	                    $record = $form->getRecord();
						if (!$record || !method_exists($record, 'canEdit') || !$record->canEdit()) {
							return $form;
						}
						$buttonLabel = 'Send Message';
						if($record->IsSent) {
							$buttonLabel = 'Resend';
						}
						$form->Actions()->push(
							FormAction::create('doSend', $buttonLabel)
								->setUseButtonTag(true)
								->addExtraClass('btn btn-primary')
						);
						return $form;
	                });
	            }
	        }
	    }
	    return $form;
	}
}