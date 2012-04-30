<?php
require_once('config.php');
?>
<!doctype html>
<!--[if lt IE 7]> <html class="no-js lt-ie9 lt-ie8 lt-ie7" lang="en"> <![endif]-->
<!--[if IE 7]>    <html class="no-js lt-ie9 lt-ie8" lang="en"> <![endif]-->
<!--[if IE 8]>    <html class="no-js lt-ie9" lang="en"> <![endif]-->
<!--[if gt IE 8]><!--> <html class="no-js" lang="en"> <!--<![endif]-->
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">

	<title>Grimoire</title>
	<meta name="description" content="">
	<meta name="author" content="">
	<meta name="viewport" content="width=device-width">

	<link rel="stylesheet" href="<?= $doc_root ?>style.css">
	<link rel="shortcut favicon" href="<?= $doc_root ?>img/favicon.png" />
	<link rel="apple-touch-icon" href="<?= $doc_root ?>img/apple-touch-icon.png" />
	<link rel="apple-touch-icon" sizes="72x72" href="<?= $doc_root ?>img/apple-touch-icon-72x72.png" />
	<link rel="apple-touch-icon" sizes="114x114" href="<?= $doc_root ?>img/apple-touch-icon-114x114.png" />
	<script src="<?= $doc_root ?>js/modernizr-2.5.3.min.js"></script>
	<script>
<?php
	echo "\tvar doc_root = '{$doc_root}';\n";
	if (isset($_GET['id'])) {
		// Pass the Grimoire ID along to Javascript
		echo "\tvar grim_id = '{$_GET['id']}';\n";
	}
?>
	</script>
</head>
<body>
<!--[if lt IE 7]><p class=chromeframe>Your browser is <em>ancient!</em> <a href="http://browsehappy.com/">Upgrade to a different browser</a> or <a href="http://www.google.com/chromeframe/?redirect=true">install Google Chrome Frame</a> to experience this site.</p><![endif]-->

<!-- Background image elements -->
<div id="book_mid">
<div id="book_top"><img src="<?= $doc_root ?>img/book_top.jpg" alt="" /></div>
<div id="book_bot"><img src="<?= $doc_root ?>img/book_bot.jpg" alt="" /></div>

<div id="left_col">
<div id="grim_display">
<h1 id="grim_title" class="default">New Grimoire</h1>
<ul id="grim_slots">
</ul>
<div id="new_slot"><input type="text" id="new_slot_text" /></div>
</div>
<p style="text-align:center; font-style:italic;"><img src="<?= $doc_root ?>img/tref_load.svg" /><br />Reticulating splines...</p>
</div>
<div id="right_col">
</div>

</div>

<script src="//ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
<script>window.jQuery || document.write('<script src="<?= $doc_root ?>js/jquery-1.7.1.min.js"><\/script>')</script>
<script src="<?= $doc_root ?>js/plugins.js"></script>
<script src="<?= $doc_root ?>js/script.js"></script>
</body>
</html>
