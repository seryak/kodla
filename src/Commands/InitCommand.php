<?php

namespace Kodla\Commands;

use Kodla\Core\SkillGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'init', description: 'Install kodla skills into the current project')]
class InitCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $generator = new SkillGenerator();
        $skills = $generator->getInstalledSkills();

        if (empty($skills)) {
            $output->writeln('<comment>No skills found.</comment>');
            return Command::FAILURE;
        }

        foreach ($skills as $skill) {
            $output->writeln("<info>Available skill:</info> /$skill");
        }

        $output->writeln('');
        $output->writeln('<comment>Done. Run /kodla:init in Claude Code to set up your AI context.</comment>');

        return Command::SUCCESS;
    }
}
