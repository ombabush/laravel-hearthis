<?php

namespace Ombabush\Hearthis;

use Illuminate\Support\Carbon;

/**
 * One track, reduced to what a page actually shows.
 *
 * hearthis returns about seventy fields. Keeping all of them means keeping
 * `stream_url` — which carries a short-lived token and therefore stores
 * something that stops working — along with sixty others nobody reads.
 *
 * What IS kept includes the things an <iframe> embed can never give you: bpm,
 * musical key, tags, the waveform image, and the counts. Those are the reason
 * to talk to the API at all.
 */
class Track
{
    /** @return array<string,mixed> */
    public static function fromApi(array $t): array
    {
        $released = trim((string) ($t['release_date'] ?? ''));
        $tags = trim((string) ($t['tags'] ?? ''));

        return [
            'id' => (string) ($t['id'] ?? ''),
            'title' => trim((string) ($t['title'] ?? '')),
            'permalink' => (string) ($t['permalink'] ?? ''),
            'url' => (string) ($t['permalink_url'] ?? ''),
            'released' => $released !== '' ? Carbon::parse($released)->toDateString() : null,
            'duration' => (int) ($t['duration'] ?? 0),
            'genre' => trim((string) ($t['genre'] ?? '')),
            'tags' => $tags !== '' ? array_values(array_filter(array_map('trim', explode(',', $tags)))) : [],
            'bpm' => (int) ($t['bpm'] ?? 0) ?: null,
            // Musical key — «Am», «F#m». Blank on most uploads, priceless on the
            // ones that have it.
            'key' => trim((string) ($t['key'] ?? '')) ?: null,
            'description' => trim(strip_tags((string) ($t['description'] ?? ''))),
            'artwork' => (string) ($t['artwork_url'] ?? $t['thumb'] ?? ''),
            'artwork_retina' => (string) ($t['artwork_url_retina'] ?? ''),
            'waveform' => (string) ($t['waveform_url'] ?? ''),
            'plays' => (int) ($t['playback_count'] ?? 0),
            'likes' => (int) ($t['favoritings_count'] ?? 0),
            'comments' => (int) ($t['comment_count'] ?? 0),
            'downloadable' => (bool) ($t['downloadable'] ?? false),
            'artist' => trim((string) ($t['user']['username'] ?? '')),
            'artist_url' => (string) ($t['user']['permalink_url'] ?? ''),
        ];
    }
}
