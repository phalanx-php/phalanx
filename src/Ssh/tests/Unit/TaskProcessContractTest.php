<?php

declare(strict_types=1);

namespace Phalanx\Ssh\Tests\Unit;

use Closure;
use Phalanx\Boot\AppContext;
use Phalanx\Ssh\CommandResult;
use Phalanx\Ssh\SshConfig;
use Phalanx\Ssh\SshCredential;
use Phalanx\Ssh\Task\RunCommand;
use Phalanx\Ssh\Task\ScpTransfer;
use Phalanx\Ssh\Task\SftpUpload;
use Phalanx\Ssh\TransferDirection;
use Phalanx\Runtime\Identity\RuntimeResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class TaskProcessContractTest extends PhalanxTestCase
{
    private ?string $scpBinaryPath = null;
    private ?string $sftpBinaryPath = null;
    private ?string $sshBinaryPath = null;

    #[Test]
    public function runCommandUsesConfiguredBinaryAndCollectsResult(): void
    {
        $this->sshBinaryPath = $this->writeExecutable(<<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

$separator = array_search('--', $argv, true);
$command = $separator === false ? '' : (string) ($argv[$separator + 1] ?? '');
fwrite(STDOUT, "agent:{$command}\n");
fwrite(STDERR, "ssh\n");
exit(7);
PHP);

        $result = $this->scope->run(static function (ExecutionScope $scope): CommandResult {
            return $scope->execute(new RunCommand(
                credential: new SshCredential(host: 'agent.internal', user: 'deploy'),
                command: 'whoami',
            ));
        });

        self::assertSame(7, $result->exitCode);
        self::assertSame("agent:whoami\n", $result->stdout);
        self::assertSame("ssh\n", $result->stderr);
        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    #[Test]
    public function scpTransferUsesConfiguredBinaryAndReportsLocalBytes(): void
    {
        $this->scpBinaryPath = $this->writeExecutable(<<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

exit(0);
PHP);
        $localFile = $this->writeDataFile('agent-scp-body');

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($localFile) {
            return $scope->execute(new ScpTransfer(
                credential: new SshCredential(host: 'agent.internal', user: 'deploy'),
                from: $localFile,
                to: '/tmp/agent-scp-body',
                direction: TransferDirection::Upload,
            ));
        });

        self::assertSame(strlen('agent-scp-body'), $result->bytesTransferred);
        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    #[Test]
    public function sftpUploadCleansBatchAndContentTempFiles(): void
    {
        $marker = $this->writeDataFile('');
        $this->sftpBinaryPath = $this->writeExecutable(<<<PHP
#!/usr/bin/env php
<?php
declare(strict_types=1);

\$batchFile = (string) \$argv[array_search('-b', \$argv, true) + 1];
\$batch = file_get_contents(\$batchFile);
preg_match('/^put\\s+(\\S+)\\s+/', (string) \$batch, \$matches);
file_put_contents('{$marker}', json_encode([
    'batch' => \$batchFile,
    'local' => \$matches[1] ?? '',
], JSON_THROW_ON_ERROR));
exit(0);
PHP);

        $result = $this->scope->run(static function (ExecutionScope $scope) {
            return $scope->execute(new SftpUpload(
                credential: new SshCredential(host: 'agent.internal', user: 'deploy'),
                remotePath: '/tmp/agent-upload',
                localContent: 'agent-upload-body',
            ));
        });

        $paths = json_decode($this->tempWorkspace()->readPath($marker), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(strlen('agent-upload-body'), $result->bytesTransferred);
        self::assertIsArray($paths);
        self::assertFileDoesNotExist((string) $paths['batch']);
        self::assertFileDoesNotExist((string) $paths['local']);
        self::assertSame(0, $this->scope->memory->resources->liveCount(RuntimeResourceSid::StreamingProcess));
    }

    protected function phalanxServices(): Closure
    {
        return static function (Services $services, AppContext $context): void {
            $config = new SshConfig(
                sshBinaryPath: $context->string('sshBinaryPath'),
                scpBinaryPath: $context->string('scpBinaryPath'),
                sftpBinaryPath: $context->string('sftpBinaryPath'),
                defaultTimeoutSeconds: 1.0,
                connectionTimeoutSeconds: 1.0,
                strictHostKeyChecking: false,
            );
            $services->singleton(SshConfig::class)
                ->factory(static fn(): SshConfig => $config);
        };
    }

    /** @return array<string, mixed> */
    #[\Override]
    protected function phalanxContext(): array
    {
        return [
            'sshBinaryPath' => $this->sshBinaryPath ?? PHP_BINARY,
            'scpBinaryPath' => $this->scpBinaryPath ?? PHP_BINARY,
            'sftpBinaryPath' => $this->sftpBinaryPath ?? PHP_BINARY,
        ];
    }

    private function writeExecutable(string $contents): string
    {
        $path = $this->writeDataFile($contents);
        chmod($path, 0755);

        return $path;
    }

    private function writeDataFile(string $contents): string
    {
        return $this->tempWorkspace('phalanx-ssh-task-')->file(uniqid('script-', true), $contents);
    }
}
