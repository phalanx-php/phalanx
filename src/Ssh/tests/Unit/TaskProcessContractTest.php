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

final class TaskProcessContractTest extends PhalanxTestCase
{
    private ?string $scpBinaryPath = null;
    private ?string $sftpBinaryPath = null;
    private ?string $sshBinaryPath = null;

    public function testRunCommandUsesConfiguredBinaryAndCollectsResult(): void
    {
        $this->sshBinaryPath = self::writeExecutable(<<<'PHP'
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

    public function testScpTransferUsesConfiguredBinaryAndReportsLocalBytes(): void
    {
        $this->scpBinaryPath = self::writeExecutable(<<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

exit(0);
PHP);
        $localFile = self::writeDataFile('agent-scp-body');

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

    public function testSftpUploadCleansBatchAndContentTempFiles(): void
    {
        $marker = self::writeDataFile('');
        $this->sftpBinaryPath = self::writeExecutable(<<<PHP
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

        $paths = json_decode((string) file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR);

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

    private static function writeExecutable(string $contents): string
    {
        $path = self::writeDataFile($contents);
        chmod($path, 0755);

        return $path;
    }

    private static function writeDataFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phalanx-ssh-task-');
        if ($path === false) {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phalanx-ssh-task-' . uniqid('', true);
        }

        file_put_contents($path, $contents);

        return $path;
    }
}
