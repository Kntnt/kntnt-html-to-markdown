<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Plugin\Base;

use Dom\Node;
use Kntnt\HtmlToMarkdown\Collapse\Collapse;
use Kntnt\HtmlToMarkdown\Converter\Context;
use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Converter\Plugin;
use Kntnt\HtmlToMarkdown\Converter\Priority;
use Kntnt\HtmlToMarkdown\Converter\TagType;
use Kntnt\HtmlToMarkdown\Dom\Dom;
use Kntnt\HtmlToMarkdown\Dom\Tags;
use Kntnt\HtmlToMarkdown\Internal\DomUtils\DomUtils;
use Kntnt\HtmlToMarkdown\Internal\TextUtils\TextUtils;

/**
 * Foundational behaviour shared by every conversion.
 *
 * The base plugin is not about any particular Markdown syntax. It removes
 * non-content nodes (head, script, style, …), collapses whitespace, escapes
 * the few HTML characters that matter, and trims the final output. The
 * commonmark plugin depends on it, and the converter refuses to run
 * commonmark without it.
 *
 * @since 0.1.0
 */
final class BasePlugin implements Plugin
{
    /**
     * Tag names whose nodes are stripped before rendering.
     *
     * @since 0.1.0
     *
     * @var list<string>
     */
    private const array REMOVE_TAGS = [
        '#comment', 'head', 'script', 'style', 'link', 'meta',
        'iframe', 'noscript', 'input', 'textarea',
    ];

    /**
     * @since 0.1.0
     */
    public function name(): string
    {
        return 'base';
    }

    /**
     * @since 0.1.0
     */
    public function init(Converter $converter): void
    {
        foreach (self::REMOVE_TAGS as $tag) {
            $converter->register->tagType($tag, TagType::Remove, Priority::STANDARD);
        }

        $converter->register->preRenderer($this->preRenderRemove(...), Priority::EARLY);
        // Collapse runs late so every other pre-render transform has finished.
        $converter->register->preRenderer($this->preRenderCollapse(...), Priority::LATE);

        $converter->register->textTransformer($this->handleTextTransform(...), Priority::STANDARD);

        $converter->register->postRenderer($this->postRenderTrimContent(...), Priority::STANDARD);
        $converter->register->postRenderer($this->postRenderUnescapeContent(...), Priority::STANDARD + 20);
    }

    /**
     * Removes nodes whose tag type is {@see TagType::Remove}, then merges the
     * adjacent text nodes a removal can leave behind.
     *
     * @since 0.1.0
     */
    private function preRenderRemove(Context $ctx, Node $doc): void
    {
        $finder = static function (Node $node) use (&$finder, $ctx): void {
            if ($ctx->getTagType(Dom::nodeName($node)) === TagType::Remove) {
                Dom::removeNode($node);

                return;
            }

            // Visit children in reverse, mirroring upstream's deferred walk,
            // so removals never disturb the surrounding iteration.
            foreach (array_reverse(Dom::allChildNodes($node)) as $child) {
                $finder($child);
            }
        };
        $finder($doc);

        DomUtils::mergeAdjacentTextNodes($doc);
    }

    /**
     * Collapses whitespace, deferring the block test to the tag-type registry.
     *
     * @since 0.1.0
     */
    private function preRenderCollapse(Context $ctx, Node $doc): void
    {
        Collapse::collapse($doc, static function (Node $node) use ($ctx): bool {
            $tagType = $ctx->getTagType(Dom::nodeName($node));
            if ($tagType !== null) {
                return $tagType === TagType::Block;
            }

            return Tags::nameIsBlock(Dom::nodeName($node));
        });
    }

    /**
     * Escapes the HTML characters that matter ("<" and ">") and then the
     * Markdown-significant characters.
     *
     * Upstream deliberately stopped escaping "&"; in most content a bare
     * ampersand is fine and over-escaping it hurt readability.
     *
     * @since 0.1.0
     */
    private function handleTextTransform(Context $ctx, string $content): string
    {
        $content = strtr($content, ['<' => '&lt;', '>' => '&gt;']);

        return $ctx->escapeContent($content);
    }

    /**
     * Trims surrounding whitespace and squeezes excess blank lines and
     * redundant hard breaks.
     *
     * @since 0.1.0
     */
    private function postRenderTrimContent(Context $ctx, string $result): string
    {
        $result = TextUtils::trimSpace($result);
        $result = TextUtils::trimConsecutiveNewlines($result);

        return TextUtils::trimUnnecessaryHardLineBreaks($result);
    }

    /**
     * Resolves the escaping sentinels into backslashes (or nothing).
     *
     * @since 0.1.0
     */
    private function postRenderUnescapeContent(Context $ctx, string $result): string
    {
        return $ctx->unEscapeContent($result);
    }
}
