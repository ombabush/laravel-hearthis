<?php

namespace Ombabush\Hearthis;

/**
 * Chapters, in hearthis's own format: one per line, `[timestamp] Artist - Title`.
 *
 * Three details that are easy to get wrong and impossible to notice:
 *
 *  - the split is on the FIRST « - » — space, hyphen, space. A hyphen inside a
 *    title survives; one without spaces is not a separator at all;
 *  - the timestamp may be MM:SS or HH:MM:SS, and is optional;
 *  - sending a tracklist REPLACES every chapter. There is no append.
 */
class Tracklist
{
    /**
     * Build the wire format from a list of chapters.
     *
     * Each entry is either a ready string, or ['at' => seconds|'MM:SS',
     * 'artist' => …, 'title' => …].
     *
     * @param  iterable<int,array<string,mixed>|string>  $chapters
     */
    public static function format(iterable $chapters): string
    {
        $lines = [];

        foreach ($chapters as $chapter) {
            if (is_string($chapter)) {
                $lines[] = trim($chapter);

                continue;
            }

            $at = $chapter['at'] ?? null;
            $stamp = is_numeric($at) ? static::stamp((int) $at) : trim((string) $at);
            $artist = trim((string) ($chapter['artist'] ?? ''));
            $title = trim((string) ($chapter['title'] ?? ''));

            $lines[] = trim($stamp.' '.($artist !== '' ? $artist.' - '.$title : $title));
        }

        return implode("\n", array_filter($lines));
    }

    /** @return array<int,array{at:?int, artist:string, title:string}> */
    public static function parse(string $tracklist): array
    {
        $chapters = [];

        foreach (preg_split('/\R/', $tracklist) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $at = null;

            if (preg_match('/^((?:\d+:)?\d{1,2}:\d{2})\s+(.*)$/', $line, $m)) {
                $at = static::seconds($m[1]);
                $line = $m[2];
            }

            // The first « - » only, so «Jean-Michel Jarre - Oxygène» keeps its
            // hyphen and splits in the right place.
            [$artist, $title] = str_contains($line, ' - ')
                ? array_map('trim', explode(' - ', $line, 2))
                : ['', $line];

            $chapters[] = ['at' => $at, 'artist' => $artist, 'title' => $title];
        }

        return $chapters;
    }

    public static function stamp(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $rest = $seconds % 3600;

        return $hours
            ? sprintf('%d:%02d:%02d', $hours, intdiv($rest, 60), $rest % 60)
            : sprintf('%02d:%02d', intdiv($rest, 60), $rest % 60);
    }

    public static function seconds(string $stamp): int
    {
        $parts = array_map('intval', explode(':', $stamp));

        return match (count($parts)) {
            3 => $parts[0] * 3600 + $parts[1] * 60 + $parts[2],
            2 => $parts[0] * 60 + $parts[1],
            default => (int) ($parts[0] ?? 0),
        };
    }
}
