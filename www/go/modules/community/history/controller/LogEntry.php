<?php


namespace go\modules\community\history\controller;

use go\core\jmap;
use go\core\orm\Query;
use go\core\util\ArrayObject;
use go\modules\community\history\model;

class LogEntry extends jmap\EntityController
{
	protected function entityClass(): string
	{
		return model\LogEntry::class;
	}

	protected function getQueryQuery(ArrayObject $params): Query
	{
		return parent::getQueryQuery($params)->removeJoin('core_entity', 'e');
	}

	public function query($params) {
		return $this->defaultQuery($params);
	}

	public function get($params) {
		return $this->defaultGet($params);
	}

	public function set($params) {
		return $this->defaultSet($params);
	}

	public function changes($params) {
		return $this->defaultChanges($params);
	}

}