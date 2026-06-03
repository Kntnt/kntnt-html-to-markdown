<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown;

use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Converter\Options;
use Kntnt\HtmlToMarkdown\Plugin\Base\BasePlugin;
use Kntnt\HtmlToMarkdown\Plugin\Commonmark\CommonmarkPlugin;

/**
 * Convenience facade for the common HTML-to-Markdown conversion.
 *
 * This mirrors upstream's `ConvertString`: it builds a converter with the
 * base and commonmark plugins and converts in one call. For strikethrough,
 * tables, custom options, or include/exclude selectors, build a
 * {@see Converter} directly.
 *
 * @since 0.1.0
 */
final class HtmlToMarkdown
{
    /**
     * Converts an HTML string to CommonMark Markdown.
     *
     * @since 0.1.0
     *
     * @param string      $html            The HTML to convert.
     * @param string      $domain          Base domain for resolving relative URLs.
     * @param string|null $includeSelector CSS selector limiting what is converted.
     * @param string|null $excludeSelector CSS selector for elements to strip.
     *
     * @throws \Kntnt\HtmlToMarkdown\Exception\HtmlToMarkdownException
     */
    public static function convert(string $html, string $domain = '', ?string $includeSelector = null, ?string $excludeSelector = null): string
    {
        $converter = new Converter([
            new BasePlugin(),
            new CommonmarkPlugin(),
        ]);

        return $converter->convertString($html, new Options($domain, $includeSelector, $excludeSelector));
    }
}
