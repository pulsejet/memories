<?php

declare(strict_types=1);

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

$finder = PhpCsFixer\Finder::create()
    ->ignoreDotFiles(false)
    ->ignoreVCSIgnored(true)
    ->in(__DIR__ . '/lib')
;

$config = new PhpCsFixer\Config();
$config
    ->setUsingCache(true)
    ->setRules([
        '@PSR1' => true,
        '@PSR2' => true,
        'align_multiline_comment' => true,
        'array_indentation' => true,
        'array_syntax' => true,
        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'cast_spaces' => ['space' => 'none'],
        'concat_space' => ['spacing' => 'one'],
        'curly_braces_position' => [
            'classes_opening_brace' => 'same_line',
            'functions_opening_brace' => 'same_line',
        ],
        'elseif' => true,
        'encoding' => true,
        'full_opening_tag' => true,
        'function_declaration' => [
            'closure_function_spacing' => 'one',
        ],
        'indentation_type' => true,
        'line_ending' => true,
        'list_syntax' => true,
        'lowercase_cast' => true,
        'lowercase_keywords' => true,
        'method_argument_space' => [
            'on_multiline' => 'ignore',
        ],
        'method_chaining_indentation' => true,
        'no_closing_tag' => true,
        'no_leading_import_slash' => true,
        'no_short_bool_cast' => true,
        'no_spaces_after_function_name' => true,
        'no_spaces_inside_parenthesis' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'no_unused_imports' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'nullable_type_declaration' => ['syntax' => 'question_mark'],
        'operator_linebreak' => [
            'position' => 'beginning',
        ],
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_var_annotation_correct_order' => true,
        'short_scalar_cast' => true,
        'single_blank_line_at_eof' => true,
        'single_class_element_per_statement' => true,
        'single_import_per_statement' => true,
        'single_line_after_imports' => true,
        'single_quote' => ['strings_containing_single_quote_chars' => false],
        'switch_case_space' => true,
        'trailing_comma_in_multiline' => ['elements' => ['parameters']],
        'types_spaces' => ['space' => 'none', 'space_multiple_catch' => 'none'],
        'type_declaration_spaces' => ['elements' => ['function', 'property']],
        'visibility_required' => [
            'elements' => ['property', 'method', 'const'],
        ],
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
    ])
    ->setFinder($finder)
;

return $config;
