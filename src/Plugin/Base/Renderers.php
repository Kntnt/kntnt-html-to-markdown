<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Plugin\Base;

use Dom\Node;
use Kntnt\HtmlToMarkdown\Converter\Buffer;
use Kntnt\HtmlToMarkdown\Converter\Context;
use Kntnt\HtmlToMarkdown\Converter\RenderStatus;
use Kntnt\HtmlToMarkdown\Converter\TagType;
use Kntnt\HtmlToMarkdown\Dom\Dom;
use Kntnt\HtmlToMarkdown\Dom\HtmlRenderer;

/**
 * Reusable render handlers that emit raw HTML instead of Markdown.
 *
 * These are escape hatches a caller can wire up with `RendererFor` — for
 * instance to keep HTML comments verbatim in the output. They are ported
 * from upstream's `plugin/base/renderers.go`.
 *
 * @since 0.1.0
 */
final class Renderers
{
    /**
     * Renders a node as raw HTML, padded with blank lines when it is a block.
     *
     * @since 0.1.0
     */
    public static function renderAsHtml(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        $tagType = $ctx->getTagType(Dom::nodeName($node));

        if ($tagType === TagType::Block) {
            $w->write("\n\n");
        }
        $w->write(HtmlRenderer::render($node));
        if ($tagType === TagType::Block) {
            $w->write("\n\n");
        }

        return RenderStatus::Success;
    }
}
