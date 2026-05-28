<?php

declare(strict_types=1);

namespace Sentinel;

use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\AgentResult;
use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Message\Message;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\Athena\Swarm\SwarmEventKind;
use Phalanx\Athena\Turn;
use Phalanx\ExecutionScope;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Grammata\Files;
use Phalanx\Task\Task;
use Sentinel\Agent\ReviewAgent;
use Sentinel\Render\ReviewRenderer;
use Sentinel\Watcher\ChangeKind;
use Sentinel\Watcher\FileChange;
use Sentinel\Watcher\RunCommand;

final class Coordinator
{
    /** Maximum number of buffered cross-session events surfaced as peer context. */
    private const int EXTERNAL_BUFFER_SIZE = 16;

    /** @var array<string, string> */
    private array $lastRoundFeedback = [];

    /** @var list<array{from: string, session: string, text: string}> */
    private array $externalBuffer = [];

    private int $reviewCount = 0;

    private bool $busy = false;

    private readonly string $projectContext;

    /**
     * @param list<ReviewAgent> $agents
     */
    public function __construct(
        private array $agents,
        private ReviewRenderer $renderer,
        private string $projectRoot,
        private Files $files,
        private ?SwarmBus $bus = null,
        private ?SwarmConfig $swarmConfig = null,
    ) {
        $this->projectContext = self::buildProjectContext($projectRoot);
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function recordExternalEvent(SwarmEvent $event): void
    {
        if ($this->swarmConfig !== null && $event->session === $this->swarmConfig->session) {
            return; // ignore our own events echoed back through the bus
        }

        $payload = $event->payload;
        $text = (string) ($payload['message'] ?? '');
        if ($text === '') {
            return;
        }

        $this->externalBuffer[] = [
            'from'    => $event->from,
            'session' => $event->session,
            'text'    => $text,
        ];

        if (count($this->externalBuffer) > self::EXTERNAL_BUFFER_SIZE) {
            $this->externalBuffer = array_slice($this->externalBuffer, -self::EXTERNAL_BUFFER_SIZE);
        }

        $this->renderer->externalMessage($event->from, $text);
    }

    public function externalMessage(string $from, string $text, ExecutionScope $scope): void
    {
        $this->busy = true;
        try {
            $this->renderer->externalMessage($from, $text);

            $enriched = "[EXTERNAL from {$from}]: {$text}";
            $tasks = $this->buildResponseTasks($enriched);
            $results = $scope->concurrent($tasks);

            foreach ($results as $run) {
                $feedback = trim($run->text);
                if ($feedback !== '') {
                    $this->renderer->agentFeedback($run->glyph, $run->color, $feedback);
                }
            }
        } finally {
            $this->busy = false;
        }
    }

    public function reviewChanges(array $changes, ExecutionScope $scope): void
    {
        $this->busy = true;
        $startTime = hrtime(true);
        try {
            $this->reviewCount++;
            $this->renderer->fileChanges($changes);

            $diffs = $this->computeDiffs($changes, $scope);
            $changeSummary = $this->formatChangeSummary($changes, $diffs);
            $tasks = $this->buildReviewTasks($changeSummary);

            /** @var array<string, AgentRunResult> $results */
            $results = $scope->concurrent($tasks);

            $triggerSummary = count($changes) . ' file(s) changed';
            $totalUsage = TokenUsage::zero();

            foreach ($results as $agentName => $run) {
                $text = trim($run->text);
                $totalUsage = $totalUsage->add($run->usage);

                if ($text === '') {
                    continue;
                }

                $this->renderer->agentFeedback($run->glyph, $run->color, $text);
                $this->lastRoundFeedback[$agentName] = $text;
                $this->broadcast($agentName, $text, $triggerSummary);
            }

            $elapsed = (hrtime(true) - $startTime) / 1e9;
            $this->renderer->reviewComplete($this->reviewCount, $elapsed, $totalUsage->total);
        } finally {
            $this->busy = false;
        }
    }

    public function humanMessage(string $message, ExecutionScope $scope): void
    {
        $this->busy = true;
        $startTime = hrtime(true);
        try {
            $this->renderer->humanMessage($message);

            $enriched = $this->enrichWithFileContents($message, $scope);
            $tasks = $this->buildResponseTasks($enriched);
            $results = $scope->concurrent($tasks);

            $this->reviewCount++;
            $totalUsage = TokenUsage::zero();

            foreach ($results as $run) {
                $text = trim($run->text);
                $totalUsage = $totalUsage->add($run->usage);

                if ($text === '') {
                    continue;
                }

                $this->renderer->agentFeedback($run->glyph, $run->color, $text);
            }

            $elapsed = (hrtime(true) - $startTime) / 1e9;
            $this->renderer->reviewComplete($this->reviewCount, $elapsed, $totalUsage->total);
        } finally {
            $this->busy = false;
        }
    }

    private function broadcast(string $agentName, string $feedback, string $trigger): void
    {
        if ($this->bus === null || $this->swarmConfig === null) {
            return;
        }

        $this->bus->emit(new SwarmEvent(
            from:      $agentName,
            kind:      SwarmEventKind::BlackboardPost,
            workspace: $this->swarmConfig->workspace,
            session:   $this->swarmConfig->session,
            payload:   [
                'message' => $feedback,
                'trigger' => $trigger,
                'project' => $this->projectRoot,
            ],
        ));
    }

    /**
     * @return array<string, Task>
     */
    private function buildReviewTasks(string $changeSummary): array
    {
        $tasks = [];

        foreach ($this->agents as $agent) {
            $agentName = $agent->name();
            $agentGlyph = $agent->glyph();
            $agentColor = $agent->color();
            $conversation = $this->conversationFor($agent);
            $peerContext = $this->buildPeerContext($agentName);

            $prompt = $peerContext !== ''
                ? $changeSummary . "\n\n--- Other agents' recent feedback (avoid repeating) ---\n" . $peerContext
                : $changeSummary;

            $turn = Turn::begin($agent)
                ->conversation($conversation)
                ->message(Message::user($prompt))
                ->maxSteps(3);

            $projectRoot = $this->projectRoot;

            $tasks[$agentName] = Task::of(
                static function (ExecutionScope $child) use ($turn, $agentName, $agentGlyph, $agentColor, $projectRoot): AgentRunResult {
                    return self::runAgentTurn(
                        $turn,
                        $child,
                        $projectRoot,
                        $agentName,
                        $agentGlyph,
                        $agentColor,
                    );
                }
            );
        }

        return $tasks;
    }

    /**
     * @return array<string, Task>
     */
    private function buildResponseTasks(string $prompt, int $maxSteps = 5): array
    {
        $tasks = [];
        $contextualPrompt = $this->projectContext . "\n\n" . $prompt;

        foreach ($this->agents as $agent) {
            $agentName = $agent->name();
            $agentGlyph = $agent->glyph();
            $agentColor = $agent->color();
            $conversation = $this->conversationFor($agent);

            $turn = Turn::begin($agent)
                ->conversation($conversation)
                ->message(Message::user($contextualPrompt))
                ->maxSteps($maxSteps);

            $projectRoot = $this->projectRoot;

            $tasks[$agentName] = Task::of(
                static function (ExecutionScope $child) use ($turn, $agentName, $agentGlyph, $agentColor, $projectRoot): AgentRunResult {
                    return self::runAgentTurn(
                        $turn,
                        $child,
                        $projectRoot,
                        $agentName,
                        $agentGlyph,
                        $agentColor,
                    );
                }
            );
        }

        return $tasks;
    }

    private static function executeAndCollect(
        Turn $turn,
        ExecutionScope $scope,
        string $agentName,
        string $agentGlyph,
        string $agentColor,
    ): AgentRunResult {
        $events = AgentLoop::run($turn, $scope);
        $tokenBuffer = '';
        $conversation = null;
        $usage = TokenUsage::zero();

        foreach ($events($scope) as $event) {
            if (!$event instanceof AgentEvent) {
                continue;
            }

            match ($event->kind) {
                AgentEventKind::TokenDelta => $tokenBuffer .= $event->data->text,
                AgentEventKind::StepComplete => $tokenBuffer .= ($tokenBuffer !== '' ? "\n\n" : ''),
                AgentEventKind::AgentComplete => self::captureCompletion($event, $conversation, $usage),
                default => null,
            };
        }

        return new AgentRunResult($agentName, $agentGlyph, $agentColor, $tokenBuffer, $conversation, $usage);
    }

    private static function captureCompletion(AgentEvent $event, ?Conversation &$conversation, TokenUsage &$usage): void
    {
        if ($event->data instanceof AgentResult) {
            $conversation = $event->data->conversation;
        }
        $usage = $event->usageSoFar;
    }

    private static function runAgentTurn(
        Turn $turn,
        ExecutionScope $parentScope,
        string $projectRoot,
        string $agentName,
        string $agentGlyph,
        string $agentColor,
    ): AgentRunResult {
        // Sentinel is a long-lived console session. Per-turn resources like provider
        // response streams must bind to a short-lived scope so their onDispose cleanup
        // runs after each review instead of only when the whole command exits.
        $turnScope = $parentScope->withAttribute('sentinel.project_root', $projectRoot);

        try {
            return self::executeAndCollect($turn, $turnScope, $agentName, $agentGlyph, $agentColor);
        } finally {
            $turnScope->dispose();
        }
    }

    private function conversationFor(ReviewAgent $agent): Conversation
    {
        return Conversation::create()->system($agent->instructions);
    }

    /**
     * @param list<FileChange> $changes
     * @param array<int, ?string> $diffs change-index => diff text (null when unavailable)
     */
    private function formatChangeSummary(array $changes, array $diffs): string
    {
        $lines = ["File changes detected (" . count($changes) . " files):\n"];

        foreach ($changes as $i => $change) {
            $lines[] = "  {$change->summary()}";

            $diff = $diffs[$i] ?? null;
            if ($diff !== null) {
                $diffLines = explode("\n", $diff);
                $truncated = count($diffLines) > 80
                    ? [...array_slice($diffLines, 0, 80), '... (truncated)']
                    : $diffLines;

                $lines[] = "  ```diff";
                foreach ($truncated as $dl) {
                    $lines[] = "  {$dl}";
                }
                $lines[] = "  ```";
            }

            if ($change->kind !== ChangeKind::Deleted) {
                $fullPath = rtrim($this->projectRoot, '/') . '/' . ltrim($change->path, '/');
                $content = $this->readBoundedFile($fullPath, maxBytes: 50_000);
                if ($content !== null) {
                    $ext = pathinfo($change->path, PATHINFO_EXTENSION);
                    $lines[] = "  Full file contents:";
                    $lines[] = "  ```{$ext}";
                    $lines[] = $content;
                    $lines[] = "  ```";
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<FileChange> $changes
     * @return array<int, ?string>
     */
    private function computeDiffs(array $changes, ExecutionScope $scope): array
    {
        $tasks = [];
        $projectRoot = $this->projectRoot;

        foreach ($changes as $i => $change) {
            if ($change->kind === ChangeKind::Deleted) {
                continue;
            }

            $relativePath = $change->path;
            $tasks[$i] = Task::of(
                static function (ExecutionScope $s) use ($relativePath, $projectRoot): ?string {
                    $command = sprintf(
                        'git -C %s diff --no-color -- %s',
                        escapeshellarg($projectRoot),
                        escapeshellarg($relativePath),
                    );

                    try {
                        $diff = $s->execute(new RunCommand($command, $projectRoot, maxBytes: 50_000));
                    } catch (\Throwable) {
                        return null;
                    }

                    return $diff !== '' ? $diff : null;
                }
            );
        }

        if ($tasks === []) {
            return [];
        }

        /** @var array<int, ?string> $results */
        $results = $scope->concurrent($tasks);

        return $results;
    }

    private function readBoundedFile(string $path, int $maxBytes): ?string
    {
        try {
            $info = $this->files->stat($path);
        } catch (FilesystemException) {
            return null;
        }

        if ($info->size > $maxBytes) {
            return null;
        }

        try {
            return $this->files->read($path);
        } catch (FilesystemException) {
            return null;
        }
    }

    private function buildPeerContext(string $excludeAgent): string
    {
        $parts = [];
        foreach ($this->lastRoundFeedback as $name => $feedback) {
            if ($name === $excludeAgent) {
                continue;
            }
            $parts[] = "[{$name}]: {$feedback}";
        }

        foreach ($this->externalBuffer as $msg) {
            $parts[] = "[{$msg['from']} (session {$msg['session']})]: {$msg['text']}";
        }

        return implode("\n\n", $parts);
    }

    private function enrichWithFileContents(string $message, ExecutionScope $scope): string
    {
        $patterns = [];

        if (preg_match_all('/\b([\w\/.-]+\.(?:php|ts|tsx|js|json|yaml|yml|neon))\b/', $message, $matches)) {
            $patterns = array_unique($matches[1]);
        }

        if ($patterns === []) {
            return $message;
        }

        $found = [];
        foreach ($patterns as $pattern) {
            $filename = basename($pattern);
            $command = sprintf(
                'find %s -name %s -not -path "*/vendor/*" -not -path "*/.git/*"',
                escapeshellarg($this->projectRoot),
                escapeshellarg($filename),
            );

            try {
                $output = $scope->execute(new RunCommand($command, $this->projectRoot));
            } catch (\Throwable) {
                continue;
            }

            $results = array_filter(explode("\n", $output), static fn(string $line) => $line !== '');

            foreach ($results as $fullPath) {
                $content = $this->readBoundedFile($fullPath, maxBytes: 50_000);
                if ($content === null) {
                    continue;
                }

                $relative = str_replace(rtrim($this->projectRoot, '/') . '/', '', $fullPath);
                $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
                $found[$relative] = "File: {$relative}\n```{$ext}\n{$content}\n```";
                break;
            }
        }

        if ($found === []) {
            return $message;
        }

        return $message . "\n\n--- Referenced files ---\n" . implode("\n\n", $found);
    }

    private static function buildProjectContext(string $projectRoot): string
    {
        $lines = ["Project: {$projectRoot}"];
        $lines[] = "Source directories (use with read_file / list_directory):";

        $srcDirs = glob($projectRoot . '/packages/*/src');
        if ($srcDirs !== false && $srcDirs !== []) {
            sort($srcDirs);
            foreach ($srcDirs as $dir) {
                $relative = str_replace(rtrim($projectRoot, '/') . '/', '', $dir);
                $lines[] = "  {$relative}/";
            }
        }

        $rootSrc = $projectRoot . '/src';
        if (is_dir($rootSrc)) {
            $lines[] = "  src/";
        }

        return implode("\n", $lines);
    }
}
