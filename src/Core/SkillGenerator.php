<?php

namespace Kodla\Core;

class SkillGenerator
{
    private string $commandsDir;
    private string $skillsDir;

    public function __construct(string $workingDir)
    {
        $this->commandsDir = $workingDir . '/.claude/commands';
        // Works both from source and from inside a PHAR
        $this->skillsDir = dirname(__DIR__, 2) . '/skills';
    }

    public function generate(): void
    {
        if (!is_dir($this->commandsDir)) {
            mkdir($this->commandsDir, 0755, true);
        }

        foreach ($this->getSkills() as $commandName => $skillFile) {
            if (file_exists($skillFile)) {
                file_put_contents(
                    $this->commandsDir . '/' . $commandName . '.md',
                    file_get_contents($skillFile)
                );
            }
        }
    }

    public function getInstalledSkills(): array
    {
        $result = [];
        foreach ($this->getSkills() as $commandName => $skillFile) {
            if (file_exists($skillFile)) {
                $result[] = $commandName;
            }
        }
        return $result;
    }

    public function getCommandsDir(): string
    {
        return $this->commandsDir;
    }

    private function getSkills(): array
    {
        return [
            'kodla:init' => $this->skillsDir . '/kodla-init/SKILL.md',
            'kodla:plan' => $this->skillsDir . '/kodla-plan/SKILL.md',
        ];
    }
}
