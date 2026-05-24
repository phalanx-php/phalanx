<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Agent\Loader;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Agent\Loader;
use Phalanx\Panoply\Agent\Registry;

/**
 * Loader that merges the Registries produced by multiple child loaders.
 * Loaders are run in declaration order; first match wins on duplicate IDs.
 *
 * When two loaders both emit an Agent with the same ID, the Agent from the
 * first loader is kept and the conflict is recorded in
 * {@see self::conflicts()}. Callers can inspect this map after `load()` for
 * diagnostic visibility.
 *
 * Conflict map shape: `{agentId => [fqcnFromFirstLoader, fqcnFromSecondLoader, ...]}`
 *
 * Final — no extension points.
 */
final class Composite implements Loader
{
    /** @var list<Loader> */
    private(set) array $loaders;

    /**
     * Keyed by agent ID; maps to the list of FQCNs that emitted that ID
     * (populated after load() is called).
     *
     * First-wins semantics: the element at index 0 is the WINNING loader's FQCN
     * (first match in declaration order). Subsequent elements are loser FQCNs in
     * declaration order. The winning class is the one present in the Registry.
     *
     * @var array<string, list<string>>
     */
    private(set) array $conflicts = [];

    public function __construct(Loader ...$loaders)
    {
        $this->loaders = array_values($loaders);
    }

    public function load(): Registry
    {
        $this->conflicts = [];

        /** @var array<string, list<string>> $seen  agent id => list of FQCNs that emitted it */
        $seen = [];
        $registry = Registry::empty();

        foreach ($this->loaders as $loader) {
            $sub = $loader->load();

            /** @var Agent $agent */
            foreach ($sub->all()->toArray() as $agent) {
                $id = $agent->id;
                $fqcn = $agent::class;

                if (!$registry->has($id)) {
                    $registry = $registry->with($agent);
                    $seen[$id] = [$fqcn];
                } else {
                    $seen[$id][] = $fqcn;
                }
            }
        }

        // Collect entries where more than one FQCN emitted the same id.
        foreach ($seen as $id => $fqcns) {
            if (count($fqcns) > 1) {
                $this->conflicts[$id] = $fqcns;
            }
        }

        return $registry;
    }
}
