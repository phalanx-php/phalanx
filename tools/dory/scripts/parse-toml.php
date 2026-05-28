<?php

$autoloadPath = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run 'composer install' in the monorepo root.\n");
    exit(1);
}

require_once $autoloadPath;

use Yosymfony\Toml\Toml;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php parse-toml.php <path/to/dory.toml>\n");
    exit(1);
}

$tomlFile = $argv[1];
if (!file_exists($tomlFile)) {
    fwrite(STDERR, "Error: Configuration file not found: $tomlFile\n");
    exit(1);
}

try {
    $config = Toml::parseFile($tomlFile);
} catch (\Exception $e) {
    fwrite(STDERR, "Error parsing TOML: " . $e->getMessage() . "\n");
    exit(1);
}

$phpVersion = $config['dory']['php_version'] ?? '8.4';
$extensions = isset($config['dory']['extensions']) ? implode(',', $config['dory']['extensions']) : '';

echo "PHP_VERSION=" . escapeshellarg($phpVersion) . "\n";
echo "EXTENSIONS=" . escapeshellarg($extensions) . "\n";
