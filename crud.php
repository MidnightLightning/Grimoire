<?php
// Create/Read/Edit/Delete (CRUD) Ajax handler'
$pk_var = 'id'; // Which request variable is the primary key being acted upon?

$verb = $_SERVER['REQUEST_METHOD'];
switch($verb) {
	case 'POST':
		if (isset($_POST[$pk_var])) {
			do_update();
		} else {
			do_create();
		}
	case 'GET':
		do_read();
	case 'DELETE':
		do_delete();
	default:
		fail_out(400, 'Action not recognized');
}

function db() {
	$db = new PDO('sqlite:data.sqlite3');
	$db->exec('CREATE TABLE IF NOT EXISTS "data" ("grimoire" TEXT NOT NULL, "name" TEXT, "reference" INTEGER)');
	return $db;
}

function do_read() {
	global $pk_var;
	if (!isset($_GET[$pk_var])) { fail_out(400, 'No ID specified'); }
	$db = db(); // Get PDO object
	$stmt = $db->prepare('SELECT * FROM "data" WHERE ROWID=?');
	$stmt->execute(array($_GET[$pk_var]));
	$data = $stmt->fetch(PDO::FETCH_OBJ);
	$out = new responseObject();
	$out->data = $data;
	echo json_encode($out);
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
