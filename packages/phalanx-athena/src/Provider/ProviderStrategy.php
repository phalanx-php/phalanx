<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;

final readonly class ProviderStrategy
{
    public static function primary(LlmProvider ...$providers): LlmProvider
    {
        $list = array_values($providers);

        return new class ($list) implements LlmProvider {
            /** @param list<LlmProvider> $providers */
            public function __construct(private array $providers)
            {
            }

            public function generate(GenerateRequest $request): Emitter
            {
                $providers = $this->providers;

                return Emitter::produce(static function (
                    Channel $ch,
                    StreamContext $ctx,
                ) use (
                    $request,
                    $providers,
                ): void {
                    $firstProvider = $providers[0] ?? null;
                    if ($firstProvider === null) {
                        throw new \RuntimeException('No providers configured for race strategy');
                    }

                    foreach ($firstProvider->generate($request)($ctx) as $event) {
                        $ch->emit($event);
                    }
                });
            }
        };
    }

    public static function fallback(LlmProvider ...$providers): LlmProvider
    {
        $list = array_values($providers);

        return new class ($list) implements LlmProvider {
            /** @param list<LlmProvider> $providers */
            public function __construct(private array $providers)
            {
            }

            public function generate(GenerateRequest $request): Emitter
            {
                $providers = $this->providers;

                return Emitter::produce(static function (
                    Channel $ch,
                    StreamContext $ctx,
                ) use (
                    $request,
                    $providers,
                ): void {
                    foreach ($providers as $provider) {
                        try {
                            foreach ($provider->generate($request)($ctx) as $event) {
                                $ch->emit($event);
                            }
                            return;
                        } catch (\Throwable) {
                            continue;
                        }
                    }

                    throw new \RuntimeException('All providers failed');
                });
            }
        };
    }

    public static function roundRobin(LlmProvider ...$providers): LlmProvider
    {
        $list = array_values($providers);

        return new class ($list) implements LlmProvider {
            private int $index = 0;

            /** @param list<LlmProvider> $providers */
            public function __construct(private array $providers)
            {
            }

            public function generate(GenerateRequest $request): Emitter
            {
                $provider = $this->providers[$this->index % count($this->providers)];
                $this->index++;

                return $provider->generate($request);
            }
        };
    }
}
