#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/module-manifest.php';

$root = dirname(__DIR__);
$modules = require $root . '/modules.php';
$check = in_array('--check', $argv, true);
$write = in_array('--write', $argv, true);
$moduleFilter = null;

foreach ($argv as $index => $arg) {
    if ($arg === '--module' && isset($argv[$index + 1])) {
        $moduleFilter = $argv[$index + 1];
    }
}

$errors = [];

foreach ($modules as $module => $meta) {
    if ($moduleFilter !== null && $module !== $moduleFilter) {
        continue;
    }

    $path = $root . '/src/' . $module . '/composer.json';
    $manifest = phalanx_module_manifest($module, $meta);
    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    if ($write) {
        file_put_contents($path, $encoded);
        printf("Wrote %s\n", $path);
        continue;
    }

    if ($check) {
        $actual = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (phalanx_normalized_manifest($actual) !== phalanx_normalized_manifest($manifest)) {
            $errors[] = "$module: composer.json does not match generated module manifest";
        }

        continue;
    }

    printf("%s", $encoded);
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
