<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Phalanx\Enigma\SshConfig;
use Phalanx\Enigma\SshCredential;

final class SshCredentialTest extends TestCase
{
    public function test_explicit_credential_builds_correct_ssh_args(): void
    {
        $cred = new SshCredential(
            host: '192.168.1.100',
            user: 'deploy',
            port: 2222,
            keyPath: '/home/deploy/.ssh/id_ed25519',
        );

        $args = $cred->toConnectionArgs(new SshConfig());

        self::assertSame('-p', $args[0]);
        self::assertSame('2222', $args[1]);
        self::assertContains('-i', $args);
        self::assertContains('/home/deploy/.ssh/id_ed25519', $args);
        self::assertContains('deploy@192.168.1.100', $args);
        self::assertContains('BatchMode=yes', $args);
    }

    public function test_config_alias_returns_just_alias(): void
    {
        $cred = SshCredential::fromConfig('forge_jhtech');
        $args = $cred->toConnectionArgs(new SshConfig());

        self::assertSame(['forge_jhtech'], $args);
    }

    public function test_sftp_args_use_capital_p_for_port(): void
    {
        $cred = new SshCredential(host: '10.0.0.5', user: 'deploy', port: 2222);
        $args = $cred->toSftpArgs(new SshConfig());

        self::assertSame('-P', $args[0]);
        self::assertSame('2222', $args[1]);
    }

    public function test_scp_args_use_capital_p_for_port(): void
    {
        $cred = new SshCredential(host: '10.0.0.5', user: 'deploy', port: 2222);
        $args = $cred->toScpArgs(new SshConfig());

        self::assertSame('-P', $args[0]);
        self::assertSame('2222', $args[1]);
    }

    public function test_sftp_config_alias_returns_just_alias(): void
    {
        $cred = SshCredential::fromConfig('forge_jhtech');
        $args = $cred->toSftpArgs(new SshConfig());

        self::assertSame(['forge_jhtech'], $args);
    }

    public function test_scp_config_alias_returns_empty(): void
    {
        $cred = SshCredential::fromConfig('forge_jhtech');
        $args = $cred->toScpArgs(new SshConfig());

        self::assertSame([], $args);
    }

    public function test_strict_host_key_checking_disabled(): void
    {
        $cred = new SshCredential(host: 'ephemeral.test', user: 'root');
        $config = new SshConfig(strictHostKeyChecking: false);
        $args = $cred->toConnectionArgs($config);

        self::assertContains('StrictHostKeyChecking=no', $args);
        self::assertContains('UserKnownHostsFile=/dev/null', $args);
    }

    public function test_to_string_never_includes_passphrase(): void
    {
        $cred = new SshCredential(
            host: 'secret.host',
            user: 'admin',
            passphrase: 'super-secret-passphrase',
        );

        self::assertStringNotContainsString('super-secret', (string) $cred);
        self::assertSame('admin@secret.host:22', (string) $cred);
    }

    public function test_to_string_with_config_alias(): void
    {
        $cred = SshCredential::fromConfig('forge_jhtech');

        self::assertSame('forge_jhtech', (string) $cred);
    }

    public function test_scp_prefix_with_config_alias(): void
    {
        $cred = SshCredential::fromConfig('forge_jhtech');

        self::assertSame('forge_jhtech:', $cred->toScpPrefix());
    }

    public function test_scp_prefix_with_explicit_host(): void
    {
        $cred = new SshCredential(host: '10.0.0.5', user: 'deploy');

        self::assertSame('deploy@10.0.0.5:', $cred->toScpPrefix());
    }

    public function test_connection_timeout_from_config(): void
    {
        $cred = new SshCredential(host: 'slow.host', user: 'root');
        $config = new SshConfig(connectionTimeoutSeconds: 30.0);
        $args = $cred->toConnectionArgs($config);

        self::assertContains('ConnectTimeout=30', $args);
    }
}
