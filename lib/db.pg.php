<?php
// Create database connection; Pagodabox config
$db = new PDO('mysql:host='.$_SERVER["DB1_HOST"].';port='.$_SERVER["DB1_PORT"] .';dbname='.$_SERVER["DB1_NAME"], $_SERVER["DB1_USER"], $_SERVER["DB1_PASS"] );
