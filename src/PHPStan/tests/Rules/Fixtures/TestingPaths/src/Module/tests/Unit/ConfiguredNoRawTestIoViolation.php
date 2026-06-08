<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Src\Module\Tests\Unit;

final class ConfiguredNoRawTestIoViolation
{
    public function run(): void
    {
        file_put_contents('fixture.txt', 'fixture');
    }
}
