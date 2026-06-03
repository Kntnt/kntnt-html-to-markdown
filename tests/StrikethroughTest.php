<?php

declare(strict_types=1);

use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Plugin\Base\BasePlugin;
use Kntnt\HtmlToMarkdown\Plugin\Strikethrough\StrikethroughPlugin;

// Ported from upstream's strikethrough TestNewStrikethroughPlugin.
test('strikethrough wraps content in the delimiter', function (string $input, string $expected): void {
    $converter = new Converter([new BasePlugin(), new StrikethroughPlugin()]);

    expect($converter->convertString($input))->toBe($expected);
})->with([
    'simple' => ['<p><s>Text</s></p>', '~~Text~~'],
    'with spaces inside' => ['<p><s>  Text  </s></p>', '~~Text~~'],
    'with tilde characters inside' => ['<p><s>~~A~~B~~</s></p>', '~~\~\~A\~\~B\~\~~~'],
    'nested' => ['<p><s>A <s>B</s> C</s></p>', '~~A B C~~'],
    'adjacent' => ['<p><s>A</s><s>B</s> <s>C</s></p>', '~~AB~~ ~~C~~'],
]);

// Ported from upstream's strikethrough TestWithDelimiter.
test('strikethrough honours a custom delimiter', function (): void {
    $converter = new Converter([new BasePlugin(), new StrikethroughPlugin(delimiter: '==')]);

    expect($converter->convertString('<p><s>Text</s></p>'))->toBe('==Text==');
});
