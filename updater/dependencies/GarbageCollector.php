<?php
namespace BramKorsten\MakeItLive;

include_once(dirname(__FILE__) . '/Logger.php');
use BramKorsten\MakeItLive\Logger as Logger;

/**
 * GarbageCollector for UpdateManager. Handles the backups and log files and
 * deletes them after a set time
 */
class GarbageCollector
{

  protected $log;

  function __construct()
  {
    $this->log = new Logger("logs", "updatelog", "GarbageCollector");
  }

  public function collectGarbage()
  {
    $this->log->add("Checking for old updatepackages, backups log files to delete");
    echo(__DIR__);
  }
}


?>
