<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Boot;

use Phalanx\Application;
use Phalanx\Boot\BootHarnessReport;
use Phalanx\Runtime\Tests\Support\Fixtures\ApplicationBuilderBootReportBundle;
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

        try {
            $report = $builder->lastBootReport();

            self::assertInstanceOf(BootHarnessReport::class, $report);
            self::assertTrue($report->isClean());
        } finally {
            $app->shutdown();
        }
    }
}
