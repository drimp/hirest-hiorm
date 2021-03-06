<?php

namespace Hirest\Hiorm;


class Model {


	const COLLECT_METHOD_AND = 'AND';
	const COLLECT_METHOD_OR  = 'OR';
	const DIRECTION_ASC      = 'ASC';
	const DIRECTION_DESC     = 'DESC';

	public static $connection = null; // PDO connection

	public static $table = null; // Models table

	public $safe_delete = false; // Safe delete flag (table should have deleted field)
	public $timestamps  = false; // Automatic timestamps handling (table should have created_at, updated_at fields)

	public static $debug_errors = false;

	// Query Builder variables
	protected $selectFields  = null;
	protected $conditions    = []; // WHERE conditions array
	protected $subConditions = null; // Sub-queries array
	protected $joins         = []; // joins array
	protected $limit         = null; // Be careful! Not used if null
	protected $orderBy       = []; // Not used if null
	protected $collectMethod = self::COLLECT_METHOD_AND; // Next WHERE block composition method


	protected $isNew        = true; // Not yet inserted into table
	protected $lastSQL      = null; // Last query placed here
	protected $modelData    = [];
	protected $originalData = []; // The Data before manipulation


	function __construct($data = null) {
		if ($data !== null) {
			$this->originalData = $data;
			$this->modelData    = $data;
			$this->isNew        = false;
		}
	}


	/**
	 * Return all rows by conditions from array
	 *
	 * @param null $conditions array field => value or ID
	 * @return \Hirest\Hiorm\Model
	 */
	public static function find($conditions = null) {
		$instance = static::select();
		if (is_array($conditions)) {
			foreach ($conditions AS $field => $value) {
				$instance->where($field, $value);
			}

			return $instance->get();
		} elseif (is_numeric($conditions)) {
			$instance->where('id', $conditions);

			return $instance->first();
		}
	}


	/**
	 * Set fields to fetch from table
	 * Return an empty model object
	 *
	 * @param string $fields - all by default
	 * @return \Hirest\Hiorm\Model
	 */
	public static function select($fields = '*') {
		$instance = new static;

		// If param is array of fields lets spit them
		if (is_array($fields)) {
			$fields = implode(', ', $fields);
		}
		$instance->selectFields = $fields;

		return $instance;
	}


	/**
	 * Add a condition to WHERE block
	 * accept 1,2 or 3 parameters
	 * In case last not set - comparison condition is "equal"
	 * In case one param set - assert a callable (what mean a new sub-query will be created)
	 *
	 * @param      $field  string
	 * @param      $param  string comparison conditions =,<,> (or value if 3rd param not set)
	 * @param null $param2 string conditional value
	 * @return \Hirest\Hiorm\Model
	 */
	public function where($field, $param = null, $param2 = null) {

		if (!isset($this)) {
			return static::select()->where($field, $param, $param2);
		}

		// If field param is callable
		// return a new sub-query
		if (is_object($field) && ($field instanceof \Closure)) {
			return $this->subWhere($field);
		}

		$equality = $param;
		$value    = $param2;

		// By default comparison condition is "equal" (if 2 param set only)
		if (is_null($param2)) {
			$equality = '=';
			$value    = $param;
		}

		$condition = [
			'method'    => $this->collectMethod,
			'field'     => $field,
			'condition' => $equality,
			'value'     => $value
		];

		// Add condition to common pool
		// If sub-query is on then add condition to sub-queries pool
		if (is_null($this->subConditions)) {
			$this->conditions[] = $condition;
		} else {
			$this->subConditions[] = $condition;
		}

		return $this;
	}


	/**
	 * Add condition to sub-queries pool
	 *
	 * @param $func \Closure
	 * @return $this
	 */
	protected function subWhere(\Closure $func) {
		// Rememeber collect methods
		// because later it can be changed
		$method = $this->collectMethod;

		// Open a sub-block
		$this->subConditions = [];
		$func($this);
		$this->conditions[] = [
			'is_group'   => true,
			'method'     => $method,
			'conditions' => $this->subConditions
		];

		// Close sub-block
		$this->subConditions = null;

		return $this;
	}


	/**
	 * Возвращает результат запроса в виде коллекции моделей
	 * Return the result as a collection of models
	 *
	 * @return \Hirest\Hiorm\Model[]
	 */
	public function get() {
		$rows                = $this->query();
		$collection          = new \Hirest\Hiorm\ModelCollection();
		$collection->lastSQL = $this->lastSQL;
		while ($row = $rows->fetch()) {
			$collection->add(static::makeModel($row, false));
		}

		return $collection;
	}


	/**
	 * Do a query
	 *
	 * @param null $SQL
	 * @param bool $write if it write query (insert-query)
	 * @return array|resource
	 * @throws \Exception
	 */
	public function query($SQL = null, $write = false) {
		if (is_null($SQL)) {
			$SQL = $this->buildSQL();
		}
		$this->lastSQL = $SQL;
		$result        = static::$connection->query($SQL, \PDO::FETCH_ASSOC);
		if (!$result) {
			$error = 'Query error';
			if (static::$debug_errors) {
				$error .= ': ' . static::$connection->errorInfo()[2] . PHP_EOL . ' SQL:' . $SQL;
			}
			throw new \Exception($error);
		}

		return $result;
	}


	/**
	 * Build query to SQL string
	 *
	 * @return string
	 */
	public function buildSQL() {
		$SQL = 'SELECT ' . $this->selectFields
			. " FROM " . static::$table;


		if (!empty($this->joins)) {
			foreach ($this->joins AS $join) {
				$SQL .= PHP_EOL
					. $join['type'] . " JOIN "
					. $join['table'] . " ON "
					. $join['on'];
			}
			$SQL .= PHP_EOL;
		}

		if (!empty($this->conditions)) {
			$SQL .= " WHERE " . $this->buildWhereSQL();
		}

		if (!empty($this->orderBy)) {
			$SQL .= " ORDER BY " . implode(', ', $this->orderBy);
		}
		if (!is_null($this->limit)) {
			$SQL .= " LIMIT " . $this->limit;
		}

		return $SQL;
	}


	/**
	 * Build WHERE block to SQL string
	 *
	 * @param null $conditions_array
	 * @return string
	 */
	protected function buildWhereSQL($conditions_array = null) {
		// By default build already set conditions for current object
		if (is_null($conditions_array)) {
			$conditions_array = $this->conditions;
		}


		$where = '';
		foreach ($conditions_array AS $condition) {
			if (!empty($where)) {
				$where .= ' ' . $condition['method'];
			}

			// If sub-query
			if (isset($condition['is_group'])) {
				$where .= ' ('
					. $this->buildWhereSQL($condition['conditions'])
					. ')';
			} else {
				$equality = $condition['condition'];
				if ($condition['value'] === null) {
					if ($equality == '=') {
						$equality = 'IS';
					} else {
						$equality = 'IS NOT';
					}
				}

				// Не экранируем если value массив (значит имеется ввиду поле или массив значений)
				// Do not escape if value is array (it mean field or array of values)
				if (is_array($condition['value'])) {
					// Группа значений
					if ($equality == 'in' || $equality == 'IN') {
						$condition['value'] = array_map(function($item) {
							return self::$connection->quote($item);
						}, $condition['value']);
						$value              = '(' . implode(', ', $condition['value']) . ')';
					} else { // поле
						$value = $condition['value'][0];
					}
				} else {
					$value = static::$connection->quote($condition['value']);
				}
				$where .= ' '
					. $condition['field'] . ' '
					. $equality . ' '
					. $value;
			}
		}

		return $where;
	}


	/**
	 * Return model object with data
	 * same new \Hirest\Hiorm\Model($modelData)
	 *
	 * @param $modelData - data array
	 * @param $isNew     - are this model still not inserted in DB
	 * @return \Hirest\Hiorm\Model
	 */
	public static function makeModel($modelData, $isNew = true) {
		$model_name   = get_called_class();
		$model        = new $model_name($modelData);
		$model->isNew = $isNew;

		return $model;
	}


	/**
	 * Return single row from table by conditions array
	 *
	 * @param null $conditions array [field => search_value] OR ID
	 * @return YModel
	 */
	public function first($conditions = null) {
		if (!isset($this)) {
			$instance = static::select();
		} else {
			$instance = $this;
		}

		$instance->limit(1);

		if (is_array($conditions)) {
			foreach ($conditions AS $field => $value) {
				$instance->where($field, $value);
			}
		} elseif (is_numeric($conditions)) {
			$instance->where('id', $conditions);
		}

		return $instance->get()->first();
	}


	/**
	 * Set a limit of rows
	 *
	 * @param     $limit
	 * @param int $from
	 * @return $this
	 */
	public function limit($limit, $from = 0) {
		$this->limit = (int)$limit . ' OFFSET ' . (int)$from;

		return $this;
	}


	/**
	 * Model data getter
	 *
	 * @param $name - field name
	 * @return null - field value
	 */
	public function __get($name) {
		if (!isset($this->modelData[ $name ])) {
			return null;
		}
		$result = $this->modelData[ $name ];

		return $result;
	}


	/**
	 * Model data setter
	 *
	 * @param $name  - field name
	 * @param $value - field value
	 */
	public function __set($name, $value) {
		$this->modelData[ $name ] = $value;
	}


	/**
	 * Wrapper for additional AND WHERE blocks
	 *
	 * @param      $field
	 * @param null $param
	 * @param null $param2
	 * @return \Hirest\Hiorm\Model
	 */
	public function andWhere($field, $param = null, $param2 = null) {
		$this->collectMethod = self::COLLECT_METHOD_AND;

		return $this->where($field, $param, $param2);
	}


	/**
	 * Wrapper for additional OR WHERE blocks
	 *
	 * @param      $field
	 * @param null $param
	 * @param null $param2
	 * @return \Hirest\Hiorm\Model
	 */
	public function orWhere($field, $param = null, $param2 = null) {
		$this->collectMethod = self::COLLECT_METHOD_OR;

		return $this->where($field, $param, $param2);
	}


	/**
	 * Set row ordering rules
	 *
	 * @param        $field
	 * @param string $direction
	 * @return $this
	 */
	public function order($field, $direction = self::DIRECTION_ASC) {
		$this->orderBy[] = $field . " " . $direction;

		return $this;
	}


	/**
	 * Delete row from table
	 *
	 * @return $this|array|resource
	 * @throws \Exception
	 */
	public function delete() {
		if (!isset($this->modelData['id'])) {
			throw new \Exception('Cant delete from table ' . static::$table . ' ID missed');
		}

		if ($this->isNew()) {
			throw new \Exception('Cant delete from table ' . static::$table . ' because model is new');
		}

		if ($this->safe_delete) {
			$this->modelData['deleted'] = 1;
			$this->save();

			return $this;
		}

		$SQL = "DELETE FROM " . static::$table . " WHERE id = '" . $this->modelData['id'] . "'";

		return $this->query($SQL);
	}


	/**
	 * Is object are not yet inserted into table
	 *
	 * @return bool
	 */
	public function isNew() {
		return $this->isNew;
	}


	/**
	 * Save row to table
	 *
	 * @return $this|array|resource
	 */
	public function save() {
		if ($this->isNew()) {
			return $this->insert();
		}

		return $this->update();;
	}


	/**
	 * Insert new row
	 *
	 * @return $this
	 * @throws \Exception
	 */
	protected function insert() {
		if (!$this->isNew()) {
			throw new \Exception('Cant insert to table ' . static::$table . ' because model is not new');
		}
		if ($this->modelData === null || empty($this->modelData)) {
			throw new \Exception('Cant insert to table ' . static::$table . ' because model_data is empty');
		}

		// If model have timestamps - handle it
		if ($this->timestamps) {
			$this->modelData['updated_at'] = date('Y-m-d H:i:s');
			$this->modelData['created_at'] = date('Y-m-d H:i:s');
		}

		$values = [];
		foreach ($this->modelData AS $value) {
			if (is_array($value)) {
				$values[] = array_shift($value);
			} else {
				$values[] = "'" . $value . "'";
			}
		}
		$query = "INSERT INTO `" . static::$table . "` (`"
			. implode('`, `', array_keys($this->modelData)) . "`) VALUES ("
			. implode(", ", $values) . ")";
		$this->query($query, true);
		$this->modelData['id'] = static::$connection->lastInsertId();
		$this->isNew           = false;

		return $this;
	}


	/**
	 * Return model data array
	 *
	 * @return array
	 */
	public function getModelData($cast_types = true) {
		if ($cast_types) {
			return self::cast_types($this->modelData);
		}

		return $this->modelData;
	}


	protected static function cast_types($array) {
		return array_map(function($string) {
			if ($string === null) {
				return null;
			}
			if (\is_iterable($string)) {
				return self::cast_types($string);
			}
			$string = trim($string);
			if (!preg_match("/[^0-9.]+/", $string)) {
				if (preg_match("/[.]+/", $string)) {
					return (double)$string;
				} else {
					return (int)$string;
				}
			}
			if ($string == "true") return true;
			if ($string == "false") return false;

			return (string)$string;
		}, $array);
	}

	/**
	 * Return original model data array
	 *
	 * @return array
	 */
	public function getOriginalData() {
		return $this->originalData;
	}

	/**
	 * Update row data in table
	 *
	 * @return array|resource
	 * @throws \Exception
	 */
	protected function update() {

		// Can not update if row have not ID value
		if (!isset($this->modelData['id'])) {
			throw new \Exception('Cant update table ' . static::$table . ' ID missed');
		}

		// Can not update if row was not saved
		if ($this->isNew()) {
			throw new \Exception('Cant update table ' . static::$table . ' because model is new');
		}

		// If model have timestamps
		if ($this->timestamps) {
			$this->modelData['updated_at'] = date('Y-m-d H:i:s');
		}

		// Build a query
		$SQL       = "UPDATE " . static::$table . " SET";
		$SET_parts = array();
		foreach ($this->modelData AS $field => $value) {
			if ($value !== null) {
				$value       = is_array($value) ? array_shift($value) : static::$connection->quote($value);
				$SET_parts[] = " {$field} = {$value}";
			} else {
				$SET_parts[] = " {$field} = NULL";
			}
		}

		$SQL .= implode(',', $SET_parts) . " WHERE id = " . static::$connection->quote($this->modelData['id']);
		if ($this->limit) {
			$SQL .= " LIMIT " . $this->limit;
		}

		return $this->query($SQL, true);
	}


	/**
	 * Add a JOIN to join-pool
	 *
	 * @param          $table - joining table
	 * @param string $type    - INNER, LEFT, RIGHT, FULL
	 * @param \Closure $join  - callback for query builder
	 * @return $this
	 */
	private function join($table, $type = 'INNER', \Closure $join) {
		$tempModel = new \Hirest\Hiorm\Model();
		$join($tempModel);
		$this->joins[] = [
			'table' => $table,
			'type'  => $type,
			'on'    => $tempModel->buildWhereSQL()
		];

		return $this;
	}


	/**
	 * Add a LEFT JOIN to join-pool
	 *
	 * @param          $table - joining table
	 * @param \Closure $join  - callback for query builder
	 * @return $this
	 */
	public function leftJoin($table, \Closure $join) {
		return $this->join($table, 'LEFT', $join);
	}

	/**
	 * Add a RIGHT JOIN to join-pool
	 *
	 * @param          $table - Joining table
	 * @param \Closure $join  - callback for query builder
	 * @return $this
	 */
	public function rightJoin($table, \Closure $join) {
		return $this->join($table, 'RIGHT', $join);
	}

	/**
	 * Add a INNER JOIN to join-pool
	 *
	 * @param          $table - Joining table
	 * @param \Closure $join  - callback for query builder
	 * @return $this
	 */
	public function innerJoin($table, \Closure $join) {
		return $this->join($table, 'INNER', $join);
	}

	/**
	 * Add a FULL JOIN to join-pool
	 *
	 * @param          $table - Joining table
	 * @param \Closure $join  - callback for query builder
	 * @return $this
	 */
	public function fullJoin($table, \Closure $join) {
		return $this->join($table, 'FULL', $join);
	}


	/**
	 * Return model data as JSON
	 *
	 * @param null $options - json_encode options
	 * @return string
	 */
	public function toJson($options = null) {
		return json_encode($this->toArray(), $options);
	}


	/**
	 * wrapper for getModelData
	 *
	 * @return array
	 */
	public function toArray() {
		return $this->getModelData();
	}


}