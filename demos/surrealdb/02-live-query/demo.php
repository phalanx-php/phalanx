<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Scope\ExecutionScope;
use Phalanx\System\StreamingProcess;
use Phalanx\Task\Task;

return static function (array $context): \Closure {
    $binary = (new \Phalanx\Demos\SurrealDb\Support\BinaryLocator())(new AppContext($context));
    if ($binary === null) {
        $report = new DemoReport('SurrealDb In-Memory Live Query');

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

                        $checkNotification = new \Phalanx\Demos\SurrealDb\Support\LiveNotificationChecker(new \Phalanx\Demos\SurrealDb\Support\ValueChecker());

                        $report->record('opened Zeus live query', $events->id() !== '');
                        $report->record(
                            'observed Zeus CREATE',
                            $checkNotification($created, \Phalanx\SurrealDb\Live\Action::Create, 'storm-rising'),
                        );
                        $report->record(
                            'observed Zeus UPDATE',
                            $checkNotification($updated, \Phalanx\SurrealDb\Live\Action::Update, 'sky-cleared'),
                        );
                        $report->record(
                            'observed Zeus DELETE',
                            $deleted instanceof \Phalanx\SurrealDb\Live\Notification && $deleted->action === \Phalanx\SurrealDb\Live\Action::Delete,
                        );
                        $report->record('killed live query cleanly', !$events->isOpen);
                    } finally {
                        $server->stop(0.2, 0.1);
                    }
                },
            ));
        },
        [new \Phalanx\SurrealDb\Bundle()],
    ))($context);
};
