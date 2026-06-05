<?php

declare(strict_types=1);

namespace Phalanx\Ssh;

use Phalanx\Boot\AppContext;
use Phalanx\Config\Config;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class SshServiceBundle extends ServiceBundle
{
    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [SshConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}
