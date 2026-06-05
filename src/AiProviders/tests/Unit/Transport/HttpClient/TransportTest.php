<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Transport\HttpClient;

use Phalanx\Application;
use Phalanx\HttpClient\HttpClient;
use Phalanx\HttpClient\HttpClientConfig;
use Phalanx\AiProviders\Runtime\CancellationException;
use Phalanx\AiProviders\Runtime\Sync\Runtime as SyncRuntime;
use Phalanx\AiProviders\Transport\HttpClient\Transport;
use Phalanx\AiProviders\Transport\Request;
use Phalanx\AiProviders\Transport\Sync\HttpError;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use Phalanx\System\TcpConnection;
use Phalanx\System\TlsOptions;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Transport}.
 *
 * Constructor shape and generator-return tests run without Swoole. Streaming
 * tests annotated {@see RequiresPhpExtension}('swoole') use scripted TCP
 * connections and a real Runtime scope via {@see Application::scoped()}.
 *
 * Transport\HttpClient\Transport is the third documented boundary exception in
 * ai-providers: it bridges the adapter family to phalanx-http-client. Concurrent +
 * mid-stream cancellation coverage lives in the acceptance gate (gate 9).
 */
final class TransportTest extends TestCase
{
    #[Test]
    public function constructorStoresClientAndScope(): void
    {
        $client = self::stubClient();
        $scope = self::stubScope();

        $transport = new Transport($client, $scope);

        self::assertSame($client, $transport->client);
        self::assertSame($scope, $transport->scope);
    }

    #[Test]
    public function streamReturnsGeneratorLazily(): void
    {
        // Generator is returned without executing — no network call until iterated.
        $transport = new Transport(self::stubClient(), self::stubScope());
        $request = Request::of('GET', 'http://127.0.0.1:1/');

        $generator = $transport->stream($request, new SyncRuntime());

        self::assertInstanceOf(\Generator::class, $generator);
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function happyPathStreamingYieldsBodyChunks(): void
    {
        $connection = self::scriptedConnection([
            self::httpResponseHeaders(),
            self::httpChunk('agora '),
            self::httpChunk('sparta thermopylae'),
            "0\r\n\r\n",
        ]);
        $body = self::streamFromConnection($connection);

        self::assertStringContainsString('agora', $body);
        self::assertStringContainsString('sparta', $body);
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function errorResponseMapsToHttpError(): void
    {
        $connection = self::scriptedConnection([
            self::httpResponseHeaders(404),
            self::httpChunk('{"error":"thermopylae not found"}'),
            "0\r\n\r\n",
        ]);
        $caught = null;

        try {
            self::streamFromConnection($connection);
        } catch (HttpError $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'HttpError must be thrown for non-2xx responses');
        self::assertSame(404, $caught->statusCode);
        self::assertStringContainsString('thermopylae', $caught->responseBody);
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function cancellationBeforeIterationThrowsCancellationException(): void
    {
        $connection = self::scriptedConnection([self::httpResponseHeaders(), self::httpChunk('leonidas survived')]);
        $caught = null;

        try {
            self::streamFromConnection($connection, static function (SyncRuntime $runtime): void {
                $runtime->cancel();
            });
        } catch (CancellationException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'CancellationException must propagate when runtime is cancelled before iteration');
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function eofTerminatesGeneratorCleanly(): void
    {
        $connection = self::scriptedConnection([self::httpResponseHeaders(), "0\r\n\r\n"]);

        self::assertSame('', self::streamFromConnection($connection));
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function largeResponsePreservesAllBytes(): void
    {
        $expected = 'athens sparta corinth thebes network olympia delphi marathon salamis plataea';
        $connection = self::scriptedConnection([
            self::httpResponseHeaders(),
            self::httpChunk($expected),
            "0\r\n\r\n",
        ]);

        self::assertSame($expected, self::streamFromConnection($connection), 'All bytes must be preserved across chunked delivery');
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function postRequestBodyIsSentToServer(): void
    {
        $connection = self::scriptedConnection([
            self::httpResponseHeaders(),
            self::httpChunk('accepted'),
            "0\r\n\r\n",
        ]);
        $body = self::streamFromConnection(
            $connection,
            request: Request::of(
                'POST',
                'http://127.0.0.1:8123/',
                ['Content-Type' => 'application/json'],
                '{"polis":"agora","strategos":"configtocles"}',
            ),
        );

        self::assertSame('accepted', $body);
        self::assertStringContainsString('POST / HTTP/1.1', $connection->sent);
        self::assertStringContainsString('{"polis":"agora","strategos":"configtocles"}', $connection->sent);
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function generatorAbandonedMidIterationReleasesStream(): void
    {
        $connection = self::scriptedConnection([
            "HTTP/1.1 200 OK\r\n"
                . "content-type: text/plain\r\n"
                . "transfer-encoding: chunked\r\n"
                . "\r\n",
            self::httpChunk('first chunk'),
            self::httpChunk('never'),
            "0\r\n\r\n",
        ]);
        $chunkSeen = false;

        self::bootApp()->scoped(
            static function (ExecutionScope $runtimeScope) use ($connection, &$chunkSeen): void {
                $client = new HttpClient(
                    tcpFactory: static fn(
                        string $_scheme,
                        string $_host,
                        ?TlsOptions $_tlsOptions,
                    ): TcpConnection => $connection,
                );
                $transport = new Transport($client, $runtimeScope);
                $request = Request::of('GET', 'http://127.0.0.1:8123/');
                $runtime = new SyncRuntime();

                $gen = $transport->stream($request, $runtime);

                $chunkSeen = $gen->current() === 'first chunk' && $gen->valid();

                unset($gen);
            },
        );

        self::assertTrue($chunkSeen, 'Generator must yield at least one chunk before abandonment');
        self::assertTrue($connection->closed, 'Abandoning the generator must close the underlying stream');
    }

    private static function bootApp(): Application
    {
        return Application::starting()
            ->providers(HttpClient::services())
            ->compile();
    }

    private static function stubClient(): HttpClient
    {
        return new HttpClient(new HttpClientConfig());
    }

    private static function stubScope(): Scope&Suspendable
    {
        return new class implements Scope, Suspendable {
            public \Phalanx\Runtime\RuntimeContext $runtime {
                get => throw new \RuntimeException('stub: runtime not available');
            }

            public function call(\Closure $fn, ?\Phalanx\Supervisor\WaitReason $waitReason = null): mixed
            {
                return $fn();
            }

            public function service(string $type): object
            {
                throw new \RuntimeException('stub: service not available');
            }

            public function trace(): \Phalanx\Trace\Trace
            {
                throw new \RuntimeException('stub: trace not available');
            }
        };
    }

    private static function httpResponseHeaders(int $status = 200): string
    {
        return "HTTP/1.1 {$status} OK\r\n"
            . "content-type: text/plain\r\n"
            . "transfer-encoding: chunked\r\n"
            . "\r\n";
    }

    private static function httpChunk(string $payload): string
    {
        return dechex(strlen($payload)) . "\r\n{$payload}\r\n";
    }

    /**
     * @param null|\Closure(SyncRuntime): void $beforeRead
     */
    private static function streamFromConnection(
        TcpConnection $connection,
        ?\Closure $beforeRead = null,
        ?Request $request = null,
    ): string {
        $body = '';

        self::bootApp()->scoped(
            static function (ExecutionScope $runtimeScope) use ($connection, $beforeRead, $request, &$body): void {
                $client = new HttpClient(
                    tcpFactory: static fn(
                        string $_scheme,
                        string $_host,
                        ?TlsOptions $_tlsOptions,
                    ): TcpConnection => $connection,
                );
                $transport = new Transport($client, $runtimeScope);
                $runtime = new SyncRuntime();
                $beforeRead?->__invoke($runtime);

                foreach ($transport->stream($request ?? Request::of('GET', 'http://127.0.0.1:8123/'), $runtime) as $chunk) {
                    $body .= $chunk;
                }
            },
        );

        return $body;
    }

    /**
     * @param list<string> $responseChunks
     * @return TcpConnection&object{closed: bool, sent: string}
     */
    private static function scriptedConnection(array $responseChunks): TcpConnection
    {
        return new class ($responseChunks) implements TcpConnection {
            public bool $closed = false;

            public string $sent = '';

            /** @param list<string> $responseChunks */
            public function __construct(private array $responseChunks)
            {
            }

            public function connect(Suspendable $_scope, string $_host, int $_port, float $_timeout = 1.0): bool
            {
                return true;
            }

            public function send(Suspendable $_scope, string $_payload, float $_timeout = 1.0): int
            {
                $this->sent .= $_payload;

                return strlen($_payload);
            }

            public function recv(Suspendable $_scope, float $_timeout = 1.0): ?string
            {
                return array_shift($this->responseChunks);
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
    }
}
