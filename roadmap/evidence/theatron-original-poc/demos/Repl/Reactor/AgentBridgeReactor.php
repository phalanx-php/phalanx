<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Reactor;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\TaskHandle;
use Phalanx\Theatron\Demos\Repl\ConversationLog;
use Phalanx\Theatron\Demos\Repl\Event\AgentErrorEvent;
use Phalanx\Theatron\Demos\Repl\Event\LlmRequestCompleteEvent;
use Phalanx\Theatron\Demos\Repl\Event\LlmRequestStartEvent;
use Phalanx\Theatron\Demos\Repl\Event\ThinkingTokenEvent;
use Phalanx\Theatron\Demos\Repl\Event\TokenReceivedEvent;
use Phalanx\Theatron\Demos\Repl\Event\ToolCallEvent;
use Phalanx\Theatron\Demos\Repl\Event\TurnCompleteEvent;
use Phalanx\Theatron\Demos\Repl\ReplAgent;
use Phalanx\Theatron\Reactor\BackgroundReactor;
use Phalanx\Theatron\Reactor\ReactorExclusivity;
use Phalanx\Theatron\Reactor\ReactorState;
use Phalanx\Theatron\Stream\TheatronStream;
use Throwable;

final class AgentBridgeReactor implements BackgroundReactor
{
    private const array MOCK_THINKING = [
        "The questioner seeks counsel on military matters. I should draw from the campaigns of Marathon and Thermopylae to frame my response. The defensive posture is often undervalued by those eager for glory, but terrain advantage has decided more battles than numerical superiority. Let me consider the strategic implications carefully before advising.",
        "This requires careful deliberation. The logistics of the campaign must be weighed against the political realities in the Assembly. Cleon's faction will oppose any cautious approach, but the treasury reports from the Delian League suggest we cannot afford extended operations. I should recommend a phased strategy.",
        "An interesting tactical question. Epaminondas proved at Leuctra that concentration of force at a decisive point can overcome superior numbers. But the Athenian phalanx differs from the Theban formation -- our strength lies at sea, not on the plains. I must balance ambition with our actual capabilities.",
        "The diplomatic dimension cannot be ignored. Every military decision in the Hellenic world carries political weight. Sparta watches, Persia waits. My advice must account for the broader balance of power, not merely the immediate tactical situation.",
    ];

    private const array MOCK_RESPONSES = [
        'Greetings, strategos. I am Pericles, advisor to the polis. The eastern flank presents both opportunity and peril -- the narrow pass at Thermopylae teaches us that terrain dictates formation. A defensive phalanx with overlapping aspides would hold against superior numbers. What aspect of the campaign concerns you most?',
        'The intelligence reports suggest the enemy musters along the northern ridge. History favors the patient commander. I recommend we fortify the supply lines first -- an army marches on its stomach, as the Spartans learned at Plataea. Shall I draft a detailed logistics assessment?',
        'A bold question. The hoplite formation excels in close quarters, but our sarissa-armed units provide reach advantage on open ground. I would deploy the heavy infantry at the center with light peltasts screening the flanks. The cavalry reserve holds for the decisive moment. This mirrors what Epaminondas achieved at Leuctra.',
        'Consider this: the agora teaches us that consensus precedes action. Before committing forces, we must secure the support of the allied poleis. Diplomatic overtures to Corinth and Thebes would strengthen our position. Military strategy without political foundation is a spear without a shaft.',
    ];

    public string $id {
        get => 'repl.agent-bridge';
    }

    public ?string $group {
        get => null;
    }

    public ReactorState $state {
        get => $this->currentState;
    }

    public ReactorExclusivity $exclusivity {
        get => ReactorExclusivity::Exclusive;
    }

    private ReplAgent $agent;
    private int $mockIndex = 0;
    private int $requestCounter = 0;
    private ?ExecutionScope $scope = null;
    private ?TaskHandle $activeRun = null;
    private ?TheatronStream $stream = null;
    private ReactorState $currentState = ReactorState::Idle;

    public function __construct(
        private(set) ?HttpClient $httpClient = null,
        private(set) ?string $ollamaModel = null,
        private(set) ?ConversationLog $log = null,
    ) {
        $this->agent = new ReplAgent();
    }

    public function start(ExecutionScope $scope, TheatronStream $stream): void
    {
        $this->scope = $scope;
        $this->stream = $stream;
        $this->currentState = ReactorState::Running;
    }

    public function cancel(): void
    {
        $this->activeRun?->cancel();
        $this->activeRun = null;
        $this->currentState = ReactorState::Cancelled;
    }

    public function submit(string $message): void
    {
        if ($this->scope === null || $this->stream === null) {
            return;
        }

        $this->activeRun?->cancel();
        $this->activeRun = null;

        if ($this->httpClient !== null && $this->ollamaModel !== null) {
            $this->submitOllama($message);
        } else {
            $this->submitMock($message);
        }
    }

    private function submitOllama(string $message): void
    {
        $scope = $this->scope;
        $stream = $this->stream;
        $httpClient = $this->httpClient;
        $model = $this->ollamaModel;
        $log = $this->log;
        $requestId = 'req-' . $this->requestCounter++;
        $agent = $this->agent;

        $messages = [['role' => 'system', 'content' => $agent->instructions]];

        if ($log !== null) {
            foreach ($log->lastN(20) as $past) {
                $messages[] = ['role' => 'user', 'content' => $past->userMessage];
                $messages[] = ['role' => 'assistant', 'content' => $past->assistantResponse];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $schemas = $agent->toolSchemas();

        $this->activeRun = $scope->go(static function (ExecutionScope $childScope) use (
            $stream,
            $httpClient,
            $model,
            $messages,
            $schemas,
            $requestId,
            $agent,
        ): void {
            $startTime = hrtime(true);
            $maxSteps = 5;
            $lastStepRequestId = $requestId;
            $currentMessages = $messages;
            $activeSchemas = $schemas;

            try {
                for ($step = 0; $step < $maxSteps; $step++) {
                    $childScope->throwIfCancelled();

                    $stepRequestId = $step === 0 ? $requestId : $requestId . '-' . $step;
                    $lastStepRequestId = $stepRequestId;

                    $body = ['model' => $model, 'messages' => $currentMessages, 'stream' => true];

                    if ($activeSchemas !== []) {
                        $body['tools'] = $activeSchemas;
                    }

                    $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);
                    $stepStart = hrtime(true);

                    $stream->emit(new LlmRequestStartEvent(
                        requestId: $stepRequestId,
                        method: 'POST',
                        path: '/api/chat',
                        requestBody: $jsonBody,
                        startTime: $stepStart / 1e9,
                    ));

                    $httpStream = $httpClient->stream(
                        $childScope,
                        new HttpRequest(
                            method: 'POST',
                            url: 'http://localhost:11434/api/chat',
                            headers: ['Content-Type' => ['application/json']],
                            body: $jsonBody,
                            readTimeout: 120.0,
                        ),
                    );

                    $fullText = '';
                    $toolCalls = [];
                    $inThinking = false;

                    try {
                        foreach ($httpStream->lines($childScope) as $line) {
                            if ($line === '') {
                                continue;
                            }

                            $chunk = json_decode($line, true);

                            if ($chunk === null) {
                                continue;
                            }

                            if (isset($chunk['error'])) {
                                throw new \RuntimeException($chunk['error']);
                            }

                            $content = $chunk['message']['content'] ?? '';
                            $done = $chunk['done'] ?? false;

                            if ($done && isset($chunk['message']['tool_calls'])) {
                                $toolCalls = $chunk['message']['tool_calls'];
                            }

                            if ($content !== '') {
                                [$content, $inThinking] = self::emitTokens(
                                    $stream,
                                    $content,
                                    $inThinking,
                                );
                                $fullText .= $content;
                            }
                        }
                    } finally {
                        $httpStream->close();
                    }

                    $elapsedMs = (hrtime(true) - $stepStart) / 1e6;
                    $stream->emit(new LlmRequestCompleteEvent(
                        requestId: $stepRequestId,
                        status: 200,
                        elapsedMs: $elapsedMs,
                        tokenCount: mb_strlen($fullText),
                        responseBody: $fullText,
                    ));

                    if ($toolCalls === []) {
                        if (!$childScope->isCancelled) {
                            if ($activeSchemas !== []) {
                                $toolNames = array_map(
                                    static fn(array $s): string => $s['function']['name'] ?? '',
                                    $activeSchemas,
                                );
                                $mentionedTool = array_find(
                                    $toolNames,
                                    static fn(string $n): bool => $n !== '' && str_contains($fullText, $n),
                                );

                                if ($mentionedTool !== null) {
                                    $stream->emit(new AgentErrorEvent(
                                        message: "Model described tool '{$mentionedTool}' in text but did not produce a structured tool call. The model may not support tool calling.",
                                    ));
                                }
                            }

                            $stream->emit(new TurnCompleteEvent());
                        }

                        return;
                    }

                    $assistantMessage = ['role' => 'assistant', 'content' => $fullText];

                    if ($toolCalls !== []) {
                        $assistantMessage['tool_calls'] = $toolCalls;
                    }

                    $currentMessages[] = $assistantMessage;

                    foreach ($toolCalls as $call) {
                        $childScope->throwIfCancelled();

                        $toolName = $call['function']['name'] ?? 'unknown';
                        $toolArgs = $call['function']['arguments'] ?? [];

                        if (is_string($toolArgs)) {
                            $toolArgs = json_decode($toolArgs, true) ?? [];
                        }

                        $argsSummary = AgentEventBridge::summarizeArguments($toolArgs);

                        $stream->emit(new ToolCallEvent(
                            toolName: $toolName,
                            argumentsSummary: $argsSummary,
                            started: true,
                        ));

                        try {
                            $result = $agent->executeTool($toolName, $toolArgs);
                        } catch (Cancelled $e) {
                            $stream->emit(new ToolCallEvent(
                                toolName: $toolName,
                                argumentsSummary: $argsSummary,
                                started: false,
                                result: 'cancelled',
                            ));

                            throw $e;
                        }

                        if ($result === null) {
                            $serialized = json_encode(['error' => "Unknown tool: {$toolName}"], JSON_THROW_ON_ERROR);
                        } else {
                            $serialized = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                        }

                        $stream->emit(new ToolCallEvent(
                            toolName: $toolName,
                            argumentsSummary: $argsSummary,
                            started: false,
                            result: 'ok',
                            resultContent: $serialized,
                            resultType: AgentEventBridge::detectResultType($serialized),
                        ));

                        $currentMessages[] = ['role' => 'tool', 'content' => $serialized];
                    }
                }

                if (!$childScope->isCancelled) {
                    $stream->emit(new AgentErrorEvent(message: 'Tool execution exceeded maximum steps'));
                    $stream->emit(new TurnCompleteEvent());
                }
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $elapsedMs = (hrtime(true) - $startTime) / 1e6;
                $stream->emit(new LlmRequestCompleteEvent(
                    requestId: $lastStepRequestId,
                    status: 500,
                    elapsedMs: $elapsedMs,
                    tokenCount: 0,
                    responseBody: $e->getMessage(),
                ));

                if (!$childScope->isCancelled) {
                    $stream->emit(new AgentErrorEvent(message: $e->getMessage(), cause: $e));
                    $stream->emit(new TurnCompleteEvent());
                }
            }
        }, 'repl.ollama-turn');
    }

    /**
     * @return array{string, bool}
     */
    private static function emitTokens(TheatronStream $stream, string $content, bool $inThinking): array
    {
        $visible = '';

        if (!$inThinking && str_contains($content, '<think>')) {
            $pos = strpos($content, '<think>');
            $before = substr($content, 0, $pos);

            if ($before !== '') {
                $stream->emit(new TokenReceivedEvent(delta: $before));
                $visible .= $before;
            }

            $content = substr($content, $pos + 7);
            $inThinking = true;
        }

        if ($inThinking && str_contains($content, '</think>')) {
            $pos = strpos($content, '</think>');
            $before = substr($content, 0, $pos);

            if ($before !== '') {
                $stream->emit(new ThinkingTokenEvent(delta: $before));
            }

            $after = ltrim(substr($content, $pos + 8));

            if ($after !== '') {
                $stream->emit(new TokenReceivedEvent(delta: $after));
                $visible .= $after;
            }

            return [$visible, false];
        }

        if ($content !== '') {
            if ($inThinking) {
                $stream->emit(new ThinkingTokenEvent(delta: $content));
            } else {
                $stream->emit(new TokenReceivedEvent(delta: $content));
                $visible .= $content;
            }
        }

        return [$visible, $inThinking];
    }

    private function submitMock(string $message): void
    {
        $scope = $this->scope;
        $stream = $this->stream;
        $index = $this->mockIndex % count(self::MOCK_RESPONSES);
        $response = self::MOCK_RESPONSES[$index];
        $thinking = self::MOCK_THINKING[$index % count(self::MOCK_THINKING)];
        $this->mockIndex++;
        $requestId = 'req-' . $this->requestCounter++;

        $this->activeRun = $scope->go(static function (ExecutionScope $childScope) use ($stream, $response, $thinking, $requestId): void {
            $startTime = hrtime(true);

            $stream->emit(new LlmRequestStartEvent(
                requestId: $requestId,
                method: 'MOCK',
                path: '/mock/chat',
                requestBody: '{"mock": true}',
                startTime: $startTime / 1e9,
            ));

            $thinkingWords = explode(' ', $thinking);

            foreach ($thinkingWords as $i => $word) {
                $childScope->delay(0.02);
                $prefix = $i === 0 ? '' : ' ';
                $stream->emit(new ThinkingTokenEvent(delta: $prefix . $word));
            }

            $childScope->delay(0.1);

            $words = explode(' ', $response);

            foreach ($words as $i => $word) {
                $childScope->delay(0.03);
                $prefix = $i === 0 ? '' : ' ';
                $stream->emit(new TokenReceivedEvent(delta: $prefix . $word));
            }

            $elapsedMs = (hrtime(true) - $startTime) / 1e6;
            $stream->emit(new LlmRequestCompleteEvent(
                requestId: $requestId,
                status: 200,
                elapsedMs: $elapsedMs,
                tokenCount: count($words),
                responseBody: $response,
            ));

            if (!$childScope->isCancelled) {
                $stream->emit(new TurnCompleteEvent());
            }
        }, 'repl.mock-turn');
    }
}
