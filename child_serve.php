#!/usr/bin/env php
<?php
require_once(dirname(__FILE__) . '/classes/load.inc.php');
$child = new As_HttpdChild();
$child->serveRequest();

exit;