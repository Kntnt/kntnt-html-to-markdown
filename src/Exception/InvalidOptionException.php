<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Exception;

use InvalidArgumentException;

/**
 * Thrown when a plugin or converter option has an invalid value.
 *
 * The message mirrors upstream's `ValidateConfigError` wording so error
 * output is identical — for example
 * `invalid value for EmDelimiter:"__" must be exactly 1 character of "*" or "_"`.
 *
 * @since 0.1.0
 */
class InvalidOptionException extends InvalidArgumentException implements HtmlToMarkdownException
{
    /**
     * Builds the upstream-compatible "invalid value for …" message.
     *
     * @since 0.1.0
     *
     * @param string $key                The option name (e.g. "EmDelimiter").
     * @param string $value              The rejected value.
     * @param string $patternDescription What a valid value must look like.
     */
    public static function forConfig(string $key, string $value, string $patternDescription): self
    {
        return new self(sprintf('invalid value for %s:"%s" must be %s', $key, $value, $patternDescription));
    }
}
