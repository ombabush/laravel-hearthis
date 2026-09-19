<?php

use Illuminate\Support\Facades\Http;
use Ombabush\Hearthis\Exceptions\HearthisException;
use Ombabush\Hearthis\Hearthis;

function track(int $id, string $date = '2026-01-01 10:00:00'): array
{
    return [
        'id' => $id,
        'title' => "Set $id",
        'permalink_url' => "https://hearthis.at/ombabush/set-$id/",
        'release_date' => $date,
        'duration' => 3600,
        'genre' => 'Techno',
        'description' => '<p>Mixed <b>live</b></p>',
        'artwork_url' => "https://img.hearthis.at/$id",
        'playback_count' => 10,
        // The field the package must NOT carry over: it holds a short-lived
        // token, so storing it stores something that stops working.
        'stream_url' => 'https://hearthis.app/listen/?s=secret',
    ];
}

it('reads a profile without any credentials', function () {
    Http::fake(['*' => Http::response([track(1), track(2, '2026-02-02 10:00:00')])]);

    $tracks = Hearthis::for('ombabush')->tracks();

    expect($tracks)->toHaveCount(2)
        // Newest first, whatever order the API used.
        ->and($tracks[0]['id'])->toBe('2')
        ->and($tracks[0]['released'])->toBe('2026-02-02');

    Http::assertSent(function ($request) {
        return ! $request->hasHeader('Authorization')
            && str_contains($request->url(), 'api-v2.hearthis.at/ombabush/');
    });
});

it('drops the token-bearing stream url and flattens the description', function () {
    Http::fake(['*' => Http::response([track(1)])]);

    $t = Hearthis::for('ombabush')->tracks()->first();

    expect($t)->not->toHaveKey('stream_url')
        ->and($t['description'])->toBe('Mixed live')
        ->and($t)->toHaveKeys(['id', 'title', 'url', 'released', 'duration', 'genre', 'artwork', 'plays']);
});

it('stops paging when a short page comes back', function () {
    config()->set('hearthis.per_page', 2);

    Http::fake(['*' => Http::sequence()
        ->push([track(1), track(2)])
        ->push([track(3)]), // short page — nothing beyond it
    ]);

    expect(Hearthis::for('ombabush')->tracks())->toHaveCount(3);
    Http::assertSentCount(2);
});

it('reads playlists with their track ids, biggest first', function () {
    Http::fake([
        '*type=playlists*' => Http::response([
            ['id' => 1, 'title' => 'Small', 'permalink' => 'a', 'track_count' => 1],
            ['id' => 2, 'title' => 'Big', 'permalink' => 'b', 'track_count' => 2],
        ]),
        '*set/a/*' => Http::response([track(11)]),
        '*set/b/*' => Http::response([track(21), track(22)]),
    ]);

    $sets = Hearthis::for('ombabush')->playlists();

    expect($sets[0]['title'])->toBe('Big')
        ->and($sets[0]['tracks'])->toBe(['21', '22'])
        ->and($sets[1]['tracks'])->toBe(['11']);
});

it('builds an embed url from the id, which is the only stable handle', function () {
    $url = Hearthis::for('ombabush')->embedUrl(13532830, ['autoplay' => 1]);

    expect($url)->toContain('/embed/13532830/transparent_black/')
        ->and($url)->toContain('autoplay=1');
});

it('says which profile it is missing rather than guessing one', function () {
    config()->set('hearthis.user', null);

    expect(fn () => (new Hearthis)->tracks())->toThrow(HearthisException::class);
});

it('reports the platform being down instead of returning nothing', function () {
    Http::fake(['*' => Http::response('nope', 503)]);

    expect(fn () => Hearthis::for('ombabush')->tracks())
        ->toThrow(HearthisException::class, '503');
});

it('sums a track list to hours', function () {
    expect(Hearthis::duration([['duration' => 3600], ['duration' => 1800]]))->toBe(5400);
});

/*
 * The rest of the API — the half an <iframe> cannot reach.
 */

it('reads the artist, which is what /<user>/ returns with no type', function () {
    Http::fake(['*' => Http::response([
        'id' => 7, 'username' => 'OmBabush', 'permalink' => 'ombabush',
        'permalink_url' => 'https://hearthis.at/ombabush/',
        'description' => '<p>Sets</p>', 'avatar_url' => 'https://img/a',
        'counts' => ['tracks' => 63, 'playlists' => 5, 'followers' => 100],
        'external_links' => ['https://sniff.ru'],
    ])]);

    $artist = Hearthis::for('ombabush')->artist();

    expect($artist['name'])->toBe('OmBabush')
        ->and($artist['tracks'])->toBe(63)
        ->and($artist['playlists'])->toBe(5)
        ->and($artist['description'])->toBe('Sets')
        ->and($artist['links'])->toBe(['https://sniff.ru']);
});

it('reads one track by permalink, or by its full URL', function () {
    Http::fake(['*' => Http::response(track(1) + ['bpm' => 127, 'key' => 'Am', 'tags' => 'Leftfield,Techno'])]);

    $t = Hearthis::for('ombabush')->track('https://hearthis.at/ombabush/set-1/');

    expect($t['bpm'])->toBe(127)
        ->and($t['key'])->toBe('Am')
        // The things no embed can tell you, which is the point of the API.
        ->and($t['tags'])->toBe(['Leftfield', 'Techno']);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/ombabush/set-1/'));
});

it('searches the right endpoint, with a type', function () {
    Http::fake(['*' => Http::response([track(1), track(2)])]);

    expect(Hearthis::for('ombabush')->search('techno'))->toHaveCount(2);

    // `/search/` WITH the trailing slash and WITH a type. Without them it
    // answers, but not with what you asked for.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/search/')
        && str_contains($r->url(), 'type=tracks')
        && str_contains($r->url(), 't=techno'));
});

it('filters the feed by length using the names hearthis actually reads', function () {
    Http::fake(['*' => Http::response([track(1)])]);

    Hearthis::for('ombabush')->longSets(60);

    // `duration_min`, not `duration`. A wrong name here is ignored in silence,
    // so the call looks fine and quietly returns three-minute singles.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'duration_min=60'));
});

it('asks the genre list the only way that returns a genre list', function () {
    Http::fake(['*' => Http::response([['id' => 'techno', 'name' => 'Techno']])]);

    Hearthis::for('ombabush')->categories();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'source=app'));
});

it('lists the genres and one genre s feed', function () {
    Http::fake([
        '*categories/techno*' => Http::response([track(1)]),
        '*categories*' => Http::response([['id' => 'techno', 'name' => 'Techno', 'url' => 'u', 'background_color' => '#000']]),
    ]);

    $client = Hearthis::for('ombabush');

    expect($client->categories()->first()['name'])->toBe('Techno')
        ->and($client->category('techno'))->toHaveCount(1);
});

it('sends key and secret as plain parameters when it has them', function () {
    config()->set('hearthis.key', 'K');
    config()->set('hearthis.secret', 'S');

    Http::fake(['*' => Http::response([track(1)])]);

    Hearthis::for('ombabush')->withCredentials()->tracks();

    // hearthis has no OAuth: the pair rides along as query parameters.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'key=K') && str_contains($r->url(), 'secret=S'));
});

it('sends nothing extra when it has no credentials', function () {
    config()->set('hearthis.key', null);
    config()->set('hearthis.secret', null);

    Http::fake(['*' => Http::response([track(1)])]);

    Hearthis::for('ombabush')->tracks();

    Http::assertSent(fn ($r) => ! str_contains($r->url(), 'secret='));
});

it('exchanges an email and password for a credential pair', function () {
    // /login/ calls them masterkey and verify_code; everything else calls the
    // same two values key and secret.
    Http::fake(['*login*' => Http::response([
        'masterkey' => 'K', 'verify_code' => 'S', 'username' => 'OmBabush',
    ])]);

    $r = Hearthis::login('a@b.c', 'pw');

    expect($r['key'])->toBe('K')
        ->and($r['secret'])->toBe('S')
        ->and($r['user']['name'])->toBe('OmBabush');

    // It is a GET — hearthis's choice, not ours. Pinned here because it means
    // the password lands in access logs, and a future "tidy-up" that switched
    // this to POST would simply stop working.
    Http::assertSent(fn ($req) => $req->method() === 'GET');
});

it('also accepts the key/secret spelling, in case /login/ is ever tidied up', function () {
    Http::fake(['*login*' => Http::response(['key' => 'K', 'secret' => 'S'])]);

    expect(Hearthis::login('a@b.c', 'pw')['secret'])->toBe('S');
});

it('repeats hearthis own words when a login is refused', function () {
    Http::fake(['*login*' => Http::response(['success' => false, 'message' => 'Session expired. Please log in again.'])]);

    expect(fn () => Hearthis::login('a@b.c', 'wrong'))
        ->toThrow(HearthisException::class, 'Session expired');
});

it('treats a 200 carrying success:false as the failure it is', function () {
    // hearthis answers 200 with `success: false` for a missing resource, so the
    // status code on its own is not the answer.
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'Not found'])]);

    expect(fn () => Hearthis::for('ombabush')->track('nope'))
        ->toThrow(HearthisException::class, 'Not found');
});

it('groups a set list the two ways anyone reads one', function () {
    $tracks = [
        ['released' => '2026-01-01', 'genre' => 'Techno', 'duration' => 3600],
        ['released' => '2025-06-01', 'genre' => 'Techno', 'duration' => 3600],
        ['released' => '2025-01-01', 'genre' => '', 'duration' => 1800],
    ];

    // PHP turns a numeric string array key into an int, so the year keys come
    // back as 2026/2025 rather than '2026'/'2025'. Harmless in a template, and
    // worth knowing before you compare them.
    expect(Hearthis::byYear($tracks)->keys()->all())->toBe([2026, 2025])
        ->and(Hearthis::byGenre($tracks)->keys()->first())->toBe('Techno')
        ->and(Hearthis::humanDuration(9000))->toBe('2 h 30 min')
        ->and(Hearthis::humanDuration(1800))->toBe('30 min');
});
