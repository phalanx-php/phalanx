<?php

declare(strict_types=1);

namespace Sentinel;

use Phalanx\Archon\CommandContext;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\ExecutionScope;
use Phalanx\Grammata\Files;
use Phalanx\Styx\Channel;
use Phalanx\Task\Executable;
use Phalanx\Task\Task;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Layout\Constraint;
use Phalanx\Theatron\Layout\Layout;
use Phalanx\Theatron\Region\RegionConfig;
use Phalanx\Theatron\Style\Palette;
use Phalanx\Theatron\Style\Style;
use Phalanx\Theatron\Surface\ScreenMode;
use Phalanx\Theatron\Surface\Surface;
use Phalanx\Theatron\Surface\SurfaceConfig;
use Phalanx\Theatron\Terminal\Terminal;
use Phalanx\Theatron\Terminal\TerminalConfig;
use Phalanx\Theatron\Widget\Box;
use Phalanx\Theatron\Widget\BoxStyle;
use Phalanx\Theatron\Widget\InputLine;
use Phalanx\Theatron\Widget\ScrollableText;
use Phalanx\Theatron\Widget\StatusBar;
use Sentinel\Agent\Dossier;
use Sentinel\Agent\ReviewAgent;
use Sentinel\Render\ConsoleRenderer;
use Sentinel\Render\TuiRenderer;
use Sentinel\Watcher\FileChange;
use Sentinel\Watcher\ProjectWatcher;

final class SentinelTuiCommand implements Executable
{
    private const array AGENT_COLORS = ['blue', 'magenta', 'cyan', 'green', 'yellow', 'red'];

    public function __invoke(ExecutionScope $scope): int
    {
        assert($scope instanceof CommandContext);

        $config = $scope->service(SentinelConfig::class);
        $consoleRenderer = $scope->service(ConsoleRenderer::class);

        if (($earlyExit = self::handleListings($scope, $config, $consoleRenderer)) !== null) {
            return $earlyExit;
        }

        // Persona selection runs in cooked-mode BEFORE Surface takes STDIN.
        $agents = self::loadAgents(
            $config->dossierDir,
            self::resolvePersonas(
                $scope->options->get('preset'),
                $scope->options->get('persona'),
                $config->dossierDir,
                $consoleRenderer,
            ),
        );

        if ($agents === []) {
            $consoleRenderer->error("No personas matched in {$config->dossierDir}");
            return 1;
        }

        $projectRoot = $scope->args->get('project') ?? $config->projectRoot;
        $bus         = $scope->service(SwarmBus::class);
        $swarmConfig = $scope->service(SwarmConfig::class);
        $files       = $scope->service(Files::class);

        $termConfig = self::detectTerminalConfig($scope);
        $surface    = new Surface(new SurfaceConfig($termConfig, mode: ScreenMode::Alternate));
        $statusBar  = new StatusBar(Style::new()->bg('blue')->fg('bright-white'));
        $inputLine  = new InputLine(prompt: '> ', style: Palette::muted());

        $tuiRenderer = new TuiRenderer($surface, $statusBar);
        $agentPanels = self::wireAgentPanels($tuiRenderer, $agents);

        self::createLayout($surface, $agents, $termConfig);

        $coordinator = new Coordinator($agents, $tuiRenderer, $projectRoot, $files, $bus, $swarmConfig);

        self::printBootInfo($tuiRenderer, $agents, $swarmConfig, $projectRoot);

        $humanInput = new Channel(bufferSize: 32);

        self::wireSurfaceCallbacks($surface, $statusBar, $inputLine, $humanInput, $agents, $agentPanels);

        // Surface.start() registers the render timer and STDIN reader on the
        // event loop BEFORE scope->concurrent() blocks the current fiber.
        $surface->start();

        // Surface.stop() MUST run even if concurrent() throws — raw mode left
        // enabled corrupts the terminal. try/finally guarantees cleanup.
        try {
            $scope->concurrent(self::buildLifecycleTasks(
                $coordinator,
                $tuiRenderer,
                $surface,
                $bus,
                $swarmConfig,
                $humanInput,
                $projectRoot,
                $config->debounce,
            ));
        } finally {
            $surface->stop();
        }

        return 0;
    }

    private static function handleListings(CommandContext $ctx, SentinelConfig $config, ConsoleRenderer $renderer): ?int
    {
        $options = $ctx->options;

        if ($options->flag('list-presets')) {
            PersonaPreset::printAll($renderer);
            return 0;
        }

        if ($options->flag('list-personas')) {
            PersonaSelector::printAvailable($config->dossierDir, $renderer);
            return 0;
        }

        return null;
    }

    private static function detectTerminalConfig(CommandContext $ctx): TerminalConfig
    {
        // Terminal detection from $context, never getenv().
        return Terminal::detect([
            'COLUMNS'   => $ctx->attribute('COLUMNS'),
            'LINES'     => $ctx->attribute('LINES'),
            'COLORTERM' => $ctx->attribute('COLORTERM'),
            'TERM'      => $ctx->attribute('TERM'),
            'NO_COLOR'  => $ctx->attribute('NO_COLOR'),
            'CI'        => $ctx->attribute('CI'),
        ]);
    }

    /**
     * @param list<ReviewAgent> $agents
     * @return array<string, ScrollableText>
     */
    private static function wireAgentPanels(TuiRenderer $renderer, array $agents): array
    {
        $panels = [];
        foreach ($agents as $agent) {
            $panel = new ScrollableText();
            $panels[$agent->name()] = $panel;
            $renderer->registerAgentPanel($agent->name(), $agent->color(), $panel);
        }

        return $panels;
    }

    /**
     * @param list<ReviewAgent> $agents
     */
    private static function printBootInfo(TuiRenderer $renderer, array $agents, SwarmConfig $swarmConfig, string $projectRoot): void
    {
        $renderer->banner();
        foreach ($agents as $agent) {
            $renderer->agentRegistered($agent->glyph(), $agent->color());
        }
        $renderer->info("daemon8 session: {$swarmConfig->session} (workspace: {$swarmConfig->workspace})");
        $renderer->watchingDirectory($projectRoot);
        $renderer->ready();
    }

    /**
     * @param list<ReviewAgent> $agents
     * @param array<string, ScrollableText> $agentPanels
     */
    private static function wireSurfaceCallbacks(
        Surface $surface,
        StatusBar $statusBar,
        InputLine $inputLine,
        Channel $humanInput,
        array $agents,
        array $agentPanels,
    ): void {
        $surface->onDraw(static function (Surface $s) use ($statusBar, $inputLine, $agents, $agentPanels): void {
            $statusRegion = $s->getRegion('status');
            if ($statusRegion !== null && $statusRegion->isDirty) {
                $statusRegion->draw($statusBar);
            }

            foreach ($agents as $agent) {
                $region = $s->getRegion("agent-{$agent->name()}");
                $panel  = $agentPanels[$agent->name()] ?? null;
                if ($region !== null && $panel !== null && $region->isDirty) {
                    $box = new Box($panel, BoxStyle::Rounded, $agent->glyph(), Style::new()->fg($agent->color()));
                    $region->draw($box);
                }
            }

            $inputRegion = $s->getRegion('input');
            if ($inputRegion !== null && $inputRegion->isDirty) {
                $inputBox = new Box($inputLine, BoxStyle::Single, null, Palette::muted());
                $inputRegion->draw($inputBox);
            }
        });

        // Surface message handlers run on the event loop, not in a fiber. Submitted
        // lines are pushed onto $humanInput; a Task::of consumer drains the channel
        // and dispatches to the Coordinator inside fiber context.
        $surface->onMessage(KeyEvent::class, static function (KeyEvent $msg, Surface $s) use ($inputLine, $humanInput): void {
            if ($msg->ctrl && $msg->is('c')) {
                $s->stop();
                return;
            }

            $submitted = $inputLine->handleKey($msg);
            $s->getRegion('input')?->invalidate();

            if ($submitted !== null && $submitted !== '') {
                $humanInput->tryEmit($submitted);
            }
        });

        $surface->onResize(static function (int $w, int $h) use ($surface, $agents): void {
            self::recreateLayout($surface, $agents, new TerminalConfig($w, $h));
        });
    }

    /**
     * @return array<string, Task>
     */
    private static function buildLifecycleTasks(
        Coordinator $coordinator,
        TuiRenderer $renderer,
        Surface $surface,
        SwarmBus $bus,
        SwarmConfig $swarmConfig,
        Channel $humanInput,
        string $projectRoot,
        float $debounce,
    ): array {
        $fileChanges = ProjectWatcher::watch($projectRoot, $debounce);

        return [
            'watcher' => Task::of(
                static function (ExecutionScope $s) use ($fileChanges, $coordinator, $renderer): void {
                    foreach ($fileChanges($s) as $batch) {
                        /** @var list<FileChange> $batch */
                        try {
                            $coordinator->reviewChanges($batch, $s);
                        } catch (\Throwable $e) {
                            $renderer->error($e->getMessage());
                        }
                    }
                }
            ),

            'input' => Task::of(
                static function (ExecutionScope $s) use ($humanInput, $coordinator, $renderer, $surface): void {
                    foreach ($humanInput->consume() as $line) {
                        if ($line === 'exit' || $line === 'quit') {
                            $surface->stop();
                            return;
                        }

                        if ($coordinator->isBusy()) {
                            $renderer->info('Agents are busy, please wait...');
                            continue;
                        }

                        try {
                            $coordinator->humanMessage($line, $s);
                        } catch (\Throwable $e) {
                            $renderer->error('Agent error: ' . $e->getMessage());
                        }
                    }
                }
            ),

            'daemon' => Task::of(
                static function (ExecutionScope $s) use ($bus, $swarmConfig, $coordinator, $renderer): void {
                    try {
                        $events = $bus->subscribe(['workspace' => $swarmConfig->workspace]);
                        foreach ($events($s) as $event) {
                            if ($event instanceof SwarmEvent) {
                                $coordinator->recordExternalEvent($event);
                            }
                        }
                    } catch (\Throwable $e) {
                        if (self::isConnectionRefused($e)) {
                            $renderer->info('daemon8 unreachable -- cross-session coordination disabled');
                            return;
                        }
                        $renderer->error('daemon8 subscription: ' . $e->getMessage());
                    }
                }
            ),
        ];
    }

    /**
     * @param list<ReviewAgent> $agents
     */
    private static function createLayout(
        Surface $surface,
        array $agents,
        TerminalConfig $termConfig,
    ): void {
        $w = $termConfig->width;
        $h = $termConfig->height;

        // Vertical: status(1) | pad(1) | agents(fill) | input(3)
        $vRects = Layout::vertical(
            Rect::sized($w, $h),
            Constraint::length(1),
            Constraint::length(1),
            Constraint::fill(),
            Constraint::length(3),
        );

        $surface->region('status', $vRects[0], new RegionConfig(tickRate: 10.0));
        $surface->region('input', $vRects[3]);

        self::createAgentGrid($surface, $agents, $vRects[2]);
    }

    /**
     * @param list<ReviewAgent> $agents
     */
    private static function recreateLayout(Surface $surface, array $agents, TerminalConfig $termConfig): void
    {
        $w = $termConfig->width;
        $h = $termConfig->height;

        $vRects = Layout::vertical(
            Rect::sized($w, $h),
            Constraint::length(1),
            Constraint::length(1),
            Constraint::fill(),
            Constraint::length(3),
        );

        $surface->getRegion('status')?->resize($vRects[0]);
        $surface->getRegion('input')?->resize($vRects[3]);

        self::resizeAgentGrid($surface, $agents, $vRects[2]);
    }

    /**
     * @param list<ReviewAgent> $agents
     */
    private static function createAgentGrid(Surface $surface, array $agents, Rect $area): void
    {
        $rects = self::calculateAgentRects(count($agents), $area);

        foreach ($agents as $i => $agent) {
            if (isset($rects[$i])) {
                $surface->region("agent-{$agent->name()}", $rects[$i]);
            }
        }
    }

    /**
     * @param list<ReviewAgent> $agents
     */
    private static function resizeAgentGrid(Surface $surface, array $agents, Rect $area): void
    {
        $rects = self::calculateAgentRects(count($agents), $area);

        foreach ($agents as $i => $agent) {
            if (isset($rects[$i])) {
                $surface->getRegion("agent-{$agent->name()}")?->resize($rects[$i]);
            }
        }
    }

    /** @return list<Rect> */
    private static function calculateAgentRects(int $count, Rect $area): array
    {
        if ($count === 0) {
            return [];
        }

        [$cols, $rows] = match (true) {
            $count <= 1 => [1, 1],
            $count <= 2 => [2, 1],
            $count <= 4 => [2, 2],
            $count <= 6 => [3, 2],
            default     => [3, 3],
        };

        $rowConstraints = array_fill(0, $rows, Constraint::fill());
        $rowRects = Layout::vertical($area, ...$rowConstraints);

        $rects = [];
        $agentIndex = 0;

        foreach ($rowRects as $rowRect) {
            $agentsInRow = min($cols, $count - $agentIndex);
            $colConstraints = array_fill(0, $agentsInRow, Constraint::fill());
            $colRects = Layout::horizontal($rowRect, ...$colConstraints);

            foreach ($colRects as $colRect) {
                if ($agentIndex < $count) {
                    $rects[] = $colRect;
                    $agentIndex++;
                }
            }
        }

        return $rects;
    }

    /** @return list<string>|null */
    private static function resolvePersonas(?string $preset, ?string $personaCsv, string $dossierDir, ConsoleRenderer $renderer): ?array
    {
        if ($preset !== null) {
            $names = PersonaPreset::get($preset);
            if ($names === null) {
                $renderer->error("Unknown preset: {$preset}");
                PersonaPreset::printAll($renderer);
                return PersonaPreset::get('full');
            }
            return $names;
        }

        if ($personaCsv !== null) {
            return array_map('trim', explode(',', $personaCsv));
        }

        return PersonaSelector::interactive($dossierDir, $renderer);
    }

    /**
     * @param list<string>|null $filter
     * @return list<ReviewAgent>
     */
    private static function loadAgents(string $dossierDir, ?array $filter): array
    {
        $agents = [];
        $colorIndex = 0;

        $files = glob(rtrim($dossierDir, '/') . '/*.md');

        if ($files === false || $files === []) {
            return [];
        }

        sort($files);

        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);

            if ($filter !== null && !in_array($filename, $filter, true)) {
                continue;
            }

            $color = self::AGENT_COLORS[$colorIndex % count(self::AGENT_COLORS)];
            $dossier = Dossier::fromFile($file, $color);
            $agents[] = new ReviewAgent($dossier);
            $colorIndex++;
        }

        return $agents;
    }

    private static function isConnectionRefused(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $message = $current->getMessage();
            if (str_contains($message, 'ECONNREFUSED') || str_contains($message, 'Connection refused')) {
                return true;
            }
        }

        return false;
    }
}
