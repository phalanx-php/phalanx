<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Unit\Agent;

use AgentBridge\Agent\CreateLegos;
use Phalanx\Athena\Tool\Disposition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phalanx\Scope;

final class CreateLegosTest extends TestCase
{
    private Scope $scope;

    protected function setUp(): void
    {
        $this->scope = $this->createMock(Scope::class);
    }

    private static function validLego(string $name = 'click-submit', array $extraSteps = []): array
    {
        return [
            'name' => $name,
            'description' => 'Test lego',
            'steps' => array_merge(
                [['op' => 'click', 'selector' => '#submit']],
                $extraSteps,
            ),
        ];
    }

    #[Test]
    public function valid_lego_passes_through(): void
    {
        $tool = new CreateLegos([self::validLego()]);
        $outcome = $tool($this->scope);

        self::assertSame(Disposition::Terminate, $outcome->disposition);
        self::assertCount(1, $outcome->data);
        self::assertSame('click-submit', $outcome->data[0]['name']);
        self::assertSame([['op' => 'click', 'selector' => '#submit']], $outcome->data[0]['steps']);
    }

    #[Test]
    public function all_valid_ops_are_accepted(): void
    {
        $validOps = [
            'click', 'clickAll', 'type', 'fill', 'select', 'check', 'press',
            'scroll', 'waitForSelector', 'waitForRemoval', 'waitForText',
            'waitForNetwork', 'getAttribute', 'getTextContent', 'evaluate', 'delay',
        ];

        $steps = array_map(static fn(string $op) => ['op' => $op, 'selector' => '.el'], $validOps);

        $tool = new CreateLegos([['name' => 'all-ops', 'description' => '', 'steps' => $steps]]);
        $outcome = $tool($this->scope);

        self::assertCount(1, $outcome->data);
        self::assertCount(count($validOps), $outcome->data[0]['steps']);
    }

    #[Test]
    public function invalid_op_is_filtered_from_steps(): void
    {
        $lego = [
            'name' => 'mixed-steps',
            'description' => '',
            'steps' => [
                ['op' => 'click', 'selector' => '#btn'],
                ['op' => 'invalidOp', 'selector' => '#bad'],
                ['op' => 'fill', 'selector' => '#input', 'value' => 'hello'],
            ],
        ];

        $tool = new CreateLegos([$lego]);
        $outcome = $tool($this->scope);

        self::assertCount(1, $outcome->data);
        self::assertCount(2, $outcome->data[0]['steps']);
        self::assertSame('click', $outcome->data[0]['steps'][0]['op']);
        self::assertSame('fill', $outcome->data[0]['steps'][1]['op']);
    }

    #[Test]
    public function lego_with_all_invalid_steps_is_dropped(): void
    {
        $lego = [
            'name' => 'bad-steps',
            'description' => '',
            'steps' => [
                ['op' => 'flyToMoon'],
                ['op' => 'hackTheGibson'],
            ],
        ];

        $tool = new CreateLegos([$lego]);
        $outcome = $tool($this->scope);

        self::assertSame([], $outcome->data);
    }

    #[Test]
    public function lego_missing_name_is_dropped(): void
    {
        $lego = [
            'description' => 'No name',
            'steps' => [['op' => 'click', 'selector' => '#btn']],
        ];

        $tool = new CreateLegos([$lego]);
        $outcome = $tool($this->scope);

        self::assertSame([], $outcome->data);
    }

    #[Test]
    public function lego_missing_steps_is_dropped(): void
    {
        $lego = ['name' => 'no-steps', 'description' => 'Missing steps'];

        $tool = new CreateLegos([$lego]);
        $outcome = $tool($this->scope);

        self::assertSame([], $outcome->data);
    }

    #[Test]
    public function lego_with_non_array_steps_is_dropped(): void
    {
        $lego = ['name' => 'bad', 'description' => '', 'steps' => 'not-an-array'];

        $tool = new CreateLegos([$lego]);
        $outcome = $tool($this->scope);

        self::assertSame([], $outcome->data);
    }

    #[Test]
    public function empty_lego_list_returns_empty_done(): void
    {
        $tool = new CreateLegos([]);
        $outcome = $tool($this->scope);

        self::assertSame(Disposition::Terminate, $outcome->disposition);
        self::assertSame([], $outcome->data);
    }

    #[Test]
    public function description_defaults_to_empty_string_when_absent(): void
    {
        $lego = ['name' => 'no-desc', 'steps' => [['op' => 'click', 'selector' => '#btn']]];

        $tool = new CreateLegos([$lego]);
        $outcome = $tool($this->scope);

        self::assertCount(1, $outcome->data);
        self::assertSame('', $outcome->data[0]['description']);
    }

    #[Test]
    public function non_array_lego_entry_is_dropped(): void
    {
        $tool = new CreateLegos(['not-a-lego', null, 42, self::validLego()]);
        $outcome = $tool($this->scope);

        self::assertCount(1, $outcome->data);
        self::assertSame('click-submit', $outcome->data[0]['name']);
    }
}
