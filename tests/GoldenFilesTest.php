<?php

declare(strict_types=1);

use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Converter\Priority;
use Kntnt\HtmlToMarkdown\Converter\TagType;
use Kntnt\HtmlToMarkdown\Plugin\Base\BasePlugin;
use Kntnt\HtmlToMarkdown\Plugin\Base\Renderers;
use Kntnt\HtmlToMarkdown\Plugin\Commonmark\CommonmarkPlugin;
use Kntnt\HtmlToMarkdown\Plugin\Strikethrough\StrikethroughPlugin;
use Kntnt\HtmlToMarkdown\Plugin\Table\TablePlugin;

/**
 * Builds the dataset of golden-file pairs for a fixture directory.
 *
 * Each case maps a readable name to the `[input path, expected path]` pair,
 * so a failing case names the exact fixture.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function goldenDataset(string $dir): array
{
    $cases = [];
    foreach (glob(__DIR__ . "/Fixtures/{$dir}/*.in.html") ?: [] as $inputPath) {
        $name = basename($inputPath, '.in.html');
        $cases["{$dir}/{$name}"] = [$inputPath, dirname($inputPath) . "/{$name}.out.md"];
    }

    return $cases;
}

// The commonmark golden files keep HTML comments as raw blocks, which upstream
// achieves by overriding the comment renderer to emit raw HTML.
test('commonmark golden files convert byte-for-byte', function (string $inputPath, string $expectedPath): void {
    $converter = new Converter([new BasePlugin(), new CommonmarkPlugin()]);
    $converter->register->rendererFor('#comment', TagType::Block, Renderers::renderAsHtml(...), Priority::EARLY);

    expect($converter->convertString((string) file_get_contents($inputPath)))
        ->toBe((string) file_get_contents($expectedPath));
})->with(goldenDataset('commonmark'));

test('strikethrough golden files convert byte-for-byte', function (string $inputPath, string $expectedPath): void {
    $converter = new Converter([new BasePlugin(), new CommonmarkPlugin(), new StrikethroughPlugin()]);

    expect($converter->convertString((string) file_get_contents($inputPath)))
        ->toBe((string) file_get_contents($expectedPath));
})->with(goldenDataset('strikethrough'));

test('table golden files convert byte-for-byte', function (string $inputPath, string $expectedPath): void {
    $converter = new Converter([new BasePlugin(), new CommonmarkPlugin(), new TablePlugin()]);

    expect($converter->convertString((string) file_get_contents($inputPath)))
        ->toBe((string) file_get_contents($expectedPath));
})->with(goldenDataset('table'));
