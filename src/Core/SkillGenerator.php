<?php

namespace Kodla\Core;

class SkillGenerator
{
    private string $skillsDir;

    public function __construct()
    {
        // Works both from source and from inside a PHAR
        $this->skillsDir = dirname(__DIR__, 2) . '/skills';
    }

    public function getInstalledSkills(): array
    {
        $result = [];
        foreach ($this->getSkills() as $skillName => $skillFile) {
            if (file_exists($skillFile)) {
                $result[] = $skillName;
            }
        }
        return $result;
    }

    private function getSkills(): array
    {
        return [
            'kodla:init'           => $this->skillsDir . '/kodla-init/SKILL.md',
            'kodla:plan'           => $this->skillsDir . '/kodla-plan/SKILL.md',
            'kodla:improve'        => $this->skillsDir . '/kodla-improve/SKILL.md',
            'kodla:research'       => $this->skillsDir . '/kodla-research/SKILL.md',
            'kodla:implement-plan' => $this->skillsDir . '/kodla-implement-plan/SKILL.md',
            'kodla:check-tasks'    => $this->skillsDir . '/kodla-check-tasks/SKILL.md',
            'kodla:evolve'         => $this->skillsDir . '/kodla-evolve/SKILL.md',
        ];
    }
}
