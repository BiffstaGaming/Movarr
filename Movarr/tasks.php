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

$s = load_settings();

// ── POST: manual trigger ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    if ($post_action === 'run_sync_library' || $post_action === 'run_sync_tautulli') {
        $type = $post_action === 'run_sync_tautulli' ? 'tautulli' : 'library';
        $cmd  = 'php ' . escapeshellarg(__DIR__ . '/sync.php') . ' ' . escapeshellarg($type)
              . ' >> /config/cron.log 2>&1';
        exec('nohup ' . $cmd . ' &');
        header('Content-Type: application/json');
        echo json_encode(['queued' => true, 'type' => $type]);
        exit;
    }
}

// ── GET: sync status (polled by JS) ───────────────────────────────────────────
if (($_GET['action'] ?? '') === 'sync_status') {
    $st = read_sync_state();
    $now = time();
    $lib_stale = isset($st['library_running_since'])  && ($now - (int)$st['library_running_since'])  > 1800;
    $tau_stale = isset($st['tautulli_running_since']) && ($now - (int)$st['tautulli_running_since']) > 1800;
    header('Content-Type: application/json');
    echo json_encode([
        'library_synced_at'   => (int)($st['library_synced_at']  ?? 0),
        'tautulli_synced_at'  => (int)($st['tautulli_synced_at'] ?? 0),
        'library_running'     => !empty($st['library_running'])  && !$lib_stale,
        'tautulli_running'    => !empty($st['tautulli_running']) && !$tau_stale,
    ]);
    exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────
$sync_state = read_sync_state();
$now        = time();

// Running flags (with staleness protection)
$lib_stale     = isset($sync_state['library_running_since'])  && ($now - (int)$sync_state['library_running_since'])  > 1800;
$tau_stale     = isset($sync_state['tautulli_running_since']) && ($now - (int)$sync_state['tautulli_running_since']) > 1800;
$lib_running   = !empty($sync_state['library_running'])  && !$lib_stale;
$tau_running   = !empty($sync_state['tautulli_running']) && !$tau_stale;

$lib_synced_at = (int)($sync_state['library_synced_at']  ?? 0);
$tau_synced_at = (int)($sync_state['tautulli_synced_at'] ?? 0);

// Last mover run from DB
$db            = db_connect();
$last_move_ts  = (int)$db->query("SELECT MAX(moved_at) FROM move_history")->fetchColumn();
$pending_moves = (int)$db->query("SELECT COUNT(*) FROM pending_moves WHERE status='pending'")->fetchColumn();

// Mover cron schedule from settings
$mover_cron = $s['cron_schedule'] ?? '0 3 * * *';

/** Calculate next run for a simple "M H * * *" daily cron expression. Returns null for complex schedules. */
function next_cron_run(string $expr): ?int {
    $parts = preg_split('/\s+/', trim($expr));
    if (count($parts) !== 5) return null;
    [$minute, $hour, $dom, $month, $dow] = $parts;
    if ($dom !== '*' || $month !== '*' || $dow !== '*') return null;
    if (!ctype_digit($minute) || !ctype_digit($hour)) return null;
    $today = mktime((int)$hour, (int)$minute, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
    return $today > time() ? $today : $today + 86400;
}

function fmt_rel_time(int $ts): string {
    if (!$ts) return '—';
    $diff = time() - $ts;
    if ($diff < 0)    return 'in ' . gmdate('H:i', -$diff);
    if ($diff < 60)   return 'just now';
    if ($diff < 3600) return (int)($diff/60) . 'm ago';
    if ($diff < 86400) return (int)($diff/3600) . 'h ago';
    return (int)($diff/86400) . 'd ago';
}

function fmt_next(int $ts): string {
    if (!$ts) return '—';
    $diff = $ts - time();
    if ($diff <= 0)    return 'now';
    if ($diff < 3600)  return 'in ' . (int)($diff/60) . 'm';
    if ($diff < 86400) return 'in ' . (int)($diff/3600) . 'h ' . (int)(($diff%3600)/60) . 'm';
    return 'in ' . (int)($diff/86400) . 'd';
}

$lib_next   = next_cron_run('0 2 * * *');
$tau_next   = next_cron_run('0 3 * * *');
$mover_next = next_cron_run($mover_cron);

// ── Parse sync history from cron.log ──────────────────────────────────────────
function parse_task_history(int $max = 40): array {
    $logFile = config_base() . '/cron.log';
    if (!file_exists($logFile)) return [];
    $handle = fopen($logFile, 'rb');
    if (!$handle) return [];
    fseek($handle, 0, SEEK_END);
    $size  = ftell($handle);
    $chunk = min($size, 131072); // last 128 KB
    fseek($handle, -$chunk, SEEK_END);
    $raw = fread($handle, $chunk);
    fclose($handle);

    $entries = [];
    $pat = '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[(\w+)\] \[sync\] (.+)$/';
    foreach (array_reverse(explode("\n", $raw)) as $line) {
        $line = rtrim($line);
        if (!$line || !preg_match($pat, $line, $m)) continue;
        $msg = $m[3];
        // Only surface meaningful summary lines
        if (!preg_match('/started|complete|failed|unreachable/i', $msg)) continue;
        $entries[] = ['ts' => $m[1], 'ts_unix' => strtotime($m[1]), 'level' => $m[2], 'msg' => $msg];
        if (count($entries) >= $max) break;
    }
    return $entries;
}

$task_history = parse_task_history(40);

layout_start('Tasks', 'tasks');
?>
<style>
.tasks-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.25rem;
}
.task-row {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: .7rem .85rem;
  border-bottom: 1px solid rgba(255,255,255,.04);
}
.task-row:last-child { border-bottom: none; }
.task-name  { flex: 2; min-width: 0; font-weight: 600; font-size: .875rem; }
.task-sched { flex: 1.5; min-width: 0; font-size: .8rem; color: var(--muted); font-family: monospace; }
.task-last  { flex: 1; min-width: 0; font-size: .8rem; color: var(--muted); }
.task-next  { flex: 1; min-width: 0; font-size: .8rem; color: var(--muted); }
.task-status{ flex: 0 0 80px; display: flex; align-items: center; gap: .4rem; font-size: .78rem; }
.task-action{ flex: 0 0 90px; text-align: right; }
.task-header{ display: flex; align-items: center; gap: 1rem; padding: .45rem .85rem;
  font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
  color: var(--muted); border-bottom: 1px solid var(--border); }
.task-header .task-name  { font-size: .68rem; font-weight: 700; }
.task-header .task-sched { font-size: .68rem; font-weight: 700; }
.btn-run {
  padding: .3rem .65rem; font-size: .72rem; font-weight: 700;
  border: 1px solid var(--border); border-radius: 3px;
  background: none; color: var(--muted); cursor: pointer;
  transition: border-color .15s, color .15s, background .15s;
  white-space: nowrap;
}
.btn-run:hover   { border-color: var(--accent); color: var(--accent); }
.btn-run:disabled{ opacity: .4; cursor: not-allowed; }
.btn-run.running { border-color: var(--accent); color: var(--accent); opacity: .6; }

.hist-row {
  display: flex; align-items: flex-start; gap: .75rem;
  padding: .45rem .85rem;
  border-bottom: 1px solid rgba(255,255,255,.04);
  font-size: .8rem;
}
.hist-row:last-child { border-bottom: none; }
.hist-ts  { flex: 0 0 130px; color: var(--muted); font-size: .75rem; font-family: monospace; }
.hist-msg { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text); }
.hist-row.level-ERROR .hist-msg { color: var(--red); }
.hist-row.level-WARN  .hist-msg { color: var(--accent); }

.notice-queued {
  display: none; padding: .5rem .85rem; font-size: .8rem;
  background: rgba(229,160,13,.1); color: var(--accent);
  border-top: 1px solid rgba(229,160,13,.2);
}
</style>

<!-- ── Scheduled Tasks ── -->
<div class="tasks-grid">
<div class="card">
  <div class="card-header">Scheduled Tasks</div>

  <div class="task-header">
    <div class="task-name">Task</div>
    <div class="task-sched">Schedule</div>
    <div class="task-last">Last Run</div>
    <div class="task-next">Next Run</div>
    <div class="task-status">Status</div>
    <div class="task-action"></div>
  </div>

  <!-- Library Sync -->
  <div class="task-row" id="row-library">
    <div class="task-name">
      Library Sync
      <div style="font-size:.72rem;font-weight:400;color:var(--muted);margin-top:1px">Sonarr &amp; Radarr → media_library</div>
    </div>
    <div class="task-sched">0 2 * * *&nbsp;<span style="font-family:sans-serif;font-size:.72rem">(daily 02:00)</span></div>
    <div class="task-last" id="lib-last"><?= $lib_synced_at ? fmt_rel_time($lib_synced_at) : '—' ?></div>
    <div class="task-next" id="lib-next"><?= $lib_next ? fmt_next($lib_next) : '—' ?></div>
    <div class="task-status" id="lib-status">
      <?php if ($lib_running): ?>
        <span class="dot dot-amber"></span><span>Running</span>
      <?php else: ?>
        <span class="dot dot-muted"></span><span>Idle</span>
      <?php endif; ?>
    </div>
    <div class="task-action">
      <button class="btn-run<?= $lib_running ? ' running' : '' ?>"
              id="btn-library"
              onclick="runTask('library')"
              <?= $lib_running ? 'disabled' : '' ?>>
        <?= $lib_running ? 'Running…' : '▶ Run Now' ?>
      </button>
    </div>
  </div>
  <div class="notice-queued" id="notice-library">Sync queued — running in background…</div>

  <!-- Watch History Sync -->
  <div class="task-row" id="row-tautulli">
    <div class="task-name">
      Watch History Sync
      <div style="font-size:.72rem;font-weight:400;color:var(--muted);margin-top:1px">Tautulli → last_watched_at</div>
    </div>
    <div class="task-sched">0 3 * * *&nbsp;<span style="font-family:sans-serif;font-size:.72rem">(daily 03:00)</span></div>
    <div class="task-last" id="tau-last"><?= $tau_synced_at ? fmt_rel_time($tau_synced_at) : '—' ?></div>
    <div class="task-next" id="tau-next"><?= $tau_next ? fmt_next($tau_next) : '—' ?></div>
    <div class="task-status" id="tau-status">
      <?php if ($tau_running): ?>
        <span class="dot dot-amber"></span><span>Running</span>
      <?php else: ?>
        <span class="dot dot-muted"></span><span>Idle</span>
      <?php endif; ?>
    </div>
    <div class="task-action">
      <button class="btn-run<?= $tau_running ? ' running' : '' ?>"
              id="btn-tautulli"
              onclick="runTask('tautulli')"
              <?= $tau_running ? 'disabled' : '' ?>>
        <?= $tau_running ? 'Running…' : '▶ Run Now' ?>
      </button>
    </div>
  </div>
  <div class="notice-queued" id="notice-tautulli">Sync queued — running in background…</div>

  <!-- Mover -->
  <div class="task-row" id="row-mover">
    <div class="task-name">
      Mover
      <div style="font-size:.72rem;font-weight:400;color:var(--muted);margin-top:1px">Move queued media between fast ↔ slow storage</div>
    </div>
    <div class="task-sched"><?= htmlspecialchars($mover_cron) ?></div>
    <div class="task-last"><?= $last_move_ts ? fmt_rel_time($last_move_ts) : '—' ?></div>
    <div class="task-next"><?= $mover_next ? fmt_next($mover_next) : '—' ?></div>
    <div class="task-status"><span class="dot dot-muted"></span><span>Idle</span></div>
    <div class="task-action">
      <a href="manual.php" class="btn-run" style="text-decoration:none;display:inline-flex;align-items:center">Queue Move</a>
    </div>
  </div>

</div><!-- .card -->

<!-- ── Task History ── -->
<div class="card">
  <div class="card-header">Task History</div>
  <?php if (!$task_history): ?>
    <div class="empty">No task history found. Run a sync to see entries here.</div>
  <?php else: ?>
    <?php foreach ($task_history as $e): ?>
    <div class="hist-row level-<?= htmlspecialchars($e['level']) ?>">
      <div class="hist-ts"><?= htmlspecialchars($e['ts']) ?></div>
      <div class="hist-msg" title="<?= htmlspecialchars($e['msg']) ?>"><?= htmlspecialchars($e['msg']) ?></div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

</div><!-- .tasks-grid -->

<script>
function runTask(type) {
  var btn        = document.getElementById('btn-' + type);
  var notice     = document.getElementById('notice-' + type);
  var statusDiv  = document.getElementById((type === 'tautulli' ? 'tau' : 'lib') + '-status');
  var stateKey   = type === 'tautulli' ? 'tautulli_synced_at' : 'library_synced_at';
  var runningKey = type === 'tautulli' ? 'tautulli_running'   : 'library_running';

  if (btn) { btn.disabled = true; btn.textContent = 'Running…'; btn.classList.add('running'); }
  if (notice) notice.style.display = 'block';
  if (statusDiv) statusDiv.innerHTML = '<span class="dot dot-amber"></span><span>Running</span>';

  var form = new FormData();
  form.append('action', 'run_sync_' + type);

  fetch('tasks.php', { method: 'POST', body: form })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.queued) { resetTaskBtn(type); return; }
      // Snapshot before timestamp then poll
      fetch('tasks.php?action=sync_status')
        .then(function(r) { return r.json(); })
        .then(function(before) {
          pollTask(type, stateKey, before[stateKey] || 0);
        });
    })
    .catch(function() { resetTaskBtn(type); });
}

function resetTaskBtn(type) {
  var btn    = document.getElementById('btn-' + type);
  var notice = document.getElementById('notice-' + type);
  var statusDiv = document.getElementById((type === 'tautulli' ? 'tau' : 'lib') + '-status');
  if (btn)    { btn.disabled = false; btn.textContent = '▶ Run Now'; btn.classList.remove('running'); }
  if (notice) notice.style.display = 'none';
  if (statusDiv) statusDiv.innerHTML = '<span class="dot dot-muted"></span><span>Idle</span>';
}

function pollTask(type, stateKey, beforeTs) {
  var lastEl = document.getElementById((type === 'tautulli' ? 'tau' : 'lib') + '-last');

  var interval = setInterval(function() {
    fetch('tasks.php?action=sync_status')
      .then(function(r) { return r.json(); })
      .then(function(state) {
        var ts = state[stateKey] || 0;
        if (ts > beforeTs) {
          clearInterval(interval);
          resetTaskBtn(type);
          if (lastEl) lastEl.textContent = 'just now';
          // Reload page to refresh history
          setTimeout(function() { location.reload(); }, 1500);
        }
      })
      .catch(function() {});
  }, 5000);
  // Safety timeout: 15 min
  setTimeout(function() { clearInterval(interval); resetTaskBtn(type); }, 900000);
}
</script>

<?php layout_end(); ?>
