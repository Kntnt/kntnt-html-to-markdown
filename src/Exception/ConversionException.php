<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Exception;

use RuntimeException;

/**
 * Thrown when conversion cannot proceed at runtime.
 *
 * Covers configuration that only becomes a problem at convert time — for
 * example no render handlers being registered, or the commonmark plugin
 * being present without the base plugin it depends on — as well as a plugin
 * that failed to initialize.
 *
 * @since 0.1.0
 */
class ConversionException extends RuntimeException implements HtmlToMarkdownException
{
}
