<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Tests\Unit;

use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Filesystem\Task\AppendFile;
use Phalanx\Filesystem\Task\WriteFile;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class WriteFileTest extends PhalanxTestCase
{
    #[Test]
    public function writesFile(): void
    {
        $tmpFile = $this->tempWorkspace('phalanx-write-file-')->path('output.txt');

        $this->testApp()
            ->scoped(Task::named(
                'test.filesystem.write-file',
                static fn(ExecutionScope $scope): mixed => $scope->execute(new WriteFile($tmpFile, 'test content')),
            ));

        $this->assertSame('test content', $this->tempWorkspace()->read('output.txt'));
    }

    #[Test]
    public function appendsFile(): void
    {
        $tmpFile = $this->tempWorkspace('phalanx-write-file-')->path('append.txt');

        $this->testApp()
            ->scoped(Task::named(
                'test.filesystem.append-file',
                static function (ExecutionScope $scope) use ($tmpFile): void {
                    $scope->execute(new WriteFile($tmpFile, 'first'));
                    $scope->execute(new AppendFile($tmpFile, '-second'));
                },
            ));

        $this->assertSame('first-second', $this->tempWorkspace()->read('append.txt'));
    }

    #[Test]
    public function throwsOnUnwritablePath(): void
    {
        $this->expectException(FilesystemException::class);

        $this->testApp()
            ->scoped(Task::named(
                'test.filesystem.write-file.unwritable',
                static fn(ExecutionScope $scope): mixed => $scope->execute(new WriteFile('/nonexistent/dir/file.txt', 'data')),
            ));
    }
}
