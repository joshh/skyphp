<?

/**
	
	@class  model 
	@param	(mixed) id/ide or null
	@param	(string) aql/model_name/ or null

**/

class model {

	protected $_aql = null; // store actual .aql file when found or input aql
	protected $_model_name = null; // from classname
	protected $_aql_array = array(); // generated by $_aql
	protected $_properties = array(); // array of fields generated by the model
	protected $_errors = array(); // return errors
	protected $_objects = array(); // names of objects
	protected $_id; // identifier set in loadDB if successsful
	protected $_return = array();

	public $_data; // all stored data, corresponds to each of $_properties

	public function __construct($id = null, $aql = null) {
		$this->_model_name = get_class($this);
		$this->getAql($aql);
		$this->makeProperties();
		if ($id) $this->loadDB($id);
	} 

/**
	
	@function	__get
	@return		(mixed)
	@param		(string)

**/
	public function __get($name) {
		if (!$this->propertyExists($name)) {
			$this->_errors[] = "Property \"{$name}\" does not exist and cannot be called in this model.";
			return false;
		} else {
			return $this->_data[$name];
		}
	}

/**
	
	@function	__set
	@return		(model)
	@param		(string)
	@param		(mixed)

**/
	public function __set($name, $value) {
		if ($this->propertyExists($name) || preg_match('/_id(e)*?$/', $name)) {
			if (method_exists($this, 'set_'.$name)) {
				if ($this->{'set_'.$name}($value)) {
					$this->_data[$name] = $value;
				}
			} else {
				$this->_data[$name] = $value;
			}
		} else {
			$this->_errors[] = 'Property '.$name.' does not exist in this model.';
		}
		return $this;
	}

/**

	@function	delete
	@return		(null)
	@param		(null)

**/

	public function delete() {
		$p = reset($this->_aql_array);
		$table = $p['table'];
		if ($this->_id) 
			aql::update($table, array('active' => 0), $this->_id);
		else
			$this->_errors[] = 'Identifier is not set, there is nothing to delete.';
	}

/**
	
	@function	getAql
	@return		(model)
	@param		(string) -- aql, or model name, or null

**/

	public function getAql($aql = null) {
		if (!$aql) {
			$this->_aql = aql::get_aql($this->_model_name);
		} else if (aql::is_aql($aql)) {
			$this->_aql = $aql;
		} else {
			$this->_aql = aql::get_aql($aql);
		}
		return $this;
	}

/**

	@function	getAqlArray
	@return		(array)
	@param		(null)

**/

	public function getAqlArray() {
		return $this->_aql_array;
	}

/**

	@function 	getModel
	@return		(string)
	@param		(null)

**/

	public function getModel() {
		return $this->_aql;
	}

/**
 
 	@function	getProperties
 	@return		(array)
 	@param		(null)

**/
	public function getProperties() {
		return $this->_properties;
	}

/**
 
 	@function	isModelClass
 	@return		(bool)
 	@param		(mixed) class instance

**/
	public static function isModelClass($class) {
		$class = new ReflectionClass($class);
		$parent = $class->getParentClass();
		if ($parent->name == 'model' || get_class($class) == 'model') return true;
		else return false;
	}

/**

	@function	isObjectParam
	@return		(bool)
	@param		(string)

**/

	public function isObjectParam($str) {
		if (in_array($str, $this->_objects)) return true;
		else return false;
	}

/**

	@function 	loadArray
	@return		(model)
	@param		(array) -- data array in the proper format

**/

	public function loadArray( $array) {
		if (is_array($array)) foreach ($array as $k => $v) {
			if ($this->propertyExists($k) || preg_match('/(_|\b)id(e)*?$/', $k)) {
				if ($this->isObjectParam($k)) {
					foreach ($v as $i => $step) {
						aql::include_class_by_name($k);
						if (class_exists($k))
							$this->_data[$k][$i] = new $k();
						else
							$this->_data[$k][$i] = new model(null, $k);
						
						$this->_data[$k][$i]->loadArray($step);
					}
				} else if (is_array($v)) {
					$this->_data[$k] = $this->toArrayObject($v);
				} else {
					if (substr($k, -4) == '_ide') {
						$d = aql::get_decrypt_key($k);
						$decrypted = decrypt($v, $d);
						if (is_numeric($decrypted)) {
							$field = substr($k, 0, -1);
							$this->_data[$field] = $decrypted;
							$this->_properties[] = $field;
						}
					}
					$this->_data[$k] = $v;
					if (!$this->propertyExists($k)) $this->_properties[] = $k;
				}
			} else {
			//	$this->_errors[] = '"'.$k.'" is not a valid property.';
			}
		}
		return $this;
	}
/**
 
 	@function	loadDB
 	@return 	(model)
 	@param		(string) identifier

**/
	public function loadDB( $id) {
		if (!is_numeric($id)) {
			$table = reset($this->_aql_array);
			$decrypt_key = $table['table'];
			$id = decrypt($id, $decrypt_key);
		}
		if (is_numeric($id)) {
			$o = aql::profile($this->_aql_array, $id, true, $this->_aql);
			$rs = $o->returnDataArray();
			if (get_class($o) == 'model' && is_array($rs)) {
				$this->_data = $rs;
			} else {
				$this->_errors[] = 'No data found for this identifier.';
				return $this;
			}
		} else {
			$this->_errors[] = 'AQL Model Error: identifier needs to be an integer or an IDE.';
			return $this;
		}
	}

/**

	@function 	loadIDs
	@return		(null)
	@param		(array) ids, used for save

**/

	public function loadIDs($ids = array()) {
		foreach ($ids as $k => $v) {
			if (!$this->_data[$k] && $this->propertyExists($k)) $this->_data[$k] = $v;
		}
	}

/**
 
 	@function	loadJSON
 	@return		(model)
 	@param		(string)

**/
	public function loadJSON($json) {
		$array = json_decode($json);
		if (is_array($array)) return $this->loadArray($array);
		$this->_errors[] = 'ERROR Loading JSON. JSON was not valid.';
		return $this;
	}


/**

	@function 	makeFKArray
	@return		(array) 
	@param		(array)

	makes a foreign key array from the aql_array

**/
	public function makeFKArray($aql_array) {
		$fk = array();
		foreach ($aql_array as $k => $v) {
			if (is_array($v['fk'])) foreach ($v['fk'] as $f) {
				$fk[$f][] = $v['table']	;
			}
		}
		return $fk;
	}

/**

	@function 	makeSaveArray (recursive)
	@return		(array) save array
	@param		(array) data array
	@param		(array) aql_array

**/

	public function makeSaveArray($data_array, $aql_array) {
		$tmp = array();
		if (is_array($data_array)) foreach($data_array as $k => $d) {
			if (!is_object($d)) { // this query
				foreach ($aql_array as $table => $info) {
					if ($info['fields'][$k]) {
						$field_name = substr($info['fields'][$k], strpos($info['fields'][$k], '.') + 1);
						if ($d) $tmp[$info['table']]['fields'][$field_name] = $d;
					} else if (substr($k, '-4') == '_ide') {
						$table_name = aql::get_decrypt_key($k);
						if ($info['table'] == $table_name && $d) {
							$tmp[$info['table']]['id'] = decrypt($d, $info['table']);
						}
					} else if (substr($k, '-3') == '_id') {
						$table_name = explode('__', substr($k, 0, -3));
						$table_name = ($table_name[1]) ? $table_name[1] : $table_name[0];
						if ($info['table'] == $table_name && $d) {
							$tmp[$info['table']]['id'] = $d;
						}
					}
				}
			} else if ($this->isObjectParam($k)) { // sub objects
				foreach ($d as $i => $j) {
					$tmp['objects'][] = array('object' => $k, 'data' => $j);
				}
			} else { // sub queries
				$d = $this->toArray($d);
				foreach ($aql_array as $table => $info) {
					if (is_array($info['subqueries'])) foreach($info['subqueries'] as $sub_k => $sub_v) {
						if ($k == $sub_k) {
							foreach ($d as $i => $s) {
								$tmp[$info['table']]['subs'][] = $this->makeSaveArray($s, $sub_v);
							}
							break;
						}
					}
				}
			}
		}
		// make sure that the array is in the correct order
		$fk = self::makeFKArray($aql_array);
		unset($aql_array); unset($data_array);
		return self::makeSaveArrayOrder($tmp, $fk);
	}

/**

	@function	makeSaveArrayOrder
	@return		(array) reordered by foreign keys
	@param		(array)	needs reordering
	@param		(array)	foreign keys

**/

	public function makeSaveArrayOrder($save_array, $fk) {
		$return_array = array();
		$first = array(); // prepends to return array
		foreach ($fk as $parent => $subs) {
			foreach ($subs as $dependent) {
				if ($save_array[$dependent]) {
					if (!array_key_exists($dependent, $fk)) {
						$return_array[$dependent] = $save_array[$dependent];
						unset($save_array[$dependent]);
					} else {
						$return_array = array($dependent => $save_array[$dependent]) + $return_array;
						unset($save_array[$dependent]);
					}
				}
			}
		}
		return $save_array + $return_array;
	}

/**
 	
 	@function	makeProperties
 	@return		(null)
 	@param		(null)

**/
	public function makeProperties() {
		if ($this->_aql) {
			$this->_aql_array = aql2array($this->_aql);
			foreach ($this->_aql_array as $table) {
				$this->tableMakeProperties($table);
			}
		} else {
			die('this is not a valid model.');
		}
	} // end makeParms

/**

	@function 	reload
	@return		(null)
	@param		(array)

**/

	public function reload($save_array) {
		$f = reset($this->_aql_array);
		$first = $f['table'];
		$id = $save_array[$first]['id'];
		if ($id) $this->loadDB($id);
	}

/** 
	
	@function	save
	@return		(bool)
	@param		(null)

	has hooks each takes the save_array as param
		before_save
		after_save
		after_fail

**/

	public function save() {
		global $dbw; $db_platform; $aql_error_email;
		$this->validate();
		if (empty($this->_errors)) {
			if (!$this->_aql_array) $this->_errors[] = 'Cannot save model without an aql statement.';
			if (empty($this->_errors)) {
				$save_array = $this->makeSaveArray($this->_data, $this->_aql_array);
				if (!$save_array) $this->_errors[] = 'Error generating save array based on the model. There may be no data set.';
				if (empty($this->_errors)) {
					$dbw->StartTrans();
					if (method_exists($this, 'before_save')) $this->before_save($save_array);
					$save_array = $this->saveArray($save_array);
					$transaction_failed = $dbw->HasFailedTrans();
					$dbw->CompleteTrans();
					if ($transaction_failed) {
						$this->_errors[]= 'Save Failed.';
						if (method_exists($this, 'after_fail')) $this->after_fail($save_array);
						return false;
					} else {
						if (method_exists($this, 'before_reload')) $this->before_reload();
						$this->reload($save_array);
						if (method_exists($this, 'after_save')) $this->after_save($save_array);
						return true;
					}
				}
			} 
		} 
		if (!empty($this->_errors)) {
			if (method_exists($this, 'after_fail')) $this->after_fail();
			return false;
		} 
	}

/**

	@function	saveArray (recursive)
	@return		(array)
	@param		(array)
	@param		(array)

**/

	public function saveArray($save_array, $ids = array()) {
		$objects = $save_array['objects'];
		unset($save_array['objects']);
		foreach ($save_array as $table => $info) {
			//print_a($ids);
			foreach ($ids as $n => $v) {
				//print_pre($n);
				if (is_array($info['fields']) && !$info['fields'][$n]) {
					$save_array[$table]['fields'][$n] = $v;
					$info['fields'][$n] = $v;
				}
			//	else print_pre($n.' not put into '.$table);
			}
			if (is_numeric($info['id'])) {
				if (is_array($info['fields'])) {
				//	echo 'update'; print_a($info['fields']);
					aql::update($table, $info['fields'], $info['id'], true);
				}
			} else {
				if (is_array($info['fields'])) {
				//	echo 'insert'; print_a($info['fields']);
					$rs = aql::insert($table, $info['fields'], true);
					$save_array[$table]['id'] = $info['id'] = $rs[0][$table.'_id'];
				}
			}
			$ids[$table.'_id'] = $info['id'];
			if (is_array($info['subs'])) foreach ($info['subs'] as $i=>$sub) {
				$save_array[$table]['subs'][$i] = $this->saveArray($sub, $ids);
			}
		}
		if (is_array($objects)) foreach ($objects as $o) {
			aql::include_class_by_name($o['object']);
			$tmp = $o['object'];
			$tmp = new $tmp;
			$tmp->loadArray($o['data']);
			$tmp->loadIDs($ids);
			$tmp->save();
		}
		$save_array['objects'] = $objects;
		return $save_array;
	}

/**
 
 	@function	tableMakeProperties
 	@return		(null)
 	@param		(array)
 	@param		(bool)

**/
	public function tableMakeProperties($table, $sub = null) {
		if (is_array($table['objects'])) foreach ($table['objects'] as $k => $v) {
			$this->_data[$k] =  new ArrayObject;
			$this->_properties[] = $k;
			$this->_objects[] = $k;
		}
		if (is_array($table['fields'])) foreach ($table['fields'] as $k => $v) {
			$type = ($sub) ? array() : '';
			if (preg_match('/[\b_]id$/', $k)) {
				$this->_data[$k.'e'] = $type;
				$this->_properties[] = $k.'e';
			}
			$this->_data[$k] = $type;
			$this->_properties[] = $k;
		}
		if (is_array($table['subqueries'])) foreach($table['subqueries'] as $k => $v) {
			$this->_data[$k] = new ArrayObject;
			$this->_properties[] = $k;
		}
	}

/**

	@function	toArray
	@return		(array)
	@param		(arrayObject)

**/

	public function toArray($obj) {
		if (get_class($obj) == 'ArrayObject') 
			$obj = $obj->getArrayCopy();

		if (is_array($obj)) foreach ($obj as $k => $v) {
			$obj[$k] = self::toArray($v);
		}
		return $obj;
	}

/**

	@function 	toArrayObject
	@return		(arrayObject)
	@param		(array)

**/

	public function toArrayObject($arr = array()) {
		$arr = new ArrayObject($arr);
		foreach ($arr as $k => $v) {
			if (is_array($v)) $arr[$k] = self::toArrayObject($v);
		}
		return $arr;
	}

/**
	
	@function	validate
	@return		(null)
	@param		(null)

**/

	public function validate() {
		foreach ($this->_properties as $prop) {
			if (method_exists($this, 'set_'.$prop)) {
				$this->{'set_'.$prop}($this->{$prop});
			}
		}
	}

/**

	@function	printData
	@return		(null)
	@param		(null)

**/

	public function printData() {
		print_pre($this->_data);
	}

/**
 
 	@function	printErrors
 	@return		(null)
 	@param		(null)

**/

	public function printErrors() {
		print_pre($this->_errors);
	}

/**

	@function	propertyExists
	@return		(bool)
	@param		(string)

**/

	public function propertyExists($p) {
		if (in_array($p, $this->_properties)) return true;
		else return false;
	}

/**

	@function 	returnJSON
	@return		(string) 
	@param		(array)

	encodes the array and dies the content with JSON headers

**/

	public static function returnJSON($arr = array()) {
		header('Cache-Control: no-cache, must-revalidate');
		header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
		header('Content-type: application/json');
		exit(json_encode($arr));
	}

/**
	
	@function	returnDataArray
	@return		(array)
	@param		(null)

**/
	
	public function returnDataArray() {
		return $this->_data;
	}

/**
	
	@function 	requiredField
	@return		(bool)
	@param		(string)
	@param		(string)

**/

	public function requiredField($name, $val) {
		if (!$val) {
			$this->_errors[] = "{$name} is required.";
			return false;
		} else {
			return true;
		}
	}
}