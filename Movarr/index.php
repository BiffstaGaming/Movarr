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

$total_shows  = count($tvShows);
$total_movies = count($movies);
$total_items  = $total_shows + $total_movies;

// ── Extra CSS ──────────────────────────────────────────────────────────────────
$extra_head = <<<'CSS'
<style>
/* ── Toolbar ── */
.content-toolbar {
  display: flex;
  align-items: center;
  gap: .65rem;
  flex-wrap: wrap;
  margin-bottom: .6rem;
}
.search-wrap {
  position: relative;
  flex: 1;
  min-width: 160px;
  max-width: 300px;
}
.search-wrap svg {
  position: absolute;
  left: .6rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--muted);
  pointer-events: none;
}
.search-wrap input { padding-left: 2rem; font-size: .83rem; }

.view-toggle {
  display: flex;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
  flex-shrink: 0;
}
.view-btn {
  background: var(--surface2);
  border: none;
  color: var(--muted);
  cursor: pointer;
  padding: .38rem .55rem;
  line-height: 0;
  transition: background .15s, color .15s;
}
.view-btn:hover  { color: var(--text); background: var(--surface); }
.view-btn.active { color: var(--accent); background: var(--accent-dim); }

.sort-wrap {
  display: flex;
  align-items: center;
  gap: .35rem;
  flex-shrink: 0;
}
.sort-wrap label {
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--muted);
  white-space: nowrap;
}
.sort-wrap select { width: auto; font-size: .8rem; padding: .35rem .55rem; }
.sort-dir-btn {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  color: var(--muted);
  cursor: pointer;
  padding: .35rem .5rem;
  font-size: .85rem;
  line-height: 1;
  transition: color .15s, border-color .15s;
}
.sort-dir-btn:hover { color: var(--accent); border-color: var(--accent); }

/* ── Filter pills ── */
.filter-bar {
  display: flex;
  gap: .35rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}
.filter-pill {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 20px;
  color: var(--muted);
  cursor: pointer;
  font-size: .75rem;
  font-weight: 600;
  padding: .28rem .75rem;
  transition: background .15s, color .15s, border-color .15s;
  white-space: nowrap;
}
.filter-pill:hover { color: var(--text); border-color: var(--accent); }
.filter-pill.active { background: var(--accent-dim); border-color: var(--accent); color: var(--accent); }
.filter-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(255,255,255,.08);
  border-radius: 10px;
  font-size: .68rem;
  font-weight: 700;
  min-width: 18px;
  padding: 0 .3rem;
  margin-left: .25rem;
}
.filter-pill.active .filter-count { background: rgba(229,160,13,.2); }

/* ── Poster card grid ── */
.poster-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(148px, 1fr));
  gap: .85rem;
}
.poster-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 6px;
  overflow: hidden;
  transition: border-color .2s, transform .15s, box-shadow .2s;
  cursor: default;
}
.poster-card:hover {
  border-color: var(--accent);
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0,0,0,.4);
}
.poster-art {
  position: relative;
  width: 100%;
  padding-top: 148%; /* ~2:3 poster ratio */
  overflow: hidden;
  background: var(--surface2);
}
.poster-art img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.poster-art-ph {
  position: absolute;
  inset: 0;
  display: none;
  align-items: center;
  justify-content: center;
  font-size: 3rem;
}
.poster-art-ph.art-show { background: linear-gradient(160deg, #1a2a4a 0%, #0a1020 100%); }
.poster-art-ph.art-movie { background: linear-gradient(160deg, #2a1a3a 0%, #100a1a 100%); }

/* Gradient overlay at bottom of poster */
.poster-overlay {
  position: absolute;
  bottom: 0; left: 0; right: 0;
  background: linear-gradient(transparent, rgba(0,0,0,.9) 55%);
  padding: 2.5rem .55rem .5rem;
}
.poster-overlay-title {
  font-size: .8rem;
  font-weight: 700;
  color: #fff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.3;
}
.poster-overlay-meta {
  font-size: .67rem;
  color: rgba(255,255,255,.65);
  margin-top: .1rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Top-right corner badge */
.poster-corner {
  position: absolute;
  top: .4rem;
  right: .4rem;
}

/* ── List view ── */
#list-view { display: none; }
.list-row {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: .6rem .85rem;
  background: var(--surface);
  border-bottom: 1px solid rgba(255,255,255,.04);
  transition: background .1s;
}
.list-row:last-child { border-bottom: none; }
.list-row:hover { background: var(--surface2); }
.list-thumb {
  width: 42px;
  height: 42px;
  object-fit: cover;
  border-radius: 4px;
  flex-shrink: 0;
  background: var(--surface2);
}
.list-thumb-ph {
  width: 42px;
  height: 42px;
  border-radius: 4px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.2rem;
}
.list-thumb-ph.art-show  { background: linear-gradient(160deg, #1a2a4a, #0a1020); }
.list-thumb-ph.art-movie { background: linear-gradient(160deg, #2a1a3a, #100a1a); }
.list-info { flex: 1; min-width: 0; }
.list-title { font-weight: 600; font-size: .875rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.list-meta  { font-size: .72rem; color: var(--muted); margin-top: 1px; }
.list-right { margin-left: auto; flex-shrink: 0; text-align: right; }

/* ── Legend ── */
.legend-box {
  margin-top: 2rem;
  padding: .85rem 1rem;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
}
.legend-title {
  font-size: .68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--muted);
  margin-bottom: .6rem;
}
.legend-grid {
  display: flex;
  flex-wrap: wrap;
  gap: .4rem 1.5rem;
}
.legend-item {
  display: flex;
  align-items: center;
  gap: .45rem;
  font-size: .76rem;
  color: var(--muted);
}
.legend-swatch {
  width: 12px; height: 12px;
  border-radius: 2px;
  flex-shrink: 0;
}

.no-results {
  grid-column: 1 / -1;
  text-align: center;
  padding: 3rem;
  color: var(--muted);
  font-size: .875rem;
}

@media (max-width: 700px) {
  .poster-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: .6rem; }
  .content-toolbar { gap: .4rem; }
  .search-wrap { max-width: 100%; }
}
</style>
CSS;
?>
<?php
layout_start("Last {$days} Days", 'dashboard', $extra_head);
?>

<?php if ($errors): ?>
<div class="notice notice-error" style="margin-bottom:1rem">
  <?php foreach ($errors as $e): ?><div>&#9888; <?= $e ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Toolbar ── -->
<div class="content-toolbar">
  <div class="search-wrap">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
      <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
    </svg>
    <input type="text" id="search-input" placeholder="Search titles…" autocomplete="off">
  </div>

  <!-- View toggle -->
  <div class="view-toggle">
    <button class="view-btn active" id="view-poster" title="Poster view">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
        <path d="M3 3h7v9H3zm11 0h7v9h-7zm-11 11h7v7H3zm11 0h7v7h-7z"/>
      </svg>
    </button>
    <button class="view-btn" id="view-list" title="List view">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
        <path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/>
      </svg>
    </button>
  </div>

  <!-- Sort -->
  <div class="sort-wrap">
    <label>Sort</label>
    <select id="sort-select">
      <option value="plays">Plays</option>
      <option value="title">Title</option>
      <option value="watched">Last Watched</option>
      <option value="added">Date Added</option>
    </select>
    <button class="sort-dir-btn" id="sort-dir" title="Toggle sort direction">↓</button>
  </div>

  <!-- Count -->
  <span style="font-size:.78rem;color:var(--muted);margin-left:auto">
    <strong id="visible-count"><?= $total_items ?></strong> of <?= $total_items ?>
  </span>
</div>

<!-- ── Filter pills ── -->
<div class="filter-bar">
  <button class="filter-pill active" data-filter="all">All <span class="filter-count"><?= $total_items ?></span></button>
  <button class="filter-pill" data-filter="show">TV Shows <span class="filter-count"><?= $total_shows ?></span></button>
  <button class="filter-pill" data-filter="movie">Movies <span class="filter-count"><?= $total_movies ?></span></button>
</div>

<!-- ── POSTER GRID ── -->
<div id="poster-view" class="poster-grid">

  <?php if ($total_items === 0): ?>
  <div class="no-results">No media found for the last <?= $days ?> days.</div>
  <?php endif; ?>

  <?php foreach ($tvShows as $showTitle => $info): ?>
  <div class="poster-card"
    data-title="<?= htmlspecialchars(strtolower($showTitle)) ?>"
    data-type="show"
    data-plays="<?= $info['plays'] ?>"
    data-watched="<?= $info['last_watched'] ?>"
    data-added="0">

    <div class="poster-art">
      <?php if ($info['rating_key']): ?>
        <img
          src="<?= htmlspecialchars($tautulliUrl . '/api/v2?apikey=' . $apiKey . '&cmd=pms_image_proxy&rating_key=' . urlencode($info['rating_key']) . '&width=300&height=450&fallback=poster') ?>"
          alt=""
          loading="lazy"
          onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="poster-art-ph art-show">📺</div>
      <?php else: ?>
        <div class="poster-art-ph art-show" style="display:flex">📺</div>
      <?php endif; ?>

      <!-- Gradient overlay -->
      <div class="poster-overlay">
        <div class="poster-overlay-title"><?= htmlspecialchars($showTitle) ?></div>
        <div class="poster-overlay-meta">
          <?= time_ago($info['last_watched']) ?>
          &middot; <?= count($info['users']) ?> <?= count($info['users']) === 1 ? 'viewer' : 'viewers' ?>
        </div>
      </div>

      <!-- Play count badge -->
      <div class="poster-corner">
        <span class="badge badge-amber" style="font-size:.65rem">
          ▶ <?= $info['plays'] ?>
        </span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php foreach ($movies as $movie): ?>
  <div class="poster-card"
    data-title="<?= htmlspecialchars(strtolower($movie['title'])) ?>"
    data-type="movie"
    data-plays="0"
    data-watched="0"
    data-added="<?= $movie['added_at'] ?>">

    <div class="poster-art">
      <?php if ($movie['thumb']): ?>
        <img
          src="<?= htmlspecialchars($tautulliUrl . '/api/v2?apikey=' . $apiKey . '&cmd=pms_image_proxy&img=' . urlencode($movie['thumb']) . '&width=300&height=450') ?>"
          alt=""
          loading="lazy"
          onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="poster-art-ph art-movie">🎬</div>
      <?php else: ?>
        <div class="poster-art-ph art-movie" style="display:flex">🎬</div>
      <?php endif; ?>

      <!-- Gradient overlay -->
      <div class="poster-overlay">
        <div class="poster-overlay-title">
          <?= htmlspecialchars($movie['title']) ?><?= $movie['year'] ? ' (' . (int)$movie['year'] . ')' : '' ?>
        </div>
        <div class="poster-overlay-meta">Added <?= time_ago($movie['added_at']) ?></div>
      </div>

      <!-- New badge -->
      <div class="poster-corner">
        <span class="badge badge-green" style="font-size:.65rem">New</span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

</div><!-- #poster-view -->

<!-- ── LIST VIEW ── -->
<div id="list-view">
  <div class="card">
    <?php foreach ($tvShows as $showTitle => $info): ?>
    <div class="list-row"
      data-title="<?= htmlspecialchars(strtolower($showTitle)) ?>"
      data-type="show"
      data-plays="<?= $info['plays'] ?>"
      data-watched="<?= $info['last_watched'] ?>"
      data-added="0">

      <?php if ($info['rating_key']): ?>
        <img class="list-thumb"
          src="<?= htmlspecialchars($tautulliUrl . '/api/v2?apikey=' . $apiKey . '&cmd=pms_image_proxy&rating_key=' . urlencode($info['rating_key']) . '&width=84&height=84&fallback=poster') ?>"
          alt="" loading="lazy"
          onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="list-thumb-ph art-show" style="display:none">📺</div>
      <?php else: ?>
        <div class="list-thumb-ph art-show">📺</div>
      <?php endif; ?>

      <div class="list-info">
        <div class="list-title"><?= htmlspecialchars($showTitle) ?></div>
        <div class="list-meta">Last watched <?= time_ago($info['last_watched']) ?> &middot; <?= count($info['users']) ?> viewer<?= count($info['users']) !== 1 ? 's' : '' ?></div>
      </div>
      <div class="list-right">
        <div style="font-weight:700;font-size:.82rem;color:var(--accent)">▶ <?= $info['plays'] ?></div>
        <div style="font-size:.68rem;color:var(--muted)"><?= $info['plays'] === 1 ? 'play' : 'plays' ?></div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php foreach ($movies as $movie): ?>
    <div class="list-row"
      data-title="<?= htmlspecialchars(strtolower($movie['title'])) ?>"
      data-type="movie"
      data-plays="0"
      data-watched="0"
      data-added="<?= $movie['added_at'] ?>">

      <?php if ($movie['thumb']): ?>
        <img class="list-thumb"
          src="<?= htmlspecialchars($tautulliUrl . '/api/v2?apikey=' . $apiKey . '&cmd=pms_image_proxy&img=' . urlencode($movie['thumb']) . '&width=84&height=84') ?>"
          alt="" loading="lazy"
          onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="list-thumb-ph art-movie" style="display:none">🎬</div>
      <?php else: ?>
        <div class="list-thumb-ph art-movie">🎬</div>
      <?php endif; ?>

      <div class="list-info">
        <div class="list-title">
          <?= htmlspecialchars($movie['title']) ?>
          <?php if ($movie['year']): ?><span style="color:var(--muted);font-weight:400">(<?= (int)$movie['year'] ?>)</span><?php endif; ?>
        </div>
        <div class="list-meta">Added <?= time_ago($movie['added_at']) ?></div>
      </div>
      <div class="list-right">
        <span class="badge badge-green">New</span>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if ($total_items === 0): ?>
    <div class="empty">No media found for the last <?= $days ?> days.</div>
    <?php endif; ?>
  </div>
</div><!-- #list-view -->

<!-- ── Legend ── -->
<div class="legend-box">
  <div class="legend-title">Legend</div>
  <div class="legend-grid">
    <div class="legend-item">
      <span style="font-size:1rem">📺</span>
      <span>TV show — watched in last <strong style="color:var(--text)"><?= $days ?></strong> days</span>
    </div>
    <div class="legend-item">
      <span style="font-size:1rem">🎬</span>
      <span>Movie — added in last <strong style="color:var(--text)"><?= $days ?></strong> days</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-amber" style="font-size:.65rem">▶ N</span>
      <span>Number of episode plays in the window</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-green" style="font-size:.65rem">New</span>
      <span>Recently added to the Plex library</span>
    </div>
    <div class="legend-item">
      <span class="legend-swatch" style="background:linear-gradient(135deg,#1a2a4a,#0a1020)"></span>
      <span>Blue gradient — TV show placeholder</span>
    </div>
    <div class="legend-item">
      <span class="legend-swatch" style="background:linear-gradient(135deg,#2a1a3a,#100a1a)"></span>
      <span>Purple gradient — Movie placeholder</span>
    </div>
  </div>
</div>

<script>
(function () {
  let currentFilter = 'all';
  let currentSort   = 'plays';
  let sortAsc       = false; // default: descending (most plays first)
  let currentView   = 'poster';

  const searchInput  = document.getElementById('search-input');
  const sortSelect   = document.getElementById('sort-select');
  const sortDirBtn   = document.getElementById('sort-dir');
  const visibleCount = document.getElementById('visible-count');
  const posterView   = document.getElementById('poster-view');
  const listView     = document.getElementById('list-view');

  function getCards()    { return [...posterView.querySelectorAll('.poster-card')]; }
  function getListRows() { return [...listView.querySelectorAll('.list-row')]; }

  function isVisible(el) {
    const query = (searchInput.value || '').toLowerCase().trim();
    const title = (el.dataset.title || '').toLowerCase();
    const type  = el.dataset.type || '';

    if (query && !title.includes(query)) return false;
    if (currentFilter !== 'all' && type !== currentFilter) return false;
    return true;
  }

  function compareItems(a, b) {
    let av, bv;
    if (currentSort === 'title') {
      av = a.dataset.title || ''; bv = b.dataset.title || '';
      return sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
    }
    if (currentSort === 'plays') {
      av = parseInt(a.dataset.plays || '0');
      bv = parseInt(b.dataset.plays || '0');
    } else if (currentSort === 'watched') {
      av = parseInt(a.dataset.watched || '0');
      bv = parseInt(b.dataset.watched || '0');
    } else if (currentSort === 'added') {
      av = parseInt(a.dataset.added || '0');
      bv = parseInt(b.dataset.added || '0');
    } else {
      return 0;
    }
    return sortAsc ? av - bv : bv - av;
  }

  function applyAll() {
    const cards = getCards();
    const rows  = getListRows();

    // Build visible set from cards (source of truth)
    const visIds  = new Set();
    const visTitles = new Set();
    cards.forEach(c => { if (isVisible(c)) visTitles.add(c.dataset.title); });

    // Sort cards
    const sorted = [...cards].sort(compareItems);
    sorted.forEach(c => posterView.appendChild(c));

    // Show/hide cards
    let count = 0;
    cards.forEach(c => {
      const show = isVisible(c);
      c.style.display = show ? '' : 'none';
      if (show) count++;
    });

    // Sort & show/hide list rows
    const sortedRows = [...rows].sort(compareItems);
    const listCard = listView.querySelector('.card');
    if (listCard) sortedRows.forEach(r => listCard.appendChild(r));
    rows.forEach(r => {
      r.style.display = isVisible(r) ? '' : 'none';
    });

    if (visibleCount) visibleCount.textContent = count;
  }

  searchInput.addEventListener('input', applyAll);

  sortSelect.addEventListener('change', function () {
    currentSort = this.value;
    applyAll();
  });

  sortDirBtn.addEventListener('click', function () {
    sortAsc = !sortAsc;
    this.textContent = sortAsc ? '↑' : '↓';
    applyAll();
  });

  document.querySelectorAll('.filter-pill').forEach(btn => {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      currentFilter = this.dataset.filter;
      applyAll();
    });
  });

  document.getElementById('view-poster').addEventListener('click', function () {
    currentView = 'poster';
    posterView.style.display = '';
    listView.style.display   = 'none';
    this.classList.add('active');
    document.getElementById('view-list').classList.remove('active');
  });

  document.getElementById('view-list').addEventListener('click', function () {
    currentView = 'list';
    posterView.style.display = 'none';
    listView.style.display   = '';
    this.classList.add('active');
    document.getElementById('view-poster').classList.remove('active');
  });

  // Initial apply
  applyAll();
})();
</script>

<?php layout_end(); ?>
