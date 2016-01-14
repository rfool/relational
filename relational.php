<?php
/**
 * @license Copyright (c) 2008-2016 Robert Frunzke. All rights reserved.
 * For licensing, see LICENSE.md
 */

/**
 * dba related exception
 * @author rob
 */
class reException extends Exception {
	public function __construct( $message ) {
		parent::__construct($message);
	}
}


/**
 * the database factory - a call to connect() creates an actual 'connected' database object 
 * @author rob
 *
 */
class reDBFactory {
	private static $drivers = array(
		'postgres'	=> array('postgres.php','reDBPostgres'),
		'mysqli'	=> array('mysqli.php',  'reDBMySQLI'  ),
	);
	/**
	 * 
	 * @param array $cfg
	 * @param boolean $model_cache_enabled
	 * @throws reException
	 * @return reDB
	 */
	public static function connect( array $cfg, $model_cache_enabled=false ) {
		$driver_name = isset($cfg['driver']) ? $cfg['driver'] : 'postgres';
		if( !isset(reDBFactory::$drivers[$driver_name]) ) throw new reException('unknown database driver: '.$driver_name);
		$driver = reDBFactory::$drivers[$driver_name];
		require_once( dirname(__FILE__).'/drivers/'.$driver[0] );
		if( $model_cache_enabled ) {
			$model_cache_path = isset($cfg['model_cache_path']) ? $cfg['model_cache_path'] : '';
			if( $model_cache_path!=='' && file_exists($model_cache_path) ) {
				$db = unserialize( file_get_contents($model_cache_path) );
				if( $db!==false ) {
					$db->onUnserialize($cfg);
					return $db;
				}
			}
		}
		$db = new $driver[1]($cfg);
		if( $model_cache_enabled ) file_put_contents($model_cache_path,serialize($db),LOCK_EX);
		return $db;
	}
}

/**
 * a database
 * @author rob
 */
abstract class reDB {

	private $config = null;
	private $tables = null;
	private $foreign_keys = null;

	public function __construct( &$config ) {
		$this->config = $config;
		$this->connect( $config );
	}

	protected abstract function connect( &$config );
	protected abstract function getSchema();


	// --- query interface ---

	public abstract function queryArray( $sql, $para=null );
	public abstract function queryOneArray( $sql, $para=null );
	public abstract function queryAssoc( $sql, $para=null );
	public abstract function queryOneAssoc( $sql, $para=null );
	public abstract function exec( $sql, $para=null );
	public abstract function getLastInsertId();

	public function queryOneValue( $sql, $para=null ) {
		return ($row=$this->queryOneArray($sql,$para))===null || !isset($row[0]) ? null : $row[0];
	}

	public function queryOneColumn( $sql, $para=null, $col_idx=0 ) {
		if( ($rows=$this->queryArray($sql,$para))===null ) return null;
		$col = array();
		foreach( $rows as $row ) $col[] = $row[$col_idx];
		return $col;
	}

	public function queryKeyValueArray( $sql, $para=null, $key_col_idx=0, $val_col_idx=1 ) {
		if( ($rows=$this->queryArray($sql,$para))===null ) return null;
		$arr = array();
		foreach( $rows as $row ) $arr[$row[$key_col_idx]] = $row[$val_col_idx];
		return $arr;
	}

	public function begin()		{ return $this->exec('BEGIN'); }
	public function commit()	{ return $this->exec('COMMIT'); }
	public function rollback()	{ return $this->exec('ROLLBACK'); }


	// --- schema interface (call loadSchema() before using it!) ---

	public function loadSchema() {
		list($this->tables,$this->foreign_keys) = $this->getSchema();
	}
	public function getTables() {
		if( $this->tables===null ) throw new reException('database schema not loaded yet');
		return $this->tables;
	}
	public function getTable( $table_name ) {
		if( $this->tables===null ) throw new reException('database schema not loaded yet');
		if( !isset($this->tables[$table_name]) ) throw new reException('unknown table: '.$table_name);
		return $this->tables[$table_name];
	}
	public function getForeignKeys() {
		if( $this->foreign_keys===null ) throw new reException('database schema not loaded yet');
		return $this->foreign_keys;
	}
	public function getForeignKey( $foreign_key_name ) {
		if( $this->foreign_keys===null ) throw new reException('database schema not loaded yet');
		if( !isset($this->foreign_keys[$foreign_key_name]) ) throw new reException('unknown foreign key: '.$foreign_key_name);
		return $this->foreign_keys[$foreign_key_name];
	}
	public function onUnserialize( &$config ) {
		$this->config = $config;
		$this->connect( $config );
	}

	public function __toString() {
		if( $this->tables===null ) $this->loadSchema();
		$rt = 'db: ' . "\n";
		foreach( $this->tables as $table ) $rt .= (string)$table . "\n";
		//foreach( $this->primary_keys as $primary_key ) $rt .= (string)$primary_key . "\n";
		//foreach( $this->foreign_keys as $foreign_key ) $rt .= (string)$foreign_key . "\n";
		return $rt;
	}
}


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
		$rt .= '    ' . (string)$this->getPrimaryKey() . "\n";
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



