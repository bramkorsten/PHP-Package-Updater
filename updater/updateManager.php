<?php

namespace BramKorsten\MakeItLive;

use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;
use PDO;

include_once(dirname(__FILE__) . '/dependencies/Mysqldump.php');
use Ifsnop\Mysqldump as IMysqldump;

include_once(dirname(__FILE__) . '/dependencies/logger.php');
use BramKorsten\MakeItLive\Logger as Logger;

error_reporting(E_ALL ^ E_NOTICE);

/**
 * Main update handler for MakeItLive
 * Uses the config file on the local server to fetch updates.
 */
class UpdateManager
{
  /**
   * Current version of UpdateManager
   * @var string
   */
  protected $version = "1.0.0";

  /**
   * MakeItLive API url
   * @var string
   */
  public $apiBaseUrl;

  /**
   * MakeItLive application token. Used for updates
   * @var string
   */
  public $applicationToken;

  /**
   * Path where backups are stored, relative to script location
   * @var string
   */
  public $backupPath;

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
  public $currentCoreVersion;

  /**
   * Current module version
   * @var string
   */
  protected $currentModuleVersion;

  /**
   * Has the database been backed up in this session
   * @var boolean
   */
  public $databaseIsBackedUp;

  /**
   * Array of all installed modules
   * @var array
   */
  protected $installedModules;

  /**
   * Array of versions of installed modules
   * @var array
   */
  protected $installedModuleVersions;

  /**
   * Latest core version retrieved from server
   * @var string
   */

  protected $latestCoreVersion;

  /**
   * Class for keeping logs of what happens during an update
   * @var BramKorsten\MakeItLive\Logger
   */
  protected $log;

  /**
   * Where the update script gets the zip containing the module from
   * @var string
   */
  protected $moduleUpdateUrl;

  /**
   * Newest module version retrieved from server
   * @var string
   */
  protected $newestModuleVersion;

  /**
   * phinxWrapper for database migrations
   * @var \Phinx\Wrapper\TextWrapper
   */
  protected $phinxWrap;

  /**
   * The remote configuration of this instance
   * @var string
   */
  public $remoteInstanceInformation;

  /**
   * Root path relative to the updatescript. Fetched on Construct from config.ini
   * @var string
   */
  public $rootPath;

  /**
   * Is the instance updatable. Pulled from configuration file, and updated by remoteInstanceInformation
   * @var string
   */
  public $updatable;

  /**
   * Path where updates are stored temporarily, relative to script location
   * @var string
   */
  public $updatePath;


  /**
   * Code to run when the updater is called
   */
  function __construct()
  {
    $this->config = parse_ini_file($this->configPath . 'config.ini', true);
    $this->currentCoreVersion = $this->config['core']['version'];
    $this->apiBaseUrl = $this->config['general']['api_url'];
    $this->applicationToken = $this->config['general']['app_token'];
    $this->rootPath = $this->config['general']['root_path'];
    $this->backupPath = __DIR__ . "/" . $this->rootPath . $this->config['general']['backup_path'];
    $this->updatePath = __DIR__ . "/" . $this->rootPath . $this->config['general']['update_path'];
    $this->updatable = $this->config['general']['updatable'];
    $this->databaseIsBackedUp = false;
    $this->installedModuleVersions = array();
    $this->log = new Logger("logs", "updatelog", "UpdateManager");
  }

  public function run()
  {
    $this->log->start();
    $this->log->add("UpdateManager version " . $this->version);

    // Check if the instance is allowed to update locally.
    if (!$this->updatable) {
      $this->log->add("Instance disabled in configuration file. This is likely caused by a failed update. Please fix any remaining issues and reset the 'updatable' flag", "warning");
      $this->log->end();
      return false;
    }

    if (set_time_limit(120) !== false) {
      $this->log->add("Set max execution time to '120'");
    } else {
      $this->log->add("Failed to modify execution time. In extreme cases, the update may timeout...", "warning");
    }

    $this->log->add("Starting updateprocess...");

    // Try to get the required information from the remote instance via the API
    // Will throw an exception if there is an error, or if the request returned 404
    try {
      $this->remoteInstanceInformation = $this->getInstanceInformation()['instance'];
    } catch (\Exception $e) {
      $this->log->add($e, "error");
      return false;
    }

    // Check if the instance is allowed to update remotely.
    if (!$this->remoteInstanceInformation['active']) {
      $this->log->add("Instance disabled remotely. Updating not allowed", "warning");
      $this->log->end();
      return false;
    }

    // Try to check and run updates on the core
    // Will throw exceptions if anything goes wrong
    // runCoreUpdates() will automatically revert required changes on exceptions
    try {
      $this->runCoreUpdates();
    } catch (\Exception $e) {
      return false;
    }

    // Try to check and run updates on the modules found in the remote instance
    // Will throw exceptions if anything goes wrong
    // runModuleUpdates() will automatically revert required changes on exceptions
    try {
      $this->runModuleUpdates();
    } catch (\Exception $e) {
      return false;
    }

    // Recursively delete all files in the folders specified in the configuration file
    $purgeFolders = $this->config['on_update']['purge'];

    if ($purgeFolders != null && !empty($purgeFolders) && $purgeFolders[0] != "") {
      foreach ($purgeFolders as $folderToPurge) {
        $this->removeFolder($folderToPurge);
      }
    }

    $this->log->end();
    return true;
  }


  /**
   * Backs up a folder using parameters from the config file. Returns true when succesful.
   * @param  string   $folder           The folder to backup, without leading/trailing slashes
   * @param  string   $backupLocation   Where to put the backup, without leading/trailing slashes
   * @param  string   $backupNamePrefix Aditional prefix to add to the backup name
   * @return boolean  state
   */
  public function backupFolder($folder, $backupLocation, $backupNamePrefix = "backup")
  {

    $now = date("Ymd-Gi");
    $nowFormatted = date("Y-m-d H:i:s");
    // Get real path for our folder
    $folderToBackup = realpath(__DIR__ . "/" . $this->rootPath  . $folder);
    if ($folderToBackup == "" || $folderToBackup == null) {
      $this->log->add("'{$folder}' is not a valid folder. No backup created", "warning");
      return false;
    }
    $this->log->add("Backing up {$folderToBackup}");

    try {
      // Initialize archive object
      $zip = new ZipArchive();
      if(!is_dir($this->backupPath. $backupLocation)) {
        mkdir($this->backupPath. $backupLocation, 0660, true);
        // TODO: Check chmod permissions for folder
        chmod($this->backupPath. $backupLocation, 0774);
        $this->log->add("added path: '{$backupLocation}'");
      }
      $zipFile = $this->backupPath . "{$backupLocation}/{$backupNamePrefix}-{$now}.zip";
      $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

      // Create recursive directory iterator
      /** @var SplFileInfo[] $files */
      $files = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($folderToBackup),
          RecursiveIteratorIterator::LEAVES_ONLY
      );

      foreach ($files as $name => $file)
      {
          // Skip directories (they would be added automatically)
          if (!$file->isDir())
          {
              // Get real and relative path for current file
              $filePath = $file->getRealPath();
              $relativePath = substr($filePath, strlen($folderToBackup) + 1);

              // Add current file to archive
              $zip->addFile($filePath, $relativePath);
          }
      }
      // TODO: Add api setting
      $zip->close();
      $this->log->add("Finished backing up folder");
      return "{$backupLocation}/{$backupNamePrefix}-{$now}.zip";

    } catch (\Exception $e) {
      $this->log->add("Could not backup folder", "error");
      $this->log->add($e, "error");
      return false;
    }
  }


  /**
   * if databaseIsBackedUp is false, attempts to backup the database provided
   * using mysqldump-php
   * @return boolean returns true if attempt was succesful
   */
  public function backupDatabase()
  {
    if ($this->databaseIsBackedUp) {
      $this->log->add("Database already backed up!");
      return true;
    } else {
      $this->log->add("Backing up database...");
      if(!is_dir($this->backupPath. "database/")) {
        $this->log->add("Creating database backup folder in {$this->backupPath}database");
        mkdir($this->backupPath. "database/", 0660, true);
        chmod($this->backupPath. "database/", 0774);
      }

      $now = date("Ymd-Gi");
      $nowFormatted = date("Y-m-d H:i:s");
      try {
        $dump = new IMysqldump\Mysqldump('mysql:host=localhost;dbname=djvbnu_makeit', 'root', '');
        $dump->start($this->backupPath . "database/db-{$this->currentCoreVersion}-{$now}.zip");
        $this->databaseIsBackedUp = true;
        // TODO: Remove local setting and add to API instance
        $this->config_set('general', 'latest_db_backup', $nowFormatted);
        $this->config_set('general', 'db_backup_name', "db-{$this->currentCoreVersion}-{$now}.zip");
        $this->log->add("Successfully backed up database");
        return true;
      } catch (\Exception $e) {
          $this->log->add("mysqldump-php throwed an error: ". $e->getMessage(), "error");
          return false;
      }
    }
  }


  /**
   * [changeRemoteInstanceSetting description]
   * @param  string $setting The setting to change in the remote instance
   * @param  string $value   What value to set
   * @return boolean returns true when successful
   */
  public function changeRemoteInstanceSetting($setting, $value)
  {
    $this->log->add("Setting '{$setting}' to '{$value}' in remote instance");
    $authorizationToken = $this->applicationToken;
    // $authorizationToken = "1234";
    $curlVariables = array(
      'Authorization' => $authorizationToken,
      'setting' => $setting,
      'value' => $value
    );
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl . 'setinstance');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, \http_build_query($curlVariables));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);
    $result = \json_decode($result, true);
    if(curl_errno($ch)) {
      $this->log->add("Curl error while saving instance data", "warning");
      $this->log->add(curl_error($ch), "warning");
      throw new \Exception(curl_error($ch));
    }
    if (curl_getinfo($ch)['http_code'] != 200) {
      $httpCode = curl_getinfo($ch)['http_code'];
      curl_close($ch);
      $this->log->add("Failed to save instance data... This should be fixed ASAP", "warning");
      $this->log->add("HTTP Code of error: {$httpCode}", "warning");
      throw new \Exception("Error while saving instance data. Error code: {$httpCode}", 1);
    }
    if ($result['error'] == false) {
      $this->log->add("Successfully saved setting");
    }
    curl_close($ch);
  }


  /**
   * Run Phinx against migrations in the cms folder.
   * @return none
   */
  public function checkMigrationsForCore()
  {
    // TODO: Add configurable cms folder to Phinx
    $this->log->add("Checking for new migrations using Phinx()");
    $phinx = $this->getPhinx();
    $_SERVER['PHINX_MIGRATION_PATH'] = "%%PHINX_CONFIG_DIR%%/cms/migrations";
    $this->log->add("  -  Checking for migrations in '%%PHINX_CONFIG_DIR%%/cms/migrations'");

    try {
      $output = call_user_func([$phinx, 'getMigrate'], NULL, NULL);
      $error = $phinx->getExitCode() > 0;

      $results = \explode("\n", $output);
      \array_splice($results, 0, 5);
      \array_splice($results, 1, 4);
      \array_splice($results, -1, 1);
      \array_splice($results, -2, 1);

      $this->log->add("  -  Using migrations folder: ". $results[0]);

      for ($i=1; $i < count($results); $i++) {
        $this->log->add("  -  ". $results[$i]);
      }
      $this->log->add("Finished migrating");
      return true;
    } catch (\Exception $e) {
      $this->log->add("Could not run migrations for core", "error");
      $this->log->add($e, "error");
      throw new \Exception("Error while running migrations for core. " . $e, 1);
    }


  }


  /**
   * Run Phinx against migrations in modules/$moduleName/migrations.
   * @param  string $moduleName name of the module to check
   * @return none
   */
  public function checkMigrationsForModule($moduleName)
  {
    $this->log->add("Checking for new migrations using Phinx()");
    $phinx = $this->getPhinx();
    $_SERVER['PHINX_MIGRATION_PATH'] = "%%PHINX_CONFIG_DIR%%/modules/{$moduleName}/migrations";
    $this->log->add("  -  Checking for migrations in '%%PHINX_CONFIG_DIR%%/modules/{$moduleName}/migrations'");
    $output = call_user_func([$phinx, 'getMigrate'], NULL, NULL);
    $error = $phinx->getExitCode() > 0;

    $results = \explode("\n", $output);
    \array_splice($results, 0, 5);
    \array_splice($results, 1, 4);
    \array_splice($results, -1, 1);
    \array_splice($results, -2, 1);

    for ($i=1; $i < count($results); $i++) {
      $this->log->add("  -  " . $results[$i]);
    }

  }


  /**
   * Check if any modules should be deleted
   * @param  array  $installedModules Current installed modules [name]
   * @return boolean                     [description]
   */
  public function checkModulesToDelete($installedModules)
  {
    $this->log->add("Checking for installed modules that will be deleted...");
    $dirs = glob("modules" . '/*' , GLOB_ONLYDIR);
    foreach ($dirs as $key => $dir) {
      $array = array();
      $array = explode("/", $dir);
      end($array);
      $arrayKey = key($array);
      reset($array);
      $dirs[$key] = $array[$arrayKey];
    }

    foreach ($dirs as $key => $dir) {
      if (!array_key_exists($dir, $installedModules)) {
        if(!$this->backupFolder("modules/{$dir}", "modules/{$dir}/deleted","delete")) {
          $this->log->add("Could not create a backup of 'modules/{$dir}'", "warning");
        }
        $this->removeFolder("modules/{$dir}");
        rmdir("modules/{$dir}");
        $this->config_delete_section("module_{$dir}");
      }
    }
  }


  /**
   * Overwrites config file parameters
   * @param string $section The section to add the parameter to
   * @param string $key     The key
   * @param string $value   The value
   */
  private function config_set($section, $key, $value)
  {
    // change loaded data for immediate use
    $this->config[$section][$key] = $value;

    $config_data = parse_ini_file(__DIR__ . "/" . $this->configPath . 'config.ini', true);
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
    file_put_contents(__DIR__ . "/" . $this->configPath . 'config.ini', $new_content);
  }


  /**
   * Delete a section of the configuration file
   * @param  string $sectionToSkip Which section to exclude from the new document
   * @return none
   */
  private function config_delete_section($sectionToSkip)
  {
    $config_data = parse_ini_file(__DIR__ . "/" . $this->configPath . 'config.ini', true);
    $new_content = '';
    foreach ($config_data as $section => $section_content) {
      if ($section != $sectionToSkip) {
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
    }
    file_put_contents(__DIR__ . "/" . $this->configPath . 'config.ini', $new_content);
  }


  /**
   * Disable automatic updates. This function should only be called when the updateprocess fails.
   * @return none
   */
  private function disableAutomaticUpdates()
  {
    $this->log->add("disabling automatic updates. Warning! This setting cannot be automatically disabled, and requires the 'updatable' flag in the .ini file to be reset to 1...");
    $this->config_set("general", "updatable", "0");
    $this->changeRemoteInstanceSetting("active", "0");
  }


  /**
   * Attemps to download a zip file from the url provided. Returns the saved path
   * @param  string $downloadUrl Where to download the file from
   * @return string              The saved path of the update file
   */
  public function downloadCoreUpdate($downloadUrl)
  {
    $this->log->add("Downloading core update from: {$downloadUrl}");
    if(!is_dir($this->updatePath)) {
      mkdir($this->updatePath, 0660, true);
      chmod($this->updatePath, 0777);
      $this->log->add("Created {$this->updatePath}");
    }
    $local_file = $this->updatePath . "mil-core-upgrade-{$this->newestCoreVersion}.zip";
    $fileHandler = \fopen($local_file, 'w');

    $header = array();
    $header[] = "Authorization: token {$this->applicationToken}";

    $options = array(
      CURLOPT_USERAGENT => "app",
      CURLOPT_FILE    => $fileHandler,
      CURLOPT_TIMEOUT =>  28800, // set this to 8 hours so we dont timeout on big files
      CURLOPT_URL     => $downloadUrl,
    );

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER,$header);
    curl_exec($ch);
    if (curl_errno($ch)) {
      $this->log->add("There was an error while downloading an update package: " . curl_error($ch), "error");
      throw new \Exception(curl_error($ch));
    }
    if (curl_getinfo($ch)['http_code'] != 200) {
      $httpCode = curl_getinfo($ch)['http_code'];
      curl_close($ch);
      \fclose($fileHandler);
      $this->log->add("There was an error while downloading an update package with error code: {$httpCode}", "error");
      throw new \Exception("Error while fetching update package. Error code: {$httpCode}", 1);
    }
    curl_close($ch);
    \fclose($fileHandler);
    $this->log->add("Successfully fetched update package!");
    return $local_file;
  }


  /**
   * Attemps to download a zip file from the url provided. Returns the saved path
   * @param  string $name           Name of the module
   * @param  string $version        New version of the module
   * @param  string $downloadUrl    Where to download the file from
   * @return string                 The saved path of the update file
   */
  public function downloadModuleUpdate($name, $version, $downloadUrl)
  {
    if(!is_dir($this->updatePath)) {
      mkdir($this->updatePath, 0660, true);
      chmod($this->updatePath, 0774);
      $this->log->add("Update folder does not exist and will be created");
    }
    $local_file = $this->updatePath . "mil-module-{$name}-{$version}.txt";
    $fileHandler = \fopen($local_file, 'w');

    $header = array();
    $header[] = "Authorization: token {$this->applicationToken}";

    $options = array(
      CURLOPT_USERAGENT => "app",
      CURLOPT_FILE    => $fileHandler,
      CURLOPT_TIMEOUT =>  28800, // set this to 8 hours so we dont timeout on big files
      CURLOPT_URL     => $downloadUrl,
    );

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER,$header);
    curl_exec($ch);
    if(curl_errno($ch)){throw new \Exception(curl_error($ch));}
    if(curl_getinfo($ch)['http_code'] != 200) {
      $httpCode = curl_getinfo($ch)['http_code'];
      curl_close($ch);
      \fclose($fileHandler);
      throw new \Exception("Error while fetching update package. Error code: {$httpCode}", 1);
    }
    curl_close($ch);
    \fclose($fileHandler);
    return $local_file;
  }


  /**
   * Gets all information about this instance and it's modules. This function replaces the old CheckUpdate() Methods.
   * @return array The formatted responsedata
   * @throws Exception When the server responds with an error
   */
  public function getInstanceInformation()
  {
    $this->log->add("Asking API for instance information using the applicationToken from the config file");
    $authorizationToken = $this->applicationToken;
    // $authorizationToken = "1234";
    $curlVariables = array(
      'Authorization' => $authorizationToken
    );
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl . 'instance');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, \http_build_query($curlVariables));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);
    if(curl_errno($ch)){throw new \Exception(curl_error($ch));}
    if(curl_getinfo($ch)['http_code'] != 200) {
      $httpCode = curl_getinfo($ch)['http_code'];
      curl_close($ch);
      throw new \Exception("Error while getting instance information... Error code: {$httpCode}", 1);
    }
    $instanceInformation = json_decode($result, true);
    $this->installedModuleVersions = \explode(",", $instanceInformation['instance']['module_versions']);
    curl_close($ch);

    if ($instanceInformation["error"] !== "false") {
      throw new \Exception("The request returned an error: {$instanceInformation['message']}", 1);
    } else {
      $this->log->add("Successfully got information!");
      return $instanceInformation;
    }
  }


  /**
   * Calls Phinx or creates a new instance if it doesn't exist yet.
   * @return \Phinx\Wrapper\TextWrapper Phinx instance
   */
  public function getPhinx()
  {
    if($this->phinxWrap == NULL) {
      $phinxApp = require __DIR__ . '/dependencies/vendor/robmorgan/phinx/app/phinx.php';
      return $this->phinxWrap = new \Phinx\Wrapper\TextWrapper($phinxApp);
    } else {
      return  $this->phinxWrap;
    }
  }


  /**
   * Restore the database to the backed up version thats provided
   * @param  string $dbBackupFile File to restore, needs to be a ZipArchive
   * @return none
   */
  public function restoreDatabase($dbBackupFile)
  {
    $this->log->add("Restoring database backup {$dbBackupFile}");
    $backup = \file_get_contents($dbBackupFile);
    $sql_clean = '';
    foreach (explode("\n", $backup) as $line){
        if(isset($line[0]) && $line[0] != "#"){
            $sql_clean .= $line."\n";
        }
    }

    try {
      $db = new PDO('mysql:host=localhost', 'root', '');
      $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      // TODO: Should probably not get restored to only a backup database, but also to the main one.
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
      $this->log->add("Successfully restored database to {$dbname}");
      return true;
    } catch (\Exception $e) {
      $this->log->add("There was a problem while restoring the database...", "error");
      $this->log->add($e->getMessage(), "error");
      return false;
    }
  }


  /**
   * Recursively remove a folder and all its subfolders
   * @param  string $path The path to delete
   * @return none
   */
  public function removeFolder($path)
  {
    if ($path == "") {
      $this->log->add("WARNING: folder to delete cannot be empty for safety reasons...", "warning");
    } else {
      if(is_dir($path)) {
        $this->log->add("Deleting folder: '{$path}'");
        foreach( new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
            RecursiveIteratorIterator::CHILD_FIRST ) as $value ) {
                $value->isFile() ? unlink( $value ) : rmdir( $value );
        }
      }
      else {
        $this->log->add("Directory '{$path}' does not exist and cannot be deleted...", "warning");
      }
    }
  }


  /**
   * Revert a destinationfolder to a backup version.
   * Warning, this does not check for any setting that could be wrong,
   * and will blindly run it's function. USE WITH CAUTION
   * @param  string $file        The backup file
   * @param  string $destination Path to revert
   * @return none
   */
  public function revertToBackup($file, $destination)
  {
    $this->log->add("Reverting '{$destination}' using '{$file}'");
    $this->removeFolder($destination);
    if (!is_dir($destination)) {
      mkdir($destination, 0660, true);
      chmod($destination, 0660);
    }
    $path = __DIR__ . "/" . $this->rootPath . "backups/" . $file;

    $zip = new ZipArchive;
    $result = $zip->open($path);
    if ($result === TRUE) {
      if ($zip->extractTo($destination)) {
        $zip->close();
        $this->log->add("Revert successful!");
      }
      else {
        // TODO: Notify of critical damage
        $zip->close();
        $this->log->add("Error while reverting", "error");
        $this->log->add("The system will not be stable at this point. A manual reset is advised. Do not delete the backup folders, as these may contain useful data!", "warning");
      }

    }
    else {
      // TODO: Notify of critical damage
      $this->log->add("Not a valid backup file", "error");
      $this->log->add("The system will not be stable at this point. A manual reset is advised. Do not delete the backup folders, as these may contain useful data!", "warning");
    }
  }


  /**
   * Rollback migrations for the module specified
   * @param string $module  Name name of the module to rollback
   * @param int $target     Target version. Set to 0 for uninstall
   * @return none
   */
  public function rollbackMigrationsForModule($moduleName, $target)
  {
    $phinx = $this->getPhinx();
    $_SERVER['PHINX_MIGRATION_PATH'] = "%%PHINX_CONFIG_DIR%%/modules/{$moduleName}/migrations";

    $output = call_user_func([$phinx, 'getRollback'], NULL, $target);
    $error = $phinx->getExitCode() > 0;

    //$results = \explode("\n", $output);
    // \array_splice($results, 0, 5);
    // \array_splice($results, 1, 4);
    // \array_splice($results, -1, 1);
    // \array_splice($results, -2, 1);
    print_r($output);
    // echo("Checking for migrations in: " . $results[0] . "<br>");
    //
    // for ($i=1; $i < count($results); $i++) {
    //   echo($results[$i] . "<br>");
    // }
  }


  /**
   * Function to check for updates to the core, and run them if true
   * @return null
   * @throws Exception will throw exception when there is an error
   */
  public function runCoreUpdates()
  {
    $this->log->add("Core running MakeItLive v{$this->currentCoreVersion}");
    $this->latestCoreVersion = $this->remoteInstanceInformation["core_versions"][0]["version"];
    $latestCoreReleaseDate = $this->remoteInstanceInformation["core_versions"][0]["release_date"];
    $this->coreUpdateUrl = $this->remoteInstanceInformation["core_versions"][0]["upgrade_link"];

    $this->log->add("Latest version is v{$this->latestCoreVersion}, released on '{$latestCoreReleaseDate}'");

    if (\version_compare($this->currentCoreVersion, $this->latestCoreVersion, '<')) {
      $this->log->add("New version found! Peparing to update the core...");
      // TODO: Make path to CMS configurable
      if (!\file_exists('cms')) {
        $this->log->add("No core folder found! Creating it!");
        mkdir('cms', 0660, true);
        chmod('cms', 0774);
      }
      if(!$this->backupDatabase()) {
        $this->log->add("Could not backup database. Updating is not secure and will be cancelled!", "error");
        $this->disableAutomaticUpdates();
        $this->sendHelp();
        throw new \Exception("Error while backing up the database. Updating would not be secure...", 1);
      }
      $step = 0;
      $backupName = "";
      if ($backupName = $this->backupFolder("cms", "core", "core-{$this->currentCoreVersion}")) {
        $this->config_set('core', "latest_backup", date("Y-m-d H:i:s"));
        $this->config_set('core', "backup_name", $backupName);
        try {
          $updateFile = $this->downloadCoreUpdate($this->coreUpdateUrl);
          $step = 1;
          $this->removeFolder("cms");
          $step = 2;
          $this->update($updateFile);
          $step = 3;
          $this->checkMigrationsForCore();
          $nowFormatted = date("Y-m-d H:i:s");
          $this->config_set('core', 'version', $this->latestCoreVersion);
          $this->config_set('core', 'last_update', $nowFormatted);
          $this->changeRemoteInstanceSetting("core_version", $this->latestCoreVersion);
        }
        catch (\Exception $e) {
          $this->log->add("Critical error while updating the core.", "error");
          $this->log->add($e, "error");
          $this->log->add("Reverting updates.", "error");

          // This switch checks where an update has gone wrong,
          // which makes reverting just those steps possible.
          switch ($step) {
            case 0:
              $this->disableAutomaticUpdates();
              $this->sendHelp();
              break;
            case 1:
            case 2:
              $this->disableAutomaticUpdates();
              $this->revertToBackup($this->config['core']['backup_name'], "cms");
              $this->sendHelp();
              break;
            case 3:
              $this->disableAutomaticUpdates();
              $this->revertToBackup($this->config['core']['backup_name'], "cms");

              // FIXME: Add a way to revert migrations. Although this isn't completely nessesary,
              //        it would be nice to not leave any new, unused tables when an update fails.

              //$this->revertMigrations();
              $this->sendHelp();
              break;

            default:
              $this->disableAutomaticUpdates();
              $this->sendHelp();
              break;
          }
          throw new \Exception("Error while updating the core with message: " . $e, 1);
        }
      }
      else {
        $this->log->add("Could not backup the core. Updating would not be secure...", "error");
        $this->disableAutomaticUpdates();
        $this->sendHelp();
        throw new \Exception("Error while backing up the core. Updating would not be secure...", 1);
      }
    } else {
      $this->log->add("Core is up to date!");
    }
  }

  // TODO: Add installed modules to the ini file
  /**
   * Function to check for modules updates. Uses MakeItLive API
   * @return none
   * @throws Exception will throw an exception if there's an error
   */
  public function runModuleUpdates()
  {
    $this->log->add("Checking for module updates");

    foreach ($this->remoteInstanceInformation["modules"] as $module) {
      if ($module !== "false") {
        $moduleName = $module['name'];
        if (isset($module['versions'][0]['version'])) {
          $latestVersion = $module['versions'][0]['version'];
          $upgradeLink = $module['versions'][0]['upgrade_link'];
        } else {
          $latestVersion = "0.0.0";
        }
        $this->installableModules[$moduleName]['version'] = $latestVersion;
        $this->installableModules[$moduleName]['upgradelink'] = $upgradeLink;
      } else {
        $this->log->add("  -  WARNING: information about a module could not be found on the server. The response was false", "warning");
      }
    }
    $installableModuleIndex = 0;
    $flag_hasUpdated = false;
    foreach ($this->installableModules as $installableModule => $module) {
      $localVersion = $this->config["module_{$installableModule}"]['version'];
      if ($localVersion == "") {
        $localVersion = "0.0.0";
      }
      $latestVersion = $module['version'];
      $latestVersionLink = $module['upgradelink'];

      if ($latestVersion != "" && $latestVersion != "0.0.0" && $latestVersion != false) {
        if (\version_compare($localVersion, $latestVersion, '<')) {
          $this->log->add("  -  Module '{$installableModule}' will be updated from v{$localVersion} to v{$latestVersion}");
          if(!$this->backupDatabase()) {
            $this->log->add("Could not backup database! Updating would not be secure...", "error");
            throw new \Exception("Could not backup database. Update stopped...", 1);
            return false;
          }
          $downloadUrl = $latestVersionLink;
          if ($localVersion != "0.0.0" && $localVersion != "") {
            $backupName = "";
            $hasBackup = false;
            if ($backupName = $this->backupFolder("modules/{$installableModule}", "modules/{$installableModule}", "{$installableModule}-{$localVersion}")) {
              $this->config_set("module_{$installableModule}", 'latest_backup', date("Y-m-d H:i:s"));
              $this->config_set("module_{$installableModule}", 'backup_name', $backupName);
              $hasBackup = true;
            } else {
              throw new \Exception("Could not backup module '{$installableModule}'. Update stopped...", 1);
              return false;
            }
          }
          $step = 0;
          try {
            $updateFile = $this->downloadModuleUpdate($installableModule,$latestVersion,$downloadUrl);
            $step = 1;
            $this->removeFolder("modules/{$installableModule}");
            $step = 2;
            $this->update($updateFile, "modules/{$installableModule}");
            $step = 3;
            $this->checkMigrationsForModule($installableModule);
            $step = 4;
            $this->installedModuleVersions[$installableModuleIndex] = $latestVersion;
            $nowFormatted = date("Y-m-d H:i:s");
            $this->config_set("module_{$installableModule}", 'version', $latestVersion);
            $this->config_set("module_{$installableModule}", 'last_update', $nowFormatted);
            $flag_hasUpdated = true;
          } catch (\Exception $e) {
            $this->log->add("Exception while updating a module. Reverting...", "error");
            $this->log->add($e, "error");

            switch ($step) {
              case 0:
                $this->disableAutomaticUpdates();
                $this->sendHelp();
                break;
              case 1:
              case 2:
                if ($hasBackup) {
                  $this->revertToBackup($this->config["module_{$installableModule}"]['backup_name'], "modules/{$installableModule}");
                }
                $this->disableAutomaticUpdates();
                $this->sendHelp();
                break;
              case 3:
                if ($hasBackup) {
                  $this->revertToBackup($this->config["module_{$installableModule}"]['backup_name'], "modules/{$installableModule}");
                }
                $this->disableAutomaticUpdates();

                // FIXME: Add a way to revert migrations. Although this isn't completely nessesary,
                //        it would be nice to not leave any new, unused tables when an update fails.
                //$this->revertMigrations();
                $this->sendHelp();
                break;

              default:
                $this->disableAutomaticUpdates();
                $this->sendHelp();
                break;

            }

            throw new \Exception("Exception while updating a module. " . $e, 1);
          }
        }
      } else {
        $this->log->add("Skipping invalid module from server", "warning");
      }
      $installableModuleIndex++;
    }
    if ($flag_hasUpdated) {
      $this->changeRemoteInstanceSetting("module_versions", implode(",", $this->installedModuleVersions));
    }
    $this->log->add("Finished checking for module updates!");

    $this->checkModulesToDelete($this->installableModules);
  }



  /**
   * Send an automated email to DJVB for help when an update fails. This might mean the developer that built this f'd up :P
   * @param  string $msg The message to include after the standard message.
   */
  public function sendHelp($msg = "Not Provided")
  {
    $message = "There was an error while updating an instance. Reverting was successful, but automatic updating has been disabled. The custom message was:\r\n {$msg}";
    $message = wordwrap($message, 70, "\r\n");
    $to = $this->config['general']['support_email'];
    $from = $this->config['general']['from_email'];
    $headers = array(
      'From' => $from,
      'Reply-To' => $from,
      'X-Mailer' => 'PHP/' . phpversion()
    );
    mail($to, 'UpdateManager: Error while updating an instance', $message, $headers);
    $this->log->add("An automatic email was sent to {$to}");
  }


  // TODO: Add this feature
  /**
   * Will set maintenance mode to the value provided.
   * @param boolean $value set mode to true or false
   */
  public function setMaintenanceMode($value)
  {
    if ($value === true) {
      $this->log->add("Maintenance mode enabled!");
    }
    else {
      $this->log->add("Maintenance mode disabled!");
    }
  }


  /**
   * Runs an update using the file provided
   * @param  string $updateFile path of updatefile. File needs to be a ZipArchive
   * @return none
   */
  public function update($updateFile, $installPath = "")
  {
    $this->log->add("Updating...");
    if ($installPath != "") {
      if(!is_dir($installPath)) {
        mkdir($installPath, 0660, true);
        chmod($installPath, 0660);
        $this->log->add("  -  Directory {$installPath} created for installation");
      }
    }

    $this->log->add("  -  Extracting update package");
    $path = __DIR__ . "/" . $this->rootPath . $installPath;
    $zip = new ZipArchive;
    $result = $zip->open($updateFile);
    if ($result === TRUE) {
        $zip->extractTo($path);
        $zip->close();
        $this->log->add("  -  Extracted update package successfully!");
        $this->log->add("  -  Deleting update package");
        if (!unlink($updateFile)) {
          $this->log->add("WARNING: Could not delete update package...", "warning");
        }
        $this->log->add("Finished updating");
    }
    else {
      $this->log->add("Error while extracting update package! Update aborted", "error");
      // TODO: Fix aborting methods
      throw new \Exception("Unexpected error while extracting an updatepackage", 1);
    }
  }
}

 ?>
