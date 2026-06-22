<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Converter;

use Kntnt\HtmlToMarkdown\Internal\TextUtils\TextUtils;

/**
 * Turns the URLs found in links and images into Markdown-safe absolute URLs.
 *
 * This ports upstream's `converter/url.go`. Parsing, RFC 3986 §5.2 reference
 * resolution, query re-encoding and the final percent-encoding pass are all
 * ported by hand to keep the byte output identical to Go's `net/url`, with no
 * external dependency — so the converter needs no PHP-8.5-only
 * `Uri\Rfc3986\Uri` and runs on PHP 8.4.
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
     * The URL is decomposed with the lenient RFC 3986 reference grammar,
     * because Go's `net/url` (which this ports) accepts raw spaces and other
     * characters in the query and opaque parts. Reference resolution against
     * the base domain is a hand-rolled port of RFC 3986 §5.2 (see
     * {@see resolveReference}), so the converter carries no PHP-8.5-only
     * `Uri\Rfc3986\Uri` dependency.
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

        // Resolve a relative reference against the base domain when one is
        // given; otherwise keep the decomposed components as they are.
        $base = $scheme === null ? self::parseBaseDomain($domain) : null;
        if ($base !== null) {
            [$scheme, $authority, $path, $query, $fragment] = self::resolveReference($base, $authority, $path, $query, $fragment);
        }

        return self::percentEncode(self::recompose($scheme, $authority, $path, $query, $fragment));
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
     * Parses a base domain into its RFC 3986 components, adding an `http://`
     * scheme when the raw value is just a host (e.g. "test.com").
     *
     * Returns null when the value yields no authority host, matching Go's
     * `parseBaseDomain`, which rejects a base without a `Host`.
     *
     * @since 0.1.0
     *
     * @return array{0: ?string, 1: ?string, 2: string, 3: ?string, 4: ?string}|null
     */
    private static function parseBaseDomain(string $rawDomain): ?array
    {
        if ($rawDomain === '') {
            return null;
        }

        // Accept the value as given when it already carries an authority host.
        $first = self::decompose($rawDomain);
        if (self::hostPort($first[1]) !== '') {
            return $first;
        }

        // Otherwise retry with a fallback scheme so a bare host gains an authority.
        $second = self::decompose('http://' . $rawDomain);
        if (self::hostPort($second[1]) !== '') {
            return $second;
        }

        return null;
    }

    /**
     * Extracts the `host[:port]` part of an authority, dropping any
     * `userinfo@` prefix — the analogue of Go's `url.URL::Host`.
     *
     * @since 0.1.0
     */
    private static function hostPort(?string $authority): string
    {
        if ($authority === null) {
            return '';
        }

        $at = strrpos($authority, '@');

        return $at === false ? $authority : substr($authority, $at + 1);
    }

    /**
     * Resolves a relative reference against a base URL per RFC 3986 §5.2.2.
     *
     * The reference never carries a scheme here — callers resolve only when the
     * raw URL was relative — so this is the "scheme undefined" branch of the
     * transform, a hand port of Go's `url.URL::ResolveReference`.
     *
     * @since 0.1.0
     *
     * @param array{0: ?string, 1: ?string, 2: string, 3: ?string, 4: ?string} $base
     *
     * @return array{0: ?string, 1: ?string, 2: string, 3: ?string, 4: ?string}
     */
    private static function resolveReference(array $base, ?string $refAuthority, string $refPath, ?string $refQuery, ?string $refFragment): array
    {
        [$baseScheme, $baseAuthority, $basePath, $baseQuery] = $base;

        // A reference authority replaces the base authority wholesale.
        if ($refAuthority !== null) {
            return [$baseScheme, $refAuthority, self::removeDotSegments($refPath), $refQuery, $refFragment];
        }

        // An empty reference path keeps the base path (and the base query when
        // the reference has none); any other path resolves against the base.
        if ($refPath === '') {
            $path = $basePath;
            $query = $refQuery ?? $baseQuery;
        } else {
            $path = str_starts_with($refPath, '/')
                ? self::removeDotSegments($refPath)
                : self::removeDotSegments(self::mergePath($baseAuthority, $basePath, $refPath));
            $query = $refQuery;
        }

        return [$baseScheme, $baseAuthority, $path, $query, $refFragment];
    }

    /**
     * Merges a relative-reference path onto the base path per RFC 3986 §5.2.3.
     *
     * @since 0.1.0
     */
    private static function mergePath(?string $baseAuthority, string $basePath, string $refPath): string
    {
        // A base with an authority but an empty path roots the reference.
        if ($baseAuthority !== null && $basePath === '') {
            return '/' . $refPath;
        }

        // Otherwise replace everything after the base's last segment.
        $slash = strrpos($basePath, '/');

        return $slash === false ? $refPath : substr($basePath, 0, $slash + 1) . $refPath;
    }

    /**
     * Removes `.` and `..` segments from a path per RFC 3986 §5.2.4.
     *
     * @since 0.1.0
     */
    private static function removeDotSegments(string $path): string
    {
        $input = $path;
        $output = '';

        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
            } elseif (str_starts_with($input, './')) {
                $input = substr($input, 2);
            } elseif (str_starts_with($input, '/./')) {
                $input = '/' . substr($input, 3);
            } elseif ($input === '/.') {
                $input = '/';
            } elseif (str_starts_with($input, '/../')) {
                $input = '/' . substr($input, 4);
                $output = self::removeLastSegment($output);
            } elseif ($input === '/..') {
                $input = '/';
                $output = self::removeLastSegment($output);
            } elseif ($input === '.' || $input === '..') {
                $input = '';
            } else {
                // Move the first path segment, with any leading slash, to output.
                $start = str_starts_with($input, '/') ? 1 : 0;
                $next = strpos($input, '/', $start);
                if ($next === false) {
                    $output .= $input;
                    $input = '';
                } else {
                    $output .= substr($input, 0, $next);
                    $input = substr($input, $next);
                }
            }
        }

        return $output;
    }

    /**
     * Drops the last segment (and its preceding slash) from an output buffer,
     * the helper {@see removeDotSegments} uses for `..` handling.
     *
     * @since 0.1.0
     */
    private static function removeLastSegment(string $output): string
    {
        $slash = strrpos($output, '/');

        return $slash === false ? '' : substr($output, 0, $slash);
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
