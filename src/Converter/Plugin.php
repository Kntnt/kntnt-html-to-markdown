<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Converter;

/**
 * A unit of conversion behaviour registered with the converter.
 *
 * A plugin contributes renderers, pre/post-render hooks, escaping rules and
 * tag-type declarations. The converter is intentionally empty on its own:
 * even basic output needs the `base` and `commonmark` plugins. Additional
 * plugins (strikethrough, table, …) layer on top.
 *
 * @since 0.1.0
 */
interface Plugin
{
    /**
     * The plugin's public name, e.g. "strikethrough".
     *
     * The name is used both for dependency checks (commonmark requires base)
     * and for error messages. It must not be empty.
     *
     * @since 0.1.0
     */
    public function name(): string;

    /**
     * Registers the plugin's behaviour and validates its configuration.
     *
     * Called once, when the plugin is added to the converter. Any invalid
     * option must throw — the converter captures the failure and re-raises it,
     * wrapped, on the first conversion.
     *
     * @since 0.1.0
     *
     * @throws \Kntnt\HtmlToMarkdown\Exception\HtmlToMarkdownException When configuration is invalid.
     */
    public function init(Converter $converter): void;
}
