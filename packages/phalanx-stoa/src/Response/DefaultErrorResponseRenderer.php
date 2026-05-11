<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Response;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\StoaRequestResource;
use Phalanx\Stoa\Runtime\StoaScopeKey;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Supervisor\TaskTreeFormatter;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Standard Stoa error response implementation.
 *
 * Extracted from the legacy hardcoded logic in StoaRunner. Returns a 500 JSON
 * response with optional debug information (trace, task tree) when enabled.
 */
final readonly class DefaultErrorResponseRenderer implements ErrorResponseRenderer
{
    public function __construct(private bool $debug = false)
    {
    }

    public function render(RequestScope $scope, Throwable $e): ?ResponseInterface
    {
        $resource = $scope->attribute(StoaScopeKey::RequestResource->value);

        $body = [
            'error' => 'Internal Server Error',
        ];

        if ($this->debug) {
            $body['message'] = $e->getMessage();

            if ($resource instanceof StoaRequestResource) {
                $body['request'] = [
                    'id' => $resource->id,
                    'path' => $resource->path,
                    'state' => $resource->stateValue(),
                    'method' => $resource->method,
                ];
            } else {
                $body['request'] = [
                    'id' => 'err-' . uniqid('plx_'),
                    'path' => $scope->path(),
                    'state' => 'failed',
                    'method' => $scope->method(),
                ];
            }

            $body['trace'] = $this->formatTrace($e);
            $body['tasks'] = '';

            try {
                $body['tasks'] = (new TaskTreeFormatter())->format(
                    $scope->service(Supervisor::class)->tree(),
                );
            } catch (Cancelled $c) {
                throw $c;
            } catch (\Throwable) {
            }
        }

        return new PsrResponse(
            500,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<string> */
    private function formatTrace(Throwable $e): array
    {
        $trace = [];

        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $func = $frame['function'];
            $class = isset($frame['class']) ? $frame['class'] . '::' : '';
            $trace[] = "{$class}{$func} at {$file}:{$line}";
        }

        return array_slice($trace, 0, 10);
    }
}
