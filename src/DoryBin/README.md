<p align="center">
  <img src="assets/banner.svg" alt="Phalanx" width="520">
</p>

# Phalanx DoryBin

Static binary compiler for Phalanx. DoryBin compiles a self-contained PHP binary with Swoole and the full Phalanx framework baked in, using [static-php-cli (SPC)](https://static-php.dev) as the build substrate.

Part of the [Phalanx framework](https://github.com/phalanx-php/phalanx).

## What it produces

A single static binary (`dory`) that contains PHP 8.4, Swoole, and all Phalanx packages. The binary runs Dory scripts without requiring a system PHP installation, Composer, or any shared libraries.

The build pipeline downloads PHP and extension sources, patches Swoole for static linking, compiles everything into a single ELF/Mach-O binary, then embeds your application's PHAR payload.

## Facade

`DoryBin` exposes two static methods that encapsulate the full build and verification workflows:

```php
<?php

use Phalanx\DoryBin\DoryBin;
use Phalanx\DoryBin\BuildOptions;
use Phalanx\DoryBin\BuildProfile;
use Phalanx\DoryBin\VerifyOptions;

$outcome = DoryBin::build($scope, new BuildOptions(
    profile: BuildProfile::Full,
    outputPath: '/tmp/dory',
    clean: true,
));

// $outcome->success, $outcome->binaryPath, $outcome->stages, $outcome->totalMs

$verification = DoryBin::verify($scope, new VerifyOptions(
    binaryPath: '/tmp/dory',
    profile: BuildProfile::Full,
));

// $verification->passed, $verification->results, $verification->failures()
```

Both methods require a `TaskScope&TaskExecutor` -- they run inside a managed Aegis scope with cancellation and task supervision.

## Build profiles

Profiles are YAML definitions in `config/profiles/` that control which extensions and features are included:

| Profile | Description |
|---|---|
| `mini` | Minimal binary -- core extensions only |
| `ops` | Operations-focused -- adds Redis, Postgres, monitoring extensions |
| `brain` | AI/ML workloads -- adds cURL, OpenSSL, JSON streaming |
| `full` | Everything included |
| `custom` | User-defined profile |

Each profile specifies: PHP version, required/optional extensions, Swoole version, Swoole compile features, INI settings, and SPC registry sources.

## Build pipeline

The build runs through 10 sequential stages:

1. **PreflightCheck** -- verify SPC binary exists and build environment is sane
2. **SetupRegistry** -- generate SPC extension registry from profile definition
3. **StashSources** -- cache downloaded source tarballs for incremental builds
4. **DownloadSources** -- fetch PHP and extension sources via SPC
5. **BuildLibraries** -- compile static libraries (libcurl, libssl, etc.)
6. **PatchSwoole** -- apply patches for static linking compatibility
7. **BuildPhp** -- compile PHP with statically linked extensions
8. **EmbedPhalanx** -- pack framework sources into PHAR and concatenate with binary
9. **VerifyBinary** -- run post-build verification checks
10. **WriteManifest** -- write build manifest with checksums and metadata

Stages that fail stop the pipeline. Stages can declare themselves skippable based on build context (e.g., skipping source downloads when cached).

## Verification checks

`DoryBin::verify()` runs five checks against a built binary:

| Check | What it verifies |
|---|---|
| `ExtensionCheck` | All required extensions from the profile are loaded |
| `FiberContextCheck` | Fiber/coroutine context is functional |
| `SmokeTestCheck` | Binary boots and executes a trivial script |
| `SymbolConflictCheck` | No duplicate curl symbol definitions (linking conflict indicator) |
| `BinarySizeCheck` | Binary size is within expected bounds for the profile |

## Service bundle

`DoryBin::services()` returns a `DoryBinServiceBundle` that registers:

- `BuildConfig` via `configs()` (auto-hydrated from environment)
- `BuildProfileRegistry` as a singleton (loads YAML profiles from disk)

When installed alongside Dory, the bundle is conditionally loaded by `dory bin/dory` so the build commands appear under `dory build`.

## CLI commands

When registered through Dory, these commands appear under `dory build`:

| Command | Description |
|---|---|
| `dory build binary` | Build a static binary from a profile |
| `dory build doctor` | Run verification checks against a built binary |
| `dory build profiles` | List available build profiles |
| `dory build clean` | Remove build artifacts |
