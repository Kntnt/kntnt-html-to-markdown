<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Converter;

/**
 * Per-conversion options.
 *
 * These are supplied at convert time (not when the converter is built), so a
 * single converter can serve many requests with different domains or scopes.
 *
 * @since 0.1.0
 */
final readonly class Options
{
    /**
     * @since 0.1.0
     *
     * @param string      $domain          Base domain used to turn relative URLs
     *                                      (in links and images) into absolute
     *                                      ones. Empty leaves relative URLs as-is.
     * @param string|null $includeSelector CSS selector; when set, only matching
     *                                      elements are converted. Applied with
     *                                      `querySelectorAll` before rendering.
     * @param string|null $excludeSelector CSS selector; matching elements are
     *                                      stripped from the DOM before rendering.
     */
    public function __construct(
        public string $domain = '',
        public ?string $includeSelector = null,
        public ?string $excludeSelector = null,
    ) {
    }
}
