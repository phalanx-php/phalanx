<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing;

use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Tests\Fixtures\Testing\FixtureBundle;
use Phalanx\Tests\Fixtures\Testing\FixtureLens;

final class PhalanxTestCaseTestAppTest extends PhalanxTestCase
{
    public function testEachTestAppCallProducesFreshInstance(): void
    {
        $first = $this->testApp();
        $second = $this->testApp();

        self::assertNotSame($first, $second);
        self::assertNotSame($first->application, $second->application);
    }

    public function testTestAppAcceptsBundlesAndContext(): void
    {
        $app = $this->testApp(['argv' => ['demo']], new FixtureBundle());
        $lens = $app->lens(FixtureLens::class);

        self::assertInstanceOf(FixtureLens::class, $lens);
        self::assertSame(0, $lens->resetCount);
    }
}
