<?php

namespace Ombabush\Hearthis;

/**
 * A profile.
 *
 * `api-v2.hearthis.at/<user>/` with no `type` returns the artist rather than
 * their tracks — easy to miss, and the only way to get the avatar, the bio and
 * the follower counts.
 */
class Artist
{
    /** @return array<string,mixed> */
    public static function fromApi(array $u): array
    {
        return [
            'id' => (string) ($u['id'] ?? ''),
            'name' => trim((string) ($u['username'] ?? '')),
            'permalink' => (string) ($u['permalink'] ?? ''),
            'url' => (string) ($u['permalink_url'] ?? ''),
            'caption' => trim((string) ($u['caption'] ?? '')),
            'description' => trim(strip_tags((string) ($u['description'] ?? ''))),
            'location' => trim((string) ($u['geo'] ?? '')),
            'avatar' => (string) ($u['avatar_url'] ?? ''),
            'avatar_retina' => (string) ($u['avatar_url_retina'] ?? ''),
            'background' => (string) ($u['background_url'] ?? ''),
            'tracks' => (int) ($u['counts']['tracks'] ?? 0),
            'playlists' => (int) ($u['counts']['playlists'] ?? 0),
            'followers' => (int) ($u['counts']['followers'] ?? $u['fans'] ?? 0),
            'following' => (int) ($u['counts']['following'] ?? 0),
            'links' => array_values((array) ($u['external_links'] ?? [])),
        ];
    }
}
