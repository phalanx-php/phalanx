<?php

declare(strict_types=1);

use AegisSwoole\Application;
use AegisSwoole\Llm\LlmConfig;
use AegisSwoole\Tests\Fixtures\HttpTestServerHandle;
use AegisSwoole\Tests\Fixtures\TestBundle;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Scenarios\AgentScenarios;
use AegisSwoole\Tests\Scenarios\AnyScenarios;
use AegisSwoole\Tests\Scenarios\ApplicationScenarios;
use AegisSwoole\Tests\Scenarios\CancellationExtraScenarios;
use AegisSwoole\Tests\Scenarios\CancellationScenarios;
use AegisSwoole\Tests\Scenarios\ConcurrentScenarios;
use AegisSwoole\Tests\Scenarios\DeferScenarios;
use AegisSwoole\Tests\Scenarios\DelayScenarios;
use AegisSwoole\Tests\Scenarios\HttpScenarios;
use AegisSwoole\Tests\Scenarios\MapScenarios;
use AegisSwoole\Tests\Scenarios\MiddlewareScenarios;
use AegisSwoole\Tests\Scenarios\PostgresScenarios;
use AegisSwoole\Tests\Scenarios\RaceScenarios;
use AegisSwoole\Tests\Scenarios\RetryScenarios;
use AegisSwoole\Tests\Scenarios\ScopeScenarios;
use AegisSwoole\Tests\Scenarios\SeriesScenarios;
use AegisSwoole\Tests\Scenarios\ServiceMiddlewareScenarios;
use AegisSwoole\Tests\Scenarios\ServiceScenarios;
use AegisSwoole\Tests\Scenarios\SettleScenarios;
use AegisSwoole\Tests\Scenarios\SingleflightScenarios;
use AegisSwoole\Tests\Scenarios\TaskScenarios;
use AegisSwoole\Tests\Scenarios\TimeoutScenarios;
use AegisSwoole\Tests\Scenarios\WaterfallScenarios;
use AegisSwoole\Tests\Scenarios\WorkerScenarios;

require __DIR__ . "/../vendor/autoload.php";

// Substrate sanity check: ext-openswoole must be the --with-postgres build.
// Loaded automatically via /opt/homebrew/etc/php/8.4/conf.d/30-openswoole.ini,
// which points at ~/.openswoole-26-pg/openswoole.so. If this is missing, the
// install has drifted — fail fast rather than deadlock on the pool later.
if (!class_exists(\OpenSwoole\Coroutine\PostgreSQL::class)) {
    fwrite(STDERR, "ERROR: OpenSwoole\\Coroutine\\PostgreSQL not present in the loaded ext-openswoole.\n");
    fwrite(STDERR, "       Check /opt/homebrew/etc/php/8.4/conf.d/30-openswoole.ini and the .so it points to.\n");
    exit(2);
}

\OpenSwoole\Coroutine::set(["hook_flags" => SWOOLE_HOOK_ALL]);

$exit = 0;

$httpServer = new HttpTestServerHandle();
$httpServer->start();

// LLM scenarios target a local Ollama daemon (default 127.0.0.1:11434).
// Probe with a short HTTP HEAD/GET so we skip the agent battery cleanly when
// Ollama isn't running.
$ollamaUp = @file_get_contents('http://127.0.0.1:11434/api/tags', false, stream_context_create([
    'http' => ['timeout' => 1, 'method' => 'GET', 'ignore_errors' => true],
])) !== false;

$context = [];

\OpenSwoole\Coroutine::run(static function () use (&$exit, $httpServer, $context, $ollamaUp): void {
    $app = Application::starting($context)->providers(new TestBundle())->compile()->startup();

    $h = new Harness();

    new ServiceScenarios($app)->register($h);
    new ServiceMiddlewareScenarios()->register($h);
    new ScopeScenarios($app)->register($h);
    new CancellationScenarios()->register($h);
    new CancellationExtraScenarios()->register($h);
    new ConcurrentScenarios()->register($h);
    new RaceScenarios()->register($h);
    new AnyScenarios()->register($h);
    new MapScenarios()->register($h);
    new SeriesScenarios()->register($h);
    new WaterfallScenarios()->register($h);
    new SettleScenarios()->register($h);
    new TimeoutScenarios()->register($h);
    new RetryScenarios()->register($h);
    new DelayScenarios()->register($h);
    new DeferScenarios($app)->register($h);
    new SingleflightScenarios()->register($h);
    new TaskScenarios()->register($h);
    new MiddlewareScenarios()->register($h);
    new WorkerScenarios()->register($h);
    new ApplicationScenarios()->register($h);
    new HttpScenarios($httpServer)->register($h);
    new PostgresScenarios()->register($h);

    if ($ollamaUp) {
        new AgentScenarios(new LlmConfig())->register($h);
    } else {
        fwrite(STDERR, "[skip] AgentScenarios: Ollama not reachable at 127.0.0.1:11434 (run `ollama serve` to enable)\n");
    }

    $ok = $h->run(static fn() => $app->createScope());

    $app->shutdown();
    $exit = $ok ? 0 : 1;
});

$httpServer->stop();

exit($exit);
