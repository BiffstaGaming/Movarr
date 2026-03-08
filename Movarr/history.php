<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/db.php';

$db   = db_connect();
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 50;
$off  = ($page - 1) * $per;

$total = (int)$db->query('SELECT COUNT(*) FROM move_history')->fetchColumn();
$pages = max(1, (int)ceil($total / $per));

$rows = $db->prepare('SELECT * FROM move_history ORDER BY moved_at DESC LIMIT ? OFFSET ?');
$rows->execute([$per, $off]);
$history = $rows->fetchAll();

// Load mapping names for display
$s        = load_settings();
$map_names = [];
foreach ($s['path_mappings'] ?? [] as $m) {
    $map_names[$m['id']] = $m['name'] ?: $m['id'];
}

layout_start('History', 'history');
?>

<!-- Stats bar -->
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;font-size:.8rem;color:var(--muted)">
  <span><?= number_format($total) ?> total move<?= $total !== 1 ? 's' : '' ?></span>
  <?php if ($total > 0):
    $fast = (int)$db->query("SELECT COUNT(*) FROM move_history WHERE direction='to_fast'")->fetchColumn();
    $slow = $total - $fast;
  ?>
  <span class="badge badge-green"><?= $fast ?> &rarr; Fast</span>
  <span class="badge badge-muted"><?= $slow ?> &larr; Slow</span>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (empty($history)): ?>
    <div class="empty">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin-bottom:.75rem;color:var(--muted)"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      <div>No move history yet</div>
      <div style="font-size:.8rem;margin-top:.35rem">Completed moves will appear here.</div>
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
        <th>Source</th>
        <th style="text-align:center" title="Sonarr/Radarr path was updated successfully">Path Synced</th>
        <th style="text-align:center" title="Plex library refresh was triggered successfully">Plex Notified</th>
        <th>Date</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($history as $row):
        $to_fast  = $row['direction'] === 'to_fast';
        $svc      = $row['service'];
        $is_show  = $row['media_type'] === 'show';
        $map_name = $map_names[$row['mapping_id']] ?? $row['mapping_id'];
      ?>
      <tr>
        <td>
          <?php if ($is_show): ?>
            <!-- TV icon -->
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="color:var(--muted)"><path d="M21 3H3a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5v2h8v-2h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 14H3V5h18v12z"/></svg>
          <?php else: ?>
            <!-- Movie icon -->
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="color:var(--muted)"><path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V4h-4z"/></svg>
          <?php endif; ?>
        </td>
        <td>
          <div style="font-weight:600;font-size:.875rem"><?= htmlspecialchars($row['title']) ?></div>
          <?php if ($row['folder']): ?>
          <div style="font-size:.7rem;color:var(--muted);font-family:monospace;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= htmlspecialchars($row['folder']) ?>
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
        <td style="font-size:.8rem;color:var(--muted)"><?= htmlspecialchars($map_name) ?></td>
        <td>
          <?php if ($row['source'] === 'manual'): ?>
            <span class="badge badge-amber">Manual</span>
          <?php else: ?>
            <span class="badge badge-muted">Auto</span>
          <?php endif; ?>
        </td>
        <td style="text-align:center">
          <?php if ($row['service_updated']): ?>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color:var(--green)"><polyline points="20 6 9 17 4 12"/></svg>
          <?php else: ?>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color:var(--muted)"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          <?php endif; ?>
        </td>
        <td style="text-align:center">
          <?php if ($row['plex_refreshed']): ?>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color:var(--green)"><polyline points="20 6 9 17 4 12"/></svg>
          <?php else: ?>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color:var(--muted)"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          <?php endif; ?>
        </td>
        <td style="font-size:.75rem;color:var(--muted);white-space:nowrap">
          <?= date('Y-m-d H:i', $row['moved_at']) ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($pages > 1): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:.65rem .75rem;border-top:1px solid var(--border);font-size:.8rem;">
    <span style="color:var(--muted)">Page <?= $page ?> of <?= $pages ?></span>
    <div style="display:flex;gap:.4rem">
      <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?>" class="btn" style="padding:.3rem .7rem;font-size:.75rem">&#8592; Prev</a>
      <?php endif; ?>
      <?php if ($page < $pages): ?>
        <a href="?page=<?= $page + 1 ?>" class="btn" style="padding:.3rem .7rem;font-size:.75rem">Next &#8594;</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
