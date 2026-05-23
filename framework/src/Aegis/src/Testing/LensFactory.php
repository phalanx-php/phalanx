<?php

declare(strict_types=1);

namespace Phalanx\Testing;

/**
 * Builds a Lens against a live TestApp.
 *
 * Factories are referenced from the #[Lens] attribute on the lens class
 * and resolved lazily by TestApp on first property access. They are the
 * single integration point a package exposes to the codegen plugin and to
 * userland — no other test-time wiring is required from a bundle author.
 *
 * Implementations MUST expose a public parameterless constructor; TestApp
 * instantiates factories via `new $factoryClass()`. Factories are stateless
 * by convention and exist solely to defer lens construction until first
 * accessor read.
 */
interface LensFactory
{
    public function create(TestApp $app): Lens;
}
