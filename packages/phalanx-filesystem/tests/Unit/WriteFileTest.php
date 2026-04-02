<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Tests\Unit;

use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Filesystem\Task\WriteFile;
use PHPUnit\Framework\TestCase;

final class WriteFileTest extends TestCase
{
    public function test_writes_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phalanx_test_');

        try {
            $task = new WriteFile($tmpFile, 'test content');
            $scope = $this->createMock(\Phalanx\ExecutionScope::class);
            $task($scope);

            $this->assertSame('test content', file_get_contents($tmpFile));
        } finally {
            unlink($tmpFile);
        }
    }

    public function test_throws_on_unwritable_path(): void
    {
        $this->expectException(FilesystemException::class);

        $task = new WriteFile('/nonexistent/dir/file.txt', 'data');
        $scope = $this->createMock(\Phalanx\ExecutionScope::class);
        $task($scope);
    }
}
