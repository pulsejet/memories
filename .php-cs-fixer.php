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
    ->in(__DIR__.'/lib')
;

$config = new PhpCsFixer\Config();
$config
    ->setUsingCache(true)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PhpCsFixer' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'fully_qualified_strict_types' => false,
        'general_phpdoc_annotation_remove' => ['annotations' => ['expectedDeprecation']], // one should use PHPUnit built-in method instead
        'modernize_strpos' => true,
        'no_alias_functions' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'nullable_type_declaration' => ['syntax' => 'question_mark'],
        'operator_linebreak' => [
            'position' => 'beginning',
        ],
        'phpdoc_to_comment' => ['ignored_tags' => ['psalm-suppress', 'template-implements', 'var']],
        'return_assignment' => true,
        'strict_param' => true,
        'ternary_to_elvis_operator' => true,
        'ternary_to_null_coalescing' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'parameters', 'arguments']],
    ])
    ->setFinder($finder)
;

return $config;
