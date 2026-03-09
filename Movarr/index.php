<?php
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key); $val = trim($val, " \t\n\r\0\x0B\"'");
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

function tautulli_get(string $base, string $key, string $cmd, array $params = []): ?array {
    $params = array_merge(['apikey' => $key, 'cmd' => $cmd], $params);
    $url = $base . '?' . http_build_query($params);
    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return ($data['response']['result'] === 'success') ? $data['response']['data'] : null;
}

$errors = [];

// ── TV Shows ──────────────────────────────────────────────────────────────────
$tvShows = [];
if ($apiKey) {
    $start = 0; $pageSize = 1000;
    while (true) {
        $history = tautulli_get($apiBase, $apiKey, 'get_history', [
            'media_type' => 'episode', 'length' => $pageSize,
            'start' => $start, 'after' => date('Y-m-d', $cutoff),
        ]);
        if ($history === null) { $errors[] = 'Failed to fetch watch history from Tautulli.'; break; }
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

// ── Movies ────────────────────────────────────────────────────────────────────
$movies = [];
if ($apiKey) {
    $data = tautulli_get($apiBase, $apiKey, 'get_recently_added', ['media_type' => 'movie', 'count' => 100]);
    if ($data === null) {
        $errors[] = 'Failed to fetch recently added movies.';
    } else {
        foreach ($data['recently_added'] ?? [] as $m) {
            $addedAt = (int)($m['added_at'] ?? 0);
            if ($addedAt < $cutoff) continue;
            $movies[] = ['title' => $m['title'] ?? 'Unknown', 'year' => $m['year'] ?? '', 'thumb' => $m['thumb'] ?? '', 'added_at' => $addedAt];
        }
        usort($movies, fn($a, $b) => $b['added_at'] <=> $a['added_at']);
    }
}

function time_ago(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 3600)  return round($diff / 60) . 'm ago';
    if ($diff < 86400) return round($diff / 3600) . 'h ago';
    return round($diff / 86400) . 'd ago';
}

// Max plays for proportional progress bar
$maxPlays = 1;
foreach ($tvShows as $info) { $maxPlays = max($maxPlays, $info['plays']); }

$totalShows  = count($tvShows);
$totalMovies = count($movies);
$totalItems  = $totalShows + $totalMovies;

// ── Page-specific styles ──────────────────────────────────────────────────────
$extra_head = <<<'CSS'
<style>
/* ── Page toolbar (Sonarr-style: sits below topbar) ── */
.page-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .5rem;
  padding: .6rem 0 1rem;
  flex-wrap: wrap;
}
.page-toolbar-left  { display: flex; align-items: center; gap: .4rem; }
.page-toolbar-right { display: flex; align-items: center; gap: .25rem; margin-left: auto; }

/* Search */
.tb-search {
  position: relative;
}
.tb-search svg {
  position: absolute; left: .55rem; top: 50%; transform: translateY(-50%);
  color: var(--muted); pointer-events: none;
}
.tb-search input {
  padding-left: 1.9rem; width: 220px; font-size: .83rem;
  background: var(--surface); border-color: var(--border);
}
.tb-search input:focus { border-color: var(--accent); }

/* Toolbar buttons (Sonarr look: borderless, icon + label) */
.tb-sep {
  width: 1px; height: 22px; background: var(--border); margin: 0 .2rem; flex-shrink: 0;
}
.tb-btn {
  display: flex; align-items: center; gap: .35rem;
  background: none; border: none; color: var(--muted);
  cursor: pointer; font-size: .82rem; font-weight: 500;
  padding: .35rem .55rem; border-radius: var(--radius);
  transition: color .15s, background .15s;
  white-space: nowrap;
}
.tb-btn:hover  { color: var(--text); background: rgba(255,255,255,.06); }
.tb-btn.active { color: var(--accent); }
.tb-btn svg { flex-shrink: 0; }

/* Dropdown wrapper */
.tb-dropdown { position: relative; }
.tb-dropdown-menu {
  display: none;
  position: absolute;
  top: calc(100% + 4px);
  right: 0;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  min-width: 160px;
  z-index: 200;
  box-shadow: 0 6px 20px rgba(0,0,0,.4);
  overflow: hidden;
}
.tb-dropdown.open .tb-dropdown-menu { display: block; }
.tb-dropdown-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: .55rem .85rem;
  font-size: .83rem; color: var(--muted);
  cursor: pointer;
  transition: background .1s, color .1s;
}
.tb-dropdown-item:hover  { background: rgba(255,255,255,.05); color: var(--text); }
.tb-dropdown-item.active { color: var(--accent); }
.tb-dropdown-item .check { font-size: .75rem; }

/* ── Series count label ── */
.series-count {
  font-size: .82rem; color: var(--muted);
  padding: 0 .25rem;
}

/* ── Poster grid (Sonarr: ~182px columns, 5px gap) ── */
.sc-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(162px, 1fr));
  gap: 5px;
}

/* ── Poster card ── */
.sc-card {
  background: var(--surface);
  border-radius: 4px;
  overflow: hidden;
  transition: box-shadow 200ms ease-in, transform 200ms ease-in;
  cursor: default;
  position: relative;
}
.sc-card:hover {
  box-shadow: 0 0 14px rgba(0,0,0,.65);
  transform: translateY(-2px);
  z-index: 2;
}

/* Poster image area */
.sc-poster {
  position: relative;
  width: 100%;
  padding-top: 150%; /* 2:3 poster ratio, same as Sonarr */
  overflow: hidden;
  background-color: #1a1a2e;
}
.sc-poster img {
  position: absolute; inset: 0;
  width: 100%; height: 100%;
  object-fit: cover; display: block;
}
/* Placeholder shown when no image / image error */
.sc-poster-ph {
  position: absolute; inset: 0;
  display: none; /* shown via JS onerror */
  align-items: center; justify-content: center;
  font-size: 3.5rem;
}
.sc-poster-ph.ph-show  { background: linear-gradient(160deg, #1a2540 0%, #0c1422 100%); }
.sc-poster-ph.ph-movie { background: linear-gradient(160deg, #28183a 0%, #100c1e 100%); }
/* Fallback text title when image completely unavailable */
.sc-overlay-title {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  padding: 8px; font-size: 1rem; font-weight: 600;
  color: rgba(255,255,255,.85); text-align: center;
  word-break: break-word;
  pointer-events: none;
}

/* Status corner triangle (top-right, like Sonarr's ended/deleted indicator) */
.sc-tri {
  position: absolute; top: 0; right: 0; z-index: 1;
  width: 0; height: 0; border-style: solid;
  border-width: 0 22px 22px 0;
}
.sc-tri-new      { border-color: transparent var(--green)  transparent transparent; }
.sc-tri-active   { border-color: transparent var(--accent) transparent transparent; }

/* Hover controls bar (bottom-left of poster, like Sonarr) */
.sc-controls {
  position: absolute; bottom: 8px; left: 8px; z-index: 3;
  display: flex; gap: 2px; align-items: center;
  background: rgba(20,22,38,.88);
  border-radius: 4px; padding: 3px 5px;
  opacity: 0; transition: opacity 0;
}
.sc-card:hover .sc-controls { opacity: 1; transition: opacity 200ms linear 150ms; }
.sc-ctrl {
  background: none; border: none; color: rgba(255,255,255,.7);
  cursor: pointer; font-size: .72rem; padding: 2px 5px;
  border-radius: 2px; white-space: nowrap;
  transition: color .1s, background .1s;
}
.sc-ctrl:hover { color: #fff; background: rgba(255,255,255,.12); }

/* ── Status bar (below poster, above title — Sonarr's progress bar equivalent) ── */
.sc-bar {
  height: 5px;
  width: 100%;
  background: var(--surface2);
  position: relative;
  overflow: hidden;
}
.sc-bar-fill {
  height: 100%;
  transition: width .3s ease;
}
.sc-bar-fill-amber { background: var(--accent); }
.sc-bar-fill-green { background: var(--green); }
.sc-bar-fill-muted { background: #555; }

/* ── Card text area (below bar) ── */
.sc-card-body {
  padding: 5px 6px 7px;
  background: var(--surface);
}
.sc-title {
  font-size: .8rem; font-weight: 600;
  text-align: center;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  color: var(--text);
}
.sc-meta {
  font-size: .68rem; color: var(--muted);
  text-align: center;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  margin-top: 2px;
}

/* ── Overview / list view ── */
#ov-view { display: none; }
.ov-row {
  display: flex; align-items: center; gap: .85rem;
  padding: .65rem .85rem;
  border-bottom: 1px solid rgba(255,255,255,.04);
  background: var(--surface);
  transition: background .1s;
}
.ov-row:last-child  { border-bottom: none; }
.ov-row:hover { background: var(--surface2); }
.ov-thumb {
  width: 45px; height: 68px;
  object-fit: cover; border-radius: 3px;
  flex-shrink: 0; background: var(--surface2);
}
.ov-thumb-ph {
  width: 45px; height: 68px; border-radius: 3px;
  flex-shrink: 0; display: flex; align-items: center; justify-content: center;
  font-size: 1.4rem;
}
.ov-thumb-ph.ph-show  { background: linear-gradient(160deg,#1a2540,#0c1422); }
.ov-thumb-ph.ph-movie { background: linear-gradient(160deg,#28183a,#100c1e); }
.ov-info { flex: 1; min-width: 0; }
.ov-title { font-weight: 600; font-size: .9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ov-meta  { font-size: .75rem; color: var(--muted); margin-top: 2px; }
.ov-right { flex-shrink: 0; text-align: right; }

/* ── Legend ── */
.legend-box {
  margin-top: 1.5rem;
  padding: .75rem 1rem;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
}
.legend-hdr {
  font-size: .65rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .09em; color: var(--muted); margin-bottom: .55rem;
}
.legend-items {
  display: flex; flex-wrap: wrap; gap: .35rem 1.5rem;
}
.legend-item {
  display: flex; align-items: center; gap: .4rem;
  font-size: .75rem; color: var(--muted);
}
.legend-tri {
  width: 0; height: 0; border-style: solid;
  border-width: 0 12px 12px 0; flex-shrink: 0;
}

/* ── Empty / no-results ── */
.sc-empty {
  grid-column: 1/-1;
  text-align: center; padding: 4rem 2rem;
  color: var(--muted); font-size: .9rem;
}

@media (max-width: 700px) {
  .sc-grid { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 4px; }
  .tb-search input { width: 140px; }
}
</style>
CSS;

layout_start("Last {$days} Days", 'dashboard', $extra_head);
?>

<?php if ($errors): ?>
<div class="notice notice-error" style="margin-bottom:1rem">
  <?php foreach ($errors as $e): ?><div>⚠ <?= $e ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Toolbar ── -->
<div class="page-toolbar">
  <div class="page-toolbar-left">

    <!-- Search -->
    <div class="tb-search">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
        <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
      </svg>
      <input type="text" id="search-input" placeholder="Search…" autocomplete="off">
    </div>

    <span class="series-count"><strong id="visible-count"><?= $totalItems ?></strong> items</span>
  </div>

  <div class="page-toolbar-right">

    <!-- View: Posters -->
    <button class="tb-btn active" id="btn-poster" title="Poster view" onclick="setView('poster',this)">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
        <path d="M3 3h7v9H3zm11 0h7v9h-7zm-11 11h7v7H3zm11 0h7v7h-7z"/>
      </svg>
      Posters
    </button>
    <!-- View: Overview/List -->
    <button class="tb-btn" id="btn-overview" title="Overview view" onclick="setView('overview',this)">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
        <path d="M3 5h2V3H3v2zm0 4h2V7H3v2zm0 4h2v-2H3v2zm4-8h14V3H7v2zm0 4h14V7H7v2zm0 4h14v-2H7v2z"/>
      </svg>
      Overview
    </button>

    <div class="tb-sep"></div>

    <!-- Sort dropdown -->
    <div class="tb-dropdown" id="sort-dd">
      <button class="tb-btn" onclick="toggleDropdown('sort-dd')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
          <path d="M3 18h6v-2H3v2zM3 6v2h18V6H3zm0 7h12v-2H3v2z"/>
        </svg>
        Sort: <span id="sort-label">Plays</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
      </button>
      <div class="tb-dropdown-menu">
        <div class="tb-dropdown-item active" data-sort="plays"   onclick="setSort(this,'plays','Plays')">Plays <span class="check">✓</span></div>
        <div class="tb-dropdown-item"        data-sort="title"   onclick="setSort(this,'title','Title')">Title</div>
        <div class="tb-dropdown-item"        data-sort="watched" onclick="setSort(this,'watched','Last Watched')">Last Watched</div>
        <div class="tb-dropdown-item"        data-sort="added"   onclick="setSort(this,'added','Date Added')">Date Added</div>
      </div>
    </div>

    <!-- Filter dropdown -->
    <div class="tb-dropdown" id="filter-dd">
      <button class="tb-btn" onclick="toggleDropdown('filter-dd')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
          <path d="M4.25 5.61C6.27 8.2 10 13 10 13v6c0 .55.45 1 1 1h2c.55 0 1-.45 1-1v-6s3.72-4.8 5.74-7.39A.998.998 0 0 0 18.95 4H5.04a1 1 0 0 0-.79 1.61z"/>
        </svg>
        <span id="filter-label">All Series</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
      </button>
      <div class="tb-dropdown-menu">
        <div class="tb-dropdown-item active" data-filter="all"   onclick="setFilter(this,'all','All Series')">All Series <span class="check">✓</span></div>
        <div class="tb-dropdown-item"        data-filter="show"  onclick="setFilter(this,'show','TV Shows')">TV Shows</div>
        <div class="tb-dropdown-item"        data-filter="movie" onclick="setFilter(this,'movie','Movies')">Movies</div>
      </div>
    </div>

  </div>
</div>

<!-- ── POSTER GRID ── -->
<div id="poster-view" class="sc-grid">

<?php if ($totalItems === 0): ?>
  <div class="sc-empty">No media found for the last <?= $days ?> days.</div>
<?php endif; ?>

<?php foreach ($tvShows as $showTitle => $info):
  $pct = $maxPlays > 0 ? round(($info['plays'] / $maxPlays) * 100) : 0;
?>
<div class="sc-card"
  data-title="<?= htmlspecialchars(strtolower($showTitle)) ?>"
  data-type="show"
  data-plays="<?= $info['plays'] ?>"
  data-watched="<?= $info['last_watched'] ?>"
  data-added="0">

  <div class="sc-poster">
    <?php if ($info['rating_key']): ?>
      <img
        src="<?= htmlspecialchars($tautulliUrl.'/api/v2?apikey='.$apiKey.'&cmd=pms_image_proxy&rating_key='.urlencode($info['rating_key']).'&width=300&height=450&fallback=poster') ?>"
        alt="" loading="lazy"
        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="sc-poster-ph ph-show">📺</div>
    <?php else: ?>
      <div class="sc-poster-ph ph-show" style="display:flex">📺
        <div class="sc-overlay-title"><?= htmlspecialchars($showTitle) ?></div>
      </div>
    <?php endif; ?>

    <!-- "Active" triangle — show is being watched -->
    <div class="sc-tri sc-tri-active" title="Recently watched"></div>
  </div>

  <!-- Progress bar: proportional to play count vs max -->
  <div class="sc-bar" title="<?= $info['plays'] ?> play<?= $info['plays'] !== 1 ? 's' : '' ?>">
    <div class="sc-bar-fill sc-bar-fill-amber" style="width:<?= $pct ?>%"></div>
  </div>

  <div class="sc-card-body">
    <div class="sc-title" title="<?= htmlspecialchars($showTitle) ?>"><?= htmlspecialchars($showTitle) ?></div>
    <div class="sc-meta">
      ▶ <?= $info['plays'] ?> &nbsp;·&nbsp;
      <?= count($info['users']) ?> viewer<?= count($info['users']) !== 1 ? 's' : '' ?>
      &nbsp;·&nbsp; <?= time_ago($info['last_watched']) ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php foreach ($movies as $movie): ?>
<div class="sc-card"
  data-title="<?= htmlspecialchars(strtolower($movie['title'])) ?>"
  data-type="movie"
  data-plays="0"
  data-watched="0"
  data-added="<?= $movie['added_at'] ?>">

  <div class="sc-poster">
    <?php if ($movie['thumb']): ?>
      <img
        src="<?= htmlspecialchars($tautulliUrl.'/api/v2?apikey='.$apiKey.'&cmd=pms_image_proxy&img='.urlencode($movie['thumb']).'&width=300&height=450') ?>"
        alt="" loading="lazy"
        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="sc-poster-ph ph-movie">🎬</div>
    <?php else: ?>
      <div class="sc-poster-ph ph-movie" style="display:flex">🎬
        <div class="sc-overlay-title"><?= htmlspecialchars($movie['title']) ?><?= $movie['year'] ? ' (' . (int)$movie['year'] . ')' : '' ?></div>
      </div>
    <?php endif; ?>

    <!-- "New" triangle -->
    <div class="sc-tri sc-tri-new" title="Recently added"></div>
  </div>

  <!-- Full green bar for new movies -->
  <div class="sc-bar">
    <div class="sc-bar-fill sc-bar-fill-green" style="width:100%"></div>
  </div>

  <div class="sc-card-body">
    <div class="sc-title" title="<?= htmlspecialchars($movie['title']) ?>">
      <?= htmlspecialchars($movie['title']) ?><?= $movie['year'] ? ' (' . (int)$movie['year'] . ')' : '' ?>
    </div>
    <div class="sc-meta">Added <?= time_ago($movie['added_at']) ?></div>
  </div>
</div>
<?php endforeach; ?>

</div><!-- #poster-view -->

<!-- ── OVERVIEW / LIST VIEW ── -->
<div id="ov-view">
  <div class="card">

  <?php foreach ($tvShows as $showTitle => $info): ?>
  <div class="ov-row"
    data-title="<?= htmlspecialchars(strtolower($showTitle)) ?>"
    data-type="show"
    data-plays="<?= $info['plays'] ?>"
    data-watched="<?= $info['last_watched'] ?>"
    data-added="0">

    <?php if ($info['rating_key']): ?>
      <img class="ov-thumb"
        src="<?= htmlspecialchars($tautulliUrl.'/api/v2?apikey='.$apiKey.'&cmd=pms_image_proxy&rating_key='.urlencode($info['rating_key']).'&width=90&height=135&fallback=poster') ?>"
        alt="" loading="lazy"
        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="ov-thumb-ph ph-show" style="display:none">📺</div>
    <?php else: ?>
      <div class="ov-thumb-ph ph-show">📺</div>
    <?php endif; ?>

    <div class="ov-info">
      <div class="ov-title"><?= htmlspecialchars($showTitle) ?></div>
      <div class="ov-meta">
        Last watched <?= time_ago($info['last_watched']) ?>
        &nbsp;·&nbsp;
        <?= count($info['users']) ?> viewer<?= count($info['users']) !== 1 ? 's' : '' ?>
      </div>
    </div>
    <div class="ov-right">
      <div style="font-weight:700;color:var(--accent);font-size:.88rem">▶ <?= $info['plays'] ?></div>
      <div style="font-size:.7rem;color:var(--muted)"><?= $info['plays'] === 1 ? 'play' : 'plays' ?></div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php foreach ($movies as $movie): ?>
  <div class="ov-row"
    data-title="<?= htmlspecialchars(strtolower($movie['title'])) ?>"
    data-type="movie"
    data-plays="0"
    data-watched="0"
    data-added="<?= $movie['added_at'] ?>">

    <?php if ($movie['thumb']): ?>
      <img class="ov-thumb"
        src="<?= htmlspecialchars($tautulliUrl.'/api/v2?apikey='.$apiKey.'&cmd=pms_image_proxy&img='.urlencode($movie['thumb']).'&width=90&height=135') ?>"
        alt="" loading="lazy"
        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="ov-thumb-ph ph-movie" style="display:none">🎬</div>
    <?php else: ?>
      <div class="ov-thumb-ph ph-movie">🎬</div>
    <?php endif; ?>

    <div class="ov-info">
      <div class="ov-title">
        <?= htmlspecialchars($movie['title']) ?>
        <?php if ($movie['year']): ?><span style="color:var(--muted);font-weight:400">(<?= (int)$movie['year'] ?>)</span><?php endif; ?>
      </div>
      <div class="ov-meta">Added <?= time_ago($movie['added_at']) ?></div>
    </div>
    <div class="ov-right">
      <span class="badge badge-green">New</span>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if ($totalItems === 0): ?>
    <div class="empty">No media found for the last <?= $days ?> days.</div>
  <?php endif; ?>
  </div>
</div><!-- #ov-view -->

<!-- ── Legend ── -->
<div class="legend-box">
  <div class="legend-hdr">Legend</div>
  <div class="legend-items">
    <div class="legend-item">
      <div class="legend-tri" style="border-color:transparent var(--accent) transparent transparent"></div>
      <span>Orange triangle — recently watched TV show</span>
    </div>
    <div class="legend-item">
      <div class="legend-tri" style="border-color:transparent var(--green) transparent transparent"></div>
      <span>Green triangle — recently added movie</span>
    </div>
    <div class="legend-item">
      <span style="display:inline-block;width:28px;height:5px;background:var(--accent);border-radius:2px"></span>
      <span>Amber bar — play activity (proportional to most-watched)</span>
    </div>
    <div class="legend-item">
      <span style="display:inline-block;width:28px;height:5px;background:var(--green);border-radius:2px"></span>
      <span>Green bar — newly added to library</span>
    </div>
    <div class="legend-item">
      <span style="font-size:1rem">📺</span>
      <span>TV show watched in last <strong style="color:var(--text)"><?= $days ?></strong> days</span>
    </div>
    <div class="legend-item">
      <span style="font-size:1rem">🎬</span>
      <span>Movie added in last <strong style="color:var(--text)"><?= $days ?></strong> days</span>
    </div>
  </div>
</div>

<script>
(function() {
  var currentSort   = 'plays';
  var currentFilter = 'all';
  var sortAsc       = false;

  var searchInput  = document.getElementById('search-input');
  var visCountEl   = document.getElementById('visible-count');
  var posterView   = document.getElementById('poster-view');
  var ovView       = document.getElementById('ov-view');
  var currentView  = 'poster';

  function cards()   { return [...posterView.querySelectorAll('.sc-card')]; }
  function ovRows()  { return [...ovView.querySelectorAll('.ov-row')]; }

  function isVisible(el) {
    var q = (searchInput.value || '').toLowerCase().trim();
    if (q && !(el.dataset.title || '').includes(q)) return false;
    if (currentFilter !== 'all' && el.dataset.type !== currentFilter) return false;
    return true;
  }

  function cmp(a, b) {
    var av, bv;
    if (currentSort === 'title') {
      av = a.dataset.title || ''; bv = b.dataset.title || '';
      return sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
    }
    av = parseInt(a.dataset[currentSort] || '0');
    bv = parseInt(b.dataset[currentSort] || '0');
    return sortAsc ? av - bv : bv - av;
  }

  function applyAll() {
    var cs = cards(), ovs = ovRows();

    // Sort & reorder cards
    cs.sort(cmp).forEach(function(c) { posterView.appendChild(c); });

    // Sort & reorder overview rows (inside the .card div)
    var ovCard = ovView.querySelector('.card');
    if (ovCard) ovs.sort(cmp).forEach(function(r) { ovCard.appendChild(r); });

    // Show/hide
    var n = 0;
    cs.forEach(function(c)  { var v = isVisible(c); c.style.display = v ? '' : 'none'; if(v) n++; });
    ovs.forEach(function(r) { r.style.display = isVisible(r) ? '' : 'none'; });

    if (visCountEl) visCountEl.textContent = n;
  }

  searchInput.addEventListener('input', applyAll);

  window.setView = function(v, btn) {
    currentView = v;
    posterView.style.display = v === 'poster'   ? '' : 'none';
    ovView.style.display     = v === 'overview' ? 'block' : 'none';
    document.querySelectorAll('.tb-btn[id^="btn-"]').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
  };

  window.toggleDropdown = function(id) {
    var dd = document.getElementById(id);
    var wasOpen = dd.classList.contains('open');
    // Close all
    document.querySelectorAll('.tb-dropdown').forEach(function(d) { d.classList.remove('open'); });
    if (!wasOpen) dd.classList.add('open');
  };

  // Close dropdowns when clicking outside
  document.addEventListener('click', function(e) {
    if (!e.target.closest('.tb-dropdown')) {
      document.querySelectorAll('.tb-dropdown').forEach(function(d) { d.classList.remove('open'); });
    }
  });

  window.setSort = function(el, key, label) {
    if (currentSort === key) { sortAsc = !sortAsc; }
    else { currentSort = key; sortAsc = key === 'title'; }
    document.querySelectorAll('#sort-dd .tb-dropdown-item').forEach(function(i) {
      i.classList.remove('active');
      i.querySelector('.check') && (i.querySelector('.check').textContent = '');
    });
    el.classList.add('active');
    if (!el.querySelector('.check')) { var s = document.createElement('span'); s.className = 'check'; el.appendChild(s); }
    el.querySelector('.check').textContent = '✓';
    document.getElementById('sort-label').textContent = label;
    document.getElementById('sort-dd').classList.remove('open');
    applyAll();
  };

  window.setFilter = function(el, key, label) {
    currentFilter = key;
    document.querySelectorAll('#filter-dd .tb-dropdown-item').forEach(function(i) {
      i.classList.remove('active');
      i.querySelector('.check') && (i.querySelector('.check').textContent = '');
    });
    el.classList.add('active');
    if (!el.querySelector('.check')) { var s = document.createElement('span'); s.className = 'check'; el.appendChild(s); }
    el.querySelector('.check').textContent = '✓';
    document.getElementById('filter-label').textContent = label;
    document.getElementById('filter-dd').classList.remove('open');
    applyAll();
  };

  applyAll();
})();
</script>

<?php layout_end(); ?>
