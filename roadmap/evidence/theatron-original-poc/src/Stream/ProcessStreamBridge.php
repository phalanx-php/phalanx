<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Stream;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Hydra\Protocol\Codec;
use Phalanx\Hydra\Protocol\Response;
use Phalanx\Hydra\Protocol\ServiceCall;
use Phalanx\Hydra\Protocol\StreamEmit;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskHandle;
use Phalanx\System\StreamingProcessHandle;

final class ProcessStreamBridge
{
    private const float READ_TIMEOUT = 0.1;

    private ?TaskHandle $reader = null;

    public function __construct(
        private StreamingProcessHandle $process,
        private TheatronStream $stream,
        private ExecutionScope $scope,
    ) {
    }

    public function start(): void
    {
        if ($this->reader !== null) {
            return;
        }

        $process = $this->process;
        $stream = $this->stream;

        $this->reader = $this->scope->go(static function () use ($process, $stream): void {
            self::readLoop($process, $stream);
        }, 'theatron.bridge.reader');
    }

    public function stop(): void
    {
        $this->reader?->cancel();
        $this->process->close('bridge.stop');
    }

    private static function readLoop(StreamingProcessHandle $process, TheatronStream $stream): void
    {
        while ($process->isRunning()) {
            try {
                $line = $process->readLine(self::READ_TIMEOUT);
            } catch (Cancelled $e) {
                throw $e;
            } catch (\Throwable) {
                break;
            }

            if ($line === '') {
                continue;
            }

            try {
                $message = Codec::decode($line);
            } catch (\Throwable) {
                continue;
            }

            if ($message instanceof StreamEmit) {
                self::handleStreamEmit($message, $stream);
                continue;
            }

            if ($message instanceof Response) {
                break;
            }
        }
    }

    private static function handleStreamEmit(StreamEmit $message, TheatronStream $stream): void
    {
        $class = $message->eventClass;

        if (!class_exists($class)) {
            return;
        }

        if (!is_subclass_of($class, SerializableStreamEvent::class)) {
            return;
        }

        $event = $class::fromPayload($message->payload);
        $stream->emit($event);
    }
}
