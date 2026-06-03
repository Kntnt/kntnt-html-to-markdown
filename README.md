# kntnt/html-to-markdown

A dependency-free PHP 8.5 library that converts HTML into [GitHub Flavored Markdown](https://github.github.com/gfm/). It is a faithful port of the Go library [`JohannesKaufmann/html-to-markdown`](https://github.com/JohannesKaufmann/html-to-markdown) — this release ports upstream **v2.5.1** (commit `b0879832`).

The port covers the converter core and the `base`, `commonmark`, `strikethrough`, and `table` plugins. It is consumed by other Kntnt projects via Composer — for example, to serve per-page Markdown to LLMs.

## Why this library

- **Faithful.** Upstream's golden fixtures are asserted byte-for-byte. Output matches the Go library wherever PHP's HTML5 parser and Go's `x/net/html` agree.
- **Zero runtime dependencies.** Every upstream dependency maps to a PHP built-in: `Dom\HTMLDocument` for HTML5 parsing and CSS selectors, `mbstring` for text handling, and `Uri\Rfc3986\Uri` for relative-URL resolution. No Composer runtime packages, ever.
- **Smart escaping.** Context-aware escaping (ported from upstream's `ESCAPING.md`) means fake `**bold**` in text stays literal while real `<strong>` becomes `**bold**`.

## Requirements

- PHP **8.5** or newer
- `ext-dom` and `ext-mbstring` (both bundled with virtually every PHP build)

## Installation

```bash
composer require kntnt/html-to-markdown
```

## Usage

### The facade

For the common case, the static facade mirrors upstream's `ConvertString`:

```php
use Kntnt\HtmlToMarkdown\HtmlToMarkdown;

$markdown = HtmlToMarkdown::convert('<strong>Bold Text</strong>');
// => **Bold Text**
```

Pass options as named arguments. To resolve relative URLs against a base domain:

```php
$markdown = HtmlToMarkdown::convert(
    '<img src="/assets/image.png" />',
    domain: 'https://example.com',
);
// => ![](https://example.com/assets/image.png)
```

### The converter

For full control — custom plugins, options, include/exclude selectors — build a `Converter` directly. The `base` and `commonmark` plugins are the minimum needed for sensible output; add `strikethrough` and `table` as required:

```php
use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Converter\Options;
use Kntnt\HtmlToMarkdown\Plugin\Base\BasePlugin;
use Kntnt\HtmlToMarkdown\Plugin\Commonmark\CommonmarkPlugin;
use Kntnt\HtmlToMarkdown\Plugin\Strikethrough\StrikethroughPlugin;
use Kntnt\HtmlToMarkdown\Plugin\Table\TablePlugin;

$converter = new Converter(
    plugins: [
        new BasePlugin(),
        new CommonmarkPlugin(),
        new StrikethroughPlugin(),
        new TablePlugin(),
    ],
);

$markdown = $converter->convertString(
    '<h1>Title</h1><table><tr><th>A</th></tr><tr><td>1</td></tr></table>',
    new Options(domain: 'https://example.com'),
);
```

### Commonmark options

The `CommonmarkPlugin` accepts the same options as upstream:

```php
new CommonmarkPlugin(
    emDelimiter: '_',          // default "*"
    strongDelimiter: '__',     // default "**"
    horizontalRule: '---',     // default "* * *"
    bulletListMarker: '+',     // default "-"
    codeBlockFence: '~~~',     // default "```"
    headingStyle: 'setext',    // default "atx"
);
```

### Include / exclude selectors

Restrict conversion to a subset of the document, or strip parts of it, using CSS selectors (resolved with `Dom\HTMLDocument::querySelectorAll`):

```php
$converter->convertString($html, new Options(
    includeSelector: 'article',
    excludeSelector: 'nav, aside, .ad',
));
```

## Supported Markdown

This library targets the parts of GFM that upstream's ported plugins cover:

- **CommonMark** — headings (ATX and Setext), bold/italic, links, images, inline and fenced code, blockquotes, ordered and unordered lists, thematic breaks, hard line breaks, and HTML comments.
- **Strikethrough** — `<del>`, `<s>`, and `<strike>`.
- **Tables** — GFM pipe tables with alignment, `colspan`/`rowspan`, captions, header promotion, and presentation-table handling.

Explicitly **not** supported (out of scope, matching the port's boundaries):

- **Task lists** (`- [ ]` checkboxes)
- **Autolinks** (bare-URL linkification)
- **The GFM tagfilter** extension

## How it works

HTML is parsed once into a `Dom\HTMLDocument`. Plugins register renderers per HTML tag (with priorities), a `TagType` (block/inline) that drives whitespace collapsing, and before/after hooks that transform the DOM and the final Markdown. Escaping is two-phase: text is escaped greedily with internal sentinel bytes during rendering, then a context-aware pass decides — per occurrence — whether each escape survives. The full design, including the Go→PHP module map and every documented parser-difference deviation, is in [`docs/architecture.md`](docs/architecture.md).

## Development

```bash
composer install
composer test        # Pest
composer stan        # PHPStan, level max
composer cs          # PHP-CS-Fixer, PSR-12 (dry run)
composer cs-fix      # PHP-CS-Fixer, apply fixes
```

The Go reference is cloned into `.reference/` (git-ignored) and pinned to the tag recorded above; it is the specification against which the port is checked.

## Attribution and license

MIT, with dual copyright: Johannes Kaufmann for the original Go library, Thomas Barregren / Kntnt for the PHP port. The whitespace-collapsing code carries an additional Turndown / collapse-whitespace lineage. The full lineage is in [`NOTICE.md`](NOTICE.md); the license text is in [`LICENSE`](LICENSE).
