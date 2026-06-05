<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Agent\Loader;

use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Agent\Loader;
use Phalanx\AiProviders\Agent\Registry;

/**
 * Loader backed by an explicit list of pre-constructed Agent instances.
 * Useful for tests and programmatic registration where the full
 * attribute-scan or manifest pipeline is not needed.
 *
 * Final — no extension points; the loaded set is the constructor args.
 */
final class Manual implements Loader
{
    /** @var list<Agent> */
    private(set) array $agents;

    public function __construct(Agent ...$agents)
    {
        $this->agents = array_values($agents);
    }

    public function load(): Registry
    {
        $registry = Registry::empty();

        foreach ($this->agents as $agent) {
            $registry = $registry->with($agent);
        }

        return $registry;
    }
}
