<?php

/*
 * Copy to config.php to change server settings. Everything else
 * (site name, alerts, SMTP) is edited in the dashboard.
 */
return [
    // SQLite database file. Keep it outside public/.
    'database'    => 'storage/vigil.sqlite',

    // Full address of this install, used for links in alerts and the heartbeat/badge URLs.
    'app_url'     => 'https://status.example.com',

    // Path prefix when Vigil lives in a subfolder, e.g. '/status/'.
    'base_url'    => '/',

    // Times on the status page and in alerts. Any PHP timezone, e.g. 'Europe/Berlin', 'Asia/Makassar'.
    'timezone'    => 'UTC',

    // Behind Cloudflare or a reverse proxy? Set true so login rate limits see the real visitor IP.
    'trust_proxy' => false,
];
