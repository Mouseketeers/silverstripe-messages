<?php
class MessagesAdmin extends ModelAdmin
{

	private static $menu_icon = '/messages/images/message.svg';

	private static $managed_models = [
		'GroupMessage',
		'Message'
	];
	private static $url_segment = 'messages';

	private static $menu_title = 'Messages';

	public function getEditForm($id = null, $fields = null)
	{
		$form = parent::getEditForm($id, $fields);

		foreach (['Message', 'GroupMessage'] as $class) {
			$gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName($class));
			if ($gridField) {
				$config = $gridField->getConfig();
				$detailForm = $config->getComponentByType('GridFieldDetailForm');
				if ($detailForm) {
					$detailForm->setItemEditFormCallback(function ($form) {
						$record = $form->getRecord();
						$buttonLabel = 'Post';
						if ($record->IsSent) {
							$buttonLabel = 'Re-post';
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
	public function subsiteCMSShowInMenu()
	{
		return true;
	}
}
