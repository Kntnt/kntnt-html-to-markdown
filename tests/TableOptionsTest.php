<?php

declare(strict_types=1);

use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Plugin\Base\BasePlugin;
use Kntnt\HtmlToMarkdown\Plugin\Commonmark\CommonmarkPlugin;
use Kntnt\HtmlToMarkdown\Plugin\Table\TablePlugin;

/**
 * Converts with a base + commonmark + table converter and trims the result,
 * matching how upstream's table option tests compare output.
 */
function convertTable(TablePlugin $plugin, string $input): string
{
    $converter = new Converter([new BasePlugin(), new CommonmarkPlugin(), $plugin]);

    return trim($converter->convertString($input));
}

// Ported from upstream's table TestOptionFunc_ColRowSpan.
test('colspan and rowspan shift cells correctly', function (TablePlugin $plugin, string $input, string $expected): void {
    expect(convertTable($plugin, $input))->toBe($expected);
})->with([
    'default (empty span)' => [
        new TablePlugin(spanCellBehavior: 'empty'),
        '<table><tr><td>A</td><td colspan="3">B</td></tr></table>',
        "|   |   |   |   |\n|---|---|---|---|\n| A | B |   |   |",
    ],
    'colspan=3 mirror' => [
        new TablePlugin(spanCellBehavior: 'mirror'),
        '<table><tr><td>A</td><td colspan="3">B</td></tr></table>',
        "|   |   |   |   |\n|---|---|---|---|\n| A | B | B | B |",
    ],
    'rowspan=3 mirror' => [
        new TablePlugin(spanCellBehavior: 'mirror'),
        '<table><tr><td>A</td><td rowspan="3">B</td></tr></table>',
        "|   |   |\n|---|---|\n| A | B |\n|   | B |\n|   | B |",
    ],
    'colspan and rowspan mirror' => [
        new TablePlugin(spanCellBehavior: 'mirror'),
        '<table><tr><td>A</td><td colspan="3" rowspan="3">B</td><td>C</td></tr></table>',
        "|   |   |   |   |   |\n|---|---|---|---|---|\n| A | B | B | B | C |\n|   | B | B | B |   |\n|   | B | B | B |   |",
    ],
    'shifting content mirror' => [
        new TablePlugin(spanCellBehavior: 'mirror'),
        '<table><tr><td>A</td><td colspan="3" rowspan="3">B</td><td>C</td></tr><tr><td>1</td><td>2</td><td>3</td></tr></table>',
        "|   |   |   |   |   |   |\n|---|---|---|---|---|---|\n| A | B | B | B | C |   |\n| 1 | B | B | B | 2 | 3 |\n|   | B | B | B |   |   |",
    ],
    'rowspans overlap with colspans mirror' => [
        new TablePlugin(spanCellBehavior: 'mirror'),
        '<table><tr><td rowspan="3">A</td><td colspan="2">B</td><td>C</td></tr><tr><td rowspan="2" colspan="2">D</td><td>E</td></tr><tr><td>F</td></tr></table>',
        "|   |   |   |   |\n|---|---|---|---|\n| A | B | B | C |\n| A | D | D | E |\n| A | D | D | F |",
    ],
]);

// Ported from upstream's table TestOptionFunc_EmptyRows.
test('empty-row handling matches upstream', function (TablePlugin $plugin, string $input, string $expected): void {
    expect(convertTable($plugin, $input))->toBe($expected);
})->with([
    'by default keep empty rows' => [
        new TablePlugin(),
        '<table><tr><td></td><td>B1</td></tr><tr><td></td><td></td></tr><tr><td>A3</td><td></td></tr></table>',
        "|    |    |\n|----|----|\n|    | B1 |\n|    |    |\n| A3 |    |",
    ],
    'some rows are empty' => [
        new TablePlugin(skipEmptyRows: true),
        '<table><tr><td></td><td>B1</td></tr><tr><td></td><td></td></tr><tr><td>A3</td><td></td></tr><tr><td>    </td><td>    </td></tr></table>',
        "|    |    |\n|----|----|\n|    | B1 |\n| A3 |    |",
    ],
    'all rows are empty' => [
        new TablePlugin(skipEmptyRows: true),
        '<p>Before</p><table><caption>A description</caption><tr><td></td><td></td></tr><tr><td></td><td></td></tr><tr><td></td><td></td></tr></table><p>After</p>',
        "Before\n\nA description\n\nAfter",
    ],
    'element that is not rendered' => [
        new TablePlugin(skipEmptyRows: true),
        '<p>Before</p><table><tr><td><script type="text/javascript" src="/script"></script></td></tr></table><p>After</p>',
        "Before\n\nAfter",
    ],
]);

// Ported from upstream's table TestOptionFunc_PromoteHeader.
test('header promotion matches upstream', function (TablePlugin $plugin, string $input, string $expected): void {
    expect(convertTable($plugin, $input))->toBe($expected);
})->with([
    'default keeps an empty header' => [
        new TablePlugin(),
        '<table><tr><td>A1</td><td>B1</td></tr><tr><td>A2</td><td>B2</td></tr></table>',
        "|    |    |\n|----|----|\n| A1 | B1 |\n| A2 | B2 |",
    ],
    'not needed when a header exists' => [
        new TablePlugin(headerPromotion: true),
        '<table><tr><th>Heading</th><th>Heading</th></tr><tr><td>A1</td><td>B1</td></tr><tr><td>A2</td><td>B2</td></tr></table>',
        "| Heading | Heading |\n|---------|---------|\n| A1      | B1      |\n| A2      | B2      |",
    ],
    'promote first row' => [
        new TablePlugin(headerPromotion: true),
        '<table><tr><td>A1</td><td>B1</td></tr><tr><td>A2</td><td>B2</td></tr></table>',
        "| A1 | B1 |\n|----|----|\n| A2 | B2 |",
    ],
    'promote first row but it is empty' => [
        new TablePlugin(headerPromotion: true),
        '<table><tr><td></td><td></td></tr><tr><td>A1</td><td>B1</td></tr><tr><td>A2</td><td>B2</td></tr></table>',
        "|    |    |\n|----|----|\n| A1 | B1 |\n| A2 | B2 |",
    ],
    'deleted empty rows and promoted first row' => [
        new TablePlugin(headerPromotion: true, skipEmptyRows: true),
        '<table><tr><td></td><td></td></tr><tr><td>A1</td><td>B1</td></tr><tr><td>A2</td><td>B2</td></tr></table>',
        "| A1 | B1 |\n|----|----|\n| A2 | B2 |",
    ],
]);

// Ported from upstream's table TestOptionFunc_PresentationTable.
test('presentation tables are skipped unless opted in', function (TablePlugin $plugin, string $input, string $expected): void {
    expect(convertTable($plugin, $input))->toBe($expected);
})->with([
    'default skips presentation tables' => [
        new TablePlugin(),
        "<table role=\"presentation\">\n  <tr>\n    <td>A1</td>\n    <td>A2</td>\n  </tr>\n  <tr>\n    <td>B1</td>\n    <td>B2</td>\n  </tr>\n</table>",
        "A1 A2 \n\nB1 B2",
    ],
    'keep the presentation table' => [
        new TablePlugin(presentationTables: true),
        '<table role="presentation"><tr><td>A1</td><td>A2</td></tr><tr><td>B1</td><td>B2</td></tr></table>',
        "|    |    |\n|----|----|\n| A1 | A2 |\n| B1 | B2 |",
    ],
]);

// Ported from upstream's table TestOptionFunc_NewlineBehavior.
test('newline behavior matches upstream', function (TablePlugin $plugin, string $input, string $expected): void {
    expect(convertTable($plugin, $input))->toBe($expected);
})->with([
    'skip behavior (default)' => [
        new TablePlugin(newlineBehavior: 'skip'),
        '<table><tr><td>A11<br />A12</td></tr></table>',
        "A11  \nA12",
    ],
    'preserve behavior' => [
        new TablePlugin(newlineBehavior: 'preserve'),
        '<table><tr><td>A11<br>A12</td><td>B11<br />B12</td></tr></table>',
        "|                |                |\n|----------------|----------------|\n| A11  <br />A12 | B11  <br />B12 |",
    ],
]);

// Ported from upstream's table TestOptionFunc_CellPaddingBehavior.
test('cell padding behavior matches upstream', function (TablePlugin $plugin, string $input, string $expected): void {
    expect(convertTable($plugin, $input))->toBe($expected);
})->with([
    'aligned padding (default)' => [
        new TablePlugin(),
        '<table><tr><td>This line has some way longer text than the other line below it.</td><td>A2</td></tr><tr><td>B1</td><td>This one has longer text than the line above.</td></tr></table>',
        "|                                                                  |                                               |\n|------------------------------------------------------------------|-----------------------------------------------|\n| This line has some way longer text than the other line below it. | A2                                            |\n| B1                                                               | This one has longer text than the line above. |",
    ],
    'minimal padding' => [
        new TablePlugin(cellPaddingBehavior: 'minimal'),
        '<table><tr><td>This line has some way longer text than the other line below it.</td><td>A2</td></tr><tr><td>B1</td><td>This one has longer text than the line above.</td></tr></table>',
        "|  |  |\n|---|---|\n| This line has some way longer text than the other line below it. | A2 |\n| B1 | This one has longer text than the line above. |",
    ],
    'no padding' => [
        new TablePlugin(cellPaddingBehavior: 'none'),
        '<table><tr><td>This line has some way longer text than the other line below it.</td><td>A2</td></tr><tr><td>B1</td><td>This one has longer text than the line above.</td></tr></table>',
        "|||\n|---|---|\n|This line has some way longer text than the other line below it.|A2|\n|B1|This one has longer text than the line above.|",
    ],
]);
