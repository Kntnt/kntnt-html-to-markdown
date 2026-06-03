<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Converter;

/**
 * A growable byte buffer that render handlers write into.
 *
 * This is the PHP stand-in for the `bytes.Buffer` / `Writer` upstream passes
 * around. PHP strings are byte strings, so a single {@see Buffer::write()}
 * method covers every upstream write variant (byte, rune, string); callers
 * convert their values to a string and append.
 *
 * @since 0.1.0
 */
final class Buffer
{
    /**
     * The accumulated bytes.
     *
     * @since 0.1.0
     */
    private string $content = '';

    /**
     * Appends a byte string to the buffer.
     *
     * @since 0.1.0
     */
    public function write(string $bytes): void
    {
        $this->content .= $bytes;
    }

    /**
     * Returns everything written so far.
     *
     * @since 0.1.0
     */
    public function bytes(): string
    {
        return $this->content;
    }
}
