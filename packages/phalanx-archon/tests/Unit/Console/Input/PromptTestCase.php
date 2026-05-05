<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Console\Input;

use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Style;
use Phalanx\Archon\Console\Style\Theme;
use PHPUnit\Framework\TestCase;

abstract class PromptTestCase extends TestCase
{
    protected const string ENTER     = 'enter';
    protected const string BACKSPACE = 'backspace';
    protected const string UP        = 'up';
    protected const string DOWN      = 'down';
    protected const string LEFT      = 'left';
    protected const string RIGHT     = 'right';
    protected const string SPACE     = 'space';
    protected const string TAB       = 'tab';
    protected const string ESCAPE    = 'escape';
    protected const string CTRL_C    = 'ctrl-c';
    protected const string CTRL_U    = 'ctrl-u';

    protected Theme $theme;

    /** @var resource */
    protected mixed $stream;
    protected StreamOutput $output;
    protected StubScope $scope;

    protected function setUp(): void
    {
        $plain       = Style::new();
        $this->theme = new Theme(
            success: $plain,
            warning: $plain,
            error:   $plain,
            muted:   $plain,
            accent:  $plain,
            label:   $plain,
            hint:    $plain,
            border:  $plain,
            active:  $plain,
        );

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Unable to open memory stream.');
        }

        $this->stream = $stream;
        $terminal     = new TerminalEnvironment(columns: 80, lines: 24);
        $this->output = new StreamOutput($this->stream, $terminal);
        $this->scope  = new StubScope();
    }

    protected function tearDown(): void
    {
        fclose($this->stream);
    }

    /** @param list<string> $keys */
    protected function reader(array $keys = [], bool $interactive = true): FakeKeyReader
    {
        return new FakeKeyReader($keys, $interactive);
    }
}
