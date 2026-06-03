<?php

declare(strict_types=1);

use Dom\HTMLDocument;
use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Converter\EscapeMode;
use Kntnt\HtmlToMarkdown\Converter\Priority;
use Kntnt\HtmlToMarkdown\Converter\TagType;
use Kntnt\HtmlToMarkdown\Exception\ConversionException;
use Kntnt\HtmlToMarkdown\Plugin\Base\BasePlugin;
use Kntnt\HtmlToMarkdown\Plugin\Base\Renderers;
use Kntnt\HtmlToMarkdown\Plugin\Commonmark\CommonmarkPlugin;

test('disabled escape mode leaves Markdown characters untouched', function (): void {
    $smart = new Converter([new BasePlugin(), new CommonmarkPlugin()]);
    $disabled = new Converter([new BasePlugin(), new CommonmarkPlugin()], EscapeMode::Disabled);

    expect($smart->convertString('<p>1. not a list</p>'))->toBe('1\. not a list');
    expect($disabled->convertString('<p>1. not a list</p>'))->toBe('1. not a list');
});

test('a renderer can emit a node as raw HTML', function (): void {
    $converter = new Converter([new BasePlugin(), new CommonmarkPlugin()]);
    $converter->register->rendererFor('mark', TagType::Inline, Renderers::renderAsHtml(...), Priority::EARLY);

    expect($converter->convertString('<p>a <mark>b</mark> c</p>'))->toBe('a <mark>b</mark> c');
});

test('convertNode accepts an already-parsed document', function (): void {
    $document = HTMLDocument::createFromString('<b>x</b>', LIBXML_NOERROR, 'UTF-8');
    $converter = new Converter([new BasePlugin(), new CommonmarkPlugin()]);

    expect($converter->convertNode($document))->toBe('**x**');
});

test('a plugin without a name is rejected', function (): void {
    $nameless = new class () implements Kntnt\HtmlToMarkdown\Converter\Plugin {
        public function name(): string
        {
            return '';
        }

        public function init(Converter $converter): void
        {
        }
    };

    expect(fn () => (new Converter([$nameless, new BasePlugin(), new CommonmarkPlugin()]))->convertString('<p>x</p>'))
        ->toThrow(ConversionException::class, 'the plugin has no name');
});

test('an empty document converts to an empty string', function (): void {
    expect((new Converter([new BasePlugin(), new CommonmarkPlugin()]))->convertString(''))->toBe('');
});
