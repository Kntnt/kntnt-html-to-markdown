<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Converter;

/**
 * The result of a render handler.
 *
 * Render handlers are tried in priority order. A handler that does not apply
 * returns {@see RenderStatus::TryNext} so the next one gets a chance; the
 * first to return {@see RenderStatus::Success} wins and stops the dispatch.
 *
 * @since 0.1.0
 */
enum RenderStatus
{
    /**
     * This handler did not handle the node; try the next one.
     *
     * @since 0.1.0
     */
    case TryNext;

    /**
     * This handler rendered the node; stop dispatching.
     *
     * @since 0.1.0
     */
    case Success;
}
