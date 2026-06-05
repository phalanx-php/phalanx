<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Conversation;

/**
 * Abstract input carrier for {@see Parser::parse()}. Concrete
 * implementations live alongside their parser — for example, a file-based
 * source carries the path and tool-specific metadata while a stream source
 * carries a readable handle. The base class intentionally carries no shared
 * state; subclasses define exactly what their parser needs.
 */
abstract class Source
{
}
