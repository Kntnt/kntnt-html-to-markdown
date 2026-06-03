<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Converter;

use Dom\Node;

/**
 * The per-conversion context handed to every renderer and hook.
 *
 * A context bundles the live converter with the current convert-time domain
 * and an immutable bag of values. Renderers descend into children through it
 * ({@see Context::renderChildNodes()}), look up tag types, resolve URLs, and
 * escape text — all without touching the converter directly.
 *
 * Values added with {@see Context::withValue()} flow only to the contexts a
 * renderer passes onward, exactly like Go's `context.WithValue`. That is how
 * "am I inside a link?" propagates to the text-transform step without leaking
 * to sibling subtrees.
 *
 * @since 0.1.0
 */
final class Context
{
    /**
     * @since 0.1.0
     *
     * @param array<string, mixed> $values Immutable per-context value bag.
     */
    public function __construct(
        private readonly Converter $converter,
        private readonly string $domain,
        private readonly array $values = [],
    ) {
    }

    /**
     * Renders a list of nodes into the buffer, in order.
     *
     * @since 0.1.0
     */
    public function renderNodes(Context $ctx, Buffer $w, Node ...$nodes): void
    {
        $this->converter->handleRenderNodes($ctx, $w, ...$nodes);
    }

    /**
     * Renders all child nodes of a node into the buffer.
     *
     * @since 0.1.0
     */
    public function renderChildNodes(Context $ctx, Buffer $w, Node $node): void
    {
        $this->converter->handleRenderNodes($ctx, $w, ...$this->converter->childNodesToRender($node));
    }

    /**
     * Resolves the effective tag type for a tag name, or null when unknown.
     *
     * @since 0.1.0
     */
    public function getTagType(string $tagName): ?TagType
    {
        return $this->converter->getTagType($tagName);
    }

    /**
     * Turns a (possibly relative) URL into an absolute one using the domain.
     *
     * @since 0.1.0
     */
    public function assembleAbsoluteUrl(Context $ctx, string $tagName, string $rawUrl): string
    {
        return UrlResolver::assembleAbsoluteUrl($tagName, $rawUrl, $this->domain);
    }

    /**
     * Escapes Markdown-significant characters in the given bytes.
     *
     * @since 0.1.0
     */
    public function escapeContent(string $content): string
    {
        return $this->converter->escapeContent($content);
    }

    /**
     * Resolves escaping sentinels in the given bytes (the inverse pass).
     *
     * @since 0.1.0
     */
    public function unEscapeContent(string $content): string
    {
        return $this->converter->unEscapeContent($content);
    }

    /**
     * Returns a derived context carrying an extra value.
     *
     * @since 0.1.0
     */
    public function withValue(string $key, mixed $value): Context
    {
        return new Context($this->converter, $this->domain, [...$this->values, $key => $value]);
    }

    /**
     * Reads a context value, or null when absent.
     *
     * @since 0.1.0
     */
    public function value(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }
}
