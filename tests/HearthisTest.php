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
