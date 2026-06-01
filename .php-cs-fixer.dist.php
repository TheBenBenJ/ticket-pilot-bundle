<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP82Migration' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'global_namespace_import' => ['import_classes' => false, 'import_functions' => false, 'import_constants' => false],
    ])
    ->setFinder($finder);
