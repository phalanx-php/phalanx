<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Input;

use Phalanx\Console\Input\RawInput;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Style\Style;
use Phalanx\Console\Style\Theme;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

abstract class PromptTestCase extends TestCase
{
    protected Theme $theme;

    /** @var resource */
    protected mixed $stream;
    protected StreamOutput $output;
    protected RawInput $input;
    private ReflectionMethod $dispatchMethod;

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

        // Non-TTY memory stream — writes still happen, cursor control is skipped.
        $this->stream = fopen('php://memory', 'w+');
        $_SERVER['COLUMNS'] = '80';
        $_SERVER['LINES']   = '24';
        $this->output = new StreamOutput($this->stream);

        // TTY=true so prompt enters interactive mode; enable()/attach() are never
        // called, avoiding real stty and STDIN registration.
        $this->input = new RawInput(isTty: true);

        $this->dispatchMethod = new ReflectionMethod(RawInput::class, 'dispatch');
    }

    protected function tearDown(): void
    {
        fclose($this->stream);
    }

    /**
     * Inject raw terminal bytes into RawInput as if typed by the user.
     * Resolves pending nextKey() deferreds synchronously — no event loop needed.
     */
    protected function press(string ...$byteSequences): void
    {
        foreach ($byteSequences as $bytes) {
            $this->dispatchMethod->invoke($this->input, $bytes);
        }
    }

    protected const string ENTER     = "\r";
    protected const string BACKSPACE  = "\x7f";
    protected const string UP        = "\x1b[A";
    protected const string DOWN      = "\x1b[B";
    protected const string LEFT      = "\x1b[D";
    protected const string RIGHT     = "\x1b[C";
    protected const string SPACE     = ' ';
    protected const string TAB       = "\x09";
    protected const string CTRL_C    = "\x03";
    protected const string CTRL_U    = "\x15";
    protected const string CTRL_A    = "\x01";
}
