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

    /** @var list<PublicSurfaceBorrowedValue> */
    public array $publicList = [];

    protected ?PublicSurfaceBorrowedValue $protectedStored = null;

    /** @var array<string, PublicSurfaceBorrowedValue> */
    protected array $protectedMap = [];

    private ?PublicSurfaceBorrowedValue $privateStored = null;

    /** @var list<PublicSurfaceBorrowedValue> */
    private array $privateList = [];

    public function publicReturn(): PublicSurfaceBorrowedValue
    {
        return new PublicSurfaceBorrowedValue();
    }

    /** @return list<PublicSurfaceBorrowedValue> */
    public function publicGenericReturn(): array
    {
        return [];
    }

    protected function protectedReturn(): ?PublicSurfaceBorrowedValue
    {
        return null;
    }

    public function publicParam(PublicSurfaceBorrowedValue $value): void
    {
        $this->privateStored = $value;
    }

    /** @param list<PublicSurfaceBorrowedValue> $values */
    public function publicGenericParam(array $values): void
    {
        $this->privateList = $values;
    }

    /** @return list<PublicSurfaceBorrowedValue> */
    private function privateGenericReturn(): array
    {
        return [];
    }

    private function privateReturn(): PublicSurfaceBorrowedValue
    {
        return new PublicSurfaceBorrowedValue();
    }
}
