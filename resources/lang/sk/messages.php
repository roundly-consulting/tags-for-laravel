<?php

declare(strict_types=1);

return [
    'unknown_type' => 'Neznámy typ štítka [:type].',
    'unknown_type_unnamed' => 'Neznámy typ štítka.',
    'type_required' => 'Uveďte typ štítka: pre obyčajné reťazce nie je nastavený predvolený typ.',

    'tag_not_found' => 'Štítok neexistuje.',
    'tag_not_found_key' => 'Žiadny štítok nemá kľúč [:key].',
    'tag_not_found_name' => 'Žiadny štítok typu [:type] sa nevolá [:name].',
    'tag_trashed' => 'Štítok je vymazaný.',

    'scope_mismatch' => 'Štítok [:tag] patrí do iného rozsahu ako tento záznam.',
    'scope_mismatch_write' => 'Štítok [:tag] patrí do iného rozsahu, než do ktorého zapisuje tento prístup.',

    'duplicate' => 'Štítok so slugom [:slug] už existuje.',
    'duplicate_locale' => 'Štítok so slugom [:slug] v jazyku [:locale] už existuje.',
    'duplicate_trashed' => 'Slug [:slug] v jazyku [:locale] patrí vymazanému štítku: namiesto toho ho obnovte.',
    'duplicate_generic' => 'Tento štítok už existuje.',

    'invalid' => [
        'blank_name' => 'Štítok musí mať názov.',
        'name_too_long' => 'Názov štítka môže mať najviac :max znakov.',
        'unsluggable' => 'V názve štítka nie sú žiadne písmená ani číslice, z ktorých by sa dal vytvoriť slug.',
        'unsupported_locale' => 'Aktuálny jazyk [:locale] nie je podporovaný, preto obyčajný reťazec nemôže pomenovať štítok. Zadajte namiesto toho názvy podľa jazykov.',
        'invalid_locale' => 'Preklad štítka má nesprávne zapísaný kód jazyka.',
        'color' => 'Farba štítka je hexadecimálna farba, napríklad #f24e29.',
        'icon' => 'Ikona štítka je identifikátor alebo emoji s najviac 64 znakmi, bez medzier a značiek.',
        'description_too_long' => 'Popis štítka môže mať najviac :max znakov.',
        'description' => 'Popis štítka je obyčajný text.',
        'slug' => 'Slug štítka tvoria malé písmená, číslice a oddeľovače.',
        'manual_slug_free_form' => 'Štítky typu [:type] berú slug z názvu: vlastný slug prijímajú len kurátorované typy.',
        'position' => 'Pozícia štítka je nula alebo viac.',
        'global_only' => 'Štítky typu [:type] sú vždy globálne a nemôžu patriť do rozsahu.',
        'immutable' => 'Hodnota [:attribute] štítka sa nikdy nemení.',
    ],

    'limit_exceeded' => '{1} Záznam môže mať len jeden štítok typu [:type].|[2,4] Záznam môže mať najviac :max štítky typu [:type].|[0,*] Záznam môže mať najviac :max štítkov typu [:type].',
    'type_not_allowed' => 'Štítky typu [:type] nie sú povolené pre :model.',
    'type_mismatch' => 'Očakával sa štítok typu [:expected], no zadaný je typu [:actual].',

    'hierarchy' => [
        'cycle' => 'Štítok nemožno presunúť pod seba ani pod vlastných potomkov.',
        'too_deep' => '{1} Štítky tohto typu nemôžu mať potomkov.|[2,4] Stromy tohto typu majú najviac :max úrovne.|[0,*] Stromy tohto typu majú najviac :max úrovní.',
        'flat_type' => 'Štítky typu [:type] sú ploché: nemajú rodičov.',
        'parent_trashed' => 'Nadradený štítok je vymazaný: najprv ho obnovte.',
    ],

    'has_children' => 'Štítok [:tag] má potomkov: vymažte ho aj s potomkami alebo ich presuňte k jeho rodičovi.',
    'unknown_alias' => 'Neznámy alias pre :kind.',
    'unknown_alias_missing' => 'Odkaz na :kind nezodpovedá žiadnemu záznamu.',
    'unsaved_taggable' => 'Pred pridaním štítkov uložte :model.',
    'type_already_defined' => 'Typ štítka [:type] je definovaný dvakrát: v :first a v :second. Typ iného balíka upravte cez Tags::extend() alebo tags.overrides.',

    'invalid_type' => [
        'name' => 'Názov typu štítka tvoria malé písmená, číslice, - a _, s jednou voliteľnou predponou balíka, napríklad events:category.',
        'frozen' => 'Typ štítka [:type] sa už používa: upravte ho cez Tags::extend() alebo tags.overrides.',
        'rule' => 'Typ štítka [:type] má neplatné pravidlo: :rule.',
    ],

    'config' => [
        'key_type_mismatch' => 'Kľúč modelu :model nezodpovedá tags.key_type: každý model so štítkami a každý model rozsahu musí používať tento typ kľúča.',
        'invalid' => 'Hodnota konfigurácie [:key] musí byť :expected.',
    ],

    'read_only_relation' => [
        'attach' => 'Vzťah tags je len na čítanie: štítky pridajte cez attachTags() alebo Tags::attach().',
        'detach' => 'Vzťah tags je len na čítanie: štítky odoberte cez detachTags() alebo clearTags() (Tags::detach(), Tags::clear()).',
        'sync' => 'Vzťah tags je len na čítanie: štítky záznamu nahraďte cez syncTags() alebo Tags::sync().',
        'sync_without_detaching' => 'Vzťah tags je len na čítanie: štítky pridajte cez attachTags() alebo Tags::attach().',
        'toggle' => 'Vzťah tags je len na čítanie: použite attachTags() a detachTags() (Tags::attach(), Tags::detach()).',
        'update_existing_pivot' => 'Vzťah tags je len na čítanie: priradenia štítkov nemajú upraviteľné stĺpce.',
        'create' => 'Vzťah tags je len na čítanie: štítky vytvorte cez Tags::type($type)->make(...)->create() alebo findOrCreate() a potom použite attachTags(). V továrni modelu použite afterCreating(fn ($model) => $model->attachTags(...)).',
    ],

    'api' => [
        'tree_too_large' => 'Strom má viac ako :max štítkov: prechádzajte namiesto toho zoznam po stránkach.',
    ],
];
