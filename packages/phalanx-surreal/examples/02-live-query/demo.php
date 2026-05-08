<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealBundle;
use Phalanx\Surreal\SurrealLiveAction;
use Phalanx\Surreal\SurrealLiveNotification;
use Phalanx\System\StreamingProcess;
use Phalanx\Task\Task;

$binary = phalanxSurrealExampleBinary();

if ($binary === null) {
    phalanxSurrealExampleCannotRun(
        'Surreal In-Memory Live Query',
        'the `surreal` binary was not found on PATH.',
        'install SurrealDB locally, then rerun this command.',
    );
}

$port = phalanxSurrealExamplePort();
$endpoint = "http://127.0.0.1:{$port}";
$context = [
    'argv' => $argv ?? [],
    'surreal_namespace' => 'athena',
    'surreal_database' => 'wisdom',
    'surreal_endpoint' => $endpoint,
    'surreal_username' => 'root',
    'surreal_password' => 'root',
];

echo "Surreal In-Memory Live Query\n";
echo "============================\n";
printf("Endpoint: %s\n", $endpoint);
echo "Topic: Athena dispatching live signals across the phalanx\n\n";

$exitCode = Application::starting($context)
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

                if (!phalanxSurrealExampleWaitForServer($scope, $surreal, $server)) {
                    echo "  FAIL server did not become ready\n";
                    phalanxSurrealExamplePrintServerError($server);
                    return 1;
                }

                if (!phalanxSurrealExampleInitialize($scope, "http://127.0.0.1:{$port}")) {
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
                    'observed Athena CREATE' => phalanxSurrealExampleLiveNotification(
                        $created,
                        SurrealLiveAction::Create,
                        'council-called',
                    ),
                    'observed Athena UPDATE' => phalanxSurrealExampleLiveNotification(
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

exit((int) $exitCode);
