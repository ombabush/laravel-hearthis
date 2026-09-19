<?php

namespace Ombabush\Hearthis\Commands;

use Illuminate\Console\Command;
use Ombabush\Hearthis\Hearthis;

/**
 * Look at a profile without storing anything.
 *
 * The package deliberately does NOT write to your database: where a track list
 * belongs — a column, a cache, a table of its own — is the application's
 * decision, and a package that guesses it is a package you fight. This command
 * exists so you can see what you would be storing.
 */
class HearthisFetchCommand extends Command
{
    protected $signature = 'hearthis:fetch
                            {user? : profile to read (default: config hearthis.user)}
                            {--playlists : list the playlists instead of the tracks}
                            {--json : print raw JSON, for piping}';

    protected $description = 'Read tracks or playlists from hearthis.at';

    public function handle(Hearthis $hearthis): int
    {
        $client = ($user = $this->argument('user')) ? Hearthis::for($user) : $hearthis;

        try {
            $items = $this->option('playlists') ? $client->playlists() : $client->tracks();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line($items->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($items->isEmpty()) {
            $this->warn('Nothing there.');

            return self::SUCCESS;
        }

        foreach ($items as $item) {
            $this->line($this->option('playlists')
                ? sprintf('  <fg=cyan>%-44s</> %d track(s)', mb_substr($item['title'], 0, 44), $item['count'])
                : sprintf('  <fg=cyan>%-44s</> %s  %4d min  %s', mb_substr($item['title'], 0, 44),
                    $item['released'] ?? '—', intdiv($item['duration'], 60), $item['genre']));
        }

        $this->newLine();

        $this->info($this->option('playlists')
            ? sprintf('%d playlist(s), %d track slots.', $items->count(), $items->sum('count'))
            : sprintf('%d track(s), %.1f hours.', $items->count(), Hearthis::duration($items) / 3600));

        return self::SUCCESS;
    }
}
