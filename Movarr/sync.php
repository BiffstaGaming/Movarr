<?php
/**
 * Library sync endpoint.
 *
 * Called by:
 *   - Sonarr / Radarr webhooks  →  POST /sync.php?token=XXX
 *   - Dashboard "Sync" button   →  POST /sync.php?token=XXX
 *   - Cron / CLI                →  php sync.php  (token not required)
 *
 * Response: JSON { ok, stats, time }
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key); $val = trim($val, " \t\n\r\0\x0B\"'");
        if (!array_key_exists($key, $_ENV)) { putenv("$key=$val"); $_ENV[$key] = $val; }
    }
}

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/sync.php';

$s = load_settings();

// ── Auto-generate sync token if not set ───────────────────────────────────────
if (empty($s['sync_token'])) {
    $s['sync_token'] = bin2hex(random_bytes(16));
    save_settings($s);
}

// ── Auth (skip for CLI) ───────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    $provided = $_GET['token'] ?? $_POST['token']
             ?? (sscanf(getallheaders()['Authorization'] ?? '', 'Bearer %s')[0] ?? '');
    if (!hash_equals($s['sync_token'], (string)$provided)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized — provide ?token=YOUR_SYNC_TOKEN']);
        exit;
    }
    header('Content-Type: application/json');
}

// ── Run sync ──────────────────────────────────────────────────────────────────
$db    = db_connect();
$stats = sync_library($db, $s);

$out = ['ok' => empty($stats['errors']), 'stats' => $stats, 'time' => date('c')];

if (PHP_SAPI === 'cli') {
    echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo json_encode($out);
}
