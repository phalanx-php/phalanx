<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Runtime;

use InvalidArgumentException;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Boot\Exception\MissingContextValue;
use Phalanx\Runtime\RuntimeCapability;
use Phalanx\Runtime\RuntimeHookNames;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimeHookSnapshot;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Scope\ScopeIdentity;
use PHPUnit\Framework\TestCase;

final class RuntimePolicyTest extends TestCase
{
    public function testPhalanxManagedPolicyContainsTheManagedRuntimeBaseline(): void
    {
        $policy = RuntimePolicy::phalanxManaged();

        self::assertTrue($policy->hasRequiredFlags(
            SWOOLE_HOOK_TCP
            | SWOOLE_HOOK_UDP
            | SWOOLE_HOOK_UNIX
            | SWOOLE_HOOK_UDG
            | SWOOLE_HOOK_SSL
            | SWOOLE_HOOK_TLS
            | SWOOLE_HOOK_STREAM_FUNCTION
            | SWOOLE_HOOK_FILE
            | SWOOLE_HOOK_CURL
            | SWOOLE_HOOK_NATIVE_CURL
            | SWOOLE_HOOK_SOCKETS,
        ));
    }

    public function testPhalanxManagedPolicyTreatsBroadSemanticHooksAsSensitive(): void
    {
        $policy = RuntimePolicy::phalanxManaged();

        self::assertSame(0, $policy->requiredFlags & SWOOLE_HOOK_SLEEP);
        self::assertSame(0, $policy->requiredFlags & SWOOLE_HOOK_STDIO);
        self::assertSame(0, $policy->requiredFlags & SWOOLE_HOOK_NET_FUNCTION);
        self::assertSame(0, $policy->requiredFlags & SWOOLE_HOOK_PROC);

        self::assertSame(SWOOLE_HOOK_SLEEP, $policy->sensitiveEnabledFlags(SWOOLE_HOOK_SLEEP));
        self::assertSame(SWOOLE_HOOK_STDIO, $policy->sensitiveEnabledFlags(SWOOLE_HOOK_STDIO));
        self::assertSame(SWOOLE_HOOK_PROC, $policy->sensitiveEnabledFlags(SWOOLE_HOOK_PROC));
        self::assertSame(
            SWOOLE_HOOK_NET_FUNCTION,
            $policy->sensitiveEnabledFlags(SWOOLE_HOOK_NET_FUNCTION),
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
            SWOOLE_HOOK_TCP | SWOOLE_HOOK_NATIVE_CURL | SWOOLE_HOOK_FILE | SWOOLE_HOOK_STDIO,
            $policy->requiredFlags
                & (SWOOLE_HOOK_TCP | SWOOLE_HOOK_NATIVE_CURL | SWOOLE_HOOK_FILE | SWOOLE_HOOK_PROC | SWOOLE_HOOK_STDIO),
        );
    }

    public function testProcessesCapabilityDoesNotEnableSemanticProcessHooks(): void
    {
        $policy = RuntimePolicy::forCapabilities(RuntimeCapability::Processes);

        self::assertSame(0, $policy->requiredFlags & SWOOLE_HOOK_PROC);
        self::assertSame(0, $policy->requiredFlags & SWOOLE_HOOK_STDIO);
        self::assertSame(SWOOLE_HOOK_PROC, $policy->sensitiveEnabledFlags(SWOOLE_HOOK_PROC));
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
            SWOOLE_HOOK_TCP | SWOOLE_HOOK_SSL | SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_STDIO,
        );

        self::assertFalse($snapshot->isHealthy());
        self::assertSame($policy->name, $snapshot->policyName);
        self::assertSame(['TCP', 'SSL', 'SLEEP', 'STDIO'], $snapshot->currentFlagNames());
        self::assertSame(['UNIX', 'TLS'], $snapshot->missingFlagNames());
        self::assertSame(['SLEEP', 'STDIO'], $snapshot->sensitiveEnabledFlagNames());
        self::assertSame([
            'policy' => $policy->name,
            'current' => SWOOLE_HOOK_TCP | SWOOLE_HOOK_SSL | SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_STDIO,
            'required' => $policy->requiredFlags,
            'missing' => $snapshot->missingFlags,
            'sensitive_enabled' => SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_STDIO,
            'healthy' => false,
        ], $snapshot->toArray());
    }

    public function testHookNamesAreStableForDiagnostics(): void
    {
        self::assertSame(
            ['TCP', 'SSL', 'NATIVE_CURL'],
            RuntimeHookNames::forMask(SWOOLE_HOOK_TCP | SWOOLE_HOOK_SSL | SWOOLE_HOOK_NATIVE_CURL),
        );

        self::assertSame(['PROC'], RuntimeHookNames::forMask(SWOOLE_HOOK_PROC));
    }

    public function testPhalanxManagedPolicyCanNameEveryRequiredHook(): void
    {
        $policy = RuntimePolicy::phalanxManaged();
        $names = RuntimeHookNames::forMask($policy->requiredFlags);

        self::assertNotContains('PROC', $names);
        self::assertNotContains('STDIO', $names);
        self::assertNotContains('SLEEP', $names);
        self::assertNotContains('BLOCKING_FUNCTION', $names);
    }
}
