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
require_once __DIR__ . '/includes/db.php';

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
                $tvShows[$show] = ['plays'=>0,'users'=>[],'rating_key'=>$r['grandparent_rating_key']??'','last_watched'=>0];
            }
            $tvShows[$show]['plays']++;
            $tvShows[$show]['users'][$r['user'] ?? 'Unknown'] = true;
            $tvShows[$show]['last_watched'] = max($tvShows[$show]['last_watched'], $r['date'] ?? 0);
        }
        if (count($records) < $pageSize) break;
        $start += $pageSize;
    }
    uasort($tvShows, fn($a,$b) => $b['plays'] <=> $a['plays']);
} else {
    $errors[] = 'TAUTULLI_API_KEY is not set. <a href="config.php" style="color:var(--accent)">Go to Config</a>';
}

// ── Movies ────────────────────────────────────────────────────────────────────
$movies = [];
if ($apiKey) {
    $data = tautulli_get($apiBase, $apiKey, 'get_recently_added', ['media_type'=>'movie','count'=>100]);
    if ($data === null) { $errors[] = 'Failed to fetch recently added movies.'; }
    else {
        foreach ($data['recently_added'] ?? [] as $m) {
            $addedAt = (int)($m['added_at'] ?? 0);
            if ($addedAt < $cutoff) continue;
            $movies[] = ['title'=>$m['title']??'Unknown','year'=>$m['year']??'','thumb'=>$m['thumb']??'','added_at'=>$addedAt];
        }
        usort($movies, fn($a,$b) => $b['added_at'] <=> $a['added_at']);
    }
}

// ── Cross-reference with tracked_media ───────────────────────────────────────
$tracked_map = [];
try {
    $tdb = db_connect();
    foreach (db_all_tracked($tdb) as $tr) {
        $key = strtolower($tr['title'] ?? '');
        if ($key !== '') $tracked_map[$key] = $tr;
    }
} catch (Exception $e) {}

function get_tracked(array &$map, string $title): ?array {
    return $map[strtolower($title)] ?? null;
}
function fmt_storage(?array $tr): string {
    if (!$tr) return '—';
    return $tr['current_location'] === 'fast' ? 'Fast' : 'Slow';
}
function fmt_movedate(?array $tr): string {
    if (!$tr) return '—';
    if ($tr['relocate_after'] === null) return 'Pinned';
    if ($tr['relocate_after'] < time()) return 'Expired';
    return date('Y-m-d', $tr['relocate_after']);
}
function time_ago(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 3600)  return round($diff/60).'m ago';
    if ($diff < 86400) return round($diff/3600).'h ago';
    return round($diff/86400).'d ago';
}

$totalShows  = count($tvShows);
$totalMovies = count($movies);
$totalItems  = $totalShows + $totalMovies;

// ── Extra CSS ─────────────────────────────────────────────────────────────────
$extra_head = <<<'CSS'
<style>
/* ── Sonarr-style toolbar ── */
.sc-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: stretch;
  height: 60px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  /* Bleed to content edges */
  margin: -1.5rem -1.5rem 1.25rem;
  padding: 0 .75rem;
}
.sc-toolbar-left, .sc-toolbar-right {
  display: flex;
  align-items: stretch;
  gap: 0;
}

/* Toolbar button: icon on top, label below (exact Sonarr style) */
.sc-tb-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  padding: 0 12px;
  min-width: 52px;
  background: none;
  border: none;
  color: var(--muted);
  cursor: pointer;
  font-size: .6rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  transition: color .15s, background .15s;
  position: relative;
  white-space: nowrap;
  text-decoration: none;
}
.sc-tb-btn:hover  { color: var(--text); background: rgba(255,255,255,.05); }
.sc-tb-btn.active { color: var(--accent); }
.sc-tb-btn svg    { flex-shrink: 0; }

/* Small blue indicator dot (used on filter when active) */
.sc-tb-indicator {
  position: absolute;
  top: 9px; right: 8px;
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--blue);
  display: none;
}
.sc-tb-btn.has-filter .sc-tb-indicator { display: block; }

.sc-tb-sep {
  width: 1px;
  background: var(--border);
  margin: 10px 2px;
  flex-shrink: 0;
}

/* ── Dropdown menus (right-aligned, dark, Sonarr style) ── */
.sc-menu-wrap { position: relative; }
.sc-menu {
  display: none;
  position: absolute;
  top: 100%;
  right: 0;
  min-width: 160px;
  background: var(--surface);
  border: 1px solid var(--border);
  box-shadow: 0 4px 20px rgba(0,0,0,.55);
  z-index: 500;
  flex-direction: column;
}
.sc-menu.open { display: flex; }
.sc-menu-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 20px;
  color: var(--muted);
  font-size: .875rem;
  cursor: pointer;
  white-space: nowrap;
  transition: background .1s, color .1s;
  gap: 1.5rem;
  border: none; background-color: transparent; width: 100%; text-align: left;
}
.sc-menu-item:hover { background: rgba(255,255,255,.06); color: var(--text); }
.sc-menu-item .mi-check { visibility: hidden; flex-shrink: 0; }
.sc-menu-item.selected .mi-check { visibility: visible; color: var(--accent); }
/* Sort direction icon (replaces check) */
.sc-menu-item .mi-sortdir { visibility: hidden; flex-shrink: 0; color: var(--muted); }
.sc-menu-item.selected .mi-sortdir { visibility: visible; }
.sc-menu-sep { height: 1px; background: var(--border); margin: 4px 0; }
/* Filter checkboxes */
.sc-filter-row {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 20px; cursor: pointer;
  font-size: .875rem; color: var(--muted);
  transition: background .1s;
  white-space: nowrap;
}
.sc-filter-row:hover { background: rgba(255,255,255,.06); color: var(--text); }
.sc-filter-row input[type="checkbox"] {
  accent-color: var(--accent); width: 14px; height: 14px;
  cursor: pointer; flex-shrink: 0;
}

/* ── Options Modal (column visibility) ── */
.sc-modal-overlay {
  display: none;
  position: fixed; inset: 0; z-index: 900;
  background: rgba(0,0,0,.6);
  align-items: center; justify-content: center;
}
.sc-modal-overlay.open { display: flex; }
.sc-modal {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 4px;
  width: 400px;
  max-width: 92vw;
  box-shadow: 0 8px 32px rgba(0,0,0,.6);
}
.sc-modal-hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--border);
  font-size: .95rem; font-weight: 600; color: var(--text);
}
.sc-modal-close {
  background: none; border: none; color: var(--muted);
  cursor: pointer; font-size: 1.1rem; padding: 2px 6px;
  border-radius: 3px; transition: color .15s;
}
.sc-modal-close:hover { color: var(--text); }
.sc-modal-body { padding: .75rem 1.25rem 1rem; }
.sc-modal-section-lbl {
  font-size: .68rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .09em; color: var(--muted); margin: .75rem 0 .4rem;
}
.sc-modal-col {
  display: flex; align-items: center; justify-content: space-between;
  padding: .5rem 0;
  border-bottom: 1px solid rgba(255,255,255,.04);
}
.sc-modal-col:last-child { border-bottom: none; }
.sc-modal-col label {
  display: flex; align-items: center; gap: .65rem;
  font-size: .875rem; color: var(--text); cursor: pointer;
}
.sc-modal-col input[type="checkbox"] {
  accent-color: var(--accent); width: 15px; height: 15px; cursor: pointer;
}
.sc-modal-ftr {
  padding: .75rem 1.25rem;
  border-top: 1px solid var(--border);
  display: flex; justify-content: flex-end;
}

/* ── Item count ── */
.sc-item-count {
  display: flex; align-items: center;
  padding: 0 12px;
  font-size: .78rem; color: var(--muted); white-space: nowrap;
}

/* ── Poster grid (NO bars — triangles only) ── */
.sc-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(162px,1fr));
  gap: 5px;
}
.sc-card {
  background: var(--surface); border-radius: 4px; overflow: hidden;
  transition: box-shadow 200ms ease-in, transform 200ms ease-in;
  cursor: default; position: relative;
}
.sc-card:hover {
  box-shadow: 0 0 14px rgba(0,0,0,.65);
  transform: translateY(-2px); z-index: 2;
}
.sc-poster {
  position: relative; width: 100%; padding-top: 150%;
  overflow: hidden; background-color: #1a1a2e;
}
.sc-poster img {
  position: absolute; inset: 0; width: 100%; height: 100%;
  object-fit: cover; display: block;
}
.sc-poster-ph {
  position: absolute; inset: 0; display: none;
  align-items: center; justify-content: center; font-size: 3.5rem;
}
.sc-poster-ph.ph-show  { background: linear-gradient(160deg,#1a2540 0%,#0c1422 100%); }
.sc-poster-ph.ph-movie { background: linear-gradient(160deg,#28183a 0%,#100c1e 100%); }
.sc-overlay-title {
  position: absolute; inset: 0; display: flex;
  align-items: center; justify-content: center;
  padding: 8px; font-size: 1rem; font-weight: 600;
  color: rgba(255,255,255,.85); text-align: center;
  word-break: break-word; pointer-events: none;
}
/* Status triangle (top-right, Sonarr border trick) */
.sc-tri {
  position: absolute; top: 0; right: 0; z-index: 1;
  width: 0; height: 0; border-style: solid; border-width: 0 22px 22px 0;
}
.sc-tri-watched { border-color: transparent var(--accent)  transparent transparent; }
.sc-tri-new     { border-color: transparent var(--green)   transparent transparent; }

/* Card body */
.sc-card-body { padding: 5px 6px 7px; background: var(--surface); }
.sc-title {
  font-size: .8rem; font-weight: 600; text-align: center;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text);
}
.sc-meta {
  font-size: .68rem; color: var(--muted); text-align: center;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px;
}

/* ── Overview rows ── */
#ov-view { display: none; }
.ov-row {
  display: flex; align-items: center; gap: .85rem;
  padding: .65rem .85rem;
  border-bottom: 1px solid rgba(255,255,255,.04);
  background: var(--surface); transition: background .1s;
}
.ov-row:last-child { border-bottom: none; }
.ov-row:hover { background: var(--surface2); }
.ov-thumb { width:45px;height:68px;object-fit:cover;border-radius:3px;flex-shrink:0;background:var(--surface2); }
.ov-thumb-ph { width:45px;height:68px;border-radius:3px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.4rem; }
.ov-thumb-ph.ph-show  { background:linear-gradient(160deg,#1a2540,#0c1422); }
.ov-thumb-ph.ph-movie { background:linear-gradient(160deg,#28183a,#100c1e); }
.ov-info { flex:1;min-width:0; }
.ov-title { font-weight:600;font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.ov-meta  { font-size:.75rem;color:var(--muted);margin-top:2px; }
.ov-right { flex-shrink:0;text-align:right; }

/* ── Table view ── */
#tbl-view { display: none; }
#tbl-view table { font-size: .83rem; }
#tbl-view thead th { cursor: pointer; user-select: none; }
#tbl-view thead th:hover { color: var(--text); }
#tbl-view thead th .th-sort { visibility: hidden; margin-left: 4px; }
#tbl-view thead th.sort-active .th-sort { visibility: visible; }
.storage-fast { color: var(--green); font-weight: 700; }
.storage-slow { color: var(--muted); font-weight: 700; }
/* Column visibility via dynamic <style> */

/* ── Legend ── */
.legend-box {
  margin-top: 1.5rem; padding: .75rem 1rem;
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
}
.legend-hdr {
  font-size: .65rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .09em; color: var(--muted); margin-bottom: .55rem;
}
.legend-items { display: flex; flex-wrap: wrap; gap: .35rem 1.5rem; }
.legend-item  { display: flex; align-items: center; gap: .4rem; font-size: .75rem; color: var(--muted); }
.legend-tri   { width:0;height:0;border-style:solid;border-width:0 12px 12px 0;flex-shrink:0; }

.sc-empty { grid-column:1/-1;text-align:center;padding:4rem 2rem;color:var(--muted);font-size:.9rem; }

@media(max-width:700px) {
  .sc-toolbar { margin: -1rem -1rem 1rem; }
  .sc-grid { grid-template-columns: repeat(auto-fill, minmax(110px,1fr)); gap:4px; }
  .sc-tb-btn { padding: 0 8px; min-width: 40px; font-size:.55rem; }
}
</style>
<style id="col-vis-style"></style>
CSS;

layout_start("Last {$days} Days", 'dashboard', $extra_head);
?>

<?php if ($errors): ?>
<div class="notice notice-error" style="margin-bottom:1rem">
  <?php foreach ($errors as $e): ?><div>⚠ <?= $e ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Sonarr-style Toolbar ── -->
<div class="sc-toolbar">

  <!-- LEFT: item count -->
  <div class="sc-toolbar-left">
    <div class="sc-item-count">
      <span id="visible-count"><?= $totalItems ?></span>&nbsp;items
    </div>
  </div>

  <!-- RIGHT: Options | sep | View | Sort | Filter -->
  <div class="sc-toolbar-right">

    <!-- Options (column visibility) -->
    <button class="sc-tb-btn" id="btn-options" onclick="openOptions()" title="Options">
      <svg id="options-icon" width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
        <!-- table icon (default, changes with view) -->
        <path d="M20 2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-9 11H5v-2h6v2zm0-4H5V7h6v2zm8 4h-6v-2h6v2zm0-4h-6V7h6v2z"/>
      </svg>
      Options
    </button>

    <div class="sc-tb-sep"></div>

    <!-- View (eye icon) -->
    <div class="sc-menu-wrap">
      <button class="sc-tb-btn" onclick="toggleMenu('view-menu')" title="View">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
          <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
        </svg>
        View
      </button>
      <div class="sc-menu" id="view-menu">
        <button class="sc-menu-item selected" data-view="poster" onclick="setView('poster',this)">
          Posters <span class="mi-check">✓</span>
        </button>
        <button class="sc-menu-item" data-view="overview" onclick="setView('overview',this)">
          Overview <span class="mi-check">✓</span>
        </button>
        <button class="sc-menu-item" data-view="table" onclick="setView('table',this)">
          Table <span class="mi-check">✓</span>
        </button>
      </div>
    </div>

    <!-- Sort (bars icon) -->
    <div class="sc-menu-wrap">
      <button class="sc-tb-btn" onclick="toggleMenu('sort-menu')" title="Sort">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
          <path d="M3 18h6v-2H3v2zM3 6v2h18V6H3zm0 7h12v-2H3v2z"/>
        </svg>
        Sort
      </button>
      <div class="sc-menu" id="sort-menu">
        <button class="sc-menu-item selected" data-sort="plays"   onclick="setSort('plays',this)">
          Plays
          <span class="mi-sortdir" id="sd-plays">↓</span>
        </button>
        <button class="sc-menu-item" data-sort="title"   onclick="setSort('title',this)">
          Title
          <span class="mi-sortdir" id="sd-title">↑</span>
        </button>
        <button class="sc-menu-item" data-sort="watched" onclick="setSort('watched',this)">
          Last Watched
          <span class="mi-sortdir" id="sd-watched">↓</span>
        </button>
        <button class="sc-menu-item" data-sort="added"   onclick="setSort('added',this)">
          Date Added
          <span class="mi-sortdir" id="sd-added">↓</span>
        </button>
      </div>
    </div>

    <!-- Filter (funnel icon) -->
    <div class="sc-menu-wrap">
      <button class="sc-tb-btn" id="btn-filter" onclick="toggleMenu('filter-menu')" title="Filter">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
          <path d="M4.25 5.61C6.27 8.2 10 13 10 13v6c0 .55.45 1 1 1h2c.55 0 1-.45 1-1v-6s3.72-4.8 5.74-7.39A.998.998 0 0 0 18.95 4H5.04a1 1 0 0 0-.79 1.61z"/>
        </svg>
        Filter
        <span class="sc-tb-indicator"></span>
      </button>
      <div class="sc-menu" id="filter-menu" style="min-width:180px">
        <label class="sc-filter-row">
          <input type="checkbox" id="f-shows" checked onchange="applyFilter()"> TV Shows
        </label>
        <label class="sc-filter-row">
          <input type="checkbox" id="f-movies" checked onchange="applyFilter()"> Movies
        </label>
      </div>
    </div>

  </div>
</div>

<!-- ── Column Options Modal ── -->
<div class="sc-modal-overlay" id="options-modal" onclick="if(event.target===this)closeOptions()">
  <div class="sc-modal">
    <div class="sc-modal-hdr">
      Table Options
      <button class="sc-modal-close" onclick="closeOptions()">✕</button>
    </div>
    <div class="sc-modal-body">
      <div class="sc-modal-section-lbl">Columns</div>
      <div class="sc-modal-col">
        <label>
          <input type="checkbox" id="col-storage"  checked onchange="saveCols()"> Current Storage
        </label>
      </div>
      <div class="sc-modal-col">
        <label>
          <input type="checkbox" id="col-movedate" checked onchange="saveCols()"> Expected Move Date
        </label>
      </div>
      <div class="sc-modal-col">
        <label>
          <input type="checkbox" id="col-plays"    checked onchange="saveCols()"> Total Plays
        </label>
      </div>
      <div class="sc-modal-col">
        <label>
          <input type="checkbox" id="col-viewers"  checked onchange="saveCols()"> Total Viewers
        </label>
      </div>
      <div class="sc-modal-col">
        <label>
          <input type="checkbox" id="col-date"     checked onchange="saveCols()"> Date
        </label>
      </div>
    </div>
    <div class="sc-modal-ftr">
      <button class="btn" onclick="closeOptions()">Close</button>
    </div>
  </div>
</div>

<!-- ── POSTER GRID ── -->
<div id="poster-view" class="sc-grid">
<?php if ($totalItems === 0): ?>
  <div class="sc-empty">No media found for the last <?= $days ?> days.</div>
<?php endif; ?>

<?php foreach ($tvShows as $showTitle => $info): ?>
<div class="sc-card"
  data-title="<?= htmlspecialchars(strtolower($showTitle)) ?>"
  data-type="show"
  data-plays="<?= $info['plays'] ?>"
  data-watched="<?= $info['last_watched'] ?>"
  data-added="0">

  <div class="sc-poster">
    <?php if ($info['rating_key']): ?>
      <img src="<?= htmlspecialchars($tautulliUrl.'/api/v2?apikey='.$apiKey.'&cmd=pms_image_proxy&rating_key='.urlencode($info['rating_key']).'&width=300&height=450&fallback=poster') ?>"
           alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="sc-poster-ph ph-show">📺</div>
    <?php else: ?>
      <div class="sc-poster-ph ph-show" style="display:flex">📺
        <div class="sc-overlay-title"><?= htmlspecialchars($showTitle) ?></div>
      </div>
    <?php endif; ?>
    <div class="sc-tri sc-tri-watched" title="Recently watched"></div>
  </div>
  <div class="sc-card-body">
    <div class="sc-title" title="<?= htmlspecialchars($showTitle) ?>"><?= htmlspecialchars($showTitle) ?></div>
    <div class="sc-meta">▶ <?= $info['plays'] ?> &nbsp;·&nbsp; <?= time_ago($info['last_watched']) ?></div>
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
      <img src="<?= htmlspecialchars($tautulliUrl.'/api/v2?apikey='.$apiKey.'&cmd=pms_image_proxy&img='.urlencode($movie['thumb']).'&width=300&height=450') ?>"
           alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="sc-poster-ph ph-movie">🎬</div>
    <?php else: ?>
      <div class="sc-poster-ph ph-movie" style="display:flex">🎬
        <div class="sc-overlay-title"><?= htmlspecialchars($movie['title']) ?><?= $movie['year'] ? ' ('.(int)$movie['year'].')' : '' ?></div>
      </div>
    <?php endif; ?>
    <div class="sc-tri sc-tri-new" title="Recently added"></div>
  </div>
  <div class="sc-card-body">
    <div class="sc-title" title="<?= htmlspecialchars($movie['title']) ?>">
      <?= htmlspecialchars($movie['title']) ?><?= $movie['year'] ? ' ('.(int)$movie['year'].')' : '' ?>
    </div>
    <div class="sc-meta">Added <?= time_ago($movie['added_at']) ?></div>
  </div>
</div>
<?php endforeach; ?>
</div><!-- #poster-view -->

<!-- ── OVERVIEW ── -->
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
      <img class="ov-thumb" src="<?= htmlspecialchars($tautulliUrl.'/api/v2?apikey='.$apiKey.'&cmd=pms_image_proxy&rating_key='.urlencode($info['rating_key']).'&width=90&height=135&fallback=poster') ?>"
           alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="ov-thumb-ph ph-show" style="display:none">📺</div>
    <?php else: ?>
      <div class="ov-thumb-ph ph-show">📺</div>
    <?php endif; ?>
    <div class="ov-info">
      <div class="ov-title"><?= htmlspecialchars($showTitle) ?></div>
      <div class="ov-meta">Last watched <?= time_ago($info['last_watched']) ?> &nbsp;·&nbsp; <?= count($info['users']) ?> viewer<?= count($info['users'])!==1?'s':'' ?></div>
    </div>
    <div class="ov-right">
      <div style="font-weight:700;color:var(--accent);font-size:.88rem">▶ <?= $info['plays'] ?></div>
      <div style="font-size:.7rem;color:var(--muted)"><?= $info['plays']===1?'play':'plays' ?></div>
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
      <img class="ov-thumb" src="<?= htmlspecialchars($tautulliUrl.'/api/v2?apikey='.$apiKey.'&cmd=pms_image_proxy&img='.urlencode($movie['thumb']).'&width=90&height=135') ?>"
           alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="ov-thumb-ph ph-movie" style="display:none">🎬</div>
    <?php else: ?>
      <div class="ov-thumb-ph ph-movie">🎬</div>
    <?php endif; ?>
    <div class="ov-info">
      <div class="ov-title"><?= htmlspecialchars($movie['title']) ?><?= $movie['year'] ? ' <span style="color:var(--muted);font-weight:400">('.(int)$movie['year'].')</span>' : '' ?></div>
      <div class="ov-meta">Added <?= time_ago($movie['added_at']) ?></div>
    </div>
    <div class="ov-right"><span class="badge badge-green">New</span></div>
  </div>
  <?php endforeach; ?>
  <?php if ($totalItems===0): ?><div class="empty">No media found for the last <?= $days ?> days.</div><?php endif; ?>
  </div>
</div><!-- #ov-view -->

<!-- ── TABLE VIEW ── -->
<div id="tbl-view">
  <?php if ($totalItems === 0): ?>
    <div class="empty">No media found for the last <?= $days ?> days.</div>
  <?php else: ?>
  <div class="card">
    <table id="main-table">
      <thead>
        <tr>
          <th onclick="setSort('title',null)"   class="sort-col" data-col="title">Title <span class="th-sort">↑</span></th>
          <th onclick="setSort('storage',null)" class="sort-col col-storage"  data-col="storage">Storage <span class="th-sort">↑</span></th>
          <th onclick="setSort('movedate',null)"class="sort-col col-movedate" data-col="movedate">Move Date <span class="th-sort">↑</span></th>
          <th onclick="setSort('plays',null)"   class="sort-col col-plays"    data-col="plays">Plays <span class="th-sort">↑</span></th>
          <th onclick="setSort('viewers',null)" class="sort-col col-viewers"  data-col="viewers">Viewers <span class="th-sort">↑</span></th>
          <th onclick="setSort('date',null)"    class="sort-col col-date"     data-col="date">Date <span class="th-sort">↑</span></th>
        </tr>
      </thead>
      <tbody>

      <?php foreach ($tvShows as $showTitle => $info):
        $tr = get_tracked($tracked_map, $showTitle);
        $storage   = fmt_storage($tr);
        $movedate  = fmt_movedate($tr);
        $ts_move   = $tr ? (int)($tr['relocate_after'] ?? 0) : 0;
        $ts_loc    = ($tr && $tr['current_location']==='fast') ? 1 : 0;
      ?>
      <tr data-title="<?= htmlspecialchars(strtolower($showTitle)) ?>"
          data-type="show"
          data-plays="<?= $info['plays'] ?>"
          data-watched="<?= $info['last_watched'] ?>"
          data-added="0"
          data-storage="<?= $ts_loc ?>"
          data-movedate="<?= $ts_move ?>">
        <td>
          <div style="display:flex;align-items:center;gap:.6rem">
            <?php if ($info['rating_key']): ?>
              <img style="width:28px;height:42px;object-fit:cover;border-radius:2px;flex-shrink:0"
                src="<?= htmlspecialchars($tautulliUrl.'/api/v2?apikey='.$apiKey.'&cmd=pms_image_proxy&rating_key='.urlencode($info['rating_key']).'&width=56&height=84&fallback=poster') ?>"
                alt="" loading="lazy" onerror="this.style.display='none'">
            <?php endif; ?>
            <div>
              <div style="font-weight:600"><?= htmlspecialchars($showTitle) ?></div>
              <div style="font-size:.72rem;color:var(--muted)">TV Show</div>
            </div>
          </div>
        </td>
        <td class="col-storage">
          <?php if ($tr): ?>
            <span class="<?= $tr['current_location']==='fast' ? 'storage-fast' : 'storage-slow' ?>">
              <?= $tr['current_location']==='fast' ? '→ Fast' : '← Slow' ?>
            </span>
          <?php else: ?>
            <span style="color:var(--muted)">—</span>
          <?php endif; ?>
        </td>
        <td class="col-movedate" style="font-size:.82rem;color:var(--muted)"><?= htmlspecialchars($movedate) ?></td>
        <td class="col-plays"   style="font-weight:700;color:var(--accent)"><?= $info['plays'] ?></td>
        <td class="col-viewers" style="color:var(--muted)"><?= count($info['users']) ?></td>
        <td class="col-date"    style="font-size:.82rem;color:var(--muted)"><?= time_ago($info['last_watched']) ?></td>
      </tr>
      <?php endforeach; ?>

      <?php foreach ($movies as $movie):
        $tr = get_tracked($tracked_map, $movie['title']);
        $storage  = fmt_storage($tr);
        $movedate = fmt_movedate($tr);
        $ts_move  = $tr ? (int)($tr['relocate_after'] ?? 0) : 0;
        $ts_loc   = ($tr && $tr['current_location']==='fast') ? 1 : 0;
      ?>
      <tr data-title="<?= htmlspecialchars(strtolower($movie['title'])) ?>"
          data-type="movie"
          data-plays="0"
          data-watched="0"
          data-added="<?= $movie['added_at'] ?>"
          data-storage="<?= $ts_loc ?>"
          data-movedate="<?= $ts_move ?>">
        <td>
          <div style="display:flex;align-items:center;gap:.6rem">
            <?php if ($movie['thumb']): ?>
              <img style="width:28px;height:42px;object-fit:cover;border-radius:2px;flex-shrink:0"
                src="<?= htmlspecialchars($tautulliUrl.'/api/v2?apikey='.$apiKey.'&cmd=pms_image_proxy&img='.urlencode($movie['thumb']).'&width=56&height=84') ?>"
                alt="" loading="lazy" onerror="this.style.display='none'">
            <?php endif; ?>
            <div>
              <div style="font-weight:600"><?= htmlspecialchars($movie['title']) ?><?= $movie['year'] ? ' <span style="color:var(--muted);font-weight:400">('.(int)$movie['year'].')</span>' : '' ?></div>
              <div style="font-size:.72rem;color:var(--muted)">Movie</div>
            </div>
          </div>
        </td>
        <td class="col-storage">
          <?php if ($tr): ?>
            <span class="<?= $tr['current_location']==='fast' ? 'storage-fast' : 'storage-slow' ?>">
              <?= $tr['current_location']==='fast' ? '→ Fast' : '← Slow' ?>
            </span>
          <?php else: ?>
            <span style="color:var(--muted)">—</span>
          <?php endif; ?>
        </td>
        <td class="col-movedate" style="font-size:.82rem;color:var(--muted)"><?= htmlspecialchars($movedate) ?></td>
        <td class="col-plays"   style="color:var(--muted)">—</td>
        <td class="col-viewers" style="color:var(--muted)">—</td>
        <td class="col-date"    style="font-size:.82rem;color:var(--muted)">Added <?= time_ago($movie['added_at']) ?></td>
      </tr>
      <?php endforeach; ?>

      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div><!-- #tbl-view -->

<!-- ── Legend ── -->
<div class="legend-box">
  <div class="legend-hdr">Legend</div>
  <div class="legend-items">
    <div class="legend-item">
      <div class="legend-tri" style="border-color:transparent var(--accent) transparent transparent"></div>
      <span>Orange — recently watched TV show</span>
    </div>
    <div class="legend-item">
      <div class="legend-tri" style="border-color:transparent var(--green) transparent transparent"></div>
      <span>Green — recently added movie</span>
    </div>
    <div class="legend-item">
      <span class="storage-fast">→ Fast</span>
      <span>Currently on fast storage (SSD)</span>
    </div>
    <div class="legend-item">
      <span class="storage-slow">← Slow</span>
      <span>Currently on slow storage (HDD/RAID)</span>
    </div>
    <div class="legend-item">
      <span style="font-size:1rem">📺</span>
      <span>TV show — watched in last <strong style="color:var(--text)"><?= $days ?></strong> days</span>
    </div>
    <div class="legend-item">
      <span style="font-size:1rem">🎬</span>
      <span>Movie — added in last <strong style="color:var(--text)"><?= $days ?></strong> days</span>
    </div>
  </div>
</div>

<script>
(function() {
  // ── State ──
  var currentView   = 'poster';
  var currentSort   = 'plays';
  var sortDirs      = { plays:false, title:true, watched:false, added:false, storage:true, movedate:true, viewers:false };
  var cols          = { storage:true, movedate:true, plays:true, viewers:true, date:true };
  var filters       = { shows:true, movies:true };

  var posterView = document.getElementById('poster-view');
  var ovView     = document.getElementById('ov-view');
  var tblView    = document.getElementById('tbl-view');
  var countEl    = document.getElementById('visible-count');
  var colStyle   = document.getElementById('col-vis-style');

  // ── Persist / restore ──
  function save() {
    try {
      localStorage.setItem('mv_view',    currentView);
      localStorage.setItem('mv_sort',    currentSort);
      localStorage.setItem('mv_sdirs',   JSON.stringify(sortDirs));
      localStorage.setItem('mv_cols',    JSON.stringify(cols));
      localStorage.setItem('mv_filters', JSON.stringify(filters));
    } catch(e) {}
  }
  function restore() {
    try {
      var v = localStorage.getItem('mv_view');    if(v) currentView = v;
      var s = localStorage.getItem('mv_sort');    if(s) currentSort = s;
      var sd= localStorage.getItem('mv_sdirs');   if(sd) Object.assign(sortDirs, JSON.parse(sd));
      var c = localStorage.getItem('mv_cols');    if(c) Object.assign(cols, JSON.parse(c));
      var f = localStorage.getItem('mv_filters'); if(f) Object.assign(filters, JSON.parse(f));
    } catch(e) {}
  }

  // ── Column CSS ──
  function applyColCSS() {
    var css = '';
    if (!cols.storage)  css += '.col-storage{display:none}';
    if (!cols.movedate) css += '.col-movedate{display:none}';
    if (!cols.plays)    css += '.col-plays{display:none}';
    if (!cols.viewers)  css += '.col-viewers{display:none}';
    if (!cols.date)     css += '.col-date{display:none}';
    colStyle.textContent = css;
    // Sync checkboxes
    document.getElementById('col-storage').checked  = cols.storage;
    document.getElementById('col-movedate').checked = cols.movedate;
    document.getElementById('col-plays').checked    = cols.plays;
    document.getElementById('col-viewers').checked  = cols.viewers;
    document.getElementById('col-date').checked     = cols.date;
  }

  window.saveCols = function() {
    cols.storage  = document.getElementById('col-storage').checked;
    cols.movedate = document.getElementById('col-movedate').checked;
    cols.plays    = document.getElementById('col-plays').checked;
    cols.viewers  = document.getElementById('col-viewers').checked;
    cols.date     = document.getElementById('col-date').checked;
    applyColCSS();
    save();
  };

  // ── View switching ──
  window.setView = function(v, btn) {
    currentView = v;
    posterView.style.display = v==='poster'   ? '' : 'none';
    ovView.style.display     = v==='overview' ? 'block' : 'none';
    tblView.style.display    = v==='table'    ? 'block' : 'none';
    // Update view menu items
    document.querySelectorAll('#view-menu .sc-menu-item').forEach(function(i) {
      i.classList.toggle('selected', i.dataset.view === v);
    });
    // Update options icon based on view
    var icons = {
      poster:   '<path d="M3 3h7v9H3zm11 0h7v9h-7zm-11 11h7v7H3zm11 0h7v7h-7z"/>',
      overview: '<path d="M3 5h2V3H3v2zm0 4h2V7H3v2zm0 4h2v-2H3v2zm4-8h14V3H7v2zm0 4h14V7H7v2zm0 4h14v-2H7v2z"/>',
      table:    '<path d="M20 2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-9 11H5v-2h6v2zm0-4H5V7h6v2zm8 4h-6v-2h6v2zm0-4h-6V7h6v2z"/>'
    };
    document.getElementById('options-icon').innerHTML = icons[v] || icons.table;
    closeAllMenus();
    save();
  };

  // ── Sort ──
  window.setSort = function(key, btn) {
    if (currentSort === key) {
      sortDirs[key] = !sortDirs[key];
    } else {
      currentSort = key;
    }
    // Update sort menu
    document.querySelectorAll('#sort-menu .sc-menu-item').forEach(function(i) {
      var isActive = i.dataset.sort === key;
      i.classList.toggle('selected', isActive);
    });
    // Update sort direction icons in menu
    Object.keys(sortDirs).forEach(function(k) {
      var el = document.getElementById('sd-'+k);
      if (el) el.textContent = sortDirs[k] ? '↑' : '↓';
    });
    // Update table header indicators
    document.querySelectorAll('#main-table .sort-col').forEach(function(th) {
      var isActive = th.dataset.col === key;
      th.classList.toggle('sort-active', isActive);
      var arrow = th.querySelector('.th-sort');
      if (arrow) arrow.textContent = sortDirs[key] ? '↑' : '↓';
    });
    closeAllMenus();
    applyAll();
    save();
  };

  // ── Filter ──
  window.applyFilter = function() {
    filters.shows  = document.getElementById('f-shows').checked;
    filters.movies = document.getElementById('f-movies').checked;
    // Show indicator dot when not everything is selected
    var allOn = filters.shows && filters.movies;
    document.getElementById('btn-filter').classList.toggle('has-filter', !allOn);
    applyAll();
    save();
  };

  // ── Comparator ──
  function cmp(a, b) {
    var asc = sortDirs[currentSort] === true;
    var av, bv;
    if (currentSort === 'title') {
      av = a.dataset.title||''; bv = b.dataset.title||'';
      return asc ? av.localeCompare(bv) : bv.localeCompare(av);
    }
    if (currentSort === 'storage') {
      av = parseInt(a.dataset.storage||'0'); bv = parseInt(b.dataset.storage||'0');
    } else if (currentSort === 'movedate') {
      av = parseInt(a.dataset.movedate||'0'); bv = parseInt(b.dataset.movedate||'0');
    } else if (currentSort === 'plays') {
      av = parseInt(a.dataset.plays||'0'); bv = parseInt(b.dataset.plays||'0');
    } else if (currentSort === 'viewers') {
      av = parseInt(a.dataset.viewers||'0'); bv = parseInt(b.dataset.viewers||'0');
    } else if (currentSort === 'watched') {
      av = parseInt(a.dataset.watched||'0'); bv = parseInt(b.dataset.watched||'0');
    } else if (currentSort === 'added' || currentSort === 'date') {
      av = parseInt(a.dataset.added||'0')  || parseInt(a.dataset.watched||'0');
      bv = parseInt(b.dataset.added||'0')  || parseInt(b.dataset.watched||'0');
    } else {
      return 0;
    }
    return asc ? av - bv : bv - av;
  }

  function isVisible(el) {
    var type = el.dataset.type || '';
    if (!filters.shows  && type === 'show')  return false;
    if (!filters.movies && type === 'movie') return false;
    return true;
  }

  // ── Apply all ──
  function applyAll() {
    var cards = [...posterView.querySelectorAll('.sc-card')];
    var ovRows= [...ovView.querySelectorAll('.ov-row')];
    var tblRows=[...document.querySelectorAll('#main-table tbody tr')];

    // Sort cards and reorder
    cards.sort(cmp).forEach(function(c) { posterView.appendChild(c); });
    var ovCard = ovView.querySelector('.card');
    if (ovCard) ovRows.sort(cmp).forEach(function(r) { ovCard.appendChild(r); });
    var tbody = document.querySelector('#main-table tbody');
    if (tbody) tblRows.sort(cmp).forEach(function(r) { tbody.appendChild(r); });

    // Show/hide
    var n = 0;
    cards.forEach(function(c)   { var v=isVisible(c); c.style.display=v?'':  'none'; if(v)n++; });
    ovRows.forEach(function(r)  { r.style.display=isVisible(r)?'':'none'; });
    tblRows.forEach(function(r) { r.style.display=isVisible(r)?'':'none'; });

    if (countEl) countEl.textContent = n;
  }

  // ── Menus ──
  window.toggleMenu = function(id) {
    var m = document.getElementById(id);
    var wasOpen = m.classList.contains('open');
    closeAllMenus();
    if (!wasOpen) m.classList.add('open');
  };
  function closeAllMenus() {
    document.querySelectorAll('.sc-menu').forEach(function(m) { m.classList.remove('open'); });
  }
  document.addEventListener('click', function(e) {
    if (!e.target.closest('.sc-menu-wrap') && !e.target.closest('.sc-menu')) closeAllMenus();
  });

  // ── Options modal ──
  window.openOptions  = function() { document.getElementById('options-modal').classList.add('open'); };
  window.closeOptions = function() { document.getElementById('options-modal').classList.remove('open'); };

  // ── Init ──
  restore();

  // Apply restored filter checkboxes
  document.getElementById('f-shows').checked  = filters.shows;
  document.getElementById('f-movies').checked = filters.movies;
  var allOn = filters.shows && filters.movies;
  document.getElementById('btn-filter').classList.toggle('has-filter', !allOn);

  applyColCSS();
  setView(currentView, null);

  // Sync sort menu selection
  document.querySelectorAll('#sort-menu .sc-menu-item').forEach(function(i) {
    i.classList.toggle('selected', i.dataset.sort === currentSort);
  });
  Object.keys(sortDirs).forEach(function(k) {
    var el = document.getElementById('sd-'+k);
    if (el) el.textContent = sortDirs[k] ? '↑' : '↓';
  });
  // Sync table headers
  document.querySelectorAll('#main-table .sort-col').forEach(function(th) {
    var isActive = th.dataset.col === currentSort;
    th.classList.toggle('sort-active', isActive);
    var arrow = th.querySelector('.th-sort');
    if (arrow) arrow.textContent = sortDirs[currentSort] ? '↑' : '↓';
  });

  applyAll();
})();
</script>

<?php layout_end(); ?>
