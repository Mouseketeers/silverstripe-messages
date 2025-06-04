<?php
class MessagesAdmin extends ModelAdmin {

	// private static $menu_icon = 'app/images/cms/order-admin.png';
	
	private static $managed_models = array(
		'Message',
		'GroupMessage'
	);
	private static $url_segment = 'messages';

	private static $menu_title = 'Messages';

	public function getEditForm($id = null, $fields = null) {
	    $form = parent::getEditForm($id, $fields);

	    foreach (array('Message', 'GroupMessage') as $class) {
	        $gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName($class));
	        if ($gridField) {
	            $config = $gridField->getConfig();
	            $detailForm = $config->getComponentByType('GridFieldDetailForm');
	            if ($detailForm) {
	                $detailForm->setItemEditFormCallback(function($form, $controller) use ($class) {
	                    // $record = $form->getRecord();
	                    // if ($record && $record->ID && ($record instanceof Message || $record instanceof GroupMessage)) {
	                        $form->Actions()->push(
	                            FormAction::create('doSend', 'Send')
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