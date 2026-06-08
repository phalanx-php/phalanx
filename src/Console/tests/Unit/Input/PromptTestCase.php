<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Input;

use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Output\TerminalEnvironment;
use Phalanx\Console\Style\Style;
use Phalanx\Console\Style\Theme;
use Phalanx\Stream\ResourceHandle;
use Phalanx\Stream\Stream;
use PHPUnit\Framework\TestCase;

abstract class PromptTestCase extends TestCase
{
    protected const string ENTER = 'enter';
    protected const string BACKSPACE = 'backspace';
    protected const string UP = 'up';
    protected const string DOWN = 'down';
    protected const string LEFT = 'left';
    protected const string RIGHT = 'right';
    protected const string SPACE = 'space';
    protected const string TAB = 'tab';
    protected const string ESCAPE = 'escape';
    protected const string CTRL_C = 'ctrl-c';
    protected const string CTRL_U = 'ctrl-u';

    protected Theme $theme;

    protected ResourceHandle $stream;
    protected StreamOutput $output;
    protected StubScope $scope;

    protected function setUp(): void
    {
        $plain = Style::new();
        $this->theme = new Theme(
            success: $plain,
            warning: $plain,
            error: $plain,
            muted: $plain,
            accent: $plain,
            label: $plain,
            hint: $plain,
            border: $plain,
            active: $plain,
        );

        $this->stream = Stream::memoryBuffer();
        $terminal = new TerminalEnvironment(columns: 80, lines: 24);
        $this->output = new StreamOutput($this->stream->resource(), $terminal);
        $this->scope = new StubScope();
    }

    protected function tearDown(): void
    {
        $this->stream->close();
    }

    /** @param list<string> $keys */
    protected function reader(array $keys = [], bool $interactive = true): FakeKeyReader
    {
        return new FakeKeyReader($keys, $interactive);
    }
}
