<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Audit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RuntimeRiskScanner
{
    /** @var list<string> */
    private const array STALE_COMPOSER_PREFIXES = [
        'amp/',
        'amphp/',
        'clue/',
        'ratchet/',
        'react/',
        'revolt/',
    ];

    /** @var list<string> */
    private const array PROCESS_FUNCTIONS = [
        'proc_open',
        'proc_close',
        'proc_get_status',
        'proc_terminate',
    ];

    /** @var list<string> */
    private const array PROCESS_CLASSES = [
        'OpenSwoole\\Core\\Process\\Manager',
        'OpenSwoole\\Process',
        'OpenSwoole\\Process\\Pool',
        'Swoole\\Process',
        'Swoole\\Process\\Pool',
        'Symfony\\Component\\Process\\Process',
    ];

    /** @var list<string> */
    private const array STREAM_FUNCTIONS = [
        'fopen',
        'fread',
        'fgets',
        'fwrite',
        'stream_select',
        'file_get_contents',
        'file_put_contents',
        'stream_get_contents',
    ];

    /**
     * @param list<string> $paths
     * @return list<RuntimeRisk>
     */
    public function scanPaths(array $paths): array
    {
        $risks = [];
        foreach ($paths as $path) {
            foreach ($this->phpFiles($path) as $file) {
                array_push($risks, ...$this->scanFile($file));
            }
        }

        usort(
            $risks,
            static fn(RuntimeRisk $a, RuntimeRisk $b): int => [$a->file, $a->line, $a->category, $a->symbol]
                <=> [$b->file, $b->line, $b->category, $b->symbol],
        );

        return $risks;
    }

    /** @return list<RuntimeRisk> */
    public function scanFile(string $file): array
    {
        if (self::isVendorPath($file)) {
            return [];
        }

        if (self::isComposerManifest($file)) {
            return $this->scanComposerFile($file);
        }

        if (!str_ends_with($file, '.php')) {
            return [];
        }

        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $tokens = token_get_all($source);
        $aliases = $this->aliases($tokens);
        $risks = $this->processGroupedUseTokens($tokens, $file);

        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;
            if ($id === T_STRING) {
                array_push($risks, ...$this->processStringToken($tokens, $i, $text, $file, $line, $aliases));
                continue;
            }

            if ($id === T_NEW) {
                $risk = $this->processNewToken($tokens, $i, $file, $line, $aliases);
                if ($risk !== null) {
                    $risks[] = $risk;
                }
                continue;
            }

            if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                array_push($risks, ...$this->processQualifiedName($tokens, $i, $text, $file, $line, $aliases));
            }
        }

        return $risks;
    }

    /** @return list<RuntimeRisk> */
    private function scanComposerFile(string $file): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $decoded = json_decode($source, true);
        if (!is_array($decoded)) {
            return [];
        }

        $risks = [];
        foreach (self::composerPackageNames($decoded) as $package) {
            if (!self::isStaleComposerPackage($package)) {
                continue;
            }

            $risks[] = new RuntimeRisk(
                'stale_async_dependency',
                'composer package ' . $package,
                $file,
                self::lineFor($source, $package),
            );
        }

        return $risks;
    }

    /**
     * @param array<int, mixed> $tokens
     * @param array<string, string> $aliases
     * @return list<RuntimeRisk>
     */
    private function processStringToken(array $tokens, int $i, string $text, string $file, int $line, array $aliases): array
    {
        if (str_starts_with($text, 'SWOOLE_HOOK_')) {
            return [new RuntimeRisk('runtime_hooks', $text, $file, $line)];
        }

        if ($this->isFunctionCall($tokens, $i)) {
            $function = strtolower($text);
            if (in_array($function, self::PROCESS_FUNCTIONS, true)) {
                return [new RuntimeRisk('process', $function . '()', $file, $line)];
            }

            if (in_array($function, self::STREAM_FUNCTIONS, true)) {
                return [new RuntimeRisk('raw_stream_io', $function . '()', $file, $line)];
            }
        }

        if ($this->isStaticAccess($tokens, $i)) {
            return $this->processStaticAccess($tokens, $i, $text, $file, $line, $aliases);
        }

        return [];
    }

    /**
     * @param array<int, mixed> $tokens
     * @param array<string, string> $aliases
     * @return list<RuntimeRisk>
     */
    private function processQualifiedName(array $tokens, int $i, string $text, string $file, int $line, array $aliases): array
    {
        if ($this->isGroupedUsePrefix($tokens, $i)) {
            return [];
        }

        if (self::isStaleAsyncSymbol($text)) {
            return [new RuntimeRisk('stale_async_dependency', ltrim($text, '\\'), $file, $line)];
        }

        if ($this->isStaticAccess($tokens, $i)) {
            return $this->processStaticAccess($tokens, $i, $text, $file, $line, $aliases);
        }

        return [];
    }

    /**
     * @param array<int, mixed> $tokens
     * @param array<string, string> $aliases
     */
    private function processNewToken(array $tokens, int $i, string $file, int $line, array $aliases): ?RuntimeRisk
    {
        $class = $this->nextName($tokens, $i);
        if ($class === null) {
            return null;
        }

        $trimmed = $this->resolveAlias($class, $aliases);
        if (in_array($trimmed, self::PROCESS_CLASSES, true) || $trimmed === 'Process') {
            return new RuntimeRisk('process', 'new ' . $trimmed, $file, $line);
        }

        if ($trimmed === 'OpenSwoole\\Coroutine\\Channel'
            || $trimmed === 'Swoole\\Coroutine\\Channel'
        ) {
            return new RuntimeRisk('raw_channel', 'new ' . $trimmed, $file, $line);
        }

        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return list<RuntimeRisk>
     */
    private function processGroupedUseTokens(array $tokens, string $file): array
    {
        $risks = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_USE || $this->nextSignificant($tokens, $i) === '(') {
                continue;
            }

            $groupStart = $this->groupedUseStart($tokens, $i);
            if ($groupStart === null) {
                continue;
            }

            $prefix = $this->groupedUsePrefix($tokens, $i, $groupStart);
            if ($prefix === '') {
                continue;
            }

            for ($j = $groupStart + 1, $count = count($tokens); $j < $count; $j++) {
                if ($tokens[$j] === '}') {
                    break;
                }

                if (!is_array($tokens[$j])) {
                    continue;
                }

                [$id, $name, $line] = $tokens[$j];
                if ($id !== T_STRING && $id !== T_NAME_QUALIFIED && $id !== T_NAME_FULLY_QUALIFIED) {
                    continue;
                }

                if ($this->previousSignificant($tokens, $j) === T_AS) {
                    continue;
                }

                $symbol = trim($prefix . '\\' . ltrim($name, '\\'), '\\');
                if (self::isStaleAsyncSymbol($symbol)) {
                    $risks[] = new RuntimeRisk('stale_async_dependency', $symbol, $file, $line);
                }
            }
        }

        return $risks;
    }

    /** @param array<int, mixed> $tokens */
    private function groupedUseStart(array $tokens, int $useIndex): ?int
    {
        for ($i = $useIndex + 1, $count = count($tokens); $i < $count; $i++) {
            if ($this->isInsignificant($tokens[$i])) {
                continue;
            }

            if ($tokens[$i] === ';') {
                return null;
            }

            if ($tokens[$i] === '{') {
                return $i;
            }
        }

        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function groupedUsePrefix(array $tokens, int $useIndex, int $groupStart): string
    {
        $parts = [];
        for ($i = $useIndex + 1; $i < $groupStart; $i++) {
            $token = $tokens[$i];
            if ($this->isInsignificant($token)) {
                continue;
            }

            if ($token === '\\') {
                $parts[] = '\\';
                continue;
            }

            if (!is_array($token)) {
                continue;
            }

            [$id, $text] = $token;
            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED || $id === T_NS_SEPARATOR) {
                $parts[] = $text;
            }
        }

        return trim(implode('', $parts), '\\');
    }

    /** @param array<int, mixed> $tokens */
    private function isGroupedUsePrefix(array $tokens, int $i): bool
    {
        $next = $this->nextSignificantIndex($tokens, $i);
        if ($next === null || !$this->isNamespaceSeparator($tokens[$next])) {
            return false;
        }

        return $this->nextSignificant($tokens, $next) === '{';
    }

    private function isNamespaceSeparator(mixed $token): bool
    {
        return $token === '\\' || (is_array($token) && $token[0] === T_NS_SEPARATOR);
    }

    /**
     * @param array<int, mixed> $tokens
     * @param array<string, string> $aliases
     * @return list<RuntimeRisk>
     */
    private function processStaticAccess(array $tokens, int $i, string $class, string $file, int $line, array $aliases): array
    {
        $member = $this->staticMember($tokens, $i);
        if ($member === null) {
            return [];
        }

        $resolvedClass = $this->resolveAlias($class, $aliases);
        $classBase = $this->basename($resolvedClass);
        if ($classBase === 'Runtime' && ($member === 'enableCoroutine' || str_starts_with($member, 'HOOK_'))) {
            return [new RuntimeRisk('runtime_hooks', $class . '::' . $member, $file, $line)];
        }

        if ($classBase === 'Coroutine' && $member === 'set') {
            return [new RuntimeRisk('runtime_hooks', $class . '::set', $file, $line)];
        }

        if ($classBase === 'Coroutine' && $member === 'create') {
            return [new RuntimeRisk('raw_coroutine_spawn', $class . '::create', $file, $line)];
        }

        if ($classBase === 'Process' && $member === 'fromShellCommandline') {
            return [new RuntimeRisk('process', $resolvedClass . '::fromShellCommandline', $file, $line)];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return str_ends_with($path, '.php') || self::isComposerManifest($path) ? [$path] : [];
        }

        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            if (
                ($file->getExtension() === 'php' || self::isComposerManifest($file->getPathname()))
                && !self::isVendorPath($file->getPathname())
            ) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @param array<int, mixed> $tokens */
    private function isFunctionCall(array $tokens, int $i): bool
    {
        $previous = $this->previousSignificant($tokens, $i);
        if ($previous === T_FUNCTION || $previous === T_OBJECT_OPERATOR || $previous === T_NULLSAFE_OBJECT_OPERATOR || $previous === T_DOUBLE_COLON) {
            return false;
        }

        return $this->nextSignificant($tokens, $i) === '(';
    }

    /** @param array<int, mixed> $tokens */
    private function isStaticAccess(array $tokens, int $i): bool
    {
        return $this->nextSignificant($tokens, $i) === T_DOUBLE_COLON;
    }

    /** @param array<int, mixed> $tokens */
    private function staticMember(array $tokens, int $i): ?string
    {
        $doubleColon = $this->nextSignificantIndex($tokens, $i);
        if ($doubleColon === null) {
            return null;
        }

        $member = $this->nextSignificantIndex($tokens, $doubleColon);
        if ($member === null || !is_array($tokens[$member])) {
            return null;
        }

        return $tokens[$member][1];
    }

    /** @param array<int, mixed> $tokens */
    private function nextName(array $tokens, int $i): ?string
    {
        $next = $this->nextSignificantIndex($tokens, $i);
        if ($next === null || !is_array($tokens[$next])) {
            return null;
        }

        [$id, $text] = $tokens[$next];
        if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED || $id === T_STRING) {
            return $text;
        }

        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function previousSignificant(array $tokens, int $i): int|string|null
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($this->isInsignificant($tokens[$j])) {
                continue;
            }

            return is_array($tokens[$j]) ? $tokens[$j][0] : $tokens[$j];
        }

        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function nextSignificant(array $tokens, int $i): int|string|null
    {
        $next = $this->nextSignificantIndex($tokens, $i);
        if ($next === null) {
            return null;
        }

        return is_array($tokens[$next]) ? $tokens[$next][0] : $tokens[$next];
    }

    /** @param array<int, mixed> $tokens */
    private function nextSignificantIndex(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
            if ($this->isInsignificant($tokens[$j])) {
                continue;
            }

            return $j;
        }

        return null;
    }

    private function isInsignificant(mixed $token): bool
    {
        return is_array($token)
            && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param array<int, mixed> $tokens
     * @return array<string, string>
     */
    private function aliases(array $tokens): array
    {
        $aliases = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_USE || $this->nextSignificant($tokens, $i) === '(') {
                continue;
            }

            $nameIndex = $this->nextSignificantIndex($tokens, $i);
            if ($nameIndex === null || !is_array($tokens[$nameIndex])) {
                continue;
            }

            [$id, $name] = $tokens[$nameIndex];
            if ($id !== T_NAME_QUALIFIED && $id !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }

            $alias = $this->basename($name);
            $afterName = $this->nextSignificantIndex($tokens, $nameIndex);
            if ($afterName !== null && is_array($tokens[$afterName]) && $tokens[$afterName][0] === T_AS) {
                $aliasIndex = $this->nextSignificantIndex($tokens, $afterName);
                if ($aliasIndex !== null && is_array($tokens[$aliasIndex]) && $tokens[$aliasIndex][0] === T_STRING) {
                    $alias = $tokens[$aliasIndex][1];
                }
            }

            $aliases[$alias] = ltrim($name, '\\');
        }

        return $aliases;
    }

    /** @param array<string, string> $aliases */
    private function resolveAlias(string $name, array $aliases): string
    {
        $trimmed = ltrim($name, '\\');
        if (str_contains($trimmed, '\\')) {
            return $trimmed;
        }

        return $aliases[$trimmed] ?? $trimmed;
    }

    private function basename(string $name): string
    {
        $parts = explode('\\', trim($name, '\\'));

        return end($parts) ?: $name;
    }

    private static function isVendorPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return $normalized === 'vendor'
            || str_starts_with($normalized, 'vendor/')
            || str_contains($normalized, '/vendor/');
    }

    private static function isComposerManifest(string $path): bool
    {
        $name = basename($path);

        return $name === 'composer.json' || $name === 'composer.lock';
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<string>
     */
    private static function composerPackageNames(array $decoded): array
    {
        $packages = [];
        foreach (['require', 'require-dev', 'suggest', 'conflict', 'replace', 'provide'] as $section) {
            if (!isset($decoded[$section]) || !is_array($decoded[$section])) {
                continue;
            }

            foreach (array_keys($decoded[$section]) as $package) {
                if (is_string($package)) {
                    $packages[] = $package;
                }
            }
        }

        foreach (['packages', 'packages-dev'] as $section) {
            if (!isset($decoded[$section]) || !is_array($decoded[$section])) {
                continue;
            }

            foreach ($decoded[$section] as $package) {
                if (is_array($package) && isset($package['name']) && is_string($package['name'])) {
                    $packages[] = $package['name'];
                }
            }
        }

        return array_values(array_unique($packages));
    }

    private static function isStaleComposerPackage(string $package): bool
    {
        foreach (self::STALE_COMPOSER_PREFIXES as $prefix) {
            if (str_starts_with($package, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isStaleAsyncSymbol(string $symbol): bool
    {
        $trimmed = ltrim($symbol, '\\');

        return str_starts_with($trimmed, 'React\\')
            || str_starts_with($trimmed, 'Amp\\')
            || str_starts_with($trimmed, 'Revolt\\');
    }

    private static function lineFor(string $source, string $needle): int
    {
        $position = strpos($source, $needle);
        if ($position === false) {
            return 1;
        }

        return substr_count(substr($source, 0, $position), "\n") + 1;
    }
}
