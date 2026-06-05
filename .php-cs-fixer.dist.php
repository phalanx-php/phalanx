<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src/Runtime',
        __DIR__ . '/src/Console',
        __DIR__ . '/src/Network',
        __DIR__ . '/src/Agents',
        __DIR__ . '/src/Cli',
        __DIR__ . '/src/Ssh',
        __DIR__ . '/src/Filesystem',
        __DIR__ . '/src/WebSocket',
        __DIR__ . '/src/Worker',
        __DIR__ . '/src/HttpClient',
        __DIR__ . '/src/Mark',
        __DIR__ . '/src/AiProviders',
        __DIR__ . '/src/DevServer',
        __DIR__ . '/src/Http',
        __DIR__ . '/src/Stream',
        __DIR__ . '/src/SurrealDb',
        __DIR__ . '/src/Tui',
        __DIR__ . '/src/Config',
    ])
    ->exclude('tests');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw'],
        ],
    ])
    ->setFinder($finder);
