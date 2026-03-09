<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db.php';

$s = load_settings();

$db = null;
$db_error = null;
try {
    $db = db_connect();
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

// ── POST handlers (PRG pattern) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['track_id'] ?? 0);
        if ($id) {
            db_delete_tracked($db, $id);
            header('Location: tracked.php?msg=' . urlencode('Entry removed.') . '&type=success');
        } else {
            header('Location: tracked.php');
        }
        exit;

    } elseif ($action === 'pin') {
        $id = (int)($_POST['track_id'] ?? 0);
        if ($id) {
            db_pin_tracked($db, $id);
            header('Location: tracked.php?msg=' . urlencode('Pinned — will not auto-relocate.') . '&type=success');
        } else {
            header('Location: tracked.php');
        }
        exit;

    } elseif ($action === 'set_relocate') {
        $id  = (int)($_POST['track_id'] ?? 0);
        $ts  = strtotime($_POST['relocate_date'] ?? '');
        if ($id && $ts) {
            db_set_relocate($db, $id, $ts);
            header('Location: tracked.php?msg=' . urlencode('Relocate date updated.') . '&type=success');
        } else {
            header('Location: tracked.php?msg=' . urlencode('Invalid date.') . '&type=error');
        }
        exit;
    }
}

// ── Redirect message ───────────────────────────────────────────────────────────
$message  = $_GET['msg']  ?? null;
$msg_type = $_GET['type'] ?? 'success';

// ── Data ───────────────────────────────────────────────────────────────────────
$tracked  = $db ? db_all_tracked($db) : [];
$mappings = $s['path_mappings'] ?? [];

$mapping_names = [];
foreach ($mappings as $m) {
    $mapping_names[$m['id']] = $m['name'] ?: $m['id'];
}

// Stats
$now          = time();
$total        = count($tracked);
$fast_count   = 0; $slow_count = 0; $pinned_count = 0;
$expired_count= 0; $tv_count   = 0; $movie_count  = 0;
foreach ($tracked as $r) {
    if ($r['current_location'] === 'fast') $fast_count++; else $slow_count++;
    if ($r['relocate_after'] === null) $pinned_count++;
    elseif ($r['relocate_after'] < $now) $expired_count++;
    if ($r['media_type'] === 'show') $tv_count++; else $movie_count++;
}

function fmt_ts_t(?int $ts): string {
    if (!$ts) return '—';
    return date('Y-m-d', $ts);
}

function days_left_label(?int $ra): array {
    // returns ['label', 'class', 'value' for sort]
    if ($ra === null) return ['Pinned', 'badge-amber', 0];
    $diff = $ra - time();
    if ($diff <= 0) return ['Expired', 'badge-red', -1];
    $d = (int)ceil($diff / 86400);
    return [$d . 'd left', 'badge-green', $d];
}

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
  max-width: 280px;
}
.search-wrap svg {
  position: absolute;
  left: .6rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--muted);
  pointer-events: none;
}
.search-wrap input {
  padding-left: 2rem;
  font-size: .83rem;
}
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
.sort-wrap select {
  width: auto;
  font-size: .8rem;
  padding: .35rem .55rem;
}
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

/* ── Filter bar ── */
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

/* ── Stats strip ── */
.stats-strip {
  display: flex;
  gap: 1.25rem;
  font-size: .78rem;
  color: var(--muted);
  margin-bottom: .5rem;
  flex-wrap: wrap;
  align-items: center;
}
.stats-strip strong { color: var(--text); }
.stats-strip .sep { color: var(--border); }

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
  cursor: default;
  transition: border-color .2s, transform .15s, box-shadow .2s;
  position: relative;
}
.poster-card:hover {
  border-color: var(--accent);
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0,0,0,.4);
}
.poster-art {
  position: relative;
  width: 100%;
  padding-top: 148%;  /* ~2:3 aspect ratio */
  overflow: hidden;
}
.poster-art-inner {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 3rem;
}
/* TV show gradient */
.poster-art-inner.art-show {
  background: linear-gradient(160deg, #1a2a4a 0%, #0d1a2e 50%, #0a1020 100%);
}
/* Movie gradient */
.poster-art-inner.art-movie {
  background: linear-gradient(160deg, #2a1a3a 0%, #1a0d28 50%, #100a1a 100%);
}
/* Fast tint overlay */
.poster-art-inner.loc-fast::after {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(60,179,113,.07);
  pointer-events: none;
}
.poster-art-overlay {
  position: absolute;
  bottom: 0; left: 0; right: 0;
  background: linear-gradient(transparent, rgba(0,0,0,.85) 60%);
  padding: 1.5rem .5rem .45rem;
}
.poster-art-badges {
  display: flex;
  gap: .25rem;
  flex-wrap: wrap;
}
.poster-corner-badge {
  position: absolute;
  top: .4rem;
  right: .4rem;
  font-size: .65rem;
  font-weight: 700;
  padding: .15rem .35rem;
  border-radius: 3px;
}
.poster-pin-badge {
  position: absolute;
  top: .4rem;
  left: .4rem;
  font-size: .85rem;
  line-height: 1;
  filter: drop-shadow(0 1px 2px rgba(0,0,0,.5));
}
.poster-info {
  padding: .5rem .55rem .55rem;
  border-top: 1px solid rgba(255,255,255,.05);
}
.poster-title {
  font-size: .8rem;
  font-weight: 600;
  color: var(--text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: .2rem;
}
.poster-meta {
  font-size: .68rem;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ── Actions (hidden until hover) ── */
.poster-actions {
  display: none;
  position: absolute;
  bottom: 0; left: 0; right: 0;
  background: rgba(0,0,0,.88);
  padding: .45rem .4rem;
  gap: .25rem;
  justify-content: center;
  flex-wrap: wrap;
  border-top: 1px solid rgba(255,255,255,.08);
}
.poster-card:hover .poster-actions { display: flex; }
.poster-card:hover .poster-info { opacity: 0; }
.act-btn {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 3px;
  color: var(--muted);
  cursor: pointer;
  font-size: .68rem;
  padding: .2rem .4rem;
  white-space: nowrap;
  transition: color .15s, border-color .15s;
}
.act-btn:hover { color: var(--text); border-color: var(--accent); }
.act-btn-danger { color: #7a3030; border-color: #4a1a1a; }
.act-btn-danger:hover { color: var(--red); border-color: var(--red); background: #1a0808; }
.act-date-input {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 3px;
  color: var(--text);
  cursor: pointer;
  font-size: .68rem;
  padding: .2rem .3rem;
  width: 90px;
}
.act-date-input:focus { border-color: var(--accent); outline: none; }

/* ── Table view (hidden by default) ── */
#table-view { display: none; }
#table-view table { font-size: .82rem; }

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

/* ── Empty state ── */
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

layout_start('Tracked Media', 'tracked', $extra_head);
?>

<?php if ($db_error): ?>
<div class="notice notice-error">Database unavailable: <?= htmlspecialchars($db_error) ?></div>
<?php endif; ?>

<?php if ($message): ?>
<div class="notice notice-<?= $msg_type === 'error' ? 'error' : 'success' ?>">
  <?= htmlspecialchars($message) ?>
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
    <button class="view-btn active" id="view-cards" title="Card view">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
        <path d="M3 3h7v9H3zm11 0h7v9h-7zm-11 11h7v7H3zm11 0h7v7h-7z"/>
      </svg>
    </button>
    <button class="view-btn" id="view-table" title="Table view">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
        <path d="M3 4h18v3H3V4zm0 5h18v2H3V9zm0 4h18v2H3v-2zm0 4h18v3H3v-3z"/>
      </svg>
    </button>
  </div>

  <!-- Sort -->
  <div class="sort-wrap">
    <label>Sort</label>
    <select id="sort-select">
      <option value="title">Title</option>
      <option value="moved">Moved Date</option>
      <option value="relocate">Relocate Date</option>
      <option value="location">Location</option>
      <option value="service">Service</option>
      <option value="type">Type</option>
    </select>
    <button class="sort-dir-btn" id="sort-dir" title="Toggle sort direction">↑</button>
  </div>

  <!-- Result count -->
  <span style="font-size:.78rem;color:var(--muted);margin-left:auto">
    <strong id="visible-count"><?= $total ?></strong> of <?= $total ?>
  </span>
</div>

<!-- ── Filter pills ── -->
<div class="filter-bar">
  <button class="filter-pill active" data-filter="all">All <span class="filter-count"><?= $total ?></span></button>
  <button class="filter-pill" data-filter="fast">Fast <span class="filter-count"><?= $fast_count ?></span></button>
  <button class="filter-pill" data-filter="slow">Slow <span class="filter-count"><?= $slow_count ?></span></button>
  <button class="filter-pill" data-filter="pinned">Pinned <span class="filter-count"><?= $pinned_count ?></span></button>
  <button class="filter-pill" data-filter="expired">Expired <span class="filter-count"><?= $expired_count ?></span></button>
  <button class="filter-pill" data-filter="tv">TV Shows <span class="filter-count"><?= $tv_count ?></span></button>
  <button class="filter-pill" data-filter="movies">Movies <span class="filter-count"><?= $movie_count ?></span></button>
</div>

<!-- ── CARDS VIEW ── -->
<div id="cards-view" class="poster-grid">
  <?php if (empty($tracked)): ?>
  <div class="no-results">Nothing tracked yet.<br>Moves will appear here once the mover runs.</div>
  <?php endif; ?>

  <?php foreach ($tracked as $row):
    $on_fast     = $row['current_location'] === 'fast';
    $is_pinned   = $row['relocate_after'] === null;
    $is_expired  = !$is_pinned && $row['relocate_after'] < $now;
    [$rl_label, $rl_class, $rl_sort] = days_left_label($row['relocate_after']);
    $map_name    = $mapping_names[$row['mapping_id']] ?? $row['mapping_id'];
    $is_show     = $row['media_type'] === 'show';
    $loc_class   = $on_fast ? 'loc-fast' : '';
    $art_class   = $is_show ? 'art-show' : 'art-movie';
    $icon        = $is_show ? '📺' : '🎬';
    $svc_label   = $row['service'] === 'sonarr' ? 'Sonarr' : 'Radarr';
    $id_label    = $row['service'] === 'sonarr' ? 'TVDB' : 'TMDB';
  ?>
  <div class="poster-card"
    data-id="<?= $row['id'] ?>"
    data-title="<?= htmlspecialchars(strtolower($row['title'] ?? '')) ?>"
    data-location="<?= htmlspecialchars($row['current_location']) ?>"
    data-type="<?= htmlspecialchars($row['media_type']) ?>"
    data-service="<?= htmlspecialchars($row['service']) ?>"
    data-moved="<?= (int)$row['moved_at'] ?>"
    data-relocate="<?= (int)$row['relocate_after'] ?>"
    data-pinned="<?= $is_pinned ? '1' : '0' ?>"
    data-expired="<?= $is_expired ? '1' : '0' ?>">

    <div class="poster-art">
      <div class="poster-art-inner <?= $art_class ?> <?= $loc_class ?>">
        <?= $icon ?>
      </div>
      <!-- Overlay badges at bottom of art -->
      <div class="poster-art-overlay">
        <div class="poster-art-badges">
          <span class="badge <?= $on_fast ? 'badge-green' : 'badge-muted' ?>" style="font-size:.62rem">
            <?= $on_fast ? '→ Fast' : '← Slow' ?>
          </span>
          <span class="badge <?= $row['service'] === 'sonarr' ? 'badge-blue' : 'badge-amber' ?>" style="font-size:.62rem">
            <?= $svc_label ?>
          </span>
        </div>
      </div>
      <!-- Top-left: pin indicator -->
      <?php if ($is_pinned): ?>
      <div class="poster-pin-badge" title="Pinned — will not auto-relocate">📌</div>
      <?php endif; ?>
      <!-- Top-right: source badge -->
      <div class="poster-corner-badge badge <?= $row['source'] === 'manual' ? 'badge-amber' : 'badge-muted' ?>">
        <?= $row['source'] === 'manual' ? 'Manual' : 'Auto' ?>
      </div>

      <!-- Hover actions -->
      <div class="poster-actions">
        <?php if (!$is_pinned): ?>
        <form method="POST" action="tracked.php" style="display:inline">
          <input type="hidden" name="action" value="pin">
          <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
          <button type="submit" class="act-btn" title="Pin (prevent auto-relocation)">📌 Pin</button>
        </form>
        <?php endif; ?>
        <form method="POST" action="tracked.php" style="display:inline" id="rf-<?= $row['id'] ?>">
          <input type="hidden" name="action" value="set_relocate">
          <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
          <input type="date" name="relocate_date" class="act-date-input"
            value="<?= $row['relocate_after'] ? date('Y-m-d', $row['relocate_after']) : '' ?>"
            onchange="document.getElementById('rf-<?= $row['id'] ?>').submit()"
            title="Set relocate date">
        </form>
        <form method="POST" action="tracked.php" style="display:inline"
              onsubmit="return confirm('Remove this entry from tracking?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
          <button type="submit" class="act-btn act-btn-danger" title="Remove">✕ Remove</button>
        </form>
      </div>
    </div><!-- .poster-art -->

    <div class="poster-info">
      <div class="poster-title" title="<?= htmlspecialchars($row['title'] ?: '—') ?>">
        <?= htmlspecialchars($row['title'] ?: '—') ?>
      </div>
      <div class="poster-meta">
        <span class="<?= $is_pinned ? 'text-accent' : ($is_expired ? '' : '') ?>"
              style="<?= $is_expired ? 'color:var(--red)' : '' ?>">
          <?= htmlspecialchars($rl_label) ?>
        </span>
        &middot; <?= $id_label ?> <?= $row['external_id'] ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div><!-- #cards-view -->

<!-- ── TABLE VIEW ── -->
<div id="table-view">
  <?php if (empty($tracked)): ?>
  <div class="empty">Nothing tracked yet. Moves will appear here once the mover runs.</div>
  <?php else: ?>
  <div class="card">
    <table>
      <thead>
        <tr>
          <th></th>
          <th>Title</th>
          <th>ID</th>
          <th>Mapping</th>
          <th>Location</th>
          <th>Moved</th>
          <th>Relocates</th>
          <th>Source</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tracked as $row):
        $on_fast   = $row['current_location'] === 'fast';
        $is_pinned = $row['relocate_after'] === null;
        $is_expired= !$is_pinned && $row['relocate_after'] < $now;
        [$rl_label, $rl_class, $rl_sort] = days_left_label($row['relocate_after']);
        $map_name  = $mapping_names[$row['mapping_id']] ?? $row['mapping_id'];
        $id_label  = $row['service'] === 'sonarr' ? 'TVDB' : 'TMDB';
      ?>
      <tr data-id="<?= $row['id'] ?>"
          data-title="<?= htmlspecialchars(strtolower($row['title'] ?? '')) ?>"
          data-location="<?= htmlspecialchars($row['current_location']) ?>"
          data-type="<?= htmlspecialchars($row['media_type']) ?>"
          data-service="<?= htmlspecialchars($row['service']) ?>"
          data-moved="<?= (int)$row['moved_at'] ?>"
          data-relocate="<?= (int)$row['relocate_after'] ?>"
          data-pinned="<?= $is_pinned ? '1' : '0' ?>"
          data-expired="<?= $is_expired ? '1' : '0' ?>">
        <td style="width:20px">
          <span class="dot <?= $on_fast ? 'dot-green' : 'dot-muted' ?>"></span>
        </td>
        <td>
          <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($row['title'] ?: '—') ?></div>
          <div style="font-size:.7rem;color:var(--muted)"><?= htmlspecialchars($row['folder'] ?: '') ?></div>
        </td>
        <td style="font-size:.75rem;color:var(--muted);font-family:monospace">
          <span style="font-size:.68rem"><?= $id_label ?></span><br>
          <?= htmlspecialchars((string)$row['external_id']) ?>
        </td>
        <td style="font-size:.78rem;color:var(--muted)"><?= htmlspecialchars($map_name) ?></td>
        <td>
          <?php if ($on_fast): ?>
            <span style="color:var(--green);font-weight:700;font-size:.82rem">→ Fast</span>
          <?php else: ?>
            <span style="color:var(--muted);font-weight:700;font-size:.82rem">← Slow</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.78rem;color:var(--muted)"><?= fmt_ts_t($row['moved_at']) ?></td>
        <td style="font-size:.78rem">
          <span class="badge <?= $rl_class ?>"><?= htmlspecialchars($rl_label) ?></span>
        </td>
        <td>
          <span class="badge <?= $row['source'] === 'manual' ? 'badge-amber' : 'badge-muted' ?>">
            <?= htmlspecialchars($row['source']) ?>
          </span>
        </td>
        <td style="white-space:nowrap">
          <div style="display:flex;gap:.3rem;align-items:center">
            <?php if (!$is_pinned): ?>
            <form method="POST" action="tracked.php" style="display:inline">
              <input type="hidden" name="action" value="pin">
              <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
              <button type="submit" class="btn" style="padding:.2rem .45rem;font-size:.7rem" title="Pin">📌</button>
            </form>
            <?php endif; ?>
            <form method="POST" action="tracked.php" style="display:inline" id="trf-<?= $row['id'] ?>">
              <input type="hidden" name="action" value="set_relocate">
              <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
              <input type="date" name="relocate_date"
                     value="<?= $row['relocate_after'] ? date('Y-m-d', $row['relocate_after']) : '' ?>"
                     style="width:108px;font-size:.72rem;padding:.2rem .35rem"
                     onchange="document.getElementById('trf-<?= $row['id'] ?>').submit()"
                     title="Set relocate date">
            </form>
            <form method="POST" action="tracked.php" style="display:inline"
                  onsubmit="return confirm('Remove this entry?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
              <button type="submit" class="btn btn-danger" style="padding:.2rem .45rem;font-size:.7rem" title="Remove">✕</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div><!-- #table-view -->

<!-- ── Legend ── -->
<div class="legend-box">
  <div class="legend-title">Legend</div>
  <div class="legend-grid">
    <div class="legend-item">
      <span class="dot dot-green"></span>
      <span>On <strong style="color:var(--green)">Fast</strong> storage (SSD)</span>
    </div>
    <div class="legend-item">
      <span class="dot dot-muted"></span>
      <span>On <strong style="color:var(--muted)">Slow</strong> storage (HDD/RAID)</span>
    </div>
    <div class="legend-item">
      <span class="legend-swatch" style="background:rgba(60,179,113,.15);border:1px solid var(--green)"></span>
      <span><strong style="color:var(--green)">→ Fast</strong> — currently on fast storage</span>
    </div>
    <div class="legend-item">
      <span class="legend-swatch" style="background:rgba(255,255,255,.06);border:1px solid var(--muted)"></span>
      <span><strong style="color:var(--muted)">← Slow</strong> — currently on slow storage</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-amber" style="font-size:.65rem">Pinned</span>
      <span>Pinned — auto-relocation disabled</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-red" style="font-size:.65rem">Expired</span>
      <span>Eligible to be moved back to slow</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-green" style="font-size:.65rem">Xd left</span>
      <span>Days until auto-relocation window opens</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-amber" style="font-size:.65rem">Manual</span>
      <span>Manually triggered move</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-muted" style="font-size:.65rem">Auto</span>
      <span>Automatically moved by mover service</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-blue" style="font-size:.65rem">Sonarr</span>
      <span>TV show managed by Sonarr (TVDB ID)</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-amber" style="font-size:.65rem">Radarr</span>
      <span>Movie managed by Radarr (TMDB ID)</span>
    </div>
    <div class="legend-item">
      <span style="font-size:1rem">📌</span>
      <span>Pinned — will never auto-relocate</span>
    </div>
  </div>
</div>

<script>
(function () {
  let currentFilter = 'all';
  let currentSort   = 'title';
  let sortAsc       = true;
  let currentView   = 'cards';
  const now         = Math.floor(Date.now() / 1000);

  const searchInput   = document.getElementById('search-input');
  const sortSelect    = document.getElementById('sort-select');
  const sortDirBtn    = document.getElementById('sort-dir');
  const visibleCount  = document.getElementById('visible-count');
  const cardsView     = document.getElementById('cards-view');
  const tableView     = document.getElementById('table-view');

  // ── Get all items (use card elements as data source) ──
  function getCardItems() {
    return [...cardsView.querySelectorAll('.poster-card[data-id]')];
  }
  function getTableRows() {
    return [...tableView.querySelectorAll('tr[data-id]')];
  }

  // ── Check if item matches current filter & search ──
  function isVisible(el) {
    const query  = (searchInput.value || '').toLowerCase().trim();
    const title  = (el.dataset.title || '').toLowerCase();
    const loc    = el.dataset.location || '';
    const type   = el.dataset.type || '';
    const pinned = el.dataset.pinned === '1';
    const expd   = el.dataset.expired === '1';
    const reloc  = parseInt(el.dataset.relocate || '0');

    if (query && !title.includes(query)) return false;

    switch (currentFilter) {
      case 'fast':    return loc === 'fast';
      case 'slow':    return loc !== 'fast';
      case 'pinned':  return pinned;
      case 'expired': return expd;
      case 'tv':      return type === 'show';
      case 'movies':  return type === 'movie';
    }
    return true;
  }

  // ── Sort comparator ──
  function compareItems(a, b) {
    let av, bv;
    if (currentSort === 'title') {
      av = a.dataset.title || ''; bv = b.dataset.title || '';
      return sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
    }
    if (currentSort === 'location') {
      av = a.dataset.location || ''; bv = b.dataset.location || '';
      return sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
    }
    if (currentSort === 'service') {
      av = a.dataset.service || ''; bv = b.dataset.service || '';
      return sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
    }
    if (currentSort === 'type') {
      av = a.dataset.type || ''; bv = b.dataset.type || '';
      return sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
    }
    if (currentSort === 'moved') {
      av = parseInt(a.dataset.moved || '0');
      bv = parseInt(b.dataset.moved || '0');
    } else if (currentSort === 'relocate') {
      av = parseInt(a.dataset.relocate || '0');
      bv = parseInt(b.dataset.relocate || '0');
    } else {
      return 0;
    }
    return sortAsc ? bv - av : av - bv;
  }

  // ── Apply everything ──
  function applyAll() {
    const cards = getCardItems();
    const rows  = getTableRows();

    // Build visible ID set
    const visIds = new Set();
    cards.forEach(c => { if (isVisible(c)) visIds.add(c.dataset.id); });

    // Sort cards
    const sorted = [...cards].sort(compareItems);
    sorted.forEach(c => cardsView.appendChild(c));

    // Show/hide cards
    cards.forEach(c => {
      c.style.display = visIds.has(c.dataset.id) ? '' : 'none';
    });

    // Show/hide table rows
    rows.forEach(r => {
      r.style.display = visIds.has(r.dataset.id) ? '' : 'none';
    });

    // Count
    if (visibleCount) visibleCount.textContent = visIds.size;
  }

  // ── Event: search ──
  searchInput.addEventListener('input', applyAll);

  // ── Event: sort select ──
  sortSelect.addEventListener('change', function () {
    currentSort = this.value;
    applyAll();
  });

  // ── Event: sort direction ──
  sortDirBtn.addEventListener('click', function () {
    sortAsc = !sortAsc;
    this.textContent = sortAsc ? '↑' : '↓';
    applyAll();
  });

  // ── Event: filter pills ──
  document.querySelectorAll('.filter-pill').forEach(btn => {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      currentFilter = this.dataset.filter;
      applyAll();
    });
  });

  // ── Event: view toggle ──
  document.getElementById('view-cards').addEventListener('click', function () {
    currentView = 'cards';
    cardsView.style.display = '';
    tableView.style.display = 'none';
    this.classList.add('active');
    document.getElementById('view-table').classList.remove('active');
  });
  document.getElementById('view-table').addEventListener('click', function () {
    currentView = 'table';
    cardsView.style.display = 'none';
    tableView.style.display = '';
    this.classList.add('active');
    document.getElementById('view-cards').classList.remove('active');
  });

  // Initial apply
  applyAll();
})();
</script>

<?php layout_end(); ?>
