<?php

namespace App\Service;

use ErrorException;

class Migration
{
    private $deviceConfig;
    private $projectDir;
    private $migrationsDir;
    private $os;
    private $inited = false;
    private $lastError = 'Undefined error';

    function __construct($deviceConfig, $projectDir)
    {
        $this->deviceConfig = $deviceConfig;
        $this->projectDir = $projectDir;
        $this->migrationsDir = $projectDir . '/migrations';
    }

    public function init() {
        if ($this->inited) return true;
        if (!$this->deviceConfig->isSetup()) {
          $this->lastError = 'Device is not configured';
          return false;
        }
        $this->os = $this->getOs();
        if ($this->os === false) {
          return false;
        }
        $this->inited = true;
        return true;
    }

    public function checkMigrations() {
      if (!file_exists($this->migrationsDir)) {
        $this->lastError('Migrations directory missing = ' . $this->migrationsDir);
        return false;
      }
      $orgDirectory = getcwd();
      chdir($this->migrationsDir);
      $current = 0;
      $currentFile = $this->migrationsDir . '/current.migration';
      if (file_exists($currentFile)) {
        $current = file_get_contents($currentFile);
        if ($current === false) {
          $this->lastError('Failed to get current timestamp currentFile = ' . $currentFile);
          chdir($orgDirectory);
          return false;
        }
        $current = (int)$current;
      }
      $migrationFiles = glob('[0-9]*');
      if (!count($migrationFiles)) {
        chdir($orgDirectory);
        return true;
      }
      $updateCurrent = 0;
      foreach ($migrationFiles as $file) {
        $timeStamp = (int)$file;
        if ($timeStamp > $current) {
          exec('/bin/bash ' . $file);
          $updateCurrent = $timeStamp;
        }
      }
      if ($updateCurrent) {
        $put = file_put_contents($currentFile, $updateCurrent);
        if ($put === false) {
          $this->lastError('Error updating ' . $currentFile . ' with ' . $updateCurrent);
          chdir($orgDirectory);
          return false;
        }
      }
      chdir($orgDirectory);
      return true;
    }

    private function getOs() {
      $uname = '/bin/uname';
      if (!file_exists($uname)) {
        $this->lastError = 'Unable to find command = ' . $uname;
        return false;
      }
      $tmp = exec($uname . ' -s');
      $tmp = trim($tmp);
      if (empty($tmp)) {
        $this->lastError = 'uname -s returned empty value';
        return false;
      }
      $valid_os = ['Linux'];
      if (!in_array($tmp, $valid_os)) {
        $this->lastError = "OS '$tmp' is an invalid Operating System";
        return false;
      }
      return $tmp;
    }

    public function getLastError() {
      return $this->lastError;
    }

}
