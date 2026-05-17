<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Agent;

/**
 * Marker attribute for agent classes that should be included when an
 * attribute-based loader scans a namespace or directory. No constructor
 * arguments — the presence of the attribute is the entire contract.
 *
 * Final — subclassing would change attribute identity and break
 * reflection-based discovery.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Discovered
{
}
