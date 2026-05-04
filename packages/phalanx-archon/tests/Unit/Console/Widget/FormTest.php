<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Console\Widget;

use Phalanx\Archon\Console\Widget\Form;
use Phalanx\Archon\Console\Widget\FormRevertedException;
use Phalanx\Archon\Console\Input\CancelledException;
use Phalanx\Archon\Console\Input\ConfirmInput;
use Phalanx\Archon\Console\Input\TextInput;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Style;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Tests\Unit\Console\Input\FakeKeyReader;
use Phalanx\Archon\Tests\Unit\Console\Input\StubScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FormTest extends TestCase
{
    private Theme $theme;
    /** @var resource */
    private mixed $stream;
    private StreamOutput $output;
    private StubScope $scope;

    protected function setUp(): void
    {
        $plain       = Style::new();
        $this->theme = new Theme($plain, $plain, $plain, $plain, $plain, $plain, $plain, $plain, $plain);

        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);
        $this->stream = $stream;

        $this->output = new StreamOutput($stream, new TerminalEnvironment(columns: 80, lines: 24));
        $this->scope  = new StubScope();
    }

    protected function tearDown(): void
    {
        fclose($this->stream);
    }

    #[Test]
    public function submitsAggregatedValuesInRegistrationOrder(): void
    {
        $form = (new Form())
            ->text('name', fn() => new TextInput($this->theme, 'Name', '', '', '', null, null))
            ->confirm('agree', fn() => new ConfirmInput($this->theme, 'Agree?', default: false));

        $reader = new FakeKeyReader(['A', 'l', 'i', 'c', 'e', 'enter', 'y']);

        $values = $form->submit($this->scope, $this->output, $reader);

        self::assertSame(['name' => 'Alice', 'agree' => true], $values);
        self::assertSame(['name', 'agree'], array_keys($values));
    }

    #[Test]
    public function revertFromSecondStepRewindsToFirst(): void
    {
        $form = (new Form())
            ->text('first', fn(mixed $prev) => new TextInput(
                $this->theme,
                'First',
                '',
                is_string($prev) ? $prev : '',
                '',
                null,
                null,
            ))
            ->text('second', fn() => new TextInput($this->theme, 'Second', '', '', '', null, null));

        $reader = new FakeKeyReader([
            'a', 'enter',
            'ctrl-u',
            'b', 'enter',
            'x', 'enter',
        ]);

        $values = $form->submit($this->scope, $this->output, $reader);

        self::assertSame(['first' => 'ab', 'second' => 'x'], $values);
    }

    #[Test]
    public function revertFromFirstStepThrows(): void
    {
        $form = (new Form())
            ->text('only', fn() => new TextInput($this->theme, 'Only', '', '', '', null, null));

        $reader = new FakeKeyReader(['ctrl-u']);

        $this->expectException(FormRevertedException::class);

        $form->submit($this->scope, $this->output, $reader);
    }

    #[Test]
    public function cancellationPropagatesFromPrompt(): void
    {
        $form = (new Form())
            ->text('first', fn() => new TextInput($this->theme, 'First', '', '', '', null, null))
            ->text('second', fn() => new TextInput($this->theme, 'Second', '', '', '', null, null));

        $reader = new FakeKeyReader(['a', 'enter', 'ctrl-c']);

        $this->expectException(CancelledException::class);

        $form->submit($this->scope, $this->output, $reader);
    }

    #[Test]
    public function emptyFormReturnsEmptyArray(): void
    {
        $form   = new Form();
        $reader = new FakeKeyReader([]);

        self::assertSame([], $form->submit($this->scope, $this->output, $reader));
    }

    #[Test]
    public function revertFromIndexTwoUnwindsToOneThenAdvancesAgain(): void
    {
        $stepTwoPrevSeen = [];

        $form = (new Form())
            ->text('first', fn(mixed $prev) => new TextInput(
                $this->theme,
                'First',
                '',
                is_string($prev) ? $prev : '',
                '',
                null,
                null,
            ))
            ->text('second', fn(mixed $prev) => new TextInput(
                $this->theme,
                'Second',
                '',
                is_string($prev) ? $prev : '',
                '',
                null,
                null,
            ))
            ->text('third', function (mixed $prev) use (&$stepTwoPrevSeen): TextInput {
                $stepTwoPrevSeen[] = $prev;
                return new TextInput($this->theme, 'Third', '', '', '', null, null);
            });

        $reader = new FakeKeyReader([
            'a', 'enter',
            'b', 'enter',
            'ctrl-u',
            'enter',
            'z', 'enter',
        ]);

        $values = $form->submit($this->scope, $this->output, $reader);

        self::assertSame(['first' => 'a', 'second' => 'b', 'third' => 'z'], $values);
        self::assertSame([null, null], $stepTwoPrevSeen);
    }

    #[Test]
    public function revertChainAllTheWayBackThrowsAtIndexZero(): void
    {
        $form = (new Form())
            ->text('first', fn() => new TextInput($this->theme, 'First', '', '', '', null, null))
            ->text('second', fn() => new TextInput($this->theme, 'Second', '', '', '', null, null))
            ->text('third', fn() => new TextInput($this->theme, 'Third', '', '', '', null, null));

        $reader = new FakeKeyReader([
            'a', 'enter',
            'b', 'enter',
            'ctrl-u',
            'ctrl-u',
            'ctrl-u',
        ]);

        $this->expectException(FormRevertedException::class);

        $form->submit($this->scope, $this->output, $reader);
    }

    #[Test]
    public function successfulPriorStepsArePreservedAcrossRevert(): void
    {
        $stepOnePrevSeen = [];

        $form = (new Form())
            ->text('first', fn() => new TextInput($this->theme, 'First', '', '', '', null, null))
            ->text('second', function (mixed $prev) use (&$stepOnePrevSeen): TextInput {
                $stepOnePrevSeen[] = $prev;
                return new TextInput(
                    $this->theme,
                    'Second',
                    '',
                    is_string($prev) ? $prev : '',
                    '',
                    null,
                    null,
                );
            })
            ->text('third', fn() => new TextInput($this->theme, 'Third', '', '', '', null, null));

        $reader = new FakeKeyReader([
            'a', 'enter',
            'b', 'enter',
            'ctrl-u',
            'enter',
            'c', 'enter',
        ]);

        $values = $form->submit($this->scope, $this->output, $reader);

        self::assertSame(['first' => 'a', 'second' => 'b', 'third' => 'c'], $values);
        self::assertSame([null, 'b'], $stepOnePrevSeen);
    }
}
