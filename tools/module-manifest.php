<?php

declare(strict_types=1);

function phalanx_module_manifest(string $module, array $meta): array
{
    $manifest = [
        'name' => $meta['package'],
        'description' => $meta['description'],
        'license' => 'MIT',
        'type' => $meta['type'],
    ];

    if (($meta['keywords'] ?? []) !== []) {
        $manifest['keywords'] = $meta['keywords'];
    }

    $source = 'https://github.com/phalanx-php/phalanx-' . phalanx_package_slug($meta['package']);

    $manifest['homepage'] = $source;
    $manifest['authors'] = [
        [
            'name' => 'Jonathan Havens',
            'email' => 'mail@phalanx-php.com',
        ],
    ];
    $manifest['support'] = [
        'source' => $source,
    ];
    $manifest['require'] = $meta['requires'];

    if (($meta['conflicts'] ?? []) !== []) {
        $manifest['conflict'] = $meta['conflicts'];
    }

    if (($meta['suggests'] ?? []) !== []) {
        $manifest['suggest'] = $meta['suggests'];
    }

    $manifest['require-dev'] = $meta['devRequires'];

    if ($meta['bins'] !== []) {
        $manifest['bin'] = $meta['bins'];
    }

    $manifest['archive'] = [
        'exclude' => [
            '/.aimind',
            '/.aimind/**',
            '/.claude',
            '/.claude/**',
            '/.daemon8',
            '/.daemon8/**',
            '/.DS_Store',
            '/.env*',
            '/**/.env*',
            '/.gitattributes',
            '/.github',
            '/.github/**',
            '/.gitignore',
            '/**/.gitignore',
            '/.idea',
            '/.idea/**',
            '/.php-cs-fixer.cache',
            '/.phpstan-cache',
            '/.phpstan-cache/**',
            '/.phpunit.result.cache',
            '/brand',
            '/brand/**',
            '/demos',
            '/demos/**',
            '/docs',
            '/docs/**',
            '/examples',
            '/examples/**',
            '/phpcs.xml',
            '/phpstan.neon',
            '/phpunit.xml',
            '/phpunit.xml.dist',
            '/rector.php',
            '/SPEC.md',
            '/tests',
            '/tests/**',
            '/tmp',
            '/tmp/**',
            '/tools',
            '/tools/**',
            '/vendor',
            '/vendor/**',
        ],
    ];

    $manifest['scripts'] = [
        'test' => phalanx_package_test_script($module),
    ];

    $autoload = [
        'psr-4' => [
            $meta['namespace'] => 'src/',
        ],
    ];

    if (($meta['autoloadFiles'] ?? []) !== []) {
        $autoload = [
            'files' => $meta['autoloadFiles'],
            'psr-4' => $autoload['psr-4'],
        ];
    }

    $manifest['autoload'] = $autoload;
    $manifest['autoload-dev'] = [
        'psr-4' => $meta['testNamespaces'] ?? [$meta['testNamespace'] => 'tests/'],
    ];

    if (($meta['devClassmap'] ?? []) !== []) {
        $manifest['autoload-dev']['classmap'] = $meta['devClassmap'];
    }

    $extra = [
        'branch-alias' => [
            'dev-main' => $meta['branchAlias'],
        ],
    ];

    if (($meta['bundles'] ?? []) !== []) {
        $extra['phalanx'] = [
            'bundles' => $meta['bundles'],
        ];
    }

    if (($meta['phpstanIncludes'] ?? []) !== []) {
        $extra['phpstan'] = [
            'includes' => $meta['phpstanIncludes'],
        ];
    }

    $manifest['extra'] = $extra;

    if (($meta['allowPlugins'] ?? []) !== [] || ($meta['sortPackages'] ?? false) === true) {
        $manifest['config'] = [];

        if (($meta['sortPackages'] ?? false) === true) {
            $manifest['config']['sort-packages'] = true;
        }

        if (($meta['allowPlugins'] ?? []) !== []) {
            $manifest['config']['allow-plugins'] = $meta['allowPlugins'];
        }
    }

    $manifest['minimum-stability'] = 'dev';
    $manifest['prefer-stable'] = true;

    return $manifest;
}

function phalanx_package_test_script(string $module): string
{
    $config = is_file(__DIR__ . '/../src/' . $module . '/phpunit.xml.dist')
        ? 'phpunit.xml.dist'
        : 'phpunit.xml';

    return 'php -d memory_limit=512M vendor/bin/phpunit -c ' . $config;
}

function phalanx_module_is_published(array $meta): bool
{
    return ($meta['publish'] ?? true) !== false;
}

function phalanx_option_value(array $argv, string $name): ?string
{
    foreach ($argv as $index => $arg) {
        if (str_starts_with($arg, $name . '=')) {
            $value = substr($arg, strlen($name) + 1);

            if ($value === '') {
                fwrite(STDERR, "Missing value for {$name}\n");
                exit(1);
            }

            return $value;
        }

        if ($arg !== $name) {
            continue;
        }

        $value = $argv[$index + 1] ?? null;

        if ($value === null || str_starts_with($value, '--')) {
            fwrite(STDERR, "Missing value for {$name}\n");
            exit(1);
        }

        return $value;
    }

    return null;
}

function phalanx_package_slug(string $package): string
{
    [, $name] = explode('/', $package, 2);

    return $name;
}

function phalanx_repository_name(string $package): string
{
    return 'phalanx-' . phalanx_package_slug($package);
}

function phalanx_normalized_manifest(array $manifest): array
{
    ksort($manifest);

    foreach ($manifest as $key => $value) {
        if (is_array($value)) {
            $manifest[$key] = phalanx_normalized_manifest($value);
        }
    }

    return $manifest;
}
