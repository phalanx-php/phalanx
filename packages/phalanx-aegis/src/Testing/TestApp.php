<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Service\ServiceBundle;
use Phalanx\Testing\Attribute\Lens as LensAttribute;
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
 * Bundles that override the lens() hook activate their lenses automatically.
 * Aegis-native lenses (LedgerLens, ScopeLens, RuntimeLens) are registered
 * unconditionally; all other lenses require their bundle.
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

    /**
     * Lenses Aegis ships unconditionally. Always available on every TestApp
     * because Aegis itself is always present; no bundle declares them.
     *
     * @var list<class-string<Lens>>
     */
    private const array AEGIS_NATIVE_LENSES = [
        Lenses\LedgerLens::class,
        Lenses\ScopeLens::class,
        Lenses\RuntimeLens::class,
    ];

    public private(set) FakeRegistry $fakes;

    /** @var array<class-string<Lens>, Lens> */
    private array $instances = [];

    /** @var array<class-string<Lens>, class-string<LensFactory>> */
    private array $factories = [];

    /** @var array<class-string<Lens>, list<class-string<ServiceBundle>>> */
    private array $providers = [];

    /** @var array<class-string, object> */
    private array $primaryApps = [];

    private bool $shutdownComplete = false;

    private function __construct(public private(set) Application $application)
    {
        $this->fakes = new FakeRegistry();
    }

    /**
     * Boot a TestApp around a freshly compiled Application.
     */
    public static function boot(AppContext $context = new AppContext(), ServiceBundle ...$bundles): self
    {
        $builder = Application::starting($context);

        if ($bundles !== []) {
            $builder = $builder->providers(...$bundles);
        }

        $instance = new self($builder->compile());
        $instance->registerAegisNativeLenses();
        $instance->registerBundleLenses($bundles);

        return $instance;
    }

    /**
     * Register an application built outside of TestApp::boot — typically a
     * package-specific facade like StoaApplication or ArchonApplication that
     * already wraps an underlying AppHost. Lenses that depend on the
     * package-specific surface resolve it via primaryApp().
     *
     * @template T of object
     * @param T $primary
     * @return $this
     */
    public function withPrimary(object $primary): self
    {
        $this->primaryApps[$primary::class] = $primary;

        return $this;
    }

    /**
     * Look up a previously registered primary application by class.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function primaryApp(string $class): object
    {
        if (!isset($this->primaryApps[$class])) {
            throw new RuntimeException(
                "No primary application of type {$class} is registered on this TestApp. "
                . "Call \$app->withPrimary(\$instance) before resolving the dependent lens.",
            );
        }

        /** @var T */
        return $this->primaryApps[$class];
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
     * @template T of Lens
     * @param class-string<T> $class
     * @return T
     */
    public function lens(string $class): Lens
    {
        if (isset($this->instances[$class])) {
            /** @var T */
            return $this->instances[$class];
        }

        if (!isset($this->factories[$class])) {
            throw new LensNotAvailable($class, $this->providers[$class] ?? []);
        }

        $factoryClass = $this->factories[$class];
        /** @var LensFactory $factory */
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
            $this->primaryApps = [];
            $this->fakes = new FakeRegistry();
        }
    }

    /** @param class-string<Lens> $lensClass */
    private static function readLensAttribute(string $lensClass): LensAttribute
    {
        $reflection = new ReflectionClass($lensClass);
        $attributes = $reflection->getAttributes(LensAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes === []) {
            throw new RuntimeException(
                "Lens {$lensClass} is missing the #[\\Phalanx\\Testing\\Attribute\\Lens] attribute.",
            );
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Register the always-on Aegis-native lenses (LedgerLens, ScopeLens,
     * RuntimeLens). These are runtime-kernel facts, not optional package
     * contributions, so no bundle is required to activate them.
     */
    private function registerAegisNativeLenses(): void
    {
        foreach (self::AEGIS_NATIVE_LENSES as $lensClass) {
            $this->factories[$lensClass] = self::readLensAttribute($lensClass)->factory;
            $this->providers[$lensClass] = [];
        }
    }

    /**
     * Register all lenses contributed by the given bundles. Each lens class
     * is reflected for #[Lens] to recover its factory and exposed accessor.
     *
     * Bundles whose lens() returns TestLens::none() are silently skipped —
     * they contribute services but no lens surface.
     *
     * @param array<array-key, ServiceBundle> $bundles
     */
    private function registerBundleLenses(array $bundles): void
    {
        foreach ($bundles as $bundle) {
            $collection = ($bundle::class)::lens();

            foreach ($collection->all() as $lensClass) {
                $this->registerLens($lensClass, $bundle::class);
            }
        }
    }

    /**
     * Registration is idempotent. The #[Lens] attribute on the lens class
     * fixes the factory, so two bundles declaring the same lens cannot
     * conflict on factory choice; both are recorded as providers for
     * diagnostic purposes.
     *
     * @param class-string<Lens>         $lensClass
     * @param class-string<ServiceBundle> $providerBundle
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
