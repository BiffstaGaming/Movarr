<?php
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/layout.php';

// ── Dismiss a health issue ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss') {
    $dismiss_id = $_POST['issue_id'] ?? '';
    if ($dismiss_id !== '') {
        $hf     = health_file();
        $issues = [];
        if (file_exists($hf)) {
            $issues = json_decode(file_get_contents($hf), true) ?: [];
        }
        $issues = array_values(array_filter($issues, fn($i) => $i['id'] !== $dismiss_id));
        file_put_contents($hf, json_encode($issues, JSON_PRETTY_PRINT));
    }
    header('Location: health.php');
    exit;
}

$s = load_settings();

// ── Load health issues ────────────────────────────────────────────────────────
$health_issues = [];
$hf = health_file();
if (file_exists($hf)) {
    $health_issues = json_decode(file_get_contents($hf), true) ?: [];
}

// ── Load disk usage written by the mover service container ────────────────────
function fmt_bytes(int $bytes): string {
    if ($bytes >= 1099511627776) return round($bytes / 1099511627776, 1) . ' TB';
    if ($bytes >= 1073741824)    return round($bytes / 1073741824, 1)    . ' GB';
    if ($bytes >= 1048576)       return round($bytes / 1048576, 1)       . ' MB';
    return $bytes . ' B';
}

$disks        = [];
$disk_updated = null;
$du_file      = disk_usage_file();

if (file_exists($du_file)) {
    $du = json_decode(file_get_contents($du_file), true) ?: [];
    $disk_updated = $du['updated'] ?? null;
    foreach ($du['drives'] ?? [] as $d) {
        $total = (int)($d['total_bytes'] ?? 0);
        $free  = (int)($d['free_bytes']  ?? 0);
        $used  = (int)($d['used_bytes']  ?? 0);
        if ($total === 0) continue;
        $pct    = round($used / $total * 100);
        $labels = array_merge([$d['label'] ?? ''], $d['extra_labels'] ?? []);
        $disks[] = [
            'label' => implode(', ', array_map('htmlspecialchars', $labels)),
            'path'  => htmlspecialchars($d['path'] ?? ''),
            'total' => $total,
            'free'  => $free,
            'used'  => $used,
            'pct'   => $pct,
        ];
    }
}

layout_start('System Status', 'health');
?>
<style>
/* ── Tab bar ── */
.tab-bar {
  display: flex;
  gap: 0;
  border-bottom: 1px solid var(--border);
  margin-bottom: 1.25rem;
}
.tab-btn {
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  padding: .6rem 1.1rem;
  color: var(--muted);
  font-size: .875rem;
  font-weight: 500;
  cursor: pointer;
  margin-bottom: -1px;
  transition: color .15s, border-color .15s;
}
.tab-btn:hover { color: var(--text); }
.tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }

/* ── Tab panels ── */
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ── Disk bar ── */
.disk-row {
  padding: .85rem 1rem;
  border-bottom: 1px solid rgba(255,255,255,.04);
}
.disk-row:last-child { border-bottom: none; }
.disk-meta {
  display: flex;
  align-items: baseline;
  gap: .5rem;
  margin-bottom: .45rem;
}
.disk-name { font-size: .875rem; font-weight: 600; }
.disk-path { font-size: .75rem; color: var(--muted); }
.disk-stats { margin-left: auto; font-size: .75rem; color: var(--muted); }
.disk-bar-wrap {
  height: 8px;
  background: var(--surface2);
  border-radius: 4px;
  overflow: hidden;
}
.disk-bar-fill {
  height: 100%;
  border-radius: 4px;
  transition: width .3s ease;
}
.disk-bar-fill.ok     { background: var(--green); }
.disk-bar-fill.warn   { background: var(--accent); }
.disk-bar-fill.danger { background: var(--red); }
.disk-legend {
  display: flex;
  gap: 1rem;
  margin-top: .35rem;
  font-size: .72rem;
  color: var(--muted);
}

/* ── Health items ── */
.health-item {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: .75rem 1rem;
  border-bottom: 1px solid rgba(255,255,255,.04);
  font-size: .875rem;
}
.health-item:last-child { border-bottom: none; }

/* ── Empty placeholder ── */
.placeholder {
  padding: 3rem 1.5rem;
  text-align: center;
  color: var(--muted);
  font-size: .85rem;
}
.placeholder svg { display: block; margin: 0 auto .75rem; opacity: .25; }
</style>

<div style="max-width:860px">

<!-- ── Tab bar ── -->
<div class="tab-bar">
  <button class="tab-btn active" data-tab="health">Health</button>
  <button class="tab-btn" data-tab="disk">Disk Space</button>
  <button class="tab-btn" data-tab="about">About</button>
  <button class="tab-btn" data-tab="more">More Info</button>
</div>

<!-- ════════════════════════════════════════════════════════════
     Health
     ════════════════════════════════════════════════════════════ -->
<div class="tab-panel active" id="tab-health">
  <div class="card">
    <div class="card-header">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
      Health
      <?php if (!empty($health_issues)): ?>
      <span class="badge badge-red" style="margin-left:.5rem"><?= count($health_issues) ?></span>
      <?php endif; ?>
    </div>

    <?php if (empty($health_issues)): ?>
    <div class="health-item">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="var(--green)"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
      <span style="color:var(--text)">No issues</span>
      <span style="margin-left:.25rem;color:var(--muted);font-size:.8rem">— everything looks good</span>
    </div>
    <?php else: ?>
    <?php foreach ($health_issues as $issue):
      $level = $issue['level'] ?? 'error';
      $icon_color = $level === 'warning' ? 'var(--accent)' : 'var(--red)';
    ?>
    <div class="health-item" style="justify-content:space-between">
      <div style="display:flex;align-items:flex-start;gap:.75rem;flex:1;min-width:0">
        <?php if ($level === 'warning'): ?>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="<?= $icon_color ?>" style="flex-shrink:0;margin-top:.1rem"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
        <?php else: ?>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="<?= $icon_color ?>" style="flex-shrink:0;margin-top:.1rem"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
        <?php endif; ?>
        <div style="min-width:0">
          <div style="font-weight:600;color:var(--text);margin-bottom:.15rem"><?= htmlspecialchars($issue['title'] ?? '') ?></div>
          <div style="font-size:.82rem;color:var(--muted);word-break:break-word"><?= htmlspecialchars($issue['message'] ?? '') ?></div>
          <?php if (!empty($issue['updated_at'])): ?>
          <div style="font-size:.72rem;color:var(--muted);margin-top:.25rem;opacity:.6"><?= htmlspecialchars($issue['updated_at']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <form method="POST" action="health.php" style="flex-shrink:0;margin-left:1rem">
        <input type="hidden" name="action" value="dismiss">
        <input type="hidden" name="issue_id" value="<?= htmlspecialchars($issue['id'] ?? '') ?>">
        <button type="submit" class="btn" style="font-size:.75rem;padding:.3rem .65rem" title="Dismiss">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
          Dismiss
        </button>
      </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     Disk Space
     ════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-disk">
  <div class="card">
    <div class="card-header">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 11H5V3H3v18h2v-8h14v8h2V3h-2v8zM7 5h10v4H7V5z"/></svg>
      Disk Space
      <?php if ($disk_updated): ?>
      <span style="margin-left:auto;font-size:.7rem;font-weight:400;text-transform:none;letter-spacing:0;color:var(--muted)">Updated <?= htmlspecialchars($disk_updated) ?></span>
      <?php endif; ?>
    </div>

    <?php if (empty($disks)): ?>
    <div class="placeholder">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="currentColor"><path d="M19 11H5V3H3v18h2v-8h14v8h2V3h-2v8zM7 5h10v4H7V5z"/></svg>
      <?php if (!file_exists($du_file)): ?>
      Disk data not yet available — it will appear after the mover service starts or runs once.
      <?php else: ?>
      No path mappings configured — add them in <a href="config.php" style="color:var(--accent)">Settings</a>.
      <?php endif; ?>
    </div>
    <?php else: ?>
    <?php foreach ($disks as $d):
      $cls = $d['pct'] >= 90 ? 'danger' : ($d['pct'] >= 75 ? 'warn' : 'ok');
    ?>
    <div class="disk-row">
      <div class="disk-meta">
        <span class="disk-name"><?= $d['label'] ?></span>
        <span class="disk-path"><?= htmlspecialchars($d['path']) ?></span>
        <span class="disk-stats"><?= fmt_bytes($d['used']) ?> used of <?= fmt_bytes($d['total']) ?></span>
      </div>
      <div class="disk-bar-wrap">
        <div class="disk-bar-fill <?= $cls ?>" style="width:<?= $d['pct'] ?>%"></div>
      </div>
      <div class="disk-legend">
        <span><?= $d['pct'] ?>% used</span>
        <span><?= fmt_bytes($d['free']) ?> free</span>
        <span><?= fmt_bytes($d['total']) ?> total</span>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     About
     ════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-about">
  <div class="card">
    <div class="card-header">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
      About
    </div>
    <div class="placeholder" style="padding:2rem 1.5rem;text-align:left;color:var(--muted)">
      <em>Nothing here yet.</em>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     More Info
     ════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-more">
  <div class="card">
    <div class="card-header">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>
      More Info
    </div>
    <div class="placeholder" style="padding:2rem 1.5rem;text-align:left;color:var(--muted)">
      <em>Nothing here yet.</em>
    </div>
  </div>
</div>

</div><!-- max-width wrapper -->

<script>
document.querySelectorAll('.tab-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var target = btn.dataset.tab;
    document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
    document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
    btn.classList.add('active');
    document.getElementById('tab-' + target).classList.add('active');
    try { localStorage.setItem('health_tab', target); } catch(e) {}
  });
});

// Restore last active tab
(function() {
  try {
    var last = localStorage.getItem('health_tab');
    if (last) {
      var btn = document.querySelector('.tab-btn[data-tab="' + last + '"]');
      if (btn) btn.click();
    }
  } catch(e) {}
})();
</script>

<?php layout_end(); ?>
