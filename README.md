# laravel-hearthis

A small Laravel client for the [hearthis.at](https://hearthis.at) public API.

**It needs no key and no account.** `api-v2.hearthis.at/<user>/` is the same
public endpoint hearthis's own embeds use. That is worth saying out loud,
because assuming otherwise is what keeps people pasting `<iframe>` tags into
pages by hand.

An embed carries a track id and nothing else. The API carries artwork, release
date, duration, genre, description and play count — and it knows about the
tracks nobody remembered to add to the page. On the profile this was written
against, the page had **26** embeds and the profile had **63** sets.

## Install

```sh
composer require ombabush/laravel-hearthis
```

```env
HEARTHIS_USER=ombabush
```

`php artisan vendor:publish --tag=hearthis-config` if you want the config file.

## Use

```php
use Ombabush\Hearthis\Facades\Hearthis;

$tracks    = Hearthis::for('ombabush')->tracks();     // newest first
$playlists = Hearthis::for('ombabush')->playlists();  // with their track ids

Hearthis::embedUrl($track['id'], ['autoplay' => 1]);
Hearthis::duration($tracks);                          // seconds
```

Each track is a plain array:

```php
['id', 'title', 'url', 'released', 'duration', 'genre', 'description',
 'artwork', 'waveform', 'plays']
```

Plain arrays rather than objects on purpose: the caller almost always wants to
put these in a `jsonb` column or a cache, and a DTO is one `toArray()` away from
that at every call site.

```sh
php artisan hearthis:fetch                # tracks
php artisan hearthis:fetch --playlists
php artisan hearthis:fetch ombabush --json | jq
```

## What it deliberately does not do

- **It does not write to your database.** Where a track list belongs — a column,
  a cache, a table of its own — is the application's decision, and a package
  that guesses it is a package you fight.
- **It does not keep `stream_url`.** That field carries a short-lived token, so
  storing it means storing something that stops working. The id is stable and
  rebuilds both the embed and the permalink.
- **It does not render.** `embedUrl()` gives you the URL; when to create the
  iframe is yours. Build it on click — sixty-three embeds are sixty-three
  third-party players loading their own scripts before anyone presses anything.

## Caching

Off by default (`HEARTHIS_CACHE_TTL=0`), because the usual caller is a sync
command, and a sync command is asking precisely because it wants the new answer.
Set a TTL if you read on render instead — though a page that calls a third-party
API to draw itself is a page that goes down when they do.

## Licence

MIT.
