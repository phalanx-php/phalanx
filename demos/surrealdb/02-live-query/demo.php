<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Demos\SurrealDb\Support\SurrealDbBinaryLocator;
use Phalanx\Demos\SurrealDb\Support\SurrealDbFreePort;
use Phalanx\Demos\SurrealDb\Support\SurrealDbLiveNotificationChecker;
use Phalanx\Demos\SurrealDb\Support\SurrealDbNamespaceInitializer;
use Phalanx\Demos\SurrealDb\Support\SurrealDbServerErrorPrinter;
use Phalanx\Demos\SurrealDb\Support\SurrealDbServerReadiness;
use Phalanx\Demos\SurrealDb\Support\SurrealDbValueChecker;
use Phalanx\Scope\ExecutionScope;
use Phalanx\SurrealDb\SurrealDb;
use Phalanx\SurrealDb\SurrealDbBundle;
use Phalanx\SurrealDb\SurrealDbLiveAction;
use Phalanx\SurrealDb\SurrealDbLiveNotification;
use Phalanx\System\StreamingProcess;
use Phalanx\Task\Task;

return static function (array $context): \Closure {
    $binary = (new SurrealDbBinaryLocator())(new AppContext($context));
    if ($binary === null) {
        $report = new DemoReport('SurrealDb In-Memory Live Query');

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
        'SurrealDb In-Memory Live Query',
        static function (DemoApp $app, DemoReport $report) use ($binary, $port, $endpoint): void {
            $report->note(sprintf('Endpoint: %s', $endpoint));
            $report->note('Topic: Zeus hurling thunderbolts as live signals across Olympus');

            $app->run(Task::named(
                'demo.surrealdb.live-query',
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

                        $surrealdb->query('DEFINE TABLE IF NOT EXISTS thunderbolt SCHEMALESS; DELETE thunderbolt;');
                        $events = $surrealdb->live('thunderbolt');

                        $surrealdb->create('thunderbolt:zeus_strikes', [
                            'name'     => 'Zeus',
                            'signal'   => 'storm-rising',
                            'priority' => 1,
                        ]);
                        $created = $events->next(3.0);

                        $surrealdb->merge('thunderbolt:zeus_strikes', ['signal' => 'sky-cleared']);
                        $updated = $events->next(3.0);

                        $surrealdb->delete('thunderbolt:zeus_strikes');
                        $deleted = $events->next(3.0);

                        $events->kill();
                        $surrealdb->close();

                        $checkNotification = new SurrealDbLiveNotificationChecker(new SurrealDbValueChecker());

                        $report->record('opened Zeus live query', $events->id() !== '');
                        $report->record(
                            'observed Zeus CREATE',
                            $checkNotification($created, SurrealDbLiveAction::Create, 'storm-rising'),
                        );
                        $report->record(
                            'observed Zeus UPDATE',
                            $checkNotification($updated, SurrealDbLiveAction::Update, 'sky-cleared'),
                        );
                        $report->record(
                            'observed Zeus DELETE',
                            $deleted instanceof SurrealDbLiveNotification && $deleted->action === SurrealDbLiveAction::Delete,
                        );
                        $report->record('killed live query cleanly', !$events->isOpen);
                    } finally {
                        $server->stop(0.2, 0.1);
                    }
                },
            ));
        },
        [new SurrealDbBundle()],
    ))($context);
};
