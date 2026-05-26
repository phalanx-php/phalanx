<?php

declare(strict_types=1);

namespace Phalanx\Harness;

use Phalanx\Agora\Harness\CueRecorder;
use Phalanx\Agora\Harness\Persistence\SurrealCueRecorder;
use Phalanx\Agora\Harness\Persistence\SurrealEventLog;
use Phalanx\Agora\Harness\Persistence\SurrealHarnessStore;
use Phalanx\Agora\Harness\Replay\ProjectionCheckpointReader;
use Phalanx\Agora\Harness\Replay\ReplaySessionReader;
use Phalanx\Boot\AppContext;
use Phalanx\Harness\Replay\TheatronReplayHydrator;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Surreal\Surreal;
use Phalanx\Themis\Config;

final class AgoraServiceBundle extends ServiceBundle
{
    public function __construct(
        private HarnessMode $mode,
    ) {
    }

    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [HarnessConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
        if ($this->mode !== HarnessMode::Durable) {
            return;
        }

        $services->scoped(SurrealHarnessStore::class)
            ->needs(Surreal::class)
            ->factory(static fn(Surreal $surreal): SurrealHarnessStore => new SurrealHarnessStore($surreal));

        $services->scoped(SurrealEventLog::class)
            ->needs(Surreal::class)
            ->factory(static fn(Surreal $surreal): SurrealEventLog => new SurrealEventLog($surreal));

        $services->scoped(SurrealCueRecorder::class)
            ->needs(SurrealEventLog::class)
            ->factory(static fn(SurrealEventLog $events): SurrealCueRecorder => new SurrealCueRecorder($events));

        $services->alias(CueRecorder::class, SurrealCueRecorder::class);
        $services->alias(ProjectionCheckpointReader::class, SurrealHarnessStore::class);

        $services->scoped(ReplaySessionReader::class)
            ->needs(SurrealEventLog::class)
            ->needs(SurrealHarnessStore::class)
            ->factory(static fn(
                SurrealEventLog $events,
                SurrealHarnessStore $store,
            ): ReplaySessionReader => new ReplaySessionReader($events, $store));

        $services->singleton(TheatronReplayHydrator::class)
            ->factory(static fn(): TheatronReplayHydrator => new TheatronReplayHydrator());
    }
}
