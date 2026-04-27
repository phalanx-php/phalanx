<?php

declare(strict_types=1);

namespace Phalanx\Athena\Stream;

use Generator;

final class SseParser
{
    private string $buffer = '';

    /** @return Generator<int, array{event: ?string, data: string}> */
    public function feed(string $chunk): Generator
    {
        $this->buffer .= $chunk;
        $this->buffer = str_replace("\r\n", "\n", $this->buffer);

        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $block = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 2);

            $event = null;
            $data = [];

            foreach (explode("\n", $block) as $line) {
                if ($line === '' || $line[0] === ':') {
                    continue;
                }

                $colonPos = strpos($line, ':');
                if ($colonPos === false) {
                    continue;
                }

                $field = substr($line, 0, $colonPos);
                $value = ltrim(substr($line, $colonPos + 1), ' ');

                match ($field) {
                    'event' => $event = $value,
                    'data' => $data[] = $value,
                    default => null,
                };
            }

            if ($data !== []) {
                yield ['event' => $event, 'data' => implode("\n", $data)];
            }
        }
    }

    public function reset(): void
    {
        $this->buffer = '';
    }
}
