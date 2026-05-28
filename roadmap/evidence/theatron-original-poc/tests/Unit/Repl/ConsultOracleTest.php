<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Tool\ConsultOracle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsultOracleTest extends TestCase
{
    #[Test]
    public function returns_array_with_prophecy_data(): void
    {
        $tool = new ConsultOracle(question: 'Should we march on Sparta?');

        $result = $tool();

        self::assertIsArray($result);
        self::assertArrayHasKey('prophecy', $result);
        self::assertArrayHasKey('oracle', $result);
        self::assertSame('Pythia of Delphi', $result['oracle']);
        self::assertSame('Should we march on Sparta?', $result['question']);
    }

    #[Test]
    public function prophecy_is_deterministic_for_same_question(): void
    {
        $result1 = (new ConsultOracle(question: 'Attack?'))();
        $result2 = (new ConsultOracle(question: 'Attack?'))();

        self::assertSame($result1['prophecy'], $result2['prophecy']);
    }
}
