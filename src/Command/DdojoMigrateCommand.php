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
use App\Service\Migration;

class DdojoMigrateCommand extends Command
{
    protected static $defaultName = 'ddojo:migrate';
    private $params;

    public function __construct(ParameterBagInterface $params) {
      $this->params = $params;
      parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Run any needed update migrations')
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
        $projectDir = $this->params->get('kernel.project_dir');
        $migrationService = new Migration($deviceConfig, $projectDir);
        $result = $migrationService->checkMigrations();
        if ($result === false) {
          $output->writeln(['Migration failed', 'Last Error: ' . $migrationService->getLastError()]);
          die;
        }
        $output->writeln('Migrations completed.');
    }

}
