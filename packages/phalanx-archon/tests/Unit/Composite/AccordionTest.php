<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Composite;

use Phalanx\Archon\Composite\Accordion;
use Phalanx\Archon\Composite\Form;
use Phalanx\Archon\Input\CancelledException;
use Phalanx\Archon\Input\TextInput;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Archon\Output\TerminalEnvironment;
use Phalanx\Archon\Style\Style;
use Phalanx\Archon\Style\Theme;
use Phalanx\Archon\Tests\Unit\Input\FakeKeyReader;
use Phalanx\Archon\Tests\Unit\Input\StubScope;
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
}
