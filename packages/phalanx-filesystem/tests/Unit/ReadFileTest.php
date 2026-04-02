<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Tests\Unit;

use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Filesystem\Task\ReadFile;
use PHPUnit\Framework\TestCase;

final class ReadFileTest extends TestCase
{
    public function test_reads_existing_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phalanx_test_');
        file_put_contents($tmpFile, 'hello world');

        try {
            $task = new ReadFile($tmpFile);
            $scope = $this->createMock(\Phalanx\ExecutionScope::class);
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
        $scope = $this->createMock(\Phalanx\ExecutionScope::class);
        $task($scope);
    }
}
