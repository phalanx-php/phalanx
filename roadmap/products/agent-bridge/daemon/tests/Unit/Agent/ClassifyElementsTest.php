<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Unit\Agent;

use AgentBridge\Agent\ClassifyElements;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Athena\Tool\Disposition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phalanx\Scope;

final class ClassifyElementsTest extends TestCase
{
    private Scope $scope;

    protected function setUp(): void
    {
        $this->scope = $this->createMock(Scope::class);
    }

    #[Test]
    public function valid_classifications_pass_through(): void
    {
        $classifications = [
            ['legoName' => 'archive-email', 'confidence' => 0.92, 'elementIndices' => [0, 1]],
            ['legoName' => 'mark-read', 'confidence' => 0.75],
        ];

        $tool = new ClassifyElements($classifications);
        $outcome = $tool($this->scope);

        self::assertSame(Disposition::Terminate, $outcome->disposition);
        self::assertCount(2, $outcome->data);
        self::assertSame('archive-email', $outcome->data[0]['legoName']);
        self::assertSame(0.92, $outcome->data[0]['confidence']);
    }

    #[Test]
    public function integer_confidence_is_accepted(): void
    {
        $classifications = [
            ['legoName' => 'click-submit', 'confidence' => 1],
        ];

        $tool = new ClassifyElements($classifications);
        $outcome = $tool($this->scope);

        self::assertCount(1, $outcome->data);
    }

    #[Test]
    public function missing_lego_name_is_filtered(): void
    {
        $classifications = [
            ['confidence' => 0.9, 'elementIndices' => []],
        ];

        $tool = new ClassifyElements($classifications);
        $outcome = $tool($this->scope);

        self::assertSame([], $outcome->data);
    }

    #[Test]
    public function missing_confidence_is_filtered(): void
    {
        $classifications = [
            ['legoName' => 'archive-email'],
        ];

        $tool = new ClassifyElements($classifications);
        $outcome = $tool($this->scope);

        self::assertSame([], $outcome->data);
    }

    #[Test]
    public function non_string_lego_name_is_filtered(): void
    {
        $classifications = [
            ['legoName' => 42, 'confidence' => 0.9],
        ];

        $tool = new ClassifyElements($classifications);
        $outcome = $tool($this->scope);

        self::assertSame([], $outcome->data);
    }

    #[Test]
    public function non_array_entry_is_filtered(): void
    {
        $classifications = ['not-an-array', null, 123];

        $tool = new ClassifyElements($classifications);
        $outcome = $tool($this->scope);

        self::assertSame([], $outcome->data);
    }

    #[Test]
    public function empty_classifications_return_empty_done(): void
    {
        $tool = new ClassifyElements([]);
        $outcome = $tool($this->scope);

        self::assertSame(Disposition::Terminate, $outcome->disposition);
        self::assertSame([], $outcome->data);
    }

    #[Test]
    public function mixed_valid_and_invalid_entries_only_returns_valid(): void
    {
        $classifications = [
            ['legoName' => 'valid-lego', 'confidence' => 0.8],
            ['confidence' => 0.9],  // missing legoName
            ['legoName' => 'another-valid', 'confidence' => 0.6],
            'scalar',               // not an array
        ];

        $tool = new ClassifyElements($classifications);
        $outcome = $tool($this->scope);

        self::assertCount(2, $outcome->data);
        self::assertSame('valid-lego', $outcome->data[0]['legoName']);
        self::assertSame('another-valid', $outcome->data[1]['legoName']);
    }

    #[Test]
    public function result_is_re_indexed(): void
    {
        $classifications = [
            ['confidence' => 0.9],           // filtered
            ['legoName' => 'keep', 'confidence' => 0.7],
        ];

        $tool = new ClassifyElements($classifications);
        $outcome = $tool($this->scope);

        self::assertArrayHasKey(0, $outcome->data);
        self::assertArrayNotHasKey(1, $outcome->data);
    }
}
