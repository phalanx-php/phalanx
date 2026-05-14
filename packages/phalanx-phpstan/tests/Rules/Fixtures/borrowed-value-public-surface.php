<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Pool\BorrowedValue;

final class PublicSurfaceBorrowedValue implements BorrowedValue
{
}

final class BorrowedValuePublicSurfaceFixture
{
    public ?PublicSurfaceBorrowedValue $publicStored = null;

    protected ?PublicSurfaceBorrowedValue $protectedStored = null;

    private ?PublicSurfaceBorrowedValue $privateStored = null;

    public function publicReturn(): PublicSurfaceBorrowedValue
    {
        return new PublicSurfaceBorrowedValue();
    }

    protected function protectedReturn(): ?PublicSurfaceBorrowedValue
    {
        return null;
    }

    public function publicParam(PublicSurfaceBorrowedValue $value): void
    {
        $this->privateStored = $value;
    }

    private function privateReturn(): PublicSurfaceBorrowedValue
    {
        return new PublicSurfaceBorrowedValue();
    }
}
