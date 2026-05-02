<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Runtime;

use InvalidArgumentException;
use OpenSwoole\Runtime;
use Phalanx\Application;
use Phalanx\Runtime\RuntimeCapability;
use Phalanx\Runtime\RuntimeHookNames;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
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
                'interactive_stdio',
            ],
        ]);

        self::assertSame(
            Runtime::HOOK_TCP | Runtime::HOOK_NATIVE_CURL | Runtime::HOOK_FILE | Runtime::HOOK_STDIO,
            $policy->requiredFlags
                & (Runtime::HOOK_TCP | Runtime::HOOK_NATIVE_CURL | Runtime::HOOK_FILE | Runtime::HOOK_STDIO),
        );
        self::assertSame(0, $policy->requiredFlags & Runtime::HOOK_PROC);
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
        $scope->dispose();

        self::assertTrue(true);
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

    public function testHookNamesAreStableForDiagnostics(): void
    {
        self::assertSame(
            ['TCP', 'SSL', 'NATIVE_CURL'],
            RuntimeHookNames::forMask(Runtime::HOOK_TCP | Runtime::HOOK_SSL | Runtime::HOOK_NATIVE_CURL),
        );
    }
}
