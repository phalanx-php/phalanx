<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Task;

use Phalanx\ExecutionScope;
use Phalanx\Enigma\CommandResult;
use Phalanx\Enigma\SshCredential;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;

final class RunScript implements Executable, HasTimeout
{
    public float $timeout {
        get => $this->timeoutSeconds ?? 0.0;
    }

    public function __construct(
        private readonly SshCredential $credential,
        private readonly string $scriptContent,
        private readonly string $interpreter = '/bin/bash',
        private readonly ?float $timeoutSeconds = null,
    ) {}

    public function __invoke(ExecutionScope $scope): CommandResult
    {
        $remotePath = '/tmp/phalanx-script-' . bin2hex(random_bytes(8));

        $scope->execute(new SftpUpload(
            credential: $this->credential,
            remotePath: $remotePath,
            localContent: $this->scriptContent,
        ));

        $command = "chmod +x {$remotePath} && {$this->interpreter} {$remotePath}; EXIT_CODE=\$?; rm -f {$remotePath}; exit \$EXIT_CODE";

        return $scope->execute(new RunCommand(
            credential: $this->credential,
            command: $command,
            timeoutSeconds: $this->timeoutSeconds,
        ));
    }
}
