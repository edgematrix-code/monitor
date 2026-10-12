<?php
declare(strict_types=1);
/**
 * Receives one page view from tracker.js and stores it.
 * Never shows errors to visitors and never slows the page down.
 */
require __DIR__ . '/lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, max-age=0');

function finish(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    } else {
        http_response_code(204);
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    // Read the page address and referrer (POST body or GET parameters).
    $url = '';
    $ref = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
        if (is_array($body)) {
            $url = (string) ($body['u'] ?? '');
            $ref = (string) ($body['r'] ?? '');
        }
    } else {
        $url = (string) ($_GET['u'] ?? '');
        $ref = (string) ($_GET['r'] ?? '');
    }

    $host = host_of($url);
    if ($host === '' || !preg_match('#^https?://#i', $url)) {
        finish();
    }

    // Allowed sites only.
    $allowed = array_map('strtolower', (array) cfg('allowed_hosts'));
    $allowed = array_map(fn($h) => preg_replace('/^www\./', '', $h), $allowed);
    if ($allowed && !in_array($host, $allowed, true)) {
        finish();
    }

    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = client_ip();

    if (is_bot($ua)) finish();
    if (cfg('respect_dnt') && (($_SERVER['HTTP_DNT'] ?? '') === '1')) finish();
    if (in_array($ip, (array) cfg('ignore_ips'), true)) finish();

    $path = parse_url($url, PHP_URL_PATH);
    $path = (is_string($path) && $path !== '') ? $path : '/';
    $path = clean_str($path, 255);
    $host = clean_str($host, 255);

    $refHost = host_of($ref);
    if ($refHost === $host) {
        $refHost = ''; // internal navigation is not a traffic source
    }
    $refHost = clean_str($refHost, 255);

    [$browser, $os, $device] = array_map(fn($v) => clean_str($v, 40), parse_ua($ua));

    $now = time();
    $day = date('Y-m-d', $now);
    // Anonymous visitor ID: changes every day and cannot be reversed.
    $visitor = substr(hash('sha256', secret() . '|' . $day . '|' . $ip . '|' . $ua), 0, 20);

    $st = db()->prepare('INSERT INTO hits
        (ts, day, hr, visitor, host, path, ref_host, browser, os, device)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$now, $day, (int) date('G', $now), $visitor, $host, $path, $refHost, $browser, $os, $device]);

    // Occasional clean-up of old data.
    $keep = (int) cfg('retention_days');
    if ($keep > 0 && random_int(1, 300) === 1) {
        db()->prepare('DELETE FROM hits WHERE ts < ?')->execute([$now - $keep * 86400]);
    }
} catch (Throwable $e) {
    error_log('Visitor Monitor: ' . $e->getMessage());
}
finish();
