<?php
/**
 * @license Copyright (c) 2008-2016 Robert Frunzke. All rights reserved.
 * For licensing, see LICENSE.md
 */


require dirname(__FILE__).'/relational_schema.php';


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
 */
class reDBFactory {
	private static $drivers = [
		'postgres'	=> [ 'postgres.php', 'reDBPostgres' ],
		'mysqli'	=> [ 'mysqli.php',   'reDBMySQLI'   ],
	];
	/**
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


	// WIP interface for queries with typed result data ...
	public function queryArray2( $sql, $para=null )			{ return $this->queryArray($sql,$para); }
	public function queryAssoc2( $sql, $para=null )			{ return $this->queryAssoc($sql,$para); }
	public function queryOneAssoc2( $sql, $para=null )		{ return $this->queryOneAssoc($sql,$para); }


	public function queryOneValue( $sql, $para=null ) {
		return ($row=$this->queryOneArray($sql,$para))===null || !isset($row[0]) ? null : $row[0];
	}

	public function queryOneColumn( $sql, $para=null, $col_idx=0 ) {
		if( ($rows=$this->queryArray($sql,$para))===null ) return null;
		$col = [];
		foreach( $rows as $row ) $col[] = $row[$col_idx];
		return $col;
	}

	public function queryKeyValueArray( $sql, $para=null, $key_col_idx=0, $val_col_idx=1 ) {
		if( ($rows=$this->queryArray($sql,$para))===null ) return null;
		$arr = [];
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




