<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Integration\Command;

use Phalanx\Cli\Command\NewCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class NewCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phalanx-cmd-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            self::removeDir($this->tempDir);
        }
    }

    #[Test]
    public function createsProjectWithNoInstall(): void
    {
        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => 'test-project',
            '--dir' => $this->tempDir,
            '--no-install' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());

        $projectDir = $this->tempDir . '/test-project';
        self::assertFileExists($projectDir . '/composer.json');
        self::assertFileExists($projectDir . '/public/index.php');
        self::assertFileExists($projectDir . '/routes.php');
        self::assertFileExists($projectDir . '/src/Routes/Home.php');
        self::assertFileExists($projectDir . '/.gitignore');
    }

    #[Test]
    public function rejectsInvalidProjectName(): void
    {
        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => '123-bad-name',
            '--dir' => $this->tempDir,
            '--no-install' => true,
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('must start with a letter', $tester->getDisplay());
    }

    #[Test]
    public function rejectsNonEmptyDirectory(): void
    {
        $projectDir = $this->tempDir . '/existing-project';
        mkdir($projectDir, 0755, true);
        file_put_contents($projectDir . '/file.txt', 'content');

        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => 'existing-project',
            '--dir' => $this->tempDir,
            '--no-install' => true,
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('already exists and is not empty', $tester->getDisplay());
    }

    #[Test]
    public function showsNextStepsAfterCreation(): void
    {
        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => 'my-app',
            '--dir' => $this->tempDir,
            '--no-install' => true,
        ]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('cd my-app', $output);
        self::assertStringContainsString('php public/index.php', $output);
    }

    #[Test]
    public function acceptsHyphensInProjectName(): void
    {
        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => 'my-cool-app',
            '--dir' => $this->tempDir,
            '--no-install' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
    }

    private static function removeDir(string $dir): void
    {
        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
