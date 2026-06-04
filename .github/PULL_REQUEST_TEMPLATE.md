<!--
Thanks for contributing! Please fill in the sections below and delete this
comment. Keep it brief — a couple of sentences per section is plenty.
-->

## Summary

<!-- What does this change do, and why? -->

Closes #

## Type of change

<!-- Mark all that apply with an "x". -->

- [ ] Bug fix (a non-breaking change that fixes an issue)
- [ ] New feature (a non-breaking change that adds capability)
- [ ] Breaking change (changes existing behaviour or output)
- [ ] Documentation only
- [ ] Refactor / internal (no change to output)
- [ ] Tests / tooling

## How was it tested?

<!--
Describe how you verified the change. For most changes this means the
Composer scripts below; mention any new fixtures or cases you added.
-->

- [ ] `composer test` — Pest suite passes (golden fixtures still match byte-for-byte)
- [ ] `composer stan` — PHPStan at level max passes
- [ ] `composer cs` — PSR-12 coding style passes

## Checklist

- [ ] Follows the project's [coding standards](docs/coding-standards.md)
- [ ] Tests added or updated where it makes sense
- [ ] Output-changing behaviour stays aligned with the upstream Go library (or the deviation is documented in `docs/architecture.md`)
- [ ] `CHANGELOG.md` and any relevant docs are updated
