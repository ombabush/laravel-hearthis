<?php

namespace Ombabush\Hearthis;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Ombabush\Hearthis\Exceptions\HearthisException;

/**
 * Sets — what the site calls playlists.
 *
 * Reading is public and lives with the rest of api-v2; writing goes through the
 * site's own ajax endpoints, which is why the parameter names here look nothing
 * like the ones elsewhere (`set`, `new_set`, `deleteentry`). They are theirs,
 * not ours.
 *
 * Reached through `Hearthis::for($user)->withCredentials()->sets()`.
 */
class Sets
{
    public function __construct(protected Hearthis $client) {}

    /** The authenticated user's own sets. @return Collection<int,array<string,mixed>> */
    public function mine(int $count = 50, int $page = 1): Collection
    {
        return collect($this->client->raw('app/collections/', ['count' => $count, 'page' => $page], auth: true))
            ->filter(fn ($s) => is_array($s) && ! empty($s['id']))
            ->map(fn (array $s) => Playlist::fromApi($s))
            ->values();
    }

    /**
     * Add a track to an existing set.
     *
     * @return array<string,mixed>
     */
    public function add(string|int $setId, string|int $trackId): array
    {
        return $this->post('set_ajax_add.php', [
            'action' => 'add', 'set' => $setId, 'track_id' => $trackId,
        ]);
    }

    /**
     * Create a set around its first track.
     *
     * There is no "create an empty set": the same endpoint makes one by being
     * given a name instead of an id, and a track to put in it.
     *
     * @return array<string,mixed>
     */
    public function create(string $name, string|int $trackId): array
    {
        return $this->post('set_ajax_add.php', [
            'action' => 'add', 'set' => '', 'new_set' => $name, 'track_id' => $trackId,
        ]);
    }

    /** Remove one track from a set — not the set. */
    public function removeTrack(string|int $setId, string|int $trackId): array
    {
        return $this->post('set_ajax_edit.php', [
            'action' => 'deleteentry', 'set_id' => $setId, 'id' => $trackId,
        ]);
    }

    /** Delete the whole set. The tracks themselves are untouched. */
    public function delete(string|int $setId): array
    {
        return $this->post('set_ajax_edit.php', ['action' => 'delete', 'set' => $setId]);
    }

    /** @param  array<string,mixed>  $body */
    protected function post(string $script, array $body): array
    {
        return $this->client->postAuth($script, $body);
    }
}
