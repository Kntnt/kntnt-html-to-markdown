# Architecture

This document is the porting record for `kntnt/html-to-markdown`. It maps every ported Go module to its PHP counterpart, explains the decisions where Go and PHP differ, and describes the escaping model in enough detail to maintain it. It is written for someone who knows the upstream Go library and wants to understand how the PHP port stays faithful to it.

The port targets upstream **v2.5.2** (commit `290df46`). That pin is recorded in `NOTICE.md` and `README.md`; re-syncing means bumping it and re-porting any changed fixtures.

## Fidelity is the contract

The upstream golden fixtures (`plugin/*/testdata/GoldenFiles/*`) are copied verbatim into `tests/Fixtures/` and asserted byte-for-byte by the Pest suite. As of this release **every golden fixture passes without modification** — there are no output deviations from upstream, and therefore no fixture annotations. The differences described below are *implementation* differences (Go semantics vs. PHP semantics) that had to be bridged precisely so that the output stays identical; none of them change the output.

If a future upstream sync introduces a genuine HTML5-parser difference (PHP's `Dom\HTMLDocument`, backed by lexbor, vs. Go's `golang.org/x/net/html`), that is the only case where a fixture may legitimately differ. Such a deviation must be documented in this file **and** annotated at the fixture. Never weaken the converter to paper over a parser difference.

## Go → PHP module map

| Upstream Go | PHP port | Notes |
|---|---|---|
| `marker/marker.go` | `src/Marker/Marker.php` | Bell (`\a`) and U+F002 sentinels, as exact byte strings. |
| `converter/converter.go`, `convert.go`, `register.go`, `render.go`, `status.go`, `prioritized.go`, `plugin.go`, `ctx.go`, `escape.go` | `src/Converter/*` | Split into `Converter`, `Register`, `Context`, `Buffer`, `Options`, `Plugin`, and the `TagType` / `RenderStatus` / `EscapeMode` enums plus the `Priority` constants. |
| `converter/url.go` | `src/Converter/UrlResolver.php` | See *URL resolution* below. |
| `collapse/collapse.go`, `whitespace.go`, `is_node.go` | `src/Collapse/Collapse.php`, `Whitespace.php` | The Turndown-lineage whitespace collapser. |
| `internal/escape/*` | `src/Internal/Escape/Escape.php` | The context-aware un-escapers and their byte/rune neighbour lookups. |
| `internal/textutils/*` | `src/Internal/TextUtils/TextUtils.php` | Whitespace/newline/fence/quote helpers, grouped into one class. |
| `internal/domutils/*` | `src/Internal/DomUtils/DomUtils.php` | The pre-render DOM rewrites, grouped into one class. |
| `github.com/JohannesKaufmann/dom` (external dep, v0.2.0) | `src/Dom/Dom.php`, `src/Dom/Tags.php` | Node navigation/mutation helpers and the block/inline/heading classification. |
| `html.Render` (from `x/net/html`) | `src/Dom/HtmlRenderer.php` | Raw-HTML serialization of a node, with comment escaping. See *Comment rendering*. |
| `plugin/base/*` | `src/Plugin/Base/BasePlugin.php`, `Renderers.php` | Node removal, collapsing, escaping, trimming. |
| `plugin/commonmark/*` | `src/Plugin/Commonmark/CommonmarkPlugin.php` | All `render_*` handlers and the pre-render clean-ups, in one cohesive class. |
| `plugin/strikethrough/*` | `src/Plugin/Strikethrough/StrikethroughPlugin.php` | |
| `plugin/table/{1_select,2_collect,3_render,table,utils}.go` | `src/Plugin/Table/TablePlugin.php` | The three table phases, in one class. |
| `convert.go` (`ConvertString`) | `src/HtmlToMarkdown.php` | The thin facade. |
| Go error returns | `src/Exception/*` | A typed hierarchy: the `HtmlToMarkdownException` marker interface, `ConversionException`, and `InvalidOptionException`. |

### Deliberately not ported

The upstream CLI (`cli/`), the hosted REST API / demo, the `doublestar` / `termenv` / `cascadia` dependencies, and task-list (checkbox) handling are out of scope, as are autolinks and the GFM tagfilter. The CLI's include/exclude CSS-selector feature *is* lifted into the library (as `Options::$includeSelector` / `$excludeSelector`, applied with `querySelectorAll`), because it is broadly useful and needs no extra dependency.

The package has **zero runtime dependencies**. Every upstream dependency maps to a PHP built-in:

| Upstream dependency | PHP built-in |
|---|---|
| `golang.org/x/net/html` (parser) | `Dom\HTMLDocument` (HTML5, lexbor) |
| `github.com/andybalholm/cascadia` (CSS selectors) | `Dom\*::querySelectorAll` |
| `github.com/JohannesKaufmann/dom` | `Dom\Node` navigation (ported in `src/Dom`) |
| `net/url` (URL resolution) | `Uri\Rfc3986\Uri` (PHP 8.5) + hand-ported query encoding |
| `unicode/utf8`, `unicode` | `mbstring`, PCRE with the `u` flag |

## The DOM model: Go nodes vs. PHP nodes

Go operates on `*html.Node`, a mutable struct with `FirstChild` / `NextSibling` / `Parent` pointers and a `Data` field that holds the tag name (for elements), the text (for text nodes), or the comment body. PHP's `Dom\HTMLDocument` produces a live W3C DOM tree. The mapping is mostly mechanical (`FirstChild` → `firstChild`, `Parent` → `parentNode`, …), with four points that need care:

- **Node names are lowercased through `Dom::nodeName()`.** PHP's `nodeName` / `tagName` return *uppercase* for HTML elements (`H1`), whereas upstream's `dom.NodeName` follows goquery and returns lowercase (`h1`). Every name comparison in the port goes through `Dom::nodeName()`, which returns the element's `localName` (lowercase) and the `#text` / `#comment` / `#document` pseudo-names.
- **Node types use the global `XML_*` constants and `instanceof`.** PHP's new DOM does not expose `ELEMENT_NODE`-style constants on `Dom\Node`; the port classifies nodes with `instanceof Dom\Element` / `Dom\Text` / `Dom\Comment` / `Dom\Document` / `Dom\DocumentType`.
- **Renaming an element is done with `Dom\Element::rename()`.** Go flips a tag name by assigning `node.Data = "strong"` because its node is a plain struct. PHP exposes the same effect through `Element::rename($namespace, $name)`, which mutates the element in place and keeps the very same object — so references held by the traversal stay valid. The HTML namespace URI must be passed, or the call throws.
- **Deferred traversal becomes reverse iteration.** A couple of upstream functions (`base.preRenderRemove`, `domutils.LeafBlockAlternatives`) use Go's `defer finder(child)` inside a loop, which runs the children in *reverse* sibling order after the loop body. The port snapshots the children and iterates them with `array_reverse`, reproducing that order exactly.

## Whitespace classification

Go's `unicode.IsSpace` is wider than PHP's `trim()`: it includes NBSP (U+00A0), NEL (U+0085), and the Unicode space/line/paragraph separators. Because trimming decisions are fidelity-critical (they determine where spaces survive in the output), the port defines the exact `unicode.IsSpace` set once as `TextUtils::WHITESPACE` (a PCRE character class) and uses it for every `bytes.TrimSpace` / `strings.TrimSpace` / `SurroundingSpaces` analogue. The `collapse` pass keeps Go's narrower ASCII-only set (`[ \t\r\n]`), matching `replaceAnyWhitespaceWithSpace`.

## Escaping

Escaping is the most fidelity-critical component, ported faithfully from upstream's `ESCAPING.md` and `internal/escape`. It is a two-phase, context-aware process built on the bell sentinel (`Marker::ESCAPING`, the single byte `0x07`):

1. **Escape phase (during render).** `BasePlugin::handleTextTransform` first replaces `<`/`>` with `&lt;`/`&gt;` (note: `&` is deliberately *not* escaped, matching upstream), then calls `Converter::escapeContent`, which prefixes every Markdown-significant character (registered by the plugins via `EscapedChar`) with the bell sentinel. The NUL byte is replaced with U+FFFD for safety.
2. **Un-escape phase (post-render).** Once the whole document is rendered, `Converter::unEscapeContent` walks the text. For each sentinel, it asks every registered un-escaper (`Escape::isAtxHeader`, `isFencedCode`, `isItalicOrBold`, …) whether the run *at that position, in its fully-rendered context* would be misread as Markdown. If any says yes, the sentinel becomes a backslash; otherwise it is simply dropped. The un-escapers skip over sentinels when looking at neighbours, so escaped and unescaped characters interleave correctly.

This is why, for example, `fake **bold**` in plain text renders as `fake \*\*bold\**`: each `*` that could *open* emphasis (is left-flanking — not followed by whitespace) gets a backslash, while the final `*` before a space does not. The same fixture confirms `*not emphasized*` → `\*not emphasized*`. Lists and tables additionally run an *early* un-escape pass on their own cells/items so they can measure the rendered width for padding and indentation.

The U+F002 code-block-newline sentinel (`Marker::CODE_BLOCK_NEWLINE`) protects newlines inside fenced code from the whitespace post-processors; a final post-render pass swaps it back to `\n`.

## Comment rendering

When a node is emitted as raw HTML (kept comments via `Renderers::renderAsHtml`, or the synthetic list-end marker), upstream uses `html.Render`, which **escapes the data inside a comment** — `&` becomes `&amp;`, and a `>` becomes `&gt;` except after ordinary text — to stop a comment from being broken out of. PHP's `saveHtml` does not do this. `HtmlRenderer::escapeComment` reproduces Go's `escapeComment` byte-for-byte; this is what makes the `code` golden fixture's `Whitespace &amp; Special Characters` comment match. Everything other than a comment defers to PHP's serializer, which matches Go's element rendering. This is a *renderer* difference, not a parser difference — PHP keeps `&amp;` verbatim in comment *data* on parse, exactly like Go.

## URL resolution

`UrlResolver` ports `converter/url.go`. Two Go-specific behaviours need care:

- **Lenient decomposition.** Go's `net/url.Parse` is lenient — it accepts raw spaces and other characters in the query and opaque parts (so a `mailto:` with a `subject=Greetings to Johannes` parses, and the query is then re-encoded). PHP's `Uri\Rfc3986\Uri` is strict RFC 3986 and rejects those inputs. The port therefore splits a URL into scheme / authority / path / query / fragment with the lenient RFC 3986 reference grammar (a regex), and uses `Uri\Rfc3986\Uri` only for the one step that needs real resolution semantics: resolving a *relative* URL against the base domain (`Options::$domain`), where the inputs are well-formed. This reproduces Go's results, including the cases where Go returns an error and falls back to plain percent-encoding (a space in a host, brackets in a path): the regex parses them, but the final percent-encoding pass yields the same bytes.
- **Hand-ported query encoding.** The query re-encoding (`ParseAndEncodeQuery`) preserves parameter order and re-encodes each component with Go's `url.QueryUnescape` / `url.QueryEscape` semantics — including the quirk that a token which fails to decode (e.g. a lone `%`) is left untouched, and that a space becomes `+` and is then rewritten to `%20`. These are ported by hand (`queryUnescape` / `queryEscape`) rather than using PHP's `urlencode`, whose character set and error handling differ from Go's.

## Plugin / renderer model

The converter is a registry plus a three-phase loop, ported faithfully:

- Plugins register **pre-render** hooks (DOM transforms), **render** handlers (per node, tried in priority order until one returns `Success`), **post-render** hooks (whole-text transforms), **text transformers** (per text node), **un-escapers**, **escaped characters**, and **tag types**. Handlers carry an integer **priority** (lower runs first); ties break on registration order, which the port makes deterministic with a sequence counter (Go's sort is unstable, but the ported plugins never rely on a particular order among equal-priority handlers).
- `RendererFor` is the escape hatch: it records a tag type and registers a render handler guarded by the tag name. The commonmark golden test uses it to keep HTML comments as raw blocks.
- A node's effective **tag type** comes from an explicit registration if one exists (lowest priority wins), otherwise from the built-in block/inline classification in `Dom\Tags`. The type drives whitespace collapsing and the render fallback (block nodes are wrapped in blank lines).

## Testing

The suite is data-driven. `tests/GoldenFilesTest.php` runs every upstream golden fixture through the exact converter wiring upstream uses for that plugin (the commonmark suite overrides `#comment` to raw HTML; the table and strikethrough suites do not). The remaining files port upstream's inline test tables — commonmark and table options and their validation errors, strikethrough cases, the facade and carriage-return behaviour, and the full `defaultAssembleAbsoluteURL` / `ParseAndEncodeQuery` tables — plus a handful of edge cases (disabled escaping, raw-HTML rendering, `convertNode`, an unnamed plugin). Line coverage of `src/` is ~94%.
