<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/layout.php';

$qf   = queue_file();
$data = null;

if (file_exists($qf)) {
    $raw = json_decode(file_get_contents($qf), true);
    if (is_array($raw)) $data = $raw;
}

$is_running = $data && $data['completed'] === null;

$mode_labels = ['real' => 'Real', 'dry_run' => 'Dry Run', 'list_only' => 'List Only'];
$mode_classes = ['real' => 'badge-red', 'dry_run' => 'badge-blue', 'list_only' => 'badge-muted'];

$status_cfg = [
    'pending' => ['label' => 'Pending',  'class' => 'badge-muted',  'dot' => 'dot-muted'],
    'moving'  => ['label' => 'Moving',   'class' => 'badge-amber',  'dot' => 'dot-amber'],
    'done'    => ['label' => 'Done',     'class' => 'badge-green',  'dot' => 'dot-green'],
    'error'   => ['label' => 'Error',    'class' => 'badge-red',    'dot' => 'dot-red'],
    'skipped' => ['label' => 'Skipped',  'class' => 'badge-muted',  'dot' => 'dot-muted'],
];

$counts = ['pending' => 0, 'moving' => 0, 'done' => 0, 'error' => 0, 'skipped' => 0];
foreach (($data['items'] ?? []) as $item) {
    $s = $item['status'] ?? 'pending';
    if (isset($counts[$s])) $counts[$s]++;
}

$extra_head = $is_running ? '<meta http-equiv="refresh" content="5">' : '';
layout_start('Queue', 'queue', $extra_head);
?>

<?php if (!$data): ?>
  <div class="empty">
    No queue data yet.<br>
    <a href="config.php">Run the mover</a> to generate queue activity.
  </div>
<?php else: ?>

  <!-- Run info bar -->
  <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem;">
    <?php if ($is_running): ?>
      <span class="dot dot-amber"></span>
      <strong>Run in progress</strong>
    <?php else: ?>
      <span class="dot dot-green"></span>
      <strong>Run complete</strong>
    <?php endif; ?>

    <span class="text-muted" style="font-size:.8rem">
      Started: <?= htmlspecialchars($data['started']) ?>
      <?php if ($data['completed']): ?>
        &mdash; Completed: <?= htmlspecialchars($data['completed']) ?>
      <?php endif; ?>
    </span>

    <span class="badge <?= $mode_classes[$data['mode']] ?? 'badge-muted' ?>">
      <?= htmlspecialchars($mode_labels[$data['mode']] ?? $data['mode']) ?>
    </span>

    <?php foreach ($counts as $key => $n): ?>
      <?php if ($n > 0): ?>
        <span class="badge <?= $status_cfg[$key]['class'] ?>">
          <?= $n ?> <?= $status_cfg[$key]['label'] ?>
        </span>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($is_running): ?>
      <span class="text-muted ml-auto" style="font-size:.75rem">Auto-refreshing every 5s</span>
    <?php endif; ?>
  </div>

  <!-- Items table -->
  <div class="card">
    <?php if (empty($data['items'])): ?>
      <div class="empty">No items queued — nothing to move this run.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th></th>
          <th>Title</th>
          <th>Service</th>
          <th>Direction</th>
          <th>Mapping</th>
          <th>Transfer</th>
          <th>Started</th>
          <th>Finished</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data['items'] as $item):
          $status = $item['status'] ?? 'pending';
          $cfg    = $status_cfg[$status] ?? $status_cfg['pending'];
          $to_fast = ($item['direction'] ?? '') === 'to_fast';
          $svc = $item['service'] ?? 'sonarr';
        ?>
        <tr>
          <td style="width:24px">
            <span class="dot <?= $cfg['dot'] ?>"></span>
          </td>
          <td>
            <div style="font-weight:600;font-size:.875rem"><?= htmlspecialchars($item['name'] ?? '') ?></div>
            <div style="font-size:.7rem;color:var(--muted);font-family:monospace;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= htmlspecialchars($to_fast ? ($item['src'] ?? '') : ($item['dst'] ?? '')) ?>
            </div>
          </td>
          <td>
            <span class="badge <?= $svc === 'radarr' ? 'badge-blue' : 'badge-muted' ?>" style="<?= $svc === 'radarr' ? 'background:rgba(160,90,219,.15);color:#a05adb' : '' ?>">
              <?= htmlspecialchars(ucfirst($svc)) ?>
            </span>
          </td>
          <td>
            <?php if ($to_fast): ?>
              <span style="color:var(--green);font-weight:700;font-size:.85rem">&#8594; Fast</span>
            <?php else: ?>
              <span style="color:var(--muted);font-weight:700;font-size:.85rem">&#8592; Slow</span>
            <?php endif; ?>
          </td>
          <td style="color:var(--muted);font-size:.8rem"><?= htmlspecialchars($item['mapping'] ?? '') ?></td>
          <td style="font-size:.75rem;color:var(--muted);font-family:monospace"><?= htmlspecialchars($item['progress'] ?? '') ?></td>
          <td style="font-size:.75rem;color:var(--muted)"><?= htmlspecialchars($item['started_at'] ?? '—') ?></td>
          <td style="font-size:.75rem;color:var(--muted)"><?= htmlspecialchars($item['done_at'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php layout_end(); ?>
