<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/public',
        __DIR__ . '/entrypoints',
        __DIR__ . '/tests',
        __DIR__ . '/../scripts',
    ])
    // Fixtures are scanned as text by the architecture tests; they must stay exactly as written.
    ->exclude('Fixtures')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
