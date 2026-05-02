<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use Phalanx\Stoa\Sse\SseEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SseEncoderTest extends TestCase
{
    #[Test]
    public function encoder_produces_valid_sse_format(): void
    {
        $output = SseEncoder::encode('hello world');

        $this->assertSame("data: hello world\n\n", $output);
    }

    #[Test]
    public function encoder_with_event_and_id(): void
    {
        $output = SseEncoder::encode('payload', event: 'update', id: '42');

        $this->assertStringContainsString("id: 42\n", $output);
        $this->assertStringContainsString("event: update\n", $output);
        $this->assertStringContainsString("data: payload\n", $output);
        $this->assertStringEndsWith("\n\n", $output);
    }

    #[Test]
    public function encoder_with_retry(): void
    {
        $output = SseEncoder::encode('data', retry: 5000);

        $this->assertStringContainsString("retry: 5000\n", $output);
    }

    #[Test]
    public function encoder_handles_multiline_data(): void
    {
        $output = SseEncoder::encode("line one\nline two\nline three");

        $this->assertStringContainsString("data: line one\n", $output);
        $this->assertStringContainsString("data: line two\n", $output);
        $this->assertStringContainsString("data: line three\n", $output);
    }
}
