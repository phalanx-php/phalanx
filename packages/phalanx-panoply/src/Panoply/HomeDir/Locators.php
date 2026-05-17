<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

use Phalanx\Panoply\Series;

/**
 * Final — Series leaf carries a sealed element type.
 *
 * Typed series over {@see Locator} entries. Consumers can use every
 * inherited combinator immediately; domain-specific filters live
 * alongside the per-tool HomeDir implementations that produce them.
 *
 * @extends Series<Locator>
 */
final class Locators extends Series
{
}
