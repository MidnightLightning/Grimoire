<?php
require_once('../lib/crud.php');
require_once('../lib/db.php');

class grim_obj extends crud {
	/**
	 * Overload the create method to create an ID first
	 */
	function handle_request() {
		switch ($_SERVER['REQUEST_METHOD']) { // The HTTP method that was used to fetch the page
			case 'POST':
				if (isset($_POST[$this->pk_var])) {
					return $this->_doUpdate();
				} else {
					if (!$this->_createID()) $this->fail_out(self::ERR_INTERNAL_ERROR, "Failed to create new ID"); // Sets new post variables
					$out = $this->_doCreate();
					$out->public_key = $_POST['public_key'];
					$out->admin_key = $_POST['admin_key'];
					return $out;
				}
			case 'GET':
				// Split into public and private, if too long
				if (strlen($_GET[$this->pk_var]) > 8) {
					$_GET[$this->pk_var] = substr($_GET[$this->pk_var], 0, 8);
				}
				$out = $this->_doRead();
				unset($out->data->admin_key); // Don't return this; the owner will already know it
				
				// Get all the rows for this grimoire
				$stmt = $this->db->prepare('SELECT `data` FROM `row` WHERE `gid`=:gid ORDER BY `order`');
				$stmt->bindValue(':gid', $_GET[$this->pk_var]);
				$stmt->execute();
				$out->data->rows = array();
				while ($data = $stmt->fetchColumn(0)) {
					print_r($data);
					$out->data->rows[] = json_decode($data);
				}
				return $out;
			case 'DELETE':
				return $this->_doDelete();
			default:
				$this->fail_out(self::ERR_BAD_REQUEST, 'Action not recognized');
		}
	}
	
	private function _createID() {
		$allowed = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjikmnopqrstuvwxyz23456789';
		$db = $this->db; // Get PDO object

		// Generate a new key; no need to check it for duplication
		$new_key = '';
		
		while (strlen($new_key) < 16) { $new_key .= $allowed[rand(0, strlen($allowed)-1)]; }

		// Generate a new slug ID
		$stmt = $db->prepare('SELECT FROM '.$this->table.' WHERE `public_key`=:key LIMIT 1'); // We'll be using this a few times; prepare it once
		$iterations = 0;
		do {
			$new_id = '';
			while (strlen($new_id) < 8) { $new_id .= $allowed[rand(0,strlen($allowed)-1)]; }
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

if (isset($_SERVER['PATH_INFO']) && $_SERVER['PATH_INFO'] != '') {
	$fragments = explode('/', $_SERVER['PATH_INFO']);
	if (count($fragments) > 0 && $fragments[1] != '') {
		$_GET['id'] = strtolower($fragments[1]);
	}
}

$db_grim = new grim_obj($db, 'id', 'public_key', 'grimoire');
$rs = $db_grim->handle_request(); // get CrudResponse
header("HTTP/1.0 ".$rs->err_no);
echo json_encode($rs);
exit;