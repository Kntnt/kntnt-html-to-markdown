<?php

declare(strict_types=1);

use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Converter\Options;
use Kntnt\HtmlToMarkdown\Exception\ConversionException;
use Kntnt\HtmlToMarkdown\HtmlToMarkdown;
use Kntnt\HtmlToMarkdown\Plugin\Base\BasePlugin;
use Kntnt\HtmlToMarkdown\Plugin\Commonmark\CommonmarkPlugin;
use Kntnt\HtmlToMarkdown\Plugin\Table\TablePlugin;

// Ported from upstream's ExampleConvertString.
test('the facade converts a simple string', function (): void {
    expect(HtmlToMarkdown::convert('<strong>Bold Text</strong>'))->toBe('**Bold Text**');
});

// Ported from upstream's ExampleWithDomain.
test('the facade resolves relative URLs against the domain', function (): void {
    expect(HtmlToMarkdown::convert('<img src="/assets/image.png" />', domain: 'https://example.com'))
        ->toBe('![](https://example.com/assets/image.png)');
});

// Ported from upstream's TestConvertString_WindowsCarriageReturn.
test('windows carriage returns collapse like other whitespace', function (string $input, string $expected): void {
    expect(HtmlToMarkdown::convert($input))->toBe($expected);
})->with([
    'just newlines' => ["\r\n\r\n\r\n\r\n", ''],
    'inside strong' => ["<strong>Bold\r\n\r\n\r\n\r\nText</strong>", '**Bold Text**'],
    'inside paragraph' => ["<p>Some\r\n\r\n\r\n\r\nText</p>", 'Some Text'],
    'inside list' => ["<ul><li>Some\r\n\r\n\r\n\r\nText</li></ul>", '- Some Text'],
]);

test('the converter throws when no render handlers are registered', function (): void {
    expect(fn () => (new Converter())->convertString('<p>x</p>'))
        ->toThrow(ConversionException::class, 'no render handlers are registered');
});

test('the converter throws when commonmark is registered without base', function (): void {
    expect(fn () => (new Converter([new CommonmarkPlugin()]))->convertString('<p>x</p>'))
        ->toThrow(ConversionException::class, 'the "base" plugin is also required');
});

test('include and exclude selectors scope the conversion', function (): void {
    $converter = new Converter([new BasePlugin(), new CommonmarkPlugin()]);
    $html = '<header>Site nav</header><article><h1>Title</h1><aside>Ad</aside><p>Body</p></article>';

    $included = $converter->convertString($html, new Options(includeSelector: 'article'));
    expect($included)->toContain('# Title')->not->toContain('Site nav');

    $excluded = $converter->convertString($html, new Options(includeSelector: 'article', excludeSelector: 'aside'));
    expect($excluded)->not->toContain('Ad')->toContain('Body');
});

// The table plugin's option errors surface, wrapped, on the first conversion.
test('the table plugin surfaces invalid option values', function (): void {
    $converter = new Converter([
        new BasePlugin(),
        new CommonmarkPlugin(),
        new TablePlugin(spanCellBehavior: 'random'),
    ]);

    expect(fn () => $converter->convertString('<strong>test</strong>'))
        ->toThrow(ConversionException::class, 'error while initializing "table" plugin: unknown value "random" for span cell behavior');
});
