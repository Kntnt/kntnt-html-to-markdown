<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Dom;

use Dom\Comment;
use Dom\Document;
use Dom\DocumentType;
use Dom\Element;
use Dom\Node;
use Dom\Text;

/**
 * Static helpers for navigating and mutating the parsed HTML tree.
 *
 * This is a faithful port of the upstream `github.com/JohannesKaufmann/dom`
 * package, adapted from Go's `*html.Node` model (FirstChild / NextSibling /
 * Parent pointers) to PHP's live `Dom\Node` tree. Node *names* follow the
 * goquery convention upstream relies on — "#text", "#comment", "#document",
 * and lowercase element tag names — which is why every name lookup goes
 * through {@see Dom::nodeName()} rather than the uppercase `nodeName`
 * property of the DOM.
 *
 * @since 0.1.0
 */
final class Dom
{
    /**
     * The HTML namespace URI, required when renaming an element in place.
     *
     * PHP ties HTML elements to this namespace; renaming out of it throws.
     *
     * @since 0.1.0
     */
    public const string HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    /**
     * Returns the goquery-style name of a node.
     *
     * Mirrors upstream `dom.NodeName`: text, comment and document nodes get
     * their "#"-prefixed pseudo-names; a doctype reports its declared name;
     * an element reports its lowercase local name. Anything else (and null)
     * is the empty string.
     *
     * @since 0.1.0
     */
    public static function nodeName(?Node $node): string
    {
        if ($node === null) {
            return '';
        }

        return match (true) {
            $node instanceof Text => '#text',
            $node instanceof Comment => '#comment',
            $node instanceof Document => '#document',
            $node instanceof DocumentType => $node->name,
            $node instanceof Element => $node->localName,
            default => '',
        };
    }

    /**
     * Recursively collects every node in the subtree, including the start node.
     *
     * @since 0.1.0
     *
     * @return list<Node>
     */
    public static function allNodes(Node $startNode): array
    {
        $allNodes = [];

        $finder = static function (Node $node) use (&$finder, &$allNodes): void {
            $allNodes[] = $node;
            for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) {
                $finder($child);
            }
        };
        $finder($startNode);

        return $allNodes;
    }

    /**
     * Snapshots the direct child nodes into an array.
     *
     * The snapshot lets callers mutate the tree while iterating, exactly as
     * upstream's slice-returning `AllChildNodes` does.
     *
     * @since 0.1.0
     *
     * @return list<Node>
     */
    public static function allChildNodes(Node $node): array
    {
        $children = [];
        for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) {
            $children[] = $child;
        }

        return $children;
    }

    /**
     * Returns the first child that is an element, or null.
     *
     * @since 0.1.0
     */
    public static function firstChildElement(Node $node): ?Element
    {
        for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) {
            if ($child instanceof Element) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Returns the next sibling that is an element, or null.
     *
     * @since 0.1.0
     */
    public static function nextSiblingElement(Node $node): ?Element
    {
        for ($sibling = $node->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
            if ($sibling instanceof Element) {
                return $sibling;
            }
        }

        return null;
    }

    /**
     * Reads an attribute, returning the value and whether it was present.
     *
     * @since 0.1.0
     *
     * @return array{0: string, 1: bool}
     */
    public static function getAttribute(Node $node, string $key): array
    {
        if ($node instanceof Element && $node->hasAttribute($key)) {
            return [$node->getAttribute($key) ?? '', true];
        }

        return ['', false];
    }

    /**
     * Reads an attribute, falling back to a default when absent.
     *
     * @since 0.1.0
     */
    public static function getAttributeOr(Node $node, string $key, string $fallback): string
    {
        [$value, $found] = self::getAttribute($node, $key);

        return $found ? $value : $fallback;
    }

    /**
     * Finds the first descendant matching the predicate (document order).
     *
     * Traversal never climbs above the start node.
     *
     * @since 0.1.0
     *
     * @param callable(Node): bool $matchFn
     */
    public static function findFirstNode(Node $startNode, callable $matchFn): ?Node
    {
        $next = self::makeNeighborWalker(
            static fn (Node $n): ?Node => $n->firstChild,
            static fn (Node $n): ?Node => $n->nextSibling,
            static fn (Node $n): bool => $n === $startNode,
        );

        $child = $startNode->firstChild;
        while ($child !== null) {
            if ($matchFn($child)) {
                return $child;
            }
            $child = $next($child);
        }

        return null;
    }

    /**
     * Finds every descendant matching the predicate (document order).
     *
     * @since 0.1.0
     *
     * @param callable(Node): bool $matchFn
     *
     * @return list<Node>
     */
    public static function findAllNodes(Node $startNode, callable $matchFn): array
    {
        $next = self::makeNeighborWalker(
            static fn (Node $n): ?Node => $n->firstChild,
            static fn (Node $n): ?Node => $n->nextSibling,
            static fn (Node $n): bool => $n === $startNode,
        );

        $found = [];
        $child = $startNode->firstChild;
        while ($child !== null) {
            if ($matchFn($child)) {
                $found[] = $child;
            }
            $child = $next($child);
        }

        return $found;
    }

    /**
     * Builds a "next neighbor" walker from child / sibling / stop callbacks.
     *
     * Ports upstream's `UNSTABLE_initGetNeighbor`: descend into the first
     * child, otherwise take the sibling, otherwise climb until an ancestor
     * has a sibling — never passing the stop predicate.
     *
     * @since 0.1.0
     *
     * @param callable(Node): ?Node $firstChildFn
     * @param callable(Node): ?Node $siblingFn
     * @param callable(Node): bool  $goUpUntilFn
     *
     * @return callable(Node): ?Node
     */
    private static function makeNeighborWalker(callable $firstChildFn, callable $siblingFn, callable $goUpUntilFn): callable
    {
        return static function (Node $node) use ($firstChildFn, $siblingFn, $goUpUntilFn): ?Node {
            $child = $firstChildFn($node);
            if ($child !== null) {
                return $child;
            }

            $sibling = $siblingFn($node);
            if ($sibling !== null) {
                return $sibling;
            }

            while (true) {
                $node = $node->parentNode;
                if ($node === null) {
                    return null;
                }
                if ($goUpUntilFn($node)) {
                    return null;
                }
                $sibling = $siblingFn($node);
                if ($sibling !== null) {
                    return $sibling;
                }
            }
        };
    }

    /**
     * Document-order next node (descending into children first).
     *
     * @since 0.1.0
     */
    public static function getNextNeighborNode(Node $node): ?Node
    {
        return self::makeNeighborWalker(
            static fn (Node $n): ?Node => $n->firstChild,
            static fn (Node $n): ?Node => $n->nextSibling,
            self::neverStop(),
        )($node);
    }

    /**
     * Document-order next element (descending into element children first).
     *
     * @since 0.1.0
     */
    public static function getNextNeighborElement(Node $node): ?Node
    {
        return self::makeNeighborWalker(
            static fn (Node $n): ?Node => self::firstChildElement($n),
            static fn (Node $n): ?Node => self::nextSiblingElement($n),
            self::neverStop(),
        )($node);
    }

    /**
     * Document-order next node, skipping the node's own children.
     *
     * @since 0.1.0
     */
    public static function getNextNeighborNodeExcludingOwnChild(Node $node): ?Node
    {
        return self::makeNeighborWalker(
            static fn (Node $n): ?Node => null,
            static fn (Node $n): ?Node => $n->nextSibling,
            self::neverStop(),
        )($node);
    }

    /**
     * Reverse document-order previous node.
     *
     * @since 0.1.0
     */
    public static function getPrevNeighborNode(Node $node): ?Node
    {
        return self::makeNeighborWalker(
            static fn (Node $n): ?Node => $n->firstChild,
            static fn (Node $n): ?Node => $n->previousSibling,
            self::neverStop(),
        )($node);
    }

    /**
     * Reverse document-order previous node, skipping the node's own children.
     *
     * @since 0.1.0
     */
    public static function getPrevNeighborNodeExcludingOwnChild(Node $node): ?Node
    {
        return self::makeNeighborWalker(
            static fn (Node $n): ?Node => null,
            static fn (Node $n): ?Node => $n->previousSibling,
            self::neverStop(),
        )($node);
    }

    /**
     * The "never climb-stop" predicate shared by the unbounded walkers.
     *
     * @since 0.1.0
     *
     * @return callable(Node): bool
     */
    private static function neverStop(): callable
    {
        return static fn (Node $n): bool => false;
    }

    /**
     * Detaches a node from its parent. A no-op when already detached.
     *
     * @since 0.1.0
     */
    public static function removeNode(?Node $node): void
    {
        if ($node === null || $node->parentNode === null) {
            return;
        }
        $node->parentNode->removeChild($node);
    }

    /**
     * Replaces all of a node's children with the node, then drops the node.
     *
     * Ports `UnwrapNode`: every child is lifted to the node's position, in
     * order, and the now-empty node is removed.
     *
     * @since 0.1.0
     */
    public static function unwrapNode(?Node $node): void
    {
        if ($node === null || $node->parentNode === null) {
            return;
        }

        $parent = $node->parentNode;
        for ($child = $node->firstChild; $child !== null; $child = $node->firstChild) {
            $node->removeChild($child);
            $parent->insertBefore($child, $node);
        }
        $parent->removeChild($node);
    }

    /**
     * Wraps an existing node inside a fresh node, returning the wrapper.
     *
     * @since 0.1.0
     */
    public static function wrapNode(Node $existingNode, Node $newNode): Node
    {
        if ($existingNode->parentNode === null) {
            return $existingNode;
        }

        $parent = $existingNode->parentNode;
        $parent->insertBefore($newNode, $existingNode);
        $parent->removeChild($existingNode);
        $newNode->appendChild($existingNode);

        return $newNode;
    }

    /**
     * Renames an element in place, preserving its children, attributes and
     * identity.
     *
     * This is the PHP analogue of upstream's `node.Data = "name"`. Go can flip
     * a tag name by assignment because its node is a plain struct; PHP exposes
     * the same effect through {@see Element::rename()}, which keeps the very
     * same object — so references held elsewhere stay valid.
     *
     * @since 0.1.0
     */
    public static function renameElement(Element $node, string $name): void
    {
        $node->rename(self::HTML_NAMESPACE, $name);
    }
}
