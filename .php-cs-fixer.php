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
        '@PhpCsFixer:risky' => true,
        'general_phpdoc_annotation_remove' => ['annotations' => ['expectedDeprecation']], // one should use PHPUnit built-in method instead
        'phpdoc_to_comment' => ['ignored_tags' => ['psalm-suppress', 'template-implements', 'var']],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'parameters', 'arguments']],
        'modernize_strpos' => true,
        'no_alias_functions' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ternary_to_elvis_operator' => true,
        'ternary_to_null_coalescing' => true,
        'return_assignment' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
    ])
    ->setFinder($finder)
;

return $config;
