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

    public function __construct(ParameterBagInterface $params) {
      $this->params = $params;
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
        $logReportFile = '/tmp/ddcc.report.log';
        if (file_exists($logReportFile) && filesize($logReportFile) > 1000000) {
          unlink($logReportFile);
        }
        $logFile = '/tmp/ddcc.log';
        if (!file_exists($logFile)) {
          $io->error('logFile not found');
          die;
        }
        # look for FATAL in log
        $cmd = '/bin/grep FATAL ' . $logFile . ' | /usr/bin/wc -l';
        exec($cmd);
        $fatalCount = (int)exec($cmd);
        if (!$fatalCount) {
          # clear log file for next run
          $cmd = '/bin/cat /dev/null > ' . $logFile;
          exec($cmd);
          $io->success('no FATAL found');
          die;
        }
        $cmd = '/bin/grep -a FATAL ' . $logFile . ' >> ' . $logReportFile;
        exec($cmd);
        # restart client via refresh
        putenv('DISPLAY=:0');
        # only restart if we have internet
        $restartClient = $this->checkInternet();
        if ($restartClient) {
          # clear log for next run
          $cmd = '/bin/cat /dev/null > ' . $logFile;
          exec($cmd);
          $projectDir = $this->params->get('kernel.project_dir');
          $relaunchScript = $projectDir . '/scripts/launch.pi.sh';
          if (!file_exists($relaunchScript)) {
            $io->error('unable to relaunch missing ' . $relaunchScript);
            die;
          }
          $cmd = $relaunchScript . ' > /dev/null 2>&1 &';
          #$cmd = '/usr/bin/xdotool windowactivate --sync $(/usr/bin/xdotool search --onlyvisible --class chromium-browser | /usr/bin/tail -1) key F5';
          exec($cmd);
          $io->success('restarting client');
        } else {
          $io->error('wanted to restart but no internet');
        }
    }

    private function checkInternet() {
      $cmd = '/bin/ping -c 1 displaydojo.com > /dev/null 2>&1';
      $returnVar = null;
      $output = null;
      $result = exec($cmd, $output, $returnVar);
      return $returnVar === 0;
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

}
