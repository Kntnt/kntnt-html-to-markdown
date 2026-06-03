<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Converter;

use Dom\Document;
use Dom\DocumentFragment;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Kntnt\HtmlToMarkdown\Dom\Dom;
use Kntnt\HtmlToMarkdown\Dom\Tags;
use Kntnt\HtmlToMarkdown\Exception\ConversionException;
use Kntnt\HtmlToMarkdown\Marker\Marker;
use Throwable;

/**
 * The conversion engine: a priority-ordered registry plus a render loop.
 *
 * The converter is a faithful port of upstream's `converter` package. It owns
 * the registry of pre-render hooks, render handlers, post-render hooks, text
 * transformers, escaping rules and tag types; plugins fill that registry in
 * via {@see Converter::$register}. Conversion then runs in three phases:
 * transform the DOM (pre-render), walk it to Markdown (render), and clean up
 * the text (post-render).
 *
 * The converter is empty by itself — at minimum the `base` and `commonmark`
 * plugins must be registered, or conversion throws.
 *
 * @since 0.1.0
 *
 * @phpstan-type PreRenderFn callable(Context, Node): void
 * @phpstan-type RenderFn callable(Context, Buffer, Node): RenderStatus
 * @phpstan-type PostRenderFn callable(Context, string): string
 * @phpstan-type TextTransformFn callable(Context, string): string
 * @phpstan-type UnEscapeFn callable(string, int): int
 */
final class Converter
{
    /**
     * The plugin-facing registration surface.
     *
     * @since 0.1.0
     */
    public readonly Register $register;

    /**
     * The first plugin-initialization failure, re-raised on convert.
     *
     * @since 0.1.0
     */
    private ?Throwable $error = null;

    /**
     * Names of the registered plugins, in registration order.
     *
     * @since 0.1.0
     *
     * @var list<string>
     */
    private array $registeredPlugins = [];

    /**
     * Monotonic counter that makes equal-priority ordering deterministic.
     *
     * @since 0.1.0
     */
    private int $sequence = 0;

    /**
     * @since 0.1.0
     *
     * @var list<array{priority: int, seq: int, fn: PreRenderFn}>
     */
    private array $preRenderHandlers = [];

    /**
     * @since 0.1.0
     *
     * @var list<array{priority: int, seq: int, fn: RenderFn}>
     */
    private array $renderHandlers = [];

    /**
     * @since 0.1.0
     *
     * @var list<array{priority: int, seq: int, fn: PostRenderFn}>
     */
    private array $postRenderHandlers = [];

    /**
     * @since 0.1.0
     *
     * @var list<array{priority: int, seq: int, fn: TextTransformFn}>
     */
    private array $textTransformHandlers = [];

    /**
     * @since 0.1.0
     *
     * @var list<array{priority: int, seq: int, fn: UnEscapeFn}>
     */
    private array $unEscapeHandlers = [];

    /**
     * Set of Markdown-significant characters, keyed by the character.
     *
     * @since 0.1.0
     *
     * @var array<string, true>
     */
    private array $markdownChars = [];

    /**
     * Registered tag types, keyed by tag name.
     *
     * @since 0.1.0
     *
     * @var array<string, list<array{priority: int, seq: int, type: TagType}>>
     */
    private array $tagTypes = [];

    /**
     * Builds a converter and initializes the given plugins.
     *
     * @since 0.1.0
     *
     * @param list<Plugin> $plugins
     */
    public function __construct(array $plugins = [], private readonly EscapeMode $escapeMode = EscapeMode::Smart)
    {
        $this->register = new Register($this);

        foreach ($plugins as $plugin) {
            $this->registerPlugin($plugin);
        }
    }

    /**
     * Converts an HTML string to Markdown.
     *
     * @since 0.1.0
     *
     * @throws \Kntnt\HtmlToMarkdown\Exception\HtmlToMarkdownException
     */
    public function convertString(string $html, ?Options $options = null): string
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');

        return $this->convertNode($document, $options);
    }

    /**
     * Converts an already-parsed DOM node (document or element) to Markdown.
     *
     * @since 0.1.0
     *
     * @throws \Kntnt\HtmlToMarkdown\Exception\HtmlToMarkdownException
     */
    public function convertNode(Node $document, ?Options $options = null): string
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        $options ??= new Options();

        // The commonmark plugin needs render handlers and the base plugin;
        // missing either is almost always a setup mistake, so fail loudly.
        if ($this->renderHandlers === []) {
            throw new ConversionException('no render handlers are registered. did you forget to register the "commonmark" and "base" plugins?');
        }
        if (in_array('commonmark', $this->registeredPlugins, true) && !in_array('base', $this->registeredPlugins, true)) {
            throw new ConversionException('you registered the "commonmark" plugin but the "base" plugin is also required');
        }

        $root = $this->applySelectors($document, $options);
        $ctx = new Context($this, $options->domain);

        // Pre-render: let hooks rewrite the DOM (remove nodes, collapse
        // whitespace, merge adjacent elements, …).
        foreach ($this->sortedPreRenderHandlers() as $handler) {
            $handler($ctx, $root);
        }

        // Render: walk the tree into a buffer.
        $buffer = new Buffer();
        $this->handleRenderNode($ctx, $buffer, $root);

        // Post-render: clean up the assembled Markdown text.
        $result = $buffer->bytes();
        foreach ($this->sortedPostRenderHandlers() as $handler) {
            $result = $handler($ctx, $result);
        }

        return $result;
    }

    /**
     * Applies the include/exclude selectors, returning the root to render.
     *
     * Include moves the matching elements into a fresh fragment; exclude
     * strips matching elements from the tree. Both are no-ops unless their
     * selector is set and the document supports CSS queries.
     *
     * @since 0.1.0
     */
    private function applySelectors(Node $document, Options $options): Node
    {
        $root = $document;

        if ($options->includeSelector !== null && ($document instanceof Document || $document instanceof Element)) {
            $owner = $document instanceof Document ? $document : $document->ownerDocument;
            if ($owner !== null) {
                $fragment = $owner->createDocumentFragment();
                foreach (iterator_to_array($document->querySelectorAll($options->includeSelector)) as $node) {
                    $fragment->appendChild($node);
                }
                $root = $fragment;
            }
        }

        if ($options->excludeSelector !== null && ($root instanceof Document || $root instanceof Element || $root instanceof DocumentFragment)) {
            foreach (iterator_to_array($root->querySelectorAll($options->excludeSelector)) as $node) {
                Dom::removeNode($node);
            }
        }

        return $root;
    }

    /**
     * Initializes a plugin, capturing any failure for the first conversion.
     *
     * @since 0.1.0
     */
    public function registerPlugin(Plugin $plugin): void
    {
        $name = $plugin->name();
        if ($name === '') {
            $this->error = new ConversionException('the plugin has no name');

            return;
        }

        $this->registeredPlugins[] = $name;

        try {
            $plugin->init($this);
        } catch (Throwable $exception) {
            $this->error = new ConversionException(
                sprintf('error while initializing "%s" plugin: %s', $name, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    /**
     * Registers a pre-render hook.
     *
     * @since 0.1.0
     *
     * @param PreRenderFn $fn
     */
    public function addPreRenderer(callable $fn, int $priority): void
    {
        $this->preRenderHandlers[] = ['priority' => $priority, 'seq' => $this->sequence++, 'fn' => $fn];
    }

    /**
     * Registers a render handler.
     *
     * @since 0.1.0
     *
     * @param RenderFn $fn
     */
    public function addRenderer(callable $fn, int $priority): void
    {
        $this->renderHandlers[] = ['priority' => $priority, 'seq' => $this->sequence++, 'fn' => $fn];
    }

    /**
     * Registers a post-render hook.
     *
     * @since 0.1.0
     *
     * @param PostRenderFn $fn
     */
    public function addPostRenderer(callable $fn, int $priority): void
    {
        $this->postRenderHandlers[] = ['priority' => $priority, 'seq' => $this->sequence++, 'fn' => $fn];
    }

    /**
     * Registers a text-transform hook.
     *
     * @since 0.1.0
     *
     * @param TextTransformFn $fn
     */
    public function addTextTransformer(callable $fn, int $priority): void
    {
        $this->textTransformHandlers[] = ['priority' => $priority, 'seq' => $this->sequence++, 'fn' => $fn];
    }

    /**
     * Registers an un-escaper.
     *
     * @since 0.1.0
     *
     * @param UnEscapeFn $fn
     */
    public function addUnEscaper(callable $fn, int $priority): void
    {
        $this->unEscapeHandlers[] = ['priority' => $priority, 'seq' => $this->sequence++, 'fn' => $fn];
    }

    /**
     * Declares one or more characters as Markdown-significant.
     *
     * @since 0.1.0
     *
     * @param array<array-key, string> $chars
     */
    public function addEscapedChars(array $chars): void
    {
        foreach ($chars as $char) {
            $this->markdownChars[$char] = true;
        }
    }

    /**
     * Declares a tag's type at a given priority.
     *
     * @since 0.1.0
     */
    public function addTagType(string $tagName, TagType $tagType, int $priority): void
    {
        $this->tagTypes[$tagName][] = ['priority' => $priority, 'seq' => $this->sequence++, 'type' => $tagType];
    }

    /**
     * Resolves the effective tag type for a name, or null when unknown.
     *
     * An explicit registration (lowest priority wins) takes precedence;
     * otherwise the built-in block/inline classification answers.
     *
     * @since 0.1.0
     */
    public function getTagType(string $tagName): ?TagType
    {
        $types = $this->tagTypes[$tagName] ?? [];

        if ($types === []) {
            return match (true) {
                Tags::nameIsBlock($tagName) => TagType::Block,
                Tags::nameIsInline($tagName) => TagType::Inline,
                default => null,
            };
        }

        usort($types, static fn (array $a, array $b): int => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]);

        return $types[0]['type'];
    }

    /**
     * Snapshots a node's children for rendering.
     *
     * @since 0.1.0
     *
     * @return list<Node>
     */
    public function childNodesToRender(Node $node): array
    {
        return Dom::allChildNodes($node);
    }

    /**
     * Renders a sequence of nodes into the buffer.
     *
     * @since 0.1.0
     */
    public function handleRenderNodes(Context $ctx, Buffer $w, Node ...$nodes): void
    {
        foreach ($nodes as $node) {
            $this->handleRenderNode($ctx, $w, $node);
        }
    }

    /**
     * Renders a single node: text fast-path, then handlers, then fallback.
     *
     * @since 0.1.0
     */
    private function handleRenderNode(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        $name = Dom::nodeName($node);

        if ($name === '#text') {
            return $this->handleRenderText($ctx, $w, $node);
        }

        foreach ($this->sortedRenderHandlers() as $handler) {
            if ($handler($ctx, $w, $node) === RenderStatus::Success) {
                return RenderStatus::Success;
            }
        }

        return $this->handleRenderFallback($ctx, $w, $node);
    }

    /**
     * The default rendering: block nodes get blank lines, then children.
     *
     * @since 0.1.0
     */
    private function handleRenderFallback(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        $tagType = $this->getTagType(Dom::nodeName($node));

        if ($tagType === TagType::Block) {
            $w->write("\n\n");
        }
        $ctx->renderChildNodes($ctx, $w, $node);
        if ($tagType === TagType::Block) {
            $w->write("\n\n");
        }

        return RenderStatus::Success;
    }

    /**
     * Renders a text node through every text-transform hook.
     *
     * @since 0.1.0
     */
    private function handleRenderText(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        $content = $node->textContent ?? '';
        foreach ($this->sortedTextTransformHandlers() as $handler) {
            $content = $handler($ctx, $content);
        }
        $w->write($content);

        return RenderStatus::Success;
    }

    /**
     * Escapes Markdown-significant characters with the escaping sentinel.
     *
     * Each significant character is prefixed with the bell sentinel; the
     * NUL character is replaced with U+FFFD for safety. The decision to keep
     * or drop each sentinel is deferred to {@see Converter::unEscapeContent()}.
     *
     * @since 0.1.0
     */
    public function escapeContent(string $chars): string
    {
        if ($this->escapeMode === EscapeMode::Disabled) {
            return $chars;
        }

        $result = '';
        $length = strlen($chars);
        for ($i = 0; $i < $length; $i++) {
            $char = $chars[$i];

            if ($char === "\x00") {
                $result .= "\u{FFFD}";
                continue;
            }

            if (isset($this->markdownChars[$char])) {
                $result .= Marker::ESCAPING . $char;
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * Resolves the escaping sentinels: each becomes a backslash when an
     * un-escaper says the following text would otherwise be misread, and is
     * dropped otherwise.
     *
     * @since 0.1.0
     */
    public function unEscapeContent(string $chars): string
    {
        if ($this->escapeMode === EscapeMode::Disabled) {
            return $chars;
        }

        $placeholder = Marker::ESCAPING;
        $length = strlen($chars);

        // First pass: mark which sentinels must become a backslash.
        $escapeAt = [];
        for ($i = 0; $i < $length; $i++) {
            if ($chars[$i] !== $placeholder) {
                continue;
            }
            if ($i + 1 >= $length) {
                break;
            }

            $skip = $this->checkUnEscapeElements($chars, $i + 1);
            if ($skip === -1) {
                continue;
            }
            $escapeAt[$i] = true;
            $i += $skip - 1;
        }

        // Second pass: drop every sentinel, emitting a backslash where marked.
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $char = $chars[$i];
            if ($char !== $placeholder) {
                $result .= $char;
                continue;
            }
            if (isset($escapeAt[$i])) {
                $result .= '\\';
            }
        }

        return $result;
    }

    /**
     * Asks each un-escaper whether the run at the index needs escaping.
     *
     * @since 0.1.0
     */
    private function checkUnEscapeElements(string $chars, int $index): int
    {
        foreach ($this->sortedUnEscapeHandlers() as $handler) {
            $skip = $handler($chars, $index);
            if ($skip !== -1) {
                return $skip;
            }
        }

        return -1;
    }

    /**
     * Pre-render hooks in ascending priority order.
     *
     * @since 0.1.0
     *
     * @return list<PreRenderFn>
     */
    private function sortedPreRenderHandlers(): array
    {
        $handlers = $this->preRenderHandlers;
        usort($handlers, $this->byPriority(...));

        return array_column($handlers, 'fn');
    }

    /**
     * Render handlers in ascending priority order.
     *
     * @since 0.1.0
     *
     * @return list<RenderFn>
     */
    private function sortedRenderHandlers(): array
    {
        $handlers = $this->renderHandlers;
        usort($handlers, $this->byPriority(...));

        return array_column($handlers, 'fn');
    }

    /**
     * Post-render hooks in ascending priority order.
     *
     * @since 0.1.0
     *
     * @return list<PostRenderFn>
     */
    private function sortedPostRenderHandlers(): array
    {
        $handlers = $this->postRenderHandlers;
        usort($handlers, $this->byPriority(...));

        return array_column($handlers, 'fn');
    }

    /**
     * Text-transform hooks in ascending priority order.
     *
     * @since 0.1.0
     *
     * @return list<TextTransformFn>
     */
    private function sortedTextTransformHandlers(): array
    {
        $handlers = $this->textTransformHandlers;
        usort($handlers, $this->byPriority(...));

        return array_column($handlers, 'fn');
    }

    /**
     * Un-escapers in ascending priority order.
     *
     * @since 0.1.0
     *
     * @return list<UnEscapeFn>
     */
    private function sortedUnEscapeHandlers(): array
    {
        $handlers = $this->unEscapeHandlers;
        usort($handlers, $this->byPriority(...));

        return array_column($handlers, 'fn');
    }

    /**
     * Compares two registered handlers by priority, then registration order.
     *
     * @since 0.1.0
     *
     * @param array{priority: int, seq: int, fn: callable} $a
     * @param array{priority: int, seq: int, fn: callable} $b
     */
    private function byPriority(array $a, array $b): int
    {
        return [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']];
    }
}
