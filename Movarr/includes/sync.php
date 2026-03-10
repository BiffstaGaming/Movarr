<?php
/**
 * Library sync: fetches all series from Sonarr and all movies from Radarr,
 * upserts them into the local media_library table, and removes stale entries.
 *
 * Requires db.php (norm_title, db_connect) and settings.php to be loaded first.
 */

function sync_library(PDO $db, array $s): array
{
    $mappings = $s['path_mappings'] ?? [];
    $stats    = ['sonarr' => 0, 'radarr' => 0, 'removed' => 0, 'errors' => []];
    $now      = time();

    // Resolve a filesystem path against configured fast/slow mappings
    $resolve_mapping = function (string $path) use ($mappings): array {
        foreach ($mappings as $m) {
            $fast = rtrim($m['fast_path_mover'] ?? '', '/');
            $slow = rtrim($m['slow_path_mover'] ?? '', '/');
            if ($fast !== '' && ($path === $fast || str_starts_with($path, $fast . '/'))) return [$m['id'], 'fast'];
            if ($slow !== '' && ($path === $slow || str_starts_with($path, $slow . '/'))) return [$m['id'], 'slow'];
        }
        return ['', 'unmapped'];
    };

    // Extract the first poster remoteUrl from an images array
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
                    'sonarr',
                    (int)$sr['id'],
                    (int)($sr['tvdbId'] ?? 0),
                    $sr['title'] ?? '',
                    norm_title($sr['title'] ?? ''),
                    json_encode($alts),
                    (int)($sr['year'] ?? 0),
                    $path,
                    basename($path),
                    $map_id,
                    $loc,
                    (int)($sr['statistics']['sizeOnDisk'] ?? 0),
                    $sr['status'] ?? '',
                    json_encode($sr['genres'] ?? []),
                    $get_poster($sr['images'] ?? []),
                    $now,
                ]);
                $stats['sonarr']++;
            }
            // Remove series deleted from Sonarr since this sync
            $del = $db->prepare("DELETE FROM media_library WHERE service='sonarr' AND synced_at < ?");
            $del->execute([$now]);
            $stats['removed'] += $del->rowCount();
            $db->commit();
        } else {
            $stats['errors'][] = 'Sonarr API unreachable';
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
                    'radarr',
                    (int)$mv['id'],
                    (int)($mv['tmdbId'] ?? 0),
                    $mv['title'] ?? '',
                    norm_title($mv['title'] ?? ''),
                    json_encode($alts),
                    (int)($mv['year'] ?? 0),
                    $path,
                    basename($path),
                    $map_id,
                    $loc,
                    (int)($mv['sizeOnDisk'] ?? $mv['movieFile']['size'] ?? 0),
                    $mv['status'] ?? '',
                    json_encode($mv['genres'] ?? []),
                    $get_poster($mv['images'] ?? []),
                    $now,
                ]);
                $stats['radarr']++;
            }
            $del = $db->prepare("DELETE FROM media_library WHERE service='radarr' AND synced_at < ?");
            $del->execute([$now]);
            $stats['removed'] += $del->rowCount();
            $db->commit();
        } else {
            $stats['errors'][] = 'Radarr API unreachable';
        }
    }

    return $stats;
}
