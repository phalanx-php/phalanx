<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

use Phalanx\Panoply\Series;

/**
 * Typed series over {@see Locator} entries. Consumers can use every
 * inherited combinator immediately; domain-specific filters live
 * alongside the per-tool HomeDir implementations that produce them.
 *
 * @extends Series<Locator>
 */
class Locators extends Series
{
}
