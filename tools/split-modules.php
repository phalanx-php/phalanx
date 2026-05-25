#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$modules = require $root . '/modules.php';
$dryRun = in_array('--dry-run', $argv, true);
$verifyOnly = in_array('--verify-only', $argv, true);
$moduleFilter = optionValue($argv, '--module');
$owner = getenv('SPLIT_REPOSITORY_OWNER') ?: 'phalanx-php';
$token = getenv('GH_ACCESS_TOKEN') ?: getenv('GITHUB_TOKEN') ?: '';
$refName = getenv('GITHUB_REF_TYPE') === 'tag' ? getenv('GITHUB_REF_NAME') : '';

chdir($root);

if ($moduleFilter !== null && !isset($modules[$moduleFilter])) {
    fwrite(STDERR, "Unknown module: {$moduleFilter}\n");
    exit(1);
}

foreach ($modules as $module => $meta) {
    if ($moduleFilter !== null && $module !== $moduleFilter) {
        continue;
    }

    $repository = repositoryName($meta['package']);
    $branch = 'split/' . packageSlug($meta['package']);
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
        : sprintf('https://x-access-token:%s@github.com/%s/%s.git', $token, $owner, $repository);

    run(['git', 'push', $target, $branch . ':main', '--force'], $dryRun, $token);

    if (is_string($refName) && $refName !== '') {
        run(['git', 'push', $target, $branch . ':refs/tags/' . $refName, '--force'], $dryRun, $token);
    }
}

function optionValue(array $argv, string $name): ?string
{
    foreach ($argv as $index => $arg) {
        if ($arg === $name && isset($argv[$index + 1])) {
            return $argv[$index + 1];
        }
    }

    return null;
}

function repositoryName(string $package): string
{
    $name = packageSlug($package);

    return 'phalanx-' . $name;
}

function packageSlug(string $package): string
{
    [, $name] = explode('/', $package, 2);

    return $name;
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
        run(['git', '-C', $worktree, 'add', '-f', '.gitattributes', 'assets'], true);
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
        verifySplitArtifact($worktree);

        run(['git', '-C', $worktree, 'add', '-f', '.gitattributes', 'assets']);

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
/.github export-ignore
/.github/** export-ignore
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
        '~(^|/)(brand|demos|docs|examples|tests|tmp|tools|vendor|\.aimind|\.claude|\.daemon8|\.github|\.idea)(/|$)|(^|/)(phpcs\.xml|phpstan\.neon|phpunit\.xml|phpunit\.xml\.dist|rector\.php|SPEC\.md)$~',
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

function run(array $command, bool $dryRun = false, string $redact = ''): void
{
    $display = implode(' ', array_map('escapeshellarg', $command));

    if ($redact !== '') {
        $display = str_replace($redact, '***', $display);
    }

    if ($dryRun) {
        echo $display . PHP_EOL;
        return;
    }

    passthru($display, $exitCode);

    if ($exitCode !== 0) {
        exit($exitCode);
    }
}
