<?php

declare(strict_types=1);

namespace Phalanx\Cli\Doctor;

use Symfony\Component\Process\Process;

final class PieCheck
{
    public function __invoke(): Check
    {
        $process = new Process(['pie', '--version']);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (\Throwable) {
            return self::notFound();
        }

        if (!$process->isSuccessful()) {
            return self::notFound();
        }

        $raw = trim($process->getOutput());

        if (preg_match('/(\d+\.\d+\.\d+)/', $raw, $m)) {
            return Check::pass('PIE', "v{$m[1]}");
        }

        return Check::pass('PIE', $raw !== '' ? $raw : 'installed');
    }

    private static function notFound(): Check
    {
        return Check::warn(
            'PIE',
            'Not found',
            "Install PIE: https://github.com/php/pie/releases\n"
            . '  Or: composer global require php/pie',
        );
    }
}
