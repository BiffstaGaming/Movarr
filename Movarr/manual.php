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

            db_queue_move($db, $ext_id, $service, $mapping_id, $direction, $notes);
            // Write the MANUAL trigger — runs manual_move.py only, ignores dry-run
            file_put_contents(manual_trigger_file(), date('c'));

            $label    = $direction === 'to_fast' ? '→ Fast' : '← Slow';
            $message  = ($title ? "\"$title\"" : "ID $ext_id") . " queued ($label). Mover triggered.";
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['track_id'] ?? 0);
        if ($id) { db_delete_tracked($db, $id); $message = 'Entry removed.'; }

    } elseif ($action === 'pin') {
        $id = (int)($_POST['track_id'] ?? 0);
        if ($id) { db_pin_tracked($db, $id); $message = 'Pinned — will not auto-relocate.'; }

    } elseif ($action === 'set_relocate') {
        $id = (int)($_POST['track_id'] ?? 0);
        $ts = strtotime($_POST['relocate_date'] ?? '');
        if ($id && $ts) {
            db_set_relocate($db, $id, $ts);
            $message = 'Relocate date updated.';
        } else {
            $message  = 'Invalid date.';
            $msg_type = 'error';
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

function fmt_ts(?int $ts): string
{
    if (!$ts) return '—';
    return date('Y-m-d', $ts);
}

function days_left(?int $relocate_after): string
{
    if ($relocate_after === null) return '<span style="color:var(--accent)">Pinned</span>';
    $diff = $relocate_after - time();
    if ($diff <= 0) return '<span style="color:var(--red)">Expired</span>';
    $d = round($diff / 86400);
    return '<span style="color:var(--green)">' . $d . 'd left</span>';
}

// ── Data for the page ──────────────────────────────────────────────────────────
$tracked  = $db ? db_all_tracked($db) : [];
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

<div style="display:grid;grid-template-columns:340px 1fr;gap:1.5rem;align-items:start;max-width:1100px">

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
          <input type="number" name="external_id" id="external_id"
                 placeholder="e.g. 305074" min="1" required>
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

  <!-- ── Tracked media table ── -->
  <div>
    <div class="section-label">
      Tracked Media
      <?php if (!empty($tracked)): ?>
        <span class="badge badge-muted" style="margin-left:.4rem"><?= count($tracked) ?></span>
      <?php endif; ?>
    </div>
    <div class="card">
      <?php if (empty($tracked)): ?>
        <div class="empty">Nothing tracked yet.<br>Moves will appear here once the mover runs.</div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th></th>
            <th>Title</th>
            <th>ID</th>
            <th>Mapping</th>
            <th>Location</th>
            <th>Moved</th>
            <th>Relocates</th>
            <th>Source</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tracked as $row):
          $on_fast = $row['current_location'] === 'fast';
          $mapping_name = '';
          foreach ($mappings as $m) {
              if ($m['id'] === $row['mapping_id']) { $mapping_name = $m['name'] ?: $m['id']; break; }
          }
          if (!$mapping_name) $mapping_name = $row['mapping_id'];
        ?>
          <tr>
            <td style="width:24px">
              <span class="dot <?= $on_fast ? 'dot-green' : 'dot-muted' ?>"></span>
            </td>
            <td>
              <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($row['title'] ?: '—') ?></div>
              <div style="font-size:.7rem;color:var(--muted)"><?= htmlspecialchars($row['folder'] ?: '') ?></div>
            </td>
            <td style="font-size:.75rem;color:var(--muted);font-family:monospace">
              <span style="font-size:.68rem;color:var(--muted)"><?= $row['service'] === 'sonarr' ? 'TVDB' : 'TMDB' ?></span><br>
              <?= htmlspecialchars((string)$row['external_id']) ?>
            </td>
            <td style="font-size:.78rem;color:var(--muted)"><?= htmlspecialchars($mapping_name) ?></td>
            <td>
              <?php if ($on_fast): ?>
                <span style="color:var(--green);font-weight:700;font-size:.82rem">&#8594; Fast</span>
              <?php else: ?>
                <span style="color:var(--muted);font-weight:700;font-size:.82rem">&#8592; Slow</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.78rem;color:var(--muted)"><?= fmt_ts($row['moved_at']) ?></td>
            <td style="font-size:.78rem"><?= days_left($row['relocate_after']) ?></td>
            <td>
              <span class="badge <?= $row['source'] === 'manual' ? 'badge-amber' : 'badge-muted' ?>">
                <?= htmlspecialchars($row['source']) ?>
              </span>
            </td>
            <td style="white-space:nowrap">
              <!-- Pin / set date / remove -->
              <div style="display:flex;gap:.3rem;align-items:center">
                <?php if ($row['relocate_after'] !== null): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="pin">
                  <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
                  <button type="submit" class="btn" style="padding:.25rem .5rem;font-size:.7rem" title="Pin (never auto-relocate)">&#128204;</button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display:inline" id="relocate-form-<?= $row['id'] ?>">
                  <input type="hidden" name="action" value="set_relocate">
                  <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
                  <input type="date" name="relocate_date"
                         value="<?= $row['relocate_after'] ? date('Y-m-d', $row['relocate_after']) : '' ?>"
                         style="width:110px;font-size:.72rem;padding:.2rem .4rem"
                         onchange="document.getElementById('relocate-form-<?= $row['id'] ?>').submit()"
                         title="Set relocate date">
                </form>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Remove this entry from tracking?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="track_id" value="<?= $row['id'] ?>">
                  <button type="submit" class="btn btn-danger" style="padding:.25rem .5rem;font-size:.7rem" title="Remove">&#10005;</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
function updateIdLabel(radio) {
  document.getElementById('id_label').textContent = radio.value === 'radarr' ? 'TMDB ID' : 'TVDB ID';
  document.getElementById('external_id').placeholder = radio.value === 'radarr' ? 'e.g. 550' : 'e.g. 305074';
}
</script>

<?php layout_end(); ?>
