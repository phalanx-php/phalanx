<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Agent;

use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Agent\Collection;
use Phalanx\AiProviders\Agent\Registry;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Tests\Unit\Agent\Stubs\StubAgent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    #[Test]
    public function emptyStartsEmpty(): void
    {
        $all = Registry::empty()->all();

        self::assertInstanceOf(Collection::class, $all);
        self::assertSame(0, $all->count());
    }

    #[Test]
    public function withIsImmutable(): void
    {
        $agent = new StubAgent('leonidas', Capability::Reasoning);
        $a = Registry::empty();
        $b = $a->with($agent);

        self::assertNotSame($a, $b);
        self::assertFalse($a->has('leonidas'));
        self::assertTrue($b->has('leonidas'));
    }

    #[Test]
    public function getReturnsAgentById(): void
    {
        $agent = new StubAgent('leonidas', Capability::Reasoning);
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
    public function allReturnsCollectionOfAllAgents(): void
    {
        $registry = Registry::empty()
            ->with(new StubAgent('leonidas', Capability::Reasoning))
            ->with(new StubAgent('odysseus', Capability::ToolUse));

        $all = $registry->all();

        self::assertInstanceOf(Collection::class, $all);
        self::assertSame(2, $all->count());
    }

    #[Test]
    public function byCapabilityFiltersCorrectly(): void
    {
        $leonidas = new StubAgent('leonidas', Capability::Reasoning);
        $odysseus = new StubAgent('odysseus', Capability::ToolUse);
        $achilles = new StubAgent('achilles', Capability::Reasoning);

        $registry = Registry::empty()
            ->with($leonidas)
            ->with($odysseus)
            ->with($achilles);

        $reasoners = $registry->byCapability(Capability::Reasoning);

        self::assertInstanceOf(Collection::class, $reasoners);
        self::assertSame(2, $reasoners->count());

        $ids = array_map(static fn (Agent $a): string => $a->id, $reasoners->toArray());
        self::assertContains('leonidas', $ids);
        self::assertContains('achilles', $ids);
        self::assertNotContains('odysseus', $ids);
    }

    #[Test]
    public function byCapabilityReturnsEmptyCollectionForNoMatch(): void
    {
        $registry = Registry::empty()->with(new StubAgent('leonidas', Capability::Reasoning));

        $result = $registry->byCapability(Capability::Vision);

        self::assertInstanceOf(Collection::class, $result);
        self::assertSame(0, $result->count());
    }
}

namespace Phalanx\AiProviders\Tests\Unit\Agent\Stubs;

use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Provider\Preference;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;

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
        private(set) string $agentId,
        private(set) Capability $cap,
    ) {
    }
}
