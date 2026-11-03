<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/migrations']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        // The code compares as `$value === null`, which reads in the order it is said.
        'yoda_style' => false,
        // Inline `/** @var ... */` comments carry types for PHPStan; keep them as doc comments.
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
    ])
    ->setFinder($finder);
