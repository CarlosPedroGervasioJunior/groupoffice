<?php
namespace go\core\db;

use go\core\data\ArrayableInterface;
use go\core\ErrorHandler;
use Exception;
use go\core\orm\Property;
use JsonSerializable;
use PDOException;
use PDOStatement;

/**
 * PDO Statement
 * 
 * Represents a prepared statement and, after the statement is executed, an
 * associated result set.
 */
class Statement extends PDOStatement implements JsonSerializable, ArrayableInterface{
	
	private $query;

	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this->fetchAll();
	}

	public function toArray(array $properties = null): array
	{
		return $this->fetchAll();
	}

	/**
	 * @var array
	 */
	private $fetchMode;

	/**
	 * @inheritDoc
	 */
	public function setFetchMode($mode, $className = null, array $params = [])
	{
		$this->fetchMode = func_get_args();

		call_user_func_array(['parent', 'setFetchMode'], $this->fetchMode);
	}


	/**
	 * Change owner constructor parameter for results in cached Statement create in Property::internalFind()
	 *
	 * @param Property $owner
	 * @return void
	 */
	public function setOwner(Property $owner) {
		$this->fetchMode[2][0] = $owner;

		call_user_func_array(['parent', 'setFetchMode'], $this->fetchMode);
	}

	/**
	 * Set's the select query object
	 * 
	 * @param Query $query
	 */
	public function setQuery(Query $query) {
		$this->query = $query;
	}
	
	/**
	 * Get query object that was used to create this statement.
	 * 
	 * Only available for select queries
	 *  
	 * @return Query
	 */
	public function getQuery(): Query
	{
		return $this->query;
	}
	
	private $build;
	
	/**
	 * Set's the build array produced by QueryBuilder. Only used to cast this object
	 * to string when debugging.
	 * 
	 * @param array $build
	 */
	public function setBuild(array $build) {
		$this->build = $build;
	}

	public function __toString() {
		try {
			if(!isset($this->build)) {
				return "Can't render SQL. Please check debug log.";
			}
			return QueryBuilder::debugBuild($this->build);
		} catch(Exception $e) {
			ErrorHandler::logException($e);
			return "Error: Could not convert SQL to string: " . $e->getMessage();
		}
	}

	/**
	 * Output query to debugger
	 *
	 * @return $this
	 */
	public function debug(): Statement
	{
		if(go()->getDebugger()->enabled) {
			go()->debug((string)$this);
		}

		return $this;
	}

	/**
	 * Executes a prepared statement
	 *
	 * @param array|null $params An array of values with as many elements as there are bound parameters in the SQL statement being executed. All values are treated as PDO::PARAM_STR.
	 *
	 * Multiple values cannot be bound to a single parameter; for example, it is not allowed to bind two values to a single named parameter in an IN() clause.
	 *
	 * Binding more values than specified is not possible; if more keys exist in input_parameters than in the SQL specified in the PDO::prepare(), then the statement will fail and an error is emitted.
	 *
	 * @throws PDOException
	 * @return bool Always returns true but must be compatible with PHP function
	 */
	public function execute($params = null): bool
	{
		try {

			if(isset($params) && isset($this->build['params'])) {
				$keys = array_keys($this->build['params']);
				foreach($params as $v) {
					$key = array_shift($keys);
					$this->build[$key] = $v;
				}
			}
			
			parent::execute($params);

			if(go()->getDbConnection()->debug && isset($this->build) && go()->getDebugger()->enabled) {
				$duration  = number_format((go()->getDebugger()->getMicrotime() * 1000) - ($this->build['start'] * 1000), 2);

				$sql = QueryBuilder::debugBuild($this->build);
				go()->debug(str_replace(["\n","\t"], [" ", ""], $sql) . "\n(" . $duration . 'ms)', 5);
			}

			return true;
		}
		catch(PDOException $e) {
			go()->error("SQL FAILURE: " . $this);
			throw $e;
		}
	}
	
}
