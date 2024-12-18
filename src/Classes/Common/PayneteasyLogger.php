<?php

namespace Payneteasy\Classes\Common;

(defined('ABSPATH') || PHP_SAPI == 'cli') or die('Restricted access');

use \Payneteasy\Classes\Exception\PayneteasyException;

class PayneteasyLogger
{
    /**
     * Log level constants for messages.
     */
    const LOG_LEVEL_DEBUG = 'DEBUG';
    const LOG_LEVEL_ERROR = 'ERROR';

    /**
     * @var string The path to the log file.
     */
    private string $logFilePath;

    /**
     * @var array Options for customizing log messages.
     */
    private array $options = [
        'prepareAsJson' => true,
        'showCurrentDate' => true,
        'showLogLevel' => true,
        'additionalCommonText' => ''
    ];

    /**
     * @var array Custom log recording functions for different log levels.
     */
    private array $customRecordings = [];

    /**
     * @var PayneteasyLogger|null Singleton instance.
     */
    private static ?self $instance = null;


    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct() {}


    /**
     * Get the singleton instance of the logger.
     *
     * @return self The logger instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }


    /**
     * Set the log file path.
     *
     * @param string $filePath The path to the log file.
     *
     * @return self
     */
    public function setLogFilePath(string $filePath = ''): self
    {
        if (empty($filePath)) {
            $filePath = dirname(__DIR__, 3) . '/log/payneteasy.log';
        }
        $this->logFilePath = $filePath;
        $this->createLogDirectory();

        return $this;
    }


    /**
     * Set an option for customizing log messages.
     *
     * @param string $option The option to set ('prepareAsJson', 'showLogLevel', or 'showCurrentDate').
     * @param mixed $value The value to set for the option.
     *
     * @return self
     */
    public function setOption(string $option, $value): self
    {
        if (array_key_exists($option, $this->options)) {
            $this->options[$option] = $value;
        }

        return $this;
    }


    /**
     * Set a custom log recording function for a specific log level.
     *
     * @param callable $fnc The custom log recording function.
     * @param string $logLevel The log level for which the function is set.
     *
     * @return self
     */
    public function setCustomRecording(callable $fnc, string $logLevel): self
    {
        $this->customRecordings[$logLevel] = $fnc;

        return $this;
    }


    /**
     * Log a message with the specified log level.
     *
     * @param string $message The log message.
     * @param string $logLevel The log level ('DEBUG' or 'ERROR').
     *
     * @return void
     */
    private function log(string $message, string $logLevel): void
    {
        try {
            if (isset($this->customRecordings[$logLevel]) && is_callable($this->customRecordings[$logLevel])) {
                $customFunc = $this->customRecordings[$logLevel];
                $customFunc($message);
            } else {
                $this->writeToFile($message);
            }
        } catch (\Exception | PayneteasyException $e) {
            // Обработка ошибки записи в файл
            error_log($e->getMessage());
        }
    }


    /**
     * Простая запись в файл.
     *
     * @param string $message The log message.
     *
     * @return void
     */
    public function writeToFile(string $message)
    {
        $result = file_put_contents($this->logFilePath, $message . PHP_EOL, FILE_APPEND);
        if ($result === false) {
            throw new PayneteasyException('Не удалось выполнить запись в файл логирования.');
        }
    }


    /**
     * Prepare a log message with optional context and log level.
     *
     * @param string $message The log message.
     * @param array $context Optional context data to log with the message.
     * @param string $logLevel The log level ('DEBUG' or 'ERROR').
     *
     * @return string The prepared log message.
     */
    private function prepareMessage(string $message, array $context, string $logLevel): string
    {
        $resultMessage = '';

        if ($this->options['showCurrentDate']) {
            $resultMessage .= '[' . date('Y-m-d H:i:s') . '] ';
        }

        if ($this->options['showLogLevel']) {
            $resultMessage .= $logLevel . ' ';
        }

        if (!empty($this->options['additionalCommonText'])) {
            $resultMessage .= (string) $this->options['additionalCommonText'] . ' ';
        }

        $resultMessage .= $message;

        if (!empty($context) && is_array($context)) {
            $resultMessage .= ' Context: ' . PHP_EOL;
            $resultMessage .= $this->options['prepareAsJson'] ? json_encode($context, JSON_UNESCAPED_UNICODE) : print_r($context, true);
        }

        return $resultMessage;
    }


    /**
     * Log a debug message with optional context.
     *
     * @param string $message The debug message.
     * @param array $context Optional context data to log with the message.
     *
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $message = $this->prepareMessage($message, $context, self::LOG_LEVEL_DEBUG);
        $this->log($message, self::LOG_LEVEL_DEBUG);
    }


    /**
     * Log an error message with optional context.
     *
     * @param string $message The error message.
     * @param array $context Optional context data to log with the message.
     *
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $message = $this->prepareMessage($message, $context, self::LOG_LEVEL_ERROR);
        $this->log($message, self::LOG_LEVEL_ERROR);
    }


    /**
     * Create the log directory if it does not exist.
     *
     * @return void
     */
    private function createLogDirectory(): void
    {
        $logDirectory = dirname($this->logFilePath);
        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0777, true);
        }
    }
}
