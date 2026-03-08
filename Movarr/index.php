<?php
// Load .env for local dev (Docker uses env vars directly)
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\n\r\0\x0B\"'");
        if (!array_key_exists($key, $_ENV)) { putenv("$key=$val"); $_ENV[$key] = $val; }
    }
}

require_once __DIR__ . '/includes/settings.php';

$s           = load_settings();
$tautulliUrl = rtrim($s['tautulli']['url'], '/');
$apiKey      = $s['tautulli']['api_key'];
$apiBase     = $tautulliUrl . '/api/v2';
$days        = (int)$s['watched_days'];
$cutoff      = time() - ($days * 86400);

function tautulli_get(string $base, string $key, string $cmd, array $params = []): ?array
{
    $params = array_merge(['apikey' => $key, 'cmd' => $cmd], $params);
    $url = $base . '?' . http_build_query($params);
    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return ($data['response']['result'] === 'success') ? $data['response']['data'] : null;
}

$errors = [];

// ── TV Shows: history (episodes) in the last N days ──────────────────────────
$tvShows = [];
if ($apiKey) {
    $start = 0;
    $pageSize = 1000;
    while (true) {
        $history = tautulli_get($apiBase, $apiKey, 'get_history', [
            'media_type' => 'episode',
            'length'     => $pageSize,
            'start'      => $start,
            'after'      => date('Y-m-d', $cutoff),
        ]);

        if ($history === null) {
            $errors[] = 'Failed to fetch watch history from Tautulli. Check your URL and API key.';
            break;
        }

        $records = $history['data'] ?? [];
        foreach ($records as $r) {
            $show = $r['grandparent_title'] ?? 'Unknown Show';
            if (!isset($tvShows[$show])) {
                $tvShows[$show] = ['plays' => 0, 'users' => [], 'thumb' => $r['grandparent_thumb'] ?? '', 'last_watched' => 0];
            }
            $tvShows[$show]['plays']++;
            $tvShows[$show]['users'][$r['user'] ?? 'Unknown'] = true;
            $tvShows[$show]['last_watched'] = max($tvShows[$show]['last_watched'], $r['date'] ?? 0);
        }

        if (count($records) < $pageSize) break;
        $start += $pageSize;
    }

    uasort($tvShows, fn($a, $b) => $b['plays'] <=> $a['plays']);
} else {
    $errors[] = 'TAUTULLI_API_KEY is not set. <a href="config.php" style="color:var(--accent)">Go to Config</a>';
}

// ── Movies: recently added in the last N days ─────────────────────────────────
$movies = [];
if ($apiKey) {
    $data = tautulli_get($apiBase, $apiKey, 'get_recently_added', [
        'media_type' => 'movie',
        'count'      => 100,
    ]);

    if ($data === null) {
        $errors[] = 'Failed to fetch recently added movies from Tautulli.';
    } else {
        foreach ($data['recently_added'] ?? [] as $m) {
            $addedAt = (int)($m['added_at'] ?? 0);
            if ($addedAt < $cutoff) continue;
            $movies[] = ['title' => $m['title'] ?? 'Unknown', 'year' => $m['year'] ?? '', 'thumb' => $m['thumb'] ?? '', 'added_at' => $addedAt];
        }
        usort($movies, fn($a, $b) => $b['added_at'] <=> $a['added_at']);
    }
}

function time_ago(int $ts): string
{
    $diff = time() - $ts;
    if ($diff < 3600)  return round($diff / 60) . 'm ago';
    if ($diff < 86400) return round($diff / 3600) . 'h ago';
    return round($diff / 86400) . 'd ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Movarr — Last <?= $days ?> Days</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #141414;
    --surface:   #1f1f1f;
    --surface2:  #252525;
    --border:    #2e2e2e;
    --accent:    #e5a00d;
    --text:      #e0e0e0;
    --muted:     #888;
    --pill-bg:   #2a2a2a;
    --radius:    8px;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 15px;
    line-height: 1.5;
    min-height: 100vh;
  }

  header {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 0 2rem;
    display: flex;
    align-items: center;
    gap: 1.2rem;
    height: 54px;
  }
  header .logo { display: flex; align-items: center; gap: .6rem; text-decoration: none; }
  header .logo span { font-size: 1.1rem; font-weight: 700; color: var(--accent); }
  nav { display: flex; gap: .25rem; margin-left: 1.5rem; }
  nav a {
    color: var(--muted);
    text-decoration: none;
    padding: .35rem .8rem;
    border-radius: 6px;
    font-size: .875rem;
    font-weight: 500;
    transition: color .15s, background .15s;
  }
  nav a:hover { color: var(--text); background: var(--surface2); }
  nav a.active { color: var(--accent); background: #2a2200; }
  header .date-label { margin-left: auto; font-size: .8rem; color: var(--muted); }

  .container { max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem; }

  .errors {
    background: #3b1010;
    border: 1px solid #7a2020;
    border-radius: var(--radius);
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    color: #ff9090;
  }
  .errors p + p { margin-top: .4rem; }

  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
  @media (max-width: 780px) { .grid { grid-template-columns: 1fr; } }

  section h2 {
    font-size: 1rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--accent);
    margin-bottom: 1rem;
    padding-bottom: .5rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: .6rem;
  }
  section h2 .count-badge {
    background: var(--accent);
    color: #000;
    font-size: .7rem;
    font-weight: 700;
    border-radius: 999px;
    padding: 1px 7px;
    letter-spacing: 0;
    text-transform: none;
  }

  .card-list { display: flex; flex-direction: column; gap: .5rem; }
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .75rem 1rem;
    display: flex;
    align-items: center;
    gap: .9rem;
    transition: border-color .15s;
  }
  .card:hover { border-color: var(--accent); }
  .card-thumb { width: 42px; height: 42px; object-fit: cover; border-radius: 4px; flex-shrink: 0; background: var(--border); }
  .card-thumb-placeholder {
    width: 42px; height: 42px; border-radius: 4px; flex-shrink: 0;
    background: var(--border); display: flex; align-items: center;
    justify-content: center; color: var(--muted); font-size: 1.2rem;
  }
  .card-body { flex: 1; min-width: 0; }
  .card-title { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .card-meta { font-size: .78rem; color: var(--muted); margin-top: 1px; }
  .card-right { margin-left: auto; flex-shrink: 0; text-align: right; }

  .pill {
    display: inline-flex; align-items: center; gap: 4px;
    background: var(--pill-bg); border-radius: 999px; padding: 2px 9px;
    font-size: .78rem; font-weight: 600; color: var(--text);
  }
  .pill.plays { color: var(--accent); }
  .pill-sub { font-size: .7rem; color: var(--muted); margin-top: 3px; }

  .empty { color: var(--muted); font-size: .9rem; padding: 1rem 0; text-align: center; }

  footer { text-align: center; padding: 2rem; color: var(--muted); font-size: .78rem; }
</style>
</head>
<body>

<header>
  <a class="logo" href="index.php">
    <img src="images/movarr-logo.svg" width="32" height="32" alt="Movarr" style="border-radius:6px">
    <span>Movarr</span>
  </a>
  <nav>
    <a href="index.php" class="active">Dashboard</a>
    <a href="config.php">Config</a>
    <a href="logs.php">Logs</a>
  </nav>
  <span class="date-label">Last <?= $days ?> days &mdash; <?= date('M j, Y') ?></span>
</header>

<div class="container">

  <?php if ($errors): ?>
  <div class="errors">
    <?php foreach ($errors as $e): ?><p>&#9888; <?= $e ?></p><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="grid">

    <!-- ── TV SHOWS ── -->
    <section>
      <h2>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M21 3H3a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5v2h8v-2h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 14H3V5h18v12z"/></svg>
        TV Shows Watched
        <span class="count-badge"><?= count($tvShows) ?></span>
      </h2>

      <?php if (empty($tvShows)): ?>
        <p class="empty">No TV shows watched in the last <?= $days ?> days.</p>
      <?php else: ?>
      <div class="card-list">
        <?php foreach ($tvShows as $showTitle => $info): ?>
        <div class="card">
          <?php if ($info['thumb']): ?>
            <img class="card-thumb"
              src="<?= htmlspecialchars($tautulliUrl . '/api/v2?apikey=' . $apiKey . '&cmd=pms_image_proxy&img=' . urlencode($info['thumb']) . '&width=42&height=42') ?>"
              alt="" loading="lazy"
              onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
            <div class="card-thumb-placeholder" style="display:none">&#128250;</div>
          <?php else: ?>
            <div class="card-thumb-placeholder">&#128250;</div>
          <?php endif; ?>
          <div class="card-body">
            <div class="card-title"><?= htmlspecialchars($showTitle) ?></div>
            <div class="card-meta">
              Last watched <?= time_ago($info['last_watched']) ?>
              &middot; <?= count($info['users']) ?> <?= count($info['users']) === 1 ? 'viewer' : 'viewers' ?>
            </div>
          </div>
          <div class="card-right">
            <span class="pill plays">&#9654; <?= $info['plays'] ?></span>
            <div class="pill-sub"><?= $info['plays'] === 1 ? 'play' : 'plays' ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- ── MOVIES ── -->
    <section>
      <h2>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V4h-4z"/></svg>
        Movies Recently Added
        <span class="count-badge"><?= count($movies) ?></span>
      </h2>

      <?php if (empty($movies)): ?>
        <p class="empty">No movies added in the last <?= $days ?> days.</p>
      <?php else: ?>
      <div class="card-list">
        <?php foreach ($movies as $movie): ?>
        <div class="card">
          <?php if ($movie['thumb']): ?>
            <img class="card-thumb"
              src="<?= htmlspecialchars($tautulliUrl . '/api/v2?apikey=' . $apiKey . '&cmd=pms_image_proxy&img=' . urlencode($movie['thumb']) . '&width=42&height=42') ?>"
              alt="" loading="lazy"
              onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
            <div class="card-thumb-placeholder" style="display:none">&#127916;</div>
          <?php else: ?>
            <div class="card-thumb-placeholder">&#127916;</div>
          <?php endif; ?>
          <div class="card-body">
            <div class="card-title"><?= htmlspecialchars($movie['title']) ?><?= $movie['year'] ? ' <span style="color:var(--muted);font-weight:400">(' . (int)$movie['year'] . ')</span>' : '' ?></div>
            <div class="card-meta">Added <?= time_ago($movie['added_at']) ?></div>
          </div>
          <div class="card-right">
            <span class="pill">&#43; New</span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

  </div><!-- .grid -->
</div><!-- .container -->

<footer>
  Powered by <a href="<?= htmlspecialchars($tautulliUrl) ?>" style="color:var(--accent);text-decoration:none">Tautulli</a>
  &mdash; refreshes on page load
</footer>

</body>
</html>
