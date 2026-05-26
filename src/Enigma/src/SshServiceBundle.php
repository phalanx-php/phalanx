<?php

declare(strict_types=1);

namespace Phalanx\Enigma;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Themis\Config;

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
