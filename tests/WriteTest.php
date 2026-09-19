<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Ombabush\Hearthis\Exceptions\HearthisException;
use Ombabush\Hearthis\Hearthis;
use Ombabush\Hearthis\Tracklist;

beforeEach(function () {
    config()->set('hearthis.key', 'K');
    config()->set('hearthis.secret', 'S');

    $this->audio = tempnam(sys_get_temp_dir(), 'set').'.mp3';
    file_put_contents($this->audio, 'ID3 not really');

    $this->cover = tempnam(sys_get_temp_dir(), 'cover').'.jpg';
    file_put_contents($this->cover, 'JFIF not really');

    $this->writer = fn () => Hearthis::for('ombabush')->withCredentials()->write();
});

afterEach(function () {
    @unlink($this->audio);
    @unlink($this->cover);
});

it('refuses to write without credentials, and says what to do about it', function () {
    config()->set('hearthis.key', null);
    config()->set('hearthis.secret', null);

    expect(fn () => Hearthis::for('ombabush')->write()->upload($this->audio))
        ->toThrow(HearthisException::class, 'Premium');
});

it('uploads to the OTHER host, not to api-v2', function () {
    Http::fake(['*' => Http::response(['files' => [['id' => '501', 'error' => '']]])]);

    $file = ($this->writer)()->upload($this->audio, ['title' => 'Berghain Closing Set']);

    expect($file['id'])->toBe('501');

    // The whole reason uploading looked impossible: writes are on xhr, and
    // POST /upload/ on api-v2 merely redirects to the web form.
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://xhr.hearthis.at/upload_api.php')
        && str_contains($r->url(), 'key=K') && str_contains($r->url(), 'secret=S'));
});

it('treats an error inside a 200 body as the failure it is', function () {
    Http::fake(['*' => Http::response(['files' => [['error' => 'This filetype is not supported.']]])]);

    expect(fn () => ($this->writer)()->upload($this->audio))
        ->toThrow(HearthisException::class, 'filetype is not supported');
});

it('does not let a silent meta_error pass for success', function () {
    // The audio lands and the metadata quietly does not. Left alone this looks
    // exactly like a clean upload, which is why strict mode is the default.
    Http::fake(['*' => Http::response(['files' => [
        ['id' => '501', 'error' => '', 'meta_error' => 'Unknown genre.'],
    ]])]);

    expect(fn () => ($this->writer)()->upload($this->audio, ['genre' => 'nonsense']))
        ->toThrow(HearthisException::class, 'Unknown genre');

    // …unless you would rather have the file up and fix it afterwards.
    expect(($this->writer)()->upload($this->audio, ['genre' => 'nonsense'], strict: false))
        ->toHaveKey('meta_error');
});

it('normalises the fields hearthis is picky about', function () {
    Http::fake(['*' => Http::response(['files' => [['id' => '1', 'error' => '']]])]);

    ($this->writer)()->upload($this->audio, [
        'tags' => ['techno', 'live', 'berlin'],
        'private' => false,
        'downloadable' => true,
        'tracklist' => [
            ['at' => 0, 'artist' => 'Artist A', 'title' => 'Opener'],
            ['at' => 3735, 'artist' => 'Artist C', 'title' => 'Closer'],
        ],
    ]);

    Http::assertSent(function (Request $r) {
        $body = (string) $r->body();

        return str_contains($body, 'techno,live,berlin')
            // `false` would go as an empty string and read as "not set".
            && str_contains($body, "\r\n0")
            && str_contains($body, '00:00 Artist A - Opener')
            && str_contains($body, '1:02:15 Artist C - Closer');
    });
});

it('edits, deletes and re-covers a track', function () {
    Http::fake(['*' => Http::response(['success' => true, 'id' => '501'])]);

    $w = ($this->writer)();

    expect($w->edit(501, ['description' => 'Updated'])['success'])->toBeTrue()
        ->and($w->delete(501)['success'])->toBeTrue()
        ->and($w->setCover(501, $this->cover)['success'])->toBeTrue()
        ->and($w->removeCover(501)['success'])->toBeTrue();

    expect(fn () => $w->edit(501, []))->toThrow(HearthisException::class, 'Nothing to edit');
});

it('repeats hearthis own refusal rather than inventing one', function () {
    Http::fake(['*' => Http::response(['error' => 'You do not own this track'], 403)]);

    expect(fn () => ($this->writer)()->edit(501, ['title' => 'Mine now']))
        ->toThrow(HearthisException::class, 'You do not own this track');
});

it('formats and parses chapters the way their parser splits them', function () {
    $wire = Tracklist::format([
        ['at' => 0, 'artist' => 'Jean-Michel Jarre', 'title' => 'Oxygène'],
        ['at' => 450, 'title' => 'Untitled'],
    ]);

    expect($wire)->toBe("00:00 Jean-Michel Jarre - Oxygène\n07:30 Untitled");

    $back = Tracklist::parse($wire);

    // Split on the FIRST « - », so the hyphen inside «Jean-Michel» survives.
    expect($back[0])->toBe(['at' => 0, 'artist' => 'Jean-Michel Jarre', 'title' => 'Oxygène'])
        ->and($back[1])->toBe(['at' => 450, 'artist' => '', 'title' => 'Untitled'])
        ->and(Tracklist::stamp(3735))->toBe('1:02:15')
        ->and(Tracklist::seconds('1:02:15'))->toBe(3735);
});

it('streams the audio instead of reading it into memory', function () {
    Http::fake(['*' => Http::response(['files' => [['id' => '1', 'error' => '']]])]);

    // A two-hour set is ~200 MB. file_get_contents() would put all of it in
    // PHP's memory before a byte left the machine — on a 2 GB box with a queue
    // worker running, that is the difference between a release and an OOM.
    $before = memory_get_usage();
    ($this->writer)()->upload($this->audio);

    expect(memory_get_usage() - $before)->toBeLessThan(1_000_000);
});

it('can send a raw binary body with X-Filename, hearthis other way', function () {
    Http::fake(['*' => Http::response(['files' => [['id' => '9', 'error' => '']]])]);

    ($this->writer)()->uploadRaw($this->audio, ['title' => 'Raw']);

    Http::assertSent(function (Request $r) {
        return $r->hasHeader('X-Filename')
            && str_contains($r->url(), 'title=Raw')
            && str_contains($r->url(), 'upload_api.php');
    });
});

it('names a duplicate for what it is, since a re-run will hit it', function () {
    Http::fake(['*' => Http::response(['files' => [
        ['error' => 'Duplicate content: This file was already uploaded. Filename: set.mp3'],
    ]])]);

    expect(fn () => ($this->writer)()->upload($this->audio))
        ->toThrow(HearthisException::class, 'Duplicate content');
});

it('creates a set around its first track, since there is no empty set', function () {
    Http::fake(['*' => Http::response(['success' => true, 'id' => '9'])]);

    Hearthis::for('ombabush')->withCredentials()->sets()->create('Best of 2026', 501);

    Http::assertSent(function (Request $r) {
        $b = (string) $r->body();

        return str_contains($r->url(), 'set_ajax_add.php')
            && str_contains($b, 'new_set=Best')
            && str_contains($b, 'track_id=501');
    });
});

it('knows blocked is a role, not an absence', function () {
    expect(\Ombabush\Hearthis\Groups::BLOCKED)->toBe(-1)
        ->and(\Ombabush\Hearthis\Groups::OWNER)->toBe(10)
        ->and(\Ombabush\Hearthis\Groups::EDITOR)->toBe(5);

    Http::fake(['*' => Http::response(['success' => true])]);

    Hearthis::for('ombabush')->withCredentials()
        ->groups()->setRole(42, 7, \Ombabush\Hearthis\Groups::BLOCKED);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'group/42/permissions/')
        && str_contains((string) $r->body(), 'rights=-1'));
});

it('will not touch group or set endpoints without credentials', function () {
    config()->set('hearthis.key', null);
    config()->set('hearthis.secret', null);

    expect(fn () => Hearthis::for('ombabush')->groups()->join(42))
        ->toThrow(HearthisException::class, 'credentials');
});

it('never sends a schedule without a timezone, because that failure is silent', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    config()->set('app.timezone', 'Europe/Berlin');

    // A bare date is read in THEIR server's timezone — the upload succeeds, the
    // field is accepted, and the release happens at the wrong hour.
    ($this->writer)()->edit(501, ['release_at' => '2026-07-01 20:00:00']);

    Http::assertSent(function (Request $r) {
        return (bool) preg_match('/release_at=[^&]*(%2B|\+)\d{2}(%3A|:)\d{2}/', (string) $r->body());
    });
});

it('passes an already-offset date through untouched, and 0 clears a schedule', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    $w = ($this->writer)();

    $w->edit(501, ['release_at' => '2026-07-01T20:00:00+02:00']);
    Http::assertSent(fn (Request $r) => str_contains(urldecode((string) $r->body()), '2026-07-01T20:00:00+02:00'));

    $w->edit(501, ['release_at' => 0]);
    Http::assertSent(fn (Request $r) => str_contains((string) $r->body(), 'release_at=0'));
});

it('refuses a date it cannot read rather than sending nonsense', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    expect(fn () => ($this->writer)()->edit(501, ['release_at' => 'next thursday-ish']))
        ->toThrow(HearthisException::class, 'wrong hour');
});
