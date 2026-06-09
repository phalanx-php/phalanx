<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw'],
        ],
    ])
    ->setFinder($finder);
