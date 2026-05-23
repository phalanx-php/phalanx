<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/framework/src/*/src',
        __DIR__ . '/framework/src/*/tests',
    ])
    ->notPath([
        'Testing/Generated/',
        'Fixtures/',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],

        'array_indentation' => true,

        'method_chaining_indentation' => true,

        'no_extra_blank_lines' => [
            'tokens' => [
                'curly_brace_block',
                'extra',
                'parenthesis_brace_block',
                'square_brace_block',
                'use',
            ],
        ],

        'no_whitespace_in_blank_line' => true,

        'trim_array_spaces' => true,

        'single_space_around_construct' => true,

        'cast_spaces' => ['space' => 'single'],

        'concat_space' => ['spacing' => 'one'],

        'type_declaration_spaces' => true,

        'no_spaces_around_offset' => true,
    ]);
