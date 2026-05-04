<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Console\Widget;

use Phalanx\Archon\Console\Widget\Accordion;
use Phalanx\Archon\Console\Widget\Form;
use Phalanx\Archon\Console\Input\CancelledException;
use Phalanx\Archon\Console\Input\TextInput;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Style;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Tests\Unit\Console\Input\FakeKeyReader;
use Phalanx\Archon\Tests\Unit\Console\Input\StubScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccordionTest extends TestCase
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
    public function navigatesAndSubmitsEachSectionForm(): void
    {
        $accordion = (new Accordion())
            ->section('one', 'Section One', fn(): Form => (new Form())->text(
                'value',
                fn() => new TextInput($this->theme, 'Value', '', '', '', null, null),
            ))
            ->section('two', 'Section Two', fn(): Form => (new Form())->text(
                'value',
                fn() => new TextInput($this->theme, 'Value', '', '', '', null, null),
            ));

        $reader = new FakeKeyReader([
            'enter',
            'A', 'enter',
            'enter',
            'B', 'enter',
        ]);

        $values = $accordion->run($this->scope, $this->output, $reader);

        self::assertSame([
            'one' => ['value' => 'A'],
            'two' => ['value' => 'B'],
        ], $values);
    }

    #[Test]
    public function ctrlCDuringHeaderNavCancels(): void
    {
        $accordion = (new Accordion())
            ->section('one', 'Section', fn(): Form => (new Form())->text(
                'value',
                fn() => new TextInput($this->theme, 'Value', '', '', '', null, null),
            ));

        $reader = new FakeKeyReader(['ctrl-c']);

        $this->expectException(CancelledException::class);

        $accordion->run($this->scope, $this->output, $reader);
    }

    #[Test]
    public function eofTreatedAsCancellation(): void
    {
        $accordion = (new Accordion())
            ->section('one', 'Section', fn(): Form => (new Form())->text(
                'value',
                fn() => new TextInput($this->theme, 'Value', '', '', '', null, null),
            ));

        $reader = new FakeKeyReader([]);

        $this->expectException(CancelledException::class);

        $accordion->run($this->scope, $this->output, $reader);
    }

    #[Test]
    public function loopExitsWhenAllSectionsCompletedRegardlessOfCursorPosition(): void
    {
        $accordion = (new Accordion())
            ->section('one', 'One', fn(): Form => (new Form())->text(
                'value',
                fn() => new TextInput($this->theme, 'Value', '', '', '', null, null),
            ))
            ->section('two', 'Two', fn(): Form => (new Form())->text(
                'value',
                fn() => new TextInput($this->theme, 'Value', '', '', '', null, null),
            ))
            ->section('three', 'Three', fn(): Form => (new Form())->text(
                'value',
                fn() => new TextInput($this->theme, 'Value', '', '', '', null, null),
            ));

        $reader = new FakeKeyReader([
            'enter', 'A', 'enter',
            'enter', 'B', 'enter',
            'enter', 'C', 'enter',
        ]);

        $values = $accordion->run($this->scope, $this->output, $reader);

        self::assertSame(
            ['one' => ['value' => 'A'], 'two' => ['value' => 'B'], 'three' => ['value' => 'C']],
            $values,
        );
    }

    #[Test]
    public function cursorAdvancesPastCompletedSection(): void
    {
        $accordion = (new Accordion())
            ->section('one', 'One', fn(): Form => (new Form())->text(
                'value',
                fn() => new TextInput($this->theme, 'Value', '', '', '', null, null),
            ))
            ->section('two', 'Two', fn(): Form => (new Form())->text(
                'value',
                fn() => new TextInput($this->theme, 'Value', '', '', '', null, null),
            ));

        $reader = new FakeKeyReader([
            'enter', 'A', 'enter',
            'enter', 'B', 'enter',
        ]);

        $values = $accordion->run($this->scope, $this->output, $reader);

        self::assertArrayHasKey('one', $values);
        self::assertArrayHasKey('two', $values);
        self::assertSame(['value' => 'A'], $values['one']);
        self::assertSame(['value' => 'B'], $values['two']);
    }
}
