#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php. Run composer install before the proof tool.\n");
    exit(1);
}

require $autoload;

$starterKit = dirname($root) . '/starter-kits/agent-collab';
$modules = require $root . '/modules.php';
$keep = in_array('--keep', $argv, true);

if (!is_dir($starterKit)) {
    fwrite(STDERR, "Starter kit not found: {$starterKit}\n");
    exit(1);
}

$fixture = sys_get_temp_dir() . '/' . uniqid('phalanx-collab-install-proof-', true);

if (!mkdir($fixture, 0777, true) && !is_dir($fixture)) {
    fwrite(STDERR, "Failed to create proof fixture: {$fixture}\n");
    exit(1);
}

try {
    copyTree($starterKit, $fixture);

    file_put_contents(
        $fixture . '/composer.json',
        json_encode(
            fixtureComposer($modules, $root),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL,
    );

    rewriteBootstrap($fixture);

    run([composerBinary(), 'install', '--no-interaction', '--no-progress'], $fixture, 300);
    run([PHP_BINARY, 'vendor/bin/phpunit', '--no-coverage'], $fixture, 60);

    echo "Collab install proof OK\n";
} finally {
    if ($keep) {
        printf("Kept proof fixture: %s\n", $fixture);
    } else {
        removeTree($fixture);
    }
}

function fixtureComposer(array $modules, string $root): array
{
    return [
        'name' => 'phalanx-php/collab-install-proof',
        'type' => 'project',
        'require' => [
            'php' => '^8.4',
            'ext-mbstring' => '*',
            'ext-swoole' => '*',
            'ext-pcntl' => '*',
            'phalanx-php/theatron' => '*',
            'symfony/runtime' => '^7.0 || ^8.0',
        ],
        'require-dev' => [
            'phpunit/phpunit' => '^13.0',
        ],
        'autoload' => [
            'psr-4' => [
                'App\\AgentCollab\\' => 'app/',
            ],
        ],
        'autoload-dev' => [
            'psr-4' => [
                'App\\AgentCollab\\Tests\\' => 'tests/',
            ],
        ],
        'repositories' => pathRepositories($modules, $root),
        'minimum-stability' => 'dev',
        'prefer-stable' => true,
        'config' => [
            'sort-packages' => true,
            'allow-plugins' => [
                'dealerdirect/phpcodesniffer-composer-installer' => true,
                'symfony/runtime' => true,
            ],
        ],
    ];
}

function pathRepositories(array $modules, string $root): array
{
    $repositories = [];

    foreach (array_keys($modules) as $module) {
        $repositories[] = [
            'type' => 'path',
            'url' => $root . '/src/' . $module,
            'options' => [
                'symlink' => false,
            ],
        ];
    }

    return $repositories;
}

function rewriteBootstrap(string $fixture): void
{
    $bootstrap = $fixture . '/tests/bootstrap.php';

    file_put_contents($bootstrap, <<<'PHP'
        <?php

        declare(strict_types=1);

        require __DIR__ . '/../vendor/autoload.php';

        spl_autoload_register(static function (string $class): void {
            $prefix = 'App\\AgentCollab\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
        PHP);
}

function copyTree(string $source, string $destination): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($items as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);

        if (str_starts_with($relative, '.git/') || str_starts_with($relative, 'vendor/')) {
            continue;
        }

        $target = $destination . '/' . $relative;

        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0777, true);
            }

            continue;
        }

        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        copy($item->getPathname(), $target);
    }
}

function composerBinary(): string
{
    $binary = getenv('COMPOSER_BINARY');

    return is_string($binary) && $binary !== '' ? $binary : 'composer';
}

function run(array $command, string $cwd, float $timeout): void
{
    $display = implode(' ', array_map('escapeshellarg', $command));
    $process = new Process(
        command: $command,
        cwd: $cwd,
        timeout: $timeout,
    );

    $exitCode = $process->run(static function (string $type, string $buffer): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
    });

    if ($exitCode !== 0) {
        fwrite(STDERR, "Command failed ({$exitCode}): {$display}\n");
        exit($exitCode);
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}
