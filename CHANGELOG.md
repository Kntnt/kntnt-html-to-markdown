# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-06-03

Initial release. A faithful PHP 8.5 port of `JohannesKaufmann/html-to-markdown` v2 (commit `b0879832`), covering the converter core and the `base`, `commonmark`, `strikethrough`, and `table` plugins.

### Added

- `Kntnt\HtmlToMarkdown\HtmlToMarkdown::convert()` — a thin convenience facade mirroring upstream's `ConvertString`.
- `Kntnt\HtmlToMarkdown\Converter\Converter` — the full converter with a priority-ordered plugin registry, renderer-per-tag dispatch, before/after hooks, and options (`withDomain`, include/exclude selectors, escape mode, …).
- The `base` plugin: node removal, whitespace collapsing, character-entity and smart escaping, final trimming.
- The `commonmark` plugin: headings, bold/italic, links, images, inline and block code, blockquotes, ordered/unordered lists, dividers, hard breaks, and comments.
- The `strikethrough` plugin: `<del>`, `<s>`, and `<strike>`.
- The `table` plugin: GFM tables with alignment, colspan/rowspan, captions, header promotion, and presentation-table handling.
- Context-aware ("smart") escaping ported from upstream's `ESCAPING.md`, including the internal sentinel byte markers.
- Golden-fixture parity: upstream's `testdata` golden files are asserted byte-for-byte by the Pest suite.

### Notes

- Task lists, autolinks, and the GFM tagfilter are intentionally **not** implemented.
- HTML is parsed with PHP's native `Dom\HTMLDocument` (HTML5). Any place where this legitimately differs from Go's `x/net/html` is documented in `docs/architecture.md` and annotated at the affected fixture.

[Unreleased]: https://github.com/Kntnt/kntnt-html-to-markdown/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Kntnt/kntnt-html-to-markdown/releases/tag/v0.1.0
