<?php

/**
 * Castor task runner for PHP projects.
 *
 * Usage:
 *   castor              — list all tasks
 *   castor <task>       — run a task
 *   castor dev:serve    — run namespaced task
 *
 * Install castor globally:
 *   curl "https://github.com/jolicode/castor/releases/latest/download/castor.linux-amd64.phar" \
 *     -Lo $HOME/.local/bin/castor && chmod u+x $HOME/.local/bin/castor
 *
 * Or per-project via Composer:
 *   composer require castor/castor --dev
 */

declare(strict_types=1);

use Castor\Attribute\AsTask;

use function Castor\capture;
use function Castor\io;
use function Castor\run;

// ---------------------------------------------------------------------------
// Project helpers
// ---------------------------------------------------------------------------

function php_bin(): string
{
    return getenv('PHP') ?: 'php';
}

function composer_bin(): string
{
    return getenv('COMPOSER') ?: 'composer';
}

function project_version(): string
{
    return capture('git describe --tags --always --dirty 2>/dev/null || echo "dev"');
}

// ---------------------------------------------------------------------------
// Development
// ---------------------------------------------------------------------------

namespace dev;

use Castor\Attribute\AsTask;

use function Castor\run;

#[AsTask(description: 'Install project dependencies')]
function install(): void
{
    run([composer_bin(), 'install']);
}

#[AsTask(description: 'Update project dependencies')]
function update(): void
{
    run([composer_bin(), 'update']);
}

#[AsTask(description: 'Start development server')]
function serve(): void
{
    run([php_bin(), '-S', 'localhost:8000', '-t', 'public/']);
}

// ---------------------------------------------------------------------------
// Testing
// ---------------------------------------------------------------------------

namespace test;

use Castor\Attribute\AsTask;

use function Castor\run;

#[AsTask(description: 'Run the test suite')]
function run_tests(): void
{
    run('./vendor/bin/phpunit');
}

#[AsTask(description: 'Run tests with HTML coverage report')]
function coverage(): void
{
    run('./vendor/bin/phpunit --coverage-html coverage/ --coverage-text');
    \Castor\io()->success('Coverage report: coverage/index.html');
}

#[AsTask(description: 'Run a filtered test (usage: castor test:filter --filter=ClassName)')]
function filter(string $filter): void
{
    run(['./vendor/bin/phpunit', '--filter=' . $filter]);
}

// ---------------------------------------------------------------------------
// Code Quality
// ---------------------------------------------------------------------------

namespace quality;

use Castor\Attribute\AsTask;

use function Castor\run;

#[AsTask(description: 'Run linter (dry-run, no changes)')]
function lint(): void
{
    run('./vendor/bin/php-cs-fixer fix --dry-run --diff');
}

#[AsTask(description: 'Fix code style issues')]
function fmt(): void
{
    run('./vendor/bin/php-cs-fixer fix');
}

#[AsTask(description: 'Run PHPStan static analysis')]
function phpstan(): void
{
    run('./vendor/bin/phpstan analyse');
}

#[AsTask(description: 'Run all quality checks (lint + phpstan + test)')]
function check(): void
{
    lint();
    phpstan();
    \test\run_tests();
}

// ---------------------------------------------------------------------------
// Database  (uncomment and adapt if using migrations)
// ---------------------------------------------------------------------------

// namespace db;
//
// use Castor\Attribute\AsTask;
// use function Castor\run;
//
// #[AsTask(description: 'Run database migrations')]
// function migrate(): void
// {
//     run([php_bin(), 'artisan', 'migrate']);
// }
//
// #[AsTask(description: 'Rollback last migration')]
// function rollback(): void
// {
//     run([php_bin(), 'artisan', 'migrate:rollback']);
// }
//
// #[AsTask(description: 'Seed the database')]
// function seed(): void
// {
//     run([php_bin(), 'artisan', 'db:seed']);
// }
//
// #[AsTask(description: 'Drop all tables and re-run migrations + seeds')]
// function fresh(): void
// {
//     \Castor\io()->warning('This will drop ALL tables. Ctrl+C to abort.');
//     sleep(3);
//     run([php_bin(), 'artisan', 'migrate:fresh', '--seed']);
// }

// ---------------------------------------------------------------------------
// Docker  (uncomment if project uses Docker)
// ---------------------------------------------------------------------------

// namespace docker;
//
// use Castor\Attribute\AsTask;
// use function Castor\run;
// use function Castor\capture;
//
// #[AsTask(description: 'Build Docker image')]
// function build(): void
// {
//     $version = \project_version();
//     run(['docker', 'build', '--build-arg', "VERSION={$version}", '-t', \project_name() . ':' . $version, '.']);
// }
//
// #[AsTask(description: 'Start all services (docker compose)')]
// function up(): void
// {
//     run('docker compose up -d');
// }
//
// #[AsTask(description: 'Stop all services')]
// function down(): void
// {
//     run('docker compose down');
// }
//
// #[AsTask(description: 'View container logs')]
// function logs(): void
// {
//     run('docker compose logs -f');
// }

// ---------------------------------------------------------------------------
// CI
// ---------------------------------------------------------------------------

namespace ci;

use Castor\Attribute\AsTask;

#[AsTask(description: 'Run full CI pipeline (install + lint + phpstan + test)')]
function run_pipeline(): void
{
    \dev\install();
    \quality\lint();
    \quality\phpstan();
    \test\run_tests();
}

// ---------------------------------------------------------------------------
// Maintenance
// ---------------------------------------------------------------------------

namespace maintenance;

use Castor\Attribute\AsTask;

use function Castor\run;

#[AsTask(description: 'Remove generated files and caches')]
function clean(): void
{
    run('rm -rf vendor/ coverage/');
}
