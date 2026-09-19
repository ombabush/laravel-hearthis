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
form**. You call `/login/` once with your email and password; it returns
`masterkey` and `verify_code`, which are the same two values every other
endpoint calls `key` and `secret`, and those ride along as ordinary query
parameters afterwards.

Two warnings, both theirs and not this package's:

- **`/login/` is a GET**, so the password travels in the query string — into
  their access logs and any proxy's in between. Run it once, from a console.
- **The API documentation is Premium-only.** `hearthis.at/api/` answers 401 with
  "the hearthis.at API documentation is available to logged-in Premium accounts
  only" for everyone else. Reading the API needs no account; reading the *docs*
  does.

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

## Writing — upload, edit, cover, delete

Writes are **not** on `api-v2`. `POST /upload/` there just redirects to the web
form, which is what makes it look as though the platform has no write API at
all. It has a full one, on a host of its own: `https://xhr.hearthis.at/`.

They need a key/secret pair **and** an active Premium account — anonymous gets
401, a free account gets 403 — and everything is scoped to the authenticated
user: touching someone else's track is a 403.

```php
$w = Hearthis::for('ombabush')->withCredentials()->write();

$track = $w->upload('/path/set.mp3', [
    'title'        => 'Berghain Closing Set 2026',
    'genre'        => 'techno',
    'tags'         => ['techno', 'live', 'berlin'],
    'description'  => "Recorded live.\nSix hours.",
    'downloadable' => true,
    'private'      => false,
    'release_at'   => '2026-07-01T20:00:00+02:00',
    'tracklist'    => [
        ['at' => 0,    'artist' => 'Artist A', 'title' => 'Opener'],
        ['at' => 3735, 'artist' => 'Artist C', 'title' => 'Closer'],
    ],
], cover: '/path/cover.jpg');

$w->edit($track['id'], ['description' => 'Updated liner notes']);
$w->setCover($track['id'], '/path/new-cover.jpg');
$w->removeCover($track['id']);
$w->delete($track['id']);          // soft-delete, owner only, no restore endpoint
```

**On `strict`.** An upload applies its metadata best-effort: an unknown genre or
an unparseable date does *not* fail it, the item just carries a `meta_error`.
Left alone that is indistinguishable from a clean upload, so `upload()` raises
by default. Pass `strict: false` when you would rather have the file up and fix
the metadata after. Note that `edit()` validates properly — a bad genre is a 400
with the reason — so anything you care about is better set there.

**Dates**: always send an explicit UTC offset. A bare `2026-07-01 20:00:00` is
read in the server's timezone, and you do not know what that is.

**Chapters**: `Tracklist::format()` and `::parse()` handle the wire format —
one line each, `[timestamp] Artist - Title`, split on the *first* « - » so a
hyphen inside a name survives. Sending a tracklist replaces every chapter.

## Sets and groups

```php
$h = Hearthis::for('ombabush')->withCredentials();

$h->sets()->mine();
$h->sets()->create('Best of 2026', $trackId);   // no such thing as an empty set
$h->sets()->add($setId, $trackId);
$h->sets()->removeTrack($setId, $trackId);
$h->sets()->delete($setId);                     // the tracks are untouched

$h->groups()->byGenre('techno');
$h->groups()->complete($id);                    // { meta, tracks }
$h->groups()->members($id);                     // owner first, rights 10
$h->groups()->addTrack($id, $trackId);
$h->groups()->setRole($id, $userId, Groups::BLOCKED);
$h->groups()->delete($id);                      // permanent, unlike a track
```

Group roles are integers and not ordinal in the obvious way: `OWNER = 10`,
`EDITOR = 5`, `MEMBER = 0`, **`BLOCKED = -1`**. A blocked member is a row with a
negative right, not a missing row.

## The docs themselves

`hearthis.at/api/` is the full reference, including an OpenAPI 3.0 spec at
`hearthis.at/api/openapi.json`. Both are **Premium-only** — they answer 401 to
everyone else — which is why no copy of either is vendored here. Reading the API
needs no account; reading the documentation does.

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
