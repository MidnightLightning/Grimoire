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
	$out = '<h1>Grimoire REST API access</h1>';
	$out .= '<p>As a REST API, the standard CRUD (Create, Read, Update, Delete) actions are determined by request method upon the locations below.</p>';
	$out .= '<ul><li>Create &rArr; POST</li>';
	$out .= '<li>Read &rArr; GET</li>';
	$out .= '<li>Update &rArr; PUT</li>';
	$out .= '<li>Delete &rArr; DELETE</li>';
	$out .= '</ul>';
	$out .= '<h2>Locations:</h2>';
	$out .= '<ul><li><tt>auth/{id}</tt>: Verify if <tt>{id}</tt> is a valid administrative (write-access) ID</li>';
	$out .= '<li><tt>grimoire/{id}</tt>: Act upon grimoire with an ID of <tt>{id}</tt></li>';
	$out .= '<li><tt>row/{id}</tt>: Act up on grimoire row <tt>{id}</tt></li>';
	$out .= '<li><tt>spells/{filter}</tt>: Get a list of SRD spells (used for auto-complete)</li>';
	$out .= '</ul>';
	return $out;
});

$api->get('/api/auth/{id}', function($id) use ($api) {
	$out = new CrudResponse();

	if (strlen($id) < 24) {
		$out->err_no = CRUD::ERR_UNAUTHORIZED;
		$out->err = 'Not Authorized';
		return $api->json($out, 200);
	}

	$public = substr($id, 0, 8);
	$admin = substr($id, 8, 16);
	$db = $api['db']; // PDO object
	
	$stmt = $db->prepare('SELECT `public_key` FROM `grimoire` WHERE `public_key`=:pid AND `admin_key`=:aid');
	$stmt->bindValue(':pid', $public);
	$stmt->bindValue(':aid', $admin);
	$stmt->execute();
	if ($stmt->rowCount() < 1) {
		// Bad public/private combo
		$out->err_no = CRUD::ERR_UNAUTHORIZED;
		$out->err = 'Not Authorized';
		return $api->json($out, 200);
	}
	
	$out->err = 'Authorized';
	return $api->json($out, CRUD::ERR_OK, array('GRIMOIRE-WRITE-ACCESS' => 'true'));
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
	abstract public function delete($id, Request $req);
}

class CrudResponse {
	public $err_no = CRUD::ERR_OK;
	public $error = false;
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
		
		$out = new stdClass;
		$out->public_key = $new_id[0];
		$out->admin_key = $new_id[1];		
		return $app->json($out, self::ERR_CREATED, array('GRIMOIRE-WRITE-ACCESS' => 'true'));
	}

	function read($id) {
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		$authorized = $this->_is_authorized($id); // The full key is not needed, but was it provided?
		if (strlen($id) > 8) $id = substr($id, 0, 8); // Trim off Admin key, if any
		updateGrimoireDate($db, $id);

		$out = new CrudResponse();
		
		$stmt = $db->prepare('SELECT * FROM `'.$this->table.'` WHERE `public_key`=? LIMIT 1');
		$stmt->execute(array($id));
		$data = $stmt->fetch(PDO::FETCH_OBJ);
		if (!$data) return $this->error_out('No such record as '.$id, self::ERR_NOT_FOUND);
		if (!$authorized) unset($data->admin_key); // Don't just send the key to people!
		
		// Get all of the rows for this grimoire
		$stmt = $db->prepare('SELECT `id`, `data` FROM `row` WHERE `gid`=:gid ORDER BY `order`');
		$stmt->bindValue(':gid', $id);
		$stmt->execute();
		$data->rows = array();
		while ($row = $stmt->fetch()) {
			$row_data = json_decode($row['data']); // Data is JSON-serialized in database. De-serialize it, since it will get re-serialized as part of the output
			$row_data->id = $row['id'];
			$data->rows[] = $row_data;
		}
		
		return $app->json($data, self::ERR_OK, ($authorized)? array('GRIMOIRE-WRITE-ACCESS' => 'true') : array());
	}

	function update($id, Request $req) {
		if (!$this->_is_authorized($id)) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED);
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		$id = substr($id, 0, 8); // Trim off Admin key
		updateGrimoireDate($db, $id);
		
		// Get existing
		$stmt = $db->prepare('SELECT `name` FROM `'.$this->table.'` WHERE `public_key`=?');
		$stmt->execute(array($id));
		$existing = $stmt->fetch(PDO::FETCH_OBJ);
		$out = new stdClass;
		if ($existing->name != $req->request->get('name')) { $out->name = $req->request->get('name'); }
		
		$stmt = $db->prepare('UPDATE `'.$this->table.'` SET `name`=:name WHERE `public_key`=:gid');
		$stmt->bindValue(':name', $req->request->get('name'));
		$stmt->bindValue(':gid', $id);
		$stmt->execute();
		if ($stmt->rowCount() < 1) return $this->error_out('SQL Failed update: '.var_export($stmt->errorInfo(), true), self::ERR_INTERNAL_ERROR);
		
		return $app->json($out, self::ERR_OK);
	}

	function delete($id, Request $req) {
		if (!$this->_is_authorized($id)) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED);
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
		
		$stmt = $db->prepare('SELECT `public_key` FROM `'.$this->table.'` WHERE `public_key`=:pid AND `admin_key`=:aid');
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
		if (!$req->request->has('order') || $req->request->get('order') === '') return $this->error_out('No Row ID given', self::ERR_BAD_REQUEST);
		
		// Authorize
		$gid = $req->request->get('gid');
		if (!$this->_is_authorized($gid)) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED);

		// Execute
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		$gid = substr($gid, 0, 8); // Trim off Admin key
		updateGrimoireDate($db, $gid);

		$data = new stdClass;
		foreach($req->request->keys() as $key) {
			if (!in_array($key, array('id', 'gid', 'order'))) {
				$data->$key = $req->request->get($key);
			}
		}
		
		$stmt = $db->prepare('INSERT INTO `'.$this->table.'` (`gid`, `order`, `data`) VALUES (:gid, :order, :data)');
		$stmt->bindValue(':gid', $gid);
		$stmt->bindValue(':order', $req->request->get('order'));
		$stmt->bindValue(':data', json_encode($data));
		$stmt->execute();
		if ($stmt->rowCount() < 1) return $this->error_out('SQL Failed insert: '.var_export($stmt->errorInfo(), true), self::ERR_INTERNAL_ERROR);
		
		$out = new stdClass;
		$out->id = $db->lastInsertId();
		return $app->json($out, self::ERR_CREATED);
	}

	function read($id) {
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		
		$stmt = $db->prepare('SELECT * FROM `'.$this->table.'` WHERE `id`=? LIMIT 1');
		$stmt->execute(array($id));
		if ($stmt->rowCount() < 1) return $this->error_out('No such row', self::ERR_NOT_FOUND);
		$out = $stmt->fetch(PDO::FETCH_OBJ);
		updateGrimoireDate($db, $out->gid);
		
		$data = json_decode($out->data, true);
		unset($out->data);
		foreach($data as $key => $value) {
			$out->$key = $value;
		}
		return $app->json($out, self::ERR_OK);
	}

	function update($id, Request $req) {
		// Validate
		if (!$req->request->has('gid') || $req->request->get('gid') === '') return $this->error_out('No Grimoire ID given', self::ERR_BAD_REQUEST);
		if (!$req->request->has('order') || $req->request->get('order') === '') return $this->error_out('No Row ID given', self::ERR_BAD_REQUEST);
		
		// Authorize
		$gid = $req->request->get('gid');
		if (!$this->_is_authorized($gid)) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED);
		
		$gid = substr($gid, 0, 8); // Trim off Admin key
		$app = $this->app; // Silex\Application
		$db = $app['db']; // PDO object
		updateGrimoireDate($db, $gid);
		
		$stmt = $db->prepare('SELECT `id` FROM `'.$this->table.'` WHERE `gid`=:gid AND `id`=:id');
		$stmt->bindValue(':gid', $gid);
		$stmt->bindValue(':id', $id);
		$stmt->execute();
		if ($stmt->rowCount() < 1) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED); // That row does not belong to that Grimoire
		
		// Get existing
		$stmt = $db->prepare('SELECT `order`, `data` FROM `'.$this->table.'` WHERE `id`=?');
		$stmt->execute(array($id));
		$data = $stmt->fetch();
		$existing = json_decode($data['data']);
		$existing->order = $data['order'];
		$out = new stdClass;
		if ($existing->order != $req->request->get('order')) { $out->order = $req->request->get('order'); }

		// Execute
		$data = new stdClass;
		foreach($req->request->keys() as $key) {
			if (!in_array($key, array('id', 'gid', 'order'))) {
				$data->$key = $req->request->get($key);
				if (isset($existing->$key) && $existing->$key != $req->request->get($key)) { $out->$key = $req->request->get($key); }
			}
		}
		$stmt = $db->prepare('UPDATE `'.$this->table.'` SET `order`=:order, `data`=:data WHERE `id`=:id LIMIT 1');
		$stmt->bindValue(':order', $req->request->get('order'));
		$stmt->bindValue(':data', json_encode($data));
		$stmt->bindValue(':id', $id);
		if (!$stmt->execute()) return $this->error_out('SQL Failed update: '.var_export($stmt->errorInfo(), true), self::ERR_INTERNAL_ERROR);
		
		return $app->json($out, self::ERR_OK);
	}

	function delete($id, Request $req) {
		// Authorize
		$gid = $req->request->get('gid');
		if (!$this->_is_authorized($gid)) return $this->error_out('Not Authorized', self::ERR_UNAUTHORIZED);
		
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
		
		$stmt = $db->prepare('SELECT `public_key` FROM `grimoire` WHERE `public_key`=:pid AND `admin_key`=:aid');
		$stmt->bindValue(':pid', $public);
		$stmt->bindValue(':aid', $admin);
		$stmt->execute();
		if ($stmt->rowCount() < 1) return false; // Bad public/private combo
		
		return true;
	}
}
$api->mount('/api/row', new Rows());

$api->get('/api/rows/{id}', function($id) use ($api) {
	// Get just the rows for this Grimoire
	$db = $api['db']; // PDO object

	// Check write-access
	$authorized = false;
	if (strlen($id) == 24) {
		$public = substr($id, 0, 8);
		$admin = substr($id, 8, 16);
	
		$stmt = $db->prepare('SELECT `public_key` FROM `grimoire` WHERE `public_key`=:pid AND `admin_key`=:aid');
		$stmt->bindValue(':pid', $public);
		$stmt->bindValue(':aid', $admin);
		$stmt->execute();
		if ($stmt->rowCount() < 1) $authorized = true;
	}
	$id = substr($id, 0, 8); // Trim off admin key, if any
	updateGrimoireDate($db, $id);

	$stmt = $db->prepare('SELECT * FROM `row` WHERE `gid`=:gid ORDER BY `order`');
	$stmt->bindValue(':gid', $id);
	$stmt->execute();
	$out = array();
	while ($row = $stmt->fetch()) {
		$row_data = json_decode($row['data']); // Data is JSON-serialized in database. De-serialize it, since it will get re-serialized as part of the output
		$row_data->id = $row['id'];
		$out[] = $row_data;
	}
	
	return $api->json($out, CRUD::ERR_OK, ($authorized)? array('GRIMOIRE-WRITE-ACCESS' => 'true') : array());
});

$api->get('/api/spells/{name}', function($name) use ($api) {
	$spells = array(
		"acid arrow", "acid fog", "acid splash", "aid", "air walk", "alarm", "align weapon", "alter self", "analyze dweomer", "animal growth", "animal messenger", "animal shapes", "animal trance", "animate dead", "animate objects", "animate plants", "animate rope", "antilife shell", "antimagic field", "antipathy", "antiplant shell", "arcane eye", "arcane lock", "arcane mark", "arcane sight", "arcane sight, greater", "astral projection", "atonement", "augury", "awaken",
		"baleful polymorph", "bane", "banishment", "barkskin", "bear's endurance", "bear's endurance, mass", "bestow curse", "binding", "black tentacles", "blade barrier", "blasphemy", "bless", "bless water", "bless weapon", "blight", "blindness/deafness", "blink", "blur", "break enchantment", "bull's strength", "bull's strength, mass", "burning hands",
		"call lightning", "call lightning storm", "calm animals", "calm emotions", "cat's grace", "cat's grace, mass", "cause fear", "chain lightning", "changestaff", "chaos hammer", "charm animal", "charm monster", "charm monster, mass", "charm person", "chill metal", "chill touch", "circle of death", "clairaudience/clairvoyance", "clenched fist", "cloak of chaos", "clone", "cloudkill", "color spray", "command", "command, greater", "command plants", "command undead", "commune", "commune with nature", "comprehend languages", "cone of cold", "confusion", "confusion, lesser", "consecrate", "contact other plane", "contagion", "contingency", "continual flame", "control plants", "control undead", "control water", "control weather", "control winds", "create food and water", "create greater undead", "create undead", "create water", "creeping doom", "crushing despair", "crushing hand", "cure critical wounds", "cure critical wounds, mass", "cure light wounds", "cure light wounds, mass", "cure minor wounds", "cure moderate wounds", "cure moderate wounds, mass", "cure serious wounds", "cure serious wounds, mass", "curse water",
		"dancing lights", "darkness", "darkvision", "daylight", "daze", "daze monster", "death knell", "death ward", "deathwatch", "deep slumber", "deeper darkness", "delay poison", "delayed blast fireball", "demand", "desecrate", "destruction", "detect animals or plants", "detect chaos", "detect evil", "detect good", "detect law", "detect magic", "detect poison", "detect scrying", "detect secret doors", "detect snares and pits", "detect thoughts", "detect undead", "dictum", "dimension door", "dimensional anchor", "dimensional lock", "diminish plants", "discern lies", "discern location", "disguise self", "disintegrate", "dismissal", "dispel chaos", "dispel evil", "dispel good", "dispel law", "dispel magic", "dispel magic, greater", "displacement", "disrupt undead", "disrupting weapon", "divination", "divine favor", "divine power", "dominate animal", "dominate monster", "dominate person", "doom", "dream",
		"eagle's splendor", "eagle's splendor, mass", "earthquake", "elemental swarm", "endure elements", "energy drain", "enervation", "enlarge person", "enlarge person, mass", "entangle", "enthrall", "entropic shield", "erase", "ethereal jaunt", "etherealness", "expeditious retreat", "explosive runes", "eyebite",
		"fabricate", "faerie fire", "false life", "false vision", "fear", "feather fall", "feeblemind", "find the path", "find traps", "finger of death", "fire seeds", "fire shield", "fire storm", "fire trap", "fireball", "flame arrow", "flame blade", "flame strike", "flaming sphere", "flare", "flesh to stone", "fly", "floating disk", "fog cloud", "forbiddance", "forcecage", "forceful hand", "foresight", "fox's cunning", "fox's cunning, mass", "freedom", "freedom of movement", "freezing sphere",
		"gaseous form", "gate", "geas/quest", "geas, lesser", "gentle repose", "ghost sound", "ghoul touch", "giant vermin", "glibness", "glitterdust", "globe of invulnerability", "globe of invulnerability, lesser", "glyph of warding", "glyph of warding, greater", "goodberry", "good hope", "grasping hand", "grease", "greater (spell name)", "guards and wards", "guidance", "gust of wind",
		"hallow", "hallucinatory terrain", "halt undead", "harm", "haste", "heal", "heal, mass", "heal mount", "heat metal", "helping hand", "heroes' feast", "heroism", "heroism, greater", "hide from animals", "hide from undead", "hideous laughter", "hold animal", "hold monster", "hold monster, mass", "hold person", "hold person, mass", "hold portal", "holy aura", "holy smite", "holy sword", "holy word", "horrid wilting", "hypnotic pattern", "hypnotism",
		"ice storm", "identify", "illusory script", "illusory wall", "imbue with spell ability", "implosion", "imprisonment", "incendiary cloud", "inflict critical wounds", "inflict critical wounds, mass", "inflict light wounds", "inflict light wounds, mass", "inflict minor wounds", "inflict moderate wounds", "inflict moderate wounds, mass", "inflict serious wounds", "inflict serious wounds, mass", "insanity", "insect plague", "instant summons", "interposing hand", "invisibility", "invisibility, greater", "invisibility, mass", "invisibility purge", "invisibility sphere", "iron body", "ironwood", "irresistible dance",
		"jump",
		"keen edge", "knock", "know direction",
		"legend lore", "lesser (spell name)", "levitate", "light", "lightning bolt", "limited wish", "liveoak", "locate creature", "locate object", "longstrider", "lullaby",
		"mage armor", "mage hand", "mage's disjunction", "mage's faithful hound", "mage's lucubration", "mage's magnificent mansion", "mage's private sanctum", "mage's sword", "magic aura", "magic circle against chaos", "magic circle against evil", "magic circle against good", "magic circle against law", "magic fang", "magic fang, greater", "magic jar", "magic missile", "magic mouth", "magic stone", "magic vestment", "magic weapon", "magic weapon, greater", "major creation", "major image", "make whole", "mark of justice", "mass (spell name)", "maze", "meld into stone", "mending", "message", "meteor swarm", "mind blank", "mind fog", "minor creation", "minor image", "miracle", "mirage arcana", "mirror image", "misdirection", "mislead", "mnemonic enhancer", "modify memory", "moment of prescience", "mount", "move earth",
		"neutralize poison", "nightmare", "nondetection",
		"obscure object", "obscuring mist", "open/close", "order's wrath", "overland flight", "owl's wisdom", "owl's wisdom, mass",
		"passwall", "pass without trace", "permanency", "permanent image", "persistent image", "phantasmal killer", "phantom steed", "phantom trap", "phase door", "planar ally", "planar ally, greater", "planar ally, lesser", "planar binding", "planar binding, greater", "planar binding, lesser", "plane shift", "plant growth", "poison", "polar ray", "polymorph", "polymorph any object", "power word blind", "power word kill", "power word stun", "prayer", "prestidigitation", "prismatic sphere", "prismatic spray", "prismatic wall", "produce flame", "programmed image", "project image", "protection from arrows", "protection from chaos", "protection from energy", "protection from evil", "protection from good", "protection from law", "protection from spells", "prying eyes", "prying eyes, greater", "purify food and drink", "pyrotechnics",
		"quench",
		"rage", "rainbow pattern", "raise dead", "ray of enfeeblement", "ray of exhaustion", "ray of frost", "read magic", "reduce animal", "reduce person", "reduce person, mass", "refuge", "regenerate", "reincarnate", "remove blindness/deafness", "remove curse", "remove disease", "remove fear", "remove paralysis", "repel metal or stone", "repel vermin", "repel wood", "repulsion", "resilient sphere", "resistance", "resist energy", "restoration", "restoration, greater", "restoration, lesser", "resurrection", "reverse gravity", "righteous might", "rope trick", "rusting grasp",
		"sanctuary", "scare", "scintillating pattern", "scorching ray", "screen", "scrying", "scrying, greater", "sculpt sound", "searing light", "secret chest", "secret page", "secure shelter", "see invisibility", "seeming", "sending", "sepia snake sigil", "sequester", "shades", "shadow conjuration", "shadow conjuration, greater", "shadow evocation", "shadow evocation, greater", "shadow walk", "shambler", "shapechange", "shatter", "shield", "shield of faith", "shield of law", "shield other", "shillelagh", "shocking grasp", "shout", "shout, greater", "shrink item", "silence", "silent image", "simulacrum", "slay living", "sleep", "sleet storm", "slow", "snare", "soften earth and stone", "solid fog", "song of discord", "soul bind", "sound burst", "speak with animals", "speak with dead", "speak with plants", "spectral hand", "spell immunity", "spell immunity, greater", "spell resistance", "spellstaff", "spell turning", "spider climb", "spike growth", "spike stones", "spiritual weapon", "statue", "status", "stinking cloud", "stone shape", "stoneskin", "stone tell", "stone to flesh", "storm of vengeance", "suggestion", "suggestion, mass", "summon instrument", "summon monster I", "summon monster II", "summon monster III", "summon monster IV", "summon monster V", "summon monster VI", "summon monster VII", "summon monster VIII", "summon monster IX", "summon nature's ally I", "summon nature's ally II", "summon nature's ally III", "summon nature's ally IV", "summon nature's ally V", "summon nature's ally VI", "summon nature's ally VII", "summon nature's ally VIII", "summon nature's ally IX", "summon swarm", "sunbeam", "sunburst", "symbol of death", "symbol of fear", "symbol of insanity", "symbol of pain", "symbol of persuasion", "symbol of sleep", "symbol of stunning", "symbol of weakness", "sympathetic vibration", "sympathy",
		"telekinesis", "telekinetic sphere", "telepathic bond", "teleport", "teleport object", "teleport, greater", "teleportation circle", "temporal stasis", "time stop", "tiny hut", "tongues", "touch of fatigue", "touch of idiocy", "transformation", "transmute metal to wood", "transmute mud to rock", "transmute rock to mud", "transport via plants", "trap the soul", "tree shape", "tree stride", "true resurrection", "true seeing", "true strike",
		"undeath to death", "undetectable alignment", "unhallow", "unholy aura", "unholy blight", "unseen servant",
		"vampiric touch", "veil", "ventriloquism", "virtue", "vision",
		"wail of the banshee", "wall of fire", "wall of force", "wall of ice", "wall of iron", "wall of stone", "wall of thorns", "warp wood", "water breathing", "water walk", "waves of exhaustion", "waves of fatigue", "web", "weird", "whirlwind", "whispering wind", "wind walk", "wind wall", "wish", "wood shape", "word of chaos", "word of recall",
		"zone of silence", "zone of truth"
	);
	
	if ($name == '') return $api->json($spells, CRUD::ERR_OK);
	
	$name = strtolower($name);
	$out = array();
	foreach($spells as $spell) {
		if (strpos(strtolower($spell), $name) !== false) $out[] = $spell;
	}
	return $api->json($out, CRUD::ERR_OK);
})
->value('name', '');

function updateGrimoireDate($db, $gid) {
	$gid = substr($gid, 0, 8); // Strip off any admin key
	$stmt = $db->prepare('UPDATE `grimoire` SET `last_viewed`=NOW() WHERE `public_key`=?');
	$stmt->execute(array($gid));
}

$api->run();