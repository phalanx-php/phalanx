<?php

declare(strict_types=1);

namespace Sentinel;

use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;
use Phalanx\Theatron\Input\EventParser;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\PasteEvent;
use Phalanx\Theatron\Widget\InputLine;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use Sentinel\Input\SttyRawMode;

/**
 * Stdin reader for non-TUI sentinel mode. Renders the prompt directly to
 * STDOUT (no Surface), but uses Theatron's input parser and InputLine widget
 * for parsing and line-editing semantics.
 */
final class RawInputReader
{
    private const string PROMPT = "\033[36m  +> \033[0m";
    private const int PROMPT_WIDTH = 5;

    public static function lines(): Emitter
    {
        return Emitter::produce(static function (Channel $ch, StreamContext $ctx): void {
            $rawMode = new SttyRawMode();
            $rawMode->enable();

            $parser = new EventParser();
            $inputLine = new InputLine();
            $suspend = new Deferred();
            $stdin = STDIN;

            $cleanup = static function () use ($rawMode, $stdin): void {
                Loop::removeReadStream($stdin);
                $rawMode->disable();
            };

            $ctx->onDispose($cleanup);

            stream_set_blocking($stdin, false);

            $redraw = static function () use ($inputLine): void {
                $text = $inputLine->text;
                $cursor = $inputLine->cursorPosition;
                $offset = self::PROMPT_WIDTH + $cursor;

                fwrite(STDOUT, "\r\033[K" . self::PROMPT . $text);

                if ($offset > 0) {
                    fwrite(STDOUT, "\r\033[" . $offset . "C");
                } else {
                    fwrite(STDOUT, "\r");
                }
            };

            Loop::addReadStream($stdin, static function ($stream) use (
                $parser, $inputLine, $ch, $redraw, $cleanup,
            ): void {
                $data = @fread($stream, 8192);
                if ($data === false || $data === '') {
                    return;
                }

                $events = $parser->parse($data);

                foreach ($events as $event) {
                    if ($event instanceof PasteEvent) {
                        $inputLine->insertText($event->content);
                        $redraw();
                        continue;
                    }

                    if (!$event instanceof KeyEvent) {
                        continue;
                    }

                    if ($event->ctrl && $event->is('c')) {
                        fwrite(STDOUT, "\r\n");
                        $cleanup();
                        Loop::stop();
                        return;
                    }

                    $submitted = $inputLine->handleKey($event);

                    if ($submitted !== null) {
                        fwrite(STDOUT, "\r\033[K");
                        if ($submitted !== '') {
                            $ch->emit($submitted);
                        }
                        $redraw();
                    } else {
                        $redraw();
                    }
                }
            });

            $redraw();

            $ctx->await($suspend->promise());
        });
    }
}
