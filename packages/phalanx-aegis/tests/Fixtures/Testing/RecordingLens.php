<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Testing\Attribute\TestLens;
use Phalanx\Testing\TestApp;
use Phalanx\Testing\TestLens as TestLensContract;

/**
 * Lens fixture that exercises the fake/service integration. Construction
 * pulls a faked service from TestApp; reset clears recorded interactions.
 *
 * Models the realistic shape of a package lens: factory passes TestApp,
 * lens looks up its dependency via $app->service(...), and reset clears
 * per-test state.
 */
#[TestLens(
    accessor: 'recording',
    returns: self::class,
    factory: RecordingLensFactory::class,
    requires: [RecordingLensTarget::class],
)]
final class RecordingLens implements TestLensContract
{
    /** @var list<string> */
    public array $observed = [];

    public function __construct(public readonly RecordingLensTarget $target)
    {
    }

    public function record(string $event): void
    {
        $this->observed[] = $event;
    }

    public function reset(): void
    {
        $this->observed = [];
    }
}
