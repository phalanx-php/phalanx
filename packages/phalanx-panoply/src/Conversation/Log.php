<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation;

use Phalanx\Panoply\Series;

/**
 * Typed series over normalized conversation records. Consumers can use
 * every inherited combinator (where/map/take/skip/etc.) immediately.
 * Domain-specific filters live alongside the {@see Record} taxonomy.
 *
 * @extends Series<Record>
 */
final class Log extends Series
{
}
