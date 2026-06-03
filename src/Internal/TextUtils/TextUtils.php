<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Internal\TextUtils;

/**
 * Byte-string helpers for shaping Markdown output.
 *
 * These are faithful ports of upstream's `internal/textutils` package. They
 * operate on byte strings (PHP strings are byte strings, matching Go's
 * `[]byte`) and are deliberately whitespace-pedantic: getting the spaces and
 * newlines exactly right is what keeps the golden fixtures byte-for-byte.
 *
 * Whitespace classification follows Go's `unicode.IsSpace`, which is wider
 * than PHP's `trim()` default — it includes NBSP, the Unicode space
 * separators, and the line/paragraph separators. {@see TextUtils::WHITESPACE}
 * captures that set once for every trimming helper here.
 *
 * @since 0.1.0
 */
final class TextUtils
{
    /**
     * PCRE character-class body matching Go's `unicode.IsSpace` set.
     *
     * @since 0.1.0
     */
    public const string WHITESPACE = '\\x{0009}-\\x{000D}\\x{0020}\\x{0085}\\x{00A0}\\x{1680}\\x{2000}-\\x{200A}\\x{2028}\\x{2029}\\x{202F}\\x{205F}\\x{3000}';

    /**
     * Trims leading and trailing Unicode whitespace.
     *
     * @since 0.1.0
     */
    public static function trimSpace(string $content): string
    {
        $trimmed = preg_replace('/^[' . self::WHITESPACE . ']+/u', '', $content);
        $trimmed = preg_replace('/[' . self::WHITESPACE . ']+$/u', '', $trimmed ?? $content);

        return $trimmed ?? $content;
    }

    /**
     * Splits content into the leading whitespace, the trimmed core, and the
     * trailing whitespace.
     *
     * Returned as a 3-tuple `[leftExtra, trimmed, rightExtra]`, matching
     * upstream's `SurroundingSpaces`. The slices are byte-exact so callers can
     * re-assemble the original string by concatenation.
     *
     * @since 0.1.0
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function surroundingSpaces(string $content): array
    {
        $rightTrimmed = preg_replace('/[' . self::WHITESPACE . ']+$/u', '', $content) ?? $content;
        $rightExtra = substr($content, strlen($rightTrimmed));

        $trimmed = preg_replace('/^[' . self::WHITESPACE . ']+/u', '', $rightTrimmed) ?? $rightTrimmed;
        $leftExtra = substr($rightTrimmed, 0, strlen($rightTrimmed) - strlen($trimmed));

        return [$leftExtra, $trimmed, $rightExtra];
    }

    /**
     * Collapses runs of soft hard-line-breaks before a blank line.
     *
     * A "  \n" immediately followed by a blank line is redundant once the
     * blank line is there; this removes that redundancy in its few shapes.
     *
     * @since 0.1.0
     */
    public static function trimUnnecessaryHardLineBreaks(string $content): string
    {
        $content = str_replace("  \n\n", "\n\n", $content);
        $content = str_replace("  \n  \n", "\n\n", $content);

        return str_replace("  \n \n", "\n\n", $content);
    }

    /**
     * Caps consecutive newlines at two, preserving leading spaces on kept
     * lines but dropping them on collapsed ones.
     *
     * @since 0.1.0
     */
    public static function trimConsecutiveNewlines(string $input): string
    {
        $result = '';
        $newlineCount = 0;
        $spaceBuffer = '';

        // Walking byte by byte is equivalent to upstream's rune walk here: the
        // only special bytes ('\n', ' ') are single-byte ASCII, and every
        // other byte takes the "reset and append" path regardless.
        $length = strlen($input);
        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];

            if ($char === "\n") {
                $newlineCount++;
                if ($newlineCount <= 2) {
                    $result .= $spaceBuffer . "\n";
                }
                $spaceBuffer = '';
            } elseif ($char === ' ') {
                $spaceBuffer .= ' ';
            } else {
                $newlineCount = 0;
                $result .= $spaceBuffer . $char;
                $spaceBuffer = '';
            }
        }

        return $result . $spaceBuffer;
    }

    /**
     * Wraps each non-empty line of multi-line content in the delimiter.
     *
     * Bold/italic delimiters are not recognized across a newline, so for
     * multi-line content the delimiter is repeated on every line that has
     * content, with surrounding spaces kept outside the delimiters.
     *
     * @since 0.1.0
     */
    public static function delimiterForEveryLine(string $text, string $delimiter): string
    {
        $buf = '';

        $lines = explode("\n", $text);
        $lastIndex = count($lines) - 1;
        foreach ($lines as $i => $line) {
            [$leftExtra, $trimmed, $rightExtra] = self::surroundingSpaces($line);

            if ($trimmed === '') {
                $buf .= $leftExtra . $rightExtra;
            } else {
                $buf .= $leftExtra . $delimiter . $trimmed . $delimiter . $rightExtra;
            }

            if ($i < $lastIndex) {
                $buf .= "\n";
            }
        }

        return $buf;
    }

    /**
     * Escapes the line breaks inside multi-line link or heading content.
     *
     * A blank interior line would otherwise terminate the link/heading, so
     * blank lines become "\\\n" and content lines get a hard break.
     *
     * @since 0.1.0
     */
    public static function escapeMultiLine(string $content): string
    {
        $parts = explode("\n", $content);
        if (count($parts) === 1) {
            return $content;
        }

        $output = '';
        $lastIndex = count($parts) - 1;
        foreach ($parts as $i => $part) {
            $trimmedLeft = preg_replace('/^[' . self::WHITESPACE . ']+/u', '', $part) ?? $part;

            if ($trimmedLeft === '') {
                $output .= "\\\n";
                continue;
            }

            if ($i === $lastIndex) {
                $output .= $trimmedLeft;
                continue;
            }

            if (str_ends_with($trimmedLeft, '  ')) {
                $output .= $trimmedLeft . "\n";
            } else {
                $output .= $trimmedLeft . "  \n";
            }
        }

        return $output;
    }

    /**
     * Prefixes the first line and every subsequent line with the replacement.
     *
     * @since 0.1.0
     */
    public static function prefixLines(string $source, string $repl): string
    {
        return $repl . str_replace("\n", "\n" . $repl, $source);
    }

    /**
     * Surrounds content with the given bytes on both sides.
     *
     * @since 0.1.0
     */
    public static function surroundBy(string $content, string $chars): string
    {
        return $chars . $content . $chars;
    }

    /**
     * Surrounds a (link/image) title with the safest quote characters.
     *
     * Prefers double quotes; switches to single quotes when the content has
     * double but no single quotes; escapes double quotes only when the content
     * contains both kinds.
     *
     * @since 0.1.0
     */
    public static function surroundByQuotes(string $content): string
    {
        if ($content === '') {
            return '';
        }

        $containsDoubleQuote = str_contains($content, '"');
        $containsSingleQuote = str_contains($content, "'");

        if ($containsDoubleQuote && $containsSingleQuote) {
            $content = str_replace('"', '\\"', $content);

            return self::surroundBy($content, '"');
        }
        if ($containsDoubleQuote) {
            return self::surroundBy($content, "'");
        }

        return self::surroundBy($content, '"');
    }

    /**
     * Counts the longest run of the fence character in the content.
     *
     * @since 0.1.0
     */
    public static function calculateCodeFenceOccurrences(string $fenceChar, string $content): int
    {
        $max = 0;
        $charsTogether = 0;

        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            if ($content[$i] === $fenceChar) {
                $charsTogether++;
            } elseif ($charsTogether !== 0) {
                $max = max($max, $charsTogether);
                $charsTogether = 0;
            }
        }
        if ($charsTogether !== 0) {
            $max = max($max, $charsTogether);
        }

        return $max;
    }

    /**
     * Computes the fence string (at least three chars, always longer than any
     * run inside the content) for a code block.
     *
     * @since 0.1.0
     */
    public static function calculateCodeFence(string $fenceChar, string $content): string
    {
        $repeat = self::calculateCodeFenceOccurrences($fenceChar, $content) + 1;
        if ($repeat < 3) {
            $repeat = 3;
        }

        return str_repeat($fenceChar, $repeat);
    }

    /**
     * Flattens inline-code content: newlines and tabs become spaces, the ends
     * are trimmed, and runs of spaces collapse to one.
     *
     * @since 0.1.0
     */
    public static function collapseInlineCodeContent(string $content): string
    {
        $content = str_replace(["\n", "\t"], ' ', $content);
        $content = self::trimSpace($content);

        $result = '';
        $count = 0;
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            if ($char === ' ') {
                $count++;
            } else {
                $count = 0;
            }
            if ($count > 1) {
                continue;
            }
            $result .= $char;
        }

        return $result;
    }
}
