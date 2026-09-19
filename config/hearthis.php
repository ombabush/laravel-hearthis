<?php

return [
    /*
     * The profile to read when none is named. hearthis.at's read API takes no
     * key and no account — this is the same public endpoint its own embeds use.
     */
    'user' => env('HEARTHIS_USER'),

    'endpoint' => env('HEARTHIS_ENDPOINT', 'https://api-v2.hearthis.at/'),

    'embed' => env('HEARTHIS_EMBED', 'https://app.hearthis.at/embed/'),

    'timeout' => (int) env('HEARTHIS_TIMEOUT', 20),

    /*
     * Tracks per request. hearthis pages at 50; `max_pages` is a ceiling so a
     * misbehaving endpoint cannot walk for ever.
     */
    'per_page' => (int) env('HEARTHIS_PER_PAGE', 50),

    'max_pages' => (int) env('HEARTHIS_MAX_PAGES', 20),

    /*
     * How long a fetched listing stays cached. Zero disables caching, which is
     * what a sync command wants — it is asking BECAUSE it wants the new answer.
     */
    'cache_ttl' => (int) env('HEARTHIS_CACHE_TTL', 0),
];
