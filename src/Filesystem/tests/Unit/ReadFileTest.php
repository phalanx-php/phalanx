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
        $tmpFile = tempnam(sys_get_temp_dir(), 'phalanx_test_');
        file_put_contents($tmpFile, 'hello world');

        try {
            $result = $this->startedApplication()
                ->scoped(Task::named(
                    'test.filesystem.read-file',
                    static fn(ExecutionScope $scope): string => $scope->execute(new ReadFile($tmpFile)),
                ));

            $this->assertSame('hello world', $result);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function throwsOnMissingFile(): void
    {
        $this->expectException(FilesystemException::class);

        $this->startedApplication()
            ->scoped(Task::named(
                'test.filesystem.read-file.missing',
                static fn(ExecutionScope $scope): string => $scope->execute(new ReadFile('/nonexistent/path/file.txt')),
            ));
    }
}
