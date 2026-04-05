<?php

declare(strict_types=1);

namespace Phalanx\Ai\Cli;

use Phalanx\Ai\AgentDefinition;
use Phalanx\Ai\AgentLoop;
use Phalanx\Ai\AgentResult;
use Phalanx\Ai\Event\AgentEvent;
use Phalanx\Ai\Event\AgentEventKind;
use Phalanx\Ai\Memory\ConversationMemory;
use Phalanx\Ai\Message\Conversation;
use Phalanx\Ai\Message\Message;
use Phalanx\Ai\Turn;
use Phalanx\Console\Command;
use Phalanx\Console\CommandScope;
use Phalanx\Console\Opt;

final class AgentRepl
{
    public static function command(AgentDefinition $agent, string $description = 'Interactive agent REPL'): Command
    {
        return new Command(
            fn: static fn(CommandScope $scope): int => self::runRepl($agent, $scope),
            desc: $description,
            opts: [
                Opt::value('session', 's', 'Resume a session by ID'),
                Opt::flag('verbose', 'v', 'Show tool calls and timing'),
            ],
        );
    }

    private static function runRepl(AgentDefinition $agent, CommandScope $scope): int
    {
        $memory = self::resolveMemory($scope);
        $sessionId = $scope->options->get('session', uniqid('cli_'));
        $conversation = $memory?->load($sessionId) ?? Conversation::create();
        $verbose = $scope->options->has('verbose');

        echo "Session: {$sessionId}\n";
        echo "Agent: " . $agent::class . "\n\n";

        while (true) {
            $input = readline('> ');

            if ($input === false || $input === 'exit') {
                break;
            }

            if ($input === '') {
                continue;
            }

            $turn = Turn::begin($agent)
                ->conversation($conversation)
                ->message(Message::user($input));

            $events = AgentLoop::run($turn, $scope);

            $result = null;

            foreach ($events($scope) as $event) {
                if (!$event instanceof AgentEvent) {
                    continue;
                }

                if ($verbose && $event->kind === AgentEventKind::ToolCallStart) {
                    $name = $event->data->toolName;
                    $args = json_encode($event->data->arguments);
                    echo "\033[90m[tool] {$name}({$args})\033[0m\n";
                }

                if ($verbose && $event->kind === AgentEventKind::ToolCallComplete) {
                    $ms = number_format($event->elapsed, 1);
                    echo "\033[90m[done] {$event->data->toolName} +{$ms}ms\033[0m\n";
                }

                if ($event->kind === AgentEventKind::TokenDelta && $event->data->text !== null) {
                    echo $event->data->text;
                }

                if ($event->kind === AgentEventKind::AgentComplete && $event->data instanceof AgentResult) {
                    $result = $event->data;
                }
            }

            echo "\n\n";

            if ($result !== null) {
                $conversation = $result->conversation;
                $memory?->save($sessionId, $conversation);
            }
        }

        return 0;
    }

    private static function resolveMemory(CommandScope $scope): ?ConversationMemory
    {
        try {
            /** @var ConversationMemory */
            return $scope->service(ConversationMemory::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
