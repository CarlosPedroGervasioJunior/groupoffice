<?php

namespace go\core\customfield;

use go\core\ErrorHandler;
use go\core\TemplateParser;

class TemplateField extends TextArea {


	public function onFieldSave(): bool
	{
		if(!parent::onFieldSave()) {
			return false;
		}

		if(!$this->field->isNew()) {
			$this->clearData();
		}

		return true;
	}

	/**
	 * @throws \Exception
	 */
	private function clearData() {

		go()->getDbConnection()
			->update($this->field->tableName(), [$this->field->databaseName => null])
			->debug()
			->execute();

	}

	private static $parser;

	private static function parser() {
		return self::$parser ?? (self::$parser = new TemplateParser());
	}

	public function beforeSave($value, \go\core\orm\CustomFieldsModel $model, $entity, &$record): bool
	{
		$tpl = $this->field->getOption('template');

		$tplParser = static::parser();
		$tplParser->addModel('entity', $entity);

		try {
			$parsed = $tplParser->parse($tpl);
		}
		catch(\Throwable $e) {
			ErrorHandler::logException($e);
			$parsed = $e->getMessage();
		}

		$record[$this->field->databaseName] = empty($parsed) ? null : $parsed;


		return true;
	}

	public function dbToApi($value, \go\core\orm\CustomFieldsModel $values, $entity)
	{
		if($value === null) {
			//field just added and value not saved yet.
			$this->beforeSave($value, $values, $entity, $record);
			if(!$entity->isNew()) {
				$entity->saveCustomFields();
			}
			$value = $record[$this->field->databaseName];
		}
		return parent::dbToApi($value, $values, $entity);
	}

	public function hasColumn(): bool
	{
		return false;
	}

}

