<?php

declare(strict_types=1);

namespace Phalanx\Athena\Examples\Support;

use Phalanx\Boot\AppContext;

/**
 * Reads the ATHENA_DEMO_LIVE flag from an AppContext.
 *
 * Live mode enables provider keys that require real API credentials.
 * When live mode is off, those keys are treated as absent regardless
 * of what the environment contains.
 */
final class LiveModeFlag
{
    public function __construct(private readonly AppContext $context)
    {
    }

    public function isEnabled(): bool
    {
        return $this->context->bool(DemoContextKeys::ATHENA_DEMO_LIVE, false);
    }

    /**
     * Return an AppContext with live-only keys stripped when live mode is off.
     *
     * Produces an effective context that demos can read without needing to
     * check the live flag themselves on every key access.
     */
    public function effective(): AppContext
    {
        if ($this->isEnabled()) {
            return $this->context;
        }

        $values = $this->context->values;
        foreach (DemoContextKeys::liveKeys() as $key) {
            unset($values[$key]);
        }

        return new AppContext($values);
    }
}
