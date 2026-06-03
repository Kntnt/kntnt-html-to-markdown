<?php

declare(strict_types=1);

use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Exception\ConversionException;
use Kntnt\HtmlToMarkdown\Plugin\Base\BasePlugin;
use Kntnt\HtmlToMarkdown\Plugin\Commonmark\CommonmarkPlugin;

/**
 * Converts with a base + commonmark converter using the given plugin.
 */
function convertWithCommonmark(CommonmarkPlugin $plugin, string $input): string
{
    return (new Converter([new BasePlugin(), $plugin]))->convertString($input);
}

// Ported from upstream's commonmark TestOptionFunc.
test('commonmark options shape the output', function (CommonmarkPlugin $plugin, string $input, string $expected): void {
    expect(convertWithCommonmark($plugin, $input))->toBe($expected);
})->with([
    'WithEmDelimiter' => [new CommonmarkPlugin(emDelimiter: '_'), '<em>italic</em>', '_italic_'],
    'WithStrongDelimiter' => [new CommonmarkPlugin(strongDelimiter: '__'), '<b>bold</b>', '__bold__'],
    'WithHorizontalRule(***)' => [new CommonmarkPlugin(horizontalRule: '***'), '<hr />', '***'],
    'WithHorizontalRule(******)' => [new CommonmarkPlugin(horizontalRule: '******'), '<hr />', '******'],
    'WithHorizontalRule(---)' => [new CommonmarkPlugin(horizontalRule: '---'), '<hr />', '---'],
    'WithHorizontalRule(___)' => [new CommonmarkPlugin(horizontalRule: '___'), '<hr />', '___'],
    'WithBulletListMarker(+)' => [new CommonmarkPlugin(bulletListMarker: '+'), '<ul><li>list item</li></ul>', '+ list item'],
    'WithBulletListMarker(*)' => [
        new CommonmarkPlugin(bulletListMarker: '*'),
        '<ul><li>list a</li></ul>  <ul><li>list b</li></ul>',
        "* list a\n\n<!--THE END-->\n\n* list b",
    ],
    'WithBulletListMarker(*) and WithListEndComment(false)' => [
        new CommonmarkPlugin(bulletListMarker: '*', listEndComment: false),
        '<ul><li>list a</li></ul>  <ul><li>list b</li></ul>',
        "* list a\n\n* list b",
    ],
    'WithCodeBlockFence' => [
        new CommonmarkPlugin(codeBlockFence: '~~~'),
        '<pre><code>hello world</code></pre>',
        "~~~\nhello world\n~~~",
    ],
    'WithHeadingStyle(atx)' => [
        new CommonmarkPlugin(headingStyle: 'atx'),
        '<h1>important<br/>heading</h1>',
        '# important heading',
    ],
    'WithHeadingStyle(setext)' => [
        new CommonmarkPlugin(headingStyle: 'setext'),
        '<h1>important<br/>heading</h1>',
        "important  \nheading\n===========",
    ],
    'WithLinkEmptyHrefBehavior(render)' => [
        new CommonmarkPlugin(linkEmptyHrefBehavior: 'render'),
        '<a href="">the link content</a>',
        '[the link content]()',
    ],
    'WithLinkEmptyHrefBehavior(skip)' => [
        new CommonmarkPlugin(linkEmptyHrefBehavior: 'skip'),
        '<a href="">the link content</a>',
        'the link content',
    ],
    'WithLinkEmptyContentBehavior(render)' => [
        new CommonmarkPlugin(linkEmptyContentBehavior: 'render'),
        '<a href="/page"></a>',
        '[](/page)',
    ],
    'WithLinkEmptyContentBehavior(skip)' => [
        new CommonmarkPlugin(linkEmptyContentBehavior: 'skip'),
        '<a href="/page"></a>',
        '',
    ],
]);

// Ported from upstream's commonmark TestOptionFunc_ValidationError.
test('commonmark rejects invalid options with upstream wording', function (CommonmarkPlugin $plugin, string $expectedError): void {
    expect(fn () => convertWithCommonmark($plugin, '<strong>bold text</strong>'))
        ->toThrow(ConversionException::class, $expectedError);
})->with([
    'WithEmDelimiter(__)' => [
        new CommonmarkPlugin(emDelimiter: '__'),
        'error while initializing "commonmark" plugin: invalid value for EmDelimiter:"__" must be exactly 1 character of "*" or "_"',
    ],
    'WithEmDelimiter(**)' => [
        new CommonmarkPlugin(emDelimiter: '**'),
        'error while initializing "commonmark" plugin: invalid value for EmDelimiter:"**" must be exactly 1 character of "*" or "_"',
    ],
    'WithStrongDelimiter(_)' => [
        new CommonmarkPlugin(strongDelimiter: '_'),
        'error while initializing "commonmark" plugin: invalid value for StrongDelimiter:"_" must be exactly 2 characters of "**" or "__"',
    ],
    'WithStrongDelimiter(*)' => [
        new CommonmarkPlugin(strongDelimiter: '*'),
        'error while initializing "commonmark" plugin: invalid value for StrongDelimiter:"*" must be exactly 2 characters of "**" or "__"',
    ],
    'WithHorizontalRule(* *)' => [
        new CommonmarkPlugin(horizontalRule: '* *'),
        'error while initializing "commonmark" plugin: invalid value for HorizontalRule:"* *" must be at least 3 characters of "*", "_" or "-"',
    ],
    'WithHorizontalRule(+++)' => [
        new CommonmarkPlugin(horizontalRule: '+++'),
        'error while initializing "commonmark" plugin: invalid value for HorizontalRule:"+++" must be at least 3 characters of "*", "_" or "-"',
    ],
    'WithBulletListMarker(_)' => [
        new CommonmarkPlugin(bulletListMarker: '_'),
        'error while initializing "commonmark" plugin: invalid value for BulletListMarker:"_" must be one of "-", "+" or "*"',
    ],
    'WithCodeBlockFence(~~)' => [
        new CommonmarkPlugin(codeBlockFence: '~~'),
        'error while initializing "commonmark" plugin: invalid value for CodeBlockFence:"~~" must be one of "```" or "~~~"',
    ],
    'WithHeadingStyle(ATX)' => [
        new CommonmarkPlugin(headingStyle: 'ATX'),
        'error while initializing "commonmark" plugin: invalid value for HeadingStyle:"ATX" must be one of "atx" or "setext"',
    ],
    'WithHeadingStyle(settext)' => [
        new CommonmarkPlugin(headingStyle: 'settext'),
        'error while initializing "commonmark" plugin: invalid value for HeadingStyle:"settext" must be one of "atx" or "setext"',
    ],
]);
