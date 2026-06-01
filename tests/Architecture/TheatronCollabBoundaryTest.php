<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class TheatronCollabBoundaryTest extends TestCase
{
    #[Test]
    public function standaloneLegacyModuleIsRemoved(): void
    {
        $root = dirname(__DIR__, 2);
        $modules = require $root . '/modules.php';

        self::assertArrayNotHasKey(self::legacyModuleName(), $modules);
        self::assertDirectoryDoesNotExist($root . '/src/' . self::legacyModuleName());
        self::assertDirectoryDoesNotExist($root . '/src/Theatron/src/' . self::legacyModuleName());
        self::assertDirectoryExists($root . '/src/Theatron/src/Collab');
    }

    #[Test]
    public function packageMetadataDoesNotExposeStandaloneLegacyModule(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = self::read($root . '/composer.json');
        $modules = self::read($root . '/modules.php');
        $phpstan = self::read($root . '/phpstan.neon');

        foreach (self::standaloneLegacyModuleTokens() as $token) {
            self::assertStringNotContainsString($token, $composer);
            self::assertStringNotContainsString($token, $modules);
            self::assertStringNotContainsString($token, $phpstan);
        }
    }

    #[Test]
    public function docsAndDemosDoNotAdvertiseStandaloneLegacyModule(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertDirectoryDoesNotExist($root . '/demos/' . strtolower(self::legacyModuleName()));

        foreach ([$root . '/README.md', $root . '/src/Theatron/README.md'] as $file) {
            $source = self::read($file);

            foreach (self::standaloneLegacyModuleTokens() as $token) {
                self::assertStringNotContainsString($token, $source, self::relative($root, $file));
            }
        }
    }

    #[Test]
    public function collabKeepsPersistenceOutOfTheCoreLoop(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::sourceFiles($root . '/src/Theatron/src/Collab') as $file) {
            $source = self::read($file);

            if (str_contains($source, 'Phalanx\\Surreal\\')) {
                $offenders[] = self::relative($root, $file) . ' contains Phalanx\\Surreal\\';
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Collab should not pull durable persistence into the core loop:\n" . implode("\n", $offenders),
        );
    }

    #[Test]
    public function collabOwnsTheAthenaPanoplyAdapterInternally(): void
    {
        $root = dirname(__DIR__, 2);
        $adapter = $root . '/src/Theatron/src/Collab/Adapters/Athena/AthenaCollaborator.php';
        $offenders = [];

        self::assertFileExists($adapter);

        $source = self::read($adapter);

        self::assertStringContainsString('Phalanx\\Athena\\', $source);
        self::assertStringContainsString('Phalanx\\Panoply\\', $source);

        foreach (self::sourceFiles($root . '/src/Theatron/src/Collab') as $file) {
            if (str_contains(self::relative($root, $file), 'src/Theatron/src/Collab/Adapters/')) {
                continue;
            }

            $source = self::read($file);
            foreach (['Phalanx\\Athena\\', 'Phalanx\\Panoply\\'] as $token) {
                if (str_contains($source, $token)) {
                    $offenders[] = self::relative($root, $file) . " contains {$token}";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Athena/Panoply imports belong in Collab adapters, not the core loop/contracts:\n" . implode("\n", $offenders),
        );
    }

    #[Test]
    public function starterScaffoldDoesNotExposeAgentRuntimeDependencies(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::sourceFiles($root . '/src/Cli/src/Scaffold') as $file) {
            $source = self::read($file);

            foreach (['Phalanx\\Athena\\', 'Phalanx\\Panoply\\'] as $token) {
                if (str_contains($source, $token)) {
                    $offenders[] = self::relative($root, $file) . " contains {$token}";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Generated userland must not import agent runtime internals directly:\n" . implode("\n", $offenders),
        );
    }

    #[Test]
    public function workContextKeepsTheCurrentMutationSurfaceSmall(): void
    {
        $methods = [];
        $properties = [];
        $class = new \ReflectionClass(\Phalanx\Theatron\Collab\WorkContext::class);

        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class === \Phalanx\Theatron\Collab\WorkContext::class && $method->name !== '__construct') {
                $methods[] = $method->name;
            }
        }

        foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->class === \Phalanx\Theatron\Collab\WorkContext::class) {
                $properties[] = $property->name;
            }
        }

        sort($methods);
        sort($properties);

        self::assertSame(['abort', 'advance', 'append', 'fulfill', 'record', 'review', 'start'], $methods);
        self::assertSame(['plan', 'scope', 'stage'], $properties);
    }

    #[Test]
    public function workContextUsesNarrowTaskScope(): void
    {
        $constructor = new \ReflectionMethod(\Phalanx\Theatron\Collab\WorkContext::class, '__construct');
        $scope = $constructor->getParameters()[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $scope);
        self::assertSame(\Phalanx\Scope\TaskScope::class, $scope->getName());
    }

    #[Test]
    public function theatronSourceDoesNotKeepStaleHarnessOrGenericReactorSurfaces(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        self::assertFileDoesNotExist($root . '/src/Theatron/src/Tui/Core/Reactor.php');
        self::assertFileDoesNotExist($root . '/src/Theatron/src/Tui/Core/ReactorContext.php');

        foreach (self::sourceFiles($root . '/src/Theatron/src') as $file) {
            $relative = self::relative($root, $file);

            if (str_contains($relative, '/Harness/')) {
                $offenders[] = "{$relative} remains under a stale Harness path";
            }

            foreach (self::staleTheatronHarnessTokens() as $token) {
                if (str_contains(self::read($file), $token)) {
                    $offenders[] = "{$relative} contains {$token}";
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Theatron source must keep the current Tui/Collab vocabulary:\n" . implode("\n", $offenders),
        );
    }

    /**
     * @return list<string>
     */
    private static function standaloneLegacyModuleTokens(): array
    {
        $module = self::legacyModuleName();
        $lower = strtolower($module);

        return [
            'Phalanx\\\\' . $module . '\\\\',
            'Phalanx\\' . $module . '\\',
            'phalanx-php/' . $lower,
            'src/' . $module,
            'demos/' . $lower,
            'demo:' . $lower,
            'test:' . $lower,
            'prove:' . $lower . '-install',
            $module . '::app',
            'Theatron::' . strtolower($module),
        ];
    }

    /**
     * @return list<string>
     */
    private static function staleTheatronHarnessTokens(): array
    {
        $module = self::legacyModuleName();

        return [
            'Phalanx\\Theatron\\' . $module . '\\',
            $module . 'Event',
            $module . 'Id',
            $module . 'Store',
            'Theatron::' . strtolower($module),
        ];
    }

    private static function legacyModuleName(): string
    {
        return 'Harness';
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function read(string $file): string
    {
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    private static function relative(string $root, string $file): string
    {
        return str_replace($root . '/', '', $file);
    }
}
