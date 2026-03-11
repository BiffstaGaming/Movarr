<?php
// $m must be defined before including this file
// Variables: id, name, service, slow_path_mover, fast_path_mover, slow_path_sonarr, fast_path_sonarr, fast_min_free_pct
$m_id               = htmlspecialchars($m['id'] ?? '');
$m_name             = htmlspecialchars($m['name'] ?? '');
$m_service          = $m['service'] ?? 'sonarr';
$m_slow_mover       = htmlspecialchars($m['slow_path_mover'] ?? '');
$m_fast_mover       = htmlspecialchars($m['fast_path_mover'] ?? '');
$m_slow_sonarr      = htmlspecialchars($m['slow_path_sonarr'] ?? '');
$m_fast_sonarr      = htmlspecialchars($m['fast_path_sonarr'] ?? '');
$m_fast_min_free    = (float)($m['fast_min_free_pct'] ?? 0);
?>
<div class="mapping-card">
  <input type="hidden" name="mapping_id[]" value="<?= $m_id ?>">

  <div class="mapping-card-header">
    <input type="text" name="mapping_name[]" value="<?= $m_name ?>" placeholder="Mapping name (e.g. TV Shows)" style="flex:1">
    <select name="mapping_service[]">
      <option value="sonarr" <?= $m_service === 'sonarr' ? 'selected' : '' ?>>Sonarr</option>
      <option value="radarr" <?= $m_service === 'radarr' ? 'selected' : '' ?>>Radarr</option>
    </select>
    <button type="button" class="btn-remove" onclick="removeMapping(this)">Remove</button>
  </div>

  <div class="path-grid">
    <div class="path-group">
      <div class="path-group-label">Mover Container Paths</div>
      <div class="path-row">
        <span>Slow storage (HDD/RAID)</span>
        <input type="text" name="mapping_slow_path_mover[]" value="<?= $m_slow_mover ?>" placeholder="/TvMedia">
      </div>
      <div class="path-row">
        <span>Fast storage (SSD)</span>
        <input type="text" name="mapping_fast_path_mover[]" value="<?= $m_fast_mover ?>" placeholder="/downloads">
      </div>
    </div>
    <div class="path-group">
      <div class="path-group-label">Service (Sonarr/Radarr) Container Paths</div>
      <div class="path-row">
        <span>Slow storage — as seen by service</span>
        <input type="text" name="mapping_slow_path_sonarr[]" value="<?= $m_slow_sonarr ?>" placeholder="/TvMedia">
      </div>
      <div class="path-row">
        <span>Fast storage — as seen by service</span>
        <input type="text" name="mapping_fast_path_sonarr[]" value="<?= $m_fast_sonarr ?>" placeholder="/downloads">
      </div>
    </div>
  </div>
  <div style="margin-top:.6rem;display:flex;align-items:center;gap:.5rem">
    <span style="font-size:.72rem;color:var(--muted)">Min free space on fast storage before moving to fast (%):</span>
    <input type="number" name="mapping_fast_min_free_pct[]" value="<?= $m_fast_min_free ?>"
           min="0" max="99" step="1" style="width:70px" placeholder="0">
    <span style="font-size:.72rem;color:var(--muted)">Leave 0 to disable.</span>
  </div>
</div>
