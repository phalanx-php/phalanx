<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Surface;

use Phalanx\Theatron\Input\EventParser;
use Phalanx\Theatron\Input\InputEvent;
use React\EventLoop\Loop;

final class InputReader
{
    private EventParser $parser;

    /** @var resource|null */
    private mixed $stream;
    private bool $attached = false;

    /**
     * @param callable(InputEvent): void $dispatch
     * @param resource|null $stdin
     */
    public function __construct(
        private $dispatch,
        mixed $stdin = null,
    ) {
        $this->parser = new EventParser();
        $this->stream = $stdin ?? \STDIN;
    }

    public function attach(): void
    {
        if ($this->attached) {
            return;
        }

        stream_set_blocking($this->stream, false);

        $dispatch = $this->dispatch;
        $parser = $this->parser;
        $stream = $this->stream;

        Loop::addReadStream($stream, static function ($s) use ($dispatch, $parser): void {
            $data = fread($s, 8192);

            if ($data === false || $data === '') {
                return;
            }

            $events = $parser->parse($data);

            foreach ($events as $event) {
                $dispatch($event);
            }
        });

        $this->attached = true;
    }

    public function detach(): void
    {
        if (!$this->attached) {
            return;
        }

        Loop::removeReadStream($this->stream);
        $this->attached = false;
    }
}
