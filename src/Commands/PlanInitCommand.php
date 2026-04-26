<?php

namespace Kodla\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'plan-init', description: 'Initialize a new plan file in .kodla/plans/')]
class PlanInitCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Plan slug (kebab-case)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $cwd = getcwd();
        $plansDir = $cwd . '/.kodla/plans';
        $planFile = $plansDir . '/' . $name . '.md';

        if (!is_dir($plansDir)) {
            mkdir($plansDir, 0755, true);
        }

        if (file_exists($planFile)) {
            $output->writeln("<comment>Plan already exists:</comment> $planFile");
            return Command::SUCCESS;
        }

        $date = date('Y-m-d');
        $template = <<<MD
        # План: $name

        **Дата:** $date

        ## Настройки

        - **Тесты:** -
        - **Ограничения:** -

        ## Задачи

        <!-- Заполняется навыком /kodla:plan -->

        ## Коммит-план

        <!-- Добавляется при 5+ задачах -->
        MD;

        file_put_contents($planFile, $template);

        $output->writeln("<info>Plan initialized:</info> $planFile");

        return Command::SUCCESS;
    }
}
