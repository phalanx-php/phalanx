#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/module-manifest.php';

$root = dirname(__DIR__);
$modules = require $root . '/modules.php';
$dryRun = in_array('--dry-run', $argv, true);
$verifyOnly = in_array('--verify-only', $argv, true);
$moduleFilter = phalanx_option_value($argv, '--module');
$owner = getenv('SPLIT_REPOSITORY_OWNER') ?: 'phalanx-php';
$token = getenv('GH_ACCESS_TOKEN') ?: getenv('GITHUB_TOKEN') ?: '';
$refName = getenv('GITHUB_REF_TYPE') === 'tag' ? getenv('GITHUB_REF_NAME') : '';

chdir($root);

if ($moduleFilter !== null && !isset($modules[$moduleFilter])) {
    fwrite(STDERR, "Unknown module: {$moduleFilter}\n");
    exit(1);
}

if ($moduleFilter !== null && ! phalanx_module_is_published($modules[$moduleFilter])) {
    fwrite(STDERR, "Module is not configured for split publishing: {$moduleFilter}\n");
    exit(1);
}

$askpass = $token !== '' ? configureGitAskPass($token) : null;

try {
    foreach ($modules as $module => $meta) {
        if ($moduleFilter !== null && $module !== $moduleFilter) {
            continue;
        }

        if (! phalanx_module_is_published($meta)) {
            continue;
        }

        $repository = phalanx_repository_name($meta['package']);
        $branch = 'split/' . phalanx_package_slug($meta['package']);
        $prefix = 'src/' . $module;

        deleteBranch($branch, $dryRun);
        run(['git', 'subtree', 'split', '--prefix', $prefix, '-b', $branch], $dryRun);
        injectAssets($branch, $root, $dryRun);
        verifySplitArchive($branch, $dryRun);

        if ($verifyOnly) {
            deleteBranch($branch, $dryRun);
            printf("Module split proof OK: %s\n", $module);
            continue;
        }

        $target = $token === ''
            ? sprintf('git@github.com:%s/%s.git', $owner, $repository)
            : sprintf('https://x-access-token@github.com/%s/%s.git', $owner, $repository);

        run(['git', 'push', $target, $branch . ':main', '--force'], $dryRun);

        if (is_string($refName) && $refName !== '') {
            run(['git', 'push', $target, $branch . ':refs/tags/' . $refName, '--force'], $dryRun);
        }
    }
} finally {
    cleanupGitAskPass($askpass);
}

function configureGitAskPass(string $token): string
{
    $path = tempnam(sys_get_temp_dir(), 'phalanx-git-askpass-');

    if ($path === false) {
        throw new RuntimeException('Failed to create temporary Git askpass helper.');
    }

    file_put_contents($path, <<<'SH'
#!/bin/sh
case "$1" in
  *Username*) printf '%s\n' 'x-access-token' ;;
  *) printf '%s\n' "$PHALANX_SPLIT_TOKEN" ;;
esac
SH);
    chmod($path, 0700);
    putenv('GIT_ASKPASS=' . $path);
    putenv('GIT_TERMINAL_PROMPT=0');
    putenv('PHALANX_SPLIT_TOKEN=' . $token);

    return $path;
}

function cleanupGitAskPass(?string $path): void
{
    putenv('GIT_ASKPASS');
    putenv('GIT_TERMINAL_PROMPT');
    putenv('PHALANX_SPLIT_TOKEN');

    if ($path !== null && is_file($path)) {
        unlink($path);
    }
}

function deleteBranch(string $branch, bool $dryRun): void
{
    if ($dryRun) {
        echo 'delete local branch if present: ' . $branch . PHP_EOL;
        return;
    }

    if (branchExists($branch)) {
        run(['git', 'branch', '-D', $branch]);
    }
}

function branchExists(string $branch): bool
{
    exec('git show-ref --verify --quiet ' . escapeshellarg('refs/heads/' . $branch), result_code: $exitCode);

    return $exitCode === 0;
}

function injectAssets(string $branch, string $root, bool $dryRun): void
{
    $worktree = sys_get_temp_dir() . '/' . uniqid('phalanx-split-' . str_replace('/', '-', $branch) . '-', true);

    run(['git', 'worktree', 'add', $worktree, $branch], $dryRun);

    if ($dryRun) {
        echo 'copy assets/ ' . $worktree . '/assets' . PHP_EOL;
        echo 'write split .gitattributes: ' . $worktree . '/.gitattributes' . PHP_EOL;
        echo 'remove forbidden hidden split files' . PHP_EOL;
        run(['git', '-C', $worktree, 'add', '-A', '-f'], true);
        run([
            'git',
            '-C',
            $worktree,
            '-c',
            'user.name=github-actions[bot]',
            '-c',
            'user.email=41898282+github-actions[bot]@users.noreply.github.com',
            'commit',
            '-m',
            'Add split assets',
        ], true);
        run(['git', 'worktree', 'remove', '--force', $worktree], true);
        return;
    }

    try {
        copyDirectory($root . '/assets', $worktree . '/assets');
        file_put_contents($worktree . '/.gitattributes', splitGitattributes());
        removeForbiddenHiddenPaths($worktree);
        verifySplitArtifact($worktree);

        run(['git', '-C', $worktree, 'add', '-A', '-f']);

        if (trim(shellOutput(['git', '-C', $worktree, 'status', '--porcelain'])) !== '') {
            run([
                'git',
                '-C',
                $worktree,
                '-c',
                'user.name=github-actions[bot]',
                '-c',
                'user.email=41898282+github-actions[bot]@users.noreply.github.com',
                'commit',
                '-m',
                'Add split assets',
            ]);
        }
    } finally {
        run(['git', 'worktree', 'remove', '--force', $worktree]);
    }
}

function copyDirectory(string $source, string $target): void
{
    if (!is_dir($source)) {
        throw new RuntimeException("Missing source assets directory: {$source}");
    }

    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException("Failed to create target assets directory: {$target}");
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($items as $item) {
        $destination = $target . '/' . $items->getSubPathName();

        if ($item->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
                throw new RuntimeException("Failed to create asset directory: {$destination}");
            }

            continue;
        }

        if (!copy($item->getPathname(), $destination)) {
            throw new RuntimeException("Failed to copy asset: {$item->getPathname()}");
        }
    }
}

function removeForbiddenHiddenPaths(string $root): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $name = $item->getFilename();

        if ($name === '.git' || $name === '.gitattributes' || !str_starts_with($name, '.')) {
            continue;
        }

        $path = $item->getPathname();

        if ($item->isDir() && !$item->isLink()) {
            removeDirectory($path);
            continue;
        }

        if (!unlink($path)) {
            throw new RuntimeException("Failed to remove file: {$path}");
        }
    }
}

function removeDirectory(string $path): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            if (!rmdir($item->getPathname())) {
                throw new RuntimeException("Failed to remove directory: {$item->getPathname()}");
            }

            continue;
        }

        if (!unlink($item->getPathname())) {
            throw new RuntimeException("Failed to remove file: {$item->getPathname()}");
        }
    }

    if (!rmdir($path)) {
        throw new RuntimeException("Failed to remove directory: {$path}");
    }
}

function splitGitattributes(): string
{
    return <<<'GITATTRIBUTES'
/.aimind export-ignore
/.aimind/** export-ignore
/.claude export-ignore
/.claude/** export-ignore
/.daemon8 export-ignore
/.daemon8/** export-ignore
/.DS_Store export-ignore
/.env* export-ignore
/**/.env* export-ignore
/.github export-ignore
/.github/** export-ignore
/.gitignore export-ignore
/**/.gitignore export-ignore
/.idea export-ignore
/.idea/** export-ignore
/.php-cs-fixer.cache export-ignore
/.phpstan-cache export-ignore
/.phpstan-cache/** export-ignore
/.phpunit.result.cache export-ignore
/brand export-ignore
/brand/** export-ignore
/demos export-ignore
/demos/** export-ignore
/docs export-ignore
/docs/** export-ignore
/examples export-ignore
/examples/** export-ignore
/phpcs.xml export-ignore
/phpstan.neon export-ignore
/phpunit.xml export-ignore
/phpunit.xml.dist export-ignore
/rector.php export-ignore
/SPEC.md export-ignore
/tests export-ignore
/tests/** export-ignore
/tmp export-ignore
/tmp/** export-ignore
/tools export-ignore
/tools/** export-ignore
/vendor export-ignore
/vendor/** export-ignore
GITATTRIBUTES . PHP_EOL;
}

function verifySplitArtifact(string $worktree): void
{
    assertFile($worktree . '/README.md');
    assertFile($worktree . '/assets/banner.svg');
    assertFile($worktree . '/.gitattributes');
    assertMissing($worktree . '/brand');
    assertMissing($worktree . '/.aimind');
    assertMissing($worktree . '/.claude');
    assertMissing($worktree . '/.gitignore');
    assertMissing($worktree . '/demos');
    assertMissing($worktree . '/SPEC.md');

    $contents = file_get_contents($worktree . '/README.md');

    if (!is_string($contents) || !str_contains($contents, 'assets/banner.svg')) {
        throw new RuntimeException('Split README must reference assets/banner.svg.');
    }

    $manifest = json_decode((string) file_get_contents($worktree . '/composer.json'), true);

    if (!is_array($manifest)) {
        throw new RuntimeException('Split composer.json is not valid JSON.');
    }

    $archiveExcludes = $manifest['archive']['exclude'] ?? null;

    if (!is_array($archiveExcludes) || !in_array('/tests', $archiveExcludes, true)) {
        throw new RuntimeException('Split composer.json must exclude tests from Composer archives.');
    }
}

function verifySplitArchive(string $branch, bool $dryRun): void
{
    if ($dryRun) {
        echo 'verify git archive for branch: ' . $branch . PHP_EOL;
        return;
    }

    $archive = tempnam(sys_get_temp_dir(), 'phalanx-split-archive-');

    if ($archive === false) {
        throw new RuntimeException('Failed to create temporary archive path.');
    }

    try {
        shellOutput(['git', 'archive', '--format=tar', '--output', $archive, $branch]);
        $listing = shellOutput(['tar', '-tf', $archive]);
    } finally {
        unlink($archive);
    }

    foreach (explode(PHP_EOL, $listing) as $path) {
        if ($path === '') {
            continue;
        }

        if (isForbiddenSplitArchivePath($path)) {
            throw new RuntimeException("Unexpected split archive path: {$path}");
        }
    }
}

function isForbiddenSplitArchivePath(string $path): bool
{
    return preg_match(
        '~(^|/)(brand|demos|docs|examples|tests|tmp|tools|vendor|\.aimind|\.claude|\.daemon8|\.github|\.idea)(/|$)|(^|/)(?!\.gitattributes$)\.[^/]+$|(^|/)(phpcs\.xml|phpstan\.neon|phpunit\.xml|phpunit\.xml\.dist|rector\.php|SPEC\.md)$~',
        $path,
    ) === 1;
}

function assertFile(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException("Expected split artifact missing: {$path}");
    }
}

function assertMissing(string $path): void
{
    if (file_exists($path) || is_link($path)) {
        throw new RuntimeException("Unexpected split artifact exists: {$path}");
    }
}

function shellOutput(array $command): string
{
    $display = implode(' ', array_map('escapeshellarg', $command));
    exec($display, $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException("Command failed ({$exitCode}): {$display}");
    }

    return implode(PHP_EOL, $output);
}

function run(array $command, bool $dryRun = false): void
{
    $display = implode(' ', array_map('escapeshellarg', $command));

    if ($dryRun) {
        echo $display . PHP_EOL;
        return;
    }

    passthru($display, $exitCode);

    if ($exitCode !== 0) {
        exit($exitCode);
    }
}
