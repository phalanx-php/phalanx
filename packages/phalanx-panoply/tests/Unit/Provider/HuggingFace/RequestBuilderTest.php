<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\HuggingFace;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\HuggingFace\Options;
use Phalanx\Panoply\Provider\HuggingFace\RequestBuilder;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestBuilderTest extends TestCase
{
    #[Test]
    public function urlPointsToV1ChatCompletions(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_token_pericles',
            'https://api-inference.huggingface.co',
        );

        self::assertSame(
            'https://api-inference.huggingface.co/v1/chat/completions',
            $request->url,
        );
    }

    #[Test]
    public function authorizationHeaderIsBearerToken(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_token_achilles',
            'https://api-inference.huggingface.co',
        );

        self::assertSame('Bearer hf_token_achilles', $request->headers['authorization']);
    }

    #[Test]
    public function contentTypeAndAcceptHeadersPresent(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_tok',
            'https://api-inference.huggingface.co',
        );

        self::assertSame('application/json', $request->headers['content-type']);
        self::assertSame('text/event-stream', $request->headers['accept']);
    }

    #[Test]
    public function bodyContainsModelAndStreamTrue(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_tok',
            'https://api-inference.huggingface.co',
        );

        $body = json_decode($request->body, true);

        self::assertSame('meta-llama/Meta-Llama-3.1-70B-Instruct', $body['model']);
        self::assertTrue($body['stream']);
    }

    #[Test]
    public function instructionsPromotedToSystemMessage(): void
    {
        $request = RequestBuilder::build(
            self::invocation(instructions: 'You are a Spartan commander.'),
            self::model(),
            'hf_tok',
            'https://api-inference.huggingface.co',
        );

        $body = json_decode($request->body, true);

        self::assertSame('system', $body['messages'][0]['role']);
        self::assertSame('You are a Spartan commander.', $body['messages'][0]['content']);
    }

    #[Test]
    public function nullOptionsFieldsOmittedFromBody(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_tok',
            'https://api-inference.huggingface.co',
            new Options(),
        );

        $body = json_decode($request->body, true);

        self::assertArrayNotHasKey('temperature', $body);
        self::assertArrayNotHasKey('top_p', $body);
        self::assertArrayNotHasKey('top_k', $body);
        self::assertArrayNotHasKey('max_tokens', $body);
        self::assertArrayNotHasKey('do_sample', $body);
    }

    #[Test]
    public function topKPassthroughInBody(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_tok',
            'https://api-inference.huggingface.co',
            new Options(topK: 40),
        );

        $body = json_decode($request->body, true);

        self::assertSame(40, $body['top_k']);
    }

    #[Test]
    public function doSamplePassthroughInBody(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_tok',
            'https://api-inference.huggingface.co',
            new Options(doSample: true),
        );

        $body = json_decode($request->body, true);

        self::assertTrue($body['do_sample']);
    }

    #[Test]
    public function maxNewTokensMapsToMaxTokens(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_tok',
            'https://api-inference.huggingface.co',
            new Options(maxNewTokens: 512),
        );

        $body = json_decode($request->body, true);

        self::assertSame(512, $body['max_tokens']);
        self::assertArrayNotHasKey('max_new_tokens', $body);
    }

    #[Test]
    public function defaultHeadersMergedIntoRequest(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_tok',
            'https://api-inference.huggingface.co',
            new Options(),
            ['x-org-id' => 'phalanx-panoply'],
        );

        self::assertSame('phalanx-panoply', $request->headers['x-org-id']);
    }

    #[Test]
    public function userSuppliedAuthorizationHeaderDoesNotOverrideFrameworkAuth(): void
    {
        // Framework-injected auth must win; user default_headers cannot clobber it.
        // array_merge($defaultHeaders, $frameworkHeaders) — rightmost key wins.
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_tok_achilles',
            'https://api-inference.huggingface.co',
            new Options(),
            ['authorization' => 'Bearer wrong-token'],
        );

        self::assertSame('Bearer hf_tok_achilles', $request->headers['authorization']);
    }

    #[Test]
    public function optionsTemperatureIsReflectedInRequestBody(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_tok',
            'https://api-inference.huggingface.co',
            new Options(temperature: 0.7),
        );

        $body = json_decode($request->body, true);

        self::assertSame(0.7, $body['temperature']);
    }

    #[Test]
    public function optionsTopPIsReflectedInRequestBody(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_tok',
            'https://api-inference.huggingface.co',
            new Options(topP: 0.9),
        );

        $body = json_decode($request->body, true);

        self::assertSame(0.9, $body['top_p']);
    }

    #[Test]
    public function optionsMaxNewTokensMapsToMaxTokens(): void
    {
        $request = RequestBuilder::build(
            self::invocation(),
            self::model(),
            'hf_tok',
            'https://api-inference.huggingface.co',
            new Options(maxNewTokens: 500),
        );

        $body = json_decode($request->body, true);

        self::assertSame(500, $body['max_tokens']);
    }

    #[Test]
    public function emptyInstructionsOmitsSystemMessage(): void
    {
        $request = RequestBuilder::build(
            self::invocation(instructions: ''),
            self::model(),
            'hf_tok',
            'https://api-inference.huggingface.co',
        );

        $body = json_decode($request->body, true);

        foreach ($body['messages'] as $message) {
            self::assertNotSame('system', $message['role'] ?? null);
        }
    }

    private static function invocation(string $instructions = ''): Invocation
    {
        return Invocation::of(
            id: 'inv_pericles',
            agentId: 'pericles',
            activityId: 'act_agora',
            contextHash: str_repeat('f', 64),
            instructions: $instructions,
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
            dynamicContext: ['user_input' => 'What is the Athenian strategy?'],
        );
    }

    private static function model(): Model
    {
        return Model::of(
            name: 'meta-llama-3.1-70b-instruct',
            modelId: 'meta-llama/Meta-Llama-3.1-70B-Instruct',
            aliases: ['llama-70b'],
            capabilities: Capabilities::of(Capability::ToolUse),
        );
    }
}
