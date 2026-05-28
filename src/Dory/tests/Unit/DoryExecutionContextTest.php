<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit;

use Phalanx\Dory\DoryConfig;
use Phalanx\Dory\DoryExecutionContext;
use Phalanx\Dory\Orchestration\AttemptBuilder;
use Phalanx\Grammata\Files;
use Phalanx\Iris\HttpClient;
use Phalanx\Scope\ExecutionScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DoryExecutionContextTest extends TestCase
{
    #[Test]
    public function script_name_derives_from_path(): void
    {
        $scope = $this->createStub(ExecutionScope::class);
        $config = new DoryConfig();

        $ctx = new DoryExecutionContext($scope, '/home/zeus/scripts/deploy.php', $config);

        self::assertSame('deploy.php', $ctx->scriptName);
    }

    #[Test]
    public function script_path_is_accessible(): void
    {
        $scope = $this->createStub(ExecutionScope::class);
        $config = new DoryConfig();

        $ctx = new DoryExecutionContext($scope, '/tmp/test.php', $config);

        self::assertSame('/tmp/test.php', $ctx->scriptPath);
    }

    #[Test]
    public function config_is_accessible(): void
    {
        $scope = $this->createStub(ExecutionScope::class);
        $config = new DoryConfig(scriptTimeout: 99.0);

        $ctx = new DoryExecutionContext($scope, '/tmp/test.php', $config);

        self::assertSame(99.0, $ctx->config->scriptTimeout);
    }

    #[Test]
    public function attempt_returns_builder(): void
    {
        $scope = $this->createStub(ExecutionScope::class);
        $config = new DoryConfig();
        $ctx = new DoryExecutionContext($scope, '/tmp/test.php', $config);

        $builder = $ctx->attempt(static fn(): string => 'olympus');

        self::assertInstanceOf(AttemptBuilder::class, $builder);
    }

    #[Test]
    public function println_outputs_to_stdout(): void
    {
        $scope = $this->createStub(ExecutionScope::class);
        $config = new DoryConfig();
        $ctx = new DoryExecutionContext($scope, '/tmp/test.php', $config);

        ob_start();
        $ctx->println('hoplite formation ready');
        $output = ob_get_clean();

        self::assertSame("hoplite formation ready\n", $output);
    }

    #[Test]
    public function dump_outputs_string_values_directly(): void
    {
        $scope = $this->createStub(ExecutionScope::class);
        $config = new DoryConfig();
        $ctx = new DoryExecutionContext($scope, '/tmp/test.php', $config);

        ob_start();
        $ctx->dump('sparta', 'thermopylae');
        $output = ob_get_clean();

        self::assertSame("sparta\nthermopylae\n", $output);
    }

    #[Test]
    public function dump_exports_non_string_values(): void
    {
        $scope = $this->createStub(ExecutionScope::class);
        $config = new DoryConfig();
        $ctx = new DoryExecutionContext($scope, '/tmp/test.php', $config);

        ob_start();
        $ctx->dump(42);
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
        $ctx = new DoryExecutionContext($scope, '/tmp/test.php', $config);

        $ctx->service(DoryConfig::class);
    }

    #[Test]
    public function http_property_resolves_via_service(): void
    {
        $httpClient = $this->createStub(HttpClient::class);
        $scope = $this->createMock(ExecutionScope::class);
        $scope->expects(self::once())
            ->method('service')
            ->with(HttpClient::class)
            ->willReturn($httpClient);

        $config = new DoryConfig();
        $ctx = new DoryExecutionContext($scope, '/tmp/test.php', $config);

        self::assertSame($httpClient, $ctx->http);
    }

    #[Test]
    public function fs_property_resolves_via_service(): void
    {
        $innerScope = $this->createStub(ExecutionScope::class);
        $files = new Files($innerScope);

        $scope = $this->createMock(ExecutionScope::class);
        $scope->expects(self::once())
            ->method('service')
            ->with(Files::class)
            ->willReturn($files);

        $config = new DoryConfig();
        $ctx = new DoryExecutionContext($scope, '/tmp/test.php', $config);

        self::assertSame($files, $ctx->fs);
    }
}
