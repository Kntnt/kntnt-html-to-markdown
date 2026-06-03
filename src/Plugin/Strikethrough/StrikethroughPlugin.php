<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Plugin\Strikethrough;

use Dom\Node;
use Kntnt\HtmlToMarkdown\Converter\Buffer;
use Kntnt\HtmlToMarkdown\Converter\Context;
use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Converter\Plugin;
use Kntnt\HtmlToMarkdown\Converter\Priority;
use Kntnt\HtmlToMarkdown\Converter\RenderStatus;
use Kntnt\HtmlToMarkdown\Dom\Dom;
use Kntnt\HtmlToMarkdown\Internal\DomUtils\DomUtils;
use Kntnt\HtmlToMarkdown\Internal\Escape\Escape;
use Kntnt\HtmlToMarkdown\Internal\TextUtils\TextUtils;

/**
 * GFM strikethrough for `<del>`, `<s>`, and `<strike>`.
 *
 * Wraps the element's content in the delimiter (default "~~"), repeating it
 * on every content line so it survives across newlines, and escapes stray
 * tildes so they are not misread as strikethrough. A faithful port of
 * upstream's `plugin/strikethrough`.
 *
 * @since 0.1.0
 */
final class StrikethroughPlugin implements Plugin
{
    /**
     * @since 0.1.0
     *
     * @param string $delimiter The strikethrough delimiter.
     */
    public function __construct(private readonly string $delimiter = '~~')
    {
    }

    /**
     * @since 0.1.0
     */
    public function name(): string
    {
        return 'strikethrough';
    }

    /**
     * @since 0.1.0
     */
    public function init(Converter $converter): void
    {
        $converter->register->preRenderer($this->handlePreRender(...), Priority::STANDARD);

        $converter->register->escapedChar('~');
        $converter->register->unEscaper($this->handleUnEscaper(...), Priority::STANDARD);

        $converter->register->renderer($this->handleRender(...), Priority::STANDARD);
    }

    /**
     * Unwraps redundant nesting and merges adjacent strikethrough elements.
     *
     * @since 0.1.0
     */
    private function handlePreRender(Context $ctx, Node $doc): void
    {
        DomUtils::removeRedundant($doc, $this->nameIsBothStrikethrough(...));
        DomUtils::mergeAdjacent($doc, $this->nameIsStrikethrough(...));
    }

    /**
     * Escapes a "~" only when it is not followed by whitespace.
     *
     * @since 0.1.0
     */
    private function handleUnEscaper(string $chars, int $index): int
    {
        if ($chars[$index] !== '~') {
            return -1;
        }

        $next = Escape::getNextAsRune($chars, $index);
        if ($next === 0 || $this->isWhitespaceRune($next)) {
            return -1;
        }

        return 1;
    }

    /**
     * Reports whether a codepoint is Unicode whitespace.
     *
     * @since 0.1.0
     */
    private function isWhitespaceRune(int $codepoint): bool
    {
        return preg_match('/^[' . TextUtils::WHITESPACE . ']$/u', mb_chr($codepoint, 'UTF-8') ?: '') === 1;
    }

    /**
     * Renders a strikethrough element, falling through for anything else.
     *
     * @since 0.1.0
     */
    private function handleRender(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        if (!$this->nameIsStrikethrough($node)) {
            return RenderStatus::TryNext;
        }

        $buf = new Buffer();
        $ctx->renderChildNodes($ctx, $buf, $node);

        $content = TextUtils::delimiterForEveryLine($buf->bytes(), $this->delimiter);
        $w->write($content);

        return RenderStatus::Success;
    }

    /**
     * Reports whether a node is a strikethrough element.
     *
     * @since 0.1.0
     */
    private function nameIsStrikethrough(Node $node): bool
    {
        $name = Dom::nodeName($node);

        return $name === 'del' || $name === 's' || $name === 'strike';
    }

    /**
     * Reports whether both nodes are strikethrough elements.
     *
     * @since 0.1.0
     */
    private function nameIsBothStrikethrough(Node $a, Node $b): bool
    {
        return $this->nameIsStrikethrough($a) && $this->nameIsStrikethrough($b);
    }
}
