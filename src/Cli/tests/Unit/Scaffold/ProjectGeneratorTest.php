<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Scaffold;

use Phalanx\Cli\Scaffold\ProjectGenerator;
use Phalanx\Cli\Scaffold\ProjectType;
use Phalanx\Testing\UsesTempWorkspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class ProjectGeneratorTest extends TestCase
{
    use UsesTempWorkspace;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = $this->tempWorkspace('phalanx-generator-test-')->missingPath('project');
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

        $content = $this->generated('composer.json');
        self::assertStringContainsString('"app/my-app"', $content);
    }

    #[Test]
    public function namespaceIsCorrectlyDerived(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-cool-app', $this->tempDir, $output, ProjectType::Api);

        $home = $this->generated('src/Routes/Home.php');
        self::assertStringContainsString('namespace App\MyCoolApp\Routes;', $home);

        $routes = $this->generated('routes.php');
        self::assertStringContainsString('use App\MyCoolApp\Routes\Home;', $routes);
    }

    #[Test]
    public function singleWordNameProducesCorrectNamespace(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('myapp', $this->tempDir, $output, ProjectType::Api);

        $home = $this->generated('src/Routes/Home.php');
        self::assertStringContainsString('namespace App\Myapp\Routes;', $home);
    }

    #[Test]
    public function composerJsonHasEscapedNamespace(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, ProjectType::Api);

        $content = $this->generated('composer.json');
        self::assertStringContainsString('"App\\\\MyApp\\\\": "src/"', $content);
    }

    #[Test]
    public function routesTemplateUsesCorrectRouteGroupNamespace(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('test-project', $this->tempDir, $output, ProjectType::Api);

        $routes = $this->generated('routes.php');
        self::assertStringContainsString('use Phalanx\Http\RouteGroup;', $routes);
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
    public function indexTemplateBootsHttp(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, ProjectType::Api);

        $index = $this->generated('public/index.php');
        self::assertStringContainsString('autoload_runtime.php', $index);
        self::assertStringContainsString('Http::starting($context)', $index);
        self::assertStringContainsString("->listen('127.0.0.1:8080')", $index);
    }

    #[Test]
    public function gitignoreIncludesVendor(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, ProjectType::Api);

        $gitignore = $this->generated('.gitignore');
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
    public function consoleComposerJsonRequiresConsole(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-tool', $this->tempDir, $output, ProjectType::Console);

        $content = $this->generated('composer.json');
        self::assertStringContainsString('"phalanx-php/console"', $content);
        self::assertStringNotContainsString('"phalanx-php/http"', $content);
        self::assertStringContainsString('"bin/app"', $content);
    }

    #[Test]
    public function consoleCommandsFileUsesCommandGroup(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-tool', $this->tempDir, $output, ProjectType::Console);

        $commands = $this->generated('commands.php');
        self::assertStringContainsString('use Phalanx\Console\Command\CommandGroup;', $commands);
        self::assertStringContainsString('CommandGroup::of(', $commands);
        self::assertStringContainsString('use App\MyTool\Commands\Hello;', $commands);
        self::assertStringContainsString("'hello' => Hello::class,", $commands);
    }

    #[Test]
    public function consoleHelloImplementsScopeableAndDescribesCommand(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-tool', $this->tempDir, $output, ProjectType::Console);

        $hello = $this->generated('src/Commands/Hello.php');
        self::assertStringContainsString('implements Scopeable, DescribesCommand', $hello);
        self::assertStringContainsString('use Phalanx\Console\Command\Arg;', $hello);
        self::assertStringContainsString('use Phalanx\Console\Command\DescribesCommand;', $hello);
        self::assertStringContainsString('public static function commandConfig(): CommandConfig', $hello);
        self::assertStringContainsString('Arg::required(', $hello);
        self::assertStringContainsString('use Phalanx\Console\Command\CommandContext;', $hello);
        self::assertStringContainsString('$ctx->args->required(', $hello);
        self::assertStringContainsString('$ctx->service(StreamOutput::class)', $hello);
        self::assertStringNotContainsString('Phalanx\Http', $hello);
    }

    #[Test]
    public function consoleBinAppUsesAutoloadRuntime(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-tool', $this->tempDir, $output, ProjectType::Console);

        $bin = $this->generated('bin/app');
        self::assertStringContainsString('#!/usr/bin/env php', $bin);
        self::assertStringContainsString('autoload_runtime.php', $bin);
        self::assertStringContainsString('Console::starting($context)', $bin);
    }

    #[Test]
    public function apiComposerJsonRequiresHttp(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, ProjectType::Api);

        $content = $this->generated('composer.json');
        self::assertStringContainsString('"phalanx-php/http"', $content);
        self::assertStringNotContainsString('"phalanx-php/console"', $content);
        self::assertStringNotContainsString('"bin":', $content);
    }

    #[Test]
    public function consoleBinAppIsExecutable(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-tool', $this->tempDir, $output, ProjectType::Console);

        self::assertTrue(is_executable($this->tempDir . '/bin/app'));
    }

    #[Test]
    #[DataProvider('projectTypes')]
    public function composerJsonIsValid(ProjectType $type): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, $type);

        $content = $this->generated('composer.json');
        self::assertIsArray(json_decode($content, associative: true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    #[DataProvider('projectTypes')]
    public function generatedPhpFilesPassSyntaxLint(ProjectType $type): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output, $type);

        foreach (self::phpFiles($this->tempDir) as $file) {
            $lines = [];
            exec(PHP_BINARY . ' -l ' . escapeshellarg($file), $lines, $status);

            self::assertSame(0, $status, implode("\n", $lines));
        }
    }

    /** @return iterable<string, array{ProjectType}> */
    public static function projectTypes(): iterable
    {
        yield 'api' => [ProjectType::Api];
        yield 'console' => [ProjectType::Console];
    }

    /** @return list<string> */
    private static function phpFiles(string $directory): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function generated(string $relative): string
    {
        return $this->tempWorkspace()->read('project/' . $relative);
    }
}
