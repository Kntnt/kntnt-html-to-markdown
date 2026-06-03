# NOTICE

`kntnt/html-to-markdown` is a faithful PHP port of the Go library [`JohannesKaufmann/html-to-markdown`](https://github.com/JohannesKaufmann/html-to-markdown) (version 2).

This product includes software developed by Johannes Kaufmann and contributors. The PHP port was written by Thomas Barregren / Kntnt.

## Upstream pin

This release ports upstream **v2.5.1** (commit `b0879832e6124221dfe878695391af0e37ec112a`). Re-syncing with upstream means bumping this pin and re-porting any changed golden fixtures.

## Lineage and copyright

The library carries a layered lineage. Each layer is preserved under the MIT License.

The **converter core** and the **`base`, `commonmark`, `strikethrough`, and `table` plugins** are ported from `JohannesKaufmann/html-to-markdown` (v2).

> MIT License — Copyright (c) 2018 Johannes Kaufmann

The **whitespace-collapsing code** (`src/Collapse/`) has a longer ancestry. Johannes Kaufmann ported it from JavaScript to Go; it was in turn adapted from the [Turndown](https://github.com/mixmark-io/turndown) library by Dom Christie, which itself adapted the [collapse-whitespace](https://github.com/wooorm/collapse-white-space) library by Luc Thevenard. This PHP port continues that chain.

> MIT License — Copyright (c) 2017 Dom Christie
> MIT License — Copyright (c) 2014 Luc Thevenard
> MIT License — Copyright (c) 2018 Johannes Kaufmann

The logic that handles **whitespace around bold/italic delimiters** (`SurroundingSpaces` in `src/Internal/TextUtils/`) was initially developed in the [anyproto fork](https://github.com/anyproto/html-to-markdown) by Roman Khafizianov and Mikhail, then merged upstream by Johannes Kaufmann.

> MIT License — Copyright (c) 2018 Johannes Kaufmann
> MIT License — Copyright (c) 2020 Roman Khafizianov
> MIT License — Copyright (c) 2023 Mikhail

The **PHP port** as a whole:

> MIT License — Copyright (c) 2026 Thomas Barregren / Kntnt

The full license text is in [`LICENSE`](LICENSE).

## What was deliberately not ported

The upstream CLI, the hosted REST API and demo, the `doublestar` and `termenv` dependencies, and task-list (checkbox) handling are out of scope. Every upstream runtime dependency is replaced by a PHP built-in, so this package has **zero runtime dependencies** beyond `ext-dom` and `ext-mbstring`. See [`docs/architecture.md`](docs/architecture.md) for the full Go→PHP module map and the parser-difference deviations.
