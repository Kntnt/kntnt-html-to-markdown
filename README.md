# kntnt/html-to-markdown

[![License](https://img.shields.io/github/license/Kntnt/kntnt-html-to-markdown)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/kntnt/html-to-markdown)](composer.json)
[![Latest release](https://img.shields.io/packagist/v/kntnt/html-to-markdown)](https://packagist.org/packages/kntnt/html-to-markdown)
[![CI](https://github.com/Kntnt/kntnt-html-to-markdown/actions/workflows/ci.yml/badge.svg)](https://github.com/Kntnt/kntnt-html-to-markdown/actions/workflows/ci.yml)

A dependency-free PHP 8.5 library that converts HTML into [GitHub Flavored Markdown](https://github.github.com/gfm/) (GFM). If you need to turn HTML into clean, predictable Markdown — to feed page content to an LLM, archive content portably, or render it elsewhere — and you want output that matches a battle-tested reference implementation, this is for you.

## Description

`kntnt/html-to-markdown` is a faithful PHP port of the Go library [`JohannesKaufmann/html-to-markdown`](https://github.com/JohannesKaufmann/html-to-markdown) (v2). It ports the converter core and the `base`, `commonmark`, `strikethrough`, and `table` plugins, reproducing the Go library's output **byte-for-byte**: upstream's golden test fixtures are asserted character-for-character by the test suite.

It has **zero runtime dependencies**. Every dependency the Go library reaches for maps onto a PHP built-in — `Dom\HTMLDocument` for HTML5 parsing and CSS selectors, `mbstring` for text handling, and `Uri\Rfc3986\Uri` for relative-URL resolution — so the package drops into any PHP 8.5 project without dragging in a dependency tree or risking version conflicts. It is used by other Kntnt projects via Composer.

### Key Features

- **Faithful port.** Output matches the Go library byte-for-byte wherever PHP's HTML5 parser and Go's `x/net/html` agree; upstream's golden fixtures are asserted in the test suite.
- **Zero runtime dependencies.** No Composer runtime packages — only the near-ubiquitous `ext-dom` and `ext-mbstring`.
- **GitHub Flavored Markdown.** Full CommonMark core plus GFM tables and strikethrough.
- **Tables done properly.** Pipe tables with alignment, `colspan`/`rowspan`, captions, header promotion, and presentation-table handling.
- **Smart, context-aware escaping.** Text that merely *looks* like Markdown stays literal, while real formatting elements become real Markdown.
- **Relative-URL resolution.** Resolve `href`/`src` against a base domain to produce absolute links and images.
- **Scoped conversion.** Include or exclude parts of the document with CSS selectors.
- **Configurable output.** Choose emphasis delimiters, heading style, code fences, list markers, and more.
- **Two entry points.** A one-line static facade for the common case, and a deep, extensible `Converter` for everything else.

### The problem

More and more PHP applications need Markdown out of HTML, and the naive approaches all fail in their own way: stripping tags throws away structure, and hand-rolled regular expressions produce invalid or surprising Markdown. Getting it right is harder than it looks, and the subtlest part is escaping: a sentence that happens to contain `**` or a leading `#` must not silently turn into bold text or a heading when it round-trips through Markdown.

### How this library helps

Rather than inventing yet another converter, this library ports a mature, widely used, well-tested one — `JohannesKaufmann/html-to-markdown` — and holds itself to that reference byte-for-byte. You get GitHub Flavored Markdown out of the box: headings, emphasis, links, images, code, blockquotes, lists, thematic breaks, hard breaks, comments, GFM tables, and strikethrough. Relative URLs can be resolved against a base domain, conversion can be scoped to part of a document, and escaping is handled by a two-phase, context-aware model that decides *per occurrence* whether a character needs a backslash. Because it has zero runtime dependencies, it installs cleanly into any PHP 8.5 codebase.

### Limitations

- **No task lists.** This is the one deliberate gap in GFM coverage: a checkbox list item (`<li><input type="checkbox">…`) is rendered as a plain list item, not `- [ ]` / `- [x]`. Task lists are out of scope upstream too.
- **Autolinks and the tagfilter do not apply.** Both are *parsing* features that act on Markdown input; when the input is HTML there is nothing for them to do. See [Supported Markdown](#supported-markdown) for the details.
- **It is a converter, not a sanitizer.** It emits Markdown (and strips script/style/iframe-style tags in the process), but it is not designed or audited as a security boundary against hostile HTML. Sanitize untrusted input separately if that is your threat model.
- **PHP 8.5+ only.** The port uses modern language and standard-library features (including `Uri\Rfc3986\Uri`) and carries no back-compatibility shims for older PHP.

## Requirements

- PHP **8.5** or newer
- `ext-dom` and `ext-mbstring` (both bundled with virtually every PHP build)

## Installation

```bash
composer require kntnt/html-to-markdown
```

No further configuration is required.

## Usage

### The facade

For the common case, the static facade mirrors upstream's `ConvertString`. It wires up the `base` and `commonmark` plugins and converts in one call:

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

The facade also accepts `includeSelector` and `excludeSelector` (see [Include / exclude selectors](#include--exclude-selectors)). For strikethrough, tables, or custom output styling, build a `Converter` directly.

### The converter

For full control — strikethrough and tables, custom output options, or custom plugins — build a `Converter`. The `base` and `commonmark` plugins are the minimum needed for sensible output; add `strikethrough` and `table` as required:

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

A single converter is safe to reuse across many conversions: options that vary per request — the base domain and the include/exclude selectors — live on the `Options` object passed to `convertString()`, not on the converter itself. If you already hold a parsed DOM, `convertNode()` takes a `Dom\Node` instead of a string.

### Commonmark options

The `CommonmarkPlugin` accepts the same options as upstream, as named constructor arguments:

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

### Supported Markdown

The output targets [GitHub Flavored Markdown](https://github.github.com/gfm/). GFM is CommonMark plus five extensions. Because this library converts *from* HTML rather than parsing Markdown, each extension means something slightly different here — the table below is how each is handled:

| GFM extension | Status | Notes |
|---|---|---|
| Tables | ✅ Produced | Pipe tables with alignment, `colspan`/`rowspan`, captions, header promotion, and presentation-table handling. |
| Strikethrough | ✅ Produced | `<del>`, `<s>`, and `<strike>` → `~~…~~`. |
| Task lists | ❌ Not produced | The one gap. A checkbox list item (`<li><input type="checkbox">…`) is rendered as a plain list item, not `- [ ]` / `- [x]`. |
| Autolinks | — Not applicable | An autolink is a *parsing* feature: a GFM reader turns a bare `https://…` in Markdown text into a link. Converting the other way, there is nothing to do — a bare URL is emitted as text and any GFM renderer autolinks it. (URLs that are already `<a>` or `<img>` elements become normal Markdown links and images.) |
| Tagfilter (disallowed raw HTML) | — Not applicable | The tagfilter neutralizes dangerous raw tags (`<script>`, `<style>`, `<iframe>`, `<noscript>`, `<textarea>`, …) found in Markdown *input*. This converter already strips exactly those tags from the HTML and emits Markdown rather than passing raw HTML through, so there is nothing left for the filter to act on. |

Underneath those extensions, the full **CommonMark** core is supported: headings (ATX and Setext), bold/italic, links, images, inline and fenced code, blockquotes, ordered and unordered lists, thematic breaks, hard line breaks, and HTML comments.

In short: the output is GFM except for task lists. This matches the upstream Go library's boundaries — task lists are out of scope there too.

### Updating

This is a Composer package, so updating is simply:

```bash
composer update kntnt/html-to-markdown
```

The project follows [Semantic Versioning](https://semver.org/), so patch and minor updates within a major version will not break your integration. See the [Changelog](#changelog) for what each release contains.

## Frequently asked questions (FAQ)

#### Does it produce task lists?

No. Rendering a checkbox `<li>` as `- [ ]` / `- [x]` is the single deliberate gap in GFM coverage; the item is emitted as a plain list item instead. This matches the upstream Go library, where task lists are also out of scope.

#### Why does it require PHP 8.5?

The port leans on modern PHP, including the `Uri\Rfc3986\Uri` class introduced in 8.5 for relative-URL resolution. Keeping a single, modern floor avoids back-compatibility shims and keeps the code close to the Go original.

#### Is the output really identical to the Go library?

Wherever PHP's HTML5 parser and Go's `x/net/html` agree, yes — upstream's golden fixtures are asserted byte-for-byte. The only legitimate source of divergence is a genuine difference between the two HTML5 parsers; any such case is documented in [`docs/architecture.md`](docs/architecture.md) and annotated at the affected fixture. As of the current release there are no such deviations.

#### Which upstream version does it track?

This release ports upstream **v2.5.2** (commit `290df46`). The exact pin is recorded in [`NOTICE.md`](NOTICE.md) and updated whenever the port is re-synced.

#### What about character encoding?

Input is expected to be UTF-8 already. The converter parses with `Dom\HTMLDocument::createFromString($html, …, 'UTF-8')`, which forces UTF-8 and does not detect or convert other charsets — the same contract as the upstream Go library, whose `html.Parse()` requires UTF-8. If your HTML is in another charset (for example `ISO-8859-1` or `Windows-1252`), decode it to UTF-8 first; otherwise replacement characters (`�`) may appear in the output. When fetching over HTTP, the `Content-Type` header gives the charset; from a file you may need to read it from a `<meta>` tag or sniff the bytes. `mb_convert_encoding()` from `ext-mbstring` performs the conversion.

#### Is it safe to run on untrusted HTML?

It is a converter, not a sanitizer. It strips script/style-type tags and emits Markdown rather than passing raw HTML through, but it is not designed as a security boundary. If you process hostile input, run it through a dedicated HTML sanitizer first.

## Questions, bugs, and feature requests

Have a usage question or something to discuss? Please use [Discussions](https://github.com/Kntnt/kntnt-html-to-markdown/discussions).

Found a bug or want to request a feature? Please [open an issue](https://github.com/Kntnt/kntnt-html-to-markdown/issues). Search the existing issues first to avoid duplicates.

## Extending

The converter is built from plugins, and the same mechanism the built-in plugins use is available to you. A plugin is any class implementing the `Plugin` interface — a `name()` and an `init()` that registers behaviour — which you then pass to the `Converter` alongside the built-ins.

### The registration surface

Inside `init()`, a plugin registers behaviour through `$converter->register`:

| Method | Registers |
|---|---|
| `preRenderer(fn, priority)` | A DOM transform run before rendering (rewrite or remove nodes). |
| `renderer(fn, priority)` | A render handler tried per node until one returns `RenderStatus::Success`. |
| `rendererFor(tag, type, fn, priority)` | A render handler guarded by a tag name, declaring its `TagType`. |
| `postRenderer(fn, priority)` | A transform over the whole rendered Markdown string. |
| `textTransformer(fn, priority)` | A transform applied to each text node. |
| `escapedChar(...chars)` | Markdown-significant characters that must be escaped in text. |
| `unEscaper(fn, priority)` | A context check deciding, per occurrence, whether an escape survives. |
| `tagType(tag, type, priority)` | An explicit block/inline classification for a tag. |
| `plugin(plugin)` | Another plugin this one depends on. |

Handlers carry an integer **priority** — lower runs first — built from the `Priority` constants `EARLY` (100), `STANDARD` (500), and `LATE` (1000). Ties break on registration order, deterministically.

### A custom plugin

A render handler receives the context, an output `Buffer`, and the current node, and returns a `RenderStatus`: `Success` if it handled the node, or `TryNext` to fall through to the next handler. This example renders `<mark>` to `==…==` (a non-standard highlight syntax, used here only to show the shape):

```php
use Dom\Node;
use Kntnt\HtmlToMarkdown\Converter\Buffer;
use Kntnt\HtmlToMarkdown\Converter\Context;
use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Converter\Plugin;
use Kntnt\HtmlToMarkdown\Converter\Priority;
use Kntnt\HtmlToMarkdown\Converter\RenderStatus;
use Kntnt\HtmlToMarkdown\Dom\Dom;

final class HighlightPlugin implements Plugin
{
    public function name(): string
    {
        return 'highlight';
    }

    public function init(Converter $converter): void
    {
        $converter->register->renderer($this->render(...), Priority::STANDARD);
    }

    private function render(Context $ctx, Buffer $w, Node $node): RenderStatus
    {
        if (Dom::nodeName($node) !== 'mark') {
            return RenderStatus::TryNext;
        }

        $inner = new Buffer();
        $ctx->renderChildNodes($ctx, $inner, $node);
        $w->write('==' . $inner->bytes() . '==');

        return RenderStatus::Success;
    }
}
```

Register it like any other plugin:

```php
$converter = new Converter([
    new BasePlugin(),
    new CommonmarkPlugin(),
    new HighlightPlugin(),
]);
```

The full design — the three-phase render loop, the escaping model, the tag-type system, and the Go→PHP module map — is documented in [`docs/architecture.md`](docs/architecture.md). Read it before writing anything that touches escaping or whitespace.

## Development

### Build from source

```bash
git clone https://github.com/Kntnt/kntnt-html-to-markdown.git
cd kntnt-html-to-markdown
composer install
```

There is nothing to compile — this is a pure PHP library. The Go reference is cloned into `.reference/` (git-ignored) and pinned to the tag recorded in [`NOTICE.md`](NOTICE.md); it is the specification the port is checked against.

### Build a release artefact

This package is distributed through [Packagist](https://packagist.org/packages/kntnt/html-to-markdown); a published Git tag is the release, and Composer builds the dist archive itself. The `.gitattributes` `export-ignore` rules keep that archive lean — tests, fixtures, CI config, and documentation are stripped, leaving only the runtime code and its licensing metadata. To reproduce the archive Composer would download:

```bash
git archive --format=tar.gz --prefix=kntnt-html-to-markdown/ -o kntnt-html-to-markdown.tar.gz HEAD
```

### Run tests

The suite is data-driven and built on [Pest](https://pestphp.com/). Its backbone is the **golden-fixture** tests: upstream's `testdata` golden files are run through the exact converter wiring upstream uses and asserted byte-for-byte. Around them sit ported unit tables — the commonmark and table options and their validation errors, strikethrough cases, the facade, URL resolution and query encoding — plus a handful of edge cases. Static analysis and coding-style checks run alongside.

```bash
composer test        # Pest test suite
composer test:coverage  # Pest with code coverage (needs pcov or Xdebug)
composer stan        # PHPStan, level max
composer cs          # PHP-CS-Fixer, PSR-12 (dry run)
composer cs-fix      # PHP-CS-Fixer, apply fixes
```

All four run in CI on every push and pull request against PHP 8.5. New code is expected to keep the golden fixtures passing, stay green under PHPStan at level max, and conform to PSR-12.

### Technical documentation

- [`docs/architecture.md`](docs/architecture.md) — the porting record: the full Go→PHP module map, the escaping model, the URL-resolution notes, and every bridged Go-vs-PHP implementation difference. Read this before changing the engine.
- [`docs/coding-standards.md`](docs/coding-standards.md) — the project's coding standard.
- [`CLAUDE.md`](CLAUDE.md) / [`AGENTS.md`](AGENTS.md) — entry points for AI coding agents working in the repository.
- [`NOTICE.md`](NOTICE.md) — the upstream pin and the full licensing lineage.

## How you can contribute

Contributions are welcome, large or small. You can help by [opening an issue](https://github.com/Kntnt/kntnt-html-to-markdown/issues) to report a bug or request a feature, by sending a pull request with a fix or improvement, or by improving the documentation. Because **fidelity to the upstream Go library is the contract**, code contributions are expected to keep the golden fixtures passing byte-for-byte; if a change would alter output, explain why, and never weaken the converter to paper over a difference. When in doubt, open a discussion first.

## Acknowledgements

This library stands on the work of others. Thanks to **Johannes Kaufmann** for [`html-to-markdown`](https://github.com/JohannesKaufmann/html-to-markdown), the Go library this is a port of; and to **Dom Christie** ([Turndown](https://github.com/mixmark-io/turndown)) and **Luc Thevenard** ([collapse-whitespace](https://github.com/wooorm/collapse-white-space)), whose whitespace-collapsing code lives on in the port's lineage. The full attribution chain is in [`NOTICE.md`](NOTICE.md).

## License

Released under the MIT License, with dual copyright: Johannes Kaufmann for the original Go library, and Thomas Barregren / Kntnt for the PHP port. The license text is in [`LICENSE`](LICENSE); the complete lineage and per-component attribution are in [`NOTICE.md`](NOTICE.md).

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md) for the release history. The project follows [Keep a Changelog](https://keepachangelog.com/) and [Semantic Versioning](https://semver.org/).
