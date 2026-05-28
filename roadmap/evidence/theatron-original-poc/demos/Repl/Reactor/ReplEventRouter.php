<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Reactor;

use Phalanx\Theatron\Demos\Repl\ConversationLog;
use Phalanx\Theatron\Demos\Repl\Event\AgentErrorEvent;
use Phalanx\Theatron\Demos\Repl\Event\LlmRequestCompleteEvent;
use Phalanx\Theatron\Demos\Repl\Event\LlmRequestStartEvent;
use Phalanx\Theatron\Demos\Repl\Event\ThinkingTokenEvent;
use Phalanx\Theatron\Demos\Repl\Event\TokenReceivedEvent;
use Phalanx\Theatron\Demos\Repl\Event\ToolCallEvent;
use Phalanx\Theatron\Demos\Repl\Event\TurnCompleteEvent;
use Phalanx\Theatron\Demos\Repl\Event\UserSubmitEvent;
use Phalanx\Theatron\Demos\Repl\Slice\AgentStatusSlice;
use Phalanx\Theatron\Demos\Repl\Slice\ConvoSlice;
use Phalanx\Theatron\Demos\Repl\Slice\ExchangeSummary;
use Phalanx\Theatron\Demos\Repl\Slice\InputSlice;
use Phalanx\Theatron\Demos\Repl\Slice\LlmRequestEntry;
use Phalanx\Theatron\Demos\Repl\Slice\LlmRequestSlice;
use Phalanx\Theatron\Reactor\ReactorContext;
use Phalanx\Theatron\Reactor\StreamReactor;
use Phalanx\Theatron\Stream\TheatronStream;

class ReplEventRouter implements StreamReactor
{
    public function __construct(
        private(set) AgentBridgeReactor $bridge,
        private(set) ConversationLog $log,
    ) {
    }

    public function subscribe(TheatronStream $stream, ReactorContext $context): void
    {
        $writer = $context->writer;
        $dirty = $context->dirty;
        $lens = $context->lens;
        $bridge = $this->bridge;

        $stream->subscribe(UserSubmitEvent::class, static function (UserSubmitEvent $event) use ($writer, $dirty, $bridge): void {
            $writer->update(ConvoSlice::class, static fn(ConvoSlice $s): ConvoSlice => $s->beginTurn($event->message));
            $writer->update(AgentStatusSlice::class, static fn(AgentStatusSlice $s): AgentStatusSlice => $s->withStatus('thinking'));
            $dirty->request();
            $bridge->submit($event->message);
        });

        $stream->subscribe(ThinkingTokenEvent::class, static function (ThinkingTokenEvent $event) use ($writer, $dirty): void {
            $writer->update(ConvoSlice::class, static function (ConvoSlice $s) use ($event): ConvoSlice {
                if ($s->activeTurn === null) {
                    return $s;
                }

                return $s->withActiveTurn($s->activeTurn->appendThinking($event->delta));
            });
            $dirty->request();
        });

        $stream->subscribe(TokenReceivedEvent::class, static function (TokenReceivedEvent $event) use ($writer, $dirty): void {
            $writer->update(ConvoSlice::class, static function (ConvoSlice $s) use ($event): ConvoSlice {
                if ($s->activeTurn === null) {
                    return $s;
                }

                return $s->withActiveTurn($s->activeTurn->appendText($event->delta));
            });
            $dirty->request();
        });

        $stream->subscribe(ToolCallEvent::class, static function (ToolCallEvent $event) use ($writer, $dirty): void {
            $writer->update(ConvoSlice::class, static function (ConvoSlice $s) use ($event): ConvoSlice {
                if ($s->activeTurn === null) {
                    return $s;
                }

                if ($event->started) {
                    return $s->withActiveTurn($s->activeTurn->addToolCall($event->toSummary()));
                }

                return $s->withActiveTurn($s->activeTurn->updateToolCall(
                    $event->toolName,
                    $event->result ?? 'ok',
                    $event->resultContent,
                    $event->resultType,
                ));
            });
            $writer->update(AgentStatusSlice::class, static fn(AgentStatusSlice $s): AgentStatusSlice => $s->withStatus('tool-use'));
            $dirty->request();
        });

        $log = $this->log;

        $stream->subscribe(TurnCompleteEvent::class, static function (TurnCompleteEvent $event) use ($writer, $dirty, $lens, $stream, $log): void {
            $next = $lens->handle(InputSlice::class)->value->peek();

            $convo = $lens->handle(ConvoSlice::class)->value;

            if ($convo->activeTurn !== null) {
                $exchange = $convo->activeTurn->finalize();
                $offset = $log->append($exchange);
                $summary = ExchangeSummary::fromExchange($exchange, $offset);

                $writer->update(ConvoSlice::class, static fn(ConvoSlice $s): ConvoSlice => $s->completeTurn($summary, $exchange));
            }

            $writer->update(AgentStatusSlice::class, static fn(AgentStatusSlice $s): AgentStatusSlice => $s->withStatus('idle'));
            $dirty->request();

            if ($next !== null) {
                $writer->update(InputSlice::class, static fn(InputSlice $s): InputSlice => $s->dequeue());
                $stream->emit(new UserSubmitEvent(message: $next));
            }
        });

        $stream->subscribe(AgentErrorEvent::class, static function (AgentErrorEvent $event) use ($writer, $dirty): void {
            $writer->update(ConvoSlice::class, static function (ConvoSlice $s) use ($event): ConvoSlice {
                if ($s->activeTurn === null) {
                    return $s;
                }

                $turn = $s->activeTurn->appendText("\n[error: {$event->message}]");

                return $s->withActiveTurn($turn);
            });
            $writer->update(AgentStatusSlice::class, static fn(AgentStatusSlice $s): AgentStatusSlice => $s->withStatus('idle'));
            $dirty->request();
        });

        $stream->subscribe(LlmRequestStartEvent::class, static function (LlmRequestStartEvent $event) use ($writer, $dirty): void {
            $entry = new LlmRequestEntry(
                requestId: $event->requestId,
                method: $event->method,
                path: $event->path,
                requestBody: $event->requestBody,
                startTime: $event->startTime,
            );
            $writer->update(LlmRequestSlice::class, static fn(LlmRequestSlice $s): LlmRequestSlice => $s->append($entry));
            $dirty->request();
        });

        $stream->subscribe(LlmRequestCompleteEvent::class, static function (LlmRequestCompleteEvent $event) use ($writer, $dirty): void {
            $writer->update(LlmRequestSlice::class, static fn(LlmRequestSlice $s): LlmRequestSlice => $s->completeById(
                $event->requestId,
                $event->status,
                $event->elapsedMs,
                $event->tokenCount,
                $event->responseBody,
            ));
            $dirty->request();
        });
    }
}
