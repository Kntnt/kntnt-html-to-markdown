<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Internal\Escape;

use Kntnt\HtmlToMarkdown\Internal\TextUtils\TextUtils;
use Kntnt\HtmlToMarkdown\Marker\Marker;

/**
 * Context-aware un-escapers: the second half of smart escaping.
 *
 * During rendering every Markdown-significant character is prefixed with the
 * escaping sentinel. These functions are then consulted, per sentinel, on the
 * fully-rendered text: each looks at the surrounding characters and reports
 * whether the run starting there would be misread as Markdown — a fenced
 * code block, an ATX heading, a list marker, and so on. If any says "yes",
 * the sentinel becomes a backslash; otherwise it is dropped.
 *
 * Each function returns how many bytes the run spans (so the caller can skip
 * past it), or -1 when it does not apply. This is a faithful port of
 * upstream's `internal/escape` package, including its sentinel-skipping
 * neighbour lookups.
 *
 * @since 0.1.0
 */
final class Escape
{
    /**
     * The escaping sentinel byte, skipped by the neighbour lookups.
     *
     * @since 0.1.0
     */
    private const string PLACEHOLDER = Marker::ESCAPING;

    /**
     * A backslash escape: the character is already a literal backslash.
     *
     * @since 0.1.0
     */
    public static function isBackslash(string $chars, int $index): int
    {
        return $chars[$index] === '\\' ? 1 : -1;
    }

    /**
     * Inline code: a single backtick.
     *
     * @since 0.1.0
     */
    public static function isInlineCode(string $chars, int $index): int
    {
        return $chars[$index] === '`' ? 1 : -1;
    }

    /**
     * A fenced code block: three or more backticks/tildes at line start.
     *
     * @since 0.1.0
     */
    public static function isFencedCode(string $chars, int $index): int
    {
        if ($chars[$index] !== '`' && $chars[$index] !== '~') {
            return -1;
        }

        for ($i = $index - 1; $i >= 0; $i--) {
            if ($chars[$i] === ' ' || $chars[$i] === self::PLACEHOLDER) {
                continue;
            }
            if ($chars[$i] === "\n") {
                break;
            }

            return -1;
        }

        $count = 1;
        $i = $index + 1;
        $length = strlen($chars);
        for (; $i < $length; $i++) {
            if ($chars[$i] === self::PLACEHOLDER) {
                continue;
            }
            if ($chars[$i] === '`' || $chars[$i] === '~') {
                $count++;
                continue;
            }

            break;
        }
        if ($count < 3) {
            return -1;
        }

        return $i - $index;
    }

    /**
     * A blockquote marker: a ">" at line start.
     *
     * @since 0.1.0
     */
    public static function isBlockQuote(string $chars, int $index): int
    {
        if ($chars[$index] !== '>') {
            return -1;
        }

        for ($i = $index - 1; $i >= 0; $i--) {
            if ($chars[$i] === "\n") {
                break;
            }
            if ($chars[$i] === self::PLACEHOLDER) {
                continue;
            }
            if ($chars[$i] === ' ') {
                continue;
            }

            return -1;
        }

        return 1;
    }

    /**
     * An ATX heading: up to six "#" at line start, followed by whitespace.
     *
     * @since 0.1.0
     */
    public static function isAtxHeader(string $chars, int $index): int
    {
        if ($chars[$index] !== '#') {
            return -1;
        }

        for ($i = $index - 1; $i >= 0; $i--) {
            if ($chars[$i] === "\n") {
                break;
            }
            if ($chars[$i] === self::PLACEHOLDER) {
                continue;
            }
            if ($chars[$i] === ' ') {
                continue;
            }

            return -1;
        }

        $poundSigns = 1;
        $length = strlen($chars);
        for ($i = $index + 1; $i < $length; $i++) {
            if ($chars[$i] === '#') {
                $poundSigns++;
                if ($poundSigns > 6) {
                    return -1;
                }
                continue;
            }
            if ($chars[$i] === self::PLACEHOLDER) {
                continue;
            }
            if ($chars[$i] === ' ' || $chars[$i] === "\t" || $chars[$i] === "\n" || $chars[$i] === "\r") {
                return $i - $index;
            }

            return -1;
        }

        return 1;
    }

    /**
     * A Setext heading underline: "=" or "-" on the line below content.
     *
     * @since 0.1.0
     */
    public static function isSetextHeader(string $chars, int $index): int
    {
        if ($chars[$index] !== '=' && $chars[$index] !== '-') {
            return -1;
        }

        $newlineCount = 0;
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($chars[$i] === self::PLACEHOLDER || $chars[$i] === ' ') {
                continue;
            }
            if ($chars[$i] === "\n") {
                $newlineCount++;
                continue;
            }

            if ($newlineCount === 0) {
                return -1;
            }
            if ($newlineCount === 1) {
                return 1;
            }

            return -1;
        }

        return -1;
    }

    /**
     * A thematic break: three or more "-", "_" or "*" alone on a line.
     *
     * @since 0.1.0
     */
    public static function isDivider(string $chars, int $index): int
    {
        if ($chars[$index] !== '-' && $chars[$index] !== '_' && $chars[$index] !== '*') {
            return -1;
        }

        for ($i = $index - 1; $i >= 0; $i--) {
            if ($chars[$i] === "\n") {
                break;
            }
            if ($chars[$i] === self::PLACEHOLDER) {
                continue;
            }
            if ($chars[$i] === ' ') {
                continue;
            }

            return -1;
        }

        $count = 1;
        $length = strlen($chars);
        $lastChar = $length;
        for ($i = $index + 1; $i < $length; $i++) {
            if ($chars[$i] === self::PLACEHOLDER) {
                continue;
            }
            if ($chars[$i] === ' ') {
                continue;
            }
            if ($chars[$i] === $chars[$index]) {
                $count++;
                continue;
            }
            if ($chars[$i] === "\n") {
                $lastChar = $i;
                break;
            }

            return -1;
        }

        if ($count >= 3) {
            return $lastChar - $index;
        }

        return -1;
    }

    /**
     * An unordered-list marker: "-", "*" or "+" at line start, then whitespace.
     *
     * @since 0.1.0
     */
    public static function isUnorderedList(string $chars, int $index): int
    {
        if ($chars[$index] !== '-' && $chars[$index] !== '*' && $chars[$index] !== '+') {
            return -1;
        }

        for ($i = $index - 1; $i >= 0; $i--) {
            if ($chars[$i] === "\n") {
                break;
            }
            if ($chars[$i] === ' ') {
                continue;
            }
            if ($chars[$i] === self::PLACEHOLDER) {
                continue;
            }

            return -1;
        }

        $next = self::getNext($chars, $index);
        if (self::isSpaceByte($next) || $next === 0) {
            return 1;
        }

        return -1;
    }

    /**
     * An ordered-list marker: digits then "." or ")" at line start, then
     * whitespace.
     *
     * @since 0.1.0
     */
    public static function isOrderedList(string $chars, int $index): int
    {
        if ($chars[$index] !== '.' && $chars[$index] !== ')') {
            return -1;
        }

        $prev = self::getPrevAsRune($chars, $index);
        if (!self::isDigitRune($prev)) {
            return -1;
        }

        for ($i = $index - 1; $i >= 0; $i--) {
            if ($chars[$i] === "\n") {
                break;
            }
            if ($chars[$i] === ' ') {
                continue;
            }
            if ($chars[$i] === self::PLACEHOLDER) {
                continue;
            }
            if (self::isDigitByte(ord($chars[$i]))) {
                continue;
            }

            return -1;
        }

        $next = self::getNext($chars, $index);
        if (self::isSpaceByte($next) || $next === 0) {
            return 1;
        }

        return -1;
    }

    /**
     * The start of an image or link: "![" or a "[…]" pair on one line.
     *
     * @since 0.1.0
     */
    public static function isImageOrLink(string $chars, int $index): int
    {
        if ($chars[$index] === '!') {
            $nextIndex = $index + 1;
            if ($nextIndex < strlen($chars) && $chars[$nextIndex] === '[') {
                return 1;
            }

            return -1;
        }

        if ($chars[$index] === '[') {
            $length = strlen($chars);
            for ($i = $index + 1; $i < $length; $i++) {
                if ($chars[$i] === "\n") {
                    return -1;
                }
                if ($chars[$i] === ']') {
                    return 1;
                }
            }

            return -1;
        }

        return -1;
    }

    /**
     * Emphasis/strong: "*" or "_" not followed by whitespace.
     *
     * @since 0.1.0
     */
    public static function isItalicOrBold(string $chars, int $index): int
    {
        if ($chars[$index] !== '*' && $chars[$index] !== '_') {
            return -1;
        }

        $next = self::getNextAsRune($chars, $index);
        if (self::isSpaceRune($next) || $next === 0) {
            return -1;
        }

        return 1;
    }

    /**
     * Returns the codepoint of the next non-sentinel rune, or 0 if none.
     *
     * Exposed so the strikethrough plugin can share the same "is the next
     * character whitespace?" logic.
     *
     * @since 0.1.0
     */
    public static function getNextAsRune(string $source, int $index): int
    {
        $length = strlen($source);
        for ($i = $index + 1; $i < $length; $i++) {
            if ($source[$i] === self::PLACEHOLDER) {
                continue;
            }

            return self::decodeRuneAt($source, $i);
        }

        return 0;
    }

    /**
     * Returns the next non-sentinel byte, or 0 if none.
     *
     * @since 0.1.0
     */
    private static function getNext(string $chars, int $index): int
    {
        $length = strlen($chars);
        for ($i = $index + 1; $i < $length; $i++) {
            if ($chars[$i] === self::PLACEHOLDER) {
                continue;
            }

            return ord($chars[$i]);
        }

        return 0;
    }

    /**
     * Returns the codepoint of the previous non-sentinel rune, or 0 if none.
     *
     * @since 0.1.0
     */
    private static function getPrevAsRune(string $chars, int $index): int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($chars[$i] === self::PLACEHOLDER) {
                continue;
            }

            return self::decodeLastRune($chars, $i + 1);
        }

        return 0;
    }

    /**
     * Classifies a byte as whitespace, matching upstream's byte-level test
     * (ASCII whitespace plus the raw bytes 0x85 and 0xA0).
     *
     * @since 0.1.0
     */
    private static function isSpaceByte(int $byte): bool
    {
        return match ($byte) {
            0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x20, 0x85, 0xA0 => true,
            default => false,
        };
    }

    /**
     * Classifies a byte as an ASCII digit.
     *
     * @since 0.1.0
     */
    private static function isDigitByte(int $byte): bool
    {
        return $byte >= 0x30 && $byte <= 0x39;
    }

    /**
     * Classifies a codepoint as Unicode whitespace (Go's `unicode.IsSpace`).
     *
     * @since 0.1.0
     */
    private static function isSpaceRune(int $codepoint): bool
    {
        if ($codepoint === 0) {
            return false;
        }

        return preg_match('/^[' . TextUtils::WHITESPACE . ']$/u', self::encodeRune($codepoint)) === 1;
    }

    /**
     * Classifies a codepoint as a Unicode decimal digit (Go's
     * `unicode.IsDigit`).
     *
     * @since 0.1.0
     */
    private static function isDigitRune(int $codepoint): bool
    {
        if ($codepoint >= 0x30 && $codepoint <= 0x39) {
            return true;
        }
        if ($codepoint === 0) {
            return false;
        }

        return preg_match('/^\p{Nd}$/u', self::encodeRune($codepoint)) === 1;
    }

    /**
     * Decodes the UTF-8 rune that starts at byte index $i.
     *
     * @since 0.1.0
     */
    private static function decodeRuneAt(string $s, int $i): int
    {
        $lead = ord($s[$i]);
        if ($lead < 0x80) {
            return $lead;
        }

        if ($lead >= 0xF0) {
            $length = 4;
            $codepoint = $lead & 0x07;
        } elseif ($lead >= 0xE0) {
            $length = 3;
            $codepoint = $lead & 0x0F;
        } elseif ($lead >= 0xC0) {
            $length = 2;
            $codepoint = $lead & 0x1F;
        } else {
            return 0xFFFD;
        }

        $total = strlen($s);
        for ($k = 1; $k < $length; $k++) {
            if ($i + $k >= $total || (ord($s[$i + $k]) & 0xC0) !== 0x80) {
                return 0xFFFD;
            }
            $codepoint = ($codepoint << 6) | (ord($s[$i + $k]) & 0x3F);
        }

        return $codepoint;
    }

    /**
     * Decodes the UTF-8 rune that ends just before byte offset $endExclusive.
     *
     * @since 0.1.0
     */
    private static function decodeLastRune(string $s, int $endExclusive): int
    {
        $start = $endExclusive - 1;
        if ($start < 0) {
            return 0;
        }
        while ($start > 0 && (ord($s[$start]) & 0xC0) === 0x80) {
            $start--;
        }

        return self::decodeRuneAt($s, $start);
    }

    /**
     * Encodes a codepoint back to its UTF-8 byte string.
     *
     * @since 0.1.0
     */
    private static function encodeRune(int $codepoint): string
    {
        return mb_chr($codepoint, 'UTF-8') ?: '';
    }
}
