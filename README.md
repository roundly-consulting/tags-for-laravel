<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/tags-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=tags-for-laravel">
    <img src="art/hero.png" alt="Tags for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/tags-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/tags-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/tags-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/tags-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/tags-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/tags-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=tags-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Tags for Laravel

Typed taxonomies for any Eloquent model: flat labels or nested category trees, single- or
multi-select, curated or free-form, global or tenant-scoped, and translatable in every locale —
with autocomplete, usage counts and query scopes that never N+1.

## Installation

Requires PHP 8.4, Laravel 12 or 13, and PostgreSQL 12+ or MySQL 8.0.23+.

```bash
composer require roundly-consulting/tags-for-laravel
php artisan vendor:publish --tag="tags-migrations"
php artisan migrate
```

## Usage

Make a model taggable:

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tags\Concerns\HasTags;
use RoundlyConsulting\Tags\Contracts\Taggable;

final class Concert extends Model implements Taggable
{
    use HasTags;
}
```

Declare your types, build a tree, tag, query and suggest:

```php
use RoundlyConsulting\Tags\Facades\Tags;

// Declare your tag types once, in a service provider
Tags::define('genre')->hierarchical()->single();   // a curated category tree, one per model
Tags::define('label')->max(5);                     // free-form labels, up to five

// Build the tree (names are translatable; a plain string is the current locale)
$music = Tags::type('genre')->make(['en' => 'Music', 'sk' => 'Hudba'])->color('#f24e29')->create();
$jazz = Tags::type('genre')->make('Jazz')->under($music)->create();

// Tag any model that uses HasTags
$concert->syncTags($jazz, 'genre');
$concert->attachTags(['Live', 'Outdoor'], 'label');     // unknown labels are created on the fly

// Query: "music" also matches everything under it
Concert::withAnyTags(['music'], 'genre', descendants: true)->get();
Concert::withAllTags(['live', 'outdoor'], 'label')->get();

// Read without N+1
foreach (Concert::with('tags')->get() as $concert) {
    echo $concert->tagOf('genre')?->name;                         // Jazz
    echo $concert->tagsOf('label')->pluck('name')->join(', ');    // Live, Outdoor
}

// Autocomplete and popularity
Tags::type('label')->suggest('liv');    // prefix matches first, in every locale
Tags::type('label')->popular(10);
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/tags-for-laravel](https://roundly-consulting.com/open-source/docs/tags-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=tags-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=tags-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=tags-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
