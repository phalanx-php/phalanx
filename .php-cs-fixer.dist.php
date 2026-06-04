<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src/Aegis/src',
        __DIR__ . '/src/Archon/src',
        __DIR__ . '/src/Argos/src',
        __DIR__ . '/src/Athena/src',
        __DIR__ . '/src/Cli/src',
        __DIR__ . '/src/Enigma/src',
        __DIR__ . '/src/Grammata/src',
        __DIR__ . '/src/Hermes/src',
        __DIR__ . '/src/Hydra/src',
        __DIR__ . '/src/Iris/src',
        __DIR__ . '/src/Mark',
        __DIR__ . '/src/Panoply/src',
        __DIR__ . '/src/Skopos/src',
        __DIR__ . '/src/Stoa/src',
        __DIR__ . '/src/Styx/src',
        __DIR__ . '/src/Surreal/src',
        __DIR__ . '/src/Theatron/src',
        __DIR__ . '/src/Themis/src',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw'],
        ],
    ])
    ->setFinder($finder);
