<?php
require_once('../lib/crud.php');
require_once('../lib/db.php');

class grim_obj extends crud {
	/**
	 * Overload the create method to create an ID first
	 */
	protected function _doCreate() {
		if (!$this->_createID()) $this->fail_out(self::ERR_INTERNAL_ERROR, "Failed to create new ID");
		parent::_doCreate();
	}
	
	private function _createID() {
		$allowed = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjikmnopqrstuvwxyz23456789';
		$db = $this->db; // Get PDO object

		// Generate a new key; no need to check it for duplication
		$new_key = '';
		while (strlen($new_id) < 16) { $new_id += $allowed[rand(0,strlen($allowed))]; }

		// Generate a new slug ID
		$stmt = $db->prepare('SELECT FROM '.$this->table_name.' WHERE `public_key`=:key LIMIT 1'); // We'll be using this a few times; prepare it once
		$iterations = 0;
		do {
			$new_id = '';
			while (strlen($new_id) < 8) { $new_id += $allowed[rand(0,strlen($allowed))]; }
			$stmt->bindValue(':key', $new_id);
			$stmt->execute();
			$iterations++;
			if ($iterations > 1000) return false; // We're out of IDs?!?!
		} while ($stmt->rowCount() > 0);
		
		$_POST['public_key'] = $new_id;
		$_POST['admin_key'] = $new_key;
		return true;
	}
}

$obj = false;
if (isset($_SERVER['PATH_INFO']) && $_SERVER['PATH_INFO'] != '') {
	$fragments = explode('/', $_SERVER['PATH_INFO']);
	if (count($fragments) > 0 && $fragments[1] != '') {
		$_GET['id'] = strtolower($fragments[1]);
	}
}

$db_grim = new grim_obj($db, 'id', 'id', 'grimoire');
$rs = $db_grim->handle_request(); // get CrudResponse
header("HTTP/1.0 ".$rs->err_no);
echo json_encode($rs);
exit;