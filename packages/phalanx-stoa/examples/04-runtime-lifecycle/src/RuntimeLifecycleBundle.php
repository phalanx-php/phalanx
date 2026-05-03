<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime;

use Acme\StoaDemo\Runtime\Support\RuntimeEvents;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final readonly class RuntimeLifecycleBundle implements ServiceBundle
{
    /** @param array<string, mixed> $context */
    public function services(Services $services, array $context): void
    {
        $eventLog = (string) ($context['runtime_event_log'] ?? self::defaultEventLog());

        $services->singleton(RuntimeEvents::class)
            ->factory(static fn(): RuntimeEvents => new RuntimeEvents($eventLog));
    }

    public static function defaultEventLog(): string
    {
        return sys_get_temp_dir() . '/phalanx-stoa-runtime-lifecycle-events.jsonl';
    }
}
