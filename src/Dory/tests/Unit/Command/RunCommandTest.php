<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit\Command;

use Phalanx\Archon\Command\CommandArgs;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Dory\Command\RunCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunCommandTest extends TestCase
{
    #[Test]
    public function returns_one_when_script_does_not_exist(): void
    {
        $scope = $this->buildScope('/nonexistent/thermopylae/script.php');
        $command = new RunCommand();

        $result = @$command($scope);

        self::assertSame(1, $result);
    }

    #[Test]
    public function returns_one_for_relative_nonexistent_path(): void
    {
        $scope = $this->buildScope('no-such-directory/olympus.php');
        $command = new RunCommand();

        $result = @$command($scope);

        self::assertSame(1, $result);
    }

    private function buildScope(string $scriptPath): CommandContext
    {
        $args = new CommandArgs(['script' => $scriptPath]);

        $scope = $this->createStub(CommandContext::class);
        $scope->method('$args::get')->willReturn($args);

        return $scope;
    }
}
