<?php

namespace Ombabush\Hearthis;

use Illuminate\Support\Collection;

/**
 * Groups — shared shelves of tracks, with members and roles.
 *
 * Roles are integers and the numbers are not ordinal in the obvious way:
 * 10 owner, 5 editor, 0 member, **-1 blocked**. A blocked member is not a
 * missing one; they are a row with a negative right.
 *
 * Reached through `Hearthis::for($user)->withCredentials()->groups()`.
 */
class Groups
{
    public const OWNER = 10;

    public const EDITOR = 5;

    public const MEMBER = 0;

    public const BLOCKED = -1;

    public function __construct(protected Hearthis $client) {}

    /** Public groups, most followed first. @return Collection<int,array<string,mixed>> */
    public function byGenre(?string $genre = null, int $count = 50, int $page = 1): Collection
    {
        return collect($this->client->raw('groups/', array_filter([
            'type' => 'genre', 'genre' => $genre, 'count' => min($count, 50), 'page' => $page,
        ])))->filter(fn ($g) => is_array($g))->values();
    }

    /** Groups a user owns. Omit the id for the authenticated account. */
    public function owned(string|int|null $userId = null, int $count = 50): Collection
    {
        return collect($this->client->raw('groups/', array_filter([
            'type' => 'user_groups', 'user_id' => $userId, 'count' => $count,
        ]), auth: true))->filter(fn ($g) => is_array($g))->values();
    }

    /** Groups a user has joined. Omit the id for the authenticated account. */
    public function joined(string|int|null $userId = null, int $count = 50): Collection
    {
        return collect($this->client->raw('groups/', array_filter([
            'type' => 'joined', 'user_id' => $userId, 'count' => $count,
        ]), auth: true))->filter(fn ($g) => is_array($g))->values();
    }

    /** @return array<string,mixed> */
    public function info(string|int $id): array
    {
        return (array) $this->client->raw('group/'.$id.'/info/');
    }

    /** Everything at once: `{ meta, tracks }`. @return array<string,mixed> */
    public function complete(string|int $id): array
    {
        return (array) $this->client->raw('group/'.$id.'/complete/');
    }

    /** @return Collection<int,array<string,mixed>> */
    public function tracks(string|int $id, int $count = 50, int $page = 1): Collection
    {
        return collect($this->client->raw('group/'.$id.'/', ['count' => $count, 'page' => $page]))
            ->filter(fn ($t) => is_array($t) && ! empty($t['id']))
            ->map(fn (array $t) => Track::fromApi($t))
            ->values();
    }

    /** Owner is always first, with rights 10. @return Collection<int,array<string,mixed>> */
    public function members(string|int $id, int $count = 50, int $page = 1): Collection
    {
        return collect($this->client->raw('group/'.$id.'/members/', ['count' => $count, 'page' => $page]))
            ->filter(fn ($m) => is_array($m))->values();
    }

    /**
     * @param  array<string,mixed>  $attributes  description, category, privat, sort_config
     * @return array<string,mixed> the new group
     */
    public function create(string $name, array $attributes = []): array
    {
        return $this->client->postAuth('groups/?type=create', ['name' => $name] + $attributes, onApi: true);
    }

    public function join(string|int $id): array
    {
        return $this->client->postAuth('group/'.$id.'/join/', [], onApi: true);
    }

    /** The owner cannot leave — they can only delete. */
    public function leave(string|int $id): array
    {
        return $this->client->postAuth('group/'.$id.'/leave/', [], onApi: true);
    }

    public function addTrack(string|int $id, string|int $trackId): array
    {
        return $this->client->postAuth('group/'.$id.'/add/', ['track_id' => $trackId], onApi: true);
    }

    public function removeTrack(string|int $id, string|int $trackId): array
    {
        return $this->client->postAuth('group/'.$id.'/remove/', ['track_id' => $trackId], onApi: true);
    }

    /** Owner only. Use the role constants: OWNER, EDITOR, MEMBER, BLOCKED. */
    public function setRole(string|int $id, string|int $userId, int $rights): array
    {
        return $this->client->postAuth('group/'.$id.'/permissions/',
            ['user_id' => $userId, 'rights' => $rights], onApi: true);
    }

    public function block(string|int $id, string|int $userId): array
    {
        return $this->client->postAuth('group/'.$id.'/block/', ['user_id' => $userId], onApi: true);
    }

    /**
     * Owner only, and permanent: the group, every track relation and every
     * member record go. Unlike a track, this is not a soft delete.
     */
    public function delete(string|int $id): array
    {
        return $this->client->postAuth('group/'.$id.'/delete/', [], onApi: true);
    }
}
