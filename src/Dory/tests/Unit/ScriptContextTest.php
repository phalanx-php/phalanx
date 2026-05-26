<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit;

use Phalanx\Dory\DoryConfig;
use Phalanx\Dory\Orchestration\AttemptBuilder;
use Phalanx\Dory\ScriptContext;
use Phalanx\Grammata\Files;
use Phalanx\Iris\HttpClient;
use Phalanx\Scope\ExecutionScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScriptContextTest extends TestCase
{
    #[Test]
    public function script_name_derives_from_path(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $config = new DoryConfig();

        $dory = new ScriptContext($scope, '/home/zeus/scripts/deploy.php', $config);

        self::assertSame('deploy.php', $dory->scriptName);
    }

    #[Test]
    public function script_path_is_accessible(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $config = new DoryConfig();

        $dory = new ScriptContext($scope, '/tmp/test.php', $config);

        self::assertSame('/tmp/test.php', $dory->scriptPath);
    }

    #[Test]
    public function config_is_accessible(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $config = new DoryConfig(scriptTimeout: 99.0);

        $dory = new ScriptContext($scope, '/tmp/test.php', $config);

        self::assertSame(99.0, $dory->config->scriptTimeout);
    }

    #[Test]
    public function attempt_returns_builder(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $config = new DoryConfig();
        $dory = new ScriptContext($scope, '/tmp/test.php', $config);

        $builder = $dory->attempt(static fn(): string => 'olympus');

        self::assertInstanceOf(AttemptBuilder::class, $builder);
    }

    #[Test]
    public function println_outputs_to_stdout(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $config = new DoryConfig();
        $dory = new ScriptContext($scope, '/tmp/test.php', $config);

        ob_start();
        $dory->println('hoplite formation ready');
        $output = ob_get_clean();

        self::assertSame("hoplite formation ready\n", $output);
    }

    #[Test]
    public function dump_outputs_string_values_directly(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $config = new DoryConfig();
        $dory = new ScriptContext($scope, '/tmp/test.php', $config);

        ob_start();
        $dory->dump('sparta', 'thermopylae');
        $output = ob_get_clean();

        self::assertSame("sparta\nthermopylae\n", $output);
    }

    #[Test]
    public function dump_exports_non_string_values(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $config = new DoryConfig();
        $dory = new ScriptContext($scope, '/tmp/test.php', $config);

        ob_start();
        $dory->dump(42);
        $output = ob_get_clean();

        self::assertSame("42\n", $output);
    }

    #[Test]
    public function delegates_service_to_inner_scope(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $scope->expects(self::once())
            ->method('service')
            ->with(DoryConfig::class)
            ->willReturn(new DoryConfig());

        $config = new DoryConfig();
        $dory = new ScriptContext($scope, '/tmp/test.php', $config);

        $dory->service(DoryConfig::class);
    }

    #[Test]
    public function http_property_resolves_via_service(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $scope = $this->createMock(ExecutionScope::class);
        $scope->expects(self::once())
            ->method('service')
            ->with(HttpClient::class)
            ->willReturn($httpClient);

        $config = new DoryConfig();
        $dory = new ScriptContext($scope, '/tmp/test.php', $config);

        self::assertSame($httpClient, $dory->http);
    }

    #[Test]
    public function fs_property_calls_service_with_files_class(): void
    {
        $scope = $this->createMock(ExecutionScope::class);
        $scope->expects(self::once())
            ->method('service')
            ->with(Files::class)
            ->willThrowException(new \RuntimeException('service called'));

        $config = new DoryConfig();
        $dory = new ScriptContext($scope, '/tmp/test.php', $config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('service called');

        $dory->fs;
    }
}
