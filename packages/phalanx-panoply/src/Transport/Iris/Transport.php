<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Transport\Iris;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpClientException;
use Phalanx\Iris\HttpRequest;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Runtime\CancellationException;
use Phalanx\Panoply\Transport as TransportContract;
use Phalanx\Panoply\Transport\Request;
use Phalanx\Panoply\Transport\Sync\HttpError;
use Phalanx\Panoply\Transport\TransportException;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;

/**
 * OpenSwoole-native HTTP transport for panoply. Bridges panoply's
 * {@see TransportContract} adapter family to phalanx-iris's coroutine-aware
 * {@see HttpClient}, delivering true incremental streaming via HTTP/1.1
 * chunked transfer.
 *
 * --- Third documented boundary exception ---
 * This file imports from {@see Phalanx\Iris}, {@see Phalanx\Scope}, and
 * {@see Phalanx\Cancellation}, which are outside panoply's usual
 * Phalanx-independence boundary. The exception is deliberate and mirrors
 * conventions followed in:
 *
 *   - {@see \Phalanx\Panoply\Runtime\Aegis\Runtime} (imports Phalanx\Scope\TaskScope)
 *   - {@see \Phalanx\Panoply\Archon\PanoplyAgentsScanCommand} (imports Phalanx\Scope\Scope, Phalanx\Task\Scopeable)
 *
 * Imports in this file: {@see \Phalanx\Iris\HttpClient},
 * {@see \Phalanx\Iris\HttpClientException}, {@see \Phalanx\Scope\Scope},
 * {@see \Phalanx\Scope\Suspendable}, {@see \Phalanx\Cancellation\Cancelled}.
 *
 * The Transport adapter family (Sync / Iris / Fake) is a coherent group of
 * adapters owned by panoply. Splitting Iris into phalanx-iris would invert
 * the dependency direction (iris importing from panoply) and fragment adapter
 * discoverability. The adapter lives here because it IS the bridge.
 *
 * Scope injection: this transport requires a {@see Scope}&{@see Suspendable}
 * at construction time. Callers that wire panoply with the Aegis runtime
 * extract the scope from {@see \Phalanx\Panoply\Runtime\Aegis\Runtime::$scope}
 * (public-read via `private(set)`) before building this transport. Closures
 * inside {@see self::stream()} are `static` to prevent `$this` capture in a
 * long-running process.
 *
 * Final — extension would alter the incremental-yield invariant that gate 9
 * and provider tests depend on.
 */
final class Transport implements TransportContract
{
    public function __construct(
        private(set) HttpClient $client,
        private(set) Scope&Suspendable $scope,
    ) {
    }

    /**
     * Open a streaming wire-level request via phalanx-iris.
     *
     * Yields raw byte chunks as they arrive from the HTTP response body.
     * Cancellation is detected via {@see Runtime::isCancelled()} between
     * read calls. On cancellation the underlying {@see \Phalanx\Iris\HttpStream}
     * is aborted and a {@see CancellationException} is re-thrown. Any other
     * Throwable closes the stream via {@see \Phalanx\Iris\HttpStream::fail()}
     * before propagating. The `finally` block guarantees
     * {@see \Phalanx\Iris\HttpStream::close()} runs regardless of exit path.
     *
     * Non-2xx responses throw {@see HttpError} after the stream headers are
     * read but before any body bytes are yielded.
     *
     * @return \Generator<int, string>
     * @throws HttpError when the response status is not 2xx
     * @throws CancellationException when the runtime is cancelled mid-stream
     */
    public function stream(Request $request, Runtime $runtime): \Generator
    {
        $scope = $this->scope;
        $client = $this->client;

        return (static function () use ($scope, $client, $request, $runtime): \Generator {
            $irisRequest = new HttpRequest(
                method: $request->method,
                url: $request->url,
                headers: self::wrapHeaders($request->headers),
                body: $request->body !== '' ? $request->body : null,
            );

            $stream = $client->stream($scope, $irisRequest);

            try {
                // Status is populated after stream() returns (headers are
                // read lazily on the first read()). Check cancellation before
                // the first read so callers that cancel between stream() and
                // the first byte do not block waiting for headers.
                $runtime->throwIfCancelled();
                $firstChunk = $stream->read($scope);

                if ($stream->status < 200 || $stream->status >= 300) {
                    $body = $firstChunk;
                    while (!$stream->eof) {
                        $runtime->throwIfCancelled();
                        $body .= $stream->read($scope);
                    }

                    throw new HttpError(
                        statusCode: $stream->status,
                        responseBody: $body,
                        message: "HTTP {$stream->status} from {$request->url}",
                    );
                }

                if ($firstChunk !== '') {
                    yield $firstChunk;
                }

                while (!$stream->eof) {
                    $runtime->throwIfCancelled();
                    $chunk = $stream->read($scope);
                    if ($chunk !== '') {
                        yield $chunk;
                    }
                }
            } catch (CancellationException $e) {
                $stream->abort('cancelled');
                throw $e;
            } catch (Cancelled $e) {
                // Phalanx\Cancellation\Cancelled is thrown by Aegis coroutine
                // infrastructure during scope->call() when the token fires. Re-wrap
                // as panoply CancellationException for cross-runtime symmetry.
                $stream->abort('cancelled');
                throw new CancellationException($e->getMessage(), 0, $e);
            } catch (HttpClientException $e) {
                // Wrap iris-specific transport errors so callers never need to
                // import Phalanx\Iris\* to handle connection or protocol failures.
                $stream->fail($e->getMessage());
                throw new TransportException("iris transport failed: {$e->getMessage()}", 0, $e);
            } catch (\Throwable $e) {
                $stream->fail($e->getMessage());
                throw $e;
            } finally {
                $stream->close();
            }
        })();
    }

    /**
     * Converts panoply's flat `array<string, string>` header map to the
     * iris header format `array<string, list<string>>`.
     *
     * @param array<string, string> $headers
     * @return array<string, list<string>>
     */
    private static function wrapHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[$name] = [$value];
        }

        return $out;
    }
}
