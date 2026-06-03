<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Internal\DomUtils;

use Dom\Document;
use Dom\Element;
use Dom\Node;
use Dom\Text;
use Kntnt\HtmlToMarkdown\Dom\Dom;
use Kntnt\HtmlToMarkdown\Dom\Tags;
use Kntnt\HtmlToMarkdown\Internal\TextUtils\TextUtils;
use RuntimeException;

/**
 * DOM rewrites that prepare the tree for clean Markdown rendering.
 *
 * A faithful port of upstream's `internal/domutils`. These transforms run in
 * the pre-render phase: they merge adjacent emphasis, unwrap redundant
 * nesting, swap mis-nested tags, normalize stray list children, demote blocks
 * that cannot live inside inline/leaf contexts, and insert the marker
 * comments that keep consecutive lists apart. Each one mutates the live tree
 * in place.
 *
 * @since 0.1.0
 */
final class DomUtils
{
    /**
     * Data of the synthetic comment inserted between two adjacent lists.
     *
     * @since 0.1.0
     */
    public const string LIST_END_COMMENT_DATA = 'THE END';

    /**
     * Merges directly-adjacent text nodes (e.g. left behind after a removal).
     *
     * @since 0.1.0
     */
    public static function mergeAdjacentTextNodes(?Node $node): void
    {
        if ($node === null) {
            return;
        }

        $prev = null;
        $child = $node->firstChild;
        while ($child !== null) {
            $next = $child->nextSibling;
            if ($child instanceof Text && $prev instanceof Text) {
                $prev->data .= $child->data;
                $node->removeChild($child);
            } else {
                self::mergeAdjacentTextNodes($child);
                $prev = $child;
            }
            $child = $next;
        }
    }

    /**
     * Unwraps nodes nested inside an ancestor of the same logical type.
     *
     * For example `<b><b>x</b></b>` becomes `<b>x</b>`. The match predicate
     * receives the node twice (self-check) and then node/ancestor pairs.
     *
     * @since 0.1.0
     *
     * @param callable(Node, Node): bool $matchFn
     */
    public static function removeRedundant(Node $doc, callable $matchFn): void
    {
        foreach (Dom::allNodes($doc) as $node) {
            if (self::hasSameTypeAncestor($node, $matchFn)) {
                Dom::unwrapNode($node);
            }
        }
    }

    /**
     * Reports whether the node has an ancestor the predicate ties it to.
     *
     * @since 0.1.0
     *
     * @param callable(Node, Node): bool $matchFn
     */
    private static function hasSameTypeAncestor(Node $node, callable $matchFn): bool
    {
        if (!$matchFn($node, $node)) {
            return false;
        }

        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($matchFn($node, $parent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merges the children of adjacent same-type elements into the first one.
     *
     * @since 0.1.0
     *
     * @param callable(Node): bool $matchFn
     */
    public static function mergeAdjacent(Node $doc, callable $matchFn): void
    {
        $node = $doc;
        while ($node !== null) {
            if ($matchFn($node)) {
                self::mergeChildren($node, self::collectAdjacentNodes($node, $matchFn));
            }
            $node = Dom::getNextNeighborElement($node);
        }
    }

    /**
     * Collects the run of matching siblings immediately following a node.
     *
     * @since 0.1.0
     *
     * @param callable(Node): bool $matchFn
     *
     * @return list<Node>
     */
    private static function collectAdjacentNodes(Node $node, callable $matchFn): array
    {
        $collected = [];

        $current = $node->nextSibling;
        while ($current !== null) {
            if (Dom::nodeName($current) === 'span') {
                $current = Dom::getNextNeighborNode($current);
            } elseif ($matchFn($current)) {
                $collected[] = $current;
                $current = Dom::getNextNeighborNodeExcludingOwnChild($current);
            } else {
                return $collected;
            }
        }

        return $collected;
    }

    /**
     * Moves every child of the given nodes into the destination, then drops
     * the now-empty nodes.
     *
     * @since 0.1.0
     *
     * @param list<Node> $nodes
     */
    private static function mergeChildren(Node $destination, array $nodes): void
    {
        foreach ($nodes as $node) {
            foreach (Dom::allChildNodes($node) as $child) {
                Dom::removeNode($child);
                $destination->appendChild($child);
            }
            Dom::removeNode($node);
        }
    }

    /**
     * Removes `<code>` elements that contain no text (e.g. icon-only spans).
     *
     * @since 0.1.0
     */
    public static function removeEmptyCode(Node $doc): void
    {
        $node = $doc;
        while ($node !== null) {
            if (Dom::nodeName($node) === 'code' && !self::hasTextChildNodes($node)) {
                $next = Dom::getNextNeighborNodeExcludingOwnChild($node);
                Dom::removeNode($node);
                $node = $next;
                continue;
            }
            $node = Dom::getNextNeighborNode($node);
        }
    }

    /**
     * Reports whether the subtree contains any non-empty text node.
     *
     * @since 0.1.0
     */
    private static function hasTextChildNodes(Node $startNode): bool
    {
        foreach (Dom::allNodes($startNode) as $node) {
            if ($node instanceof Text && $node->data !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Swaps the tags of an outer node and its single matching inner child.
     *
     * Fixes mis-nesting such as `<code><pre>…</pre></code>` →
     * `<pre><code>…</code></pre>`.
     *
     * @since 0.1.0
     *
     * @param callable(Node): bool $isOuterNode
     * @param callable(Node): bool $isInnerNode
     */
    public static function swapTags(Node $doc, callable $isOuterNode, callable $isInnerNode): void
    {
        $finder = static function (Node $node) use (&$finder, $isOuterNode, $isInnerNode): void {
            if ($isOuterNode($node)) {
                $children = array_values(array_filter(Dom::allChildNodes($node), static fn (Node $child): bool => !self::isEmptyText($child)));

                if (count($children) === 1 && $isInnerNode($children[0]) && $node instanceof Element && $children[0] instanceof Element) {
                    self::swapTagsOfNodes($node, $children[0]);

                    return;
                }
            }

            for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) {
                $finder($child);
            }
        };
        $finder($doc);
    }

    /**
     * Swaps the tag names and attributes of two elements in place, keeping
     * their children.
     *
     * @since 0.1.0
     */
    private static function swapTagsOfNodes(Element $node1, Element $node2): void
    {
        $name1 = $node1->localName;
        $attrs1 = self::attributesOf($node1);
        $name2 = $node2->localName;
        $attrs2 = self::attributesOf($node2);

        Dom::renameElement($node1, $name2);
        self::setAttributes($node1, $attrs2);
        Dom::renameElement($node2, $name1);
        self::setAttributes($node2, $attrs1);
    }

    /**
     * Reports whether a node is a whitespace-only text node.
     *
     * @since 0.1.0
     */
    private static function isEmptyText(Node $node): bool
    {
        return $node instanceof Text && TextUtils::trimSpace($node->data) === '';
    }

    /**
     * Adds a space inside the text surrounding an outer node when its first or
     * last meaningful child is an inner node (e.g. bold wrapping inline code).
     *
     * @since 0.1.0
     *
     * @param callable(Node): bool $isOuterNode
     * @param callable(Node): bool $isInnerNode
     */
    public static function addSpace(Node $doc, callable $isOuterNode, callable $isInnerNode): void
    {
        $node = $doc;
        while ($node !== null) {
            if ($isOuterNode($node)) {
                if (self::getFirstChildNode($node, $isInnerNode) !== null) {
                    $prev = self::getPrevTextNode($node);
                    if ($prev instanceof Text) {
                        $prev->data .= ' ';
                    }
                }

                if (self::getLastChildNode($node, $isInnerNode) !== null) {
                    $next = self::getNextTextNode($node);
                    if ($next instanceof Text) {
                        $next->data = ' ' . $next->data;
                    }
                }
            }

            $node = Dom::getNextNeighborElement($node);
        }
    }

    /**
     * Returns the first meaningful child matching the predicate, skipping
     * spans; null when the first child neither matches nor is a span.
     *
     * @since 0.1.0
     *
     * @param callable(Node): bool $matchFn
     */
    private static function getFirstChildNode(Node $startNode, callable $matchFn): ?Node
    {
        $node = $startNode->firstChild;
        while ($node !== null) {
            if (Dom::nodeName($node) === 'span') {
                $node = Dom::getNextNeighborNode($node);
            } elseif ($matchFn($node)) {
                return $node;
            } else {
                return null;
            }
        }

        return null;
    }

    /**
     * Mirror of {@see DomUtils::getFirstChildNode()} from the last child.
     *
     * @since 0.1.0
     *
     * @param callable(Node): bool $matchFn
     */
    private static function getLastChildNode(Node $startNode, callable $matchFn): ?Node
    {
        $node = $startNode->lastChild;
        while ($node !== null) {
            if (Dom::nodeName($node) === 'span') {
                $node = Dom::getPrevNeighborNode($node);
            } elseif ($matchFn($node)) {
                return $node;
            } else {
                return null;
            }
        }

        return null;
    }

    /**
     * Finds the next text node after a node, skipping spans.
     *
     * @since 0.1.0
     */
    private static function getNextTextNode(Node $startNode): ?Node
    {
        $node = Dom::getNextNeighborNodeExcludingOwnChild($startNode);
        while ($node !== null) {
            if ($node instanceof Text) {
                return $node;
            }
            if (Dom::nodeName($node) === 'span') {
                $node = Dom::getNextNeighborNode($node);
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * Finds the previous text node before a node, skipping spans.
     *
     * @since 0.1.0
     */
    private static function getPrevTextNode(Node $startNode): ?Node
    {
        $node = Dom::getPrevNeighborNodeExcludingOwnChild($startNode);
        while ($node !== null) {
            if ($node instanceof Text) {
                return $node;
            }
            if (Dom::nodeName($node) === 'span') {
                $node = Dom::getPrevNeighborNode($node);
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * Renames `<span>` wrappers that actually contain block content to `<div>`.
     *
     * @since 0.1.0
     */
    public static function renameFakeSpans(Node $doc): void
    {
        $finder = static function (Node $node) use (&$finder): void {
            if (self::isFakeSpan($node) && $node instanceof Element) {
                Dom::renameElement($node, 'div');
            }

            for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) {
                $finder($child);
            }
        };
        $finder($doc);
    }

    /**
     * Reports whether a span contains any block-level descendant.
     *
     * @since 0.1.0
     */
    private static function isFakeSpan(Node $node): bool
    {
        if (Dom::nodeName($node) !== 'span') {
            return false;
        }

        foreach (Dom::allNodes($node) as $descendant) {
            if (Tags::nameIsBlock(Dom::nodeName($descendant))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replaces blocks that cannot legally nest inside inline or leaf-block
     * contexts with inline alternatives (e.g. a heading inside a link becomes
     * bold text).
     *
     * @since 0.1.0
     */
    public static function leafBlockAlternatives(Node $doc): void
    {
        $finder = static function (Node $node, bool $isInsideLeafBlock, bool $isInsideInline) use (&$finder): void {
            $name = Dom::nodeName($node);
            $structure = self::markdownStructure($name);

            if (($structure === 'container_block' || $structure === 'leaf_block') && ($isInsideLeafBlock || $isInsideInline)) {
                if ($node instanceof Element) {
                    self::applyAlternative($node, $name);
                }
            }

            if ($structure === 'leaf_block') {
                $isInsideLeafBlock = true;
            }
            if ($structure === 'inline') {
                $isInsideInline = true;
            }

            // Children are visited in reverse to mirror upstream's deferred
            // traversal, where later siblings are processed first.
            foreach (array_reverse(Dom::allChildNodes($node)) as $child) {
                $finder($child, $isInsideLeafBlock, $isInsideInline);
            }
        };
        $finder($doc, false, false);
    }

    /**
     * Classifies a tag for the leaf-block-alternatives traversal.
     *
     * @since 0.1.0
     */
    private static function markdownStructure(string $name): string
    {
        return match ($name) {
            '#document', 'html', 'head', 'body', 'blockquote', 'ul', 'ol', 'li' => 'container_block',
            'hr', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => 'leaf_block',
            '#text', 'span', 'code', 'b', 'strong', 'i', 'em', 'a', 'img', 'br' => 'inline',
            default => '',
        };
    }

    /**
     * Applies the inline alternative for a mis-placed block element.
     *
     * @since 0.1.0
     */
    private static function applyAlternative(Element $node, string $name): void
    {
        if (Tags::nameIsHeading($name)) {
            self::headingAlternative($node);

            return;
        }

        match ($name) {
            'blockquote' => self::blockquoteAlternative($node),
            'pre' => Dom::renameElement($node, 'code'),
            'hr' => Dom::removeNode($node),
            default => Dom::renameElement($node, 'span'),
        };
    }

    /**
     * Turns a heading into bold text followed by a line break.
     *
     * @since 0.1.0
     */
    private static function headingAlternative(Element $node): void
    {
        Dom::renameElement($node, 'strong');

        $break = self::documentOf($node)->createElement('br');
        $node->parentNode?->insertBefore($break, $node->nextSibling);
    }

    /**
     * Turns a blockquote into quoted inline text.
     *
     * @since 0.1.0
     */
    private static function blockquoteAlternative(Element $node): void
    {
        $parent = $node->parentNode;
        if ($parent === null) {
            return;
        }

        $before = self::documentOf($node)->createTextNode(' "');
        $parent->insertBefore($before, $node);

        Dom::renameElement($node, 'span');

        $after = self::documentOf($node)->createTextNode('" ');
        $parent->insertBefore($after, $node->nextSibling);
    }

    /**
     * Moves stray non-`<li>` children of a list into the preceding list item,
     * wrapping leading strays in a fresh `<li>`.
     *
     * @since 0.1.0
     */
    public static function moveListItems(Node $node): void
    {
        if ($node instanceof Element && ($node->localName === 'ol' || $node->localName === 'ul')) {
            $previousLi = null;

            foreach (Dom::allChildNodes($node) as $child) {
                if ($child instanceof Element && $child->localName === 'li') {
                    $previousLi = $child;
                } elseif ($child instanceof Text && TextUtils::trimSpace($child->data) === '') {
                    // Whitespace between items (e.g. source indentation): skip.
                    continue;
                } elseif ($previousLi !== null) {
                    $node->removeChild($child);
                    $previousLi->appendChild($child);
                } else {
                    $newLi = self::documentOf($node)->createElement('li');
                    $wrapped = Dom::wrapNode($child, $newLi);
                    $previousLi = $wrapped instanceof Element ? $wrapped : null;
                }
            }
        }

        for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) {
            self::moveListItems($child);
        }
    }

    /**
     * Inserts a marker comment between two consecutive lists so Markdown does
     * not merge them into a single list.
     *
     * @since 0.1.0
     */
    public static function addListEndComments(Node $doc): void
    {
        $node = $doc;
        while ($node !== null) {
            if (self::nameIsList($node) && self::nextNameIsList($node)) {
                self::insertListEndComment($node);
            }
            $node = Dom::getNextNeighborElement($node);
        }
    }

    /**
     * Reports whether a node is a `<ul>` or `<ol>`.
     *
     * @since 0.1.0
     */
    private static function nameIsList(Node $node): bool
    {
        $name = Dom::nodeName($node);

        return $name === 'ul' || $name === 'ol';
    }

    /**
     * Inserts the synthetic "list end" comment right after a list.
     *
     * @since 0.1.0
     */
    private static function insertListEndComment(Node $listNode): void
    {
        $comment = self::documentOf($listNode)->createComment(self::LIST_END_COMMENT_DATA);
        $listNode->parentNode?->insertBefore($comment, $listNode->nextSibling);
    }

    /**
     * Reports whether the next meaningful sibling content is another list.
     *
     * @since 0.1.0
     */
    private static function nextNameIsList(Node $startNode): bool
    {
        $node = Dom::getNextNeighborNodeExcludingOwnChild($startNode);
        while ($node !== null) {
            $name = Dom::nodeName($node);
            if ($name === 'ul' || $name === 'ol') {
                return true;
            }
            if ($name === 'li') {
                return false;
            }
            if ($name === '#comment' && $node->textContent === self::LIST_END_COMMENT_DATA) {
                return false;
            }
            if ($node instanceof Text) {
                return false;
            }
            if ($name === 'hr') {
                return false;
            }

            $node = Dom::getNextNeighborNode($node);
        }

        return false;
    }

    /**
     * Snapshots an element's attributes as name/value pairs.
     *
     * @since 0.1.0
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function attributesOf(Element $element): array
    {
        $attributes = [];
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $attributes[] = [$attribute->name, $attribute->value];
        }

        return $attributes;
    }

    /**
     * Replaces all of an element's attributes with the given pairs.
     *
     * @since 0.1.0
     *
     * @param list<array{0: string, 1: string}> $attributes
     */
    private static function setAttributes(Element $element, array $attributes): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $element->removeAttribute($attribute->name);
        }
        foreach ($attributes as [$name, $value]) {
            $element->setAttribute($name, $value);
        }
    }

    /**
     * Returns the document a node belongs to.
     *
     * Every node these transforms create a sibling/child for is attached to a
     * document, so a missing owner would be a programming error rather than a
     * recoverable condition.
     *
     * @since 0.1.0
     */
    private static function documentOf(Node $node): Document
    {
        return $node->ownerDocument ?? throw new RuntimeException('expected the node to belong to a document');
    }
}
