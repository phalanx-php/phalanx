<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Support;

final readonly class SseFrameParser
{
    /** @return array{event?: string, id?: string, data: string}|null */
    public function __invoke(string $text): ?array
    {
        $frame = ['data' => ''];
        $dataParts = [];

        foreach (explode("\n", $text) as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = substr($line, 0, $colon);
            $value = ltrim(substr($line, $colon + 1));
            if ($name === 'data') {
                $dataParts[] = $value;
            } elseif (in_array($name, ['event', 'id', 'retry'], true)) {
                $frame[$name] = $value;
            }
        }

        if ($dataParts === []) {
            return null;
        }

        $frame['data'] = implode("\n", $dataParts);
        return $frame;
    }
}
