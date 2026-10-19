<?php

declare(strict_types=1);

return [
    'unknown_type' => 'Unknown tag type [:type].',
    'unknown_type_unnamed' => 'Unknown tag type.',
    'type_required' => 'Name a tag type: there is no default type for plain strings.',

    'tag_not_found' => 'The tag does not exist.',
    'tag_not_found_key' => 'No tag has the key [:key].',
    'tag_not_found_name' => 'No tag of type [:type] is called [:name].',
    'tag_trashed' => 'The tag is deleted.',

    'scope_mismatch' => 'The tag [:tag] belongs to another scope than this model.',
    'scope_mismatch_write' => 'The tag [:tag] belongs to another scope than this handle writes to.',

    'duplicate' => 'A tag with the slug [:slug] already exists.',
    'duplicate_locale' => 'A tag with the slug [:slug] in [:locale] already exists.',
    'duplicate_trashed' => 'A deleted tag holds the slug [:slug] in [:locale]: restore that tag instead.',
    'duplicate_generic' => 'This tag already exists.',

    'invalid' => [
        'blank_name' => 'A tag needs a name.',
        'name_too_long' => 'A tag name may have at most :max characters.',
        'unsluggable' => 'The tag name has no letters or digits to build a slug from.',
        'unsupported_locale' => 'The current locale [:locale] is not a supported locale, so a plain string cannot name a tag. Pass a locale map instead.',
        'invalid_locale' => 'A tag translation is keyed by a malformed locale.',
        'color' => 'A tag colour is a hex colour such as #f24e29.',
        'icon' => 'A tag icon is an identifier or an emoji of at most 64 characters, without spaces or markup.',
        'description_too_long' => 'A tag description may have at most :max characters.',
        'description' => 'A tag description is plain text.',
        'slug' => 'A tag slug is lowercase letters, digits and separators.',
        'manual_slug_free_form' => 'Tags of type [:type] take their slug from the name: only curated types accept a slug.',
        'position' => 'A tag position is zero or more.',
        'global_only' => 'Tags of type [:type] are always global and cannot belong to a scope.',
        'immutable' => 'The [:attribute] of a tag never changes.',
    ],

    'limit_exceeded' => '{1} A record may carry only one tag of type [:type].|[0,*] A record may carry at most :max tags of type [:type].',
    'type_not_allowed' => 'Tags of type [:type] are not allowed on :model.',
    'type_mismatch' => 'Expected a tag of type [:expected], got one of type [:actual].',

    'hierarchy' => [
        'cycle' => 'A tag cannot move below itself or its own descendants.',
        'too_deep' => '{1} Tags of this type cannot have children.|[0,*] Trees of this type are at most :max levels deep.',
        'flat_type' => 'Tags of type [:type] are flat: they have no parents.',
        'parent_trashed' => 'The parent tag is deleted: restore it first.',
    ],

    'has_children' => 'The tag [:tag] has children: delete it with its children or move them to its parent.',
    'unknown_alias' => 'Unknown :kind alias.',
    'unknown_alias_missing' => 'The :kind reference names no record.',
    'unsaved_taggable' => 'Save the :model before tagging it.',
    'type_already_defined' => 'The tag type [:type] is defined twice: by :first and by :second. Adjust another package\'s type with Tags::extend() or tags.overrides.',

    'invalid_type' => [
        'name' => 'A tag type name is lowercase letters, digits, - and _, with one optional package prefix such as events:category.',
        'frozen' => 'The tag type [:type] is already in use: adjust it with Tags::extend() or tags.overrides.',
        'rule' => 'The tag type [:type] has an invalid rule: :rule.',
    ],

    'config' => [
        'key_type_mismatch' => 'The key of :model does not match tags.key_type: every taggable and scope model must use that key type.',
        'invalid' => 'Configuration value [:key] must be :expected.',
    ],

    'read_only_relation' => [
        'attach' => 'The tags relation is read-only: tag a record with attachTags() or Tags::attach(). In a model factory, use afterCreating(fn ($model) => $model->attachTags(...)) instead of hasAttached().',
        'detach' => 'The tags relation is read-only: untag a record with detachTags() or clearTags() (Tags::detach(), Tags::clear()).',
        'sync' => 'The tags relation is read-only: replace a record\'s tags with syncTags() or Tags::sync().',
        'sync_without_detaching' => 'The tags relation is read-only: add tags with attachTags() or Tags::attach().',
        'toggle' => 'The tags relation is read-only: use attachTags() and detachTags() (Tags::attach(), Tags::detach()).',
        'update_existing_pivot' => 'The tags relation is read-only: taggings have no editable pivot columns.',
        'create' => 'The tags relation is read-only: create tags with Tags::type($type)->make(...)->create() or findOrCreate(), then attachTags(). In a model factory, use afterCreating(fn ($model) => $model->attachTags(...)).',
    ],

    'api' => [
        'tree_too_large' => 'The tree has more than :max tags: page through the list instead.',
    ],
];
