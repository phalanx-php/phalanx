#!/usr/bin/env php
<?php

declare(strict_types=1);

$count = max(1, (int) (optionValue($argv, '--count') ?? 1));
$timeoutMs = max(100, (int) (optionValue($argv, '--timeout-ms') ?? 750));

if (hasFlag($argv, '--help')) {
    echo "Usage: php tools/capture-terminal-keys.php [--count N] [--timeout-ms MS]\n";
    echo "Press one key sequence for each prompt. Output is JSON Lines.\n";
    exit(0);
}

if (! stream_isatty(STDIN)) {
    fwrite(STDERR, "STDIN is not a TTY. Run this directly in the terminal being audited.\n");
    exit(2);
}

$originalMode = trim((string) shell_exec('stty -g'));

try {
    system('stty raw -echo min 0 time 0');
    stream_set_blocking(STDIN, false);

    fwrite(STDERR, "Press {$count} key sequence(s). Ctrl+C at a prompt exits.\n");

    for ($index = 1; $index <= $count; $index++) {
        fwrite(STDERR, "[$index/$count] key: ");

        $bytes = readSequence($timeoutMs);
        fwrite(STDERR, PHP_EOL);

        if ($bytes === "\x03") {
            fwrite(STDERR, "Cancelled.\n");
            break;
        }

        echo json_encode([
            'term_program' => getenv('TERM_PROGRAM') ?: null,
            'term' => getenv('TERM') ?: null,
            'colorterm' => getenv('COLORTERM') ?: null,
            'tmux' => getenv('TMUX') !== false,
            'bytes_hex' => bin2hex($bytes),
            'bytes_c' => cEscape($bytes),
            'length' => strlen($bytes),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
} finally {
    if ($originalMode !== '') {
        system('stty ' . escapeshellarg($originalMode));
    }
}

function readSequence(int $timeoutMs): string
{
    $buffer = '';
    $deadline = microtime(true) + ($timeoutMs / 1000);

    while (microtime(true) < $deadline) {
        $chunk = fread(STDIN, 1024);

        if (is_string($chunk) && $chunk !== '') {
            $buffer .= $chunk;
            $deadline = microtime(true) + (0.08);
        }

        usleep(5_000);
    }

    return $buffer;
}

function cEscape(string $bytes): string
{
    $escaped = '';
    $length = strlen($bytes);

    for ($index = 0; $index < $length; $index++) {
        $byte = ord($bytes[$index]);
        $escaped .= match ($byte) {
            0x1B => '\\e',
            0x0A => '\\n',
            0x0D => '\\r',
            0x09 => '\\t',
            default => $byte >= 0x20 && $byte <= 0x7E
                ? chr($byte)
                : sprintf('\\x%02X', $byte),
        };
    }

    return $escaped;
}

/** @param list<string> $argv */
function optionValue(array $argv, string $name): ?string
{
    foreach ($argv as $index => $arg) {
        if ($arg === $name && isset($argv[$index + 1])) {
            return $argv[$index + 1];
        }
    }

    return null;
}

/** @param list<string> $argv */
function hasFlag(array $argv, string $name): bool
{
    return in_array($name, $argv, true);
}
