# laravel-hearthis

A Laravel client for the [hearthis.at](https://hearthis.at) API — the DJ-mix
platform. Read any profile without an account; upload, edit and organise your
own tracks with a key.

```php
$hearthis = Hearthis::for('ombabush');

$hearthis->tracks();        // 63 sets, 153 hours, newest first
$hearthis->playlists();     // «The Dark Side of the Spoon», 16 tracks
$hearthis->artist();        // avatar, bio, followers, links
```

## Why

The usual way to put a mix on a page is to paste hearthis's `<iframe>` embed
into the HTML. That has two costs people discover late:

- **An embed carries a track id and nothing else.** No artwork, no date, no
  duration, no genre, no bpm, no play count — so the page around it has to
  repeat by hand what the platform already knows.
- **A page only holds the embeds someone remembered to paste.** On the profile
  this package was written against, the page had 26 and the profile had **63**.
  More than half the music was missing because adding a mix meant editing HTML.

And every embed is a third-party player that loads its own scripts. Twenty-six
of them load twenty-six times, before anyone presses play.

This package reads the API instead, hands you plain arrays, and leaves rendering
to you.

## Install

```sh
composer require ombabush/laravel-hearthis
```

```env
HEARTHIS_USER=ombabush
```

Optional: `php artisan vendor:publish --tag=hearthis-config`.

Requires PHP 8.2+ and Laravel 11 or 12.

## Reading

No credentials needed for any of this.

```php
use Ombabush\Hearthis\Facades\Hearthis;

$h = Hearthis::for('ombabush');

// One profile
$h->artist();                      // avatar, bio, counts, external links
$h->tracks();                      // every track, newest first
$h->likes();                       // tracks this profile liked
$h->reposts();
$h->followers();  $h->following();
$h->playlists();                   // sets, with the ids of their tracks
$h->playlistTracks($permalink);    // …or the tracks in full

// One track — by permalink, or by full URL
$h->track('german-teacher-bday');
$h->chapters($permalink);          // parsed tracklist
$h->related($permalink);
$h->comments($permalink);
$h->transcript($permalink);        // AI transcript, where there is one

// All of hearthis
$h->search('techno');              // also 'user' and 'playlists'
$h->searchArtists('ombabush');
$h->popular('techno');
$h->longSets(60);                  // over an hour: sets, not singles
$h->categories();                  // all 69 genres
$h->category('techno');
```

### What a track looks like

```php
[
    'id' => '13944121',
    'title' => "German Teacher's B-Tag @ The Warren",
    'permalink' => 'german-teacher-bday',
    'url' => 'https://hearthis.at/ombabush/german-teacher-bday/',
    'released' => '2026-03-04',
    'duration' => 9528,                    // seconds
    'genre' => 'Techno',
    'tags' => ['Leftfield', 'Techno'],
    'bpm' => 127,
    'key' => 'Am',                         // musical key
    'description' => 'Mixed by OmBabush @ The Warren',
    'artwork' => 'https://img.hearthis.at/…',
    'artwork_retina' => '…',
    'waveform' => 'https://cdn.hearthis.at/…/waveform_mask/…png',
    'plays' => 2910,
    'likes' => 2,
    'comments' => 0,
    'downloadable' => false,
    'artist' => 'OmBabush',
    'artist_url' => 'https://hearthis.at/ombabush/',
]
```

Plain arrays, not objects, on purpose: the caller almost always wants to put
these in a `jsonb` column or a cache, and a DTO is one `toArray()` away from
that at every call site.

`stream_url` is **not** included. It carries a short-lived token, so storing it
means storing something that stops working. The id is stable and rebuilds both
the embed and the permalink.

### Helpers

```php
Hearthis::embedUrl($id, ['autoplay' => 1]);
Hearthis::duration($tracks);        // 553_320
Hearthis::humanDuration(553_320);   // «153 h 42 min»
Hearthis::byYear($tracks);          // grouped, newest year first
Hearthis::byGenre($tracks);         // grouped, fullest genre first
```

## Credentials

hearthis has **no OAuth, no developer portal and no "create an application"
form**. You call `/login/` once with your email and password; it returns
`masterkey` and `verify_code`, which are the same two values every other
endpoint calls `key` and `secret`.

```sh
php artisan hearthis:login
```

It prompts, hides the password, and prints the pair for you to paste into
`.env` as `HEARTHIS_KEY` and `HEARTHIS_SECRET`. It writes nothing itself — a
package that edits your `.env` is a package that surprises you, and one that
keeps your password is one you have to trust twice.

```php
Hearthis::for('ombabush')->withCredentials()->write();
```

Two warnings, both hearthis's and not this package's:

- **`/login/` is a GET**, so the password travels in the query string — into
  their access logs and any proxy's in between. Run it once, from a console.
- **Writes need Premium.** Anonymous gets 401, a logged-in free account gets
  403. Every write is scoped to the authenticated account: touching someone
  else's track is a 403.

## Writing

Writes are **not** on `api-v2`. `POST /upload/` there redirects to the web form,
which is what makes it look as though the platform has no write API at all. It
has a full one, on a host of its own.

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
$w->delete($track['id']);
```

Accepted audio: mp3, wav, flac, aac, m4a, ogg, aif/aiff, wma, opus. Cover: JPG
or PNG, ≤ 10 MB, ≥ 50×50.

The file is **streamed**, not read into memory: a two-hour set is around 200 MB,
and `file_get_contents` on one is 200 MB of PHP memory before a byte leaves the
machine. `uploadRaw()` sends hearthis's other form — a raw binary body with an
`X-Filename` header — for when you want one less layer of framing.

### Three things that would bite you — handled here, listed so you know why

These are hearthis's behaviours, not this package's. Each one is dealt with
below; they are written down because the handling looks paranoid until you know
what it is for.

**Metadata is best-effort on upload.** An unknown genre or an unparseable date
does *not* fail the upload; the item quietly carries a `meta_error` instead,
which is indistinguishable from success unless you look. `upload()` therefore
raises by default — pass `strict: false` if you would rather have the file up
and fix it after. `edit()` validates properly (a bad genre is a 400 with the
reason), so anything you care about is better set there.

**Errors arrive inside a 200.** Including `Duplicate content: This file was
already uploaded`, which is the one a re-run of a batch hits. They are raised as
exceptions here, with hearthis's own wording.

**Dates need an explicit offset** — and this package adds one for you. Their
parser is `strtotime()`, so a bare `2026-07-01 20:00:00` is read in *their*
server's timezone: the upload succeeds, the field is accepted, and the release
quietly happens at the wrong hour. Anything without an offset is resolved in
your application's timezone and sent as ISO 8601; a date that cannot be read at
all raises rather than travelling as nonsense. Send `0` or an empty value to
clear a schedule.

### Chapters

```php
use Ombabush\Hearthis\Tracklist;

Tracklist::format([
    ['at' => 0,    'artist' => 'Jean-Michel Jarre', 'title' => 'Oxygène'],
    ['at' => 3735, 'title' => 'Untitled'],
]);
// "00:00 Jean-Michel Jarre - Oxygène\n1:02:15 Untitled"

Tracklist::parse($wire);   // back to an array
```

One chapter per line, `[timestamp] Artist - Title`. The split is on the **first**
« - » (space, hyphen, space), so the hyphen inside «Jean-Michel» survives.
Timestamps may be `MM:SS` or `HH:MM:SS` and are optional. Sending a tracklist
**replaces** every chapter — there is no append.

## Sets and groups

```php
$h = Hearthis::for('ombabush')->withCredentials();

$h->sets()->mine();
$h->sets()->create('Best of 2026', $trackId);
$h->sets()->add($setId, $trackId);
$h->sets()->removeTrack($setId, $trackId);
$h->sets()->delete($setId);              // the tracks themselves are untouched

$h->groups()->byGenre('techno');
$h->groups()->complete($id);             // { meta, tracks }
$h->groups()->members($id);              // owner first
$h->groups()->addTrack($id, $trackId);
$h->groups()->setRole($id, $userId, Groups::EDITOR);
$h->groups()->delete($id);               // permanent, unlike a track
```

**There is no empty set.** One is created by handing the endpoint a name instead
of an id, together with the first track to put in it.

**Group roles are integers, and not ordinal in the obvious way:** `OWNER = 10`,
`EDITOR = 5`, `MEMBER = 0`, `BLOCKED = -1`. A blocked member is a row with a
negative right, not a missing row.

## Console

```sh
php artisan hearthis:login                    # get your key/secret pair
php artisan hearthis:fetch                    # list a profile's tracks
php artisan hearthis:fetch --playlists
php artisan hearthis:fetch ombabush --json | jq
```

## Caching

Off by default (`HEARTHIS_CACHE_TTL=0`), because the usual caller is a sync
command and a sync command is asking precisely because it wants the new answer.
Set a TTL if you read on render instead — though a page that calls a third-party
API to draw itself is a page that goes down when they do.

## What it deliberately does not do

- **It does not write to your database.** Where a track list belongs — a column,
  a cache, a table of its own — is your application's decision, and a package
  that guesses it is a package you fight.
- **It does not render.** `embedUrl()` gives you a URL; when to create the
  iframe is yours. Build it on click, so sixty-three mixes do not mean
  sixty-three players before anyone presses anything.
- **It does not delete accounts.** The API has `action=deleteaccount`; a library
  is the wrong place to keep the trigger.

## The documentation

`hearthis.at/api/` is the full reference, with an OpenAPI 3.0 spec at
`hearthis.at/api/openapi.json`. Both are **Premium-only** and answer 401 to
everyone else, so no copy of either is vendored here. Reading the API needs no
account; reading the docs does.

Worth knowing, because three of this package's calls were wrong before that
reference was read — and wrong silently. `/search/` needs a `type`; the feed's
length filter is `duration_min`, not `duration`; `/categories/` returns a genre
list only with `?source=app`. A wrong parameter name is ignored rather than
rejected, so the request looks fine and answers with the wrong thing. The tests
here assert the **URL**, not the response, for exactly that reason: a faked
response will happily agree with a broken request.

## Tests

```sh
composer install
./vendor/bin/pest
```

## Licence

MIT.
