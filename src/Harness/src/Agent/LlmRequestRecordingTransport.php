<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Harness\Ui\AppStore;
use Phalanx\Harness\Ui\Slices\LlmRequestEntry;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Transport;
use Phalanx\Panoply\Transport\Request;
use Phalanx\Panoply\Transport\Sync\HttpError;

final class LlmRequestRecordingTransport implements Transport
{
    /** @var \Closure(): float */
    private \Closure $clock;

    /** @var \Closure(): string */
    private \Closure $requestIds;

    public function __construct(
        private(set) Transport $inner,
        private(set) AppStore $store,
        private(set) ?string $invocationId = null,
        ?\Closure $clock = null,
        ?\Closure $requestIds = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
        $this->requestIds = $requestIds ?? static fn(): string => uniqid('req-', true);
    }

    public static function wrap(Transport $transport, AppStore $store, ?string $invocationId = null): self
    {
        return new self($transport, $store, $invocationId);
    }

    /**
     * @return \Generator<int, string>
     */
    public function stream(Request $request, Runtime $runtime): \Generator
    {
        $requestId = ($this->requestIds)();
        $startedAt = ($this->clock)();
        $responseBody = '';
        $finished = false;

        $this->store->requests = $this->store->requests->append(new LlmRequestEntry(
            requestId: $requestId,
            method: $request->method,
            path: self::pathFromUrl($request->url),
            requestBody: $request->body,
            startTime: $startedAt,
            invocationId: $this->invocationId,
        ));

        try {
            foreach ($this->inner->stream($request, $runtime) as $chunk) {
                $responseBody .= $chunk;
                $this->store->requests = $this->store->requests->updateResponseBodyById($requestId, $responseBody);

                yield $chunk;
            }

            $this->complete($requestId, 200, $startedAt, $responseBody);
            $finished = true;
        } catch (HttpError $e) {
            $this->complete($requestId, $e->statusCode, $startedAt, $e->responseBody);
            $finished = true;

            throw $e;
        } catch (Cancelled $e) {
            $this->error($requestId, $startedAt, new \RuntimeException('stream cancelled'));
            $finished = true;

            throw $e;
        } catch (\Throwable $e) {
            $this->error($requestId, $startedAt, $e);
            $finished = true;

            throw $e;
        } finally {
            if (!$finished) {
                $this->error($requestId, $startedAt, new \RuntimeException('stream abandoned'));
            }
        }
    }

    private static function pathFromUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';

        if (isset($parts['query']) && $parts['query'] !== '') {
            return "{$path}?{$parts['query']}";
        }

        return $path;
    }

    private function complete(string $requestId, int $status, float $startedAt, string $body): void
    {
        $this->store->requests = $this->store->requests->completeById(
            requestId: $requestId,
            status: $status,
            elapsedMs: $this->elapsedMs($startedAt),
            tokenCount: $this->store->activity->totalTokens,
            body: $body,
        );
    }

    private function error(string $requestId, float $startedAt, \Throwable $e): void
    {
        $this->store->requests = $this->store->requests->errorById(
            requestId: $requestId,
            error: $e->getMessage(),
            elapsedMs: $this->elapsedMs($startedAt),
        );
    }

    private function elapsedMs(float $startedAt): float
    {
        return max(0.0, (($this->clock)() - $startedAt) * 1000);
    }
}
