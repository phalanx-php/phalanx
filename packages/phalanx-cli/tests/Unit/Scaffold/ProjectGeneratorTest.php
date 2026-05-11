<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Scaffold;

use Phalanx\Cli\Scaffold\ProjectGenerator;
use Phalanx\Cli\Scaffold\ProjectType;
use Phalanx\Cli\Tests\Support\RemovesDirectories;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class ProjectGeneratorTest extends TestCase
{
    use RemovesDirectories;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phalanx-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            self::removeDir($this->tempDir);
        }
    }

    #[Test]
    public function createsExpectedFiles(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, ProjectType::Api);

        self::assertFileExists($this->tempDir . '/composer.json');
        self::assertFileExists($this->tempDir . '/public/index.php');
        self::assertFileExists($this->tempDir . '/routes.php');
        self::assertFileExists($this->tempDir . '/src/Routes/Home.php');
        self::assertFileExists($this->tempDir . '/.gitignore');
    }

    #[Test]
    public function composerJsonContainsProjectName(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, ProjectType::Api);

        $content = file_get_contents($this->tempDir . '/composer.json');
        self::assertIsString($content);
        self::assertStringContainsString('"app/my-app"', $content);
    }

    #[Test]
    public function namespaceIsCorrectlyDerived(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-cool-app', $this->tempDir, $output, ProjectType::Api);

        $home = file_get_contents($this->tempDir . '/src/Routes/Home.php');
        self::assertIsString($home);
        self::assertStringContainsString('namespace App\MyCoolApp\Routes;', $home);

        $routes = file_get_contents($this->tempDir . '/routes.php');
        self::assertIsString($routes);
        self::assertStringContainsString('use App\MyCoolApp\Routes\Home;', $routes);
    }

    #[Test]
    public function singleWordNameProducesCorrectNamespace(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('myapp', $this->tempDir, $output, ProjectType::Api);

        $home = file_get_contents($this->tempDir . '/src/Routes/Home.php');
        self::assertIsString($home);
        self::assertStringContainsString('namespace App\Myapp\Routes;', $home);
    }

    #[Test]
    public function composerJsonHasEscapedNamespace(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, ProjectType::Api);

        $content = file_get_contents($this->tempDir . '/composer.json');
        self::assertIsString($content);
        self::assertStringContainsString('"App\\\\MyApp\\\\": "src/"', $content);
    }

    #[Test]
    public function routesTemplateUsesCorrectRouteGroupNamespace(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('test-project', $this->tempDir, $output, ProjectType::Api);

        $routes = file_get_contents($this->tempDir . '/routes.php');
        self::assertIsString($routes);
        self::assertStringContainsString('use Phalanx\Stoa\RouteGroup;', $routes);
        self::assertStringNotContainsString('Routing\RouteGroup', $routes);
    }

    #[Test]
    public function outputShowsRelativePaths(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, ProjectType::Api);

        $text = $output->fetch();
        self::assertStringContainsString('composer.json', $text);
        self::assertStringContainsString('public/index.php', $text);
        self::assertStringContainsString('src/Routes/Home.php', $text);
    }

    #[Test]
    public function indexTemplateBootsStoa(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, ProjectType::Api);

        $index = file_get_contents($this->tempDir . '/public/index.php');
        self::assertIsString($index);
        self::assertStringContainsString('autoload_runtime.php', $index);
        self::assertStringContainsString('Stoa::starting($context)', $index);
        self::assertStringContainsString("->listen('127.0.0.1:8080')", $index);
    }

    #[Test]
    public function gitignoreIncludesVendor(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, ProjectType::Api);

        $gitignore = file_get_contents($this->tempDir . '/.gitignore');
        self::assertIsString($gitignore);
        self::assertStringContainsString('/vendor/', $gitignore);
    }

    #[Test]
    public function consoleCreatesExpectedFiles(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-tool', $this->tempDir, $output, ProjectType::Console);

        self::assertFileExists($this->tempDir . '/composer.json');
        self::assertFileExists($this->tempDir . '/bin/app');
        self::assertFileExists($this->tempDir . '/commands.php');
        self::assertFileExists($this->tempDir . '/src/Commands/Hello.php');
        self::assertFileExists($this->tempDir . '/.gitignore');
        self::assertFileDoesNotExist($this->tempDir . '/public/index.php');
        self::assertFileDoesNotExist($this->tempDir . '/routes.php');
    }

    #[Test]
    public function consoleComposerJsonRequiresArchon(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-tool', $this->tempDir, $output, ProjectType::Console);

        $content = file_get_contents($this->tempDir . '/composer.json');
        self::assertIsString($content);
        self::assertStringContainsString('"phalanx-php/archon"', $content);
        self::assertStringNotContainsString('"phalanx-php/stoa"', $content);
        self::assertStringContainsString('"bin/app"', $content);
    }

    #[Test]
    public function consoleCommandsFileUsesCommandGroup(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-tool', $this->tempDir, $output, ProjectType::Console);

        $commands = file_get_contents($this->tempDir . '/commands.php');
        self::assertIsString($commands);
        self::assertStringContainsString('use Phalanx\Archon\Command\CommandGroup;', $commands);
        self::assertStringContainsString('CommandGroup::of(', $commands);
        self::assertStringContainsString('use App\MyTool\Commands\Hello;', $commands);
    }

    #[Test]
    public function consoleHelloImplementsScopeable(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-tool', $this->tempDir, $output, ProjectType::Console);

        $hello = file_get_contents($this->tempDir . '/src/Commands/Hello.php');
        self::assertIsString($hello);
        self::assertStringContainsString('implements Scopeable', $hello);
        self::assertStringContainsString('use Phalanx\Archon\Command\CommandScope;', $hello);
        self::assertStringContainsString('$scope->args->required(', $hello);
        self::assertStringContainsString('$scope->service(StreamOutput::class)', $hello);
    }

    #[Test]
    public function consoleBinAppUsesAutoloadRuntime(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-tool', $this->tempDir, $output, ProjectType::Console);

        $bin = file_get_contents($this->tempDir . '/bin/app');
        self::assertIsString($bin);
        self::assertStringContainsString('#!/usr/bin/env php', $bin);
        self::assertStringContainsString('autoload_runtime.php', $bin);
        self::assertStringContainsString('Archon::starting($context)', $bin);
    }

    #[Test]
    public function apiComposerJsonRequiresStoa(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, ProjectType::Api);

        $content = file_get_contents($this->tempDir . '/composer.json');
        self::assertIsString($content);
        self::assertStringContainsString('"phalanx-php/stoa"', $content);
        self::assertStringNotContainsString('"phalanx-php/archon"', $content);
        self::assertStringNotContainsString('"bin":', $content);
    }
}
