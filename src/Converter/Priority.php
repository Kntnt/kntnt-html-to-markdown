<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Converter;

/**
 * Conventional priority values for registered handlers.
 *
 * Handlers run in ascending priority order. These three constants are the
 * anchors plugins reach for; to run just before or after one of them, a
 * plugin subtracts from or adds to the constant (for example
 * `Priority::LATE + 100` to run after every late handler).
 *
 * @since 0.1.0
 */
final class Priority
{
    /**
     * Runs early in the pipeline.
     *
     * @since 0.1.0
     */
    public const int EARLY = 100;

    /**
     * Runs in the middle, when ordering does not matter.
     *
     * @since 0.1.0
     */
    public const int STANDARD = 500;

    /**
     * Runs late in the pipeline.
     *
     * @since 0.1.0
     */
    public const int LATE = 1000;
}
