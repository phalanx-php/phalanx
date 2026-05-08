<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Tests\Unit;

use Closure;
use Phalanx\Boot\AppContext;
use Phalanx\Enigma\CommandResult;
use Phalanx\Enigma\SshConfig;
use Phalanx\Enigma\SshCredential;
use Phalanx\Enigma\Task\RunCommand;
use Phalanx\Enigma\Task\ScpTransfer;
use Phalanx\Enigma\Task\SftpUpload;
use Phalanx\Enigma\TransferDirection;
use Phalanx\Runtime\Identity\AegisResourceSid;
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
fwrite(STDOUT, "athena:{$command}\n");
fwrite(STDERR, "enigma\n");
exit(7);
PHP);

        $result = $this->scope->run(static function (ExecutionScope $scope): CommandResult {
            return $scope->execute(new RunCommand(
                credential: new SshCredential(host: 'athena.internal', user: 'deploy'),
                command: 'whoami',
            ));
        });

        self::assertSame(7, $result->exitCode);
        self::assertSame("athena:whoami\n", $result->stdout);
        self::assertSame("enigma\n", $result->stderr);
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
    }

    public function testScpTransferUsesConfiguredBinaryAndReportsLocalBytes(): void
    {
        $this->scpBinaryPath = self::writeExecutable(<<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

exit(0);
PHP);
        $localFile = self::writeDataFile('athena-scp-body');

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($localFile) {
            return $scope->execute(new ScpTransfer(
                credential: new SshCredential(host: 'athena.internal', user: 'deploy'),
                from: $localFile,
                to: '/tmp/athena-scp-body',
                direction: TransferDirection::Upload,
            ));
        });

        self::assertSame(strlen('athena-scp-body'), $result->bytesTransferred);
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
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
                credential: new SshCredential(host: 'athena.internal', user: 'deploy'),
                remotePath: '/tmp/athena-upload',
                localContent: 'athena-upload-body',
            ));
        });

        $paths = json_decode((string) file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(strlen('athena-upload-body'), $result->bytesTransferred);
        self::assertIsArray($paths);
        self::assertFileDoesNotExist((string) $paths['batch']);
        self::assertFileDoesNotExist((string) $paths['local']);
        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::StreamingProcess));
    }

    protected function phalanxServices(): ?Closure
    {
        return static function (Services $services): void {
            $services->config(SshConfig::class, static fn(array $context): SshConfig => new SshConfig(
                sshBinaryPath: (string) $context['sshBinaryPath'],
                scpBinaryPath: (string) $context['scpBinaryPath'],
                sftpBinaryPath: (string) $context['sftpBinaryPath'],
                defaultTimeoutSeconds: 1.0,
                connectionTimeoutSeconds: 1.0,
                strictHostKeyChecking: false,
            ));
        };
    }

    protected function phalanxContext(): AppContext
    {
        return AppContext::test([
            'sshBinaryPath' => $this->sshBinaryPath ?? PHP_BINARY,
            'scpBinaryPath' => $this->scpBinaryPath ?? PHP_BINARY,
            'sftpBinaryPath' => $this->sftpBinaryPath ?? PHP_BINARY,
        ]);
    }

    private static function writeExecutable(string $contents): string
    {
        $path = self::writeDataFile($contents);
        chmod($path, 0755);

        return $path;
    }

    private static function writeDataFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phalanx-enigma-task-');
        if ($path === false) {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phalanx-enigma-task-' . uniqid('', true);
        }

        file_put_contents($path, $contents);

        return $path;
    }
}
