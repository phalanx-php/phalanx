<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Tests\Unit;

use Phalanx\Application;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Grammata\Task\ReadFile;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReadFileTest extends TestCase
{
    #[Test]
    public function readsExistingFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phalanx_test_');
        file_put_contents($tmpFile, 'hello world');

        try {
            $result = Application::starting()
                ->run(Task::named(
                    'test.grammata.read-file',
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

        Application::starting()
            ->run(Task::named(
                'test.grammata.read-file.missing',
                static fn(ExecutionScope $scope): string => $scope->execute(new ReadFile('/nonexistent/path/file.txt')),
            ));
    }
}
