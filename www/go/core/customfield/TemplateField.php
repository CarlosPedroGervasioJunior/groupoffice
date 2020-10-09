<?php

namespace go\core\customfield;

use go\core\ErrorHandler;
use go\core\TemplateParser;

class TemplateField extends TextArea {

	public function beforeSave($value, &$record, \go\core\orm\Entity $entity)
	{
		$tpl = $this->field->getOption('template');

		$tplParser = new TemplateParser();
		$tplParser->addModel('entity', $entity);

		try {
			$parsed = $tplParser->parse($tpl);
		}
		catch(\Exception $e) {
			ErrorHandler::logException($e);
			$parsed = $e->getMessage();
		}

		$record[$this->field->databaseName] = $parsed;

		return true;
	}

	public function dbToApi($value, &$values, $entity)
	{
		if($value == null) {
			//field just added and value not saved yet.
			$this->beforeSave($value, $values, $entity);
			$entity->saveCustomFields();
			$value = $values[$this->field->databaseName];
		}
		return parent::dbToApi($value, $values, $entity);
	}


}

