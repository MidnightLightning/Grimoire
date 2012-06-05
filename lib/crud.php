<?php
/**
 * Basic CRUD Ajax handler
 *
 * Create/Read/Edit/Delete from Ajax easily.
 * Each instance of this class acts upon exactly one database table; if you need more than that, use a more complete DB class
 */

class crud {
	const ERR_OK = 200;
	const ERR_CREATED = 201;
	const ERR_NO_CONTENT = 204;
	const ERR_SEE_OTHER = 303;
	const ERR_BAD_REQUEST = 400;
	const ERR_NOT_FOUND = 404;
	const ERR_INTERNAL_ERROR = 500;
	
	/**
	 * Create object
	 *
	 * @param PDO $db database handler (DI)
	 * @param string $pk_var Name of the primary key request variable (defaults to 'id')
	 * @param string $pk_field Name of the database table field that is the primary key (defaults to 'ROWID')
	 * @param string $table_name Name of the table we'll be acting upon (defaults to 'data')
	 */
	function __construct(PDO $db, $pk_var = 'id', $pk_field = 'ROWID', $table_name = 'data') {
		$this->db = $db;
		$this->pk_var = $pk_var;
		$this->pk_field = $pk_field;
		$this->table = $table_name;
		
		$this->db_init();
	}
	
	/**
	 * Start up database
	 *
	 * The database PDO object is already dependency-injected;
	 * if there's more setup needed, extend this function in child classes
	 */
	function db_init() {
		return true;
	}
	
	/**
	 * Main request-handling function
	 *
	 * Uses the request method to call the appropriate CRUD method
	 * @uses _doCreate()
	 * @uses _doRead()
	 * @uses _doUpdate()
	 * @uses _doDelete()
	 */
	function handle_request() {
		switch ($_SERVER['REQUEST_METHOD']) { // The HTTP method that was used to fetch the page
			case 'POST':
				if (isset($_POST[$this->pk_var])) {
					return $this->_doUpdate();
				} else {
					return $this->_doCreate();
				}
			case 'GET':
				return $this->_doRead();
			case 'DELETE':
				return $this->_doDelete();
			default:
				$this->fail_out(self::ERR_BAD_REQUEST, 'Action not recognized');
		}
	}
	
	protected function _doCreate() {
		// Every value in the $_POST array is a column in the database; insert that data into a row
		$keys = array_keys($_POST);
		$sql = 'INSERT INTO `'.$this->table.'` (';
		foreach($keys as $key) {
			$sql .= '`'.$key.'`, ';
		}
		$sql = substr($sql, 0,-2).') VALUES (';
		foreach($keys as $key) {
			$sql .= '?, ';
		}
		$sql = substr($sql,0,-2).')';

		$db = $this->db; // Get PDO object
		$stmt = $db->prepare($sql);
		if (!$stmt) { $this->fail_out(self::ERR_INTERNAL_ERROR, 'SQL error: '.$sql.': '.$db->errorInfo()); }
		foreach($keys as $i => $key) {
			if (!is_numeric($_POST[$key]) && $_POST[$key] != 'NULL') {
				$stmt->bindValue($i+1, $_POST[$key], PDO::PARAM_STR);
			} elseif ($_POST[$key] == 'NULL') {
				$stmt->bindValue($i+1, $_POST[$key], PDO::PARAM_NULL);
			} else {
				$stmt->bindValue($i+1, $_POST[$key]);
			}
		}
		$stmt->execute();
		if ($stmt->rowCount() < 1) { $this->fail_out(self::ERR_INTERNAL_ERROR, 'SQL Failed insert: '.var_dump($db->errorInfo(), true)); }
		$out = new CrudResponse();
		$out->id = $db->lastInsertId();
		$out->error = self::ERR_CREATED;
		return($out);
	}
	
	protected function _doRead() {
		if (!isset($_GET[$this->pk_var])) { $this->fail_out(self::ERR_BAD_REQUEST, 'No ID specified'); }
		$db = $this->db; // Get PDO object
		$stmt = $db->prepare('SELECT * FROM `'.$this->table.'` WHERE `'.$this->pk_field.'`=?');
		$stmt->execute(array($_GET[$this->pk_var]));
		$data = $stmt->fetch(PDO::FETCH_OBJ);
		if (!$data) { $this->fail_out(self::ERR_NOT_FOUND, "No such record"); }
		$out = new CrudResponse();
		$out->error = self::ERR_OK;
		$out->data = $data;
		return($out);
	}
	
	protected function _doUpdate() {
		// Every value in the $_POST array except the primary key is a column in the database; update that data
		$keys = array_keys($_POST);
		$sql = 'UPDATE "'.$this->table.'" SET ';
		$sql = substr($sql, 0,-2).') VALUES (';
		foreach($keys as $key) {
			if ($key == $this->pk_var) continue; // Skip PK var
			$sql .= '"'.$key.'"=?, ';
		}
		$sql = substr($sql,0,-2);
		$sql .= ' WHERE '.$this->pk_field.'=?';

		$db = $this->db; // Get PDO object
		$stmt = $db->prepare($sql);
		if (!$stmt) { $this->fail_out(self::ERR_INTERNAL_ERROR, 'SQL error: '.$sql.': '.$db->errorInfo()); }
		foreach($keys as $i => $key) {
			if ($key == $this->pk_var) continue; // Skip PK var
			if (!is_numeric($_POST[$key]) && $_POST[$key] != 'NULL') {
				$stmt->bindValue($i+1, $_POST[$key], PDO::PARAM_STR);
			} elseif ($_POST[$key] == 'NULL') {
				$stmt->bindValue($i+1, $_POST[$key], PDO::PARAM_NULL);
			} else {
				$stmt->bindValue($i+1, $_POST[$key]);
			}
		}
		$stmt->bindValue($i+2, $_POST[$this->pk_var]); // Bind PK
		$rs = $stmt->execute();
		if (!$rs) { $this->fail_out(self::ERR_INTERNAL_ERROR, 'SQL update failed: '.var_dump($db->errorInfo(), true)); }
		$out = new CrudResponse();
		$out->error = self::ERR_OK;
		return($out);
	}
	
	protected function _doDelete() {
		if (!isset($_GET[$this->pk_var])) { $this->fail_out(self::ERR_BAD_REQUEST, 'No ID specified'); }
		$db = $this->db; // Get PDO object
		$stmt = $db->prepare('DELETE FROM "'.$this->table.'" WHERE '.$this->pk_field.'=?');
		$stmt->execute(array($_GET[$this->pk_var]));
		$rs = $stmt->execute();
		if (!$rs) { $this->fail_out(self::ERR_INTERNAL_ERROR, 'SQL delete failed: '.var_dump($db->errorInfo(), true)); }
		$out = new CrudResponse();
		$out->error = self::ERR_OK;
		return($out);
	}
	
	function fail_out($err_no, $message) {
		$err_no = intval($err_no);
		header("HTTP/1.0 ".$err_no);
		$out = new CrudResponse();
		$out->error = $message;
		$out->err_no = $err_no;
		echo json_encode($out);
		exit;
	}
	
}

class CrudResponse {
	public $err_no = 200;
	public $error = false;
	public $data = false;
}