<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Unit\Tool;

use Phalanx\Agents\Tool\Disposition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DispositionTest extends TestCase
{
    /** @return iterable<string, array{Disposition, string}> */
    public static function dispositionCases(): iterable
    {
        yield 'continue' => [Disposition::Continue, 'continue'];
        yield 'terminate' => [Disposition::Terminate, 'terminate'];
        yield 'suspend' => [Disposition::Suspend, 'suspend'];
    }

    #[Test]
    public function threeDispositionCasesExist(): void
    {
        self::assertCount(3, Disposition::cases());
    }

    #[Test]
    #[DataProvider('dispositionCases')]
    public function casesBackedByExpectedValues(Disposition $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }

    #[Test]
    public function casesAreResolvableFromStringValue(): void
    {
        self::assertSame(Disposition::Continue, Disposition::from('continue'));
        self::assertSame(Disposition::Terminate, Disposition::from('terminate'));
        self::assertSame(Disposition::Suspend, Disposition::from('suspend'));
    }
}
