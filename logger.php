<?php
// Simple Logger
class Logger {
    private $logFile;

    public function __construct($logFile) {
        $this->logFile = $logFile;
    }

    public function log($message) {
        $timestamp = date('c');
        file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }
}