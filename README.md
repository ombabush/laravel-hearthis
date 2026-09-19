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

$hearthis = Hearthis::for('ombabush');

// One profile
$hearthis->artist();                  // avatar, bio, counts, links
$hearthis->tracks();                  // every track, newest first
$hearthis->likes();                   // what they have liked
$hearthis->track('german-teacher-bday');     // one track, or a full URL
$hearthis->playlists();               // «sets», with their track ids
$hearthis->playlistTracks($permalink);       // a set's tracks in full

// The rest of hearthis
$hearthis->search('techno');
$hearthis->feed('popular', 20, minutes: 60); // sets, not singles
$hearthis->feed('new');
$hearthis->categories();              // all 69 genres
$hearthis->category('techno');

// URLs and sums
Hearthis::embedUrl($id, ['autoplay' => 1]);
Hearthis::duration($tracks);          // seconds
Hearthis::humanDuration(9000);        // «2 h 30 min»
Hearthis::byYear($tracks);            // grouped, newest year first
Hearthis::byGenre($tracks);           // grouped, fullest genre first
```

Each track is a plain array:

```php
['id', 'title', 'permalink', 'url', 'released', 'duration', 'genre', 'tags',
 'bpm', 'key', 'description', 'artwork', 'artwork_retina', 'waveform',
 'plays', 'likes', 'comments', 'downloadable', 'artist', 'artist_url']
```

`bpm`, `key` (musical — «Am»), `tags` and the counts are the reason to use the
API at all: an `<iframe>` carries a track id and nothing else.

## Credentials — where the key and secret come from

hearthis has **no OAuth, no developer portal and no "create an application"
form**. You post your email and password to `/login/` once, it returns a `key`
and a `secret`, and those two ride along as ordinary query parameters on any
endpoint afterwards.

```sh
php artisan hearthis:login          # prompts, hides the password, prints the pair
```

Put the two values in `.env` as `HEARTHIS_KEY` and `HEARTHIS_SECRET`, then:

```php
Hearthis::for('ombabush')->withCredentials()->tracks();
```

The command does not write to your `.env` and the package does not store the
pair — a library that keeps your password is a library you have to trust twice.
**Everything above works without any of this**; unset, the client simply sees
what the public sees.

> Uploading is not possible through the API. `POST /upload/` on api-v2 redirects
> to the web form, and the older `/api/upload/` paths are empty redirects too.

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
