<?php

namespace BramKorsten\MakeItLive;

use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;
use PDO;

include_once(dirname(__FILE__) . '/dependencies/Mysqldump.php');
use Ifsnop\Mysqldump as IMysqldump;

error_reporting(E_ALL ^ E_NOTICE);

/**
 * Main update handler for MakeItLive
 * Uses the config file on the local server to fetch updates.
 */
class UpdateManager
{

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
    $this->databaseIsBackedUp = false;
  }

  public function run()
  {
    try {
      $this->remoteInstanceInformation = $this->getInstanceInformation();
    } catch (\Exception $e) {
      echo "Error:" . $e;
    }


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

    $rootPath = realpath(__DIR__ . "/" . $this->rootPath  . "cms");

    // Initialize archive object
    $zip = new ZipArchive();
    if(!is_dir($this->backupPath. "core/")) {
      mkdir($this->backupPath. "core/", 0660, true);
      chmod($this->backupPath. "core/", 0774);
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
        chmod($this->backupPath. "database/", 0774);
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
    curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl . 'core/releases/latest');
    $result = curl_exec($ch);
    curl_close($ch);

    $obj = json_decode($result);
    $first = true;
    foreach ($obj->results as $object) { // TODO: CHECK IF ANY RESULTS
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

    if (\version_compare($this->currentCoreVersion, $this->newestCoreVersion, '<')) {
      echo("<pre>New version found. Fetching update package...");
      if (!\file_exists('cms')) {
        mkdir('cms', 0660, true);
        chmod('cms', 0774);
      }
      if(!$this->backupDatabase()) {
        die('Could not backup database. Updating is not secure...');
      }
      if ($this->backupCore()) {
        $updateFile = $this->downloadCoreUpdate($this->coreUpdateUrl);
        $this->update($updateFile);
        $this->checkMigrationsForCore();
        $nowFormatted = date("Y-m-d H:i:s");
        $this->config_set('core', 'version', $this->newestCoreVersion);
        $this->config_set('core', 'last_update', $nowFormatted);
      };
    } else {
      echo("<pre>No new version found</pre>");
    }
  }


  /**
   * Run Phinx against migrations in the cms folder.
   * @return none
   */
  public function checkMigrationsForCore()
  {
    $phinx = $this->getPhinx();
    $_SERVER['PHINX_MIGRATION_PATH'] = "%%PHINX_CONFIG_DIR%%/cms/migrations";

    $output = call_user_func([$phinx, 'getMigrate'], NULL, NULL);
    $error = $phinx->getExitCode() > 0;

    $results = \explode("\n", $output);
    \array_splice($results, 0, 5);
    \array_splice($results, 1, 4);
    \array_splice($results, -1, 1);
    \array_splice($results, -2, 1);
    //print_r($results);
    echo("Checking for migrations in: " . $results[0] . "<br>");

    for ($i=1; $i < count($results); $i++) {
      echo($results[$i] . "<br>");
    }

  }


  /**
   * Run Phinx against migrations in modules/$moduleName/migrations.
   * @param  string $moduleName name of the module to check
   * @return none
   */
  public function checkMigrationsForModule($moduleName)
  {
    $phinx = $this->getPhinx();
    $_SERVER['PHINX_MIGRATION_PATH'] = "%%PHINX_CONFIG_DIR%%/modules/{$moduleName}/migrations";

    $output = call_user_func([$phinx, 'getMigrate'], NULL, NULL);
    $error = $phinx->getExitCode() > 0;

    $results = \explode("\n", $output);
    \array_splice($results, 0, 5);
    \array_splice($results, 1, 4);
    \array_splice($results, -1, 1);
    \array_splice($results, -2, 1);
    //print_r($results);
    echo("Checking for migrations in: " . $results[0] . "<br>");

    for ($i=1; $i < count($results); $i++) {
      echo($results[$i] . "<br>");
    }

  }


  /**
   * Function to check for modules updates. Uses MakeItLive API
   * @return none
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

    curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl . 'modules/releases');
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
        if (\version_compare($localVersion, $remoteVersion, '<')) {
          echo("Update available!");
          if(!$this->backupDatabase()) {
            die('Could not backup database. Updating is not secure...');
          }
          $downloadUrl = $remoteModules['results'][$localModule]['packages']['upgrade_link'];
          $updateFile = $this->downloadModuleUpdate($localModule,$remoteVersion,$downloadUrl);
          $this->update($updateFile, "modules/{$localModule}");
          $this->checkMigrationsForModule($localModule);
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
   * Attemps to download a zip file from the url provided. Returns the saved path
   * @param  string $downloadUrl Where to download the file from
   * @return string              The saved path of the update file
   */
  public function downloadCoreUpdate($downloadUrl)
  {
    echo($this->updatePath);
    if(!is_dir($this->updatePath)) {
      mkdir($this->updatePath, 0660, true);
      chmod($this->updatePath, 0777);
      echo("<pre>
      Directory {$this->updatePath} does not exist. Created.
      </pre>");
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
    if(curl_errno($ch)){throw new Exception(curl_error($ch));}
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
      chmod($this->updatePath, 0774);
      echo("<pre>
      Directory {$this->updatePath} does not exist. Created.
      </pre>");
    }
    $local_file = $this->updatePath . "mil-module-{$name}-{$version}.zip";
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
    if(curl_errno($ch)){throw new Exception(curl_error($ch));}
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
    $authorizationToken = $this->applicationToken;
    // $authorizationToken = "1234";
    $curlVariables = array(
      'Authorization' => $authorizationToken
    );
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl . 'instances');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, \http_build_query($curlVariables));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);
    $instanceInformation = json_decode($result, true);
    curl_close($ch);

    if ($instanceInformation["error"] !== "false") {
      throw new \Exception("The request returned an error: {$instanceInformation['message']}", 1);
    } else {
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
   * Runs an update using the file provided
   * @param  string $updateFile path of updatefile. File needs to be a ZipArchive
   * @return none
   */
  public function update($updateFile, $installPath = "")
  {
    if ($installpath != "") {
      if(!is_dir($installPath)) {
        mkdir($installPath, 0660, true);
        chmod($installPath, 0660);
        echo("<pre>
        Directory {$installPath} does not exist. Created.
        </pre>");
      }
      foreach( new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator( $installpath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
          RecursiveIteratorIterator::CHILD_FIRST ) as $value ) {
              $value->isFile() ? unlink( $value ) : rmdir( $value );
      }
    } else {
      foreach( new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator( 'cms', FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
          RecursiveIteratorIterator::CHILD_FIRST ) as $value ) {
              $value->isFile() ? unlink( $value ) : rmdir( $value );
      }
    }

    $path = __DIR__ . "/" . $this->rootPath . $installPath;
    $zip = new ZipArchive;
    $res = $zip->open($updateFile);
    if ($res === TRUE) {
        $zip->extractTo($path);
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

 ?>
