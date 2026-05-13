<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Pool;

/** Deliberate readonly fixture — proves resetAsLazyGhost cannot recycle readonly properties. */
final class ReadonlyStub
{
    public readonly string $id;
    public readonly int $code;
    public string $label = '';
    public float $score = 0.0;
}

final class AsymmetricStub
{
    private(set) string $id = '';
    private(set) int $code = 0;
    public string $label = '';
    public float $score = 0.0;
}
