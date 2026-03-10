<?php
/**
 * Library and watch-history sync functions.
 *
 * Requires db.php (norm_title) and settings.php to be loaded first.
 */

// ── Shared helpers ────────────────────────────────────────────────────────────

/** Append a timestamped line to the main log file. */
function sync_log(string $msg, string $level = 'INFO'): void
{
    $line = date('Y-m-d H:i:s') . " [$level] [sync] $msg" . PHP_EOL;
    @file_put_contents(log_file(), $line, FILE_APPEND | LOCK_EX);
}

/** Path to the sync-state JSON file (stores last-sync timestamps). */
function sync_state_file(): string { return config_base() . '/sync_state.json'; }

function read_sync_state(): array
{
    $f = sync_state_file();
    if (!file_exists($f)) return [];
    return json_decode(@file_get_contents($f), true) ?: [];
}

function write_sync_state(array $state): void
{
    @file_put_contents(sync_state_file(), json_encode($state, JSON_PRETTY_PRINT));
}

/** Fetch data from Tautulli's HTTP API. Returns the `data` field on success, null on failure. */
function tautulli_api(string $base, string $key, string $cmd, array $params = []): ?array
{
    $params = array_merge(['apikey' => $key, 'cmd' => $cmd], $params);
    $url    = $base . '?' . http_build_query($params);
    $ctx    = stream_context_create(['http' => ['timeout' => 15]]);
    $raw    = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return (isset($data['response']['result']) && $data['response']['result'] === 'success')
        ? $data['response']['data'] : null;
}

// ── sync_library: Sonarr + Radarr → media_library ────────────────────────────

function sync_library(PDO $db, array $s): array
{
    $mappings = $s['path_mappings'] ?? [];
    $stats    = ['sonarr' => 0, 'radarr' => 0, 'removed' => 0, 'errors' => []];
    $now      = time();

    sync_log('Library sync started');

    $resolve_mapping = function (string $path) use ($mappings): array {
        foreach ($mappings as $m) {
            $fast = rtrim($m['fast_path_mover'] ?? '', '/');
            $slow = rtrim($m['slow_path_mover'] ?? '', '/');
            if ($fast !== '' && ($path === $fast || str_starts_with($path, $fast . '/'))) return [$m['id'], 'fast'];
            if ($slow !== '' && ($path === $slow || str_starts_with($path, $slow . '/'))) return [$m['id'], 'slow'];
        }
        return ['', 'unmapped'];
    };

    $get_poster = function (array $images): string {
        foreach ($images as $img) {
            if (($img['coverType'] ?? '') === 'poster') {
                return $img['remoteUrl'] ?? $img['url'] ?? '';
            }
        }
        return '';
    };

    $upsert = $db->prepare("
        INSERT INTO media_library
            (service, service_id, external_id, title, title_norm, alt_titles,
             year, path, folder, mapping_id, location, size_on_disk,
             status, genres, poster_url, synced_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(service, service_id) DO UPDATE SET
            external_id  = excluded.external_id,
            title        = excluded.title,
            title_norm   = excluded.title_norm,
            alt_titles   = excluded.alt_titles,
            year         = excluded.year,
            path         = excluded.path,
            folder       = excluded.folder,
            mapping_id   = excluded.mapping_id,
            location     = excluded.location,
            size_on_disk = excluded.size_on_disk,
            status       = excluded.status,
            genres       = excluded.genres,
            poster_url   = excluded.poster_url,
            synced_at    = excluded.synced_at
    ");

    // ── Sonarr ────────────────────────────────────────────────────────────────
    if (!empty($s['sonarr']['url']) && !empty($s['sonarr']['api_key'])) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 20,
            'header'  => "X-Api-Key: {$s['sonarr']['api_key']}\r\n",
        ]]);
        $raw = @file_get_contents(rtrim($s['sonarr']['url'], '/') . '/api/v3/series', false, $ctx);
        if ($raw !== false) {
            $db->beginTransaction();
            foreach (json_decode($raw, true) ?: [] as $sr) {
                $path = $sr['path'] ?? '';
                [$map_id, $loc] = $resolve_mapping($path);
                $alts = [];
                foreach ($sr['alternateTitles'] ?? [] as $alt) {
                    $n = norm_title($alt['title'] ?? '');
                    if ($n !== '') $alts[] = $n;
                }
                $upsert->execute([
                    'sonarr', (int)$sr['id'], (int)($sr['tvdbId'] ?? 0),
                    $sr['title'] ?? '', norm_title($sr['title'] ?? ''), json_encode($alts),
                    (int)($sr['year'] ?? 0), $path, basename($path), $map_id, $loc,
                    (int)($sr['statistics']['sizeOnDisk'] ?? 0),
                    $sr['status'] ?? '', json_encode($sr['genres'] ?? []),
                    $get_poster($sr['images'] ?? []), $now,
                ]);
                $stats['sonarr']++;
            }
            $del = $db->prepare("DELETE FROM media_library WHERE service='sonarr' AND synced_at < ?");
            $del->execute([$now]);
            $stats['removed'] += $del->rowCount();
            $db->commit();
            sync_log("Sonarr: {$stats['sonarr']} series upserted, {$stats['removed']} removed");
        } else {
            $stats['errors'][] = 'Sonarr API unreachable';
            sync_log('Sonarr API unreachable', 'ERROR');
        }
    }

    // ── Radarr ────────────────────────────────────────────────────────────────
    if (!empty($s['radarr']['url']) && !empty($s['radarr']['api_key'])) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 20,
            'header'  => "X-Api-Key: {$s['radarr']['api_key']}\r\n",
        ]]);
        $raw = @file_get_contents(rtrim($s['radarr']['url'], '/') . '/api/v3/movie', false, $ctx);
        if ($raw !== false) {
            $db->beginTransaction();
            foreach (json_decode($raw, true) ?: [] as $mv) {
                $path = $mv['path'] ?? '';
                [$map_id, $loc] = $resolve_mapping($path);
                $alts = [];
                foreach ($mv['alternativeTitles'] ?? [] as $alt) {
                    $n = norm_title($alt['title'] ?? '');
                    if ($n !== '') $alts[] = $n;
                }
                $upsert->execute([
                    'radarr', (int)$mv['id'], (int)($mv['tmdbId'] ?? 0),
                    $mv['title'] ?? '', norm_title($mv['title'] ?? ''), json_encode($alts),
                    (int)($mv['year'] ?? 0), $path, basename($path), $map_id, $loc,
                    (int)($mv['sizeOnDisk'] ?? $mv['movieFile']['size'] ?? 0),
                    $mv['status'] ?? '', json_encode($mv['genres'] ?? []),
                    $get_poster($mv['images'] ?? []), $now,
                ]);
                $stats['radarr']++;
            }
            $radarr_removed = 0;
            $del = $db->prepare("DELETE FROM media_library WHERE service='radarr' AND synced_at < ?");
            $del->execute([$now]);
            $radarr_removed = $del->rowCount();
            $stats['removed'] += $radarr_removed;
            $db->commit();
            sync_log("Radarr: {$stats['radarr']} movies upserted, {$radarr_removed} removed");
        } else {
            $stats['errors'][] = 'Radarr API unreachable';
            sync_log('Radarr API unreachable', 'ERROR');
        }
    }

    // Persist sync timestamp
    $state = read_sync_state();
    $state['library_synced_at']     = $now;
    $state['library_sonarr_count']  = $stats['sonarr'];
    $state['library_radarr_count']  = $stats['radarr'];
    write_sync_state($state);

    sync_log(sprintf(
        'Library sync complete — sonarr: %d, radarr: %d, removed: %d%s',
        $stats['sonarr'], $stats['radarr'], $stats['removed'],
        $stats['errors'] ? ', errors: ' . implode(', ', $stats['errors']) : ''
    ));

    return $stats;
}

// ── sync_tautulli: Tautulli watch history → media_library.last_watched_at ─────

function sync_tautulli(PDO $db, array $s): array
{
    $stats = [
        'shows_updated'  => 0,
        'movies_updated' => 0,
        'api_calls'      => 0,
        'errors'         => [],
    ];

    if (empty($s['tautulli']['url']) || empty($s['tautulli']['api_key'])) {
        $stats['errors'][] = 'Tautulli not configured';
        sync_log('Tautulli sync skipped: not configured', 'WARN');
        return $stats;
    }

    sync_log('Tautulli watch-history sync started');

    $base      = rtrim($s['tautulli']['url'], '/') . '/api/v2';
    $key       = $s['tautulli']['api_key'];
    $cutoff    = date('Y-m-d', strtotime('-365 days'));
    $page_size = 1000;

    // ── GUID cache (plex:// → tvdb/tmdb ID resolution) ────────────────────────
    $guid_cache_file = config_base() . '/guid_cache.json';
    $guid_cache      = [];
    if (file_exists($guid_cache_file)) {
        $gc = json_decode(@file_get_contents($guid_cache_file), true);
        if (is_array($gc)) $guid_cache = $gc;
    }
    $cache_dirty = false;
    $now         = time();

    /**
     * Resolve a plex:// rating_key to a numeric external ID.
     * Calls Tautulli get_metadata, caches successful results for 24 h.
     */
    $resolve_plex = function (string $rk, string $pattern) use (
        $base, $key, &$guid_cache, &$cache_dirty, &$stats, $now
    ): int {
        if (!$rk) return 0;
        $ckey = $pattern[0] . ':' . $rk;
        if (isset($guid_cache[$ckey]) && $guid_cache[$ckey]['exp'] > $now) {
            return (int)$guid_cache[$ckey]['id'];
        }
        $stats['api_calls']++;
        $meta = tautulli_api($base, $key, 'get_metadata', ['rating_key' => $rk]);
        $id   = 0;
        if ($meta) {
            foreach ($meta['guids'] ?? [] as $g) {
                $gid = is_array($g) ? ($g['id'] ?? '') : (string)$g;
                if (preg_match($pattern, $gid, $m)) { $id = (int)$m[1]; break; }
            }
            if (!$id) {
                $gs = $meta['guid'] ?? '';
                if (preg_match($pattern, $gs, $m)) $id = (int)$m[1];
            }
        }
        if ($id > 0) {
            $guid_cache[$ckey] = ['id' => $id, 'exp' => $now + 86400];
            $cache_dirty = true;
        }
        return $id;
    };

    // ── TV Shows (episode history, grouped by grandparent) ────────────────────
    $show_map  = []; // tvdb_id (int) => max Unix timestamp
    $plex_show = []; // grandparent_rating_key => max timestamp (unresolved plex://)
    $start     = 0;

    do {
        $stats['api_calls']++;
        $data    = tautulli_api($base, $key, 'get_history', [
            'media_type' => 'episode',
            'length'     => $page_size,
            'start'      => $start,
            'after'      => $cutoff,
        ]);
        if (!$data) {
            $stats['errors'][] = 'Episode history fetch failed';
            sync_log('Episode history fetch failed', 'ERROR');
            break;
        }
        $records = $data['data'] ?? [];
        foreach ($records as $r) {
            $date = (int)($r['date'] ?? 0);
            if (!$date) continue;
            $guid = $r['grandparent_guid'] ?? '';
            if (preg_match('/(?:thetvdb|tvdb):\/\/(\d+)/i', $guid, $m)) {
                $tid = (int)$m[1];
                if (!isset($show_map[$tid]) || $date > $show_map[$tid]) $show_map[$tid] = $date;
            } elseif (str_starts_with($guid, 'plex://')) {
                $rk = (string)($r['grandparent_rating_key'] ?? '');
                if ($rk && (!isset($plex_show[$rk]) || $date > $plex_show[$rk])) $plex_show[$rk] = $date;
            }
        }
        $start += $page_size;
    } while (count($records) === $page_size);

    sync_log(sprintf('Episode history: %d show buckets (direct) + %d plex:// to resolve',
        count($show_map), count($plex_show)));

    // Resolve plex:// show GUIDs via get_metadata
    foreach ($plex_show as $rk => $date) {
        $tid = $resolve_plex($rk, '/(?:thetvdb|tvdb):\/\/(\d+)/i');
        if ($tid > 0 && (!isset($show_map[$tid]) || $date > $show_map[$tid])) {
            $show_map[$tid] = $date;
        }
    }

    // Bulk-update media_library for shows
    if ($show_map) {
        $stmt = $db->prepare(
            "UPDATE media_library SET last_watched_at = ?
             WHERE service = 'sonarr' AND external_id = ?
               AND (last_watched_at IS NULL OR last_watched_at < ?)"
        );
        foreach ($show_map as $tid => $ts) {
            $stmt->execute([$ts, $tid, $ts]);
            $stats['shows_updated'] += $stmt->rowCount();
        }
    }

    // ── Movies ────────────────────────────────────────────────────────────────
    $movie_map  = []; // tmdb_id => max timestamp
    $plex_movie = []; // rating_key => max timestamp
    $start      = 0;

    do {
        $stats['api_calls']++;
        $data    = tautulli_api($base, $key, 'get_history', [
            'media_type' => 'movie',
            'length'     => $page_size,
            'start'      => $start,
            'after'      => $cutoff,
        ]);
        if (!$data) {
            $stats['errors'][] = 'Movie history fetch failed';
            sync_log('Movie history fetch failed', 'ERROR');
            break;
        }
        $records = $data['data'] ?? [];
        foreach ($records as $r) {
            $date = (int)($r['date'] ?? 0);
            if (!$date) continue;
            $guid = $r['guid'] ?? '';
            if (preg_match('/(?:themoviedb|tmdb):\/\/(\d+)/i', $guid, $m)) {
                $mid = (int)$m[1];
                if (!isset($movie_map[$mid]) || $date > $movie_map[$mid]) $movie_map[$mid] = $date;
            } elseif (str_starts_with($guid, 'plex://')) {
                $rk = (string)($r['rating_key'] ?? '');
                if ($rk && (!isset($plex_movie[$rk]) || $date > $plex_movie[$rk])) $plex_movie[$rk] = $date;
            }
        }
        $start += $page_size;
    } while (count($records) === $page_size);

    foreach ($plex_movie as $rk => $date) {
        $mid = $resolve_plex($rk, '/(?:themoviedb|tmdb):\/\/(\d+)/i');
        if ($mid > 0 && (!isset($movie_map[$mid]) || $date > $movie_map[$mid])) {
            $movie_map[$mid] = $date;
        }
    }

    if ($movie_map) {
        $stmt = $db->prepare(
            "UPDATE media_library SET last_watched_at = ?
             WHERE service = 'radarr' AND external_id = ?
               AND (last_watched_at IS NULL OR last_watched_at < ?)"
        );
        foreach ($movie_map as $mid => $ts) {
            $stmt->execute([$ts, $mid, $ts]);
            $stats['movies_updated'] += $stmt->rowCount();
        }
    }

    // Flush GUID cache
    if ($cache_dirty) {
        @file_put_contents($guid_cache_file, json_encode($guid_cache));
    }

    // Persist sync state
    $state = read_sync_state();
    $state['tautulli_synced_at']      = time();
    $state['tautulli_shows_updated']  = $stats['shows_updated'];
    $state['tautulli_movies_updated'] = $stats['movies_updated'];
    write_sync_state($state);

    sync_log(sprintf(
        'Tautulli sync complete — shows: %d updated, movies: %d updated, API calls: %d%s',
        $stats['shows_updated'],
        $stats['movies_updated'],
        $stats['api_calls'],
        $stats['errors'] ? ', errors: ' . implode(', ', $stats['errors']) : ''
    ));

    return $stats;
}
