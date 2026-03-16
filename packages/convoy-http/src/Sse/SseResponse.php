<?php

declare(strict_types=1);

namespace Convoy\Http\Sse;

use Convoy\ExecutionScope;
use Convoy\Stream\Emitter;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use React\Stream\ThroughStream;

use function React\Async\async;

final class SseResponse
{
    public static function from(
        Emitter $source,
        ExecutionScope $scope,
        ?string $event = null,
    ): ResponseInterface {
        $stream = new ThroughStream();

        $pump = async(static function () use ($source, $scope, $stream, $event): void {
            try {
                $id = 0;
                foreach (($source)($scope) as $item) {
                    $scope->throwIfCancelled();

                    if (!$stream->isWritable()) {
                        break;
                    }

                    $data = is_string($item) ? $item : json_encode($item, JSON_THROW_ON_ERROR);
                    $stream->write(SseEncoder::encode($data, $event, (string) ++$id));
                }
            } catch (\Convoy\Exception\CancelledException) {
            } catch (\Throwable $e) {
                fwrite(STDERR, "SSE pump error: {$e->getMessage()}\n");
            } finally {
                if ($stream->isWritable()) {
                    $stream->end();
                }
            }
        });

        $stream->on('close', static function () use ($scope): void {
            $scope->dispose();
        });

        $pump();

        return new Response(
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
            $stream,
        );
    }
}
