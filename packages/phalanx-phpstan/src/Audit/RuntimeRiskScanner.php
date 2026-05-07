<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Audit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RuntimeRiskScanner
{
    /** @var list<string> */
    private const PROCESS_FUNCTIONS = [
        'proc_open',
        'proc_close',
        'proc_get_status',
        'proc_terminate',
    ];

    /** @var list<string> */
    private const STREAM_FUNCTIONS = [
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
        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $tokens = token_get_all($source);
        $risks = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;
            if ($id === T_STRING) {
                array_push($risks, ...$this->processStringToken($tokens, $i, $text, $file, $line));
                continue;
            }

            if ($id === T_NEW) {
                $risk = $this->processNewToken($tokens, $i, $file, $line);
                if ($risk !== null) {
                    $risks[] = $risk;
                }
                continue;
            }

            if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                array_push($risks, ...$this->processQualifiedName($tokens, $i, $text, $file, $line));
            }
        }

        return $risks;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return list<RuntimeRisk>
     */
    private function processStringToken(array $tokens, int $i, string $text, string $file, int $line): array
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
            return $this->processStaticAccess($tokens, $i, $text, $file, $line);
        }

        return [];
    }

    /**
     * @param array<int, mixed> $tokens
     * @return list<RuntimeRisk>
     */
    private function processQualifiedName(array $tokens, int $i, string $text, string $file, int $line): array
    {
        if (str_starts_with(ltrim($text, '\\'), 'React\\')
            || str_starts_with(ltrim($text, '\\'), 'Amp\\')
            || str_starts_with(ltrim($text, '\\'), 'Revolt\\')
        ) {
            return [new RuntimeRisk('stale_async_dependency', ltrim($text, '\\'), $file, $line)];
        }

        if ($this->isStaticAccess($tokens, $i)) {
            return $this->processStaticAccess($tokens, $i, $text, $file, $line);
        }

        return [];
    }

    /** @param array<int, mixed> $tokens */
    private function processNewToken(array $tokens, int $i, string $file, int $line): ?RuntimeRisk
    {
        $class = $this->nextName($tokens, $i);
        if ($class === null) {
            return null;
        }

        $trimmed = ltrim($class, '\\');
        if ($trimmed === 'Symfony\\Component\\Process\\Process' || $trimmed === 'Process') {
            return new RuntimeRisk('process', 'new ' . $trimmed, $file, $line);
        }

        if ($trimmed === 'OpenSwoole\\Coroutine\\Channel'
            || $trimmed === 'Swoole\\Coroutine\\Channel'
            || $trimmed === 'Channel'
        ) {
            return new RuntimeRisk('raw_channel', 'new ' . $trimmed, $file, $line);
        }

        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return list<RuntimeRisk>
     */
    private function processStaticAccess(array $tokens, int $i, string $class, string $file, int $line): array
    {
        $member = $this->staticMember($tokens, $i);
        if ($member === null) {
            return [];
        }

        $classBase = $this->basename($class);
        if ($classBase === 'Runtime' && ($member === 'enableCoroutine' || str_starts_with($member, 'HOOK_'))) {
            return [new RuntimeRisk('runtime_hooks', $class . '::' . $member, $file, $line)];
        }

        if ($classBase === 'Coroutine' && $member === 'set') {
            return [new RuntimeRisk('runtime_hooks', $class . '::set', $file, $line)];
        }

        if ($classBase === 'Coroutine' && $member === 'create') {
            return [new RuntimeRisk('raw_coroutine_spawn', $class . '::create', $file, $line)];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return str_ends_with($path, '.php') ? [$path] : [];
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

            if ($file->getExtension() === 'php') {
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

    private function basename(string $name): string
    {
        $parts = explode('\\', trim($name, '\\'));

        return end($parts) ?: $name;
    }
}
