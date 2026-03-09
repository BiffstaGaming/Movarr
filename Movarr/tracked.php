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

// Stats
$total = count($tracked);
$fast_count = $slow_count = $pinned_count = $expired_count = $tv_count = $movie_count = 0;
foreach ($tracked as $r) {
    if ($r['current_location'] === 'fast') $fast_count++; else $slow_count++;
    if ($r['relocate_after'] === null) $pinned_count++;
    elseif ($r['relocate_after'] < $now) $expired_count++;
    if ($r['media_type'] === 'show') $tv_count++; else $movie_count++;
}

// ── Styles ────────────────────────────────────────────────────────────────────
$extra_head = <<<'CSS'
<style>
/* ── Page toolbar ── */
.page-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  gap: .5rem; padding: .6rem 0 1rem; flex-wrap: wrap;
}
.page-toolbar-left  { display: flex; align-items: center; gap: .4rem; }
.page-toolbar-right { display: flex; align-items: center; gap: .25rem; margin-left: auto; }

.tb-search { position: relative; }
.tb-search svg { position:absolute;left:.55rem;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none; }
.tb-search input { padding-left:1.9rem;width:220px;font-size:.83rem;background:var(--surface);border-color:var(--border); }
.tb-search input:focus { border-color:var(--accent); }

.series-count { font-size:.82rem;color:var(--muted);padding:0 .25rem; }

.tb-sep { width:1px;height:22px;background:var(--border);margin:0 .2rem;flex-shrink:0; }
.tb-btn {
  display:flex;align-items:center;gap:.35rem;
  background:none;border:none;color:var(--muted);
  cursor:pointer;font-size:.82rem;font-weight:500;
  padding:.35rem .55rem;border-radius:var(--radius);
  transition:color .15s,background .15s; white-space:nowrap;
}
.tb-btn:hover  { color:var(--text);background:rgba(255,255,255,.06); }
.tb-btn.active { color:var(--accent); }
.tb-btn svg { flex-shrink:0; }

.tb-dropdown { position:relative; }
.tb-dropdown-menu {
  display:none;position:absolute;top:calc(100% + 4px);right:0;
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius);min-width:170px;z-index:200;
  box-shadow:0 6px 20px rgba(0,0,0,.4);overflow:hidden;
}
.tb-dropdown.open .tb-dropdown-menu { display:block; }
.tb-dropdown-item {
  display:flex;align-items:center;justify-content:space-between;
  padding:.55rem .85rem;font-size:.83rem;color:var(--muted);
  cursor:pointer;transition:background .1s,color .1s;
}
.tb-dropdown-item:hover  { background:rgba(255,255,255,.05);color:var(--text); }
.tb-dropdown-item.active { color:var(--accent); }
.tb-dropdown-item .check { font-size:.75rem; }

/* ── Poster grid ── */
.sc-grid {
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(162px,1fr));
  gap:5px;
}

/* ── Poster card ── */
.sc-card {
  background:var(--surface);border-radius:4px;overflow:hidden;
  transition:box-shadow 200ms ease-in,transform 200ms ease-in;
  cursor:default;position:relative;
}
.sc-card:hover {
  box-shadow:0 0 14px rgba(0,0,0,.65);transform:translateY(-2px);z-index:2;
}
.sc-poster {
  position:relative;width:100%;padding-top:150%;overflow:hidden;
  background-color:#1a1a2e;
}
.sc-poster-ph {
  position:absolute;inset:0;display:none;
  align-items:center;justify-content:center;font-size:3.5rem;
}
.sc-poster-ph.ph-show  { background:linear-gradient(160deg,#1a2540 0%,#0c1422 100%); }
.sc-poster-ph.ph-movie { background:linear-gradient(160deg,#28183a 0%,#100c1e 100%); }
.sc-overlay-title {
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  padding:8px;font-size:1rem;font-weight:600;color:rgba(255,255,255,.85);
  text-align:center;word-break:break-word;pointer-events:none;
}

/* Status triangle (top-right corner) */
.sc-tri { position:absolute;top:0;right:0;z-index:1;width:0;height:0;border-style:solid;border-width:0 22px 22px 0; }
.sc-tri-fast    { border-color:transparent var(--green) transparent transparent; }
.sc-tri-slow    { border-color:transparent #555 transparent transparent; }
.sc-tri-expired { border-color:transparent var(--red) transparent transparent; }
.sc-tri-pinned  { border-color:transparent var(--accent) transparent transparent; }

/* Hover controls */
.sc-controls {
  position:absolute;bottom:8px;left:8px;z-index:3;
  display:flex;gap:2px;align-items:center;
  background:rgba(20,22,38,.88);border-radius:4px;padding:3px 5px;
  opacity:0;transition:opacity 0;
}
.sc-card:hover .sc-controls { opacity:1;transition:opacity 200ms linear 150ms; }
.sc-ctrl {
  background:none;border:none;color:rgba(255,255,255,.7);
  cursor:pointer;font-size:.7rem;padding:2px 5px;border-radius:2px;
  white-space:nowrap;transition:color .1s,background .1s;
}
.sc-ctrl:hover { color:#fff;background:rgba(255,255,255,.12); }
.sc-ctrl-date {
  background:rgba(255,255,255,.07);border:none;color:rgba(255,255,255,.7);
  cursor:pointer;font-size:.68rem;padding:2px 4px;border-radius:2px;
  width:88px;outline:none;
}
.sc-ctrl-date:focus { background:rgba(255,255,255,.12);color:#fff; }
.sc-ctrl-danger:hover { color:var(--red);background:rgba(224,80,80,.15); }

/* Status bar */
.sc-bar { height:5px;width:100%;background:var(--surface2);position:relative;overflow:hidden; }
.sc-bar-fill { height:100%;transition:width .3s ease; }
.sc-bar-fill-green  { background:var(--green); }
.sc-bar-fill-muted  { background:#555; }
.sc-bar-fill-red    { background:var(--red); }
.sc-bar-fill-amber  { background:var(--accent); }

/* Card body */
.sc-card-body { padding:5px 6px 7px;background:var(--surface); }
.sc-title {
  font-size:.8rem;font-weight:600;text-align:center;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text);
}
.sc-meta {
  font-size:.68rem;color:var(--muted);text-align:center;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;
}

/* ── Table view ── */
#table-view { display:none; }

/* ── Legend ── */
.legend-box { margin-top:1.5rem;padding:.75rem 1rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius); }
.legend-hdr { font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--muted);margin-bottom:.55rem; }
.legend-items { display:flex;flex-wrap:wrap;gap:.35rem 1.5rem; }
.legend-item { display:flex;align-items:center;gap:.4rem;font-size:.75rem;color:var(--muted); }
.legend-tri { width:0;height:0;border-style:solid;border-width:0 12px 12px 0;flex-shrink:0; }

.sc-empty { grid-column:1/-1;text-align:center;padding:4rem 2rem;color:var(--muted);font-size:.9rem; }

@media(max-width:700px) {
  .sc-grid { grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:4px; }
  .tb-search input { width:140px; }
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

<!-- ── Toolbar ── -->
<div class="page-toolbar">
  <div class="page-toolbar-left">
    <div class="tb-search">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
        <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
      </svg>
      <input type="text" id="search-input" placeholder="Search…" autocomplete="off">
    </div>
    <span class="series-count"><strong id="visible-count"><?= $total ?></strong> of <?= $total ?></span>
  </div>

  <div class="page-toolbar-right">

    <!-- View: Posters -->
    <button class="tb-btn active" id="btn-poster" title="Poster view" onclick="setView('poster',this)">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M3 3h7v9H3zm11 0h7v9h-7zm-11 11h7v7H3zm11 0h7v7h-7z"/></svg>
      Posters
    </button>
    <!-- View: Table -->
    <button class="tb-btn" id="btn-table" title="Table view" onclick="setView('table',this)">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M3 5h2V3H3v2zm0 4h2V7H3v2zm0 4h2v-2H3v2zm4-8h14V3H7v2zm0 4h14V7H7v2zm0 4h14v-2H7v2z"/></svg>
      Table
    </button>

    <div class="tb-sep"></div>

    <!-- Sort dropdown -->
    <div class="tb-dropdown" id="sort-dd">
      <button class="tb-btn" onclick="toggleDropdown('sort-dd')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M3 18h6v-2H3v2zM3 6v2h18V6H3zm0 7h12v-2H3v2z"/></svg>
        Sort: <span id="sort-label">Title</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
      </button>
      <div class="tb-dropdown-menu">
        <div class="tb-dropdown-item active" data-sort="title"    onclick="setSort(this,'title','Title')">Title <span class="check">✓</span></div>
        <div class="tb-dropdown-item"        data-sort="moved"    onclick="setSort(this,'moved','Moved Date')">Moved Date</div>
        <div class="tb-dropdown-item"        data-sort="relocate" onclick="setSort(this,'relocate','Relocate Date')">Relocate Date</div>
        <div class="tb-dropdown-item"        data-sort="location" onclick="setSort(this,'location','Location')">Location</div>
        <div class="tb-dropdown-item"        data-sort="service"  onclick="setSort(this,'service','Service')">Service</div>
      </div>
    </div>

    <!-- Filter dropdown -->
    <div class="tb-dropdown" id="filter-dd">
      <button class="tb-btn" onclick="toggleDropdown('filter-dd')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M4.25 5.61C6.27 8.2 10 13 10 13v6c0 .55.45 1 1 1h2c.55 0 1-.45 1-1v-6s3.72-4.8 5.74-7.39A.998.998 0 0 0 18.95 4H5.04a1 1 0 0 0-.79 1.61z"/></svg>
        <span id="filter-label">All Media</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
      </button>
      <div class="tb-dropdown-menu">
        <div class="tb-dropdown-item active" data-filter="all"     onclick="setFilter(this,'all','All Media')">All Media <span class="check">✓</span></div>
        <div class="tb-dropdown-item"        data-filter="fast"    onclick="setFilter(this,'fast','On Fast Storage')">On Fast Storage</div>
        <div class="tb-dropdown-item"        data-filter="slow"    onclick="setFilter(this,'slow','On Slow Storage')">On Slow Storage</div>
        <div class="tb-dropdown-item"        data-filter="pinned"  onclick="setFilter(this,'pinned','Pinned')">Pinned</div>
        <div class="tb-dropdown-item"        data-filter="expired" onclick="setFilter(this,'expired','Expired')">Expired</div>
        <div class="tb-dropdown-item"        data-filter="show"    onclick="setFilter(this,'show','TV Shows')">TV Shows</div>
        <div class="tb-dropdown-item"        data-filter="movie"   onclick="setFilter(this,'movie','Movies')">Movies</div>
      </div>
    </div>

  </div>
</div>

<!-- ── POSTER GRID ── -->
<div id="poster-view" class="sc-grid">

<?php if (empty($tracked)): ?>
  <div class="sc-empty">Nothing tracked yet.<br>Moves will appear here once the mover runs.</div>
<?php endif; ?>

<?php foreach ($tracked as $row):
  $on_fast    = $row['current_location'] === 'fast';
  $is_pinned  = $row['relocate_after'] === null;
  $is_expired = !$is_pinned && (int)$row['relocate_after'] < $now;
  $is_show    = $row['media_type'] === 'show';
  $svc_label  = $row['service'] === 'sonarr' ? 'Sonarr' : 'Radarr';
  $id_label   = $row['service'] === 'sonarr' ? 'TVDB' : 'TMDB';
  $map_name   = $map_names[$row['mapping_id']] ?? $row['mapping_id'];

  // Triangle class: pinned > expired > location
  if ($is_pinned)       $tri = 'sc-tri-pinned';
  elseif ($is_expired)  $tri = 'sc-tri-expired';
  elseif ($on_fast)     $tri = 'sc-tri-fast';
  else                  $tri = 'sc-tri-slow';

  // Triangle title
  if ($is_pinned)       $tri_title = 'Pinned — auto-relocation disabled';
  elseif ($is_expired)  $tri_title = 'Expired — eligible to move back to slow';
  elseif ($on_fast)     $tri_title = 'On fast storage';
  else                  $tri_title = 'On slow storage';

  // Status bar
  if ($is_expired)      $bar = 'sc-bar-fill-red';
  elseif ($on_fast)     $bar = 'sc-bar-fill-green';
  else                  $bar = 'sc-bar-fill-muted';

  // Days left label
  if ($is_pinned) {
      $rl = 'Pinned';
  } elseif ($is_expired) {
      $rl = 'Expired';
  } else {
      $diff = (int)$row['relocate_after'] - $now;
      $rl = ceil($diff / 86400) . 'd left';
  }

  $relocate_val = $row['relocate_after'] ? date('Y-m-d', $row['relocate_after']) : '';
?>
<div class="sc-card"
  data-id="<?= $row['id'] ?>"
  data-title="<?= htmlspecialchars(strtolower($row['title'] ?? '')) ?>"
  data-location="<?= htmlspecialchars($row['current_location']) ?>"
  data-type="<?= htmlspecialchars($row['media_type']) ?>"
  data-service="<?= htmlspecialchars($row['service']) ?>"
  data-moved="<?= (int)$row['moved_at'] ?>"
  data-relocate="<?= (int)$row['relocate_after'] ?>"
  data-pinned="<?= $is_pinned ? '1' : '0' ?>"
  data-expired="<?= $is_expired ? '1' : '0' ?>">

  <div class="sc-poster">
    <div class="sc-poster-ph <?= $is_show ? 'ph-show' : 'ph-movie' ?>" style="display:flex">
      <?= $is_show ? '📺' : '🎬' ?>
      <div class="sc-overlay-title"><?= htmlspecialchars($row['title'] ?: '—') ?></div>
    </div>

    <!-- Status triangle -->
    <div class="sc-tri <?= $tri ?>" title="<?= htmlspecialchars($tri_title) ?>"></div>

    <!-- Hover controls -->
    <div class="sc-controls">
      <?php if (!$is_pinned): ?>
      <form method="POST" action="tracked.php" style="display:contents">
        <input type="hidden" name="action" value="pin">
        <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
        <button type="submit" class="sc-ctrl" title="Pin — prevent auto-relocation">📌 Pin</button>
      </form>
      <?php endif; ?>
      <form method="POST" action="tracked.php" style="display:contents" id="rf-<?= $row['id'] ?>">
        <input type="hidden" name="action" value="set_relocate">
        <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
        <input type="date" name="relocate_date" class="sc-ctrl-date"
          value="<?= htmlspecialchars($relocate_val) ?>"
          onchange="document.getElementById('rf-<?= $row['id'] ?>').submit()"
          title="Set relocate date">
      </form>
      <form method="POST" action="tracked.php" style="display:contents"
            onsubmit="return confirm('Remove this entry from tracking?')">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
        <button type="submit" class="sc-ctrl sc-ctrl-danger" title="Remove">✕ Remove</button>
      </form>
    </div>
  </div>

  <!-- Status bar: full width, colour indicates location -->
  <div class="sc-bar" title="<?= htmlspecialchars($on_fast ? 'Fast storage' : 'Slow storage') ?>">
    <div class="sc-bar-fill <?= $bar ?>" style="width:100%"></div>
  </div>

  <div class="sc-card-body">
    <div class="sc-title" title="<?= htmlspecialchars($row['title'] ?: '—') ?>"><?= htmlspecialchars($row['title'] ?: '—') ?></div>
    <div class="sc-meta">
      <?= htmlspecialchars($on_fast ? '→ Fast' : '← Slow') ?> &nbsp;·&nbsp; <?= htmlspecialchars($rl) ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

</div><!-- #poster-view -->

<!-- ── TABLE VIEW ── -->
<div id="table-view">
  <?php if (empty($tracked)): ?>
    <div class="empty">Nothing tracked yet. Moves will appear here once the mover runs.</div>
  <?php else: ?>
  <div class="card">
    <table>
      <thead>
        <tr>
          <th></th><th>Title</th><th>ID</th><th>Mapping</th>
          <th>Location</th><th>Moved</th><th>Relocates</th><th>Source</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tracked as $row):
        $on_fast    = $row['current_location'] === 'fast';
        $is_pinned  = $row['relocate_after'] === null;
        $is_expired = !$is_pinned && (int)$row['relocate_after'] < $now;
        $id_label   = $row['service'] === 'sonarr' ? 'TVDB' : 'TMDB';
        $map_name   = $map_names[$row['mapping_id']] ?? $row['mapping_id'];
        $relocate_val = $row['relocate_after'] ? date('Y-m-d', $row['relocate_after']) : '';
        if ($is_pinned)       $rl_label = 'Pinned';       $rl_class = 'badge-amber';
        if ($is_expired)     { $rl_label = 'Expired';     $rl_class = 'badge-red'; }
        if (!$is_pinned && !$is_expired) {
            $diff = (int)$row['relocate_after'] - $now;
            $rl_label = ceil($diff / 86400) . 'd left';
            $rl_class = 'badge-green';
        }
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
        <td style="width:20px"><span class="dot <?= $on_fast ? 'dot-green' : 'dot-muted' ?>"></span></td>
        <td>
          <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($row['title'] ?: '—') ?></div>
          <div style="font-size:.7rem;color:var(--muted)"><?= htmlspecialchars($row['folder'] ?: '') ?></div>
        </td>
        <td style="font-size:.75rem;color:var(--muted);font-family:monospace">
          <span style="font-size:.68rem"><?= $id_label ?></span><br><?= (int)$row['external_id'] ?>
        </td>
        <td style="font-size:.78rem;color:var(--muted)"><?= htmlspecialchars($map_name) ?></td>
        <td>
          <?php if ($on_fast): ?>
            <span style="color:var(--green);font-weight:700;font-size:.82rem">→ Fast</span>
          <?php else: ?>
            <span style="color:var(--muted);font-weight:700;font-size:.82rem">← Slow</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.78rem;color:var(--muted)"><?= $row['moved_at'] ? date('Y-m-d',$row['moved_at']) : '—' ?></td>
        <td><span class="badge <?= $rl_class ?>"><?= htmlspecialchars($rl_label) ?></span></td>
        <td><span class="badge <?= $row['source']==='manual'?'badge-amber':'badge-muted' ?>"><?= htmlspecialchars($row['source']) ?></span></td>
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
                     value="<?= htmlspecialchars($relocate_val) ?>"
                     style="width:108px;font-size:.72rem;padding:.2rem .35rem"
                     onchange="document.getElementById('trf-<?= $row['id'] ?>').submit()">
            </form>
            <form method="POST" action="tracked.php" style="display:inline"
                  onsubmit="return confirm('Remove this entry?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
              <button type="submit" class="btn btn-danger" style="padding:.2rem .45rem;font-size:.7rem">✕</button>
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
  <div class="legend-hdr">Legend</div>
  <div class="legend-items">
    <div class="legend-item">
      <div class="legend-tri" style="border-color:transparent var(--green) transparent transparent"></div>
      <span>Green triangle — on <strong style="color:var(--green)">fast</strong> storage</span>
    </div>
    <div class="legend-item">
      <div class="legend-tri" style="border-color:transparent #555 transparent transparent"></div>
      <span>Grey triangle — on <strong style="color:var(--muted)">slow</strong> storage</span>
    </div>
    <div class="legend-item">
      <div class="legend-tri" style="border-color:transparent var(--accent) transparent transparent"></div>
      <span>Amber triangle — <strong style="color:var(--accent)">pinned</strong>, auto-relocation disabled</span>
    </div>
    <div class="legend-item">
      <div class="legend-tri" style="border-color:transparent var(--red) transparent transparent"></div>
      <span>Red triangle — <strong style="color:var(--red)">expired</strong>, eligible to move back to slow</span>
    </div>
    <div class="legend-item">
      <span style="display:inline-block;width:28px;height:5px;background:var(--green);border-radius:2px"></span>
      <span>Green bar — currently on fast storage</span>
    </div>
    <div class="legend-item">
      <span style="display:inline-block;width:28px;height:5px;background:#555;border-radius:2px"></span>
      <span>Grey bar — currently on slow storage</span>
    </div>
    <div class="legend-item">
      <span style="display:inline-block;width:28px;height:5px;background:var(--red);border-radius:2px"></span>
      <span>Red bar — expired, ready to relocate</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-amber" style="font-size:.65rem">Manual</span>
      <span>Manually triggered move</span>
    </div>
    <div class="legend-item">
      <span class="badge badge-muted" style="font-size:.65rem">Auto</span>
      <span>Automatically moved by the mover service</span>
    </div>
  </div>
</div>

<script>
(function() {
  var currentSort   = 'title';
  var currentFilter = 'all';
  var sortAsc       = true;
  var currentView   = 'poster';
  var now           = Math.floor(Date.now() / 1000);

  var searchInput = document.getElementById('search-input');
  var visCountEl  = document.getElementById('visible-count');
  var posterView  = document.getElementById('poster-view');
  var tableView   = document.getElementById('table-view');

  function cards()     { return [...posterView.querySelectorAll('.sc-card')]; }
  function tableRows() { return [...tableView.querySelectorAll('tr[data-id]')]; }

  function isVisible(el) {
    var q       = (searchInput.value || '').toLowerCase().trim();
    var title   = (el.dataset.title || '').toLowerCase();
    var loc     = el.dataset.location || '';
    var type    = el.dataset.type || '';
    var pinned  = el.dataset.pinned === '1';
    var expired = el.dataset.expired === '1';

    if (q && !title.includes(q)) return false;
    if (currentFilter === 'fast'    && loc !== 'fast')   return false;
    if (currentFilter === 'slow'    && loc === 'fast')    return false;
    if (currentFilter === 'pinned'  && !pinned)           return false;
    if (currentFilter === 'expired' && !expired)          return false;
    if (currentFilter === 'show'    && type !== 'show')   return false;
    if (currentFilter === 'movie'   && type !== 'movie')  return false;
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

  function applyAll() {
    var cs = cards(), trs = tableRows();

    // Sort & reorder cards
    cs.sort(cmp).forEach(function(c) { posterView.appendChild(c); });

    // Sort & reorder table rows
    var tbody = tableView.querySelector('tbody');
    if (tbody) trs.sort(cmp).forEach(function(r) { tbody.appendChild(r); });

    // Show/hide
    var n = 0;
    cs.forEach(function(c) {
      var v = isVisible(c); c.style.display = v ? '' : 'none'; if (v) n++;
    });
    trs.forEach(function(r) { r.style.display = isVisible(r) ? '' : 'none'; });

    if (visCountEl) visCountEl.textContent = n;
  }

  searchInput.addEventListener('input', applyAll);

  window.setView = function(v, btn) {
    currentView = v;
    posterView.style.display = v === 'poster' ? '' : 'none';
    tableView.style.display  = v === 'table'  ? 'block' : 'none';
    document.querySelectorAll('.tb-btn[id^="btn-"]').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
  };

  window.toggleDropdown = function(id) {
    var dd = document.getElementById(id);
    var wasOpen = dd.classList.contains('open');
    document.querySelectorAll('.tb-dropdown').forEach(function(d) { d.classList.remove('open'); });
    if (!wasOpen) dd.classList.add('open');
  };

  document.addEventListener('click', function(e) {
    if (!e.target.closest('.tb-dropdown')) {
      document.querySelectorAll('.tb-dropdown').forEach(function(d) { d.classList.remove('open'); });
    }
  });

  window.setSort = function(el, key, label) {
    if (currentSort === key) { sortAsc = !sortAsc; }
    else { currentSort = key; sortAsc = (key === 'title' || key === 'location' || key === 'service'); }
    document.querySelectorAll('#sort-dd .tb-dropdown-item').forEach(function(i) {
      i.classList.remove('active');
      var ck = i.querySelector('.check'); if (ck) ck.textContent = '';
    });
    el.classList.add('active');
    if (!el.querySelector('.check')) { var s = document.createElement('span'); s.className='check'; el.appendChild(s); }
    el.querySelector('.check').textContent = '✓';
    document.getElementById('sort-label').textContent = label;
    document.getElementById('sort-dd').classList.remove('open');
    applyAll();
  };

  window.setFilter = function(el, key, label) {
    currentFilter = key;
    document.querySelectorAll('#filter-dd .tb-dropdown-item').forEach(function(i) {
      i.classList.remove('active');
      var ck = i.querySelector('.check'); if (ck) ck.textContent = '';
    });
    el.classList.add('active');
    if (!el.querySelector('.check')) { var s = document.createElement('span'); s.className='check'; el.appendChild(s); }
    el.querySelector('.check').textContent = '✓';
    document.getElementById('filter-label').textContent = label;
    document.getElementById('filter-dd').classList.remove('open');
    applyAll();
  };

  applyAll();
})();
</script>

<?php layout_end(); ?>
