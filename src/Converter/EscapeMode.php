<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Converter;

/**
 * How aggressively the converter escapes Markdown-significant characters.
 *
 * "Smart" (the default) escapes a character only when, in its rendered
 * context, it would otherwise be misread as Markdown syntax. "Disabled"
 * turns escaping off entirely, which can be useful when the caller knows the
 * input is safe and wants the rawest possible output.
 *
 * @since 0.1.0
 */
enum EscapeMode: string
{
    /**
     * No escaping at all.
     *
     * @since 0.1.0
     */
    case Disabled = 'disabled';

    /**
     * Context-aware escaping (the default).
     *
     * @since 0.1.0
     */
    case Smart = 'smart';
}
