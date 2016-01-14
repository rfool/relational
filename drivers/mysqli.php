<?php
/**
 * @license Copyright (c) 2008-2016 Robert Frunzke. All rights reserved.
 * For licensing, see LICENSE.md
 */


class reDBMySQLI extends reDB {

	private $conn = null;
	private $query_log = null;

	public function __construct( &$config ) {
		parent::__construct( $config );
	}
	public function __destruct() {
	}

	protected function connect( &$cfg ) {
		if( $this->conn !== null ) throw new reException('mysqli db: already connected');
		$this->conn = mysqli_connect($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['db'],$cfg['port']/*,$cfg['socket']*/);
		mysqli_set_charset($this->conn,'utf8');
		if( isset($cfg['query_log']) ) $this->query_log = $cfg['query_log'];
	}

	public function __wakeup() {
		$this->conn = null;
	}

	public function queryArray( $sql, $para=null ) {
		return mysqli_fetch_all($this->querySqlRessource($sql,$para),MYSQLI_NUM);
	}

	public function queryOneArray( $sql, $para=null ) {
		return ($row=mysqli_fetch_row($this->querySqlRessource($sql,$para)))===false ? null : $row;
	}

	public function queryAssoc( $sql, $para=null ) {
		return ($res=mysqli_fetch_all($this->querySqlRessource($sql,$para),MYSQLI_ASSOC))===false ? array() : $res;
	}

	public function queryOneAssoc( $sql, $para=null ) {
		return ($row=mysqli_fetch_assoc($this->querySqlRessource($sql,$para)))===false ? null : $row;
	}

	public function exec( $sql, $para=null ) {
		$rs = $this->querySqlRessource($sql,$para);
		return true;
	}

	public function getLastInsertId() {
		return mysqli_insert_id($this->conn);
	}

	private function querySqlRessource( $sql, $para=null ) {
		if( $para===null ) $para = array();
		else if( !is_array($para) ) $para = array( $para );

		// replace "?" with actual parameters
		$sql_parts = explode('?',$sql);
		$sql_mysqli = $sql_parts[0];
		for( $i=1; isset($sql_parts[$i]); ++$i ) {
			$v = $para[$i-1];

			if( $v===null ) $sql_mysqli .= 'NULL';
			else if( is_bool($v) ) $sql_mysqli .= $v?'1':'0';
			else if( is_array($v) ) $sql_mysqli .= '{'.implode(',',$v).'}';
			else $sql_mysqli .= '\''.mysqli_real_escape_string($this->conn,(string)$v).'\'';

			$sql_mysqli .= $sql_parts[$i];
		}
		if( $this->query_log!==null ) {
			// with quick & dirty query profiling
			$prof_start = microtime(true);
			if( ($rs=@mysqli_query($this->conn,$sql_mysqli))===false ) throw new reException('mysqli db: sql query failed ('.mysqli_error($this->conn).')');
			$prof_end = microtime(true);
			$prof_ll = sprintf('%8.2f',1000*($prof_end-$prof_start)).' ms: '.$sql.'        ('.implode(',',$para).')';
			file_put_contents(dirname(__FILE__).'/../../'.$this->query_log,$prof_ll."\n",FILE_APPEND|LOCK_EX);
		} else {
			// without profiling
			if( ($rs=@mysqli_query($this->conn,$sql_mysqli))===false ) throw new reException('mysqli db: sql query failed ('.mysqli_error($this->conn).')');
		}
		return $rs;
	}

	protected function getSchema() {
		//TODO
		die('reDBMySQLI::getSchema() not implemented yet');
		return array();
	}

}

