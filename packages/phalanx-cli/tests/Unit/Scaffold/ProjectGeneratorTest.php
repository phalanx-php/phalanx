<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Scaffold;

use Phalanx\Cli\Scaffold\ProjectGenerator;
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

        (new ProjectGenerator())('my-app', $this->tempDir, $output);

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

        (new ProjectGenerator())('my-app', $this->tempDir, $output);

        $content = file_get_contents($this->tempDir . '/composer.json');
        self::assertStringContainsString('"app/my-app"', $content);
    }

    #[Test]
    public function namespaceIsCorrectlyDerived(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-cool-app', $this->tempDir, $output);

        $home = file_get_contents($this->tempDir . '/src/Routes/Home.php');
        self::assertStringContainsString('namespace App\MyCoolApp\Routes;', $home);

        $routes = file_get_contents($this->tempDir . '/routes.php');
        self::assertStringContainsString('use App\MyCoolApp\Routes\Home;', $routes);
    }

    #[Test]
    public function singleWordNameProducesCorrectNamespace(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('myapp', $this->tempDir, $output);

        $home = file_get_contents($this->tempDir . '/src/Routes/Home.php');
        self::assertStringContainsString('namespace App\Myapp\Routes;', $home);
    }

    #[Test]
    public function composerJsonHasEscapedNamespace(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output);

        $content = file_get_contents($this->tempDir . '/composer.json');
        self::assertStringContainsString('"App\\\\MyApp\\\\": "src/"', $content);
    }

    #[Test]
    public function routesTemplateUsesCorrectRouteGroupNamespace(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('test-project', $this->tempDir, $output);

        $routes = file_get_contents($this->tempDir . '/routes.php');
        self::assertStringContainsString('use Phalanx\Stoa\RouteGroup;', $routes);
        self::assertStringNotContainsString('Routing\RouteGroup', $routes);
    }

    #[Test]
    public function outputShowsRelativePaths(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output);

        $text = $output->fetch();
        self::assertStringContainsString('composer.json', $text);
        self::assertStringContainsString('public/index.php', $text);
        self::assertStringContainsString('src/Routes/Home.php', $text);
    }

    #[Test]
    public function indexTemplateBootsStoa(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output);

        $index = file_get_contents($this->tempDir . '/public/index.php');
        self::assertStringContainsString('Stoa::starting()', $index);
        self::assertStringContainsString("->listen('127.0.0.1:8080')", $index);
    }

    #[Test]
    public function gitignoreIncludesVendor(): void
    {
        $output = new BufferedOutput();

        (new ProjectGenerator())('my-app', $this->tempDir, $output);

        $gitignore = file_get_contents($this->tempDir . '/.gitignore');
        self::assertStringContainsString('/vendor/', $gitignore);
    }
}
