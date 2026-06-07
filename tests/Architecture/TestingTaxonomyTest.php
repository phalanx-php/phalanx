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
    private const string PURE_UNIT = 'Pure Unit';
    private const string RUNTIME_UNIT = 'Runtime Unit';
    private const string APP_INTEGRATION = 'App Integration';
    private const string PACKAGE_LENS_INTEGRATION = 'Package Lens Integration';
    private const string KERNEL_INTERNAL_EXCEPTION = 'Kernel/Internal Exception';
    private const string EXTERNAL_FIXTURE_PROCESS = 'External Fixture/Process';

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
            $signals = self::signals($file);
            $bucket = self::classify($signals);

            if ($bucket === null) {
                $violations[] = self::relative($file) . ' does not match any OSR-181 testing taxonomy bucket.';
                continue;
            }

            if (!self::baseClassMatches($bucket, $signals)) {
                $violations[] = sprintf(
                    '%s classifies as %s but uses incompatible base class %s.',
                    self::relative($file),
                    $bucket,
                    $signals['baseClass'] ?? '<none>',
                );
                continue;
            }

            $classified[$bucket][] = self::relative($file);
        }

        foreach ([
            self::PURE_UNIT,
            self::RUNTIME_UNIT,
            self::APP_INTEGRATION,
            self::PACKAGE_LENS_INTEGRATION,
            self::KERNEL_INTERNAL_EXCEPTION,
            self::EXTERNAL_FIXTURE_PROCESS,
        ] as $bucket) {
            if (($classified[$bucket] ?? []) === []) {
                $violations[] = "No tests classified as {$bucket}.";
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
        }

        self::assertSame([], $violations);
    }

    #[Test]
    public function testapp_reach_through_is_fenced_for_osr_182(): void
    {
        $actual = [];

        foreach (self::testFiles() as $file) {
            $signals = self::signals($file);
            if (!$signals['rawApplication'] || $signals['architecture'] || $signals['phpstan']) {
                continue;
            }

            $actual[] = self::relative($file);
        }

        sort($actual);
        $expected = self::OSR_182_TESTAPP_REACH_THROUGH_FILES;
        sort($expected);

        self::assertSame(
            $expected,
            $actual,
            'Ordinary TestApp reach-through is OSR-182 cleanup scope and must not spread.',
        );
    }

    #[Test]
    public function raw_sleep_exceptions_name_files_that_contain_raw_sleep_boundaries(): void
    {
        $expected = self::phpStanParameters()['phalanxTestingNoRawSleepExemptPaths'] ?? [];
        $violations = [];

        foreach ($expected as $path) {
            $source = (string) file_get_contents(self::root() . '/' . $path);
            if (!self::usesRawSleep($source)) {
                $violations[] = "Raw-sleep exception {$path} no longer contains a raw sleep boundary.";
            }
        }

        self::assertSame([], $violations);
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
     * @return array{
     *     path:string,
     *     source:string,
     *     baseClass:?string,
     *     usesPhalanxHarness:bool,
     *     usesRuleHarness:bool,
     *     usesTestApp:bool,
     *     usesLens:bool,
     *     rawApplication:bool,
     *     rawScope:bool,
     *     rawSleep:bool,
     *     rawSwoole:bool,
     *     externalBoundary:bool,
     *     architecture:bool,
     *     phpstan:bool,
     *     runtimeInternal:bool
     * }
     */
    private static function signals(string $file): array
    {
        $relative = self::relative($file);
        $source = (string) file_get_contents($file);

        return [
            'path' => $relative,
            'source' => $source,
            'baseClass' => self::baseClass($source),
            'usesPhalanxHarness' => self::usesPhalanxHarness($source),
            'usesRuleHarness' => str_contains($source, 'RuleTestCase'),
            'usesTestApp' => str_contains($source, 'testApp(') || str_contains($source, 'TestApp::boot('),
            'usesLens' => self::usesLens($source),
            'rawApplication' => str_contains($source, '->application')
                || preg_match('/(?<![A-Za-z_])startedApplication\s*\(/', $source) === 1
                || preg_match('/(?<![A-Za-z_])application\s*\(/', $source) === 1,
            'rawScope' => str_contains($source, 'createScope('),
            'rawSleep' => self::usesRawSleep($source),
            'rawSwoole' => str_contains($source, 'Swoole\\') || str_contains($source, 'Swoole\\'),
            'externalBoundary' => self::usesExternalBoundary($source),
            'architecture' => str_starts_with($relative, 'tests/Architecture/'),
            'phpstan' => str_starts_with($relative, 'src/PHPStan/tests/'),
            'runtimeInternal' => self::isRuntimeInternalPath($relative),
        ];
    }

    /** @param array<string, mixed> $signals */
    private static function classify(array $signals): ?string
    {
        if ($signals['architecture'] || $signals['phpstan']) {
            return self::PURE_UNIT;
        }

        if (
            $signals['runtimeInternal']
            && (
                $signals['usesTestApp']
                || $signals['usesLens']
                || $signals['rawApplication']
                || $signals['rawScope']
                || $signals['rawSleep']
                || $signals['rawSwoole']
            )
        ) {
            return self::KERNEL_INTERNAL_EXCEPTION;
        }

        if ($signals['externalBoundary']) {
            return self::EXTERNAL_FIXTURE_PROCESS;
        }

        if ($signals['usesLens']) {
            return self::PACKAGE_LENS_INTEGRATION;
        }

        if ($signals['usesTestApp'] || $signals['rawApplication']) {
            return self::APP_INTEGRATION;
        }

        if ($signals['usesPhalanxHarness']) {
            return self::RUNTIME_UNIT;
        }

        if ($signals['baseClass'] !== null) {
            return self::PURE_UNIT;
        }

        return null;
    }

    /** @param array<string, mixed> $signals */
    private static function baseClassMatches(string $bucket, array $signals): bool
    {
        return match ($bucket) {
            self::PURE_UNIT => !$signals['usesPhalanxHarness'],
            self::RUNTIME_UNIT,
            self::APP_INTEGRATION,
            self::PACKAGE_LENS_INTEGRATION => $signals['usesPhalanxHarness'],
            self::KERNEL_INTERNAL_EXCEPTION,
            self::EXTERNAL_FIXTURE_PROCESS => true,
            default => false,
        };
    }

    private static function usesPhalanxHarness(string $source): bool
    {
        return str_contains($source, 'Phalanx\\Testing\\PhalanxTestCase')
            || str_contains($source, 'Phalanx\\Http\\Tests\\Support\\TestCase')
            || str_contains($source, 'Phalanx\\Console\\Tests\\Support\\TestCase');
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
        if (preg_match('/\bclass\s+\w+\s+extends\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $source, $match) !== 1) {
            return null;
        }

        return $match[1];
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

    private static function relative(string $path): string
    {
        return ltrim(str_replace(self::root(), '', $path), '/');
    }
}
