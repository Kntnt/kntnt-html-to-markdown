<?php

declare(strict_types=1);

namespace Kntnt\HtmlToMarkdown\Plugin\Table;

use Dom\Node;
use Kntnt\HtmlToMarkdown\Converter\Buffer;
use Kntnt\HtmlToMarkdown\Converter\Context;
use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Converter\Plugin;
use Kntnt\HtmlToMarkdown\Converter\Priority;
use Kntnt\HtmlToMarkdown\Converter\RenderStatus;
use Kntnt\HtmlToMarkdown\Dom\Dom;
use Kntnt\HtmlToMarkdown\Dom\Tags;
use Kntnt\HtmlToMarkdown\Exception\InvalidOptionException;
use Kntnt\HtmlToMarkdown\Internal\TextUtils\TextUtils;
use Kntnt\HtmlToMarkdown\Marker\Marker;

/**
 * GFM pipe tables.
 *
 * Converting an HTML table to Markdown happens in three phases, ported from
 * upstream's numbered `table` files: *select* the header and body rows,
 * *collect* their cell content (resolving colspan/rowspan into a flat grid),
 * and *render* the grid with aligned padding and a header underline. Tables
 * that cannot be represented (nested tables, illegal block content, multi-line
 * cells under the default policy) are skipped so the surrounding document
 * still converts.
 *
 * @since 0.1.0
 */
final class TablePlugin implements Plugin
{
    /**
     * A deferred option-validation error, surfaced at init time.
     *
     * @since 0.1.0
     */
    private ?InvalidOptionException $error = null;

    /**
     * @since 0.1.0
     *
     * @param string $spanCellBehavior    "" / "empty" (default) or "mirror".
     * @param string $newlineBehavior     "" / "skip" (default) or "preserve".
     * @param bool   $skipEmptyRows       Drop rows whose every cell is empty.
     * @param bool   $headerPromotion     Promote the first row to a header when none exists.
     * @param bool   $presentationTables  Convert tables with role="presentation".
     * @param string $cellPaddingBehavior "aligned" (default), "minimal" or "none".
     */
    public function __construct(
        private readonly string $spanCellBehavior = '',
        private readonly string $newlineBehavior = '',
        private readonly bool $skipEmptyRows = false,
        private readonly bool $headerPromotion = false,
        private readonly bool $presentationTables = false,
        private readonly string $cellPaddingBehavior = 'aligned',
    ) {
        if (!in_array($spanCellBehavior, ['', 'empty', 'mirror'], true)) {
            $this->error = new InvalidOptionException(sprintf('unknown value "%s" for span cell behavior', $spanCellBehavior));
        } elseif (!in_array($newlineBehavior, ['', 'skip', 'preserve'], true)) {
            $this->error = new InvalidOptionException(sprintf('unknown value "%s" for newline behavior', $newlineBehavior));
        } elseif (!in_array($cellPaddingBehavior, ['', 'aligned', 'minimal', 'none'], true)) {
            $this->error = new InvalidOptionException(sprintf('unknown value "%s" for cell padding behavior', $cellPaddingBehavior));
        }
    }

    /**
     * @since 0.1.0
     */
    public function name(): string
    {
        return 'table';
    }

    /**
     * @since 0.1.0
     */
    public function init(Converter $converter): void
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        $converter->register->escapedChar('|');
        $converter->register->renderer($this->handleRender(...), Priority::STANDARD);
    }

    /**
     * Renders `<table>`, with a `<tr>` fallback that just separates rows.
     *
     * @since 0.1.0
     */
    private function handleRender(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        return match (Dom::nodeName($node)) {
            'table' => $this->renderTable($ctx, $w, $node),
            'tr' => $this->renderFallbackRow($ctx, $w, $node),
            default => RenderStatus::TryNext,
        };
    }

    /**
     * Fallback for a stray `<tr>` rendered outside a recognized table.
     *
     * @since 0.1.0
     */
    private function renderFallbackRow(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        $w->write("\n\n");
        $ctx->renderChildNodes($ctx, $w, $node);
        $w->write("\n\n");

        return RenderStatus::Success;
    }

    /**
     * Renders a table, or skips it when it cannot be represented in Markdown.
     *
     * @since 0.1.0
     */
    private function renderTable(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        $table = $this->collectTableContent($ctx, $node);
        if ($table === null) {
            return RenderStatus::TryNext;
        }

        [$alignments, $rows, $caption] = $table;

        $counts = $this->calculateMaxCounts($rows);
        $rows = $this->fillUpRows($rows, count($counts));

        $w->write("\n\n");
        $this->writeRow($w, $counts, $rows[0]);
        $w->write("\n");
        $this->writeHeaderUnderline($w, $alignments, $counts);
        $w->write("\n");

        foreach (array_slice($rows, 1) as $cells) {
            $this->writeRow($w, $counts, $cells);
            $w->write("\n");
        }

        if ($caption !== null) {
            $w->write("\n\n" . $caption);
        }
        $w->write("\n\n");

        return RenderStatus::Success;
    }

    /**
     * Collects a table's alignments, grid of cells, and caption — or null when
     * the table is empty or contains content that cannot be a Markdown table.
     *
     * @since 0.1.0
     *
     * @return array{0: list<string>, 1: list<list<string>>, 2: ?string}|null
     */
    private function collectTableContent(Context $ctx, Node $node): ?array
    {
        if (Dom::getAttributeOr($node, 'role', '') === 'presentation' && !$this->presentationTables) {
            return null;
        }
        if ($this->hasProblematicChildNode($node)) {
            return null;
        }
        if ($this->hasProblematicParentNode($node)) {
            return null;
        }

        $headerRowNode = $this->selectHeaderRowNode($node);
        $normalRowNodes = $this->selectNormalRowNodes($node, $headerRowNode);

        $rows = $this->collectRows($ctx, $headerRowNode, $normalRowNodes);
        if ($rows === []) {
            return null;
        }

        foreach ($rows as $i => $cells) {
            foreach ($cells as $j => $cell) {
                if (str_contains($cell, "\n")) {
                    if ($this->newlineBehavior === 'preserve') {
                        $rows[$i][$j] = str_replace("\n", '<br />', $cell);
                        continue;
                    }

                    return null;
                }
            }
        }

        return [
            $this->collectAlignments($headerRowNode, $normalRowNodes),
            $rows,
            $this->collectCaption($ctx, $node),
        ];
    }

    /**
     * Returns the content used to fill cells a colspan/rowspan covers.
     *
     * @since 0.1.0
     */
    private function contentForMergedCell(string $originalContent): string
    {
        return $this->spanCellBehavior === 'mirror' ? $originalContent : '';
    }

    /**
     * Reports whether a node contains content that cannot live in a table.
     *
     * @since 0.1.0
     */
    private function hasProblematicChildNode(Node $node): bool
    {
        return Dom::findFirstNode($node, static function (Node $n): bool {
            $name = Dom::nodeName($n);
            if (Tags::nameIsHeading($name)) {
                return true;
            }

            return match ($name) {
                'table', 'hr', 'ul', 'ol', 'blockquote' => true,
                default => false,
            };
        }) !== null;
    }

    /**
     * Reports whether an ancestor would be broken by an inner table.
     *
     * @since 0.1.0
     */
    private function hasProblematicParentNode(Node $node): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            $name = Dom::nodeName($parent);
            if (in_array($name, ['a', 'strong', 'b', 'em', 'i', 'del', 's', 'strike'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Selects the row node that should become the header, if any.
     *
     * @since 0.1.0
     */
    private function selectHeaderRowNode(Node $node): ?Node
    {
        $thead = Dom::findFirstNode($node, static fn (Node $n): bool => Dom::nodeName($n) === 'thead');
        if ($thead !== null) {
            $firstTr = Dom::findFirstNode($thead, static fn (Node $n): bool => Dom::nodeName($n) === 'tr');
            if ($firstTr !== null) {
                return $firstTr;
            }
        }

        $firstTh = Dom::findFirstNode($node, static fn (Node $n): bool => Dom::nodeName($n) === 'th');
        if ($firstTh !== null) {
            return $firstTh->parentNode;
        }

        return null;
    }

    /**
     * Selects every body `<tr>` except the chosen header row.
     *
     * @since 0.1.0
     *
     * @return list<Node>
     */
    private function selectNormalRowNodes(Node $tableNode, ?Node $headerRowNode): array
    {
        $collected = [];

        $finder = static function (Node $node) use (&$finder, &$collected, $headerRowNode): void {
            if (Dom::nodeName($node) === 'tr' && $node !== $headerRowNode) {
                $collected[] = $node;
            }
            for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) {
                $finder($child);
            }
        };
        $finder($tableNode);

        return $collected;
    }

    /**
     * Collects the alignment of each column from the first row's cells.
     *
     * @since 0.1.0
     *
     * @param list<Node> $rowNodes
     *
     * @return list<string>
     */
    private function collectAlignments(?Node $headerRowNode, array $rowNodes): array
    {
        $firstRow = $headerRowNode ?? ($rowNodes[0] ?? null);
        if ($firstRow === null) {
            return [];
        }

        $cellNodes = Dom::findAllNodes($firstRow, static function (Node $node): bool {
            $name = Dom::nodeName($node);

            return $name === 'th' || $name === 'td';
        });

        $alignments = [];
        foreach ($cellNodes as $cellNode) {
            $alignments[] = Dom::getAttributeOr($cellNode, 'align', '');
        }

        return $alignments;
    }

    /**
     * Renders a row's cells and computes the colspan/rowspan modifications.
     *
     * @since 0.1.0
     *
     * @return array{0: list<string>, 1: list<array{y: int, x: int, data: string}>}
     */
    private function collectCellsInRow(Context $ctx, int $rowIndex, Node $rowNode): array
    {
        $cellNodes = Dom::findAllNodes($rowNode, static function (Node $node): bool {
            $name = Dom::nodeName($node);

            return $name === 'th' || $name === 'td';
        });

        $cellContents = [];
        $modifications = [];

        foreach ($cellNodes as $index => $cellNode) {
            $buf = new Buffer();
            $ctx->renderNodes($ctx, $buf, $cellNode);

            $content = TextUtils::trimSpace($buf->bytes());
            // A literal "|" would be read as a column separator, so force-escape it.
            $content = str_replace(Marker::ESCAPING . '|', '\\|', $content);
            $content = $ctx->unEscapeContent($content);

            $cellContents[] = $content;

            $rowSpan = $this->numberAttributeOr($cellNode, 'rowspan', 1);
            $colSpan = $this->numberAttributeOr($cellNode, 'colspan', 1);

            foreach ($this->calculateModifications($rowIndex, $index, $rowSpan, $colSpan, $this->contentForMergedCell($content)) as $modification) {
                $modifications[] = $modification;
            }
        }

        return [$cellContents, $modifications];
    }

    /**
     * Collects every row's cells and applies colspan/rowspan shifts.
     *
     * @since 0.1.0
     *
     * @param list<Node> $rowNodes
     *
     * @return list<list<string>>
     */
    private function collectRows(Context $ctx, ?Node $headerRowNode, array $rowNodes): array
    {
        $rowContents = [];
        $groupedModifications = [];

        if ($headerRowNode !== null) {
            [$cells, $mods] = $this->collectCellsInRow($ctx, 0, $headerRowNode);
            $rowContents[] = $cells;
            $groupedModifications[] = $mods;
        } else {
            // Markdown needs a header row for the table to be recognized, so an
            // empty one is better than none.
            $rowContents[] = [];
        }

        foreach ($rowNodes as $index => $rowNode) {
            [$cells, $mods] = $this->collectCellsInRow($ctx, $index + 1, $rowNode);
            $rowContents[] = $cells;
            $groupedModifications[] = $mods;
        }

        $rowContents = $this->applyGroupedModifications($rowContents, $groupedModifications);

        if ($this->skipEmptyRows) {
            $rowContents = $this->removeEmptyRows($rowContents);
        }
        if ($this->headerPromotion) {
            $rowContents = $this->removeFirstRowIfEmpty($rowContents);
        }

        return $rowContents;
    }

    /**
     * Renders the `<caption>` content, or null when there is no caption.
     *
     * @since 0.1.0
     */
    private function collectCaption(Context $ctx, Node $node): ?string
    {
        $captionNode = Dom::findFirstNode($node, static fn (Node $n): bool => Dom::nodeName($n) === 'caption');
        if ($captionNode === null) {
            return null;
        }

        $buf = new Buffer();
        $ctx->renderNodes($ctx, $buf, $captionNode);

        return TextUtils::trimSpace($buf->bytes());
    }

    /**
     * Writes a single data row, padding cells per the padding behavior.
     *
     * @since 0.1.0
     *
     * @param list<int>    $counts
     * @param list<string> $cells
     */
    private function writeRow(Buffer $w, array $counts, array $cells): void
    {
        foreach ($cells as $i => $cell) {
            if ($i === 0) {
                $w->write('|');
            }

            $filler = $counts[$i] - mb_strlen($cell, 'UTF-8');

            if ($this->cellPaddingBehavior === 'aligned' || $this->cellPaddingBehavior === 'minimal') {
                $w->write(' ');
            }

            $w->write($cell);

            if ($this->cellPaddingBehavior === 'aligned' && $filler > 0) {
                $w->write(str_repeat(' ', $filler));
            }

            if ($this->cellPaddingBehavior === 'aligned' || $this->cellPaddingBehavior === 'minimal') {
                $w->write(' ');
            }

            $w->write('|');
        }
    }

    /**
     * Writes the header underline row, including alignment colons.
     *
     * @since 0.1.0
     *
     * @param list<string> $alignments
     * @param list<int>    $counts
     */
    private function writeHeaderUnderline(Buffer $w, array $alignments, array $counts): void
    {
        foreach ($counts as $i => $maxLength) {
            $align = $alignments[$i] ?? '';

            if ($i === 0) {
                $w->write('|');
            }
            $w->write($align === 'left' || $align === 'center' ? ':' : '-');

            if ($this->cellPaddingBehavior === 'aligned') {
                $w->write(str_repeat('-', $maxLength));
            } else {
                $w->write('-');
            }

            $w->write($align === 'right' || $align === 'center' ? ':' : '-');
            $w->write('|');
        }
    }

    /**
     * Computes the maximum rune width of each column (at least one).
     *
     * @since 0.1.0
     *
     * @param list<list<string>> $rows
     *
     * @return list<int>
     */
    private function calculateMaxCounts(array $rows): array
    {
        $maxCounts = [];
        foreach ($rows as $cells) {
            foreach ($cells as $index => $cell) {
                $count = mb_strlen($cell, 'UTF-8');
                if ($index >= count($maxCounts)) {
                    $maxCounts[] = 1;
                }
                if ($count > $maxCounts[$index]) {
                    $maxCounts[$index] = $count;
                }
            }
        }

        return array_values($maxCounts);
    }

    /**
     * Pads short rows with empty cells so every row has the same width.
     *
     * @since 0.1.0
     *
     * @param list<list<string>> $rows
     *
     * @return list<list<string>>
     */
    private function fillUpRows(array $rows, int $maxColumnCount): array
    {
        foreach ($rows as $i => $cells) {
            for ($missing = $maxColumnCount - count($cells); $missing > 0; $missing--) {
                $rows[$i][] = '';
            }
        }

        return $rows;
    }

    /**
     * Reads a positive integer attribute, falling back when absent or invalid.
     *
     * @since 0.1.0
     */
    private function numberAttributeOr(Node $node, string $key, int $fallback): int
    {
        [$value, $found] = Dom::getAttribute($node, $key);
        if (!$found || !preg_match('/^-?\d+$/', $value)) {
            return $fallback;
        }

        $num = (int) $value;

        return $num < 1 ? $fallback : $num;
    }

    /**
     * Computes the cell modifications a colspan/rowspan implies.
     *
     * @since 0.1.0
     *
     * @return list<array{y: int, x: int, data: string}>
     */
    private function calculateModifications(int $currentRowIndex, int $currentColIndex, int $rowSpan, int $colSpan, string $data): array
    {
        $mods = [];
        if ($colSpan <= 1 && $rowSpan <= 1) {
            return $mods;
        }

        for ($dx = 1; $dx < $colSpan; $dx++) {
            $mods[] = ['y' => $currentRowIndex, 'x' => $currentColIndex + $dx, 'data' => $data];
        }

        if ($rowSpan > 1) {
            for ($dy = 1; $dy < $rowSpan; $dy++) {
                for ($dx = 0; $dx < $colSpan; $dx++) {
                    $mods[] = ['y' => $currentRowIndex + $dy, 'x' => $currentColIndex + $dx, 'data' => $data];
                }
            }
        }

        return $mods;
    }

    /**
     * Applies the grouped modifications in reverse, handling overlaps.
     *
     * @since 0.1.0
     *
     * @param list<list<string>>                            $contents
     * @param list<list<array{y: int, x: int, data: string}>> $groupedMods
     *
     * @return list<list<string>>
     */
    private function applyGroupedModifications(array $contents, array $groupedMods): array
    {
        foreach (array_reverse($groupedMods) as $mods) {
            $contents = $this->applyModifications($contents, $mods);
        }

        return $contents;
    }

    /**
     * Inserts each modification's cell, growing the grid as needed.
     *
     * @since 0.1.0
     *
     * @param list<list<string>>                        $contents
     * @param list<array{y: int, x: int, data: string}> $mods
     *
     * @return list<list<string>>
     */
    private function applyModifications(array $contents, array $mods): array
    {
        foreach ($mods as $mod) {
            while ($mod['y'] >= count($contents)) {
                $contents[] = [];
            }
            while ($mod['x'] - 1 >= count($contents[$mod['y']])) {
                $contents[$mod['y']][] = '';
            }
            array_splice($contents[$mod['y']], $mod['x'], 0, [$mod['data']]);
        }

        return $contents;
    }

    /**
     * Reports whether every cell in a row is empty.
     *
     * @since 0.1.0
     *
     * @param list<string> $cells
     */
    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Removes empty body rows (keeping the header); returns an empty grid when
     * everything is empty.
     *
     * @since 0.1.0
     *
     * @param list<list<string>> $rows
     *
     * @return list<list<string>>
     */
    private function removeEmptyRows(array $rows): array
    {
        $filtered = [];
        foreach ($rows as $index => $cells) {
            if ($index === 0 || !$this->isEmptyRow($cells)) {
                $filtered[] = $cells;
            }
        }

        if (count($filtered) === 1 && $this->isEmptyRow($filtered[0])) {
            return [];
        }

        return $filtered;
    }

    /**
     * Drops the header row when it is empty (used with header promotion).
     *
     * @since 0.1.0
     *
     * @param list<list<string>> $rows
     *
     * @return list<list<string>>
     */
    private function removeFirstRowIfEmpty(array $rows): array
    {
        if ($rows !== [] && $this->isEmptyRow($rows[0])) {
            array_shift($rows);
        }

        return $rows;
    }
}
