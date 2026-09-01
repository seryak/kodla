#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="$SCRIPT_DIR/build"
PHAR="/tmp/kodla-build.phar"
OUTPUT="$BUILD_DIR/kodla"
CDN="https://dl.static-php.dev/static-php-cli/common"

PHP_MINOR=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;")
OS=$(uname -s | tr '[:upper:]' '[:lower:]')
ARCH=$(uname -m)

if [[ "$ARCH" == "x86_64" ]]; then
    ARCH_SLUG="x86_64"
elif [[ "$ARCH" == "aarch64" || "$ARCH" == "arm64" ]]; then
    ARCH_SLUG="aarch64"
else
    echo "Unsupported architecture: $ARCH"
    exit 1
fi

if [[ "$OS" == "linux" ]]; then
    OS_SLUG="linux"
elif [[ "$OS" == "darwin" ]]; then
    OS_SLUG="macos"
else
    echo "Unsupported OS: $OS"
    exit 1
fi

echo "Building phar..."
SCRIPT_DIR="$SCRIPT_DIR" php -d phar.readonly=0 -- <<'PHP'
<?php

$pharFile = '/tmp/kodla-build.phar';
$root = getenv('SCRIPT_DIR');

if (file_exists($pharFile)) unlink($pharFile);

$phar = new Phar($pharFile);
$phar->startBuffering();

// PHP source files (exclude build/, .git/, .claude/)
$phar->buildFromDirectory($root, '/^(?!.*\/(build|\.git|\.claude)\/).*\.(php)$/');

// skills: only .md files
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/skills', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'md') {
        $rel = 'skills/' . ltrim(str_replace($root . '/skills', '', $file->getPathname()), '/');
        $phar->addFile($file->getPathname(), $rel);
    }
}

// scripts: .php and .yaml files
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/scripts', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->isFile() && in_array($file->getExtension(), ['php', 'yaml'])) {
        $rel = 'scripts/' . ltrim(str_replace($root . '/scripts', '', $file->getPathname()), '/');
        $phar->addFile($file->getPathname(), $rel);
    }
}

// vendor
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/vendor', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $rel = 'vendor/' . ltrim(str_replace($root . '/vendor', '', $file->getPathname()), '/');
        $phar->addFile($file->getPathname(), $rel);
    }
}

$stub = <<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('kodla.phar');
require 'phar://kodla.phar/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Kodla\Commands\InitCommand;
use Kodla\Commands\ConfigUpdateCommand;
use Kodla\Commands\PlanInitCommand;

$app = new Application('kodla', '1.0.0');
$app->add(new InitCommand());
$app->add(new ConfigUpdateCommand());
$app->add(new PlanInitCommand());
$app->run();
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();
echo "Phar ready\n";
PHP

echo "Looking for micro.sfx: PHP $PHP_MINOR on $OS_SLUG-$ARCH_SLUG..."
MICRO_FILE=$(curl -sL "$CDN/" \
    | grep -o "php-${PHP_MINOR}\.[0-9]*-micro-${OS_SLUG}-${ARCH_SLUG}\.tar\.gz" \
    | sort -V | tail -1)

if [[ -z "$MICRO_FILE" ]]; then
    echo "No pre-built micro found for PHP $PHP_MINOR on $OS_SLUG-$ARCH_SLUG"
    echo "Check available builds at: $CDN/"
    rm -f "$PHAR"
    exit 1
fi

MICRO_CACHE="$BUILD_DIR/${MICRO_FILE}"

if [[ ! -f "$MICRO_CACHE" ]]; then
    echo "Downloading $MICRO_FILE..."
    curl -L --progress-bar "$CDN/$MICRO_FILE" -o "$MICRO_CACHE"
fi

echo "Extracting micro.sfx..."
tar -xzf "$MICRO_CACHE" -C "$BUILD_DIR" micro.sfx

echo "Combining micro.sfx + phar..."
cat "$BUILD_DIR/micro.sfx" "$PHAR" > "$OUTPUT"
chmod +x "$OUTPUT"

rm "$BUILD_DIR/micro.sfx"
rm "$PHAR"

SIZE=$(du -sh "$OUTPUT" | cut -f1)
echo "Built: build/kodla ($SIZE) — PHP $PHP_MINOR static binary"
