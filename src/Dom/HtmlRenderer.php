<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Dom;

use Dom\Comment;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * Serializes a DOM node the way Go's `html.Render` would.
 *
 * The converter occasionally emits a node as raw HTML (kept comments, the
 * synthetic list-end marker). PHP's `saveHtml` is almost identical to Go's
 * renderer, but it does not escape the data inside a comment. Go does — for
 * security, it rewrites `&` and certain `>` so a comment cannot be broken out
 * of. This class reproduces Go's `escapeComment` so that raw-HTML output
 * stays byte-for-byte with upstream.
 *
 * @since 0.1.0
 */
final class HtmlRenderer
{
    /**
     * Renders a node to its HTML string.
     *
     * Comments are escaped exactly as Go does; every other node defers to
     * PHP's serializer, which matches Go's element rendering.
     *
     * @since 0.1.0
     */
    public static function render(Node $node): string
    {
        if ($node instanceof Comment) {
            return '<!--' . self::escapeComment($node->data) . '-->';
        }

        $document = $node->ownerDocument;
        if ($document instanceof HTMLDocument) {
            return $document->saveHtml($node);
        }

        return '';
    }

    /**
     * Escapes comment data following Go's `escapeComment`.
     *
     * `&` always becomes `&amp;`. A `>` becomes `&gt;` only at the very start
     * or when it follows `!` or `-` (the bytes that could otherwise help form
     * a premature comment terminator); a `>` after ordinary text is left
     * alone. `<` is never escaped.
     *
     * @since 0.1.0
     */
    private static function escapeComment(string $s): string
    {
        $result = '';
        $i = 0;
        $length = strlen($s);

        for ($j = 0; $j < $length; $j++) {
            $char = $s[$j];
            if ($char === '&') {
                $escaped = '&amp;';
            } elseif ($char === '>') {
                if ($j > 0 && $s[$j - 1] !== '!' && $s[$j - 1] !== '-') {
                    continue;
                }
                $escaped = '&gt;';
            } else {
                continue;
            }

            if ($i < $j) {
                $result .= substr($s, $i, $j - $i);
            }
            $result .= $escaped;
            $i = $j + 1;
        }

        if ($i < $length) {
            $result .= substr($s, $i);
        }

        return $result;
    }
}
