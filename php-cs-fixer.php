<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->notPath('vendor')
    ->notName('aliases.php'); // generated aliases file — skip style enforcement

return (new Config())
    ->setRiskyAllowed(true)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setRules([
        // ── Rulesets ──────────────────────────────────────────────
        '@PER-CS'                       => true,
        '@PER-CS:risky'                 => true,
        '@PHP82Migration'               => true,
        '@PHP82Migration:risky'         => true,

        // ── Strict types ──────────────────────────────────────────
        'declare_strict_types'          => true,
        'strict_param'                  => true,
        'strict_comparison'             => true,

        // ── Imports ───────────────────────────────────────────────
        'ordered_imports'               => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'             => true,
        'fully_qualified_strict_types'  => true,
        'global_namespace_import'       => [
            'import_classes'   => false,
            'import_constants' => false,
            'import_functions' => false,
        ],

        // ── Arrays ────────────────────────────────────────────────
        'array_syntax'                  => ['syntax' => 'short'],
        'trailing_comma_in_multiline'   => [
            'elements' => ['arrays', 'arguments', 'parameters', 'match'],
        ],
        'normalize_index_brace'         => true,

        // ── Classes ───────────────────────────────────────────────
        'ordered_class_elements'        => [
            'order' => [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_public',
                'method_protected',
                'method_private',
            ],
        ],
        'no_blank_lines_after_class_opening' => true,
        'class_attributes_separation'   => [
            'elements' => [
                'const'        => 'one',
                'method'       => 'one',
                'property'     => 'one',
                'trait_import' => 'none',
                'case'         => 'none',
            ],
        ],
        'self_accessor'                 => true,
        'self_static_accessor'          => true,

        // ── Functions & Closures ──────────────────────────────────
        'use_arrow_functions'           => true,
        'static_lambda'                 => true,
        'no_useless_return'             => true,

        // ── Strings ───────────────────────────────────────────────
        'single_quote'                  => ['strings_containing_single_quote_chars' => false],
        'explicit_string_variable'      => true,

        // ── Control flow ──────────────────────────────────────────
        'no_superfluous_elseif'         => true,
        'no_useless_else'               => true,
        'simplified_if_return'          => true,
        'yoda_style'                    => [
            'equal'          => false,
            'identical'      => false,
            'less_and_greater' => false,
        ],

        // ── Types & Nullables ─────────────────────────────────────
        'nullable_type_declaration_for_default_null_value' => true,
        'nullable_type_declaration'     => ['syntax' => 'union'],

        // ── PHPDoc ────────────────────────────────────────────────
        'phpdoc_order'                  => true,
        'phpdoc_separation'             => true,
        'phpdoc_trim'                   => true,
        'phpdoc_no_empty_return'        => true,
        'phpdoc_scalar'                 => true,
        'no_superfluous_phpdoc_tags'    => ['remove_inheritdoc' => false],

        // ── Whitespace & Formatting ───────────────────────────────
        'concat_space'                  => ['spacing' => 'one'],
        'binary_operator_spaces'        => ['default' => 'single_space'],
        'blank_line_before_statement'   => [
            'statements' => ['break', 'continue', 'declare', 'return', 'throw', 'try'],
        ],
        'operator_linebreak'            => ['only_booleans' => true, 'position' => 'beginning'],

        // ── Misc ──────────────────────────────────────────────────
        'mb_str_functions'              => true,
        'modernize_strpos'              => true,
        'get_class_to_class_keyword'    => true,
        'no_alias_functions'            => true,
        'pow_to_exponentiation'         => true,
    ])
    ->setFinder($finder);
