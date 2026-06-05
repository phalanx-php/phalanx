<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Scope\ExecutionScope;
use Phalanx\System\StreamingProcess;
use Phalanx\Task\Task;

// Bundle reads its config from context at compile-time, so port
// allocation and binary detection have to happen before DemoApp::boot.
return static function (array $context): \Closure {
    $binary = (new \Phalanx\Demos\SurrealDb\Support\BinaryLocator())(new AppContext($context));
    if ($binary === null) {
        $report = new DemoReport('SurrealDb In-Memory RPC');

        return static fn (): int => $report->cannotRun(
            'the `surrealdb` binary was not found on PATH.',
            'install SurrealDB locally, then rerun this command.',
        );
    }

    $port = (new \Phalanx\Demos\SurrealDb\Support\FreePort())();
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
                        $surrealdb = $scope->service(\Phalanx\SurrealDb\Client::class);

                        if (!(new \Phalanx\Demos\SurrealDb\Support\ServerReadiness())($scope, $surrealdb, $server)) {
                            $report->record('server became ready', false);
                            (new \Phalanx\Demos\SurrealDb\Support\ServerErrorPrinter())($server);

                            return;
                        }
                        if (!(new \Phalanx\Demos\SurrealDb\Support\NamespaceInitializer())($scope, "http://127.0.0.1:{$port}")) {
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

                        $hasRecord = new \Phalanx\Demos\SurrealDb\Support\RecordChecker();
                        $hasValue  = new \Phalanx\Demos\SurrealDb\Support\ValueChecker();

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
        [new \Phalanx\SurrealDb\Bundle()],
    ))($context);
};
