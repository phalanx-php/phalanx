<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Phalanx\Athena\Athena;
use Phalanx\Athena\AthenaApplication;
use Phalanx\Athena\Provider\ProviderConfig;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AthenaApplicationBuilderTest extends TestCase
{
    #[Test]
    public function facadeBuilderReturnsAthenaApplicationWithDefaultAiBundle(): void
    {
        $app = Athena::starting()->build();

        try {
            self::assertInstanceOf(AthenaApplication::class, $app);
            self::assertSame($app->aegis(), $app->host());
            self::assertSame([
                'workspace' => 'default',
                'session' => 'default',
                'daemon8Url' => 'http://localhost:8888',
                'app' => 'phalanx-swarm',
            ], $app->run(Task::named(
                'test.athena.facade.defaults',
                static function (ExecutionScope $scope): array {
                    $swarmConfig = $scope->service(SwarmConfig::class);

                    self::assertInstanceOf(SwarmConfig::class, $swarmConfig);

                    return [
                        'workspace' => $swarmConfig->workspace,
                        'session' => $swarmConfig->session,
                        'daemon8Url' => $swarmConfig->daemon8Url,
                        'app' => $swarmConfig->app,
                    ];
                },
            )));
        } finally {
            $app->shutdown();
        }
    }

    #[Test]
    public function facadeBuilderPassesContextToAthenaServices(): void
    {
        $result = Athena::starting([
            'ANTHROPIC_API_KEY' => 'anthropic-test-key',
            'DAEMON8_APP' => 'athena-test',
            'DAEMON8_URL' => 'http://localhost:9077',
            'SWARM_SESSION' => 'session-a',
            'SWARM_WORKSPACE' => 'workspace-a',
        ])->run(Task::named(
            'test.athena.facade.context',
            static function (ExecutionScope $scope): array {
                $providerConfig = $scope->service(ProviderConfig::class);
                $swarmConfig = $scope->service(SwarmConfig::class);

                self::assertInstanceOf(ProviderConfig::class, $providerConfig);
                self::assertInstanceOf(SwarmConfig::class, $swarmConfig);

                return [
                    'providers' => array_keys($providerConfig->all()),
                    'workspace' => $swarmConfig->workspace,
                    'session' => $swarmConfig->session,
                    'daemon8Url' => $swarmConfig->daemon8Url,
                    'app' => $swarmConfig->app,
                ];
            },
        ));

        self::assertSame([
            'providers' => ['anthropic'],
            'workspace' => 'workspace-a',
            'session' => 'session-a',
            'daemon8Url' => 'http://localhost:9077',
            'app' => 'athena-test',
        ], $result);
    }

    #[Test]
    public function facadeRunPassesContextToAthenaServices(): void
    {
        $result = Athena::run(Task::named(
            'test.athena.facade.static-run',
            static fn(ExecutionScope $scope): string => $scope->service(SwarmConfig::class)->session,
        ), [
            'SWARM_SESSION' => 'static-run-session',
        ]);

        self::assertSame('static-run-session', $result);
    }
}
