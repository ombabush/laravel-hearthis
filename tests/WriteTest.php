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
