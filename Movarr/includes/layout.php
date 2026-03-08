<?php
// layout_start($title, $active) - outputs html/head/sidebar/nav up to content div
// layout_end() - closes everything
// $active: 'dashboard', 'config', 'queue', 'history', 'logs', 'manual'

function layout_start(string $title, string $active, string $extra_head = ''): void {
    // Each entry: type='item' or type='label'
    $nav = [
        ['type' => 'item',  'key' => 'dashboard', 'href' => 'index.php',   'label' => 'Dashboard', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>'],
        ['type' => 'item',  'key' => 'manual',    'href' => 'manual.php',  'label' => 'Manual',    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M13 2v8h8l-8 12v-8H5L13 2z"/></svg>'],
        ['type' => 'label', 'label' => 'Activity'],
        ['type' => 'item',  'key' => 'queue',     'href' => 'queue.php',   'label' => 'Queue',     'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>'],
        ['type' => 'item',  'key' => 'history',   'href' => 'history.php', 'label' => 'History',   'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6a7 7 0 1 1 2.05 4.95L6.64 18.36A9 9 0 1 0 13 3zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>'],
        ['type' => 'label', 'label' => 'System'],
        ['type' => 'item',  'key' => 'logs',      'href' => 'logs.php',    'label' => 'Logs',      'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM6 20V4h5v7h7v9H6z"/></svg>'],
        ['type' => 'item',  'key' => 'config',    'href' => 'config.php',  'label' => 'Settings',  'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M19.14 12.94c.04-.3.06-.61.06-.94s-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96a6.97 6.97 0 0 0-1.62-.94l-.36-2.54a.484.484 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — Movarr</title>
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
}

.sidebar-logo {
  display: flex;
  align-items: center;
  gap: .65rem;
  padding: 1.1rem 1.1rem 1rem;
  border-bottom: 1px solid rgba(255,255,255,.06);
  text-decoration: none;
}
.sidebar-logo img { width: 32px; height: 32px; border-radius: 6px; flex-shrink: 0; }
.sidebar-logo span {
  font-size: 1rem;
  font-weight: 700;
  color: var(--accent);
  letter-spacing: .02em;
}

.sidebar nav { padding: .5rem 0; flex: 1; }
.sidebar nav a {
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
}
.sidebar nav a:hover {
  color: var(--text);
  background: rgba(255,255,255,.04);
  border-left-color: rgba(255,255,255,.15);
}
.sidebar nav a.active {
  color: var(--accent);
  background: var(--accent-dim);
  border-left-color: var(--accent);
}
.sidebar nav a svg { flex-shrink: 0; opacity: .8; }
.sidebar nav a.active svg { opacity: 1; }
.nav-section-label {
  padding: .9rem 1.1rem .25rem;
  font-size: .6rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: rgba(255,255,255,.25);
}

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

.content { padding: 1.5rem; flex: 1; max-width: 1200px; }

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

/* ── Media cards (dashboard) ── */
.media-grid { display: flex; flex-direction: column; gap: 2px; }
.media-row {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: .55rem .75rem;
  background: var(--surface);
  border-bottom: 1px solid rgba(255,255,255,.04);
  transition: background .1s;
}
.media-row:first-child { border-radius: var(--radius) var(--radius) 0 0; }
.media-row:last-child  { border-bottom: none; border-radius: 0 0 var(--radius) var(--radius); }
.media-row:only-child  { border-radius: var(--radius); }
.media-row:hover { background: var(--surface2); }
.media-thumb {
  width: 40px; height: 40px;
  object-fit: cover;
  border-radius: 3px;
  flex-shrink: 0;
  background: var(--border);
}
.media-thumb-ph {
  width: 40px; height: 40px;
  border-radius: 3px;
  flex-shrink: 0;
  background: var(--surface2);
  display: flex; align-items: center; justify-content: center;
  color: var(--muted); font-size: 1.1rem;
}
.media-info { flex: 1; min-width: 0; }
.media-title { font-weight: 600; font-size: .875rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.media-meta  { font-size: .75rem; color: var(--muted); margin-top: 1px; }
.media-right { margin-left: auto; flex-shrink: 0; text-align: right; }
.media-plays { font-size: .75rem; font-weight: 700; color: var(--accent); }

/* ── Mapping cards (config) ── */
.mapping-card {
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: .85rem;
  margin-bottom: .5rem;
}
.mapping-card-header {
  display: flex; align-items: center; gap: .6rem; margin-bottom: .75rem;
}
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
  .sidebar-logo span, .sidebar nav a span { display: none; }
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
      if ($entry['type'] === 'label'): ?>
    <div class="nav-section-label"><?= htmlspecialchars($entry['label']) ?></div>
    <?php else: ?>
    <a href="<?= $entry['href'] ?>" class="<?= $active === $entry['key'] ? 'active' : '' ?>">
      <?= $entry['icon'] ?>
      <span><?= $entry['label'] ?></span>
    </a>
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

</body>
</html>
<?php
}
