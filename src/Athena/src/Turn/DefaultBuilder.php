<?php

declare(strict_types=1);

namespace Phalanx\Athena\Turn;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Id;
use Phalanx\Panoply\Invocation;
use Phalanx\Scope\TaskScope;

final class DefaultBuilder implements Builder
{
    public function build(TaskScope $scope, Agent $agent, Log $log, Config $config): Invocation
    {
        $records = $log->toArray();

        return Invocation::of(
            id: 'inv_' . Id::generate(),
            agentId: $agent->id,
            activityId: $config->activityId,
            contextHash: Canonical::of($config->context),
            instructions: $agent->purpose,
            output: $agent->output,
            effects: $agent->effects,
            provider: $agent->provider,
            transport: $agent->transport,
            dynamicContext: [
                'messages' => self::messages($records),
                'conversation_record_count' => count($records),
                'invocation' => $config->invocation,
                'max_invocations' => $config->maxInvocations,
                'scope_cancelled' => $scope->isCancelled,
            ],
        );
    }

    /**
     * @param list<\Phalanx\Panoply\Conversation\Record> $records
     * @return list<array{role: string, content: string}>
     */
    private static function messages(array $records): array
    {
        $messages = [];

        foreach ($records as $record) {
            if (!$record instanceof Message) {
                continue;
            }

            $messages[] = [
                'role' => $record->role,
                'content' => $record->text,
            ];
        }

        return $messages;
    }
}
