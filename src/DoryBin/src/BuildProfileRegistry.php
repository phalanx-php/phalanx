<?php

declare(strict_types=1);

namespace Phalanx\DoryBin;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

final class BuildProfileRegistry
{
    /** @var array<string, BuildProfileDefinition> */
    private array $profiles = [];

    /**
     * @param array<string, string> $env
     */
    public function __construct(
        private(set) string $profileDir,

        private(set) string $home = '',

        private(set) array $env = [],
    ) {
    }

    public static function defaultProfileDir(): string
    {
        // src/DoryBin/src/ is 3 levels deep from DoryBin root; config/profiles is sibling of src/
        $candidates = [
            dirname(__DIR__) . '/config/profiles',
            dirname(__DIR__, 4) . '/src/DoryBin/config/profiles',
        ];

        return array_find($candidates, static fn(string $p): bool => is_dir($p)) ?? $candidates[0];
    }

    public function get(BuildProfile $profile): BuildProfileDefinition
    {
        return $this->getByName($profile->value);
    }

    public function getByName(string $name): BuildProfileDefinition
    {
        if (!isset($this->profiles[$name])) {
            $this->load($name);
        }

        return $this->profiles[$name];
    }

    public function has(string $name): bool
    {
        $path = $this->profileDir . '/' . $name . '.yaml';
        return file_exists($path);
    }

    /** @return list<BuildProfileDefinition> */
    public function all(): array
    {
        foreach (BuildProfile::cases() as $profile) {
            if ($this->has($profile->value) && !isset($this->profiles[$profile->value])) {
                $this->load($profile->value);
            }
        }

        return array_values($this->profiles);
    }

    private function load(string $name): void
    {
        $path = $this->profileDir . '/' . $name . '.yaml';

        if (!file_exists($path)) {
            throw new RuntimeException("Build profile '{$name}' not found at {$path}.");
        }

        $data = Yaml::parseFile($path);

        if (!is_array($data)) {
            throw new RuntimeException("Invalid profile YAML at {$path}: expected a mapping, got " . get_debug_type($data));
        }

        $php = $data['php'] ?? [];
        $iniRaw = $php['ini'] ?? [];

        $iniSettings = [];
        foreach ($iniRaw as $key => $value) {
            $iniSettings[$key] = (string) $value;
        }

        $iniPath = $this->resolveEnvVars((string) ($php['ini_path'] ?? '~/.config/dory'));
        $iniScanDir = $this->resolveEnvVars((string) ($php['ini_scan_dir'] ?? '~/.config/dory/conf.d'));

        $extensions = $data['extensions'] ?? [];

        $openSwoole = $data['openswoole'] ?? [];
        $featuresRaw = $openSwoole['features'] ?? [];
        $features = [];
        foreach ($featuresRaw as $key => $value) {
            $features[$key] = (bool) $value;
        }

        $phalanx = $data['phalanx'] ?? [];
        $spc = $data['spc'] ?? [];

        $this->profiles[$name] = new BuildProfileDefinition(
            profile: BuildProfile::tryFrom($name) ?? BuildProfile::Custom,
            description: (string) ($data['description'] ?? ''),
            phpVersion: (string) ($php['version'] ?? '8.4'),
            iniSettings: $iniSettings,
            iniPath: $iniPath,
            iniScanDir: $iniScanDir,
            requiredExtensions: array_values((array) ($extensions['required'] ?? [])),
            optionalExtensions: array_values((array) ($extensions['optional'] ?? [])),
            openSwooleVersion: (string) ($openSwoole['version'] ?? '26.2.0'),
            openSwooleFeatures: $features,
            phalanxPackages: array_values((array) ($phalanx['packages'] ?? [])),
            spcRegistries: array_values((array) ($spc['registries'] ?? [])),
        );
    }

    private function resolveEnvVars(string $value): string
    {
        $home = $this->home !== '' ? $this->home : '~';
        $env = $this->env;

        $resolved = preg_replace_callback(
            '/\$\{([A-Z_]+)(?::-([^}]*))?\}/',
            static function (array $matches) use ($home, $env): string {
                $varName = $matches[1];
                $default = $matches[2] ?? '';
                $envValue = $env[$varName] ?? null;
                $result = ($envValue !== null && $envValue !== '') ? $envValue : $default;
                return str_replace('~', $home, $result);
            },
            $value,
        ) ?? $value;

        return str_replace('~', $home, $resolved);
    }
}
