<?php

declare(strict_types=1);

namespace Phalanx\Cli\Install;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class PieRunner
{
    public function __construct(
        private(set) string $pieBinary = 'pie',
    ) {
    }

    public function isInstalled(): bool
    {
        return $this->version() !== null;
    }

    public function version(): ?string
    {
        $process = new Process([$this->pieBinary, '--version']);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        if (preg_match('/(\d+\.\d+\.\d+)/', $output, $m)) {
            return $m[1];
        }

        return $output !== '' ? $output : null;
    }

    public function install(FlagSet $flags, OutputInterface $output): int
    {
        $argv = [$this->pieBinary, 'install', 'openswoole/ext-openswoole'];

        foreach ($flags->toPieArgs() as $arg) {
            $argv[] = $arg;
        }

        $process = new Process($argv);
        $process->setTimeout(null);

        $exitCode = $process->run(static function (string $type, string $buffer) use ($output): void {
            if ($type === Process::ERR) {
                $output->write("<comment>{$buffer}</comment>");
            } else {
                $output->write($buffer);
            }
        });

        return $exitCode;
    }

    public static function verifyExtensionLoaded(): bool
    {
        $process = new Process([
            PHP_BINARY,
            '-r',
            "echo extension_loaded('openswoole') ? 'yes' : 'no';",
        ]);
        $process->setTimeout(5);
        $process->run();

        return trim($process->getOutput()) === 'yes';
    }
}
