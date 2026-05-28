<?php

declare(strict_types=1);

namespace Phx\Command;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

final class DoctorCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $output->persist("<info>Phalanx Doctor</info>");

        $output->persist("<comment>Checking environment...</comment>");

        // 1. Check PHP Version
        $phpVersion = PHP_VERSION;
        $output->persist("  - PHP Version: <info>{$phpVersion}</info>");

        // 2. Check Extensions
        $extensions = ['swoole', 'openswoole', 'openssl', 'curl', 'mbstring', 'phar', 'pcntl', 'posix'];
        foreach ($extensions as $ext) {
            $loaded = extension_loaded($ext) ? '<info>OK</info>' : '<error>MISSING</error>';
            $output->persist("  - Extension {$ext}: {$loaded}");
        }

        // 3. Check OpenSwoole specifically
        if (class_exists('OpenSwoole\Runtime')) {
            $output->persist("  - OpenSwoole API: <info>Available</info>");
        } else {
            $output->persist("  - OpenSwoole API: <error>Missing (Shim required)</error>");
        }

        // 4. Check Project structure
        if (file_exists('composer.json')) {
            $output->persist("  - Project structure: <info>Detected</info>");
        } else {
            $output->persist("  - Project structure: <comment>Not a Phalanx project</comment>");
        }

        return 0;
    }
}
