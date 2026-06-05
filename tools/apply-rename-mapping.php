<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$mappingFile = $root . '/tools/rename-mapping.json';
$entries = json_decode((string) file_get_contents($mappingFile), associative: true, flags: JSON_THROW_ON_ERROR);

if (!is_array($entries)) {
    fwrite(STDERR, "Rename mapping must decode to an array.\n");
    exit(1);
}

$renames = array_values(array_filter(
    $entries,
    static fn(array $entry): bool => ($entry['action'] ?? null) === 'rename',
));

foreach ($renames as $entry) {
    $oldDir = $root . '/' . $entry['oldDir'];
    $newDir = $root . '/' . $entry['newDir'];

    if (!is_dir($oldDir) && is_dir($newDir)) {
        fwrite(
            STDERR,
            "Rename mapping appears to be applied already; {$entry['oldDir']} is gone and {$entry['newDir']} exists.\n",
        );
        exit(1);
    }
}

$skipDirs = [
    '.git' => true,
    'vendor' => true,
    '.phpstan-cache' => true,
];

$skipFiles = [
    'composer.lock' => true,
    'tools/rename-mapping.json' => true,
    'tools/apply-rename-mapping.php' => true,
];

$textExtensions = [
    'dist' => true,
    'json' => true,
    'md' => true,
    'neon' => true,
    'php' => true,
    'xml' => true,
    'yaml' => true,
    'yml' => true,
];

function packageSlug(string $package): string
{
    $parts = explode('/', $package, 2);

    return $parts[1] ?? $package;
}

function relativePath(string $root, string $path): string
{
    return ltrim(str_replace($root, '', $path), '/');
}

function shouldSkipPath(string $root, string $path, array $skipDirs, array $skipFiles): bool
{
    $relative = relativePath($root, $path);
    if (isset($skipFiles[$relative])) {
        return true;
    }

    foreach (explode('/', $relative) as $part) {
        if (isset($skipDirs[$part])) {
            return true;
        }
    }

    return false;
}

function isTextCandidate(SplFileInfo $file, array $textExtensions): bool
{
    $name = $file->getFilename();
    if (
        $name === 'composer.json'
        || $name === '.env.example'
        || str_ends_with($name, '.xml.dist')
        || str_starts_with((string) file_get_contents($file->getPathname(), false, null, 0, 64), '#!')
    ) {
        return true;
    }

    $extension = strtolower($file->getExtension());

    return isset($textExtensions[$extension]);
}

function replacementPairs(array $entry): array
{
    $old = (string) $entry['old'];
    $new = (string) $entry['new'];
    $oldPackage = (string) $entry['oldPackage'];
    $newPackage = (string) $entry['newPackage'];
    $oldSlug = packageSlug($oldPackage);
    $newSlug = packageSlug($newPackage);

    $pairs = [
        (string) $entry['oldDir'] => (string) $entry['newDir'],
        $oldPackage => $newPackage,
        (string) $entry['oldSplitRepo'] => (string) $entry['newSplitRepo'],
        $oldSlug => $newSlug,
        $old => $new,
        strtolower($old) => $newSlug,
    ];

    if (($entry['oldNamespace'] ?? null) !== ($entry['newNamespace'] ?? null)) {
        $oldNamespace = (string) $entry['oldNamespace'];
        $newNamespace = (string) $entry['newNamespace'];
        $pairs[$oldNamespace] = $newNamespace;
        $pairs[str_replace('\\', '\\\\', $oldNamespace)] = str_replace('\\', '\\\\', $newNamespace);
    }

    if (($entry['oldTestNamespace'] ?? null) !== ($entry['newTestNamespace'] ?? null)) {
        $oldTestNamespace = (string) $entry['oldTestNamespace'];
        $newTestNamespace = (string) $entry['newTestNamespace'];
        $pairs[$oldTestNamespace] = $newTestNamespace;
        $pairs[str_replace('\\', '\\\\', $oldTestNamespace)] = str_replace('\\', '\\\\', $newTestNamespace);
    }

    return $pairs;
}

$textPairs = [];
$pathPairs = [];

foreach ($renames as $entry) {
    foreach (replacementPairs($entry) as $old => $new) {
        if ($old !== $new) {
            $textPairs[$old] = $new;
        }
    }

    $old = (string) $entry['old'];
    $new = (string) $entry['new'];
    $oldSlug = packageSlug((string) $entry['oldPackage']);
    $newSlug = packageSlug((string) $entry['newPackage']);
    $pathPairs[$old] = $new;
    $pathPairs[strtolower($old)] = $newSlug;
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

$updatedFiles = 0;

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    $path = $file->getPathname();
    if (shouldSkipPath($root, $path, $skipDirs, $skipFiles) || !isTextCandidate($file, $textExtensions)) {
        continue;
    }

    $content = (string) file_get_contents($path);
    $updated = strtr($content, $textPairs);

    if ($updated !== $content) {
        file_put_contents($path, $updated);
        $updatedFiles++;
    }
}

$paths = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo) {
        continue;
    }

    $path = $file->getPathname();
    if (shouldSkipPath($root, $path, $skipDirs, $skipFiles)) {
        continue;
    }

    $paths[] = $path;
}

$renamedPaths = 0;

foreach ($paths as $path) {
    if (!file_exists($path)) {
        continue;
    }

    $base = basename($path);
    $newBase = strtr($base, $pathPairs);
    if ($newBase === $base) {
        continue;
    }

    $target = dirname($path) . '/' . $newBase;
    if (file_exists($target)) {
        fwrite(STDERR, "Refusing to overwrite {$target} while renaming {$path}.\n");
        exit(1);
    }

    rename($path, $target);
    $renamedPaths++;
}

printf("Updated %d files and renamed %d paths.\n", $updatedFiles, $renamedPaths);
