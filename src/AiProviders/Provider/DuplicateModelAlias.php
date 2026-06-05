<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Provider;

/**
 * Thrown by {@see Registry::with()} when a config introduces a model
 * name or alias that already exists in the registry. Fail-loud at
 * registration prevents silent ambiguity at {@see Registry::byModelAlias()}
 * lookup.
 *
 * Final — sealed exception with no extension hook.
 */
final class DuplicateModelAlias extends \LogicException
{
}
