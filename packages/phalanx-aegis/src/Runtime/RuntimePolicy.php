<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use InvalidArgumentException;
use OpenSwoole\Runtime;
use Phalanx\Boot\AppContext;

final readonly class RuntimePolicy
{
    public const string CONTEXT_POLICY = 'phalanx.runtime_policy';
    public const string CONTEXT_CAPABILITIES = 'phalanx.runtime_capabilities';
    public const string CONTEXT_STRICT_HOOKS = 'phalanx.runtime_hooks.strict';

    public function __construct(
        public string $name,
        public int $requiredFlags,
        public int $sensitiveFlags,
        public bool $useFiberContext = true,
        public ReactorType $reactorType = ReactorType::Auto,
    ) {
    }

    public static function phalanxManaged(): self
    {
        return self::forCapabilities(
            RuntimeCapability::Network,
            RuntimeCapability::HttpClient,
            RuntimeCapability::Streams,
            RuntimeCapability::Files,
            RuntimeCapability::Sockets,
            RuntimeCapability::Datagrams,
            RuntimeCapability::Processes,
        );
    }

    public static function forCapabilities(RuntimeCapability ...$capabilities): self
    {
        $capabilities = array_values($capabilities);
        $required = 0;
        foreach ($capabilities as $capability) {
            $required |= self::flagsFor($capability);
        }

        // Console input owns its coroutine handoff explicitly; global stdio,
        // sleep, and blocking-function hooks are too coarse for managed pools.
        return new self(
            name: self::nameFor($capabilities),
            requiredFlags: $required,
            sensitiveFlags: Runtime::HOOK_SLEEP | Runtime::HOOK_STDIO | Runtime::HOOK_BLOCKING_FUNCTION,
        );
    }

    public static function fromContext(AppContext $context): self
    {
        $policy = $context->get(self::CONTEXT_POLICY);
        if ($policy instanceof self) {
            return $policy;
        }
        if ($policy !== null) {
            throw new InvalidArgumentException(sprintf(
                '%s must be an instance of %s.',
                self::CONTEXT_POLICY,
                self::class,
            ));
        }

        if (!$context->has(self::CONTEXT_CAPABILITIES)) {
            return self::phalanxManaged();
        }

        $rawCapabilities = $context->require(self::CONTEXT_CAPABILITIES);
        if (!is_array($rawCapabilities)) {
            throw new InvalidArgumentException(sprintf('%s must be a list.', self::CONTEXT_CAPABILITIES));
        }

        return self::forCapabilities(...array_map(RuntimeCapability::fromContextValue(...), $rawCapabilities));
    }

    public function missingFlags(int $currentFlags): int
    {
        return $this->requiredFlags & ~$currentFlags;
    }

    public function hasRequiredFlags(int $currentFlags): bool
    {
        return $this->missingFlags($currentFlags) === 0;
    }

    public function sensitiveEnabledFlags(int $currentFlags): int
    {
        return $currentFlags & $this->sensitiveFlags;
    }

    /**
     * Render coroutine-level options for OpenSwoole\Coroutine::set().
     *
     * `use_fiber_context` routes coroutine context through PHP's native
     * zend_fiber API (OpenSwoole 26 default-ready). This makes Xdebug step
     * debugging work across coroutines and prevents context drift when
     * Phalanx code shares a process with other Fiber-aware libraries.
     *
     * @return array<string, bool>
     */
    public function coroutineOptions(): array
    {
        return ['use_fiber_context' => $this->useFiberContext];
    }

    private static function flagsFor(RuntimeCapability $capability): int
    {
        return match ($capability) {
            RuntimeCapability::Network => Runtime::HOOK_TCP
                | Runtime::HOOK_UNIX
                | Runtime::HOOK_SSL
                | Runtime::HOOK_TLS,
            RuntimeCapability::HttpClient => Runtime::HOOK_TCP
                | Runtime::HOOK_SSL
                | Runtime::HOOK_TLS
                | Runtime::HOOK_CURL
                | Runtime::HOOK_NATIVE_CURL,
            RuntimeCapability::Streams => Runtime::HOOK_STREAM_FUNCTION,
            RuntimeCapability::Files => Runtime::HOOK_FILE,
            RuntimeCapability::Sockets => Runtime::HOOK_SOCKETS,
            RuntimeCapability::Datagrams => Runtime::HOOK_UDP | Runtime::HOOK_UDG,
            RuntimeCapability::Processes => Runtime::HOOK_PROC,
            RuntimeCapability::InteractiveStdio => Runtime::HOOK_STDIO,
            RuntimeCapability::Sleep => Runtime::HOOK_SLEEP,
            RuntimeCapability::BlockingFunctions => Runtime::HOOK_BLOCKING_FUNCTION,
        };
    }

    /** @param list<RuntimeCapability> $capabilities */
    private static function nameFor(array $capabilities): string
    {
        if ($capabilities === []) {
            return 'custom:none';
        }

        return 'capabilities:' . implode(
            ',',
            array_map(static fn(RuntimeCapability $capability): string => $capability->value, $capabilities),
        );
    }
}
