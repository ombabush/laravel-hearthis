<?php

namespace Ombabush\Hearthis;

/** One playlist — a «set» — and the ids of the tracks on it. */
class Playlist
{
    /**
     * @param  array<int,string>  $trackIds
     * @return array<string,mixed>
     */
    public static function fromApi(array $set, array $trackIds = []): array
    {
        return [
            'id' => (string) ($set['id'] ?? ''),
            'title' => trim((string) ($set['title'] ?? '')),
            'url' => (string) ($set['permalink_url'] ?? ''),
            'permalink' => (string) ($set['permalink'] ?? ''),
            'description' => trim(strip_tags((string) ($set['description'] ?? ''))),
            'artwork' => (string) ($set['artwork_url'] ?? $set['thumb'] ?? ''),
            'count' => (int) ($set['track_count'] ?? count($trackIds)),
            'tracks' => $trackIds,
        ];
    }
}
