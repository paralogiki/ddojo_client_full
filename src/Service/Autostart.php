<?php

namespace App\Service;

use ErrorException;

class Autostart
{
    private $deviceConfig;
    private $projectDir;
    private $autoStartFile = '/etc/xdg/lxsession/LXDE/autostart';
    private $os;
    private $inited = false;
    private $lastError = 'Undefined error';

    function __construct($deviceConfig, $projectDir)
    {
        $this->deviceConfig = $deviceConfig;
        $this->projectDir = $projectDir;
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

    public function setupAutostart() {
      if ($this->os == 'Linux') {
        $lxdeIsNew = 0;
        $lxdeSkeletonFile = '/etc/xdg/lxsession/LXDE-pi/autostart';
        $lxdeAutoStartFile = '/home/pi/.config/lxsession/LXDE-pi/autostart';
        if (!file_exists($lxdeAutoStartFile)) {
          $lxdeDir = dirname($lxdeAutoStartFile);
          if (!file_exists($lxdeDir)) {
            $mkdirResult = mkdir(dirname($lxdeAutoStartFile), 0700, true);
            if ($mkdirResult === false) {
              $this->lastError = 'Unable to make directory: ' . dirname($lxdeAutoStartFile);
              return false;
            }
          }
          if (file_exists($lxdeSkeletonFile)) {
            $copyResult = copy($lxdeSkeletonFile, $lxdeAutoStartFile);
            if ($copyResult === false) {
              $this->lastError = 'Unable to copy skeleton: ' . $lxdeSkeletonFile;
              return false;
            }
          } else {
            $touchResult = touch($lxdeAutoStartFile);
            if ($touchResult === false) {
              $this->lastError = 'Unable to touch file: ' . dirname($lxdeAutoStartFile);
              return false;
            }
          }
        }
        $launchScript = $this->projectDir . '/scripts/' . 'launch.pi.sh';
        if (!file_exists($launchScript)) {
          $this->lastError = 'Unable to find file: ' . $launchScript;
          return false;
        }
        $contents = file($lxdeAutoStartFile);
        $outContents = '';
        $hasAutoStartAlready = 0;
        foreach ($contents as $line) {
          if (substr_count($line, 'ddojo_local/scripts/launch')) $hasAutoStartAlready = 1;
          $outContents .= $line;
        }
        if ($hasAutoStartAlready) {
          return true;
        }
        if (empty($outContents)) {
          $outContents = "@" . $launchScript . "\n";
        } else {
          $outContents = trim($outContents);
          $outContents .= "\n" . "@" . $launchScript . "\n";
        }
        $tmpWritten = 0;
        try {
          $tmp = file_put_contents($lxdeAutoStartFile, $outContents);
          if ($tmp) $tmpWritten = 1;
        } catch (ErrorException $e) {
          $this->lastError = $e->getMessage();
        }
        if (!$tmpWritten) {
          return false;
        }
        # double-check written
        $contents = file_get_contents($lxdeAutoStartFile);
        if (!substr_count($contents, 'ddojo_local/scripts/launch')) {
          $this->lastError = 'Failed to update file: ' . $lxdeAutoStartFile;
          return false;
        }
        return true;
      }
      return false;
    }

    public function disableAutostart() {
      if ($this->os == 'Linux') {
        $lxdeAutoStartFile = '/home/pi/.config/lxsession/LXDE-pi/autostart';
        if (!file_exists($lxdeAutoStartFile)) {
          return true;
        }
        $contents = file($lxdeAutoStartFile);
        $outContents = '';
        foreach ($contents as $line) {
          if (substr_count($line, 'ddojo_local/scripts/launch')) continue;
          $outContents .= $line;
        }
        $tmpWritten = 0;
        try {
          $tmp = file_put_contents($lxdeAutoStartFile, $outContents);
          if ($tmp) $tmpWritten = 1;
        } catch (ErrorException $e) {
          $this->lastError = $e->getMessage();
        }
        if (!$tmpWritten) {
          return false;
        }
        return true;
      }
      return false;
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
