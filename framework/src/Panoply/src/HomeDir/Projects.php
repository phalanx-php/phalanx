<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

use Phalanx\Panoply\Series;

/**
 * Typed Series leaf over {@see Project}. Vendor HomeDir adapters may
 * subclass to add tool-specific predicates (e.g., `byCwd(string $cwd): static`);
 * subclasses must add only methods, not constructor state, per Series contract.
 *
 * @extends Series<Project>
 */
class Projects extends Series
{
}
