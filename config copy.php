<?php
/**
 * Visitor Monitor settings. Edit the values below, then save the file.
 */
return [
    // Your local time zone. Daily totals reset at midnight in this zone.
    // List of valid names: https://www.php.net/manual/en/timezones.php
    'timezone' => 'Africa/Lagos',

    // Only count visits from these domains (without https://).
    // Leave empty to accept visits from any site that uses your tracker script.
    // Example: ['example.com', 'www.example.com']
    'allowed_hosts' => [],

    // IP addresses to ignore, such as your own. Example: ['203.0.113.7']
    'ignore_ips' => [],

    // Skip visitors whose browser sends a "Do Not Track" signal.
    'respect_dnt' => false,

    // Delete hits older than this many days. 0 keeps everything.
    'retention_days' => 0,

    // Only change this if your site sits behind a proxy or CDN.
    // Cloudflare: 'CF-Connecting-IP'.  Others often: 'X-Forwarded-For'.
    // Leave empty if you are not sure.
    'ip_header' => '',

    // Which database to use:
    //   'auto'   - MySQL when the host provides DB_HOST (Wasmer does), otherwise SQLite
    //   'sqlite' - a single file, no setup (regular web hosting)
    //   'mysql'  - MySQL / MariaDB
    'db_driver' => 'auto',

    // SQLite only: where the database file lives. For best security, point this
    // to a folder outside your public web directory.
    'db_path' => __DIR__ . '/data/analytics.sqlite',

    // MySQL only. On Wasmer these are filled in automatically from the
    // DB_HOST, DB_PORT, DB_NAME, DB_USERNAME and DB_PASSWORD settings,
    // so you can leave this alone there. On other hosts, fill it in.
    'mysql' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'monitor',
        'user' => 'root',
        'pass' => 'wiztech',
        'ssl'  => 'auto', // 'auto' tries an encrypted connection first, 'on' requires it, 'off' disables it
    ],

    // Optional: the public web address of this tool, used in the tracking code
    // shown on the dashboard. Example: 'https://my-monitor.wasmer.app'
    'public_url' => '',
];
