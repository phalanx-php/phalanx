<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Tests\Unit;

use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Grammata\Task\ReadFile;
use Phalanx\Scope\ExecutionScope;
use PHPUnit\Framework\TestCase;

final class ReadFileTest extends TestCase
{
    public function test_reads_existing_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phalanx_test_');
        file_put_contents($tmpFile, 'hello world');

        try {
            $task = new ReadFile($tmpFile);
            $scope = $this->createStub(ExecutionScope::class);
            $result = $task($scope);

            $this->assertSame('hello world', $result);
        } finally {
            unlink($tmpFile);
        }
    }

    public function test_throws_on_missing_file(): void
    {
        $this->expectException(FilesystemException::class);

        $task = new ReadFile('/nonexistent/path/file.txt');
        $scope = $this->createStub(ExecutionScope::class);
        $task($scope);
    }
}
