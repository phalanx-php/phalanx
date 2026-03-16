<?php

declare(strict_types=1);

namespace Convoy\Integration\Ai;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;

use function React\Async\await;

final class GptClient
{
    private Browser $browser;

    public function __construct(private AiConfig $config)
    {
        $this->browser = new Browser()
            ->withTimeout(30.0)
            ->withFollowRedirects(false);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    public function complete(array $messages, ?string $model = null, ?int $maxTokens = null): string
    {
        $body = [
            'model' => $model ?? $this->config->openaiModel,
            'max_tokens' => $maxTokens ?? $this->config->maxTokens,
            'messages' => $messages,
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer {$this->config->openaiApiKey}",
        ];

        /** @var ResponseInterface $response */
        $response = await($this->browser->post(
            $this->config->openaiEndpoint,
            $headers,
            json_encode($body, JSON_THROW_ON_ERROR),
        ));

        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $data['choices'][0]['message']['content'] ?? '';
    }
}
