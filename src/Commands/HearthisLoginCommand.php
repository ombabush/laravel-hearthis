<?php

namespace Ombabush\Hearthis\Commands;

use Illuminate\Console\Command;
use Ombabush\Hearthis\Hearthis;

/**
 * Where the key and secret come from.
 *
 * hearthis has no developer portal, no OAuth and no "create an application"
 * form. You POST your email and password to `/login/` once and it hands back a
 * `key` and a `secret`, which every other endpoint then accepts as ordinary
 * query parameters.
 *
 * So this command exists to be run by a person, at their own terminal, exactly
 * once. The password is prompted for and hidden, never taken as an argument —
 * an argument lands in shell history and in `ps` — and the pair is printed for
 * the operator to paste into their own `.env`. The package does not write it
 * anywhere and does not keep it.
 */
class HearthisLoginCommand extends Command
{
    protected $signature = 'hearthis:login {email? : the account email}';

    protected $description = 'Exchange your hearthis.at email and password for an API key/secret pair';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('hearthis.at email');

        // `secret()` hides the typing. A password passed as an argument would
        // sit in shell history and be visible in the process list.
        $password = $this->secret('password (not stored, not echoed)');

        // Said out loud because it is hearthis's design and the operator cannot
        // opt out of it: /login/ is a GET, so the password goes in the query
        // string — into their access logs, and any proxy's in between.
        $this->newLine();
        $this->warn('hearthis /login/ is a GET: the password travels in the URL and will');
        $this->warn('appear in their server logs. This is their API, not a choice here.');

        if (! $email || ! $password) {
            $this->error('Both an email and a password are needed.');

            return self::FAILURE;
        }

        try {
            $result = Hearthis::login($email, $password);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Signed in as '.($result['user']['name'] ?: $email).'.');
        $this->newLine();
        $this->line('Put these in your .env — this command will not do it for you,');
        $this->line('because a package that edits your .env is a package that surprises you:');
        $this->newLine();
        $this->line('  <fg=cyan>HEARTHIS_KEY</>='.$result['key']);
        $this->line('  <fg=cyan>HEARTHIS_SECRET</>='.$result['secret']);
        $this->newLine();
        $this->comment('Then: Hearthis::for($user)->withCredentials()');

        return self::SUCCESS;
    }
}
