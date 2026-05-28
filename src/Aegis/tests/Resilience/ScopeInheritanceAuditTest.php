<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Tests\Resilience;

use PHPUnit\Framework\TestCase;

/**
 * Static-source audit. Every coroutine spawn in src/Scope/ MUST be
 * followed within ~16 source lines by `CoroutineScopeRegistry::install(...)`
 * as the first action of the spawned closure body. Swoole child coroutines
 * do NOT inherit parent context; missing the install call is the canonical
 * foot-gun that produces orphaned tasks invisible to the supervisor.
 *
 * This is a regression tripwire -- it doesn't run anything async; it
 * reads the source. Future contributors who add a new primitive without
 * installing the parent scope will see a failed test pointing at the
 * exact line.
 */
final class ScopeInheritanceAuditTest extends TestCase
{
    private const string SCOPE_SRC_DIR = __DIR__ . '/../../src/Scope';

    public function testEverySpawnInScopeDirInstallsTheCapturedScope(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(self::SCOPE_SRC_DIR) as $file) {
            $lines = file($file);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $idx => $line) {
                if (!str_contains($line, 'Substrate::coroutine()->create(')) {
                    continue;
                }
                // Look shortly after the spawn for the install call. Long
                // `use (...)` lists are line-wrapped by the style checker, so
                // this window allows formatting without weakening the audit.
                $window = implode('', array_slice($lines, $idx + 1, 16));
                if (!str_contains($window, 'CoroutineScopeRegistry::install(')) {
                    $offenders[] = sprintf(
                        '%s:%d  %s',
                        basename($file),
                        $idx + 1,
                        trim($line),
                    );
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Every Substrate::coroutine()->create() in src/Scope must be followed by\n"
            . "CoroutineScopeRegistry::install() in the spawned closure. Offenders:\n"
            . implode("\n", $offenders),
        );
    }

    /**
     * @return iterable<string>
     */
    private function phpFilesIn(string $dir): iterable
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
