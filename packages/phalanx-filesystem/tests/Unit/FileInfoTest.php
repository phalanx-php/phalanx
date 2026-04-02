<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Tests\Unit;

use Phalanx\Filesystem\FileInfo;
use PHPUnit\Framework\TestCase;

final class FileInfoTest extends TestCase
{
    public function test_from_stat_creates_file_info(): void
    {
        $stat = stat(__FILE__);
        $info = FileInfo::fromStat(__FILE__, $stat);

        $this->assertSame(__FILE__, $info->path);
        $this->assertTrue($info->isFile);
        $this->assertFalse($info->isDirectory);
        $this->assertFalse($info->isSymlink);
        $this->assertGreaterThan(0, $info->size);
        $this->assertGreaterThan(0, $info->modifiedAt);
    }

    public function test_from_stat_directory(): void
    {
        $stat = stat(__DIR__);
        $info = FileInfo::fromStat(__DIR__, $stat);

        $this->assertTrue($info->isDirectory);
        $this->assertFalse($info->isFile);
    }

    public function test_readonly_properties(): void
    {
        $info = new FileInfo(
            path: '/test',
            size: 1024,
            modifiedAt: 1000,
            accessedAt: 2000,
            createdAt: 500,
            permissions: 0644,
            isFile: true,
            isDirectory: false,
            isSymlink: false,
        );

        $this->assertSame('/test', $info->path);
        $this->assertSame(1024, $info->size);
        $this->assertSame(0644, $info->permissions);
    }
}
