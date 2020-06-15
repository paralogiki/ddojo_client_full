<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Service\DeviceConfig;

class DdojoScreenshotCommand extends Command
{
    protected static $defaultName = 'ddojo:screenshot';

    protected function configure()
    {
        $this
            ->setDescription('Submit display screenshot to server')
            #->addArgument('ssFile', InputArgument::REQUIRED, 'Path to screenshot file')
            #->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        #$ssFile = $input->getArgument('ssFile');

        $deviceConfig = new DeviceConfig();
        if (!$deviceConfig->isSetup()) {
          $io->error('Display is not setup');
          return 0;
        }
        $displayId = (int)$deviceConfig->getDisplayId();

        $ssFile = '/tmp/' . time() . '-' . $displayId . '.jpg';
        $cmd = exec('/usr/bin/scrot ' . $ssFile);
        if (!file_exists($ssFile)) {
          $io->error(sprintf("File '%s' was not found.", $ssFile));
          return 0;
        }

        $boundary = '----------------' . microtime(true);
        $headers = 'X-AUTH-TOKEN: ' . $deviceConfig->getApiToken() . "\r\n";
        $headers .= 'Content-Type: multipart/form-data; boundary=' . $boundary;
        $ssFileContents = file_get_contents($ssFile);
        #unlink($ssFile);
        $content  = '--' . $boundary . "\r\n" .
                    'Content-Disposition: form-data; name="uploadfile"; filename="ss.jpg"' . "\r\n" .
                    'Content-Type: image/jpeg' . "\r\n\r\n" .
                    $ssFileContents . "\r\n";
        $content .= '--' . $boundary . "\r\n" .
                    'Content-Disposition: form-data; name="displayId"' . "\r\n\r\n" .
                    $displayId . "\r\n";
        $content .= '--' . $boundary . "--\r\n";
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $content,
            ]
        ];
        $resource_context = stream_context_create($opts);
        # TODO think about moving this somewhere
        $uploadUrl = 'https://www.displaydojo.com/client/v1/screenshot';
        $contents = '';
        try {
            $contents = file_get_contents($uploadUrl, null, $resource_context);
        } catch (ErrorException $e) {
        }
        if (empty($contents)) {
          $io->error("Empty server response");
          return 0;
        }
        $contents = json_decode($contents, true);
        if (!isset($contents['status'])) {
          $io->error("No status returned");
          return 0;
        }
        if (!isset($contents['url'])) {
          $io->error("No url returned");
          return 0;
        }
        $io->success('URL=' . $contents['url']);
    }
}
