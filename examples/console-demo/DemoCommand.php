<?php

declare(strict_types=1);

namespace Phalanx\Console\Demo;

use Phalanx\Console\Composite\ConcurrentTaskList;
use Phalanx\Console\Composite\Form;
use Phalanx\Console\Input\ConfirmInput;
use Phalanx\Console\Input\MultiSelectInput;
use Phalanx\Console\Input\NumberInput;
use Phalanx\Console\Input\PasswordInput;
use Phalanx\Console\Input\RawInput;
use Phalanx\Console\Input\SearchInput;
use Phalanx\Console\Input\SelectInput;
use Phalanx\Console\Input\SuggestInput;
use Phalanx\Console\Input\TextInput;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Console\Style\Style;
use Phalanx\Console\Style\Theme;
use Phalanx\Console\Widget\Badge;
use Phalanx\Console\Widget\Box;
use Phalanx\Console\Widget\BoxStyle;
use Phalanx\Console\Widget\Divider;
use Phalanx\Console\Widget\KeyValue;
use Phalanx\Console\Widget\ProgressBar;
use Phalanx\Console\Widget\Spinner;
use Phalanx\Console\Widget\Table;
use Phalanx\Console\Widget\TaskList;
use Phalanx\Console\Widget\TaskState;
use Phalanx\Console\Widget\Tree;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function React\Promise\Timer\sleep as asyncSleep;

final class DemoCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        $output = $scope->service(StreamOutput::class);
        $theme  = $scope->service(Theme::class);
        $input  = RawInput::fromStdin();

        $input->enable();
        $input->attach();

        try {
            self::demo($scope, $output, $theme, $input);
        } finally {
            $input->detach();
            $input->disable();
        }

        return 0;
    }

    private static function demo(
        ExecutionScope $scope,
        StreamOutput $output,
        Theme $theme,
        RawInput $input,
    ): void {
        $w = $output->width();

        // ── Welcome ──────────────────────────────────────────────────────────
        $output->persist(Box::render(
            content: $theme->muted->apply('  Interactive showcase of every phalanx/console primitive.')
                . "\n  Arrow keys · Enter · Ctrl+C to quit · Ctrl+U to go back (in forms)",
            title: $theme->accent->apply('phalanx/console  demo'),
            style: BoxStyle::Rounded,
            borderStyle: $theme->accent,
            width: min($w - 2, 70),
        ));

        // ── Style System ─────────────────────────────────────────────────────
        $output->persist(Divider::render($w, $theme, 'Style System'));

        $output->persist(
            '  ' . Style::new()->bold()->apply('bold')
            . '  ' . Style::new()->dim()->apply('dim')
            . '  ' . Style::new()->italic()->apply('italic')
            . '  ' . Style::new()->underline()->apply('underline')
            . '  ' . Style::new()->inverse()->apply('inverse')
            . '  ' . Style::new()->strike()->apply('strike'),
        );

        $output->persist(
            '  ' . Style::new()->fg('red')->apply('red')
            . '  ' . Style::new()->fg('green')->apply('green')
            . '  ' . Style::new()->fg('yellow')->apply('yellow')
            . '  ' . Style::new()->fg('blue')->apply('blue')
            . '  ' . Style::new()->fg('magenta')->apply('magenta')
            . '  ' . Style::new()->fg('cyan')->apply('cyan')
            . '  ' . Style::new()->fg([255, 140, 0])->apply('rgb(255,140,0)')
            . '  ' . Style::new()->fg(208)->apply('256-color #208'),
        );

        // ── Widgets ──────────────────────────────────────────────────────────
        $output->persist(Divider::render($w, $theme, 'Widgets'));

        // Badge
        $output->persist(
            '  '
            . Badge::render('SUCCESS', $theme->success) . ' '
            . Badge::render('WARNING', $theme->warning) . ' '
            . Badge::render('ERROR',   $theme->error)   . ' '
            . Badge::render('INFO',    $theme->accent)  . ' '
            . Badge::render('MUTED',   $theme->muted),
        );

        // Divider
        $output->persist(Divider::render($w, $theme));
        $output->persist(Divider::render($w, $theme, 'Labeled Divider'));

        // KeyValue
        $output->persist(KeyValue::render([
            'PHP Version'    => '8.4.12',
            'Event Loop'     => 'ReactPHP',
            'Concurrency'    => 'Fibers',
            'Terminal Width' => "{$w} cols",
        ], $theme));

        // Box — all styles in one line
        $output->persist('');
        $output->persist(implode('  ', [
            Box::render("Rounded\nBox", '', BoxStyle::Rounded, $theme->accent,  16),
            Box::render("Single\nBox",  '', BoxStyle::Single,  $theme->border,  16),
            Box::render("Double\nBox",  '', BoxStyle::Double,  $theme->label,   16),
            Box::render("Heavy\nBox",   '', BoxStyle::Heavy,   $theme->error,   16),
        ]));

        // ProgressBar — animated
        $output->persist($theme->muted->apply('  ProgressBar'));
        $bar      = new ProgressBar($theme);
        $tick     = 0;
        $donePb   = new Deferred();
        $timerPb  = Loop::addPeriodicTimer(0.012, static function () use ($output, $bar, $w, &$tick, $donePb): void {
            $tick++;
            $output->update('  ' . $bar->render($tick, 80, $w - 6, 'Building'));
            if ($tick >= 80) {
                $donePb->resolve(null);
            }
        });
        $scope->await($donePb->promise());
        Loop::cancelTimer($timerPb);
        $output->persist('  ' . $bar->render(80, 80, $w - 6, 'Building') . '  ' . $theme->success->apply('done'));

        // Spinner — cycle through frame sets
        $output->persist($theme->muted->apply('  Spinner'));
        $sets      = [['frames' => Spinner::BRAILLE, 'label' => 'braille'], ['frames' => Spinner::DOTS, 'label' => 'dots'], ['frames' => Spinner::ARC, 'label' => 'arc']];
        $setIdx    = 0;
        $spinTick  = 0;
        $doneSp    = new Deferred();
        $timerSp   = Loop::addPeriodicTimer(0.08, static function () use ($output, $theme, $sets, &$setIdx, &$spinTick, $doneSp): void {
            $spinTick++;
            $current = $sets[$setIdx % 3];
            $output->update('  ' . (new Spinner($theme, $current['frames']))->frame($spinTick, $current['label']));
            if ($spinTick % 12 === 0) {
                $setIdx++;
            }
            if ($spinTick >= 36) {
                $doneSp->resolve(null);
            }
        });
        $scope->await($doneSp->promise());
        Loop::cancelTimer($timerSp);
        $output->clear();

        // Table — rows stream in
        $output->persist($theme->muted->apply('  Table'));
        $headers = ['Framework', 'Language', 'Stars', 'License'];
        $rows    = [
            ['ReactPHP',   'PHP',    '8.9k',   'MIT'],
            ['Swoole',     'PHP/C',  '18k',    'Apache 2'],
            ['Amp',        'PHP',    '3.9k',   'MIT'],
            ['Revolt PHP', 'PHP',    '0.8k',   'MIT'],
            ['Phalanx',    'PHP',    'new',    'MIT'],
        ];
        $widths  = Table::computeWidths($headers, $rows, $w - 2);
        $table   = new Table($theme);
        $output->persist($table->header($headers, $widths));
        foreach ($rows as $row) {
            $scope->await(asyncSleep(0.07));
            $output->persist($table->row($row, $widths));
        }
        $output->persist($table->footer($widths, count($rows) . ' async PHP frameworks'));

        // Tree
        $output->persist($theme->muted->apply('  Tree'));
        $output->persist(Tree::render([
            'phalanx-core'    => ['ExecutionScope', 'Task', 'Service', 'Concurrency'],
            'phalanx-console' => [
                'Output'    => ['StreamOutput'],
                'Widget'    => ['Box', 'Table', 'Tree', 'ProgressBar', 'Spinner', 'TaskList'],
                'Input'     => ['TextInput', 'SelectInput', 'MultiSelectInput', 'SearchInput'],
                'Composite' => ['Form', 'Accordion', 'ConcurrentTaskList'],
            ],
            'phalanx-http'    => ['Route', 'RouteGroup', 'HttpRunner'],
            'phalanx-stream'  => ['Emitter', 'Channel', 'StreamContext'],
        ], $theme));

        // TaskList — animated state transitions
        $output->persist($theme->muted->apply('  TaskList'));
        $taskList = new TaskList($theme);
        $taskList->add('deps',    'Resolve dependencies');
        $taskList->add('compile', 'Compile assets');
        $taskList->add('test',    'Run test suite');
        $taskList->add('deploy',  'Push to staging');

        $phase       = 0;
        $tlTick      = 0;
        $doneTl      = new Deferred();
        $timerTl     = Loop::addPeriodicTimer(0.08, static function () use ($taskList, $output, &$phase, &$tlTick, $doneTl): void {
            $tlTick++;
            match (true) {
                $phase === 0  => $taskList->setState('deps', TaskState::Running),
                $phase === 7  => $taskList->setState('deps', TaskState::Success),
                $phase === 8  => $taskList->setState('compile', TaskState::Running),
                $phase === 15 => $taskList->setState('compile', TaskState::Success),
                $phase === 16 => $taskList->setState('test', TaskState::Running),
                $phase === 23 => $taskList->setState('test', TaskState::Success),
                $phase === 24 => $taskList->setState('deploy', TaskState::Running),
                $phase === 31 => $taskList->setState('deploy', TaskState::Success),
                $phase === 32 => $doneTl->resolve(null),
                default       => null,
            };
            $phase++;
            $output->update($taskList->render($tlTick));
        });
        $scope->await($doneTl->promise());
        Loop::cancelTimer($timerTl);
        $output->persist($taskList->render($tlTick));

        // ── Interactive Inputs ────────────────────────────────────────────────
        $output->persist(Divider::render($w, $theme, 'Inputs'));
        $output->persist($theme->muted->apply('  Type your answers. Ctrl+C cancels · Ctrl+U goes back (forms only).'));

        $name = $scope->await(
            (new TextInput(theme: $theme, label: 'Your name', placeholder: 'e.g. Ada Lovelace'))
                ->prompt($output, $input),
        );

        $_ = $scope->await(
            (new PasswordInput(theme: $theme, label: 'Password', hint: 'min 8 characters',
                validate: static fn(string $v): ?string => mb_strlen($v) < 8 ? 'Too short (min 8 chars)' : null))
                ->prompt($output, $input),
        );

        $age = $scope->await(
            (new NumberInput(theme: $theme, label: 'Your age', min: 1, max: 120, default: 25))
                ->prompt($output, $input),
        );

        $darkMode = $scope->await(
            (new ConfirmInput(theme: $theme, label: 'Enable dark mode?', default: true))
                ->prompt($output, $input),
        );

        // ── Selection ────────────────────────────────────────────────────────
        $output->persist(Divider::render($w, $theme, 'Selection'));

        $language = $scope->await(
            (new SelectInput(theme: $theme, label: 'Favorite language', options: [
                'php'    => 'PHP',
                'rust'   => 'Rust',
                'go'     => 'Go',
                'elixir' => 'Elixir',
                'haskell' => 'Haskell',
                'kotlin' => 'Kotlin',
                'swift'  => 'Swift',
            ], scroll: 5))
                ->prompt($output, $input),
        );

        $tools = $scope->await(
            (new MultiSelectInput(theme: $theme, label: 'Tools you use daily', options: [
                'phpstan'  => 'PHPStan',
                'rector'   => 'Rector',
                'pest'     => 'Pest',
                'phpunit'  => 'PHPUnit',
                'psalm'    => 'Psalm',
                'cs-fixer' => 'PHP-CS-Fixer',
            ], scroll: 5))
                ->prompt($output, $input),
        );

        // ── Search Inputs ─────────────────────────────────────────────────────
        $output->persist(Divider::render($w, $theme, 'Search Inputs'));

        $fwList = ['Laravel', 'Symfony', 'Slim', 'Laminas', 'Yii', 'CodeIgniter', 'CakePHP', 'Phalcon', 'Phalanx'];

        $framework = $scope->await(
            (new SearchInput(theme: $theme, label: 'Find a PHP framework',
                search: static function (string $q) use ($fwList): array {
                    return $q === '' ? $fwList : array_values(
                        array_filter($fwList, static fn(string $f) => stripos($f, $q) !== false),
                    );
                }))
                ->prompt($output, $input),
        );

        $colorList = ['Red', 'Green', 'Blue', 'Cyan', 'Magenta', 'Yellow', 'Orange', 'Purple', 'Teal', 'Indigo'];

        $color = $scope->await(
            (new SuggestInput(theme: $theme, label: 'Favorite color', hint: 'Tab to accept suggestion',
                search: static function (string $q) use ($colorList): array {
                    return $q === '' ? [] : array_values(
                        array_filter($colorList, static fn(string $c) => stripos($c, $q) !== false),
                    );
                }))
                ->prompt($output, $input),
        );

        // ── Composite — Form ──────────────────────────────────────────────────
        $output->persist(Divider::render($w, $theme, 'Form (multi-step, Ctrl+U goes back)'));

        $profile = $scope->await(
            (new Form())
                ->text('username', static fn() => new TextInput(
                    theme: $theme,
                    label: 'Username',
                    placeholder: 'lowercase letters/numbers/underscores',
                    validate: static fn(string $v): ?string => preg_match('/^[a-z0-9_]{3,}$/', $v) ? null
                        : 'Lowercase letters, numbers, underscores — min 3 chars',
                ))
                ->text('email', static fn() => new TextInput(
                    theme: $theme,
                    label: 'Email address',
                    placeholder: 'you@example.com',
                    validate: static fn(string $v): ?string => str_contains($v, '@') ? null : 'Must contain @',
                ))
                ->select('role', static fn() => new SelectInput(
                    theme: $theme,
                    label: 'Role',
                    options: [
                        'dev'  => 'Developer',
                        'ops'  => 'DevOps Engineer',
                        'lead' => 'Tech Lead',
                        'arch' => 'Architect',
                    ],
                ))
                ->confirm('terms', static fn() => new ConfirmInput(
                    theme: $theme,
                    label: 'Accept terms of service?',
                    default: false,
                ))
                ->submit($output, $input),
        );

        // ── Composite — ConcurrentTaskList ────────────────────────────────────
        $output->persist(Divider::render($w, $theme, 'ConcurrentTaskList'));

        (new ConcurrentTaskList($scope, $output, $theme))
            ->add('deps',    'Resolve dependencies',  self::fakeWork(0.4))
            ->add('compile', 'Compile TypeScript',     self::fakeWork(0.7))
            ->add('lint',    'Run linter',             self::fakeWork(0.3))
            ->add('test',    'Run test suite',         self::fakeWork(0.9))
            ->add('build',   'Build Docker image',     self::fakeWork(1.1))
            ->add('push',    'Push to registry',       self::fakeWork(0.5))
            ->run();

        // ── Summary ───────────────────────────────────────────────────────────
        $output->persist(Divider::render($w, $theme, 'Summary'));
        $output->persist(KeyValue::render([
            'Name'      => (string) $name,
            'Age'       => (string) $age,
            'Dark mode' => $darkMode ? 'yes' : 'no',
            'Language'  => (string) $language,
            'Tools'     => $tools !== [] ? implode(', ', (array) $tools) : $theme->muted->apply('none'),
            'Framework' => (string) $framework,
            'Color'     => (string) $color,
            'Username'  => (string) ($profile['username'] ?? ''),
            'Email'     => (string) ($profile['email'] ?? ''),
            'Role'      => (string) ($profile['role'] ?? ''),
            'Terms'     => ($profile['terms'] ?? false) ? 'accepted' : 'declined',
        ], $theme));

        $output->persist('');
        $output->persist(Box::render(
            '  ' . $theme->success->apply('All 12 components exercised.'),
            title: $theme->accent->apply('demo complete'),
            style: BoxStyle::Rounded,
            borderStyle: $theme->success,
            width: min($w - 2, 50),
        ));
    }

    private static function fakeWork(float $seconds): Executable
    {
        return new class($seconds) implements Executable {
            public function __construct(private readonly float $seconds) {}

            public function __invoke(ExecutionScope $scope): mixed
            {
                $scope->await(asyncSleep($this->seconds));
                return null;
            }
        };
    }
}
