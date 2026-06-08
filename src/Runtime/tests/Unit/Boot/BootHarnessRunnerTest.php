<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Boot;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\BootHarnessRunner;
use Phalanx\Boot\Exception\CannotBootException;
use Phalanx\Boot\Optional;
use Phalanx\Boot\Required;
use Phalanx\Config\Config;
use Phalanx\Config\Env;
use Phalanx\Config\Issue;
use Phalanx\Config\IssueLevel;
use Phalanx\Config\ValidationContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RequiredEnvBundle extends ServiceBundle
{
    #[\Override]
    public static function harness(): BootHarness
    {
        return BootHarness::of(Required::env('CRITICAL_KEY'));
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}

final class OptionalEnvBundle extends ServiceBundle
{
    #[\Override]
    public static function harness(): BootHarness
    {
        return BootHarness::of(Optional::env('OPTIONAL_KEY'));
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}

final class DefaultHarnessBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
    }
}

final class ConfiguredBundle extends ServiceBundle
{
    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [BootRunnerConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}

final class BootRunnerConfig implements Config
{
    public bool $configured {
        get => $this->limit > 0;
    }

    public function __construct(
        #[Env('BOOT_RUNNER_LIMIT', 'Boot runner limit')]
        public int $limit = 1,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return $this->limit > 0
            ? []
            : [new Issue(IssueLevel::Error, 'boot-runner.limit', 'BOOT_RUNNER_LIMIT must be positive.', 'BOOT_RUNNER_LIMIT')];
    }
}

final class WarningConfigBundle extends ServiceBundle
{
    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [WarningBootRunnerConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}

final class WarningBootRunnerConfig implements Config
{
    public bool $configured {
        get => true;
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [new Issue(IssueLevel::Warning, 'boot-runner.warning', 'Config warning.')];
    }
}

final class ComposerExtraHarnessStub
{
    public static function buildHarness(): BootHarness
    {
        return BootHarness::of(Required::env('FROM_EXTRA'));
    }
}

final class BootHarnessRunnerTest extends PhalanxTestCase
{
    #[Test]
    public function runWithNoBundlesAndEmptyContextIsClean(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->run(new AppContext([]), []);

        self::assertTrue($report->isClean());
        self::assertFalse($report->hasFailures());
        self::assertFalse($report->hasWarnings());
        self::assertSame([], $report->failed);
        self::assertSame([], $report->warned);
    }

    #[Test]
    public function runWithMissingRequiredEnvProducesFailure(): void
    {
        $runner = new BootHarnessRunner();

        try {
            $runner->run(new AppContext([]), [new RequiredEnvBundle()]);
            self::fail('Expected CannotBootException was not thrown.');
        } catch (CannotBootException $e) {
            $report = $e->report;

            self::assertTrue($report->hasFailures());
            self::assertFalse($report->hasWarnings());

            $failedEntry = $report->failed[0];
            self::assertStringContainsString('CRITICAL_KEY', $failedEntry->evaluation->message);
        }
    }

    #[Test]
    public function runWithMissingOptionalEnvProducesWarning(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->run(new AppContext([]), [new OptionalEnvBundle()]);

        self::assertTrue($report->hasWarnings());
        self::assertFalse($report->hasFailures());
    }

    #[Test]
    public function throwIfFailedThrowsWithMeaningfulMessage(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->dryRun(
            new AppContext([]),
            BootHarness::of(Required::env('MISSING_KEY')),
        );

        $this->expectException(CannotBootException::class);
        $this->expectExceptionMessageMatches('/MISSING_KEY/');
        $this->expectExceptionMessageMatches('/\.env/');

        $runner->throwIfFailed($report);
    }

    #[Test]
    public function dryRunEvaluatesSuppliedHarnessDirectly(): void
    {
        $runner = new BootHarnessRunner();
        $harness = BootHarness::of(
            Required::env('A'),
            Optional::env('B'),
        );
        $report = $runner->dryRun(new AppContext(['A' => 'value']), $harness);

        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());
        self::assertCount(1, $report->passed);
        self::assertCount(1, $report->warned);
    }

    #[Test]
    public function runPicksUpComposerExtraHarness(): void
    {
        $tmpDir = $this->tempWorkspace('phalanx-harness-runner-')->dir('project');

        $stub = ComposerExtraHarnessStub::class;
        $installed = [
            'packages' => [
                [
                    'name' => 'test/pkg',
                    'extra' => [
                        'phalanx' => [
                            'harness' => $stub . '::buildHarness',
                        ],
                    ],
                ],
            ],
        ];
        $this->tempWorkspace()->file(
            'project/composer/installed.json',
            json_encode($installed, JSON_THROW_ON_ERROR),
        );

        try {
            $runner = new BootHarnessRunner();
            $runner->run(new AppContext([]), [], $tmpDir);
            self::fail('Expected CannotBootException for FROM_EXTRA was not thrown.');
        } catch (CannotBootException $e) {
            self::assertStringContainsString('FROM_EXTRA', $e->getMessage());
        }
    }

    #[Test]
    public function bundlesWithDefaultHarnessAreNoOps(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->run(new AppContext([]), [
            new DefaultHarnessBundle(),
            new DefaultHarnessBundle(),
        ]);

        self::assertTrue($report->isClean());
        self::assertSame([], $report->passed);
    }

    #[Test]
    public function runAcceptsBundleClassStrings(): void
    {
        $runner = new BootHarnessRunner();

        try {
            $runner->run(new AppContext([]), [RequiredEnvBundle::class]);
            self::fail('Expected CannotBootException was not thrown.');
        } catch (CannotBootException $e) {
            self::assertStringContainsString('CRITICAL_KEY', $e->getMessage());
        }
    }

    #[Test]
    public function runWithAllRequirementsSatisfiedReturnsCleanReport(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->run(
            new AppContext(['CRITICAL_KEY' => 'present', 'OPTIONAL_KEY' => 'also_present']),
            [new RequiredEnvBundle(), new OptionalEnvBundle()],
        );

        self::assertTrue($report->isClean());
        self::assertCount(2, $report->passed);
    }

    #[Test]
    public function runFailsWhenTypedConfigValidationFails(): void
    {
        $runner = new BootHarnessRunner();

        try {
            $runner->run(new AppContext(['BOOT_RUNNER_LIMIT' => '0']), [new ConfiguredBundle()]);
            self::fail('Expected CannotBootException was not thrown.');
        } catch (CannotBootException $e) {
            self::assertStringContainsString('BOOT_RUNNER_LIMIT must be positive', $e->getMessage());
            self::assertTrue($e->report->hasFailures());
        }
    }

    #[Test]
    public function runWarnsForTypedConfigWarningsUnlessStrict(): void
    {
        $runner = new BootHarnessRunner();

        $report = $runner->run(new AppContext([]), [new WarningConfigBundle()]);
        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());

        try {
            $runner->run(new AppContext(['PHALANX_CONFIG_STRICT' => 'true']), [new WarningConfigBundle()]);
            self::fail('Expected CannotBootException was not thrown.');
        } catch (CannotBootException $e) {
            self::assertStringContainsString('Config warning.', $e->getMessage());
        }
    }

    #[Test]
    public function contextSchemaListsBundleAndComposerExtraKeys(): void
    {
        $tmpDir = $this->tempWorkspace('phalanx-harness-schema-')->dir('project');

        $stub = ComposerExtraHarnessStub::class;
        $installed = [
            'packages' => [
                [
                    'name' => 'test/pkg',
                    'extra' => [
                        'phalanx' => [
                            'harness' => $stub . '::buildHarness',
                        ],
                    ],
                ],
            ],
        ];
        $this->tempWorkspace()->file(
            'project/composer/installed.json',
            json_encode($installed, JSON_THROW_ON_ERROR),
        );

        $schema = (new BootHarnessRunner())->contextSchema([RequiredEnvBundle::class], $tmpDir);
        $keys = $schema->all();

        self::assertCount(2, $keys);
        self::assertSame('CRITICAL_KEY', $keys[0]->name);
        self::assertSame(RequiredEnvBundle::class, $keys[0]->owner);
        self::assertSame('FROM_EXTRA', $keys[1]->name);
        self::assertSame('composer-extra', $keys[1]->owner);
        self::assertStringContainsString('CRITICAL_KEY', $schema->render());
        self::assertStringContainsString(RequiredEnvBundle::class, $schema->render());
    }

    #[Test]
    public function contextSchemaIncludesTypedConfigKeys(): void
    {
        $schema = (new BootHarnessRunner())->contextSchema([ConfiguredBundle::class], vendorDir: null);
        $keys = $schema->all();

        self::assertSame('BOOT_RUNNER_LIMIT', $keys[0]->name);
        self::assertSame(BootRunnerConfig::class, $keys[0]->owner);
        self::assertStringContainsString('BOOT_RUNNER_LIMIT', $schema->render());
    }
}
