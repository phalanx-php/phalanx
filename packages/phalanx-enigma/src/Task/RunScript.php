<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Task;

use Phalanx\Enigma\CommandResult;
use Phalanx\Enigma\SshCredential;
use Phalanx\Scope\ExecutionScope;
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
    ) {
    }

    public function __invoke(ExecutionScope $scope): CommandResult
    {
        $remotePath = '/tmp/phalanx-script-' . uniqid('', true);

        $scope->execute(new SftpUpload(
            credential: $this->credential,
            remotePath: $remotePath,
            localContent: $this->scriptContent,
        ));

        $escaped = escapeshellarg($remotePath);
        $command = "chmod +x {$escaped} && {$this->interpreter} {$escaped}; EXIT_CODE=\$?; rm -f {$escaped}; exit \$EXIT_CODE";

        return $scope->execute(new RunCommand(
            credential: $this->credential,
            command: $command,
            timeoutSeconds: $this->timeoutSeconds,
        ));
    }
}
