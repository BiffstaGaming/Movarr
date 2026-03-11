<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db.php';

// ── POST: cancel a pending move ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_pending') {
    $pm_id = (int)($_POST['pm_id'] ?? 0);
    if ($pm_id) {
        try {
            $db = db_connect();
            $db->prepare("DELETE FROM pending_moves WHERE id=? AND status='pending'")
               ->execute([$pm_id]);
        } catch (Exception $e) {}
    }
    header('Location: queue.php'); exit;
}

// ── queue.json ────────────────────────────────────────────────────────────────
$qf   = queue_file();
$data = null;
if (file_exists($qf)) {
    $raw = json_decode(file_get_contents($qf), true);
    if (is_array($raw)) $data = $raw;
}

// Active items from queue.json (pending/moving)
$json_items = [];
foreach (($data['items'] ?? []) as $item) {
    $s = $item['status'] ?? '';
    if ($s === 'pending' || $s === 'moving') {
        $json_items[] = $item;
    }
}

// ── DB pending manual moves ───────────────────────────────────────────────────
$s = load_settings();
$map_names = [];
foreach ($s['path_mappings'] ?? [] as $m) {
    $map_names[$m['id']] = $m['name'] ?: $m['id'];
}

$db_pending = [];
try {
    $db = db_connect();
    $db_pending = $db->query("SELECT * FROM pending_moves WHERE status='pending' ORDER BY requested_at ASC")
                     ->fetchAll();
} catch (Exception $e) {}

// Combine: DB pending (Queued) first, then json items (active/moving)
$has_anything = !empty($db_pending) || !empty($json_items);
$is_running   = !empty($json_items);

$mode_labels  = ['real' => 'Real', 'dry_run' => 'Dry Run', 'list_only' => 'List Only'];
$mode_classes = ['real' => 'badge-red', 'dry_run' => 'badge-blue', 'list_only' => 'badge-muted'];

// Auto-refresh if there's anything active
$extra_head = ($has_anything) ? '<meta http-equiv="refresh" content="4">' : '';

layout_start('Queue', 'queue', $extra_head);
?>

<?php if ($is_running): ?>
<!-- Active run banner -->
<div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;padding:.5rem .75rem;background:rgba(229,160,13,.08);border:1px solid rgba(229,160,13,.2);border-radius:4px;">
  <span class="dot dot-amber"></span>
  <span style="font-size:.85rem;font-weight:600;color:var(--accent)">Transfer in progress</span>
  <span style="font-size:.75rem;color:var(--muted);margin-left:.25rem">
    <?= count($json_items) ?> item<?= count($json_items) !== 1 ? 's' : '' ?> active
  </span>
  <?php if ($data && isset($data['mode'])): ?>
  <span class="badge <?= $mode_classes[$data['mode']] ?? 'badge-muted' ?>" style="margin-left:.25rem">
    <?= htmlspecialchars($mode_labels[$data['mode']] ?? $data['mode']) ?>
  </span>
  <?php endif; ?>
  <span style="font-size:.7rem;color:var(--muted);margin-left:auto">Auto-refreshing every 4s</span>
</div>
<?php elseif (!empty($db_pending)): ?>
<div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;padding:.5rem .75rem;background:rgba(80,80,200,.07);border:1px solid rgba(100,120,220,.18);border-radius:4px;">
  <span class="dot dot-muted"></span>
  <span style="font-size:.85rem;font-weight:600;color:var(--muted)">
    <?= count($db_pending) ?> manual move<?= count($db_pending) !== 1 ? 's' : '' ?> queued — waiting for mover to run
  </span>
  <span style="font-size:.7rem;color:var(--muted);margin-left:auto">Auto-refreshing every 4s</span>
</div>
<?php endif; ?>

<div class="card">
  <?php if (!$has_anything): ?>
    <div class="empty">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin-bottom:.75rem;color:var(--muted)"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      <div>No active transfers</div>
      <div style="font-size:.8rem;margin-top:.35rem">
        Files move here when a job is running. &nbsp;<a href="config.php" style="color:var(--accent)">Run Now</a> or check
        <a href="history.php" style="color:var(--accent)">History</a>.
      </div>
    </div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th style="width:16px"></th>
        <th>Title</th>
        <th>Service</th>
        <th>Direction</th>
        <th>Mapping</th>
        <th>Status</th>
        <th>Queued / Started</th>
        <th></th>
      </tr>
    </thead>
    <tbody>

      <!-- DB pending manual moves (waiting for mover to pick up) -->
      <?php foreach ($db_pending as $pm):
        $to_fast = $pm['direction'] === 'to_fast';
        $svc     = $pm['service'];
        $title   = $pm['title'] ?: ($svc === 'sonarr' ? 'TVDB ' : 'TMDB ') . $pm['external_id'];
        $mapname = $map_names[$pm['mapping_id']] ?? $pm['mapping_id'];
      ?>
      <tr>
        <td><span class="dot dot-muted"></span></td>
        <td>
          <div style="font-weight:600;font-size:.875rem"><?= htmlspecialchars($title) ?></div>
          <div style="font-size:.7rem;color:var(--muted)">
            <?= htmlspecialchars($svc === 'sonarr' ? 'TVDB' : 'TMDB') ?>
            <?= (int)$pm['external_id'] ?>
            <?php if ($pm['notes']): ?>
              &nbsp;·&nbsp; <?= htmlspecialchars($pm['notes']) ?>
            <?php endif; ?>
          </div>
        </td>
        <td>
          <span class="badge <?= $svc === 'radarr' ? '' : 'badge-muted' ?>"
            <?= $svc === 'radarr' ? 'style="background:rgba(160,90,219,.15);color:#a05adb"' : '' ?>>
            <?= htmlspecialchars(ucfirst($svc)) ?>
          </span>
        </td>
        <td>
          <?php if ($to_fast): ?>
            <span style="color:var(--green);font-weight:700;font-size:.8rem">&#8594; Fast</span>
          <?php else: ?>
            <span style="color:var(--muted);font-weight:700;font-size:.8rem">&#8592; Slow</span>
          <?php endif; ?>
        </td>
        <td style="color:var(--muted);font-size:.8rem"><?= htmlspecialchars($mapname) ?></td>
        <td><span class="badge badge-muted">Pending</span></td>
        <td style="font-size:.75rem;color:var(--muted)"><?= date('Y-m-d H:i', $pm['requested_at']) ?></td>
        <td>
          <form method="POST" action="queue.php" style="display:inline"
                onsubmit="return confirm('Cancel this queued move?')">
            <input type="hidden" name="action" value="cancel_pending">
            <input type="hidden" name="pm_id" value="<?= (int)$pm['id'] ?>">
            <button type="submit" class="btn btn-danger" style="padding:.2rem .5rem;font-size:.72rem" title="Cancel move">✕ Cancel</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>

      <!-- queue.json items (actively being processed) -->
      <?php foreach ($json_items as $item):
        $status  = $item['status'] ?? 'pending';
        $to_fast = ($item['direction'] ?? '') === 'to_fast';
        $svc     = $item['service'] ?? 'sonarr';
      ?>
      <tr>
        <td>
          <?php if ($status === 'moving'): ?>
            <span class="dot dot-amber"></span>
          <?php else: ?>
            <span class="dot dot-muted"></span>
          <?php endif; ?>
        </td>
        <td>
          <div style="font-weight:600;font-size:.875rem"><?= htmlspecialchars($item['name'] ?? '') ?></div>
          <div style="font-size:.7rem;color:var(--muted);font-family:monospace;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= htmlspecialchars($to_fast ? ($item['src'] ?? '') : ($item['dst'] ?? '')) ?>
            &nbsp;&rarr;&nbsp;
            <?= htmlspecialchars($to_fast ? ($item['dst'] ?? '') : ($item['src'] ?? '')) ?>
          </div>
          <?php if ($status === 'moving' && isset($item['progress_pct'])):
            $pct       = max(0, min(100, (int)$item['progress_pct']));
            $done_b    = (int)($item['bytes_done']  ?? 0);
            $total_b   = (int)($item['total_bytes'] ?? 0);
            $speed     = $item['speed'] ?? '';
            $done_gib  = $done_b  / (1024 ** 3);
            $total_gib = $total_b / (1024 ** 3);
          ?>
          <div style="margin-top:5px;max-width:340px">
            <div style="width:100%;height:3px;background:rgba(255,255,255,.1);border-radius:2px;margin-bottom:3px">
              <div style="width:<?= $pct ?>%;height:100%;background:var(--accent);border-radius:2px"></div>
            </div>
            <span style="font-size:.68rem;color:var(--muted)">
              <?= $pct ?>%
              <?php if ($total_b > 0): ?>
                &nbsp;·&nbsp; <?= number_format($done_gib, 2) ?> / <?= number_format($total_gib, 2) ?> GiB
              <?php endif; ?>
              <?php if ($speed): ?>
                &nbsp;·&nbsp; <?= htmlspecialchars($speed) ?>
              <?php endif; ?>
            </span>
          </div>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge <?= $svc === 'radarr' ? '' : 'badge-muted' ?>"
            <?= $svc === 'radarr' ? 'style="background:rgba(160,90,219,.15);color:#a05adb"' : '' ?>>
            <?= htmlspecialchars(ucfirst($svc)) ?>
          </span>
        </td>
        <td>
          <?php if ($to_fast): ?>
            <span style="color:var(--green);font-weight:700;font-size:.8rem">&#8594; Fast</span>
          <?php else: ?>
            <span style="color:var(--muted);font-weight:700;font-size:.8rem">&#8592; Slow</span>
          <?php endif; ?>
        </td>
        <td style="color:var(--muted);font-size:.8rem"><?= htmlspecialchars($item['mapping'] ?? '') ?></td>
        <td>
          <?php if ($status === 'moving'): ?>
            <span class="badge badge-amber">Moving</span>
          <?php else: ?>
            <span class="badge badge-muted">Queued</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.75rem;color:var(--muted)"><?= htmlspecialchars($item['started_at'] ?? '—') ?></td>
        <td></td>
      </tr>
      <?php endforeach; ?>

    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
