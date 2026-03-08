<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/layout.php';

// Handle clear action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    $lf = log_file();
    if (file_exists($lf)) {
        file_put_contents($lf, '');
    }
    header('Location: logs.php');
    exit;
}

$log_path  = log_file();
$log_lines = [];
$log_size  = 0;

$level_order  = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];
$valid_levels = ['ALL', 'DEBUG', 'INFO', 'WARNING', 'ERROR'];
$sel_level    = in_array($_GET['level'] ?? '', $valid_levels) ? $_GET['level'] : 'INFO';
$line_limit   = max(1, min(10000, (int)($_GET['limit'] ?? 100)));

if (file_exists($log_path)) {
    $log_size  = filesize($log_path);
    $all_lines = file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($sel_level === 'ALL') {
        $filtered = $all_lines;
    } else {
        $min_order = $level_order[$sel_level] ?? 1;
        $filtered  = array_filter($all_lines, function($line) use ($level_order, $min_order) {
            // Always keep separator lines
            if (str_starts_with(trim($line), '===') || str_starts_with(trim($line), '---')) return true;
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[(\w+)\]/', $line, $m)) {
                return ($level_order[$m[1]] ?? 0) >= $min_order;
            }
            return false;
        });
    }

    // Take last N of the filtered lines, newest first
    $log_lines = array_reverse(array_slice(array_values($filtered), -$line_limit));
}

$auto_refresh = isset($_GET['refresh']);
$extra_head = $auto_refresh ? '<meta http-equiv="refresh" content="15">' : '';
layout_start('Logs', 'logs', $extra_head);
?>

<div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
  <a href="logs.php?level=<?= $sel_level ?>&limit=<?= $line_limit ?>" class="btn">&#8635; Refresh</a>
  <a href="logs.php?level=<?= $sel_level ?>&limit=<?= $line_limit ?>&refresh=1" class="btn <?= $auto_refresh ? 'btn-info' : '' ?>">
    &#9711; Auto (15s)
  </a>
  <select class="level-select" id="level-filter" onchange="applyFilters()" style="width:120px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);padding:.4rem .6rem;font-size:.8rem;cursor:pointer">
    <?php foreach (['INFO','ALL','DEBUG','WARNING','ERROR'] as $lvl): ?>
    <option value="<?= $lvl ?>" <?= $sel_level === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
    <?php endforeach; ?>
  </select>
  <input type="number" id="line-limit" style="width:70px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);padding:.4rem .6rem;font-size:.8rem" value="<?= $line_limit ?>" min="1" max="10000" title="Max lines" onchange="applyFilters()">
  <form method="POST" style="display:inline" onsubmit="return confirm('Clear the log file?')">
    <input type="hidden" name="action" value="clear">
    <button type="submit" class="btn btn-danger">&#128465; Clear</button>
  </form>
  <span style="font-size:.75rem;color:var(--muted);margin-left:auto">
    <?php if ($log_size > 0): ?>
      <?= count($log_lines) ?> lines &mdash; <?= number_format($log_size / 1024, 1) ?> KB
      <?php if ($auto_refresh): ?> &mdash; <span id="countdown">15</span>s<?php endif; ?>
    <?php else: ?>
      Log file is empty
    <?php endif; ?>
  </span>
</div>

<div class="log-box">
  <?php if (empty($log_lines)): ?>
    <div class="empty">No log entries yet.</div>
  <?php else: ?>
    <?php foreach ($log_lines as $line): ?>
      <?php
      $level = 'INFO'; $ts = ''; $msg = htmlspecialchars($line);
      if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[(\w+)\] (.+)$/', $line, $m)) {
          $ts = $m[1]; $level = $m[2]; $msg = htmlspecialchars($m[3]);
      }
      $is_sep = str_starts_with(trim($line), '===') || str_starts_with(trim($line), '---');
      $class  = $is_sep ? 'separator' : 'level-' . $level;
      ?>
      <div class="log-line <?= $class ?>">
        <?php if ($ts): ?>
          <span class="log-ts"><?= htmlspecialchars($ts) ?></span>
          <span class="log-level"><?= $level ?></span>
        <?php endif; ?>
        <span class="log-msg"><?= $msg ?></span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
function applyFilters() {
  const level = document.getElementById('level-filter').value;
  const limit = document.getElementById('line-limit').value;
  const params = new URLSearchParams({ level, limit <?= $auto_refresh ? ", refresh: 1" : "" ?> });
  window.location.href = 'logs.php?' + params.toString();
}
</script>
<?php if ($auto_refresh): ?>
<script>
let t = 15;
const el = document.getElementById('countdown');
setInterval(() => { if (el && t > 0) el.textContent = --t; }, 1000);
</script>
<?php endif; ?>

<?php layout_end(); ?>
