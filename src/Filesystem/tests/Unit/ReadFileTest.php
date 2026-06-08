<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Tests\Unit;

use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Filesystem\Task\ReadFile;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ReadFileTest extends PhalanxTestCase
{
    #[Test]
    public function readsExistingFile(): void
    {
        $tmpFile = $this->tempWorkspace('phalanx-read-file-')->file('input.txt', 'hello world');

        $result = $this->testApp()
            ->scoped(Task::named(
                'test.filesystem.read-file',
                static fn(ExecutionScope $scope): string => $scope->execute(new ReadFile($tmpFile)),
            ));

        $this->assertSame('hello world', $result);
    }

    #[Test]
    public function throwsOnMissingFile(): void
    {
        $this->expectException(FilesystemException::class);

        $this->testApp()
            ->scoped(Task::named(
                'test.filesystem.read-file.missing',
                static fn(ExecutionScope $scope): string => $scope->execute(new ReadFile('/nonexistent/path/file.txt')),
            ));
    }
}
