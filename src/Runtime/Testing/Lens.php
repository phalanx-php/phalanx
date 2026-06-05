<?php

declare(strict_types=1);

namespace Phalanx\Testing;

/**
 * Marker contract for a per-package testing surface attached to TestApp.
 *
 * Lenses observe a single TestApp instance through accessor properties
 * generated from #[Lens] attributes. They never own a runtime; they
 * read from and act on the live app's services, scope, and ledger.
 *
 * reset() is invoked by TestApp::reset() between PHPUnit tests so any
 * per-test state captured by the lens (acting identity, captured streams,
 * recorded calls) does not bleed across tests.
 */
interface Lens
{
    public function reset(): void;
}
