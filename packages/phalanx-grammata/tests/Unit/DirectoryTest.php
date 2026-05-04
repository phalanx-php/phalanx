<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Tests\Unit;

use Phalanx\Grammata\Task\CreateDirectory;
use Phalanx\Grammata\Task\ExistsFile;
use Phalanx\Grammata\Task\ListDirectory;
use PHPUnit\Framework\TestCase;

final class DirectoryTest extends TestCase
{
    public function test_create_directory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phalanx_test_' . uniqid();

        try {
            $task = new CreateDirectory($tmpDir);
            /** @var \Phalanx\ExecutionScope&\PHPUnit\Framework\MockObject\MockObject $scope */
            $scope = $this->createMock(\Phalanx\ExecutionScope::class);
            $task($scope);

            $this->assertTrue(is_dir($tmpDir));
        } finally {
            @rmdir($tmpDir);
        }
    }

    public function test_create_directory_recursive(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phalanx_test_' . uniqid() . '/nested/deep';

        try {
            $task = new CreateDirectory($tmpDir, recursive: true);
            /** @var \Phalanx\ExecutionScope&\PHPUnit\Framework\MockObject\MockObject $scope */
            $scope = $this->createMock(\Phalanx\ExecutionScope::class);
            $task($scope);

            $this->assertTrue(is_dir($tmpDir));
        } finally {
            @rmdir($tmpDir);
            @rmdir(dirname($tmpDir));
            @rmdir(dirname($tmpDir, 2));
        }
    }

    public function test_list_directory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phalanx_test_' . uniqid();
        mkdir($tmpDir);
        touch($tmpDir . '/a.txt');
        touch($tmpDir . '/b.txt');

        try {
            $task = new ListDirectory($tmpDir);

            /** @var \Phalanx\ExecutionScope&\PHPUnit\Framework\MockObject\MockObject $scope */
            $scope = $this->createMock(\Phalanx\ExecutionScope::class);
            $entries = $task($scope);

            $this->assertCount(2, $entries);
            $this->assertContains('a.txt', $entries);
            $this->assertContains('b.txt', $entries);
            $this->assertNotContains('.', $entries);
            $this->assertNotContains('..', $entries);
        } finally {
            @unlink($tmpDir . '/a.txt');
            @unlink($tmpDir . '/b.txt');
            @rmdir($tmpDir);
        }
    }

    public function test_exists_file(): void
    {
        /** @var \Phalanx\ExecutionScope&\PHPUnit\Framework\MockObject\MockObject $scope */
        $scope = $this->createMock(\Phalanx\ExecutionScope::class);

        $this->assertTrue((new ExistsFile(__FILE__))($scope));
        $this->assertFalse((new ExistsFile('/nonexistent'))($scope));
    }
}
