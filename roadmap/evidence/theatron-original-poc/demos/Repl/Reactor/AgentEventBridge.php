<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Reactor;

class AgentEventBridge
{
    public static function detectResultType(string $content): string
    {
        if (str_starts_with($content, '---') || str_contains($content, "\n+") || str_contains($content, "\n-")) {
            return 'diff';
        }

        if (str_contains($content, "\n") && preg_match('/^\d+[:|]/', $content)) {
            return 'search';
        }

        if (str_contains($content, "\n") && (str_contains($content, '<?php') || str_contains($content, 'function '))) {
            return 'code';
        }

        $trimmed = ltrim($content);

        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            if (json_decode($trimmed, true) !== null) {
                return 'json';
            }
        }

        return 'text';
    }

    /** @param array<string, mixed> $arguments */
    public static function summarizeArguments(array $arguments): string
    {
        if ($arguments === []) {
            return '';
        }

        $parts = [];

        foreach ($arguments as $key => $value) {
            $display = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);

            if (mb_strlen($display) > 30) {
                $display = mb_substr($display, 0, 27) . '...';
            }

            $parts[] = "{$key}: {$display}";
        }

        return implode(', ', $parts);
    }
}
