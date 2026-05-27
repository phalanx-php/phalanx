<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Tests\Unit;

use Phalanx\DoryBin\BuildConfig;
use Phalanx\DoryBin\BuildProfile;
use Phalanx\Themis\IssueLevel;
use Phalanx\Themis\ValidationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuildConfig::class)]
final class BuildConfigTest extends TestCase
{
    #[Test]
    public function defaults_are_sensible(): void
    {
        $config = new BuildConfig();

        self::assertSame('/tmp/dory-build', $config->buildRoot);
        self::assertSame('', $config->spcPath);
        self::assertSame('full', $config->defaultProfile);
        self::assertFalse($config->verbose);
        self::assertTrue($config->cacheEnabled);
        self::assertSame('~/.config/dory', $config->iniPath);
        self::assertSame('~/.config/dory/conf.d', $config->iniScanDir);
    }

    #[Test]
    public function configured_is_true_when_build_root_is_non_empty(): void
    {
        $config = new BuildConfig(buildRoot: '/tmp/zeus-build');

        self::assertTrue($config->configured);
    }

    #[Test]
    public function configured_is_false_when_build_root_is_empty(): void
    {
        $config = new BuildConfig(buildRoot: '');

        self::assertFalse($config->configured);
    }

    #[Test]
    public function valid_config_produces_no_issues(): void
    {
        $config = new BuildConfig();
        $issues = $config->validate(new ValidationContext());

        self::assertCount(0, $issues);
    }

    #[Test]
    public function empty_build_root_fails_validation(): void
    {
        $config = new BuildConfig(buildRoot: '');
        $issues = $config->validate(new ValidationContext());

        $codes = array_map(static fn($i) => $i->code, $issues);
        self::assertContains('dory.build.build-root', $codes);

        $error = array_find($issues, static fn($i) => $i->code === 'dory.build.build-root');
        self::assertNotNull($error);
        self::assertSame(IssueLevel::Error, $error->level);
        self::assertSame('DORY_BUILD_ROOT', $error->envKey);
    }

    #[Test]
    public function empty_ini_path_fails_validation(): void
    {
        $config = new BuildConfig(iniPath: '');
        $issues = $config->validate(new ValidationContext());

        $codes = array_map(static fn($i) => $i->code, $issues);
        self::assertContains('dory.build.ini-path', $codes);
    }

    #[Test]
    public function invalid_profile_name_fails_validation(): void
    {
        $config = new BuildConfig(defaultProfile: 'leonidas');
        $issues = $config->validate(new ValidationContext());

        $codes = array_map(static fn($i) => $i->code, $issues);
        self::assertContains('dory.build.default-profile', $codes);

        $error = array_find($issues, static fn($i) => $i->code === 'dory.build.default-profile');
        self::assertNotNull($error);
        self::assertSame(IssueLevel::Error, $error->level);
        self::assertSame('DORY_BUILD_PROFILE', $error->envKey);
    }

    #[Test]
    public function each_valid_profile_passes_validation(): void
    {
        foreach (BuildProfile::cases() as $profile) {
            $config = new BuildConfig(defaultProfile: $profile->value);
            $issues = $config->validate(new ValidationContext());

            $profileIssues = array_filter($issues, static fn($i) => $i->code === 'dory.build.default-profile');
            self::assertCount(0, $profileIssues, "Profile '{$profile->value}' should pass validation.");
        }
    }

    #[Test]
    public function multiple_errors_accumulate(): void
    {
        $config = new BuildConfig(buildRoot: '', defaultProfile: 'olympus', iniPath: '');
        $issues = $config->validate(new ValidationContext());

        self::assertGreaterThanOrEqual(3, count($issues));

        $codes = array_map(static fn($i) => $i->code, $issues);
        self::assertContains('dory.build.build-root', $codes);
        self::assertContains('dory.build.default-profile', $codes);
        self::assertContains('dory.build.ini-path', $codes);
    }

    #[Test]
    public function custom_values_are_stored(): void
    {
        $config = new BuildConfig(
            buildRoot: '/opt/zeus/build',
            spcPath: '/usr/local/bin/spc',
            defaultProfile: 'mini',
            verbose: true,
            cacheEnabled: false,
        );

        self::assertSame('/opt/zeus/build', $config->buildRoot);
        self::assertSame('/usr/local/bin/spc', $config->spcPath);
        self::assertSame('mini', $config->defaultProfile);
        self::assertTrue($config->verbose);
        self::assertFalse($config->cacheEnabled);
    }
}
