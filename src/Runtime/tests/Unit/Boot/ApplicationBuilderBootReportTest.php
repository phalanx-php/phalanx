<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Boot;

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\BootHarnessReport;
use Phalanx\Boot\Required;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplicationBuilderBootReportTest extends TestCase
{
    #[Test]
    public function exposesLastBootReportAfterCompile(): void
    {
        $builder = Application::starting(['CRITICAL_KEY' => 'value'])
            ->providers(new ApplicationBuilderBootReportBundle());

        self::assertNull($builder->lastBootReport(), 'Report is null before compile().');

        $app = $builder->compile();
        $report = $builder->lastBootReport();

        self::assertInstanceOf(BootHarnessReport::class, $report);
        self::assertTrue($report->isClean());

        $app->shutdown();
    }
}

final class ApplicationBuilderBootReportBundle extends ServiceBundle
{
    #[\Override]
    public static function harness(): BootHarness
    {
        return BootHarness::of(Required::env('CRITICAL_KEY'));
    }

    public function services(Services $services, AppContext $context): void {}
}
