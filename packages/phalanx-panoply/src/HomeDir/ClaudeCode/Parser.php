<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\ClaudeCode;

use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Options;
use Phalanx\Panoply\Conversation\Parser as ParserInterface;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Conversation\Record\Metadata;
use Phalanx\Panoply\Conversation\Record\ToolCall;
use Phalanx\Panoply\Conversation\Record\ToolResult;
use Phalanx\Panoply\Conversation\Record\Unknown;
use Phalanx\Panoply\Conversation\Source as ConversationSource;
use Phalanx\Panoply\Conversation\StrictMode;
use Phalanx\Panoply\Id;

/**
 * Parses a single Claude Code JSONL conversation file into a normalized
 * {@see Log}. Reads line-by-line via {@see \SplFileObject}; the underlying
 * generator is lazy so large files are not loaded into memory.
 *
 * Claude Code JSONL shape (per observed output):
 * - Every line is a JSON object with a top-level `type` field.
 * - `type: "user"` — user turn; may contain a `message.content[]` array with
 *   `tool_result` blocks inside (those become {@see ToolResult} records).
 * - `type: "assistant"` — assistant turn; `message.content[]` may contain
 *   `tool_use` blocks (those become {@see ToolCall} records); text content
 *   becomes a {@see Message} record with role=assistant.
 * - `type: "system"` — system prompt text; becomes {@see Message} role=system.
 * - `type: "summary"` — conversation summary line; becomes {@see Metadata}
 *   key="summary".
 * - Any unrecognised `type` value: Lenient yields {@see Unknown}; Loud throws;
 *   Silent drops.
 *
 * Only {@see Source} is accepted as input; passing a foreign {@see ConversationSource}
 * throws immediately.
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

                $type = isset($data['type']) && is_string($data['type']) ? $data['type'] : '';
                $ts = self::extractTimestamp($data);
                $seq++;

                yield from self::dispatchLine($data, $type, $ts, $seq, $mode);
            }

            unset($file);
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return \Generator<\Phalanx\Panoply\Conversation\Record>
     */
    private static function dispatchLine(
        array $data,
        string $type,
        \DateTimeImmutable $ts,
        int $seq,
        StrictMode $mode,
    ): \Generator {
        $message = isset($data['message']) && is_array($data['message']) ? $data['message'] : [];
        $content = isset($message['content']) && is_array($message['content']) ? $message['content'] : [];

        switch ($type) {
            case 'user':
                // User turns may carry tool_result blocks in content[].
                $text = self::extractText($content);
                if ($text !== '') {
                    yield new Message(Id::generate(), $seq, $ts, role: 'user', text: $text);
                }
                foreach ($content as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    if (($block['type'] ?? '') === 'tool_result') {
                        yield self::buildToolResult($block, $seq, $ts);
                    }
                }
                break;

            case 'assistant':
                // Assistant turns: extract text content + tool_use blocks.
                $text = self::extractText($content);
                if ($text !== '') {
                    yield new Message(Id::generate(), $seq, $ts, role: 'assistant', text: $text);
                }
                foreach ($content as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    if (($block['type'] ?? '') === 'tool_use') {
                        yield self::buildToolCall($block, $seq, $ts);
                    }
                }
                break;

            case 'system':
                $text = self::extractText($content);
                if ($text === '') {
                    // Fall back to top-level content field if present.
                    $text = isset($data['content']) && is_string($data['content'])
                        ? $data['content']
                        : '';
                }
                if ($text !== '') {
                    yield new Message(Id::generate(), $seq, $ts, role: 'system', text: $text);
                }
                break;

            case 'summary':
                $summaryText = isset($data['summary']) && is_string($data['summary'])
                    ? $data['summary']
                    : (isset($data['content']) && is_string($data['content']) ? $data['content'] : '');
                yield new Metadata(Id::generate(), $seq, $ts, key: 'summary', value: $summaryText);
                break;

            default:
                yield from self::handleUnknown(json_encode($data) ?: $type, $type, $seq, $ts, $mode);
                break;
        }
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function buildToolCall(array $block, int $seq, \DateTimeImmutable $ts): ToolCall
    {
        $callId = isset($block['id']) && is_string($block['id']) ? $block['id'] : Id::generate();
        $toolName = isset($block['name']) && is_string($block['name']) ? $block['name'] : 'unknown_tool';
        $arguments = isset($block['input']) && is_array($block['input']) ? $block['input'] : [];

        return new ToolCall(Id::generate(), $seq, $ts, callId: $callId, toolName: $toolName, arguments: $arguments);
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function buildToolResult(array $block, int $seq, \DateTimeImmutable $ts): ToolResult
    {
        $callId = isset($block['tool_use_id']) && is_string($block['tool_use_id']) ? $block['tool_use_id'] : '';
        $isError = isset($block['is_error']) && $block['is_error'] === true;

        // Content may be a string or an array of text blocks.
        $output = '';
        if (isset($block['content'])) {
            if (is_string($block['content'])) {
                $output = $block['content'];
            } elseif (is_array($block['content'])) {
                $output = self::extractText($block['content']);
            }
        }

        return new ToolResult(Id::generate(), $seq, $ts, callId: $callId, output: $output, isError: $isError);
    }

    /**
     * @param array<int|string, mixed> $content
     */
    private static function extractText(array $content): string
    {
        // Handles both plain-string content (rare) and content[] array of blocks.
        $parts = [];
        foreach ($content as $block) {
            if (is_string($block)) {
                $parts[] = $block;
            } elseif (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return implode('', $parts);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractTimestamp(array $data): \DateTimeImmutable
    {
        $raw = $data['timestamp'] ?? null;

        if (is_string($raw) && $raw !== '') {
            $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $raw)
                ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $raw)
                ?: false;

            if ($dt !== false) {
                return $dt;
            }
        }

        if (is_int($raw) || (is_float($raw) && $raw > 0)) {
            return new \DateTimeImmutable('@' . (int) $raw);
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
                    "Claude Code parser: unrecognised record type '{$hint}'",
                );
            case StrictMode::Lenient:
                yield new Unknown(Id::generate(), $seq, $ts, rawJson: $rawJson, parserHint: $hint);
                break;
            case StrictMode::Silent:
                break;
        }
    }
}
