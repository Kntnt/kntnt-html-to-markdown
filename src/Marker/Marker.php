<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Marker;

/**
 * Sentinel byte markers used internally by the converter.
 *
 * Markdown conversion needs a few "private" characters that can be embedded
 * in the working text without ever colliding with real content, then removed
 * or replaced in a later phase. Upstream uses the bell character for escaping
 * and a private-use rune for protected code-block newlines; both are ported
 * here as the exact same byte sequences so output stays byte-for-byte
 * identical.
 *
 * @since 0.1.0
 */
final class Marker
{
    /**
     * Escaping sentinel: the bell character (U+0007, a single byte).
     *
     * During rendering every character that *might* need a backslash is
     * prefixed with this byte. A later context-aware pass decides, per
     * occurrence, whether the byte becomes a backslash or is simply dropped.
     *
     * @since 0.1.0
     */
    public const string ESCAPING = "\x07";

    /**
     * Code-block newline sentinel: U+F002 (a private-use rune, 3 UTF-8 bytes).
     *
     * Newlines inside a fenced code block are temporarily swapped for this
     * marker so the whitespace-trimming post-processors leave them untouched.
     * A final post-render pass swaps the marker back to a real newline.
     *
     * @since 0.1.0
     */
    public const string CODE_BLOCK_NEWLINE = "\u{F002}";
}
