<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Reactive;

use Phalanx\Tui\Tui\Reactive\DirtyBatch;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DirtyBatchTest extends TestCase
{
    #[Test]
    public function initiallyClean(): void
    {
        $batch = new DirtyBatch();

        self::assertFalse($batch->isDirty);
        self::assertSame(0, $batch->requests);
    }

    #[Test]
    public function requestMarksDirtyAndIncrementsCounter(): void
    {
        $batch = new DirtyBatch();
        $batch->request();

        self::assertTrue($batch->isDirty);
        self::assertSame(1, $batch->requests);
    }

    #[Test]
    public function requestIsIdempotentWhenAlreadyDirty(): void
    {
        $batch = new DirtyBatch();
        $batch->request();
        $batch->request();
        $batch->request();

        self::assertSame(1, $batch->requests);
    }

    #[Test]
    public function consumeReturnsTrueAndClearsFlag(): void
    {
        $batch = new DirtyBatch();
        $batch->request();

        self::assertTrue($batch->consume());
        self::assertFalse($batch->isDirty);
    }

    #[Test]
    public function consumeReturnsFalseWhenClean(): void
    {
        $batch = new DirtyBatch();

        self::assertFalse($batch->consume());
    }

    #[Test]
    public function requestAfterConsumeIncrementsAgain(): void
    {
        $batch = new DirtyBatch();
        $batch->request();
        $batch->consume();
        $batch->request();

        self::assertSame(2, $batch->requests);
        self::assertTrue($batch->isDirty);
    }
}
