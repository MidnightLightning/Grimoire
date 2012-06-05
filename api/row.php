<?php
require_once('../lib/crud.php');
require_once('../lib/db.php');

class row_obj extends crud {
	/**
	 * Overload the create method to get ID by Grimoire/order fields
	 */
	function handle_request() {
		switch ($_SERVER['REQUEST_METHOD']) { // The HTTP method that was used to fetch the page
			case 'POST':
				// Rows will be identified by Grimoire ID and order, not by primary key
				if (!isset($_POST['gid']) || empty($_POST['gid'])) $this->fail_out(self::ERR_BAD_REQUEST, "No Grimoire ID given");
				if (!isset($_POST['order']) || $_POST['order'] == '') $this->fail_out(self::ERR_BAD_REQUEST, "No Row ID given");
				if (strlen($_POST['gid']) != 24) $this->fail_out(self::ERR_BAD_REQUEST, "Not a valid Grimoire ID given");
				$public = substr($_POST['gid'], 0, 8);
				$admin = substr($_POST['gid'], 8);
				
				// Check for proper code
				$stmt = $this->db->prepare('SELECT `public_key` FROM `grimoire` WHERE `public_key`=:public AND `admin_key`=:admin');
				$stmt->bindValue(':public', $public);
				$stmt->bindValue(':admin', $admin);
				$stmt->execute();
				if ($stmt->rowCount() == 0) $this->fail_out(self::ERR_BAD_REQUEST, "Not a valid Grimoire ID");

				$stmt = $this->db->prepare('SELECT `id` FROM '.$this->table.' WHERE `gid`=:gid AND `order`=:order LIMIT 1');
				$stmt->bindValue(':gid', $public);
				$stmt->bindValue(':order', intval($_POST['order']));
				$stmt->execute();
				if ($stmt->rowCount() == 0) {
					// This row does not exist yet
					$_POST['data'] = stripslashes($_POST['data']); // Why?
					return $this->_doCreate();
				} else {
					// This row already exists
					$_POST[$this->pk_var] = $stmt->fetchColumn(0);
					return $this->_doUpdate();
				}
			case 'GET':
				return $this->_doRead();
			case 'DELETE':
				return $this->_doDelete();
			default:
				$this->fail_out(self::ERR_BAD_REQUEST, 'Action not recognized');
		}
	}
}

if (isset($_SERVER['PATH_INFO']) && $_SERVER['PATH_INFO'] != '') {
	$fragments = explode('/', $_SERVER['PATH_INFO']);
	if (count($fragments) > 1 && $fragments[1] != '') {
		$_GET['gid'] = strtolower($fragments[1]);
	}
	if (count($fragments) > 2 && $fragments[2] != '') {
		$_GET['order'] = strtolower($fragments[2]);
	}
}

$db_row = new row_obj($db, 'id', 'id', 'row');
$rs = $db_row->handle_request(); // get CrudResponse
header("HTTP/1.0 ".$rs->err_no);
echo json_encode($rs);
exit;