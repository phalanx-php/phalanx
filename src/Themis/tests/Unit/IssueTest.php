<?php

declare(strict_types=1);

namespace Phalanx\Themis\Tests\Unit;

use Phalanx\Themis\Issue;
use Phalanx\Themis\IssueLevel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IssueTest extends TestCase
{
    #[Test]
    public function errorFactoryProducesErrorLevel(): void
    {
        $issue = Issue::error(
            code: 'themis.test.error',
            message: 'Something went wrong.',
            envKey: 'TEST_KEY',
            path: 'testPath',
            hint: 'Check your env.',
        );

        self::assertSame(IssueLevel::Error, $issue->level);
        self::assertSame('themis.test.error', $issue->code);
        self::assertSame('Something went wrong.', $issue->message);
        self::assertSame('TEST_KEY', $issue->envKey);
        self::assertSame('testPath', $issue->path);
        self::assertSame('Check your env.', $issue->hint);
    }

    #[Test]
    public function warningFactoryProducesWarningLevel(): void
    {
        $issue = Issue::warning(
            code: 'themis.test.warning',
            message: 'Something is suspicious.',
        );

        self::assertSame(IssueLevel::Warning, $issue->level);
        self::assertSame('themis.test.warning', $issue->code);
        self::assertSame('Something is suspicious.', $issue->message);
        self::assertNull($issue->envKey);
        self::assertNull($issue->path);
        self::assertNull($issue->hint);
    }

    #[Test]
    public function infoFactoryProducesInfoLevel(): void
    {
        $issue = Issue::info(
            code: 'themis.test.info',
            message: 'For your information.',
            envKey: 'INFO_KEY',
        );

        self::assertSame(IssueLevel::Info, $issue->level);
        self::assertSame('themis.test.info', $issue->code);
        self::assertSame('For your information.', $issue->message);
        self::assertSame('INFO_KEY', $issue->envKey);
        self::assertNull($issue->path);
        self::assertNull($issue->hint);
    }

    #[Test]
    public function constructorPassesThroughAllParameters(): void
    {
        $issue = new Issue(
            level: IssueLevel::Error,
            code: 'direct.construct',
            message: 'Direct construction.',
            envKey: 'DIRECT_KEY',
            path: 'direct.path',
            hint: 'Direct hint.',
        );

        self::assertSame(IssueLevel::Error, $issue->level);
        self::assertSame('direct.construct', $issue->code);
        self::assertSame('Direct construction.', $issue->message);
        self::assertSame('DIRECT_KEY', $issue->envKey);
        self::assertSame('direct.path', $issue->path);
        self::assertSame('Direct hint.', $issue->hint);
    }

    #[Test]
    public function optionalParametersDefaultToNull(): void
    {
        $issue = new Issue(
            level: IssueLevel::Info,
            code: 'minimal',
            message: 'Minimal issue.',
        );

        self::assertNull($issue->envKey);
        self::assertNull($issue->path);
        self::assertNull($issue->hint);
    }
}
