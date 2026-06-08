<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance;

use Phalanx\Stream\Stream;
use Phalanx\Testing\UsesTempWorkspace;

final class NoRawTestIoValid
{
    use UsesTempWorkspace;

    public function run(): void
    {
        Stream::memoryBuffer('fixture');
        Stream::captureBuffer();
        Stream::nullInput();

        $this->tempWorkspace()->file('fixture.txt', 'fixture');
    }
}
