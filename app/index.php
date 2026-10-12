<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

function fail_page(string $title, string $msg): void
{
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($title) . '</title><body style="font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:12vh auto;padding:0 20px">'
       . '<h1 style="font-size:1.4rem">' . e($title) . '</h1><p>' . e($msg) . '</p></body>';
    exit;
}

// Logins are stored in the database so they survive restarts and work across servers.
try {
    db();
    session_set_save_handler(new DbSessionHandler(), true);
} catch (Throwable $e) {
    error_log('Visitor Monitor: ' . $e->getMessage());
    fail_page('Cannot reach the database',
        db_driver() === 'mysql'
            ? 'Check that your MySQL database exists and that its host, name, user and password are correct (see the README).'
            : 'Check that the "data" folder exists and that PHP is allowed to write to it (see the README).');
}
session_name('vm_session');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf  = $_SESSION['csrf'];
$hash  = setting_get('admin_hash');
$error = '';

/* ---------- sign-in, first-time setup, sign-out ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif ($action === 'setup' && !$hash) {
        $p1 = (string) ($_POST['password'] ?? '');
        $p2 = (string) ($_POST['confirm'] ?? '');
        if (strlen($p1) < 8) {
            $error = 'Use at least 8 characters.';
        } elseif ($p1 !== $p2) {
            $error = 'The two passwords do not match.';
        } else {
            setting_set('admin_hash', password_hash($p1, PASSWORD_DEFAULT));
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
    } elseif ($action === 'login' && $hash) {
        if (password_verify((string) ($_POST['password'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        usleep(800000); // slow down password guessing
        $error = 'That password is not correct.';
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

$styles = <<<'CSS'
:root{--bg:#f3f5f7;--panel:#fff;--ink:#10212e;--muted:#5b6b78;--line:#dce3e8;--accent:#0b6e99;--pv:#c6d8e3;--live:#1f9d55;--soft:#e8f0f5}
@media (prefers-color-scheme:dark){:root{--bg:#0d1820;--panel:#13232e;--ink:#e7eef3;--muted:#93a4b1;--line:#243642;--accent:#53b6e8;--pv:#2a4658;--live:#4cc27f;--soft:#1a303d}}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
a{color:var(--accent)}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:4px}
.wrap{max-width:1080px;margin:0 auto;padding:24px 20px 56px}
header.top{display:flex;flex-wrap:wrap;gap:12px 24px;align-items:center;justify-content:space-between;margin-bottom:28px}
header.top h1{font-size:1rem;font-weight:650;margin:0;letter-spacing:.01em}
.controls{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.seg{display:inline-flex;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--panel)}
.seg a{padding:6px 14px;text-decoration:none;color:var(--muted);font-size:.9rem;border-right:1px solid var(--line)}
.seg a:last-child{border-right:0}
.seg a[aria-current=true]{background:var(--ink);color:var(--panel);font-weight:600}
select,button{font:inherit;font-size:.9rem;color:var(--ink);background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:6px 10px}
button{cursor:pointer}
.hero{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:28px;align-items:end;margin-bottom:28px}
.hero .big{font-size:clamp(3.4rem,10vw,6rem);line-height:1;font-weight:700;letter-spacing:-.03em;font-variant-numeric:tabular-nums}
.hero .cap{color:var(--muted);margin-top:6px}
.delta{display:inline-block;margin-left:10px;font-size:.95rem;font-weight:600;letter-spacing:0}
.delta.up{color:var(--live)}.delta.down{color:#c0392b}.delta.flat{color:var(--muted)}
.live{display:inline-flex;align-items:center;gap:8px;font-weight:600}
.dot{width:10px;height:10px;border-radius:50%;background:var(--live);box-shadow:0 0 0 0 var(--live);animation:pulse 2s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 color-mix(in srgb,var(--live) 55%,transparent)}70%{box-shadow:0 0 0 10px transparent}100%{box-shadow:0 0 0 0 transparent}}
@media (prefers-reduced-motion:reduce){.dot{animation:none}}
.facts{display:grid;grid-template-columns:repeat(3,1fr);gap:0;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
.facts div{padding:14px 16px 14px 0}
.facts div+div{padding-left:16px;border-left:1px solid var(--line)}
.facts b{display:block;font-size:1.5rem;font-variant-numeric:tabular-nums}
.facts span{color:var(--muted);font-size:.85rem}
section.block{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:18px 20px;margin-bottom:20px}
section.block h2{font-size:.95rem;margin:0 0 12px;font-weight:650}
.legend{display:flex;gap:16px;color:var(--muted);font-size:.85rem;margin-bottom:6px}
.legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;vertical-align:-1px}
svg.chart{width:100%;height:auto;display:block}
svg.chart .grid{stroke:var(--line);stroke-width:1}
svg.chart text{fill:var(--muted);font-size:12px;font-family:inherit}
svg.chart .pv{fill:var(--pv)}svg.chart .uv{fill:var(--accent)}
svg.chart .hit{fill:transparent}svg.chart g:hover .hit{fill:var(--soft)}
.cols{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}
table{width:100%;border-collapse:collapse;font-size:.92rem;table-layout:fixed}
th:not(:first-child){width:84px}
.cols>div{min-width:0}
th{font-weight:500;color:var(--muted);text-align:right;padding:0 0 6px;font-size:.82rem}
th:first-child{text-align:left}
td{padding:6px 0;border-top:1px solid var(--line);text-align:right;font-variant-numeric:tabular-nums}
td:first-child{text-align:left;position:relative;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:10px}
td:first-child span{position:relative}
td:first-child::before{content:"";position:absolute;left:0;top:3px;bottom:3px;width:var(--w,0%);background:var(--soft);border-radius:3px}
td:first-child span{padding-left:6px}
.empty{color:var(--muted);margin:0}
pre{background:var(--soft);padding:12px 14px;border-radius:8px;overflow:auto;font-size:.85rem;margin:8px 0}
.note{color:var(--muted);font-size:.85rem}
.auth{max-width:380px;margin:12vh auto;padding:0 20px}
.auth h1{font-size:1.5rem;margin:0 0 6px}
.auth p{color:var(--muted);margin:0 0 18px}
.auth label{display:block;font-size:.9rem;font-weight:600;margin:12px 0 4px}
.auth input{width:100%;font:inherit;padding:9px 11px;border:1px solid var(--line);border-radius:8px;background:var(--panel);color:var(--ink)}
.auth button{margin-top:18px;width:100%;padding:10px;background:var(--accent);color:#fff;border-color:var(--accent);font-weight:600}
.err{background:#fdecea;color:#8a1c12;border:1px solid #f5c2bd;padding:9px 12px;border-radius:8px;margin-bottom:12px;font-size:.9rem}
@media (max-width:760px){.hero{grid-template-columns:1fr}.cols{grid-template-columns:1fr}}
CSS;

/* ---------- not signed in: show the form ---------- */
if (empty($_SESSION['auth'])) {
    $setup = !$hash;
    ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $setup ? 'Create your password' : 'Sign in' ?> · Visitor Monitor</title>
<style><?= $styles ?></style></head><body>
<main class="auth">
  <h1><?= $setup ? 'Create your password' : 'Sign in' ?></h1>
  <p><?= $setup ? 'Choose a password to protect your visitor numbers.' : 'Enter your password to see your visitor numbers.' ?></p>
  <?php if ($error): ?><div class="err" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="<?= $setup ? 'setup' : 'login' ?>">
    <label for="pw">Password</label>
    <input id="pw" type="password" name="password" required autofocus
           autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>">
    <?php if ($setup): ?>
    <label for="pw2">Repeat password</label>
    <input id="pw2" type="password" name="confirm" required autocomplete="new-password">
    <?php endif; ?>
    <button type="submit"><?= $setup ? 'Save password' : 'Sign in' ?></button>
  </form>
</main></body></html>
<?php
    exit;
}

/* ---------- dashboard data ---------- */
function q(string $sql, array $p = []): array
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

function nice_max(int $v): int
{
    if ($v <= 4) return 4;
    $p = 10 ** (int) floor(log10($v));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($v <= $m * $p) return (int) ceil($m * $p);
    }
    return $v;
}

function pct_delta(int $now, int $before): array
{
    if ($before === 0) return $now === 0 ? ['flat', 'no change'] : ['up', 'new'];
    $d = ($now - $before) / $before * 100;
    if (abs($d) < 0.5) return ['flat', 'no change'];
    return [$d > 0 ? 'up' : 'down', ($d > 0 ? '+' : '−') . number_format(abs($d), abs($d) < 10 ? 1 : 0) . '%'];
}

$ranges = ['1' => 'Today', '7' => '7 days', '30' => '30 days', '90' => '90 days'];
$range  = isset($ranges[$_GET['range'] ?? '']) ? (string) $_GET['range'] : '7';
$n      = (int) $range;

$sites = q('SELECT host, COUNT(*) c FROM hits GROUP BY host ORDER BY c DESC');
$siteNames = array_column($sites, 'host');
$site = (string) ($_GET['site'] ?? '');
if (!in_array($site, $siteNames, true)) $site = '';

$dv        = dv();
$today     = date('Y-m-d');
$start     = date('Y-m-d', strtotime('-' . ($n - 1) . ' days'));
$prevStart = date('Y-m-d', strtotime('-' . (2 * $n - 1) . ' days'));
$prevEnd   = date('Y-m-d', strtotime('-' . $n . ' days'));

$siteSql = $site !== '' ? ' AND host = :host' : '';
$bind = function (array $p) use ($site): array {
    if ($site !== '') $p[':host'] = $site;
    return $p;
};

$tot = q("SELECT COUNT(*) pv, COUNT(DISTINCT {$dv}) uv FROM hits
          WHERE day >= :s AND day <= :e $siteSql", $bind([':s' => $start, ':e' => $today]))[0];
$prev = q("SELECT COUNT(*) pv, COUNT(DISTINCT {$dv}) uv FROM hits
           WHERE day >= :s AND day <= :e $siteSql", $bind([':s' => $prevStart, ':e' => $prevEnd]))[0];
$all = q("SELECT COUNT(*) pv, COUNT(DISTINCT {$dv}) uv, MIN(day) first_day FROM hits"
         . ($site !== '' ? ' WHERE host = :host' : ''), $bind([]))[0];
$online = (int) q("SELECT COUNT(DISTINCT visitor) c FROM hits WHERE ts >= :t $siteSql",
                  $bind([':t' => time() - 300]))[0]['c'];

$uv = (int) $tot['uv'];
$pv = (int) $tot['pv'];
[$dClass, $dText] = pct_delta($uv, (int) $prev['uv']);

/* chart series */
$labels = $uvS = $pvS = [];
if ($n === 1) {
    $rows = q("SELECT hr, COUNT(*) pv, COUNT(DISTINCT visitor) uv FROM hits
               WHERE day = :d $siteSql GROUP BY hr", $bind([':d' => $today]));
    $by = array_column($rows, null, 'hr');
    for ($h = 0; $h < 24; $h++) {
        $labels[] = date('ga', mktime($h, 0, 0));
        $uvS[] = (int) ($by[$h]['uv'] ?? 0);
        $pvS[] = (int) ($by[$h]['pv'] ?? 0);
    }
    $unit = 'hour';
} else {
    $rows = q("SELECT day, COUNT(*) pv, COUNT(DISTINCT visitor) uv FROM hits
               WHERE day >= :s AND day <= :e $siteSql GROUP BY day",
              $bind([':s' => $start, ':e' => $today]));
    $by = array_column($rows, null, 'day');
    for ($i = $n - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $labels[] = date('M j', strtotime($d));
        $uvS[] = (int) ($by[$d]['uv'] ?? 0);
        $pvS[] = (int) ($by[$d]['pv'] ?? 0);
    }
    $unit = 'day';
}

function chart_svg(array $labels, array $uvS, array $pvS, string $unit): string
{
    $W = 1000; $H = 250; $pl = 38; $pr = 6; $pt = 10; $pb = 26;
    $cnt = count($labels);
    $max = nice_max(max(1, max($pvS)));
    $iw = $W - $pl - $pr; $ih = $H - $pt - $pb;
    $slot = $iw / $cnt;
    $s = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Visitors and page views per ' . $unit . '">';
    foreach ([0, 0.5, 1] as $f) {
        $y = $pt + $ih - $ih * $f;
        $s .= '<line class="grid" x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . $y . '" y2="' . $y . '"/>';
        $s .= '<text x="' . ($pl - 6) . '" y="' . ($y + 4) . '" text-anchor="end">' . number_format((int) round($max * $f)) . '</text>';
    }
    $step = max(1, (int) ceil($cnt / 7));
    for ($i = 0; $i < $cnt; $i++) {
        $x = $pl + $slot * $i;
        $hp = $ih * $pvS[$i] / $max;
        $hu = $ih * $uvS[$i] / $max;
        $bw = max(1.5, $slot * 0.72);
        $bx = $x + ($slot - $bw) / 2;
        $uw = $bw * 0.55;
        $s .= '<g><title>' . e($labels[$i]) . ': ' . $uvS[$i] . ' visitors, ' . $pvS[$i] . ' page views</title>';
        $s .= '<rect class="hit" x="' . $x . '" y="' . $pt . '" width="' . $slot . '" height="' . $ih . '"/>';
        if ($pvS[$i] > 0) $s .= '<rect class="pv" x="' . $bx . '" y="' . ($pt + $ih - $hp) . '" width="' . $bw . '" height="' . $hp . '" rx="1.5"/>';
        if ($uvS[$i] > 0) $s .= '<rect class="uv" x="' . ($bx + ($bw - $uw) / 2) . '" y="' . ($pt + $ih - $hu) . '" width="' . $uw . '" height="' . $hu . '" rx="1.5"/>';
        $s .= '</g>';
        if ($i % $step === 0) {
            $s .= '<text x="' . ($x + $slot / 2) . '" y="' . ($H - 6) . '" text-anchor="middle">' . e($labels[$i]) . '</text>';
        }
    }
    return $s . '</svg>';
}

/* breakdown tables */
$where = "day >= :s AND day <= :e $siteSql";
$bp = $bind([':s' => $start, ':e' => $today]);
$topPages = q("SELECT path label, COUNT(*) a, COUNT(DISTINCT {$dv}) b FROM hits
               WHERE $where GROUP BY path ORDER BY a DESC, label ASC LIMIT 10", $bp);
$topRefs = q("SELECT CASE WHEN ref_host = '' THEN 'Direct or unknown' ELSE ref_host END label,
               COUNT(DISTINCT {$dv}) a, COUNT(*) b FROM hits
               WHERE $where GROUP BY label ORDER BY a DESC, label ASC LIMIT 10", $bp);
$browsers = q("SELECT browser label, COUNT(DISTINCT {$dv}) a FROM hits
               WHERE $where GROUP BY browser ORDER BY a DESC, label ASC LIMIT 8", $bp);
$systems = q("SELECT os label, COUNT(DISTINCT {$dv}) a FROM hits
              WHERE $where GROUP BY os ORDER BY a DESC, label ASC LIMIT 8", $bp);
$devices = q("SELECT device label, COUNT(DISTINCT {$dv}) a FROM hits
              WHERE $where GROUP BY device ORDER BY a DESC, label ASC", $bp);

function table(string $title, array $rows, string $c1, string $c2, ?string $c3 = null): void
{
    echo '<section class="block"><h2>' . e($title) . '</h2>';
    if (!$rows) {
        echo '<p class="empty">Nothing recorded for this period yet.</p></section>';
        return;
    }
    $max = max(1, (int) $rows[0]['a']);
    echo '<table><thead><tr><th>' . e($c1) . '</th><th>' . e($c2) . '</th>' . ($c3 ? '<th>' . e($c3) . '</th>' : '') . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $w = round((int) $r['a'] / $max * 100);
        echo '<tr><td style="--w:' . $w . '%" title="' . e($r['label']) . '"><span>' . e($r['label']) . '</span></td><td>'
            . number_format((int) $r['a']) . '</td>' . ($c3 ? '<td>' . number_format((int) $r['b']) . '</td>' : '') . '</tr>';
    }
    echo '</tbody></table></section>';
}

$publicUrl = rtrim((string) cfg('public_url'), '/');
if ($publicUrl !== '') {
    $base = $publicUrl;
} else {
    $hostName = (string) ($_SERVER['HTTP_HOST'] ?? 'your-domain.com');
    $isLocal = (bool) preg_match('/^(localhost|127\.|\[::1\])/', $hostName);
    $base = (($isLocal && !is_https()) ? 'http' : 'https') . '://' . $hostName
          . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
}
$snippet = '<script defer src="' . $base . '/tracker.js"></script>';
$qs = fn(array $o) => '?' . http_build_query(array_filter(array_merge(['range' => $range, 'site' => $site], $o), fn($v) => $v !== ''));
$ppv = $uv > 0 ? number_format($pv / $uv, 1) : '0';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta http-equiv="refresh" content="120">
<title>Visitor Monitor</title>
<style><?= $styles ?></style></head><body>
<div class="wrap">
  <header class="top">
    <h1>Visitor Monitor</h1>
    <div class="controls">
      <nav class="seg" aria-label="Time range">
        <?php foreach ($ranges as $k => $label): ?>
          <a href="<?= e($qs(['range' => (string) $k])) ?>"<?= (string) $k === $range ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <?php if (count($siteNames) > 1): ?>
      <form method="get">
        <input type="hidden" name="range" value="<?= e($range) ?>">
        <label class="note" for="site" style="position:absolute;left:-9999px">Website</label>
        <select id="site" name="site" onchange="this.form.submit()">
          <option value="">All websites</option>
          <?php foreach ($siteNames as $h): ?>
            <option value="<?= e($h) ?>"<?= $h === $site ? ' selected' : '' ?>><?= e($h) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="logout">
        <button type="submit">Sign out</button>
      </form>
    </div>
  </header>

  <div class="hero">
    <div>
      <div class="big"><?= number_format($uv) ?><span class="delta <?= $dClass ?>" title="Compared with the previous <?= $n === 1 ? 'day' : $n . ' days' ?>"><?= e($dText) ?></span></div>
      <div class="cap">Visitors <?= $n === 1 ? 'today' : 'in the last ' . $n . ' days' ?></div>
    </div>
    <div>
      <div class="live"><span class="dot" aria-hidden="true"></span><?= number_format($online) ?> on your site right now</div>
      <div class="note">Active in the last 5 minutes</div>
    </div>
  </div>

  <div class="facts" style="margin-bottom:24px">
    <div><b><?= number_format($pv) ?></b><span>Page views</span></div>
    <div><b><?= $ppv ?></b><span>Pages per visitor</span></div>
    <div><b><?= number_format((int) $all['uv']) ?></b><span>Visitors all time<?= $all['first_day'] ? ', since ' . e(date('M j, Y', strtotime($all['first_day']))) : '' ?></span></div>
  </div>

  <section class="block">
    <h2>Traffic by <?= $unit ?></h2>
    <div class="legend"><span><i style="background:var(--accent)"></i>Visitors</span><span><i style="background:var(--pv)"></i>Page views</span></div>
    <?= chart_svg($labels, $uvS, $pvS, $unit) ?>
  </section>

  <?php if ((int) $all['pv'] === 0): ?>
  <section class="block">
    <h2>Start counting visitors</h2>
    <p>Paste this line into every page of your website, just before the closing <code>&lt;/head&gt;</code> tag. Numbers appear here as soon as someone visits.</p>
    <pre><?= e($snippet) ?></pre>
  </section>
  <?php endif; ?>

  <div class="cols">
    <div><?php table('Top pages', $topPages, 'Page', 'Views', 'Visitors'); ?></div>
    <div><?php table('Where visitors come from', $topRefs, 'Source', 'Visitors', 'Views'); ?></div>
    <div><?php table('Browsers', $browsers, 'Browser', 'Visitors'); ?></div>
    <div><?php table('Operating systems', $systems, 'System', 'Visitors'); ?></div>
    <div><?php table('Devices', $devices, 'Device', 'Visitors'); ?></div>
    <div>
      <section class="block">
        <h2>Tracking code</h2>
        <pre><?= e($snippet) ?></pre>
        <p class="note">A visitor is one browser per day. Someone who returns on another day counts again in longer ranges. No cookies are used.</p>
      </section>
    </div>
  </div>
</div>
</body></html>
