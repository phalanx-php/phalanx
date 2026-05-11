<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Unit\Response\Ignition;

use PHPUnit\Framework\TestCase;
use Phalanx\Stoa\Response\Ignition\PhalanxErrorPageViewModel;
use Spatie\FlareClient\Report;
use Spatie\Ignition\Config\IgnitionConfig;
use Spatie\Ignition\Solutions\SolutionTransformer;
use RuntimeException;

final class PhalanxErrorPageViewModelTest extends TestCase
{
    public function test_it_loads_assets_from_phalanx_resources(): void
    {
        $e = new RuntimeException('test');
        $report = new Report();
        $config = new IgnitionConfig();
        
        $viewModel = new PhalanxErrorPageViewModel(
            $e,
            $config,
            $report,
            [],
            SolutionTransformer::class
        );

        $css = $viewModel->getAssetContents('ignition.css');
        
        $this->assertNotEmpty($css);
        $this->assertStringNotContainsString('Asset ignition.css not found', $css);
        $this->assertStringContainsString('html', $css);
    }

    public function test_it_scrubs_laravel_solutions(): void
    {
        $e = new RuntimeException('test');
        $report = new Report();
        $config = new IgnitionConfig();
        
        $viewModel = new PhalanxErrorPageViewModel(
            $e,
            $config,
            $report,
            [new LaravelDummySolution(), new PhalanxDummySolution()],
            SolutionTransformer::class
        );

        $solutions = $viewModel->solutions();
        
        $this->assertCount(1, $solutions);
        $this->assertSame('Phalanx Solution', reset($solutions)['title']);
    }
}

/** Dummies for test_it_scrubs_laravel_solutions */
class LaravelDummySolution implements \Spatie\Ignition\Contracts\Solution {
    public function getSolutionTitle(): string { return 'Laravel Solution'; }
    public function getSolutionDescription(): string { return 'D'; }
    public function getDocumentationLinks(): array { return []; }
}
class PhalanxDummySolution implements \Spatie\Ignition\Contracts\Solution {
    public function getSolutionTitle(): string { return 'Phalanx Solution'; }
    public function getSolutionDescription(): string { return 'D'; }
    public function getDocumentationLinks(): array { return []; }
}
