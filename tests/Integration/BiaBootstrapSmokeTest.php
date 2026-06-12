<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration;

use FilesystemIterator;
use Phalanx\Bootstrap\BootstrapContract;
use Phalanx\Phalanx;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class BiaBootstrapSmokeTest extends TestCase
{
    private ?string $workspace = null;

    #[Test]
    public function pathRepositoryCanInstallAndLoadTheBootstrapContract(): void
    {
        $workspace = $this->makeWorkspace();
        $this->writeComposerJson($workspace);

        $this->runCommand(['composer', 'install', '--no-interaction', '--no-progress', '--quiet'], $workspace);

        $output = $this->runCommand([
            PHP_BINARY,
            '-r',
            <<<'PHP'
                require 'vendor/autoload.php';

                echo json_encode(\Phalanx\Phalanx::bootstrapContract()->toArray(), JSON_THROW_ON_ERROR);
                PHP,
        ], $workspace);

        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            'contract' => BootstrapContract::CONTRACT,
            'entrypoint' => Phalanx::class,
            'package' => BootstrapContract::PACKAGE,
            'version' => BootstrapContract::VERSION,
        ], $payload);
    }

    protected function tearDown(): void
    {
        if ($this->workspace !== null && is_dir($this->workspace)) {
            $this->removeDirectory($this->workspace);
        }
    }

    private function makeWorkspace(): string
    {
        $workspace = sys_get_temp_dir() . '/phalanx-bia-bootstrap-' . bin2hex(random_bytes(6));

        if (!mkdir($workspace, 0777, true) && !is_dir($workspace)) {
            throw new RuntimeException("Could not create bootstrap smoke workspace: {$workspace}");
        }

        $this->workspace = $workspace;

        return $workspace;
    }

    private function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if ($file->isLink()) {
                unlink($file->getPathname());
                continue;
            }

            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($directory);
    }

    /** @param list<string> $command */
    private function runCommand(array $command, string $cwd): string
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start bootstrap smoke command.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim((string) $stderr) ?: "Bootstrap smoke command failed with exit code {$exitCode}.");
        }

        return (string) $stdout;
    }

    private function writeComposerJson(string $workspace): void
    {
        $composer = [
            'name' => 'phalanx/bootstrap-smoke',
            'type' => 'project',
            'require' => [
                'php' => '^8.4',
                BootstrapContract::PACKAGE => '^2.0@dev',
            ],
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => $this->packageRoot(),
                    'options' => [
                        'symlink' => true,
                        'versions' => [
                            BootstrapContract::PACKAGE => '2.0.x-dev',
                        ],
                    ],
                ],
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ];

        file_put_contents(
            $workspace . '/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }
}
