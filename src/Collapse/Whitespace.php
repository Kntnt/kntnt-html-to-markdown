<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Collapse;

/**
 * The whitespace-normalization primitive used by {@see Collapse}.
 *
 * @since 0.1.0
 */
final class Whitespace
{
    /**
     * Replaces every run of ASCII whitespace (space, tab, CR, LF) with a
     * single space.
     *
     * Upstream hand-optimizes this to avoid allocations; the behaviour is a
     * plain "collapse runs of `[ \t\r\n]` to one space", which a single regex
     * expresses exactly. Note the set is deliberately ASCII-only — NBSP and
     * the Unicode separators are left for later phases.
     *
     * @since 0.1.0
     */
    public static function replaceAnyWhitespaceWithSpace(string $source): string
    {
        if ($source === '') {
            return $source;
        }

        return preg_replace('/[ \t\r\n]+/', ' ', $source) ?? $source;
    }
}
