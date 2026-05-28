<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Showcase\Worker;

use Phalanx\Hydra\Runtime\WorkerScope;
use Phalanx\Theatron\Demos\Showcase\Event\AgentStatusEvent;
use Phalanx\Theatron\Demos\Showcase\Event\AgentTokenEvent;
use Phalanx\Worker\WorkerTask;

final class AgentWorkerTask implements WorkerTask
{
    public string $traceName {
        get => "agent.{$this->agentId}";
    }

    public function __construct(
        private string $agentId,
        private string $agentName,
        private string $systemPrompt,
        private string $userMessage,
        private string $provider = 'ollama',
        private string $model = 'llama3.2',
        private string $baseUrl = 'http://localhost:11434',
        private string $apiKey = '',
    ) {
    }

    public function __invoke(WorkerScope $scope): mixed
    {
        $scope->streamEmit(
            AgentStatusEvent::class,
            ['agentId' => $this->agentId, 'status' => 'thinking', 'totalTokens' => 0],
        );

        try {
            $totalTokens = match ($this->provider) {
                'ollama' => self::runOllama($scope, $this->agentId, $this->model, $this->baseUrl, $this->systemPrompt, $this->userMessage),
                'gemini' => self::runGemini($scope, $this->agentId, $this->model, $this->baseUrl, $this->apiKey, $this->systemPrompt, $this->userMessage),
                default => self::runOllama($scope, $this->agentId, $this->model, $this->baseUrl, $this->systemPrompt, $this->userMessage),
            };
        } catch (\Throwable $e) {
            $scope->streamEmit(
                AgentStatusEvent::class,
                ['agentId' => $this->agentId, 'status' => 'error', 'totalTokens' => 0],
            );

            throw $e;
        }

        $scope->streamEmit(
            AgentStatusEvent::class,
            ['agentId' => $this->agentId, 'status' => 'complete', 'totalTokens' => $totalTokens],
        );

        return ['agentId' => $this->agentId, 'tokens' => $totalTokens];
    }

    private static function runOllama(
        WorkerScope $scope,
        string $agentId,
        string $model,
        string $baseUrl,
        string $systemPrompt,
        string $userMessage,
    ): int {
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'stream' => true,
            'options' => ['num_predict' => 256],
        ], JSON_THROW_ON_ERROR);

        $tokens = 0;
        $buffer = '';

        $ch = curl_init("{$baseUrl}/api/chat");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use ($scope, $agentId, &$tokens, &$buffer): int {
                $buffer .= $data;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    if ($line === '') {
                        continue;
                    }

                    try {
                        $frame = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        continue;
                    }

                    $content = $frame['message']['content'] ?? '';

                    if ($content !== '') {
                        $tokens++;
                        $scope->streamEmit(AgentTokenEvent::class, [
                            'agentId' => $agentId,
                            'delta' => $content,
                        ]);
                    }
                }

                return strlen($data);
            },
            CURLOPT_TIMEOUT => 120,
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $errno !== 0) {
            throw new \RuntimeException("Ollama request failed [{$errno}]: {$error}");
        }

        return $tokens;
    }

    private static function runGemini(
        WorkerScope $scope,
        string $agentId,
        string $model,
        string $baseUrl,
        string $apiKey,
        string $systemPrompt,
        string $userMessage,
    ): int {
        if ($apiKey === '') {
            $scope->streamEmit(AgentTokenEvent::class, [
                'agentId' => $agentId,
                'delta' => '[Gemini API key not configured]',
            ]);

            return 0;
        }

        $payload = json_encode([
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => "{$systemPrompt}\n\n{$userMessage}"]]],
            ],
            'generationConfig' => ['maxOutputTokens' => 256],
        ], JSON_THROW_ON_ERROR);

        $url = "{$baseUrl}/v1beta/models/{$model}:streamGenerateContent?alt=sse&key={$apiKey}";

        $tokens = 0;
        $buffer = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use ($scope, $agentId, &$tokens, &$buffer): int {
                $buffer .= $data;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    if (!str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $json = substr($line, 6);

                    try {
                        $frame = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        continue;
                    }

                    $text = $frame['candidates'][0]['content']['parts'][0]['text'] ?? '';

                    if ($text !== '') {
                        $tokens++;
                        $scope->streamEmit(AgentTokenEvent::class, [
                            'agentId' => $agentId,
                            'delta' => $text,
                        ]);
                    }
                }

                return strlen($data);
            },
            CURLOPT_TIMEOUT => 120,
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $errno !== 0) {
            throw new \RuntimeException("Gemini request failed [{$errno}]: {$error}");
        }

        return $tokens;
    }
}
