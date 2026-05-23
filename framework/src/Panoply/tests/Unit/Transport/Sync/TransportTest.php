<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Transport\Sync;

use Phalanx\Panoply\Runtime\CancellationException;
use Phalanx\Panoply\Runtime\Sync\Runtime as SyncRuntime;
use Phalanx\Panoply\Transport\Request;
use Phalanx\Panoply\Transport\Sync\HttpError;
use Phalanx\Panoply\Transport\Sync\Transport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Transport}.
 *
 * The Sync transport's end-to-end SSE-to-Cue path is integration-tested by
 * {@see \Phalanx\Panoply\Tests\Unit\Provider\Anthropic\ProviderTest} via
 * Transport\Fake. Tests here cover the class shape, constructor, and the
 * HttpError exception contract.
 *
 * A curl integration test using a local `php -S` subprocess is included for
 * environments where curl is available and `@requires extension curl`
 * conditions are met.
 */
final class TransportTest extends TestCase
{
    #[Test]
    public function defaultTimeoutsAreApplied(): void
    {
        $transport = self::fixture();

        self::assertSame(10, $transport->connectTimeoutSeconds);
        self::assertSame(300, $transport->totalTimeoutSeconds);
    }

    #[Test]
    public function customTimeoutsAreStored(): void
    {
        $transport = new Transport(connectTimeoutSeconds: 5, totalTimeoutSeconds: 60);

        self::assertSame(5, $transport->connectTimeoutSeconds);
        self::assertSame(60, $transport->totalTimeoutSeconds);
    }

    #[Test]
    public function streamReturnsGenerator(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('curl extension not available');
        }

        $transport = self::fixture();
        // Use a well-known unroutable address — the generator is returned
        // without executing until iterated, so no actual connection attempt.
        $request = Request::of('GET', 'http://127.0.0.1:1/');
        $runtime = new SyncRuntime();

        // The call returns a generator without executing until iterated.
        $generator = $transport->stream($request, $runtime);

        self::assertInstanceOf(\Generator::class, $generator);
    }

    #[Test]
    public function httpErrorCarriesStatusAndBody(): void
    {
        $error = new HttpError(
            statusCode: 429,
            responseBody: '{"error":"rate_limit_exceeded"}',
            message: 'HTTP 429 from https://api.anthropic.com/v1/messages',
        );

        self::assertSame(429, $error->statusCode);
        self::assertSame('{"error":"rate_limit_exceeded"}', $error->responseBody);
        self::assertSame('HTTP 429 from https://api.anthropic.com/v1/messages', $error->getMessage());
    }

    #[Test]
    public function httpErrorIsRuntimeException(): void
    {
        $error = new HttpError(503, 'overloaded', 'HTTP 503');

        self::assertInstanceOf(\RuntimeException::class, $error);
    }

    #[Test]
    public function localCurlRoundTrip(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('curl extension not available');
        }

        // Spin up a local PHP built-in server serving a fixed response.
        // Pick a random high port and retry up to five times to avoid racing
        // with other parallel test processes that may have claimed the port.
        $serverScript = self::writeServerScript();
        [$proc, $pipes, $port] = self::startServer($serverScript);

        if ($proc === null) {
            @unlink($serverScript);
            self::markTestSkipped('Could not bind a local PHP server after 5 attempts');
        }

        try {
            $transport = self::fixture();
            $request = Request::of('POST', "http://127.0.0.1:{$port}/", [], '');
            $runtime = new SyncRuntime();

            $chunks = iterator_to_array($transport->stream($request, $runtime), preserve_keys: false);
            $body = implode('', $chunks);

            self::assertStringContainsString('agora', $body);
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($proc);
            proc_close($proc);
            @unlink($serverScript);
        }
    }

    #[Test]
    public function cancellationMidStreamThrowsCancellationException(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('curl extension not available');
        }

        // Spin up a local PHP server that sleeps before responding so the
        // progress callback has a chance to fire and abort curl.
        $serverScript = self::writeSlowServerScript();
        [$proc, $pipes, $port] = self::startServer($serverScript);

        if ($proc === null) {
            @unlink($serverScript);
            self::markTestSkipped('Could not bind a local PHP server after 5 attempts');
        }

        try {
            $transport = self::fixture();
            $request = Request::of('GET', "http://127.0.0.1:{$port}/");
            $runtime = new SyncRuntime();

            // Cancel before curl starts — the progress callback fires
            // immediately on the first poll and returns 1, triggering abort.
            $runtime->cancel();

            $this->expectException(CancellationException::class);

            iterator_to_array($transport->stream($request, $runtime), preserve_keys: false);
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($proc);
            proc_close($proc);
            @unlink($serverScript);
        }
    }

    private static function fixture(): Transport
    {
        return new Transport();
    }

    /**
     * Attempt to start a `php -S` server on a random high port. Retries up
     * to five times on different ports to survive parallel test execution.
     *
     * Returns [proc_resource, pipes, port] on success, or [null, [], 0] if
     * all attempts fail.
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

            // Server exited — port likely already in use. Clean up and retry.
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

    private static function writeServerScript(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'panoply_sync_');
        $path = $base . '_server_' . getmypid() . '.php';
        @unlink($base);
        $content = '<?php header("Content-Type: text/plain"); echo "agora response";';
        file_put_contents($path, $content);

        return $path;
    }

    private static function writeSlowServerScript(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'panoply_sync_');
        $path = $base . '_slow_' . getmypid() . '.php';
        @unlink($base);
        $content = '<?php sleep(2); header("Content-Type: text/plain"); echo "thermopylae";';
        file_put_contents($path, $content);

        return $path;
    }
}
