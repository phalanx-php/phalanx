<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealBundle;
use Phalanx\System\StreamingProcess;
use Phalanx\Task\Task;

$binary = phalanxSurrealExampleBinary();

if ($binary === null) {
    phalanxSurrealExampleCannotRun(
        'Surreal In-Memory RPC',
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

echo "Surreal In-Memory RPC\n";
echo "=====================\n";
printf("Endpoint: %s\n", $endpoint);
echo "Topic: Athena as disciplined wisdom and strategic clarity\n\n";

$exitCode = Application::starting($context)
    ->providers(new SurrealBundle())
    ->run(Task::named(
        'demo.surreal.in-memory-rpc',
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

                $surreal->query('DELETE goddess;');
                $created = $surreal->create('goddess:athena', [
                    'name' => 'Athena',
                    'domain' => 'wisdom',
                    'city' => 'Athens',
                ]);
                $selected = $surreal->select('goddess:athena');
                $merged = $surreal->merge('goddess:athena', ['symbol' => 'aegis']);
                $queried = $surreal->query(
                    'SELECT name, domain, symbol FROM goddess WHERE name = $name;',
                    ['name' => 'Athena'],
                );
                $surreal->delete('goddess:athena');
                $afterDelete = $surreal->select('goddess:athena');

                $checks = [
                    'server health endpoint' => in_array($surreal->health(), [200, 204], true),
                    'created Athena record' => phalanxSurrealExampleHasRecord($created, 'Athena'),
                    'selected Athena record' => phalanxSurrealExampleHasRecord($selected, 'Athena'),
                    'merged Athena symbol' => phalanxSurrealExampleHasValue($merged, 'aegis'),
                    'queried Athena projection' => phalanxSurrealExampleHasRecord($queried, 'Athena'),
                    'deleted Athena record' => !phalanxSurrealExampleHasRecord($afterDelete, 'Athena'),
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
