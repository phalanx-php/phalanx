<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Application;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Service\ServiceBundle;
use Phalanx\Testing\Attribute\TestLens as TestLensAttribute;
use Phalanx\Testing\Fakes\FakeRegistry;
use Phalanx\Testing\Generated\TestAppAccessors;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Root primitive for Phalanx integration tests and demos.
 *
 * TestApp boots a real Application through the production startup path,
 * exposes one typed property hook per registered lens via the codegen-emitted
 * TestAppAccessors trait, and tears down per test through PhalanxTestCase.
 *
 * Lifecycle:
 *
 *   $app = TestApp::boot($context, ...$bundles);   // boot, register lenses
 *   $app->ledger->assertNoOrphans();                // lens accessors lazy-resolve
 *   $app->reset();                                  // between PHPUnit tests
 *   $app->shutdown();                               // disposes Application
 *
 * Bundles that implement TestableBundle automatically activate their lenses.
 * Aegis-native lenses (LedgerLens, ScopeLens, TimeLens, RuntimeLens, FakeLens)
 * are registered unconditionally; all other lenses require their bundle.
 *
 * Hard-fail discipline: accessing $app->http when no Stoa bundle was passed
 * raises LensNotAvailable with a message naming the missing bundle.
 *
 * Lens authors must use $app->application->scoped(...) for in-scope work —
 * never $app->application->run(...), which shuts the host down at the end of
 * the call.
 */
final class TestApp
{
    use TestAppAccessors;

    public private(set) FakeRegistry $fakes;

    /** @var array<class-string<TestLens>, TestLens> */
    private array $instances = [];

    /** @var array<class-string<TestLens>, class-string<TestLensFactory>> */
    private array $factories = [];

    /** @var array<class-string<TestLens>, list<class-string<TestableBundle>>> */
    private array $providers = [];

    private bool $shutdownComplete = false;

    private function __construct(public private(set) Application $application)
    {
        $this->fakes = new FakeRegistry();
    }

    /**
     * Boot a TestApp around a freshly compiled Application.
     *
     * @param array<string, mixed> $context
     */
    public static function boot(array $context = [], ServiceBundle ...$bundles): self
    {
        $builder = Application::starting($context);

        if ($bundles !== []) {
            $builder = $builder->providers(...$bundles);
        }

        $instance = new self($builder->compile());
        $instance->registerBundleLenses($bundles);

        return $instance;
    }

    /**
     * @template T of object
     * @param class-string<T> $service
     * @param T $fake
     */
    public function fake(string $service, object $fake): self
    {
        $this->fakes->register($service, $fake);

        return $this;
    }

    /**
     * Resolve a service, preferring registered fakes over bundle bindings.
     *
     * Lenses use this to reach into the live application (e.g., HttpLens
     * reads StoaApplication, AthenaLens reads ProviderConfig). Bundle-internal
     * resolution is unaffected — fakes only intercept direct lens access.
     *
     * For non-fake bundle-bound services, lenses must drive an active scope
     * via $app->application->scoped(...) — never run(...), which tears the
     * application down at the end of the call.
     *
     * @template T of object
     * @param class-string<T> $service
     * @return T
     */
    public function service(string $service): object
    {
        $fake = $this->fakes->get($service);
        if ($fake !== null) {
            /** @var T */
            return $fake;
        }

        throw new RuntimeException(
            "TestApp::service({$service}) currently resolves only registered fakes. "
            . 'Lenses requiring bundle-bound services must drive a scope via '
            . '$app->application->scoped(...).',
        );
    }

    /**
     * Lazily resolve a lens. Called by generated property hooks in the
     * TestAppAccessors trait.
     *
     * @template T of TestLens
     * @param class-string<T> $class
     * @return T
     */
    public function lens(string $class): TestLens
    {
        if (isset($this->instances[$class])) {
            /** @var T */
            return $this->instances[$class];
        }

        if (!isset($this->factories[$class])) {
            throw new LensNotAvailable($class, $this->providers[$class] ?? []);
        }

        $factoryClass = $this->factories[$class];
        /** @var TestLensFactory $factory */
        $factory = new $factoryClass();
        $lens = $factory->create($this);
        $this->instances[$class] = $lens;

        /** @var T */
        return $lens;
    }

    /**
     * Reset every instantiated lens and clear fakes, leaving the underlying
     * Application intact. Called between tests by PhalanxTestCase.
     *
     * fake reset is guaranteed to run even if a lens reset throws — fakes
     * leaking across tests would silently corrupt subsequent assertions, so
     * the registry takes precedence over per-lens cleanup. Lens reset
     * exceptions are suppressed in aggregate and re-raised as one
     * RuntimeException at the end so callers see the original cause.
     */
    public function reset(): void
    {
        /** @var list<Throwable> $failures */
        $failures = [];

        try {
            foreach ($this->instances as $lens) {
                try {
                    $lens->reset();
                } catch (Cancelled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $failures[] = $e;
                }
            }
        } finally {
            $this->fakes->reset();
        }

        if ($failures !== []) {
            $messages = array_map(
                static fn(Throwable $e): string => $e::class . ': ' . $e->getMessage(),
                $failures,
            );
            throw new RuntimeException(
                'One or more lenses failed to reset: '
                . implode('; ', $messages),
                previous: $failures[0],
            );
        }
    }

    /**
     * Tear down the underlying Application and clear the lens cache.
     * Idempotent and exception-safe: lens reset failures during shutdown are
     * swallowed because shutdown is the teardown step — by the time it runs,
     * any lens failure that mattered to the test should have already been
     * raised by an earlier explicit reset() call. Propagating failures here
     * would mask the actual test outcome reported by PHPUnit.
     *
     * Cancellation propagates — a Cancelled escape from a lens reset is
     * never swallowed even at shutdown.
     */
    public function shutdown(): void
    {
        if ($this->shutdownComplete) {
            return;
        }

        $this->shutdownComplete = true;

        try {
            try {
                $this->reset();
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable) {
            }
        } finally {
            $this->application->shutdown();
            $this->instances = [];
            $this->factories = [];
            $this->providers = [];
            $this->fakes = new FakeRegistry();
        }
    }

    /** @param class-string<TestLens> $lensClass */
    private static function readLensAttribute(string $lensClass): TestLensAttribute
    {
        $reflection = new ReflectionClass($lensClass);
        $attributes = $reflection->getAttributes(TestLensAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes === []) {
            throw new RuntimeException(
                "Lens {$lensClass} is missing the #[\\Phalanx\\Testing\\Attribute\\TestLens] attribute.",
            );
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Register all lenses contributed by the given bundles. Each lens class
     * is reflected for #[TestLens] to recover its factory and exposed accessor.
     *
     * Bundles that do not implement TestableBundle are silently ignored —
     * they contribute services but no lens surface.
     *
     * @param array<array-key, ServiceBundle> $bundles
     */
    private function registerBundleLenses(array $bundles): void
    {
        foreach ($bundles as $bundle) {
            if (!$bundle instanceof TestableBundle) {
                continue;
            }

            foreach ($bundle::testLenses() as $lensClass) {
                $this->registerLens($lensClass, $bundle::class);
            }
        }
    }

    /**
     * Registration is idempotent. The #[TestLens] attribute on the lens class
     * fixes the factory, so two bundles declaring the same lens cannot
     * conflict on factory choice; both are recorded as providers for
     * diagnostic purposes.
     *
     * @param class-string<TestLens>       $lensClass
     * @param class-string<TestableBundle> $providerBundle
     */
    private function registerLens(string $lensClass, string $providerBundle): void
    {
        $this->factories[$lensClass] = self::readLensAttribute($lensClass)->factory;
        $this->providers[$lensClass] ??= [];

        if (!in_array($providerBundle, $this->providers[$lensClass], true)) {
            $this->providers[$lensClass][] = $providerBundle;
        }
    }
}
