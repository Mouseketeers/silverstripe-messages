<?php

/**
 * Shared validator for Message and GroupMessage classes
 * Validates FromEmail requirement when SendAsEmail is enabled
 */
class MessageFormValidator extends RequiredFields
{
	public function php($data)
	{
		$valid = parent::php($data);
		
		// Check if SendAsEmail is enabled and FromEmail is required
		if (!empty($data['SendAsEmail']) && empty($data['FromEmail'])) {
			$this->validationError(
				'FromEmail',
				'From Email is required when sending emails',
				'required'
			);
			$valid = false;
		}
		
		return $valid;
	}
}