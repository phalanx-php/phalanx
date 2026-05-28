<?php

declare(strict_types=1);

namespace Phx\Command;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

final class DoctorCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $output->persist("<info>Dory Environment Doctor</info>");

        $output->persist("<comment>Checking runtime...</comment>");

        $phpVersion = PHP_VERSION;
        $output->persist("  - PHP Version: <info>{$phpVersion}</info>");

        $extensions = ['swoole', 'openssl', 'curl', 'mbstring', 'phar', 'pcntl', 'posix'];
        foreach ($extensions as $ext) {
            $loaded = extension_loaded($ext) ? '<info>OK</info>' : '<error>MISSING</error>';
            $output->persist("  - Extension {$ext}: {$loaded}");
        }

        if (class_exists('OpenSwoole\Runtime')) {
            $output->persist("  - OpenSwoole API Shim: <info>Available</info>");
        } else {
            $output->persist("  - OpenSwoole API Shim: <error>Missing</error>");
        }

        if (file_exists('composer.json')) {
            $output->persist("  - Project Context: <info>Detected</info>");
        } else {
            $output->persist("  - Project Context: <comment>Not in a project directory</comment>");
        }

        return 0;
    }
}
