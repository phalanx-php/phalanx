<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Template\Slice;

use Phalanx\Boot\AppContext;
use Phalanx\Harness\Template\Slice\RuntimeStatusSlice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuntimeStatusSliceTest extends TestCase
{
    #[Test]
    public function cwdLabelShortensPathsUnderHome(): void
    {
        $slice = new RuntimeStatusSlice('/home/sparta/project', '/home/sparta');

        self::assertSame('~/project', $slice->cwdLabel());
    }

    #[Test]
    public function cwdLabelShortensHomeItself(): void
    {
        $slice = new RuntimeStatusSlice('/home/sparta/', '/home/sparta/');

        self::assertSame('~', $slice->cwdLabel());
    }

    #[Test]
    public function cwdLabelLeavesPathsOutsideHomeUnchanged(): void
    {
        $slice = new RuntimeStatusSlice('/srv/phalanx', '/home/sparta');

        self::assertSame('/srv/phalanx', $slice->cwdLabel());
    }

    #[Test]
    public function cwdLabelDoesNotTreatHomePrefixSiblingsAsHomeChildren(): void
    {
        $slice = new RuntimeStatusSlice('/home/sparta-tools/project', '/home/sparta');

        self::assertSame('/home/sparta-tools/project', $slice->cwdLabel());
    }

    #[Test]
    public function fromContextReadsPwdAndHome(): void
    {
        $slice = RuntimeStatusSlice::fromContext(new AppContext([
            'PWD' => '/workspace/phalanx',
            'HOME' => '/workspace',
        ]));

        self::assertSame('~/phalanx', $slice->cwdLabel());
    }
}
