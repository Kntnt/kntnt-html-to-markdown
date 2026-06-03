<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Collapse;

use Dom\Comment;
use Dom\Element;
use Dom\Node;
use Dom\Text;
use Kntnt\HtmlToMarkdown\Dom\Dom;

/**
 * Collapses insignificant whitespace in the HTML tree before rendering.
 *
 * This is the most-traveled part of the lineage: the algorithm was adapted
 * by Luc Thevenard (collapse-whitespace), reused by Dom Christie (Turndown),
 * ported to Go by Johannes Kaufmann, and is ported again here to PHP. It
 * walks the tree in document order, squeezing runs of whitespace to a single
 * space and trimming spaces at block boundaries, while leaving preformatted
 * and void elements alone.
 *
 * Block, void and preformatted classification is injectable so the base
 * plugin can defer "is this a block?" to the converter's tag-type registry;
 * the defaults below are the Turndown lists, ported verbatim.
 *
 * @since 0.1.0
 */
final class Collapse
{
    /**
     * Turndown's block-element list (the default block test).
     *
     * @since 0.1.0
     *
     * @var array<string, true>
     */
    private const array BLOCK_ELEMENTS = [
        'address' => true, 'article' => true, 'aside' => true, 'audio' => true,
        'blockquote' => true, 'body' => true, 'canvas' => true, 'center' => true,
        'dd' => true, 'dir' => true, 'div' => true, 'dl' => true, 'dt' => true,
        'fieldset' => true, 'figcaption' => true, 'figure' => true, 'footer' => true,
        'form' => true, 'frameset' => true, 'h1' => true, 'h2' => true, 'h3' => true,
        'h4' => true, 'h5' => true, 'h6' => true, 'header' => true, 'hgroup' => true,
        'hr' => true, 'html' => true, 'isindex' => true, 'li' => true, 'main' => true,
        'menu' => true, 'nav' => true, 'noframes' => true, 'noscript' => true,
        'ol' => true, 'output' => true, 'p' => true, 'pre' => true, 'section' => true,
        'table' => true, 'tbody' => true, 'td' => true, 'tfoot' => true, 'th' => true,
        'thead' => true, 'tr' => true, 'ul' => true,
    ];

    /**
     * Void-element list (the default void test).
     *
     * @since 0.1.0
     *
     * @var array<string, true>
     */
    private const array VOID_ELEMENTS = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true,
        'command' => true, 'embed' => true, 'hr' => true, 'img' => true,
        'input' => true, 'keygen' => true, 'link' => true, 'meta' => true,
        'param' => true, 'track' => true, 'wbr' => true,
    ];

    /**
     * Collapses whitespace in the subtree rooted at the element.
     *
     * @since 0.1.0
     *
     * @param (callable(Node): bool)|null $isBlockNode        Block test (defaults to Turndown's list).
     * @param (callable(Node): bool)|null $isVoidNode         Void test (defaults to Turndown's list).
     * @param (callable(Node): bool)|null $isPreformattedNode Preformatted test (defaults to pre/code).
     */
    public static function collapse(Node $element, ?callable $isBlockNode = null, ?callable $isVoidNode = null, ?callable $isPreformattedNode = null): void
    {
        $isBlockNode ??= static fn (Node $node): bool => isset(self::BLOCK_ELEMENTS[Dom::nodeName($node)]);
        $isVoidNode ??= static fn (Node $node): bool => isset(self::VOID_ELEMENTS[Dom::nodeName($node)]);
        $isPreformattedNode ??= static fn (Node $node): bool => Dom::nodeName($node) === 'pre' || Dom::nodeName($node) === 'code';

        if ($element->firstChild === null || $isPreformattedNode($element)) {
            return;
        }

        $prevText = null;
        $keepLeadingWs = false;
        $prev = null;
        $node = self::nextNode($prev, $element, $isPreformattedNode);

        while ($node !== null && $node !== $element) {
            if ($node instanceof Text) {
                $text = Whitespace::replaceAnyWhitespaceWithSpace($node->data);

                // Drop a leading space when the previous text already ended in
                // one (or there was none) and the space is not protected.
                if (($prevText === null || str_ends_with($prevText->data, ' ')) && !$keepLeadingWs && $text !== '' && $text[0] === ' ') {
                    $text = substr($text, 1);
                }

                if ($text === '') {
                    $node = self::removeNode($node);
                    continue;
                }

                $node->data = $text;
                $prevText = $node;
            } elseif ($node instanceof Element) {
                // A block boundary (or a <br>) ends the current run and trims
                // the trailing space of the text before it.
                if ($isBlockNode($node) || Dom::nodeName($node) === 'br') {
                    if ($prevText !== null) {
                        $prevText->data = self::trimTrailingSpace($prevText->data);
                    }
                    $prevText = null;
                    $keepLeadingWs = false;
                } elseif ($isVoidNode($node) || $isPreformattedNode($node) || Dom::nodeName($node) === 'code') {
                    // Protect space around inline void/preformatted/code nodes.
                    $prevText = null;
                    $keepLeadingWs = true;
                } elseif ($prevText !== null) {
                    $keepLeadingWs = false;
                }
            } elseif (!($node instanceof Comment)) {
                // Anything that is not a comment here (doctype and the like) is
                // dropped. Comments are kept untouched: they neither reset nor
                // protect the surrounding whitespace state.
                $node = self::removeNode($node);
                continue;
            }

            $next = self::nextNode($prev, $node, $isPreformattedNode);
            $prev = $node;
            $node = $next;
        }

        if ($prevText !== null) {
            $prevText->data = self::trimTrailingSpace($prevText->data);
            if ($prevText->data === '') {
                self::removeNode($prevText);
            }
        }
    }

    /**
     * Computes the next node to visit in the walk.
     *
     * Descends into children unless we have just climbed back out of the
     * current node or it is preformatted, in which case we move sideways or up.
     *
     * @since 0.1.0
     *
     * @param callable(Node): bool $isPreformattedNode
     */
    private static function nextNode(?Node $prev, Node $current, callable $isPreformattedNode): ?Node
    {
        if (($prev !== null && $prev->parentNode === $current) || $isPreformattedNode($current)) {
            if ($current->nextSibling !== null) {
                return $current->nextSibling;
            }

            return $current->parentNode;
        }

        if ($current->firstChild !== null) {
            return $current->firstChild;
        }
        if ($current->nextSibling !== null) {
            return $current->nextSibling;
        }

        return $current->parentNode;
    }

    /**
     * Detaches a node and returns where the walk should continue.
     *
     * @since 0.1.0
     */
    private static function removeNode(Node $node): ?Node
    {
        $next = $node->nextSibling ?? $node->parentNode;
        $node->parentNode?->removeChild($node);

        return $next;
    }

    /**
     * Removes a single trailing space, if present.
     *
     * @since 0.1.0
     */
    private static function trimTrailingSpace(string $value): string
    {
        if (str_ends_with($value, ' ')) {
            return substr($value, 0, -1);
        }

        return $value;
    }
}
