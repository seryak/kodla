#!/usr/bin/env php
<?php

/**
 * PHP port of update-config.mjs
 * Updates .kodla/config.yaml preserving comments and formatting.
 *
 * Usage:
 *   php update-config.php --template config-template.yaml --target .kodla/config.yaml --payload payload.json
 *
 * Exit codes:
 *   0 - success
 *   1 - unsafe structure in target file
 *   2 - I/O error
 *   3 - invalid arguments or payload
 */

const SECTION_KEYS = [
    'language' => ['ui', 'artifacts', 'technical_terms'],
    'paths'    => [
        'description', 'architecture', 'rules_file', 'rules', 'plans', 'research', 'roadmap',
        'fix_plan', 'patches', 'evolutions', 'references', 'docs', 'specs',
    ],
    'workflow' => ['auto_create_dirs', 'verify_mode'],
    'git'      => ['enabled', 'base_branch', 'create_branches', 'branch_prefix', 'commit_language', 'skip_push_after_commit'],
    'rules'    => ['base'],
];

function isManagedKeyPath(string $keyPath): bool
{
    if (preg_match('/^rules\.[a-z][a-z0-9_-]*$/', $keyPath)) {
        return true;
    }

    [$section, $key] = explode('.', $keyPath, 2) + [null, null];

    return $section !== null
        && $key !== null
        && isset(SECTION_KEYS[$section])
        && in_array($key, SECTION_KEYS[$section], true);
}

function fail(int $code, string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

function parseArgs(array $argv): array
{
    $args = [];
    $argc = count($argv);

    for ($i = 0; $i < $argc; $i++) {
        $flag = $argv[$i];
        $value = $argv[$i + 1] ?? null;

        if (!str_starts_with($flag, '--')) {
            fail(3, "Unknown argument: $flag");
        }
        if ($value === null || str_starts_with($value, '--')) {
            fail(3, "Missing value for $flag");
        }

        match ($flag) {
            '--template' => $args['template'] = $value,
            '--target'   => $args['target'] = $value,
            '--payload'  => $args['payload'] = $value,
            default      => fail(3, "Unknown argument: $flag"),
        };
        $i++;
    }

    if (!isset($args['template'], $args['target'], $args['payload'])) {
        fail(3, 'Usage: update-config.php --template <path> --target <path> --payload <path>');
    }

    return $args;
}

function readTextFile(string $path, string $label): string
{
    $content = @file_get_contents($path);
    if ($content === false) {
        fail(2, "Unable to read $label: $path");
    }
    return $content;
}

function parsePayload(string $raw): array
{
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail(3, 'Invalid payload JSON: ' . json_last_error_msg());
    }
    if (!in_array($data['mode'] ?? null, ['create', 'merge'])) {
        fail(3, 'Payload mode must be "create" or "merge"');
    }

    foreach (['set', 'fillMissing'] as $field) {
        $map = $data[$field] ?? [];
        if (!is_array($map)) {
            fail(3, "$field must be an object");
        }
        foreach ($map as $keyPath => $value) {
            if (!isManagedKeyPath($keyPath)) {
                fail(3, "Unknown managed key path: $keyPath");
            }
            if (!is_string($value) && !is_bool($value)) {
                fail(3, "Unsupported value type for $keyPath; expected string or boolean");
            }
        }
    }

    return [
        'mode'        => $data['mode'],
        'set'         => $data['set'] ?? [],
        'fillMissing' => $data['fillMissing'] ?? [],
    ];
}

function formatScalar(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    return (string) $value;
}

function isIncompleteValue(string $value): bool
{
    return in_array($value, ['', 'null', 'Null', 'NULL', '~', '""', "''"], true);
}

/**
 * Split inline comment from value part: "en  # Default" → ["en", "  # Default"]
 */
function splitInlineComment(string $valuePart): array
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

/**
 * Apply set/fillMissing to YAML text lines, preserving comments and formatting.
 * Returns updated lines array.
 */
function applyToLines(array $lines, array $set, array $fillMissing): array
{
    $currentSection = null;
    $updatedKeys = [];

    foreach ($lines as $i => $line) {
        // Top-level section header (no leading space, ends with colon)
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_-]*):\s*$/', $line, $m)) {
            $currentSection = $m[1];
            continue;
        }

        // Managed key: exactly two spaces + key + colon
        if ($currentSection !== null && preg_match('/^  ([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$/', $line, $m)) {
            $key = $m[1];
            $rest = $m[2];
            $keyPath = "$currentSection.$key";

        if (!isManagedKeyPath($keyPath)) {
                continue;
            }

            [$currentValue, $comment] = splitInlineComment($rest);
            $commentSuffix = $comment !== '' ? "  $comment" : '';

            if (array_key_exists($keyPath, $set)) {
                $lines[$i] = "  $key: " . formatScalar($set[$keyPath]) . $commentSuffix;
                $updatedKeys[$keyPath] = true;
            } elseif (array_key_exists($keyPath, $fillMissing)) {
                $updatedKeys[$keyPath] = true; // mark found regardless
                if (isIncompleteValue($currentValue)) {
                    $lines[$i] = "  $key: " . formatScalar($fillMissing[$keyPath]) . $commentSuffix;
                }
            }
        }
    }

    return [$lines, $updatedKeys];
}

/**
 * Insert missing keys from fillMissing that weren't found in existing file.
 * Finds the section and appends key after the last known key in that section.
 */
function insertMissingKeys(array $lines, array $fillMissing, array $updatedKeys): array
{
    foreach ($fillMissing as $keyPath => $value) {
        if (isset($updatedKeys[$keyPath])) {
            continue;
        }

        [$section, $key] = explode('.', $keyPath, 2);

        // Find section and insert after it
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

        if ($sectionLine === -1) {
            if ($lines !== [] && end($lines) !== '') {
                $lines[] = '';
            }
            $lines[] = "$section:";
            $lines[] = "  $key: " . formatScalar($value);
        } elseif ($insertAfter !== -1) {
            array_splice($lines, $insertAfter + 1, 0, ["  $key: " . formatScalar($value)]);
        }

        $updatedKeys[$keyPath] = true;
    }

    return $lines;
}

function applyCreate(string $templateText, array $payload): string
{
    $lines = explode("\n", rtrim(str_replace("\r\n", "\n", $templateText)));
    [$lines, $updatedKeys] = applyToLines($lines, $payload['set'], []);
    $lines = insertMissingKeys($lines, $payload['set'], $updatedKeys);
    return implode("\n", $lines) . "\n";
}

function applyMerge(string $targetText, array $payload): string
{
    $lines = explode("\n", rtrim(str_replace("\r\n", "\n", $targetText)));
    [$lines, $updatedKeys] = applyToLines($lines, $payload['set'], $payload['fillMissing']);
    $missingValues = array_merge($payload['fillMissing'], $payload['set']);
    $lines = insertMissingKeys($lines, $missingValues, $updatedKeys);
    return implode("\n", $lines) . "\n";
}

// ---- Main ----

$args = parseArgs(array_slice($argv, 1));
$payloadRaw = readTextFile($args['payload'], 'payload');
$payload = parsePayload($payloadRaw);

if ($payload['mode'] === 'create') {
    $templateText = readTextFile($args['template'], 'template');
    $result = applyCreate($templateText, $payload);
    $dir = dirname($args['target']);
    if ($dir && !is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($args['target'], $result);
} else {
    if (!file_exists($args['target'])) {
        fail(2, "Target file does not exist for merge mode: {$args['target']}");
    }
    $targetText = readTextFile($args['target'], 'target');
    $result = applyMerge($targetText, $payload);
    if ($result !== $targetText) {
        file_put_contents($args['target'], $result);
    }
}

exit(0);
