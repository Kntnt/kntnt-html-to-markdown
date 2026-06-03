<?php

declare(strict_types=1);

/**
 * PHP-CS-Fixer configuration.
 *
 * The surface style is PSR-12. The project coding standard overrides a
 * handful of points where the Kntnt standard is stricter or more modern
 * than the PSR-12 baseline (short array syntax, trailing commas, ordered
 * imports).
 *
 * @since 0.1.0
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters'],
        ],
        'declare_strict_types' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder);
