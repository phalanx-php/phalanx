<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Tests\Unit;

use Phalanx\DoryBin\BuildProfile;
use Phalanx\DoryBin\BuildProfileDefinition;
use Phalanx\DoryBin\BuildProfileRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(BuildProfileRegistry::class)]
final class BuildProfileRegistryTest extends TestCase
{
    private string $profileDir;

    protected function setUp(): void
    {
        $this->profileDir = BuildProfileRegistry::defaultProfileDir();
    }

    #[Test]
    public function default_profile_dir_is_resolvable(): void
    {
        self::assertDirectoryExists($this->profileDir);
    }

    #[Test]
    public function can_load_mini_profile(): void
    {
        $registry = new BuildProfileRegistry($this->profileDir);
        $definition = $registry->get(BuildProfile::Mini);

        self::assertInstanceOf(BuildProfileDefinition::class, $definition);
        self::assertSame('mini', $definition->profile->value);
        self::assertNotEmpty($definition->requiredExtensions);
        self::assertContains('openswoole', $definition->requiredExtensions);
    }

    #[Test]
    public function can_load_full_profile(): void
    {
        $registry = new BuildProfileRegistry($this->profileDir);
        $definition = $registry->get(BuildProfile::Full);

        self::assertSame('full', $definition->profile->value);
        self::assertNotEmpty($definition->description);
        self::assertSame('8.4', $definition->phpVersion);
    }

    #[Test]
    public function all_returns_all_profiles_with_yaml_files(): void
    {
        $registry = new BuildProfileRegistry($this->profileDir);
        $all = $registry->all();

        self::assertNotEmpty($all);

        foreach ($all as $definition) {
            self::assertInstanceOf(BuildProfileDefinition::class, $definition);
        }

        // Every profile that has a yaml file should be present
        $names = array_map(static fn(BuildProfileDefinition $d) => $d->profile->value, $all);
        self::assertContains('mini', $names);
        self::assertContains('full', $names);
    }

    #[Test]
    public function get_by_name_works_for_valid_profile(): void
    {
        $registry = new BuildProfileRegistry($this->profileDir);
        $definition = $registry->getByName('mini');

        self::assertSame('mini', $definition->profile->value);
    }

    #[Test]
    public function missing_profile_throws_runtime_exception(): void
    {
        $registry = new BuildProfileRegistry($this->profileDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/Build profile 'apollo' not found/");

        $registry->getByName('apollo');
    }

    #[Test]
    public function has_returns_true_for_existing_profile(): void
    {
        $registry = new BuildProfileRegistry($this->profileDir);

        self::assertTrue($registry->has('mini'));
        self::assertTrue($registry->has('full'));
    }

    #[Test]
    public function has_returns_false_for_missing_profile(): void
    {
        $registry = new BuildProfileRegistry($this->profileDir);

        self::assertFalse($registry->has('sparta'));
        self::assertFalse($registry->has('thermopylae'));
    }

    #[Test]
    public function profile_is_cached_on_second_access(): void
    {
        $registry = new BuildProfileRegistry($this->profileDir);

        $first = $registry->get(BuildProfile::Mini);
        $second = $registry->get(BuildProfile::Mini);

        self::assertSame($first, $second, 'Same BuildProfileDefinition instance should be returned on repeated access.');
    }

    #[Test]
    public function env_var_in_ini_path_is_resolved_with_provided_home(): void
    {
        $registry = new BuildProfileRegistry(
            profileDir: $this->profileDir,
            home: '/home/leonidas',
        );

        $definition = $registry->get(BuildProfile::Mini);

        // mini.yaml has ini_path: "${DORY_INI_PATH:-~/.config/dory}"
        // With no env override, the default "~/.config/dory" is used, ~ resolved to home
        self::assertSame('/home/leonidas/.config/dory', $definition->iniPath);
        self::assertSame('/home/leonidas/.config/dory/conf.d', $definition->iniScanDir);
    }

    #[Test]
    public function explicit_env_var_overrides_default_in_ini_path(): void
    {
        $registry = new BuildProfileRegistry(
            profileDir: $this->profileDir,
            home: '/home/achilles',
            env: ['DORY_INI_PATH' => '/etc/phalanx/php.ini'],
        );

        $definition = $registry->get(BuildProfile::Mini);

        self::assertSame('/etc/phalanx/php.ini', $definition->iniPath);
    }

    #[Test]
    public function profile_extensions_are_correctly_parsed(): void
    {
        $registry = new BuildProfileRegistry($this->profileDir);
        $definition = $registry->get(BuildProfile::Mini);

        self::assertIsArray($definition->requiredExtensions);
        self::assertContains('openssl', $definition->requiredExtensions);
        self::assertContains('curl', $definition->requiredExtensions);
        self::assertContains('mbstring', $definition->requiredExtensions);
    }

    #[Test]
    public function openswoole_version_is_set_from_yaml(): void
    {
        $registry = new BuildProfileRegistry($this->profileDir);
        $definition = $registry->get(BuildProfile::Mini);

        self::assertSame('26.2.0', $definition->openSwooleVersion);
    }

    #[Test]
    public function invalid_profile_dir_throws_on_load(): void
    {
        $registry = new BuildProfileRegistry('/nonexistent/path');

        $this->expectException(RuntimeException::class);

        $registry->getByName('mini');
    }
}
