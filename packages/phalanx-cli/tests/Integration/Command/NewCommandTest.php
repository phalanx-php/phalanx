<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Integration\Command;

use Phalanx\Cli\Command\NewCommand;
use Phalanx\Cli\Tests\Support\RemovesDirectories;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class NewCommandTest extends TestCase
{
    use RemovesDirectories;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phalanx-cmd-test-' . uniqid();
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

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

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

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
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

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('already exists and is not empty', $tester->getDisplay());
    }

    #[Test]
    public function allowsEmptyExistingDirectory(): void
    {
        $projectDir = $this->tempDir . '/empty-project';
        mkdir($projectDir, 0755, true);

        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => 'empty-project',
            '--dir' => $this->tempDir,
            '--no-install' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($projectDir . '/composer.json');
    }

    #[Test]
    public function showsFullPathInNextSteps(): void
    {
        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => 'my-app',
            '--dir' => $this->tempDir,
            '--no-install' => true,
        ]);

        $output = $tester->getDisplay();
        self::assertStringContainsString("cd {$this->tempDir}/my-app", $output);
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

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->tempDir . '/my-cool-app/composer.json');
    }

    #[Test]
    public function rejectsTrailingHyphen(): void
    {
        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => 'bad-name-',
            '--dir' => $this->tempDir,
            '--no-install' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not end with a hyphen', $tester->getDisplay());
    }

    #[Test]
    public function rejectsConsecutiveHyphens(): void
    {
        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => 'bad--name',
            '--dir' => $this->tempDir,
            '--no-install' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function handlesFilesystemError(): void
    {
        $readOnlyDir = $this->tempDir . '/readonly';
        mkdir($readOnlyDir, 0555, true);

        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => 'my-app',
            '--dir' => $readOnlyDir,
            '--no-install' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Failed to create directory', $tester->getDisplay());

        chmod($readOnlyDir, 0755);
    }

    #[Test]
    public function createsConsoleProjectWithNoInstall(): void
    {
        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => 'my-tool',
            '--type' => 'console',
            '--dir' => $this->tempDir,
            '--no-install' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $projectDir = $this->tempDir . '/my-tool';
        self::assertFileExists($projectDir . '/composer.json');
        self::assertFileExists($projectDir . '/bin/app');
        self::assertFileExists($projectDir . '/commands.php');
        self::assertFileExists($projectDir . '/src/Commands/Hello.php');
        self::assertFileExists($projectDir . '/.gitignore');
        self::assertFileDoesNotExist($projectDir . '/public/index.php');
    }

    #[Test]
    public function consoleProjectShowsCorrectNextSteps(): void
    {
        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => 'my-tool',
            '--type' => 'console',
            '--dir' => $this->tempDir,
            '--no-install' => true,
        ]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('php bin/app hello Leonidas', $output);
        self::assertStringNotContainsString('php public/index.php', $output);
    }

    #[Test]
    public function rejectsInvalidProjectType(): void
    {
        $tester = new CommandTester(new NewCommand());
        $tester->execute([
            'name' => 'my-app',
            '--type' => 'invalid',
            '--dir' => $this->tempDir,
            '--no-install' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Invalid project type', $tester->getDisplay());
    }
}
