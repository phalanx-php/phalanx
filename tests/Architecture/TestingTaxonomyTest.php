<?php

declare(strict_types=1);

namespace Phalanx\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[Group('architecture')]
final class TestingTaxonomyTest extends TestCase
{
    /** @var list<string> */
    private const array TESTING_EXCEPTION_PARAMETERS = [
        'phalanxTestingUseTestAppExemptPaths',
        'phalanxTestingUseTestScopeExemptPaths',
        'phalanxTestingNoRawSleepExemptPaths',
        'phalanxTestingLensRequiresBundleExemptPaths',
    ];

    /** @var list<string> */
    private const array OSR_182_TESTAPP_REACH_THROUGH_FILES = [
        'src/AiProviders/tests/Acceptance/V0AcceptanceGateTest.php',
        'src/Console/tests/Integration/Application/RuntimeTest.php',
        'src/Console/tests/Integration/Command/CommandDispatchTest.php',
        'src/Filesystem/tests/Unit/FilePoolTest.php',
        'src/Filesystem/tests/Unit/FilesystemTest.php',
        'src/Filesystem/tests/Unit/ReadFileTest.php',
        'src/Filesystem/tests/Unit/WriteFileTest.php',
        'src/Http/tests/Integration/Auth/AuthenticateTest.php',
        'src/Http/tests/Integration/GracefulDrainTest.php',
        'src/Http/tests/Integration/ResponseLeaseTest.php',
        'src/Http/tests/Integration/RunnerTest.php',
        'src/Http/tests/Integration/Upgrade/SeamTest.php',
        'src/Http/tests/Support/TestCase.php',
        'src/Http/tests/Unit/Response/HtmlErrorResponseRendererTest.php',
        'src/Http/tests/Unit/Response/IgnitionErrorResponseRendererTest.php',
        'src/Http/tests/Unit/RunnerActiveRequestsTest.php',
        'src/Http/tests/Unit/Validator/RequireAbilityTest.php',
        'src/Http/tests/Unit/Validator/RequireQueryParamTest.php',
        'src/HttpClient/tests/Unit/ClientTest.php',
        'src/Runtime/tests/Integration/Boot/CannotBootRenderingTest.php',
        'src/Runtime/tests/Integration/Handler/HandlerDispatchTest.php',
        'src/Runtime/tests/Integration/Handler/HandlerResolverTest.php',
        'src/Runtime/tests/Integration/Handler/HasMiddlewareDispatchTest.php',
        'src/Runtime/tests/Unit/Scope/PeriodicTest.php',
        'src/Runtime/tests/Unit/Testing/Lenses/LedgerLensTest.php',
        'src/Runtime/tests/Unit/Testing/Lenses/RuntimeLensTest.php',
        'src/Runtime/tests/Unit/Testing/Lenses/ScopeLensTest.php',
        'src/Runtime/tests/Unit/Testing/PhalanxTestCaseTestAppTest.php',
        'src/Runtime/tests/Unit/Testing/TestAppTest.php',
        'src/SurrealDb/tests/Unit/BundleTest.php',
        'src/Tui/tests/Unit/Apps/AppInputTest.php',
        'src/Tui/tests/Unit/Apps/AppRenderDiagnosticsTest.php',
        'src/WebSocket/tests/Integration/ClientCancellationTest.php',
        'src/WebSocket/tests/Integration/ClientConcurrentSendTest.php',
        'src/WebSocket/tests/Integration/ClientHandshakeTest.php',
        'src/WebSocket/tests/Integration/ServerUpgradeTest.php',
        'src/WebSocket/tests/Unit/WebSocketTest.php',
        'src/Worker/tests/Integration/InWorkerTest.php',
    ];

    #[Test]
    public function every_test_file_has_one_taxonomy_bucket(): void
    {
        $violations = [];
        $classified = [];

        foreach (self::testFiles() as $file) {
            $signals = TestFileSignals::from($file, self::root());
            $bucket = $signals->classify();

            if ($bucket === null) {
                $violations[] = "{$signals->path} does not match any OSR-181 testing taxonomy bucket.";
                continue;
            }

            if (!$bucket->acceptsBaseClass($signals)) {
                $violations[] = $bucket->baseClassViolation($signals);
                continue;
            }

            $classified[$bucket->value][] = $signals->path;
        }

        foreach (TestingTaxonomy::cases() as $bucket) {
            if ($bucket->requiresCurrentCoverage() && ($classified[$bucket->value] ?? []) === []) {
                $violations[] = "No tests classified as {$bucket->value}.";
            }
        }

        self::assertSame([], $violations);
    }

    #[Test]
    public function testing_phpstan_exceptions_are_file_scoped(): void
    {
        $parameters = self::phpStanParameters();
        $violations = [];

        foreach (self::TESTING_EXCEPTION_PARAMETERS as $parameter) {
            foreach (($parameters[$parameter] ?? []) as $path) {
                if (!is_string($path) || $path === '') {
                    $violations[] = "{$parameter} contains a non-string or empty path.";
                    continue;
                }

                if (!str_starts_with($path, 'src/') || !str_contains($path, '/tests/')) {
                    $violations[] = "{$parameter} entry {$path} is not a module-local test path.";
                    continue;
                }

                if (!str_ends_with($path, '.php')) {
                    $violations[] = "{$parameter} entry {$path} must name a concrete PHP file.";
                    continue;
                }

                if (!is_file(self::root() . '/' . $path)) {
                    $violations[] = "{$parameter} entry {$path} does not exist.";
                }
            }

            $duplicates = self::duplicates($parameters[$parameter] ?? []);
            foreach ($duplicates as $path) {
                $violations[] = "{$parameter} entry {$path} is duplicated.";
            }
        }

        self::assertSame([], $violations);
    }

    #[Test]
    public function phalanx_phpstan_test_ignores_are_file_scoped(): void
    {
        $violations = [];
        $pathsByIdentifier = self::phalanxTestIgnorePaths();

        foreach ($pathsByIdentifier as $identifier => $paths) {
            foreach ($paths as $path) {
                if (!str_starts_with($path, 'src/') || !str_contains($path, '/tests/')) {
                    $violations[] = "{$identifier} ignore path {$path} is not a module-local test path.";
                    continue;
                }

                if (!str_ends_with($path, '.php')) {
                    $violations[] = "{$identifier} ignore path {$path} must name a concrete PHP file.";
                    continue;
                }

                if (!is_file(self::root() . '/' . $path)) {
                    $violations[] = "{$identifier} ignore path {$path} does not exist.";
                }
            }

            foreach (self::duplicates($paths) as $path) {
                $violations[] = "{$identifier} ignore path {$path} is duplicated.";
            }
        }

        self::assertSame([], $violations);
    }

    #[Test]
    public function testapp_reach_through_is_fenced_for_osr_182(): void
    {
        $actual = [];

        foreach (self::testPhpFiles() as $file) {
            $signals = TestFileSignals::from($file, self::root());
            if (!$signals->rawApplication || $signals->architecture || $signals->phpstan) {
                continue;
            }

            $actual[$signals->path] = $signals;
        }

        $violations = [];
        $expected = array_fill_keys(self::OSR_182_TESTAPP_REACH_THROUGH_FILES, true);

        foreach ($actual as $path => $signals) {
            if (isset($expected[$path])) {
                continue;
            }

            $hit = $signals->firstRawApplicationHit();
            $violations[] = sprintf(
                '%s:%d uses %s; ordinary TestApp reach-through is OSR-182 cleanup scope and must not spread.',
                $path,
                $hit?->line ?? 1,
                $hit?->label ?? 'raw application access',
            );
        }

        foreach (array_keys($expected) as $path) {
            if (!isset($actual[$path])) {
                $violations[] = "{$path} no longer uses TestApp reach-through; remove it from the OSR-182 fence.";
            }
        }

        self::assertSame([], $violations);
    }

    #[Test]
    public function raw_sleep_exceptions_name_files_that_contain_raw_sleep_boundaries(): void
    {
        $expected = [
            ...(self::phpStanParameters()['phalanxTestingNoRawSleepExemptPaths'] ?? []),
            ...(self::phalanxTestIgnorePaths(['phalanx.cancellation.rawSleep'])['phalanx.cancellation.rawSleep'] ?? []),
        ];
        $violations = [];

        foreach (array_unique($expected) as $path) {
            $signals = TestFileSignals::from(self::root() . '/' . $path, self::root());
            if (!$signals->rawSleep) {
                $violations[] = "Raw-sleep exception {$path} no longer contains a raw sleep boundary.";
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * @return list<string>
     */
    private static function testPhpFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root() . '/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, '/tests/')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function testFiles(): array
    {
        $files = [];

        foreach ([self::root() . '/src', self::root() . '/tests/Architecture'] as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }

                if (!str_ends_with($file->getFilename(), 'Test.php')) {
                    continue;
                }

                $path = $file->getPathname();
                if (!str_contains($path, '/tests/')) {
                    continue;
                }

                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private static function duplicates(array $values): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            isset($seen[$value]) ? $duplicates[$value] = true : $seen[$value] = true;
        }

        $paths = array_keys($duplicates);
        sort($paths);

        return $paths;
    }

    /**
     * @param null|list<string> $identifiers
     * @return array<string, list<string>>
     */
    private static function phalanxTestIgnorePaths(?array $identifiers = null): array
    {
        $pathsByIdentifier = [];

        foreach (self::phpStanParameters()['ignoreErrors'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $identifier = $entry['identifier'] ?? null;
            $path = $entry['path'] ?? null;
            if (!is_string($identifier) || !is_string($path) || !str_starts_with($identifier, 'phalanx.')) {
                continue;
            }

            if ($identifiers !== null && !in_array($identifier, $identifiers, true)) {
                continue;
            }

            if (!str_contains($path, '/tests/')) {
                continue;
            }

            $pathsByIdentifier[$identifier] ??= [];
            $pathsByIdentifier[$identifier][] = $path;
        }

        ksort($pathsByIdentifier);

        return $pathsByIdentifier;
    }

    /**
     * @return array<string, mixed>
     */
    private static function phpStanParameters(): array
    {
        $config = Yaml::parseFile(self::root() . '/phpstan.neon');

        self::assertIsArray($config);
        self::assertIsArray($config['parameters'] ?? null);

        return $config['parameters'];
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}

enum TestingTaxonomy: string
{
    case PureUnit = 'Pure Unit';
    case RuntimeUnit = 'Runtime Unit';
    case AppIntegration = 'App Integration';
    case PackageLensIntegration = 'Package Lens Integration';
    case KernelInternalException = 'Kernel/Internal Exception';
    case ExternalFixtureProcess = 'External Fixture/Process';

    public function acceptsBaseClass(TestFileSignals $signals): bool
    {
        return match ($this) {
            self::PureUnit => !$signals->usesPhalanxHarness,
            self::RuntimeUnit,
            self::AppIntegration,
            self::PackageLensIntegration => $signals->usesPhalanxHarness,
            self::KernelInternalException,
            self::ExternalFixtureProcess => true,
        };
    }

    public function baseClassViolation(TestFileSignals $signals): string
    {
        $expected = match ($this) {
            self::PureUnit => 'a plain PHPUnit base class with no Phalanx harness',
            self::RuntimeUnit,
            self::AppIntegration,
            self::PackageLensIntegration => 'PhalanxTestCase or a module support case that extends it',
            self::KernelInternalException,
            self::ExternalFixtureProcess => 'an explicitly reviewed exception base class',
        };

        return sprintf(
            '%s classifies as %s but extends %s; expected %s.',
            $signals->path,
            $this->value,
            $signals->baseClass ?? '<none>',
            $expected,
        );
    }

    public function requiresCurrentCoverage(): bool
    {
        return match ($this) {
            self::PureUnit,
            self::RuntimeUnit,
            self::AppIntegration,
            self::PackageLensIntegration => true,
            self::KernelInternalException,
            self::ExternalFixtureProcess => false,
        };
    }
}

readonly class TestFileSignals
{
    /**
     * @param list<SourceHit> $rawApplicationHits
     */
    public function __construct(
        public string $path,
        public ?string $baseClass,
        public bool $usesPhalanxHarness,
        public bool $usesTestApp,
        public bool $usesLens,
        public bool $rawApplication,
        public bool $rawScope,
        public bool $rawSleep,
        public bool $rawSwoole,
        public bool $externalBoundary,
        public bool $architecture,
        public bool $phpstan,
        public bool $runtimeInternal,
        private array $rawApplicationHits,
    ) {
    }

    public static function from(string $file, string $root): self
    {
        $path = ltrim(str_replace($root, '', $file), '/');
        $source = (string) file_get_contents($file);
        $rawApplicationHits = self::rawApplicationHits($source);

        return new self(
            path: $path,
            baseClass: self::baseClass($source),
            usesPhalanxHarness: self::usesPhalanxHarness($source),
            usesTestApp: str_contains($source, 'testApp(') || str_contains($source, 'TestApp::boot('),
            usesLens: self::usesLens($source),
            rawApplication: $rawApplicationHits !== [],
            rawScope: str_contains($source, 'createScope('),
            rawSleep: self::usesRawSleep($source),
            rawSwoole: str_contains($source, 'Swoole\\'),
            externalBoundary: self::usesExternalBoundary($source),
            architecture: str_starts_with($path, 'tests/Architecture/'),
            phpstan: str_starts_with($path, 'src/PHPStan/tests/'),
            runtimeInternal: self::isRuntimeInternalPath($path),
            rawApplicationHits: $rawApplicationHits,
        );
    }

    public function classify(): ?TestingTaxonomy
    {
        if ($this->architecture || $this->phpstan) {
            return TestingTaxonomy::PureUnit;
        }

        if (
            $this->runtimeInternal
            && (
                $this->usesTestApp
                || $this->usesLens
                || $this->rawApplication
                || $this->rawScope
                || $this->rawSleep
                || $this->rawSwoole
            )
        ) {
            return TestingTaxonomy::KernelInternalException;
        }

        if ($this->usesLens) {
            return TestingTaxonomy::PackageLensIntegration;
        }

        if ($this->usesTestApp || $this->rawApplication) {
            return TestingTaxonomy::AppIntegration;
        }

        if ($this->usesPhalanxHarness) {
            return TestingTaxonomy::RuntimeUnit;
        }

        if ($this->externalBoundary) {
            return TestingTaxonomy::ExternalFixtureProcess;
        }

        if ($this->baseClass !== null) {
            return TestingTaxonomy::PureUnit;
        }

        return null;
    }

    public function firstRawApplicationHit(): ?SourceHit
    {
        return $this->rawApplicationHits[0] ?? null;
    }

    private static function usesPhalanxHarness(string $source): bool
    {
        return str_contains($source, 'Phalanx\\Testing\\PhalanxTestCase')
            || str_contains($source, 'Phalanx\\Http\\Tests\\Support\\TestCase')
            || str_contains($source, 'Phalanx\\Console\\Tests\\Support\\TestCase')
            || preg_match('/use\s+Phalanx\\\\[A-Za-z0-9_]+\\\\Tests\\\\Support\\\\TestCase;/', $source) === 1;
    }

    private static function usesLens(string $source): bool
    {
        return str_contains($source, 'TestableBundle')
            || str_contains($source, 'TestLens::')
            || str_contains($source, 'Phalanx\\Testing\\Lens')
            || str_contains($source, 'Phalanx\\Testing\\Attribute\\Lens');
    }

    private static function usesRawSleep(string $source): bool
    {
        return preg_match('/(?<![A-Za-z_])u?sleep\s*\(/', $source) === 1
            || preg_match('/(?:Coroutine|Co)::u?sleep\s*\(/', $source) === 1;
    }

    private static function usesExternalBoundary(string $source): bool
    {
        foreach ([
            'PHP_BINARY',
            'Symfony\\Component\\Process',
            'new Process(',
            'proc_open(',
            'fsockopen(',
            'stream_socket',
            'new Socket(',
            'socket_',
            'tempnam(',
            'sys_get_temp_dir(',
            'php://',
            'AnthropicLiveTest',
            '#[RequiresDaemon8]',
            '#[RequiresService]',
        ] as $needle) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<SourceHit>
     */
    private static function rawApplicationHits(string $source): array
    {
        return [
            ...self::sourceHits($source, '/->application/', 'TestApp->application'),
            ...self::sourceHits($source, '/(?<![A-Za-z_])startedApplication\s*\(/', 'startedApplication()'),
            ...self::sourceHits($source, '/(?<![A-Za-z_])application\s*\(/', 'application()'),
        ];
    }

    /**
     * @return list<SourceHit>
     */
    private static function sourceHits(string $source, string $pattern, string $label): array
    {
        $count = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0) {
            return [];
        }

        $hits = [];
        foreach ($matches[0] as $match) {
            $hits[] = new SourceHit(
                label: $label,
                line: substr_count(substr($source, 0, $match[1]), "\n") + 1,
            );
        }

        return $hits;
    }

    private static function isRuntimeInternalPath(string $path): bool
    {
        foreach ([
            'src/Runtime/tests/Integration/',
            'src/Runtime/tests/Resilience/',
            'src/Runtime/tests/Smoke/',
            'src/Runtime/tests/Unit/Boot/',
            'src/Runtime/tests/Unit/Scope/',
            'src/Runtime/tests/Unit/Supervisor/',
            'src/Runtime/tests/Unit/System/',
            'src/Runtime/tests/Unit/Testing/',
        ] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function baseClass(string $source): ?string
    {
        $count = preg_match_all(
            '/\bclass\s+(\w+)\s+extends\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/',
            $source,
            $matches,
            PREG_SET_ORDER,
        );
        if ($count === false || $count === 0) {
            return null;
        }

        foreach ($matches as $match) {
            if (str_ends_with($match[1], 'Test')) {
                return $match[2];
            }
        }

        return $matches[0][2] ?? null;
    }
}

readonly class SourceHit
{
    public function __construct(
        public string $label,
        public int $line,
    ) {
    }
}
