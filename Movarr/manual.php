<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db.php';

$s       = load_settings();
$message = null;
$msg_type = 'success';

// ── DB connection ──────────────────────────────────────────────────────────────
$db = null;
$db_error = null;
try {
    $db = db_connect();
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

// ── POST handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    $action = $_POST['action'] ?? '';

    if ($action === 'queue_move') {
        $service    = in_array($_POST['service'] ?? '', ['sonarr','radarr']) ? $_POST['service'] : 'sonarr';
        $ext_id     = (int)($_POST['external_id'] ?? 0);
        $mapping_id = trim($_POST['mapping_id'] ?? '');
        $direction  = in_array($_POST['direction'] ?? '', ['to_fast','to_slow']) ? $_POST['direction'] : 'to_fast';
        $notes      = trim($_POST['notes'] ?? '');

        if (!$ext_id) {
            $message  = 'Please enter a valid ' . ($service === 'sonarr' ? 'TVDB' : 'TMDB') . ' ID.';
            $msg_type = 'error';
        } elseif (!$mapping_id) {
            $message  = 'Please select a path mapping.';
            $msg_type = 'error';
        } else {
            // Optionally resolve title from Sonarr/Radarr now so the UI looks nice
            $title = resolve_title($s, $service, $ext_id);

            db_queue_move($db, $ext_id, $service, $mapping_id, $direction, $notes, $title ?? '');
            // Write the MANUAL trigger — runs manual_move.py only, ignores dry-run
            file_put_contents(manual_trigger_file(), date('c'));

            $label    = $direction === 'to_fast' ? '→ Fast' : '← Slow';
            $message  = ($title ? "\"$title\"" : "ID $ext_id") . " queued ($label). Mover triggered.";
        }

    }
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function resolve_title(array $s, string $service, int $ext_id): ?string
{
    try {
        if ($service === 'sonarr' && ($s['sonarr']['url'] ?? '')) {
            $url = rtrim($s['sonarr']['url'], '/') . '/api/v3/series?tvdbId=' . $ext_id;
            $ctx = stream_context_create(['http' => [
                'timeout' => 8,
                'header'  => 'X-Api-Key: ' . $s['sonarr']['api_key'] . "\r\n",
            ]]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw) {
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data[0]['title'])) return $data[0]['title'];
            }
        } elseif ($service === 'radarr' && ($s['radarr']['url'] ?? '')) {
            $url = rtrim($s['radarr']['url'], '/') . '/api/v3/movie?tmdbId=' . $ext_id;
            $ctx = stream_context_create(['http' => [
                'timeout' => 8,
                'header'  => 'X-Api-Key: ' . $s['radarr']['api_key'] . "\r\n",
            ]]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw) {
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data[0]['title'])) return $data[0]['title'];
            }
        }
    } catch (Exception $e) {}
    return null;
}

// ── Data for the page ──────────────────────────────────────────────────────────
$pending  = $db ? db_pending_moves($db) : [];
$mappings = $s['path_mappings'] ?? [];

layout_start('Manual Move', 'manual');
?>

<?php if ($db_error): ?>
<div class="notice notice-error">Database unavailable: <?= htmlspecialchars($db_error) ?> — is the pdo_sqlite PHP extension installed?</div>
<?php endif; ?>

<?php if ($message): ?>
<div class="notice notice-<?= $msg_type === 'error' ? 'error' : 'success' ?>">
  <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div style="max-width:480px">

  <!-- ── Queue Move form ── -->
  <div>
    <div class="section-label">Queue a Manual Move</div>
    <div class="card">
      <div style="padding:.85rem 1rem">
        <form method="POST" action="manual.php">
        <input type="hidden" name="action" value="queue_move">

        <div class="field-row" style="grid-template-columns:90px 1fr">
          <label>Service</label>
          <div style="display:flex;gap:.5rem">
            <label style="display:flex;align-items:center;gap:.35rem;color:var(--text);cursor:pointer;padding-top:0">
              <input type="radio" name="service" value="sonarr" id="svc_sonarr"
                     onchange="updateIdLabel(this)" checked style="width:auto;accent-color:var(--accent)">
              Sonarr
            </label>
            <label style="display:flex;align-items:center;gap:.35rem;color:var(--text);cursor:pointer;padding-top:0">
              <input type="radio" name="service" value="radarr" id="svc_radarr"
                     onchange="updateIdLabel(this)" style="width:auto;accent-color:var(--accent)">
              Radarr
            </label>
          </div>
        </div>

        <div class="field-row" style="grid-template-columns:90px 1fr">
          <label id="id_label">TVDB ID</label>
          <input type="text" name="external_id" id="external_id"
                 inputmode="numeric" pattern="[0-9]+"
                 placeholder="e.g. 305074" required>
        </div>

        <div class="field-row" style="grid-template-columns:90px 1fr">
          <label>Mapping</label>
          <?php if (empty($mappings)): ?>
            <span style="font-size:.8rem;color:var(--muted)">No mappings configured — <a href="config.php" style="color:var(--accent)">add one</a></span>
          <?php else: ?>
          <select name="mapping_id">
            <?php foreach ($mappings as $m): ?>
            <option value="<?= htmlspecialchars($m['id']) ?>">
              <?= htmlspecialchars($m['name'] ?: $m['id']) ?>
              (<?= htmlspecialchars(ucfirst($m['service'] ?? 'sonarr')) ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>

        <div class="field-row" style="grid-template-columns:90px 1fr">
          <label>Direction</label>
          <div style="display:flex;gap:.5rem">
            <label style="display:flex;align-items:center;gap:.35rem;color:var(--green);cursor:pointer;padding-top:0">
              <input type="radio" name="direction" value="to_fast" checked
                     style="width:auto;accent-color:var(--green)">
              &#8594; Fast
            </label>
            <label style="display:flex;align-items:center;gap:.35rem;color:var(--muted);cursor:pointer;padding-top:0">
              <input type="radio" name="direction" value="to_slow"
                     style="width:auto;accent-color:var(--muted)">
              &#8592; Slow
            </label>
          </div>
        </div>

        <div class="field-row" style="grid-template-columns:90px 1fr">
          <label>Notes</label>
          <input type="text" name="notes" placeholder="optional" maxlength="200">
        </div>

        <div style="margin-top:.85rem;display:flex;gap:.5rem">
          <button type="submit" class="btn btn-primary" <?= empty($mappings) ? 'disabled' : '' ?>>
            Queue &amp; Trigger
          </button>
        </div>
        </form>

        <div style="margin-top:.85rem;font-size:.75rem;color:var(--muted);border-top:1px solid var(--border);padding-top:.75rem">
          The mover will process this as soon as the scheduled job runs (or immediately if triggered now). The <?= (int)$s['watched_days'] ?>-day window starts from the move date; the nightly job extends it while the show is still being watched.
        </div>
      </div>
    </div>

    <?php if (!empty($pending)): ?>
    <div class="section-label" style="margin-top:1.25rem">Pending (<?= count($pending) ?>)</div>
    <div class="card">
      <?php foreach ($pending as $pm): ?>
      <div style="padding:.6rem .85rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.8rem;display:flex;align-items:center;gap:.5rem">
        <span class="dot dot-amber"></span>
        <span style="color:var(--muted)"><?= $pm['service'] === 'sonarr' ? 'TVDB' : 'TMDB' ?></span>
        <strong><?= $pm['external_id'] ?></strong>
        <span class="badge <?= $pm['direction'] === 'to_fast' ? 'badge-green' : 'badge-muted' ?>">
          <?= $pm['direction'] === 'to_fast' ? '→ Fast' : '← Slow' ?>
        </span>
        <?php if ($pm['notes']): ?>
          <span style="color:var(--muted);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($pm['notes']) ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<div style="margin-top:1rem;font-size:.8rem;color:var(--muted)">
  View and manage all tracked media on the <a href="tracked.php" style="color:var(--accent)">Tracked Media</a> page.
</div>

<script>
function updateIdLabel(radio) {
  document.getElementById('id_label').textContent = radio.value === 'radarr' ? 'TMDB ID' : 'TVDB ID';
  document.getElementById('external_id').placeholder = radio.value === 'radarr' ? 'e.g. 550' : 'e.g. 305074';
}
</script>

<?php layout_end(); ?>
