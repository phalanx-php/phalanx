<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Demos\SurrealDb\Support\SurrealDbBinaryLocator;
use Phalanx\Demos\SurrealDb\Support\SurrealDbFreePort;
use Phalanx\Demos\SurrealDb\Support\SurrealDbNamespaceInitializer;
use Phalanx\Demos\SurrealDb\Support\SurrealDbRecordChecker;
use Phalanx\Demos\SurrealDb\Support\SurrealDbServerErrorPrinter;
use Phalanx\Demos\SurrealDb\Support\SurrealDbServerReadiness;
use Phalanx\Demos\SurrealDb\Support\SurrealDbValueChecker;
use Phalanx\Scope\ExecutionScope;
use Phalanx\SurrealDb\SurrealDb;
use Phalanx\SurrealDb\SurrealDbBundle;
use Phalanx\System\StreamingProcess;
use Phalanx\Task\Task;

// SurrealDbBundle reads its config from context at compile-time, so port
// allocation and binary detection have to happen before DemoApp::boot.
return static function (array $context): \Closure {
    $binary = (new SurrealDbBinaryLocator())(new AppContext($context));
    if ($binary === null) {
        $report = new DemoReport('SurrealDb In-Memory RPC');

        return static fn (): int => $report->cannotRun(
            'the `surrealdb` binary was not found on PATH.',
            'install SurrealDB locally, then rerun this command.',
        );
    }

    $port = (new SurrealDbFreePort())();
    $endpoint = "http://127.0.0.1:{$port}";
    $context['surrealdb_namespace'] = 'olympus';
    $context['surrealdb_database']  = 'pantheon';
    $context['surrealdb_endpoint']  = $endpoint;
    $context['surrealdb_username']  = 'root';
    $context['surrealdb_password']  = 'root';

    return (DemoApp::boot(
        'SurrealDb In-Memory RPC',
        static function (DemoApp $app, DemoReport $report) use ($binary, $port, $endpoint): void {
            $report->note(sprintf('Endpoint: %s', $endpoint));
            $report->note('Topic: Apollo, oracle of Delphi, recording his prophecies');

            $app->run(Task::named(
                'demo.surrealdb.in-memory-rpc',
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
                        $surrealdb = $scope->service(SurrealDb::class);

                        if (!(new SurrealDbServerReadiness())($scope, $surrealdb, $server)) {
                            $report->record('server became ready', false);
                            (new SurrealDbServerErrorPrinter())($server);

                            return;
                        }
                        if (!(new SurrealDbNamespaceInitializer())($scope, "http://127.0.0.1:{$port}")) {
                            $report->record('namespace initialized', false);

                            return;
                        }

                        $surrealdb->query('DELETE oracle;');
                        $created     = $surrealdb->create('oracle:apollo', ['name' => 'Apollo', 'domain' => 'prophecy', 'shrine' => 'Delphi']);
                        $selected    = $surrealdb->select('oracle:apollo');
                        $merged      = $surrealdb->merge('oracle:apollo', ['symbol' => 'laurel']);
                        $queried     = $surrealdb->query(
                            'SELECT name, domain, symbol FROM oracle WHERE name = $name;',
                            ['name' => 'Apollo'],
                        );
                        $surrealdb->delete('oracle:apollo');
                        $afterDelete = $surrealdb->select('oracle:apollo');

                        $hasRecord = new SurrealDbRecordChecker();
                        $hasValue  = new SurrealDbValueChecker();

                        $report->record('server health endpoint',     in_array($surrealdb->health(), [200, 204], true));
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
        [new SurrealDbBundle()],
    ))($context);
};
