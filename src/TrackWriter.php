<?php

namespace Ombabush\Hearthis;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Ombabush\Hearthis\Exceptions\HearthisException;

/**
 * Uploading, editing and deleting a track.
 *
 * These live on a **different host** — `xhr.hearthis.at`, not `api-v2` — which
 * is why `POST /upload/` on the read API merely redirects to the web form and
 * makes it look as though the platform has no write API at all. It has a full
 * one.
 *
 * Every call here needs credentials AND an active Premium account: anonymous
 * requests get 401, logged-in free accounts get 403. Everything is scoped to
 * the authenticated account — touching someone else's track is a 403, which is
 * worth knowing before you build a batch job around it.
 *
 * Reached through `Hearthis::for($user)->withCredentials()->write()`.
 */
class TrackWriter
{
    public function __construct(protected Hearthis $client) {}

    /**
     * Upload an audio file, with any metadata, in one call.
     *
     * Accepted: mp3, wav, flac, aac, m4a, ogg, aif/aiff, wma, opus.
     *
     * The audio lands first and the optional fields are applied best-effort:
     * an unknown genre or an unparseable date does NOT fail the upload, it
     * comes back as `meta_error` on the item. That is generous of them and
     * treacherous for us — a silent `meta_error` looks exactly like success —
     * so `$strict` (the default) turns one into an exception. Pass false when
     * you would rather have the file up and fix the metadata afterwards.
     *
     * @param  array<string,mixed>  $meta  title, private, description, genre,
     *                                     tags, tracklist, downloadable,
     *                                     comments, release_at, unpublish_at
     * @param  string|null  $cover  path to a JPG or PNG, ≤ 10 MB
     * @return array<string,mixed> the uploaded track: id, name, filename, meta
     */
    public function upload(string $path, array $meta = [], ?string $cover = null, bool $strict = true): array
    {
        if (! is_file($path)) {
            throw new HearthisException("No such file: {$path}");
        }

        // A HANDLE, not the file's contents. `file_get_contents` on a two-hour
        // set is two hundred megabytes in PHP's memory before a single byte
        // leaves the machine — on a box with 2 GB free and a queue worker
        // already running, that is the difference between a release and an
        // out-of-memory. Guzzle streams a resource straight to the socket.
        $request = $this->request()->attach('file', $this->handle($path), basename($path));

        if ($cover !== null) {
            if (! is_file($cover)) {
                throw new HearthisException("No such cover image: {$cover}");
            }

            // `image` beats artwork embedded in the file's ID3 tags, which is
            // extracted automatically when this is absent.
            $request = $request->attach('image', $this->handle($cover), basename($cover));
        }

        return $this->file($request->post($this->url('upload_api.php'), $this->fields($meta))->json(), $strict);
    }

    /**
     * Unwrap an upload response.
     *
     * @param  mixed  $body
     * @return array<string,mixed>
     */
    protected function file($body, bool $strict): array
    {
        $file = is_array($body) ? ($body['files'][0] ?? null) : null;

        if (! is_array($file)) {
            throw new HearthisException('hearthis returned no file in the upload response.');
        }

        // Their errors arrive inside a 200 body, not as a status code. That
        // includes «Duplicate content: This file was already uploaded», which
        // is the one a re-run of a batch will hit.
        if (! empty($file['error'])) {
            throw new HearthisException((string) $file['error']);
        }

        if ($strict && ! empty($file['meta_error'])) {
            throw new HearthisException(
                'The audio uploaded (id '.($file['id'] ?? '?').') but metadata did not apply: '
                .$file['meta_error']
            );
        }

        return $file;
    }

    /**
     * Patch any subset of a track's metadata. Only what you send changes.
     *
     * Unlike upload, this one validates properly: an unknown genre is a 400
     * with the exact reason, not a shrug. Which makes it the right place to set
     * anything you actually care about.
     *
     * @param  array<string,mixed>  $fields
     * @return array<string,mixed>
     */
    public function edit(string|int $trackId, array $fields): array
    {
        if ($fields === []) {
            throw new HearthisException('Nothing to edit — pass at least one field.');
        }

        return $this->send('track_edit_api.php', ['track_id' => $trackId] + $this->fields($fields));
    }

    /**
     * Soft-delete a track: hidden from the site, from search and from every set
     * it was in. Owner only.
     *
     * Not undoable from this API — there is no restore endpoint — so it is a
     * separate method rather than a flag on edit().
     */
    public function delete(string|int $trackId): array
    {
        return $this->send('track_edit_api.php', ['action' => 'delete', 'track_id' => $trackId]);
    }

    /**
     * Set or replace the cover.
     *
     * JPG or PNG, at most 10 MB, at least 50×50; anything over 2000 px on the
     * long side is downscaled. The image is re-encoded server-side, so embedded
     * data does not survive — which is a feature, not a loss.
     *
     * @return array<string,mixed> id, img, artwork_url
     */
    public function setCover(string|int $trackId, string $path): array
    {
        if (! is_file($path)) {
            throw new HearthisException("No such image: {$path}");
        }

        $response = $this->request()
            ->attach('image', $this->handle($path), basename($path))
            ->post($this->url('track_image_api.php'), ['track_id' => $trackId]);

        return $this->result($response->json());
    }

    public function removeCover(string|int $trackId): array
    {
        return $this->send('track_image_api.php', ['action' => 'delete', 'track_id' => $trackId]);
    }

    // -----------------------------------------------------------------

    /**
     * Normalise the fields hearthis is picky about.
     *
     * `tags` may be an array here and is joined; booleans become 1/0 because
     * `false` would be sent as an empty string and read as "not set"; dates
     * become ISO 8601 WITH an offset, since a bare date is interpreted in the
     * server's timezone and nobody knows what that is.
     *
     * @param  array<string,mixed>  $fields
     * @return array<string,mixed>
     */
    protected function fields(array $fields): array
    {
        foreach (['private', 'downloadable', 'comments', 'allowpremium'] as $flag) {
            if (array_key_exists($flag, $fields)) {
                $fields[$flag] = $fields[$flag] ? 1 : 0;
            }
        }

        if (isset($fields['tags']) && is_array($fields['tags'])) {
            $fields['tags'] = implode(',', array_map('trim', $fields['tags']));
        }

        if (isset($fields['tracklist']) && is_array($fields['tracklist'])) {
            $fields['tracklist'] = Tracklist::format($fields['tracklist']);
        }

        foreach (['release_at', 'unpublish_at'] as $when) {
            if (array_key_exists($when, $fields)) {
                $fields[$when] = $this->schedule($fields[$when]);
            }
        }

        return $fields;
    }

    /**
     * Upload a file hearthis's other way: a raw binary body with an
     * `X-Filename` header, metadata in the query string.
     *
     * Same result as `upload()`, one less layer of encoding — multipart adds
     * boundaries and base64-free but still framed chunks around the audio.
     * Useful when the file is very large or the sending side is memory-shy.
     *
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public function uploadRaw(string $path, array $meta = [], bool $strict = true): array
    {
        if (! is_file($path)) {
            throw new HearthisException("No such file: {$path}");
        }

        $url = $this->url('upload_api.php');
        $query = http_build_query($this->fields($meta));

        $response = $this->request()
            ->withHeaders(['X-Filename' => basename($path)])
            ->withBody($this->handle($path), 'application/octet-stream')
            ->post($url.($query !== '' ? '&'.$query : ''));

        return $this->file($response->json(), $strict);
    }

    /** @return resource */
    protected function handle(string $path)
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new HearthisException("Could not open: {$path}");
        }

        return $handle;
    }

    /**
     * A schedule hearthis cannot misread.
     *
     * Their parser is `strtotime()` and the result is stored as a Unix
     * timestamp, so a value WITHOUT an offset is interpreted in their server's
     * timezone — which nobody knows. The failure is silent and expensive: the
     * upload succeeds, the field is accepted, and the release simply happens at
     * the wrong hour.
     *
     * So anything that is not already offset-bearing is resolved here, in the
     * application's own timezone, and sent as ISO 8601 with the offset spelled
     * out. `0`, null and '' pass through — those are how you CLEAR a schedule.
     */
    protected function schedule(mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        $value = trim((string) $value);

        // Already carries a zone: «…+02:00», «…Z», «… -0300».
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return $value;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            throw new HearthisException(
                "Could not read '{$value}' as a date. Use ISO 8601 with an offset, "
                .'e.g. 2026-07-01T20:00:00+02:00 — hearthis reads a bare date in its '
                .'own timezone, and the release quietly happens at the wrong hour.'
            );
        }
    }

    protected function request(): PendingRequest
    {
        if (! $this->client->hasCredentials()) {
            throw new HearthisException(
                'Writing needs a key/secret pair and an active Premium account. '
                .'Run `php artisan hearthis:login`, then ->withCredentials().'
            );
        }

        return Http::timeout((int) $this->client->config('upload_timeout', 600))->acceptJson();
    }

    protected function url(string $script): string
    {
        return rtrim((string) $this->client->config('upload_endpoint', 'https://xhr.hearthis.at/'), '/')
            .'/'.$script.'?'.http_build_query($this->client->credentials());
    }

    /** @param  array<string,mixed>  $body */
    protected function send(string $script, array $body): array
    {
        return $this->result($this->request()->asForm()->post($this->url($script), $body)->json());
    }

    /** @param  mixed  $body */
    protected function result($body): array
    {
        if (! is_array($body)) {
            throw new HearthisException('hearthis returned something unreadable.');
        }

        if (! empty($body['error'])) {
            throw new HearthisException((string) $body['error']);
        }

        return $body;
    }
}
