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
