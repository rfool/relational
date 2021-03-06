<?php
/**
 * @license Copyright (c) 2008-2016 Robert Frunzke. All rights reserved.
 * For licensing, see LICENSE.md
 */


class reDBPostgres extends reDB {

	private $conn = null;
	private $query_log = null;

	public function __construct( &$config ) {
		parent::__construct( $config );
	}
	public function __destruct() {
	}

	protected function connect( &$config ) {
		if( $this->conn !== null ) throw new reException('postgres db: already connected');
		if( ($this->conn=pg_connect($config['connection_string']))===false ) throw new reException('postgres db connect failed: '.pg_last_error(null));
		pg_set_error_verbosity($this->conn,PGSQL_ERRORS_VERBOSE);
		if( isset($config['query_log']) ) $this->query_log = $config['query_log'];
	}

	public function __wakeup() {
		$this->conn = null;
	}

	public function queryArray( $sql, $para=null ) {
		if( ($rs=$this->querySqlRessource($sql,$para))===null ) return array();
		$r = array();
		while( ($row=pg_fetch_row($rs))!==false ) $r[] = $row;
		return $r;
	}

	public function queryOneArray( $sql, $para=null ) {
		return ($row=pg_fetch_row($this->querySqlRessource($sql,$para)))===false ? null : $row;
	}

	public function queryAssoc( $sql, $para=null ) {
		return ($res=pg_fetch_all($this->querySqlRessource($sql,$para)))===false ? array() : $res;
	}

	public function queryOneAssoc( $sql, $para=null ) {
		return ($row=pg_fetch_assoc($this->querySqlRessource($sql,$para)))===false ? null : $row;
	}

	public function exec( $sql, $para=null ) {
		$rs = $this->querySqlRessource($sql,$para);
		return true;
	}

	public function getLastInsertId() {
		return ($row=$this->queryOneArray('SELECT lastval()'))===null ? null : $row[0];
	}

	private function prepareSqlParam( $v, $is_part_of_array_literal=false ) {
		if( $v===null ) {								// pg_query_params() will handle NULL parameter values as expected
			return $is_part_of_array_literal ? 'null' : null;
		} else if( is_bool($v) ) {						// 0 or 1 is ok (t or f would be ok too, but 0 and 1 are more universal, e.g. will also work on int fields..)
			return $v ? '1' : '0';
		} else if( is_array($v) ) {						// postgres arrays are complicated ...
			//$para[$i] = '{'.implode(',',$v).'}';		// would support arrays containing numeric types only
			return $this->prepareSqlArrayParam($v);
		}
		if( $is_part_of_array_literal ) {
			if( is_numeric($v) ) return $v;
			else return '"'.str_replace('"','\\"',str_replace('\\','\\\\',$v)).'"';
		}
		return (string)$v;
	}
	private function prepareSqlArrayParam( $arr ) {
		$r = array();
		foreach( $arr as $v ) $r[] = $this->prepareSqlParam($v,true);
		return '{'.implode(',',$r).'}';
	}

	private function querySqlRessource( $sql, $para=null ) {
		if( $para===null ) $para = array();
		else if( !is_array($para) ) $para = array( $para );
		// replace "?" with "$X" placeholders..
		$sql_parts = explode('?',$sql);
		$sql_pg = $sql_parts[0];
		for( $i=1; isset($sql_parts[$i]); ++$i ) $sql_pg .= '$'.$i.$sql_parts[$i];
		// prepare typed data in parameter array
		foreach( $para as $i=>$v ) {
			$para[$i] = $this->prepareSqlParam($v);
		}
		if( $this->query_log!==null ) {
			// with quick & dirty query profiling
			$prof_start = microtime(true);
			if( ($rs=@pg_query_params($this->conn,$sql_pg,$para))===false ) throw new reException('postgres: sql query failed ('.pg_last_error($this->conn).')');
			$prof_end = microtime(true);
			$prof_ll = sprintf('%8.2f',1000*($prof_end-$prof_start)).' ms: '.$sql.'        ('.implode(',',$para).')';
			file_put_contents(dirname(__FILE__).'/../../'.$this->query_log,$prof_ll."\n",FILE_APPEND|LOCK_EX);
		} else {
			// without profiling
			if( ($rs=@pg_query_params($this->conn,$sql_pg,$para))===false ) throw new reException('postgres: sql query failed ('.pg_last_error($this->conn).')');
		}
		return $rs;
	}


	private function parseSqlArray( $s, $et, $start=0, &$end=NULL ) {
		if( !isset($s[0]) || $s[0]!='{' ) return null;
		$return = array();
		$br = 0;
		$string = false;
		$quote = '';
		$len = strlen($s);
		$v = '';
		for( $i=$start+1; $i<$len; ++$i ) {
			$ch = $s[$i];
			if( !$string && $ch=='}' ) {
				if( $v!=='' || !empty($return) ) $return[] = $this->cvtSqlDataToPHP($v,$et);
				$end = $i;
				break;
			} else if( !$string && $ch=='{' ) {
				$v = $this->parseSqlArray($s,$et,$i,$i);
			} else if( !$string && $ch==',' ) {
				$return[] = $this->cvtSqlDataToPHP($v,$et);
				$v = '';
			} else if( !$string && ($ch=='"'||$ch=="'") ) {
				$string = true;
				$quote = $ch;
			} else if( $string && $ch==$quote && $s[$i-1]=="\\" ) {
				$v = substr($v,0,-1).$ch;
			} else if( $string && $ch==$quote && $s[$i-1]!="\\" ) {
				$string = false;
			} else {
				$v .= $ch;
			}
		}
		return $return;
	}
	private function cvtSqlDataToPHP( $v, $t ) {
		if( isset($t[0]) && $t[0]==='_' ) {
			$et = substr($t,1);
			if( ($varr=$this->parseSqlArray($v,$et))===null ) return null;
			return $varr;
		}
		switch( $t ) {
		case 'int2': return intval($v,10);
		case 'int4': return intval($v,10);
		case 'int8': return intval($v,10);
		case 'bool': return $v==='t' ? true : false;
		case 'float4': return floatval($v);
		case 'float8': return floatval($v);
		}
		return $v;
	}
	private function cvtSqlDataRowToPhp( &$row, &$field_types ) {
		foreach( $row as $fn => $v ) {
			if( $v!==null ) { // null values were already converted by pg_fetch_all()
				$row[$fn] = $this->cvtSqlDataToPHP($v,$field_types[$fn]);
			}
		}
	}
	private function getPgFieldTypesByName( $rs, $r0 ) {
		//$ncols = pg_num_fields($rs);
		$field_types = [];
		foreach( $r0 as $fn=>$v0 ) {
			$fi = pg_field_num($rs,$fn);
			$ft = pg_field_type($rs,$fi);
			$field_types[$fn] = $ft;
		}
		//var_dump($field_types);exit;
		return $field_types;
	}
	private function getPgFieldTypesByIdx( $rs, $r0 ) {
		//$ncols = pg_num_fields($rs);
		$field_types = [];
		foreach( $r0 as $fi=>$v0 ) {
			$ft = pg_field_type($rs,$fi);
			$field_types[$fi] = $ft;
		}
		//var_dump($field_types);exit;
		return $field_types;
	}

	// WIP interface for queries with typed result data ...
	public function queryArray2( $sql, $para=null ) {
		if( ($rs=$this->querySqlRessource($sql,$para))===null ) return array();
		if( ($row0=pg_fetch_row($rs))===false ) return array();
		$field_types = $this->getPgFieldTypesByIdx($rs,$row0);
		$this->cvtSqlDataRowToPhp($row0,$field_types);
		$r = array($row0);
		while( ($row=pg_fetch_row($rs))!==false ) {
			$this->cvtSqlDataRowToPhp($row,$field_types);
			$r[] = $row;
		}
		return $r;
	}
	public function queryAssoc2( $sql, $para=null ) {
		$rs = $this->querySqlRessource($sql,$para);
		if( ($r=pg_fetch_all($rs))===false || count($r)===0 ) return array();
		$field_types = $this->getPgFieldTypesByName($rs,$r[0]);
		foreach( $r as $row_idx => $row ) $this->cvtSqlDataRowToPhp($r[$row_idx],$field_types);
		return $r;
	}
	public function queryOneAssoc2( $sql, $para=null ) {
		$rs = $this->querySqlRessource($sql,$para);
		if( ($row=pg_fetch_assoc($rs))===false ) return null;
		$field_types = $this->getPgFieldTypesByName($rs,$row);
		$this->cvtSqlDataRowToPhp($row,$field_types);
		return $row;
	}


	protected function getSchema() {
		$schema = 'public';
		$tables = array();				// table_name -> reDBTable
		$views = array();				// view_name -> reDBView
		$columns = array();				// table_name -> array( column_name->reDBColumn, column_name->reDBColumn, .. )
		$all_columns = array();			// i -> reDBColumn
		$primary_keys = array();		// table_name -> reDBPrimaryKey
		$foreign_keys = array();		// table_name -> array( constraint_name->reDBForeignKey, constraint_name->reDBForeignKey, .. )
		$all_foreign_keys = array();	// constraint_name -> reDBForeignKey
		// get tables
		foreach( $this->queryAssoc('SELECT table_name,table_type FROM information_schema.tables WHERE table_schema=? ORDER BY table_type,table_name',$schema) as $table_def ) {
			$table_name = $table_def['table_name'];
			$table_escaped_name = pg_escape_identifier($this->conn,$table_name);
			switch( $table_def['table_type'] ) {
			case 'VIEW':       $tables[$table_name] = new reDBView(  $this, $table_name, $table_escaped_name ); break;
			case 'BASE TABLE': $tables[$table_name] = new reDBTable( $this, $table_name, $table_escaped_name ); break;
			}
		}
		// get columns
		foreach( $this->queryAssoc('SELECT column_name,ordinal_position,table_name,data_type FROM information_schema.columns WHERE table_schema=? ORDER BY table_name,ordinal_position',$schema) as $def ) {
			$column_name = $def['column_name'];
			$column_index = intval($def['ordinal_position'],10)-1;
			$column_table_name = $def['table_name'];
			$column_escaped_name = pg_escape_identifier($this->conn,$column_name);
			$column = new reDBColumn( $tables[$column_table_name], $column_name, $column_escaped_name, $column_index, $def['data_type'] );
			$all_columns[] = $column;
			$columns[$column_table_name][$column_name] = $column;
		}
		// get primary key constraints
		$tmp_primary_keys = array(); // constraint_name -> array( 'table'=>reDBTable, 'column_names'=>array( column_name, column_name, .. ) )
		$rows = $this->queryAssoc( 'SELECT tc.constraint_name,tc.table_name,kcu.column_name
							         FROM information_schema.table_constraints tc
							              LEFT JOIN information_schema.key_column_usage kcu ON tc.constraint_catalog=kcu.constraint_catalog AND tc.constraint_schema=kcu.constraint_schema AND tc.constraint_name=kcu.constraint_name
							              LEFT JOIN information_schema.referential_constraints rc ON tc.constraint_catalog=rc.constraint_catalog AND tc.constraint_schema=rc.constraint_schema AND tc.constraint_name=rc.constraint_name
							         WHERE tc.table_schema=? AND tc.constraint_type=\'PRIMARY KEY\'
							         ORDER BY tc.constraint_name, kcu.ordinal_position', $schema );
		foreach( $rows as $row ) {
			$constraint_name = $row['constraint_name'];
			if( !isset($tmp_primary_keys[$constraint_name]) ) $tmp_primary_keys[$constraint_name] = array( 'table_name'=>$row['table_name'], 'column_names'=>array() );
			$tmp_primary_keys[$constraint_name]['column_names'][] = $row['column_name'];
		}
		// create primary keys objects
		foreach( $tmp_primary_keys as $constraint_name => $pk_tmp ) {
			$table_name = $pk_tmp['table_name'];
			$primary_keys[$table_name] = new reDBPrimaryKey( $tables[$table_name], $constraint_name, $pk_tmp['column_names'] );
		}
		// get foreign key constraints
		$tmp_foreign_keys = array(); // constraint_name -> array( 'table'=>reDBTable, 'column_references'=>array( column_name=>references_column_name, column_name=>references_column_name, .. ) )
		$rows = $this->queryAssoc('SELECT tc.constraint_name AS constraint_name,
										  kcu1.table_name AS from_table_name, kcu1.column_name AS from_column_name,
										  kcu2.table_name AS to_table_name, kcu2.column_name AS to_column_name
									FROM information_schema.table_constraints tc
										 LEFT JOIN information_schema.referential_constraints rc ON tc.constraint_catalog=rc.constraint_catalog AND tc.constraint_schema=rc.constraint_schema AND tc.constraint_name=rc.constraint_name
										 LEFT JOIN information_schema.key_column_usage kcu1 ON tc.constraint_catalog=kcu1.constraint_catalog AND tc.constraint_schema=kcu1.constraint_schema AND tc.constraint_name=kcu1.constraint_name
										 LEFT JOIN information_schema.key_column_usage kcu2 ON tc.constraint_catalog=kcu2.constraint_catalog AND tc.constraint_schema=kcu2.constraint_schema AND kcu2.constraint_name=rc.unique_constraint_name AND kcu2.ordinal_position=kcu1.ordinal_position
									WHERE tc.table_schema=? AND tc.constraint_type=\'FOREIGN KEY\'
									ORDER BY tc.constraint_name, kcu1.ordinal_position', $schema );
		foreach( $rows as $row ) {
			$constraint_name = $row['constraint_name'];
			if( !isset($tmp_foreign_keys[$constraint_name]) ) $tmp_foreign_keys[$constraint_name] = array( 'table_name'=>$row['from_table_name'], 'references_table'=>$row['to_table_name'], 'column_references'=>array() );
			$tmp_foreign_keys[$constraint_name]['column_references'][$row['from_column_name']] = $row['to_column_name'];
		}
		// create foreign key objects
		foreach( $tmp_foreign_keys as $constraint_name => $fk_tmp ) {
			$table_name = $fk_tmp['table_name'];
			$foreign_key = new reDBForeignKey( $tables[$table_name], $constraint_name, $tables[$fk_tmp['references_table']], $fk_tmp['column_references'] );
			$foreign_keys[$table_name][$constraint_name] = $foreign_key;
			$all_foreign_keys[$constraint_name] = $foreign_key;
		}
		// initialize tables -> assign them their columns and constraints
		foreach( $tables as $table_name => $table ) {
			$table_columns = isset($columns[$table_name]) ? $columns[$table_name] : array();
			if( $table->isView() ) $table->initialize( $table_columns );
			else $table->initialize( $table_columns, isset($primary_keys[$table_name])?$primary_keys[$table_name]:null, isset($foreign_keys[$table_name])?$foreign_keys[$table_name]:array() );
		}
		// return the model
		return array( $tables, $all_foreign_keys );
	}

}

