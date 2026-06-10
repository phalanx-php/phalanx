<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Contracts;

use Error;
use Phalanx\Err\Err;
use Phalanx\Err\Severity;
use Phalanx\Invocation\InvocationCtx;
use Phalanx\Scope\Scope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class PropertyHookContractTest extends TestCase
{
    #[Test]
    public function errDeclaresSeverityAsAGetOnlyHookRequirementWithoutMethods(): void
    {
        $reflection = new ReflectionClass(Err::class);

        self::assertSame([], $reflection->getMethods(), 'Hook requirements must not surface as interface methods.');

        $severity = $reflection->getProperty('severity');

        self::assertTrue($severity->isAbstract(), 'The requirement is abstract: implementers must provide it.');
        self::assertTrue($severity->isVirtual(), 'The requirement declares no backing storage.');
        self::assertSame(['get'], array_keys($severity->getHooks()), 'The contract demands read access only.');
        self::assertSame(Severity::class, (string) $severity->getType());
    }

    #[Test]
    public function hookedImplementationsDeriveSeverityFromLiveState(): void
    {
        $err = new AttemptScaledErr(attempt: 1);

        self::assertSame(Severity::Transient, $err->severity);

        $err->escalate();
        $err->escalate();

        self::assertSame(Severity::Fatal, $err->severity);
        self::assertTrue(
            new ReflectionProperty(AttemptScaledErr::class, 'severity')->isVirtual(),
            'A hooked severity is a derivation, not stored state.',
        );
    }

    #[Test]
    public function plainPropertyImplementationsSatisfyTheSameContract(): void
    {
        $err = new DeclaredSeverityErr(severity: Severity::Expected);

        self::assertInstanceOf(Err::class, $err);
        self::assertSame(Severity::Expected, $err->severity);
        self::assertFalse(
            new ReflectionProperty(DeclaredSeverityErr::class, 'severity')->isVirtual(),
            'A plain property satisfies the get requirement with stored state.',
        );
    }

    #[Test]
    public function getOnlyHookedSeverityRejectsWrites(): void
    {
        $err = new AttemptScaledErr(attempt: 1);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('read-only');

        $this->write($err, 'severity', Severity::Expected);
    }

    #[Test]
    public function ctxProjectionExposesScopeStateWithoutExposingTheScope(): void
    {
        $scope = new SpikeScope();
        $ctx = new SpikeJobCtx($scope);

        self::assertFalse($ctx->cancelled);

        $scope->cancel();

        self::assertTrue($ctx->cancelled, 'The projection reads live scope state, not a boot-time copy.');

        $publicProperties = array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            new ReflectionClass(SpikeJobCtx::class)->getProperties(ReflectionProperty::IS_PUBLIC),
        );

        self::assertSame(['cancelled'], $publicProperties, 'The scope reference must stay unreachable through Ctx.');
    }

    #[Test]
    public function ctxProjectionIsSealedAgainstOverrideAndWrites(): void
    {
        $projection = new ReflectionProperty(SpikeCtx::class, 'cancelled');

        self::assertTrue($projection->isFinal(), 'Subclasses must not replace the projection with writable storage.');
        self::assertTrue($projection->isVirtual());

        $ctx = new SpikeJobCtx(new SpikeScope());

        $this->expectException(Error::class);
        $this->expectExceptionMessage('read-only');

        $this->write($ctx, 'cancelled', true);
    }

    #[Test]
    public function theEngineRejectsErrImplementationsMissingTheSeverityContract(): void
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $script = sprintf('require %s; new class implements \Phalanx\Err\Err {};', var_export($autoload, true));
        $command = sprintf('%s -r %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($script));

        exec($command, $output, $exitCode);

        self::assertNotSame(0, $exitCode, 'Declaring an Err without severity must be an engine-level failure.');
        self::assertStringContainsString('Err::$severity::get', implode("\n", $output));
    }

    private function write(object $subject, string $property, mixed $value): void
    {
        $subject->{$property} = $value;
    }
}

final class AttemptScaledErr implements Err
{
    public Severity $severity {
        get => $this->attempt >= 3 ? Severity::Fatal : Severity::Transient;
    }

    public function __construct(
        private int $attempt,
    ) {
    }

    public function escalate(): void
    {
        $this->attempt++;
    }
}

final class DeclaredSeverityErr implements Err
{
    public function __construct(
        private(set) Severity $severity,
    ) {
    }
}

final class SpikeScope implements Scope
{
    private bool $cancelled = false;

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}

abstract class SpikeCtx implements InvocationCtx
{
    final public bool $cancelled {
        get => $this->scope->isCancelled();
    }

    public function __construct(
        private readonly SpikeScope $scope,
    ) {
    }
}

final class SpikeJobCtx extends SpikeCtx
{
}
