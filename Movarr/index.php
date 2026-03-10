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
require_once __DIR__ . '/includes/sync.php';

$s      = load_settings();
$errors = [];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    // Async sync triggers — spawn background process, return immediately
    if ($post_action === 'sync_library' || $post_action === 'sync_tautulli') {
        $type = $post_action === 'sync_tautulli' ? 'tautulli' : 'library';
        $cmd  = 'php ' . escapeshellarg(__DIR__ . '/sync.php') . ' ' . escapeshellarg($type)
              . ' >> /config/cron.log 2>&1';
        exec('nohup ' . $cmd . ' &');
        header('Content-Type: application/json');
        echo json_encode(['queued' => true, 'type' => $type]);
        exit;
    }

    if (in_array($post_action, ['track', 'untrack', 'move_to_fast', 'move_to_slow'])) {
        $ajax = !empty($_POST['_ajax']);
        $ajax_error = null;
        try {
            $adb        = db_connect();
            $ext_id     = (int)($_POST['external_id'] ?? 0);
            $service    = in_array($_POST['service'] ?? '', ['sonarr','radarr']) ? $_POST['service'] : 'sonarr';
            $mapping_id = trim($_POST['mapping_id'] ?? '');
            $a_title    = trim($_POST['title'] ?? '');
            $location   = trim($_POST['current_location'] ?? 'unknown');
            $folder     = trim($_POST['folder'] ?? '');
            $media_type   = trim($_POST['media_type'] ?? 'show');
            $size_on_disk = (int)($_POST['size_on_disk'] ?? 0);

            if ($post_action === 'untrack') {
                $track_id = (int)($_POST['track_id'] ?? 0);
                if ($track_id) db_delete_tracked($adb, $track_id);
            } elseif ($ext_id && $mapping_id) {
                if ($post_action === 'track') {
                    $adb->prepare(
                        "INSERT OR IGNORE INTO tracked_media
                         (media_type,title,external_id,service,mapping_id,folder,current_location,size_on_disk,source,created_at,updated_at)
                         VALUES (?,?,?,?,?,?,?,?,'manual',?,?)"
                    )->execute([$media_type,$a_title,$ext_id,$service,$mapping_id,$folder,$location,$size_on_disk,time(),time()]);
                } else {
                    $direction = $post_action === 'move_to_fast' ? 'to_fast' : 'to_slow';
                    db_queue_move($adb, $ext_id, $service, $mapping_id, $direction, 'Queued from dashboard', $a_title);
                    file_put_contents(manual_trigger_file(), date('c'));
                    $adb->prepare(
                        "INSERT OR IGNORE INTO tracked_media
                         (media_type,title,external_id,service,mapping_id,folder,current_location,size_on_disk,source,created_at,updated_at)
                         VALUES (?,?,?,?,?,?,?,?,'manual',?,?)"
                    )->execute([$media_type,$a_title,$ext_id,$service,$mapping_id,$folder,$location,$size_on_disk,time(),time()]);
                }
            }
        } catch (Exception $e) {
            $ajax_error = $e->getMessage();
            if (!$ajax) $errors[] = 'Action failed: ' . $e->getMessage();
        }

        if ($ajax) {
            // For move actions the actual file move is queued (not instant), so we
            // show the intended destination immediately for responsive feedback.
            $intended_loc = match($post_action) {
                'move_to_fast' => 'fast',
                'move_to_slow' => 'slow',
                default        => $location,
            };
            // Re-fetch the updated tracked state for this item
            $tr_row = null;
            if (!$ajax_error && isset($adb)) {
                $stmt = $adb->prepare("
                    SELECT id, current_location, moved_at, relocate_after, source
                    FROM tracked_media
                    WHERE external_id=? AND service=? AND mapping_id=?
                    LIMIT 1
                ");
                $stmt->execute([$ext_id, $service, $mapping_id]);
                $tr_row = $stmt->fetch() ?: null;
            }
            $action_arr = [
                'ext_id'      => $ext_id,
                'service'     => $service,
                'mapping_id'  => $mapping_id,
                'folder'      => $folder,
                'location'    => $intended_loc,
                'media_type'  => $media_type,
                'size_on_disk'=> $size_on_disk,
            ];
            $tr_arr = $tr_row ? [
                'id'               => (int)$tr_row['id'],
                'current_location' => $tr_row['current_location'],
                'moved_at'         => $tr_row['moved_at'],
                'relocate_after'   => $tr_row['relocate_after'],
                'source'           => $tr_row['source'],
            ] : null;
            $wrap_cls = trim($_POST['wrap_cls'] ?? 'dash-row-actions');
            header('Content-Type: application/json');
            echo json_encode([
                'ok'           => $ajax_error === null,
                'error'        => $ajax_error,
                'buttons_html' => render_dash_action_btns($action_arr, $tr_arr, $a_title, $wrap_cls),
                'location'     => $intended_loc,
                'tracked'      => $tr_arr ? '1' : '0',
            ]);
            exit;
        }

        header('Location: index.php');
        exit;
    }
}

// ── Sync status endpoint (polled by JS after queueing a sync) ─────────────────
if (($_GET['action'] ?? '') === 'sync_status') {
    header('Content-Type: application/json');
    $state = read_sync_state();
    echo json_encode([
        'library_synced_at'  => (int)($state['library_synced_at']  ?? 0),
        'tautulli_synced_at' => (int)($state['tautulli_synced_at'] ?? 0),
    ]);
    exit;
}

// ── Load library from DB ──────────────────────────────────────────────────────
$db        = db_connect();
$lib_count = (int)$db->query("SELECT COUNT(*) FROM media_library")->fetchColumn();

// Sync state (library + tautulli last-synced timestamps)
$_sync_state        = read_sync_state();
$last_lib_synced    = (int)($_sync_state['library_synced_at']  ?? 0);
$last_tau_synced    = (int)($_sync_state['tautulli_synced_at'] ?? 0);

$library_rows = $lib_count ? $db->query("
    SELECT ml.*,
           tm.id               AS track_id,
           tm.current_location AS tracked_location,
           tm.moved_at,
           tm.relocate_after,
           tm.source           AS track_source
    FROM media_library ml
    LEFT JOIN tracked_media tm
           ON tm.external_id = ml.external_id
          AND tm.service      = ml.service
          AND tm.mapping_id   = ml.mapping_id
    ORDER BY ml.last_watched_at IS NULL ASC,
             ml.last_watched_at DESC,
             ml.title ASC
")->fetchAll() : [];

$tvShows = array_values(array_filter($library_rows, fn($r) => $r['service'] === 'sonarr'));
$movies  = array_values(array_filter($library_rows, fn($r) => $r['service'] === 'radarr'));

$totalShows  = count($tvShows);
$totalMovies = count($movies);
$totalItems  = $totalShows + $totalMovies;

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Build an $action array from a media_library row (for render_dash_action_btns). */
function row_to_action(array $row): ?array {
    if (empty($row['mapping_id'])) return null;
    return [
        'ext_id'      => (int)$row['external_id'],
        'service'     => $row['service'],
        'mapping_id'  => $row['mapping_id'],
        'folder'      => $row['folder'],
        'location'    => $row['location'],
        'media_type'  => $row['service'] === 'sonarr' ? 'show' : 'movie',
        'size_on_disk'=> (int)$row['size_on_disk'],
    ];
}

/** Build a $tr (tracked) array from a media_library row with JOIN fields. */
function row_to_tracked(array $row): ?array {
    if (empty($row['track_id'])) return null;
    return [
        'id'               => (int)$row['track_id'],
        'current_location' => $row['tracked_location'] ?? 'unknown',
        'moved_at'         => $row['moved_at'],
        'relocate_after'   => $row['relocate_after'],
        'source'           => $row['track_source'] ?? '',
    ];
}

function fmt_movedate(?array $tr): string {
    if (!$tr || !$tr['moved_at'] || $tr['relocate_after'] === null) return '—';
    if ($tr['relocate_after'] < time()) return '—';
    return date('Y-m-d', $tr['relocate_after']);
}

function time_ago(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 3600)  return round($diff/60).'m ago';
    if ($diff < 86400) return round($diff/3600).'h ago';
    return round($diff/86400).'d ago';
}

function fmt_watched(?int $ts): string {
    if (!$ts) return 'Never';
    return time_ago($ts);
}

function fmt_bytes(int $bytes): string {
    if ($bytes <= 0) return '—';
    $u = ['B','KB','MB','GB','TB']; $i = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return round($bytes, 1) . ' ' . $u[$i];
}

function render_dash_action_btns(?array $action, ?array $tr, string $title, string $wrap_cls = 'dash-card-actions'): string {
    if (!$action || empty($action['ext_id'])) return '';
    $loc     = $tr ? ($tr['current_location'] ?? 'unknown') : $action['location'];
    $compact = str_contains($wrap_cls, 'row');
    $base    = ' data-ext-id="'.htmlspecialchars((string)$action['ext_id']).'"'
             . ' data-service="'.htmlspecialchars($action['service']).'"'
             . ' data-mapping-id="'.htmlspecialchars($action['mapping_id']).'"'
             . ' data-title="'.htmlspecialchars($title, ENT_QUOTES).'"'
             . ' data-folder="'.htmlspecialchars($action['folder'], ENT_QUOTES).'"'
             . ' data-location="'.htmlspecialchars($action['location']).'"'
             . ' data-media-type="'.htmlspecialchars($action['media_type']).'"'
             . ' data-track-id="'.($tr ? (int)$tr['id'] : '').'"'
             . ' data-size-on-disk="'.(int)($action['size_on_disk'] ?? 0).'"'
             . ' onclick="submitDashAction(this)"';
    $out = '<div class="'.htmlspecialchars($wrap_cls).'">';
    if ($tr) {
        $out .= '<button class="dash-act-btn act-untrack" data-action="untrack"'.$base.'>'.($compact ? '−Trk' : 'Untrack').'</button>';
    } else {
        $out .= '<button class="dash-act-btn act-track" data-action="track"'.$base.'>Track</button>';
    }
    if ($loc !== 'fast') {
        $out .= '<button class="dash-act-btn act-fast" data-action="move_to_fast"'.$base.'>'.($compact ? '→Fast' : '→&nbsp;Fast').'</button>';
    }
    if ($loc !== 'slow') {
        $out .= '<button class="dash-act-btn act-slow" data-action="move_to_slow"'.$base.'>'.($compact ? '←Slow' : '←&nbsp;Slow').'</button>';
    }
    $out .= '</div>';
    return $out;
}

// ── Sync token (for webhook instructions) ─────────────────────────────────────
if (empty($s['sync_token'])) {
    $s['sync_token'] = bin2hex(random_bytes(16));
    save_settings($s);
}
$sync_token = $s['sync_token'];

// ── Extra CSS ─────────────────────────────────────────────────────────────────
$extra_head = <<<'CSS'
<script>
// Hide poster-view before render if a different view was saved — prevents flash
(function(){try{var v=localStorage.getItem('mv2_view');if(v&&v!=='poster')document.documentElement.classList.add('mv-no-poster');}catch(e){}}());
</script>
<style>
.mv-no-poster #poster-view { display: none; }
</style>
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
  height: 40px;
  background: rgba(255,255,255,.35);
  margin: 0 20px;
  flex-shrink: 0;
  align-self: center;
}

/* ── Sync button (wider to fit label) ── */
.sc-tb-btn.sc-tb-sync { width: 56px; }

/* ── Dropdown menus ── */
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
.sc-menu-item .mi-sortdir { visibility: hidden; flex-shrink: 0; color: var(--muted); }
.sc-menu-item.selected .mi-sortdir { visibility: visible; }
.sc-menu-sep { height: 1px; background: var(--border); margin: 4px 0; }
.sc-menu-lbl {
  padding: 8px 20px 4px;
  font-size: .65rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .09em; color: var(--muted);
}
.sc-filter-row {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 20px; cursor: pointer;
  font-size: .875rem; color: var(--muted);
  transition: background .1s;
  white-space: nowrap;
}
.sc-filter-row:hover { background: rgba(255,255,255,.06); color: var(--text); }
.sc-filter-row input[type="checkbox"] {
  accent-color: var(--accent); width: 14px; height: 14px;
  cursor: pointer; flex-shrink: 0;
}

/* ── Options Modal ── */
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
  width: 400px; max-width: 92vw;
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

/* ── Item count + sync info ── */
.sc-toolbar-left { gap: 0; }
.sc-item-count {
  display: flex; align-items: center;
  padding: 0 12px;
  font-size: .78rem; color: var(--muted); white-space: nowrap;
}
.sc-sync-info {
  display: flex; align-items: center;
  padding: 0 12px;
  font-size: .72rem; color: var(--muted); white-space: nowrap;
  border-left: 1px solid var(--border);
}
.sc-sync-info a { color: var(--muted); text-decoration: none; }
.sc-sync-info a:hover { color: var(--accent); }

/* ── Poster grid ── */
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
.sc-tri {
  position: absolute; top: 0; right: 0; z-index: 1;
  width: 0; height: 0; border-style: solid; border-width: 0 22px 22px 0;
}
.sc-tri-fast    { border-color: transparent var(--accent) transparent transparent; }
.sc-tri-watched { border-color: transparent var(--green)  transparent transparent; }

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
.ov-right { flex-shrink:0;text-align:right;min-width:60px; }

/* ── Sonarr-style flex table ── */
#tbl-view { display: none; }
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
.sc-tbl-title    { flex: 1 1 auto; min-width: 140px; }
.sc-tbl-type     { flex: 0 0 7%;   min-width: 52px; }
.sc-tbl-location { flex: 0 0 7%;   min-width: 54px; }
.sc-tbl-movedate { flex: 0 0 13%;  min-width: 100px; }
.sc-tbl-watched  { flex: 0 0 11%;  min-width: 90px; }
.sc-tbl-size     { flex: 0 0 8%;   min-width: 70px; text-align: right; }
.sc-tbl-actions  { flex: 0 0 155px; min-width: 140px; justify-content: flex-end; }
.sc-tbl-header .sort-col { cursor: pointer; user-select: none; }
.sc-tbl-header .sort-col:hover { color: var(--text); }
.sc-tbl-header .sort-col.sort-active { color: var(--accent); }
.th-arrow { visibility: hidden; margin-left: 3px; font-size: .68rem; }
.sort-active .th-arrow { visibility: visible; }
.th-info { font-style: normal; font-size: .8rem; color: var(--muted); margin-left: 2px; cursor: help; }
.storage-fast { color: var(--green); font-weight: 700; }
.storage-slow { color: var(--muted); font-weight: 700; }
.loc-fast     { color: var(--green); font-size: .78rem; font-weight: 700; }
.loc-slow     { color: var(--muted); font-size: .78rem; }
.loc-unmapped { color: rgba(255,255,255,.2); font-size: .78rem; }

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
.sc-no-library {
  text-align: center; padding: 4rem 2rem; color: var(--muted);
}
.sc-no-library strong { color: var(--text); font-size: 1.05rem; display: block; margin-bottom: .5rem; }

/* ── Dashboard action buttons ── */
.dash-card-actions {
  position: absolute; bottom: 0; left: 0; right: 0; z-index: 3;
  background: linear-gradient(transparent, rgba(0,0,0,.88));
  display: flex; gap: 3px; padding: 14px 5px 5px;
  opacity: 0; pointer-events: none; transition: opacity .15s;
}
.sc-card:hover .dash-card-actions { opacity: 1; pointer-events: all; }
.dash-act-btn {
  flex: 1; padding: 4px 3px;
  font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
  background: rgba(15,15,25,.7); border: 1px solid rgba(255,255,255,.15); border-radius: 3px;
  color: var(--text); cursor: pointer; white-space: nowrap; transition: background .1s;
}
.dash-act-btn:hover { background: rgba(60,60,80,.9); }
.dash-act-btn.act-fast    { color: var(--green); border-color: var(--green); }
.dash-act-btn.act-slow    { color: var(--muted); }
.dash-act-btn.act-track   { color: var(--accent); border-color: var(--accent); }
.dash-act-btn.act-untrack { color: var(--red); border-color: var(--red); }
.dash-row-actions { display: flex; gap: 3px; align-items: center; flex-shrink: 0; margin-left: .5rem; }
.dash-row-actions .dash-act-btn { flex: none; padding: 2px 6px; font-size: .65rem; }
.sc-tbl-actions .dash-act-btn { flex: none; padding: 2px 5px; font-size: .65rem; }

@media(max-width:700px) {
  .sc-toolbar { margin: -1rem -1rem 1rem; }
  .sc-grid { grid-template-columns: repeat(auto-fill, minmax(110px,1fr)); gap:4px; }
  .sc-tb-btn { padding: 0 8px; min-width: 40px; font-size:.55rem; }
  .sc-sync-info { display: none; }
}
</style>
<style id="col-vis-style"></style>
CSS;

layout_start('Library', 'dashboard', $extra_head);
?>

<?php if ($errors): ?>
<div class="notice notice-error" style="margin-bottom:1rem">
  <?php foreach ($errors as $e): ?><div>⚠ <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Toolbar ── -->
<div class="sc-toolbar">

  <div class="sc-toolbar-left">
    <div class="sc-item-count">
      <span id="visible-count"><?= $totalItems ?></span>&nbsp;items
    </div>
    <div class="sc-sync-info" id="sync-info-lib">
      <?php if ($last_lib_synced): ?>
        Library: <?= time_ago($last_lib_synced) ?>
      <?php else: ?>
        Library: never synced
      <?php endif; ?>
    </div>
    <div class="sc-sync-info" id="sync-info-tau">
      <?php if ($last_tau_synced): ?>
        Watched: <?= time_ago($last_tau_synced) ?>
      <?php else: ?>
        Watched: never synced
      <?php endif; ?>
    </div>
  </div>

  <div class="sc-toolbar-right">

    <!-- Sync dropdown -->
    <div class="sc-menu-wrap">
      <button class="sc-tb-btn sc-tb-sync" onclick="toggleMenu('sync-menu')" title="Sync">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
          <path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46A7.93 7.93 0 0 0 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74A7.93 7.93 0 0 0 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/>
        </svg>
        <span class="lbl">Sync</span>
      </button>
      <div class="sc-menu" id="sync-menu" style="right:0;left:auto;">
        <div class="sc-menu-lbl">Sync</div>
        <button class="sc-menu-item" onclick="triggerSync('library')">
          Sync Library
          <span style="font-size:.7rem;color:var(--muted);margin-left:.5rem;">Sonarr &amp; Radarr</span>
        </button>
        <button class="sc-menu-item" onclick="triggerSync('tautulli')">
          Sync Watch History
          <span style="font-size:.7rem;color:var(--muted);margin-left:.5rem;">Tautulli</span>
        </button>
      </div>
    </div>

    <!-- Options (column visibility) -->
    <button class="sc-tb-btn" id="btn-options" onclick="openOptions()" title="Options">
      <svg id="options-icon" width="21" height="21" viewBox="0 0 24 24" fill="currentColor">
        <path d="M20 2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-9 11H5v-2h6v2zm0-4H5V7h6v2zm8 4h-6v-2h6v2zm0-4h-6V7h6v2z"/>
      </svg>
      <span class="lbl">Options</span>
    </button>

    <div class="sc-tb-sep"></div>

    <!-- View -->
    <div class="sc-menu-wrap">
      <button class="sc-tb-btn" onclick="toggleMenu('view-menu')" title="View">
        <svg width="21" height="21" viewBox="0 0 24 24" fill="currentColor">
          <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
        </svg>
        <span class="lbl">View</span>
      </button>
      <div class="sc-menu" id="view-menu">
        <button class="sc-menu-item selected" data-view="poster"   onclick="setView('poster',this)">Posters   <span class="mi-check">✓</span></button>
        <button class="sc-menu-item"          data-view="overview" onclick="setView('overview',this)">Overview <span class="mi-check">✓</span></button>
        <button class="sc-menu-item"          data-view="table"    onclick="setView('table',this)">Table     <span class="mi-check">✓</span></button>
      </div>
    </div>

    <!-- Sort -->
    <div class="sc-menu-wrap">
      <button class="sc-tb-btn" onclick="toggleMenu('sort-menu')" title="Sort">
        <svg width="21" height="21" viewBox="0 0 24 24" fill="currentColor">
          <path d="M3 18h6v-2H3v2zM3 6v2h18V6H3zm0 7h12v-2H3v2z"/>
        </svg>
        <span class="lbl">Sort</span>
      </button>
      <div class="sc-menu" id="sort-menu">
        <button class="sc-menu-item selected" data-sort="watched" onclick="setSort('watched',this)">
          Last Watched <span class="mi-sortdir" id="sd-watched">↓</span>
        </button>
        <button class="sc-menu-item" data-sort="title" onclick="setSort('title',this)">
          Title <span class="mi-sortdir" id="sd-title">↑</span>
        </button>
        <button class="sc-menu-item" data-sort="size" onclick="setSort('size',this)">
          Size <span class="mi-sortdir" id="sd-size">↓</span>
        </button>
        <button class="sc-menu-item" data-sort="movedate" onclick="setSort('movedate',this)">
          Move Date <span class="mi-sortdir" id="sd-movedate">↑</span>
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
        <div class="sc-menu-lbl">Type</div>
        <label class="sc-filter-row"><input type="checkbox" id="f-shows"   checked onchange="applyFilter()"> TV Shows</label>
        <label class="sc-filter-row"><input type="checkbox" id="f-movies"  checked onchange="applyFilter()"> Movies</label>
        <div class="sc-menu-sep"></div>
        <div class="sc-menu-lbl">Location</div>
        <label class="sc-filter-row"><input type="checkbox" id="f-fast"     checked onchange="applyFilter()"> Fast</label>
        <label class="sc-filter-row"><input type="checkbox" id="f-slow"     checked onchange="applyFilter()"> Slow</label>
        <label class="sc-filter-row"><input type="checkbox" id="f-unmapped" checked onchange="applyFilter()"> Unmapped</label>
        <div class="sc-menu-sep"></div>
        <div class="sc-menu-lbl">Tracked</div>
        <label class="sc-filter-row"><input type="checkbox" id="f-tracked"   checked onchange="applyFilter()"> Tracked</label>
        <label class="sc-filter-row"><input type="checkbox" id="f-untracked" checked onchange="applyFilter()"> Not Tracked</label>
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
        <label><input type="checkbox" id="col-location" checked onchange="saveCols()"> Location</label>
      </div>
      <div class="sc-modal-col">
        <label><input type="checkbox" id="col-movedate" checked onchange="saveCols()"> Expected Move Date</label>
      </div>
      <div class="sc-modal-col">
        <label><input type="checkbox" id="col-watched"  checked onchange="saveCols()"> Last Watched</label>
      </div>
      <div class="sc-modal-col">
        <label><input type="checkbox" id="col-size"     checked onchange="saveCols()"> Size</label>
      </div>
    </div>
    <div class="sc-modal-ftr">
      <button class="btn" onclick="closeOptions()">Close</button>
    </div>
  </div>
</div>

<?php if ($lib_count === 0): ?>
<!-- ── No library yet ── -->
<div class="sc-no-library">
  <strong>Library not synced yet</strong>
  Click <strong>Sync</strong> in the toolbar to fetch all titles from Sonarr &amp; Radarr.
  <br><br>
  <button type="button" class="btn btn-primary" onclick="triggerSync('library')">Sync Now</button>
</div>

<?php else: ?>

<!-- ── POSTER GRID ── -->
<div id="poster-view" class="sc-grid">
<?php if ($totalItems === 0): ?>
  <div class="sc-empty">No media found in library.</div>
<?php endif; ?>

<?php foreach ($tvShows as $row):
  $action  = row_to_action($row);
  $tr      = row_to_tracked($row);
  $loc     = $row['location'];
  $watched = (int)($row['last_watched_at'] ?? 0);
  $recently_watched = $watched && (time() - $watched < 30 * 86400);
?>
<div class="sc-card"
  data-title="<?= htmlspecialchars(strtolower($row['title'])) ?>"
  data-type="show"
  data-watched="<?= $watched ?>"
  data-size="<?= (int)$row['size_on_disk'] ?>"
  data-location="<?= htmlspecialchars($loc) ?>"
  data-tracked="<?= $tr ? '1' : '0' ?>"
  data-storage="<?= $loc === 'fast' ? '1' : '0' ?>"
  data-movedate="<?= ($tr && $tr['moved_at'] && $tr['relocate_after'] && $tr['relocate_after'] > time()) ? (int)$tr['relocate_after'] : 0 ?>">

  <div class="sc-poster">
    <?php if ($row['poster_url']): ?>
      <img src="<?= htmlspecialchars($row['poster_url']) ?>"
           alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="sc-poster-ph ph-show">📺</div>
    <?php else: ?>
      <div class="sc-poster-ph ph-show" style="display:flex">📺
        <div class="sc-overlay-title"><?= htmlspecialchars($row['title']) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($loc === 'fast'): ?>
      <div class="sc-tri sc-tri-fast" title="On fast storage"></div>
    <?php elseif ($recently_watched): ?>
      <div class="sc-tri sc-tri-watched" title="Recently watched"></div>
    <?php endif; ?>
    <?= render_dash_action_btns($action, $tr, $row['title']) ?>
  </div>
  <div class="sc-card-body">
    <div class="sc-title" title="<?= htmlspecialchars($row['title']) ?>"><?= htmlspecialchars($row['title']) ?></div>
    <div class="sc-meta"><?= $watched ? time_ago($watched) : 'Never watched' ?> &nbsp;·&nbsp; <?= fmt_bytes((int)$row['size_on_disk']) ?></div>
  </div>
</div>
<?php endforeach; ?>

<?php foreach ($movies as $row):
  $action  = row_to_action($row);
  $tr      = row_to_tracked($row);
  $loc     = $row['location'];
  $watched = (int)($row['last_watched_at'] ?? 0);
  $recently_watched = $watched && (time() - $watched < 30 * 86400);
?>
<div class="sc-card"
  data-title="<?= htmlspecialchars(strtolower($row['title'])) ?>"
  data-type="movie"
  data-watched="<?= $watched ?>"
  data-size="<?= (int)$row['size_on_disk'] ?>"
  data-location="<?= htmlspecialchars($loc) ?>"
  data-tracked="<?= $tr ? '1' : '0' ?>"
  data-storage="<?= $loc === 'fast' ? '1' : '0' ?>"
  data-movedate="<?= ($tr && $tr['moved_at'] && $tr['relocate_after'] && $tr['relocate_after'] > time()) ? (int)$tr['relocate_after'] : 0 ?>">

  <div class="sc-poster">
    <?php if ($row['poster_url']): ?>
      <img src="<?= htmlspecialchars($row['poster_url']) ?>"
           alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="sc-poster-ph ph-movie">🎬</div>
    <?php else: ?>
      <div class="sc-poster-ph ph-movie" style="display:flex">🎬
        <div class="sc-overlay-title"><?= htmlspecialchars($row['title']) ?><?= $row['year'] ? ' ('.(int)$row['year'].')' : '' ?></div>
      </div>
    <?php endif; ?>
    <?php if ($loc === 'fast'): ?>
      <div class="sc-tri sc-tri-fast" title="On fast storage"></div>
    <?php elseif ($recently_watched): ?>
      <div class="sc-tri sc-tri-watched" title="Recently watched"></div>
    <?php endif; ?>
    <?= render_dash_action_btns($action, $tr, $row['title']) ?>
  </div>
  <div class="sc-card-body">
    <div class="sc-title" title="<?= htmlspecialchars($row['title']) ?>">
      <?= htmlspecialchars($row['title']) ?><?= $row['year'] ? ' ('.(int)$row['year'].')' : '' ?>
    </div>
    <div class="sc-meta"><?= $watched ? time_ago($watched) : 'Never watched' ?> &nbsp;·&nbsp; <?= fmt_bytes((int)$row['size_on_disk']) ?></div>
  </div>
</div>
<?php endforeach; ?>
</div><!-- #poster-view -->

<!-- ── OVERVIEW ── -->
<div id="ov-view">
  <div class="card">
  <?php foreach ($tvShows as $row):
    $action  = row_to_action($row);
    $tr      = row_to_tracked($row);
    $loc     = $row['location'];
    $watched = (int)($row['last_watched_at'] ?? 0);
  ?>
  <div class="ov-row"
    data-title="<?= htmlspecialchars(strtolower($row['title'])) ?>"
    data-type="show"
    data-watched="<?= $watched ?>"
    data-size="<?= (int)$row['size_on_disk'] ?>"
    data-location="<?= htmlspecialchars($loc) ?>"
    data-tracked="<?= $tr ? '1' : '0' ?>"
    data-storage="<?= $loc === 'fast' ? '1' : '0' ?>"
    data-movedate="<?= ($tr && $tr['moved_at'] && $tr['relocate_after'] && $tr['relocate_after'] > time()) ? (int)$tr['relocate_after'] : 0 ?>">
    <?php if ($row['poster_url']): ?>
      <img class="ov-thumb" src="<?= htmlspecialchars($row['poster_url']) ?>"
           alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="ov-thumb-ph ph-show" style="display:none">📺</div>
    <?php else: ?>
      <div class="ov-thumb-ph ph-show">📺</div>
    <?php endif; ?>
    <div class="ov-info">
      <div class="ov-title"><?= htmlspecialchars($row['title']) ?></div>
      <div class="ov-meta">
        <?= $watched ? 'Watched '.time_ago($watched) : 'Never watched' ?>
        &nbsp;·&nbsp; <?= fmt_bytes((int)$row['size_on_disk']) ?>
      </div>
    </div>
    <div class="ov-right">
      <?php if ($loc === 'fast'): ?>
        <span class="storage-fast js-loc" style="font-size:.78rem">Fast</span>
      <?php elseif ($loc === 'slow'): ?>
        <span class="storage-slow js-loc" style="font-size:.78rem">Slow</span>
      <?php else: ?>
        <span class="js-loc" style="font-size:.78rem;color:rgba(255,255,255,.2)">—</span>
      <?php endif; ?>
    </div>
    <?= render_dash_action_btns($action, $tr, $row['title'], 'dash-row-actions') ?>
  </div>
  <?php endforeach; ?>
  <?php foreach ($movies as $row):
    $action  = row_to_action($row);
    $tr      = row_to_tracked($row);
    $loc     = $row['location'];
    $watched = (int)($row['last_watched_at'] ?? 0);
  ?>
  <div class="ov-row"
    data-title="<?= htmlspecialchars(strtolower($row['title'])) ?>"
    data-type="movie"
    data-watched="<?= $watched ?>"
    data-size="<?= (int)$row['size_on_disk'] ?>"
    data-location="<?= htmlspecialchars($loc) ?>"
    data-tracked="<?= $tr ? '1' : '0' ?>"
    data-storage="<?= $loc === 'fast' ? '1' : '0' ?>"
    data-movedate="<?= ($tr && $tr['moved_at'] && $tr['relocate_after'] && $tr['relocate_after'] > time()) ? (int)$tr['relocate_after'] : 0 ?>">
    <?php if ($row['poster_url']): ?>
      <img class="ov-thumb" src="<?= htmlspecialchars($row['poster_url']) ?>"
           alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
      <div class="ov-thumb-ph ph-movie" style="display:none">🎬</div>
    <?php else: ?>
      <div class="ov-thumb-ph ph-movie">🎬</div>
    <?php endif; ?>
    <div class="ov-info">
      <div class="ov-title"><?= htmlspecialchars($row['title']) ?><?= $row['year'] ? ' <span style="color:var(--muted);font-weight:400">('.(int)$row['year'].')</span>' : '' ?></div>
      <div class="ov-meta">
        <?= $watched ? 'Watched '.time_ago($watched) : 'Never watched' ?>
        &nbsp;·&nbsp; <?= fmt_bytes((int)$row['size_on_disk']) ?>
      </div>
    </div>
    <div class="ov-right">
      <?php if ($loc === 'fast'): ?>
        <span class="storage-fast js-loc" style="font-size:.78rem">Fast</span>
      <?php elseif ($loc === 'slow'): ?>
        <span class="storage-slow js-loc" style="font-size:.78rem">Slow</span>
      <?php else: ?>
        <span class="js-loc" style="font-size:.78rem;color:rgba(255,255,255,.2)">—</span>
      <?php endif; ?>
    </div>
    <?= render_dash_action_btns($action, $tr, $row['title'], 'dash-row-actions') ?>
  </div>
  <?php endforeach; ?>
  <?php if ($totalItems === 0): ?>
    <div class="empty">No media found in library.</div>
  <?php endif; ?>
  </div>
</div><!-- #ov-view -->

<!-- ── TABLE VIEW ── -->
<div id="tbl-view">
  <?php if ($totalItems === 0): ?>
    <div class="empty">No media found in library.</div>
  <?php else: ?>
  <div class="card sc-tbl" id="tbl-container">
    <div class="sc-tbl-header">
      <div class="sc-tbl-cell sc-tbl-title sort-col" data-col="title" onclick="setSort('title',null)">
        Title <span class="th-arrow">↑</span>
      </div>
      <div class="sc-tbl-cell sc-tbl-type">Type</div>
      <div class="sc-tbl-cell sc-tbl-location col-location sort-col" data-col="location" onclick="setSort('storage',null)">
        Location <span class="th-arrow">↑</span>
      </div>
      <div class="sc-tbl-cell sc-tbl-movedate col-movedate sort-col" data-col="movedate" onclick="setSort('movedate',null)">
        Move Date
        <span class="th-info" title="Scheduled relocation date (only shown when a future date is set)">ⓘ</span>
        <span class="th-arrow">↑</span>
      </div>
      <div class="sc-tbl-cell sc-tbl-watched col-watched sort-col" data-col="watched" onclick="setSort('watched',null)">
        Last Watched <span class="th-arrow">↑</span>
      </div>
      <div class="sc-tbl-cell sc-tbl-size col-size sort-col" data-col="size" onclick="setSort('size',null)">
        Size <span class="th-arrow">↑</span>
      </div>
      <div class="sc-tbl-cell sc-tbl-actions"></div>
    </div>

    <?php foreach ($tvShows as $row):
      $action   = row_to_action($row);
      $tr       = row_to_tracked($row);
      $loc      = $row['location'];
      $watched  = (int)($row['last_watched_at'] ?? 0);
      $movedate = fmt_movedate($tr);
      $ts_move  = ($tr && $tr['moved_at'] && $tr['relocate_after'] && $tr['relocate_after'] > time()) ? (int)$tr['relocate_after'] : 0;
    ?>
    <div class="sc-tbl-row"
      data-title="<?= htmlspecialchars(strtolower($row['title'])) ?>"
      data-type="show"
      data-watched="<?= $watched ?>"
      data-size="<?= (int)$row['size_on_disk'] ?>"
      data-location="<?= htmlspecialchars($loc) ?>"
      data-tracked="<?= $tr ? '1' : '0' ?>"
      data-storage="<?= $loc === 'fast' ? '1' : '0' ?>"
      data-movedate="<?= $ts_move ?>">
      <div class="sc-tbl-cell sc-tbl-title" style="font-weight:600"><?= htmlspecialchars($row['title']) ?></div>
      <div class="sc-tbl-cell sc-tbl-type"><span class="badge badge-muted" style="font-size:.68rem">TV</span></div>
      <div class="sc-tbl-cell sc-tbl-location col-location">
        <?php if ($loc === 'fast'): ?><span class="loc-fast js-loc">Fast</span>
        <?php elseif ($loc === 'slow'): ?><span class="loc-slow js-loc">Slow</span>
        <?php else: ?><span class="loc-unmapped js-loc">—</span><?php endif; ?>
      </div>
      <div class="sc-tbl-cell sc-tbl-movedate col-movedate" style="color:var(--muted);font-size:.82rem"><?= htmlspecialchars($movedate) ?></div>
      <div class="sc-tbl-cell sc-tbl-watched  col-watched"  style="color:var(--muted);font-size:.82rem"><?= fmt_watched($watched ?: null) ?></div>
      <div class="sc-tbl-cell sc-tbl-size     col-size"     style="color:var(--muted);font-size:.82rem"><?= fmt_bytes((int)$row['size_on_disk']) ?></div>
      <div class="sc-tbl-cell sc-tbl-actions"><?= render_dash_action_btns($action, $tr, $row['title'], 'dash-row-actions') ?></div>
    </div>
    <?php endforeach; ?>

    <?php foreach ($movies as $row):
      $action   = row_to_action($row);
      $tr       = row_to_tracked($row);
      $loc      = $row['location'];
      $watched  = (int)($row['last_watched_at'] ?? 0);
      $movedate = fmt_movedate($tr);
      $ts_move  = ($tr && $tr['moved_at'] && $tr['relocate_after'] && $tr['relocate_after'] > time()) ? (int)$tr['relocate_after'] : 0;
    ?>
    <div class="sc-tbl-row"
      data-title="<?= htmlspecialchars(strtolower($row['title'])) ?>"
      data-type="movie"
      data-watched="<?= $watched ?>"
      data-size="<?= (int)$row['size_on_disk'] ?>"
      data-location="<?= htmlspecialchars($loc) ?>"
      data-tracked="<?= $tr ? '1' : '0' ?>"
      data-storage="<?= $loc === 'fast' ? '1' : '0' ?>"
      data-movedate="<?= $ts_move ?>">
      <div class="sc-tbl-cell sc-tbl-title" style="font-weight:600">
        <?= htmlspecialchars($row['title']) ?><?= $row['year'] ? ' <span style="color:var(--muted);font-weight:400">('.(int)$row['year'].')</span>' : '' ?>
      </div>
      <div class="sc-tbl-cell sc-tbl-type"><span class="badge" style="font-size:.68rem;background:rgba(160,90,219,.12);color:#a05adb">Movie</span></div>
      <div class="sc-tbl-cell sc-tbl-location col-location">
        <?php if ($loc === 'fast'): ?><span class="loc-fast js-loc">Fast</span>
        <?php elseif ($loc === 'slow'): ?><span class="loc-slow js-loc">Slow</span>
        <?php else: ?><span class="loc-unmapped js-loc">—</span><?php endif; ?>
      </div>
      <div class="sc-tbl-cell sc-tbl-movedate col-movedate" style="color:var(--muted);font-size:.82rem"><?= htmlspecialchars($movedate) ?></div>
      <div class="sc-tbl-cell sc-tbl-watched  col-watched"  style="color:var(--muted);font-size:.82rem"><?= fmt_watched($watched ?: null) ?></div>
      <div class="sc-tbl-cell sc-tbl-size     col-size"     style="color:var(--muted);font-size:.82rem"><?= fmt_bytes((int)$row['size_on_disk']) ?></div>
      <div class="sc-tbl-cell sc-tbl-actions"><?= render_dash_action_btns($action, $tr, $row['title'], 'dash-row-actions') ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div><!-- #tbl-view -->

<!-- ── Legend ── -->
<div class="legend-box">
  <div class="legend-hdr">Legend</div>
  <div class="legend-items">
    <div class="legend-item">
      <div class="legend-tri" style="border-color:transparent var(--accent) transparent transparent"></div>
      <span>Orange — on fast storage</span>
    </div>
    <div class="legend-item">
      <div class="legend-tri" style="border-color:transparent var(--green) transparent transparent"></div>
      <span>Green — watched in last 30 days</span>
    </div>
    <div class="legend-item">
      <span class="loc-fast">Fast</span>
      <span>Currently on fast storage (SSD)</span>
    </div>
    <div class="legend-item">
      <span class="loc-slow">Slow</span>
      <span>Currently on slow storage (HDD/RAID)</span>
    </div>
    <div class="legend-item">
      <span style="font-size:.78rem;color:rgba(255,255,255,.2)">—</span>
      <span>Not on a configured path mapping</span>
    </div>
  </div>
</div>

<?php endif; // lib_count > 0 ?>

<script>
function submitDashAction(btn) {
  var d         = btn.dataset;
  var container = btn.closest('.dash-card-actions, .dash-row-actions');
  var row       = container && container.closest('[data-location]');

  // Disable all buttons in this container while the request is in flight
  var btns = container ? container.querySelectorAll('button') : [];
  btns.forEach(function(b) { b.disabled = true; b.style.opacity = '0.5'; });

  var form = new FormData();
  form.append('_ajax',           '1');
  form.append('action',          d.action);
  form.append('external_id',     d.extId    || '');
  form.append('service',         d.service  || '');
  form.append('mapping_id',      d.mappingId|| '');
  form.append('title',           d.title    || '');
  form.append('folder',          d.folder   || '');
  form.append('current_location',d.location || '');
  form.append('media_type',      d.mediaType|| '');
  form.append('track_id',        d.trackId  || '');
  form.append('size_on_disk',    d.sizeOnDisk||'0');
  form.append('wrap_cls',        container ? container.className : 'dash-row-actions');

  fetch('index.php', { method: 'POST', body: form })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.ok) {
        btns.forEach(function(b) { b.disabled = false; b.style.opacity = ''; });
        console.error('Action failed:', data.error);
        return;
      }
      // Replace button container with freshly-rendered HTML from server
      if (container && data.buttons_html !== undefined) {
        var tmp = document.createElement('div');
        tmp.innerHTML = data.buttons_html;
        var newNode = tmp.firstElementChild;
        if (newNode) container.replaceWith(newNode);
      }
      // Update parent row/card data attributes and location badge
      if (row) {
        if (data.location) {
          row.dataset.location = data.location;
          row.dataset.storage  = data.location === 'fast' ? '1' : '0';
          // Update location badge if present in this row
          var badge = row.querySelector('.js-loc');
          if (badge) {
            var isFast = data.location === 'fast', isSlow = data.location === 'slow';
            // Support both table (loc-*) and overview (storage-fast/slow) class names
            badge.className   = (isFast ? 'loc-fast storage-fast' : isSlow ? 'loc-slow storage-slow' : 'loc-unmapped') + ' js-loc';
            badge.textContent = isFast ? 'Fast' : isSlow ? 'Slow' : '—';
            if (!isFast && !isSlow) badge.style.cssText = 'font-size:.78rem;color:rgba(255,255,255,.2)';
            else badge.style.cssText = 'font-size:.78rem';
          }
        }
        if (data.tracked !== undefined) row.dataset.tracked = data.tracked;
      }
    })
    .catch(function() {
      btns.forEach(function(b) { b.disabled = false; b.style.opacity = ''; });
    });
}

function triggerSync(type) {
  // Close dropdown
  document.querySelectorAll('.sc-menu').forEach(function(m) { m.classList.remove('open'); });

  var action   = type === 'tautulli' ? 'sync_tautulli' : 'sync_library';
  var stateKey = type === 'tautulli' ? 'tautulli_synced_at' : 'library_synced_at';

  var form = new FormData();
  form.append('action', action);

  fetch('index.php', { method: 'POST', body: form })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.queued) return;
      // Snapshot current timestamp then start polling
      fetch('index.php?action=sync_status')
        .then(function(r) { return r.json(); })
        .then(function(before) {
          pollSyncCompletion(stateKey, before[stateKey] || 0);
        });
    })
    .catch(function() {});
}

function pollSyncCompletion(stateKey, beforeTs) {
  var interval = setInterval(function() {
    fetch('index.php?action=sync_status')
      .then(function(r) { return r.json(); })
      .then(function(state) {
        if ((state[stateKey] || 0) > beforeTs) {
          clearInterval(interval);
          location.reload();
        }
      })
      .catch(function() {});
  }, 5000);
  setTimeout(function() { clearInterval(interval); }, 900000);
}

(function() {
  var currentView = 'poster';
  var currentSort = 'watched';
  var sortDirs    = { watched:false, title:true, size:false, movedate:true, storage:true };
  var cols        = { location:true, movedate:true, watched:true, size:true };
  var filters     = { shows:true, movies:true, fast:true, slow:true, unmapped:true, tracked:true, untracked:true };

  var posterView = document.getElementById('poster-view');
  var ovView     = document.getElementById('ov-view');
  var tblView    = document.getElementById('tbl-view');
  var countEl    = document.getElementById('visible-count');
  var colStyle   = document.getElementById('col-vis-style');

  function save() {
    try {
      localStorage.setItem('mv2_view',    currentView);
      localStorage.setItem('mv2_sort',    currentSort);
      localStorage.setItem('mv2_sdirs',   JSON.stringify(sortDirs));
      localStorage.setItem('mv2_cols',    JSON.stringify(cols));
      localStorage.setItem('mv2_filters', JSON.stringify(filters));
    } catch(e) {}
  }
  function restore() {
    try {
      var v  = localStorage.getItem('mv2_view');    if(v)  currentView = v;
      var s  = localStorage.getItem('mv2_sort');    if(s)  currentSort = s;
      var sd = localStorage.getItem('mv2_sdirs');   if(sd) Object.assign(sortDirs, JSON.parse(sd));
      var c  = localStorage.getItem('mv2_cols');    if(c)  Object.assign(cols, JSON.parse(c));
      var f  = localStorage.getItem('mv2_filters'); if(f)  Object.assign(filters, JSON.parse(f));
    } catch(e) {}
  }

  function applyColCSS() {
    var css = '';
    if (!cols.location) css += '.col-location{display:none}';
    if (!cols.movedate) css += '.col-movedate{display:none}';
    if (!cols.watched)  css += '.col-watched{display:none}';
    if (!cols.size)     css += '.col-size{display:none}';
    colStyle.textContent = css;
    document.getElementById('col-location').checked = cols.location;
    document.getElementById('col-movedate').checked = cols.movedate;
    document.getElementById('col-watched').checked  = cols.watched;
    document.getElementById('col-size').checked     = cols.size;
  }

  window.saveCols = function() {
    cols.location = document.getElementById('col-location').checked;
    cols.movedate = document.getElementById('col-movedate').checked;
    cols.watched  = document.getElementById('col-watched').checked;
    cols.size     = document.getElementById('col-size').checked;
    applyColCSS(); save();
  };

  window.setView = function(v, btn) {
    currentView = v;
    posterView.style.display = v==='poster'   ? '' : 'none';
    ovView.style.display     = v==='overview' ? 'block' : 'none';
    tblView.style.display    = v==='table'    ? 'block' : 'none';
    document.querySelectorAll('#view-menu .sc-menu-item').forEach(function(i) {
      i.classList.toggle('selected', i.dataset.view === v);
    });
    var icons = {
      poster:   '<path d="M3 3h7v9H3zm11 0h7v9h-7zm-11 11h7v7H3zm11 0h7v7h-7z"/>',
      overview: '<path d="M3 5h2V3H3v2zm0 4h2V7H3v2zm0 4h2v-2H3v2zm4-8h14V3H7v2zm0 4h14V7H7v2zm0 4h14v-2H7v2z"/>',
      table:    '<path d="M20 2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-9 11H5v-2h6v2zm0-4H5V7h6v2zm8 4h-6v-2h6v2zm0-4h-6V7h6v2z"/>'
    };
    document.getElementById('options-icon').innerHTML = icons[v] || icons.table;
    closeAllMenus(); save();
  };

  window.setSort = function(key, btn) {
    if (currentSort === key) { sortDirs[key] = !sortDirs[key]; }
    else { currentSort = key; }
    document.querySelectorAll('#sort-menu .sc-menu-item').forEach(function(i) {
      i.classList.toggle('selected', i.dataset.sort === key);
    });
    Object.keys(sortDirs).forEach(function(k) {
      var el = document.getElementById('sd-'+k);
      if (el) el.textContent = sortDirs[k] ? '↑' : '↓';
    });
    document.querySelectorAll('#tbl-view .sort-col').forEach(function(th) {
      var isActive = th.dataset.col === key;
      th.classList.toggle('sort-active', isActive);
      var arrow = th.querySelector('.th-arrow');
      if (arrow) arrow.textContent = sortDirs[key] ? '↑' : '↓';
    });
    closeAllMenus(); applyAll(); save();
  };

  window.applyFilter = function() {
    filters.shows    = document.getElementById('f-shows').checked;
    filters.movies   = document.getElementById('f-movies').checked;
    filters.fast     = document.getElementById('f-fast').checked;
    filters.slow     = document.getElementById('f-slow').checked;
    filters.unmapped = document.getElementById('f-unmapped').checked;
    filters.tracked  = document.getElementById('f-tracked').checked;
    filters.untracked= document.getElementById('f-untracked').checked;
    var allOn = filters.shows && filters.movies && filters.fast && filters.slow
             && filters.unmapped && filters.tracked && filters.untracked;
    document.getElementById('btn-filter').classList.toggle('has-filter', !allOn);
    applyAll(); save();
  };

  function cmp(a, b) {
    var asc = sortDirs[currentSort] === true;
    if (currentSort === 'title') {
      var av = a.dataset.title||'', bv = b.dataset.title||'';
      return asc ? av.localeCompare(bv) : bv.localeCompare(av);
    }
    var av, bv;
    if      (currentSort === 'watched')  { av = parseInt(a.dataset.watched||'0');   bv = parseInt(b.dataset.watched||'0'); }
    else if (currentSort === 'size')     { av = parseInt(a.dataset.size||'0');      bv = parseInt(b.dataset.size||'0'); }
    else if (currentSort === 'storage')  { av = parseInt(a.dataset.storage||'0');   bv = parseInt(b.dataset.storage||'0'); }
    else if (currentSort === 'movedate') { av = parseInt(a.dataset.movedate||'0');  bv = parseInt(b.dataset.movedate||'0'); }
    else return 0;
    return asc ? av - bv : bv - av;
  }

  function isVisible(el) {
    var type    = el.dataset.type    || '';
    var loc     = el.dataset.location|| '';
    var tracked = el.dataset.tracked || '0';
    if (!filters.shows    && type === 'show')      return false;
    if (!filters.movies   && type === 'movie')     return false;
    if (!filters.fast     && loc  === 'fast')      return false;
    if (!filters.slow     && loc  === 'slow')      return false;
    if (!filters.unmapped && loc  === 'unmapped')  return false;
    if (!filters.tracked  && tracked === '1')      return false;
    if (!filters.untracked&& tracked === '0')      return false;
    return true;
  }

  function applyAll() {
    var cards   = [...posterView.querySelectorAll('.sc-card')];
    var ovRows  = [...ovView.querySelectorAll('.ov-row')];
    var tblRows = [...document.querySelectorAll('#tbl-view .sc-tbl-row')];

    cards.sort(cmp).forEach(function(c)   { posterView.appendChild(c); });
    var ovCard = ovView.querySelector('.card');
    if (ovCard) ovRows.sort(cmp).forEach(function(r) { ovCard.appendChild(r); });
    var tblCont = document.getElementById('tbl-container');
    if (tblCont) tblRows.sort(cmp).forEach(function(r) { tblCont.appendChild(r); });

    var n = 0;
    cards.forEach(function(c)   { var v=isVisible(c); c.style.display=v?'':'none'; if(v)n++; });
    ovRows.forEach(function(r)  { r.style.display=isVisible(r)?'':'none'; });
    tblRows.forEach(function(r) { r.style.display=isVisible(r)?'flex':'none'; });

    if (countEl) countEl.textContent = n;
  }

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

  window.openOptions  = function() { document.getElementById('options-modal').classList.add('open'); };
  window.closeOptions = function() { document.getElementById('options-modal').classList.remove('open'); };

  // ── Init ──
  restore();

  // Sync filter checkboxes
  var fKeys = ['shows','movies','fast','slow','unmapped','tracked','untracked'];
  fKeys.forEach(function(k) {
    var el = document.getElementById('f-'+k);
    if (el) el.checked = filters[k];
  });
  var allOn = fKeys.every(function(k) { return filters[k]; });
  document.getElementById('btn-filter').classList.toggle('has-filter', !allOn);

  applyColCSS();
  setView(currentView, null);

  document.querySelectorAll('#sort-menu .sc-menu-item').forEach(function(i) {
    i.classList.toggle('selected', i.dataset.sort === currentSort);
  });
  Object.keys(sortDirs).forEach(function(k) {
    var el = document.getElementById('sd-'+k);
    if (el) el.textContent = sortDirs[k] ? '↑' : '↓';
  });
  document.querySelectorAll('#tbl-view .sort-col').forEach(function(th) {
    th.classList.toggle('sort-active', th.dataset.col === currentSort);
    var arrow = th.querySelector('.th-arrow');
    if (arrow) arrow.textContent = sortDirs[currentSort] ? '↑' : '↓';
  });

  applyAll();
})();
</script>


<?php layout_end(); ?>
