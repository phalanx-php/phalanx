<?php

declare(strict_types=1);

namespace Phalanx\Agent\Turn;

use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Conversation\Record\Message;
use Phalanx\AiProviders\Hash\Canonical;
use Phalanx\AiProviders\Id;
use Phalanx\AiProviders\Invocation;
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
     * @param list<\Phalanx\AiProviders\Conversation\Record> $records
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
