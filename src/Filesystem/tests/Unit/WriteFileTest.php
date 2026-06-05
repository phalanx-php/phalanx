<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Tests\Unit;

use Phalanx\Application;
use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Filesystem\Task\AppendFile;
use Phalanx\Filesystem\Task\WriteFile;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WriteFileTest extends TestCase
{
    #[Test]
    public function writesFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phalanx_test_');

        try {
            Application::starting()
                ->run(Task::named(
                    'test.filesystem.write-file',
                    static fn(ExecutionScope $scope): mixed => $scope->execute(new WriteFile($tmpFile, 'test content')),
                ));

            $this->assertSame('test content', file_get_contents($tmpFile));
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function appendsFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phalanx_test_');

        try {
            Application::starting()
                ->run(Task::named(
                    'test.filesystem.append-file',
                    static function (ExecutionScope $scope) use ($tmpFile): void {
                        $scope->execute(new WriteFile($tmpFile, 'first'));
                        $scope->execute(new AppendFile($tmpFile, '-second'));
                    },
                ));

            $this->assertSame('first-second', file_get_contents($tmpFile));
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function throwsOnUnwritablePath(): void
    {
        $this->expectException(FilesystemException::class);

        Application::starting()
            ->run(Task::named(
                'test.filesystem.write-file.unwritable',
                static fn(ExecutionScope $scope): mixed => $scope->execute(new WriteFile('/nonexistent/dir/file.txt', 'data')),
            ));
    }
}
