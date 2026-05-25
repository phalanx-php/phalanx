<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Agent;

use Phalanx\Athena\Router\InvocationRouter;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Harness\Agent\LlmRequestRecordingRouter;
use Phalanx\Harness\Agent\LlmRequestRecordingTransport;
use Phalanx\Harness\Template\AppStore;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Stream;
use Phalanx\Panoply\Transport;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use Phalanx\Panoply\Transport\Request;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LlmRequestRecordingRouterTest extends TestCase
{
    #[Test]
    public function routeDecoratesProviderTransportWhenAppStoreIsAvailable(): void
    {
        $store = new AppStore();
        $provider = new RecordingProvider(new RouterScriptedTransport(['{}']), name: 'apollo');
        $router = new LlmRequestRecordingRouter(new StaticRouter($provider));

        $routed = $router->route(
            new ServiceScope([AppStore::class => $store]),
            self::agent(),
            self::invocation(),
        );

        self::assertInstanceOf(RecordingProvider::class, $routed);
        self::assertNotSame($provider, $routed);
        self::assertSame('apollo', $routed->name);
        self::assertInstanceOf(LlmRequestRecordingTransport::class, $routed->transport);

        iterator_to_array($routed->perform(self::invocation(), new RouterNullRuntime()), false);

        $entry = $store->requests->focused();
        self::assertNotNull($entry);
        self::assertSame('POST', $entry->method);
        self::assertSame('/api/chat', $entry->path);
        self::assertSame('{"model":"demo"}', $entry->requestBody);
        self::assertSame('inv.apollo', $entry->invocationId);
        self::assertTrue($entry->complete);
    }

    #[Test]
    public function routeLeavesProviderAloneWhenAppStoreIsUnavailable(): void
    {
        $provider = new RecordingProvider(new RouterScriptedTransport(['{}']));
        $router = new LlmRequestRecordingRouter(new StaticRouter($provider));

        $routed = $router->route(new ServiceScope([]), self::agent(), self::invocation());

        self::assertSame($provider, $routed);
        self::assertInstanceOf(RouterScriptedTransport::class, $provider->transport);
    }

    #[Test]
    public function decorateProviderDoesNotDoubleWrapRecordingTransport(): void
    {
        $store = new AppStore();
        $transport = LlmRequestRecordingTransport::wrap(new RouterScriptedTransport(['{}']), $store);
        $provider = new RecordingProvider($transport);

        $router = new LlmRequestRecordingRouter(new StaticRouter($provider));
        $decorated = $router->route(
            new ServiceScope([AppStore::class => $store]),
            self::agent(),
            self::invocation(),
        );

        self::assertInstanceOf(RecordingProvider::class, $decorated);
        self::assertSame($transport, $decorated->transport);
    }

    private static function agent(): Agent
    {
        return new class implements Agent {
            public string $id { get => 'agent.apollo'; }

            public string $name { get => 'Apollo'; }

            public Output $output { get => Output::text(); }

            public string $purpose { get => 'Speak clearly.'; }

            public Context $context { get => Context::new(); }

            public Effects $effects { get => Effects::none(); }

            public ProviderNeeds $provider { get => ProviderNeeds::new(); }

            public Capabilities $capabilities { get => Capabilities::empty(); }

            public TransportNeeds $transport { get => TransportNeeds::new()->streaming(); }
        };
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv.apollo',
            agentId: 'agent.apollo',
            activityId: 'act.apollo',
            contextHash: '',
            instructions: 'Speak clearly.',
            output: Output::text(),
            effects: Effects::none(),
            provider: ProviderNeeds::new(),
            transport: TransportNeeds::new()->streaming(),
        );
    }
}

final class StaticRouter implements InvocationRouter
{
    public function __construct(
        private(set) Provider $provider,
    ) {
    }

    public function route(TaskScope $scope, Agent $agent, Invocation $invocation): Provider
    {
        return $this->provider;
    }
}

final class RecordingProvider implements Provider
{
    public function __construct(
        private(set) Transport $transport,
        private(set) string $name = 'zeus',
    ) {
    }

    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        $transport = $this->transport;

        return new Stream(static function () use ($transport, $runtime): \Generator {
            $chunks = $transport->stream(
                Request::of('POST', 'https://example.com/api/chat', body: '{"model":"demo"}'),
                $runtime,
            );

            foreach ($chunks as $_chunk) {
            }

            yield from [];
        });
    }

    public function capabilities(): Capabilities
    {
        return Capabilities::empty();
    }
}

final class RouterScriptedTransport implements Transport
{
    /**
     * @param list<string> $chunks
     */
    public function __construct(
        private(set) array $chunks,
    ) {
    }

    public function stream(Request $request, Runtime $runtime): \Generator
    {
        foreach ($this->chunks as $chunk) {
            yield $chunk;
        }
    }
}

final class ServiceScope implements TaskScope
{
    public bool $isCancelled { get => false; }

    public RuntimeContext $runtime {
        get => throw new \RuntimeException('Runtime is not implemented by ServiceScope.');
    }

    /**
     * @param array<class-string, object> $services
     */
    public function __construct(
        private(set) array $services,
    ) {
    }

    public function call(\Closure $fn, ?WaitReason $waitReason = null): mixed
    {
        return $fn();
    }

    public function throwIfCancelled(): void
    {
    }

    public function cancellation(): CancellationToken
    {
        return CancellationToken::create();
    }

    public function onDispose(\Closure $callback): void
    {
    }

    public function dispose(): void
    {
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function service(string $type): object
    {
        if (!isset($this->services[$type])) {
            throw new ServiceNotFoundException($type);
        }

        $service = $this->services[$type];

        if (!$service instanceof $type) {
            throw new \RuntimeException("Registered service does not match {$type}.");
        }

        return $service;
    }

    public function trace(): Trace
    {
        throw new \RuntimeException('Trace is not implemented by ServiceScope.');
    }

    public function execute(Scopeable|Executable|\Closure $task): mixed
    {
        return $task instanceof \Closure ? $task() : throw new \RuntimeException('Task execution is not implemented.');
    }

    public function executeFresh(Scopeable|Executable|\Closure $task): mixed
    {
        return $this->execute($task);
    }
}

final class RouterNullRuntime implements Runtime
{
    public function call(\Closure $work, ?string $waitReason = null): mixed
    {
        return $work();
    }

    public function isCancelled(): bool
    {
        return false;
    }

    public function throwIfCancelled(): void
    {
    }

    public function onCancel(\Closure $cleanup): void
    {
    }
}
