<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Dom;

/**
 * Built-in classification of HTML tag names as block, inline, or heading.
 *
 * These lists are the converter's default answer to "is this tag a block?"
 * when no plugin has registered an explicit {@see \Kntnt\HtmlToMarkdown\Converter\TagType}
 * for it. They are ported verbatim from upstream's `dom` package so the
 * block/inline decision — which drives whitespace collapsing and the render
 * fallback — matches byte-for-byte.
 *
 * @since 0.1.0
 */
final class Tags
{
    /**
     * Inline tag names (plus the "#text" pseudo-name).
     *
     * @since 0.1.0
     *
     * @var array<string, true>
     */
    private const array INLINE = [
        '#text' => true, 'a' => true, 'abbr' => true, 'acronym' => true,
        'audio' => true, 'b' => true, 'bdi' => true, 'bdo' => true,
        'big' => true, 'br' => true, 'button' => true, 'canvas' => true,
        'cite' => true, 'code' => true, 'data' => true, 'datalist' => true,
        'del' => true, 'dfn' => true, 'em' => true, 'embed' => true,
        'i' => true, 'iframe' => true, 'img' => true, 'input' => true,
        'ins' => true, 'kbd' => true, 'label' => true, 'map' => true,
        'mark' => true, 'meter' => true, 'noscript' => true, 'object' => true,
        'output' => true, 'picture' => true, 'progress' => true, 'q' => true,
        'ruby' => true, 's' => true, 'samp' => true, 'script' => true,
        'select' => true, 'slot' => true, 'small' => true, 'span' => true,
        'strong' => true, 'sub' => true, 'sup' => true, 'svg' => true,
        'template' => true, 'textarea' => true, 'time' => true, 'u' => true,
        'tt' => true, 'var' => true, 'video' => true, 'wbr' => true,
    ];

    /**
     * Block tag names.
     *
     * @since 0.1.0
     *
     * @var array<string, true>
     */
    private const array BLOCK = [
        'address' => true, 'article' => true, 'aside' => true,
        'blockquote' => true, 'details' => true, 'dialog' => true,
        'dd' => true, 'div' => true, 'dl' => true, 'dt' => true,
        'fieldset' => true, 'figcaption' => true, 'figure' => true,
        'footer' => true, 'form' => true, 'h1' => true, 'h2' => true,
        'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'header' => true, 'hgroup' => true, 'hr' => true, 'li' => true,
        'main' => true, 'nav' => true, 'ol' => true, 'p' => true,
        'pre' => true, 'section' => true, 'table' => true, 'ul' => true,
    ];

    /**
     * Reports whether the name is a built-in inline tag.
     *
     * @since 0.1.0
     */
    public static function nameIsInline(string $name): bool
    {
        return isset(self::INLINE[$name]);
    }

    /**
     * Reports whether the name is a built-in block tag.
     *
     * @since 0.1.0
     */
    public static function nameIsBlock(string $name): bool
    {
        return isset(self::BLOCK[$name]);
    }

    /**
     * Reports whether the name is one of h1–h6.
     *
     * @since 0.1.0
     */
    public static function nameIsHeading(string $name): bool
    {
        return $name === 'h1' || $name === 'h2' || $name === 'h3'
            || $name === 'h4' || $name === 'h5' || $name === 'h6';
    }
}
