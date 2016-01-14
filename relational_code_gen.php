<?php
/**
 * @license Copyright (c) 2008-2016 Robert Frunzke. All rights reserved.
 * For licensing, see LICENSE.md
 */

/**
 * Utility class for generating ORM wrapping code for relational database tables & views
 * @author rob
 */
class reDBCodeGen {

	/**
	 * @var reDB
	 */
	private $db;

	/**
	 * @var string
	 */
	private $class_prefix = 'rm';

	/**
	 * @var array
	 */
	private $table_hints = array();

	/**
	 * @var string
	 */
	private $item_base_class = 'reOMItem';


	/**
	 * @param reDB $db
	 * @param string $class_prefix
	 */
	public function __construct( reDB $db, $class_prefix ) {
		$this->db = $db;
		$this->class_prefix = $class_prefix;
	}

	/**
	 * @param array $hints
	 */
	public function setTableHints( $hints ) {
		$this->table_hints = $hints;
	}

	/**
	 * @param string $item_base_class
	 */
	public function setItemBaseClass( $item_base_class ) {
		$this->item_base_class = $item_base_class;
	}


	private function get_ORM_item_name( $name, $single=true ) {
		if( $single && substr($name,-1)=='s' ) {
			// exception: 'status'
			if( substr($name,-6)!='status' ) $name = substr($name,0,-1);
		}
		return to_camel_case($name,true);
	}
	private function get_ORM_use_class( $table_name ) {
		return isset($this->table_hints[$table_name]) && isset($this->table_hints[$table_name]['skip_impl']) && $this->table_hints[$table_name]['skip_impl']===true ? false : true;
	}
	private function get_ORM_class_name( $table_name, $lookup_impl_class=false ) {
		if( $lookup_impl_class && isset($this->table_hints[$table_name]) && isset($this->table_hints[$table_name]['impl_class']) ) return $this->table_hints[$table_name]['impl_class'];
		return $this->class_prefix.$this->get_ORM_item_name($table_name,true);
	}
	private function get_ORM_fk_name( reDBForeignKey $fk ) {
		// array( column => referenced_colum )
		$cols = array_keys( $fk->getColumnReferences() );
		foreach( $cols as $i=>$col ) {
			if( substr($col,-3)=='_id' ) $cols[$i] = substr($col,0,-3);
		}
		return to_camel_case( implode('_',$cols), true );
	}
	private function get_ORM_order_by( reDBTable $table ) {
		$table_name = $table->getName();
		if( isset($this->table_hints[$table_name]) && isset($this->table_hints[$table_name]['order_by']) ) return $this->table_hints[$table_name]['order_by'];
		if( $table->isView() ) {
			return null;
		} else {
			$pk = $table->getPrimaryKey();
			return implode(',',$pk->getColumnNames());
		}
	}
	private function getPHPTypeForColumn( reDBColumn $column ) {
		switch( $column->getType() ) {
		case 'bigint':					return 'int';
		case 'integer':					return 'int';
		case 'boolean':					return 'bool';
		case 'ARRAY':					return 'int[]';	// quirk: we use integer arrays only, so this is fine..
		case 'timestamp with time zone':
		case 'timestamp without time zone':
		case 'character varying':
		case 'text':
		case 'USER-DEFINED':
		default:						return 'string';
		}
	}
	private function getPHPCastCodeForColumn( reDBColumn $column, $vc ) {
		switch( $column->getType() ) {
		case 'bigint':					return '(int)'.$vc;
		case 'integer':					return '(int)'.$vc;
		case 'boolean':					return "(".$vc."=='t'||".$vc."=='true')";
		case 'ARRAY':					return "\$this->_getRoot()->_parseDBIntArray(".$vc.")";	// quirk: we use integer arrays only, so this is fine..
		case 'timestamp with time zone':
		case 'timestamp without time zone':
		case 'character varying':
		case 'text':
		case 'USER-DEFINED':
		default:						return $vc;
		}
	}

	public function generateAll() {
		ob_start();
		$this->db->loadSchema();
		echo '<'.'?'."php\n";
		echo "/*------------------------------------------------------------------------------\n";
		echo " (c)(r) 2008-2016 IT-Service Robert Frunzke\n";
		echo "--------------------------------------------------------------------------------\n";
		echo "------------------------------------------------------------------------------*/\n\n";

		$all_fks = $this->db->getForeignKeys();

		$root_class_name = $this->get_ORM_class_name('root',false);//'reOMRoot';

		// item objects
		foreach( $this->db->getTables() as $table_name => $table ) {

			if( !$this->get_ORM_use_class($table_name) ) continue;

			$table_fks = $table->getForeignKeys();
			$columns = $table->getColumns();
			echo "/**\n";
			echo " * ORM wrapper class for items of DB ".($table->isView()?"view":"table")." ".$table_name.".\n";
			echo " * \n";
			echo " * DB Schema:\n";
			foreach( explode("\n",(string)$table) as $line ) echo " * ".$line."\n";
			echo " */\n";
			echo "class ".$this->get_ORM_class_name($table_name,false)." extends ".$this->item_base_class." {\n";
			echo "\n";
			// public column value members (public to make them visible when using json_encode())
			foreach( $columns as $column_name=>$column ) {
				echo "\t/**\n";
				echo "\t * @var ".$this->getPHPTypeForColumn($column)."\n";
				echo "\t */\n";
				echo "\tpublic \$".$column_name.";\n";
			}
			echo "\n";

			// constructor - sets normalized values, according to sql column data type (e.g. ints, bigints, ...)
			echo "\t/**\n";
			echo "\t * constructor\n";
			echo "\t * @param ".$root_class_name." \$root\n";
			echo "\t * @param array \$data\n";
			echo "\t */\n";
			echo "\tpublic function __construct( ".$root_class_name." \$root, array \$data ) {\n";
			echo "\t\tparent::__construct(\$root);\n";
			foreach( $columns as $column_name=>$column ) {
				echo "\t\t\$this->".$column_name." = ".$this->getPHPCastCodeForColumn($column,"\$data['".$column_name."']").";\n";
			}
			echo "\t}\n";
			echo "\n";

			// toArray() - return data in an associative array
			echo "\t/**\n";
			echo "\t * return all properties in plain array\n";
			echo "\t * @return array\n";
			echo "\t */\n";
			echo "\tpublic function toArray() {\n";
			echo "\t\treturn array(\n";
			foreach( $columns as $column_name=>$column ) {
				echo "\t\t\t'".$column_name."'=>\$this->".$column_name.",\n";
			}
			echo "\t\t);\n";
			echo "\t}\n";
			echo "\n";

			echo "\t// --- column value getter --- \n\n";
			foreach( $columns as $column_name=>$column ) {
				echo "\t/**\n";
				echo "\t * @return ".$this->getPHPTypeForColumn($column)."\n";
				echo "\t */\n";
				echo "\tpublic function get".$this->get_ORM_item_name($column_name,false)."() {\n";
				echo "\t\treturn \$this->".$column_name.";\n";
				echo "\t}\n";
			}
			echo "\n";

			if( $table->isView() ) {

				// --- nothing yet ---

			} else {
			
				echo "\t// --- referenced objects --- \n\n";
				foreach( $table_fks as $constraint_name=>$fk ) {
					$fk_ref_table = $fk->getReferencesTable();
					$fk_ref_table_name = $fk_ref_table->getName();
					if( $this->get_ORM_use_class($fk_ref_table_name) ) {
						$fk_col_refs = $fk->getColumnReferences();
						echo "\t/**\n";
						echo "\t * @return ".$this->get_ORM_class_name($fk_ref_table_name,true)."\n";
						echo "\t */\n";
						echo "\tpublic function get".$this->get_ORM_fk_name($fk)."() {\n";
						echo "\t\treturn \$this->_getRoot()->get".$this->get_ORM_item_name($fk_ref_table_name,true)."(";
						$tmp = array();
						// TODO: match parameter order to order of columns in primary key
						foreach( $fk_col_refs as $column_name=>$referenced_column_name ) $tmp[] = "\$this->".$column_name;
						echo implode(',',$tmp);
						echo ");\n";
						echo "\t}\n";
					}
				}
				echo "\n";

				$use_fk_constraint_names = isset($this->table_hints[$table_name]) && isset($this->table_hints[$table_name]['use_fk_constraint_names']) ? !!$this->table_hints[$table_name]['use_fk_constraint_names'] : false;

				echo "\t// --- objects referencing this one --- \n\n";
				foreach( $all_fks as $constraint_name=>$fk ) {
					if( $table===$fk->getReferencesTable() ) {
						$fk_table = $fk->getTable();
						$fk_table_name = $fk_table->getName();
						if( $this->get_ORM_use_class($fk_table_name) ) {
							$flt_data = array();
							foreach( $fk->getColumnReferences() as $column_name=>$references_column_name ) $flt_data[] = "'".$column_name."'=>\$this->".$references_column_name;
							echo "\t/**\n";
							echo "\t * @return ".$this->get_ORM_class_name($fk_table_name,true)."[]\n";
							echo "\t */\n";
							if( $use_fk_constraint_names ) {
								// extended style: derive getter name from constraint name...
								/*
								$gc_name = strncmp(substr($constraint_name,-5,5),'_fkey',5)===0 ? substr($constraint_name,0,-5) : $constraint_name;
								$fktnl = strlen($fk_table_name);
								if( strncmp($gc_name,$fk_table_name.'_',$fktnl+1)===0 ) $gc_name = substr($gc_name,$fktnl+1);
								$tmp_getter_name = $this->get_ORM_item_name($fk_table_name,false) . '_' . $gc_name;
								*/
								$tmp_getter_name = $this->get_ORM_item_name($fk_table_name,false)
												 . 'By'
												 . $this->get_ORM_fk_name($fk)
												 ;
							} else {
								// simple/classic style: just use fk table name in getter name
								// --> THIS WILL NOT WORK when one table has multiple foreign key constraints to a target table
								// --> use extended style in these cases!
								$tmp_getter_name = $this->get_ORM_item_name($fk_table_name,false);
							}
							echo "\tpublic function get".$tmp_getter_name."( \$filter=null, \$order_by=null ) {\n";
							echo "\t\t return \$this->_getRoot()->get".$this->get_ORM_item_name($fk_table_name)."List(\$filter===null?array(".implode(',',$flt_data)."):array_merge(\$filter,array(".implode(',',$flt_data).")),\$order_by);\n";
							echo "\t}\n";
						}
					}
				}
				echo "\n";

			}

			echo "}\n\n";
		}

		// the root object
		echo "class ".$root_class_name." extends reOMRoot {\n";
		echo "\n";
		foreach( $this->db->getTables() as $table_name => $table ) {
			if( $this->get_ORM_use_class($table_name) ) {
				if( $table->isView() ) {
					$table_pk = isset($this->table_hints[$table_name]) && isset($this->table_hints[$table_name]['pseudo_view_pk']) ? $this->table_hints[$table_name]['pseudo_view_pk'] : null;
					if( $table_pk !== null ) {
						$table_pk = is_array($table_pk) ? $table_pk : array($table_pk);
						$php_para = array();
						$sql_para = array();
						foreach( $table_pk as $column_name ) {
							$php_para[] = '$'.$column_name;
							$sql_para[] = $column_name.'=?';
						}
						// get item object by primary key
						$class_name = $this->get_ORM_class_name($table_name,true);
						echo "\t/**\n";
						echo "\t * @return ".$class_name."\n";
						echo "\t */\n";
						echo "\tpublic function get".$this->get_ORM_item_name($table_name,true)."( ".implode(', ',$php_para)." ) {\n";
						echo "\t\treturn \$this->_createObject('".$class_name."','SELECT * FROM ".$table_name." WHERE ".implode(' AND ',$sql_para)."',array(".implode(',',$php_para)."));\n";
						echo "\t}\n";
					}
				} else {
					$table_pk = $table->getPrimaryKey();
					$php_para = array();
					$sql_para = array();
					foreach( $table_pk->getColumnNames() as $column_name ) {
						$php_para[] = '$'.$column_name;
						$sql_para[] = $column_name.'=?';
					}
					// get item object by primary key
					$class_name = $this->get_ORM_class_name($table_name,true);
					echo "\t/**\n";
					echo "\t * @return ".$class_name."\n";
					echo "\t */\n";
					echo "\tpublic function get".$this->get_ORM_item_name($table_name,true)."( ".implode(', ',$php_para)." ) {\n";
					echo "\t\treturn \$this->_createObject('".$class_name."','SELECT * FROM ".$table_name." WHERE ".implode(' AND ',$sql_para)."',array(".implode(',',$php_para)."));\n";
					echo "\t}\n";
				}
				// get item object lists
				$lists_filter = isset($this->table_hints[$table_name]) && isset($this->table_hints[$table_name]['lists_filter']) ? $this->table_hints[$table_name]['lists_filter'] : "";
				$default_order_by = $this->get_ORM_order_by($table);
				$class_name = $this->get_ORM_class_name($table_name,true);
				echo "\t/**\n";
				echo "\t * @return ".$class_name."[] \n";
				echo "\t */\n";
				echo "\tpublic function get".$this->get_ORM_item_name($table_name,true)."List( \$filter=null, \$order_by=null ) {\n";
				echo "\t\tif( \$order_by===null ) \$order_by = '".$default_order_by."';\n";
				echo "\t\treturn \$this->_createObjectListWithFilter('".$class_name."','SELECT * FROM ".$table_name." WHERE true".($lists_filter?" AND (".$lists_filter.")":"")."',\$order_by?' ORDER BY '.\$order_by:'',\$filter);\n";
				echo "\t}\n\n";
			}
		}
		echo "}\n\n";

		echo "/*\n";
		echo "source schema:\n\n";
		echo (string)$this->db;
		echo "*/\n\n";

		return ob_get_clean();
	}
}

