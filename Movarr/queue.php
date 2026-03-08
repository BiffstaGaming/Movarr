<?php
require_once __DIR__ . '/includes/settings.php';

$qf   = queue_file();
$data = null;

if (file_exists($qf)) {
    $raw = json_decode(file_get_contents($qf), true);
    if (is_array($raw)) {
        $data = $raw;
    }
}

$is_running = $data && $data['completed'] === null;

$mode_labels = [
    'real'      => 'Real Move',
    'dry_run'   => 'Dry Run',
    'list_only' => 'List Only',
];

$status_cfg = [
    'pending' => ['label' => 'Pending',  'color' => '#888',    'bg' => '#222'],
    'moving'  => ['label' => 'Moving',   'color' => '#e5a00d', 'bg' => '#2a2000'],
    'done'    => ['label' => 'Done',     'color' => '#4caf7d', 'bg' => '#0d2d1e'],
    'error'   => ['label' => 'Error',    'color' => '#e05050', 'bg' => '#2d0d0d'],
    'skipped' => ['label' => 'Skipped',  'color' => '#888',    'bg' => '#1a1a1a'],
];

// Tally counts
$counts = ['pending' => 0, 'moving' => 0, 'done' => 0, 'error' => 0, 'skipped' => 0];
foreach (($data['items'] ?? []) as $item) {
    $s = $item['status'] ?? 'pending';
    if (isset($counts[$s])) $counts[$s]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Queue — Movarr</title>
<?php if ($is_running): ?>
<meta http-equiv="refresh" content="5">
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
    color: var(--muted); text-decoration: none;
    padding: .35rem .8rem; border-radius: 6px;
    font-size: .875rem; font-weight: 500;
    transition: color .15s, background .15s;
  }
  nav a:hover { color: var(--text); background: var(--surface2); }
  nav a.active { color: var(--accent); background: #2a2200; }

  .container { max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem; }

  .run-header {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1.5rem;
    flex-wrap: wrap;
  }
  .run-title { font-size: 1rem; font-weight: 700; color: var(--accent); }
  .run-meta { font-size: .8rem; color: var(--muted); }
  .run-meta strong { color: var(--text); }

  .pulse {
    display: inline-block;
    width: 10px; height: 10px;
    border-radius: 50%;
    background: var(--accent);
    animation: pulse 1.2s ease-in-out infinite;
    flex-shrink: 0;
  }
  @keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: .4; transform: scale(.85); }
  }

  .tally {
    display: flex;
    gap: .5rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
  }
  .tally-pill {
    padding: .25rem .7rem;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 700;
    border: 1px solid transparent;
  }

  .mode-badge {
    padding: .2rem .6rem;
    border-radius: 4px;
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-left: auto;
  }
  .mode-real      { background: #2d0d0d; color: #e05050; border: 1px solid #662020; }
  .mode-dry_run   { background: #1a2a3a; color: #6ab0e0; border: 1px solid #1e4a6a; }
  .mode-list_only { background: #1a1a1a; color: #888;    border: 1px solid #333; }

  table {
    width: 100%;
    border-collapse: collapse;
    font-size: .875rem;
  }
  thead th {
    text-align: left;
    padding: .6rem .75rem;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
  }
  tbody tr { border-bottom: 1px solid #1a1a1a; }
  tbody tr:hover { background: var(--surface2); }
  td { padding: .6rem .75rem; vertical-align: middle; }

  .item-name { font-weight: 600; }
  .item-path { font-size: .72rem; color: var(--muted); font-family: monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 320px; }
  .item-progress { font-size: .75rem; color: var(--muted); }

  .status-badge {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .2rem .55rem; border-radius: 4px;
    font-size: .72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em;
    white-space: nowrap;
  }

  .dir-fast { color: #4caf7d; font-weight: 700; font-size: .85rem; }
  .dir-slow { color: #888;    font-weight: 700; font-size: .85rem; }

  .svc-badge {
    font-size: .7rem; font-weight: 700;
    padding: .1rem .4rem; border-radius: 3px;
    text-transform: uppercase; letter-spacing: .04em;
  }
  .svc-sonarr { background: #1a2a3a; color: #6ab0e0; }
  .svc-radarr { background: #2a1a2a; color: #c07de0; }

  .empty {
    text-align: center; padding: 4rem;
    color: var(--muted); font-size: .9rem;
  }
  .empty a { color: var(--accent); }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
  }

  @media (max-width: 700px) {
    .item-path { display: none; }
    td:nth-child(4) { display: none; }
  }
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
    <a href="queue.php" class="active">Queue</a>
    <a href="logs.php">Logs</a>
  </nav>
</header>

<div class="container">

<?php if (!$data): ?>
  <div class="empty">
    No queue data yet.<br>
    <a href="config.php">Run the mover</a> to see activity here.
  </div>
<?php else: ?>

  <!-- Run header -->
  <div class="run-header">
    <?php if ($is_running): ?>
      <span class="pulse"></span>
      <span class="run-title">Run in progress</span>
    <?php else: ?>
      <span class="run-title">Last Run</span>
    <?php endif; ?>

    <div class="run-meta">
      <strong>Started:</strong> <?= htmlspecialchars($data['started']) ?>
      <?php if ($data['completed']): ?>
        &nbsp;&mdash;&nbsp;<strong>Completed:</strong> <?= htmlspecialchars($data['completed']) ?>
      <?php endif; ?>
    </div>

    <span class="mode-badge mode-<?= htmlspecialchars($data['mode']) ?>">
      <?= htmlspecialchars($mode_labels[$data['mode']] ?? $data['mode']) ?>
    </span>

    <?php if ($is_running): ?>
      <span style="font-size:.75rem;color:var(--muted);margin-left:auto">Auto-refreshing every 5s</span>
    <?php endif; ?>
  </div>

  <!-- Tally pills -->
  <?php if (!empty($data['items'])): ?>
  <div class="tally">
    <?php foreach ($status_cfg as $key => $cfg): ?>
      <?php if ($counts[$key] > 0): ?>
      <span class="tally-pill" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;border-color:<?= $cfg['color'] ?>33">
        <?= $counts[$key] ?> <?= $cfg['label'] ?>
      </span>
      <?php endif; ?>
    <?php endforeach; ?>
    <span class="tally-pill" style="background:var(--surface);color:var(--muted);border-color:var(--border)">
      <?= count($data['items']) ?> total
    </span>
  </div>
  <?php endif; ?>

  <!-- Items table -->
  <div class="card">
    <?php if (empty($data['items'])): ?>
      <div class="empty">
        No items queued for this run.
        <?php if ($data['mode'] === 'list_only'): ?>
          <br><small>List Only mode — no moves were planned.</small>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Status</th>
          <th>Name</th>
          <th>Service</th>
          <th>Direction</th>
          <th>Mapping</th>
          <th>Progress</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data['items'] as $item):
          $status = $item['status'] ?? 'pending';
          $cfg    = $status_cfg[$status] ?? $status_cfg['pending'];
          $is_to_fast = ($item['direction'] ?? '') === 'to_fast';
        ?>
        <tr>
          <td>
            <span class="status-badge" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>">
              <?php if ($status === 'moving'): ?><span class="pulse" style="width:7px;height:7px;margin-right:2px"></span><?php endif; ?>
              <?= $cfg['label'] ?>
            </span>
          </td>
          <td>
            <div class="item-name"><?= htmlspecialchars($item['name'] ?? '') ?></div>
            <div class="item-path"><?= htmlspecialchars($is_to_fast ? ($item['src'] ?? '') : ($item['dst'] ?? '')) ?></div>
          </td>
          <td>
            <span class="svc-badge svc-<?= htmlspecialchars($item['service'] ?? 'sonarr') ?>">
              <?= htmlspecialchars(ucfirst($item['service'] ?? 'sonarr')) ?>
            </span>
          </td>
          <td>
            <?php if ($is_to_fast): ?>
              <span class="dir-fast">&#8594; Fast</span>
            <?php else: ?>
              <span class="dir-slow">&#8592; Slow</span>
            <?php endif; ?>
          </td>
          <td style="color:var(--muted);font-size:.8rem"><?= htmlspecialchars($item['mapping'] ?? '') ?></td>
          <td class="item-progress"><?= htmlspecialchars($item['progress'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

<?php endif; ?>

</div>

</body>
</html>
