#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\Opt;
use Phalanx\Boot\AppContext;
use Phalanx\Theatron\Demos\Showcase\Component\AgoraScreen;
use Phalanx\Theatron\Demos\Showcase\Reactor\AgentEventReactor;
use Phalanx\Theatron\Demos\Showcase\Slice\AgentEntry;
use Phalanx\Theatron\Demos\Showcase\Slice\AgentMetricsSlice;
use Phalanx\Theatron\Demos\Showcase\Slice\AgentRosterSlice;
use Phalanx\Theatron\Demos\Showcase\Slice\DispatchFeedSlice;
use Phalanx\Theatron\Demos\Showcase\Worker\AgentWorkerTask;
use Phalanx\Theatron\Reactor\HydraReactor;
use Phalanx\Theatron\Reactor\RestartPolicy;
use Phalanx\Theatron\Store\Store;
use Phalanx\Theatron\TheatronBuilder;

$agents = [
    'researcher' => new AgentEntry(
        id: 'researcher',
        name: 'Thales',
        role: 'researcher',
        provider: 'ollama',
    ),
    'analyst' => new AgentEntry(
        id: 'analyst',
        name: 'Archimedes',
        role: 'analyst',
        provider: 'ollama',
    ),
    'steward' => new AgentEntry(
        id: 'steward',
        name: 'Pericles',
        role: 'steward',
        provider: 'gemini',
    ),
];

$prompts = [
    'researcher' => 'You are Thales, a research analyst. Provide concise analysis on the topic given. Be direct and analytical. Keep responses under 200 words.',
    'analyst' => 'You are Archimedes, a technical analyst. Break down the topic into key components and provide structured observations. Keep responses under 200 words.',
    'steward' => 'You are Pericles, the steward. Synthesize and coordinate the analysis from your team. Provide a unified summary. Keep responses under 200 words.',
];

$userMessage = 'Analyze the architectural principles behind building resilient distributed systems with graceful degradation.';

exit(Archon::command('showcase', static function (CommandContext $ctx) use ($agents, $prompts, $userMessage): int {
    $provider = $ctx->options->get('provider') ?? 'ollama';
    $model = $ctx->options->get('model') ?? 'llama3.2';
    $baseUrl = $ctx->options->get('base-url') ?? 'http://localhost:11434';
    $geminiModel = $ctx->options->get('gemini-model') ?? 'gemini-2.5-flash';
    $geminiApiKey = $ctx->options->get('gemini-key') ?? '';
    $geminiUrl = $ctx->options->get('gemini-url') ?? 'https://generativelanguage.googleapis.com';

    $reactors = [];

    foreach ($agents as $id => $agent) {
        $isGemini = $agent->provider === 'gemini';
        $taskProvider = $isGemini ? 'gemini' : $provider;
        $taskModel = $isGemini ? $geminiModel : $model;
        $taskUrl = $isGemini ? $geminiUrl : $baseUrl;

        $reactors[] = new HydraReactor(
            id: "agent.{$id}",
            taskClass: AgentWorkerTask::class,
            group: 'agents',
            constructorArgs: [
                'agentId' => $id,
                'agentName' => $agent->name,
                'systemPrompt' => $prompts[$id],
                'userMessage' => $userMessage,
                'provider' => $taskProvider,
                'model' => $taskModel,
                'baseUrl' => $taskUrl,
                'apiKey' => $isGemini ? $geminiApiKey : '',
            ],
            restartPolicy: RestartPolicy::never(),
        );
    }

    $app = (new TheatronBuilder(new AppContext()))
        ->root(new AgoraScreen())
        ->store(Store::concurrent(
            'showcase',
            AgentRosterSlice::class,
            DispatchFeedSlice::class,
            AgentMetricsSlice::class,
        ))
        ->initialState(new AgentRosterSlice($agents))
        ->reactors(new AgentEventReactor())
        ->background(...$reactors)
        ->devtools()
        ->build();

    $app->run($ctx);

    return 0;
}, new CommandConfig(options: [
    Opt::value('provider', desc: 'LLM provider: ollama, gemini'),
    Opt::value('model', desc: 'Model name (default: llama3.2)'),
    Opt::value('base-url', desc: 'Provider base URL'),
    Opt::value('gemini-model', desc: 'Gemini model (default: gemini-2.5-flash)'),
    Opt::value('gemini-key', desc: 'Gemini API key'),
    Opt::value('gemini-url', desc: 'Gemini API base URL'),
]))->default('showcase')->run(array_slice($_SERVER['argv'] ?? [], 1)));
