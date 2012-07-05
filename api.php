<?php

require_once('vendor/autoload.php'); // Composer dependencies
require_once('lib/db.php'); // PDO DB object

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$api = new Silex\Application();
$api['debug'] = true;
$api['db'] = $db; // Database for 

$api->before(function (Request $req) {
	if ($req->headers->get('Content-Type') == 'application/json') {
		$data = json_decode($req->getContent(), true); // Parse body as JSON into an array
		$req->request->replace(is_array($data)? $data : array());
	}
});

// API root
$api->get('/api/', function() {
	return '<h1>Hello World</h1>';
});


abstract class CRUD implements Silex\ControllerProviderInterface {
	const ERR_OK = 200;
	const ERR_CREATED = 201;
	const ERR_NO_CONTENT = 204;
	const ERR_SEE_OTHER = 303;
	const ERR_BAD_REQUEST = 400;
	const ERR_UNAUTHORIZED = 403;
	const ERR_NOT_FOUND = 404;
	const ERR_INTERNAL_ERROR = 500;
	
	public function connect(Silex\Application $app) {
		$this->app = $app; // Save for method use later
		
		$c = $app['controllers_factory']; // New ControllerCollection
		$c->post('/', array($this, 'create'));
		$c->get('/{id}', array($this, 'read')); 
		$c->put('/{id}', array($this, 'update')); 
		$c->delete('/{id}', array($this, 'delete')); 
		
		return $c;
	}
	
	protected function error_out($msg, $err_no = self::ERR_INTERNAL_ERROR) {
		$out = new CrudResponse();
		$out->error = $msg;
		$out->err_no = $err_no;
		return $this->app->json($out, $err_no);
	}
	
	abstract public function create(Request $req);
	abstract public function read($id);
	abstract public function update($id, Request $req);
	abstract public function delete($id);
}

class CrudResponse {
	public $err_no = CRUD::ERR_OK;
	public $error = false;
	public $data = false;
}

class Grimoires extends CRUD {
	public $table = 'grimoire';
	
	function create(Request $req) {
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object

		$new_id = $this->_createID();
		if ($new_id === false) return $this->error_out('Failed to create new ID', self::ERR_INTERNAL_ERROR);
		
		$stmt = $db->prepare('INSERT INTO `'.$this->table.'` (`name`, `public_key`, `admin_key`) VALUES (:name, :public, :admin)');
		$stmt->bindValue(':name', $req->request->get('name'));
		$stmt->bindValue(':public', $new_id[0]);
		$stmt->bindValue(':admin', $new_id[1]);
		$stmt->execute();
		if ($stmt->rowCount() < 1) return $this->error_out('SQL Failed insert: '.var_export($stmt->errorInfo(), true), self::ERR_INTERNAL_ERROR);
		
		$out = new CrudResponse();
		$out->id = $db->lastInsertId();
		$out->err_no = self::ERR_CREATED;
		$out->public_key = $new_id[0];
		$out->admin_key = $new_id[1];		
		return $app->json($out, self::ERR_CREATED);
	}

	function read($id) {
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		if (strlen($id) > 8) $id = substr($id, 0, 8); // Trim off Admin key, if any

		$out = new CrudResponse();
		
		$stmt = $db->prepare('SELECT * FROM `'.$this->table.'` WHERE `public_key`=? LIMIT 1');
		$stmt->execute(array($id));
		$data = $stmt->fetch(PDO::FETCH_OBJ);
		if (!$data) return $this->error_out('No such record as '.$id, self::ERR_NOT_FOUND);
		$out->data = $data;
		
		// Get all of the rows for this grimoire
		$stmt = $db->prepare('SELECT `id`, `data` FROM `row` WHERE `gid`=:gid ORDER BY `order`');
		$stmt->bindValue(':gid', $id);
		$stmt->execute();
		$out->data->rows = array();
		while ($row = $stmt->fetch()) {
			$out->data->rows[$row['id']] = json_decode($row['data']); // Data is JSON-serialized in database. De-serialize it, since it will get re-serialized as part of the output
		}

		return $app->json($out, self::ERR_OK);
	}

	function update($id, Request $req) {
		if (!$this->is_authorized($id)) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED);
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		$id = substr($id, 0, 8); // Trim off Admin key
		
		$stmt = $db->prepare('UPDATE `'.$this->table.'` SET `name`=:name WHERE `public_key`=:gid');
		$stmt->bindValue(':name', $req->request->get('name'));
		$stmt->bindValue(':gid', $id);
		$stmt->execute();
		if ($stmt->rowCount() < 1) return $this->error_out('SQL Failed update: '.var_export($stmt->errorInfo(), true), self::ERR_INTERNAL_ERROR);
		
		$out = new CrudResponse();
		$out->id = $id;
		return $app->json($out, self::ERR_OK);
	}

	function delete($id) {
		if (!$this->is_authorized($id)) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED);
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		$id = substr($id, 0, 8); // Trim off Admin key

		$stmt = $db->prepare('DELETE FROM `'.$this->table.'` WHERE `public_key`=?');
		$stmt->execute(admin($id));
		if ($stmt->rowCount() < 1) return $this->error_out('SQL Failed delete: '.var_export($stmt->errorInfo(), true), self::ERR_INTERNAL_ERROR);
		
		$out = new CrudResponse();
		$out->id = $id;
		return $app->json($out, self::ERR_OK);
	}
	
	private function _is_authorized($id) {
		if (strlen($id) < 24) return false; // Not a valid ID
		$public = substr($id, 0, 8);
		$admin = substr($id, 8, 16);

		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		
		$stmt = $db->prepare('SELECT `public_id` FROM `'.$this->table.'` WHERE `public_key`=:pid AND `admin_key`=:aid');
		$stmt->bindValue(':pid', $public);
		$stmt->bindValue(':aid', $admin);
		$stmt->execute();
		if ($stmt->rowCount() < 1) return false; // Bad public/private combo
		
		return true;
	}

	private function _createID() {
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object

		$allowed = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjikmnopqrstuvwxyz23456789';

		// Generate a new private key; no need to check it for duplication
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
		
		return array($new_id, $new_key);
	}

}
$api->mount('/api/grimoire', new Grimoires());

class Rows extends CRUD {
	public $table = 'row';
	
	function create(Request $req) {
		// Validate
		if (!$req->request->has('gid') || $req->request->get('gid') == '') return $this->error_out('No Grimoire ID given', self::ERR_BAD_REQUEST);
		if (!$req->request->has('order') || $req->request->get('order') == '') return $this->error_out('No Row ID given', self::ERR_BAD_REQUEST);
		
		// Authorize
		$gid = $req->request->get('gid');
		if (!$this->is_authorized($gid)) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED);

		// Execute
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		$gid = substr($gid, 0, 8); // Trim off Admin key
		
		$stmt = $db->prepare('INSERT INTO `'.$this->table.'` (`gid`, `order`, `data`) VALUES (:gid, :order, :data)');
		$stmt->bindValue(':gid', $gid);
		$stmt->bindValue(':order', $req->request->get('order'));
		$stmt->bindValue(':data', $req->request->get('data'));
		$stmt->execute();
		if ($stmt->rowCount() < 1) return $this->error_out('SQL Failed insert: '.var_export($stmt->errorInfo(), true), self::ERR_INTERNAL_ERROR);
		
		$out = new CrudResponse();
		$out->id = $db->lastInsertId();
		$out->err_no = self::ERR_CREATED;

		return $app->json($out, self::ERR_CREATED);
	}

	function read($id) {
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		
		$stmt = $db->prepare('SELECT * FROM `'.$this->table.'` WHERE `id`=? LIMIT 1');
		$stmt->execute(array($id));
		if ($stmt->rowCount() < 1) return $this->error_out('No such row', self::ERR_NOT_FOUND);
		
		$out = new CrudResponse();
		$out->id = $id;
		$out->data = $stmt->fetch(PDO::FETCH_OBJ);

		return $app->json($out, self::ERR_OK);
	}

	function update($id, Request $req) {
		// Validate
		if (!$req->request->has('gid') || $req->request->get('gid') == '') return $this->error_out('No Grimoire ID given', self::ERR_BAD_REQUEST);
		if (!$req->request->has('order') || $req->request->get('order') == '') return $this->error_out('No Row ID given', self::ERR_BAD_REQUEST);
		
		// Authorize
		$gid = $req->request->get('gid');
		if (!$this->is_authorized($gid)) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED);
		
		$gid = substr($gid, 0, 8); // Trim off Admin key
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		
		$stmt = $db->prepare('SELECT `id` FROM `'.$this->table.'` WHERE `gid`=:gid AND `id`=:id');
		$stmt->bindValue(':gid', $gid);
		$stmt->bindValue(':id', $id);
		$stmt->execute();
		if ($stmt->rowCount() < 1) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED); // That row does not belong to that Grimoire
		

		// Execute
		$stmt = $db->prepare('UPDATE `'.$this->table.'` `order`=:order, `data`=:data WHERE `id`=:id LIMIT 1');
		$stmt->bindValue(':order', $req->request->get('order'));
		$stmt->bindValue(':data', $req->request->get('data'));
		$stmt->bindValue(':id', $id);
		$stmt->execute();
		if ($stmt->rowCount() < 1) return $this->error_out('SQL Failed udpate: '.var_export($stmt->errorInfo(), true), self::ERR_INTERNAL_ERROR);
		
		$out = new CrudResponse();
		$out->id = $id;

		return $app->json($out, self::ERR_OK);
	}

	function delete($id) {
		// Authorize
		$gid = $req->request->get('gid');
		if (!$this->is_authorized($gid)) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED);
		
		$gid = substr($gid, 0, 8); // Trim off Admin key
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object

		$stmt = $db->prepare('SELECT `id` FROM `'.$this->table.'` WHERE `gid`=:gid AND `id`=:id');
		$stmt->bindValue(':gid', $gid);
		$stmt->bindValue(':id', $id);
		$stmt->execute();
		if ($stmt->rowCount() < 1) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED); // That row does not belong to that Grimoire
		

		// Execute
		$stmt = $db->prepare('DELETE FROM `'.$this->table.'` WHERE `id`=?');
		$stmt->execute(array($id));
		if ($stmt->rowCount() < 1) return $this->error_out('SQL Failed delete: '.var_export($stmt->errorInfo(), true), self::ERR_INTERNAL_ERROR);
		
		$out = new CrudResponse();
		$out->id = $id;

		return $app->json($out, self::ERR_OK);
	}
	
	private function _is_authorized($id) {
		if (strlen($id) < 24) return false; // Not a valid ID
		$public = substr($id, 0, 8);
		$admin = substr($id, 8, 16);

		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		
		$stmt = $db->prepare('SELECT `public_id` FROM `grimoire` WHERE `public_key`=:pid AND `admin_key`=:aid');
		$stmt->bindValue(':pid', $public);
		$stmt->bindValue(':aid', $admin);
		$stmt->execute();
		if ($stmt->rowCount() < 1) return false; // Bad public/private combo
		
		return true;
	}
}
$api->mount('/api/row', new Rows());


$api->run();