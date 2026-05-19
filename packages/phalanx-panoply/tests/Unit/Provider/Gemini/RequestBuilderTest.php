<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\Gemini;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\Gemini\Options;
use Phalanx\Panoply\Provider\Gemini\RequestBuilder;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestBuilderTest extends TestCase
{
    #[Test]
    public function urlContainsAltSseQueryAndApiKey(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
        );

        self::assertStringContainsString('?alt=sse&key=key_artemis', $request->url);
        self::assertStringContainsString('/v1beta/models/gemini-2.5-flash', $request->url);
    }

    #[Test]
    public function urlEncodesApiKey(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key with+spaces&special=chars',
            'https://generativelanguage.googleapis.com',
        );

        // urlencode converts spaces to + and encodes special chars.
        self::assertStringContainsString('key+with%2Bspaces%26special%3Dchars', $request->url);
    }

    #[Test]
    public function baseUrlTrailingSlashNormalized(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_poseidon',
            'https://generativelanguage.googleapis.com/',
        );

        self::assertStringNotContainsString('//v1beta', $request->url);
        self::assertStringContainsString('/v1beta/models/', $request->url);
    }

    #[Test]
    public function bodyContentsFromUserInput(): void
    {
        $request = RequestBuilder::build(
            self::invocation(userInput: 'Charge the Persian line.'),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
        );

        $body = json_decode($request->body, true);

        self::assertIsArray($body['contents']);
        self::assertSame('user', $body['contents'][0]['role']);
        self::assertSame('Charge the Persian line.', $body['contents'][0]['parts'][0]['text']);
    }

    #[Test]
    public function bodyContentsFromGeminiShapedContext(): void
    {
        $geminiContents = [
            ['role' => 'user', 'parts' => [['text' => 'Who commands the fleet?']]],
            ['role' => 'model', 'parts' => [['text' => 'Themistocles.']],
            ],
        ];
        $request = RequestBuilder::build(
            self::invocation(dynamicContext: ['contents' => $geminiContents]),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
        );

        $body = json_decode($request->body, true);

        self::assertCount(2, $body['contents']);
        self::assertSame('user', $body['contents'][0]['role']);
        self::assertSame('model', $body['contents'][1]['role']);
    }

    #[Test]
    public function instructionsPromotedToSystemInstruction(): void
    {
        $request = RequestBuilder::build(
            self::invocation(instructions: 'You are the Oracle at Delphi.'),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
        );

        $body = json_decode($request->body, true);

        self::assertArrayHasKey('systemInstruction', $body);
        self::assertSame('You are the Oracle at Delphi.', $body['systemInstruction']['parts'][0]['text']);
        // systemInstruction must NOT appear as a contents entry.
        foreach ($body['contents'] as $entry) {
            self::assertNotSame('system', $entry['role'] ?? null);
        }
    }

    #[Test]
    public function emptyInstructionsOmitsSystemInstruction(): void
    {
        $request = RequestBuilder::build(
            self::invocation(instructions: ''),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
        );

        $body = json_decode($request->body, true);

        self::assertArrayNotHasKey('systemInstruction', $body);
    }

    #[Test]
    public function nullOptionsFieldsOmittedFromGenerationConfig(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
            new Options(),
        );

        $body = json_decode($request->body, true);

        self::assertArrayNotHasKey('generationConfig', $body);
    }

    #[Test]
    public function explicitOptionsReflectedInGenerationConfig(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
            new Options(
                maxOutputTokens: 512,
                temperature: 0.8,
                topP: 0.95,
                topK: 64,
                stopSequences: ['END'],
            ),
        );

        $body = json_decode($request->body, true);
        $config = $body['generationConfig'];

        self::assertSame(512, $config['maxOutputTokens']);
        self::assertSame(0.8, $config['temperature']);
        self::assertSame(0.95, $config['topP']);
        self::assertSame(64, $config['topK']);
        self::assertSame(['END'], $config['stopSequences']);
    }

    #[Test]
    public function contentTypeAndAcceptHeadersPresent(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
        );

        self::assertSame('application/json', $request->headers['content-type']);
        self::assertSame('text/event-stream', $request->headers['accept']);
    }

    #[Test]
    public function defaultHeadersMergedBeforeFixedHeaders(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
            new Options(),
            ['x-custom-header' => 'phalanx-test'],
        );

        self::assertSame('phalanx-test', $request->headers['x-custom-header']);
        self::assertSame('application/json', $request->headers['content-type']);
    }

    #[Test]
    public function userSuppliedContentTypeDoesNotOverrideFrameworkHeader(): void
    {
        // Framework headers are the rightmost operand in array_merge, so they
        // always win over user-supplied default_headers.
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
            new Options(),
            ['content-type' => 'text/plain', 'accept' => 'application/octet-stream'],
        );

        self::assertSame('application/json', $request->headers['content-type']);
        self::assertSame('text/event-stream', $request->headers['accept']);
    }

    #[Test]
    public function nullThinkingBudgetOmitsThinkingConfig(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
            new Options(thinkingBudget: null),
        );

        $body = json_decode($request->body, true);

        self::assertArrayNotHasKey('generationConfig', $body);
    }

    #[Test]
    public function thinkingBudgetLowMapsToTokenBudget(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
            new Options(thinkingBudget: 'low'),
        );

        $body = json_decode($request->body, true);

        self::assertSame(256, $body['generationConfig']['thinkingConfig']['thinkingBudget']);
    }

    #[Test]
    public function thinkingBudgetMediumMapsToTokenBudget(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
            new Options(thinkingBudget: 'medium'),
        );

        $body = json_decode($request->body, true);

        self::assertSame(1024, $body['generationConfig']['thinkingConfig']['thinkingBudget']);
    }

    #[Test]
    public function thinkingBudgetHighMapsToTokenBudget(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
            new Options(thinkingBudget: 'high'),
        );

        $body = json_decode($request->body, true);

        self::assertSame(4096, $body['generationConfig']['thinkingConfig']['thinkingBudget']);
    }

    #[Test]
    public function toolsFromDynamicContextPassedAsFunctionDeclarations(): void
    {
        $tools = [['name' => 'list_phalanx_units', 'description' => 'Returns hoplite roster.']];
        $request = RequestBuilder::build(
            self::invocation(dynamicContext: ['user_input' => 'List units.', 'tools' => $tools]),
            self::model(),
            'key_artemis',
            'https://generativelanguage.googleapis.com',
        );

        $body = json_decode($request->body, true);

        self::assertArrayHasKey('tools', $body);
        self::assertSame('list_phalanx_units', $body['tools'][0]['functionDeclarations'][0]['name']);
    }

    /**
     * @param array<string, mixed>|null $dynamicContext
     */
    private static function invocation(
        string $userInput = 'What is the battle plan?',
        string $instructions = 'You are a Spartan strategos.',
        ?array $dynamicContext = null,
    ): Invocation {
        return Invocation::of(
            id: 'inv_artemis',
            agentId: 'artemis',
            activityId: 'act_hunt',
            contextHash: str_repeat('a', 64),
            instructions: $instructions,
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
            dynamicContext: $dynamicContext ?? ['user_input' => $userInput],
        );
    }

    private static function model(): Model
    {
        return Model::of(
            name: 'gemini-2.5-flash',
            modelId: 'gemini-2.5-flash',
            aliases: ['flash'],
            capabilities: Capabilities::of(Capability::Reasoning),
        );
    }
}
