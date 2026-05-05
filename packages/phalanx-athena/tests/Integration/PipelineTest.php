<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Phalanx\Application;
use Phalanx\Athena\Pipeline\Pipeline;
use Phalanx\Scope\Scope;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class PipelineTest extends TestCase
{
    #[Test]
    public function pipelineCreatesImmutably(): void
    {
        $p1 = Pipeline::create();
        $p2 = $p1->step(new AddSuffix(' world'));

        $this->assertNotSame($p1, $p2);
    }

    #[Test]
    public function pipelineWithInput(): void
    {
        $pipeline = Pipeline::create()->run('test input');

        self::assertSame('test input', Application::starting()->run($pipeline));
    }

    #[Test]
    public function pipelineStepComposition(): void
    {
        AddSuffix::$calls = [];

        $p = Pipeline::create()
            ->step(new AddSuffix(' first'))
            ->step(new AddSuffix(' second'));

        self::assertSame('input second', Application::starting()->run($p));
        self::assertSame([' first', ' second'], AddSuffix::$calls);
    }

    #[Test]
    public function pipelineBranchComposition(): void
    {
        $p = Pipeline::create()
            ->step(new AddSuffix(' classified'))
            ->branch(static fn(mixed $prev): Scopeable => new AddSuffix(' branched after ' . $prev));

        self::assertSame('input branched after input classified', Application::starting()->run($p));
    }

    #[Test]
    public function pipelineFanComposition(): void
    {
        $p = Pipeline::create()
            ->fan(...[
                new AddSuffix(' a'),
                new AddSuffix(' b'),
                new AddSuffix(' c'),
            ]);

        self::assertSame([
            'input a',
            'input b',
            'input c',
        ], Application::starting()->run($p));
    }
}

/** @internal */
final class AddSuffix implements Scopeable
{
    /** @var list<string> */
    public static array $calls = [];

    public function __construct(
        private string $suffix,
    ) {
    }

    public function __invoke(Scope $scope): string
    {
        self::$calls[] = $this->suffix;

        return 'input' . $this->suffix;
    }
}
