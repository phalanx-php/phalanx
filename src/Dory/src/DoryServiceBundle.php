<?php

declare(strict_types=1);

namespace Phalanx\Dory;

use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Console\Widget\Table;
use Phalanx\Boot\AppContext;
use Phalanx\Dory\Rendering\ArrayRenderer;
use Phalanx\Dory\Rendering\EchoSink;
use Phalanx\Dory\Rendering\OutputSink;
use Phalanx\Dory\Rendering\ScalarRenderer;
use Phalanx\Dory\Rendering\SettlementRenderer;
use Phalanx\Dory\Rendering\ThrowableRenderer;
use Phalanx\Dory\Rendering\ValueRendererPipeline;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Themis\ConfigFactory;

final class DoryServiceBundle extends ServiceBundle
{
    #[\Override]
    public static function configs(): array
    {
        return [DoryConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
        $services
            ->singleton(DoryConfig::class)
            ->factory(static fn(): DoryConfig => ConfigFactory::fromContext($context->values)
                ->hydrate(DoryConfig::class));

        $services->singleton(ValueRendererPipeline::class)
            ->factory(static fn(): ValueRendererPipeline => new ValueRendererPipeline([
                new ScalarRenderer(),
                new ArrayRenderer(new Table(Theme::default())),
                new SettlementRenderer(),
                new ThrowableRenderer(),
            ]));

        $services->scoped(OutputSink::class)
            ->factory(static fn(): OutputSink => new EchoSink());
    }
}
