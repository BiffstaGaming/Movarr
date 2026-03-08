<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/layout.php';

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
            'radarr' => [
                'url'     => trim($_POST['radarr_url'] ?? ''),
                'api_key' => trim($_POST['radarr_api_key'] ?? ''),
            ],
            'plex' => [
                'url'   => trim($_POST['plex_url'] ?? ''),
                'token' => trim($_POST['plex_token'] ?? ''),
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
layout_start('Settings', 'config');
?>

<div style="max-width:820px">

<?php if ($message): ?>
<div class="notice notice-<?= $message_type === 'error' ? 'error' : 'success' ?>">
  <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<form method="POST" action="config.php">
<input type="hidden" name="action" value="save" id="form-action">

<!-- ── Tautulli ── -->
<div class="card" style="margin-bottom:.75rem">
  <div class="card-header">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
    Tautulli
  </div>
  <div style="padding:.75rem 1rem">
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
<div class="card" style="margin-bottom:.75rem">
  <div class="card-header">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M21 3H3a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5v2h8v-2h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 14H3V5h18v12z"/></svg>
    Sonarr
  </div>
  <div style="padding:.75rem 1rem">
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

<!-- ── Radarr ── -->
<div class="card" style="margin-bottom:.75rem">
  <div class="card-header">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V4h-4z"/></svg>
    Radarr
  </div>
  <div style="padding:.75rem 1rem">
    <div class="field-row">
      <label>URL</label>
      <input type="text" name="radarr_url" value="<?= htmlspecialchars($s['radarr']['url']) ?>" placeholder="http://vm-plex.home:7878">
    </div>
    <div class="field-row">
      <label>API Key</label>
      <div class="api-key-wrap">
        <input type="password" id="radarr_api_key" name="radarr_api_key" value="<?= htmlspecialchars($s['radarr']['api_key']) ?>" placeholder="Settings → General → API Key">
        <button type="button" class="btn-eye" onclick="toggleKey('radarr_api_key', this)" title="Show/hide">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Plex ── -->
<div class="card" style="margin-bottom:.75rem">
  <div class="card-header">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/></svg>
    Plex
  </div>
  <div style="padding:.75rem 1rem">
    <div class="field-row">
      <label>URL</label>
      <input type="text" name="plex_url" value="<?= htmlspecialchars($s['plex']['url']) ?>" placeholder="http://vm-plex.home:32400">
    </div>
    <div class="field-row">
      <label>Token</label>
      <div class="api-key-wrap">
        <input type="password" id="plex_token" name="plex_token" value="<?= htmlspecialchars($s['plex']['token']) ?>" placeholder="Settings → Troubleshooting → X-Plex-Token">
        <button type="button" class="btn-eye" onclick="toggleKey('plex_token', this)" title="Show/hide">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
      <div class="field-hint" style="grid-column:2">Used to trigger a library refresh after every move. Find your token at: Plex Web → Settings → Troubleshooting → Show → X-Plex-Token.</div>
    </div>
  </div>
</div>

<!-- ── Schedule & Options ── -->
<div class="card" style="margin-bottom:.75rem">
  <div class="card-header">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg>
    Schedule &amp; Options
  </div>
  <div style="padding:.75rem 1rem">
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
      <div class="toggle-wrap">
        <input type="checkbox" id="dry_run" name="dry_run" <?= $s['dry_run'] ? 'checked' : '' ?>>
        <label for="dry_run">Simulate moves via rsync — no files will be touched</label>
      </div>
    </div>
    <div class="field-row">
      <label>List Only</label>
      <div class="toggle-wrap">
        <input type="checkbox" id="list_only" name="list_only" <?= $s['list_only'] ? 'checked' : '' ?>>
        <label for="list_only">Fetch decisions and log what would move — skips rsync entirely</label>
      </div>
    </div>
  </div>
</div>

<!-- ── Path Mappings ── -->
<div class="card" style="margin-bottom:.75rem">
  <div class="card-header">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2z"/></svg>
    Path Mappings
  </div>
  <div style="padding:.75rem 1rem">
    <p style="font-size:.8rem;color:var(--muted);margin-bottom:.75rem">
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
  <button type="button" class="btn btn-success" onclick="triggerMover(false)">&#9654; Run Now (Dry)</button>
  <button type="button" class="btn btn-danger" onclick="triggerMover(true)">&#9654; Run Now (Real)</button>
  <span style="font-size:.75rem;color:var(--muted);margin-left:auto">Saved to /config/settings.json</span>
</div>

</form>

</div><!-- max-width wrapper -->

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
  tpl.querySelector('input[name="mapping_id[]"]').value = 'map_' + Date.now();
  document.getElementById('mappings-container').appendChild(tpl);
}

function removeMapping(btn) {
  btn.closest('.mapping-card').remove();
}

function triggerMover(real) {
  if (real && !confirm('This will REALLY move files. Are you sure?')) return;
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

document.querySelector('.btn-primary').addEventListener('click', function() {
  document.getElementById('form-action').value = 'save';
});
</script>

<?php layout_end(); ?>
