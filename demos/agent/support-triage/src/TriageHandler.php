<?php

declare(strict_types=1);

namespace Acme;

use Phalanx\Agent\Activity\Config as ActivityConfig;
use Phalanx\Agent\Agent;
use Phalanx\Cancellation\Cancelled;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Cue\Effect\Executed;
use Phalanx\AiProviders\Cue\Effect\Requested;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Id;
use Phalanx\AiProviders\Runtime\CancellationException;
use Phalanx\Http\RequestContext;
use Phalanx\Http\Sse\SseStream;
use Phalanx\Http\Sse\SseStreamFactory;
use Phalanx\Task\Scopeable;
use Throwable;

final class TriageHandler implements Scopeable
{
    public function __invoke(RequestContext $ctx): SseStream
    {
        $agent  = new SupportTriageAgent();
        $config = new ActivityConfig(
            id: Id::generate(),
            context: Context::new(),
            maxInvocations: 4,
            timeoutSeconds: 25.0,
        );

        $stream = self::openStream($ctx);

        try {
            $result = Agent::run($ctx, $agent, $config);

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

    private static function openStream(RequestContext $ctx): SseStream
    {
        return (new SseStreamFactory())->open($ctx);
    }
}
