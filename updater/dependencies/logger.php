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
     * Begin and endtimes of the timing function.
     * @var int
     */
    protected $startTime, $endTime;


    /**
     * Constructor for the Logger class. Takes a fileName and filePath
     * @param string $filePath Path of the file to write the log to, relative to the logging class path.
     * @param string $fileName Name of the file to write to. Will be proceeded with a date.
     */
    function __construct($filePath, $fileName)
    {
      $this->filePath = dirname(__DIR__) . "/" . $filePath . "/";

      if (!is_dir($this->filePath)) {
        mkdir($this->filePath, 0660, true);
      }

      $this->fileName = date("Y-m-d") . "-" . $fileName . ".txt";
      $this->file = $this->filePath . $this->fileName;
    }

    /**
     * Add a new row to the log
     * @param string $message The message to write to the log
     * @param string $type    the type of message [verbose, warning, error]
     */
    public function add($message, $type = "verbose")
    {
      // open file
      $fd = fopen($this->file, "a");
      // append date/time to message
      $str = "[" . date("Y/m/d h:i:s", mktime()) . "] [{$type}] " . $message;
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
      $str = "[" . date("Y/m/d h:i:s", mktime()) . "] [STARTING LOG] \n";
      // write string
      fwrite($fd, $str . "\n");
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
      $str = "[" . date("Y/m/d h:i:s", mktime()) . "] [END OF LOG]\nTOTAL EXECUTION TIME SINCE LOG START: " . $execution_time . "SECONDS";
      // write string
      fwrite($fd, $str . "\n\n");
      // close file
      fclose($fd);
    }
  }

?>
