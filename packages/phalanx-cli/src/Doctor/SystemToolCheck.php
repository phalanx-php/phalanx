<?php

declare(strict_types=1);

namespace Phalanx\Cli\Doctor;

use Symfony\Component\Process\Process;

final class SystemToolCheck
{
    public function __construct(
        private(set) string $tool,
    ) {
    }

    public function __invoke(): Check
    {
        $process = new Process(['which', $this->tool]);
        $process->setTimeout(3);

        try {
            $process->run();
        } catch (\Throwable) {
            return self::notFound($this->tool);
        }

        if (!$process->isSuccessful()) {
            return self::notFound($this->tool);
        }

        return Check::pass($this->tool, trim($process->getOutput()));
    }

    private static function notFound(string $tool): Check
    {
        return Check::warn(
            $tool,
            'Not found (needed for PIE compilation)',
            match (PHP_OS_FAMILY) {
                'Darwin' => "brew install {$tool}",
                'Linux' => "sudo apt install {$tool}  (or equivalent for your distro)",
                default => "Install {$tool} for your platform",
            },
        );
    }
}
