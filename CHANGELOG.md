# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.3] – 2026-06-22

### Changed

- Lowered the PHP floor from 8.5 to 8.4. Relative-URL resolution no longer uses the PHP-8.5-only `Uri\Rfc3986\Uri` extension; `UrlResolver` now hand-ports RFC 3986 §5.2 reference resolution (mirroring Go's `net/url`), with byte-for-byte-identical output verified by the golden fixtures. The remaining floor driver is the native `Dom\HTMLDocument` HTML5 parser, which requires PHP 8.4. CI and PHPStan's `phpVersion` now target 8.4.

## [0.1.2] - 2026-06-21

### Changed

- Bumped the documented upstream pin from v2.5.1 to v2.5.2. The ported engine is byte-for-byte unchanged: upstream's v2.5.2 release consists solely of documentation and Go-dependency updates, none of which touch the converter, the plugins, or the golden fixtures.
- Documented character-encoding expectations in the README FAQ. Input is expected to be UTF-8; the converter forces UTF-8 when parsing and does not detect or convert other charsets. Decode non-UTF-8 HTML (for example with `mb_convert_encoding()`) before passing it in.

## [0.1.1] - 2026-06-04

### Added

- GitHub issue forms (bug report, feature request) and a pull request template.

### Changed

- Rewrote the README around a users / extenders / contributors structure, documenting the plugin extension API and clarifying GFM coverage.

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

[Unreleased]: https://github.com/Kntnt/kntnt-html-to-markdown/compare/v0.1.3...HEAD
[0.1.3]: https://github.com/Kntnt/kntnt-html-to-markdown/releases/tag/v0.1.3
[0.1.2]: https://github.com/Kntnt/kntnt-html-to-markdown/releases/tag/v0.1.2
[0.1.1]: https://github.com/Kntnt/kntnt-html-to-markdown/releases/tag/v0.1.1
[0.1.0]: https://github.com/Kntnt/kntnt-html-to-markdown/releases/tag/v0.1.0
