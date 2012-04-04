<?php
// Create/Read/Edit/Delete (CRUD) Ajax handler'
$pk_var = 'id'; // Which request variable is the primary key being acted upon?
$table_name = 'data'; // Name of database table to act upon?

$verb = $_SERVER['REQUEST_METHOD'];
switch($verb) {
	case 'POST':
		if (isset($_POST[$pk_var])) {
			do_update($pk_var, $table_name);
		} else {
			do_create($table_name);
		}
	case 'GET':
		do_read($pk_var, $table_name);
	case 'DELETE':
		do_delete($pk_var, $table_name);
	default:
		fail_out(400, 'Action not recognized');
}

function db() {
	$db = new PDO('sqlite:data.sqlite3');
	$db->exec('CREATE TABLE IF NOT EXISTS "data" ("grimoire" TEXT NOT NULL, "name" TEXT, "reference" INTEGER)');
	return $db;
}

function do_read($pk_var, $table_name) {
	if (!isset($_GET[$pk_var])) { fail_out(400, 'No ID specified'); }
	$db = db(); // Get PDO object
	$stmt = $db->prepare('SELECT * FROM "'.$table_name.'" WHERE ROWID=?');
	$stmt->execute(array($_GET[$pk_var]));
	$data = $stmt->fetch(PDO::FETCH_OBJ);
	$out = new responseObject();
	$out->data = $data;
	echo json_encode($out);
	exit;
}

function do_create($table_name) {
	// Every value in the $_POST array is a column in the database; insert that data into a row
	$keys = array_keys($_POST);
	$sql = 'INSERT INTO "'.$table_name.'" (';
	foreach($keys as $key) {
		$sql .= '"'.$key.'", ';
	}
	$sql = substr($sql, 0,-2).') VALUES (';
	foreach($keys as $key) {
		$sql .= '?, ';
	}
	$sql = substr($sql,0,-2).')';
	
	$db = db(); // Get PDO object
	$stmt = $db->prepare($sql);
	if (!$stmt) { fail_out(500, 'SQL error: '.$sql.': '.$db->errorInfo()); }
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
	if ($stmt->rowCount() < 1) { fail_out(500, 'SQL Failed insert: '.var_dump($db->errorInfo(), true)); }
	$out = new responseObject();
	$out->id = $db->lastInsertId();
	echo json_encode($out);
	sleep(3);
	exit;
}

function fail_out($err_no, $message) {
	header("HTTP/1.0 ".$err_no);
	$out = new responseObject();
	$out->error = $message;
	$out->err_no = intval($err_no);
	echo json_encode($out);
	exit;
}

class responseObject {
	public $err_no = 200;
	public $error = "";
}
