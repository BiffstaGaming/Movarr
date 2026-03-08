<?php
require_once __DIR__ . '/includes/settings.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Logs — Movarr</title>
<?php if ($auto_refresh): ?>
<meta http-equiv="refresh" content="15">
<?php endif; ?>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:       #141414;
    --surface:  #1f1f1f;
    --surface2: #252525;
    --border:   #2e2e2e;
    --accent:   #e5a00d;
    --text:     #e0e0e0;
    --muted:    #888;
    --radius:   8px;
  }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 15px;
    line-height: 1.5;
    min-height: 100vh;
  }

  header {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 0 2rem;
    display: flex;
    align-items: center;
    gap: 1.2rem;
    height: 54px;
  }
  header .logo { display: flex; align-items: center; gap: .6rem; text-decoration: none; }
  header .logo span { font-size: 1.1rem; font-weight: 700; color: var(--accent); }
  nav { display: flex; gap: .25rem; margin-left: 1.5rem; }
  nav a {
    color: var(--muted);
    text-decoration: none;
    padding: .35rem .8rem;
    border-radius: 6px;
    font-size: .875rem;
    font-weight: 500;
    transition: color .15s, background .15s;
  }
  nav a:hover { color: var(--text); background: var(--surface2); }
  nav a.active { color: var(--accent); background: #2a2200; }

  .container { max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem; }

  .toolbar {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
  }
  .toolbar-info { font-size: .8rem; color: var(--muted); margin-left: auto; }

  .btn {
    padding: .4rem .9rem;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    font-size: .8rem;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    transition: border-color .15s;
  }
  .btn:hover { border-color: var(--accent); color: var(--accent); }
  .btn.active { border-color: var(--accent); color: var(--accent); background: #2a2200; }
  .btn-danger { border-color: #662020; color: #eb7d7d; }
  .btn-danger:hover { background: #2d0d0d; }

  .log-box {
    background: #0d0d0d;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1rem;
    font-family: 'Cascadia Code', 'Fira Code', 'Consolas', monospace;
    font-size: .78rem;
    line-height: 1.6;
    overflow-x: auto;
    min-height: 200px;
    max-height: 75vh;
    overflow-y: auto;
  }

  .log-line { display: flex; gap: .75rem; }
  .log-line + .log-line { border-top: 1px solid #1a1a1a; }
  .log-line:hover { background: #141414; }

  .log-ts { color: #555; flex-shrink: 0; user-select: none; }

  .log-line.level-INFO    .log-msg { color: #c8c8c8; }
  .log-line.level-WARNING .log-msg { color: #e5a00d; }
  .log-line.level-ERROR   .log-msg { color: #e05050; }
  .log-line.level-DEBUG   .log-msg { color: #666; }
  .log-line.separator     .log-msg { color: #444; }

  .log-level {
    flex-shrink: 0;
    font-size: .7rem;
    font-weight: 700;
    padding: .05rem .35rem;
    border-radius: 3px;
    align-self: flex-start;
    margin-top: .15rem;
  }
  .level-INFO    .log-level { background: #1a2a3a; color: #6ab0e0; }
  .level-WARNING .log-level { background: #2a2000; color: #e5a00d; }
  .level-ERROR   .log-level { background: #2d0d0d; color: #e05050; }
  .level-DEBUG   .log-level { background: #1a1a1a; color: #555; }
  .separator     .log-level { display: none; }

  .empty { color: var(--muted); text-align: center; padding: 3rem; font-size: .9rem; }

  .level-select {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    padding: .35rem .6rem;
    font-size: .8rem;
    cursor: pointer;
    outline: none;
  }
  .level-select:focus { border-color: var(--accent); }
</style>
</head>
<body>

<header>
  <a class="logo" href="index.php">
    <img src="images/movarr-logo.svg" width="32" height="32" alt="Movarr" style="border-radius:6px">
    <span>Movarr</span>
  </a>
  <nav>
    <a href="index.php">Dashboard</a>
    <a href="config.php">Config</a>
    <a href="queue.php">Queue</a>
    <a href="logs.php" class="active">Logs</a>
  </nav>
</header>

<div class="container">

  <div class="toolbar">
    <a href="logs.php?level=<?= $sel_level ?>&limit=<?= $line_limit ?>" class="btn">&#8635; Refresh</a>
    <a href="logs.php?level=<?= $sel_level ?>&limit=<?= $line_limit ?>&refresh=1" class="btn <?= $auto_refresh ? 'active' : '' ?>">
      &#9711; Auto-refresh (15s)
    </a>
    <select class="level-select" id="level-filter" onchange="applyFilters()">
      <?php foreach (['INFO','ALL','DEBUG','WARNING','ERROR'] as $lvl): ?>
      <option value="<?= $lvl ?>" <?= $sel_level === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
      <?php endforeach; ?>
    </select>
    <input type="number" id="line-limit" class="level-select" value="<?= $line_limit ?>" min="1" max="10000" style="width:70px" title="Max lines" onchange="applyFilters()">
    <form method="POST" style="display:inline" onsubmit="return confirm('Clear the log file?')">
      <input type="hidden" name="action" value="clear">
      <button type="submit" class="btn btn-danger">&#128465; Clear Log</button>
    </form>
    <span class="toolbar-info">
      <?php if ($log_size > 0): ?>
        <?= count($log_lines) ?> lines shown &mdash; <?= number_format($log_size / 1024, 1) ?> KB total
        <?php if ($auto_refresh): ?> &mdash; <span id="countdown">15</span>s until refresh<?php endif; ?>
      <?php else: ?>
        Log file is empty
      <?php endif; ?>
    </span>
  </div>

  <div class="log-box">
    <?php if (empty($log_lines)): ?>
      <div class="empty">No log entries yet. Run the mover to generate output.</div>
    <?php else: ?>
      <?php foreach ($log_lines as $line): ?>
        <?php
        // Parse: "2024-01-15 03:00:01 [INFO] Some message" or separator lines
        $level = 'INFO';
        $ts    = '';
        $msg   = htmlspecialchars($line);

        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[(\w+)\] (.+)$/', $line, $matches)) {
            $ts    = $matches[1];
            $level = $matches[2];
            $msg   = htmlspecialchars($matches[3]);
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

</body>
</html>
