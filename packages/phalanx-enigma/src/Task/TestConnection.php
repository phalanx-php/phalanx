<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Task;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Enigma\SshCredential;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

final class TestConnection implements Executable
{
    public function __construct(
        private readonly SshCredential $credential,
    ) {
    }

    public function __invoke(ExecutionScope $scope): bool
    {
        try {
            $result = $scope->execute(new RunCommand(
                credential: $this->credential,
                command: 'exit 0',
                timeoutSeconds: 10.0,
            ));

            return $result->successful;
        } catch (Cancelled $e) {
            throw $e;
        } catch (\Throwable) {
            return false;
        }
    }
}
