<?php

namespace AiraGroupSro\Dobee\Provider;

use AiraGroupSro\Dobee\Transformer\Transformer;
use AiraGroupSro\Dobee\Traits\ModelHelper;
use AiraGroupSro\Dobee\Exception\DatabaseException;
use AiraGroupSro\Dobee\Exception\UnknownOperationException;
use AiraGroupSro\Dobee\Exception\InvalidPropertyTypeException;
use AiraGroupSro\Dobee\LazyLoader\SingleLazyLoader;
use AiraGroupSro\Dobee\LazyLoader\MultipleLazyLoader;
use AiraGroupSro\Dobee\Provider\Changelog;

class Provider {

	use ModelHelper;

	protected $connection;
	protected $entityNamespace;
	protected $model;
	protected $blame;

	public function __construct($connection,$entityNamespace,$model){
		$this->connection = $connection;
		$this->entityNamespace = $entityNamespace;
		$this->model = $model;
	}

	public function setBlame($blame){
		$this->blame = $blame;
		return $this;
	}

	public function fetchBlame($entity,$blame){
		$blameable = $this->getEntityBlameable($entity);
		if(true === isset($blameable['targetEntity'])){
			return $this->fetchOne($blameable['targetEntity'],$blame);
		}
		else{
			return null;
		}
	}

	public function fetchOne($entityName,$primaryKey = null,$options = array()){
		$types = array();
		$params = array();

		/// select
		$select = $this->getSelect($entityName,$options);
		/// joins (join, left join)
		$join = $this->getJoins($options,$entityName);
		/// where
		$where = $this->getWhere($entityName,$options,$types,$params);
		/// add PK to the where clause
		if(!is_null($primaryKey)){
			if(strlen($where) <= 0){
				$where .= " WHERE";
			}
			else{
				$where .= " AND";
			}
			$where .= " this.`".Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($entityName))."` = ?";
			$types[] = $this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName));
			$params[] = $this->resolveValue($entityName,$this->getPrimaryKeyForEntity($entityName),$primaryKey);
		}
		/// if entity is soft-deletable add condition
		if($this->isSoftDeletable($entityName) === true){
			if(strlen($where) <= 0){
				$where .= " WHERE";
			}
			else{
				$where .= " AND";
			}
			$where .= " this.`deleted` = 0";
		}
		/// order
		$order = $this->getOrderBy($options);
		/// limit
		$limit = " LIMIT 0,1";
		/// fetch result
		$result = $this->execute($select.$join.$where.$order.$limit,$types,$params);

		if(is_array($result) && count($result)){
			if(isset($options['result']) && $options['result'] === 'array'){
				return $result[0];
			}
			else{
				return $this->hydrateEntity($entityName,$result[0]);
			}
		}

		return null;
	}

	public function fetch($entityName,$options = array()){
		$types = array();
		$params = array();

		/// select
		$select = $this->getSelect($entityName,$options);
		/// joins (join, left join)
		$join = $this->getJoins($options,$entityName);
		/// where
		$where = $this->getWhere($entityName,$options,$types,$params);
		/// if entity is soft-deletable add condition
		if($this->isSoftDeletable($entityName) === true){
			if(strlen($where) <= 0){
				$where .= " WHERE";
			}
			else{
				$where .= " AND";
			}
			if ($options['showDeleted'] ?? false) {
				$where .= " this.`deleted` = 1";
			} else {
				$where .= " this.`deleted` = 0";
			}
		}

		/// order
		$order = $this->getOrderBy($options);
		/// limit
		$limit = $this->getLimit($options);
		/// fetch result
		$result = $this->execute($select.$join.$where.$order.$limit,$types,$params);

		$results = array();
		if(is_array($result) && count($result)){
			if(isset($options['result']) && $options['result'] === 'array'){
				return $result;
			}
			else{
				foreach ($result as $key => $rowData) {
					$results[$rowData[Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($entityName))]] = $this->hydrateEntity($entityName,$rowData);
				}
			}
		}

		return $results;
	}

	public function save($entity, $log = true)
	{
		if(!is_null($entity->getPrimaryKey())){
			$this->doUpdate($entity, $log);
		}
		else{
			$this->doInsert($entity, $log);
		}
	}

	public function delete($entity){
		if(!is_null($entity)){
			if(method_exists($entity,'delete')){
				$entity->delete();
				$this->update($entity);
			}
			else{
				$this->doDelete($entity);
			}
		}
	}

	protected function prepareVersionData($entity){
		$reflection = new \ReflectionClass($entity);
		$properties = $reflection->getProperties();
		$versionData = [];

		if(count($properties) > 0){
			foreach ($properties as $property) {
				$property->setAccessible(true);
				$value = $property->getValue($entity);
				$name = $property->getName();

				/// skip blame object
				if($name === 'blame'){
					continue;
				}

				if(is_object($value)){
					/// skip changelog
					if($value instanceof Changelog){
						continue;
					}
					/// process single lazy-loaded object
					else if($value instanceof SingleLazyLoader){
						$versionData[$name] = [
							'entity' => $name,
							'id' => $value->getPrimaryKey(),
						];
					}
					/// process multiple lazy-loaded objects
					else if($value instanceof MultipleLazyLoader){
						$value->loadData();
						$value = $property->getValue($entity);
						$versionData[$name] = [
							'entity' => $name,
							'id' => array_keys($value)
						];
					}
					/// process other objects
					else{
						/// check wheter object is a mapped entity
						if(true === method_exists($value,'getPrimaryKey')){
							$versionData[$name] = [
								'entity' => $name,
								'id' => $value->getPrimaryKey(),
							];
						}
						else{
							$versionData[$name] = $value;
						}
					}
				}
				else if(is_array($value)){
					if(count($value) > 0){
						foreach ($value as $id => $item) {
							if(true === method_exists($item,'getPrimaryKey')){
								$versionData[$name] = [
									'entity' => $name,
									'id' => array_keys($value)
								];
							}
							else{
								$versionData[$name] = array_keys($value);
							}
							break;
						}
					}
					else{
						$versionData[$name] = array_keys($value);
					}
				}
				else{
					$versionData[$name] = $value;
				}
			}
		}

		return $versionData;
	}

	protected function log($actionType,$entity): bool
	{
		$entityName = $this->getEntityName($entity);
		if(true === $this->isEntityLoggable($entityName)){
			$entityId = $this->resolveValue($entityName,$this->getPrimaryKeyForEntity($entityName),$entity->getPrimaryKey());

			/// get version number
			$query = "SELECT version FROM `log_storage` WHERE entity_class = ? AND entity_id = ? ORDER BY version DESC LIMIT 0,1";
			$types = ['s','s'];
			$params = [
				$entityName,
				$entityId,
			];
			$result = $this->execute($query,$types,$params);
			if(true === is_array($result) && count($result) > 0){
				$version = intval($result[0]['version']) + 1;
			}
			else{
				$version = 0;
			}

			$versionData = $this->prepareVersionData($entity);

			/// create log query
			$query = "INSERT INTO `log_storage` SET action_type = ?, blame = ?, entity_class = ?, entity_id = ?, version = ?, logged_at = NOW(), data = ?";
			$types = ['s','s','s','s','i','s'];
			$params = [
				$actionType, /// action_type
				$this->blame, /// blame
				$entityName, /// entity_class
				$entityId, /// entity_id
				$version, /// version
				serialize($versionData), /// data
			];
			$this->execute($query,$types,$params);
			/// logged
			return true;
		}
		/// nothing logged
		return false;
	}

	protected function doUpdate($entity, $log = true)
	{
		$types = array();
		$params = array();

		$entityName = $this->getEntityName($entity);

		$query = "UPDATE `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName))."` SET ";
		$query .= $this->getSaveQueryBody($entity,$entityName,$types,$params);
		$query .= " WHERE `".Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($entityName))."` = ?";
		$types[] = $this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName));
		$params[] = $this->resolveValue($entityName,$this->getPrimaryKeyForEntity($entityName),$entity->getPrimaryKey());

		/// execute update
		$this->execute($query,$types,$params);

		/// many-to-many relations
		$this->saveManyToManyRelations($entity,$entityName);

		/// log action
		if ($log) {
			$this->log('update', $entity);
		}
	}

	protected function doInsert($entity, $log = true)
	{
		$types = array();
		$params = array();

		$entityName = $this->getEntityName($entity);

		$query = "INSERT INTO `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName))."` SET ";
		$query .= $this->getSaveQueryBody($entity,$entityName,$types,$params);

		/// execute update
		$this->execute($query,$types,$params);
		$entity->setPrimaryKey($this->connection->insert_id);

		/// many-to-many relations
		$this->saveManyToManyRelations($entity,$entityName);

		/// log action
		if ($log) {
			$this->log('create', $entity);
		}
	}

	protected function doDelete($entity)
	{
		$types = array();
		$params = array();

		$entityName = $this->getEntityName($entity);

		$query = "DELETE FROM `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName))."`";
		$query .= " WHERE `".Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($entityName))."` = ?";
		$types[] = $this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName));
		$params[] = $this->resolveValue($entityName,$this->getPrimaryKeyForEntity($entityName),$entity->getPrimaryKey());

		/// execute update
		$this->execute($query,$types,$params);

		/// log action
		$this->log('delete',$entity);
	}

	protected function getSaveQueryBody($entity,$entityName,&$types,&$params){
		$query = "";

		/// set who to blame (author) if not yet set
		if(true === $this->isEntityBlameable($entityName)){
			$blameable = $this->getEntityBlameable($entityName);
			$nullValue = (true === isset($blameable['nullValue']) ? $blameable['nullValue'] : null);
			if($nullValue === $entity->{'get'.ucfirst($blameable['property'])}() || null === $entity->{'get'.ucfirst($blameable['property'])}()){
				$entity->{'set'.ucfirst($blameable['property'])}($this->blame);
			}
		}

		$properties = $this->getEntityProperties($entityName);
		if(count($properties)){
			foreach ($properties as $property) {
				if($this->getPrimaryKeyForEntity($entityName) == $property) continue;	/// skip primary key
				$query .= "`".Transformer::camelCaseToUnderscore($property)."` = ?, ";
				$types[] = $this->resolvePropertyStatementType($entityName,$property);
				$params[] = $this->resolveValue($entityName,$property,$entity->{'get'.ucfirst($property)}());
			}
		}

		$relations = $this->getEntityRelations($entityName,true);
		if(count($relations)){
			foreach ($relations as $relatedEntity => $cardinality) {
				if(in_array($cardinality,array('SELF::MANY_TO_ONE','SELF::ONE_TO_MANY'))){
					if(!is_null($entity->{'getParent'.ucfirst($relatedEntity)}())){
						$query .= "`".Transformer::camelCaseToUnderscore($relatedEntity)."_id` = ?, ";
						$types[] = $this->resolvePropertyStatementType($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity));
						$params[] = $this->resolveValue($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity),$entity->{'getParent'.ucfirst($relatedEntity)}()->getPrimaryKey());
					}
					else{
						$query .= "`".Transformer::camelCaseToUnderscore($relatedEntity)."_id` = NULL, ";
					}
				}
				else if(in_array($cardinality,array('<<ONE_TO_ONE','MANY_TO_ONE'))){
					if(!is_null($entity->{'get'.ucfirst($relatedEntity)}())){
						$query .= "`".Transformer::camelCaseToUnderscore($relatedEntity)."_id` = ?, ";
						$types[] = $this->resolvePropertyStatementType($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity));
						$params[] = $this->resolveValue($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity),$entity->{'get'.ucfirst($relatedEntity)}()->getPrimaryKey());
						/// if an abstract entity is the relation add query for discriminator
						if(true === array_key_exists('abstract',$this->model[$relatedEntity])){
							$query .= "`".Transformer::camelCaseToUnderscore($relatedEntity)."_class` = ?, ";
							$types[] = 's';
							$params[] = $entity->{'get'.ucfirst($relatedEntity).'Class'}();
						}
					}
					else{
						$query .= "`".Transformer::camelCaseToUnderscore($relatedEntity)."_id` = NULL, ";
						/// if an abstract entity is the relation add query for discriminator
						if(true === array_key_exists('abstract',$this->model[$relatedEntity])){
							$query .= "`".Transformer::camelCaseToUnderscore($relatedEntity)."_class` = NULL, ";
						}
					}
				}
			}
		}

		if(strlen($query) > 0){
			$query = substr($query,0,-2);
		}

		return $query;
	}

	protected function saveManyToManyRelations($entity,$entityName){
		$relations = $this->getEntityRelations($entityName,true);
		if(count($relations)){
			foreach ($relations as $relatedEntity => $cardinality) {
				if($cardinality === 'SELF::MANY_TO_MANY'){
					/// fetch current relations from database
					$currentRelationsResult = $this->execute(
						"SELECT * FROM `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntity))."` WHERE master_".Transformer::camelCaseToUnderscore($entityName)."_id = ?",
						array(
							$this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName))
						),
						array(
							$entity->getPrimaryKey()
						)
					);
					$currentRelations = array();
					if(is_array($currentRelationsResult) && count($currentRelationsResult)){
						foreach ($currentRelationsResult as $rowData) {
							$currentRelations[$rowData['slave_'.Transformer::camelCaseToUnderscore($relatedEntity).'_id']] = $rowData['slave_'.Transformer::camelCaseToUnderscore($relatedEntity).'_id'];
						}
					}
					/// get relations as set during business logic operation
					$notPersistedRelations = array();
					$slave = $entity->{'getSlave'.ucfirst(Transformer::pluralize($relatedEntity))}();
					if(is_countable($slave) && count($slave)){
						foreach ($entity->{'getSlave'.ucfirst(Transformer::pluralize($relatedEntity))}() as $key => $relatedItem) {
							$notPersistedRelations[$relatedItem->getPrimaryKey()] = $relatedItem->getPrimaryKey();
						}
					}
					/// check for removed relations (and remove them from database)
					if(is_countable($currentRelations) && count($currentRelations)){
						foreach ($currentRelations as $key => $id) {
							if(!array_key_exists($id,$notPersistedRelations)){
								$this->execute(
									"DELETE FROM `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntity))."` WHERE master_".Transformer::camelCaseToUnderscore($entityName)."_id = ? AND slave_".Transformer::camelCaseToUnderscore($relatedEntity)."_id = ?",
									array(
										$this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName)),
										$this->resolvePropertyStatementType($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity))
									),
									array(
										$entity->getPrimaryKey(),
										$key
									)
								);
							}
						}
					}
					/// check for new relations (and add them to database)
					if(is_countable($notPersistedRelations) && count($notPersistedRelations)){
						foreach ($notPersistedRelations as $key => $id) {
							if(!array_key_exists($id,$currentRelations)){
								$this->execute(
									"INSERT INTO `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntity))."` SET master_".Transformer::camelCaseToUnderscore($entityName)."_id = ?, slave_".Transformer::camelCaseToUnderscore($relatedEntity)."_id = ?",
									array(
										$this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName)),
										$this->resolvePropertyStatementType($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity))
									),
									array(
										$entity->getPrimaryKey(),
										$key
									)
								);
							}
						}
					}
				}
				else if(false === in_array($cardinality,array('SELF::MANY_TO_ONE','SELF::ONE_TO_MANY','<<ONE_TO_ONE','MANY_TO_ONE'))){
					/// fetch current relations from database
					$currentRelationsResult = $this->execute(
						"SELECT * FROM `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntity))."` WHERE ".Transformer::camelCaseToUnderscore($entityName)."_id = ?",
						array(
							$this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName))
						),
						array(
							$entity->getPrimaryKey()
						)
					);
					$currentRelations = array();
					if(is_array($currentRelationsResult) && count($currentRelationsResult)){
						foreach ($currentRelationsResult as $rowData) {
							$currentRelations[$rowData[Transformer::camelCaseToUnderscore($relatedEntity).'_id']] = $rowData[Transformer::camelCaseToUnderscore($relatedEntity).'_id'];
						}
					}
					/// get relations as set during business logic operation
					$notPersistedRelations = array();
					$tmp = $entity->{'get'.ucfirst(Transformer::pluralize($relatedEntity))}();
					if(is_countable($tmp) && count($tmp)){
						foreach ($entity->{'get'.ucfirst(Transformer::pluralize($relatedEntity))}() as $key => $relatedItem) {
							$notPersistedRelations[$relatedItem->getPrimaryKey()] = $relatedItem->getPrimaryKey();
						}
					}
					/// check for removed relations (and remove them from database)
					if(is_countable($currentRelations) && count($currentRelations)){
						foreach ($currentRelations as $key => $id) {
							if(!array_key_exists($id,$notPersistedRelations)){
								$this->execute(
									"DELETE FROM `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntity))."` WHERE ".Transformer::camelCaseToUnderscore($entityName)."_id = ? AND ".Transformer::camelCaseToUnderscore($relatedEntity)."_id = ?",
									array(
										$this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName)),
										$this->resolvePropertyStatementType($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity))
									),
									array(
										$entity->getPrimaryKey(),
										$key
									)
								);
							}
						}
					}
					/// check for new relations (and add them to database)
					if(is_countable($notPersistedRelations) && count($notPersistedRelations)){
						foreach ($notPersistedRelations as $key => $id) {
							if(!array_key_exists($id,$currentRelations)){
								$this->execute(
									"INSERT INTO `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntity))."` SET ".Transformer::camelCaseToUnderscore($entityName)."_id = ?, ".Transformer::camelCaseToUnderscore($relatedEntity)."_id = ?",
									array(
										$this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName)),
										$this->resolvePropertyStatementType($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity))
									),
									array(
										$entity->getPrimaryKey(),
										$key
									)
								);
							}
						}
					}
				}
			}
		}
	}

	protected function resolveOperation($operator){
		switch (strtolower($operator)) {
			case '=': case 'eq': return '= ?';
			case '!=': case 'neq': return '!= ?';
			case '>': case 'gt': return '> ?';
			case '>=': case 'gte': return '>= ?';
			case '<': case 'lt': return '< ?';
			case '<=': case 'lte': return '<= ?';
			case '%%': case 'like': return 'LIKE ?';
			case '~': case 'in': return 'IN (?)';
			case '!~': case 'nin': return 'NOT IN (?)';
			case '0': case 'null': return 'IS NULL';
			case '!0': case 'nn': return 'IS NOT NULL';
			case 'between': return 'BETWEEN ? AND ?';
			default: throw new UnknownOperationException('Operator "'.$operator.'" is unknown!');
		}
	}

	protected function resolveValue($entityName,$property,$value,$type = null){
		if(isset($this->model[$entityName]['properties']) && isset($this->model[$entityName]['properties'][$property])){
			switch (null !== $type ? $type : $this->model[$entityName]['properties'][$property]['type']) {
				case 'bool': return intval($value);
				case 'int': case 'i': return intval($value);
				case 'float': case 'd': return doubleval($value);
				case 'string': case 's': return $value;
				case 'text': return $value;
				case 'datetime': return $value;
				default: throw new InvalidPropertyTypeException('"'.(null !== $type ? $type : $this->model[$entityName]['properties'][$property]['type']).'" is not a valid property type!');
			}
		}
		else if(isset($this->model[$entityName]['extends'])){
			return $this->resolveValue($this->model[$entityName]['extends'],$property,$value,$type);
		}
		else if(!is_null($type)){
			switch ($type) {
				case 'i': return intval($value);
				case 'd': return doubleval($value);
				case 's': return $value;
				default: throw new InvalidPropertyTypeException('"'.$type.'" is not a valid property type!');
			}
		}
		else return null;
	}

	protected function resolvePropertyStatementType($entityName,$property){
		if(isset($this->model[$entityName]['properties']) && isset($this->model[$entityName]['properties'][$property])){
			switch ($this->model[$entityName]['properties'][$property]['type']) {
				case 'bool': case 'int': return 'i';
				case 'float': return 'd';
				case 'string': case 'text': case 'datetime': return 's';
				default: throw new InvalidPropertyTypeException('"'.$this->model[$entityName]['properties'][$property]['type'].'" is not a valid property type!');
			}
		}
		else if(isset($this->model[$entityName]['extends'])){
			return $this->resolvePropertyStatementType($this->model[$entityName]['extends'],$property);
		}
		else{
			return null;
		}
	}

	public function execute($query,$types,$params){
		/// prepare statement
		$statement = $this->connection->stmt_init();
		if(!$statement->prepare($query)){
			throw new DatabaseException("Query failed: (".$statement->errno.") ".$statement->error);
		}
		/// dynamically bind parameters
		if(is_array($types) && is_array($params) && count($types) && count($params)){
			call_user_func_array(
				array(
					$statement,
					'bind_param'
				),
				$this->getArrayAsReferences(array_merge(
					array(implode('',$types)),
					$params
				))
			);
		}
		/// execute statement
		if(!$statement->execute()){
			throw new DatabaseException("Query failed: (".$statement->errno.") ".$statement->error);
		}
		/// get result
		$result = $this->getResult($statement);
		/// close statement
		$statement->close();
		
		return $result;
	}

	protected function getResult(\mysqli_stmt $statement){
		
		$metadata = $statement->result_metadata();
		if($metadata === false){
			if($statement->errno === 0){	/// DELETE / INSERT / DROP / ...
				return null;
			}
			else throw new DatabaseException("Query failed: (".$statement->errno.") ".$statement->error);
		}

		$row = array();
		while($field = $metadata->fetch_field()){
			$params[] = &$row[$field->name];
		}

		call_user_func_array(array($statement,'bind_result'),$params);

		$result = array();

		while($statement->fetch()){
			$columns = array();
			foreach($row as $key => $val){
				$columns[$key] = $val;
			}
			$result[] = $columns;
		}

		return $result;
	}

	protected function resolveOrderDirection($direction){
		switch (strtolower($direction)) {
			case 'desc': return 'DESC';
			default: return 'ASC';
		}
	}

	protected function getSelect($entityName,$options = []){
		$selectionProperty = 'this.*';
		if(isset($options['select'])){
			$selectionProperty = $options['select'];
		}
		return "SELECT ".$selectionProperty." FROM ".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName))." this";
	}

	protected function getOrderBy($options){
		$order = "";

		if(isset($options['order']) && is_array($options['order'])){
			$orderClauses = array();
			foreach ($options['order'] as $property => $direction) {
				$orderClauses[] = Transformer::camelCaseToUnderscore($property)." ".$this->resolveOrderDirection($direction);
			}
			$order .= " ORDER BY ".implode(", ",$orderClauses);
		}

		return $order;
	}

	protected function getJoins($options,$entityName){
		$join = "";

		if(isset($options['join']) && is_array($options['join'])){
			foreach ($options['join'] as $relatedEntity => $name) {
				$relatedEntityStripped = Transformer::strip($relatedEntity);
				$relatedEntityOwner = str_replace('<<','',Transformer::strip($relatedEntity,false));
				/// check for existence here, if not existing, try entityName/relatedEntityOwner parent and add discriminator
				$entityJoinedColumnName = $this->getEntityJoinedColumnName($relatedEntityOwner === 'this' ? $entityName : $relatedEntityOwner,$relatedEntityStripped);

				$cardinality = $this->model[$relatedEntityOwner === 'this' ? $entityJoinedColumnName : $relatedEntityOwner]['relations'][$relatedEntityStripped];
				$owning = (false !== strpos($cardinality,'<<') ? true : ($cardinality === 'MANY_TO_ONE' ? true : (false !== strpos($relatedEntity,'<<') ? true : false)));
				if(false !== strpos($cardinality,'MANY_TO_MANY')){
					/// M:N join
					$mtmTableName = ($owning ? Transformer::smurf(Transformer::camelCaseToUnderscore($entityJoinedColumnName)).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntityStripped) : Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped)).'_mtm_'.Transformer::camelCaseToUnderscore($entityJoinedColumnName));
					$join .= " JOIN ".$mtmTableName." `".$mtmTableName."` ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$this->getPrimaryKeyForEntity($entityJoinedColumnName)." = ".$mtmTableName.".".Transformer::camelCaseToUnderscore($entityJoinedColumnName)."_id JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." `".Transformer::camelCaseToUnderscore($name)."` ON ".$mtmTableName.".".Transformer::camelCaseToUnderscore($relatedEntityStripped)."_id = ".Transformer::camelCaseToUnderscore($name).".".$this->getPrimaryKeyForEntity($relatedEntityStripped);
				}
				else if($owning === true){
					/// many-to-one or one-to-one join
					$join .= " JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." `".Transformer::camelCaseToUnderscore($name)."` ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".Transformer::camelCaseToUnderscore($relatedEntityStripped)."_id = ".Transformer::camelCaseToUnderscore($name).".".$this->getPrimaryKeyForEntity($relatedEntityStripped);
				}
				else{
					/// one-to-many left join
					$join .= " JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." `".Transformer::camelCaseToUnderscore($name)."` ON (".Transformer::camelCaseToUnderscore($name).".".Transformer::camelCaseToUnderscore($relatedEntityOwner === 'this' ? $entityName : $relatedEntityOwner)."_id = ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$this->getPrimaryKeyForEntity($entityName);
					if($entityName !== $entityJoinedColumnName){	/// inheritance mapping => add discriminator
						$reflection = new \ReflectionClass($this->entityNamespace.'\\'.ucfirst($entityName));
						$join .= " AND ".Transformer::camelCaseToUnderscore($name).".".Transformer::camelCaseToUnderscore($relatedEntityOwner === 'this' ? $entityJoinedColumnName : $relatedEntityOwner)."_class = '".str_replace('\\','\\\\',$reflection->getName())."'";
					}
					$join .= ")";
				}
			}
		}
		if(isset($options['leftJoin']) && is_array($options['leftJoin'])){
			foreach ($options['leftJoin'] as $relatedEntity => $name) {
				$relatedEntityStripped = Transformer::strip($relatedEntity);
				$relatedEntityOwner = str_replace('<<','',Transformer::strip($relatedEntity,false));
				/// check for existence here, if not existing, try entityName/relatedEntityOwner parent and add discriminator
				$entityJoinedColumnName = $this->getEntityJoinedColumnName($relatedEntityOwner === 'this' ? $entityName : $relatedEntityOwner,$relatedEntityStripped);

				$cardinality = $this->model[$relatedEntityOwner === 'this' ? $entityJoinedColumnName : $relatedEntityOwner]['relations'][$relatedEntityStripped];
				$owning = (false !== strpos($cardinality,'<<') ? true : ($cardinality === 'MANY_TO_ONE' ? true : (false !== strpos($relatedEntity,'<<') ? true : false)));
				if($cardinality === 'SELF::MANY_TO_MANY'){
					/// M:N left join
					$mtmTableName = Transformer::smurf(Transformer::camelCaseToUnderscore($entityJoinedColumnName)).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntityStripped);
					$join .= " LEFT JOIN ".$mtmTableName." `".$mtmTableName."` ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$this->getPrimaryKeyForEntity($entityJoinedColumnName)." = ".$mtmTableName.".".($owning ? 'master_' : 'slave_').Transformer::camelCaseToUnderscore($entityJoinedColumnName)."_id LEFT JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." `".Transformer::camelCaseToUnderscore($name)."` ON ".$mtmTableName.".".($owning ? 'slave_' : 'master_').Transformer::camelCaseToUnderscore($relatedEntityStripped)."_id = ".Transformer::camelCaseToUnderscore($name).".".$this->getPrimaryKeyForEntity($relatedEntityStripped);
				}
				else if(false !== strpos($cardinality,'MANY_TO_MANY')){
					/// M:N left join
					$mtmTableName = ($owning ? Transformer::smurf(Transformer::camelCaseToUnderscore($entityJoinedColumnName)).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntityStripped) : Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped)).'_mtm_'.Transformer::camelCaseToUnderscore($entityJoinedColumnName));
					$join .= " LEFT JOIN ".$mtmTableName." `".$mtmTableName."` ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$this->getPrimaryKeyForEntity($entityJoinedColumnName)." = ".$mtmTableName.".".Transformer::camelCaseToUnderscore($entityJoinedColumnName)."_id LEFT JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." `".Transformer::camelCaseToUnderscore($name)."` ON ".$mtmTableName.".".Transformer::camelCaseToUnderscore($relatedEntityStripped)."_id = ".Transformer::camelCaseToUnderscore($name).".".$this->getPrimaryKeyForEntity($relatedEntityStripped);
				}
				else if($owning === true){
					/// many-to-one or one-to-one left join
					$join .= " LEFT JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." `".Transformer::camelCaseToUnderscore($name)."` ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".Transformer::camelCaseToUnderscore($relatedEntityStripped)."_id = ".Transformer::camelCaseToUnderscore($name).".".$this->getPrimaryKeyForEntity($relatedEntityStripped);
				}
				else{
					/// one-to-many left join
					$join .= " LEFT JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." `".Transformer::camelCaseToUnderscore($name)."` ON (".Transformer::camelCaseToUnderscore($name).".".Transformer::camelCaseToUnderscore($relatedEntityOwner === 'this' ? $entityJoinedColumnName : $relatedEntityOwner)."_id = ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$this->getPrimaryKeyForEntity($entityName);
					if($entityName !== $entityJoinedColumnName){	/// inheritance mapping => add discriminator
						$reflection = new \ReflectionClass($this->entityNamespace.'\\'.ucfirst($entityName));
						$join .= " AND ".Transformer::camelCaseToUnderscore($name).".".Transformer::camelCaseToUnderscore($relatedEntityOwner === 'this' ? $entityJoinedColumnName : $relatedEntityOwner)."_class = '".str_replace('\\','\\\\',$reflection->getName())."'";
					}
					$join .= ")";
				}
			}
		}
		if(isset($options['plainJoin']) && is_array($options['plainJoin'])){
			foreach ($options['plainJoin'] as $relatedEntity => $settings) {
				$relatedEntityStripped = Transformer::strip($relatedEntity);
				$relatedEntityOwner = str_replace('<<','',Transformer::strip($relatedEntity,false));

				$join .= " JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." `".Transformer::camelCaseToUnderscore($settings['name'])."` ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$settings['entityKey']." = ".Transformer::camelCaseToUnderscore($settings['name']).".".$settings['relatedEntityKey'];
			}
		}
		if(isset($options['plainLeftJoin']) && is_array($options['plainLeftJoin'])){
			foreach ($options['plainLeftJoin'] as $relatedEntity => $settings) {
				$relatedEntityStripped = Transformer::strip($relatedEntity);
				$relatedEntityOwner = str_replace('<<','',Transformer::strip($relatedEntity,false));

				$join .= " LEFT JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." `".Transformer::camelCaseToUnderscore($settings['name'])."` ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$settings['entityKey']." = ".Transformer::camelCaseToUnderscore($settings['name']).".".$settings['relatedEntityKey'];
			}
		}

		return $join;
	}

	protected function getWhere($entityName,$options,&$types,&$params){
		$where = "";

		if(isset($options['where']) && is_array($options['where']) && count($options['where'])){
			$whereClauses = array();
			foreach ($options['where'] as $propertyGroup => $settings) {
				if(false === is_array($settings)){
					continue;
				}
				/// property group can be e.g. 'this.id' or 'this.id|this.active|this.title' where '|'' stands for logical OR
				$properties = explode('|', (string) $propertyGroup);
				$operators = explode('|', (string) $settings['operator']);
				if(false === is_array($settings['value'])){
					$values = explode('|', (string) $settings['value']);
				}
				else{
					$values = $settings['value'];
				}
				if(isset($settings['type'])){
					$typesets = explode('|', (string) $settings['type']);
				}
				else{
					$typesets = null;
				}

				$clause = "(";
				$_values = $values;
				foreach ($properties as $key => $property) {
					if($clause !== "("){
						$clause .= ' OR ';
					}

					$operator = count($operators) > 1 ? $operators[$key] : $operators[0];
					if(false === is_array($settings['value'])){
						$value = count($values) > 1 ? $values[$key] : $values[0];
					}
					if (isset($_values[$key]) && is_array($_values[$key])) {
						$values = $_values[$key];
					} else {
						$values = $_values;
					}
					if(null !== $typesets){
						$type = count($typesets) > 1 ? $typesets[$key] : $typesets[0];
					}
					else{
						$type = null;
					}
					if(null !== $type && empty($type)){
						$type = -1;
					}

					if (strpos($property, '(')) {
						$clause .= $property . " " . $this->resolveOperation($operator);
					} else {
						$clause .= Transformer::camelCaseToUnderscore($property) . " " . $this->resolveOperation($operator);
					}
					/// handle IN operator (that takes array as parameter)
					$propertyStripped = Transformer::strip($property);
					$entityStripped = Transformer::strip($property,false);
					if($entityStripped === 'this'){
						$entityStripped = $entityName;
					}
					if($operator === 'between' && is_array($values)) {
						$_type = null !== $type ? $type : $this->resolvePropertyStatementType($entityStripped,$propertyStripped);
						array_push($types, $_type, $_type);
						array_push($params, $values[0], $values[1]);
					}
					else if(true === in_array($operator, ['~','in','!~','nin']) && is_array($values)){
						$clause = str_replace('IN (?)','IN('.(function() use ($values) {
							$str = '';
							for ($i=0; $i < count($values); $i++) {
								$str .= ($i == 0 ? '' : ',').'?';
							}
							return $str;
						})().')',$clause);
						foreach ($values as $key => $value) {
							$types[] = null !== $type ? $type : $this->resolvePropertyStatementType($entityStripped,$propertyStripped);
							$params[] = $this->resolveValue($entityStripped,$propertyStripped,$value,null !== $type ? $type : null);
						}
					}
					/// handle all other operators
					else if(strpos($clause,'?') !== false){
						if($type !== -1){
							$types[] = null !== $type ? $type : $this->resolvePropertyStatementType($entityStripped,$propertyStripped);
							$params[] = $this->resolveValue($entityStripped,$propertyStripped,$value,null !== $type ? $type : null);
						}
					}
				}
				$clause .= ")";

				$whereClauses[] = $clause;
			}
			$where .= " WHERE ".implode(" AND ",$whereClauses);
		}

		return $where;
	}

	protected function getLimit($options){
		$limit = "";

		if(isset($options['limit']) && is_array($options['limit']) && isset($options['limit']['firstResult']) && isset($options['limit']['maxResults'])){
			$limit .= " LIMIT ".intval($options['limit']['firstResult']).",".intval($options['limit']['maxResults']);
		}

		return $limit;
	}

	protected function hydrateEntity($entityName,$entityData){
		if(is_array($entityData)){
			$entityClass = $this->entityNamespace.'\\'.ucfirst($entityName);
			$entity = new $entityClass;
			foreach ($entityData as $property => $value) {
				/// hydrate properties
				if($this->hasProperty($entityName,lcfirst(Transformer::underscoreToCamelCase($property))) === true){
					$entity->{'set'.Transformer::underscoreToCamelCase($property)}($value);
				}
			}

			if (method_exists($entity, 'setProvider')) {
				$entity->setProvider($this);
			}

			/// hydrate loggable
			if(true === $this->isEntityLoggable($entityName)){
				$entity->setChangelog(
					new Changelog(
						$this,
						$entityName,
						$entityData[lcfirst(Transformer::underscoreToCamelCase($this->getPrimaryKeyForEntity($entityName)))]
					)
				);
			}

			/// hydrate blameable
			if(true === $this->isEntityBlameable($entityName)){
				$blameable = $this->getEntityBlameable($entityName);
				$nullValue = (true === isset($blameable['nullValue']) ? $blameable['nullValue'] : null);
				if(true === isset($blameable['targetEntity'])){
					/// blame must be set and not 'null' (null value can be configured)
					if(isset($entityData[Transformer::camelCaseToUnderscore($blameable['property'])]) && $nullValue !== ($entityData[Transformer::camelCaseToUnderscore($blameable['property'])])){
						/// set lazy-loader for single item
						$entity->setBlame(
							new SingleLazyLoader(
								$this,
								$blameable['targetEntity'],
								$entityData[Transformer::camelCaseToUnderscore($blameable['property'])]
							)
						);
					}
				}
			}

			/// hydrate relations
			$relations = $this->getEntityRelations($entityName,false,true);
			if(count($relations) > 0){
				foreach ($relations as $relatedEntity => $cardinality) {
					$pairs = explode(':', (string) $relatedEntity);
					$owningEntity = $pairs[0];
					$relatedEntity = $pairs[1];
					switch ($cardinality) {
						case 'SELF::MANY_TO_ONE':
						case 'SELF::ONE_TO_MANY':
							/// foreign key must be set and not null
							if(isset($entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_id']) && !is_null($entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_id'])){
								/// set lazy-loader for single item
								$entity->{'setParent'.ucfirst($relatedEntity)}(
									new SingleLazyLoader(
										$this,
										$relatedEntity,
										$entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_id']
									)
								);
							}
							/// set lazy-loader for multiple items
							$entity->{'set'.ucfirst(Transformer::pluralize($relatedEntity))}(
								new MultipleLazyLoader(
									$this,
									$entity,
									$relatedEntity,
									array(
										'where' => array(
											'this.'.Transformer::camelCaseToUnderscore($owningEntity).'_id' => array(
												'operator' => 'eq',
												'type' => $this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName)),
												'value' => $entity->{'get'.ucfirst($this->getPrimaryKeyForEntity($entityName))}()
											)
										),
										'order' => array(
											'this.'.Transformer::camelCaseToUnderscore($this->getDefaultOrderForEntity($relatedEntity)) => $this->getDefaultOrderForEntity($relatedEntity,false)
										)
									)
								)
							);
							break;
						case '<<ONE_TO_ONE':
						case 'MANY_TO_ONE':
							/// foreign key must be set and not null
							if(isset($entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_id']) && !is_null($entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_id'])){
								$owningEntity = null;
								if(true === array_key_exists('abstract',$this->model[$relatedEntity])){
									$owningEntity = lcfirst(substr($entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_class'],strrpos($entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_class'],'\\')+1));
								}
								/// set lazy-loader for single item
								$entity->{'set'.ucfirst($relatedEntity)}(
									new SingleLazyLoader(
										$this,
										null !== $owningEntity ? $owningEntity : $relatedEntity,
										$entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_id']
									)
								);
							}
							break;
						case 'ONE_TO_ONE':
							/// foreign key must be set and not null
							$owningEntity = null;
							if(true === array_key_exists('abstract',$this->model[$relatedEntity])){
								$owningEntity = lcfirst(substr($entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_class'],strrpos($entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_class'],'\\')+1));
							}
							/// set lazy-loader for single item
							$entity->{'set'.ucfirst($relatedEntity)}(
								new SingleLazyLoader(
									$this,
									null !== $owningEntity ? $owningEntity : $relatedEntity,
									null,
									[
										'leftJoin' => [
											'this.'.$entityName => $entityName,
										],
										'where' => [
											$entityName.'.'.$this->getPrimaryKeyForEntity($entityName) => [
												'operator' => 'eq',
												'value' => $entity->getPrimaryKey(),
											]
										],
									]
								)
							);
							break;
						case 'ONE_TO_MANY':
							/// set lazy-loader for multiple items
							$entity->{'set'.ucfirst(Transformer::pluralize($relatedEntity))}(
								new MultipleLazyLoader(
									$this,
									$entity,
									$relatedEntity,
									array(
										'where' => array(
											'this.'.Transformer::camelCaseToUnderscore($owningEntity).'_id' => array(
												'operator' => 'eq',
												'type' => $this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName)),
												'value' => $entity->{'get'.ucfirst($this->getPrimaryKeyForEntity($entityName))}()
											),
											'this.'.Transformer::camelCaseToUnderscore($owningEntity).'_class' => ($owningEntity !== $entityName ? array(
													'operator' => 'eq',
													'type' => 's',
													'value' => get_class($entity)
												)
											: null)
										),
										'order' => array(
											'this.'.Transformer::camelCaseToUnderscore($this->getDefaultOrderForEntity($relatedEntity)) => $this->getDefaultOrderForEntity($relatedEntity,false)
										)
									)
								)
							);
							break;
						case 'MANY_TO_MANY':
						case '<<MANY_TO_MANY':
							/// set lazy-loader for multiple items with many-to-many loading
							$entity->{'set'.ucfirst(Transformer::pluralize($relatedEntity))}(
								new MultipleLazyLoader(
									$this,
									$entity,
									$relatedEntity,
									array(
										'leftJoin' => array(
											'this.'.$entityName => $entityName
										),
										'where' => array(
											$entityName.'.'.$this->getPrimaryKeyForEntity($entityName) => array(
												'operator' => 'eq',
												'value' => $entity->{'get'.ucfirst($this->getPrimaryKeyForEntity($entityName))}()
											)
										),
										'order' => array(
											'this.'.Transformer::camelCaseToUnderscore($this->getDefaultOrderForEntity($relatedEntity)) => $this->getDefaultOrderForEntity($relatedEntity,false)
										)
									)
								)
							);
							break;
						case 'SELF::MANY_TO_MANY':
							/// set lazy-loader for multiple items with many-to-many loading
							$entity->{'setMaster'.ucfirst(Transformer::pluralize($relatedEntity))}(
								new MultipleLazyLoader(
									$this,
									$entity,
									$relatedEntity,
									array(
										'leftJoin' => array(
											'<<this.'.$entityName => $entityName
										),
										'where' => array(
											$entityName.'.'.$this->getPrimaryKeyForEntity($entityName) => array(
												'operator' => 'eq',
												'value' => $entity->{'get'.ucfirst($this->getPrimaryKeyForEntity($entityName))}()
											)
										),
										'order' => array(
											'this.'.Transformer::camelCaseToUnderscore($this->getDefaultOrderForEntity($relatedEntity)) => $this->getDefaultOrderForEntity($relatedEntity,false)
										)
									),
									[
										'prefix' => 'Master',
									]
								)
							);
							$entity->{'setSlave'.ucfirst(Transformer::pluralize($relatedEntity))}(
								new MultipleLazyLoader(
									$this,
									$entity,
									$relatedEntity,
									array(
										'leftJoin' => array(
											'this.'.$entityName => $entityName
										),
										'where' => array(
											$entityName.'.'.$this->getPrimaryKeyForEntity($entityName) => array(
												'operator' => 'eq',
												'value' => $entity->{'get'.ucfirst($this->getPrimaryKeyForEntity($entityName))}()
											)
										),
										'order' => array(
											'this.'.Transformer::camelCaseToUnderscore($this->getDefaultOrderForEntity($relatedEntity)) => $this->getDefaultOrderForEntity($relatedEntity,false)
										)
									),
									[
										'prefix' => 'Slave',
									]
								)
							);
							break;
					}
				}
			}
		}
		else{
			$entity = null;
		}

		return $entity;
	}

	protected function getArrayAsReferences($array){
		$references = array();
		
		foreach($array as $key => $value) {
			$references[$key] = &$array[$key];
		}

		return $references;
	}

	public function entityExists($entity) {
		return isset($this->model[$entity]);
	}

}
