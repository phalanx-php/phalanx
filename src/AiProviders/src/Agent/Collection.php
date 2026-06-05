<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Agent;

use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Series;

/**
 * Typed Series leaf over {@see Agent}. Vendor agent adapters may subclass
 * to add domain-specific predicates; subclasses must add only methods,
 * not constructor state, per the Series contract.
 *
 * @extends Series<Agent>
 */
class Collection extends Series
{
}
