<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\GeminiCli;

use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Options;
use Phalanx\Panoply\Conversation\Parser as ParserInterface;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Conversation\Record\ToolCall;
use Phalanx\Panoply\Conversation\Record\ToolResult;
use Phalanx\Panoply\Conversation\Record\Unknown;
use Phalanx\Panoply\Conversation\Source as ConversationSource;
use Phalanx\Panoply\Conversation\StrictMode;
use Phalanx\Panoply\Id;

/**
 * Parses a single Gemini CLI JSONL conversation file into a normalized
 * {@see Log}. Reads line-by-line via {@see \SplFileObject}; lazy generator —
 * large files are not loaded into memory.
 *
 * Gemini CLI JSONL shape (per observed CLI output):
 * - Every line is a JSON object with a top-level `role` field: `"user"` or
 *   `"model"`. The `model` role is translated to `assistant` in panoply's
 *   normalized taxonomy.
 * - Content is carried in a `parts[]` array. Each part is an object with a
 *   `text` field (plain text) or a `functionCall` / `functionResponse` field
 *   (tool invocation / result).
 * - `functionCall` parts become {@see ToolCall} records.
 * - `functionResponse` parts become {@see ToolResult} records.
 * - Any unrecognised `role` value: Lenient yields {@see Unknown}; Loud
 *   throws; Silent drops.
 *
 * Only {@see Source} is accepted as input.
 *
 * Final — Parser implementations are sealed per vendor.
 */
final class Parser implements ParserInterface
{
    public function parse(ConversationSource $source, ?Options $options = null): Log
    {
        if (!$source instanceof Source) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s requires a %s source; got %s',
                    self::class,
                    Source::class,
                    $source::class,
                ),
            );
        }

        $mode = $options !== null ? $options->strictMode : StrictMode::Loud;
        $path = $source->path;

        return new Log(static function () use ($path, $mode): \Generator {
            if (!is_file($path)) {
                return;
            }

            $file = new \SplFileObject($path, 'r');
            $file->setFlags(\SplFileObject::DROP_NEW_LINE | \SplFileObject::SKIP_EMPTY);

            $seq = 0;

            foreach ($file as $rawLine) {
                if (!is_string($rawLine) || $rawLine === '') {
                    continue;
                }

                $data = json_decode($rawLine, associative: true);

                if (!is_array($data)) {
                    continue;
                }

                $role = isset($data['role']) && is_string($data['role']) ? $data['role'] : '';
                $parts = isset($data['parts']) && is_array($data['parts']) ? $data['parts'] : [];
                $ts = self::extractTimestamp($data);
                $seq++;

                yield from self::dispatchLine($data, $role, $parts, $ts, $seq, $mode);
            }

            unset($file);
        });
    }

    /**
     * @param array<string, mixed>     $data
     * @param array<int, mixed>        $parts
     * @return \Generator<\Phalanx\Panoply\Conversation\Record>
     */
    private static function dispatchLine(
        array $data,
        string $role,
        array $parts,
        \DateTimeImmutable $ts,
        int $seq,
        StrictMode $mode,
    ): \Generator {
        // Gemini uses "model" for the assistant role; normalize it.
        $normalizedRole = match ($role) {
            'user' => 'user',
            'model' => 'assistant',
            default => '',
        };

        if ($normalizedRole === '') {
            yield from self::handleUnknown(json_encode($data) ?: $role, $role, $seq, $ts, $mode);

            return;
        }

        // Each part may carry text, functionCall, or functionResponse.
        $textParts = [];

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }

            if (isset($part['text']) && is_string($part['text'])) {
                $textParts[] = $part['text'];
                continue;
            }

            if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                yield self::buildToolCall($part['functionCall'], $seq, $ts);
                continue;
            }

            if (isset($part['functionResponse']) && is_array($part['functionResponse'])) {
                yield self::buildToolResult($part['functionResponse'], $seq, $ts);
            }
        }

        if ($textParts !== []) {
            yield new Message(
                Id::generate(),
                $seq,
                $ts,
                role: $normalizedRole,
                text: implode('', $textParts),
            );
        }
    }

    /**
     * @param array<string, mixed> $fc
     */
    private static function buildToolCall(array $fc, int $seq, \DateTimeImmutable $ts): ToolCall
    {
        $callId = isset($fc['id']) && is_string($fc['id']) ? $fc['id'] : Id::generate();
        $toolName = isset($fc['name']) && is_string($fc['name']) ? $fc['name'] : 'unknown_tool';
        $arguments = isset($fc['args']) && is_array($fc['args']) ? $fc['args'] : [];

        return new ToolCall(Id::generate(), $seq, $ts, callId: $callId, toolName: $toolName, arguments: $arguments);
    }

    /**
     * @param array<string, mixed> $fr
     */
    private static function buildToolResult(array $fr, int $seq, \DateTimeImmutable $ts): ToolResult
    {
        $callId = isset($fr['name']) && is_string($fr['name']) ? $fr['name'] : '';
        $response = isset($fr['response']) && is_array($fr['response']) ? $fr['response'] : [];
        $output = isset($response['output']) && is_string($response['output'])
            ? $response['output']
            : (json_encode($response) ?: '');
        $isError = isset($fr['response']['error']);

        return new ToolResult(Id::generate(), $seq, $ts, callId: $callId, output: $output, isError: $isError);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractTimestamp(array $data): \DateTimeImmutable
    {
        $raw = $data['timestamp'] ?? $data['createTime'] ?? null;

        if (is_string($raw) && $raw !== '') {
            $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $raw)
                ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $raw)
                ?: false;

            if ($dt !== false) {
                return $dt;
            }
        }

        return new \DateTimeImmutable();
    }

    /**
     * @return \Generator<\Phalanx\Panoply\Conversation\Record>
     */
    private static function handleUnknown(
        string $rawJson,
        string $hint,
        int $seq,
        \DateTimeImmutable $ts,
        StrictMode $mode,
    ): \Generator {
        switch ($mode) {
            case StrictMode::Loud:
                throw new \UnexpectedValueException(
                    "Gemini CLI parser: unrecognised role '{$hint}'",
                );
            case StrictMode::Lenient:
                yield new Unknown(Id::generate(), $seq, $ts, rawJson: $rawJson, parserHint: $hint);
                break;
            case StrictMode::Silent:
                break;
        }
    }
}
