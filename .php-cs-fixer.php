<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->ignoreVCSIgnored(true)
    ->in([
        __DIR__.'/lib',
        __DIR__.'/tests',
    ])
;

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache')
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP8x2Migration' => true, // lowest PHP supported by Nextcloud
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        'general_phpdoc_annotation_remove' => ['annotations' => ['expectedDeprecation']], // one should use PHPUnit built-in method instead
        'phpdoc_to_comment' => ['ignored_tags' => ['psalm-suppress', 'template-implements', 'var']],
        'trailing_comma_in_multiline' => ['after_heredoc' => true, 'elements' => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters']],
        'declare_strict_types' => true,
        'fully_qualified_strict_types' => false, // keep leading `\` on `\OC\...` references
    ])
    ->setFinder($finder)
;
