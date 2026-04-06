<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/network

Network discovery, probing, and device management as Phalanx tasks. Scan subnets, probe ports, wake machines, and discover services--all through the same scope-driven concurrency model as the rest of Phalanx.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Subnet Addressing](#subnet-addressing)
- [Probe Strategies](#probe-strategies)
- [Task Reference](#task-reference)
  - [PingHost](#pinghost)
  - [ProbePort](#probeport)
  - [ProbeUdp](#probeudp)
  - [WakeHost](#wakehost)
  - [ScanSubnet](#scansubnet)
  - [ScanPorts](#scanports)
  - [WakeAndWait](#wakeandwait)
  - [IdentifyDevice](#identifydevice)
  - [ResolveHostname](#resolvehostname)
- [Discovery](#discovery)
- [Value Objects](#value-objects)
- [Configuration](#configuration)

## Installation

```bash
composer require phalanx/network
```

Requires PHP 8.4+, `phalanx/core`, `mlocati/ip-lib`, `react/datagram`, `react/socket`, `react/dns`, and `react/child-process`.

Optional: `clue/multicast-react`, `clue/mdns-react`, `clue/ssdp-react` for mDNS and SSDP discovery.

## Quick Start

```php
<?php

use Phalanx\Application;
use Phalanx\Network\NetworkServiceBundle;
use Phalanx\Network\Task\ScanSubnet;
use Phalanx\Network\Subnet;

[$app, $scope] = Application::starting()
    ->providers(new NetworkServiceBundle())
    ->compile()
    ->boot();

$hosts = $scope->execute(new ScanSubnet(
    Subnet::from('192.168.1.0/24'),
));

foreach ($hosts as $result) {
    if ($result->reachable) {
        echo "{$result->ip} is up ({$result->latencyMs}ms)\n";
    }
}

$scope->dispose();
$app->shutdown();
```

## Subnet Addressing

`Subnet` wraps `mlocati/ip-lib` for CIDR range handling. It supports both IPv4 and IPv6 and exposes addresses as a lazy generator--iterating a `/16` doesn't allocate 65,536 strings upfront.

```php
<?php

use Phalanx\Network\Subnet;

$net = Subnet::from('10.0.0.0/24');     // 256 addresses
$net = Subnet::from('fd00::/64');        // IPv6 works the same way

foreach ($net->addresses() as $ip) {
    // lazy -- yields one address at a time
}
```

## Probe Strategies

`ProbeStrategy` is a factory that produces the right probe task for a given method. Swap scan strategies without changing calling code:

```php
<?php

use Phalanx\Network\ProbeStrategy;

// TCP connect probe on port 443 with 2s timeout
$probe = ProbeStrategy::tcp(port: 443, timeout: 2.0);

// UDP probe with a custom payload
$probe = ProbeStrategy::udp(port: 53, payload: "\x00\x00\x10\x00\x00\x00\x00\x00\x00\x00\x00\x00");

// ICMP ping with 1s timeout
$probe = ProbeStrategy::ping(timeout: 1.0);
```

Pass a strategy to `ScanSubnet` to control how each host is probed:

```php
<?php

use Phalanx\Network\ProbeStrategy;
use Phalanx\Network\Subnet;
use Phalanx\Network\Task\ScanSubnet;

// Scan for web servers instead of pinging
$hosts = $scope->execute(new ScanSubnet(
    Subnet::from('192.168.1.0/24'),
    probe: ProbeStrategy::tcp(port: 80, timeout: 1.0),
    concurrency: 50,
));
```

## Task Reference

All tasks live in the `Phalanx\Network\Task` namespace. Each is an invokable class with serializable constructor args.

### PingHost

ICMP ping via child process. Implements `HasTimeout` and `Retryable`.

```php
<?php

use Phalanx\Network\Task\PingHost;

$result = $scope->execute(new PingHost('192.168.1.1'));
// ProbeResult { ip: '192.168.1.1', reachable: true, latencyMs: 1.2, method: 'ping' }
```

### ProbePort

TCP connect probe. Implements `HasTimeout`.

```php
<?php

use Phalanx\Network\Task\ProbePort;

$result = $scope->execute(new ProbePort('192.168.1.1', port: 22));
// ProbeResult { ip: '192.168.1.1', reachable: true, latencyMs: 3.4, method: 'tcp', port: 22 }
```

### ProbeUdp

UDP probe with payload. Implements `HasTimeout`.

```php
<?php

use Phalanx\Network\Task\ProbeUdp;

$result = $scope->execute(new ProbeUdp('192.168.1.1', port: 53, payload: $dnsQuery));
```

### WakeHost

Send a Wake-on-LAN magic packet via UDP broadcast.

```php
<?php

use Phalanx\Network\Task\WakeHost;

$scope->execute(new WakeHost(mac: '00:11:22:33:44:55'));
```

### ScanSubnet

Scan a CIDR range with bounded concurrency via `$scope->map()`.

```php
<?php

use Phalanx\Network\Subnet;
use Phalanx\Network\Task\ScanSubnet;

$results = $scope->execute(new ScanSubnet(
    Subnet::from('10.0.0.0/24'),
    concurrency: 100,
));
```

### ScanPorts

Scan multiple ports on a single host.

```php
<?php

use Phalanx\Network\Task\ScanPorts;

$results = $scope->execute(new ScanPorts(
    ip: '192.168.1.1',
    ports: [22, 80, 443, 3306, 5432, 8080],
));
```

### WakeAndWait

Send a WOL packet, then retry-probe until the host comes up.

```php
<?php

use Phalanx\Network\Task\WakeAndWait;

$result = $scope->execute(new WakeAndWait(
    mac: '00:11:22:33:44:55',
    ip: '192.168.1.50',
));
```

### IdentifyDevice

Concurrent ping + port scan to fingerprint a device.

```php
<?php

use Phalanx\Network\Task\IdentifyDevice;

$host = $scope->execute(new IdentifyDevice('192.168.1.1'));
// Host { ip, mac, hostname, services, metadata }
```

### ResolveHostname

Async DNS resolution via `react/dns`.

```php
<?php

use Phalanx\Network\Task\ResolveHostname;

$ip = $scope->execute(new ResolveHostname('example.com'));
```

## Discovery

Discovery tasks require optional dependencies. Install them as needed.

### SSDP/UPnP Discovery

Requires `clue/ssdp-react`.

```php
<?php

use Phalanx\Network\Discovery\DiscoverSsdp;

$devices = $scope->execute(new DiscoverSsdp(timeout: 5.0));
```

### mDNS/Zeroconf Discovery

Requires `clue/mdns-react`.

```php
<?php

use Phalanx\Network\Discovery\DiscoverMdns;

$services = $scope->execute(new DiscoverMdns(serviceType: '_http._tcp.local', timeout: 3.0));
```

## Value Objects

**ProbeResult** -- readonly result from any probe task:

| Property | Type | Description |
|----------|------|-------------|
| `ip` | `string` | Target IP address |
| `reachable` | `bool` | Whether the host responded |
| `latencyMs` | `?float` | Round-trip time in milliseconds |
| `method` | `string` | Probe method used (`ping`, `tcp`, `udp`) |
| `port` | `?int` | Port probed (null for ICMP) |
| `responseData` | `?string` | Raw response data (UDP probes) |

**Host** -- readonly device identity:

| Property | Type | Description |
|----------|------|-------------|
| `ip` | `string` | IP address |
| `mac` | `?string` | MAC address if resolved |
| `hostname` | `?string` | Hostname if resolved |
| `services` | `array` | Open ports / detected services |
| `metadata` | `array` | Additional discovery data |

## Configuration

`NetworkConfig` controls defaults for all network tasks:

```php
<?php

use Phalanx\Network\NetworkConfig;
use Phalanx\Network\NetworkServiceBundle;

$bundle = new NetworkServiceBundle(new NetworkConfig(
    defaultTimeout: 3.0,
    defaultConcurrency: 50,
    pingBinary: '/usr/bin/ping',
    broadcastAddress: '192.168.1.255',
    wolPort: 9,
));
```

`NetworkServiceBundle` registers `DatagramFactory`, `Connector`, and `NetworkConfig` into the service graph.
