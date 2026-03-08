<?php
require_once __DIR__ . '/includes/settings.php';

$message = null;
$message_type = 'success';

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'trigger') {
        if (write_trigger()) {
            $message = 'Mover triggered. Check the Logs page for output.';
        } else {
            $message = 'Failed to write trigger file. Is the config volume mounted?';
            $message_type = 'error';
        }
    } elseif ($action === 'save') {
        // Build path mappings from parallel arrays
        $mappings = [];
        $ids = $_POST['mapping_id'] ?? [];
        foreach ($ids as $i => $id) {
            $mappings[] = [
                'id'               => $id ?: uniqid('map_'),
                'name'             => trim($_POST['mapping_name'][$i]             ?? ''),
                'service'          => $_POST['mapping_service'][$i]              ?? 'sonarr',
                'slow_path_mover'  => trim($_POST['mapping_slow_path_mover'][$i] ?? ''),
                'fast_path_mover'  => trim($_POST['mapping_fast_path_mover'][$i] ?? ''),
                'slow_path_sonarr' => trim($_POST['mapping_slow_path_sonarr'][$i]?? ''),
                'fast_path_sonarr' => trim($_POST['mapping_fast_path_sonarr'][$i]?? ''),
            ];
        }

        $settings = [
            'tautulli' => [
                'url'     => trim($_POST['tautulli_url'] ?? ''),
                'api_key' => trim($_POST['tautulli_api_key'] ?? ''),
            ],
            'sonarr' => [
                'url'     => trim($_POST['sonarr_url'] ?? ''),
                'api_key' => trim($_POST['sonarr_api_key'] ?? ''),
            ],
            'watched_days'  => max(1, (int)($_POST['watched_days'] ?? 30)),
            'dry_run'       => isset($_POST['dry_run']),
            'list_only'     => isset($_POST['list_only']),
            'cron_schedule' => trim($_POST['cron_schedule'] ?? '0 3 * * *'),
            'path_mappings' => $mappings,
        ];

        if (save_settings($settings)) {
            $message = 'Settings saved.';
        } else {
            $message = 'Failed to save settings. Check that the config volume is writable.';
            $message_type = 'error';
        }
    }
}

$s = load_settings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Config — Movarr</title>
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
    --red:      #e05050;
    --green:    #4caf7d;
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

  /* ── Nav ── */
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

  /* ── Layout ── */
  .container { max-width: 860px; margin: 0 auto; padding: 2rem 1.5rem; }

  .notice {
    padding: .85rem 1.1rem;
    border-radius: var(--radius);
    margin-bottom: 1.5rem;
    font-size: .9rem;
  }
  .notice.success { background: #0d2d1e; border: 1px solid #1e6644; color: #7debb0; }
  .notice.error   { background: #2d0d0d; border: 1px solid #662020; color: #eb7d7d; }

  /* ── Sections ── */
  .section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 1.5rem;
    overflow: hidden;
  }
  .section-header {
    padding: .85rem 1.25rem;
    border-bottom: 1px solid var(--border);
    font-size: .8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--accent);
    display: flex;
    align-items: center;
    gap: .5rem;
  }
  .section-body { padding: 1.25rem; }

  /* ── Form fields ── */
  .field-row {
    display: grid;
    grid-template-columns: 200px 1fr;
    align-items: center;
    gap: .75rem;
    margin-bottom: .9rem;
  }
  .field-row:last-child { margin-bottom: 0; }
  .field-row label { font-size: .875rem; color: var(--muted); text-align: right; }
  .field-hint { font-size: .75rem; color: var(--muted); margin-top: .25rem; }

  input[type="text"],
  input[type="password"],
  input[type="number"],
  select {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    padding: .5rem .75rem;
    font-size: .875rem;
    outline: none;
    transition: border-color .15s;
  }
  input[type="text"]:focus,
  input[type="password"]:focus,
  input[type="number"]:focus,
  select:focus { border-color: var(--accent); }
  select option { background: var(--surface); }

  .api-key-wrap {
    display: flex;
    align-items: center;
    gap: .4rem;
  }
  .api-key-wrap input { flex: 1; }
  .btn-eye {
    background: none;
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--muted);
    cursor: pointer;
    padding: .4rem .5rem;
    line-height: 1;
    flex-shrink: 0;
    transition: color .15s, border-color .15s;
  }
  .btn-eye:hover { color: var(--accent); border-color: var(--accent); }

  .toggle-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: .4rem 0;
  }
  .toggle-row label { font-size: .875rem; color: var(--muted); cursor: pointer; }
  input[type="checkbox"] { accent-color: var(--accent); width: 16px; height: 16px; cursor: pointer; }

  /* ── Path Mappings ── */
  .mapping-card {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1rem;
    margin-bottom: .75rem;
    position: relative;
  }
  .mapping-card-header {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin-bottom: 1rem;
  }
  .mapping-card-header input { font-weight: 600; }
  .mapping-card-header select { width: 130px; flex-shrink: 0; }
  .btn-remove {
    margin-left: auto;
    background: none;
    border: 1px solid #444;
    color: var(--red);
    border-radius: 6px;
    padding: .3rem .65rem;
    cursor: pointer;
    font-size: .8rem;
    flex-shrink: 0;
  }
  .btn-remove:hover { background: #2d0d0d; border-color: var(--red); }

  .path-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .75rem;
  }
  .path-group { display: flex; flex-direction: column; gap: .4rem; }
  .path-group-label {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--muted);
    padding-bottom: .3rem;
    border-bottom: 1px solid var(--border);
    margin-bottom: .1rem;
  }
  .path-row { display: flex; flex-direction: column; gap: .2rem; }
  .path-row span { font-size: .75rem; color: var(--muted); }

  @media (max-width: 600px) {
    .path-grid { grid-template-columns: 1fr; }
    .field-row { grid-template-columns: 1fr; }
    .field-row label { text-align: left; }
  }

  /* ── Buttons ── */
  .action-bar {
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
    align-items: center;
    margin-top: 1.5rem;
  }
  .btn {
    padding: .55rem 1.2rem;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: .875rem;
    font-weight: 600;
    transition: opacity .15s;
  }
  .btn:hover { opacity: .85; }
  .btn-primary { background: var(--accent); color: #000; }
  .btn-secondary { background: var(--surface2); border: 1px solid var(--border); color: var(--text); }
  .btn-run { background: #1e3d1e; border: 1px solid #2e6e2e; color: #7debb0; }
  .btn-run-real { background: #3d1e1e; border: 1px solid #7a2020; color: #eb7d7d; }
  .btn-add { background: none; border: 1px dashed var(--border); color: var(--muted); width: 100%; padding: .6rem; border-radius: var(--radius); cursor: pointer; font-size: .875rem; }
  .btn-add:hover { border-color: var(--accent); color: var(--accent); }
</style>
</head>
<body>

<header>
  <a class="logo" href="index.php">
    <svg width="28" height="28" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="16" fill="#E5A00D"/><path d="M11 8h4.5c3.6 0 6 2.2 6 5.5S19.1 19 15.5 19H14v5h-3V8zm3 8.5h1.3c1.9 0 3.1-1 3.1-3S17.2 10.5 15.3 10.5H14v6z" fill="#1a1a1a"/></svg>
    <span>Movarr</span>
  </a>
  <nav>
    <a href="index.php">Dashboard</a>
    <a href="config.php" class="active">Config</a>
    <a href="logs.php">Logs</a>
  </nav>
</header>

<div class="container">

  <?php if ($message): ?>
  <div class="notice <?= $message_type ?>">
    <?= htmlspecialchars($message) ?>
  </div>
  <?php endif; ?>

  <form method="POST" action="config.php">
  <input type="hidden" name="action" value="save" id="form-action">

  <!-- ── Tautulli ── -->
  <div class="section">
    <div class="section-header">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
      Tautulli
    </div>
    <div class="section-body">
      <div class="field-row">
        <label>URL</label>
        <input type="text" name="tautulli_url" value="<?= htmlspecialchars($s['tautulli']['url']) ?>" placeholder="http://vm-plex.home:8181">
      </div>
      <div class="field-row">
        <label>API Key</label>
        <div class="api-key-wrap">
          <input type="password" id="tautulli_api_key" name="tautulli_api_key" value="<?= htmlspecialchars($s['tautulli']['api_key']) ?>" placeholder="Settings → Web Interface → API Key">
          <button type="button" class="btn-eye" onclick="toggleKey('tautulli_api_key', this)" title="Show/hide">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Sonarr ── -->
  <div class="section">
    <div class="section-header">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M21 3H3a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5v2h8v-2h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 14H3V5h18v12z"/></svg>
      Sonarr
    </div>
    <div class="section-body">
      <div class="field-row">
        <label>URL</label>
        <input type="text" name="sonarr_url" value="<?= htmlspecialchars($s['sonarr']['url']) ?>" placeholder="http://vm-plex.home:8989">
      </div>
      <div class="field-row">
        <label>API Key</label>
        <div class="api-key-wrap">
          <input type="password" id="sonarr_api_key" name="sonarr_api_key" value="<?= htmlspecialchars($s['sonarr']['api_key']) ?>" placeholder="Settings → General → API Key">
          <button type="button" class="btn-eye" onclick="toggleKey('sonarr_api_key', this)" title="Show/hide">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Schedule & Options ── -->
  <div class="section">
    <div class="section-header">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg>
      Schedule &amp; Options
    </div>
    <div class="section-body">
      <div class="field-row">
        <label>Watched Days</label>
        <div>
          <input type="number" name="watched_days" value="<?= (int)$s['watched_days'] ?>" min="1" max="365" style="width:100px">
          <div class="field-hint">Shows watched within this many days are considered active.</div>
        </div>
      </div>
      <div class="field-row">
        <label>Cron Schedule</label>
        <div>
          <input type="text" name="cron_schedule" value="<?= htmlspecialchars($s['cron_schedule']) ?>" placeholder="0 3 * * *" style="width:200px">
          <div class="field-hint">Standard 5-field cron expression. Default: daily at 3am.</div>
        </div>
      </div>
      <div class="field-row">
        <label>Dry Run</label>
        <div class="toggle-row">
          <input type="checkbox" id="dry_run" name="dry_run" <?= $s['dry_run'] ? 'checked' : '' ?>>
          <label for="dry_run">Simulate moves via rsync — no files will be touched</label>
        </div>
      </div>
      <div class="field-row">
        <label>List Only</label>
        <div class="toggle-row">
          <input type="checkbox" id="list_only" name="list_only" <?= $s['list_only'] ? 'checked' : '' ?>>
          <label for="list_only">Fetch decisions and log what would move — skips rsync entirely</label>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Path Mappings ── -->
  <div class="section">
    <div class="section-header">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2z"/></svg>
      Path Mappings
    </div>
    <div class="section-body">
      <p style="font-size:.8rem;color:var(--muted);margin-bottom:1rem">
        Define where each service stores media and the corresponding paths inside the <strong>mover container</strong> and the <strong>service container</strong> (these may differ if you mount volumes at different paths).
      </p>

      <div id="mappings-container">
        <?php foreach ($s['path_mappings'] as $m): ?>
        <?php include __DIR__ . '/includes/mapping_row.php'; ?>
        <?php endforeach; ?>
      </div>

      <button type="button" class="btn-add" onclick="addMapping()">+ Add Path Mapping</button>
    </div>
  </div>

  <!-- ── Action Bar ── -->
  <div class="action-bar">
    <button type="submit" class="btn btn-primary">Save Settings</button>
    <button type="button" class="btn btn-run" onclick="triggerMover(false)">&#9654; Run Now (Dry)</button>
    <button type="button" class="btn btn-run-real" onclick="triggerMover(true)">&#9654; Run Now (Real)</button>
    <span style="font-size:.78rem;color:var(--muted);margin-left:auto">Settings saved to /config/settings.json</span>
  </div>

  </form>

</div><!-- .container -->

<!-- ── Mapping row template (hidden) ── -->
<template id="mapping-template">
  <?php
  $m = [
    'id' => '',
    'name' => '',
    'service' => 'sonarr',
    'slow_path_mover' => '',
    'fast_path_mover' => '',
    'slow_path_sonarr' => '',
    'fast_path_sonarr' => '',
  ];
  ob_start();
  include __DIR__ . '/includes/mapping_row.php';
  $template_html = ob_get_clean();
  ?>
  <?= $template_html ?>
</template>

<script>
function addMapping() {
  const tpl = document.getElementById('mapping-template').content.cloneNode(true);
  // Give the new row a fresh unique id
  tpl.querySelector('input[name="mapping_id[]"]').value = 'map_' + Date.now();
  document.getElementById('mappings-container').appendChild(tpl);
}

function removeMapping(btn) {
  btn.closest('.mapping-card').remove();
}

function triggerMover(real) {
  if (real && !confirm('This will REALLY move files. Are you sure?')) return;
  // Save first, then trigger
  document.getElementById('form-action').value = 'trigger';
  document.querySelector('form').submit();
}

function toggleKey(id, btn) {
  const input = document.getElementById(id);
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  btn.style.color = showing ? '' : 'var(--accent)';
  btn.style.borderColor = showing ? '' : 'var(--accent)';
}

// Reset action to 'save' when save button is clicked directly
document.querySelector('.btn-primary').addEventListener('click', function() {
  document.getElementById('form-action').value = 'save';
});
</script>

</body>
</html>
