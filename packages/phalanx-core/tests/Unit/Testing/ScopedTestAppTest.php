<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing;

use InvalidArgumentException;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopedTestAppTest extends TestCase
{
    #[Test]
    public function run_enforces_static_closure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('static');

        TestScope::run(function () {});
    }

    #[Test]
    public function compile_run_enforces_static_closure(): void
    {
        $app = TestScope::compile();

        $this->expectException(InvalidArgumentException::class);

        try {
            $app->run(function () {});
        } finally {
            $app->shutdown();
        }
    }
}
