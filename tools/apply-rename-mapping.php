<?php

declare(strict_types=1);

namespace Phalanx\Tools\RenameMapping;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_filter;
use function array_is_list;
use function array_values;
use function basename;
use function count;
use function dirname;
use function explode;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function is_array;
use function is_dir;
use function json_decode;
use function ltrim;
use function preg_quote;
use function preg_replace_callback;
use function printf;
use function realpath;
use function rename;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;

use const JSON_THROW_ON_ERROR;
use const STDERR;

/** @return list<array<string, mixed>>|null */
function loadMappingEntries(string $mappingFile): ?array
{
    $decoded = json_decode((string) file_get_contents($mappingFile), associative: true, flags: JSON_THROW_ON_ERROR);

    if (!is_array($decoded) || !array_is_list($decoded)) {
        return null;
    }

    $entries = [];

    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            return null;
        }

        $entries[] = $entry;
    }

    return $entries;
}

/**
 * @param list<array<string, mixed>> $entries
 * @return list<array<string, mixed>>
 */
function renameEntries(array $entries): array
{
    return array_values(array_filter(
        $entries,
        static fn(array $entry): bool => ($entry['action'] ?? null) === 'rename',
    ));
}

function packageSlug(string $package): string
{
    $parts = explode('/', $package, 2);

    return $parts[1] ?? $package;
}

function relativePath(string $root, string $path): string
{
    return ltrim(str_replace($root, '', $path), '/');
}

/**
 * @param array<string, true> $skipDirs
 * @param array<string, true> $skipFiles
 */
function shouldSkipPath(
    string $root,
    string $path,
    array $skipDirs,
    array $skipFiles,
): bool {
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

/** @param array<string, true> $textExtensions */
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

/**
 * @param array<string, mixed> $entry
 * @return list<array{pattern: string, replacement: string}>
 */
function replacementRules(array $entry): array
{
    $old = (string) $entry['old'];
    $new = (string) $entry['new'];
    $oldPackage = (string) $entry['oldPackage'];
    $newPackage = (string) $entry['newPackage'];
    $oldSlug = packageSlug($oldPackage);
    $newSlug = packageSlug($newPackage);

    $rules = [
        exactRule((string) $entry['oldDir'], (string) $entry['newDir']),
        exactRule($oldPackage, $newPackage),
        exactRule((string) $entry['oldSplitRepo'], (string) $entry['newSplitRepo']),
        slugRule($oldSlug, $newSlug),
        slugRule(strtolower($old), $newSlug),
        classPrefixRule($old, $new),
    ];

    if (($entry['oldNamespace'] ?? null) !== ($entry['newNamespace'] ?? null)) {
        $oldNamespace = (string) $entry['oldNamespace'];
        $newNamespace = (string) $entry['newNamespace'];
        $rules[] = exactRule(
            str_replace('\\', '\\\\', $oldNamespace),
            str_replace('\\', '\\\\', $newNamespace),
        );
        $rules[] = exactRule($oldNamespace, $newNamespace);
    }

    if (($entry['oldTestNamespace'] ?? null) !== ($entry['newTestNamespace'] ?? null)) {
        $oldTestNamespace = (string) $entry['oldTestNamespace'];
        $newTestNamespace = (string) $entry['newTestNamespace'];
        $rules[] = exactRule(
            str_replace('\\', '\\\\', $oldTestNamespace),
            str_replace('\\', '\\\\', $newTestNamespace),
        );
        $rules[] = exactRule($oldTestNamespace, $newTestNamespace);
    }

    return array_values(array_filter(
        $rules,
        static fn(array $rule): bool => $rule['pattern'] !== '' && $rule['replacement'] !== '',
    ));
}

/** @return array{pattern: string, replacement: string} */
function exactRule(string $old, string $new): array
{
    return [
        'pattern' => '~' . preg_quote($old, '~') . '~',
        'replacement' => $new,
    ];
}

/** @return array{pattern: string, replacement: string} */
function slugRule(string $old, string $new): array
{
    return [
        'pattern' => '~(?<![A-Za-z0-9_])' . preg_quote($old, '~') . '(?![A-Za-z0-9_])~',
        'replacement' => $new,
    ];
}

/** @return array{pattern: string, replacement: string} */
function classPrefixRule(string $old, string $new): array
{
    return [
        'pattern' => '~(?<![A-Za-z0-9_])' . preg_quote($old, '~') . '(?=[A-Z_]|$)~',
        'replacement' => $new,
    ];
}

/**
 * @param list<array<string, mixed>> $entries
 */
function replaceContent(string $content, array $entries): string
{
    foreach (renameEntries($entries) as $entry) {
        foreach (replacementRules($entry) as $rule) {
            $content = (string) preg_replace_callback(
                $rule['pattern'],
                static fn(): string => $rule['replacement'],
                $content,
            );
        }
    }

    return $content;
}

/**
 * @param list<array<string, mixed>> $entries
 * @return array<string, string>
 */
function pathPairs(array $entries): array
{
    $pairs = [];

    foreach (renameEntries($entries) as $entry) {
        $old = (string) $entry['old'];
        $new = (string) $entry['new'];
        $oldSlug = packageSlug((string) $entry['oldPackage']);
        $newSlug = packageSlug((string) $entry['newPackage']);
        $pairs[$old] = $new;
        $pairs[strtolower($old)] = $newSlug;
        $pairs[$oldSlug] = $newSlug;
    }

    return $pairs;
}

/**
 * @param array<string, string> $pairs
 */
function renameBase(string $base, array $pairs): string
{
    foreach ($pairs as $old => $new) {
        $base = (string) preg_replace_callback(
            classPrefixRule($old, $new)['pattern'],
            static fn(): string => $new,
            $base,
        );
        $base = (string) preg_replace_callback(
            slugRule($old, $new)['pattern'],
            static fn(): string => $new,
            $base,
        );
    }

    return $base;
}

function main(?string $root = null): int
{
    $root ??= dirname(__DIR__);
    $mappingFile = $root . '/tools/rename-mapping.json';
    $entries = loadMappingEntries($mappingFile);

    if ($entries === null) {
        fwrite(STDERR, "Rename mapping must decode to an array.\n");

        return 1;
    }

    $renames = renameEntries($entries);

    foreach ($renames as $entry) {
        $oldDir = $root . '/' . $entry['oldDir'];
        $newDir = $root . '/' . $entry['newDir'];

        if (!is_dir($oldDir) && is_dir($newDir)) {
            fwrite(
                STDERR,
                "Rename mapping appears to be applied already; {$entry['oldDir']} is gone and {$entry['newDir']} exists.\n",
            );

            return 1;
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
        $updated = replaceContent($content, $entries);

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
    $pairs = pathPairs($entries);

    foreach ($paths as $path) {
        if (!file_exists($path)) {
            continue;
        }

        $base = basename($path);
        $newBase = renameBase($base, $pairs);
        if ($newBase === $base) {
            continue;
        }

        $target = dirname($path) . '/' . $newBase;
        if (file_exists($target)) {
            fwrite(STDERR, "Refusing to overwrite {$target} while renaming {$path}.\n");

            return 1;
        }

        rename($path, $target);
        $renamedPaths++;
    }

    printf("Updated %d files and renamed %d paths.\n", $updatedFiles, $renamedPaths);

    return 0;
}

$script = $_SERVER['SCRIPT_FILENAME'] ?? null;

if ($script !== null && realpath($script) === __FILE__) {
    exit(main());
}
