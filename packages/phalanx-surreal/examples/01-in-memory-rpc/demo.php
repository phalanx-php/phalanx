<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealBundle;
use Phalanx\Surreal\Examples\Support\SurrealBinaryLocator;
use Phalanx\Surreal\Examples\Support\SurrealCannotRun;
use Phalanx\Surreal\Examples\Support\SurrealFreePort;
use Phalanx\Surreal\Examples\Support\SurrealNamespaceInitializer;
use Phalanx\Surreal\Examples\Support\SurrealRecordChecker;
use Phalanx\Surreal\Examples\Support\SurrealServerErrorPrinter;
use Phalanx\Surreal\Examples\Support\SurrealServerReadiness;
use Phalanx\Surreal\Examples\Support\SurrealValueChecker;
use Phalanx\System\StreamingProcess;
use Phalanx\Task\Task;

return static function (array $context): \Closure {
    $binary = (new SurrealBinaryLocator())(new AppContext($context));

    if ($binary === null) {
        (new SurrealCannotRun())(
            'Surreal In-Memory RPC',
            'the `surreal` binary was not found on PATH.',
            'install SurrealDB locally, then rerun this command.',
        );
    }

    $port = (new SurrealFreePort())();
    $endpoint = "http://127.0.0.1:{$port}";

    $context['surreal_namespace'] = 'athena';
    $context['surreal_database'] = 'wisdom';
    $context['surreal_endpoint'] = $endpoint;
    $context['surreal_username'] = 'root';
    $context['surreal_password'] = 'root';

    echo "Surreal In-Memory RPC\n";
    echo "=====================\n";
    printf("Endpoint: %s\n", $endpoint);
    echo "Topic: Athena as disciplined wisdom and strategic clarity\n\n";

    return static fn (): int => (int) Application::starting($context)
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
                    $readiness = new SurrealServerReadiness();
                    $printError = new SurrealServerErrorPrinter();
                    $initialize = new SurrealNamespaceInitializer();
                    $hasRecord = new SurrealRecordChecker();
                    $hasValue = new SurrealValueChecker();

                    if (!$readiness($scope, $surreal, $server)) {
                        echo "  FAIL server did not become ready\n";
                        $printError($server);
                        return 1;
                    }

                    if (!$initialize($scope, "http://127.0.0.1:{$port}")) {
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
                        'created Athena record' => $hasRecord($created, 'Athena'),
                        'selected Athena record' => $hasRecord($selected, 'Athena'),
                        'merged Athena symbol' => $hasValue($merged, 'aegis'),
                        'queried Athena projection' => $hasRecord($queried, 'Athena'),
                        'deleted Athena record' => !$hasRecord($afterDelete, 'Athena'),
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
