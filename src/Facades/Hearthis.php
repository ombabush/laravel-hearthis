<?php

namespace Ombabush\Hearthis\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Ombabush\Hearthis\Hearthis for(string $user)
 * @method static \Illuminate\Support\Collection tracks()
 * @method static \Illuminate\Support\Collection playlists(bool $withTracks = true)
 * @method static array playlistTrackIds(string $permalink)
 * @method static string embedUrl(string|int $id, array $params = [])
 * @method static string profileUrl()
 * @method static string user()
 * @method static array artist()
 * @method static array track(string $permalink)
 * @method static \Illuminate\Support\Collection likes()
 * @method static \Illuminate\Support\Collection reposts()
 * @method static \Illuminate\Support\Collection followers(int $count = 50, int $page = 1)
 * @method static \Illuminate\Support\Collection following(int $count = 50, int $page = 1)
 * @method static \Illuminate\Support\Collection related(string $permalink, int $count = 10)
 * @method static \Illuminate\Support\Collection comments(string $permalink)
 * @method static array chapters(string $permalink)
 * @method static array transcript(string $permalink)
 * @method static \Illuminate\Support\Collection search(string $query, string $type = 'tracks', int $count = 20, int $page = 1)
 * @method static \Illuminate\Support\Collection feed(?string $type = null, int $count = 20, ?int $minMinutes = null, ?int $maxMinutes = null, ?string $category = null, int $page = 1)
 * @method static \Illuminate\Support\Collection popular(?string $category = null, int $count = 20)
 * @method static \Illuminate\Support\Collection longSets(int $minMinutes = 60, int $count = 20)
 * @method static \Illuminate\Support\Collection categories()
 * @method static \Illuminate\Support\Collection category(string $slug, int $count = 20, int $page = 1)
 * @method static \Ombabush\Hearthis\TrackWriter write()
 * @method static \Ombabush\Hearthis\Hearthis withCredentials(?string $key = null, ?string $secret = null)
 * @method static array login(string $email, string $password, array $config = [])
 * @method static int duration(iterable $tracks)
 * @method static string humanDuration(int $seconds)
 *
 * @see \Ombabush\Hearthis\Hearthis
 */
class Hearthis extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ombabush\Hearthis\Hearthis::class;
    }
}
