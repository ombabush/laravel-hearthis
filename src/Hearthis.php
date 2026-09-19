<?php

namespace Ombabush\Hearthis;

use Illuminate\Support\Carbon;
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
 * package exists to replace: pasting <iframe> tags into a page by hand.
 *
 * An embed carries a track id and nothing else. The API carries artwork, release
 * date, duration, genre, description and play count, and it knows about tracks
 * the page was never updated with — on the profile this was written against, the
 * page had 26 embeds and the profile had 63 sets.
 *
 * Returns plain arrays rather than objects on purpose: the caller almost always
 * wants to put them in a jsonb column or a cache, and a DTO is one `toArray()`
 * away from that at every call site.
 */
class Hearthis
{
    public function __construct(
        protected ?string $user = null,
        protected array $config = [],
    ) {}

    /** A reader bound to one profile. */
    public static function for(string $user): static
    {
        return new static($user);
    }

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
     * Every track on the profile, newest first.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function tracks(): Collection
    {
        return $this->remember('tracks:'.$this->user(), function () {
            $perPage = max(1, (int) $this->option('per_page', 50));
            $tracks = [];

            for ($page = 1; $page <= (int) $this->option('max_pages', 20); $page++) {
                $batch = $this->get($this->user().'/', [
                    'type' => 'tracks', 'page' => $page, 'count' => $perPage,
                ]);

                if ($batch === []) {
                    break;
                }

                foreach ($batch as $track) {
                    if (is_array($track) && ! empty($track['id'])) {
                        $tracks[(string) $track['id']] = Track::fromApi($track);
                    }
                }

                if (count($batch) < $perPage) {
                    break;
                }
            }

            return collect(array_values($tracks))
                ->sortByDesc(fn (array $t) => (string) $t['released'])
                ->values();
        });
    }

    /**
     * The profile's playlists — «sets», in hearthis's own word — each with the
     * ids of the tracks on it.
     *
     * One extra request per playlist, because the listing endpoint gives a
     * count but not the contents. Skip it with `withTracks: false` when all you
     * want is the shelf.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function playlists(bool $withTracks = true): Collection
    {
        return $this->remember('playlists:'.$this->user().':'.(int) $withTracks, function () use ($withTracks) {
            $sets = $this->get($this->user().'/', ['type' => 'playlists', 'page' => 1, 'count' => 50]);

            return collect($sets)
                ->filter(fn ($s) => is_array($s) && ! empty($s['permalink']))
                ->map(function (array $set) use ($withTracks) {
                    $ids = $withTracks ? $this->playlistTrackIds((string) $set['permalink']) : [];

                    return Playlist::fromApi($set, $ids);
                })
                ->sortByDesc('count')
                ->values();
        });
    }

    /** @return array<int,string> */
    public function playlistTrackIds(string $permalink): array
    {
        $tracks = $this->get('set/'.trim($permalink, '/').'/');

        return collect($tracks)
            ->filter(fn ($t) => is_array($t) && ! empty($t['id']))
            ->map(fn (array $t) => (string) $t['id'])
            ->values()->all();
    }

    /**
     * The embed URL for a track.
     *
     * Kept here rather than in a view because the id is the only stable handle
     * hearthis gives you — `stream_url` carries a short-lived token, so storing
     * one stores something that stops working.
     */
    public function embedUrl(string|int $id, array $params = []): string
    {
        $params = array_merge([
            'style' => 2, 'waveform' => 1, 'cover' => 0, 'autoplay' => 0,
        ], $params);

        return rtrim((string) $this->option('embed', 'https://app.hearthis.at/embed/'), '/')
            .'/'.urlencode((string) $id).'/transparent_black/?'.http_build_query($params);
    }

    public function profileUrl(): string
    {
        return 'https://hearthis.at/'.$this->user().'/';
    }

    /** @return array<int,mixed> */
    protected function get(string $path, array $query = []): array
    {
        $response = Http::timeout((int) $this->option('timeout', 20))
            ->acceptJson()
            ->get(rtrim((string) $this->option('endpoint', 'https://api-v2.hearthis.at/'), '/').'/'.$path, $query);

        if (! $response->successful()) {
            throw new HearthisException(
                "hearthis.at answered {$response->status()} for /{$path}."
            );
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    protected function remember(string $key, \Closure $fetch): Collection
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

    /** Total running time of a track list, in seconds. */
    public static function duration(iterable $tracks): int
    {
        $total = 0;

        foreach ($tracks as $track) {
            $total += (int) ($track['duration'] ?? 0);
        }

        return $total;
    }
}
