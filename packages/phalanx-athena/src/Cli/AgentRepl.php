<?php

declare(strict_types=1);

namespace Phalanx\Athena\Cli;

use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\AgentResult;
use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Memory\ConversationMemory;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Message\Message;
use Phalanx\Athena\Turn;
use Phalanx\Archon\Command\CommandScope;

/**
 * Interactive REPL runner for an AgentDefinition.
 *
 * Used as the body of a Scopeable command class. The command class
 * constructor injects an AgentDefinition (registered as a scoped/singleton
 * service) and an optional ConversationMemory, then delegates __invoke to
 * AgentRepl::run.
 */
final class AgentRepl
{
    public static function run(AgentDefinition $agent, CommandScope $scope, ?ConversationMemory $memory = null): int
    {
        $sessionId = $scope->options->get('session', uniqid('cli_'));
        $conversation = $memory?->load($sessionId) ?? Conversation::create();
        $verbose = $scope->options->has('verbose');

        echo "Session: {$sessionId}\n";
        echo "Agent: " . $agent::class . "\n\n";

        while (true) {
            fwrite(STDOUT, '> ');
            $raw = fgets(STDIN);

            if ($raw === false) {
                break;
            }

            $input = rtrim($raw, "\n\r");

            if ($input === 'exit') {
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
}
