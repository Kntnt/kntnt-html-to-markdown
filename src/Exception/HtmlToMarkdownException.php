<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception this library throws.
 *
 * Catching this interface lets a consumer handle all conversion failures in
 * one place, regardless of the concrete type, while still distinguishing
 * them from unrelated runtime errors.
 *
 * @since 0.1.0
 */
interface HtmlToMarkdownException extends Throwable
{
}
