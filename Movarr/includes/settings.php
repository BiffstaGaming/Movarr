<?php

function config_base(): string {
    if (getenv('CONFIG_PATH')) {
        return rtrim(getenv('CONFIG_PATH'), '/');
    }
    // Local dev fallback: store in a config/ folder next to the project
    $local = __DIR__ . '/../config';
    if (!is_dir($local)) {
        mkdir($local, 0755, true);
    }
    return realpath($local);
}

function settings_file(): string      { return config_base() . '/settings.json'; }
function trigger_file(): string        { return config_base() . '/.trigger'; }
function manual_trigger_file(): string { return config_base() . '/.manual_trigger'; }
function log_file(): string            { return config_base() . '/mover.log'; }
function queue_file(): string          { return config_base() . '/queue.json'; }
function db_file(): string             { return config_base() . '/movarr.db'; }
function health_file(): string         { return config_base() . '/health.json'; }
function disk_usage_file(): string     { return config_base() . '/disk_usage.json'; }

function load_settings(): array {
    $defaults = [
        'tautulli' => [
            'url'     => getenv('TAUTULLI_URL') ?: 'http://vm-plex.home:8181',
            'api_key' => getenv('TAUTULLI_API_KEY') ?: '',
        ],
        'sonarr' => [
            'url'     => getenv('SONARR_URL') ?: '',
            'api_key' => getenv('SONARR_API_KEY') ?: '',
        ],
        'radarr' => [
            'url'     => getenv('RADARR_URL') ?: '',
            'api_key' => getenv('RADARR_API_KEY') ?: '',
        ],
        'plex' => [
            'url'   => getenv('PLEX_URL') ?: '',
            'token' => getenv('PLEX_TOKEN') ?: '',
        ],
        'watched_days'   => 30,
        'dry_run'        => true,
        'list_only'      => false,
        'cron_schedule'  => '0 3 * * *',
        'path_mappings'  => [],
    ];

    $file = settings_file();
    if (!file_exists($file)) return $defaults;

    $raw = json_decode(file_get_contents($file), true);
    if (!is_array($raw)) return $defaults;

    // Deep merge: saved values win, defaults fill gaps
    return array_replace_recursive($defaults, $raw);
}

function save_settings(array $settings): bool {
    $dir = config_base();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return file_put_contents(
        settings_file(),
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

function write_trigger(): bool {
    return file_put_contents(trigger_file(), date('c')) !== false;
}
