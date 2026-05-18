<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Agent\Loader;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Agent\Loader;
use Phalanx\Panoply\Agent\Registry;
use Symfony\Component\Yaml\Yaml;

/**
 * Loader backed by a YAML manifest file. The manifest lists agent classes
 * by FQCN; this loader instantiates each via a no-argument constructor and
 * registers it. Schema validation runs before any instantiation so all
 * structure errors surface at once.
 *
 * Manifest schema (validated inline — see inline rules):
 * ```yaml
 * agents:
 *   - class: App\Agents\HopliteAgent
 *   - class: App\Agents\MarathonAgent
 * ```
 *
 * The schema requires:
 * - Root object with exactly one key: `agents`.
 * - `agents`: non-empty list of objects, each with exactly one required
 *   key `class` (non-empty string). No additional keys allowed.
 *
 * Throws {@see LoaderError} on file-not-found, schema violation, or
 * non-instantiable class.
 *
 * Final — sealed manifest contract.
 */
final class Manifest implements Loader
{
    public function __construct(
        private(set) string $manifestPath,
    ) {
    }

    public function load(): Registry
    {
        if (!is_file($this->manifestPath)) {
            throw LoaderError::manifestNotFound($this->manifestPath);
        }

        $raw  = file_get_contents($this->manifestPath);
        $data = Yaml::parse($raw !== false ? $raw : '');

        self::validate($data, $this->manifestPath);

        /** @var array{agents: list<array{class: string}>} $data */
        $registry = Registry::empty();

        foreach ($data['agents'] as $entry) {
            $fqcn = $entry['class'];
            $registry = $registry->with(self::instantiate($fqcn, $this->manifestPath));
        }

        return $registry;
    }

    /**
     * Validate the parsed YAML document against the manifest schema.
     * Accumulates all violations before throwing so the caller sees the
     * complete error surface in one pass.
     *
     * @param mixed $data
     */
    private static function validate(mixed $data, string $path): void
    {
        $violations = [];

        if (!is_array($data)) {
            throw LoaderError::manifestInvalid($path, 'document root must be a mapping');
        }

        $allowed = ['agents'];

        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, strict: true)) {
                $violations[] = "unknown key '{$key}'";
            }
        }

        if (!array_key_exists('agents', $data)) {
            $violations[] = "missing required key 'agents'";
        } elseif (!is_array($data['agents']) || $data['agents'] === []) {
            $violations[] = "'agents' must be a non-empty list";
        } else {
            foreach ($data['agents'] as $i => $entry) {
                if (!is_array($entry)) {
                    $violations[] = "agents[{$i}] must be a mapping";
                    continue;
                }

                foreach (array_keys($entry) as $key) {
                    if ($key !== 'class') {
                        $violations[] = "agents[{$i}]: unknown key '{$key}'";
                    }
                }

                if (!array_key_exists('class', $entry)) {
                    $violations[] = "agents[{$i}]: missing required key 'class'";
                } elseif (!is_string($entry['class']) || $entry['class'] === '') {
                    $violations[] = "agents[{$i}].class must be a non-empty string";
                }
            }
        }

        if ($violations !== []) {
            throw LoaderError::manifestInvalid($path, implode('; ', $violations));
        }
    }

    private static function instantiate(string $fqcn, string $path): Agent
    {
        if (!class_exists($fqcn)) {
            throw LoaderError::notInstantiable($fqcn, "class not found (referenced in {$path})");
        }

        $reflection = new \ReflectionClass($fqcn);

        if (!$reflection->implementsInterface(Agent::class)) {
            throw LoaderError::notAnAgent($fqcn);
        }

        if (!$reflection->isInstantiable()) {
            throw LoaderError::notInstantiable($fqcn);
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw LoaderError::nonTrivialConstructor($fqcn);
        }

        /** @var Agent $instance */
        $instance = $reflection->newInstance();

        return $instance;
    }
}
