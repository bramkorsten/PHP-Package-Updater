<?php
require_once "updater/updateManager.php";

use BramKorsten\MakeItLive\UpdateManager as UpdateManager;

$time_start = microtime(true);

$updateManager = new UpdateManager();
$updateManager->run();

$time_end = microtime(true);
$execution_time = ($time_end - $time_start);

//execution time of the script
echo '<b>Total Execution Time:</b> '.$execution_time.' Secs';
die();


?>
