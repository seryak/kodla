<?php

namespace Kodla\Core;

class ConfigUpdater
{
    private const SECTION_KEYS = [
        'language' => ['ui', 'artifacts', 'technical_terms'],
        'paths'    => ['description', 'architecture', 'rules_file', 'rules'],
        'workflow' => ['auto_create_dirs'],
        'git'      => ['enabled', 'base_branch', 'create_branches', 'branch_prefix'],
    ];

    private string $templatePath;

    public function __construct(string $templatePath)
    {
        $this->templatePath = $templatePath;
    }

    /**
     * @throws \RuntimeException
     */
    public function run(string $targetPath, string $payloadPath): void
    {
        $payloadRaw = $this->readFile($payloadPath, 'payload');
        $payload = $this->parsePayload($payloadRaw);

        if ($payload['mode'] === 'create') {
            $templateText = $this->readFile($this->templatePath, 'template');
            $result = $this->applyCreate($templateText, $payload);
            $dir = dirname($targetPath);
            if ($dir && !is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($targetPath, $result);
        } else {
            if (!file_exists($targetPath)) {
                throw new \RuntimeException("Target file does not exist for merge mode: $targetPath");
            }
            $targetText = $this->readFile($targetPath, 'target');
            $result = $this->applyMerge($targetText, $payload);
            if ($result !== $targetText) {
                file_put_contents($targetPath, $result);
            }
        }
    }

    private function readFile(string $path, string $label): string
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Unable to read $label: $path");
        }
        return $content;
    }

    private function parsePayload(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid payload JSON: ' . json_last_error_msg());
        }
        if (!in_array($data['mode'] ?? null, ['create', 'merge'])) {
            throw new \InvalidArgumentException('Payload mode must be "create" or "merge"');
        }

        $allowed = [];
        foreach (self::SECTION_KEYS as $section => $keys) {
            foreach ($keys as $key) {
                $allowed[] = "$section.$key";
            }
        }

        foreach (['set', 'fillMissing'] as $field) {
            $map = $data[$field] ?? [];
            if (!is_array($map)) {
                throw new \InvalidArgumentException("$field must be an object");
            }
            foreach ($map as $keyPath => $value) {
                if (!in_array($keyPath, $allowed)) {
                    throw new \InvalidArgumentException("Unknown managed key path: $keyPath");
                }
                if (!is_string($value) && !is_bool($value)) {
                    throw new \InvalidArgumentException("Unsupported value type for $keyPath; expected string or boolean");
                }
            }
        }

        return [
            'mode'        => $data['mode'],
            'set'         => $data['set'] ?? [],
            'fillMissing' => $data['fillMissing'] ?? [],
        ];
    }

    private function formatScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }

    private function isIncompleteValue(string $value): bool
    {
        return in_array($value, ['', 'null', 'Null', 'NULL', '~', '""', "''"], true);
    }

    private function splitInlineComment(string $valuePart): array
    {
        $inQuote = null;
        $len = strlen($valuePart);

        for ($i = 0; $i < $len; $i++) {
            $ch = $valuePart[$i];
            $prev = $i > 0 ? $valuePart[$i - 1] : '';

            if ($inQuote !== null) {
                if ($ch === $inQuote && $prev !== '\\') {
                    $inQuote = null;
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inQuote = $ch;
                continue;
            }

            if ($ch === '#') {
                $start = $i;
                while ($start > 0 && ctype_space($valuePart[$start - 1])) {
                    $start--;
                }
                return [
                    rtrim(substr($valuePart, 0, $start)),
                    substr($valuePart, $start),
                ];
            }
        }

        return [rtrim($valuePart), ''];
    }

    private function applyToLines(array $lines, array $set, array $fillMissing): array
    {
        $currentSection = null;
        $updatedKeys = [];

        foreach ($lines as $i => $line) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_-]*):\s*$/', $line, $m)) {
                $currentSection = $m[1];
                continue;
            }

            if ($currentSection !== null && preg_match('/^  ([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $rest = $m[2];
                $keyPath = "$currentSection.$key";

                if (!isset(self::SECTION_KEYS[$currentSection]) || !in_array($key, self::SECTION_KEYS[$currentSection])) {
                    continue;
                }

                [$currentValue, $comment] = $this->splitInlineComment($rest);
                $commentSuffix = $comment !== '' ? "  $comment" : '';

                if (array_key_exists($keyPath, $set)) {
                    $lines[$i] = "  $key: " . $this->formatScalar($set[$keyPath]) . $commentSuffix;
                    $updatedKeys[$keyPath] = true;
                } elseif (array_key_exists($keyPath, $fillMissing)) {
                    $updatedKeys[$keyPath] = true;
                    if ($this->isIncompleteValue($currentValue)) {
                        $lines[$i] = "  $key: " . $this->formatScalar($fillMissing[$keyPath]) . $commentSuffix;
                    }
                }
            }
        }

        return [$lines, $updatedKeys];
    }

    private function insertMissingKeys(array $lines, array $fillMissing, array $updatedKeys): array
    {
        foreach ($fillMissing as $keyPath => $value) {
            if (isset($updatedKeys[$keyPath])) {
                continue;
            }

            [$section, $key] = explode('.', $keyPath, 2);

            $sectionLine = -1;
            $insertAfter = -1;

            foreach ($lines as $i => $line) {
                if (preg_match('/^' . preg_quote($section, '/') . ':\s*$/', $line)) {
                    $sectionLine = $i;
                    continue;
                }
                if ($sectionLine !== -1) {
                    if (preg_match('/^  [A-Za-z_]/', $line)) {
                        $insertAfter = $i;
                    } elseif (preg_match('/^[A-Za-z_]/', $line) && $i > $sectionLine) {
                        break;
                    }
                }
            }

            if ($insertAfter !== -1) {
                array_splice($lines, $insertAfter + 1, 0, ["  $key: " . $this->formatScalar($value)]);
            }
        }

        return $lines;
    }

    private function applyCreate(string $templateText, array $payload): string
    {
        $lines = explode("\n", rtrim(str_replace("\r\n", "\n", $templateText)));
        [$lines] = $this->applyToLines($lines, $payload['set'], []);
        return implode("\n", $lines) . "\n";
    }

    private function applyMerge(string $targetText, array $payload): string
    {
        $lines = explode("\n", rtrim(str_replace("\r\n", "\n", $targetText)));
        [$lines, $updatedKeys] = $this->applyToLines($lines, $payload['set'], $payload['fillMissing']);
        $lines = $this->insertMissingKeys($lines, $payload['fillMissing'], $updatedKeys);
        return implode("\n", $lines) . "\n";
    }
}
