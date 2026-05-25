<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\State;

use Phalanx\Theatron\Reactive\Tracker;
use Phalanx\Theatron\State\Store;
use Phalanx\Theatron\State\StoreSubscription;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StoreTest extends TestCase
{
    #[Test]
    public function readReturnsRegisteredSlice(): void
    {
        $store = new OlympusStore();

        self::assertSame([], $store->heroes->names);
    }

    #[Test]
    public function readRecordsAccessInTracker(): void
    {
        $store = new OlympusStore();
        $frame = Tracker::push();

        self::assertSame([], $store->heroes->names);

        $deps = Tracker::pop($frame);
        self::assertCount(1, $deps);
        self::assertSame($store, $deps[0]);
    }

    #[Test]
    public function writeUpdatesSlice(): void
    {
        $store = new OlympusStore();

        $store->heroes = new HeroSlice(['Leonidas']);

        self::assertSame(['Leonidas'], $store->heroes->names);
    }

    #[Test]
    public function writeNotifiesSubscribers(): void
    {
        $store = new OlympusStore();
        $calls = 0;

        $store->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $store->heroes = new HeroSlice(['Achilles']);

        self::assertSame(1, $calls);
    }

    #[Test]
    public function mutateAppliesTransformAndNotifies(): void
    {
        $store = new OlympusStore();
        $calls = 0;

        $store->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $store->mutate(HeroSlice::class, static fn(HeroSlice $s): HeroSlice => $s->add('Odysseus'));

        self::assertSame(1, $calls);
        self::assertSame(['Odysseus'], $store->heroes->names);
    }

    #[Test]
    public function mutateReturnsNewValue(): void
    {
        $store = new OlympusStore();

        $result = $store->mutate(HeroSlice::class, static fn(HeroSlice $s): HeroSlice => $s->add('Pericles'));

        self::assertInstanceOf(HeroSlice::class, $result);
        self::assertSame(['Pericles'], $result->names);
    }

    #[Test]
    public function subscribeRequiresStaticClosure(): void
    {
        $store = new OlympusStore();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('static');

        $store->subscribe(function (): void {
        });
    }

    #[Test]
    public function unsubscribeStopsNotification(): void
    {
        $store = new OlympusStore();
        $count = 0;

        $sub = $store->subscribe(static function () use (&$count): void {
            $count++;
        });

        $store->heroes = new HeroSlice(['Zeus']);
        self::assertSame(1, $count);

        $sub->dispose();

        $store->heroes = new HeroSlice(['Apollo']);
        self::assertSame(1, $count);
    }

    #[Test]
    public function propertyHookReadRoutesToStore(): void
    {
        $store = new OlympusStore();
        $frame = Tracker::push();

        $heroes = $store->heroes;

        $deps = Tracker::pop($frame);
        self::assertCount(1, $deps);
        self::assertInstanceOf(HeroSlice::class, $heroes);
    }

    #[Test]
    public function propertyHookSetRoutesToStore(): void
    {
        $store = new OlympusStore();
        $calls = 0;

        $store->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $store->heroes = new HeroSlice(['Themistocles']);

        self::assertSame(1, $calls);
        self::assertSame(['Themistocles'], $store->heroes->names);
    }

    #[Test]
    public function sliceIsCopyOnModify(): void
    {
        $original = new HeroSlice(['Sparta']);
        $modified = $original->add('Marathon');

        self::assertSame(['Sparta'], $original->names);
        self::assertSame(['Sparta', 'Marathon'], $modified->names);
        self::assertNotSame($original, $modified);
    }

    #[Test]
    public function registerDuplicateThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already registered');

        new DuplicateRegistrationStore();
    }

    #[Test]
    public function readUnregisteredThrows(): void
    {
        $store = new EmptyStore();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not registered');

        $store->readUnregistered();
    }

    #[Test]
    public function writeTypeMismatchThrows(): void
    {
        $store = new OlympusStore();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected instance of');

        $store->writeMismatch();
    }

    #[Test]
    public function multipleSubscribersAllFire(): void
    {
        $store = new OlympusStore();
        $firstCalls = 0;
        $secondCalls = 0;

        $store->subscribe(static function () use (&$firstCalls): void {
            $firstCalls++;
        });

        $store->subscribe(static function () use (&$secondCalls): void {
            $secondCalls++;
        });

        $store->heroes = new HeroSlice(['Aristotle']);

        self::assertSame(1, $firstCalls);
        self::assertSame(1, $secondCalls);
    }

    #[Test]
    public function writeSameObjectStillNotifies(): void
    {
        $store = new OlympusStore();
        $calls = 0;
        $slice = new HeroSlice(['Socrates']);

        $store->heroes = $slice;

        $store->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $store->heroes = $slice;

        self::assertSame(1, $calls);
    }

    #[Test]
    public function writeUnregisteredThrows(): void
    {
        $store = new EmptyStore();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not registered');

        $store->writeUnregistered();
    }

    #[Test]
    public function mutateUnregisteredThrows(): void
    {
        $store = new EmptyStore();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not registered');

        $store->mutate(HeroSlice::class, static fn(HeroSlice $s): HeroSlice => $s->add('Ghost'));
    }

    #[Test]
    public function multipleSlicesWorkIndependently(): void
    {
        $store = new PantheonStore();

        $store->heroes = new HeroSlice(['Leonidas']);

        self::assertSame(['Leonidas'], $store->heroes->names);
        self::assertSame('Zeus', $store->deity->name);
    }

    #[Test]
    public function subscriberDisposingDuringNotifyIsSafe(): void
    {
        $store = new OlympusStore();
        $log = [];

        $sub = null;
        $sub = $store->subscribe(static function () use (&$sub, &$log): void {
            $log[] = 'first';
            /** @var StoreSubscription $sub */
            $sub->dispose();
        });

        $store->subscribe(static function () use (&$log): void {
            $log[] = 'second';
        });

        $store->heroes = new HeroSlice(['Theseus']);

        self::assertSame(['first', 'second'], $log);
        self::assertTrue($sub->isDisposed);
    }

    #[Test]
    public function subscriptionDisposeIsIdempotent(): void
    {
        $store = new OlympusStore();

        $sub = $store->subscribe(static function (): void {
        });

        $sub->dispose();
        $sub->dispose();

        self::assertTrue($sub->isDisposed);
    }

    protected function tearDown(): void
    {
        while (Tracker::isTracking()) {
            Tracker::pop(0);
        }
    }
}

final class HeroSlice
{
    /**
     * @param list<string> $names
     */
    public function __construct(
        private(set) array $names = [],
    ) {
    }

    public function add(string $name): self
    {
        return new self([...$this->names, $name]);
    }
}

final class OlympusStore extends Store
{
    public HeroSlice $heroes {
        get => $this->read(HeroSlice::class);
        set {
            $this->write(HeroSlice::class, $value);
        }
    }

    public function __construct()
    {
        $this->register(HeroSlice::class, new HeroSlice());
    }

    public function writeMismatch(): void
    {
        $this->write(HeroSlice::class, new \stdClass());
    }
}

final class EmptyStore extends Store
{
    public function readUnregistered(): void
    {
        $this->read(HeroSlice::class);
    }

    public function writeUnregistered(): void
    {
        $this->write(HeroSlice::class, new HeroSlice());
    }
}

final class DeitySlice
{
    public function __construct(
        private(set) string $name = 'Zeus',
    ) {
    }
}

final class PantheonStore extends Store
{
    public HeroSlice $heroes {
        get => $this->read(HeroSlice::class);
        set {
            $this->write(HeroSlice::class, $value);
        }
    }

    public DeitySlice $deity {
        get => $this->read(DeitySlice::class);
        set {
            $this->write(DeitySlice::class, $value);
        }
    }

    public function __construct()
    {
        $this->register(HeroSlice::class, new HeroSlice());
        $this->register(DeitySlice::class, new DeitySlice());
    }
}

final class DuplicateRegistrationStore extends Store
{
    public function __construct()
    {
        $this->register(HeroSlice::class, new HeroSlice());
        $this->register(HeroSlice::class, new HeroSlice());
    }
}
