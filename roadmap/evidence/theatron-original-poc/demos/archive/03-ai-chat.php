#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\MouseButton;
use Phalanx\Theatron\Input\MouseEvent;
use Phalanx\Theatron\Input\PasteEvent;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;

final class MockStream
{
    private const array RESPONSES = [
        'phalanx' => "The phalanx formation was the backbone of Greek warfare from the 7th century BCE through Alexander's conquests. Hoplites stood shoulder to shoulder, shields overlapping to form an impenetrable wall of bronze. Each warrior's aspis protected not just himself but the man to his left -- making the formation fundamentally cooperative.\n\nThe sarissa-armed Macedonian phalanx under Philip II extended this concept to devastating effect. Pikes up to 18 feet long created a hedge of iron points that no cavalry could penetrate. At Chaeronea in 338 BCE, the Macedonian phalanx pinned the Greek center while Alexander's companion cavalry delivered the decisive blow on the flank.\n\nThe formation's weakness was its flanks and rear. Once broken or outmaneuvered, the tightly packed soldiers could not easily reform. The Romans exploited this systematically with their more flexible manipular system at Cynoscephalae and Pydna.",

        'sparta' => "Sparta's military system was unique in the ancient world -- an entire society organized around producing warriors. The agoge began at age seven, when boys were taken from their families and placed in communal barracks. They trained barefoot, ate meager rations, and were encouraged to steal food to develop cunning.\n\nAt Thermopylae, three hundred Spartans under Leonidas held the narrow pass against Xerxes' vast army for three days. When told the Persian arrows would blot out the sun, Dienekes reportedly replied: \"Then we shall fight in the shade.\" The sacrifice bought time for the Greek fleet at Artemisium and ultimately for the defense of the Peloponnese.\n\nSpartan women held unusual power in the Greek world. They managed estates, received physical training, and were famously sharp-tongued. When asked why Spartan women were the only ones who could rule men, Gorgo replied: \"Because we are the only ones who give birth to men.\"",

        'odysseus' => "Odysseus embodies the Greek ideal of metis -- cunning intelligence. Where Achilles relied on divine strength and Ajax on brute force, Odysseus survived through wit. The Trojan Horse was his stratagem, and it ended a ten-year siege that raw power could not break.\n\nHis ten-year journey home tested every aspect of his character. He resisted the Sirens' song by having himself bound to the mast -- acknowledging his own weakness while finding a way through it. He blinded Polyphemus not through strength but through patience and deception, introducing himself as 'Nobody' so the other Cyclopes would not come to Polyphemus' aid.\n\nThe Odyssey's deepest theme is the cost of survival. Odysseus left Troy with twelve ships and returned alone. Every companion died along the way. His homecoming required one final act of cunning -- disguising himself as a beggar to assess the suitors before striking.",

        'default' => "The ancient Greeks shaped Western civilization in ways that still resonate. Their experiments in democracy, philosophy, theater, and warfare created frameworks we still use today.\n\nIn philosophy, Socrates introduced the method of systematic questioning -- not to provide answers but to expose the limits of what we think we know. His student Plato built the Academy, the first institution of higher learning. Aristotle, Plato's student in turn, created the foundations of logic, biology, and political science.\n\nGreek theater emerged from religious festivals honoring Dionysus. Tragedy explored the tension between human ambition and divine order. Comedy, particularly Aristophanes, used humor to critique politics and social norms. The theatrical innovations of the 5th century BCE -- dramatic structure, character development, the chorus as commentary -- remain the foundation of Western drama.",
    ];

    /** @return list<string> */
    public static function tokensFor(string $prompt): array
    {
        $lower = strtolower($prompt);
        $response = self::RESPONSES['default'];

        foreach (self::RESPONSES as $key => $text) {
            if ($key !== 'default' && str_contains($lower, $key)) {
                $response = $text;
                break;
            }
        }

        $words = preg_split('/(?<=\s)|(?=\n)/', $response, -1, PREG_SPLIT_NO_EMPTY);

        return $words !== false ? $words : explode(' ', $response);
    }
}

exit(Archon::command('ai-chat', static function (CommandContext $ctx): int {
    $ui = new Ui();

    $stage = Stage::boot(new StageConfig(
        mouseTracking: true,
        handleInput: true,
        defaultExitHandler: false,
        activeIntervalUs: 16_667,
    ));

    $w = $stage->width();
    $h = $stage->height();

    $chatLines = [
        'Theatron AI Chat Demo',
        '',
        'Type a message and press Enter to send.',
        'Try asking about: phalanx, sparta, odysseus',
        'Ctrl+C cancels generation. Escape exits.',
        '',
    ];
    $inputBuffer = '';
    $scrollOffset = 0;
    $generating = false;
    $cancelGeneration = false;
    $totalTokens = 0;
    $turnCount = 0;
    $genStartTime = 0.0;
    $genTokenCount = 0;
    $frames = 0;
    $spinnerFrame = 0;
    $startTime = microtime(true);

    $chatRegion = $stage->region('chat', Rect::of(0, 0, $w, $h - 2));
    $inputRegion = $stage->region('input', Rect::of(0, $h - 2, $w, 1));
    $barRegion = $stage->region('bar', Rect::of(0, $h - 1, $w, 1));

    $stage->onResize(static function (int $nw, int $nh) use ($chatRegion, $inputRegion, $barRegion): void {
        $chatRegion->resize(Rect::of(0, 0, $nw, $nh - 2));
        $inputRegion->resize(Rect::of(0, $nh - 2, $nw, 1));
        $barRegion->resize(Rect::of(0, $nh - 1, $nw, 1));
    });

    $stage->onInput(static function (InputEvent $event) use (
        $ctx,
        &$chatLines,
        &$inputBuffer,
        &$scrollOffset,
        &$generating,
        &$cancelGeneration,
        &$totalTokens,
        &$turnCount,
        &$genStartTime,
        &$genTokenCount,
    ): void {
        if ($event instanceof KeyEvent) {
            if ($event->is(Key::Escape)) {
                $ctx->cancellation()->cancel();
                return;
            }

            if ($event->ctrl && $event->is('c')) {
                if ($generating) {
                    $cancelGeneration = true;
                } else {
                    $ctx->cancellation()->cancel();
                }
                return;
            }

            if ($event->is(Key::PageUp)) {
                $scrollOffset = min($scrollOffset + 10, max(0, count($chatLines) - 5));
                return;
            }

            if ($event->is(Key::PageDown)) {
                $scrollOffset = max(0, $scrollOffset - 10);
                return;
            }

            if ($generating) {
                return;
            }

            if ($event->is(Key::Enter)) {
                if ($inputBuffer !== '') {
                    $turnCount++;
                    $chatLines[] = sprintf('You: %s', $inputBuffer);
                    $chatLines[] = '';
                    $inputBuffer = '';
                    $scrollOffset = 0;

                    $generating = true;
                    $cancelGeneration = false;
                    $genStartTime = microtime(true);
                    $genTokenCount = 0;

                    $prompt = $chatLines[count($chatLines) - 2];
                    $tokens = MockStream::tokensFor($prompt);

                    $ctx->go(static function () use (
                        $ctx,
                        $tokens,
                        &$chatLines,
                        &$generating,
                        &$cancelGeneration,
                        &$totalTokens,
                        &$genTokenCount,
                        &$scrollOffset,
                    ): void {
                        $chatLines[] = 'Apollo: ';
                        $lineIdx = count($chatLines) - 1;

                        $ctx->delay(random_int(300, 600) / 1000);

                        foreach ($tokens as $token) {
                            if ($cancelGeneration || $ctx->isCancelled) {
                                $chatLines[$lineIdx] .= ' [cancelled]';
                                break;
                            }

                            if (str_contains($token, "\n")) {
                                $parts = explode("\n", $token);
                                $chatLines[$lineIdx] .= $parts[0];

                                for ($i = 1; $i < count($parts); $i++) {
                                    $chatLines[] = $parts[$i];
                                    $lineIdx = count($chatLines) - 1;
                                }
                            } else {
                                $chatLines[$lineIdx] .= $token;
                            }

                            $totalTokens++;
                            $genTokenCount++;
                            $scrollOffset = 0;

                            $ctx->delay(random_int(15, 60) / 1000);
                        }

                        $chatLines[] = '';
                        $generating = false;
                        $cancelGeneration = false;
                    }, 'ai-generation');
                }
                return;
            }

            if ($event->is(Key::Backspace)) {
                $inputBuffer = mb_substr($inputBuffer, 0, -1);
                return;
            }

            $char = $event->char();
            if ($char !== null) {
                $inputBuffer .= $char;
            }

            return;
        }

        if ($event instanceof MouseEvent) {
            if ($event->button === MouseButton::ScrollUp) {
                $scrollOffset = min($scrollOffset + 3, max(0, count($chatLines) - 5));
                return;
            }

            if ($event->button === MouseButton::ScrollDown) {
                $scrollOffset = max(0, $scrollOffset - 3);
                return;
            }

            return;
        }

        if ($event instanceof PasteEvent) {
            if (!$generating) {
                $inputBuffer .= $event->content;
            }
        }
    });

    $drawFrame = static function () use (
        $ui, $chatRegion, $inputRegion, $barRegion, $stage,
        &$chatLines, &$inputBuffer, &$scrollOffset,
        &$generating, &$totalTokens, &$turnCount, &$frames, &$spinnerFrame,
        &$genStartTime, &$genTokenCount, $startTime,
    ): void {
        $frames++;

        $elapsed = microtime(true) - $startTime;
        $mem = memory_get_usage();
        $memLabel = $mem >= 1_048_576
            ? sprintf('%.1fMB', $mem / 1_048_576)
            : sprintf('%.0fKB', $mem / 1_024);

        $visibleHeight = max(1, $stage->height() - 4);
        $total = count($chatLines);
        $start = max(0, $total - $visibleHeight - $scrollOffset);
        $displayLines = array_slice($chatLines, $start, $visibleHeight);

        $chatRegion->draw($ui->panel(
            'Apollo Chat',
            $ui->scrollable(implode("\n", $displayLines), style: Style::of(color: Color::white())),
            style: Style::of(border: Border::Rounded, color: Color::brightCyan()),
        ));

        if ($generating) {
            $genElapsed = microtime(true) - $genStartTime;
            $tokenSpeed = $genElapsed > 0.1 ? $genTokenCount / $genElapsed : 0;
            $speedLabel = sprintf('%.1f tok/s', $tokenSpeed);
            $state = 'STREAMING';
            $spinnerFrame++;

            $inputRegion->draw($ui->spinner(
                label: 'Generating... (Ctrl+C to cancel)',
                frame: $spinnerFrame,
                style: Style::of(color: Color::green()),
            ));
        } else {
            $speedLabel = '--';
            $state = 'IDLE';

            $inputRegion->draw($ui->input(
                value: $inputBuffer,
                prompt: '> ',
                cursor: mb_strlen($inputBuffer),
                style: Style::of(color: Color::white(), background: Color::indexed(236)),
            ));
        }

        $barRegion->draw(new StatusLineElement(
            sections: [
                $ui->text(
                    sprintf(' %s | Tokens: %d | Speed: %s | Turns: %d', $state, $totalTokens, $speedLabel, $turnCount),
                    style: Style::of(size: Size::fill(), color: Color::brightWhite(), background: Color::indexed(236)),
                ),
                $ui->text(
                    sprintf('Mem: %s | %.1fs ', $memLabel, $elapsed),
                    style: Style::of(color: Color::brightWhite(), background: Color::indexed(236)),
                ),
            ],
            style: Style::of(background: Color::indexed(236)),
        ));
    };

    $drawFrame();
    $ctx->periodic(0.05, $drawFrame);
    $stage->run($ctx);

    return 0;
})->default('ai-chat')->run(array_slice($_SERVER['argv'] ?? [], 1)));
