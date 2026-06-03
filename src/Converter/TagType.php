<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Converter;

/**
 * How a tag participates in block vs. inline layout.
 *
 * The tag type drives two things: whether whitespace around the element is
 * collapsed as a block boundary, and how the render fallback pads the
 * element with blank lines. `Remove` is a third, special value — a tag
 * marked for removal is stripped from the DOM in an early pre-render pass.
 *
 * @since 0.1.0
 */
enum TagType: string
{
    /**
     * A block-level element (own line, surrounded by blank lines).
     *
     * @since 0.1.0
     */
    case Block = 'block';

    /**
     * An inline element (flows within a line).
     *
     * @since 0.1.0
     */
    case Inline = 'inline';

    /**
     * A tag to delete from the DOM before rendering.
     *
     * @since 0.1.0
     */
    case Remove = 'remove';
}
