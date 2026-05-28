<?php

declare(strict_types=1);

namespace BgAgents\Repl;

use BgAgents\Repl\Cmd\AskCmd;
use BgAgents\Repl\Cmd\BookkeeperAcceptCmd;
use BgAgents\Repl\Cmd\BookkeeperDismissCmd;
use BgAgents\Repl\Cmd\BookkeeperListCmd;
use BgAgents\Repl\Cmd\EmptyCmd;
use BgAgents\Repl\Cmd\ExitCmd;
use BgAgents\Repl\Cmd\HelpCmd;
use BgAgents\Repl\Cmd\ListSpecialistsCmd;
use BgAgents\Repl\Cmd\MemoryQueryCmd;
use BgAgents\Repl\Cmd\StatusCmd;
use BgAgents\Repl\Cmd\UnknownCmd;

/**
 * Pure parser: line → ReplCommand. No I/O, no scope. Easy to test.
 *
 * Quoting: simple single/double-quote aware tokenizer; '\\' escapes the
 * next character inside a quoted region. Good enough for the v1 REPL.
 */
final class ReplCommandParser
{
    public function parse(string $line): ReplCommand
    {
        $line = trim($line);
        if ($line === '') {
            return new EmptyCmd();
        }

        $tokens = self::tokenize($line);
        if ($tokens === []) {
            return new EmptyCmd();
        }

        $verb = strtolower($tokens[0]);
        $rest = array_slice($tokens, 1);

        return match ($verb) {
            'help', '?' => new HelpCmd(),
            'exit', 'quit', ':q' => new ExitCmd(),
            'list', 'specialists', 'ls' => new ListSpecialistsCmd(),
            'status' => new StatusCmd(),
            'ask' => self::parseAsk($rest, $line),
            'bookkeeper', 'bk' => self::parseBookkeeper($rest, $line),
            'memory', 'mem' => self::parseMemory($rest, $line),
            default => new UnknownCmd($line),
        };
    }

    /** @param list<string> $rest */
    private static function parseAsk(array $rest, string $raw): ReplCommand
    {
        if (count($rest) < 2) {
            return new UnknownCmd($raw);
        }
        return new AskCmd(
            specialist: $rest[0],
            query: implode(' ', array_slice($rest, 1)),
        );
    }

    /** @param list<string> $rest */
    private static function parseBookkeeper(array $rest, string $raw): ReplCommand
    {
        $sub = strtolower($rest[0] ?? 'list');
        return match ($sub) {
            'list', 'issues', '' => new BookkeeperListCmd(),
            'accept' => isset($rest[1]) ? new BookkeeperAcceptCmd((int) $rest[1]) : new UnknownCmd($raw),
            'dismiss' => isset($rest[1]) ? new BookkeeperDismissCmd((int) $rest[1]) : new UnknownCmd($raw),
            default => new UnknownCmd($raw),
        };
    }

    /** @param list<string> $rest */
    private static function parseMemory(array $rest, string $raw): ReplCommand
    {
        $sub = strtolower($rest[0] ?? '');
        if ($sub === 'query' && isset($rest[1])) {
            return new MemoryQueryCmd(implode(' ', array_slice($rest, 1)));
        }
        if ($sub !== 'query' && $sub !== '') {
            return new MemoryQueryCmd(implode(' ', $rest));
        }
        return new UnknownCmd($raw);
    }

    /** @return list<string> */
    private static function tokenize(string $line): array
    {
        $tokens = [];
        $cur = '';
        $quote = null;
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];

            if ($quote !== null) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $cur .= $line[++$i];
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                    continue;
                }
                $cur .= $ch;
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }

            if ($ch === ' ' || $ch === "\t") {
                if ($cur !== '') {
                    $tokens[] = $cur;
                    $cur = '';
                }
                continue;
            }

            $cur .= $ch;
        }

        if ($cur !== '') {
            $tokens[] = $cur;
        }

        return $tokens;
    }
}
