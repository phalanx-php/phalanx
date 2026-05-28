<?php

declare(strict_types=1);

namespace BgAgents\Tests\Unit\Repl;

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
use BgAgents\Repl\ReplCommandParser;
use PHPUnit\Framework\TestCase;

final class ReplCommandParserTest extends TestCase
{
    private ReplCommandParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ReplCommandParser();
    }

    public function test_empty_input(): void
    {
        self::assertInstanceOf(EmptyCmd::class, $this->parser->parse(''));
        self::assertInstanceOf(EmptyCmd::class, $this->parser->parse('   '));
    }

    public function test_help_aliases(): void
    {
        self::assertInstanceOf(HelpCmd::class, $this->parser->parse('help'));
        self::assertInstanceOf(HelpCmd::class, $this->parser->parse('?'));
    }

    public function test_exit_aliases(): void
    {
        self::assertInstanceOf(ExitCmd::class, $this->parser->parse('exit'));
        self::assertInstanceOf(ExitCmd::class, $this->parser->parse('quit'));
        self::assertInstanceOf(ExitCmd::class, $this->parser->parse(':q'));
    }

    public function test_list_aliases(): void
    {
        self::assertInstanceOf(ListSpecialistsCmd::class, $this->parser->parse('list'));
        self::assertInstanceOf(ListSpecialistsCmd::class, $this->parser->parse('ls'));
        self::assertInstanceOf(ListSpecialistsCmd::class, $this->parser->parse('specialists'));
    }

    public function test_status(): void
    {
        self::assertInstanceOf(StatusCmd::class, $this->parser->parse('status'));
    }

    public function test_ask_with_double_quoted_query(): void
    {
        $cmd = $this->parser->parse('ask supervisor "what is happening?"');

        self::assertInstanceOf(AskCmd::class, $cmd);
        self::assertSame('supervisor', $cmd->specialist);
        self::assertSame('what is happening?', $cmd->query);
    }

    public function test_ask_with_addressing_token(): void
    {
        $cmd = $this->parser->parse("ask @runtime 'fix this'");

        self::assertInstanceOf(AskCmd::class, $cmd);
        self::assertSame('@runtime', $cmd->specialist);
        self::assertSame('fix this', $cmd->query);
    }

    public function test_ask_unquoted_multi_word(): void
    {
        $cmd = $this->parser->parse('ask rn how do hooks compose');

        self::assertInstanceOf(AskCmd::class, $cmd);
        self::assertSame('rn', $cmd->specialist);
        self::assertSame('how do hooks compose', $cmd->query);
    }

    public function test_ask_with_no_query_is_unknown(): void
    {
        self::assertInstanceOf(UnknownCmd::class, $this->parser->parse('ask supervisor'));
    }

    public function test_quote_escape(): void
    {
        $cmd = $this->parser->parse('ask s "she said \"hi\""');

        self::assertInstanceOf(AskCmd::class, $cmd);
        self::assertSame('she said "hi"', $cmd->query);
    }

    public function test_bookkeeper_subcommands(): void
    {
        self::assertInstanceOf(BookkeeperListCmd::class, $this->parser->parse('bookkeeper'));
        self::assertInstanceOf(BookkeeperListCmd::class, $this->parser->parse('bookkeeper list'));
        self::assertInstanceOf(BookkeeperListCmd::class, $this->parser->parse('bookkeeper issues'));
        self::assertInstanceOf(BookkeeperListCmd::class, $this->parser->parse('bk'));

        $accept = $this->parser->parse('bk accept 42');
        self::assertInstanceOf(BookkeeperAcceptCmd::class, $accept);
        self::assertSame(42, $accept->issueId);

        $dismiss = $this->parser->parse('bookkeeper dismiss 7');
        self::assertInstanceOf(BookkeeperDismissCmd::class, $dismiss);
        self::assertSame(7, $dismiss->issueId);

        self::assertInstanceOf(UnknownCmd::class, $this->parser->parse('bk accept'));
    }

    public function test_memory_query(): void
    {
        $cmd = $this->parser->parse('memory query video lock');

        self::assertInstanceOf(MemoryQueryCmd::class, $cmd);
        self::assertSame('video lock', $cmd->topic);

        $shorthand = $this->parser->parse('mem video lock');
        self::assertInstanceOf(MemoryQueryCmd::class, $shorthand);
        self::assertSame('video lock', $shorthand->topic);
    }

    public function test_unknown_command(): void
    {
        $cmd = $this->parser->parse('foobar baz');

        self::assertInstanceOf(UnknownCmd::class, $cmd);
        self::assertSame('foobar baz', $cmd->raw);
    }
}
