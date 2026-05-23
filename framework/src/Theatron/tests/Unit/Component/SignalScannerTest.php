<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Component;

use Phalanx\Theatron\Component\SignalScanner;
use Phalanx\Theatron\Reactive\DirtyBatch;
use Phalanx\Theatron\Reactive\Resource;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Reactive\SignalRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SignalScannerTest extends TestCase
{
    #[Test]
    public function findsSignalProperties(): void
    {
        $component = new class () {
            public function __construct(
                private(set) Signal $count = new Signal(0),
                private(set) Signal $name = new Signal(''),
            ) {
            }
        };

        $result = SignalScanner::scan($component, new DirtyBatch());

        self::assertCount(2, $result->ownedSignals);
    }

    #[Test]
    public function subscribesSignalsToDirtyBatch(): void
    {
        $component = new class () {
            public function __construct(
                private(set) Signal $count = new Signal(0),
            ) {
            }
        };

        $batch = new DirtyBatch();
        $result = SignalScanner::scan($component, $batch);

        self::assertFalse($batch->isDirty);

        $component->count->set(42);

        self::assertTrue($batch->isDirty);
        self::assertCount(1, $result->subscriptions);
    }

    #[Test]
    public function subscribesResourcesToDirtyBatch(): void
    {
        $component = new class (new Resource(static fn(): iterable => ['hello'])) {
            public function __construct(
                private(set) Resource $reply,
            ) {
            }
        };

        $batch = new DirtyBatch();
        $result = SignalScanner::scan($component, $batch);

        self::assertFalse($batch->isDirty);

        $component->reply->stream();

        self::assertTrue($batch->isDirty);
        self::assertCount(1, $result->subscriptions);
    }

    #[Test]
    public function identifiesOwnedSignals(): void
    {
        $component = new class () {
            public function __construct(
                private(set) Signal $count = new Signal(0),
            ) {
            }
        };

        $result = SignalScanner::scan($component, new DirtyBatch());

        self::assertCount(1, $result->ownedSignals);
        self::assertSame($component->count, $result->ownedSignals[0]);
    }

    #[Test]
    public function identifiesBorrowedSignals(): void
    {
        $shared = new Signal('parent-owned');

        $component = new class ($shared) {
            public function __construct(
                private(set) Signal $input,
            ) {
            }
        };

        $result = SignalScanner::scan($component, new DirtyBatch(), ['input' => $shared]);

        self::assertCount(0, $result->ownedSignals);
        self::assertCount(1, $result->subscriptions);
    }

    #[Test]
    public function mixedOwnedAndBorrowed(): void
    {
        $shared = new Signal('from-parent');

        $component = new class ($shared) {
            public function __construct(
                private(set) Signal $borrowed,
                private(set) Signal $local = new Signal(0),
            ) {
            }
        };

        $result = SignalScanner::scan($component, new DirtyBatch(), ['borrowed' => $shared]);

        self::assertCount(1, $result->ownedSignals);
        self::assertSame($component->local, $result->ownedSignals[0]);
        self::assertCount(2, $result->subscriptions);
    }

    #[Test]
    public function ignoresNonSignalProperties(): void
    {
        $component = new class () {
            public function __construct(
                private(set) string $label = 'test',
                private(set) int $count = 0,
                private(set) Signal $signal = new Signal(false),
            ) {
            }
        };

        $result = SignalScanner::scan($component, new DirtyBatch());

        self::assertCount(1, $result->ownedSignals);
    }

    #[Test]
    public function handlesNullableSignalProperty(): void
    {
        $component = new class () {
            public function __construct(
                private(set) ?Signal $maybeSignal = null,
                private(set) Signal $real = new Signal(0),
            ) {
            }
        };

        $result = SignalScanner::scan($component, new DirtyBatch());

        self::assertCount(1, $result->ownedSignals);
        self::assertSame($component->real, $result->ownedSignals[0]);
    }

    #[Test]
    public function emptyComponentReturnsEmptyResult(): void
    {
        $component = new class () {
        };

        $result = SignalScanner::scan($component, new DirtyBatch());

        self::assertCount(0, $result->ownedSignals);
        self::assertCount(0, $result->subscriptions);
    }

    #[Test]
    public function registersSignalsInRegistryWhenProvided(): void
    {
        $registry = new SignalRegistry();

        $component = new class () {
            public function __construct(
                private(set) Signal $inputText = new Signal(''),
            ) {
            }
        };

        SignalScanner::scan($component, new DirtyBatch(), registry: $registry);

        $snapshot = $registry->snapshot();
        self::assertCount(1, $snapshot);

        self::assertStringEndsWith('::inputText', $snapshot[0]->label);
    }

    #[Test]
    public function skipsRegistrationWhenNoRegistry(): void
    {
        $component = new class () {
            public function __construct(
                private(set) Signal $count = new Signal(0),
            ) {
            }
        };

        $result = SignalScanner::scan($component, new DirtyBatch());

        self::assertCount(1, $result->ownedSignals);
    }
}
