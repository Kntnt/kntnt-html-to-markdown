# AGENTS.md

Guidance for AI coding agents (Claude Code, Copilot, Cursor, Codex,
…) working with code in this repository.

## Coding standards

@docs/coding-standards.md

## Project context

`kntnt/html-to-markdown` is a dependency-free PHP 8.4 library that converts HTML into GitHub Flavored Markdown. It is a faithful port of the Go library [`JohannesKaufmann/html-to-markdown`](https://github.com/JohannesKaufmann/html-to-markdown) (v2) — its converter core plus the `base`, `commonmark`, `strikethrough`, and `table` plugins. The upstream CLI, hosted REST API / demo, and task-list handling are out of scope. The package is consumed by other Kntnt projects via Composer (for example, to serve per-page Markdown to LLMs).

## Architecture

The full Go→PHP module map, the parser-difference deviations, and the escaping notes live in [`docs/architecture.md`](docs/architecture.md) — read it before changing the engine. Load-bearing decisions: HTML is parsed with PHP's native `Dom\HTMLDocument` (HTML5, with `querySelectorAll`), never legacy `DOMDocument`; the package has zero runtime dependencies (every upstream dependency maps to a PHP built-in); the plugin/renderer model is ported faithfully (a `Plugin` registers renderers per tag with priorities, a `TagType` enum drives whitespace collapsing, before/after hooks transform the DOM and the final Markdown); escaping follows upstream's `ESCAPING.md` exactly, including the sentinel byte markers.

## Project-specific conventions

- **Identity:** namespace `\Kntnt\HtmlToMarkdown`; Composer package `kntnt/html-to-markdown`; PSR-4 source in `src/`. PHP **8.4** floor (from the native `Dom\HTMLDocument` HTML5 parser), no back-compatibility shims.
- **Fidelity is the contract.** Upstream's golden fixtures are asserted byte-for-byte by the Pest suite. Deviate only where PHP's HTML5 parser legitimately differs from `x/net/html`; document every such deviation in `docs/architecture.md` and annotate it at the fixture. Never weaken the converter to paper over a parser difference.
- **Upstream pin:** the exact ported upstream tag/commit is recorded in `NOTICE.md` and `README.md`. Re-syncing means bumping that pin and re-porting any changed fixtures.
- **Tooling:** Composer, Pest, PHPStan `--level max`, pcov, PHP-CS-Fixer (PSR-12). No DDEV — pure library, no server component.
