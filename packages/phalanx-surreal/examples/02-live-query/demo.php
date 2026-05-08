<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealBundle;
use Phalanx\Surreal\SurrealLiveAction;
use Phalanx\Surreal\SurrealLiveNotification;
use Phalanx\Surreal\Examples\Support\SurrealBinaryLocator;
use Phalanx\Surreal\Examples\Support\SurrealCannotRun;
use Phalanx\Surreal\Examples\Support\SurrealFreePort;
use Phalanx\Surreal\Examples\Support\SurrealLiveNotificationChecker;
use Phalanx\Surreal\Examples\Support\SurrealNamespaceInitializer;
use Phalanx\Surreal\Examples\Support\SurrealServerErrorPrinter;
use Phalanx\Surreal\Examples\Support\SurrealServerReadiness;
use Phalanx\Surreal\Examples\Support\SurrealValueChecker;
use Phalanx\System\StreamingProcess;
use Phalanx\Task\Task;

return static function (array $context): \Closure {
    $appContext = AppContext::fromSymfonyRuntime($context);

    $binary = (new SurrealBinaryLocator())($appContext);

    if ($binary === null) {
        (new SurrealCannotRun())(
            'Surreal In-Memory Live Query',
            'the `surreal` binary was not found on PATH.',
            'install SurrealDB locally, then rerun this command.',
        );
    }

    $port = (new SurrealFreePort())();
    $endpoint = "http://127.0.0.1:{$port}";

    $appContext = $appContext
        ->with('surreal_namespace', 'athena')
        ->with('surreal_database', 'wisdom')
        ->with('surreal_endpoint', $endpoint)
        ->with('surreal_username', 'root')
        ->with('surreal_password', 'root');

    echo "Surreal In-Memory Live Query\n";
    echo "============================\n";
    printf("Endpoint: %s\n", $endpoint);
    echo "Topic: Athena dispatching live signals across the phalanx\n\n";

    return static fn (): int => (int) Application::starting($appContext)
        ->providers(new SurrealBundle())
        ->run(Task::named(
            'demo.surreal.live-query',
            static function (ExecutionScope $scope) use ($binary, $port): int {
                $server = StreamingProcess::command([
                    $binary,
                    'start',
                    '--no-banner',
                    '--username',
                    'root',
                    '--password',
                    'root',
                    '--allow-all',
                    '--bind',
                    "127.0.0.1:{$port}",
                    'memory',
                ])->start($scope);

                try {
                    $surreal = $scope->service(Surreal::class);
                    $readiness = new SurrealServerReadiness();
                    $printError = new SurrealServerErrorPrinter();
                    $initialize = new SurrealNamespaceInitializer();
                    $valueChecker = new SurrealValueChecker();
                    $checkNotification = new SurrealLiveNotificationChecker($valueChecker);

                    if (!$readiness($scope, $surreal, $server)) {
                        echo "  FAIL server did not become ready\n";
                        $printError($server);
                        return 1;
                    }

                    if (!$initialize($scope, "http://127.0.0.1:{$port}")) {
                        return 1;
                    }

                    $surreal->query('DEFINE TABLE IF NOT EXISTS agora_event SCHEMALESS; DELETE agora_event;');
                    $events = $surreal->live('agora_event');

                    $surreal->create('agora_event:athena_arrives', [
                        'name' => 'Athena',
                        'signal' => 'council-called',
                        'priority' => 1,
                    ]);
                    $created = $events->next(3.0);

                    $surreal->merge('agora_event:athena_arrives', ['signal' => 'strategy-updated']);
                    $updated = $events->next(3.0);

                    $surreal->delete('agora_event:athena_arrives');
                    $deleted = $events->next(3.0);

                    $events->kill();
                    $surreal->close();

                    $checks = [
                        'opened Athena live query' => $events->id() !== '',
                        'observed Athena CREATE' => $checkNotification(
                            $created,
                            SurrealLiveAction::Create,
                            'council-called',
                        ),
                        'observed Athena UPDATE' => $checkNotification(
                            $updated,
                            SurrealLiveAction::Update,
                            'strategy-updated',
                        ),
                        'observed Athena DELETE' => $deleted instanceof SurrealLiveNotification
                            && $deleted->action === SurrealLiveAction::Delete,
                        'killed live query cleanly' => !$events->isOpen,
                    ];

                    $failed = false;
                    foreach ($checks as $label => $ok) {
                        $failed = $failed || !$ok;
                        printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $label);
                    }

                    return $failed ? 1 : 0;
                } finally {
                    $server->stop(0.2, 0.1);
                }
            },
        ));
};
