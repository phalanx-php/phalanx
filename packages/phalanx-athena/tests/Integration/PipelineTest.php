<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Phalanx\Athena\Pipeline\Pipeline;
use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    #[Test]
    public function pipeline_creates_immutably(): void
    {
        $p1 = Pipeline::create();
        $p2 = $p1->step(new AddSuffix(' world'));

        $this->assertNotSame($p1, $p2);
    }

    #[Test]
    public function pipeline_with_input(): void
    {
        $pipeline = Pipeline::create()->run('test input');

        $this->assertInstanceOf(Pipeline::class, $pipeline);
    }

    #[Test]
    public function pipeline_step_composition(): void
    {
        $p = Pipeline::create()
            ->step(new AddSuffix(' first'))
            ->step(new AddSuffix(' second'));

        $this->assertInstanceOf(Pipeline::class, $p);
    }

    #[Test]
    public function pipeline_branch_composition(): void
    {
        $p = Pipeline::create()
            ->step(new AddSuffix(' classified'))
            ->branch(static fn(mixed $prev): Scopeable => new AddSuffix(' branched'));

        $this->assertInstanceOf(Pipeline::class, $p);
    }

    #[Test]
    public function pipeline_fan_composition(): void
    {
        $p = Pipeline::create()
            ->fan(...[
                new AddSuffix(' a'),
                new AddSuffix(' b'),
                new AddSuffix(' c'),
            ]);

        $this->assertInstanceOf(Pipeline::class, $p);
    }
}

/** @internal */
final readonly class AddSuffix implements Scopeable
{
    public function __construct(private string $suffix) {}

    public function __invoke(Scope $scope): string
    {
        return 'input' . $this->suffix;
    }
}
