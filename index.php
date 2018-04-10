<?php

namespace Analogue\MakeItLive;

use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use PDO;

include_once(dirname(__FILE__) . '/dependencies/Mysqldump.php');
use Ifsnop\Mysqldump as IMysqldump;

error_reporting(E_ALL ^ E_NOTICE);

/**
 * Main update handler for MakeItLive
 * Uses the config file on the local server to fetch updates.
 */
class Updater
{

  /**
   * MakeItLive API url
   * @var string
   */
  protected $apiBaseUrl;

  /**
   * Path where backups are stored, relative to script location
   * @var string
   */
  protected $backupPath;

  /**
   * Parsed configuration file
   * @var array
   */
  protected $config;

  /**
   * Where to find the config file, relative to this script
   * @var string
   */
  protected $configPath = "";

  /**
   * Where the update script gets the zip containing the core from
   * @var string
   */
  protected $coreUpdateUrl;

  /**
   * Current core version
   * @var string
   */
  protected $currentCoreVersion;

  /**
   * Current module version
   * @var string
   */
  protected $currentModuleVersion;

  /**
   * Has the database been backed up in this session
   * @var boolean
   */
  protected $databaseIsBackedUp;

  /**
   * Array of all installed modules
   * @var array
   */
  protected $installedModules;

  /**
   * Where the update script gets the zip containing the module from
   * @var string
   */
  protected $moduleUpdateUrl;

  /**
   * Newest core version retrieved from server
   * @var string
   */
  protected $newestCoreVersion;

  /**
   * Newest module version retrieved from server
   * @var string
   */
  protected $newestModuleVersion;

  /**
   * Path where updates are stored temporarily, relative to script location
   * @var string
   */
  protected $updatePath;


  /**
   * Code to run when the updater is called
   */
  function __construct()
  {
    $this->config = parse_ini_file($this->configPath . 'config.ini', true);
    $this->currentCoreVersion = $this->config['core']['version'];
    $this->apiBaseUrl = $this->config['general']['api_url'];
    $this->backupPath = $this->config['general']['backup_path'];
    $this->updatePath = $this->config['general']['update_path'];
    $this->databaseIsBackedUp = false;
  }

  public function runUpdate()
  {
    $this->checkCoreUpdates();
    $this->checkModuleUpdates();
  }


  /**
   * Backs up the core using parameters from the config file. Returns true when succesful.
   * @return boolean state
   */
  public function backupCore()
  {
    $now = date("Ymd-Gi");
    $nowFormatted = date("Y-m-d H:i:s");
    // Get real path for our folder

    $rootPath = realpath('cms');

    // Initialize archive object
    $zip = new ZipArchive();
    if(!is_dir($this->backupPath. "core/")) {
      mkdir($this->backupPath. "core/", 0660, true);
      echo("<pre>
      Directory {$this->backupPath}core/ does not exist. Created.
      </pre>");
    }
    $zip->open($this->backupPath . "core/backup-{$this->currentCoreVersion}-{$now}.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);

    // Create recursive directory iterator
    /** @var SplFileInfo[] $files */
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootPath),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file)
    {
        // Skip directories (they would be added automatically)
        if (!$file->isDir())
        {
            // Get real and relative path for current file
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($rootPath) + 1);

            // Add current file to archive
            $zip->addFile($filePath, $relativePath);
        }
    }
    $this->config_set('core', 'latest_backup', $nowFormatted);
    $this->config_set('core', 'backup_name', "backup-{$this->currentCoreVersion}-{$now}.zip");

    $zip->close();
    return true;
  }


  /**
   * if databaseIsBackedUp is false, attempts to backup the database provided
   * using mysqldump-php
   * @return boolean returns true if attempt was succesful
   */
  public function backupDatabase()
  {
    if ($this->databaseIsBackedUp) {
      return true;
    } else {
      echo('<br>backing up database...');

      if(!is_dir($this->backupPath. "database/")) {
        mkdir($this->backupPath. "database/", 0660, true);
        echo("<pre>
        Directory {$this->backupPath}database/ does not exist. Created.
        </pre>");
      }

      $now = date("Ymd-Gi");
      $nowFormatted = date("Y-m-d H:i:s");
      try {
        $dump = new IMysqldump\Mysqldump('mysql:host=localhost;dbname=djvbnu_makeit', 'root', '');
        $dump->start($this->backupPath . "database/db-{$this->currentCoreVersion}-{$now}.zip");
        $this->databaseIsBackedUp = true;
        $this->config_set('general', 'latest_db_backup', $nowFormatted);
        $this->config_set('general', 'db_backup_name', "db-{$this->currentCoreVersion}-{$now}.zip");

        return true;
      } catch (\Exception $e) {
          echo 'mysqldump-php error: ' . $e->getMessage();
          return false;
      }
    }
  }

  /**
   * Function to check for core updates. Uses MakeItLive API
   * @return null
   */
  public function checkCoreUpdates()
  {
    echo("<pre>Current core version is {$this->currentCoreVersion}.</pre>
          <pre>Fetching latest version...</pre>");

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl . 'core/latest');
    $result = curl_exec($ch);
    curl_close($ch);

    $obj = json_decode($result);
    $first = true;
    foreach ($obj->results as $object) {
      if ($first) {
        $this->newestCoreVersion = $object->version;
        $this->coreUpdateUrl = $object->packages->upgrade_link;
        $first = false;
      }
      echo("<pre>
      Version {$object->version}
      Released on {$object->release_date}
      Download: {$object->packages->upgrade_link}
      </pre>");
    }

    if ($this->currentCoreVersion != $this->newestCoreVersion) {
      echo("<pre>New version found. Fetching update package...");
      if (!\file_exists('cms')) {
        mkdir('cms', 0660, true);
      }
      if(!$this->backupDatabase()) {
        die('Could not backup database. Updating is not secure...');
      }
      if ($this->backupCore()) {
        $updateFile = $this->downloadCoreUpdate($this->coreUpdateUrl);
        $this->update($updateFile);
        $nowFormatted = date("Y-m-d H:i:s");
        $this->config_set('core', 'version', $this->newestCoreVersion);
        $this->config_set('core', 'last_update', $nowFormatted);
      };
    } else {
      echo("<pre>No new version found</pre>");
    }
  }


  /**
   * Function to check for modules updates. Uses MakeItLive API
   * @return null
   */
  public function checkModuleUpdates()
  {
    $curlVariables = array(
      'modules' => array()
    );
    echo("<pre>Using modules: </pre><pre>");
    foreach ($this->config['modules']['modules'] as $module) {
      $version = $this->config["module_{$module}"]['version'];
      echo("{$module}@v{$version}\n");
      $this->installedModules[$module] = $version;
      $curlVariables['modules'][] = $module;

    }
    echo('</pre>');

    echo("<pre>Fetching newest module versions: </pre><pre>");

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl . 'modules/versions');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, \http_build_query($curlVariables));

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);

    $remoteModules = json_decode($result, true);
    curl_close($ch);

    foreach ($this->installedModules as $localModule => $localVersion) {
      $remoteVersion = $remoteModules['results'][$localModule]['version'];
      if ($remoteVersion != "") {
        echo("<pre>{$localModule}: {$localVersion} -> <b>{$remoteVersion}</b></pre>");
        if ($localVersion != $remoteVersion) {
          echo("Update available!");
          $downloadUrl = $remoteModules['results'][$localModule]['packages']['upgrade_link'];
          $updateFile = $this->downloadModuleUpdate($localModule,$remoteVersion,$downloadUrl);
          $this->update($updateFile);
          $nowFormatted = date("Y-m-d H:i:s");
          $this->config_set("module_{$localModule}", 'version', $remoteVersion);
          $this->config_set("module_{$localModule}", 'last_update', $nowFormatted);
        }
      } else {
        echo("<pre>No information available for module <b>{$localModule}</b>. Is it a valid module?</pre>");
      }

    }
  }


  /**
   * Overwrites config file parameters
   * @param string $section The section to add the parameter to
   * @param string $key     The key
   * @param string $value   The value
   */
  public function config_set($section, $key, $value)
  {
    $config_data = parse_ini_file($this->configPath . 'config.ini', true);
    $config_data[$section][$key] = $value;
    $new_content = '';
    foreach ($config_data as $section => $section_content) {
        $section_content = array_map(function($value, $key) {
          if (is_array($value)) {
            $subarray = "";

            foreach ($value as $arraykey => $arrayvalue) {
              $subarray .= "{$key}[] = '$arrayvalue'\n";
            }
            return substr($subarray, 0, -1);
          } else {
            return "$key = \"{$value}\"";
          }
        }, array_values($section_content), array_keys($section_content));
        $section_content = implode("\n", $section_content);
        $new_content .= "[$section]\n$section_content\n\n";
    }
    file_put_contents($this->configPath . 'config.ini', $new_content);
  }


  /**
   * Attemps to download a zip file from the url provided. Returns the saved path
   * @param  string $downloadUrl Where to download the file from
   * @return string              The saved path of the update file
   */
  public function downloadCoreUpdate($downloadUrl)
  {
    if(!is_dir($this->updatePath)) {
      mkdir($this->updatePath, 0660, true);
      echo("<pre>
      Directory {$this->updatePath} does not exist. Created.
      </pre>");
    }
    $local_file = $this->updatePath . "mil-core-upgrade-{$this->newestCoreVersion}.zip";
    $copy = copy($downloadUrl, $local_file);
    if (!$copy) {
      echo " <b>Failed!</b><br>Error while downloading.<br><br>The installation failed.";
      die();
    }
    else {
      echo " <b>Done!</b><br>Extracting package";
      return $local_file;
    }
  }


  /**
   * Attemps to download a zip file from the url provided. Returns the saved path
   * @param  string $name Name of the module
   * @param  string $version New version of the module
   * @param  string $downloadUrl Where to download the file from
   * @return string              The saved path of the update file
   */
  public function downloadModuleUpdate($name, $version, $downloadUrl)
  {
    if(!is_dir($this->updatePath)) {
      mkdir($this->updatePath, 0660, true);
      echo("<pre>
      Directory {$this->updatePath} does not exist. Created.
      </pre>");
    }
    $local_file = $this->updatePath . "mil-module-{$name}-{$version}.zip";
    $copy = copy($downloadUrl, $local_file);
    if (!$copy) {
      echo " <b>Failed!</b><br>Error while downloading.<br><br>The installation failed.";
      die();
    }
    else {
      return $local_file;
    }
  }


  /**
   * Restore the database to the backed up version thats provided
   * @param  string $dbBackupFile File to restore, needs to be a ZipArchive
   * @return none
   */
  public function restoreDatabase($dbBackupFile)
  {
    $backup = \file_get_contents($dbBackupFile);
    $sql_clean = '';
    foreach (explode("\n", $backup) as $line){
        if(isset($line[0]) && $line[0] != "#"){
            $sql_clean .= $line."\n";
        }
    }
    $db = new PDO('mysql:host=localhost', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbname = 'djvbnu_makeit_restore';
    $dbname = "`".str_replace("`","``",$dbname)."`";
    $db->query("CREATE DATABASE IF NOT EXISTS $dbname");
    $db->query("use $dbname");

    foreach (explode(";\n", $sql_clean) as $sql){
        $sql = trim($sql);
        if($sql){
            $db->query($sql);
        }
    }
  }


  /**
   * Runs an update using the file provided
   * @param  string $updateFile path of updatefile. File needs to be a ZipArchive
   * @return none
   */
  public function update($updateFile)
  {
    $path = pathinfo(realpath(__FILE__), PATHINFO_DIRNAME);
    $zip = new ZipArchive;
    $res = $zip->open($updateFile);
    if ($res === TRUE) {
        $zip->extractTo($path); // TODO: Add way to set a ROOT path to install to
        $zip->close();
        echo " <b>Done!</b><br>";
        $installed = true;
        unlink($updateFile);

    }
      else {
        echo " <b>Failed!</b><br>Error while extracting MIL.<br>The installation failed.</p>";
      }
      echo("</pre>");
  }
}



$time_start = microtime(true);
$updater = new Updater();
$updater->runUpdate();
$time_end = microtime(true);
$execution_time = ($time_end - $time_start);

//execution time of the script
echo '<b>Total Execution Time:</b> '.$execution_time.' Secs';
die();


 ?>
