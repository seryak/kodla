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
        $cwd = getcwd();
        $generator = new SkillGenerator($cwd);
        $generator->generate();

        $skills = $generator->getInstalledSkills();
        $commandsDir = $generator->getCommandsDir();

        foreach ($skills as $skill) {
            $output->writeln("<info>Installed:</info> $commandsDir/$skill.md");
        }

        if (empty($skills)) {
            $output->writeln('<comment>No skills found to install.</comment>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('<comment>Done. Run /kodla:init in Claude Code to set up your AI context.</comment>');

        return Command::SUCCESS;
    }
}
