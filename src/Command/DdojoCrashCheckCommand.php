<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Service\DeviceConfig;

class DdojoCrashCheckCommand extends Command
{
    protected static $defaultName = 'ddojo:crashcheck';
    private $params;
    private $deviceConfig;
    private $maxStrLen = 8192;
    private $maxLogSize = 1048576;
    private $logFile = '/tmp/ddcc.log';
    private $reportUrl = 'https://www.displaydojo.com/client/v1/report/error';

    public function __construct(ParameterBagInterface $params, DeviceConfig $deviceConfig) {
      $this->params = $params;
      $this->deviceConfig = $deviceConfig;
      parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Check display screenshot for crash icons and reboot if needed')
            #->addArgument('ssFile', InputArgument::REQUIRED, 'Path to screenshot file')
            #->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        # check for chromium
        $cmd = '/usr/bin/pgrep chromium | /usr/bin/wc -l';
        $chromiumCheck = (int)exec($cmd);
        if (!$chromiumCheck) {
          # chromium not running
          $io->success('chromium not running');
          die;
        }
        #$logReportFile = '/tmp/ddcc.report.log';
        #if (file_exists($logReportFile) && filesize($logReportFile) > 1000000) {
        #  unlink($logReportFile);
        #}
        if (!file_exists($this->logFile)) {
          $io->error('logFile not found');
          die;
        }
        $projectDir = $this->params->get('kernel.project_dir');
        $grepFile = $projectDir . '/contrib/ddojocrashcheck.grep';
        $ignoreFile = $projectDir . '/contrib/ddojocrashcheck.ignore';
        if (!file_exists($grepFile)) {
          $io->error('missing file = ' . $grepFile);
          die;
        }
        if (!file_exists($ignoreFile)) {
          $io->error('missing file = ' . $ignoreFile);
          die;
        }
        $errorLines = [];
        $hasGray = false;
        $grayResults = 0;
        $restartBecauseOfGray = 0;
        if (function_exists('imagecreatefromjpeg')) {
          $grayResults = $this->checkForGray();
          if ($grayResults !== false) {
            $hasGray = true;
            $errorLines[] = "ERROR:Display has gray pixels ratio over threshold, percentage = " . round($grayResults * 100, 2) . '%';
            $errorLines[] = "FATAL:Display has has been restarted automatically";
            $restartBecauseOfGray = 1;
          }
        }
        $cmd = '/bin/grep -af ' . $grepFile . ' ' . $this->logFile . ' | /bin/grep -avf ' . $ignoreFile;
        exec($cmd, $errorLines);
        $tmpLines = [];
        foreach($errorLines as $line) {
          $line = trim($line);
          if (empty($line)) continue;
          $tmpLines[] = $line;
        }
        if (!count($tmpLines)) {
          $io->success('tmpLines was size 0');
          $this->checkLogSize();
          die;
        }
        $errorLines = $tmpLines;
        # restart client via refresh
        putenv('DISPLAY=:0');
        # only restart if we have internet
        $checkInternet = $this->checkInternet();
        $restartClient = 0; // no restarting automatically yet
        if ($restartBecauseOfGray && $checkInternet) $restartClient = 1;
        $reportLog = 1;
        if ($reportLog) {
          if (!$this->deviceConfig->isSetup()) {
            $io->error('wanted to reportLog but device is not setup');
            die;
          }
          $chromiumVersion = exec('/usr/bin/chromium-browser --version');
          array_unshift($errorLines, '----');
          if (!empty($chromiumVersion)) {
            $chromiumVersion = 'Chromium version: ' . $chromiumVersion;
          } else {
            $chromiumVersion = 'Chromium version: unknown';
          }
          array_unshift($errorLines, $chromiumVersion);
          $ddojoClientVersion = 'DDOJO Client Version: v' . $this->params->get('app.clientVersion');
          array_unshift($errorLines, $ddojoClientVersion);
          $postData = [
            'displayId' => $this->deviceConfig->getDisplayId(),
            'errorLines' => substr(implode("\n", $errorLines), 0, $this->maxStrLen),
          ];
          $projectDir = $this->params->get('kernel.project_dir');
          $screenShotCmd = $projectDir . '/bin/console ddojo:screenshot';
          $screenShotLines = [];
          exec($screenShotCmd, $screenShotLines);
          $screenShotUrl = '';
          foreach ($screenShotLines as $line) {
            $matches = [];
            if (preg_match('/^ \[OK\] URL=(.*)$/', $line, $matches)) {
              $screenShotUrl = $matches[1];
            }
          }
          $postData['screenShotUrl'] = $screenShotUrl;
          $opts = [
              'http' => [
                'header' => [
                  'X-AUTH-TOKEN: ' . $this->deviceConfig->getApiToken(),
                  'Content-type: application/x-www-form-urlencoded',
                ],
                'method' => 'POST',
                'content' => http_build_query($postData),
              ]
          ];
          $resource_context = stream_context_create($opts);
          $contents = '';
          try {
              $contents = file_get_contents($this->reportUrl, null, $resource_context);
          } catch (ErrorException $e) {
          }
          if (empty($contents)) {
            $io->error('Failed to report error to ' . $this->reportUrl);
            die;
          }
          $response = json_decode($contents, true);
          if ($response === null) {
            $io->error("unexpected response");
            die;
          }
          if (!isset($response['status'])) {
            $io->error("response has no status");
            die;
          }
          if ($response['status'] == 'success') {
            # clear log for next run
            $cmd = '/bin/cat /dev/null > ' . $this->logFile;
            exec($cmd);
            $io->success('reported error to server');
          } else {
            $io->error("status is not success, message = " . $response['message'] ?? 'no message');
          }
        }
        if ($restartClient) {
          # clear log for next run
          $cmd = '/bin/cat /dev/null > ' . $this->logFile;
          exec($cmd);
          $relaunchScript = $projectDir . '/scripts/launch.pi.sh';
          if (!file_exists($relaunchScript)) {
            $io->error('unable to relaunch missing ' . $relaunchScript);
            die;
          }
          $cmd = $relaunchScript . ' > /dev/null 2>&1 &';
          #$cmd = '/usr/bin/xdotool windowactivate --sync $(/usr/bin/xdotool search --onlyvisible --class chromium-browser | /usr/bin/tail -1) key F5';
          exec($cmd);
          $io->success('restarting client');
        }
    }

    private function checkInternet() {
      $cmd = '/bin/ping -c 1 displaydojo.com > /dev/null 2>&1';
      $returnVar = null;
      $output = null;
      $result = exec($cmd, $output, $returnVar);
      return $returnVar === 0;
    }

    private function checkForGray() {
      $deviceConfig = new DeviceConfig();
      if (!$deviceConfig->isSetup()) {
        return false;
      }
      $displayId = (int)$deviceConfig->getDisplayId();
      putenv('DISPLAY=:0');
      $ssFile = '/tmp/' . time() . '-' . $displayId . '.jpg';
      $cmd = exec('/usr/bin/scrot ' . $ssFile);
      if (!file_exists($ssFile)) {
        return false;
      }
      $image = imagecreatefromjpeg($ssFile);
      if ($image === false) {
        if (file_exists($ssFile)) unlink($ssFile);
        return false;
      }
      $w = imagesx($image);
      $h = imagesy($image);
      $colors = [];
      $totalPixels = 0;
      for ($x = 0; $x < $w; $x += 1) {
        for ($y = 0; $y < $h; $y += 1) {
          $rgb = imagecolorat($image, $x, $y);
          if (!isset($colors[$rgb])) $colors[$rgb] = 0;
          $colors[$rgb] += 1;
          $totalPixels++;
        }
      }
      //arsort($colors); # only needed for debuging
      $badColorGray = 8947848; # dark gray #888888
      $badColorLightGray = 15658734; # light gray #eeeeee
      $badColorLightGray2 = 14540253; # light gray #dddddd
      $threshHold = 0.1;
      $absMax = 200;
      $totalHits = 0;
      foreach ($colors as $color => $count) {
        $diff1 = abs($color - $badColorGray);
        $diff2 = abs($color - $badColorLightGray);
        $diff3 = abs($color - $badColorLightGray2);
        if ($diff1 < $absMax || $diff2 < $absMax || $diff3 < $absMax) {
          $totalHits += $count;
        }
      }
      $ratio = $totalHits / $totalPixels;
      if (file_exists($ssFile)) unlink($ssFile);
      if ($ratio >= $threshHold) return $ratio;
      return false;
    }

    private function checkForIcons($ssFile, $verbose = 0) {
      if (!file_exists($ssFile)) {
        return 'Missing ssFile = ' . $ssFile;
      }
      $projectDir = $this->params->get('kernel.project_dir');
      $frownFile = $projectDir . '/contrib/crashcheck-frown.png';
      if (!file_exists($frownFile)) {
        return 'Missing frownFile = ' . $frownFile;
      }
      $frown = imagecreatefrompng($frownFile);
      if ($frown === false) {
        return 'Unable to imagecreatefrompng(' . $frown . ')';
      }
      $frownW = imagesx($frown);
      $frownH = imagesy($frown);
      $map = $this->getMap($frown, 0, 0, $frownW, $frownH, $frownW, $frownH);
      if (!$map['colorsTotal']) {
        return 'Error map count is 0';
      }
      $map0 = $map['firstColor'];
      $image = imagecreatefromjpeg($ssFile);
      if ($image === false) {
        return 'Unable to imagecreatefrompng(' . $ssFile . ')';
      }
      $w = imagesx($image);
      $h = imagesy($image);
      $maxX = $w - $frownW;
      $maxY = $h - $frownH;
      $highestThreshold = 0;
      $highestX = 0;
      $highestY = 0;
      $threshold = 0.99;
      $step = 12;
      for ($x = 0; $x < $maxX; $x += $step) {
        for ($y = 0; $y < $maxY; $y += $step) {
          $rgb = imagecolorat($image, $x, $y);
          if ($rgb == $map0) {
            if ($verbose) print "hit map0 at $x, $y" . PHP_EOL;
            $chk = $this->getMap($image, $x, $y, $frownW, $frownH, $w, $h);
            $compare = $this->compareMaps($map, $chk);
            if ($compare > $highestThreshold) {
              $highestThreshold = $compare;
              $highestX = $x;
              $highestY = $y;
            }
            if ($compare > $threshold) {
              return "FOUND frown at $x, $y, $compare";
            }
          }
        }
      }
      $response = 'GOOD';
      if ($verbose) $response .= " highestThreshold = $highestThreshold, $highestX, $highestY";
      return $response;
    }

    private function getMap($image, $x, $y, $w, $h, $maxW, $maxH) {
      $firstColor = null;
      $colors = [];
      $colorsTotal = 0;
      #$map = [];
      $w = $x + $w;
      $h = $y + $h;
      if ($w > $maxW) $w = $maxW;
      if ($h > $maxH) $h = $maxH;
      for ($i = $x; $i < $w; $i++) {
        for ($j = $y; $j < $h; $j++) {
          #print "$i, $j\n";
          $rgb = imagecolorat($image, $i, $j);
          if (!isset($colors[$rgb])) $colors[$rgb] = 0;
          $colors[$rgb] += 1;
          $colorsTotal += $rgb;
          if (is_null($firstColor)) $firstColor = $rgb;
          #$map[] = $rgb;
        }
      }
      return compact('firstColor', 'colors', 'colorsTotal');
      #return compact('map', 'colors', 'colorsTotal');
    }

    private function compareMaps($map1, $map2) {
      if ($map1['colorsTotal'] == $map2['colorsTotal']) return 1;
      $colorsTotal1 = $map1['colorsTotal'];
      $colorsTotal2 = $map2['colorsTotal'];
      $diff = $colorsTotal2 / $colorsTotal1;
      return $diff;
    }

    private function logToFile($logFile, $string) {
      file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $string . PHP_EOL, FILE_APPEND);
    }

    private function checkLogSize() {
      if (file_exists($this->logFile) && filesize($this->logFile) > $this->maxLogSize) {
        $cmd = '/bin/cat /dev/null > ' . $logFile;
        exec($cmd);
      }
    }

}
