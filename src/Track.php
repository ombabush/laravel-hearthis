<?php

namespace Ombabush\Hearthis;

use Illuminate\Support\Carbon;

/**
 * One track, reduced to what a page actually shows.
 *
 * hearthis returns seventy fields per track. Storing all of them means storing
 * `stream_url` — which carries a short-lived token and therefore stores
 * something that stops working — along with sixty others nobody reads.
 */
class Track
{
    /** @return array<string,mixed> */
    public static function fromApi(array $t): array
    {
        $released = trim((string) ($t['release_date'] ?? ''));

        return [
            'id' => (string) $t['id'],
            'title' => trim((string) ($t['title'] ?? '')),
            'url' => (string) ($t['permalink_url'] ?? ''),
            'released' => $released !== '' ? Carbon::parse($released)->toDateString() : null,
            'duration' => (int) ($t['duration'] ?? 0),
            'genre' => trim((string) ($t['genre'] ?? '')),
            'description' => trim(strip_tags((string) ($t['description'] ?? ''))),
            'artwork' => (string) ($t['artwork_url'] ?? $t['thumb'] ?? ''),
            'waveform' => (string) ($t['waveform_url'] ?? ''),
            'plays' => (int) ($t['playback_count'] ?? 0),
        ];
    }
}
