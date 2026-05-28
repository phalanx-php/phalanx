<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Reactor;

use Phalanx\Theatron\Demos\Capstone\Event\AgentMessageEvent;
use Phalanx\Theatron\Demos\Capstone\Event\AgentOnlineEvent;
use Phalanx\Theatron\Demos\Capstone\Event\TaskCompletedEvent;
use Phalanx\Theatron\Demos\Capstone\Event\TaskDelegatedEvent;
use Phalanx\Theatron\Demos\Capstone\Slice\AgentRegistrySlice;
use Phalanx\Theatron\Demos\Capstone\Slice\ConversationMessage;
use Phalanx\Theatron\Demos\Capstone\Slice\ConversationSlice;
use Phalanx\Theatron\Demos\Capstone\Slice\TaskBoardSlice;
use Phalanx\Theatron\Demos\Capstone\Slice\TaskEntry;
use Phalanx\Theatron\Reactor\ReactorContext;
use Phalanx\Theatron\Reactor\StreamReactor;
use Phalanx\Theatron\Stream\TheatronStream;

final class SwarmEventRouter implements StreamReactor
{
    public function subscribe(TheatronStream $stream, ReactorContext $context): void
    {
        $writer = $context->writer;

        $stream->subscribe(AgentOnlineEvent::class, static function (AgentOnlineEvent $event) use ($writer): void {
            $writer->update(
                AgentRegistrySlice::class,
                static fn(AgentRegistrySlice $s): AgentRegistrySlice => $s->withStatus($event->agentId, 'online'),
            );
        });

        $stream->subscribe(AgentMessageEvent::class, static function (AgentMessageEvent $event) use ($writer): void {
            $writer->update(
                ConversationSlice::class,
                static fn(ConversationSlice $s): ConversationSlice => $s->append(
                    new ConversationMessage(
                        from: $event->agentId,
                        body: $event->body,
                        timestamp: $event->timestamp,
                    ),
                ),
            );

            $writer->update(
                AgentRegistrySlice::class,
                static fn(AgentRegistrySlice $s): AgentRegistrySlice => $s->withStatus($event->agentId, 'working'),
            );
        });

        $stream->subscribe(TaskDelegatedEvent::class, static function (TaskDelegatedEvent $event) use ($writer): void {
            $writer->update(
                TaskBoardSlice::class,
                static fn(TaskBoardSlice $s): TaskBoardSlice => $s->addTask(
                    new TaskEntry(
                        id: $event->taskId,
                        title: $event->title,
                        assignedTo: $event->assignedTo,
                        status: 'active',
                    ),
                ),
            );
        });

        $stream->subscribe(TaskCompletedEvent::class, static function (TaskCompletedEvent $event) use ($writer): void {
            $writer->update(
                TaskBoardSlice::class,
                static fn(TaskBoardSlice $s): TaskBoardSlice => $s->updateStatus($event->taskId, 'completed'),
            );

            $writer->update(
                AgentRegistrySlice::class,
                static fn(AgentRegistrySlice $s): AgentRegistrySlice => $s->withStatus($event->agentId, 'idle'),
            );
        });
    }
}
