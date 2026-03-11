<?php
// layout_start($title, $active) - outputs html/head/sidebar/nav up to content div
// layout_end() - closes everything
// $active: 'dashboard', 'config', 'queue', 'history', 'logs', 'manual', 'tracked', 'health'

function layout_start(string $title, string $active, string $extra_head = ''): void {
    // Grouped nav: type='item' = standalone link, type='group' = expandable parent
    $nav = [
        ['type' => 'item',  'key' => 'dashboard', 'href' => 'index.php',   'label' => 'Dashboard', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>'],
        ['type' => 'item',  'key' => 'manual',    'href' => 'manual.php',  'label' => 'Manual',    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M13 2v8h8l-8 12v-8H5L13 2z"/></svg>'],
        ['type' => 'item',  'key' => 'tracked',   'href' => 'tracked.php', 'label' => 'Tracked',   'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M4 5h3v3H4V5zm0 5.5h3v3H4v-3zm0 5.5h3v3H4v-3zm5-11h11v3H9V5zm0 5.5h11v3H9v-3zm0 5.5h11v3H9v-3z"/></svg>'],
        ['type' => 'group', 'key' => 'activity',  'label' => 'Activity',
            'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
            'children' => [
                ['key' => 'queue',   'href' => 'queue.php',   'label' => 'Queue'],
                ['key' => 'history', 'href' => 'history.php', 'label' => 'History'],
                ['key' => 'preview', 'href' => 'preview.php', 'label' => 'Move Preview'],
            ],
        ],
        ['type' => 'group', 'key' => 'system', 'label' => 'System',
            'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2zM4 15V9h16v6H4zm8-4a1 1 0 1 0 0 2 1 1 0 0 0 0-2zM6 11h2v2H6zm8 0h4v2h-4z"/></svg>',
            'children' => [
                ['key' => 'tasks',  'href' => 'tasks.php',  'label' => 'Tasks'],
                ['key' => 'health', 'href' => 'health.php', 'label' => 'Status'],
                ['key' => 'logs',   'href' => 'logs.php',   'label' => 'Logs'],
                ['key' => 'config', 'href' => 'config.php', 'label' => 'Settings'],
            ],
        ],
    ];

    // Determine which groups should be expanded (contains the active page)
    $expanded_groups = [];
    foreach ($nav as $entry) {
        if ($entry['type'] === 'group') {
            foreach ($entry['children'] as $child) {
                if ($child['key'] === $active) {
                    $expanded_groups[] = $entry['key'];
                }
            }
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Movarr</title>
<link rel="icon" type="image/svg+xml" href="images/movarr-logo.svg">
<?= $extra_head ?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --sidebar-bg:  #131722;
  --sidebar-w:   200px;
  --bg:          #1c1c1c;
  --surface:     #252525;
  --surface2:    #2e2e2e;
  --border:      #3a3a3a;
  --accent:      #e5a00d;
  --accent-dim:  rgba(229,160,13,.12);
  --text:        #d8d8d8;
  --muted:       #8a8a8a;
  --green:       #3cb371;
  --red:         #e05050;
  --blue:        #5aabdb;
  --radius:      4px;
}

body {
  background: var(--bg);
  color: var(--text);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  font-size: 14px;
  line-height: 1.5;
  min-height: 100vh;
}

/* ── Sidebar ── */
.sidebar {
  position: fixed;
  top: 0; left: 0;
  width: var(--sidebar-w);
  height: 100vh;
  background: var(--sidebar-bg);
  border-right: 1px solid rgba(255,255,255,.06);
  display: flex;
  flex-direction: column;
  z-index: 100;
  overflow-y: auto;
  overflow-x: hidden;
}

.sidebar-logo {
  display: flex;
  align-items: center;
  gap: .65rem;
  padding: 1.1rem 1.1rem 1rem;
  border-bottom: 1px solid rgba(255,255,255,.06);
  text-decoration: none;
  flex-shrink: 0;
}
.sidebar-logo img { width: 32px; height: 32px; border-radius: 6px; flex-shrink: 0; }
.sidebar-logo span {
  font-size: 1rem;
  font-weight: 700;
  color: var(--accent);
  letter-spacing: .02em;
}

.sidebar nav { padding: .35rem 0; flex: 1; }

/* ── Standalone nav items ── */
.nav-item {
  display: flex;
  align-items: center;
  gap: .7rem;
  padding: .6rem 1.1rem;
  color: var(--muted);
  text-decoration: none;
  font-size: .875rem;
  font-weight: 500;
  border-left: 3px solid transparent;
  transition: color .15s, background .15s, border-color .15s;
  white-space: nowrap;
  overflow: hidden;
}
.nav-item:hover {
  color: var(--text);
  background: rgba(255,255,255,.04);
  border-left-color: rgba(255,255,255,.15);
}
.nav-item.active {
  color: var(--accent);
  background: var(--accent-dim);
  border-left-color: var(--accent);
}
.nav-item svg { flex-shrink: 0; opacity: .75; }
.nav-item.active svg { opacity: 1; }
.nav-item span { overflow: hidden; text-overflow: ellipsis; }

/* ── Group (expandable) ── */
.nav-group { border-left: 3px solid transparent; }
.nav-group.group-active { border-left-color: var(--accent); }

.nav-group-toggle {
  display: flex;
  align-items: center;
  gap: .7rem;
  padding: .6rem 1.1rem;
  color: var(--muted);
  font-size: .875rem;
  font-weight: 500;
  cursor: pointer;
  user-select: none;
  background: none;
  border: none;
  width: 100%;
  text-align: left;
  transition: color .15s, background .15s;
  white-space: nowrap;
  overflow: hidden;
}
.nav-group-toggle:hover {
  color: var(--text);
  background: rgba(255,255,255,.04);
}
.nav-group.group-active .nav-group-toggle {
  color: var(--accent);
  background: var(--accent-dim);
}
.nav-group-toggle svg:first-child { flex-shrink: 0; opacity: .75; }
.nav-group.group-active .nav-group-toggle svg:first-child { opacity: 1; }
.nav-group-label { flex: 1; overflow: hidden; text-overflow: ellipsis; }

.nav-group-arrow {
  flex-shrink: 0;
  width: 14px;
  height: 14px;
  margin-left: auto;
  color: rgba(255,255,255,.25);
  transition: transform .2s ease, color .15s;
}
.nav-group.expanded .nav-group-arrow { transform: rotate(90deg); color: rgba(255,255,255,.45); }

/* ── Group children ── */
.nav-group-children {
  overflow: hidden;
  max-height: 0;
  transition: max-height .22s ease;
}
.nav-group.expanded .nav-group-children { max-height: 300px; }

.nav-child-link {
  display: flex;
  align-items: center;
  padding: .48rem 1.1rem .48rem 2.85rem;
  color: var(--muted);
  text-decoration: none;
  font-size: .85rem;
  font-weight: 500;
  transition: color .15s, background .15s;
  white-space: nowrap;
  border-left: none;
}
.nav-child-link:hover {
  color: var(--text);
  background: rgba(255,255,255,.04);
}
.nav-child-link.active { color: var(--accent); }

/* ── Main area ── */
.layout-main {
  margin-left: var(--sidebar-w);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.topbar {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 0 1.5rem;
  height: 48px;
  display: flex;
  align-items: center;
  gap: .75rem;
  flex-shrink: 0;
}
.topbar-title {
  font-size: .95rem;
  font-weight: 600;
  color: var(--text);
}

.content { padding: 1.5rem; flex: 1; }

/* ── Common cards ── */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
}
.card + .card { margin-top: 1rem; }

.card-header {
  padding: .75rem 1rem;
  border-bottom: 1px solid var(--border);
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--accent);
  display: flex;
  align-items: center;
  gap: .5rem;
}

.section-label {
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--muted);
  margin-bottom: .6rem;
  margin-top: 1.25rem;
}
.section-label:first-child { margin-top: 0; }

/* ── Tables ── */
table { width: 100%; border-collapse: collapse; }
thead th {
  padding: .55rem .75rem;
  text-align: left;
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--muted);
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
tbody tr { border-bottom: 1px solid rgba(255,255,255,.04); }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: rgba(255,255,255,.02); }
td { padding: .55rem .75rem; vertical-align: middle; }

/* ── Badges ── */
.badge {
  display: inline-flex;
  align-items: center;
  gap: .3rem;
  padding: .15rem .5rem;
  border-radius: 3px;
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .04em;
  white-space: nowrap;
}
.badge-green  { background: rgba(60,179,113,.15); color: var(--green); }
.badge-red    { background: rgba(224,80,80,.15);  color: var(--red); }
.badge-amber  { background: rgba(229,160,13,.15); color: var(--accent); }
.badge-blue   { background: rgba(90,171,219,.15); color: var(--blue); }
.badge-muted  { background: rgba(255,255,255,.06); color: var(--muted); }

/* ── Status dot ── */
.dot {
  display: inline-block;
  width: 8px; height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.dot-green { background: var(--green); }
.dot-red   { background: var(--red); }
.dot-amber { background: var(--accent); animation: pulse 1.2s ease-in-out infinite; }
.dot-muted { background: var(--muted); }

@keyframes pulse {
  0%,100% { opacity:1; transform:scale(1); }
  50%      { opacity:.4; transform:scale(.85); }
}

/* ── Buttons ── */
.btn {
  padding: .45rem 1rem;
  border-radius: var(--radius);
  border: 1px solid var(--border);
  background: var(--surface2);
  color: var(--text);
  cursor: pointer;
  font-size: .8rem;
  font-weight: 600;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  transition: border-color .15s, background .15s;
  white-space: nowrap;
}
.btn:hover { border-color: var(--accent); color: var(--accent); }
.btn-primary { background: var(--accent); color: #000; border-color: var(--accent); }
.btn-primary:hover { background: #c8890b; border-color: #c8890b; color: #000; }
.btn-danger { border-color: #662020; color: var(--red); }
.btn-danger:hover { background: #2d0d0d; }
.btn-success { border-color: #1e5c36; color: var(--green); }
.btn-success:hover { background: #0d2d1e; }
.btn-info { border-color: #1e4a6a; color: var(--blue); }
.btn-info:hover { background: #0d1f2d; }

/* ── Notice ── */
.notice {
  padding: .75rem 1rem;
  border-radius: var(--radius);
  margin-bottom: 1rem;
  font-size: .85rem;
  border-left: 3px solid;
}
.notice-success { background: #0d2d1e; border-color: var(--green); color: #7debb0; }
.notice-error   { background: #2d0d0d; border-color: var(--red);   color: #eb7d7d; }

/* ── Form fields ── */
input[type="text"], input[type="password"], input[type="number"], select, textarea {
  width: 100%;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  color: var(--text);
  padding: .45rem .7rem;
  font-size: .875rem;
  outline: none;
  transition: border-color .15s;
}
input[type="text"]:focus,
input[type="password"]:focus,
input[type="number"]:focus,
select:focus { border-color: var(--accent); }
select option { background: var(--surface); }
input[type="checkbox"] { accent-color: var(--accent); width: 15px; height: 15px; cursor: pointer; }

.field-row {
  display: grid;
  grid-template-columns: 180px 1fr;
  align-items: start;
  gap: .6rem .75rem;
  padding: .6rem 0;
  border-bottom: 1px solid rgba(255,255,255,.04);
}
.field-row:last-child { border-bottom: none; }
.field-row label {
  font-size: .82rem;
  color: var(--muted);
  padding-top: .45rem;
  text-align: right;
}
.field-hint { font-size: .75rem; color: var(--muted); margin-top: .25rem; }

.api-key-wrap { display: flex; align-items: center; gap: .4rem; }
.api-key-wrap input { flex: 1; }
.btn-eye {
  background: none; border: 1px solid var(--border); border-radius: var(--radius);
  color: var(--muted); cursor: pointer; padding: .4rem .5rem;
  line-height: 1; flex-shrink: 0; transition: color .15s, border-color .15s;
}
.btn-eye:hover { color: var(--accent); border-color: var(--accent); }

.toggle-wrap { display: flex; align-items: center; gap: .7rem; padding-top: .35rem; }
.toggle-wrap label { font-size: .85rem; color: var(--text); cursor: pointer; }

/* ── Mapping cards (config) ── */
.mapping-card {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: .85rem;
  margin-bottom: .5rem;
}
.mapping-card-header { display: flex; align-items: center; gap: .6rem; margin-bottom: .75rem; }
.mapping-card-header input { font-weight: 600; flex: 1; }
.mapping-card-header select { width: 120px; flex-shrink: 0; }
.btn-remove {
  margin-left: auto; background: none; border: 1px solid var(--border);
  color: var(--red); border-radius: var(--radius); padding: .3rem .6rem;
  cursor: pointer; font-size: .75rem; flex-shrink: 0;
}
.btn-remove:hover { background: #2d0d0d; border-color: var(--red); }
.path-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
.path-group-label {
  font-size: .68rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .06em; color: var(--muted); margin-bottom: .4rem;
  padding-bottom: .3rem; border-bottom: 1px solid var(--border);
}
.path-row { display: flex; flex-direction: column; gap: .25rem; margin-bottom: .4rem; }
.path-row span { font-size: .72rem; color: var(--muted); }
.btn-add {
  width: 100%; padding: .55rem; border: 1px dashed var(--border);
  background: none; color: var(--muted); border-radius: var(--radius);
  cursor: pointer; font-size: .8rem; margin-top: .5rem;
}
.btn-add:hover { border-color: var(--accent); color: var(--accent); }

/* ── Log box ── */
.log-box {
  background: #0f0f0f;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: .75rem;
  font-family: 'Cascadia Code', 'Fira Code', Consolas, monospace;
  font-size: .75rem;
  line-height: 1.65;
  overflow-x: auto;
  min-height: 200px;
  max-height: 75vh;
  overflow-y: auto;
}
.log-line { display: flex; gap: .65rem; padding: .1rem 0; }
.log-line + .log-line { border-top: 1px solid rgba(255,255,255,.03); }
.log-line:hover { background: rgba(255,255,255,.02); }
.log-ts { color: #444; flex-shrink: 0; user-select: none; }
.log-line.level-INFO    .log-msg { color: #c0c0c0; }
.log-line.level-WARNING .log-msg { color: var(--accent); }
.log-line.level-ERROR   .log-msg { color: var(--red); }
.log-line.level-DEBUG   .log-msg { color: #555; }
.log-line.separator     .log-msg { color: #3a3a3a; }
.log-level {
  flex-shrink: 0; font-size: .65rem; font-weight: 700;
  padding: .05rem .3rem; border-radius: 2px;
  align-self: flex-start; margin-top: .15rem;
}
.level-INFO    .log-level { background: #1a2a3a; color: var(--blue); }
.level-WARNING .log-level { background: #2a2000; color: var(--accent); }
.level-ERROR   .log-level { background: #2d0d0d; color: var(--red); }
.level-DEBUG   .log-level { background: #1a1a1a; color: #555; }
.separator     .log-level { display: none; }

/* ── Misc ── */
.empty { text-align: center; padding: 3rem; color: var(--muted); font-size: .875rem; }
.empty a { color: var(--accent); }
.action-bar { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; margin-top: 1.25rem; }
.text-muted { color: var(--muted); }
.text-accent { color: var(--accent); }
.fw-bold { font-weight: 700; }
.flex { display: flex; }
.gap-half { gap: .5rem; }
.ml-auto { margin-left: auto; }

@media (max-width: 700px) {
  .sidebar { width: 56px; }
  .sidebar-logo span,
  .nav-item span,
  .nav-group-label,
  .nav-group-arrow,
  .nav-group-children { display: none; }
  .layout-main { margin-left: 56px; }
  .path-grid { grid-template-columns: 1fr; }
  .field-row { grid-template-columns: 1fr; }
  .field-row label { text-align: left; }
}
</style>
</head>
<body>

<div class="sidebar">
  <a class="sidebar-logo" href="index.php">
    <img src="images/movarr-logo.svg" alt="Movarr">
    <span>Movarr</span>
  </a>
  <nav>
    <?php foreach ($nav as $entry):
      if ($entry['type'] === 'item'):
        $isActive = ($active === $entry['key']);
    ?>
    <a href="<?= $entry['href'] ?>" class="nav-item<?= $isActive ? ' active' : '' ?>">
      <?= $entry['icon'] ?>
      <span><?= htmlspecialchars($entry['label']) ?></span>
    </a>

    <?php elseif ($entry['type'] === 'group'):
      $childKeys    = array_column($entry['children'], 'key');
      $groupActive  = in_array($active, $childKeys);
      $isExpanded   = in_array($entry['key'], $expanded_groups);
      $groupClasses = 'nav-group';
      if ($groupActive)  $groupClasses .= ' group-active';
      if ($isExpanded)   $groupClasses .= ' expanded';
    ?>
    <div class="<?= $groupClasses ?>" data-group="<?= htmlspecialchars($entry['key']) ?>">
      <button class="nav-group-toggle" onclick="toggleGroup(this)">
        <?= $entry['icon'] ?>
        <span class="nav-group-label"><?= htmlspecialchars($entry['label']) ?></span>
        <svg class="nav-group-arrow" viewBox="0 0 24 24" fill="currentColor">
          <path d="M8 5l8 7-8 7V5z"/>
        </svg>
      </button>
      <div class="nav-group-children">
        <?php foreach ($entry['children'] as $child):
          $childActive = ($active === $child['key']);
        ?>
        <a href="<?= $child['href'] ?>" class="nav-child-link<?= $childActive ? ' active' : '' ?>">
          <?= htmlspecialchars($child['label']) ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; endforeach; ?>
  </nav>
</div>

<div class="layout-main">
  <div class="topbar">
    <span class="topbar-title"><?= htmlspecialchars($title) ?></span>
  </div>
  <div class="content">
    <?php
}

function layout_end(): void { ?>
  </div><!-- .content -->
</div><!-- .layout-main -->

<script>
function toggleGroup(btn) {
  var group = btn.closest('.nav-group');
  group.classList.toggle('expanded');
  try {
    var key = group.dataset.group;
    var open = JSON.parse(localStorage.getItem('nav_open') || '{}');
    open[key] = group.classList.contains('expanded');
    localStorage.setItem('nav_open', JSON.stringify(open));
  } catch(e) {}
}

// Restore persisted open/closed state (only for groups not already set by PHP)
(function() {
  try {
    var open = JSON.parse(localStorage.getItem('nav_open') || '{}');
    document.querySelectorAll('.nav-group').forEach(function(g) {
      var key = g.dataset.group;
      if (key in open) {
        // Only apply if PHP hasn't already set it active (active groups stay open)
        if (!g.classList.contains('group-active')) {
          if (open[key]) g.classList.add('expanded');
          else g.classList.remove('expanded');
        }
      }
    });
  } catch(e) {}
})();
</script>

</body>
</html>
<?php
}
