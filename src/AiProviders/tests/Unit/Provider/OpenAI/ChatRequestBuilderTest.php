<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider\OpenAI;

use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Config\Model;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Provider\OpenAI\ChatOptions;
use Phalanx\AiProviders\Provider\OpenAI\ChatRequestBuilder;
use Phalanx\AiProviders\Provider\Preference;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChatRequestBuilderTest extends TestCase
{
    #[Test]
    public function requestMethodIsPost(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );

        self::assertSame('POST', $request->method);
    }

    #[Test]
    public function requestUrlAppendedToBaseUrl(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );

        self::assertSame('https://api.openai.com/v1/chat/completions', $request->url);
    }

    #[Test]
    public function hostOnlyBaseUrlGetsV1PathInserted(): void
    {
        // When base_url is host-only (the canonical openai.ai-providers.yaml convention),
        // the builder inserts /v1 before the endpoint suffix.
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com',
        );

        self::assertSame('https://api.openai.com/v1/chat/completions', $request->url);
    }

    #[Test]
    public function trailingSlashInBaseUrlIsStripped(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1/',
        );

        self::assertSame('https://api.openai.com/v1/chat/completions', $request->url);
    }

    #[Test]
    public function requiredHeadersArePresent(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_sparta',
            'https://api.openai.com/v1',
        );

        self::assertSame('Bearer key_sparta', $request->headers['authorization']);
        self::assertSame('application/json', $request->headers['content-type']);
        self::assertSame('text/event-stream', $request->headers['accept']);
    }

    #[Test]
    public function defaultHeadersMergedIntoRequest(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://openrouter.ai/api/v1',
            new ChatOptions(),
            ['HTTP-Referer' => 'https://phalanx.test', 'X-Title' => 'Phalanx'],
        );

        self::assertSame('https://phalanx.test', $request->headers['HTTP-Referer']);
        self::assertSame('Phalanx', $request->headers['X-Title']);
    }

    #[Test]
    public function bodyContainsModelAndStream(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body = json_decode($request->body, associative: true);

        self::assertSame('gpt-5', $body['model']);
        self::assertTrue($body['stream']);
    }

    #[Test]
    public function instructionsBecomeFirstSystemMessage(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body = json_decode($request->body, associative: true);

        self::assertSame('system', $body['messages'][0]['role']);
        self::assertSame('Guard the agora. Report to the phalanx.', $body['messages'][0]['content']);
    }

    #[Test]
    public function dynamicContextMessagesUsedWhenPresent(): void
    {
        $invocation = self::invocationWith([
            'messages' => [
                ['role' => 'user', 'content' => 'What happened at Marathon?'],
                ['role' => 'assistant', 'content' => 'The Athenians prevailed.'],
            ],
        ]);
        $request = ChatRequestBuilder::build(
            $invocation,
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body = json_decode($request->body, associative: true);

        // System + 2 history messages.
        self::assertSame('user', $body['messages'][1]['role']);
        self::assertSame('assistant', $body['messages'][2]['role']);
    }

    #[Test]
    public function userInputFallsBackToSingleUserMessage(): void
    {
        $invocation = self::invocationWith(['user_input' => 'Describe the pass at Thermopylae.']);
        $request = ChatRequestBuilder::build(
            $invocation,
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body = json_decode($request->body, associative: true);

        // system message + user message
        self::assertCount(2, $body['messages']);
        self::assertSame('user', $body['messages'][1]['role']);
        self::assertSame('Describe the pass at Thermopylae.', $body['messages'][1]['content']);
    }

    #[Test]
    public function toolsKeyAbsentWhenNoTools(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body = json_decode($request->body, associative: true);

        self::assertArrayNotHasKey('tools', $body);
    }

    #[Test]
    public function toolsKeyPresentFromDynamicContext(): void
    {
        $invocation = self::invocationWith([
            'tools' => [['type' => 'function', 'function' => ['name' => 'search_agora']]],
        ]);
        $request = ChatRequestBuilder::build(
            $invocation,
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body = json_decode($request->body, associative: true);

        self::assertArrayHasKey('tools', $body);
        self::assertCount(1, $body['tools']);
    }

    #[Test]
    public function nullMaxTokensOmittedFromBody(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body = json_decode($request->body, associative: true);

        self::assertArrayNotHasKey('max_tokens', $body);
    }

    #[Test]
    public function maxTokensReflectedWhenSet(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
            new ChatOptions(maxTokens: 4096),
        );
        $body = json_decode($request->body, associative: true);

        self::assertSame(4096, $body['max_tokens']);
    }

    #[Test]
    public function temperatureAndTopPOmittedWhenNull(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body = json_decode($request->body, associative: true);

        self::assertArrayNotHasKey('temperature', $body);
        self::assertArrayNotHasKey('top_p', $body);
    }

    #[Test]
    public function seedReflectedWhenSet(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
            new ChatOptions(seed: 42),
        );
        $body = json_decode($request->body, associative: true);

        self::assertSame(42, $body['seed']);
    }

    #[Test]
    public function customBaseUrlUsedForCompatibleProviders(): void
    {
        $request = ChatRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_groq',
            'https://api.groq.com/openai/v1',
        );

        self::assertSame('https://api.groq.com/openai/v1/chat/completions', $request->url);
    }

    private static function invocation(): Invocation
    {
        return self::invocationWith([]);
    }

    /**
     * @param array<string, mixed> $dynamicContext
     */
    private static function invocationWith(array $dynamicContext): Invocation
    {
        return Invocation::of(
            id: 'inv_olympus',
            agentId: 'odysseus',
            activityId: 'act_sparta',
            contextHash: str_repeat('b', 64),
            instructions: 'Guard the agora. Report to the phalanx.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::WebFetch),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
            dynamicContext: $dynamicContext,
        );
    }

    private static function model(): Model
    {
        return Model::of(
            name: 'gpt-5',
            modelId: 'gpt-5',
            aliases: ['gpt5'],
            capabilities: Capabilities::of(Capability::Reasoning, Capability::ToolUse),
        );
    }
}
