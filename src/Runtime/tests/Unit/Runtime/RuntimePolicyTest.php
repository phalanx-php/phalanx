<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Runtime;

use InvalidArgumentException;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Boot\Exception\MissingContextValue;
use Phalanx\Runtime\RuntimeCapability;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimeHookSnapshot;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Runtime\SwooleHook;
use Phalanx\Scope\ScopeIdentity;
use PHPUnit\Framework\TestCase;

final class RuntimePolicyTest extends TestCase
{
    public function testPhalanxManagedPolicyContainsTheManagedRuntimeBaseline(): void
    {
        $policy = RuntimePolicy::phalanxManaged();

        self::assertTrue($policy->hasRequiredFlags(
            SwooleHook::Tcp->value
            | SwooleHook::Udp->value
            | SwooleHook::Unix->value
            | SwooleHook::Udg->value
            | SwooleHook::Ssl->value
            | SwooleHook::Tls->value
            | SwooleHook::StreamFunction->value
            | SwooleHook::File->value
            | SwooleHook::NativeCurl->value
            | SwooleHook::Sockets->value,
        ));

        self::assertSame(0, $policy->requiredFlags & SwooleHook::Curl->value);
        self::assertSame(0, $policy->requiredFlags & SwooleHook::PdoPgsql->value);
        self::assertSame(0, $policy->requiredFlags & SwooleHook::MongoDb->value);
    }

    public function testPhalanxManagedPolicyTreatsBroadSemanticHooksAsSensitive(): void
    {
        $policy = RuntimePolicy::phalanxManaged();

        self::assertSame(0, $policy->requiredFlags & SwooleHook::Sleep->value);
        self::assertSame(0, $policy->requiredFlags & SwooleHook::Stdio->value);
        self::assertSame(0, $policy->requiredFlags & SwooleHook::NetFunction->value);
        self::assertSame(0, $policy->requiredFlags & SwooleHook::Proc->value);
        self::assertSame(0, $policy->requiredFlags & SwooleHook::MongoDb->value);

        self::assertSame(SwooleHook::Sleep->value, $policy->sensitiveEnabledFlags(SwooleHook::Sleep->value));
        self::assertSame(SwooleHook::Stdio->value, $policy->sensitiveEnabledFlags(SwooleHook::Stdio->value));
        self::assertSame(SwooleHook::Proc->value, $policy->sensitiveEnabledFlags(SwooleHook::Proc->value));
        self::assertSame(SwooleHook::MongoDb->value, $policy->sensitiveEnabledFlags(SwooleHook::MongoDb->value));
        self::assertSame(
            SwooleHook::NetFunction->value,
            $policy->sensitiveEnabledFlags(SwooleHook::NetFunction->value),
        );
    }

    public function testCapabilityContextResolvesToPolicy(): void
    {
        $policy = RuntimePolicy::fromContext(new AppContext([
            RuntimePolicy::CONTEXT_CAPABILITIES => [
                RuntimeCapability::HttpClient,
                'files',
                'processes',
                'interactive_stdio',
            ],
        ]));

        self::assertSame(
            SwooleHook::Tcp->value
                | SwooleHook::NativeCurl->value
                | SwooleHook::File->value
                | SwooleHook::Stdio->value,
            $policy->requiredFlags
                & (
                    SwooleHook::Tcp->value
                    | SwooleHook::NativeCurl->value
                    | SwooleHook::File->value
                    | SwooleHook::Proc->value
                    | SwooleHook::Stdio->value
                ),
        );
    }

    public function testDatabaseCapabilitiesAreExplicitOptInHooks(): void
    {
        $policy = RuntimePolicy::forCapabilities(
            RuntimeCapability::PdoPgsql,
            RuntimeCapability::PdoSqlite,
            RuntimeCapability::MongoDb,
        );

        self::assertSame(
            SwooleHook::PdoPgsql->value | SwooleHook::PdoSqlite->value | SwooleHook::MongoDb->value,
            $policy->requiredFlags,
        );
        self::assertSame(SwooleHook::MongoDb->value, $policy->sensitiveEnabledFlags(SwooleHook::MongoDb->value));
    }

    public function testProcessesCapabilityDoesNotEnableSemanticProcessHooks(): void
    {
        $policy = RuntimePolicy::forCapabilities(RuntimeCapability::Processes);

        self::assertSame(0, $policy->requiredFlags & SwooleHook::Proc->value);
        self::assertSame(0, $policy->requiredFlags & SwooleHook::Stdio->value);
        self::assertSame(SwooleHook::Proc->value, $policy->sensitiveEnabledFlags(SwooleHook::Proc->value));
    }

    public function testInvalidCapabilityContextThrowsClearly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown runtime capability: mystery');

        RuntimePolicy::fromContext(new AppContext([
            RuntimePolicy::CONTEXT_CAPABILITIES => ['mystery'],
        ]));
    }

    public function testBuilderRuntimePolicyOverrideWinsOverInvalidContext(): void
    {
        $app = Application::starting([
            RuntimePolicy::CONTEXT_POLICY => 'invalid',
        ])
            ->withRuntimePolicy(RuntimePolicy::forCapabilities(RuntimeCapability::Network))
            ->compile();

        $scope = $app->createScope();
        self::assertInstanceOf(ScopeIdentity::class, $scope);
        self::assertNotSame('', $scope->scopeId);

        $scope->dispose();
    }

    public function testInvalidStrictContextThrowsClearly(): void
    {
        $this->expectException(MissingContextValue::class);
        $this->expectExceptionMessage(RuntimePolicy::CONTEXT_STRICT_HOOKS . '" expected bool, got string');

        Application::starting([
            RuntimePolicy::CONTEXT_STRICT_HOOKS => 'maybe',
        ])->compile();
    }

    public function testRuntimeHooksEnsureIsIdempotentAndPreservesExistingFlags(): void
    {
        $before = RuntimeHooks::currentFlags();
        $policy = RuntimePolicy::phalanxManaged();

        $first = RuntimeHooks::ensure($policy);
        $second = RuntimeHooks::ensure($policy);

        self::assertSame($first, $second);
        self::assertSame($before, $first & $before);
        self::assertTrue($policy->hasRequiredFlags($second));
    }

    public function testRuntimeHooksSnapshotReportsPolicyStateWithoutMutatingHooks(): void
    {
        $policy = RuntimePolicy::forCapabilities(
            RuntimeCapability::Network,
            RuntimeCapability::Sleep,
        );

        $snapshot = RuntimeHookSnapshot::fromFlags(
            $policy,
            SwooleHook::Tcp->value | SwooleHook::Ssl->value | SwooleHook::Sleep->value | SwooleHook::Stdio->value,
            SwooleHook::Tcp->value
                | SwooleHook::Unix->value
                | SwooleHook::Ssl->value
                | SwooleHook::Tls->value
                | SwooleHook::Sleep->value
                | SwooleHook::Stdio->value,
        );

        self::assertFalse($snapshot->isHealthy());
        self::assertSame($policy->name, $snapshot->policyName);
        self::assertSame(['TCP', 'SSL', 'SLEEP', 'STDIO'], $snapshot->currentFlagNames());
        self::assertSame(['UNIX', 'TLS'], $snapshot->missingFlagNames());
        self::assertSame(['SLEEP', 'STDIO'], $snapshot->sensitiveEnabledFlagNames());
        self::assertSame([
            'policy' => $policy->name,
            'current' => SwooleHook::Tcp->value
                | SwooleHook::Ssl->value
                | SwooleHook::Sleep->value
                | SwooleHook::Stdio->value,
            'available' => SwooleHook::Tcp->value
                | SwooleHook::Unix->value
                | SwooleHook::Ssl->value
                | SwooleHook::Tls->value
                | SwooleHook::Sleep->value
                | SwooleHook::Stdio->value,
            'required' => $policy->requiredFlags,
            'missing' => $snapshot->missingFlags,
            'unavailable_required' => 0,
            'sensitive_enabled' => SwooleHook::Sleep->value | SwooleHook::Stdio->value,
            'healthy' => false,
        ], $snapshot->toArray());
    }

    public function testUnavailableRequiredHooksMakeSnapshotUnhealthy(): void
    {
        $policy = RuntimePolicy::forCapabilities(RuntimeCapability::PdoPgsql);
        $snapshot = RuntimeHookSnapshot::fromFlags(
            $policy,
            SwooleHook::PdoPgsql->value,
            0,
        );

        self::assertFalse($snapshot->isHealthy());
        self::assertSame(SwooleHook::PdoPgsql->value, $snapshot->unavailableRequiredFlags);
        self::assertSame(['PDO_PGSQL'], $snapshot->unavailableRequiredFlagNames());
    }

    public function testHookNamesAreStableForDiagnostics(): void
    {
        self::assertSame(
            ['TCP', 'SSL', 'NATIVE_CURL'],
            SwooleHook::namesForMask(SwooleHook::Tcp->value | SwooleHook::Ssl->value | SwooleHook::NativeCurl->value),
        );

        self::assertSame(['PROC'], SwooleHook::namesForMask(SwooleHook::Proc->value));
        self::assertSame('SWOOLE_HOOK_PDO_PGSQL', SwooleHook::PdoPgsql->constantName());
    }

    public function testPhalanxManagedPolicyCanNameEveryRequiredHook(): void
    {
        $policy = RuntimePolicy::phalanxManaged();
        $names = SwooleHook::namesForMask($policy->requiredFlags);

        self::assertNotContains('PROC', $names);
        self::assertNotContains('STDIO', $names);
        self::assertNotContains('SLEEP', $names);
        self::assertNotContains('NET_FUNCTION', $names);
        self::assertNotContains('PDO_PGSQL', $names);
        self::assertNotContains('MONGODB', $names);
    }
}
