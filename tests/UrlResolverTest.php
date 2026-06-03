<?php

declare(strict_types=1);

use Kntnt\HtmlToMarkdown\Converter\UrlResolver;

// Ported from upstream's converter TestDefaultAssembleAbsoluteURL.
test('relative and absolute URLs resolve like upstream', function (string $tagName, string $input, string $domain, string $expected): void {
    expect(UrlResolver::assembleAbsoluteUrl($tagName, $input, $domain))->toBe($expected);
})->with([
    'with whitespaces around' => ['', "  example.com  \n  ", '', 'example.com'],
    'empty fragment' => ['a', '#', '', '#'],
    'fragment' => ['a', '#heading', '', '#heading'],
    'fragment with space' => ['a', '#my heading', '', '#my%20heading'],
    'no domain' => ['a', '/page.html?key=val#hash', '', '/page.html?key=val#hash'],
    'with domain' => ['a', '/page.html?key=val#hash', 'test.com', 'http://test.com/page.html?key=val#hash'],
    'with http domain' => ['a', '/page.html?key=val#hash', 'http://test.com', 'http://test.com/page.html?key=val#hash'],
    'with https domain' => ['a', '/page.html?key=val#hash', 'https://test.com', 'https://test.com/page.html?key=val#hash'],
    'with domain that includes path' => ['a', '/page.html?key=val#hash', 'https://test.com/random_stuff', 'https://test.com/page.html?key=val#hash'],
    'data uri' => ['a', 'data:image/gif;base64,R0lGODlhEAAQAMQAAORHHOVSKudfOulrSOp3WOyDZu6QdvCchPGolfO0o/XBs/fNwfjZ0frl3/zy7////wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACH5BAkAABAALAAAAAAQABAAAAVVICSOZGlCQAosJ6mu7fiyZeKqNKToQGDsM8hBADgUXoGAiqhSvp5QAnQKGIgUhwFUYLCVDFCrKUE1lBavAViFIDlTImbKC5Gm2hB0SlBCBMQiB0UjIQA7', 'test.com', 'data:image/gif;base64,R0lGODlhEAAQAMQAAORHHOVSKudfOulrSOp3WOyDZu6QdvCchPGolfO0o/XBs/fNwfjZ0frl3/zy7////wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACH5BAkAABAALAAAAAAQABAAAAVVICSOZGlCQAosJ6mu7fiyZeKqNKToQGDsM8hBADgUXoGAiqhSvp5QAnQKGIgUhwFUYLCVDFCrKUE1lBavAViFIDlTImbKC5Gm2hB0SlBCBMQiB0UjIQA7'],
    'data uri (with spaces)' => ['a', "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 56 56' width='56' height='56' %3E%3C/svg%3E", 'test.com', "data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2056%2056'%20width='56'%20height='56'%20%3E%3C/svg%3E"],
    'URI scheme' => ['a', 'slack://open?team=abc', 'test.com', 'slack://open?team=abc'],
    'already with http' => ['a', 'http://www.example.com', 'test.com', 'http://www.example.com'],
    'already with https' => ['a', 'https://www.example.com', 'test.com', 'https://www.example.com'],
    'query parameters' => ['', 'https://www.example.com?a=1&c=2&b=3&x=&y', 'test.com', 'https://www.example.com?a=1&c=2&b=3&x=&y'],
    'invalid url with space' => ['', 'https://Open Demo', '', 'https://Open%20Demo'],
    'invalid url with space and brackets' => ['', 'https://Open [foo](uri) Demo', '', 'https://Open%20%5Bfoo%5D%28uri%29%20Demo'],
    'mailto' => ['a', 'mailto:hi@example.com?subject=Mail&cc=someoneelse@example.com', 'test.com', 'mailto:hi@example.com?subject=Mail&cc=someoneelse%40example.com'],
    'invalid url with newline in mailto' => ['a', "mailto:hi@example.com?body=Hello\nJohannes", 'test.com', 'mailto:hi@example.com?body=Hello%0AJohannes'],
    'mailto with already encoded space' => ['a', 'mailto:hi@example.com?subject=Hello%20Johannes', 'test.com', 'mailto:hi@example.com?subject=Hello%20Johannes'],
    'mailto with raw space' => ['a', 'mailto:hi@example.com?subject=Greetings to Johannes', 'test.com', 'mailto:hi@example.com?subject=Greetings%20to%20Johannes'],
    'mailto with german umlaut' => ['a', 'mailto:hi@example.com?subject=Sie können gern einen Screenshot anhängen', 'test.com', 'mailto:hi@example.com?subject=Sie%20k%C3%B6nnen%20gern%20einen%20Screenshot%20anh%C3%A4ngen'],
    'mailto with link' => ['a', 'mailto:hi@example.com?body=Article: www.website.com/page.html', 'test.com', 'mailto:hi@example.com?body=Article%3A%20www.website.com%2Fpage.html'],
    'brackets inside link #1' => ['a', 'foo(and(bar)', '', 'foo%28and%28bar%29'],
    'brackets inside link #2' => ['a', '[foo](uri)', '', '%5Bfoo%5D%28uri%29'],
]);

// Ported from upstream's converter TestParseAndEncodeQuery.
test('query strings are re-encoded while preserving order', function (string $input, string $expected): void {
    expect(UrlResolver::parseAndEncodeQuery($input))->toBe($expected);
})->with([
    'empty string' => ['', ''],
    'one pair' => ['a=1', 'a=1'],
    'multiple pairs' => ['a=1&b=2&c=3', 'a=1&b=2&c=3'],
    'keep order of multiple pairs' => ['a=1&c=2&b=3', 'a=1&c=2&b=3'],
    'encode a space' => ['a=hello world&b=hello', 'a=hello+world&b=hello'],
    'value with space is encoded with percent' => ['key=%20', 'key=+'],
    'key with space is encoded with percent' => ['%20=value', '+=value'],
    'key with space is encoded with plus' => ['key=+', 'key=+'],
    'value with space is encoded with plus' => ['+=value', '+=value'],
    'continue on error at value' => ['a=1&b=%&c=hello world', 'a=1&b=%&c=hello+world'],
    'continue on error at key' => ['a=1&%=2&c=hello world', 'a=1&%=2&c=hello+world'],
]);
