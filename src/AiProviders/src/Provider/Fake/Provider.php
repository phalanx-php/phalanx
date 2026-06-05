<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Provider\Fake;

use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Cue;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider as ProviderContract;
use Phalanx\AiProviders\Runtime;
use Phalanx\AiProviders\Stream;

/**
 * Scriptable {@see ProviderContract} for end-to-end stream testing.
 * The constructor accepts a list of pre-built {@see Cue} instances to
 * emit in order, plus a {@see Capabilities} advertisement.
 * {@see self::perform()} returns a {@see Stream} that lazily yields each
 * scripted Cue, honoring runtime cancellation between cues.
 *
 * Final — extension would alter the deterministic-script contract that
 * tests rely on.
 */
final class Provider implements ProviderContract
{
    /**
     * @param list<Cue> $script
     */
    public function __construct(
        private(set) array $script,
        private(set) Capabilities $capabilities,
    ) {
    }

    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        $script = $this->script;

        return new Stream(static function () use ($script, $runtime): \Generator {
            foreach ($script as $cue) {
                $runtime->throwIfCancelled();
                yield $cue;
            }
        });
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }
}
