# CLAUDE.md

Guidance for AI coding agents (Claude Code, Copilot, Cursor, Codex, …) working with code in this repository.

## Coding standards

@docs/coding-standards.md

## Project context

`kntnt/html-to-markdown` is a dependency-free PHP 8.5 library that converts HTML into GitHub Flavored Markdown. It is a faithful port of the Go library [`JohannesKaufmann/html-to-markdown`](https://github.com/JohannesKaufmann/html-to-markdown) (v2) — specifically its `converter` core and the `base`, `commonmark`, `strikethrough`, and `table` plugins. The upstream CLI, the hosted REST API / demo, and task-list handling are deliberately out of scope. The package is consumed by other Kntnt projects via Composer (for example, to serve per-page Markdown to LLMs).

## Architecture

The full Go→PHP module mapping lives in `docs/architecture.md`, which the initial build produces (along with `docs/coding-standards.md`); it does not exist in a fresh checkout. Until then, the load-bearing decisions are:

- **Parser:** PHP's native `Dom\HTMLDocument` (HTML5-compliant, with `querySelectorAll`) replaces both Go's `golang.org/x/net/html` and `cascadia`. No `Masterminds/html5`; no legacy `DOMDocument` (it mangles HTML5).
- **Zero runtime dependencies.** Every upstream dependency maps to a PHP built-in: `Dom\*`, `levenshtein()`, `mbstring`, and `Uri\Rfc3986\Uri` (PHP 8.5) for `withDomain` URL resolution.
- **Plugin / renderer model, ported faithfully.** A `Plugin` registers renderers per HTML tag with priorities; a `TagType` enum (block / inline) drives whitespace collapsing; before/after hooks transform the DOM and the final Markdown. The external interface is deep: a `Converter` plus a thin `HtmlToMarkdown::convert()` convenience facade (the analogue of Go's `ConvertString`).
- **Escaping** follows upstream's `ESCAPING.md` (smart, context-aware escaping) — the single most fidelity-critical component. Upstream's internal sentinel markers (the bell character and private-use runes) are ported as byte markers.
- **Errors:** Go's error returns become thrown exceptions under `\Kntnt\HtmlToMarkdown\Exception`.

## Project-specific conventions

- **Identity:** namespace `\Kntnt\HtmlToMarkdown`; Composer package `kntnt/html-to-markdown`; PSR-4 source in `src/`. PHP **8.5** floor, pragmatically modern — no back-compatibility shims.
- **Fidelity is the contract.** Upstream's golden fixtures (`testdata/*/GoldenFiles`) are ported into the Pest suite and asserted byte-for-byte. Deviate only where PHP's HTML5 parser legitimately differs from `x/net/html`; document every such deviation in `docs/architecture.md` and annotate it at the fixture. Never weaken the converter to paper over a parser difference.
- **Upstream pin:** the exact ported upstream tag/commit is recorded in `NOTICE.md` and `README.md`. Re-syncing with upstream means bumping that pin and re-porting any changed fixtures.
- **License:** MIT, with dual copyright (Johannes Kaufmann for the original, Thomas Barregren / Kntnt for the PHP port). `NOTICE.md` carries the full lineage, including the Turndown / collapse-whitespace ancestry of the whitespace-collapse code.
- **Tooling:** Composer, Pest, PHPStan `--level max`, pcov, PHP-CS-Fixer (PSR-12). No DDEV — this is a pure library with no server component.
