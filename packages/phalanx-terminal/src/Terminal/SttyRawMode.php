<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Terminal;

final class SttyRawMode implements RawMode
{
    private ?string $savedSettings = null;

    public function enable(): void
    {
        $this->savedSettings = self::exec('stty -g');
        self::exec('stty raw -echo');
    }

    public function disable(): void
    {
        if ($this->savedSettings !== null) {
            self::exec("stty {$this->savedSettings}");
            $this->savedSettings = null;
        }
    }

    private static function exec(string $command): string
    {
        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/tty', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!is_resource($process)) {
            return '';
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim((string) $output);
    }
}
