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
require_once __DIR__ . '/includes/layout.php';

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
                $tvShows[$show] = ['plays' => 0, 'users' => [], 'rating_key' => $r['grandparent_rating_key'] ?? '', 'last_watched' => 0];
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
<?php
layout_start("Last {$days} Days", 'dashboard');
?>

<?php if ($errors): ?>
<div class="notice notice-error" style="margin-bottom:1rem">
  <?php foreach ($errors as $e): ?><div>&#9888; <?= $e ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">

  <!-- TV Shows -->
  <div>
    <div class="section-label">
      TV Shows Watched
      <span class="badge badge-amber" style="margin-left:.4rem"><?= count($tvShows) ?></span>
    </div>
    <div class="card">
      <?php if (empty($tvShows)): ?>
        <div class="empty">No TV shows watched in the last <?= $days ?> days.</div>
      <?php else: ?>
      <div class="media-grid">
        <?php foreach ($tvShows as $showTitle => $info): ?>
        <div class="media-row">
          <?php if ($info['rating_key']): ?>
            <img class="media-thumb"
              src="<?= htmlspecialchars($tautulliUrl . '/api/v2?apikey=' . $apiKey . '&cmd=pms_image_proxy&rating_key=' . urlencode($info['rating_key']) . '&width=80&height=80&fallback=poster') ?>"
              alt="" loading="lazy"
              onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
            <div class="media-thumb-ph" style="display:none">&#128250;</div>
          <?php else: ?>
            <div class="media-thumb-ph">&#128250;</div>
          <?php endif; ?>
          <div class="media-info">
            <div class="media-title"><?= htmlspecialchars($showTitle) ?></div>
            <div class="media-meta">
              <?= time_ago($info['last_watched']) ?>
              &middot; <?= count($info['users']) ?> <?= count($info['users']) === 1 ? 'viewer' : 'viewers' ?>
            </div>
          </div>
          <div class="media-right">
            <div class="media-plays">&#9654; <?= $info['plays'] ?></div>
            <div style="font-size:.7rem;color:var(--muted)"><?= $info['plays'] === 1 ? 'play' : 'plays' ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Movies -->
  <div>
    <div class="section-label">
      Movies Recently Added
      <span class="badge badge-amber" style="margin-left:.4rem"><?= count($movies) ?></span>
    </div>
    <div class="card">
      <?php if (empty($movies)): ?>
        <div class="empty">No movies added in the last <?= $days ?> days.</div>
      <?php else: ?>
      <div class="media-grid">
        <?php foreach ($movies as $movie): ?>
        <div class="media-row">
          <?php if ($movie['thumb']): ?>
            <img class="media-thumb"
              src="<?= htmlspecialchars($tautulliUrl . '/api/v2?apikey=' . $apiKey . '&cmd=pms_image_proxy&img=' . urlencode($movie['thumb']) . '&width=80&height=80') ?>"
              alt="" loading="lazy"
              onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
            <div class="media-thumb-ph" style="display:none">&#127916;</div>
          <?php else: ?>
            <div class="media-thumb-ph">&#127916;</div>
          <?php endif; ?>
          <div class="media-info">
            <div class="media-title">
              <?= htmlspecialchars($movie['title']) ?>
              <?php if ($movie['year']): ?>
                <span style="color:var(--muted);font-weight:400">(<?= (int)$movie['year'] ?>)</span>
              <?php endif; ?>
            </div>
            <div class="media-meta">Added <?= time_ago($movie['added_at']) ?></div>
          </div>
          <div class="media-right">
            <span class="badge badge-green">New</span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php layout_end(); ?>
