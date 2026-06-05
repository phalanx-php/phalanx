<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider\Ollama;

use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Config\Model;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Provider\Ollama\ChatOptions;
use Phalanx\AiProviders\Provider\Ollama\RequestBuilder;
use Phalanx\AiProviders\Provider\Preference;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestBuilderTest extends TestCase
{
    #[Test]
    public function requestMethodIsPost(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'http://localhost:11434');

        self::assertSame('POST', $request->method);
    }

    #[Test]
    public function requestUrlIsApiChat(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'http://localhost:11434');

        self::assertSame('http://localhost:11434/api/chat', $request->url);
    }

    #[Test]
    public function noAuthorizationHeaderForOllama(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'http://localhost:11434');

        self::assertArrayNotHasKey('authorization', $request->headers);
    }

    #[Test]
    public function contentTypeAndAcceptHeadersArePresent(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'http://localhost:11434');

        self::assertSame('application/json', $request->headers['content-type']);
        self::assertSame('application/x-ndjson', $request->headers['accept']);
    }

    #[Test]
    public function bodyContainsModelAndStream(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'http://localhost:11434');
        $body = json_decode($request->body, associative: true);

        self::assertSame('llama3.1', $body['model']);
        self::assertTrue($body['stream']);
    }

    #[Test]
    public function systemInstructionsPrependedAsSystemMessage(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'http://localhost:11434');
        $body = json_decode($request->body, associative: true);

        self::assertSame('system', $body['messages'][0]['role']);
        self::assertSame('Rally at the agora.', $body['messages'][0]['content']);
    }

    #[Test]
    public function userInputWrappedAsUserMessage(): void
    {
        $invocation = self::invocationWith(['user_input' => 'What is the plan for Thermopylae?']);
        $request = RequestBuilder::build($invocation, self::model(), 'http://localhost:11434');
        $body = json_decode($request->body, associative: true);

        $userMessages = array_values(array_filter($body['messages'], static fn ($m) => $m['role'] === 'user'));
        self::assertCount(1, $userMessages);
        self::assertSame('What is the plan for Thermopylae?', $userMessages[0]['content']);
    }

    #[Test]
    public function optionsOmittedWhenAllNull(): void
    {
        $request = RequestBuilder::build(self::invocation(), self::model(), 'http://localhost:11434');
        $body = json_decode($request->body, associative: true);

        self::assertArrayNotHasKey('options', $body);
    }

    #[Test]
    public function temperatureReflectedInOptionsBody(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'http://localhost:11434',
            new ChatOptions(temperature: 0.7),
        );
        $body = json_decode($request->body, associative: true);

        self::assertSame(0.7, $body['options']['temperature']);
    }

    #[Test]
    public function toolsKeyPresentFromDynamicContext(): void
    {
        $invocation = self::invocationWith([
            'tools' => [['type' => 'function', 'function' => ['name' => 'rally_hoplites']]],
        ]);
        $request = RequestBuilder::build($invocation, self::model(), 'http://localhost:11434');
        $body = json_decode($request->body, associative: true);

        self::assertArrayHasKey('tools', $body);
        self::assertCount(1, $body['tools']);
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
            id: 'inv_pericles',
            agentId: 'pericles',
            activityId: 'act_agora',
            contextHash: str_repeat('g', 64),
            instructions: 'Rally at the agora.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::ToolUse),
            transport: TransportNeeds::new()->streaming(),
            dynamicContext: $dynamicContext,
        );
    }

    private static function model(): Model
    {
        return Model::of(
            name: 'llama3.1',
            modelId: 'llama3.1',
            aliases: ['llama'],
            capabilities: Capabilities::of(Capability::ToolUse),
        );
    }
}
