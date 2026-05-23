<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Transport\Iris;

use Phalanx\Application;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpClientConfig;
use Phalanx\Iris\Iris;
use Phalanx\Panoply\Runtime\CancellationException;
use Phalanx\Panoply\Runtime\Sync\Runtime as SyncRuntime;
use Phalanx\Panoply\Transport\Iris\Transport;
use Phalanx\Panoply\Transport\Request;
use Phalanx\Panoply\Transport\Sync\HttpError;
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
 * Constructor shape and generator-return tests run without OpenSwoole.
 * Live streaming tests annotated {@see RequiresPhpExtension}('openswoole')
 * use a PHP built-in HTTP server fixture and a real Aegis scope via
 * {@see Application::scoped()} — mirroring the Sync\Transport integration
 * pattern.
 *
 * Transport\Iris\Transport is the third documented boundary exception in
 * panoply: it bridges the adapter family to phalanx-iris. Concurrent +
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
    #[RequiresPhpExtension('openswoole')]
    public function happyPathStreamingYieldsBodyChunks(): void
    {
        $serverScript = self::writeServer('header("Content-Type: text/plain"); echo "agora sparta thermopylae";');
        [$proc, $pipes, $port] = self::startServer($serverScript);

        if ($proc === null) {
            @unlink($serverScript);
            self::markTestSkipped('Could not bind local PHP server');
        }

        $body = '';

        try {
            self::bootApp()->scoped(
                static function (ExecutionScope $aegisScope) use ($port, &$body): void {
                    $client = Iris::client($aegisScope);
                    $transport = new Transport($client, $aegisScope);
                    $request = Request::of('GET', "http://127.0.0.1:{$port}/");
                    $runtime = new SyncRuntime();

                    foreach ($transport->stream($request, $runtime) as $chunk) {
                        $body .= $chunk;
                    }
                },
            );
        } finally {
            self::stopServer($proc, $pipes, $serverScript);
        }

        self::assertStringContainsString('agora', $body);
        self::assertStringContainsString('sparta', $body);
    }

    #[Test]
    #[RequiresPhpExtension('openswoole')]
    public function errorResponseMapsToHttpError(): void
    {
        $serverScript = self::writeServer('http_response_code(404); echo \'{"error":"thermopylae not found"}\';');
        [$proc, $pipes, $port] = self::startServer($serverScript);

        if ($proc === null) {
            @unlink($serverScript);
            self::markTestSkipped('Could not bind local PHP server');
        }

        $caught = null;

        try {
            self::bootApp()->scoped(
                static function (ExecutionScope $aegisScope) use ($port, &$caught): void {
                    $client = Iris::client($aegisScope);
                    $transport = new Transport($client, $aegisScope);
                    $request = Request::of('GET', "http://127.0.0.1:{$port}/");
                    $runtime = new SyncRuntime();

                    try {
                        // Must iterate — generator is lazy.
                        foreach ($transport->stream($request, $runtime) as $_) {
                        }
                    } catch (HttpError $e) {
                        $caught = $e;
                    }
                },
            );
        } finally {
            self::stopServer($proc, $pipes, $serverScript);
        }

        self::assertNotNull($caught, 'HttpError must be thrown for non-2xx responses');
        self::assertSame(404, $caught->statusCode);
        self::assertStringContainsString('thermopylae', $caught->responseBody);
    }

    #[Test]
    #[RequiresPhpExtension('openswoole')]
    public function cancellationBeforeIterationThrowsCancellationException(): void
    {
        // Slow server: delay gives cancellation time to register before bytes arrive.
        $serverScript = self::writeServer('sleep(3); echo "leonidas survived";');
        [$proc, $pipes, $port] = self::startServer($serverScript);

        if ($proc === null) {
            @unlink($serverScript);
            self::markTestSkipped('Could not bind local PHP server');
        }

        $caught = null;

        try {
            self::bootApp()->scoped(
                static function (ExecutionScope $aegisScope) use ($port, &$caught): void {
                    $client = Iris::client($aegisScope);
                    $transport = new Transport($client, $aegisScope);
                    $request = Request::of('GET', "http://127.0.0.1:{$port}/");

                    // Cancel the SyncRuntime before iteration so the first
                    // throwIfCancelled() in the stream generator fires.
                    $runtime = new SyncRuntime();
                    $runtime->cancel();

                    try {
                        foreach ($transport->stream($request, $runtime) as $_) {
                        }
                    } catch (CancellationException $e) {
                        $caught = $e;
                    }
                },
            );
        } finally {
            self::stopServer($proc, $pipes, $serverScript);
        }

        self::assertNotNull($caught, 'CancellationException must propagate when runtime is cancelled before iteration');
    }

    #[Test]
    #[RequiresPhpExtension('openswoole')]
    public function eofTerminatesGeneratorCleanly(): void
    {
        $serverScript = self::writeServer('header("Content-Type: text/plain");');
        [$proc, $pipes, $port] = self::startServer($serverScript);

        if ($proc === null) {
            @unlink($serverScript);
            self::markTestSkipped('Could not bind local PHP server');
        }

        $completed = false;
        $chunks = [];

        try {
            self::bootApp()->scoped(
                static function (ExecutionScope $aegisScope) use ($port, &$chunks, &$completed): void {
                    $client = Iris::client($aegisScope);
                    $transport = new Transport($client, $aegisScope);
                    $request = Request::of('GET', "http://127.0.0.1:{$port}/");
                    $runtime = new SyncRuntime();

                    foreach ($transport->stream($request, $runtime) as $chunk) {
                        $chunks[] = $chunk;
                    }

                    $completed = true;
                },
            );
        } finally {
            self::stopServer($proc, $pipes, $serverScript);
        }

        self::assertTrue($completed, 'Generator must complete without exception on empty 200 response');
        self::assertSame([], $chunks);
    }

    #[Test]
    #[RequiresPhpExtension('openswoole')]
    public function largeResponsePreservesAllBytes(): void
    {
        $expected = 'athens sparta corinth thebes argos olympia delphi marathon salamis plataea';
        $serverScript = self::writeServer('echo ' . var_export($expected, return: true) . ';');
        [$proc, $pipes, $port] = self::startServer($serverScript);

        if ($proc === null) {
            @unlink($serverScript);
            self::markTestSkipped('Could not bind local PHP server');
        }

        $body = '';

        try {
            self::bootApp()->scoped(
                static function (ExecutionScope $aegisScope) use ($port, &$body): void {
                    $client = Iris::client($aegisScope);
                    $transport = new Transport($client, $aegisScope);
                    $request = Request::of('GET', "http://127.0.0.1:{$port}/");
                    $runtime = new SyncRuntime();

                    foreach ($transport->stream($request, $runtime) as $chunk) {
                        $body .= $chunk;
                    }
                },
            );
        } finally {
            self::stopServer($proc, $pipes, $serverScript);
        }

        self::assertSame($expected, $body, 'All bytes must be preserved across chunked delivery');
    }

    #[Test]
    #[RequiresPhpExtension('openswoole')]
    public function postRequestBodyIsSentToServer(): void
    {
        $serverScript = self::writeServer('echo file_get_contents("php://input");');
        [$proc, $pipes, $port] = self::startServer($serverScript);

        if ($proc === null) {
            @unlink($serverScript);
            self::markTestSkipped('Could not bind local PHP server');
        }

        $body = '';

        try {
            self::bootApp()->scoped(
                static function (ExecutionScope $aegisScope) use ($port, &$body): void {
                    $client = Iris::client($aegisScope);
                    $transport = new Transport($client, $aegisScope);
                    $request = Request::of(
                        'POST',
                        "http://127.0.0.1:{$port}/",
                        ['Content-Type' => 'application/json'],
                        '{"polis":"agora","strategos":"themistocles"}',
                    );
                    $runtime = new SyncRuntime();

                    foreach ($transport->stream($request, $runtime) as $chunk) {
                        $body .= $chunk;
                    }
                },
            );
        } finally {
            self::stopServer($proc, $pipes, $serverScript);
        }

        self::assertStringContainsString('themistocles', $body);
    }

    #[Test]
    #[RequiresPhpExtension('openswoole')]
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
            static function (ExecutionScope $aegisScope) use ($connection, &$chunkSeen): void {
                $client = new HttpClient(
                    tcpFactory: static fn(
                        string $_scheme,
                        string $_host,
                        ?TlsOptions $_tlsOptions,
                    ): TcpConnection => $connection,
                );
                $transport = new Transport($client, $aegisScope);
                $request = Request::of('GET', 'http://127.0.0.1:8123/');
                $runtime = new SyncRuntime();

                $gen = $transport->stream($request, $runtime);

                // Advance to first chunk then abandon without completing.
                $chunkSeen = $gen->current() === 'first chunk' && $gen->valid();

                // Abandon mid-stream: unset triggers generator cleanup
                // (finally block in stream() closes the underlying HttpStream).
                unset($gen);
            },
        );

        // The generator produced at least one chunk before abandonment.
        self::assertTrue($chunkSeen, 'Generator must yield at least one chunk before abandonment');
        self::assertTrue($connection->closed, 'Abandoning the generator must close the underlying stream');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Boot an Aegis Application with Iris registered. Requires OpenSwoole. */
    private static function bootApp(): Application
    {
        return Application::starting()
            ->providers(Iris::services())
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

    /**
     * Attempt to start a `php -S` server on a random high port. Retries up
     * to five times on different ports to survive parallel test execution.
     *
     * @return array{0: resource|null, 1: array<int, resource>, 2: int}
     */
    private static function startServer(string $serverScript): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 60000);
            $proc = proc_open(
                'php -S 127.0.0.1:' . $port . ' ' . $serverScript,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            if (!is_resource($proc)) {
                continue;
            }

            if (self::waitForServer($port)) {
                return [$proc, $pipes, $port];
            }

            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }

        return [null, [], 0];
    }

    private static function waitForServer(int $port): bool
    {
        $deadline = microtime(true) + 2.0;

        do {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);

            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * @param resource $proc
     * @param array<int, resource> $pipes
     */
    private static function stopServer($proc, array $pipes, string $serverScript): void
    {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($proc);
        proc_close($proc);
        @unlink($serverScript);
    }

    /** Writes a minimal PHP built-in server script with the given body PHP code. */
    private static function writeServer(string $phpBody): string
    {
        $base = tempnam(sys_get_temp_dir(), 'panoply_iris_');
        $path = $base . '_' . getmypid() . '.php';
        @unlink($base);
        file_put_contents($path, "<?php {$phpBody}");

        return $path;
    }

    private static function httpChunk(string $payload): string
    {
        return dechex(strlen($payload)) . "\r\n{$payload}\r\n";
    }

    /**
     * @param list<string> $responseChunks
     * @return TcpConnection&object{closed: bool}
     */
    private static function scriptedConnection(array $responseChunks): TcpConnection
    {
        return new class ($responseChunks) implements TcpConnection {
            public bool $closed = false;

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
                return strlen($_payload);
            }

            public function recv(Suspendable $_scope, float $_timeout = 1.0): ?string
            {
                return array_shift($this->responseChunks) ?? '';
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
    }
}
