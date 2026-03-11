<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db.php';

$s = load_settings();

$db = null; $db_error = null;
try { $db = db_connect(); } catch (Exception $e) { $db_error = $e->getMessage(); }

// ── POST handlers (PRG) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = (int)($_POST['track_id'] ?? 0);
        if ($id) db_delete_tracked($db, $id);
        header('Location: tracked.php?msg='.urlencode('Entry removed.').'&mtype=success'); exit;
    } elseif ($action === 'unpin') {
        $id = (int)($_POST['track_id'] ?? 0);
        if ($id) {
            $db->prepare("UPDATE tracked_media SET relocate_after=?, source='auto', updated_at=? WHERE id=?")
               ->execute([time(), time(), $id]);
        }
        header('Location: tracked.php?msg='.urlencode('Unpinned — auto-relocation re-enabled.').'&mtype=success'); exit;
    } elseif ($action === 'pin') {
        $id = (int)($_POST['track_id'] ?? 0);
        if ($id) db_pin_tracked($db, $id);
        header('Location: tracked.php?msg='.urlencode('Pinned — will not auto-relocate.').'&mtype=success'); exit;
    } elseif ($action === 'set_relocate') {
        $id = (int)($_POST['track_id'] ?? 0);
        $ts = strtotime($_POST['relocate_date'] ?? '');
        if ($id && $ts) { db_set_relocate($db, $id, $ts); header('Location: tracked.php?msg='.urlencode('Relocate date updated.').'&mtype=success'); }
        else { header('Location: tracked.php?msg='.urlencode('Invalid date.').'&mtype=error'); }
        exit;
    } elseif ($action === 'move_to_fast' || $action === 'move_to_slow') {
        $id = (int)($_POST['track_id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("SELECT * FROM tracked_media WHERE id=?");
            $stmt->execute([$id]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($entry) {
                $direction = ($action === 'move_to_fast') ? 'to_fast' : 'to_slow';
                db_queue_move($db, (int)$entry['external_id'], $entry['service'],
                              $entry['mapping_id'], $direction,
                              'manual move from tracked page', $entry['title']);
                // Trigger manual_move.py in the mover container immediately
                @file_put_contents(config_base() . '/.manual_trigger',
                    date('Y-m-d H:i:s') . ' manual trigger from tracked page' . PHP_EOL);
                header('Location: tracked.php?msg='.urlencode('Move queued — starting now.').'&mtype=success'); exit;
            }
        }
        header('Location: tracked.php?msg='.urlencode('Entry not found.').'&mtype=error'); exit;
    }
}

$message  = isset($_GET['msg'])   ? $_GET['msg']   : null;
$msg_type = isset($_GET['mtype']) ? $_GET['mtype'] : 'success';

// ── Data ──────────────────────────────────────────────────────────────────────
$tracked  = $db ? db_all_tracked($db) : [];
$mappings = $s['path_mappings'] ?? [];
$map_names = [];
foreach ($mappings as $m) $map_names[$m['id']] = $m['name'] ?: $m['id'];

$now = time();

$total = count($tracked);
$fast_count = $slow_count = $pinned_count = $expired_count = 0;
foreach ($tracked as $r) {
    if ($r['current_location'] === 'fast') $fast_count++; else $slow_count++;
    if ($r['relocate_after'] === null) $pinned_count++;
    elseif ($r['relocate_after'] < $now) $expired_count++;
}

// ── Styles ────────────────────────────────────────────────────────────────────
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
  margin: -1.5rem -1.5rem 1.25rem;
  padding: 0 .75rem;
}
.sc-toolbar-left, .sc-toolbar-right {
  display: flex;
  align-items: stretch;
  gap: 0;
}
.sc-item-count {
  display: flex; align-items: center;
  padding: 0 12px;
  font-size: .78rem; color: var(--muted); white-space: nowrap;
}

/* Toolbar button — exact Sonarr spec */
.sc-tb-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 60px;
  height: 60px;
  padding: 0;
  background: none;
  border: none;
  color: var(--muted);
  cursor: pointer;
  transition: color .15s, background .15s;
  position: relative;
  text-decoration: none;
  flex-shrink: 0;
}
.sc-tb-btn:hover  { color: var(--text); background: rgba(255,255,255,.05); }
.sc-tb-btn.active { color: var(--accent); }
.sc-tb-btn svg    { flex-shrink: 0; }
.sc-tb-btn .lbl {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  padding-top: 4px;
  line-height: 1;
  white-space: nowrap;
}

/* Small indicator dot */
.sc-tb-indicator {
  position: absolute; top: 9px; right: 6px;
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--blue); display: none;
}
.sc-tb-btn.has-filter .sc-tb-indicator { display: block; }

.sc-tb-sep {
  width: 1px;
  height: 40px;
  background: rgba(255,255,255,.35);
  margin: 0 20px;
  flex-shrink: 0;
  align-self: center;
}

/* ── Search field in toolbar left ── */
.tb-search {
  display: flex; align-items: center;
  margin: 0 8px;
}
.tb-search input {
  width: 200px; font-size: .83rem; padding: 0 .65rem;
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: var(--radius); height: 32px;
  color: var(--text); outline: none;
}
.tb-search input:focus { border-color: var(--accent); }

/* ── Dropdown menus ── */
.sc-menu-wrap { position: relative; }
.sc-menu {
  display: none; position: absolute; top: 100%; right: 0;
  min-width: 180px; background: var(--surface); border: 1px solid var(--border);
  box-shadow: 0 4px 20px rgba(0,0,0,.55); z-index: 500; flex-direction: column;
}
.sc-menu.open { display: flex; }
.sc-menu-item {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 20px; color: var(--muted); font-size: .875rem;
  cursor: pointer; white-space: nowrap; gap: 1.5rem;
  border: none; background-color: transparent; width: 100%; text-align: left;
  transition: background .1s, color .1s;
}
.sc-menu-item:hover { background: rgba(255,255,255,.06); color: var(--text); }
.sc-menu-item .mi-check { visibility: hidden; flex-shrink: 0; color: var(--accent); }
.sc-menu-item.selected .mi-check { visibility: visible; }
.sc-menu-sep { height: 1px; background: var(--border); margin: 4px 0; }

/* ── Sonarr-style flex table ── */
.sc-tbl { overflow-x: auto; }
.sc-tbl-header,
.sc-tbl-row {
  display: flex;
  align-items: center;
  height: 38px;
}
.sc-tbl-header {
  border-bottom: 1px solid var(--border);
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--muted);
}
.sc-tbl-row {
  border-bottom: 1px solid rgba(255,255,255,.04);
  font-size: .875rem;
  transition: background .1s;
}
.sc-tbl-row:last-child { border-bottom: none; }
.sc-tbl-row:hover { background: rgba(255,255,255,.03); }
.sc-tbl-cell {
  padding: 0 8px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex-shrink: 0;
}
/* Column widths — flex-grow fills available space; actions always fixed */
.sc-tbl-status  { flex: 0 0 24px; }
.sc-tbl-title   { flex: 3 1 140px; min-width: 140px; }
.sc-tbl-type    { flex: 0 0 58px; }
.sc-tbl-loc     { flex: 0 0 78px; }
.sc-tbl-size    { flex: 1 0 80px; }
.sc-tbl-moved   { flex: 1 0 95px; }
.sc-tbl-reloc   { flex: 1 0 120px; overflow: visible; }
.sc-tbl-source  { flex: 0 0 68px; }
.sc-tbl-actions { flex: 0 0 145px; overflow: visible; }
.btn-move {
  padding: .2rem .4rem; font-size: .7rem; font-weight: 600;
  border: 1px solid var(--border); border-radius: var(--radius);
  cursor: pointer; white-space: nowrap; line-height: 1.4;
  background: rgba(255,255,255,.04); color: var(--text);
  transition: background .15s, color .15s;
}
.btn-move:hover { background: rgba(255,255,255,.1); }
.btn-move.to-fast { border-color: var(--green); color: var(--green); }
.btn-move.to-fast:hover { background: rgba(var(--green-rgb, 81,207,102),.15); }
.btn-move.to-slow { border-color: var(--muted); color: var(--muted); }
.btn-move.to-slow:hover { background: rgba(255,255,255,.08); color: var(--text); }

/* Sortable header cells */
.sc-tbl-header .sort-col { cursor: pointer; user-select: none; }
.sc-tbl-header .sort-col:hover { color: var(--text); }
.sc-tbl-header .sort-col.sort-active { color: var(--accent); }
.th-arrow { visibility: hidden; margin-left: 3px; font-size: .68rem; }
.sort-active .th-arrow { visibility: visible; }
.th-info { font-style: normal; font-size: .8rem; color: var(--muted); margin-left: 2px; cursor: help; }

/* ── Legend ── */
.legend-box { margin-top:1.5rem;padding:.75rem 1rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius); }
.legend-hdr { font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--muted);margin-bottom:.55rem; }
.legend-items { display:flex;flex-wrap:wrap;gap:.35rem 1.5rem; }
.legend-item { display:flex;align-items:center;gap:.4rem;font-size:.75rem;color:var(--muted); }

@media(max-width:900px) {
  .sc-tbl-moved, .sc-tbl-size, .sc-tbl-source { display: none; }
}
@media(max-width:700px) {
  .sc-toolbar { margin: -1rem -1rem 1rem; }
  .tb-search input { width: 130px; }
  .sc-tbl-reloc { display: none; }
  .sc-tbl-actions { flex: 0 0 100px; }
}
</style>
CSS;

layout_start('Tracked Media', 'tracked', $extra_head);
?>

<?php if ($db_error): ?>
<div class="notice notice-error">Database unavailable: <?= htmlspecialchars($db_error) ?></div>
<?php endif; ?>
<?php if ($message): ?>
<div class="notice notice-<?= $msg_type === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- ── Sonarr-style Toolbar ── -->
<div class="sc-toolbar">

  <!-- LEFT: search + count -->
  <div class="sc-toolbar-left">
    <div class="tb-search">
      <input type="text" id="search-input" placeholder="Search…" autocomplete="off">
    </div>
    <div class="sc-item-count">
      <span id="visible-count"><?= $total ?></span>&nbsp;of&nbsp;<?= $total ?>
    </div>
  </div>

  <!-- RIGHT: Sort | Filter -->
  <div class="sc-toolbar-right">

    <!-- Sort -->
    <div class="sc-menu-wrap">
      <button class="sc-tb-btn" onclick="toggleMenu('sort-menu')" title="Sort">
        <svg width="21" height="21" viewBox="0 0 24 24" fill="currentColor">
          <path d="M3 18h6v-2H3v2zM3 6v2h18V6H3zm0 7h12v-2H3v2z"/>
        </svg>
        <span class="lbl">Sort</span>
      </button>
      <div class="sc-menu" id="sort-menu">
        <button class="sc-menu-item selected" data-sort="title"    onclick="setSort('title',this)">
          Title <span class="mi-check">✓</span>
        </button>
        <button class="sc-menu-item" data-sort="location" onclick="setSort('location',this)">
          Location <span class="mi-check">✓</span>
        </button>
        <button class="sc-menu-item" data-sort="moved"    onclick="setSort('moved',this)">
          Moved Date <span class="mi-check">✓</span>
        </button>
        <button class="sc-menu-item" data-sort="relocate" onclick="setSort('relocate',this)">
          Relocate Date <span class="mi-check">✓</span>
        </button>
        <button class="sc-menu-item" data-sort="service"  onclick="setSort('service',this)">
          Service <span class="mi-check">✓</span>
        </button>
      </div>
    </div>

    <!-- Filter -->
    <div class="sc-menu-wrap">
      <button class="sc-tb-btn" id="btn-filter" onclick="toggleMenu('filter-menu')" title="Filter">
        <svg width="21" height="21" viewBox="0 0 24 24" fill="currentColor">
          <path d="M4.25 5.61C6.27 8.2 10 13 10 13v6c0 .55.45 1 1 1h2c.55 0 1-.45 1-1v-6s3.72-4.8 5.74-7.39A.998.998 0 0 0 18.95 4H5.04a1 1 0 0 0-.79 1.61z"/>
        </svg>
        <span class="lbl">Filter</span>
        <span class="sc-tb-indicator"></span>
      </button>
      <div class="sc-menu" id="filter-menu" style="min-width:190px">
        <button class="sc-menu-item selected" data-filter="all"     onclick="setFilter('all',this)">
          All Media <span class="mi-check">✓</span>
        </button>
        <div class="sc-menu-sep"></div>
        <button class="sc-menu-item" data-filter="fast"    onclick="setFilter('fast',this)">
          Fast Storage <span class="mi-check">✓</span>
        </button>
        <button class="sc-menu-item" data-filter="slow"    onclick="setFilter('slow',this)">
          Slow Storage <span class="mi-check">✓</span>
        </button>
        <button class="sc-menu-item" data-filter="pinned"  onclick="setFilter('pinned',this)">
          Pinned <span class="mi-check">✓</span>
        </button>
        <button class="sc-menu-item" data-filter="expired" onclick="setFilter('expired',this)">
          Expired <span class="mi-check">✓</span>
        </button>
        <div class="sc-menu-sep"></div>
        <button class="sc-menu-item" data-filter="show"    onclick="setFilter('show',this)">
          TV Shows <span class="mi-check">✓</span>
        </button>
        <button class="sc-menu-item" data-filter="movie"   onclick="setFilter('movie',this)">
          Movies <span class="mi-check">✓</span>
        </button>
      </div>
    </div>

  </div>
</div>

<!-- ── TABLE (Sonarr flex rows) ── -->
<?php if (empty($tracked)): ?>
  <div style="text-align:center;padding:4rem 2rem;color:var(--muted);font-size:.9rem">
    Nothing tracked yet.<br>Completed moves will appear here.
  </div>
<?php else: ?>
<div class="card sc-tbl" id="tracked-table">

  <!-- Header row -->
  <div class="sc-tbl-header">
    <div class="sc-tbl-cell sc-tbl-status"></div>
    <div class="sc-tbl-cell sc-tbl-title sort-col" data-sort="title" onclick="doSort('title')">
      Title <span class="th-arrow">↑</span>
    </div>
    <div class="sc-tbl-cell sc-tbl-type">Type</div>
    <div class="sc-tbl-cell sc-tbl-loc sort-col" data-sort="location" onclick="doSort('location')" title="Storage location (Fast / Slow)">
      Storage <span class="th-arrow">↑</span>
    </div>
    <div class="sc-tbl-cell sc-tbl-size">Size</div>
    <div class="sc-tbl-cell sc-tbl-moved sort-col" data-sort="moved" onclick="doSort('moved')">
      Moved <span class="th-arrow">↑</span>
    </div>
    <div class="sc-tbl-cell sc-tbl-reloc sort-col" data-sort="relocate" onclick="doSort('relocate')">
      Relocate
      <span class="th-info" title="Date this item will be auto-relocated. Edit inline. Pin/unpin via the 📌 button.">ⓘ</span>
      <span class="th-arrow">↑</span>
    </div>
    <div class="sc-tbl-cell sc-tbl-source sort-col" data-sort="service" onclick="doSort('service')">
      Svc <span class="th-arrow">↑</span>
    </div>
    <div class="sc-tbl-cell sc-tbl-actions">Actions</div>
  </div>

  <!-- Data rows -->
  <?php foreach ($tracked as $row):
    $on_fast    = $row['current_location'] === 'fast';
    $is_pinned  = $row['relocate_after'] === null;
    $is_expired = !$is_pinned && (int)$row['relocate_after'] < $now;
    $is_show    = $row['media_type'] === 'show';
    $svc_label  = $row['service'] === 'sonarr' ? 'Sonarr' : 'Radarr';
    $id_label   = $row['service'] === 'sonarr' ? 'TVDB' : 'TMDB';
    $map_name   = $map_names[$row['mapping_id']] ?? $row['mapping_id'];

    if ($is_pinned)            { $rl_label = 'Pinned';   $rl_class = 'badge-amber'; }
    elseif ($is_expired)       { $rl_label = 'Expired';  $rl_class = 'badge-red'; }
    else {
      $diff = (int)$row['relocate_after'] - $now;
      $rl_label = date('Y-m-d', $row['relocate_after']);
      $rl_class = 'badge-green';
    }

    $relocate_val = $row['relocate_after'] ? date('Y-m-d', $row['relocate_after']) : '';
  ?>
  <div class="sc-tbl-row"
    data-id="<?= $row['id'] ?>"
    data-title="<?= htmlspecialchars(strtolower($row['title'] ?? '')) ?>"
    data-location="<?= htmlspecialchars($row['current_location']) ?>"
    data-type="<?= htmlspecialchars($row['media_type']) ?>"
    data-service="<?= htmlspecialchars($row['service']) ?>"
    data-moved="<?= (int)$row['moved_at'] ?>"
    data-relocate="<?= (int)($row['relocate_after'] ?? 0) ?>"
    data-pinned="<?= $is_pinned ? '1' : '0' ?>"
    data-expired="<?= $is_expired ? '1' : '0' ?>">

    <div class="sc-tbl-cell sc-tbl-status">
      <span class="dot <?= $on_fast ? 'dot-green' : 'dot-muted' ?>"></span>
    </div>

    <div class="sc-tbl-cell sc-tbl-title">
      <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <?= htmlspecialchars($row['title'] ?: '—') ?>
      </div>
    </div>

    <div class="sc-tbl-cell sc-tbl-type">
      <?php if ($is_show): ?>
        <span class="badge badge-muted" style="font-size:.68rem">TV</span>
      <?php else: ?>
        <span class="badge" style="font-size:.68rem;background:rgba(160,90,219,.12);color:#a05adb">Movie</span>
      <?php endif; ?>
    </div>

    <div class="sc-tbl-cell sc-tbl-loc">
      <?php if ($on_fast): ?>
        <span style="color:var(--green);font-weight:700;font-size:.82rem">Fast</span>
      <?php else: ?>
        <span style="color:var(--muted);font-weight:700;font-size:.82rem">Slow</span>
      <?php endif; ?>
    </div>

    <div class="sc-tbl-cell sc-tbl-size" style="font-size:.78rem;color:var(--muted)">
      <?php $sz = $row['size_on_disk'] ?? null;
            echo ($sz && $sz > 0) ? number_format($sz / (1024**3), 2) . ' GiB' : '—'; ?>
    </div>

    <div class="sc-tbl-cell sc-tbl-moved" style="font-size:.78rem;color:var(--muted)">
      <?= $row['moved_at'] ? date('Y-m-d', $row['moved_at']) : '—' ?>
    </div>

    <div class="sc-tbl-cell sc-tbl-reloc">
      <?php if ($is_pinned): ?>
        <span style="color:var(--muted);font-size:.78rem">Pinned</span>
      <?php else: ?>
        <form method="POST" action="tracked.php" id="trf-<?= $row['id'] ?>" style="display:inline">
          <input type="hidden" name="action" value="set_relocate">
          <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
          <input type="date" name="relocate_date"
                 value="<?= htmlspecialchars($relocate_val) ?>"
                 style="width:108px;font-size:.7rem;padding:.15rem .25rem;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);color:<?= $is_expired ? 'var(--red)' : 'var(--text)' ?>"
                 onchange="this.form.submit()"
                 title="Relocate date">
        </form>
      <?php endif; ?>
    </div>

    <div class="sc-tbl-cell sc-tbl-source">
      <span class="badge <?= $row['service']==='sonarr' ? 'badge-muted' : '' ?>"
        <?= $row['service']==='radarr' ? 'style="background:rgba(160,90,219,.15);color:#a05adb"' : '' ?>>
        <?= htmlspecialchars($svc_label) ?>
      </span>
    </div>

    <div class="sc-tbl-cell sc-tbl-actions">
      <div style="display:flex;gap:.3rem;align-items:center">

        <!-- Move to other location -->
        <?php if ($on_fast): ?>
        <form method="POST" action="tracked.php" style="display:inline">
          <input type="hidden" name="action" value="move_to_slow">
          <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
          <button type="submit" class="btn-move to-slow" title="Queue move to slow storage">← Slow</button>
        </form>
        <?php else: ?>
        <form method="POST" action="tracked.php" style="display:inline">
          <input type="hidden" name="action" value="move_to_fast">
          <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
          <button type="submit" class="btn-move to-fast" title="Queue move to fast storage">→ Fast</button>
        </form>
        <?php endif; ?>

        <!-- Pin / Unpin (icon only) -->
        <?php if ($is_pinned): ?>
        <form method="POST" action="tracked.php" style="display:inline">
          <input type="hidden" name="action" value="unpin">
          <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
          <button type="submit" class="btn" style="padding:.2rem .35rem;font-size:.75rem" title="Unpin — re-enable auto-relocation">📍</button>
        </form>
        <?php else: ?>
        <form method="POST" action="tracked.php" style="display:inline">
          <input type="hidden" name="action" value="pin">
          <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
          <button type="submit" class="btn" style="padding:.2rem .35rem;font-size:.75rem" title="Pin — prevent auto-relocation">📌</button>
        </form>
        <?php endif; ?>

        <!-- Delete -->
        <form method="POST" action="tracked.php" style="display:inline"
              onsubmit="return confirm('Remove this entry?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
          <button type="submit" class="btn btn-danger" style="padding:.2rem .35rem;font-size:.75rem" title="Remove">✕</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

</div>
<?php endif; ?>

<!-- ── Legend ── -->
<div class="legend-box">
  <div class="legend-hdr">Legend</div>
  <div class="legend-items">
    <div class="legend-item">
      <span class="dot dot-green"></span>
      <span>Green dot — on <strong style="color:var(--green)">fast</strong> storage</span>
    </div>
    <div class="legend-item">
      <span class="dot dot-muted"></span>
      <span>Grey dot — on <strong style="color:var(--muted)">slow</strong> storage</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-green" style="font-size:.65rem">2025-01-15</span>
      <span>Scheduled relocate date</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-amber" style="font-size:.65rem">Pinned</span>
      <span>Auto-relocation disabled — click 📍 Unpin to re-enable</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-red" style="font-size:.65rem">Expired</span>
      <span>Eligible to move back to slow storage</span>
    </div>
  </div>
</div>

<script>
(function() {
  var currentSort   = localStorage.getItem('trk_sort')   || 'title';
  var currentFilter = localStorage.getItem('trk_filter') || 'all';
  var sortAsc       = localStorage.getItem('trk_sortasc') !== 'false';

  var searchInput = document.getElementById('search-input');
  var visCountEl  = document.getElementById('visible-count');

  function rows() { return [...document.querySelectorAll('#tracked-table .sc-tbl-row')]; }

  function isVisible(el) {
    var q       = (searchInput ? searchInput.value : '').toLowerCase().trim();
    var title   = (el.dataset.title || '').toLowerCase();
    var loc     = el.dataset.location || '';
    var type    = el.dataset.type || '';
    var pinned  = el.dataset.pinned === '1';
    var expired = el.dataset.expired === '1';

    if (q && !title.includes(q)) return false;
    if (currentFilter === 'fast'    && loc !== 'fast')   return false;
    if (currentFilter === 'slow'    && loc === 'fast')   return false;
    if (currentFilter === 'pinned'  && !pinned)          return false;
    if (currentFilter === 'expired' && !expired)         return false;
    if (currentFilter === 'show'    && type !== 'show')  return false;
    if (currentFilter === 'movie'   && type !== 'movie') return false;
    return true;
  }

  function cmp(a, b) {
    var av, bv;
    if (currentSort === 'title' || currentSort === 'location' || currentSort === 'service') {
      av = (a.dataset[currentSort] || ''); bv = (b.dataset[currentSort] || '');
      return sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
    }
    av = parseInt(a.dataset[currentSort] || '0');
    bv = parseInt(b.dataset[currentSort] || '0');
    return sortAsc ? bv - av : av - bv;
  }

  function applyVisibility() {
    var n = 0;
    rows().forEach(function(r) {
      var v = isVisible(r);
      r.style.display = v ? 'flex' : 'none';
      if (v) n++;
    });
    if (visCountEl) visCountEl.textContent = n;
  }

  function applyAll() {
    var rs = rows();
    var tbl = document.getElementById('tracked-table');
    rs.sort(cmp).forEach(function(r) { tbl.appendChild(r); });
    applyVisibility();
  }

  function updateSortHeaders() {
    document.querySelectorAll('#tracked-table .sort-col').forEach(function(th) {
      var isActive = th.dataset.sort === currentSort;
      th.classList.toggle('sort-active', isActive);
      var arrow = th.querySelector('.th-arrow');
      if (arrow) arrow.textContent = sortAsc ? '↑' : '↓';
    });
  }

  window.doSort = function(key) {
    if (currentSort === key) { sortAsc = !sortAsc; }
    else { currentSort = key; sortAsc = (key === 'title' || key === 'location' || key === 'service'); }
    localStorage.setItem('trk_sort', currentSort);
    localStorage.setItem('trk_sortasc', sortAsc);
    updateSortHeaders();
    // Sync sort menu
    document.querySelectorAll('#sort-menu .sc-menu-item').forEach(function(i) {
      i.classList.toggle('selected', i.dataset.sort === currentSort);
    });
    closeAllMenus();
    applyAll();
  };

  window.setSort = function(key, btn) {
    doSort(key);
  };

  window.setFilter = function(key, btn) {
    currentFilter = key;
    localStorage.setItem('trk_filter', key);
    document.querySelectorAll('#filter-menu .sc-menu-item').forEach(function(i) {
      i.classList.toggle('selected', i.dataset.filter === key);
    });
    var allOn = (key === 'all');
    document.getElementById('btn-filter').classList.toggle('has-filter', !allOn);
    closeAllMenus();
    applyAll();
  };

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

  if (searchInput) {
    var _searchTimer = null;
    searchInput.addEventListener('input', function() {
      clearTimeout(_searchTimer);
      _searchTimer = setTimeout(applyVisibility, 180);
    });
  }

  // Init
  document.querySelectorAll('#sort-menu .sc-menu-item').forEach(function(i) {
    i.classList.toggle('selected', i.dataset.sort === currentSort);
  });
  document.querySelectorAll('#filter-menu .sc-menu-item').forEach(function(i) {
    i.classList.toggle('selected', i.dataset.filter === currentFilter);
  });
  var filterAllOn = (currentFilter === 'all');
  document.getElementById('btn-filter').classList.toggle('has-filter', !filterAllOn);
  updateSortHeaders();
  applyAll();
})();
</script>

<?php layout_end(); ?>
