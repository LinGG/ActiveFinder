<?php

/**
 * It is a copy of original CActiveFinder class of Yii. I changed it and added a function getCommand() to get the SQL generated
 * from criteria being passed to the method. Since CommandBuider does not support creation of JOIN stuff from criteria.
 *
 * Class ActiveFinder
 */
class ActiveFinder extends CComponent
{
	/**
	 * @var boolean join all tables all at once. Defaults to false.
	 * This property is internally used.
	 */
	public $joinAll=false;
	/**
	 * @var boolean whether the base model has limit or offset.
	 * This property is internally used.
	 */
	public $baseLimited=false;

	private $_joinCount=0;
	private $_joinTree;
	private $_builder;

	/**
	 * Constructor.
	 * A join tree is built up based on the declared relationships between active record classes.
	 * @param CActiveRecord $model the model that initiates the active finding process
	 * @param mixed $with the relation names to be actively looked for
	 */
	public function __construct($model,$with)
	{
		$this->_builder=$model->getCommandBuilder();
		$this->_joinTree=new JoinElement($this,$model);
		$this->buildJoinTree($this->_joinTree,$with);
	}

	/**
	 * Do not call this method. This method is used internally to perform the relational query
	 * based on the given DB criteria.
	 * @param CDbCriteria $criteria the DB criteria
	 * @param boolean $all whether to bring back all records
	 * @return mixed the query result
	 */
	public static function getCommand($model, $criteria)
	{
		$model = new ActiveFinder($model, $criteria->with);

		$model->joinAll=$criteria->together===true;

		if($criteria->alias!='')
		{
			$model->_joinTree->tableAlias=$criteria->alias;
			$model->_joinTree->rawTableAlias=$model->_builder->getSchema()->quoteTableName($criteria->alias);
		}

		$command = $model->_joinTree->find($criteria);
		return $command;
	}


	/**
	 * This method is internally called.
	 * @param CDbCriteria $criteria the query criteria
	 * @return string
	 */
	public function count($criteria)
	{
		Yii::trace(get_class($this->_joinTree->model).'.count() eagerly','system.db.ar.CActiveRecord');
		$this->joinAll=$criteria->together!==true;

		$alias=$criteria->alias===null ? 't' : $criteria->alias;
		$this->_joinTree->tableAlias=$alias;
		$this->_joinTree->rawTableAlias=$this->_builder->getSchema()->quoteTableName($alias);

		$n=$this->_joinTree->count($criteria);
		$this->destroyJoinTree();
		return $n;
	}

	/**
	 * Finds the related objects for the specified active record.
	 * This method is internally invoked by {@link CActiveRecord} to support lazy loading.
	 * @param CActiveRecord $baseRecord the base record whose related objects are to be loaded
	 */
	public function lazyFind($baseRecord)
	{
		$this->_joinTree->lazyFind($baseRecord);
		if(!empty($this->_joinTree->children))
		{
			foreach($this->_joinTree->children as $child)
				$child->afterFind();
		}
		$this->destroyJoinTree();
	}

	private function destroyJoinTree()
	{
		if($this->_joinTree!==null)
			$this->_joinTree->destroy();
		$this->_joinTree=null;
	}

	/**
	 * Builds up the join tree representing the relationships involved in this query.
	 *
*@param JoinElement $parent  the parent tree node
	 * @param mixed $with    the names of the related objects relative to the parent tree node
	 * @param array $options additional query options to be merged with the relation
	 */
	private function buildJoinTree($parent,$with,$options=null)
	{
		if($parent instanceof CStatElement)
			throw new CDbException(Yii::t('yii','The STAT relation "{name}" cannot have child relations.',
				array('{name}'=>$parent->relation->name)));

		if(is_string($with))
		{
			if(($pos=strrpos($with,'.'))!==false)
			{
				$parent=$this->buildJoinTree($parent,substr($with,0,$pos));
				$with=substr($with,$pos+1);
			}

			// named scope
			$scopes=array();
			if(($pos=strpos($with,':'))!==false)
			{
				$scopes=explode(':',substr($with,$pos+1));
				$with=substr($with,0,$pos);
			}

			if(isset($parent->children[$with]) && $parent->children[$with]->master===null)
				return $parent->children[$with];

			if(($relation=$parent->model->getActiveRelation($with))===null)
				throw new CDbException(Yii::t('yii','Relation "{name}" is not defined in active record class "{class}".',
					array('{class}'=>get_class($parent->model), '{name}'=>$with)));

			$relation=clone $relation;
			$model=CActiveRecord::model($relation->className);

			if($relation instanceof CActiveRelation)
			{
				$oldAlias=$model->getTableAlias(false,false);
				if(isset($options['alias']))
					$model->setTableAlias($options['alias']);
				elseif($relation->alias===null)
					$model->setTableAlias($relation->name);
				else
					$model->setTableAlias($relation->alias);
			}

			if(!empty($relation->scopes))
				$scopes=array_merge($scopes,(array)$relation->scopes); // no need for complex merging

			if(!empty($options['scopes']))
				$scopes=array_merge($scopes,(array)$options['scopes']); // no need for complex merging

			$model->resetScope(false);
			$criteria=$model->getDbCriteria();
			$criteria->scopes=$scopes;
			$model->beforeFindInternal();
			$model->applyScopes($criteria);

			// select has a special meaning in stat relation, so we need to ignore select from scope or model criteria
			if($relation instanceof CStatRelation)
				$criteria->select='*';

			$relation->mergeWith($criteria,true);

			// dynamic options
			if($options!==null)
				$relation->mergeWith($options);

			if($relation instanceof CActiveRelation)
				$model->setTableAlias($oldAlias);

			if($relation instanceof CStatRelation)
				return new CStatElement($this,$relation,$parent);
			else
			{
				if(isset($parent->children[$with]))
				{
					$element=$parent->children[$with];
					$element->relation=$relation;
				}
				else
					$element=new JoinElement($this,$relation,$parent,++$this->_joinCount);
				if(!empty($relation->through))
				{
					$slave=$this->buildJoinTree($parent,$relation->through,array('select'=>false));
					$slave->master=$element;
					$element->slave=$slave;
				}
				$parent->children[$with]=$element;
				if(!empty($relation->with))
					$this->buildJoinTree($element,$relation->with);
				return $element;
			}
		}

		// $with is an array, keys are relation name, values are relation spec
		foreach($with as $key=>$value)
		{
			if(is_string($value))  // the value is a relation name
				$this->buildJoinTree($parent,$value);
			elseif(is_string($key) && is_array($value))
				$this->buildJoinTree($parent,$key,$value);
		}
	}
}


/**
 * CJoinElement represents a tree node in the join tree created by {@link CActiveFinder}.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @package system.db.ar
 * @since 1.0
 */
class JoinElement
{
	/**
	 * @var integer the unique ID of this tree node
	 */
	public $id;
	/**
	 * @var CActiveRelation the relation represented by this tree node
	 */
	public $relation;
	/**
	 * @var CActiveRelation the master relation
	 */
	public $master;
	/**
	 * @var CActiveRelation the slave relation
	 */
	public $slave;
	/**
	 * @var CActiveRecord the model associated with this tree node
	 */
	public $model;
	/**
	 * @var array list of active records found by the queries. They are indexed by primary key values.
	 */
	public $records=array();
	/**
	 * @var array list of child join elements
	 */
	public $children=array();
	/**
	 * @var array list of stat elements
	 */
	public $stats=array();
	/**
	 * @var string table alias for this join element
	 */
	public $tableAlias;
	/**
	 * @var string the quoted table alias for this element
	 */
	public $rawTableAlias;

	private $_finder;
	private $_builder;
	private $_parent;
	private $_pkAlias;  				// string or name=>alias
	private $_columnAliases=array();	// name=>alias
	private $_joined=false;
	private $_table;
	private $_related=array();			// PK, relation name, related PK => true

	/**
	 * Constructor.
	 *
*@param CActiveFinder     $finder   the finder
	 * @param mixed       $relation the relation (if the third parameter is not null)
	 * or the model (if the third parameter is null) associated with this tree node.
	 * @param JoinElement $parent   the parent tree node
	 * @param integer     $id       the ID of this tree node that is unique among all the tree nodes
	 */
	public function __construct($finder,$relation,$parent=null,$id=0)
	{
		$this->_finder=$finder;
		$this->id=$id;
		if($parent!==null)
		{
			$this->relation=$relation;
			$this->_parent=$parent;
			$this->model=CActiveRecord::model($relation->className);
			$this->_builder=$this->model->getCommandBuilder();
			$this->tableAlias=$relation->alias===null?$relation->name:$relation->alias;
			$this->rawTableAlias=$this->_builder->getSchema()->quoteTableName($this->tableAlias);
			$this->_table=$this->model->getTableSchema();
		}
		else  // root element, the first parameter is the model.
		{
			$this->model=$relation;
			$this->_builder=$relation->getCommandBuilder();
			$this->_table=$relation->getTableSchema();
			$this->tableAlias=$this->model->getTableAlias();
			$this->rawTableAlias=$this->_builder->getSchema()->quoteTableName($this->tableAlias);
		}

		// set up column aliases, such as t1_c2
		$table=$this->_table;
		if($this->model->getDbConnection()->getDriverName()==='oci')  // Issue 482
			$prefix='T'.$id.'_C';
		else
			$prefix='t'.$id.'_c';
		foreach($table->getColumnNames() as $key=>$name)
		{
			$alias=$prefix.$key;
			$this->_columnAliases[$name]=$alias;
			if($table->primaryKey===$name)
				$this->_pkAlias=$alias;
			elseif(is_array($table->primaryKey) && in_array($name,$table->primaryKey))
				$this->_pkAlias[$name]=$alias;
		}
	}

	/**
	 * Removes references to child elements and finder to avoid circular references.
	 * This is internally used.
	 */
	public function destroy()
	{
		if(!empty($this->children))
		{
			foreach($this->children as $child)
				$child->destroy();
		}
		unset($this->_finder, $this->_parent, $this->model, $this->relation, $this->master, $this->slave, $this->records, $this->children, $this->stats);
	}

	/**
	 * Performs the recursive finding with the criteria.
	 * @param CDbCriteria $criteria the query criteria
	 */
	public function find($criteria=null)
	{
		if($this->_parent===null) // root element
		{
			$query=new JoinQuery($this,$criteria);
			$this->_finder->baseLimited=($criteria->offset>=0 || $criteria->limit>=0);
			$this->buildQuery($query);
		}
		elseif(!$this->_joined && !empty($this->_parent->records)) // not joined before
		{
			$query=new JoinQuery($this->_parent);
			$this->_joined=true;
			$query->join($this);
			$this->buildQuery($query);
		}

		return $query->createCommand($this->_builder);

		/*foreach($this->children as $child) // find recursively
			$child->find();

		foreach($this->stats as $stat)
			$stat->query();*/
	}

	/**
	 * Builds the join query with all descendant HAS_ONE and BELONGS_TO nodes.
	 *
*@param JoinQuery $query the query being built up
	 */
	public function buildQuery($query)
	{
		foreach($this->children as $child)
		{
			if($child->master!==null)
				$child->_joined=true;
			elseif($child->relation instanceof CHasOneRelation || $child->relation instanceof CBelongsToRelation
				|| $this->_finder->joinAll || $child->relation->together || (!$this->_finder->baseLimited && $child->relation->together===null))
			{
				$child->_joined=true;
				$query->join($child);
				$child->buildQuery($query);
			}
		}
	}

	/**
	 * Executes the join query and populates the query results.
	 *
*@param JoinQuery $query the query to be executed.
	 */
	public function runQuery($query)
	{
		$command=$query->createCommand($this->_builder);
		foreach($command->queryAll() as $row)
			$this->populateRecord($query,$row);
	}

	/**
	 * Populates the active records with the query data.
	 *
* @param JoinQuery  $query the query executed
	 * @param array $row   a row of data
	 *
*@return CActiveRecord the populated record
	 */
	private function populateRecord($query,$row)
	{
		// determine the primary key value
		if(is_string($this->_pkAlias))  // single key
		{
			if(isset($row[$this->_pkAlias]))
				$pk=$row[$this->_pkAlias];
			else	// no matching related objects
				return null;
		}
		else // is_array, composite key
		{
			$pk=array();
			foreach($this->_pkAlias as $name=>$alias)
			{
				if(isset($row[$alias]))
					$pk[$name]=$row[$alias];
				else	// no matching related objects
					return null;
			}
			$pk=serialize($pk);
		}

		// retrieve or populate the record according to the primary key value
		if(isset($this->records[$pk]))
			$record=$this->records[$pk];
		else
		{
			$this->records[]=$pk;
		}

	}

	/**
	 * @return string the table name and the table alias (if any). This can be used directly in SQL query without escaping.
	 */
	public function getTableNameWithAlias()
	{
		if($this->tableAlias!==null)
			return $this->_table->rawName . ' ' . $this->rawTableAlias;
		else
			return $this->_table->rawName;
	}

	/**
	 * Generates the list of columns to be selected.
	 * Columns will be properly aliased and primary keys will be added to selection if they are not specified.
	 * @param mixed $select columns to be selected. Defaults to '*', indicating all columns.
	 * @return string the column selection
	 */
	public function getColumnSelect($select='*')
	{
		$schema=$this->_builder->getSchema();
		$prefix=$this->getColumnPrefix();
		$columns=array();
		if($select==='*')
		{
			foreach($this->_table->getColumnNames() as $name)
				$columns[]=$prefix.$schema->quoteColumnName($name).' AS '.$schema->quoteColumnName($this->_columnAliases[$name]);
		}
		else
		{
			if(is_string($select))
				$select=explode(',',$select);
			$selected=array();
			foreach($select as $name)
			{
				$name=trim($name);
				$matches=array();
				if(($pos=strrpos($name,'.'))!==false)
					$key=substr($name,$pos+1);
				else
					$key=$name;
				$key=trim($key,'\'"`');

				if($key==='*')
				{
					foreach($this->_table->columns as $name=>$column)
					{
						$alias=$this->_columnAliases[$name];
						if(!isset($selected[$alias]))
						{
							$columns[]=$prefix.$column->rawName.' AS '.$schema->quoteColumnName($alias);
							$selected[$alias]=1;
						}
					}
					continue;
				}

				if(isset($this->_columnAliases[$key]))  // simple column names
				{
					$columns[]=$prefix.$schema->quoteColumnName($key).' AS '.$schema->quoteColumnName($this->_columnAliases[$key]);
					$selected[$this->_columnAliases[$key]]=1;
				}
				elseif(preg_match('/^(.*?)\s+AS\s+(\w+)$/im',$name,$matches)) // if the column is already aliased
				{
					$alias=$matches[2];
					if(!isset($this->_columnAliases[$alias]) || $this->_columnAliases[$alias]!==$alias)
					{
						$this->_columnAliases[$alias]=$alias;
						$columns[]=$name;
						$selected[$alias]=1;
					}
				}
				else
					throw new CDbException(Yii::t('yii','Active record "{class}" is trying to select an invalid column "{column}". Note, the column must exist in the table or be an expression with alias.',
						array('{class}'=>get_class($this->model), '{column}'=>$name)));
			}
			// add primary key selection if they are not selected
			if(is_string($this->_pkAlias) && !isset($selected[$this->_pkAlias]))
				$columns[]=$prefix.$schema->quoteColumnName($this->_table->primaryKey).' AS '.$schema->quoteColumnName($this->_pkAlias);
			elseif(is_array($this->_pkAlias))
			{
				foreach($this->_table->primaryKey as $name)
					if(!isset($selected[$name]))
						$columns[]=$prefix.$schema->quoteColumnName($name).' AS '.$schema->quoteColumnName($this->_pkAlias[$name]);
			}
		}

		return implode(', ',$columns);
	}

	/**
	 * @return string the primary key selection
	 */
	public function getPrimaryKeySelect()
	{
		$schema=$this->_builder->getSchema();
		$prefix=$this->getColumnPrefix();
		$columns=array();
		if(is_string($this->_pkAlias))
			$columns[]=$prefix.$schema->quoteColumnName($this->_table->primaryKey).' AS '.$schema->quoteColumnName($this->_pkAlias);
		elseif(is_array($this->_pkAlias))
		{
			foreach($this->_pkAlias as $name=>$alias)
				$columns[]=$prefix.$schema->quoteColumnName($name).' AS '.$schema->quoteColumnName($alias);
		}
		return implode(', ',$columns);
	}

	/**
	 * @return string the condition that specifies only the rows with the selected primary key values.
	 */
	public function getPrimaryKeyRange()
	{
		if(empty($this->records))
			return '';
		$values=array_keys($this->records);
		if(is_array($this->_table->primaryKey))
		{
			foreach($values as &$value)
				$value=unserialize($value);
		}
		return $this->_builder->createInCondition($this->_table,$this->_table->primaryKey,$values,$this->getColumnPrefix());
	}

	/**
	 * @return string the column prefix for column reference disambiguation
	 */
	public function getColumnPrefix()
	{
		if($this->tableAlias!==null)
			return $this->rawTableAlias.'.';
		else
			return $this->_table->rawName.'.';
	}

	/**
	 * @return string the join statement (this node joins with its parent)
	 */
	public function getJoinCondition()
	{
		$parent=$this->_parent;
		if($this->relation instanceof CManyManyRelation)
		{
			$schema=$this->_builder->getSchema();
			$joinTableName=$this->relation->getJunctionTableName();
			if(($joinTable=$schema->getTable($joinTableName))===null)
				throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is not specified correctly: the join table "{joinTable}" given in the foreign key cannot be found in the database.',
					array('{class}'=>get_class($parent->model), '{relation}'=>$this->relation->name, '{joinTable}'=>$joinTableName)));
			$fks=$this->relation->getJunctionForeignKeys();

			return $this->joinManyMany($joinTable,$fks,$parent);
		}
		else
		{
			$fks=is_array($this->relation->foreignKey) ? $this->relation->foreignKey : preg_split('/\s*,\s*/',$this->relation->foreignKey,-1,PREG_SPLIT_NO_EMPTY);
			if($this->relation instanceof CBelongsToRelation)
			{
				$pke=$this;
				$fke=$parent;
			}
			elseif($this->slave===null)
			{
				$pke=$parent;
				$fke=$this;
			}
			else
			{
				$pke=$this;
				$fke=$this->slave;
			}
			return $this->joinOneMany($fke,$fks,$pke,$parent);
		}
	}

	/**
	 * Generates the join statement for one-many relationship.
	 * This works for HAS_ONE, HAS_MANY and BELONGS_TO.

*
*@param JoinElement       $fke    the join element containing foreign keys
	 * @param array       $fks    the foreign keys
	 * @param JoinElement $pke    the join element containg primary keys
	 * @param JoinElement $parent the parent join element

*
*@return string the join statement
	 * @throws CDbException if a foreign key is invalid
	 */
	private function joinOneMany($fke,$fks,$pke,$parent)
	{
		$schema=$this->_builder->getSchema();
		$joins=array();
		if(is_string($fks))
			$fks=preg_split('/\s*,\s*/',$fks,-1,PREG_SPLIT_NO_EMPTY);
		foreach($fks as $i=>$fk)
		{
			if(!is_int($i))
			{
				$pk=$fk;
				$fk=$i;
			}

			if(!isset($fke->_table->columns[$fk]))
				throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is specified with an invalid foreign key "{key}". There is no such column in the table "{table}".',
					array('{class}'=>get_class($parent->model), '{relation}'=>$this->relation->name, '{key}'=>$fk, '{table}'=>$fke->_table->name)));

			if(is_int($i))
			{
				if(isset($fke->_table->foreignKeys[$fk]) && $schema->compareTableNames($pke->_table->rawName, $fke->_table->foreignKeys[$fk][0]))
					$pk=$fke->_table->foreignKeys[$fk][1];
				else // FK constraints undefined
				{
					if(is_array($pke->_table->primaryKey)) // composite PK
						$pk=$pke->_table->primaryKey[$i];
					else
						$pk=$pke->_table->primaryKey;
				}
			}

			$joins[]=$fke->getColumnPrefix().$schema->quoteColumnName($fk) . '=' . $pke->getColumnPrefix().$schema->quoteColumnName($pk);
		}
		if(!empty($this->relation->on))
			$joins[]=$this->relation->on;
		return $this->relation->joinType . ' ' . $this->getTableNameWithAlias() . ' ON (' . implode(') AND (',$joins).')';
	}

	/**
	 * Generates the join statement for many-many relationship.
	 *
*@param CDbTableSchema    $joinTable the join table
	 * @param array       $fks       the foreign keys
	 * @param JoinElement $parent    the parent join element
	 *
*@return string the join statement
	 * @throws CDbException if a foreign key is invalid
	 */
	private function joinManyMany($joinTable,$fks,$parent)
	{
		$schema=$this->_builder->getSchema();
		$joinAlias=$schema->quoteTableName($this->relation->name.'_'.$this->tableAlias);
		$parentCondition=array();
		$childCondition=array();

		$fkDefined=true;
		foreach($fks as $i=>$fk)
		{
			if(!isset($joinTable->columns[$fk]))
				throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is specified with an invalid foreign key "{key}". There is no such column in the table "{table}".',
					array('{class}'=>get_class($parent->model), '{relation}'=>$this->relation->name, '{key}'=>$fk, '{table}'=>$joinTable->name)));

			if(isset($joinTable->foreignKeys[$fk]))
			{
				list($tableName,$pk)=$joinTable->foreignKeys[$fk];
				if(!isset($parentCondition[$pk]) && $schema->compareTableNames($parent->_table->rawName,$tableName))
					$parentCondition[$pk]=$parent->getColumnPrefix().$schema->quoteColumnName($pk).'='.$joinAlias.'.'.$schema->quoteColumnName($fk);
				elseif(!isset($childCondition[$pk]) && $schema->compareTableNames($this->_table->rawName,$tableName))
					$childCondition[$pk]=$this->getColumnPrefix().$schema->quoteColumnName($pk).'='.$joinAlias.'.'.$schema->quoteColumnName($fk);
				else
				{
					$fkDefined=false;
					break;
				}
			}
			else
			{
				$fkDefined=false;
				break;
			}
		}

		if(!$fkDefined)
		{
			$parentCondition=array();
			$childCondition=array();
			foreach($fks as $i=>$fk)
			{
				if($i<count($parent->_table->primaryKey))
				{
					$pk=is_array($parent->_table->primaryKey) ? $parent->_table->primaryKey[$i] : $parent->_table->primaryKey;
					$parentCondition[$pk]=$parent->getColumnPrefix().$schema->quoteColumnName($pk).'='.$joinAlias.'.'.$schema->quoteColumnName($fk);
				}
				else
				{
					$j=$i-count($parent->_table->primaryKey);
					$pk=is_array($this->_table->primaryKey) ? $this->_table->primaryKey[$j] : $this->_table->primaryKey;
					$childCondition[$pk]=$this->getColumnPrefix().$schema->quoteColumnName($pk).'='.$joinAlias.'.'.$schema->quoteColumnName($fk);
				}
			}
		}

		if($parentCondition!==array() && $childCondition!==array())
		{
			$join=$this->relation->joinType.' '.$joinTable->rawName.' '.$joinAlias;
			$join.=' ON ('.implode(') AND (',$parentCondition).')';
			$join.=' '.$this->relation->joinType.' '.$this->getTableNameWithAlias();
			$join.=' ON ('.implode(') AND (',$childCondition).')';
			if(!empty($this->relation->on))
				$join.=' AND ('.$this->relation->on.')';
			return $join;
		}
		else
			throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is specified with an incomplete foreign key. The foreign key must consist of columns referencing both joining tables.',
				array('{class}'=>get_class($parent->model), '{relation}'=>$this->relation->name)));
	}
}


/**
 * CJoinQuery represents a JOIN SQL statement.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @package system.db.ar
 * @since 1.0
 */
class JoinQuery
{
	/**
	 * @var array list of column selections
	 */
	public $selects=array();
	/**
	 * @var boolean whether to select distinct result set
	 */
	public $distinct=false;
	/**
	 * @var array list of join statement
	 */
	public $joins=array();
	/**
	 * @var array list of WHERE clauses
	 */
	public $conditions=array();
	/**
	 * @var array list of ORDER BY clauses
	 */
	public $orders=array();
	/**
	 * @var array list of GROUP BY clauses
	 */
	public $groups=array();
	/**
	 * @var array list of HAVING clauses
	 */
	public $havings=array();
	/**
	 * @var integer row limit
	 */
	public $limit=-1;
	/**
	 * @var integer row offset
	 */
	public $offset=-1;
	/**
	 * @var array list of query parameters
	 */
	public $params=array();
	/**
	 * @var array list of join element IDs (id=>true)
	 */
	public $elements=array();

	/**
	 * Constructor.
	 *
	 * @param JoinElement $joinElement The root join tree.
	 * @param CDbCriteria $criteria the query criteria
	 */
	public function __construct($joinElement,$criteria=null)
	{
		if($criteria!==null)
		{
			$this->selects[]=$joinElement->getColumnSelect($criteria->select);
			$this->joins[]=$joinElement->getTableNameWithAlias();
			$this->joins[]=$criteria->join;
			$this->conditions[]=$criteria->condition;
			$this->orders[]=$criteria->order;
			$this->groups[]=$criteria->group;
			$this->havings[]=$criteria->having;
			$this->limit=$criteria->limit;
			$this->offset=$criteria->offset;
			$this->params=$criteria->params;
			if(!$this->distinct && $criteria->distinct)
				$this->distinct=true;
		}
		else
		{
			$this->selects[]=$joinElement->getPrimaryKeySelect();
			$this->joins[]=$joinElement->getTableNameWithAlias();
			$this->conditions[]=$joinElement->getPrimaryKeyRange();
		}
		$this->elements[$joinElement->id]=true;
	}

	/**
	 * Joins with another join element
	 *
	 * @param JoinElement $element the element to be joined
	 */
	public function join($element)
	{
		if($element->slave!==null)
			$this->join($element->slave);
		if(!empty($element->relation->select))
			$this->selects[]=$element->getColumnSelect($element->relation->select);
		$this->conditions[]=$element->relation->condition;
		$this->orders[]=$element->relation->order;
		$this->joins[]=$element->getJoinCondition();
		$this->joins[]=$element->relation->join;
		$this->groups[]=$element->relation->group;
		$this->havings[]=$element->relation->having;

		if(is_array($element->relation->params))
		{
			if(is_array($this->params))
				$this->params=array_merge($this->params,$element->relation->params);
			else
				$this->params=$element->relation->params;
		}
		$this->elements[$element->id]=true;
	}

	/**
	 * Creates the SQL statement.
	 * @param CDbCommandBuilder $builder the command builder
	 * @return CDbCommand DB command instance representing the SQL statement
	 */
	public function createCommand($builder)
	{
		$sql=($this->distinct ? 'SELECT DISTINCT ':'SELECT ') . implode(', ',$this->selects);
		$sql.=' FROM ' . implode(' ',$this->joins);

		$conditions=array();
		foreach($this->conditions as $condition)
			if($condition!=='')
				$conditions[]=$condition;
		if($conditions!==array())
			$sql.=' WHERE (' . implode(') AND (',$conditions).')';

		$groups=array();
		foreach($this->groups as $group)
			if($group!=='')
				$groups[]=$group;
		if($groups!==array())
			$sql.=' GROUP BY ' . implode(', ',$groups);

		$havings=array();
		foreach($this->havings as $having)
			if($having!=='')
				$havings[]=$having;
		if($havings!==array())
			$sql.=' HAVING (' . implode(') AND (',$havings).')';

		$orders=array();
		foreach($this->orders as $order)
			if($order!=='')
				$orders[]=$order;
		if($orders!==array())
			$sql.=' ORDER BY ' . implode(', ',$orders);

		$sql=$builder->applyLimit($sql,$this->limit,$this->offset);
		$command=$builder->getDbConnection()->createCommand($sql);
		$builder->bindValues($command,$this->params);
		return $command;
	}
}

