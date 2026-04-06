<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/ssh

Non-blocking SSH command execution, file transfer, and tunnel management as Phalanx tasks. Built on `react/child-process` to drive the system `ssh`, `scp`, and `sftp` binaries without blocking the event loop.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Connection Credentials](#connection-credentials)
- [Running Commands](#running-commands)
- [Running Scripts](#running-scripts)
- [File Transfer (SFTP)](#file-transfer-sftp)
- [File Transfer (SCP)](#file-transfer-scp)
- [SSH Tunnels](#ssh-tunnels)
- [Tunnel-Through (Bastion Hosts)](#tunnel-through-bastion-hosts)
- [Deployment](#deployment)
- [Multi-Server Deployment](#multi-server-deployment)
- [Connection Testing](#connection-testing)
- [Service Bundle](#service-bundle)
- [Error Handling](#error-handling)

## Installation

```bash
composer require phalanx/ssh
```

Requires PHP 8.4+, `phalanx/core`, `react/child-process`, `react/async`, `react/promise`, and `react/promise-timer`.

## Quick Start

```php
<?php

use Phalanx\Application;
use Phalanx\Ssh\SshCredential;
use Phalanx\Ssh\SshServiceBundle;
use Phalanx\Ssh\Task\RunCommand;

$scope = Application::starting()
    ->providers(new SshServiceBundle())
    ->compile()
    ->startup()
    ->createScope();

$server = new SshCredential(host: '192.168.1.10', user: 'deploy');

$result = $scope->execute(new RunCommand(
    credential: $server,
    command: 'uptime',
));

echo $result->stdout; // 14:32:01 up 42 days, ...

$scope->dispose();
```

## Connection Credentials

`SshCredential` describes how to reach a remote host. It supports explicit host/port/user/key parameters or an SSH config alias:

```php
<?php

use Phalanx\Ssh\SshCredential;

// Explicit connection details
$server = new SshCredential(
    host: '10.0.0.5',
    user: 'deploy',
    port: 22,
    keyPath: '/home/deploy/.ssh/id_ed25519',
);

// SSH config alias (~/.ssh/config)
$server = SshCredential::fromConfig('production-web-01');
```

When using `fromConfig()`, all connection details come from your SSH config file. The credential passes the alias directly to the ssh binary.

## Running Commands

`RunCommand` executes a shell command on the remote host and returns a `CommandResult`:

```php
<?php

use Phalanx\Ssh\Task\RunCommand;

$result = $scope->execute(new RunCommand(
    credential: $server,
    command: 'df -h / | tail -1',
    timeoutSeconds: 10.0,
));

if ($result->successful) {
    echo $result->stdout;
}

// Access parsed output lines
foreach ($result->lines as $line) {
    echo $line . "\n";
}
```

`CommandResult` provides property hooks for common checks:

| Property | Type | Description |
|----------|------|-------------|
| `exitCode` | `int` | Process exit code |
| `stdout` | `string` | Standard output |
| `stderr` | `string` | Standard error |
| `durationMs` | `float` | Execution time in milliseconds |
| `successful` | `bool` | `true` when exit code is 0 |
| `lines` | `list<string>` | stdout split by newline |

Call `$result->throwIfFailed()` to throw an `SshException` on non-zero exit codes.

## Running Scripts

`RunScript` uploads a script via SFTP, executes it remotely, then cleans up:

```php
<?php

use Phalanx\Ssh\Task\RunScript;

$result = $scope->execute(new RunScript(
    credential: $server,
    scriptContent: <<<'BASH'
        #!/bin/bash
        set -euo pipefail
        echo "Disk usage:"
        df -h /
        echo "Memory:"
        free -m
    BASH,
    interpreter: '/bin/bash',
    timeoutSeconds: 30.0,
));

echo $result->stdout;
```

## File Transfer (SFTP)

`SftpUpload` and `SftpDownload` use the system `sftp` binary in batch mode.

### Upload

```php
<?php

use Phalanx\Ssh\Task\SftpUpload;

// Upload a local file
$result = $scope->execute(new SftpUpload(
    credential: $server,
    remotePath: '/var/www/config.json',
    localPath: './config.json',
));

echo "{$result->bytesTransferred} bytes in {$result->durationMs}ms\n";

// Upload string content directly (written to a temp file, cleaned up on dispose)
$result = $scope->execute(new SftpUpload(
    credential: $server,
    remotePath: '/tmp/data.json',
    localContent: json_encode(['key' => 'value']),
));
```

### Download

```php
<?php

use Phalanx\Ssh\Task\SftpDownload;

$result = $scope->execute(new SftpDownload(
    credential: $server,
    remotePath: '/var/log/app.log',
    localPath: '/tmp/app.log',
));

echo "Downloaded {$result->bytesTransferred} bytes\n";
```

`TransferResult` tracks throughput via a property hook:

| Property | Type | Description |
|----------|------|-------------|
| `localPath` | `string` | Local file path |
| `remotePath` | `string` | Remote file path |
| `bytesTransferred` | `int` | Total bytes moved |
| `durationMs` | `float` | Transfer time in milliseconds |
| `throughputBytesPerSec` | `float` | Computed throughput |

## File Transfer (SCP)

`ScpTransfer` handles both upload and download via the `scp` binary:

```php
<?php

use Phalanx\Ssh\Task\ScpTransfer;
use Phalanx\Ssh\TransferDirection;

// Upload
$scope->execute(new ScpTransfer(
    credential: $server,
    from: './release.tar.gz',
    to: '/tmp/release.tar.gz',
    direction: TransferDirection::Upload,
));

// Download
$scope->execute(new ScpTransfer(
    credential: $server,
    from: '/var/log/app.log',
    to: '/tmp/app.log',
    direction: TransferDirection::Download,
));
```

## SSH Tunnels

`OpenTunnel` establishes an SSH tunnel as a long-running child process and returns a `TunnelHandle`:

```php
<?php

use Phalanx\Ssh\Task\OpenTunnel;
use Phalanx\Ssh\TunnelDirection;

$tunnel = $scope->execute(new OpenTunnel(
    credential: $bastion,
    localPort: 15432,
    remoteHost: 'db.internal',
    remotePort: 5432,
    direction: TunnelDirection::Local,
));

// The tunnel is now active -- localhost:15432 forwards to db.internal:5432
echo "Tunnel alive: " . ($tunnel->isAlive ? 'yes' : 'no') . "\n";

// Execute tasks through the tunnel
$result = $tunnel->run('psql -c "SELECT 1"', $tunnel->tunneledCredential());

// Tunnel closes automatically when the scope disposes, or close it explicitly
$tunnel->close();
```

`TunnelHandle` provides `execute()` and `run()` methods that route tasks through the tunnel's scope. The tunnel process is terminated on scope disposal via `onDispose()`.

## Tunnel-Through (Bastion Hosts)

`TunnelThrough` composes `OpenTunnel` with an inner task. It opens a tunnel to a bastion, executes the inner task through it, then tears down the tunnel:

```php
<?php

use Phalanx\Ssh\Task\RunCommand;
use Phalanx\Ssh\Task\TunnelThrough;

$result = $scope->execute(new TunnelThrough(
    bastion: $bastionCredential,
    targetHost: '10.0.1.50',
    targetPort: 22,
    localPort: 12222,
    innerTask: new RunCommand(
        credential: $targetCredential,
        command: 'hostname',
    ),
    targetCredential: $targetCredential,
));

echo $result->stdout; // internal-web-01
```

## Deployment

`Deploy` implements atomic symlink-based deployments with optional migrations, health checks, and automatic rollback:

```php
<?php

use Phalanx\Ssh\Task\Deploy;

$result = $scope->execute(new Deploy(
    credential: $server,
    localReleasePath: './dist/release.tar.gz',
    remoteBasePath: '/var/www/myapp',
    keepReleases: 5,
    migrationsCommand: 'php artisan migrate --force',
    healthCheckCommand: 'curl -sf http://localhost/health',
));

echo "Deployed to: {$result->stdout}\n";
```

The deploy sequence: create release directory, upload tarball via SCP, extract, run migrations, atomically swap the `current` symlink, run health check. If the health check fails, the symlink rolls back to the previous release. Old releases are pruned to `keepReleases`.

## Multi-Server Deployment

`MultiDeploy` fans out a `Deploy` task across multiple servers with bounded concurrency via `$scope->map()`:

```php
<?php

use Phalanx\Ssh\Task\MultiDeploy;

$results = $scope->execute(new MultiDeploy(
    credentials: [$web01, $web02, $web03],
    localReleasePath: './dist/release.tar.gz',
    remoteBasePath: '/var/www/myapp',
    concurrency: 2,
    keepReleases: 5,
    healthCheckCommand: 'curl -sf http://localhost/health',
));
```

## Connection Testing

`TestConnection` verifies SSH connectivity by running `exit 0` on the remote host:

```php
<?php

use Phalanx\Ssh\Task\TestConnection;

$reachable = $scope->execute(new TestConnection(
    credential: $server,
));

echo $reachable ? 'Connected' : 'Unreachable';
```

Returns `true` on success, `false` on any failure. Never throws.

## Service Bundle

`SshServiceBundle` registers `SshConfig` into the service graph. Configuration flows through `$context`:

```php
<?php

use Phalanx\Ssh\SshServiceBundle;

$app = Application::starting($context)
    ->providers(new SshServiceBundle())
    ->compile()
    ->startup();
```

| Context Key | Default | Description |
|-------------|---------|-------------|
| `SSH_BINARY_PATH` | `ssh` | Path to the ssh binary |
| `SCP_BINARY_PATH` | `scp` | Path to the scp binary |
| `SFTP_BINARY_PATH` | `sftp` | Path to the sftp binary |
| `SSH_DEFAULT_TIMEOUT` | `30.0` | Default command timeout (seconds) |
| `SSH_CONNECTION_TIMEOUT` | `10.0` | Connection establishment timeout (seconds) |
| `SSH_STRICT_HOST_KEY_CHECKING` | `true` | Enable strict host key verification |

## Error Handling

All SSH operations throw typed exceptions rooted at `SshException`:

| Exception | When |
|-----------|------|
| `SshException` | General SSH failure (non-zero exit, transfer error) |
| `SshConnectionException` | Connection refused, timed out, permission denied, host key verification |
| `SshTimeoutException` | Operation exceeded its timeout |

`RunCommand` detects exit code 255 (SSH transport failure) and inspects stderr to throw the appropriate `SshConnectionException` with a descriptive message.

```php
<?php

use Phalanx\Ssh\Exception\SshConnectionException;
use Phalanx\Ssh\Exception\SshException;

try {
    $result = $scope->execute(new RunCommand(
        credential: $server,
        command: 'systemctl status nginx',
    ));
    $result->throwIfFailed();
} catch (SshConnectionException $e) {
    echo "Connection failed: {$e->getMessage()}\n";
    echo "stderr: {$e->stderr}\n";
} catch (SshException $e) {
    echo "SSH error (exit {$e->getCode()}): {$e->stderr}\n";
}
```
