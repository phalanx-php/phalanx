<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Tests\Unit;

use Phalanx\Filesystem\Task\CreateDirectory;
use Phalanx\Filesystem\Task\ExistsFile;
use Phalanx\Filesystem\Task\ListDirectory;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\UsesTempWorkspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DirectoryTest extends TestCase
{
    use UsesTempWorkspace;

    #[Test]
    public function createsDirectory(): void
    {
        $tmpDir = $this->tempWorkspace('phalanx-directory-')->path('created');

        $task = new CreateDirectory($tmpDir);
        $scope = $this->createStub(ExecutionScope::class);
        $task($scope);

        $this->assertTrue(is_dir($tmpDir));
    }

    #[Test]
    public function createsDirectoryRecursively(): void
    {
        $tmpDir = $this->tempWorkspace('phalanx-directory-')->path('nested/deep');

        $task = new CreateDirectory($tmpDir, recursive: true);
        $scope = $this->createStub(ExecutionScope::class);
        $task($scope);

        $this->assertTrue(is_dir($tmpDir));
    }

    #[Test]
    public function listsDirectory(): void
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

    #[Test]
    public function checksFileExistence(): void
    {
        $scope = $this->createStub(ExecutionScope::class);
        $missingPath = $this->tempWorkspace('phalanx-directory-')->missingPath('missing.txt');

        $this->assertTrue((new ExistsFile(__FILE__))($scope));
        $this->assertFalse((new ExistsFile($missingPath))($scope));
    }
}
