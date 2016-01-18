<?php
/**
 * @license Copyright (c) 2008-2016 Robert Frunzke. All rights reserved.
 * For licensing, see LICENSE.md
 */


/**
 * a table
 * @author rob
 */
class reDBTable {

	private $db;
	private $name;
	private $columns = null;
	private $primary_key = null;
	private $foreign_keys = null;
	private $relations = null;

	public function __construct( reDB $db, $name ) {
		$this->db = $db;
		$this->name = $name;
	}

	public function initialize( array $columns, $primary_key=null, $foreign_keys=null ) {
		$this->columns = $columns;
		$this->primary_key = $primary_key;
		$this->foreign_keys = $foreign_keys;
	}

	public function getDB()				{ return $this->db; }
	public function getName()			{ return $this->name; }
	public function getColumns()		{ return $this->columns; }
	public function getPrimaryKey()		{ return $this->primary_key; }
	public function getForeignKeys()	{ return $this->foreign_keys; }
	public function isView()			{ return false; }

	public function getForeignKey( $foreign_key_name ) {
		if( !isset($this->foreign_keys[$foreign_key_name]) ) throw new reException('unknown foreign key: '.$foreign_key_name.' in table '.$this->name);
		return $this->foreign_keys[$foreign_key_name];
	}

	public function getRowsArray( $match=null, $order_by_clause=null, $limit=null, $offset=null ) {
		$rt = array();
		$where_clause = '';
		$query_data = array();
		if( is_array($match) && count($match) ) {
			$where_clause = array();
			foreach( $match as $k=>$v ) {
				if( $v===null ) {
					$where_clause[] = $k.' IS NULL';
				} else {
					$where_clause[] = $k.'=?';
					$query_data[] = $v;
				}
			}
			$where_clause = ' WHERE ' . implode(' AND ',$where_clause);
		}
		$sql = 'SELECT * FROM ' . $this->getName() . $where_clause;
		if( $order_by_clause!==null ) $sql .= ' ORDER BY ' . $order_by_clause;
		if( $limit!==null ) $sql .= ' LIMIT ' . $limit;
		if( $offset!==null ) $sql .= ' OFFSET ' . $offset;
		foreach( $this->getDb()->queryArray($sql,$query_data) as $row ) {
			$values = array();
			foreach( $this->getColumns() as $column_name => $column ) $values[$column_name] = /*new reDBValue( $column, */$row[$column->getIndex()]/* )*/;
			$rt[] = $values;
		}
		return $rt;
	}

	public function getRows( $match=null, $order_by_clause=null, $limit=null, $offset=null ) {
		$rows = $this->getRowsArray($match,$order_by_clause,$limit,$offset);
		foreach( $rows as $k=>$row ) $rows[$k] = new reDBRow( $this, $row );
		return $rows;
	}

	public function __toString() {
		$rt = 'table: ' . $this->getName() . "\n";
		foreach( $this->getColumns() as $column ) $rt .= '    ' . (string)$column . "\n";
		if( $this->primary_key===null ) $rt .= '     pk: NONE'."\n";
		else $rt .= '    ' . (string)$this->getPrimaryKey() . "\n";
		foreach( $this->getForeignKeys() as $foreign_key ) $rt .= '    ' . (string)$foreign_key . "\n";
		//foreach( $this->getRows() as $row ) $rt .= '  ' . (string)$row . "\n";
		return $rt;
	}

}

/**
 * a view
 * @author rob
 */
class reDBView extends reDBTable {

	public function __construct( reDB $db, $name ) {
		parent::__construct( $db, $name );
	}

	public function initialize( array $columns, $primary_key=null, $foreign_keys=null ) {
		// TODO: add table-usage information
		parent::initialize( $columns, $primary_key, $foreign_keys );
	}

	public function isView()			{ return true; }

	public function __toString() {
		$rt = 'view: ' . $this->getName() . "\n";
		foreach( $this->getColumns() as $column ) $rt .= '    ' . (string)$column . "\n";
		//foreach( $this->getRows() as $row ) $rt .= '  ' . (string)$row . "\n";
		return $rt;
	}

}


/**
 * a column definition of a database table
 * @author rob
 */
class reDBColumn {

	private $table;
	private $name;
	private $index;
	private $type;

	public function __construct( reDBTable $table, $name, $index, $type ) {
		$this->table = $table;
		$this->name = $name;
		$this->index = $index;
		$this->type = $type;
	}

	public function getTable()		{ return $this->table; }
	public function getName()		{ return $this->name; }
	public function getIndex()		{ return $this->index; }
	public function getType()		{ return $this->type; }

	public function getValues( $order_by_clause=null ) {
		$order_by_clause = $order_by_clause===null ? '' : ' ORDER BY ' . $order_by_clause;
		$rows = $this->table->getDb()->queryArray( 'SELECT ' . $this->name . ' FROM ' . $this->table->getName() . $order_by_clause );
		$rt = array();
		foreach( $rows as $row ) $rt[] = /*new reDBValue( $this, */$row[0]/* )*/;
		return $rt;
	}

	public function __toString() {
		return 'col: '.$this->name.' '.$this->type;
	}
}


/**
 * a primary key, consisting of single column or list of columns
 * @author rob
 */
class reDBPrimaryKey {

	private $table;
	private $name;
	private $column_names;

	public function __construct( reDBTable $table, $name, array $column_names ) {
		$this->table = $table;
		$this->name = $name;
		$this->column_names = $column_names;
	}
	public function getTable()			{ return $this->table; }
	public function getName()			{ return $this->name; }
	public function getColumnNames()	{ return $this->column_names; }

	public function __toString() {
		return ' pk: ( '.implode(', ',$this->column_names).' )';
	}

}


/**
 * a row of a database table, containing actual values
 * @author rob
 */
class reDBRow {

	private $table;
	private $values;

	public function __construct( reDBTable $table, array $values ) {
		$this->table = $table;
		$this->values = $values;
	}

	public function getTable()		{ return $this->table; }
	public function getValues()		{ return $this->values; }

	public function getValue( $column_name ) {
		if( !isset($this->values[$column_name]) ) throw new reException('unknown column : '.$this->table->getName().'.'.$column_name);
		return $this->values[$column_name];
	}

	public function getParentRow( $foreign_key_name ) {
		return $this->table->getForeignKey($foreign_key_name)->getParentRow( $this );
	}
	public function getChildRows( $foreign_key_name ) {
		return $this->table->getDb()->getForeignKey($foreign_key_name)->getChildRows( $this );
	}

	public function __toString() {
		$rt = array();
		foreach( $this->values as $value ) $rt[] = (string)$value;
		return $this->table->getName() . ': ('.implode(',',$rt).')';
	}

}


/**
 * a foreign key
 * @author rob
 */
class reDBForeignKey {

	private $table;
	private $name;
	private $references_table;
	private $column_references;

	public function __construct( reDBTable $table, $name, $references_table, $column_references ) {
		$this->table = $table;
		$this->name = $name;
		$this->references_table = $references_table;
		$this->column_references = $column_references;
	}
	public function getTable()					{ return $this->table; }
	public function getName()					{ return $this->name; }
	public function getReferencesTable()		{ return $this->references_table; }
	public function getColumnReferences()		{ return $this->column_references; }

	public function getParentRow( reDBRow $child_row ) {
		$match = array();
		foreach( $this->column_references as $column=>$references_column ) $match[$references_column] = $child_row->getValue( $column );
		$rows = $this->references_table->getRows($match);
		$n = count($rows);
		if( $n>1 ) throw new reException('multiple rows returned while querying a parent row');
		return $n==1 ? $rows[0] : null;
	}

	public function getChildRows( reDBRow $parent_row ) {
		$match = array();
		foreach( $this->column_references as $column=>$references_column ) $match[$column] = $parent_row->getValue( $references_column );
		return $this->table->getRows( $match );
	}

	public function __toString() {
		$rt = array();
		return ' fk: ' . $this->name /*. $this->table->getName()*/ . ' = ( '.implode(', ',array_keys($this->column_references)).' ) -> '.$this->references_table->getName().' ( '.implode(', ',array_values($this->column_references)).' )';
	}

}


/**
 * an actual value of a column in a row in a table
 * @author rob
 */
/*DISABLED, OVERKILL (though we will need Datetime types and similar stuff)
class reDBValue {
	private $column;
	private $value;
	public function __construct( $column, $value ) {
		$this->column = $column;
		$this->value = $value;
	}
	public function getValue()		{ return $this->value; }
	public function __toString() {
		return (string)$this->value;
	}
}
*/



/**
 * base class for object models generated root class
 * @author rob
 */
class reOMRoot {

	/**
	 * @var reDB
	 */
	private $_db;

	public function __construct( reDB $db ) {
		$this->_db = $db;
	}

	/**
	 * return associated db object
	 * @return reDB
	 */
	public function _getDb() {
		return $this->_db;
	}

	/**
	 * create & return an item instance, according to given class and sql query
	 * @param string $class_name
	 * @param string $sql
	 * @param array $para
	 * @return reOMItem
	 */
	public function _createObject( $class_name, $sql, array $para ) {
		return ($row=$this->_db->queryOneAssoc($sql,$para))===null ? null : new $class_name($this,$row);
	}

	/**
	 * create & return an array of item instances, according to given class and sql query
	 * @param string $class_name
	 * @param string $sql
	 * @param array $para
	 * @return reOMItem[]
	 */
	public function _createObjectList( $class_name, $sql, array $para ) {
		$objs = array();
		foreach( $this->_db->queryAssoc($sql,$para) as $row ) $objs[] = new $class_name($this,$row);
		return $objs;
	}

	/**
	 * create & return an array of item instances, according to given class, sql and filter
	 * @param string $class_name
	 * @param string $sql1
	 * @param string $sql2
	 * @param array $filter
	 * @throws reException
	 * @return reOMItem[]
	 */
	public function _createObjectListWithFilter( $class_name, $sql1, $sql2, array $filter=null ) {
		$para=array();
		if( $filter!==null ) {
			foreach( $filter as $c=>$v ) {
				$sql1 .= ' AND ';
				if( is_array($v) ) {
					switch( $v[0] ) {
					case '=':
					case '<':
					case '>':
					case '<=':
					case '>=':
					case '<>':
						$sql1 .= $c.$v[0].'?';
						$para[] = $v[1];
						break;
					case 'sql':
						$sql1 .= '('.$c.' '.$v[1].')';
						break;
					case 'rawsql':
						$sql1 .= '('.$v[1].')';
						break;
					default:
						throw new reException('unknown query filter operator: '.$v[0]);
					}
				} else {
					$sql1 .= $c.'=?';
					$para[] = $v;
				}
			}
		}
		$objs = array();
		foreach( $this->_db->queryAssoc($sql1.$sql2,$para) as $row ) $objs[] = new $class_name($this,$row);
		return $objs;
	}

	/**
	 * parse sql array string and return the contents of it as an array of integers
	 * @param string $str
	 * @return number[]
	 */
	public function _parseDBIntArray( $str ) {
		if( isset($str[0]) && $str[0]==='{' ) {
			$l = strlen($str);
			if( $str[$l-1]==='}' && ($str=substr($str,1,$l-2))!=='' ) {
				$tmp = explode(',',$str);
				foreach( $tmp as $k=>$v ) $tmp[$k] = (int)$v;
				return $tmp;
			}
		}
		return array();
	}

}


/**
 * base class for object models generated item classes
 * @author rob
 */
abstract class reOMItem {

	/**
	 * @var reOMRoot
	 */
	private $_root;

	public function __construct( reOMRoot $root ) {
		$this->_root = $root;
	}

	/**
	 * return associated root object
	 * @return reOMRoot
	 */
	public function _getRoot() {
		return $this->_root;
	}

	/**
	 * shortcut to return associated db object (from associated root object)
	 * @return reDB
	 */
	public function _getDb() {
		return $this->_root->_getDb();
	}

	/**
	 * return properties in plain array
	 * @return array
	 */
	abstract public function toArray();

}



