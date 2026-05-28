<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Unit\Agent;

use AgentBridge\Agent\RepairLego;
use Phalanx\Athena\Tool\Disposition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phalanx\Scope;

final class RepairLegoTest extends TestCase
{
    private Scope $scope;

    protected function setUp(): void
    {
        $this->scope = $this->createMock(Scope::class);
    }

    #[Test]
    public function valid_steps_return_done(): void
    {
        $steps = [
            ['op' => 'click', 'selector' => '[data-action="archive"]'],
            ['op' => 'waitForRemoval', 'selector' => '.email-row.selected', 'timeoutMs' => 5000],
        ];

        $tool = new RepairLego($steps);
        $outcome = $tool($this->scope);

        self::assertSame(Disposition::Terminate, $outcome->disposition);
        self::assertSame($steps, $outcome->data);
    }

    #[Test]
    public function empty_steps_return_retry(): void
    {
        $tool = new RepairLego([]);
        $outcome = $tool($this->scope);

        self::assertSame(Disposition::Retry, $outcome->disposition);
        self::assertNull($outcome->data);
        self::assertStringContainsString('No steps provided', $outcome->reason);
    }

    #[Test]
    public function retry_reason_prompts_analysis(): void
    {
        $tool = new RepairLego([]);
        $outcome = $tool($this->scope);

        self::assertNotEmpty($outcome->reason);
        self::assertStringContainsString('Analyze the DOM', $outcome->reason);
    }

    #[Test]
    public function single_step_is_valid(): void
    {
        $steps = [['op' => 'fill', 'selector' => '#email', 'value' => 'test@example.com']];

        $tool = new RepairLego($steps);
        $outcome = $tool($this->scope);

        self::assertSame(Disposition::Terminate, $outcome->disposition);
        self::assertCount(1, $outcome->data);
    }
}
