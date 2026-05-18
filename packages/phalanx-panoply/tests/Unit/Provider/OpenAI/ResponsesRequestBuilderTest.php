<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\OpenAI;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\OpenAI\ResponsesOptions;
use Phalanx\Panoply\Provider\OpenAI\ResponsesRequestBuilder;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponsesRequestBuilderTest extends TestCase
{
    #[Test]
    public function requestMethodIsPost(): void
    {
        $request = ResponsesRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );

        self::assertSame('POST', $request->method);
    }

    #[Test]
    public function requestUrlIsResponsesEndpoint(): void
    {
        $request = ResponsesRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );

        self::assertSame('https://api.openai.com/v1/responses', $request->url);
    }

    #[Test]
    public function hostOnlyBaseUrlGetsV1PathInserted(): void
    {
        // When base_url is host-only (the canonical openai.panoply.yaml convention),
        // the builder inserts /v1 before the endpoint suffix.
        $request = ResponsesRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com',
        );

        self::assertSame('https://api.openai.com/v1/responses', $request->url);
    }

    #[Test]
    public function requiredHeadersArePresent(): void
    {
        $request = ResponsesRequestBuilder::build(
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
    public function bodyContainsModelAndStream(): void
    {
        $request = ResponsesRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body    = json_decode($request->body, associative: true);

        self::assertSame('o3', $body['model']);
        self::assertTrue($body['stream']);
    }

    #[Test]
    public function userInputFallbackToInput(): void
    {
        $invocation = self::invocationWith(['user_input' => 'Describe Thermopylae.']);
        $request    = ResponsesRequestBuilder::build(
            $invocation,
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body       = json_decode($request->body, associative: true);

        self::assertSame('Describe Thermopylae.', $body['input']);
    }

    #[Test]
    public function prefersDynamicContextInput(): void
    {
        $invocation = self::invocationWith(['input' => 'explicit input', 'user_input' => 'fallback']);
        $request    = ResponsesRequestBuilder::build(
            $invocation,
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body       = json_decode($request->body, associative: true);

        self::assertSame('explicit input', $body['input']);
    }

    #[Test]
    public function instructionsAddedWhenNonEmpty(): void
    {
        $request = ResponsesRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body    = json_decode($request->body, associative: true);

        self::assertSame('Think like a Spartan hoplite.', $body['instructions']);
    }

    #[Test]
    public function reasoningEffortAddedWhenSet(): void
    {
        $request = ResponsesRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
            new ResponsesOptions(reasoningEffort: 'high'),
        );
        $body = json_decode($request->body, associative: true);

        self::assertSame(['effort' => 'high'], $body['reasoning']);
    }

    #[Test]
    public function defaultHeadersMergedIntoRequest(): void
    {
        $request = ResponsesRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
            new ResponsesOptions(),
            ['X-Phalanx-Agent' => 'Leonidas', 'X-Session' => 'thermopylae'],
        );

        self::assertSame('Leonidas', $request->headers['X-Phalanx-Agent']);
        self::assertSame('thermopylae', $request->headers['X-Session']);
        // Authorization header still present — default headers do not override it.
        self::assertSame('Bearer key_test', $request->headers['authorization']);
    }

    #[Test]
    public function nullOptionsOmitFieldsFromBody(): void
    {
        $request = ResponsesRequestBuilder::build(
            self::invocation(),
            self::model(),
            'key_test',
            'https://api.openai.com/v1',
        );
        $body    = json_decode($request->body, associative: true);

        self::assertArrayNotHasKey('reasoning', $body);
        self::assertArrayNotHasKey('max_output_tokens', $body);
        self::assertArrayNotHasKey('temperature', $body);
        self::assertArrayNotHasKey('top_p', $body);
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
            id: 'inv_odysseus',
            agentId: 'odysseus',
            activityId: 'act_ithaca',
            contextHash: str_repeat('d', 64),
            instructions: 'Think like a Spartan hoplite.',
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
            name: 'o3',
            modelId: 'o3',
            aliases: ['o3-latest'],
            capabilities: Capabilities::of(Capability::Reasoning),
        );
    }
}
