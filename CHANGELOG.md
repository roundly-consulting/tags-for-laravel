# Changelog

All notable changes to `tags-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- Typed tag taxonomies for any Eloquent model: types declared in code (`Tags::define()`) or
  `tags.types`, each flat or hierarchical, single- or multi-select (`max`), curated or free-form,
  with stable slugs, global-only and private options. Hosts adjust any package's type with
  `Tags::extend()` or `tags.overrides`, whatever the provider order; `php artisan tags:types`
  lists every type and where it came from.
- Translatable names and descriptions with one slug per locale; a string names a tag by its slug
  along the locale chain, so "Rock", "rock" and "ROCK!" are one tag and a taken slug is a
  duplicate, never "rock-2".
- Global or tenant-scoped tags (any scope model), uniqueness per type and scope enforced by the
  database, and scoped catalog handles that never write a global tag.
- `HasTags` with `attachTags()`, `detachTags()`, `syncTags()`, `clearTags()`, type-aware reads
  that never N+1, the `withAnyTags`, `withAllTags`, `withoutTags` and `withTagsOf` scopes (with
  descendants), and a read-only `tags()` relation.
- Category trees: create under a parent, move, reorder, delete with refuse/reparent/delete,
  restore, one-query trees and ancestors.
- Usage counts, `popular()` (by usage, or live on one taggable class with a visibility
  constraint) and `suggest()` autocomplete across locales.
- `TagsRule` for form requests, `TagsFilter` for query-builder index endpoints,
  `TagSummaryResource` for nesting tags in host resources.
- An opt-in JSON API (`tags.routes.enabled`): types, list or tree, show, suggest, popular and
  manager CRUD with moves and reorders, gated by the host's abilities, with no class names and no
  existence oracle across scopes.
- Events (`Tagged`, `Untagged`, `TagCreated`, `TagUpdated`, `TagMoved`, `TagsReordered`,
  `TagDeleted`, `TagRestored`) after commit, a record-and-pass-through `Tags::fake()` and Pest
  expectations.
- `tags:doctor` and `tags:repair` for paths, scope keys, usage counts and orphan taggings.
- English and Slovak translations.
