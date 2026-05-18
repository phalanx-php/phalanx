<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\Gemini;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Invocation\Completed;
use Phalanx\Panoply\Cue\Invocation\Failed;
use Phalanx\Panoply\Cue\Invocation\Started;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\Provider\Resolved;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Cue\Usage\FinalUsage;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Gemini\CueMapper;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Sse\Event;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CueMapperTest extends TestCase
{
    #[Test]
    public function firstChunkWithPartsYieldsResolvedThenStarted(): void
    {
        $mapper = self::fixture();
        $cues   = self::translateChunk($mapper, self::textChunk('Poseidon speaks.'));

        self::assertCount(3, $cues);
        self::assertInstanceOf(Resolved::class, $cues[0]);
        self::assertInstanceOf(Started::class, $cues[1]);
        self::assertInstanceOf(TokenDelta::class, $cues[2]);
    }

    #[Test]
    public function resolvedCueCarriesGeminiProviderAndModelVersion(): void
    {
        $mapper = self::fixture();
        $cues   = self::translateChunk($mapper, self::textChunk('text', modelVersion: 'gemini-2.5-flash'));

        $resolved = array_values(array_filter($cues, static fn ($c) => $c instanceof Resolved));
        self::assertCount(1, $resolved);
        self::assertSame('gemini', $resolved[0]->provider);
        self::assertSame('gemini-2.5-flash', $resolved[0]->model);
    }

    #[Test]
    public function textPartYieldsTokenDeltaOnMessageChannel(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $cues = self::translateChunk($mapper, self::textChunk('The sea roars.'));

        $deltas = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenDelta));
        self::assertCount(1, $deltas);
        self::assertSame('The sea roars.', $deltas[0]->text);
        self::assertSame(Channel::Message, $deltas[0]->channel);
    }

    #[Test]
    public function thoughtPartYieldsTokenDeltaOnThinkingChannel(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'candidates' => [[
                'content'      => ['role' => 'model', 'parts' => [
                    ['thought' => true, 'text' => 'Artemis deliberates the shot.'],
                ]],
                'finishReason' => null,
                'index'        => 0,
            ]],
            'modelVersion' => 'gemini-2.5-pro',
        ]);
        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        $deltas = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenDelta));
        self::assertCount(1, $deltas);
        self::assertSame('Artemis deliberates the shot.', $deltas[0]->text);
        self::assertSame(Channel::Thinking, $deltas[0]->channel);
    }

    #[Test]
    public function functionCallPartYieldsEffectRequested(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['functionCall' => ['name' => 'search_hades_records', 'args' => ['query' => 'shades']]],
                ]],
                'finishReason' => null,
                'index'        => 0,
            ]],
            'modelVersion' => 'gemini-2.5-pro',
        ]);
        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        $requested = array_values(array_filter($cues, static fn ($c) => $c instanceof Requested));
        self::assertCount(1, $requested);
        self::assertStringContainsString('search_hades_records', $requested[0]->summary);
        self::assertSame(['query' => 'shades'], $requested[0]->arguments);
        self::assertStringStartsWith('fc_', $requested[0]->effectId);
    }

    #[Test]
    public function finishReasonStopYieldsTokenStopWithEndOfTurn(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $cues  = self::translateChunk($mapper, self::finishChunk('STOP'));
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertCount(1, $stops);
        self::assertSame(StopReason::EndOfTurn, $stops[0]->reason);
    }

    #[Test]
    public function finishReasonMaxTokensMapsToMaxTokens(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $cues  = self::translateChunk($mapper, self::finishChunk('MAX_TOKENS'));
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertCount(1, $stops);
        self::assertSame(StopReason::MaxTokens, $stops[0]->reason);
    }

    #[Test]
    public function finishReasonSafetyMapsToError(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $cues  = self::translateChunk($mapper, self::finishChunk('SAFETY'));
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertCount(1, $stops);
        self::assertSame(StopReason::Error, $stops[0]->reason);
    }

    #[Test]
    public function finishReasonRecitationMapsToError(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $cues  = self::translateChunk($mapper, self::finishChunk('RECITATION'));
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertSame(StopReason::Error, $stops[0]->reason);
    }

    #[Test]
    public function finishReasonOtherMapsToError(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $cues  = self::translateChunk($mapper, self::finishChunk('OTHER'));
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertCount(1, $stops);
        self::assertSame(StopReason::Error, $stops[0]->reason);
    }

    #[Test]
    public function finishReasonUnknownStringMapsToError(): void
    {
        // Locks the default arm: any novel reason string Gemini adds in future
        // must not accidentally break the mapper's default clause.
        $mapper = self::fixture();
        self::primeStart($mapper);

        $cues  = self::translateChunk($mapper, self::finishChunk('FUTURE_NEW_REASON'));
        $stops = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenStop));

        self::assertCount(1, $stops);
        self::assertSame(StopReason::Error, $stops[0]->reason);
    }

    #[Test]
    public function usageMetadataWithoutFinishReasonDoesNotEmitTerminalCues(): void
    {
        // usageMetadata alone (no finishReason) must not close the stream.
        // Terminal cues (FinalUsage + Completed) require both $finished AND $usageMetadata.
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'candidates'    => [[
                'content'      => ['role' => 'model', 'parts' => []],
                'finishReason' => null,
                'index'        => 0,
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
            'modelVersion'  => 'gemini-2.5-flash',
        ]);
        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        $finals    = array_values(array_filter($cues, static fn ($c) => $c instanceof FinalUsage));
        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));
        self::assertCount(0, $finals);
        self::assertCount(0, $completed);

        // Now send a finish chunk — terminal cues must emit then.
        $cues2     = self::translateChunk($mapper, self::finishChunk('STOP'));
        $stops     = array_values(array_filter($cues2, static fn ($c) => $c instanceof TokenStop));
        $cues3     = iterator_to_array($mapper->complete(), preserve_keys: false);
        $finals2   = array_values(array_filter($cues3, static fn ($c) => $c instanceof FinalUsage));
        $completed2 = array_values(array_filter($cues3, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $stops);
        self::assertCount(1, $finals2);
        self::assertCount(1, $completed2);
    }

    #[Test]
    public function usageMetadataWithFinishReasonEmitsFinalUsageAndCompleted(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'candidates' => [[
                'content'      => ['role' => 'model', 'parts' => []],
                'finishReason' => 'STOP',
                'index'        => 0,
            ]],
            'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 8, 'totalTokenCount' => 28],
            'modelVersion'  => 'gemini-2.5-flash',
        ]);
        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        $finals    = array_values(array_filter($cues, static fn ($c) => $c instanceof FinalUsage));
        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $finals);
        self::assertCount(1, $completed);
        self::assertSame(20, $finals[0]->inputTokens);
        self::assertSame(8, $finals[0]->outputTokens);
    }

    #[Test]
    public function completedNotDoubleEmitted(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        // Inline usage chunk closes the stream.
        $candidate = ['content' => ['role' => 'model', 'parts' => []], 'finishReason' => 'STOP', 'index' => 0];
        $event     = new Event('', [
            'candidates'    => [$candidate],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 3, 'totalTokenCount' => 8],
            'modelVersion'  => 'gemini-2.5-flash',
        ]);
        iterator_to_array($mapper->translate($event), preserve_keys: false);

        // complete() must be a no-op.
        $noop = iterator_to_array($mapper->complete(), preserve_keys: false);
        self::assertCount(0, $noop);
    }

    #[Test]
    public function completeAfterStartedWithoutUsageEmitsTerminalCues(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        // Feed a finish chunk but no usageMetadata.
        self::translateChunk($mapper, self::finishChunk('STOP'));

        // complete() should emit FinalUsage + Completed (defensive path).
        $cues      = iterator_to_array($mapper->complete(), preserve_keys: false);
        $finals    = array_values(array_filter($cues, static fn ($c) => $c instanceof FinalUsage));
        $completed = array_values(array_filter($cues, static fn ($c) => $c instanceof Completed));

        self::assertCount(1, $finals);
        self::assertCount(1, $completed);
    }

    #[Test]
    public function emptyStreamYieldsNoCues(): void
    {
        $mapper = self::fixture();
        $cues   = iterator_to_array($mapper->complete(), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function errorChunkYieldsFailedCue(): void
    {
        $mapper = self::fixture();

        $event = new Event('', [
            'error' => [
                'code'    => 429,
                'message' => 'Hephaestus forge is overloaded.',
                'status'  => 'RESOURCE_EXHAUSTED',
            ],
        ]);
        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        $failed = array_values(array_filter($cues, static fn ($c) => $c instanceof Failed));
        self::assertCount(1, $failed);
        self::assertStringContainsString('Hephaestus', $failed[0]->reason);
        self::assertSame('RESOURCE_EXHAUSTED', $failed[0]->errorClass);
    }

    #[Test]
    public function typedEventYieldsNoCues(): void
    {
        // Gemini chunks never carry a typed event field in normal use, but
        // the mapper silently skips any non-empty event type for safety.
        $mapper = self::fixture();
        $event  = new Event('some-type', ['candidates' => []]);

        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        self::assertCount(0, $cues);
    }

    #[Test]
    public function sequenceNumbersIncrementAcrossEvents(): void
    {
        $mapper = self::fixture();

        $chunk1 = self::textChunk('Poseidon ', modelVersion: 'gemini-2.5-flash');
        $cues1  = self::translateChunk($mapper, $chunk1);

        // Resolved=0, Started=1, TokenDelta=2
        self::assertSame(0, $cues1[0]->sequence);
        self::assertSame(1, $cues1[1]->sequence);
        self::assertSame(2, $cues1[2]->sequence);
    }

    #[Test]
    public function textAndFunctionCallInSameChunkBothYieldCues(): void
    {
        $mapper = self::fixture();
        self::primeStart($mapper);

        $event = new Event('', [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['text' => 'Calling the oracle.'],
                    ['functionCall' => ['name' => 'consult_oracle', 'args' => ['question' => 'fate']]],
                ]],
                'finishReason' => null,
                'index'        => 0,
            ]],
            'modelVersion' => 'gemini-2.5-pro',
        ]);
        $cues = iterator_to_array($mapper->translate($event), preserve_keys: false);

        $deltas    = array_values(array_filter($cues, static fn ($c) => $c instanceof TokenDelta));
        $requested = array_values(array_filter($cues, static fn ($c) => $c instanceof Requested));

        self::assertCount(1, $deltas);
        self::assertCount(1, $requested);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private static function fixture(): CueMapper
    {
        return new CueMapper(self::invocation());
    }

    private static function primeStart(CueMapper $mapper): void
    {
        self::translateChunk($mapper, self::textChunk('', modelVersion: 'gemini-2.5-flash'));
    }

    /**
     * @return list<\Phalanx\Panoply\Cue>
     */
    private static function translateChunk(CueMapper $mapper, array $data): array
    {
        return iterator_to_array($mapper->translate(new Event('', $data)), preserve_keys: false);
    }

    /**
     * @return array<string, mixed>
     */
    private static function textChunk(string $text, string $modelVersion = 'gemini-2.5-flash'): array
    {
        return [
            'candidates' => [[
                'content'      => ['role' => 'model', 'parts' => [['text' => $text]]],
                'finishReason' => null,
                'index'        => 0,
            ]],
            'modelVersion' => $modelVersion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function finishChunk(string $finishReason, string $modelVersion = 'gemini-2.5-flash'): array
    {
        return [
            'candidates' => [[
                'content'      => ['role' => 'model', 'parts' => []],
                'finishReason' => $finishReason,
                'index'        => 0,
            ]],
            'modelVersion' => $modelVersion,
        ];
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv_poseidon',
            agentId: 'poseidon',
            activityId: 'act_seafloor',
            contextHash: str_repeat('d', 64),
            instructions: 'Rule the seas.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
        );
    }
}
