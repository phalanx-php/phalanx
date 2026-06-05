<?php

declare(strict_types=1);

namespace Phalanx\DevServer;

enum Binary: string
{
    case Bun = 'bun';
    case Npx = 'npx';
    case Php = 'php';
    case Node = 'node';
    case Sass = 'sass';
    case Composer = 'composer';
    case Tailwindcss = 'tailwindcss';

    public function installHint(): string
    {
        return match ($this) {
            self::Bun => 'Install bun and pass PATH through the dev-server environment.',
            self::Npx => 'Install Node.js and pass PATH through the dev-server environment.',
            self::Php => 'PHP binary not found in PATH',
            self::Node => 'Install Node.js and pass PATH through the dev-server environment.',
            self::Sass => 'Install Dart Sass and pass PATH through the dev-server environment.',
            self::Composer => 'Install Composer and pass PATH through the dev-server environment.',
            self::Tailwindcss => 'Install Tailwind CSS or add it to node_modules/.bin.',
        };
    }

    /**
     * @param array<string, string> $env
     * @return list<string>
     */
    public function fallbacks(array $env = []): array
    {
        return match ($this) {
            self::Php => [\PHP_BINARY],
            self::Npx => ['./node_modules/.bin/npx'],
            self::Sass => ['./node_modules/.bin/sass'],
            self::Tailwindcss => ['./node_modules/.bin/tailwindcss'],
            self::Bun => ($home = $env['HOME'] ?? $env['USERPROFILE'] ?? '') !== '' ? [$home . '/.bun/bin/bun'] : [],
            default => [],
        };
    }
}
