<?php

declare(strict_types=1);

namespace Phalanx\Stream\Tests\Unit;

use Phalanx\Stream\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResourceHandleTest extends TestCase
{
    #[Test]
    public function captureBufferOwnsWritableTemporaryStream(): void
    {
        $buffer = Stream::captureBuffer();

        $buffer->write('alpha');
        $buffer->write(' beta');

        self::assertSame('alpha beta', $buffer->contents());
    }

    #[Test]
    public function memoryInputStartsWithProvidedContents(): void
    {
        $input = Stream::memoryInput('typed input');

        self::assertSame('typed input', $input->contents());
    }

    #[Test]
    public function closedHandleRejectsFurtherResourceAccess(): void
    {
        $buffer = Stream::memoryBuffer('closed');

        $buffer->close();

        $this->expectException(RuntimeException::class);
        $buffer->resource();
    }
}
