<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl;

use Generator;
use Phalanx\Theatron\Demos\Repl\Slice\Exchange;
use Phalanx\Theatron\Demos\Repl\Slice\ToolCallSummary;

class ConversationLog
{
    private int $lineCount = 0;

    public function __construct(
        private(set) string $path,
    ) {
    }

    public function append(Exchange $exchange): int
    {
        $offset = $this->lineCount;
        $json = json_encode(self::serialize($exchange), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->path, $json . "\n", FILE_APPEND | LOCK_EX);
        $this->lineCount++;

        return $offset;
    }

    public function readAt(int $offset): ?Exchange
    {
        $handle = @fopen($this->path, 'r');

        if ($handle === false) {
            return null;
        }

        try {
            $current = 0;

            while (($line = fgets($handle)) !== false) {
                if ($current === $offset) {
                    $data = json_decode(rtrim($line, "\n"), true, flags: JSON_THROW_ON_ERROR);

                    return self::deserialize($data);
                }

                $current++;
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    public function readById(string $id): ?Exchange
    {
        $handle = @fopen($this->path, 'r');

        if ($handle === false) {
            return null;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = rtrim($line, "\n");

                if ($trimmed === '') {
                    continue;
                }

                $data = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);

                if (($data['id'] ?? null) === $id) {
                    return self::deserialize($data);
                }
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<Exchange> */
    public function lastN(int $n): array
    {
        $handle = @fopen($this->path, 'r');

        if ($handle === false) {
            return [];
        }

        try {
            $buffer = [];

            while (($line = fgets($handle)) !== false) {
                $trimmed = rtrim($line, "\n");

                if ($trimmed === '') {
                    continue;
                }

                $buffer[] = $trimmed;

                if (count($buffer) > $n) {
                    array_shift($buffer);
                }
            }

            return array_map(
                static fn(string $json): Exchange => self::deserialize(json_decode($json, true, flags: JSON_THROW_ON_ERROR)),
                $buffer,
            );
        } finally {
            fclose($handle);
        }
    }

    /** @return Generator<Exchange> */
    public function readRange(int $start, int $count): Generator
    {
        $handle = @fopen($this->path, 'r');

        if ($handle === false) {
            return;
        }

        try {
            $current = 0;
            $read = 0;

            while (($line = fgets($handle)) !== false && $read < $count) {
                if ($current >= $start) {
                    $data = json_decode(rtrim($line, "\n"), true, flags: JSON_THROW_ON_ERROR);
                    yield self::deserialize($data);
                    $read++;
                }

                $current++;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, mixed> */
    private static function serialize(Exchange $exchange): array
    {
        $toolCalls = array_map(static fn(ToolCallSummary $tc): array => [
            'toolName' => $tc->toolName,
            'argumentsSummary' => $tc->argumentsSummary,
            'status' => $tc->status,
            'resultContent' => $tc->resultContent,
            'resultType' => $tc->resultType,
            'expanded' => $tc->expanded,
        ], $exchange->toolCalls);

        return [
            'id' => $exchange->id,
            'userMessage' => $exchange->userMessage,
            'assistantResponse' => $exchange->assistantResponse,
            'summary' => $exchange->summary,
            'toolCalls' => $toolCalls,
            'thinkingContent' => $exchange->thinkingContent,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function deserialize(array $data): Exchange
    {
        $toolCalls = array_map(static fn(array $tc): ToolCallSummary => new ToolCallSummary(
            toolName: $tc['toolName'],
            argumentsSummary: $tc['argumentsSummary'],
            status: $tc['status'] ?? null,
            resultContent: $tc['resultContent'] ?? null,
            resultType: $tc['resultType'] ?? null,
            expanded: $tc['expanded'] ?? false,
        ), $data['toolCalls'] ?? []);

        return new Exchange(
            userMessage: $data['userMessage'],
            assistantResponse: $data['assistantResponse'],
            summary: $data['summary'],
            toolCalls: $toolCalls,
            thinkingContent: $data['thinkingContent'] ?? null,
            id: $data['id'] ?? null,
        );
    }
}
