<?php

declare(strict_types=1);

namespace BgAgents\Repl;

use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;
use React\EventLoop\Loop;
use React\Promise\Deferred;

/**
 * Non-blocking, line-buffered stdin → Emitter<string>.
 *
 * Deliberately simple: no readline, no escape parsing, no history. Just
 * fread → buffer → split on "\n" → emit. Fits cleanly inside the event loop
 * via Loop::addReadStream, completes when stdin closes or scope disposes.
 */
final class ReplLineReader
{
    public static function lines(): Emitter
    {
        return Emitter::produce(static function (Channel $channel, StreamContext $ctx): void {
            $stdin = STDIN;
            stream_set_blocking($stdin, false);

            $buffer = '';
            $suspend = new Deferred();
            $resolved = false;

            $resolve = static function () use (&$suspend, &$resolved): void {
                if (!$resolved) {
                    $resolved = true;
                    $suspend->resolve(null);
                }
            };

            $ctx->onDispose(static function () use ($stdin, $resolve): void {
                Loop::removeReadStream($stdin);
                Loop::futureTick($resolve);
            });

            Loop::addReadStream($stdin, static function ($stream) use (&$buffer, $channel, $resolve): void {
                $chunk = @fread($stream, 4096);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        $resolve();
                    }
                    return;
                }

                $buffer .= $chunk;
                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $nl);
                    $buffer = substr($buffer, $nl + 1);
                    $line = rtrim($line, "\r");
                    $channel->emit($line);
                }
            });

            $ctx->await($suspend->promise());
            $channel->complete();
        });
    }
}
