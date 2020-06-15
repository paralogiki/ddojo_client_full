<?php
namespace App\Service;

use ErrorException;

class DeviceConfig
{
    private $config = [];
    private $configFile;
    private $displayId = null;
    private $apiToken = null;
    private $isSetup = 0;

    function __construct()
    {
        $this->configFile = $_SERVER['HOME'] . '/.config/ddojo/ddojo.conf';
        if (file_exists($this->configFile)) {
            $this->config = parse_ini_file($this->configFile);
        }
        $this->displayId = $this->config['DISPLAY_ID'] ?? null;
        $this->displayKey = $this->config['DISPLAY_KEY'] ?? null;
        $this->apiToken = $this->config['API_TOKEN'] ?? null;
        $this->checkIsSetup();
    }

    function isSetup() {
        return $this->isSetup;
    }

    function getConfig($case = 'lower') {
        if ($case == 'lower') {
            return array_change_key_case($this->config, CASE_LOWER);
        }
        return $this->config;
    }

    function getDisplayId() {
        return $this->displayId;
    }

    function setDisplayId($displayId) {
        $this->displayId = $displayId;
        $this->config['DISPLAY_ID'] = $this->displayId;
        $this->checkIsSetup();
    }

    function getDisplayKey() {
        return $this->displayKey;
    }

    function setDisplayKey($displayKey) {
        $this->displayKey = $displayKey;
        $this->config['DISPLAY_KEY'] = $this->displayKey;
        $this->checkIsSetup();
    }

    function getApiToken() {
        return $this->apiToken;
    }

    function setApiToken($apiToken) {
        $this->apiToken = $apiToken;
        $this->config['API_TOKEN'] = $this->apiToken;
        $this->checkIsSetup();
    }

    function checkIsSetup() {
        if ($this->displayId && $this->apiToken && $this->displayKey) {
            $this->isSetup = 1;
        }
        return $this->isSetup;
    }

    function getConfigFile() {
        return $this->configFile;
    }

    function updateConfig() {
        $config_contents = '';
        foreach ($this->config as $key => $val) {
            $config_contents .= sprintf('%s="%s"', $key, $val) . PHP_EOL;
        }
        if (!file_exists($this->configFile)) {
            if (!is_dir(dirname($this->configFile))) {
                try {
                    $make_directory = mkdir(dirname($this->configFile), 0700, true);
                } catch (ErrorException $e) {
                    $make_directory = false;
                }
            }
        }
        try {
            $wrote_config = file_put_contents($this->configFile, $config_contents);
        } catch (ErrorException $e) {
            $wrote_config = false;
        }
        if ($wrote_config === false) {
            return false;
        }
        try {
            chmod($this->configFile, 0600);
        } catch (ErrorException $e) {
            // Do we care
        }
        return true;
    }

    function setConfig($config) {
        $config = array_change_key_case($config, CASE_UPPER);
        $this->config = array_merge($this->config, $config);
        $this->checkIsSetup();
    }

}
