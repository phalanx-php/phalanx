<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src/Aegis',
        __DIR__ . '/src/Archon',
        __DIR__ . '/src/Argos',
        __DIR__ . '/src/Athena',
        __DIR__ . '/src/Cli',
        __DIR__ . '/src/Enigma',
        __DIR__ . '/src/Grammata',
        __DIR__ . '/src/Hermes',
        __DIR__ . '/src/Hydra',
        __DIR__ . '/src/Iris',
        __DIR__ . '/src/Mark',
        __DIR__ . '/src/Panoply',
        __DIR__ . '/src/Skopos',
        __DIR__ . '/src/Stoa',
        __DIR__ . '/src/Styx',
        __DIR__ . '/src/Surreal',
        __DIR__ . '/src/Theatron',
        __DIR__ . '/src/Themis',
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
