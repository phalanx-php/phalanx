<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

use Phalanx\Athena\Message\Conversation;

final readonly class GenerateRequest
{
    /**
     * @param list<array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     * @param list<string>|null $stopSequences
     */
    public function __construct(
        public Conversation $conversation,
        public array $tools = [],
        public ?string $outputSchema = null,
        public ?string $model = null,
        public int $maxTokens = 4096,
        public ?float $temperature = null,
        public ?array $stopSequences = null,
    ) {}

    /** @param list<array{name: string, description: string, input_schema: array<string, mixed>}> $tools */
    public static function from(Conversation $conversation, array $tools = [], ?string $outputSchema = null): self
    {
        return new self(
            conversation: $conversation,
            tools: $tools,
            outputSchema: $outputSchema,
        );
    }

    public function withModel(string $model): self
    {
        return new self(
            $this->conversation,
            $this->tools,
            $this->outputSchema,
            $model,
            $this->maxTokens,
            $this->temperature,
            $this->stopSequences,
        );
    }

    public function withMaxTokens(int $maxTokens): self
    {
        return new self(
            $this->conversation,
            $this->tools,
            $this->outputSchema,
            $this->model,
            $maxTokens,
            $this->temperature,
            $this->stopSequences,
        );
    }
}
