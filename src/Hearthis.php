<?php

namespace Ombabush\Hearthis;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Ombabush\Hearthis\Exceptions\HearthisException;

/**
 * A reader for hearthis.at.
 *
 * The read API takes **no key and no account**: `api-v2.hearthis.at/<user>/` is
 * the same public endpoint hearthis's own embeds use. That is worth stating
 * plainly, because the obvious assumption — that a music platform's API needs
 * credentials — is what stops people using it, and the alternative is what this
 * package replaces: pasting <iframe> tags into a page by hand.
 *
 * An embed carries a track id and nothing else. The API carries artwork,
 * release date, duration, genre, tags, bpm, musical key, waveform and every
 * count — and it knows about the tracks nobody remembered to add to the page.
 *
 * Credentials are optional and exist for the authenticated half: hearthis
 * identifies a logged-in user by a `key`/`secret` pair sent as ordinary
 * parameters, obtained once from `POST /login/`. See `login()`.
 *
 * Everything returns plain arrays, not objects: the caller almost always wants
 * to put them in a jsonb column or a cache, and a DTO is one `toArray()` away
 * from that at every call site.
 */
class Hearthis
{
    protected ?string $key = null;

    protected ?string $secret = null;

    public function __construct(
        protected ?string $user = null,
        protected array $config = [],
    ) {}

    /** A reader bound to one profile. */
    public static function for(string $user): static
    {
        return new static($user);
    }

    /**
     * Send a `key`/`secret` pair with every request.
     *
     * hearthis has no OAuth and no developer portal: you POST your email and
     * password to `/login/` once, it hands back a key and a secret, and those
     * two go as query parameters on any endpoint thereafter. Unset, everything
     * here still works — it just sees what the public sees.
     */
    public function withCredentials(?string $key = null, ?string $secret = null): static
    {
        $clone = clone $this;
        $clone->key = $key ?: $this->option('key');
        $clone->secret = $secret ?: $this->option('secret');

        return $clone;
    }

    public function hasCredentials(): bool
    {
        return ($this->key ?: $this->option('key')) && ($this->secret ?: $this->option('secret'));
    }

    /**
     * Exchange an email and password for the credential pair, once.
     *
     * Two things about this are hearthis's design and not ours, and both are
     * worth knowing before you run it:
     *
     *  - It is a **GET**. The password therefore travels in the query string,
     *    which is exactly where a password should never be: query strings are
     *    written to access logs, proxy logs and browser history. There is no
     *    POST form of this endpoint. Run it once, from a console, and treat the
     *    password as having been seen.
     *  - The pair comes back as `masterkey` and `verify_code`, which are the
     *    same two values every other endpoint calls `key` and `secret`. The
     *    names are translated here so nothing downstream has to know.
     *
     * Deliberately a separate, explicit call that RETURNS the pair instead of
     * storing it: a password should pass through your hands and land in your
     * own `.env`, not be held by a library.
     *
     * @return array{key:string, secret:string, user:array<string,mixed>}
     */
    public static function login(string $email, string $password, array $config = []): array
    {
        $client = new static(null, $config);

        $response = Http::timeout((int) $client->option('timeout', 20))
            ->acceptJson()
            ->get(rtrim((string) $client->option('endpoint'), '/').'/login/', [
                'email' => $email, 'password' => $password,
            ]);

        $body = $response->json();

        // `masterkey`/`verify_code` is what /login/ calls them; `key`/`secret`
        // is what everything else does. Accept either, hand back one shape.
        $key = is_array($body) ? ($body['masterkey'] ?? $body['key'] ?? null) : null;
        $secret = is_array($body) ? ($body['verify_code'] ?? $body['secret'] ?? null) : null;

        if (! $key || ! $secret) {
            throw new HearthisException(
                'hearthis did not return a credential pair: '
                .(is_array($body) ? ($body['message'] ?? 'unknown response') : 'unreadable response')
            );
        }

        return [
            'key' => (string) $key,
            'secret' => (string) $secret,
            'user' => Artist::fromApi(is_array($body) ? $body : []),
        ];
    }

    // -----------------------------------------------------------------
    //  One profile
    // -----------------------------------------------------------------

    public function user(): string
    {
        $user = $this->user ?: $this->option('user');

        if (! $user) {
            throw new HearthisException(
                'No hearthis profile given. Pass one to Hearthis::for(), or set HEARTHIS_USER.'
            );
        }

        return trim($user, '/');
    }

    /**
     * The artist themselves — avatar, bio, counts, links.
     *
     * `/<user>/` with NO `type` returns the profile; with one it returns a
     * list. Easy to miss, and the only route to any of this.
     *
     * @return array<string,mixed>
     */
    public function artist(): array
    {
        return $this->remember('artist:'.$this->user(), function () {
            $body = $this->get($this->user().'/', ['count' => 1], assoc: true);

            return Artist::fromApi($body);
        });
    }

    /**
     * Every track on the profile, newest first.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function tracks(): Collection
    {
        return $this->remember('tracks:'.$this->user(), fn () => $this->walk($this->user().'/', ['type' => 'tracks']));
    }

    /**
     * Tracks the profile has liked. Needs no credentials — likes are public.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function likes(): Collection
    {
        return $this->remember('likes:'.$this->user(), fn () => $this->walk($this->user().'/', ['type' => 'likes']));
    }

    /**
     * One track, by its permalink («german-teacher-bday») or full URL.
     *
     * @return array<string,mixed>
     */
    public function track(string $permalink): array
    {
        $permalink = trim(str_replace('https://hearthis.at/', '', $permalink), '/');
        $path = str_contains($permalink, '/') ? $permalink : $this->user().'/'.$permalink;

        return Track::fromApi($this->get($path.'/', [], assoc: true));
    }

    /**
     * The profile's playlists — «sets», in hearthis's own word — each with the
     * ids of the tracks on it.
     *
     * One extra request per playlist, because the listing endpoint gives a
     * count but not the contents. Pass false when all you want is the shelf.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function playlists(bool $withTracks = true): Collection
    {
        return $this->remember('playlists:'.$this->user().':'.(int) $withTracks, function () use ($withTracks) {
            return collect($this->get($this->user().'/', ['type' => 'playlists', 'page' => 1, 'count' => 50]))
                ->filter(fn ($s) => is_array($s) && ! empty($s['permalink']))
                ->map(fn (array $set) => Playlist::fromApi($set, $withTracks ? $this->playlistTrackIds((string) $set['permalink']) : []))
                ->sortByDesc('count')
                ->values();
        });
    }

    /** @return array<int,string> */
    public function playlistTrackIds(string $permalink): array
    {
        return collect($this->get('set/'.trim($permalink, '/').'/'))
            ->filter(fn ($t) => is_array($t) && ! empty($t['id']))
            ->map(fn (array $t) => (string) $t['id'])
            ->values()->all();
    }

    /**
     * A playlist's tracks in full, not just their ids.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function playlistTracks(string $permalink): Collection
    {
        return collect($this->get('set/'.trim($permalink, '/').'/'))
            ->filter(fn ($t) => is_array($t) && ! empty($t['id']))
            ->map(fn (array $t) => Track::fromApi($t))
            ->values();
    }

    // -----------------------------------------------------------------
    //  The rest of hearthis
    // -----------------------------------------------------------------

    /**
     * Search all of hearthis.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function search(string $query, int $count = 20, int $page = 1): Collection
    {
        return collect($this->get('search', ['t' => $query, 'count' => $count, 'page' => $page]))
            ->filter(fn ($t) => is_array($t) && ! empty($t['id']))
            ->map(fn (array $t) => Track::fromApi($t))
            ->values();
    }

    /**
     * The site-wide feed. `popular` or `new`; `$minutes` filters by length,
     * which is how you ask for sets rather than singles.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function feed(string $type = 'popular', int $count = 20, ?int $minutes = null): Collection
    {
        $query = ['type' => $type, 'count' => $count];

        if ($minutes !== null) {
            $query['duration'] = $minutes;
        }

        return collect($this->get('feed/', $query))
            ->filter(fn ($t) => is_array($t) && ! empty($t['id']))
            ->map(fn (array $t) => Track::fromApi($t))
            ->values();
    }

    /** The 69 genres hearthis files things under. @return Collection<int,array<string,mixed>> */
    public function categories(): Collection
    {
        return $this->remember('categories', fn () => collect($this->get('categories/'))
            ->filter(fn ($c) => is_array($c) && ! empty($c['id']))
            ->map(fn (array $c) => [
                'id' => (string) $c['id'],
                'name' => trim((string) ($c['name'] ?? '')),
                'url' => (string) ($c['url'] ?? ''),
                'colour' => (string) ($c['background_color'] ?? ''),
            ])
            ->values());
    }

    /** One genre's feed. @return Collection<int,array<string,mixed>> */
    public function category(string $slug, int $count = 20, int $page = 1): Collection
    {
        return collect($this->get('categories/'.trim($slug, '/').'/', ['count' => $count, 'page' => $page]))
            ->filter(fn ($t) => is_array($t) && ! empty($t['id']))
            ->map(fn (array $t) => Track::fromApi($t))
            ->values();
    }

    // -----------------------------------------------------------------
    //  URLs and sums
    // -----------------------------------------------------------------

    /**
     * The embed URL for a track.
     *
     * The id is the only stable handle hearthis gives you — `stream_url`
     * carries a short-lived token, so storing one stores something that stops
     * working.
     */
    public function embedUrl(string|int $id, array $params = []): string
    {
        $params = array_merge(['style' => 2, 'waveform' => 1, 'cover' => 0, 'autoplay' => 0], $params);

        return rtrim((string) $this->option('embed', 'https://app.hearthis.at/embed/'), '/')
            .'/'.urlencode((string) $id).'/transparent_black/?'.http_build_query($params);
    }

    public function profileUrl(): string
    {
        return 'https://hearthis.at/'.$this->user().'/';
    }

    /** Total running time of a track list, in seconds. */
    public static function duration(iterable $tracks): int
    {
        $total = 0;

        foreach ($tracks as $track) {
            $total += (int) ($track['duration'] ?? 0);
        }

        return $total;
    }

    /** «12 h 40 min», or «40 min» when it is under an hour. */
    public static function humanDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = (int) round(($seconds % 3600) / 60);

        return $hours ? "{$hours} h {$minutes} min" : "{$minutes} min";
    }

    /**
     * Group a track list the two ways a set list is ever read.
     *
     * Here rather than in every caller's view because it is the same three
     * lines everywhere, and getting «no genre» and «no date» to sort sensibly
     * is the part people skip.
     *
     * NOTE the keys of byYear() arrive as INTEGERS: PHP converts a numeric
     * string array key to an int, so '2026' becomes 2026. Harmless in a
     * template; surprising in a comparison.
     *
     * @return Collection<string,Collection<int,array<string,mixed>>>
     */
    public static function byYear(iterable $tracks): Collection
    {
        return collect($tracks)
            ->groupBy(fn (array $t) => substr((string) ($t['released'] ?? ''), 0, 4) ?: '—')
            ->sortKeysDesc();
    }

    /** @return Collection<string,Collection<int,array<string,mixed>>> */
    public static function byGenre(iterable $tracks): Collection
    {
        return collect($tracks)
            ->groupBy(fn (array $t) => $t['genre'] ?: '—')
            ->sortByDesc(fn (Collection $group) => $group->count());
    }

    // -----------------------------------------------------------------
    //  Transport
    // -----------------------------------------------------------------

    /**
     * Walk a paged listing to the end.
     *
     * @return Collection<int,array<string,mixed>>
     */
    protected function walk(string $path, array $query): Collection
    {
        $perPage = max(1, (int) $this->option('per_page', 50));
        $items = [];

        for ($page = 1; $page <= (int) $this->option('max_pages', 20); $page++) {
            $batch = $this->get($path, $query + ['page' => $page, 'count' => $perPage]);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $track) {
                if (is_array($track) && ! empty($track['id'])) {
                    $items[(string) $track['id']] = Track::fromApi($track);
                }
            }

            if (count($batch) < $perPage) {
                break;
            }
        }

        return collect(array_values($items))
            ->sortByDesc(fn (array $t) => (string) $t['released'])
            ->values();
    }

    /** @return array<mixed> */
    protected function get(string $path, array $query = [], bool $assoc = false): array
    {
        if ($this->hasCredentials()) {
            $query += [
                'key' => $this->key ?: $this->option('key'),
                'secret' => $this->secret ?: $this->option('secret'),
            ];
        }

        $response = Http::timeout((int) $this->option('timeout', 20))
            ->acceptJson()
            ->get(rtrim((string) $this->option('endpoint', 'https://api-v2.hearthis.at/'), '/').'/'.$path, $query);

        if (! $response->successful()) {
            throw new HearthisException("hearthis.at answered {$response->status()} for /{$path}.");
        }

        $body = $response->json();

        if (! is_array($body)) {
            return [];
        }

        // hearthis answers 200 with a `success: false` body when a session or a
        // resource is not there, so the status code alone is not the answer.
        if ($assoc && isset($body['success']) && $body['success'] === false) {
            throw new HearthisException((string) ($body['message'] ?? 'hearthis refused the request.'));
        }

        return $body;
    }

    protected function remember(string $key, \Closure $fetch): mixed
    {
        $ttl = (int) $this->option('cache_ttl', 0);

        if ($ttl <= 0) {
            return $fetch();
        }

        return Cache::remember('hearthis:'.$key, $ttl, $fetch);
    }

    protected function option(string $key, mixed $default = null): mixed
    {
        return $this->config[$key]
            ?? (function_exists('config') ? config('hearthis.'.$key, $default) : $default);
    }
}
