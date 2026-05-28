<?php

declare(strict_types=1);

namespace BgAgents\Repl;

/**
 * All REPL output funnels through here. ANSI colors only when stdout is a tty.
 */
final class ReplPrinter
{
    private bool $tty;

    public function __construct()
    {
        $this->tty = function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }

    public function prompt(): void
    {
        fwrite(STDOUT, $this->c("bg> ", '36'));
    }

    public function info(string $msg): void
    {
        fwrite(STDOUT, $this->c($msg, '90') . "\n");
    }

    public function note(string $msg): void
    {
        fwrite(STDOUT, $msg . "\n");
    }

    public function warn(string $msg): void
    {
        fwrite(STDERR, $this->c("warn: {$msg}", '33') . "\n");
    }

    public function error(string $msg): void
    {
        fwrite(STDERR, $this->c("error: {$msg}", '31') . "\n");
    }

    public function answer(string $from, string $text): void
    {
        $head = $this->c("[{$from}]", '32');
        fwrite(STDOUT, "{$head} {$text}\n");
    }

    public function banner(string $line): void
    {
        fwrite(STDOUT, $this->c($line, '36') . "\n");
    }

    public function kv(string $key, string $value): void
    {
        $key = str_pad($key, 14);
        fwrite(STDOUT, $this->c($key, '90') . " {$value}\n");
    }

    private function c(string $text, string $code): string
    {
        return $this->tty ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
