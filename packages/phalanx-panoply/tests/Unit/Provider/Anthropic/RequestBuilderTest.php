<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\Anthropic;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Anthropic\MessagesOptions;
use Phalanx\Panoply\Provider\Anthropic\RequestBuilder;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestBuilderTest extends TestCase
{
    #[Test]
    public function requestMethodIsPost(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'key_test', 'https://api.anthropic.com');

        self::assertSame('POST', $request->method);
    }

    #[Test]
    public function requestUrlAppendedToBaseUrl(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'key_test', 'https://api.anthropic.com');

        self::assertSame('https://api.anthropic.com/v1/messages', $request->url);
    }

    #[Test]
    public function trailingSlashInBaseUrlIsStripped(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'key_test', 'https://api.anthropic.com/');

        self::assertSame('https://api.anthropic.com/v1/messages', $request->url);
    }

    #[Test]
    public function requiredHeadersArePresent(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'key_sparta', 'https://api.anthropic.com');

        self::assertSame('key_sparta', $request->headers['x-api-key']);
        self::assertSame('2023-06-01', $request->headers['anthropic-version']);
        self::assertSame('application/json', $request->headers['content-type']);
        self::assertSame('text/event-stream', $request->headers['accept']);
    }

    #[Test]
    public function bodyContainsModelAndStream(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'key_test', 'https://api.anthropic.com');
        $body    = json_decode($request->body, associative: true);

        self::assertSame('claude-opus-4-7', $body['model']);
        self::assertTrue($body['stream']);
    }

    #[Test]
    public function bodyContainsInstructionsAsSystem(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'key_test', 'https://api.anthropic.com');
        $body    = json_decode($request->body, associative: true);

        self::assertSame('Guard the agora. Report to the phalanx.', $body['system']);
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
        $request = RequestBuilder::build($invocation, self::model(), 'key_test', 'https://api.anthropic.com');
        $body    = json_decode($request->body, associative: true);

        self::assertCount(2, $body['messages']);
        self::assertSame('user', $body['messages'][0]['role']);
    }

    #[Test]
    public function userInputFallsBackToSingleMessage(): void
    {
        $invocation = self::invocationWith(['user_input' => 'What is the pass at Thermopylae?']);
        $request    = RequestBuilder::build($invocation, self::model(), 'key_test', 'https://api.anthropic.com');
        $body       = json_decode($request->body, associative: true);

        self::assertCount(1, $body['messages']);
        self::assertSame('user', $body['messages'][0]['role']);
        self::assertSame('What is the pass at Thermopylae?', $body['messages'][0]['content']);
    }

    #[Test]
    public function toolsKeyAbsentWhenNoTools(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'key_test', 'https://api.anthropic.com');
        $body    = json_decode($request->body, associative: true);

        self::assertArrayNotHasKey('tools', $body);
    }

    #[Test]
    public function toolsKeyPresentFromDynamicContext(): void
    {
        $invocation = self::invocationWith([
            'tools' => [
                ['name' => 'search', 'description' => 'Search the agora records'],
            ],
        ]);
        $request = RequestBuilder::build($invocation, self::model(), 'key_test', 'https://api.anthropic.com');
        $body    = json_decode($request->body, associative: true);

        self::assertArrayHasKey('tools', $body);
        self::assertCount(1, $body['tools']);
        self::assertSame('search', $body['tools'][0]['name']);
    }

    #[Test]
    public function defaultMaxTokensIs4096(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'key_test', 'https://api.anthropic.com');
        $body    = json_decode($request->body, associative: true);

        self::assertSame(4096, $body['max_tokens']);
    }

    #[Test]
    public function optionsMaxTokensIsReflectedInRequestBody(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.anthropic.com',
            new MessagesOptions(maxTokens: 8192),
        );
        $body = json_decode($request->body, associative: true);

        self::assertSame(8192, $body['max_tokens']);
    }

    #[Test]
    public function optionsTemperatureIsReflectedInRequestBody(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.anthropic.com',
            new MessagesOptions(temperature: 0.7),
        );
        $body = json_decode($request->body, associative: true);

        self::assertSame(0.7, $body['temperature']);
    }

    #[Test]
    public function optionsTopPIsReflectedInRequestBody(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.anthropic.com',
            new MessagesOptions(topP: 0.9),
        );
        $body = json_decode($request->body, associative: true);

        self::assertSame(0.9, $body['top_p']);
    }

    #[Test]
    public function optionsStopSequencesAreReflectedInRequestBody(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.anthropic.com',
            new MessagesOptions(stopSequences: ['STOP', 'END']),
        );
        $body = json_decode($request->body, associative: true);

        self::assertSame(['STOP', 'END'], $body['stop_sequences']);
    }

    #[Test]
    public function defaultOptionsOmitTemperatureAndTopPAndStopSequences(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.anthropic.com',
            new MessagesOptions(),
        );
        $body = json_decode($request->body, associative: true);

        self::assertArrayNotHasKey('temperature', $body);
        self::assertArrayNotHasKey('top_p', $body);
        self::assertArrayNotHasKey('stop_sequences', $body);
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
            name: 'claude-opus-4-7',
            modelId: 'claude-opus-4-7',
            aliases: ['opus'],
            capabilities: Capabilities::of(Capability::Reasoning, Capability::ToolUse),
        );
    }
}
