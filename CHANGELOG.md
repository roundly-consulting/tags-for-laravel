# Changelog

All notable changes to `tags-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Changed

- `GET /tag-types` lists only the types the caller may read: a type the
  `tags.authorization.abilities.view` gate denies is left out, as its show already answered 403.
  No view ability configured still lists every type.
- A scoped tag's `scope` in the API renders its `tags.scopes` alias (`{"id": 1, "type": "team"}`),
  the value `?scope=team:1` takes, instead of the class basename. A scope model without an alias
  still renders its basename, and `tags.resources.scope` still swaps the resource.
- A serialised tag's `pivot` no longer carries `taggable_type` — without a morph map it was the
  host model's class name. The attribute is still there on the `Tagging` model.
- Maintenance: requires package-toolkit `^1.4.0`; the opt-in API's off-by-default switch now uses
  the toolkit's `enabledByDefault` (behaviour unchanged).

### Fixed

- Moving a tag to a later position under the same parent puts it at that position; it used to
  land one place early.
- `tags:repair` (and `Tags::repair()`) fixes scope keys before rebuilding paths, so a tag whose
  scope key drifted keeps its place in the tree, and `tags:doctor` no longer reports such a tag as
  a path error.
- The API's tag list keeps the scope, filters, sort and page size in its pagination links.
- `filter[depth]` past the depth column's range lists nothing instead of answering 500 on
  PostgreSQL.
- A tag key in another spelling the database accepts — a uuid in upper case, a ulid in lower
  case, a number with leading zeros — is found by `findMany()`, `TagsRule::byId()`, `reorder()`
  and `TagsFilter::byId()` like the canonical key.
- `detachTags()` by name removes a global tag the model carries even after its scope got its own
  tag of that name; attaching still prefers the scope's tag.
- The API places a tag at every position the `integer` rule accepts (`1.0`, `"+1"`, `1e0`)
  instead of silently appending it.
- `Tags::fake()` no longer counts a restore of a live tag, an update that changed nothing, a
  second delete of a deleted tag, a move to the tag's current parent and position or a reorder to
  the current order as a restore, update, delete, move or reorder.
- `assertCleared($model, $type)` passes after `clearTags()` or the deletion hook, which clear
  every type.
- `toHaveTags()` and `toHaveNoTags($type)` read the database, never a `tags` relation loaded
  before another instance wrote.
- A tag named "0" is found among other names in `withAnyTags()` and in tagging calls.
- A sibling at the position cap no longer makes appending or inserting under the same parent fail
  with a database error (or store a position past the cap): the write is refused as an invalid
  position.
- A tag description may contain a bare `<` ("I <3 jazz"); only tag-shaped markup is refused.
- The migrations refuse a configured table name whose derived index names would be too long for
  MySQL or collide on PostgreSQL, naming the config key, instead of failing halfway through.
- Two concurrent deletes of the same flat tag fire `TagDeleted` once; the second returns the
  deleted tag.
- A move to the tag's current parent and position, or a reorder to the current order, no longer
  fires `TagMoved` or `TagsReordered`.

## 1.0.0 - 2026-10-20

Initial public release.

### Added

- Typed tag taxonomies for any Eloquent model: types declared in code (`Tags::define()`) or
  `tags.types`, each flat or hierarchical, single- or multi-select (`max`), curated or free-form,
  limited to given models (`on()`, `onlyOn()`), with translated labels, stable slugs, global-only
  and private options. Hosts adjust any package's type with `Tags::extend()` or `tags.overrides`,
  whatever the provider order; `php artisan tags:types` lists every type and where it came from.
- Translatable names and descriptions with one slug per locale, plus an optional colour and icon
  per tag; a string names a tag by its slug along the locale chain, so "Rock", "rock" and "ROCK!"
  are one tag and a taken slug is a duplicate, never "rock-2".
- Global or tenant-scoped tags (any scope model), uniqueness per type and scope enforced by the
  database, and scoped catalog handles that never write a global tag. A tag or key of another
  scope answers exactly like a missing one, in every verb and in `TagsRule`.
- Idempotent seeding: `Tags::type($type)->make('Jazz')->under($music)->findOrCreate()` returns an
  existing tag unchanged or creates it with every attribute; `TypeTags::findTrashed()` finds a
  deleted tag of exactly the handle's scope by name, slug or key, to restore it. A deleted tag's
  name stays reserved: using it again brings the tag back as it was, in its place in the tree —
  only `create()` gives it new attributes, and only a given parent moves it.
- `HasTags` with `attachTags()`, `detachTags()`, `syncTags()`, `clearTags()`, type-aware reads
  that never N+1, the `withAnyTags`, `withAllTags`, `withoutTags` and `withTagsOf` scopes (with
  descendants), and a read-only `tags()` relation.
- Category trees: create under a parent, move, reorder, delete with refuse/reparent/delete,
  restore, one-query trees and ancestors, and `Tags::loadAncestors()` to load the ancestors of a
  whole page of tags in one query (`TagSummaryResource` then renders them as `ancestors`).
- A plain `$tag->delete()` (an admin panel) is safe: reads ignore the leftover taggings and a
  restore starts clean.
- Usage counts, `popular()` (by usage, or live on one taggable class with a visibility
  constraint) and `suggest()` autocomplete across locales.
- `TagsRule` for form requests (`Tags::for($model)->findMany($ids, $type)` turns the keys it
  validated into tags, in the model's scopes), `TagsFilter` for query-builder index endpoints,
  `TagSummaryResource` for nesting tags in host resources.
- An opt-in JSON API (`tags.routes.enabled`): types, list or tree, show, suggest, popular and
  manager CRUD with moves and reorders, gated by the host's abilities, with no class names and no
  existence oracle across scopes. A switch that is not set — missing from a published config,
  `null` or blank — keeps it off.
- Events (`Tagged`, `Untagged`, `TagCreated`, `TagUpdated`, `TagMoved`, `TagsReordered`,
  `TagDeleted`, `TagRestored`) after commit, a record-and-pass-through `Tags::fake()` with an
  assertion for every operation (repairs and tags created or restored on the fly included) and
  Pest expectations. `TagChanges` lists the tags a write attached, detached, created and restored.
- `tags:doctor` and `tags:repair` for paths, scope keys, usage counts, orphan taggings and
  taggings left on deleted tags; the doctor also checks the app locale against
  `translatable.locales` and lists tags deeper than their type's cap, and repair runs in chunks,
  safe under live traffic.
- Safe under concurrent writes: no duplicate tags, no broken `max` limit and exact usage counts
  with parallel writers, proven against real PostgreSQL and MySQL; a write that hits a deadlock
  is retried.
- Bigint, UUID or ULID keys (`tags.key_type`, `tags.primary_key_type`), renamable tables, a
  swappable `Tag` model (`tags.models.tag`) and a `php artisan about` section.
- English and Slovak translations.
