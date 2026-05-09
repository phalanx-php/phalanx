<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Demos\Surreal\Support\SurrealBinaryLocator;
use Phalanx\Demos\Surreal\Support\SurrealFreePort;
use Phalanx\Demos\Surreal\Support\SurrealLiveNotificationChecker;
use Phalanx\Demos\Surreal\Support\SurrealNamespaceInitializer;
use Phalanx\Demos\Surreal\Support\SurrealServerErrorPrinter;
use Phalanx\Demos\Surreal\Support\SurrealServerReadiness;
use Phalanx\Demos\Surreal\Support\SurrealValueChecker;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealBundle;
use Phalanx\Surreal\SurrealLiveAction;
use Phalanx\Surreal\SurrealLiveNotification;
use Phalanx\System\StreamingProcess;
use Phalanx\Task\Task;

return static function (array $context): \Closure {
    $binary = (new SurrealBinaryLocator())(new AppContext($context));
    if ($binary === null) {
        $report = new DemoReport('Surreal In-Memory Live Query');

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
        'Surreal In-Memory Live Query',
        static function (DemoApp $app, DemoReport $report) use ($binary, $port, $endpoint): void {
            $report->note(sprintf('Endpoint: %s', $endpoint));
            $report->note('Topic: Zeus hurling thunderbolts as live signals across Olympus');

            $app->run(Task::named(
                'demo.surreal.live-query',
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

                        $surreal->query('DEFINE TABLE IF NOT EXISTS thunderbolt SCHEMALESS; DELETE thunderbolt;');
                        $events = $surreal->live('thunderbolt');

                        $surreal->create('thunderbolt:zeus_strikes', [
                            'name'     => 'Zeus',
                            'signal'   => 'storm-rising',
                            'priority' => 1,
                        ]);
                        $created = $events->next(3.0);

                        $surreal->merge('thunderbolt:zeus_strikes', ['signal' => 'sky-cleared']);
                        $updated = $events->next(3.0);

                        $surreal->delete('thunderbolt:zeus_strikes');
                        $deleted = $events->next(3.0);

                        $events->kill();
                        $surreal->close();

                        $checkNotification = new SurrealLiveNotificationChecker(new SurrealValueChecker());

                        $report->record('opened Zeus live query', $events->id() !== '');
                        $report->record(
                            'observed Zeus CREATE',
                            $checkNotification($created, SurrealLiveAction::Create, 'storm-rising'),
                        );
                        $report->record(
                            'observed Zeus UPDATE',
                            $checkNotification($updated, SurrealLiveAction::Update, 'sky-cleared'),
                        );
                        $report->record(
                            'observed Zeus DELETE',
                            $deleted instanceof SurrealLiveNotification && $deleted->action === SurrealLiveAction::Delete,
                        );
                        $report->record('killed live query cleanly', !$events->isOpen);
                    } finally {
                        $server->stop(0.2, 0.1);
                    }
                },
            ));
        },
        [new SurrealBundle()],
    ))($context);
};
