<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'php_unit_method_casing' => [
            'case' => 'snake_case'
        ],
    ])
    ->setFinder($finder);
