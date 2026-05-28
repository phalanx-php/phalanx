<?php

declare(strict_types=1);

namespace Sentinel;

use Phalanx\Archon\CommandContext;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\ExecutionScope;
use Phalanx\Grammata\Files;
use Phalanx\Task\Executable;
use Phalanx\Task\Task;
use Sentinel\Agent\Dossier;
use Sentinel\Agent\ReviewAgent;
use Sentinel\Render\ConsoleRenderer;
use Sentinel\Watcher\FileChange;
use Sentinel\Watcher\ProjectWatcher;

final class SentinelCommand implements Executable
{
    private const array AGENT_COLORS = ['blue', 'magenta', 'cyan', 'green', 'yellow', 'red'];

    public function __invoke(ExecutionScope $scope): int
    {
        assert($scope instanceof CommandContext);

        $config = $scope->service(SentinelConfig::class);
        $renderer = $scope->service(ConsoleRenderer::class);

        if (($earlyExit = self::handleListings($scope, $config, $renderer)) !== null) {
            return $earlyExit;
        }

        $renderer->banner();

        $projectRoot = self::resolveProjectRoot($scope, $config);
        $agents = self::loadAgents(
            $config->dossierDir,
            $renderer,
            self::resolvePersonas(
                $scope->options->get('preset'),
                $scope->options->get('persona'),
                $config->dossierDir,
                $renderer,
            ),
        );

        if ($agents === []) {
            $renderer->error("No personas matched in {$config->dossierDir}");
            return 1;
        }

        $bus         = $scope->service(SwarmBus::class);
        $swarmConfig = $scope->service(SwarmConfig::class);
        $files       = $scope->service(Files::class);

        $renderer->info("daemon8 session: {$swarmConfig->session} (workspace: {$swarmConfig->workspace})");
        $renderer->info("Custom personas: {$config->dossierDir}/");
        $renderer->watchingDirectory($projectRoot);

        $coordinator = new Coordinator($agents, $renderer, $projectRoot, $files, $bus, $swarmConfig);

        $renderer->ready();

        $scope->concurrent(self::buildLifecycleTasks($coordinator, $renderer, $bus, $swarmConfig, $projectRoot, $config->debounce));

        $renderer->shutdown();

        return 0;
    }

    private static function handleListings(CommandContext $ctx, SentinelConfig $config, ConsoleRenderer $renderer): ?int
    {
        $options = $ctx->options;

        if ($options->flag('help')) {
            self::printHelp($renderer);
            return 0;
        }

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

    private static function resolveProjectRoot(CommandContext $ctx, SentinelConfig $config): string
    {
        $requested = $ctx->args->get('project') ?? $config->projectRoot;

        return realpath($requested) ?: $requested;
    }

    /**
     * @return array<string, Task>
     */
    private static function buildLifecycleTasks(
        Coordinator $coordinator,
        ConsoleRenderer $renderer,
        SwarmBus $bus,
        SwarmConfig $swarmConfig,
        string $projectRoot,
        float $debounce,
    ): array {
        $fileChanges = ProjectWatcher::watch($projectRoot, $debounce);
        $humanInput  = RawInputReader::lines();

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

            'stdin' => Task::of(
                static function (ExecutionScope $s) use ($humanInput, $coordinator, $renderer): void {
                    foreach ($humanInput($s) as $line) {
                        if ($line === 'exit' || $line === 'quit') {
                            $s->dispose();
                            return;
                        }

                        if ($line === 'status') {
                            $renderer->status();
                            continue;
                        }

                        try {
                            $coordinator->humanMessage($line, $s);
                        } catch (\Throwable $e) {
                            $renderer->error($e->getMessage());
                        }
                    }

                    $s->dispose();
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
     * @return list<string>|null persona filenames to load, null for all
     */
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
     * @param list<string>|null $filter persona filenames (without .md), null for all
     * @return list<ReviewAgent>
     */
    private static function loadAgents(string $dossierDir, ConsoleRenderer $renderer, ?array $filter): array
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
            $agent = new ReviewAgent($dossier);

            $renderer->agentRegistered($agent->glyph(), $color);

            $agents[] = $agent;
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

    private static function printHelp(ConsoleRenderer $renderer): void
    {
        $renderer->banner();

        echo <<<'HELP'
Usage:
  php bin/sentinel.php [project] [options]

Examples:
  php bin/sentinel.php                              Watch cwd, pick personas interactively
  php bin/sentinel.php ./myapp --preset php         Watch myapp with PHP-focused reviewers
  php bin/sentinel.php . --preset core              Architect + security + performance agents
  php bin/sentinel.php . --persona architect,security
                                                    Cherry-pick specific personas
  php bin/sentinel.php --list-presets               Show preset groups and their personas
  php bin/sentinel.php --list-personas              Show all persona files in dossier dir

Options:
  -p, --preset=<name>    Persona preset: php, react-native, tv, core, full
  --persona=<names>      Comma-separated persona names (e.g. architect,security)
  -l, --list-presets     List available presets and their personas
  --list-personas        List all available persona files
  -h, --help             Show this help

Interactive commands (during a session):
  status                 Show active agents and review stats
  exit / quit            Stop watching and shut down
  <any text>             Send a message to all agents as supervisor input

Environment (.env):
  ANTHROPIC_API_KEY      Claude API key (required)
  ANTHROPIC_MODEL        Model name (default: claude-haiku-4-5-20251001)
  DAEMON8_URL            daemon8 ingest URL (default: http://localhost:8888)
  SWARM_WORKSPACE        Logical workspace for cross-session coordination (default: sentinel)
  SWARM_SESSION          Session id (default: sentinel-<random>)
  SENTINEL_DEBOUNCE      Change debounce in seconds (default: 0.5)
  SENTINEL_DOSSIER_DIR   Persona directory (default: personas/)
  SENTINEL_ERROR_LOG     Error log path (default: <project>/sentinel-error.log)

HELP;
    }
}
