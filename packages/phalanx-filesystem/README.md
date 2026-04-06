<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/filesystem

Async-aware file operations with resource governance. Read, write, stream, and manage files through the scope's service layer--with a file descriptor pool that prevents your process from exhausting OS handles during concurrent streaming.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [The Files Facade](#the-files-facade)
- [Streaming](#streaming)
  - [Reading Streams](#reading-streams)
  - [Writing Streams](#writing-streams)
- [FilePool](#filepool)
- [Task Reference](#task-reference)
- [FileInfo](#fileinfo)
- [Configuration](#configuration)

## Installation

```bash
composer require phalanx/filesystem
```

Requires PHP 8.4+, `phalanx/core`, and `react/stream`.

Optional: `phalanx/parallel` for worker-offloaded reads on slow storage.

## Quick Start

```php
<?php

use Phalanx\Application;
use Phalanx\Filesystem\Files;
use Phalanx\Filesystem\FilesystemServiceBundle;

$scope = Application::starting()
    ->providers(new FilesystemServiceBundle())
    ->compile()
    ->startup()
    ->createScope();

$files = $scope->service(Files::class);
$config = $files->readJson('/etc/myapp/config.json');

echo $config['database']['host'];

$scope->dispose();
$app->shutdown();
```

## The Files Facade

`Files` is a scoped service resolved via `$scope->service(Files::class)`. It wraps every file operation as a task under the hood, so tracing and cancellation apply automatically.

```php
<?php

use Phalanx\Filesystem\Files;

$files = $scope->service(Files::class);

// Read / write
$content = $files->read('/tmp/input.txt');
$files->write('/tmp/output.txt', $content);
$files->append('/tmp/log.txt', "line\n");

// JSON with pretty print
$data = $files->readJson('/tmp/data.json');
$files->writeJson('/tmp/data.json', $data);

// Filesystem operations
$exists = $files->exists('/tmp/input.txt');
$files->delete('/tmp/input.txt');
$files->move('/tmp/a.txt', '/tmp/b.txt');
$files->mkdir('/tmp/output', recursive: true);

// Directory listing
$entries = $files->listDir('/tmp/output');

// Stat
$info = $files->stat('/tmp/output.txt');
echo "{$info->size} bytes, modified {$info->modifiedAt->format('Y-m-d')}";
```

## Streaming

Streaming tasks use `react/stream` under the hood and integrate with the `FilePool` to bound concurrent open file descriptors.

### Reading Streams

`ReadFileStream` returns an `Emitter`-based stream. Pipe it through operators the same way you would with any Phalanx stream:

```php
<?php

use Phalanx\Filesystem\Files;

$files = $scope->service(Files::class);

// readStream() returns an Emitter — chain operators directly
$stream = $files->readStream('/data/transactions.csv');

$results = $stream
    ->map(static fn(string $chunk) => str_getcsv($chunk))
    ->filter(static fn(array $row) => (float) $row[3] > 1000.00)
    ->take(100);

$rows = $scope->execute($results->toArray());
```

Memory stays flat regardless of file size--values flow one line at a time through the operator pipeline.

### Writing Streams

`writeStream()` accepts a path and an `Emitter` source. The stream data is written to the file as it arrives:

```php
<?php

use Phalanx\Filesystem\Files;
use Phalanx\Stream\Emitter;

$files = $scope->service(Files::class);

// Create an Emitter that produces output data
$source = Emitter::produce(static function ($ch, $ctx) {
    $ch->emit("id,name,total\n");
    $ch->emit("1,Alice,500\n");
    $ch->emit("2,Bob,750\n");
});

$files->writeStream('/data/output.csv', $source);
```

## FilePool

`FilePool` is a singleton that governs concurrent open file descriptors for streaming operations. The default limit is 64 simultaneous streams. When all slots are occupied, new streaming tasks wait until a slot frees up--no silent resource exhaustion.

Streaming tasks (`ReadFileStream`, `WriteFileStream`) acquire a pool slot on open and release it on end, error, or scope disposal. Non-streaming tasks (`ReadFile`, `WriteFile`, etc.) use inline `file_get_contents`/`file_put_contents` and do not consume pool slots.

```php
<?php

use Phalanx\Filesystem\FilePool;
use Phalanx\Filesystem\FilesystemServiceBundle;

// Custom pool limit
$bundle = new FilesystemServiceBundle(maxOpen: 128);
```

If you're streaming hundreds of files concurrently (log tailing, bulk ETL), the pool prevents your process from hitting the OS file descriptor limit. Tasks queue transparently--callers don't need to manage slots manually.

## Task Reference

All tasks live in the `Phalanx\Filesystem\Task` namespace. The `Files` facade calls these internally, but you can use them directly with `$scope->execute()`.

| Task | Operation | Notes |
|------|-----------|-------|
| `ReadFile` | `file_get_contents` | Inline, no pool slot |
| `ReadJsonFile` | Read + `json_decode` | Inline |
| `WriteFile` | `file_put_contents` | Inline |
| `WriteJsonFile` | Write + `json_encode` | Pretty print by default |
| `AppendFile` | `file_put_contents` with `FILE_APPEND` | Inline |
| `ReadFileStream` | Pooled `ReadableResourceStream` | Acquires pool slot, emits via `Emitter::stream()` |
| `WriteFileStream` | Pooled `WritableResourceStream` | Acquires pool slot |
| `StatFile` | Returns `FileInfo` | |
| `ExistsFile` | Returns `bool` | |
| `DeleteFile` | `unlink` | |
| `MoveFile` | `rename` | |
| `CreateDirectory` | `mkdir` | Supports recursive |
| `ListDirectory` | `scandir` | Returns filtered entries |

```php
<?php

use Phalanx\Filesystem\Task\ReadFile;
use Phalanx\Filesystem\Task\WriteFile;
use Phalanx\Filesystem\Task\StatFile;

// Direct task usage
$content = $scope->execute(new ReadFile('/etc/hosts'));
$scope->execute(new WriteFile('/tmp/out.txt', $content));
$info = $scope->execute(new StatFile('/tmp/out.txt'));
```

## FileInfo

Readonly value object returned by `StatFile` and `Files::stat()`:

| Property | Type | Description |
|----------|------|-------------|
| `path` | `string` | Absolute file path |
| `size` | `int` | File size in bytes |
| `modifiedAt` | `DateTimeImmutable` | Last modification time |
| `accessedAt` | `DateTimeImmutable` | Last access time |
| `createdAt` | `DateTimeImmutable` | Creation time |
| `permissions` | `int` | Unix permission bits |
| `isFile` | `bool` | Regular file |
| `isDirectory` | `bool` | Directory |
| `isSymlink` | `bool` | Symbolic link |
| `symlinkTarget` | `?string` | Resolved symlink target |

## Configuration

`FilesystemServiceBundle` registers `FilePool` (singleton) and `Files` (scoped service) into the service graph:

```php
<?php

use Phalanx\Application;
use Phalanx\Filesystem\FilesystemServiceBundle;

$app = Application::starting()
    ->providers(new FilesystemServiceBundle(maxOpen: 128))
    ->compile();
```
