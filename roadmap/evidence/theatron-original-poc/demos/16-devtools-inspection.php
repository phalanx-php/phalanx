#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\Opt;
use Phalanx\Boot\AppContext;
use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\DevTools\DockPosition;
use Phalanx\Theatron\Store\Slice;
use Phalanx\Theatron\Store\Store;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;
use Phalanx\Theatron\TheatronBuilder;

final class CampaignSlice implements Slice
{
    public string $key { get => 'demo.campaign'; }

    public function __construct(
        private(set) string $phase = 'assembling',
        private(set) int $hoplites = 7,
        private(set) int $dispatches = 3,
    ) {
    }
}

final class OlympusCommand implements StatefulComponent
{
    public function __invoke(StatefulContext $ctx): Renderable
    {
        $ui = $ctx->ui;

        $formation = $ctx->signal('phalanx', key: 'formation.type');
        $morale = $ctx->signal(85, key: 'morale.level');
        $battleCry = $ctx->signal('Molōn labe!', key: 'battle.cry');

        $strength = $ctx->computed(
            static fn(): string => match (true) {
                $morale->value >= 90 => 'overwhelming',
                $morale->value >= 70 => 'strong',
                $morale->value >= 50 => 'moderate',
                default => 'minimal',
            },
            key: 'force.strength',
        );

        $readiness = $ctx->computed(
            static fn(): string => sprintf(
                '%s (%d%%)',
                $morale->value >= 80 ? 'battle-ready' : ($morale->value >= 50 ? 'prepared' : 'wavering'),
                $morale->value,
            ),
            key: 'force.readiness',
        );

        $campaign = $ctx->lens(CampaignSlice::class)->value;

        $moraleColor = match (true) {
            $morale->value >= 80 => Color::brightGreen(),
            $morale->value >= 50 => Color::brightYellow(),
            default => Color::brightRed(),
        };

        $headerLine = Line::from(
            Span::styled(
                ' OLYMPUS COMMAND ',
                TextStyle::new()->fg(Color::black())->bg(Color::brightCyan())->bold(),
            ),
            Span::styled(' DevTools Inspection Demo', TextStyle::new()->fg(Color::indexed(245))),
        );

        $statsPanel = $ui->panel(
            'Force Status',
            $ui->column(
                self::stat($ui, 'Hoplites', (string) $campaign->hoplites, Color::brightWhite()),
                self::stat($ui, 'Formation', $formation->value, Color::brightYellow()),
                self::stat($ui, 'Morale', "{$morale->value}%", $moraleColor),
                self::stat($ui, 'Strength', $strength->value, Color::brightCyan()),
                self::stat($ui, 'Readiness', $readiness->value, Color::brightGreen()),
                self::stat($ui, 'Cry', $battleCry->value, Color::indexed(208)),
            ),
            style: Style::of(
                size: Size::fill(),
                border: Border::Rounded,
                color: Color::brightCyan(),
            ),
        );

        $storePanel = $ui->panel(
            'Campaign',
            $ui->column(
                self::stat($ui, 'Phase', $campaign->phase, Color::brightYellow()),
                self::stat($ui, 'Dispatches', (string) $campaign->dispatches, Color::brightWhite()),
                $ui->text('', Style::of(size: Size::fixed(1))),
                $ui->text(Line::from(
                    Span::styled(
                        ' 3 signals, 2 computed, 1 store slice.',
                        TextStyle::new()->fg(Color::indexed(242)),
                    ),
                )),
                $ui->text(Line::from(
                    Span::styled(
                        ' Open DevTools to inspect reactive state.',
                        TextStyle::new()->fg(Color::indexed(242)),
                    ),
                )),
            ),
            style: Style::of(
                size: Size::fill(),
                border: Border::Rounded,
                color: Color::brightYellow(),
            ),
        );

        $helpLine = Line::from(
            Span::styled(' F12', TextStyle::new()->fg(Color::brightCyan())),
            Span::styled(':overlay ', TextStyle::new()->fg(Color::indexed(242))),
            Span::styled(' Ctrl+D', TextStyle::new()->fg(Color::brightCyan())),
            Span::styled(':dock ', TextStyle::new()->fg(Color::indexed(242))),
            Span::styled(' 1/2/3', TextStyle::new()->fg(Color::brightCyan())),
            Span::styled(':tab (overlay) ', TextStyle::new()->fg(Color::indexed(242))),
            Span::styled(' q/Esc', TextStyle::new()->fg(Color::brightCyan())),
            Span::styled(':quit', TextStyle::new()->fg(Color::indexed(242))),
        );

        return $ui->column(
            $ui->text($headerLine),
            $ui->text('', Style::of(size: Size::fixed(1))),
            $ui->row($statsPanel, $storePanel),
            $ui->text('', Style::of(size: Size::fixed(1))),
            $ui->text($helpLine),
        );
    }

    private static function stat(
        Ui $ui,
        string $name,
        string $value,
        Color $color,
    ): Renderable {
        return $ui->text(Line::from(
            Span::styled(" {$name}: ", TextStyle::new()->fg(Color::indexed(245))),
            Span::styled($value, TextStyle::new()->fg($color)->bold()),
        ));
    }
}

exit(Archon::command('devtools-inspection', static function (CommandContext $ctx): int {
    $app = (new TheatronBuilder(new AppContext()))
        ->root(new OlympusCommand())
        ->store(Store::concurrent('demo', CampaignSlice::class))
        ->initialState(new CampaignSlice())
        ->devtools(DockPosition::Bottom, height: 8)
        ->build();

    $app->run($ctx);

    return 0;
}, new CommandConfig(options: [
    Opt::value('frames', desc: 'Run N frames then exit'),
]))->default('devtools-inspection')->run(array_slice($_SERVER['argv'] ?? [], 1)));
