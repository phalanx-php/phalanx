<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class LifecycleSurfaceTest extends TestCase
{
    #[Test]
    public function http_lifecycle_entry_is_module_named(): void
    {
        $root = self::root();

        self::assertFileExists($root . '/src/Http/src/Http.php');
        self::assertFileDoesNotExist($root . '/src/Http/src/Server.php');
        self::assertSame([], self::staleHttpServerEntries($root));
    }

    #[Test]
    public function service_config_declares_the_complete_application_lifecycle(): void
    {
        $source = (string) file_get_contents(self::root() . '/src/Runtime/src/Service/ServiceConfig.php');

        foreach (['onInit', 'onStartup', 'onReady', 'onDispose', 'onShutdown'] as $hook) {
            self::assertStringContainsString("public function {$hook}(Closure \$hook): self;", $source);
        }
    }

    #[Test]
    public function generic_lifecycle_callback_bags_do_not_exist(): void
    {
        self::assertSame([], glob(self::root() . '/src/Runtime/src/Lifecycle/*.php') ?: []);
    }

    #[Test]
    public function module_bootstrap_entries_expose_starting(): void
    {
        $root = self::root();

        foreach ([
            'src/Console/src/Console.php' => 'class Console',
            'src/Http/src/Http.php' => 'class Http',
            'src/Tui/src/Tui.php' => 'class Tui',
            'src/DevServer/src/DevServer.php' => 'class DevServer',
        ] as $path => $classNeedle) {
            $source = (string) file_get_contents($root . '/' . $path);

            self::assertStringContainsString($classNeedle, $source);
            self::assertStringContainsString('public static function starting(array $context = [])', $source);
        }
    }

    /** @return list<string> */
    private static function staleHttpServerEntries(string $root): array
    {
        $violations = [];
        foreach (self::phpFiles($root) as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, 'use Phalanx\\Http\\Server;')
                || str_contains($source, 'Phalanx\\Http\\Server::starting')
                || preg_match('/(?<!Dev)Server::starting/', $source) === 1
            ) {
                $violations[] = self::relative($root, $file);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private static function phpFiles(string $root): array
    {
        $files = [];
        foreach (['src', 'tests', 'demos', 'benchmarks'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    if ($file->getPathname() === __FILE__) {
                        continue;
                    }

                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function relative(string $root, string $path): string
    {
        return ltrim(str_replace($root, '', $path), '/');
    }
}
