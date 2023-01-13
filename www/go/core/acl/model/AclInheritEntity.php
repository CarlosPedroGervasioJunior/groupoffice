<?php

namespace go\core\acl\model;

use Exception;;

use go\core\orm\Query;
use go\core\jmap\Entity;

/**
 * The AclInheritEntity class
 * 
 * Similar to the AclItemEntity but instead of joining the tables it copies the aclId to
 * it's model. It can also be overridden per item.
 * 
 * @see AclItemEntity
 */
abstract class AclInheritEntity extends AclOwnerEntity {

	/**
	 * Get the {@see AclOwnerEntity} or {@see AclItemEntity} class name that it 
	 * depends on.
	 * 
	 * @return string 
	 */
	abstract protected static function aclEntityClass(): string;

	/**
	 * Get the keys for joining the aclEntityClass table.
	 * 
	 * @return array eg. ['folderId' => 'id']
	 */
	abstract protected static function aclEntityKeys(): array;

	protected function createAcl()
	{
		$this->{static::$aclColumnName} = $this->findAclEntity()->findAclId();

		//parent::createAcl();
	}

	/**
	 * @throws Exception
	 */
	protected function internalGetPermissionLevel() : int
	{
		if(!isset($this->{static::$aclColumnName})) {
			$this->createAcl();
		}

		return parent::internalGetPermissionLevel();
	}

	protected static function internalRequiredProperties() : array
	{
		return array_keys(static::aclEntityKeys());
	}


	/**
	 * Get the entity that holds the acl id.
	 *
	 * @return Entity
	 * @throws Exception
	 */
	public function findAclEntity(): Entity
	{

		$cls = static::aclEntityClass();

		/* @var $cls Entity */


		$keys = [];
		foreach (static::aclEntityKeys() as $from => $to) {
			if(!isset($this->{$from})) {
				throw new Exception("Required property '".static::class."::$from' not fetched");
			}
			$keys[$to] = $this->{$from};
		}

		$aclEntity = $cls::find($cls::getMapping()->getColumnNames())->where($keys)->single();

		if(!$aclEntity) {
			throw new Exception("Can't find related ACL entity. The keys for class '$cls' must be invalid: " . var_export($keys, true));
		}

		return $aclEntity;
	}


	/**
	 *
	 * @param Query $query
	 * @return array
	 * @throws Exception
	 */
	protected static function getAclsToDelete(Query $query) : array {

		if(!empty(self::$keepAcls[static::class])) {
			return [];
		}

		// Delete only overriden acl's
		$q = clone $query;
		$ownerAclAlias = static::joinAclEntity($q);

		$q->groupWhere()->where($query->getTableAlias() . '.' .static::$aclColumnName . " != " . $ownerAclAlias );

		$q->select($query->getTableAlias() . '.' . static::$aclColumnName);

		return $q->all();

	}

	protected function saveAcl()
	{
		if(!isset($this->setAcl)) {
			return;
		}

		if($this->hasOwnAcl()) {
			parent::saveAcl();
		} else {
			$a = $this->findAcl();

			foreach($this->setAcl as $groupId => $level) {
				$a->addGroup($groupId, $level);
			}

			if($a->isModified()) {
				// we need our own ACL now.
				parent::createAcl();
				parent::saveAcl();
			}

		}
	}

	/**
	 * Check if the ACL is different from than the ACL it inherits from.
	 * @throws Exception
	 */
	public function hasOwnAcl(): bool
	{
		return $this->{static::$aclColumnName} != $this->findAclEntity()->findAclId();
	}

	/**
	 * Join's the ACL owner entity primary table
	 *
	 * @param Query $query
	 * @param ?string $fromAlias
	 * @return string Alias for the acl column. For example: "addressbook.aclId"
	 * @throws Exception
	 */
	private static function joinAclEntity(Query $query, ?string $fromAlias = null): string
	{
		$cls = static::aclEntityClass();

		/** @var Entity $cls */

		if(!isset($fromAlias)) {
			$fromAlias = $query->getTableAlias();
		}

		$keys = [];
		foreach (static::aclEntityKeys() as $from => $to) {

			$column = $cls::getMapping()->getColumn($to);

			$keys[] = $fromAlias . '.' . $from . ' = ' . $column->table->getAlias() . ' . '. $to;
		}

		// Override didn't work because on delete it did need to be joined.
//		if($query->isJoined($column->table->getName(), $column->table->getAlias())) {
//			throw new \Exception(
//				"The ACL owner table `". $column->table->getName() .
//				"` was already joined with alias `" .  $column->table->getAlias() .
//				"` in class " . static::class . ". If you joined this table via defineMapping() then override the method joinAclEntity() and return '" . $column->table->getAlias() . '.' . $cls::$aclColumnName ."'.") ;
//		}

		if(!$query->isJoined($column->table->getName(), $column->table->getAlias())) {
			$query->join($column->table->getName(), $column->table->getAlias(), implode(' AND ', $keys));
		}


		//If this is another AclItemEntity then recurse
		if(is_a($cls, AclItemEntity::class, true)) {
			return $cls::joinAclEntity($query,  $column->table->getAlias());
		} else
		{
			//otherwise this must hold the aclId column
			$aclColumn = $cls::getMapping()->getColumn($cls::$aclColumnName);
			if(!$aclColumn) {
				throw new Exception("Column 'aclId' is required for AclEntity '$cls'");
			}

			return $column->table->getAlias() . '.' . $cls::$aclColumnName;
		}
	}


	protected static function checkAclJoinEntityTable(): Query
	{
		$updateQuery = parent::checkAclJoinEntityTable();
		$ownerAclAlias = static::joinAclEntity($updateQuery, 'entity');
		$updateQuery->where('entity.' .static::$aclColumnName . " != " . $ownerAclAlias );

		return $updateQuery;
	}
}
