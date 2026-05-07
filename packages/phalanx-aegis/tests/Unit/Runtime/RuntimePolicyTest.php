<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Runtime;

use InvalidArgumentException;
use OpenSwoole\Runtime;
use Phalanx\Application;
use Phalanx\Runtime\RuntimeCapability;
use Phalanx\Runtime\RuntimeHookNames;
use Phalanx\Runtime\RuntimeHookSnapshot;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Scope\ScopeIdentity;
use PHPUnit\Framework\TestCase;

final class RuntimePolicyTest extends TestCase
{
    public function testPhalanxManagedPolicyContainsTheManagedRuntimeBaseline(): void
    {
        $policy = RuntimePolicy::phalanxManaged();

        self::assertTrue($policy->hasRequiredFlags(
            Runtime::HOOK_TCP
            | Runtime::HOOK_UDP
            | Runtime::HOOK_UNIX
            | Runtime::HOOK_UDG
            | Runtime::HOOK_SSL
            | Runtime::HOOK_TLS
            | Runtime::HOOK_STREAM_FUNCTION
            | Runtime::HOOK_FILE
            | Runtime::HOOK_PROC
            | Runtime::HOOK_CURL
            | Runtime::HOOK_NATIVE_CURL
            | Runtime::HOOK_SOCKETS,
        ));
    }

    public function testPhalanxManagedPolicyTreatsBroadSemanticHooksAsSensitive(): void
    {
        $policy = RuntimePolicy::phalanxManaged();

        self::assertSame(0, $policy->requiredFlags & Runtime::HOOK_SLEEP);
        self::assertSame(0, $policy->requiredFlags & Runtime::HOOK_STDIO);
        self::assertSame(0, $policy->requiredFlags & Runtime::HOOK_BLOCKING_FUNCTION);

        self::assertSame(Runtime::HOOK_SLEEP, $policy->sensitiveEnabledFlags(Runtime::HOOK_SLEEP));
        self::assertSame(Runtime::HOOK_STDIO, $policy->sensitiveEnabledFlags(Runtime::HOOK_STDIO));
        self::assertSame(
            Runtime::HOOK_BLOCKING_FUNCTION,
            $policy->sensitiveEnabledFlags(Runtime::HOOK_BLOCKING_FUNCTION),
        );
    }

    public function testCapabilityContextResolvesToPolicy(): void
    {
        $policy = RuntimePolicy::fromContext([
            RuntimePolicy::CONTEXT_CAPABILITIES => [
                RuntimeCapability::HttpClient,
                'files',
                'processes',
                'interactive_stdio',
            ],
        ]);

        self::assertSame(
            Runtime::HOOK_TCP | Runtime::HOOK_NATIVE_CURL | Runtime::HOOK_FILE | Runtime::HOOK_PROC | Runtime::HOOK_STDIO,
            $policy->requiredFlags
                & (Runtime::HOOK_TCP | Runtime::HOOK_NATIVE_CURL | Runtime::HOOK_FILE | Runtime::HOOK_PROC | Runtime::HOOK_STDIO),
        );
    }

    public function testProcessesCapabilityDoesNotEnableInteractiveStdio(): void
    {
        $policy = RuntimePolicy::forCapabilities(RuntimeCapability::Processes);

        self::assertSame(Runtime::HOOK_PROC, $policy->requiredFlags & Runtime::HOOK_PROC);
        self::assertSame(0, $policy->requiredFlags & Runtime::HOOK_STDIO);
    }

    public function testInvalidCapabilityContextThrowsClearly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown runtime capability: mystery');

        RuntimePolicy::fromContext([
            RuntimePolicy::CONTEXT_CAPABILITIES => ['mystery'],
        ]);
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
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(RuntimePolicy::CONTEXT_STRICT_HOOKS . ' must be a boolean.');

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
            Runtime::HOOK_TCP | Runtime::HOOK_SSL | Runtime::HOOK_SLEEP | Runtime::HOOK_STDIO,
        );

        self::assertFalse($snapshot->isHealthy());
        self::assertSame($policy->name, $snapshot->policyName);
        self::assertSame(['TCP', 'SSL', 'SLEEP', 'STDIO'], $snapshot->currentFlagNames());
        self::assertSame(['UNIX', 'TLS'], $snapshot->missingFlagNames());
        self::assertSame(['SLEEP', 'STDIO'], $snapshot->sensitiveEnabledFlagNames());
        self::assertSame([
            'policy' => $policy->name,
            'current' => Runtime::HOOK_TCP | Runtime::HOOK_SSL | Runtime::HOOK_SLEEP | Runtime::HOOK_STDIO,
            'required' => $policy->requiredFlags,
            'missing' => $snapshot->missingFlags,
            'sensitive_enabled' => Runtime::HOOK_SLEEP | Runtime::HOOK_STDIO,
            'healthy' => false,
        ], $snapshot->toArray());
    }

    public function testHookNamesAreStableForDiagnostics(): void
    {
        self::assertSame(
            ['TCP', 'SSL', 'NATIVE_CURL'],
            RuntimeHookNames::forMask(Runtime::HOOK_TCP | Runtime::HOOK_SSL | Runtime::HOOK_NATIVE_CURL),
        );

        self::assertSame(['PROC'], RuntimeHookNames::forMask(Runtime::HOOK_PROC));
    }

    public function testPhalanxManagedPolicyCanNameEveryRequiredHook(): void
    {
        $policy = RuntimePolicy::phalanxManaged();
        $names = RuntimeHookNames::forMask($policy->requiredFlags);

        self::assertContains('PROC', $names);
        self::assertNotContains('STDIO', $names);
        self::assertNotContains('SLEEP', $names);
        self::assertNotContains('BLOCKING_FUNCTION', $names);
    }
}
