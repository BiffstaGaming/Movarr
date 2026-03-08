<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/layout.php';

$qf   = queue_file();
$data = null;

if (file_exists($qf)) {
    $raw = json_decode(file_get_contents($qf), true);
    if (is_array($raw)) $data = $raw;
}

// Active = any item in pending or moving state
$active_items = [];
foreach (($data['items'] ?? []) as $item) {
    $s = $item['status'] ?? '';
    if ($s === 'pending' || $s === 'moving') {
        $active_items[] = $item;
    }
}

$is_running = !empty($active_items);
$extra_head = $is_running ? '<meta http-equiv="refresh" content="3">' : '';

$mode_labels  = ['real' => 'Real', 'dry_run' => 'Dry Run', 'list_only' => 'List Only'];
$mode_classes = ['real' => 'badge-red', 'dry_run' => 'badge-blue', 'list_only' => 'badge-muted'];

layout_start('Queue', 'queue', $extra_head);
?>

<?php if ($is_running): ?>
<!-- Active run banner -->
<div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;padding:.5rem .75rem;background:rgba(229,160,13,.08);border:1px solid rgba(229,160,13,.2);border-radius:4px;">
  <span class="dot dot-amber"></span>
  <span style="font-size:.85rem;font-weight:600;color:var(--accent)">Transfer in progress</span>
  <span style="font-size:.75rem;color:var(--muted);margin-left:.25rem">
    <?= count($active_items) ?> item<?= count($active_items) !== 1 ? 's' : '' ?> remaining
  </span>
  <?php if ($data && isset($data['mode'])): ?>
  <span class="badge <?= $mode_classes[$data['mode']] ?? 'badge-muted' ?>" style="margin-left:.25rem">
    <?= htmlspecialchars($mode_labels[$data['mode']] ?? $data['mode']) ?>
  </span>
  <?php endif; ?>
  <span style="font-size:.7rem;color:var(--muted);margin-left:auto">Auto-refreshing every 3s</span>
</div>
<?php endif; ?>

<div class="card">
  <?php if (empty($active_items)): ?>
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
        <th>Started</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($active_items as $item):
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
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
