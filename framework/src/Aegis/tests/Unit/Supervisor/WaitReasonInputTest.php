<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Supervisor\WaitKind;
use Phalanx\Supervisor\WaitReason;
use PHPUnit\Framework\TestCase;

final class WaitReasonInputTest extends TestCase
{
    public function testInputUsesInputKind(): void
    {
        $reason = WaitReason::input('Enter your name: ');

        self::assertSame(WaitKind::Input, $reason->kind);
    }

    public function testInputCarriesPromptAsDetail(): void
    {
        $reason = WaitReason::input('Enter your name: ');

        self::assertSame('Enter your name: ', $reason->detail);
    }

    public function testInputCombinesPromptAndDetail(): void
    {
        $reason = WaitReason::input('confirm', 'destructive action');

        self::assertSame('confirm (destructive action)', $reason->detail);
    }

    public function testInputAcceptsEmptyPrompt(): void
    {
        $reason = WaitReason::input();

        self::assertSame(WaitKind::Input, $reason->kind);
        self::assertSame('', $reason->detail);
    }

    public function testInputUsesDetailWhenPromptEmpty(): void
    {
        $reason = WaitReason::input('', 'raw read');

        self::assertSame('raw read', $reason->detail);
    }

    public function testStartedAtIsRecorded(): void
    {
        $before = microtime(true);
        $reason = WaitReason::input('go');
        $after = microtime(true);

        self::assertGreaterThanOrEqual($before, $reason->startedAt);
        self::assertLessThanOrEqual($after, $reason->startedAt);
    }
}
