<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Converter;

use Kntnt\HtmlToMarkdown\Internal\TextUtils\TextUtils;
use Uri\Rfc3986\Uri;

/**
 * Turns the URLs found in links and images into Markdown-safe absolute URLs.
 *
 * This ports upstream's `converter/url.go`. The parsing and reference
 * resolution use PHP 8.5's native {@see Uri}, which tracks Go's `net/url`
 * closely — it even rejects the same malformed inputs (returning null where
 * Go returns an error), so both fall back to plain percent-encoding in
 * exactly the same cases. The query re-encoding and the final
 * percent-encoding pass are ported by hand to keep the byte output identical.
 *
 * @since 0.1.0
 */
final class UrlResolver
{
    /**
     * Characters that must be percent-encoded so the URL survives Markdown.
     *
     * Spaces would break link recognition; brackets and parentheses would be
     * read as link syntax; angle brackets as autolink/HTML.
     *
     * @since 0.1.0
     *
     * @var array<string, string>
     */
    private const array PERCENT_ENCODING = [
        ' ' => '%20',
        '[' => '%5B',
        ']' => '%5D',
        '(' => '%28',
        ')' => '%29',
        '<' => '%3C',
        '>' => '%3E',
    ];

    /**
     * Resolves a raw URL (from an `href`/`src`) to an absolute, safe URL.
     *
     * The domain — when given — turns relative URLs into absolute ones; data
     * URIs are passed straight to percent-encoding, matching upstream's
     * short-circuits.
     *
     * The URL is decomposed with the lenient RFC 3986 reference grammar rather
     * than the strict {@see Uri} parser, because Go's `net/url` (which this
     * ports) accepts raw spaces and other characters in the query and opaque
     * parts. {@see Uri} is still used for reference resolution against the
     * base domain, where the inputs are well-formed.
     *
     * @since 0.1.0
     */
    public static function assembleAbsoluteUrl(string $tagName, string $rawUrl, string $domain): string
    {
        $rawUrl = TextUtils::trimSpace($rawUrl);

        // Go's url.Parse cannot tell an empty fragment from no fragment, so a
        // lone "#" is preserved verbatim rather than round-tripped.
        if ($rawUrl === '#') {
            return $rawUrl;
        }

        // Improve the odds of a successful parse: real newlines/tabs in an
        // attribute value are encoded first.
        $rawUrl = str_replace(["\n", "\t"], ['%0A', '%09'], $rawUrl);

        [$scheme, $authority, $path, $query, $fragment] = self::decompose($rawUrl);

        // A data URI (e.g. an inline base64 image) must not have its body
        // rewritten; just make it Markdown-safe.
        if ($scheme === 'data') {
            return self::percentEncode($rawUrl);
        }

        // Re-encode the query while preserving the original parameter order,
        // then prefer "%20" over "+" for spaces (better for mailto links).
        if ($query !== null) {
            $query = str_replace('+', '%20', self::parseAndEncodeQuery($query));
        }

        $assembled = self::recompose($scheme, $authority, $path, $query, $fragment);

        // Resolve a relative URL against the base domain, when one is given.
        $base = $scheme === null ? self::parseBaseDomain($domain) : null;
        if ($base !== null) {
            $resolved = self::tryParse($assembled);
            if ($resolved !== null) {
                $assembled = $base->resolve($resolved->toRawString())->toRawString();
            }
        }

        return self::percentEncode($assembled);
    }

    /**
     * Splits a URL into its RFC 3986 components, leniently.
     *
     * Unset components are null (distinguishing "no query" from an empty "?").
     *
     * @since 0.1.0
     *
     * @return array{0: ?string, 1: ?string, 2: string, 3: ?string, 4: ?string}
     */
    private static function decompose(string $url): array
    {
        preg_match(
            '%^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$%',
            $url,
            $matches,
            PREG_UNMATCHED_AS_NULL,
        );

        return [
            $matches[1] ?? null,
            $matches[2] ?? null,
            $matches[3] ?? '',
            $matches[4] ?? null,
            $matches[5] ?? null,
        ];
    }

    /**
     * Reassembles a URL from its RFC 3986 components.
     *
     * @since 0.1.0
     */
    private static function recompose(?string $scheme, ?string $authority, string $path, ?string $query, ?string $fragment): string
    {
        $result = '';
        if ($scheme !== null) {
            $result .= $scheme . ':';
        }
        if ($authority !== null) {
            $result .= '//' . $authority;
        }
        $result .= $path;
        if ($query !== null) {
            $result .= '?' . $query;
        }
        if ($fragment !== null) {
            $result .= '#' . $fragment;
        }

        return $result;
    }

    /**
     * Re-encodes a raw query string component by component, keeping order.
     *
     * @since 0.1.0
     */
    public static function parseAndEncodeQuery(string $rawQuery): string
    {
        if ($rawQuery === '') {
            return '';
        }

        $parts = explode('&', $rawQuery);
        $encodedParts = [];
        foreach ($parts as $part) {
            $split = explode('=', $part, 2);

            if (count($split) === 1) {
                $encodedParts[] = self::decodeAndEncode($split[0]);
            } elseif ($split[1] === '') {
                $encodedParts[] = self::decodeAndEncode($split[0]) . '=';
            } else {
                $encodedParts[] = self::decodeAndEncode($split[0]) . '=' . self::decodeAndEncode($split[1]);
            }
        }

        return implode('&', $encodedParts);
    }

    /**
     * Decodes then re-encodes a query token; on a decode error, the original
     * token is kept untouched (matching Go's `QueryUnescape` failure path).
     *
     * @since 0.1.0
     */
    private static function decodeAndEncode(string $original): string
    {
        $decoded = self::queryUnescape($original);
        if ($decoded === null) {
            return $original;
        }

        return self::queryEscape($decoded);
    }

    /**
     * Decodes an `application/x-www-form-urlencoded` token, or null on an
     * invalid percent-escape.
     *
     * @since 0.1.0
     */
    private static function queryUnescape(string $value): ?string
    {
        $result = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '%') {
                if ($i + 2 >= $length || !ctype_xdigit($value[$i + 1]) || !ctype_xdigit($value[$i + 2])) {
                    return null;
                }
                $result .= chr((int) hexdec($value[$i + 1] . $value[$i + 2]) & 0xFF);
                $i += 2;
            } elseif ($char === '+') {
                $result .= ' ';
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * Encodes a string as an `application/x-www-form-urlencoded` token.
     *
     * Unreserved characters (`A-Za-z0-9-_.~`) pass through, a space becomes
     * "+", and everything else becomes an uppercase percent-escape — matching
     * Go's `url.QueryEscape` exactly.
     *
     * @since 0.1.0
     */
    private static function queryEscape(string $value): string
    {
        $result = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if (
                ($char >= 'A' && $char <= 'Z')
                || ($char >= 'a' && $char <= 'z')
                || ($char >= '0' && $char <= '9')
                || $char === '-' || $char === '_' || $char === '.' || $char === '~'
            ) {
                $result .= $char;
            } elseif ($char === ' ') {
                $result .= '+';
            } else {
                $result .= '%' . strtoupper(bin2hex($char));
            }
        }

        return $result;
    }

    /**
     * Parses a base domain into a {@see Uri}, adding an `http://` scheme when
     * the raw value is just a host (e.g. "test.com").
     *
     * @since 0.1.0
     */
    private static function parseBaseDomain(string $rawDomain): ?Uri
    {
        if ($rawDomain === '') {
            return null;
        }

        $first = self::tryParse($rawDomain);
        if ($first !== null && ($first->getRawHost() ?? '') !== '') {
            return $first;
        }

        $second = self::tryParse('http://' . $rawDomain);
        if ($second !== null && ($second->getRawHost() ?? '') !== '') {
            return $second;
        }

        return null;
    }

    /**
     * Parses a URL, returning null on failure (the analogue of Go's error).
     *
     * @since 0.1.0
     */
    private static function tryParse(string $value): ?Uri
    {
        try {
            return Uri::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Percent-encodes the Markdown-unsafe characters in the final URL string.
     *
     * @since 0.1.0
     */
    private static function percentEncode(string $url): string
    {
        return strtr($url, self::PERCENT_ENCODING);
    }
}
