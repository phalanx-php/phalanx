<?php

declare(strict_types=1);

namespace Acme;

use Phalanx\Athena\Activity\Config as ActivityConfig;
use Phalanx\Athena\Athena;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Cue\Effect\Executed;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Id;
use Phalanx\Panoply\Runtime\CancellationException;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\Sse\SseStream;
use Phalanx\Stoa\Sse\SseStreamFactory;
use Phalanx\Task\Scopeable;
use Throwable;

final class TriageHandler implements Scopeable
{
    public function __invoke(RequestScope $scope): SseStream
    {
        $agent  = new SupportTriageAgent();
        $config = new ActivityConfig(
            id: Id::generate(),
            context: Context::new(),
            maxInvocations: 4,
            timeoutSeconds: 25.0,
        );

        $stream = self::openStream($scope);

        try {
            $result = Athena::run($scope, $agent, $config);

            foreach ($result->stream->toArray() as $cue) {
                $payload = match (true) {
                    $cue instanceof TokenDelta => [
                        'type' => 'token',
                        'text' => $cue->text,
                    ],
                    $cue instanceof Requested => [
                        'type' => 'tool_start',
                        'tool' => $cue->effectId,
                    ],
                    $cue instanceof Executed => [
                        'type' => 'tool_done',
                        'tool' => $cue->effectId,
                    ],
                    default => null,
                };

                if ($payload === null) {
                    continue;
                }

                $stream->writeEvent(
                    json_encode($payload, JSON_THROW_ON_ERROR),
                    event: 'triage',
                );
            }
        } catch (Cancelled | CancellationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $stream->writeEvent(
                json_encode([
                    'type'    => 'error',
                    'message' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR),
                event: 'triage',
            );
        }

        $stream->close();

        return $stream;
    }

    private static function openStream(RequestScope $scope): SseStream
    {
        return (new SseStreamFactory())->open($scope);
    }
}
