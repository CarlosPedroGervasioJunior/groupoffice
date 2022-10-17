<?php /** @noinspection PhpPossiblePolymorphicInvocationInspection */

namespace go\core\jmap;

use Exception;
use go\core\event\EventEmitterTrait;
use go\core\exception\NotFound;
use go\core\acl\model\AclOwnerEntity;
use go\core\fs\File;
use go\core\jmap\exception\UnsupportedSort;
use go\core\model\Acl;
use go\core\App;
use go\core\Controller;
use go\core\data\convert\AbstractConverter;
use go\core\exception\Forbidden;
use go\core\fs\Blob;
use go\core\jmap\exception\InvalidArguments;
use go\core\jmap\exception\StateMismatch;
use go\core\orm\EntityType;
use go\core\orm\Query;
use go\core\util\ArrayObject;
use go\core\util\Lock;
use InvalidArgumentException;
use PDO;
use PDOException;
use ReflectionException;

abstract class EntityController extends Controller {

	use EventEmitterTrait;

	const EVENT_BEFORE_QUERY = "beforequery";
	const EVENT_QUERY = "query";
	const EVENT_BEFORE_SET = "beforeset";
	const EVENT_SET = "set";
	const EVENT_BEFORE_GET = "beforeget";
	const EVENT_GET = "get";

	/**
	 * The class name of the entity this controller is for.
	 * 
	 * @return class-string<Entity>
	 */
	abstract protected function entityClass(): string;

	
	/**
	 * Creates a short name based on the class name.
	 * 
	 * This is used to generate response name. 
	 * 
	 * eg. class go\modules\community\notes\model\Note becomes just "note"
	 * 
	 * @return string
	 */
	protected function getShortName(): string
	{
		$cls = $this->entityClass();
		return lcfirst(substr($cls, strrpos($cls, '\\') + 1));
	}
	
//	/**
//	 * Creates a short plural name
//	 *
//	 * @see getShortName()
//	 *
//	 * @return string
//	 */
//	protected function getShortPluralName(): string
//	{
//
//		$shortName = $this->getShortName();
//
//		if(substr($shortName, -1) == 'y') {
//			return substr($shortName, 0, -1) . 'ies';
//		} else
//		{
//			return $shortName . 's';
//		}
//	}

	/**
	 * Querying readonly has a slight performance benefit
	 *
	 * @var bool
	 */
	protected static $getReadOnly = true;

	/**
	 * Gets the query for the Foo/query JMAP method
	 *
	 * @param ArrayObject $params
	 * @return Query
	 * @throws Exception
	 */
	protected function getQueryQuery(ArrayObject $params): Query
	{
		$cls = $this->entityClass();

		/** @var $cls Entity */

		$query = $cls::find($cls::getPrimaryKey(false), false)
						->limit($params['limit'])
						->offset($params['position']);

//		if($params['calculateTotal']) {
//			$query->calcFoundRows();
//		}
		
		/* @var $query Query */

		$sort = $this->transformSort($params['sort']);
		
		$cls::sort($query, $sort);

		// MS: this messes up sort for invoices by date. Can't remember
		// why this was needed before. I expect somthing to break with this removed!
//		if(!empty($query->getGroupBy())) {
//			//always add primary key for a stable sort. (https://dba.stackexchange.com/questions/22609/mysql-group-by-and-order-by-giving-inconsistent-results)
//			$keys = $cls::getPrimaryKey();
//			$pkSort = [];
//			foreach($keys as $key) {
//				if(!isset($sort[$key])) {
//					$pkSort[$key] = 'ASC';
//				}
//			}
//			$query->orderBy($pkSort, true);
//		}

		$this->selectColumnsForQueryQuery($query);

		$query->filter($params['filter']);

		$this->applyPermissionLevelToQueryQuery($query);

		return $query;
	}

	protected function selectColumnsForQueryQuery(Query $query) {
		$cls = $this->entityClass();
//		/**
//		 * @var Entity $cls;
//		 */
//		$mapQuery = $cls::getMapping()->getQuery();
//		if(isset($mapQuery)) {
//			$select = $mapQuery->select($cls::getPrimaryKey(true), true)->getSelect();
//			$query->select($select); //only select primary key
//		} else{

		$keys = $cls::getPrimaryKey(true);
		if(count($keys) == 1) {
			$query->select($keys);
		} else{
			$query->select("CONCAT(" . implode(", '-', ", $keys) . ")");
		}
//		}
	}

	protected function applyPermissionLevelToQueryQuery(Query $query) {
		$cls = $this->entityClass();
		// Only return readable ID's
		if($cls::getFilters()->hasFilter('permissionLevel') &&  !$query->isFilterUsed('permissionLevel')) {
			$query->filter(['permissionLevel' => Acl::LEVEL_READ]);
		}
	}

	
	/**
	 * Takes the request arguments, validates them and fills it with defaults.
	 * 
	 * @param array $params
	 * @return ArrayObject
	 * @throws InvalidArguments
	 */
	protected function paramsQuery(array $params): ArrayObject
	{
		if(!isset($params['limit'])) {
			$params['limit'] = 0;
		}		

		if ($params['limit'] < 0) {
			throw new InvalidArguments("Limit MUST be positive");
		}
		//cap at max of 50
		//$params['limit'] = min([$params['limit'], Capabilities::get()->maxObjectsInGet]);
		
		if(!isset($params['position'])) {
			$params['position'] = 0;
		}

		if ($params['position'] < 0) {
			throw new InvalidArguments("Position MUST be positive");
		}
		
		if(!isset($params['sort'])) {
			$params['sort'] = [];
		} else
		{
			if(!is_array($params['sort'])) {
				throw new InvalidArguments("Parameter 'sort' must be an array");
			}
		}

		if(!isset($params['filter'])) {
			$params['filter'] = $this->getDefaultQueryFilter();
		} else
		{
			if(!is_array($params['filter'])) {
				throw new InvalidArguments("Parameter 'filter' must be an array");
			}
		}

		if(!isset($params['accountId'])) {
			$params['accountId'] = null;
		}
		
		$params['calculateTotal'] = !empty($params['calculateTotal']);

		$params['calculateHasMore'] = !empty($params['calculateHasMore']) && $params['limit'] > 0;

		//a faster alternative to calculateTotal just indicating that there are more entities. We do that by selecting one more than required.
		if($params['calculateHasMore']) {
			$params['limit']++;
		}
		
		return new ArrayObject($params);
	}

	protected function getDefaultQueryFilter(): array
	{
		return [];
	}

  /**
   * Handles the Foo entity's  "getFooList" command
   *
   * @param array $params
   * @return ArrayObject
   * @throws InvalidArguments
   * @throws Exception
   */
	protected function defaultQuery(array $params): ArrayObject
	{
		$state = $this->getState();
		
		$p = $this->paramsQuery($params);
		$idsQuery = $this->getQueryQuery($p);
		$idsQuery->fetchMode(PDO::FETCH_COLUMN, 0);

		static::fireEvent(self::EVENT_BEFORE_QUERY, $this, $p, $idsQuery);


		try {

			$ids = $idsQuery->all();

			if($p['calculateHasMore'] && count($ids) > $params['limit']) {
				$hasMore = !!array_pop($ids);
			}

			$response = new ArrayObject([
				'accountId' => $p['accountId'],
				'state' => $state,
				'ids' => $ids,
				'notfound' => [],
				'canCalculateUpdates' => false
			]);

			if(go()->getDebugger()->enabled) {
				$response['query'] = (string) $idsQuery;
			}

			if(isset($hasMore)) {
				$response['hasMore'] = $hasMore;
			}

			if ($p['calculateTotal']) {

				if($idsQuery->getCalcFoundRows()) {
					$response['total'] = $idsQuery->foundRows();
				} else{
					 $totalQuery = clone $idsQuery;

					 if(count($idsQuery->getGroupBy())) {
					 	$totalQuery->selectSingleValue("count(distinct " . $totalQuery->getTableAlias() . ".id)");
					 } else{
						 //count(*) can be used because we use a subquery in AclItemEntity::applyAclToQuery()
						 $totalQuery->selectSingleValue("count(*)");
					 }

					 $response['total'] = $totalQuery

					 								->orderBy([], false)
					 								->groupBy([])
					 								->limit(1)
					 								->offset(0)
					 								->single();
				}
			}
		}catch(PDOException $e) {

			//Check if the PDOException is due to an invalid sort
			//SQLSTATE[42S22]: Column not found: 1054 Unknown column 'customFields.A_checkbox' in 'order clause'
			$msg = $e->getMessage();
			if(strpos($msg, '42S22') !== false && strpos($msg, 'order clause') !== false) {
				throw new UnsupportedSort();
			} else{
				throw $e;
			}
		}

		static::fireEvent(self::EVENT_QUERY, $this, $p, $response);

		return $response;
	}

  /**
   * Get the JMAP sync state of the entity
   *
   * @return string
   * @throws Exception
   */
	protected function getState(): string
	{
		$cls = $this->entityClass();
		
		//entities that don't support syncing can be listed and fetched with the read only controller
		return $cls::getState();
	}

	/**
	 * Transforms JMAP sort param into: ['name' => 'ASC']
	 * 
	 * @param array[] $sort
	 * @return ArrayObject
	 */
	protected function transformSort(array $sort) : ArrayObject {
		if(empty($sort)) {
			return new ArrayObject();
		}
		
		$transformed = [];

		foreach ($sort as $s) {
			if(!isset($s['property'])) {
				throw new InvalidArgumentException("'sort' parameter is invalid.");
			}
			$transformed[$s['property']] = (isset($s['isAscending']) && $s['isAscending'] === false) ? 'DESC' : 'ASC';
		}
		
		return new ArrayObject($transformed);
	}


  /**
   * Get the entity model
   *
   * @param string $id
   * @param array $properties
   * @return boolean|Entity
   * @throws Exception
   */
	protected function getEntity(string $id, array $properties = []) {
		$cls = $this->entityClass();

		$entity = $cls::findById($id, $properties);

		if(!$entity){
			return false;
		}
		
		if (isset($entity->deletedAt)) {
			return false;
		}
		
		if(!$this->canRead($entity)) {
			App::get()->debug("Forbidden: " . $cls . ": ".$id);
			return false; //not found
		}

		return $entity;
	}

	
	/**
	 * Takes the request arguments, validates them and fills it with defaults.
	 * 
	 * @param array $params
	 * @return ArrayObject
	 * @throws InvalidArgumentException
	 */
	protected function paramsGet(array $params): ArrayObject
	{
		if(isset($params['ids']) && !is_array($params['ids'])) {
			throw new InvalidArgumentException("ids must be of type array");
		}

		if(!empty($params['ids'])) {
			$params['ids'] = array_unique($params['ids']);
			$params['ids'] = array_filter($params['ids'], function($id) {
				return !empty($id);
			});
		}

		if(!isset($params['properties'])) {
			$params['properties'] = [];
		}
		
		if(!isset($params['accountId'])) {
			$params['accountId'] = [];
		}
		
		return new ArrayObject($params);
	}

  /**
   * Override to add more query options for the "get" method.
   * @param ArrayObject $params
   * @return Query
   * @throws Exception
   */
	protected function getGetQuery(ArrayObject $params): Query
	{
		$cls = $this->entityClass();
		
		if(!isset($params['ids'])) {
			$query = $cls::find($params['properties'], static::$getReadOnly);
		} else
		{
			$query = $cls::findByIds($params['ids'], $params['properties'], static::$getReadOnly);
		}

		return $query;	
	}


	/**
	 * Handles the Foo entity's getFoo command
	 *
	 * @param array $params
	 * @return ArrayObject
	 * @throws Exception
	 */
	protected function defaultGet(array $params) : ArrayObject
	{

		$p = $this->paramsGet($params);

		$result = new ArrayObject([
			'accountId' => $p['accountId'],
			'state' => $this->getState(),
			'list' => [],
			'notFound' => []
		]);

		//empty array should return empty result. but ids == null should return all.
		if(isset($p['ids']) && !count($p['ids'])) {
			return $result;
		}

		$query = $this->getGetQuery($p);

		static::fireEvent(self::EVENT_BEFORE_GET, $this, $p, $query);

		$unsorted = [];
		$foundIds = [];

		foreach($query as $e) {
			if($this->canRead($e)) {
				$arr = $e->toArray();
				$arr['id'] = $e->id();
				$unsorted[$arr['id']] = $arr;
				$foundIds[] = $arr['id'];
			}
		}

		if(!empty($p['ids'])) {
			// Sort the result by given ids.
			$result['list'] = array_values(
				array_filter(
					array_map(function ($v) use ($unsorted) {
						//if not in sorted then the ID's were not found.
						return $unsorted[$v] ?? null;
					}, $p['ids']),

					function($id) {
						return $id != null;
					}
				)
			);
		} else{
			$result['list'] = array_values($unsorted);
		}

		$result['notFound'] = isset($p['ids']) ? array_values(array_diff($p['ids'], $foundIds)) : [];


		static::fireEvent(self::EVENT_GET, $this, $p, $result);

		return $result;
	}

//	private function getEntityArray($id, $properties) {
//		$e = $this->getEntity($id, $properties);
//		if(!$e) {
//			return false;
//		}
//
//		return $e->toArray($properties);
//	}

	// Caching doesn't work because entities can contain user specific props like user tables and getPermissionLevel()
//	private function getEntityArrayFromCache($id, $properties) {
//		$key = $this->entityClass() . '-toArray-' . $id;
//		$arr = go()->getCache()->get($key);
//
//		if(!$arr) {
//			$e = $this->getEntity($id);
//			if(!$e) {
//				return false;
//			} else {
//				$arr = $e->toArray();
//				$arr['id'] = $e->id();
//				go()->getCache()->set($key, $arr);
//			}
//		}
//
//		if(!empty($properties)) {
//			$arr = array_intersect_key($arr, array_flip($properties));
//		}
//
//		return $arr;
//	}
	
	/**
	 * Takes the request arguments, validates them and fills it with defaults.
	 * 
	 * @param array $params
	 * @return ArrayObject
	 * @throws InvalidArguments
	 */
	protected function paramsSet(array $params): ArrayObject
	{
		if(!isset($params['accountId'])) {
			$params['accountId'] = null;
		}
		
		if(!isset($params['create']) && !isset($params['update']) && !isset($params['destroy'])) {
			throw new InvalidArguments("You must pass one of these arguments: create, update or destroy");
		}
		
		if(!isset($params['create'])) {
			$params['create'] = [];
		}
		
		if(!isset($params['update'])) {
			$params['update'] = [];
		}
		
		if(!isset($params['destroy'])) {
			$params['destroy'] = [];
		}
		
		
		if(count($params['create']) + count($params['update'])  + count($params['destroy']) > Capabilities::get()->maxObjectsInSet) {
			throw new InvalidArguments("You can't set more than " . Capabilities::get()->maxObjectsInGet . " objects");
		}
		
		return new ArrayObject($params);
	}


	/**
	 * When doing a set we update and create models. But sometimes the models itself create or update other models. When this happen
	 * we must also return those in the client or it won't sync them because the chage occurred in the same modseq.
	 */
	private function trackSaves() {
		$cls = $this->entityClass();
		$cls::on(Entity::EVENT_SAVE, static::class, 'onEntitySave');		
	}

	public static $createdEntitities = [];
	public static $updatedEntitities = [];

	public static function onEntitySave(Entity $entity) {

		//$mod = array_map(function($mod) { return $mod[0];}, $entity->getModified()); //Get only modified values

		//Server should sent modified only but it's hard to check with getters and setters. So we just send all.
		if($entity->isNew()) {
			static::$createdEntitities[$entity->id()] = $entity->toArray();
		} else {
			static::$updatedEntitities[$entity->id()] = $entity->toArray();
		}
	}


	/**
	 * Put all modified entities tracked by trackSave into the result array
	 */
	private function mergeOtherSaves(&$result) {

		//build a list of ID's of entities that were created/ updated in the set requests. We can filter them out to avoid duplicates in the response.
		$setIds = [];
		if(isset($result['updated'])) {
			$setIds = array_keys($result['updated']);
		}
		if(isset($result['created'])) {
			$setIds = array_merge(array_map(function($mod) {return $mod['id'];}, $result['created']), $setIds);
		}

		static::$updatedEntitities = array_filter(static::$updatedEntitities, function($id) use($setIds) {
			return !in_array($id, $setIds);
		}, ARRAY_FILTER_USE_KEY);

		$result['updated'] = isset($result['updated']) ? array_replace($result['updated'], static::$updatedEntitities) : static::$updatedEntitities;
		if(empty($result['updated'])) {
			$result['updated'] = null;
		}

		static::$createdEntitities = array_filter(static::$createdEntitities, function($id) use($setIds) {
			return !in_array($id, $setIds);
		}, ARRAY_FILTER_USE_KEY);

		$result['created'] = isset($result['created']) ? array_replace($result['created'], static::$createdEntitities) : static::$createdEntitities;
		if(empty($result['created'])) {
			$result['created'] = null;
		}
	}

  /**
   * Handles the Foo entity setFoos command
   *
   * @param array $params
   * @return ArrayObject
   * @throws InvalidArguments
   * @throws StateMismatch
   * @throws Exception
   */
	protected function defaultSet(array $params): ArrayObject
	{
		$this->trackSaves();

		$p = $this->paramsSet($params);

		// make sure there are no concurrent set request to avoid clients missing states
		$lock = new Lock("jmap-set-lock");
		if (!$lock->lock()) {
			throw new Exception("Could not obtain lock");
		}

		static::fireEvent(self::EVENT_BEFORE_SET, $this, $p);

		$oldState = $this->getState();

		if (isset($p['ifInState']) && $p['ifInState'] != $oldState) {
			throw new StateMismatch("State mismatch. The server state " . $oldState . ' does not match your state ' .$p['ifInState']);
		}

		$result = new ArrayObject([
			'accountId' => $p['accountId'],
			'created' => null,
			'updated' => null,
			'destroyed' => null,
			'notCreated' => null,
			'notUpdated' => null,
			'notDestroyed' => null,
		]);

		$this->createEntitites($p['create'], $result);
		$this->updateEntities($p['update'], $result);
		$this->destroyEntities($p['destroy'], $result);

		$this->mergeOtherSaves($result);

		$result['oldState'] = $oldState;

		EntityType::push();

		$result['newState'] = $this->getState();


		static::fireEvent(self::EVENT_SET, $this, $p, $result);

		$lock->unlock();

		return $result;
	}

  /**
   * Create entities
   *
   * @param array $create
   * @param ArrayObject $result
   * @throws Exception
   */
	private function createEntitites(array $create, ArrayObject $result) {
		foreach ($create as $clientId => $properties) {

			$entity = $this->create($properties);
			
			if(!$this->canCreate($entity)) {
				$result['notCreated'][$clientId] = new SetError("forbidden", go()->t("Permission denied"));
				continue;
			}

			if ($entity->save()) {

				//refetch from server when mapping has a query object.
				if($entity::getMapping()->getQuery() != null) {
					$entity = $this->getEntity($entity->id());
					if(!$entity) {
						throw new NotFound("Item was created but not found?");
					}
				}

				$entityProps = new ArrayObject($entity->toArray());
				$diff = $entityProps->diff($properties);
				$diff['id'] = $entity->id();
				
				$result['created'][$clientId] = empty($diff) ? null : $diff;
			} else {				
				$result['notCreated'][$clientId] = new SetError("invalidProperties");
				$result['notCreated'][$clientId]->properties = array_keys($entity->getValidationErrors());
				$result['notCreated'][$clientId]->validationErrors = $entity->getValidationErrors();
			}
		}
	}

	/**
	 * Override this if you want to implement permissions for creating entities
	 * New properties have already been set so you can validate per property too if needed.
	 *
	 * @param Entity $entity
	 * @return boolean
	 */
	protected function canRead(Entity $entity): bool
	{
		return $entity->hasPermissionLevel(Acl::LEVEL_READ);
	}

  /**
   * Override this if you want to implement permissions for creating entities
   * New properties have already been set so you can validate per property too if needed.
   *
   * @param Entity $entity
   * @return boolean
   */
	protected function canCreate(Entity $entity): bool
	{
		return $entity->hasPermissionLevel(Acl::LEVEL_CREATE);
	}


  /**
   * Creates a single entity
   *
   * @param array $properties
   * @return Entity
   * @throws Exception
   * @todo Check permissions
   *
   */
	protected function create(array $properties): Entity
	{
		$cls = $this->entityClass();

		$entity = new $cls;
		$entity->setValues($properties); 
		
		return $entity;
	}

	/**
	 * Override this if you want to change the default permissions for updating an entity.
	 * New properties have already been set so you can validate per property too if needed.
	 *
	 * @param Entity $entity
	 * @return bool
	 */
	protected function canUpdate(Entity $entity): bool
	{
		return $entity->hasPermissionLevel(Acl::LEVEL_WRITE) && $this->checkAclChange($entity);
	}


	protected function checkAclChange(Entity $entity): bool
	{
		if(!($entity instanceof AclOwnerEntity)) {
			return true;
		}

		return $entity->getPermissionLevel() == Acl::LEVEL_MANAGE || !$entity->isAclModified();
	}


	/**
   * Updates the entities
   *
   * @param array $update
   * @param ArrayObject $result
   * @throws Exception
   */
	private function updateEntities(array $update, ArrayObject $result) {
		foreach ($update as $id => $properties) {
			if(empty($properties)) {
				$properties = [];
			}
			$entity = $this->getEntity($id);			
			if (!$entity) {
				$result['notUpdated'][$id] = new SetError('notFound', go()->t("Item not found"));
				continue;
			}
			
			//create snapshot of props client should be aware of
			$clientProps = array_merge($entity->toArray(), $properties);
			
			//apply new values before canUpdate so this function can check for modified properties too.
			$entity->setValues($properties);
			
			
			if(!$this->canUpdate($entity)) {
				$result['notUpdated'][$id] = new SetError("forbidden", go()->t("Permission denied"));
				continue;
			}
			
			if (!$entity->save()) {				
				$result['notUpdated'][$id] = new SetError("invalidProperties");				
				$result['notUpdated'][$id]->properties = array_keys($entity->getValidationErrors());
				$result['notUpdated'][$id]->validationErrors = $entity->getValidationErrors();				
				continue;
			}

			//refetch from server when mapping has a query object or caches values in getters that need clearing
			//if($entity::getMapping()->getQuery() != null) {
				$entity = $this->getEntity($id);
			//}
			
			//The server must return all properties that were changed during a create or update operation for the JMAP spec
			$entityProps = new ArrayObject($entity->toArray());			
			$diff = $entityProps->diff($clientProps);

			// In some rare cases like password values may be set but not retrieved. We must add "null" here for the client.
			foreach($properties as $key => $value) {
				if(!$entityProps->hasKey($key)) {
					$diff[$key] = null;
				}
			}

			$result['updated'][$id] = empty($diff) ? null : $diff;
		}
	}
	
	protected function canDestroy(Entity $entity): bool
	{
		return $entity->hasPermissionLevel(Acl::LEVEL_DELETE);
	}

  /**
   * Destroys entities
   *
   * @param int[] $destroy
   * @param ArrayObject $result
   * @throws InvalidArguments
   * @throws Exception
   */
	private function destroyEntities(array $destroy, ArrayObject $result) {

		$doDestroy = [];
		foreach ($destroy as $id) {
			$entity = $this->getEntity($id);
			if (!$entity) {
				$result['notDestroyed'][$id] = new SetError('notFound', go()->t("Item not found"));
				continue;
			}
			
			if(!$this->canDestroy($entity)) {
				$result['notDestroyed'][$id] = new SetError("forbidden", go()->t("Permission denied"));
				continue;
			}

			$doDestroy[] = $entity->id();
		}
		$cls = $this->entityClass();

		if(!empty($doDestroy)) {
			$query = new Query();
			foreach($doDestroy as $id) {
				$query->orWhere($cls::parseId($id));
			}
			$success = $cls::delete($query);
		} else {
			$success = true;
		}
			
		if ($success) {
			$result['destroyed'] = $doDestroy;
		} else {
			throw new Exception("Delete error");
		}
	}
	
	/**
	 * Takes the request arguments, validates them and fills it with defaults.
	 * 
	 * @param array $params
	 * @return array
	 * @throws InvalidArguments
	 */
	protected function paramsGetUpdates(array $params): ArrayObject
	{
		
		if(!isset($params['maxChanges'])) {
			$params['maxChanges'] = Capabilities::get()->maxObjectsInGet;
		}
		
		if ($params['maxChanges'] < 1 || $params['maxChanges'] > Capabilities::get()->maxObjectsInGet) {
			throw new InvalidArguments("maxChanges should be greater than 0 and smaller than 50");
		}
		
		if(!isset($params['sinceState'])) {
			throw new InvalidArguments('sinceState is required');
		}
		
		if(!isset($params['accountId'])) {
			$params['accountId'] = null;
		}
		
		return new ArrayObject($params);
		
	}


  /**
   * Handles the Foo entity's getFooUpdates command
   *
   * @param array $params
   * @return array
   * @throws InvalidArguments
   * @throws Exception
   */
	protected function defaultChanges(array $params): ArrayObject
	{
		$p = $this->paramsGetUpdates($params);	
		$cls = $this->entityClass();		
		
		$result = $cls::getChanges($p['sinceState'], $p['maxChanges']);

		$result['accountId'] = $p['accountId'];

		return new ArrayObject($result);
	}

  /**
   * @param $params
   * @return ArrayObject
   * @throws InvalidArguments
   */
	protected function paramsExport($params): ArrayObject
	{
		
		if(!isset($params['extension'])) {
			throw new InvalidArguments("'extension' parameter is required");
		}
		
		return $this->paramsGet($params);
	}

  /**
   * @param $params
   * @return ArrayObject
   * @throws InvalidArguments
   */
	protected function paramsImport($params) : ArrayObject {
		
		if(!isset($params['blobId'])) {
			throw new InvalidArguments("'blobId' parameter is required");
		}
		
		if(!isset($params['values'])) {
			$params['values'] = [];
		}
		
		return new ArrayObject($params);
	}
	
	/**
	 * Default handler for Foo/import method
	 * 
	 * @param array $params
	 * @return array
	 * @throws Exception
	 */
	protected function defaultImport(array $params): ArrayObject
	{

		ini_set('max_execution_time', 10 * 60);
		
		$params = $this->paramsImport($params);
		
		$blob = Blob::findById($params['blobId']);	

		$extension = (new File($blob->name))->getExtension();
		$converter = $this->findConverter($extension);

		if($extension == 'csv') {
			$file = $blob->getFile()->copy(File::tempFile($extension));
			$file->convertToUtf8();
		} else{
			$file = $blob->getFile();
		}

		$response = $converter->importFile($file, $params->getArray());
		
		if(!$response) {
			throw new Exception("Invalid response from import converter");
		}
		
		return new ArrayObject($response);
	}


	protected function defaultExportColumns($params): ArrayObject
	{
		$converter = $this->findConverter($params['extension']);

		$mapping = $converter->getEntityMapping();
		if(isset($mapping['customFields'])) {
			$mapping = array_merge($mapping, $mapping['customFields']['properties']);
			unset($mapping['customFields']);
		}

		return new ArrayObject($mapping);
	}
	
	/**
	 * Default handler for Foo/importCSVMapping method
	 * 
	 * @param array $params
	 * @return array
	 * @throws Exception
	 */
	protected function defaultImportCSVMapping(array $params): ArrayObject
	{
		$blob = Blob::findById($params['blobId']);

		$extension = (new File($blob->name))->getExtension();

		if($extension == 'csv') {
			$file = $blob->getFile()->copy(File::tempFile($extension));
			$file->convertToUtf8();
		} else{
			$file = $blob->getFile();
		}

		$converter = $this->findConverter($extension);
		
		$response['goHeaders'] = $converter->getEntityMapping();
		$response['csvHeaders'] = $converter->getCsvHeaders($file);
		
		if(!$response) {
			throw new Exception("Invalid response from import convertor");
		}
		
		return new ArrayObject($response);
	}

	/**
	 * Find a convertor for exporting or importing
	 *
	 * @param string $extension
	 * @return AbstractConverter
	 */
	private function findConverter(string $extension): AbstractConverter
	{
		
		$cls = $this->entityClass();		
		foreach($cls::converters() as $converter) {
			if($converter::supportsExtension($extension)) {
				return new $converter($extension, $this->entityClass());
			}
		}
		
		throw new InvalidArgumentException("Converter for file extension '" . $extension .'" is not found');
	}

  /**
   * Standard export function
   *
   * You can use Foo/query first and then pass the ids of that result to
   * Foo/export().
   *
   * @param array $params Identical to Foo/get. Additionally you MUST pass a 'extension'. It will find the converter class using the Entity::converter() method.
   * @return array
   * @throws InvalidArguments
   * @throws Exception
   * @see AbstractConverter
   *
   */
	protected function defaultExport(array $params): ArrayObject
	{
		go()->getEnvironment()->setMaxExecutionTime(10 * 60);
		
		$params = $this->paramsExport($params);
		
		$convertor = $this->findConverter($params['extension']);
				
		$entities = $this->getGetQuery($params);

		$blob = $convertor->exportToBlob($entities, $params->getArray());
		
		return new ArrayObject(['blobId' => $blob->id, 'blob' => $blob->toArray()]);
	}

  /**
   * Merge entities into one
   *
   * The first ID in the list will be kept after the merge.
   * @param $params
   * @return array
   * @throws Forbidden
   * @throws InvalidArguments
   * @throws Exception
   */
	protected function defaultMerge($params): ArrayObject
	{
		if(empty($params['ids'])) {
			throw new InvalidArguments('ids is required');
		}

		if(count($params['ids']) < 2) {
			throw new InvalidArguments('At least 2 id\'s are required');

		}
		$primaryId = array_shift($params['ids']);

		$cls = $this->entityClass();

		/** @var Entity $cls */

		$entity = $cls::findById($primaryId);

		if(!$this->canUpdate($entity)) {
			throw new Forbidden();
		}

		$oldState = $this->getState();

		go()->getDbConnection()->beginTransaction();
		foreach($params['ids'] as $id) {
			$other = $cls::findById($id);
			if(!$this->canDestroy($other)) {
				throw new Forbidden();
			}
			if(!$entity->merge($other)) {
				throw new Exception("Failed to merge ID: ".$id . ", Validation errors: ". var_export($entity->getValidationErrors(), true));
			}
		}

		go()->getDbConnection()->commit();

		return new ArrayObject([
			"id" => $primaryId,
			"updated" => [$primaryId => $entity],
			"destroyed" => $params['ids'],
			'oldState' => $oldState,
			'newState' => $this->getState()
		]);
	}
}
