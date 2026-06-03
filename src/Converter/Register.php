<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Converter;

use Dom\Node;

/**
 * The registration surface a plugin uses to extend the converter.
 *
 * This mirrors upstream's `register` type and is reached through
 * {@see Converter::$register}. Every method forwards to the owning converter;
 * the indirection exists only to keep the plugin-facing vocabulary
 * ("PreRenderer", "Renderer", "RendererFor", …) close to upstream.
 *
 * @since 0.1.0
 */
final class Register
{
    /**
     * @since 0.1.0
     */
    public function __construct(private readonly Converter $converter)
    {
    }

    /**
     * Adds and initializes a plugin.
     *
     * @since 0.1.0
     */
    public function plugin(Plugin $plugin): void
    {
        $this->converter->registerPlugin($plugin);
    }

    /**
     * Registers a DOM-transforming hook that runs before rendering.
     *
     * @since 0.1.0
     *
     * @param callable(Context, Node): void $fn
     */
    public function preRenderer(callable $fn, int $priority): void
    {
        $this->converter->addPreRenderer($fn, $priority);
    }

    /**
     * Registers a render handler tried (in priority order) for every node.
     *
     * @since 0.1.0
     *
     * @param callable(Context, Buffer, Node): RenderStatus $fn
     */
    public function renderer(callable $fn, int $priority): void
    {
        $this->converter->addRenderer($fn, $priority);
    }

    /**
     * Convenience: declare a tag's type and render it with one handler.
     *
     * The escape hatch upstream calls `RendererFor` — it both records the tag
     * type and registers a renderer guarded by the tag name.
     *
     * @since 0.1.0
     *
     * @param callable(Context, Buffer, Node): RenderStatus $renderFn
     */
    public function rendererFor(string $tagName, TagType $tagType, callable $renderFn, int $priority): void
    {
        $this->tagType($tagName, $tagType, $priority);

        $this->renderer(static function (Context $ctx, Buffer $w, Node $node) use ($tagName, $renderFn): RenderStatus {
            if (\Kntnt\HtmlToMarkdown\Dom\Dom::nodeName($node) === $tagName) {
                return $renderFn($ctx, $w, $node);
            }

            return RenderStatus::TryNext;
        }, $priority);
    }

    /**
     * Registers a hook that transforms the whole Markdown output after render.
     *
     * @since 0.1.0
     *
     * @param callable(Context, string): string $fn
     */
    public function postRenderer(callable $fn, int $priority): void
    {
        $this->converter->addPostRenderer($fn, $priority);
    }

    /**
     * Registers a hook that transforms each text node's content.
     *
     * @since 0.1.0
     *
     * @param callable(Context, string): string $fn
     */
    public function textTransformer(callable $fn, int $priority): void
    {
        $this->converter->addTextTransformer($fn, $priority);
    }

    /**
     * Declares characters as Markdown-significant (candidates for escaping).
     *
     * @since 0.1.0
     */
    public function escapedChar(string ...$chars): void
    {
        $this->converter->addEscapedChars($chars);
    }

    /**
     * Registers an un-escaper that decides, per occurrence, whether an escaped
     * character keeps its backslash.
     *
     * @since 0.1.0
     *
     * @param callable(string, int): int $fn
     */
    public function unEscaper(callable $fn, int $priority): void
    {
        $this->converter->addUnEscaper($fn, $priority);
    }

    /**
     * Declares a tag's {@see TagType} at a given priority.
     *
     * @since 0.1.0
     */
    public function tagType(string $tagName, TagType $tagType, int $priority): void
    {
        $this->converter->addTagType($tagName, $tagType, $priority);
    }
}
