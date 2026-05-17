<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Agent;

use Phalanx\Panoply\Agent\Registry;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Tests\Unit\Agent\Stubs\StubAgent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    #[Test]
    public function emptyStartsEmpty(): void
    {
        $registry = Registry::empty();

        self::assertSame([], $registry->all());
    }

    #[Test]
    public function withIsImmutable(): void
    {
        $agent = new StubAgent('leonidas', Capability::Reasoning);
        $a     = Registry::empty();
        $b     = $a->with($agent);

        self::assertNotSame($a, $b);
        self::assertFalse($a->has('leonidas'));
        self::assertTrue($b->has('leonidas'));
    }

    #[Test]
    public function getReturnsAgentById(): void
    {
        $agent    = new StubAgent('leonidas', Capability::Reasoning);
        $registry = Registry::empty()->with($agent);

        self::assertSame($agent, $registry->get('leonidas'));
    }

    #[Test]
    public function getReturnsNullForUnknown(): void
    {
        self::assertNull(Registry::empty()->get('missing'));
    }

    #[Test]
    public function hasReturnsTrueForRegisteredAgent(): void
    {
        $registry = Registry::empty()->with(new StubAgent('odysseus', Capability::ToolUse));

        self::assertTrue($registry->has('odysseus'));
        self::assertFalse($registry->has('achilles'));
    }

    #[Test]
    public function allReturnsAllAgents(): void
    {
        $registry = Registry::empty()
            ->with(new StubAgent('leonidas', Capability::Reasoning))
            ->with(new StubAgent('odysseus', Capability::ToolUse));

        self::assertCount(2, $registry->all());
        self::assertArrayHasKey('leonidas', $registry->all());
        self::assertArrayHasKey('odysseus', $registry->all());
    }

    #[Test]
    public function byCapabilityFiltersCorrectly(): void
    {
        $registry = Registry::empty()
            ->with(new StubAgent('leonidas', Capability::Reasoning))
            ->with(new StubAgent('odysseus', Capability::ToolUse))
            ->with(new StubAgent('achilles', Capability::Reasoning));

        $reasoners = $registry->byCapability(Capability::Reasoning);

        self::assertCount(2, $reasoners);
        self::assertArrayHasKey('leonidas', $reasoners);
        self::assertArrayHasKey('achilles', $reasoners);
        self::assertArrayNotHasKey('odysseus', $reasoners);
    }

    #[Test]
    public function byCapabilityReturnsEmptyForNoMatch(): void
    {
        $registry = Registry::empty()->with(new StubAgent('leonidas', Capability::Reasoning));

        $result = $registry->byCapability(Capability::Vision);

        self::assertSame([], $result);
    }
}

namespace Phalanx\Panoply\Tests\Unit\Agent\Stubs;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;

final class StubAgent implements Agent
{
    public string $id        { get => $this->agentId; }

    public string $name      { get => ucfirst($this->agentId); }

    public string $purpose   { get => 'Stub agent for ' . $this->agentId; }

    public Capabilities $capabilities { get => Capabilities::of($this->cap); }

    public Context $context     { get => Context::new(); }

    public Effects $effects     { get => Effects::allow(EffectKind::FileRead); }

    public ProviderNeeds $provider { get => ProviderNeeds::new()->prefer(Preference::LocalFirst)->require($this->cap); }

    public TransportNeeds $transport { get => TransportNeeds::new()->streaming(); }

    public Output $output        { get => Output::artifact(ArtifactKind::Thesis); }

    public function __construct(
        private readonly string $agentId,
        private readonly Capability $cap,
    ) {
    }
}
