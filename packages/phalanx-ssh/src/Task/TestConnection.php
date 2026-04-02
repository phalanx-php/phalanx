<?php

declare(strict_types=1);

namespace Phalanx\Ssh\Task;

use Phalanx\ExecutionScope;
use Phalanx\Ssh\SshCredential;
use Phalanx\Task\Executable;

final class TestConnection implements Executable
{
    public function __construct(
        private readonly SshCredential $credential,
    ) {}

    public function __invoke(ExecutionScope $scope): bool
    {
        try {
            $result = $scope->execute(new RunCommand(
                credential: $this->credential,
                command: 'exit 0',
                timeoutSeconds: 10.0,
            ));

            return $result->successful;
        } catch (\Throwable) {
            return false;
        }
    }
}
