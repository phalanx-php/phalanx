<?php

declare(strict_types=1);

namespace Phalanx\Console\Style;

use Phalanx\Console\Output\StreamOutput;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

/**
 * Registers the console foundation services as singletons.
 *
 * Override the Theme binding in your application's ServiceBundle to
 * apply a custom color scheme without touching any widget code.
 */
final class ConsoleServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(Theme::class)
            ->factory(static fn() => Theme::default());

        $services->singleton(StreamOutput::class)
            ->factory(static fn() => new StreamOutput());
    }
}
