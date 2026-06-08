<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Tests\Unit;

use Phalanx\Filesystem\Task\CreateDirectory;
use Phalanx\Filesystem\Task\ExistsFile;
use Phalanx\Filesystem\Task\ListDirectory;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\UsesTempWorkspace;
use PHPUnit\Framework\TestCase;

final class DirectoryTest extends TestCase
{
    use UsesTempWorkspace;

    public function test_create_directory(): void
    {
        $tmpDir = $this->tempWorkspace('phalanx-directory-')->path('created');

        $task = new CreateDirectory($tmpDir);
        $scope = $this->createStub(ExecutionScope::class);
        $task($scope);

        $this->assertTrue(is_dir($tmpDir));
    }

    public function test_create_directory_recursive(): void
    {
        $tmpDir = $this->tempWorkspace('phalanx-directory-')->path('nested/deep');

        $task = new CreateDirectory($tmpDir, recursive: true);
        $scope = $this->createStub(ExecutionScope::class);
        $task($scope);

        $this->assertTrue(is_dir($tmpDir));
    }

    public function test_list_directory(): void
    {
        $workspace = $this->tempWorkspace('phalanx-directory-');
        $tmpDir = $workspace->dir('list');
        $workspace->file('list/a.txt');
        $workspace->file('list/b.txt');

        $task = new ListDirectory($tmpDir);

        $scope = $this->createStub(ExecutionScope::class);
        $entries = $task($scope);

        $this->assertCount(2, $entries);
        $this->assertContains('a.txt', $entries);
        $this->assertContains('b.txt', $entries);
        $this->assertNotContains('.', $entries);
        $this->assertNotContains('..', $entries);
    }

    public function test_exists_file(): void
    {
        $scope = $this->createStub(ExecutionScope::class);

        $this->assertTrue((new ExistsFile(__FILE__))($scope));
        $this->assertFalse((new ExistsFile('/nonexistent'))($scope));
    }
}
