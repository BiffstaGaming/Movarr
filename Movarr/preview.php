<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/layout.php';

// ── POST: trigger preview generation ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_preview') {
    @file_put_contents(preview_trigger_file(), date('Y-m-d H:i:s') . ' preview trigger' . PHP_EOL);
    header('Content-Type: application/json');
    echo json_encode(['queued' => true]);
    exit;
}

// ── Load preview data ──────────────────────────────────────────────────────────
$pf   = preview_file();
$data = null;
if (file_exists($pf)) {
    $raw = json_decode(file_get_contents($pf), true);
    if (is_array($raw)) $data = $raw;
}

$has_data   = $data !== null;

function fmt_preview_bytes(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    if ($bytes >= 1_099_511_627_776) return number_format($bytes / 1_099_511_627_776, 2) . ' TB';
    if ($bytes >= 1_073_741_824)     return number_format($bytes / 1_073_741_824, 2) . ' GB';
    if ($bytes >= 1_048_576)         return number_format($bytes / 1_048_576, 1) . ' MB';
    return $bytes . ' B';
}

$extra_head = '';
layout_start('Move Preview', 'preview', $extra_head);
?>

<style>
.preview-section { margin-bottom:1.5rem; }
.preview-mapping-title {
  font-size:.72rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.08em;color:var(--accent);
  padding:.65rem 1rem;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:.5rem;
}
.preview-direction-header {
  display:flex;align-items:center;gap:.5rem;
  padding:.45rem 1rem;background:rgba(255,255,255,.02);
  border-bottom:1px solid rgba(255,255,255,.04);
  font-size:.75rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.06em;color:var(--muted);
}
.preview-item {
  display:flex;align-items:center;gap:.75rem;
  padding:.45rem 1rem;border-bottom:1px solid rgba(255,255,255,.03);
  font-size:.875rem;
}
.preview-item:last-child { border-bottom:none; }
.preview-item:hover { background:rgba(255,255,255,.02); }
.preview-item-title { flex:1;min-width:0; }
.preview-item-size  { flex:0 0 80px;text-align:right;font-size:.75rem;color:var(--muted); }
.preview-item-path  { flex:0 0 300px;font-size:.68rem;color:var(--muted);font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.preview-item-warn  { color:var(--red);font-size:.7rem; }
.preview-empty-section { padding:.6rem 1rem;font-size:.8rem;color:var(--muted); }
</style>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem">
  <div>
    <div style="font-size:.95rem;font-weight:600">Move Preview</div>
    <?php if ($has_data): ?>
    <div style="font-size:.78rem;color:var(--muted)">
      Generated <?= htmlspecialchars($data['generated_at']) ?>
      &nbsp;·&nbsp; Watched window: <?= (int)($data['watched_days'] ?? 30) ?> days
    </div>
    <?php else: ?>
    <div style="font-size:.78rem;color:var(--muted)">No preview data yet — click Refresh to generate.</div>
    <?php endif; ?>
  </div>
  <button class="btn btn-primary" style="margin-left:auto" id="btn-refresh" onclick="runPreview()">&#8635; Refresh Preview</button>
</div>
<div id="notice-refresh" style="display:none;margin-bottom:.75rem;padding:.5rem .75rem;background:rgba(229,160,13,.08);border:1px solid rgba(229,160,13,.2);border-radius:4px;font-size:.8rem;color:var(--accent)">
  Preview generation queued — results will appear in ~30 seconds. This page auto-refreshes.
</div>

<?php if (!$has_data): ?>
<div class="card">
  <div class="empty">
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin-bottom:.75rem;color:var(--muted)"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
    <div>No preview data available</div>
    <div style="font-size:.8rem;margin-top:.35rem">Click <strong>Refresh Preview</strong> to calculate what would move on the next mover run.</div>
  </div>
</div>

<?php else:
  $all_mappings = $data['mappings'] ?? [];
  $total_to_fast = array_sum(array_map(fn($m) => count($m['to_fast'] ?? []), $all_mappings));
  $total_to_slow = array_sum(array_map(fn($m) => count($m['to_slow'] ?? []), $all_mappings));
?>

<!-- Summary bar -->
<div style="display:flex;gap:1.5rem;margin-bottom:1rem;padding:.65rem 1rem;background:var(--surface);border:1px solid var(--border);border-radius:4px">
  <div>
    <span style="font-size:1.4rem;font-weight:700;color:var(--green)"><?= $total_to_fast ?></span>
    <span style="font-size:.78rem;color:var(--muted);margin-left:.3rem">→ to Fast</span>
  </div>
  <div>
    <span style="font-size:1.4rem;font-weight:700;color:var(--muted)"><?= $total_to_slow ?></span>
    <span style="font-size:.78rem;color:var(--muted);margin-left:.3rem">← to Slow</span>
  </div>
  <div style="margin-left:auto;font-size:.75rem;color:var(--muted);align-self:center">
    <?= count($all_mappings) ?> mapping<?= count($all_mappings) !== 1 ? 's' : '' ?>
  </div>
</div>

<?php foreach ($all_mappings as $mp): ?>
<?php
  $to_fast   = $mp['to_fast']   ?? [];
  $to_slow   = $mp['to_slow']   ?? [];
  $to_extend = $mp['to_extend'] ?? [];
  $svc       = $mp['service']   ?? 'sonarr';
?>
<div class="card preview-section">
  <div class="preview-mapping-title">
    <?php if ($svc === 'radarr'): ?>
      <span class="badge" style="background:rgba(160,90,219,.15);color:#a05adb">Radarr</span>
    <?php else: ?>
      <span class="badge badge-muted">Sonarr</span>
    <?php endif; ?>
    <?= htmlspecialchars($mp['mapping']) ?>
    <span style="font-weight:400;color:var(--muted);font-size:.68rem">
      <?= count($to_fast) ?> → fast &nbsp; <?= count($to_slow) ?> ← slow &nbsp; <?= count($to_extend) ?> extend
    </span>
  </div>

  <?php if (!empty($to_fast)):
    $fast_bytes = array_sum(array_column($to_fast, 'size_bytes'));
  ?>
  <div class="preview-direction-header">
    <span style="color:var(--green)">&#8594;</span> Move to Fast (<?= count($to_fast) ?>)
    <span style="margin-left:auto;font-weight:400;color:var(--green);opacity:.8"><?= htmlspecialchars(fmt_preview_bytes($fast_bytes)) ?></span>
  </div>
  <?php foreach ($to_fast as $item): ?>
  <div class="preview-item">
    <span class="preview-item-title">
      <?= htmlspecialchars($item['title']) ?>
      <?php if (!($item['disk_ok'] ?? true)): ?>
        <span class="preview-item-warn" title="Source folder not found on disk">⚠ disk missing</span>
      <?php endif; ?>
    </span>
    <span class="preview-item-path"><?= htmlspecialchars($item['src_path'] ?? '') ?></span>
    <span class="preview-item-size"><?= htmlspecialchars($item['size_human']) ?></span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($to_slow)):
    $slow_bytes = array_sum(array_column($to_slow, 'size_bytes'));
  ?>
  <div class="preview-direction-header">
    <span style="color:var(--muted)">&#8592;</span> Move to Slow (<?= count($to_slow) ?>)
    <span style="margin-left:auto;font-weight:400;color:var(--muted)"><?= htmlspecialchars(fmt_preview_bytes($slow_bytes)) ?></span>
  </div>
  <?php foreach ($to_slow as $item): ?>
  <div class="preview-item">
    <span class="preview-item-title">
      <?= htmlspecialchars($item['title']) ?>
      <?php if (!($item['disk_ok'] ?? true)): ?>
        <span class="preview-item-warn" title="Source folder not found on disk">⚠ disk missing</span>
      <?php endif; ?>
    </span>
    <span class="preview-item-path"><?= htmlspecialchars($item['src_path'] ?? '') ?></span>
    <span class="preview-item-size"><?= htmlspecialchars($item['size_human']) ?></span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($to_extend)): ?>
  <div class="preview-direction-header">
    <span style="color:var(--accent)">&#8735;</span> Keep on Fast — window extended (<?= count($to_extend) ?>)
  </div>
  <?php foreach ($to_extend as $item): ?>
  <div class="preview-item">
    <span class="preview-item-title"><?= htmlspecialchars($item['title']) ?></span>
    <span class="preview-item-size"><?= htmlspecialchars($item['size_human']) ?></span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if (empty($to_fast) && empty($to_slow) && empty($to_extend)): ?>
    <div class="preview-empty-section">Nothing to move for this mapping.</div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
function runPreview() {
  var btn    = document.getElementById('btn-refresh');
  var notice = document.getElementById('notice-refresh');
  if (btn) { btn.disabled = true; btn.textContent = 'Queued…'; }
  if (notice) notice.style.display = 'block';

  var form = new FormData();
  form.append('action', 'run_preview');
  fetch('preview.php', { method: 'POST', body: form })
    .catch(function() {})
    .finally(function() {
      // Auto-reload after ~35s to show updated results
      setTimeout(function() { location.reload(); }, 35000);
    });
}
</script>

<?php layout_end(); ?>
