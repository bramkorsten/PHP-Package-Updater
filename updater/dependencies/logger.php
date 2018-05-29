<?php
  namespace BramKorsten\MakeItLive;

  /**
   * Logging class for MakeItLive UpdateManager. Writes a new file every time an update is available
   */
  class Logger
  {

    /**
     * Name of the file to write to
     * @var string
     */
    protected $fileName;

    /**
     * path of the file to write to
     * @var string
     */
    protected $filePath;

    /**
     * final file to write to
     * @var string
     */
    protected $file;

    /**
     * total executionTime of the log
     * @var string
     */
    protected $executionTime;

    /**
     * name of the package making log entries
     * @var string
     */
    protected $packageName;

    /**
     * Begin and endtimes of the timing function.
     * @var int
     */
    protected $startTime, $endTime;


    /**
     * Constructor for the Logger class. Takes a fileName and filePath
     * @param string $filePath Path of the file to write the log to, relative to the logging class path.
     * @param string $fileName Name of the file to write to. Will be proceeded with a date.
     */
    function __construct($filePath, $fileName, $packageName)
    {
      $this->filePath = dirname(__DIR__) . "/" . $filePath . "/";

      if (!is_dir($this->filePath)) {
        mkdir($this->filePath, 0660, true);
      }

      $this->fileName = date("Y-m-d") . "-" . $fileName . ".txt";
      $this->file = $this->filePath . $this->fileName;
      $this->packageName = $packageName;
    }

    /**
     * Add a new row to the log
     * @param string $message The message to write to the log
     * @param string $type    the type of message [verbose, warning, error]
     */
    public function add($message, $type = "info", $package = "")
    {
      if ($package == "") {
        $package = $this->packageName;
      }
      $typeLetter;
      switch ($type) {
        case 'verbose':
          $typeLetter = "V/";
          break;

        case 'info':
          $typeLetter = "I/";
          break;

        case 'warning':
          $typeLetter = "W/";
          break;

        case 'error':
          $typeLetter = "E/";
          break;

        default:
          $typeLetter = "";
          break;
      }
      // open file
      $fd = fopen($this->file, "a");
      // append date/time to message
      $str = date("Y/m/d H:i:s", time()) . ": {$typeLetter}{$package}: " . $message;
      // write string
      fwrite($fd, $str . "\n");
      // close file
      fclose($fd);
    }

    public function start()
    {
      $this->startTime = microtime(true);
      // open file
      $fd = fopen($this->file, "a");
      // append date/time to message
      $str = date("Y/m/d H:i:s", time()) . ": V/MakeItLive_Logger: STARTING LOG\n";
      // write string
      fwrite($fd, "\n" . $str . "\n");
      // close file
      fclose($fd);
    }

    public function end()
    {
      $this->endTime = microtime(true);
      $execution_time = ($this->endTime - $this->startTime);
      // open file
      $fd = fopen($this->file, "a");
      // append date/time to message
      $this->add("ENDING LOG", "verbose", "MakeItLive_Logger");
      $str = date("Y/m/d H:i:s", time()) . ": V/MakeItLive_Logger: TOTAL EXECUTION TIME SINCE LOG START: " . $execution_time . " SECONDS";
      // write string
      fwrite($fd, $str . "\n\n");
      // close file
      fclose($fd);
    }


    /**
     * Export a log as plaintext
     * @param  string $date The date of the log file. Defaults to today
     * @param  string $name The name of the file. Defaults to updatelog
     * @return string|false Returns the file as a string, or false if the file does not exist
     */
    public function exportLog($date = "now", $name = "updatelog")
    {
      if ($date = "now") {
        $date = date("Y-m-d");
      }
      $dir = $this->filePath . "/";
      $fileName = $date . "-" . $name . ".txt";
      if (file_exists($dir.$fileName)) {
        $this->add("Exporting '{$dir}{$fileName}' as plaintext", "verbose", "MakeItLive_Logger");
        $file = fopen($dir.$fileName, "r");
        $fileContent = fread($file, filesize($dir.$fileName));
        fclose($file);
        return $fileContent;

      } else {
        $this->add("Cannot export '{$fileName}'. File does not exist", "warning", "MakeItLive_Logger");
        return false;
      }

    }
  }

?>
