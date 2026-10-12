<?php
declare(strict_types=1);

/** Load settings (and apply the configured time zone). */
function cfg(?string $key = null)
{
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
        date_default_timezone_set($c['timezone'] ?? 'UTC');
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

function env_value(string $k): ?string
{
    $v = getenv($k);
    if ($v === false || $v === '') {
        $v = $_SERVER[$k] ?? ($_ENV[$k] ?? false);
    }
    return ($v === false || $v === '') ? null : (string) $v;
}

/** 'mysql' or 'sqlite'. */
function db_driver(): string
{
    $d = strtolower((string) cfg('db_driver'));
    if ($d === 'mysql' || $d === 'sqlite') {
        return $d;
    }
    return env_value('DB_HOST') !== null ? 'mysql' : 'sqlite';
}

function mysql_settings(): array
{
    $m = (array) (cfg('mysql') ?? []);
    return [
        'host' => env_value('DB_HOST') ?? (string) ($m['host'] ?? 'localhost'),
        'port' => env_value('DB_PORT') ?? (string) ($m['port'] ?? '3306'),
        'name' => env_value('DB_NAME') ?? (string) ($m['name'] ?? ''),
        'user' => env_value('DB_USERNAME') ?? env_value('DB_USER') ?? (string) ($m['user'] ?? ''),
        'pass' => env_value('DB_PASSWORD') ?? (string) ($m['pass'] ?? ''),
        'ssl'  => strtolower((string) ($m['ssl'] ?? 'auto')),
    ];
}

function pdo_mysql_const(string $name): ?int
{
    foreach (['Pdo\\Mysql::ATTR_' . $name, 'PDO::MYSQL_ATTR_' . $name] as $c) {
        if (defined($c)) {
            return (int) constant($c);
        }
    }
    return null;
}

function connect_mysql(): PDO
{
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('The PHP "pdo_mysql" extension is not available on this server.');
    }
    $s = mysql_settings();
    $dsn = 'mysql:host=' . $s['host'] . ';port=' . $s['port'] . ';dbname=' . $s['name'] . ';charset=utf8mb4';
    $base = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ];
    $local = in_array($s['host'], ['localhost', '127.0.0.1', '::1'], true);
    $tryTls = $s['ssl'] === 'on' || $s['ssl'] === 'auto' && !$local;
    $verify = pdo_mysql_const('SSL_VERIFY_SERVER_CERT');
    $cipher = pdo_mysql_const('SSL_CIPHER');

    if ($tryTls && $verify !== null && $cipher !== null) {
        try {
            // Encrypted connection. Managed databases often use a private
            // certificate authority, so the certificate chain is not verified.
            return new PDO($dsn, $s['user'], $s['pass'], $base + [$verify => false, $cipher => 'DEFAULT']);
        } catch (PDOException $e) {
            if ($s['ssl'] === 'on') {
                throw $e;
            }
        }
    }
    return new PDO($dsn, $s['user'], $s['pass'], $base);
}

function connect_sqlite(): PDO
{
    $path = (string) cfg('db_path');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 3000');
    return $pdo;
}

function create_schema(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS hits (
            id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ts       BIGINT NOT NULL,
            day      CHAR(10) NOT NULL,
            hr       TINYINT NOT NULL,
            visitor  CHAR(20) NOT NULL,
            host     VARCHAR(255) NOT NULL,
            path     VARCHAR(255) NOT NULL,
            ref_host VARCHAR(255) NOT NULL DEFAULT "",
            browser  VARCHAR(40) NOT NULL,
            os       VARCHAR(40) NOT NULL,
            device   VARCHAR(20) NOT NULL,
            INDEX idx_hits_day (day),
            INDEX idx_hits_ts (ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(128) NOT NULL PRIMARY KEY,
            data TEXT NOT NULL,
            ts BIGINT NOT NULL,
            INDEX idx_sessions_ts (ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS hits (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        ts       INTEGER NOT NULL,
        day      TEXT    NOT NULL,
        hr       INTEGER NOT NULL,
        visitor  TEXT    NOT NULL,
        host     TEXT    NOT NULL,
        path     TEXT    NOT NULL,
        ref_host TEXT    NOT NULL DEFAULT "",
        browser  TEXT    NOT NULL,
        os       TEXT    NOT NULL,
        device   TEXT    NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hits_day ON hits(day)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hits_ts ON hits(ts)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (id TEXT PRIMARY KEY, data TEXT NOT NULL, ts INTEGER NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_ts ON sessions(ts)');
}

/** Connect to the database (MySQL or SQLite) and create tables on first use. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $driver = db_driver();
    $conn = $driver === 'mysql' ? connect_mysql() : connect_sqlite();
    try {
        $conn->query('SELECT 1 FROM sessions LIMIT 1')->closeCursor();
    } catch (PDOException $e) {
        create_schema($conn, $driver);
    }
    return $pdo = $conn;
}

/** SQL for "unique visitor per day" counting, valid on both databases. */
function dv(): string
{
    return db_driver() === 'mysql' ? 'CONCAT(day, visitor)' : "(day || visitor)";
}

/** Insert-or-update a two-column row. $table has columns ($key, $cols...). */
function upsert(string $table, string $keyCol, array $row): void
{
    $cols = array_keys($row);
    $ph = implode(', ', array_fill(0, count($cols), '?'));
    $upd = [];
    foreach ($cols as $c) {
        if ($c !== $keyCol) {
            $upd[] = db_driver() === 'mysql' ? "$c = VALUES($c)" : "$c = excluded.$c";
        }
    }
    $tail = db_driver() === 'mysql'
        ? 'ON DUPLICATE KEY UPDATE ' . implode(', ', $upd)
        : "ON CONFLICT($keyCol) DO UPDATE SET " . implode(', ', $upd);
    $st = db()->prepare("INSERT INTO $table (" . implode(', ', $cols) . ") VALUES ($ph) $tail");
    $st->execute(array_values($row));
}

function setting_get(string $k): ?string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function setting_set(string $k, string $v): void
{
    upsert('settings', 'k', ['k' => $k, 'v' => $v]);
}

/** Keeps login sessions in the database, so they survive restarts and work across several servers. */
class DbSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        $st = db()->prepare('SELECT data FROM sessions WHERE id = ?');
        $st->execute([$id]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string) $v;
    }

    public function write(string $id, string $data): bool
    {
        upsert('sessions', 'id', ['id' => $id, 'data' => $data, 'ts' => time()]);
        return true;
    }

    public function destroy(string $id): bool
    {
        db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $st = db()->prepare('DELETE FROM sessions WHERE ts < ?');
        $st->execute([time() - $max_lifetime]);
        return $st->rowCount();
    }
}

/** Trim to a maximum number of bytes, keep valid UTF-8 (the database rejects broken text). */
function clean_str(string $s, int $max): string
{
    if (!preg_match('//u', $s)) {
        $s = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    }
    return function_exists('mb_strcut') ? mb_strcut($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

/** Random secret used to anonymise visitors. Created once, never shown. */
function secret(): string
{
    $s = setting_get('secret');
    if ($s === null) {
        $s = bin2hex(random_bytes(32));
        setting_set('secret', $s);
    }
    return $s;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function client_ip(): string
{
    $h = (string) cfg('ip_header');
    if ($h !== '') {
        $k = 'HTTP_' . strtoupper(str_replace('-', '_', $h));
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function is_bot(string $ua): bool
{
    if ($ua === '') {
        return true;
    }
    return (bool) preg_match(
        '/bot|crawl|spider|slurp|facebookexternalhit|preview|fetch|monitor|uptime|pingdom|headless|lighthouse|curl|wget|python-requests|httpclient|axios|go-http|java\//i',
        $ua
    );
}

/** Returns [browser, os, device] from a user-agent string. */
function parse_ua(string $ua): array
{
    if (preg_match('/Edg(e|A|iOS)?\//', $ua))            $browser = 'Edge';
    elseif (preg_match('/OPR\/|Opera/', $ua))            $browser = 'Opera';
    elseif (stripos($ua, 'SamsungBrowser') !== false)    $browser = 'Samsung Internet';
    elseif (preg_match('/UCBrowser|UCWEB/', $ua))        $browser = 'UC Browser';
    elseif (preg_match('/Firefox\/|FxiOS/', $ua))        $browser = 'Firefox';
    elseif (preg_match('/Chrome\/|CriOS/', $ua))         $browser = 'Chrome';
    elseif (stripos($ua, 'Safari') !== false)            $browser = 'Safari';
    elseif (preg_match('/MSIE|Trident/', $ua))           $browser = 'Internet Explorer';
    else                                                 $browser = 'Other';

    if (preg_match('/Windows/i', $ua))                   $os = 'Windows';
    elseif (preg_match('/Android/i', $ua))               $os = 'Android';
    elseif (preg_match('/iPhone|iPad|iPod/i', $ua))      $os = 'iOS';
    elseif (preg_match('/Mac OS X|Macintosh/i', $ua))    $os = 'macOS';
    elseif (preg_match('/CrOS/', $ua))                   $os = 'ChromeOS';
    elseif (preg_match('/Linux|X11/i', $ua))             $os = 'Linux';
    else                                                 $os = 'Other';

    if (preg_match('/iPad|Tablet/i', $ua) || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) {
        $device = 'Tablet';
    } elseif (preg_match('/Mobi|iPhone|Android/i', $ua)) {
        $device = 'Mobile';
    } else {
        $device = 'Desktop';
    }
    return [$browser, $os, $device];
}

/** Lower-cased host from a URL, without "www." prefix. */
function host_of(string $url): string
{
    $h = parse_url($url, PHP_URL_HOST);
    if (!is_string($h)) {
        return '';
    }
    $h = strtolower($h);
    return strpos($h, 'www.') === 0 ? substr($h, 4) : $h;
}
