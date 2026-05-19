<?php

/**
 * Streaming provider — Athena v2 activity run.
 *
 * Boots Aegis with AthenaBundle, probes Ollama at localhost:11434 and
 * falls back to a deterministic Fake provider when Ollama is unreachable.
 * Runs one Athena activity with a simple wisdom-and-strategy prompt and
 * streams the resulting TokenDelta cues to stdout.
 *
 * Either path exits 0: real token output when Ollama is up; scripted Cue
 * replay otherwise.
 *
 * Usage:
 *   php demos/athena/01-streaming-providers/demo.php
 *   php -d extension=openswoole demos/athena/01-streaming-providers/demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Athena\Activity\Config as ActivityConfig;
use Phalanx\Athena\Athena;
use Phalanx\Athena\AthenaBundle;
use Phalanx\Athena\Router\SingleProviderRouter;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoProvider;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Demos\Kit\FakeCueScript;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use Phalanx\Scope\TaskScope;

// DemoApp::boot() constructs the Aegis kernel which requires OpenSwoole\Table.
// Guard before boot so a missing extension produces a clean cannotRun message.
if (!extension_loaded('openswoole')) {
    return DemoReport::demo(
        'Athena Streaming Providers',
        static function (DemoReport $report): void {
            $report->cannotRun(
                'openswoole extension required',
                'Run with: php -d extension=openswoole demos/athena/01-streaming-providers/demo.php',
            );
        },
    );
}

// Resolve the provider before boot — the probe is a plain curl call with no
// Aegis dependency. We can build AthenaBundle here and capture it in the boot
// closure.
$fakeScript = FakeCueScript::tokens(
    'Disciplined wisdom is strategy made executable: ' .
    'scope every decision, cancel what drifts, observe what remains.',
    activityId: 'demo-streaming-providers',
    agentId: 'leonidas-advisor',
);

$choice = DemoProvider::ollamaOrFake($fakeScript);
$bundle = new AthenaBundle(new SingleProviderRouter($choice->provider));

return DemoApp::boot(
    'Athena Streaming Providers',
    static function (DemoApp $app, DemoReport $report) use ($choice): void {
        $report->note(sprintf('provider: %s', $choice->description));
        $report->note('topic: disciplined wisdom and strategic clarity as an agent runtime');

        // LeonidusAdvisor — a hoplite-themed agent that summarizes strategic
        // wisdom without tool use, purely through the streaming text channel.
        $agent = new class () implements \Phalanx\Panoply\Agent {
            public string $id { get => 'leonidas-advisor'; }
            public string $name { get => 'Leonidas Advisor'; }
            public string $purpose {
                get => 'In 20 words or fewer, connect disciplined wisdom and strategic clarity to an AI agent runtime.';
            }
            public Output $output { get => Output::text(); }
            public Context $context { get => Context::new(); }
            public Effects $effects { get => Effects::none(); }
            public ProviderNeeds $provider {
                get => ProviderNeeds::new()->prefer(Preference::LocalFirst);
            }
            public Capabilities $capabilities {
                get => Capabilities::of(Capability::Streaming);
            }
            public TransportNeeds $transport {
                get => TransportNeeds::new()->streaming();
            }
        };

        $config = new ActivityConfig(
            id: 'demo-streaming-providers',
            context: Context::new(),
            maxInvocations: 1,
        );

        $tokenCount = 0;
        $fullText = '';

        // Run inside the Aegis task so Athena::run() can resolve its services.
        $result = $app->run(
            static function (TaskScope $scope) use ($agent, $config, &$tokenCount, &$fullText): \Phalanx\Athena\Activity\Result {
                $result = Athena::run($scope, $agent, $config);

                foreach ($result->stream->toArray() as $cue) {
                    if ($cue instanceof TokenDelta) {
                        $fullText .= $cue->text;
                        $tokenCount++;
                    }
                }

                return $result;
            },
        );

        if ($fullText !== '') {
            $report->note(sprintf('output: %s', trim($fullText)));
        }

        $report->record(
            'activity completed',
            $result->state === \Phalanx\Athena\Activity\State::Completed,
        );

        $report->record(
            'at least one TokenDelta emitted',
            $tokenCount >= 1,
        );

        $report->record(
            'scope cleanup left no orphaned tasks',
            $app->ledger()->liveTaskCount() === 0,
        );
    },
    [$bundle],
);
