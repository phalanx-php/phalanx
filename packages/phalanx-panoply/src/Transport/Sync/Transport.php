<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Transport\Sync;

use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Runtime\CancellationException;
use Phalanx\Panoply\Transport as TransportContract;
use Phalanx\Panoply\Transport\Request;

/**
 * Curl-based blocking HTTP transport. Executes the request synchronously,
 * buffers the full response body, then yields the buffered chunks.
 *
 * Sync transport buffers the entire response before yielding chunks. For
 * true incremental streaming, use Transport\Iris\Transport (requires
 * phalanx-iris). Sync exists as a Phalanx-independent fallback for testing
 * and short responses.
 *
 * Cancellation is honoured via CURLOPT_PROGRESSFUNCTION: when the runtime
 * signals cancellation, the progress callback returns non-zero and curl
 * aborts. Cancellation is therefore checked at the OS poll boundary, not
 * between PHP instructions.
 *
 * Final — sealed transport contract; subclassing would alter the
 * buffered-yield invariant tests rely on.
 */
final class Transport implements TransportContract
{
    public function __construct(
        private(set) int $connectTimeoutSeconds = 10,
        private(set) int $totalTimeoutSeconds = 300,
    ) {
    }

    /**
     * @return \Generator<int, string>
     * @throws HttpError when the response status is not 2xx
     */
    public function stream(Request $request, Runtime $runtime): \Generator
    {
        $chunks = [];

        $handle = curl_init();
        try {
            curl_setopt_array($handle, [
                CURLOPT_URL => $request->url,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $this->totalTimeoutSeconds,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => self::flattenHeaders($request->headers),
                CURLOPT_NOPROGRESS => false,
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $ch, string $data) use (&$chunks): int {
                    $chunks[] = $data;
                    return strlen($data);
                },
                // phpcs:ignore Generic.Files.LineLength.TooLong
                CURLOPT_PROGRESSFUNCTION => static fn(\CurlHandle $ch, int $downloadTotal, int $downloaded, int $uploadTotal, int $uploaded): int => $runtime->isCancelled() ? 1 : 0,
            ]);

            if ($request->method === 'POST') {
                curl_setopt($handle, CURLOPT_POST, true);
                curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
            } elseif ($request->method !== 'GET') {
                $method = $request->method;
                if ($method !== '') {
                    curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
                }
                if ($request->body !== '') {
                    curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
                }
            }

            $ok = curl_exec($handle);
            $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

            if (!$ok) {
                // CURLOPT_PROGRESSFUNCTION returned non-zero to abort — translate
                // to a panoply CancellationException so callers can distinguish
                // a deliberate cancellation from a network error.
                if ($runtime->isCancelled()) {
                    throw new CancellationException('Sync transport cancelled mid-stream');
                }

                $error = curl_error($handle);
                if ($error !== '') {
                    throw new \RuntimeException("curl error: {$error}");
                }
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                $body = implode('', $chunks);
                throw new HttpError(
                    statusCode: $statusCode,
                    responseBody: $body,
                    message: "HTTP {$statusCode} from {$request->url}",
                );
            }
        } finally {
            curl_close($handle);
        }

        foreach ($chunks as $chunk) {
            $runtime->throwIfCancelled();
            yield $chunk;
        }
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private static function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $value) {
            $flat[] = "{$name}: {$value}";
        }

        return $flat;
    }
}
