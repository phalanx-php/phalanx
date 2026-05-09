<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Demos\Surreal\Support\SurrealBinaryLocator;
use Phalanx\Demos\Surreal\Support\SurrealFreePort;
use Phalanx\Demos\Surreal\Support\SurrealNamespaceInitializer;
use Phalanx\Demos\Surreal\Support\SurrealRecordChecker;
use Phalanx\Demos\Surreal\Support\SurrealServerErrorPrinter;
use Phalanx\Demos\Surreal\Support\SurrealServerReadiness;
use Phalanx\Demos\Surreal\Support\SurrealValueChecker;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealBundle;
use Phalanx\System\StreamingProcess;
use Phalanx\Task\Task;

// SurrealBundle reads its config from context at compile-time, so port
// allocation and binary detection have to happen before DemoApp::boot.
return static function (array $context): \Closure {
    $binary = (new SurrealBinaryLocator())(new AppContext($context));
    if ($binary === null) {
        $report = new DemoReport('Surreal In-Memory RPC');

        return static fn (): int => $report->cannotRun(
            'the `surreal` binary was not found on PATH.',
            'install SurrealDB locally, then rerun this command.',
        );
    }

    $port = (new SurrealFreePort())();
    $endpoint = "http://127.0.0.1:{$port}";
    $context['surreal_namespace'] = 'olympus';
    $context['surreal_database']  = 'pantheon';
    $context['surreal_endpoint']  = $endpoint;
    $context['surreal_username']  = 'root';
    $context['surreal_password']  = 'root';

    return (DemoApp::boot(
        'Surreal In-Memory RPC',
        static function (DemoApp $app, DemoReport $report) use ($binary, $port, $endpoint): void {
            $report->note(sprintf('Endpoint: %s', $endpoint));
            $report->note('Topic: Apollo, oracle of Delphi, recording his prophecies');

            $app->run(Task::named(
                'demo.surreal.in-memory-rpc',
                static function (ExecutionScope $scope) use ($binary, $port, $report): void {
                    $server = StreamingProcess::command([
                        $binary, 'start',
                        '--no-banner',
                        '--username', 'root',
                        '--password', 'root',
                        '--allow-all',
                        '--bind', "127.0.0.1:{$port}",
                        'memory',
                    ])->start($scope);

                    try {
                        $surreal = $scope->service(Surreal::class);

                        if (!(new SurrealServerReadiness())($scope, $surreal, $server)) {
                            $report->record('server became ready', false);
                            (new SurrealServerErrorPrinter())($server);

                            return;
                        }
                        if (!(new SurrealNamespaceInitializer())($scope, "http://127.0.0.1:{$port}")) {
                            $report->record('namespace initialized', false);

                            return;
                        }

                        $surreal->query('DELETE oracle;');
                        $created     = $surreal->create('oracle:apollo', ['name' => 'Apollo', 'domain' => 'prophecy', 'shrine' => 'Delphi']);
                        $selected    = $surreal->select('oracle:apollo');
                        $merged      = $surreal->merge('oracle:apollo', ['symbol' => 'laurel']);
                        $queried     = $surreal->query(
                            'SELECT name, domain, symbol FROM oracle WHERE name = $name;',
                            ['name' => 'Apollo'],
                        );
                        $surreal->delete('oracle:apollo');
                        $afterDelete = $surreal->select('oracle:apollo');

                        $hasRecord = new SurrealRecordChecker();
                        $hasValue  = new SurrealValueChecker();

                        $report->record('server health endpoint',     in_array($surreal->health(), [200, 204], true));
                        $report->record('created Apollo record',      $hasRecord($created, 'Apollo'));
                        $report->record('selected Apollo record',     $hasRecord($selected, 'Apollo'));
                        $report->record('merged Apollo symbol',       $hasValue($merged, 'laurel'));
                        $report->record('queried Apollo projection',  $hasRecord($queried, 'Apollo'));
                        $report->record('deleted Apollo record',      !$hasRecord($afterDelete, 'Apollo'));
                    } finally {
                        $server->stop(0.2, 0.1);
                    }
                },
            ));
        },
        [new SurrealBundle()],
    ))($context);
};
