<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Unit\Output;

use Phalanx\Agents\Exception\OutputHydrationError;
use Phalanx\Agents\Output\OutputHydrator;
use Phalanx\Agents\Testing\ScopeStub;
use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Output\Mode;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutputHydratorTest extends TestCase
{
    #[Test]
    public function hydratesValidJson(): void
    {
        $scope = new ScopeStub();
        $agent = self::agentWithOutput(Output::structured(WeatherResult::class));
        $raw = '{"city":"Athens","temperature":22.5}';

        $result = OutputHydrator::hydrate($scope, $raw, $agent);

        self::assertInstanceOf(WeatherResult::class, $result);
        self::assertSame('Athens', $result->city);
        self::assertEqualsWithDelta(22.5, $result->temperature, 0.001);
    }

    #[Test]
    public function returnsNullForTextMode(): void
    {
        $scope = new ScopeStub();
        $agent = self::agentWithOutput(Output::text());

        $result = OutputHydrator::hydrate($scope, 'any raw value', $agent);

        self::assertNull($result);
    }

    #[Test]
    public function returnsNullForArtifactMode(): void
    {
        $scope = new ScopeStub();
        $agent = self::agentWithOutput(Output::artifact(ArtifactKind::Thesis));

        $result = OutputHydrator::hydrate($scope, 'any raw value', $agent);

        self::assertNull($result);
    }

    #[Test]
    public function throwsOnMalformedJson(): void
    {
        $this->expectException(OutputHydrationError::class);
        $this->expectExceptionMessage('Failed to decode output JSON');

        $scope = new ScopeStub();
        $agent = self::agentWithOutput(Output::structured(WeatherResult::class));

        OutputHydrator::hydrate($scope, '{not valid json}', $agent);
    }

    #[Test]
    public function throwsOnNonStringInput(): void
    {
        $this->expectException(OutputHydrationError::class);
        $this->expectExceptionMessage('Expected JSON string for structured output');

        $scope = new ScopeStub();
        $agent = self::agentWithOutput(Output::structured(WeatherResult::class));

        OutputHydrator::hydrate($scope, 42, $agent);
    }

    #[Test]
    public function throwsWhenDecodedValueIsNotArray(): void
    {
        $this->expectException(OutputHydrationError::class);
        $this->expectExceptionMessage('Decoded output must be an array');

        $scope = new ScopeStub();
        $agent = self::agentWithOutput(Output::structured(WeatherResult::class));

        OutputHydrator::hydrate($scope, '"just a string"', $agent);
    }

    #[Test]
    public function throwsWhenSchemaConstructorDoesNotMatchFields(): void
    {
        $this->expectException(OutputHydrationError::class);
        $this->expectExceptionMessage('Failed to construct');

        $scope = new ScopeStub();
        $agent = self::agentWithOutput(Output::structured(WeatherResult::class));

        OutputHydrator::hydrate($scope, '{"wrong_field":"value"}', $agent);
    }

    #[Test]
    public function throwsWhenSchemaIsNull(): void
    {
        $this->expectException(OutputHydrationError::class);
        $this->expectExceptionMessage('Structured output mode requires a schema class-string');

        $scope = new ScopeStub();
        $output = self::structuredOutputWithNullSchema();
        $agent = self::agentWithOutput($output);

        OutputHydrator::hydrate($scope, '{}', $agent);
    }

    private static function agentWithOutput(Output $output): Agent
    {
        return new class ($output) implements Agent {
            public string $id       { get => 'test-agent'; }
            public string $name     { get => 'Test Agent'; }
            public string $purpose  { get => 'Test agent for OutputHydrator.'; }

            public Output $output { get => $this->outputValue; }

            public Context $context {
                get => Context::new();
            }

            public Effects $effects {
                get => Effects::allow(EffectKind::FileRead);
            }

            public ProviderNeeds $provider {
                get => ProviderNeeds::new();
            }

            public Capabilities $capabilities {
                get => Capabilities::of(Capability::Reasoning);
            }

            public TransportNeeds $transport {
                get => TransportNeeds::new();
            }

            public function __construct(private readonly Output $outputValue)
            {
            }
        };
    }

    private static function structuredOutputWithNullSchema(): Output
    {
        $ref = new \ReflectionClass(Output::class);
        $obj = $ref->newInstanceWithoutConstructor();

        $modeProp = $ref->getProperty('mode');
        $modeProp->setValue($obj, Mode::Structured);

        $schemaProp = $ref->getProperty('schema');
        $schemaProp->setValue($obj, null);

        $artifactProp = $ref->getProperty('artifactKind');
        $artifactProp->setValue($obj, null);

        return $obj;
    }
}

final class WeatherResult
{
    public function __construct(
        private(set) string $city,
        private(set) float $temperature,
    ) {
    }
}
