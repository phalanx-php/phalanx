<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

use Phalanx\Panoply\Series;

/**
 * Typed series over {@see Project} entries. Consumers can use every
 * inherited combinator immediately; domain-specific filters live
 * alongside the per-tool HomeDir implementations that produce them.
 *
 * @extends Series<Project>
 */
final class Projects extends Series
{
}
