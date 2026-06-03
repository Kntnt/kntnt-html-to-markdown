<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Plugin\Commonmark;

use Dom\Element;
use Dom\Node;
use Dom\Text;
use Kntnt\HtmlToMarkdown\Converter\Buffer;
use Kntnt\HtmlToMarkdown\Converter\Context;
use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Converter\Plugin;
use Kntnt\HtmlToMarkdown\Converter\Priority;
use Kntnt\HtmlToMarkdown\Converter\RenderStatus;
use Kntnt\HtmlToMarkdown\Dom\Dom;
use Kntnt\HtmlToMarkdown\Dom\HtmlRenderer;
use Kntnt\HtmlToMarkdown\Dom\Tags;
use Kntnt\HtmlToMarkdown\Exception\InvalidOptionException;
use Kntnt\HtmlToMarkdown\Internal\DomUtils\DomUtils;
use Kntnt\HtmlToMarkdown\Internal\Escape\Escape;
use Kntnt\HtmlToMarkdown\Internal\TextUtils\TextUtils;
use Kntnt\HtmlToMarkdown\Marker\Marker;

/**
 * CommonMark rendering: the core of the converter's output.
 *
 * This plugin turns the standard HTML elements into CommonMark — headings,
 * emphasis, links, images, code (inline and fenced), blockquotes, lists,
 * thematic breaks, hard breaks, and comments. It also runs the pre-render
 * DOM clean-ups (merging emphasis, fixing mis-nesting, demoting illegal
 * blocks) and registers the un-escapers that make escaping context-aware.
 *
 * It is a faithful port of upstream's `plugin/commonmark`. Options match
 * upstream's `WithEmDelimiter`, `WithStrongDelimiter`, and friends.
 *
 * @since 0.1.0
 */
final class CommonmarkPlugin implements Plugin
{
    /**
     * Characters this plugin marks as Markdown-significant for escaping.
     *
     * @since 0.1.0
     *
     * @var list<string>
     */
    private const array ESCAPED_CHARS = [
        '\\', '*', '_', '-', '+', '.', '>', '|', '$', '#', '=',
        '[', ']', '(', ')', '!', '~', '`', '"', "'",
    ];

    /**
     * @since 0.1.0
     */
    private readonly bool $disableListEndComment;

    /**
     * @since 0.1.0
     *
     * @param string $emDelimiter              Emphasis delimiter ("*" or "_").
     * @param string $strongDelimiter          Strong delimiter ("**" or "__").
     * @param string $horizontalRule           Thematic break string.
     * @param string $bulletListMarker         Bullet marker ("-", "+" or "*").
     * @param bool   $listEndComment           Insert the marker comment between adjacent lists.
     * @param string $codeBlockFence           Code-block fence ("```" or "~~~").
     * @param string $headingStyle             "atx" or "setext".
     * @param string $linkEmptyHrefBehavior    "render" or "skip" for empty-href links.
     * @param string $linkEmptyContentBehavior "render" or "skip" for empty-content links.
     */
    public function __construct(
        private readonly string $emDelimiter = '*',
        private readonly string $strongDelimiter = '**',
        private readonly string $horizontalRule = '* * *',
        private readonly string $bulletListMarker = '-',
        bool $listEndComment = true,
        private readonly string $codeBlockFence = '```',
        private readonly string $headingStyle = 'atx',
        private readonly string $linkEmptyHrefBehavior = 'render',
        private readonly string $linkEmptyContentBehavior = 'render',
    ) {
        $this->disableListEndComment = !$listEndComment;
    }

    /**
     * @since 0.1.0
     */
    public function name(): string
    {
        return 'commonmark';
    }

    /**
     * @since 0.1.0
     */
    public function init(Converter $converter): void
    {
        $this->validateConfig();

        $converter->register->preRenderer($this->handlePreRender(...), Priority::STANDARD);

        // Runs after collapse and removal so list adjacency is already settled.
        $converter->register->preRenderer(function (Context $ctx, Node $doc): void {
            if ($this->disableListEndComment) {
                return;
            }
            DomUtils::addListEndComments($doc);
        }, Priority::LATE + 100);

        $converter->register->escapedChar(...self::ESCAPED_CHARS);

        $unEscapers = [
            Escape::isItalicOrBold(...),
            Escape::isBlockQuote(...),
            Escape::isAtxHeader(...),
            Escape::isSetextHeader(...),
            Escape::isDivider(...),
            Escape::isOrderedList(...),
            Escape::isUnorderedList(...),
            Escape::isImageOrLink(...),
            Escape::isFencedCode(...),
            Escape::isInlineCode(...),
            Escape::isBackslash(...),
        ];
        foreach ($unEscapers as $unEscaper) {
            $converter->register->unEscaper($unEscaper, Priority::STANDARD);
        }

        $converter->register->renderer($this->handleRender(...), Priority::STANDARD);
        $converter->register->textTransformer($this->handleTextTransform(...), Priority::LATE);
        $converter->register->postRenderer($this->handlePostRenderCodeBlockNewline(...), Priority::LATE);
    }

    /**
     * Restores protected code-block newlines after all trimming is done.
     *
     * @since 0.1.0
     */
    private function handlePostRenderCodeBlockNewline(Context $ctx, string $content): string
    {
        return str_replace(Marker::CODE_BLOCK_NEWLINE, "\n", $content);
    }

    /**
     * Force-escapes "]" inside link text so it cannot close the link early.
     *
     * @since 0.1.0
     */
    private function handleTextTransform(Context $ctx, string $content): string
    {
        if ($ctx->value('is_inside_link') === true) {
            $content = str_replace(Marker::ESCAPING . ']', '\\]', $content);
        }

        return $content;
    }

    /**
     * Runs the pre-render DOM clean-ups in upstream's exact order.
     *
     * @since 0.1.0
     */
    private function handlePreRender(Context $ctx, Node $doc): void
    {
        DomUtils::renameFakeSpans($doc);

        DomUtils::removeRedundant($doc, $this->nameIsBothBoldOrItalic(...));
        DomUtils::mergeAdjacent($doc, $this->nameIsBoldOrItalic(...));

        DomUtils::removeEmptyCode($doc);
        DomUtils::swapTags($doc, $this->nameIsInlineCode(...), $this->nameIsPre(...));
        DomUtils::mergeAdjacent($doc, $this->nameIsInlineCode(...));

        DomUtils::addSpace($doc, $this->nameIsBoldOrItalic(...), $this->nameIsInlineCode(...));

        DomUtils::removeRedundant($doc, $this->nameIsBothLink(...));
        DomUtils::swapTags($doc, $this->nameIsBoldOrItalic(...), $this->nameIsLink(...));

        DomUtils::swapTags($doc, $this->nameIsLink(...), $this->nameIsHeading(...));
        DomUtils::leafBlockAlternatives($doc);

        DomUtils::moveListItems($doc);
    }

    /**
     * Dispatches a node to its render method by tag name.
     *
     * @since 0.1.0
     */
    private function handleRender(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        return match (Dom::nodeName($node)) {
            'strong', 'b', 'em', 'i' => $this->renderBoldItalic($ctx, $w, $node),
            'hr' => $this->renderDivider($w),
            'br' => $this->renderBreak($w),
            'ul', 'ol' => $this->renderListContainer($ctx, $w, $node),
            'pre' => $this->renderBlockCode($w, $node),
            'code', 'var', 'samp', 'kbd', 'tt' => $this->renderInlineCode($w, $node),
            'blockquote' => $this->renderBlockquote($ctx, $w, $node),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->renderHeading($ctx, $w, $node),
            'img' => $this->renderImage($ctx, $w, $node),
            'a' => $this->renderLink($ctx, $w, $node),
            '#comment' => $this->renderComment($w, $node),
            default => RenderStatus::TryNext,
        };
    }

    /**
     * Renders bold/italic with a delimiter repeated on every content line.
     *
     * @since 0.1.0
     */
    private function renderBoldItalic(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        $buf = new Buffer();
        $ctx->renderChildNodes($ctx, $buf, $node);

        $content = TextUtils::delimiterForEveryLine($buf->bytes(), $this->getDelimiter($node));
        $w->write($content);

        return RenderStatus::Success;
    }

    /**
     * Picks the strong or emphasis delimiter for the node.
     *
     * @since 0.1.0
     */
    private function getDelimiter(Node $node): string
    {
        $name = Dom::nodeName($node);

        return match (true) {
            $name === 'strong' || $name === 'b' => $this->strongDelimiter,
            $name === 'em' || $name === 'i' => $this->emDelimiter,
            default => '',
        };
    }

    /**
     * Renders a thematic break.
     *
     * @since 0.1.0
     */
    private function renderDivider(Buffer $w): RenderStatus
    {
        $w->write("\n\n" . $this->horizontalRule . "\n\n");

        return RenderStatus::Success;
    }

    /**
     * Renders a hard line break.
     *
     * @since 0.1.0
     */
    private function renderBreak(Buffer $w): RenderStatus
    {
        $w->write("  \n");

        return RenderStatus::Success;
    }

    /**
     * Renders a blockquote, prefixing every line with "> ".
     *
     * @since 0.1.0
     */
    private function renderBlockquote(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        $buf = new Buffer();
        $ctx->renderChildNodes($ctx, $buf, $node);

        $content = TextUtils::trimSpace($buf->bytes());
        if ($content === '') {
            return RenderStatus::Success;
        }

        $content = TextUtils::trimConsecutiveNewlines($content);
        $content = TextUtils::trimUnnecessaryHardLineBreaks($content);
        $content = TextUtils::prefixLines($content, '> ');

        $w->write("\n\n" . $content . "\n\n");

        return RenderStatus::Success;
    }

    /**
     * Renders an ATX or Setext heading.
     *
     * @since 0.1.0
     */
    private function renderHeading(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        $level = $this->getHeadingLevel(Dom::nodeName($node));

        $buf = new Buffer();
        $ctx->renderChildNodes($ctx, $buf, $node);
        $content = $buf->bytes();

        if (TextUtils::trimSpace($content) === '') {
            return RenderStatus::Success;
        }

        if ($this->headingStyle === 'setext' && $level < 3) {
            $content = TextUtils::trimConsecutiveNewlines($content);
            $content = TextUtils::escapeMultiLine($content);

            $width = $this->getUnderlineWidth($content, 3);
            $underline = str_repeat($level === 1 ? '=' : '-', $width);

            $w->write("\n\n" . $content . "\n" . $underline . "\n\n");

            return RenderStatus::Success;
        }

        $content = str_replace(["\n", "\r"], ' ', $content);
        $content = preg_replace('/  +/', ' ', $content) ?? $content;
        $content = TextUtils::trimSpace($content);
        $content = $this->escapePoundSignAtEnd($content);

        $w->write("\n\n" . str_repeat('#', $level) . ' ' . $content . "\n\n");

        return RenderStatus::Success;
    }

    /**
     * Maps a heading tag name to its level (defaulting to 6).
     *
     * @since 0.1.0
     */
    private function getHeadingLevel(string $name): int
    {
        return match ($name) {
            'h1' => 1,
            'h2' => 2,
            'h3' => 3,
            'h4' => 4,
            'h5' => 5,
            default => 6,
        };
    }

    /**
     * Computes the Setext underline width, ignoring escaping sentinels and
     * counting runes (so wide characters count as one).
     *
     * @since 0.1.0
     */
    private function getUnderlineWidth(string $content, int $minVal): int
    {
        $width = 0;
        foreach (explode("\n", $content) as $part) {
            $runeCount = mb_strlen($part, 'UTF-8') - substr_count($part, Marker::ESCAPING);
            $width = max($width, $runeCount);
        }

        return max($width, $minVal);
    }

    /**
     * Forces the escaping of a trailing "#" that ATX rendering would drop.
     *
     * @since 0.1.0
     */
    private function escapePoundSignAtEnd(string $s): string
    {
        $length = strlen($s);
        if ($length === 0 || $s[$length - 1] !== '#') {
            return $s;
        }
        if ($length >= 3 && $s[$length - 3] === '\\') {
            return $s;
        }

        // Overwrite the sentinel that precedes the final "#" with a backslash.
        $s[$length - 2] = '\\';

        return $s;
    }

    /**
     * Renders an image.
     *
     * @since 0.1.0
     */
    private function renderImage(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        $src = TextUtils::trimSpace(Dom::getAttributeOr($node, 'src', ''));
        if ($src === '') {
            return RenderStatus::TryNext;
        }

        $src = $ctx->assembleAbsoluteUrl($ctx, 'img', $src);

        $title = str_replace("\n", ' ', Dom::getAttributeOr($node, 'title', ''));
        $alt = str_replace("\n", ' ', Dom::getAttributeOr($node, 'alt', ''));
        $alt = $this->escapeAlt($alt);

        $w->write('![' . $alt . '](' . $src);
        if ($title !== '') {
            $w->write(' ' . TextUtils::surroundByQuotes($title));
        }
        $w->write(')');

        return RenderStatus::Success;
    }

    /**
     * Escapes the brackets inside an image's alt text.
     *
     * @since 0.1.0
     */
    private function escapeAlt(string $alt): string
    {
        $result = '';
        $length = strlen($alt);
        for ($i = 0; $i < $length; $i++) {
            if ($alt[$i] === '[' || $alt[$i] === ']') {
                if ($i - 1 < 0 || $alt[$i - 1] !== '\\') {
                    $result .= '\\';
                }
            }
            $result .= $alt[$i];
        }

        return $result;
    }

    /**
     * Renders an inline link.
     *
     * @since 0.1.0
     */
    private function renderLink(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        $ctx = $ctx->withValue('is_inside_link', true);

        $href = TextUtils::trimSpace(Dom::getAttributeOr($node, 'href', ''));
        $href = $ctx->assembleAbsoluteUrl($ctx, 'a', $href);

        if ($href === '' && $this->linkEmptyHrefBehavior === 'skip') {
            return RenderStatus::TryNext;
        }

        $title = str_replace("\n", ' ', Dom::getAttributeOr($node, 'title', ''));

        $buf = new Buffer();
        $ctx->renderChildNodes($ctx, $buf, $node);
        $content = $buf->bytes();

        if (TextUtils::trimSpace($content) === '' && $this->linkEmptyContentBehavior === 'skip') {
            return RenderStatus::TryNext;
        }

        // A link without an href is valid (e.g. "[text]()") but a title on it
        // would be invalid, so drop the title in that case.
        if ($href === '') {
            $title = '';
        }

        [$leftExtra, $trimmed, $rightExtra] = TextUtils::surroundingSpaces($content);
        $trimmed = TextUtils::trimConsecutiveNewlines($trimmed);
        $trimmed = TextUtils::escapeMultiLine($trimmed);

        $w->write($leftExtra . '[' . $trimmed . '](' . $href);
        if ($title !== '') {
            $w->write(' ' . TextUtils::surroundByQuotes($title));
        }
        $w->write(')' . $rightExtra);

        return RenderStatus::Success;
    }

    /**
     * Renders inline code, choosing a backtick fence long enough to wrap it.
     *
     * @since 0.1.0
     */
    private function renderInlineCode(Buffer $w, Node $node): RenderStatus
    {
        $fenceChar = '`';
        [$codeContent] = $this->getCodeWithoutTags($node);

        // A span of only spaces is emitted verbatim, with no stripping.
        if (TextUtils::trimSpace($codeContent) === '') {
            $w->write($fenceChar . $codeContent . $fenceChar);

            return RenderStatus::Success;
        }

        $code = TextUtils::collapseInlineCodeContent($codeContent);

        $fence = str_repeat($fenceChar, TextUtils::calculateCodeFenceOccurrences($fenceChar, $code) + 1);

        if (str_starts_with($code, '`')) {
            $code = ' ' . $code;
        }
        if (str_ends_with($code, '`')) {
            $code .= ' ';
        }

        $w->write($fence . $code . $fence);

        return RenderStatus::Success;
    }

    /**
     * Renders a fenced code block.
     *
     * @since 0.1.0
     */
    private function renderBlockCode(Buffer $w, Node $node): RenderStatus
    {
        [$code, $infoString] = $this->getCodeWithoutTags($node);

        if (str_ends_with($code, "\n")) {
            $code = substr($code, 0, -1);
        }

        $fenceChar = substr($this->codeBlockFence, 0, 1);
        $fence = TextUtils::calculateCodeFence($fenceChar, $code);

        // Protect the interior newlines from the whitespace post-processors.
        $code = str_replace("\n", Marker::CODE_BLOCK_NEWLINE, $code);

        $w->write("\n\n" . $fence . $infoString . "\n" . $code . "\n" . $fence . "\n\n");

        return RenderStatus::Success;
    }

    /**
     * Collects the text content of a code element (and its info string).
     *
     * @since 0.1.0
     *
     * @return array{0: string, 1: string}
     */
    private function getCodeWithoutTags(Node $startNode): array
    {
        $buf = '';
        $infoString = '';

        $walk = function (Node $node) use (&$walk, &$buf, &$infoString): void {
            $name = Dom::nodeName($node);

            if ($node instanceof Element && ($name === 'code' || $name === 'pre')) {
                if ($infoString === '') {
                    $infoString = $this->getCodeLanguage($node);
                }
            }

            if ($node instanceof Element && ($name === 'style' || $name === 'script' || $name === 'textarea')) {
                return;
            }
            if ($node instanceof Element && ($name === 'br' || $name === 'div')) {
                $buf .= "\n";
            }

            if ($node instanceof Text) {
                $buf .= $node->data;

                return;
            }

            for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) {
                $walk($child);
            }
        };
        $walk($startNode);

        return [$buf, $infoString];
    }

    /**
     * Extracts the language from a "language-"/"lang-" class, if present.
     *
     * @since 0.1.0
     */
    private function getCodeLanguage(Node $node): string
    {
        $class = Dom::getAttributeOr($node, 'class', '');

        foreach (explode(' ', $class) as $part) {
            if (!str_contains($part, 'language-') && !str_contains($part, 'lang-')) {
                continue;
            }

            $part = str_replace('language-', '', $part);

            return str_replace('lang-', '', $part);
        }

        return '';
    }

    /**
     * Renders a list container (ordered or unordered).
     *
     * @since 0.1.0
     */
    private function renderListContainer(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        $items = [];
        foreach (Dom::allChildNodes($node) as $child) {
            $buf = new Buffer();
            $ctx->renderNodes($ctx, $buf, $child);

            $content = TextUtils::trimSpace($buf->bytes());
            if ($content === '') {
                continue;
            }
            $items[] = $content;
        }

        if ($items === []) {
            return RenderStatus::Success;
        }

        $prefixFor = $this->getPrefixFunc($node, count($items));
        $indentCount = mb_strlen($prefixFor(0), 'UTF-8');

        $w->write("\n\n");
        $lastIndex = count($items) - 1;
        foreach ($items as $i => $item) {
            $w->write($prefixFor($i));

            $item = TextUtils::trimConsecutiveNewlines($item);
            $item = TextUtils::trimUnnecessaryHardLineBreaks($item);
            $item = $ctx->unEscapeContent($item);

            $this->renderMultiLineListItem($w, $item, $indentCount);

            if ($i < $lastIndex) {
                $w->write("\n");
            }
        }
        $w->write("\n\n");

        return RenderStatus::Success;
    }

    /**
     * Builds the per-item prefix function (bullet or zero-padded number).
     *
     * @since 0.1.0
     *
     * @return callable(int): string
     */
    private function getPrefixFunc(Node $node, int $sliceLength): callable
    {
        $startAt = $this->getStartAt($node);
        $isUnordered = Dom::nodeName($node) === 'ul';

        return function (int $sliceIndex) use ($sliceLength, $startAt, $isUnordered): string {
            if ($isUnordered) {
                return $this->bulletListMarker . ' ';
            }

            $currentIndex = $startAt + $sliceIndex;
            $lastIndex = $startAt + $sliceLength - 1;
            $maxLength = mb_strlen((string) $lastIndex, 'UTF-8');

            return sprintf('%0' . $maxLength . 'd. ', $currentIndex);
        };
    }

    /**
     * Reads an ordered list's "start" attribute, defaulting to 1.
     *
     * @since 0.1.0
     */
    private function getStartAt(Node $node): int
    {
        $startVal = Dom::getAttributeOr($node, 'start', '1');
        if (!preg_match('/^-?\d+$/', $startVal)) {
            return 1;
        }

        return (int) $startVal;
    }

    /**
     * Writes a possibly multi-line list item, indenting continuation lines.
     *
     * @since 0.1.0
     */
    private function renderMultiLineListItem(Buffer $w, string $content, int $indentCount): void
    {
        $lines = explode("\n", $content);
        $indent = str_repeat(' ', $indentCount);
        $indentedCodeBlockNewline = Marker::CODE_BLOCK_NEWLINE . $indent;

        $lastIndex = count($lines) - 1;
        foreach ($lines as $i => $line) {
            $line = str_replace(Marker::CODE_BLOCK_NEWLINE, $indentedCodeBlockNewline, $line);

            if ($i !== 0) {
                $w->write($indent);
            }
            $w->write($line);

            if ($i < $lastIndex) {
                $w->write("\n");
            }
        }
    }

    /**
     * Renders the synthetic "list end" comment as raw HTML; other comments
     * fall through to the default handling.
     *
     * @since 0.1.0
     */
    private function renderComment(Buffer $w, Node $node): RenderStatus
    {
        if ($node->textContent === DomUtils::LIST_END_COMMENT_DATA) {
            $w->write("\n\n" . HtmlRenderer::render($node) . "\n\n");

            return RenderStatus::Success;
        }

        return RenderStatus::TryNext;
    }

    /**
     * Validates the configuration, mirroring upstream's error wording.
     *
     * @since 0.1.0
     *
     * @throws InvalidOptionException
     */
    private function validateConfig(): void
    {
        if (substr_count($this->emDelimiter, '_') !== 1 && substr_count($this->emDelimiter, '*') !== 1) {
            throw InvalidOptionException::forConfig('EmDelimiter', $this->emDelimiter, 'exactly 1 character of "*" or "_"');
        }
        if (substr_count($this->strongDelimiter, '_') !== 2 && substr_count($this->strongDelimiter, '*') !== 2) {
            throw InvalidOptionException::forConfig('StrongDelimiter', $this->strongDelimiter, 'exactly 2 characters of "**" or "__"');
        }
        if (
            substr_count($this->horizontalRule, '*') < 3
            && substr_count($this->horizontalRule, '_') < 3
            && substr_count($this->horizontalRule, '-') < 3
        ) {
            throw InvalidOptionException::forConfig('HorizontalRule', $this->horizontalRule, 'at least 3 characters of "*", "_" or "-"');
        }
        if (!in_array($this->bulletListMarker, ['-', '+', '*'], true)) {
            throw InvalidOptionException::forConfig('BulletListMarker', $this->bulletListMarker, 'one of "-", "+" or "*"');
        }
        if (!in_array($this->codeBlockFence, ['```', '~~~'], true)) {
            throw InvalidOptionException::forConfig('CodeBlockFence', $this->codeBlockFence, 'one of "```" or "~~~"');
        }
        if (!in_array($this->headingStyle, ['atx', 'setext'], true)) {
            throw InvalidOptionException::forConfig('HeadingStyle', $this->headingStyle, 'one of "atx" or "setext"');
        }
    }

    /**
     * Reports whether a node is bold (`strong`/`b`).
     *
     * @since 0.1.0
     */
    private function nameIsBold(Node $node): bool
    {
        $name = Dom::nodeName($node);

        return $name === 'strong' || $name === 'b';
    }

    /**
     * Reports whether a node is italic (`em`/`i`).
     *
     * @since 0.1.0
     */
    private function nameIsItalic(Node $node): bool
    {
        $name = Dom::nodeName($node);

        return $name === 'em' || $name === 'i';
    }

    /**
     * Reports whether a node is bold or italic.
     *
     * @since 0.1.0
     */
    private function nameIsBoldOrItalic(Node $node): bool
    {
        return $this->nameIsBold($node) || $this->nameIsItalic($node);
    }

    /**
     * Reports whether two nodes are the same emphasis kind.
     *
     * @since 0.1.0
     */
    private function nameIsBothBoldOrItalic(Node $a, Node $b): bool
    {
        if ($this->nameIsBold($a) && $this->nameIsBold($b)) {
            return true;
        }

        return $this->nameIsItalic($a) && $this->nameIsItalic($b);
    }

    /**
     * Reports whether a node is a `<pre>`.
     *
     * @since 0.1.0
     */
    private function nameIsPre(Node $node): bool
    {
        return Dom::nodeName($node) === 'pre';
    }

    /**
     * Reports whether a node is treated as inline code.
     *
     * @since 0.1.0
     */
    private function nameIsInlineCode(Node $node): bool
    {
        $name = Dom::nodeName($node);

        return $name === 'code' || $name === 'var' || $name === 'samp' || $name === 'kbd' || $name === 'tt';
    }

    /**
     * Reports whether a node is a link.
     *
     * @since 0.1.0
     */
    private function nameIsLink(Node $node): bool
    {
        return Dom::nodeName($node) === 'a';
    }

    /**
     * Reports whether both nodes are links.
     *
     * @since 0.1.0
     */
    private function nameIsBothLink(Node $a, Node $b): bool
    {
        return Dom::nodeName($a) === 'a' && Dom::nodeName($b) === 'a';
    }

    /**
     * Reports whether a node is a heading.
     *
     * @since 0.1.0
     */
    private function nameIsHeading(Node $node): bool
    {
        return Tags::nameIsHeading(Dom::nodeName($node));
    }
}
