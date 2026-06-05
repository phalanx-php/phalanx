<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Transport\HttpClient;

use Phalanx\Cancellation\Cancelled;
use Phalanx\HttpClient\HttpClient;
use Phalanx\HttpClient\HttpClientException;
use Phalanx\HttpClient\HttpRequest;
use Phalanx\AiProviders\Runtime;
use Phalanx\AiProviders\Runtime\CancellationException;
use Phalanx\AiProviders\Transport as TransportContract;
use Phalanx\AiProviders\Transport\Request;
use Phalanx\AiProviders\Transport\Sync\HttpError;
use Phalanx\AiProviders\Transport\TransportException;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;

/**
 * Swoole-native HTTP transport for ai-providers. Bridges ai-providers's
 * {@see TransportContract} adapter family to phalanx-http-client's coroutine-aware
 * {@see HttpClient}, delivering true incremental streaming via HTTP/1.1
 * chunked transfer.
 *
 * --- Third documented boundary exception ---
 * This file imports from {@see Phalanx\HttpClient}, {@see Phalanx\Scope}, and
 * {@see Phalanx\Cancellation}, which are outside ai-providers's usual
 * Phalanx-independence boundary. The exception is deliberate and mirrors
 * conventions followed in:
 *
 *   - {@see \Phalanx\AiProviders\Runtime\Runtime\Runtime} (imports Phalanx\Scope\TaskScope)
 *   - {@see \Phalanx\AiProviders\Console\AiProvidersAgentsScanCommand} (imports Phalanx\Scope\Scope, Phalanx\Task\Scopeable)
 *
 * Imports in this file: {@see \Phalanx\HttpClient\HttpClient},
 * {@see \Phalanx\HttpClient\HttpClientException}, {@see \Phalanx\Scope\Scope},
 * {@see \Phalanx\Scope\Suspendable}, {@see \Phalanx\Cancellation\Cancelled}.
 *
 * The Transport adapter family (Sync / HttpClient / Fake) is a coherent group of
 * adapters owned by ai-providers. Splitting HttpClient into phalanx-http-client would invert
 * the dependency direction (http-client importing from ai-providers) and fragment adapter
 * discoverability. The adapter lives here because it IS the bridge.
 *
 * Scope injection: this transport requires a {@see Scope}&{@see Suspendable}
 * at construction time. Callers that wire ai-providers with the Runtime runtime
 * extract the scope from {@see \Phalanx\AiProviders\Runtime\Runtime\Runtime::$scope}
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
     * Open a streaming wire-level request via phalanx-http-client.
     *
     * Yields raw byte chunks as they arrive from the HTTP response body.
     * Cancellation is detected via {@see Runtime::isCancelled()} between
     * read calls. On cancellation the underlying {@see \Phalanx\HttpClient\HttpStream}
     * is aborted and a {@see CancellationException} is re-thrown. Any other
     * Throwable closes the stream via {@see \Phalanx\HttpClient\HttpStream::fail()}
     * before propagating. The `finally` block guarantees
     * {@see \Phalanx\HttpClient\HttpStream::close()} runs regardless of exit path.
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
            $httpClientRequest = new HttpRequest(
                method: $request->method,
                url: $request->url,
                headers: self::wrapHeaders($request->headers),
                body: $request->body !== '' ? $request->body : null,
            );

            $stream = $client->stream($scope, $httpClientRequest);

            try {
                // Check cancellation before the first lazy header/body read.
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
                // Re-wrap Runtime coroutine cancellation for runtime symmetry.
                $stream->abort('cancelled');

                throw new CancellationException($e->getMessage(), 0, $e);
            } catch (HttpClientException $e) {
                // Wrap http-client-specific transport errors so callers never need to
                // import Phalanx\HttpClient\* to handle connection or protocol failures.
                $stream->fail($e->getMessage());

                throw new TransportException("http-client transport failed: {$e->getMessage()}", 0, $e);
            } catch (\Throwable $e) {
                $stream->fail($e->getMessage());

                throw $e;
            } finally {
                $stream->close();
            }
        })();
    }

    /**
     * Converts ai-providers's flat `array<string, string>` header map to the
     * http-client header format `array<string, list<string>>`.
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
