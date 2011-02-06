#!/usr/bin/env php
<?php
require_once(dirname(__FILE__) . '/classes/load.inc.php');

$server = new As_HttpdParent();
$server->serve();
