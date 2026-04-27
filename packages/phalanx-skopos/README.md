<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx Skopos

> Part of the [Phalanx](https://github.com/phalanx-php/phalanx-aegis) async PHP framework.

PHP-native dev server orchestrator. Manages backend servers, frontend builds, CSS compilation, file watching, and live reload from a single `skopos.php` config file. No Vite. No Node. No `npx concurrently`.

## Table of Contents

- [Installation](#installation)
- [Getting Started](#getting-started)
  - [Laravel + React + Tailwind](#laravel--react--tailwind)
  - [Phalanx App with Live Reload](#phalanx-app-with-live-reload)
  - [Custom Processes](#custom-processes)
- [Backend Configuration](#backend-configuration)
- [Frontend Configuration](#frontend-configuration)
- [CSS Configuration](#css-configuration)
- [Live Reload](#live-reload)
- [Raw Process API](#raw-process-api)
- [Binary Requirements](#binary-requirements)

## Installation

```bash
composer require phalanx/skopos
```

> [!NOTE]
> Requires PHP 8.4 or later.

## Getting Started

Create a `skopos.php` in your project root. It returns a `DevServer` instance:

### Laravel + React + Tailwind

```php
<?php

use Phalanx\Skopos\Backend;
use Phalanx\Skopos\Css;
use Phalanx\Skopos\DevServer;
use Phalanx\Skopos\Frontend;

return DevServer::create()
    ->backend(
        Backend::php('php artisan serve --port=8000')
            ->watch(['app/', 'routes/', 'config/'])
    )
    ->frontend(
        Frontend::react('resources/js/app.jsx')
            ->outdir('public/assets/js')
            ->css(Css::tailwind('resources/css/app.css', 'public/assets/css/app.css'))
    )
    ->liveReload();
```

Run it:

```bash
vendor/bin/skopos
```

Skopos starts the PHP server, bun build in watch mode, Tailwind CSS in watch mode, and an SSE reload server. Edit a PHP file — the server restarts and the browser reloads. Edit a JS or CSS file — the build runs and the browser reloads.

### Phalanx App with Live Reload

```php
<?php

use Phalanx\Skopos\Backend;
use Phalanx\Skopos\DevServer;
use Phalanx\Skopos\Frontend;

return DevServer::create()
    ->backend(
        Backend::phalanx('php bin/server.php')
            ->watch(['src/'])
    )
    ->frontend(
        Frontend::vanilla('resources/js/app.js')
            ->outdir('public/assets/js')
    )
    ->liveReload();
```

### Custom Processes

For full control, use the raw process API directly:

```php
<?php

use Phalanx\Skopos\DevServer;
use Phalanx\Skopos\Process;

return DevServer::create()
    ->server('php artisan serve --port=8000', ready: '/Server running/')
    ->process(
        Process::named('webpack')
            ->command('npx webpack --watch')
            ->reloadOn('/compiled successfully/')
    )
    ->process(
        Process::named('queue')
            ->command('php artisan queue:work')
    )
    ->liveReload();
```

## Backend Configuration

The `Backend` builder produces a server process with sensible defaults for each type.

```php
<?php

// PHP built-in server (default watch: app/, routes/, config/, src/)
Backend::php('php artisan serve --port=8000')

// Phalanx async server (default watch: src/)
Backend::phalanx('php bin/server.php')

// Node server (no default watch — most Node tools self-watch)
Backend::node('node server.js')

// Anything else
Backend::custom('python manage.py runserver', readyPattern: '/Starting development server/')
```

All backend types support fluent configuration:

```php
<?php

Backend::php('php artisan serve')
    ->ready('/Server running/')        // override readiness pattern
    ->watch(['app/', 'routes/'], ['php', 'blade.php'])  // paths and extensions
    ->env(['APP_ENV' => 'local'])      // environment variables
    ->cwd('/path/to/project')          // working directory
```

## Frontend Configuration

The `Frontend` builder produces one or two processes: the JS build and optionally a CSS build.

```php
<?php

// React/JSX — pure bun CLI, no plugins needed
Frontend::react('resources/js/app.jsx')

// Vue — generates a .skopos/build.ts using bun-plugin-vue
Frontend::vue('resources/js/app.js')

// Svelte — generates a .skopos/build.ts using bun-plugin-svelte
Frontend::svelte('resources/js/app.js')

// Vanilla JS/TS — pure bun CLI
Frontend::vanilla('resources/js/app.ts')

// Anything else
Frontend::custom('npx vite build --watch', reloadPattern: '/built in/')
```

Fluent configuration:

```php
<?php

Frontend::react('resources/js/app.jsx')
    ->outdir('public/assets/js')       // output directory
    ->publicPath('/assets/js/')        // public URL prefix
    ->splitting()                      // code splitting (default: on)
    ->sourcemap()                      // source maps (default: on)
    ->minify()                         // minification (default: off)
    ->css(Css::tailwind())             // attach CSS build
    ->env(['NODE_ENV' => 'development'])
```

## CSS Configuration

The `Css` builder produces a standalone CSS compilation process. Attach it to a frontend with `->css()`, or resolve it directly and pass it as a raw process.

```php
<?php

// Tailwind CSS standalone CLI
Css::tailwind('resources/css/app.css', 'public/assets/css/app.css')

// Dart Sass
Css::sass('resources/scss/app.scss', 'public/assets/css/app.css')

// UnoCSS via bun
Css::unocss('public/assets/css/uno.css')

// PostCSS via bun
Css::postcss('resources/css/app.css', 'public/assets/css/app.css')

// No CSS processing
Css::none()

// Custom command
Css::custom('npx lightningcss --watch input.css -o output.css', reloadPattern: '/Done/')
```

All CSS types support `->minify()` and `->watch()`:

```php
<?php

Css::tailwind('resources/css/app.css', 'public/assets/css/app.css')
    ->minify()
    ->env(['TAILWIND_MODE' => 'watch'])
```

## Live Reload

Call `->liveReload()` on the `DevServer` to start an SSE (Server-Sent Events) server. Connected browsers receive a reload signal when:

- A frontend build completes (detected via the process reload probe)
- A backend server restarts after a file change

The default port is 35729. Override it with `->liveReload(port: 8080)`.

### Connecting the Browser

Add the script tag to your HTML layout:

```html
<script src="http://localhost:35729/livereload.js"></script>
```

Or use the PHP helper to generate it:

```php
<?php

use Phalanx\Skopos\LiveReload\ClientScript;

echo ClientScript::scriptTag(port: 35729);
```

The script uses `EventSource` (SSE) — built into all browsers. No WebSocket. No dependencies.

## Raw Process API

The `Process` builder and `DevServer::server()` / `DevServer::process()` methods give full control when the typed builders don't fit your stack.

```php
<?php

use Phalanx\Skopos\DevServer;
use Phalanx\Skopos\Process;

return DevServer::create()
    ->server('php artisan serve --port=8000', ready: '/Server running/')
    ->process(
        Process::named('sass')
            ->command('sass --watch resources/scss:public/css')
            ->reloadOn('/Compiled/')
    )
    ->process(
        Process::named('queue')
            ->command('php artisan queue:work --tries=3')
    )
    ->when(getenv('ENABLE_MAIL') !== false, static fn($ds) => $ds->process(
        Process::named('mailhog')
            ->command('mailhog')
    ));
```

Every builder (`Backend`, `Frontend`, `Css`) resolves to `Process` instances. The raw API is the escape hatch that proves the system is open.

## Binary Requirements

| Builder | Binary | Install |
|---------|--------|---------|
| `Frontend::react()`, `vue()`, `svelte()`, `vanilla()` | `bun` | `curl -fsSL https://bun.sh/install \| bash` |
| `Css::tailwind()` | `tailwindcss` | [Standalone CLI](https://tailwindcss.com/blog/standalone-cli) |
| `Css::sass()` | `sass` | [Dart Sass](https://sass-lang.com/install/) |
| `Css::unocss()`, `Css::postcss()` | `bun` | Same as above |
| `Backend::php()` | `php` | Already installed |
| `Backend::node()` | `node` | [nodejs.org](https://nodejs.org/) |

Skopos resolves binaries at startup via `PATH`, then checks common install locations. If a binary is missing, the error message includes the install command.
