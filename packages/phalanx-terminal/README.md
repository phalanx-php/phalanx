# phalanx/terminal

Terminal UI framework for building interactive CLI applications with Phalanx. Provides a surface/region rendering model, input handling with ANSI escape parsing, syntax highlighting, and widget composition.

## Installation

```bash
composer require phalanx/terminal
```

Requires PHP 8.4+ and `phalanx/core`.

## Status

Under active development. Used internally by aisentinel-cli for multi-agent code review TUI.

## Components

- **Surface** -- the root rendering context with periodic tick-based redraws
- **Region** -- positioned content areas within the surface
- **Buffer** -- character-level write buffer with style attributes
- **Input** -- ANSI escape sequence parser for keyboard/mouse events
- **Highlight** -- syntax highlighting via Tempest
- **Widget** -- composable UI elements (text, scrollable panels, input lines)

## License

MIT
