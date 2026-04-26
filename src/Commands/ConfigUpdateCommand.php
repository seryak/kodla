<?php

namespace Kodla\Commands;

use Kodla\Core\ConfigUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config-update', description: 'Update .kodla/config.yaml preserving comments')]
class ConfigUpdateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Path to target config.yaml')
            ->addOption('payload', null, InputOption::VALUE_REQUIRED, 'Path to JSON payload file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getOption('target');
        $payload = $input->getOption('payload');

        if (!$target || !$payload) {
            $output->writeln('<error>Options --target and --payload are required.</error>');
            return Command::FAILURE;
        }

        // Works both from source and inside a PHAR
        $templatePath = dirname(__DIR__, 2) . '/scripts/config-template.yaml';

        try {
            $updater = new ConfigUpdater($templatePath);
            $updater->run($target, $payload);
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
