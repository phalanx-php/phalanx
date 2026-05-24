<?php

declare(strict_types=1);

namespace Phalanx\Agora\Tests\Integration\Harness;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Phalanx\Agora\Agora;
use Phalanx\Agora\Harness\EventSource;
use Phalanx\Agora\Harness\Persistence\HarnessEventDraft;
use Phalanx\Agora\Harness\Persistence\SurrealHarnessStore;
use Phalanx\Agora\Harness\ProjectionSet;
use Phalanx\Agora\Harness\ResumePoint;
use Phalanx\Agora\Harness\ResumeStatus;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Surreal\Support\SurrealBinaryLocator;
use Phalanx\Demos\Surreal\Support\SurrealFreePort;
use Phalanx\Demos\Surreal\Support\SurrealServerReadiness;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Panoply\Cue\Invocation\Started as InvocationStarted;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\Services;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealBundle;
use Phalanx\Surreal\SurrealException;
use Phalanx\System\StreamingProcess;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SurrealPersistenceProofTest extends PhalanxTestCase
{
    private const string ROOT_USER = 'root';
    private const string ROOT_PASSWORD = 'root';

    private int $port = 0;

    private string $database = 'agora';

    #[Test]
    public function surrealHarnessStorePersistsEventsCheckpointsResumeAndRecordRefs(): void
    {
        $this->runAgainstSurreal(static function (Surreal $surreal): void {
            self::assertSurrealOk($surreal->queryRaw((string) file_get_contents(Agora::HARNESS_SCHEMA_RESOURCE)));

            $at = self::at(1);
            $sessionId = uniqid('session-', true);
            $turnId = uniqid('turn-', true);
            $store = new SurrealHarnessStore($surreal);

            $store->createSession(
                sessionId: $sessionId,
                startedAt: $at,
                title: 'Agora persistence proof',
                model: 'qwen3:4b',
                provider: 'ollama',
                workingDirectory: '~/project',
            );
            $store->createTurn($sessionId, $turnId, 1, 'assistant', $at);

            $eventLog = $store->events();
            $persisted = [
                $eventLog->append(HarnessEventDraft::fromCue(
                    self::invocationStarted(10, $at),
                    $sessionId,
                    $turnId,
                )),
                $eventLog->append(HarnessEventDraft::fromCue(
                    self::token(20, 'thinking ', Channel::Thinking, $at),
                    $sessionId,
                    $turnId,
                )),
                $eventLog->append(HarnessEventDraft::fromCue(
                    self::token(30, 'answer', Channel::Message, $at),
                    $sessionId,
                    $turnId,
                )),
                $eventLog->append(HarnessEventDraft::fromCue(
                    self::usage(40, $at),
                    $sessionId,
                    $turnId,
                )),
                $eventLog->append(HarnessEventDraft::marker(
                    sessionId: $sessionId,
                    cueType: 'agora.workspace.restore',
                    source: EventSource::Agora,
                    occurredAt: $at,
                    payload: [
                        'active_surface' => 'conversation',
                        'selected_turn_id' => $turnId,
                        'scroll_offset' => 12,
                        'expanded_block' => null,
                        'input_mode' => 'insert',
                    ],
                    turnId: $turnId,
                )),
            ];

            self::assertSame([1, 2, 3, 4, 5], array_map(static fn($event): int => $event->sequence, $persisted));
            self::assertSame(20, $persisted[1]->payload['source_sequence']);

            $checkpointProjection = ProjectionSet::empty($sessionId)
                ->apply($persisted[0])
                ->apply($persisted[1])
                ->apply($persisted[2]);
            $store->saveCheckpoints($checkpointProjection, self::at(2));

            $loaded = $store->latestProjectionSet($sessionId);
            self::assertNotNull($loaded);

            foreach ($eventLog->readAfter($sessionId, $loaded->conversation->eventSequence()) as $event) {
                $loaded = $loaded->apply($event);
            }

            $full = ProjectionSet::empty($sessionId);
            foreach ($persisted as $event) {
                $full = $full->apply($event);
            }

            self::assertEquals($full->conversation->state(), $loaded->conversation->state());
            self::assertEquals($full->runtime->state(), $loaded->runtime->state());
            self::assertEquals($full->activity->state(), $loaded->activity->state());
            self::assertEquals($full->workspace->state(), $loaded->workspace->state());
            self::assertSame(
                $full->conversation->checkpoint(self::at(3))->projectionHash,
                $loaded->conversation->checkpoint(self::at(3))->projectionHash,
            );

            $effectRecordId = self::createEffectRecord(
                surreal: $surreal,
                sessionId: $sessionId,
                turnId: $turnId,
                eventId: $persisted[3]->id,
                at: $at,
            );
            $resume = new ResumePoint(
                sessionId: $sessionId,
                turnId: $turnId,
                eventSequence: 5,
                status: ResumeStatus::WaitingApproval,
                pendingEffectRecordId: $effectRecordId,
                serializedContext: ['pending_effect_id' => 'effect.read'],
                updatedAt: self::at(4),
            );

            try {
                $store->saveResumePoint(new ResumePoint(
                    sessionId: $sessionId,
                    turnId: $turnId,
                    eventSequence: 5,
                    status: ResumeStatus::WaitingApproval,
                    pendingEffectRecordId: 'effect.read',
                    serializedContext: [],
                    updatedAt: self::at(4),
                ));
                self::fail('Source effect ids must not be accepted as Surreal effect record refs.');
            } catch (SurrealException $e) {
                self::assertStringContainsString('agora_effect record reference', $e->getMessage());
            }

            $store->saveResumePoint($resume);
            $loadedResume = $store->resumePoint($sessionId);

            self::assertNotNull($loadedResume);
            self::assertSame($resume->toCanonical(), $loadedResume->toCanonical());
            self::assertSame($persisted[3]->id, self::storedEffectEventRef($surreal, $effectRecordId));
        });
    }

    /** @return array<string, mixed> */
    #[\Override]
    protected function phalanxContext(): array
    {
        return [
            'PATH' => getenv('PATH') ?: '',
            'surreal_namespace' => 'phalanx',
            'surreal_database' => $this->database,
            'surreal_endpoint' => "http://127.0.0.1:{$this->port}",
            'surreal_username' => self::ROOT_USER,
            'surreal_password' => self::ROOT_PASSWORD,
        ];
    }

    #[\Override]
    protected function phalanxServices(): Closure
    {
        return static function (Services $services, AppContext $context): void {
            (new SurrealBundle())->services($services, $context);
        };
    }

    private static function initializeNamespace(
        ExecutionScope $scope,
        int $port,
        string $database,
    ): void {
        $body = json_encode([
            'id' => 1,
            'method' => 'query',
            'params' => [
                sprintf('DEFINE NAMESPACE phalanx; USE NAMESPACE phalanx; DEFINE DATABASE %s;', $database),
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $scope->service(HttpClient::class)->request($scope, new HttpRequest(
            method: 'POST',
            url: "http://127.0.0.1:{$port}/rpc",
            headers: [
                'accept' => ['application/json'],
                'authorization' => ['Basic ' . base64_encode(self::ROOT_USER . ':' . self::ROOT_PASSWORD)],
                'content-type' => ['application/json'],
            ],
            body: $body,
        ));

        self::assertTrue($response->successful, "Surreal namespace bootstrap returned HTTP {$response->status}.");
    }

    private static function invocationStarted(
        int $sequence,
        DateTimeImmutable $at,
    ): InvocationStarted {
        return new InvocationStarted(
            id: 'cue.invocation.started.' . $sequence,
            sequence: $sequence,
            activityId: 'activity.agora',
            invocationId: 'invocation.agora',
            agentId: 'agent.agora',
            at: $at,
        );
    }

    private static function token(
        int $sequence,
        string $text,
        Channel $channel,
        DateTimeImmutable $at,
    ): TokenDelta {
        return new TokenDelta(
            id: 'cue.output.token.' . $sequence,
            sequence: $sequence,
            activityId: 'activity.agora',
            invocationId: 'invocation.agora',
            agentId: 'agent.agora',
            at: $at,
            text: $text,
            channel: $channel,
        );
    }

    private static function usage(
        int $sequence,
        DateTimeImmutable $at,
    ): FinalUsage {
        return new FinalUsage(
            id: 'cue.usage.final.' . $sequence,
            sequence: $sequence,
            activityId: 'activity.agora',
            invocationId: 'invocation.agora',
            agentId: 'agent.agora',
            at: $at,
            inputTokens: 2,
            outputTokens: 4,
            cacheReadTokens: 1,
            cacheWriteTokens: 0,
            costUsd: 0.01,
        );
    }

    private static function createEffectRecord(
        Surreal $surreal,
        string $sessionId,
        string $turnId,
        string $eventId,
        DateTimeImmutable $at,
    ): string {
        $effectKey = uniqid('effect-', true);
        $effectRecord = self::recordLiteral('agora_effect', $effectKey);
        $sessionRecord = self::recordLiteral('agora_session', $sessionId);
        $turnRecord = self::recordLiteral('agora_turn', $turnId);
        $eventRecord = self::recordLiteral('agora_event', self::recordKey($eventId, 'agora_event'));
        $requestedAt = self::formatInstant($at);
        self::assertSurrealOk($surreal->queryRaw(
            <<<SURREAL
            CREATE ONLY {$effectRecord} SET
                session_id = {$sessionRecord},
                turn_id = {$turnRecord},
                event_id = {$eventRecord},
                effect_id = 'effect.read',
                kind = 'file.read',
                summary = 'Read file',
                status = 'requested',
                requested_at = d'{$requestedAt}';
            SURREAL,
        ));

        return 'agora_effect:`' . $effectKey . '`';
    }

    private static function storedEffectEventRef(
        Surreal $surreal,
        string $effectRecordId,
    ): string {
        $effectRecord = self::recordLiteral('agora_effect', self::recordKey($effectRecordId, 'agora_effect'));

        $rows = self::statementResults($surreal->queryRaw(
            <<<SURREAL
            SELECT event_id
            FROM ONLY {$effectRecord};
            SURREAL,
        ));

        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            self::fail('Effect record was not found.');
        }

        $eventId = $row['event_id'] ?? null;
        self::assertIsString($eventId);

        return $eventId;
    }

    /**
     * @param list<mixed>|null $raw
     */
    private static function assertSurrealOk(
        ?array $raw,
    ): void {
        self::statementResults($raw);
        self::assertNotNull($raw);
    }

    /**
     * @param list<mixed>|null $raw
     * @return list<mixed>
     */
    private static function statementResults(
        ?array $raw,
    ): array {
        self::assertNotNull($raw);

        $results = [];
        foreach ($raw as $statement) {
            self::assertIsArray($statement);
            self::assertNotSame('ERR', $statement['status'] ?? null, is_string($statement['result'] ?? null) ? $statement['result'] : 'Surreal query failed.');
            $results[] = $statement['result'] ?? $statement;
        }

        return $results;
    }

    private static function recordKey(
        string $recordId,
        string $table,
    ): string {
        $prefix = $table . ':';
        self::assertStringStartsWith($prefix, $recordId);
        $key = substr($recordId, strlen($prefix));

        if (str_starts_with($key, '`') && str_ends_with($key, '`')) {
            return substr($key, 1, -1);
        }

        return $key;
    }

    private static function recordLiteral(
        string $table,
        string $key,
    ): string {
        if (str_contains($key, '`')) {
            throw new \InvalidArgumentException('Surreal record keys cannot contain backticks.');
        }

        return sprintf('%s:`%s`', $table, $key);
    }

    private static function at(
        int $second,
    ): DateTimeImmutable {
        return new DateTimeImmutable(sprintf('2026-05-24T00:00:%02d.000000Z', $second), new DateTimeZone('UTC'));
    }

    private static function formatInstant(
        DateTimeImmutable $instant,
    ): string {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @param Closure(Surreal): void $assert
     */
    private function runAgainstSurreal(
        Closure $assert,
    ): void {
        $binary = (new SurrealBinaryLocator())(new AppContext($this->phalanxContext()));
        if ($binary === null) {
            self::markTestSkipped('The `surreal` binary is not available on PATH.');
        }

        $this->port = (new SurrealFreePort())();
        $this->database = 'agora_' . str_replace('-', '_', uniqid('', false));
        $port = $this->port;
        $database = $this->database;

        $this->scope->run(
            static function (ExecutionScope $scope) use ($assert, $binary, $database, $port): void {
                $server = StreamingProcess::command([
                    $binary, 'start',
                    '--no-banner',
                    '--username', self::ROOT_USER,
                    '--password', self::ROOT_PASSWORD,
                    '--allow-all',
                    '--bind', "127.0.0.1:{$port}",
                    'memory',
                ])->start($scope);

                try {
                    $surreal = $scope->service(Surreal::class);
                    if (!(new SurrealServerReadiness())($scope, $surreal, $server)) {
                        self::fail('SurrealDB memory server did not become ready.');
                    }

                    self::initializeNamespace($scope, $port, $database);
                    $assert($surreal);
                } finally {
                    $server->stop(0.2, 0.1);
                }
            },
            'test.agora.surreal-persistence',
        );
    }
}
