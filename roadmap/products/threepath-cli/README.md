# ThreePath CLI (POC)

Async CLI for ThreePath set-top boxes on Phalanx.

See main repo: https://github.com/phalanx-php/phalanx

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Commands](#commands)
  - [ping](#ping)
  - [scan](#scan)
  - [channel:switch](#channelswitch)
  - [channel:up / channel:down](#channelup--channeldown)
  - [status](#status)
- [Architecture](#architecture)
  - [UDP Protocol](#udp-protocol)
  - [Multi-Packet Reassembly](#multi-packet-reassembly)
  - [Project Structure](#project-structure)
- [STB Command Reference](#stb-command-reference)
- [Adding Commands](#adding-commands)

## Requirements

- PHP 8.4+
- Composer
- Network access to STB subnet (port 25671/UDP)

## Installation

```bash
git clone git@gh-personal:jhavenz/threepathcli-php.git threepath-cli
cd threepath-cli
composer install
```

The CLI entry point is `bin/threepath`. Optionally symlink it:

```bash
ln -s "$(pwd)/bin/threepath" /usr/local/bin/threepath
```

Phalanx packages are resolved from the `../../phalanx/packages/*` path repository. The monorepo must be present at `../../phalanx/` (this project lives under `poc/`).

## Configuration

All configuration flows through environment variables via Symfony Runtime's `$context` array. Set them in a `.env` file at the project root or export them in your shell.

| Variable | Default | Description |
|----------|---------|-------------|
| `STB_PORT` | `25671` | UDP port for STB communication |
| `STB_API_KEY` | `dca15ceb-...` | API key included in every command |
| `STB_TIMEOUT` | `2.0` | UDP response timeout in seconds |
| `STB_SCAN_CONCURRENCY` | `50` | Max concurrent probes during scan |
| `STB_DEFAULT_SERVICE_ID` | `146` | Default channel/service ID |

## Commands

### ping

Ping a single STB via `HELLO_DISCOVERY`. Validates connectivity and retrieves device info.

```bash
threepath ping 10.30.5.219
```

```
STB found at 10.30.5.219
  Chip ID:  750051296
  Firmware: 4.T20250405.0-rev-12a8c20bf
  IP:       DHCP is active and IP is assigned: 10.30.5.219 (Wi-Fi)
  Date:     2025-04-07T15:12:57.391
```

### scan

Scan a CIDR subnet for STBs. Uses `$scope->map()` with bounded concurrency (default 50 simultaneous UDP probes).

```bash
threepath scan 10.30.5.0/24
```

```
Scanning 10.30.5.0/24...
Found 3 STB(s) in 2.4s:

  10.30.5.28   chip=750051296  fw=4.T20250405.0-rev-12a8c20bf
  10.30.5.42   chip=750051274  fw=4.T20250405.0-rev-12a8c20bf
  10.30.5.219  chip=750051345  fw=4.T20250413.0-rev-4d8d93de8
```

Concurrency is configurable via `STB_SCAN_CONCURRENCY`. A /24 (254 hosts) at concurrency 50 completes in ~5 timeout windows (~10s worst case).

### channel:switch

Switch an STB to a specific service/channel by service ID.

```bash
threepath channel:switch 10.30.5.219 155
threepath channel:switch 10.30.5.219 155 -d 750051296
```

The `-d` / `--device-id` option sets the chip ID for the message prefix. Defaults to `0` if omitted.

### channel:up / channel:down

Simple channel increment/decrement.

```bash
threepath channel:up 10.30.5.219
threepath channel:down 10.30.5.219
```

### status

Retrieve DVB-S tuner status (signal quality, frequency, lock state).

```bash
threepath status 10.30.5.219
```

```
Tuner Status for 10.30.5.219:
  Frequency:  12207 MHz
  Mode:       Vertical
  AGC:        78%
  SNR:        14.2
  Symbol:     27500 Ksps
  Lock:       locked
```

## Architecture

### UDP Protocol

All STB communication uses UDP on port 25671. Messages are UTF-8 encoded with the format:

```
{device_id}:msg:{json_payload}
```

Every payload includes:

```json
{
  "id": 12345678,
  "command": "COMMAND_NAME",
  "api_key": "dca15ceb-...",
  "description": "Human-readable description",
  "payload": { }
}
```

The `id` field is a random 8-digit integer for request correlation. The `api_key` authenticates the client. The `payload` object carries command-specific parameters.

### Multi-Packet Reassembly

Large responses (recordings, EPG data) are split across multiple UDP packets. Each packet is prefixed with `seq/total:` and a final `END` packet signals completion. `StbTransport` reassembles these automatically.

### Project Structure

```
threepath-cli/
bin/
  threepath                    Entry point (Symfony Runtime)
  commands/
    ping.php                   HELLO_DISCOVERY single host
    scan.php                   CIDR subnet scan
    channel.php                channel:switch, channel:up, channel:down
    status.php                 Tuner status query
src/ThreePath/
  ConsoleRuntime.php           Symfony Runtime adapter
  StbConfig.php                Port, API key, timeout, concurrency
  StbCommand.php               Command factory (all STB commands)
  StbResponse.php              Response parsing, property hooks
  StbTransport.php             UDP send/receive, multi-packet reassembly
  ThreePathServiceBundle.php   Service registration (DI)
  Task/
    PingStb.php                Single discovery task
    ScanForStbs.php            Subnet scan via $scope->map()
    SendStbCommand.php         Generic command dispatch
```

Commands are auto-discovered from `bin/commands/`. Each file returns a `CommandGroup`. The `ConsoleRunner` merges all groups and routes `argv` to the matching command.

Services are registered in `ThreePathServiceBundle` and resolved via `$scope->service()`. `StbTransport` is a singleton holding `Suspendable` (narrowed from `ExecutionScope`) for non-blocking UDP I/O.

## STB Command Reference

Commands available via `StbCommand` factory methods. Not all are wired to CLI commands yet.

| Factory Method | STB Command | Payload |
|----------------|-------------|---------|
| `helloDiscovery()` | `HELLO_DISCOVERY` | none |
| `channelUp()` | `CH_UP` | none |
| `channelDown()` | `CH_DOWN` | none |
| `forceChannelSwitch(serviceId)` | `FORCE_CH_SWITCH` | `service_id` |
| `tunerStatus()` | `CMD_GET_TUNER_STATUS` | none |
| `currentEpg(serviceId)` | `CMD_GET_CURRENT_EPG` | `service_id` |
| `sendButtonKey(key)` | `CMD_SEND_BUTTON_KEY` | `button_key` |
| `reboot()` | `CMD_REBOOT_STB` | none |
| `recordingList()` | `CMD_GET_SHORT_RECORDING_LIST` | none |

Valid button keys: `UP`, `DOWN`, `LEFT`, `RIGHT`, `OK`, `BACK`, `EPG`, `SETTINGS`, `VOL+`, `VOL-`, `0`-`9`.

## Adding Commands

1. Add a factory method to `StbCommand` if the STB command doesn't exist yet.
2. Create a file in `bin/commands/` returning a `CommandGroup`:

```php
<?php

use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\CommandScope;
use Phalanx\ExecutionScope;
use ThreePath\StbCommand;
use ThreePath\Task\SendStbCommand;

return CommandGroup::of([
    'my:command' => new Command(
        fn: static function (ExecutionScope $scope): int {
            assert($scope instanceof CommandScope);

            $ip = $scope->args->get('ip');
            $response = $scope->execute(new SendStbCommand(
                ip: $ip,
                deviceId: '0',
                command: StbCommand::reboot(),
            ));

            echo $response->success ? "Done\n" : "Failed\n";
            return $response->success ? 0 : 1;
        },
        config: static fn($c) => $c
            ->withDescription('Description shown in help')
            ->withArgument('ip', 'IP address of the STB'),
    ),
]);
```

The command is automatically discovered on next run. No registration step needed.
