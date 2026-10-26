<?php
// W0.2: colours come from the shared status map — the old keys spelled without
// the "In " prefix matched nothing, because the enum value is 'In Maintenance'.
$statusColor = array(); $statusBg = array();
foreach (equip_statuses() as $s) {
    $m = equip_status_map($s);
    $statusColor[$s] = $m['color'];
    $statusBg[$s]    = $m['bg'];
}
$asset_h = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$assets = isset($assets) ? $assets : array();
$eq_types = isset($eq_types) ? $eq_types : array();
$status_counts = array('All' => count($assets));
foreach (equip_statuses() as $s) $status_counts[$s] = 0;
$type_summary = array();
foreach ($assets as $asset) {
    if (isset($status_counts[$asset['status']])) $status_counts[$asset['status']]++;
    $type_name = trim((string)(isset($asset['type_name']) ? $asset['type_name'] : '')) ?: 'Other';
    if (!isset($type_summary[$type_name])) $type_summary[$type_name] = array('count' => 0, 'availability' => 0.0);
    $type_summary[$type_name]['count']++;
    $type_summary[$type_name]['availability'] += (float)$asset['availability'];
}
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">Asset Register</h1>
    <div class="cs">Full fleet inventory — <?=date('d F Y')?></div>
  </div>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <?php if ($role_id == 1): ?>
    <a href="<?=base_url('equipment/create')?>" class="btn btn-primary btn-icon" title="Add one asset manually">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Register
    </a>
    <button type="button" class="btn btn-primary btn-icon" onclick="assetImportToggle()">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Import Excel
    </button>
    <?php endif; ?>
    <a href="<?=base_url('reports/asset_template_xlsx')?>" class="btn btn-ghost btn-icon" title="Empty workbook with the correct column structure">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
      Import Template
    </a>
    <a href="<?=base_url('reports/export_asset_register_xlsx')?>"
       class="btn btn-success btn-icon" title="Full register — every field, plus import-ready sheet">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export Excel
    </a>
    <button onclick="window.print()" class="btn btn-ghost btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print
    </button>
  </div>
</div>

<?php
$import_result = isset($import_result) ? $import_result : null;
$n = function($k) use ($import_result) {
    return ($import_result && isset($import_result['summary'][$k])) ? (int)$import_result['summary'][$k] : 0;
};
$role_id = isset($role_id) ? $role_id : 0;
?>

<?php if ($import_result): ?>
<!-- Import result summary -->
<div class="card mb-4" id="importResult">
  <div class="ch" style="padding:12px 18px 10px;border-bottom:1px solid var(--g2);">
    <div>
      <div class="ct" style="font-size:13px;">Import summary<?= $import_result['file_name'] ? ' — '.htmlspecialchars((string)($import_result['file_name'])) : '' ?></div>
      <div class="cs">
        <?= $import_result['mode'] === 'validate' ? 'Validation only — nothing was saved' : 'Sheet "'.$import_result['sheet'].'"'.($import_result['duplicate_action'] ? ' • duplicates: '.$import_result['duplicate_action'] : '') ?>
      </div>
    </div>
    <?php if (!empty($import_result['report_token'])): ?>
    <a class="ca" href="<?=base_url('reports/asset_import_report/'.$import_result['report_token'])?>">Download full report ↓</a>
    <?php endif; ?>
  </div>
  <div style="padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;">
    <span class="badge b-secondary"><?=$n('total_rows')?> rows read</span>
    <span class="badge b-completed"><?=$n('inserted')?> created</span>
    <span class="badge b-info"><?=$n('updated')?> updated</span>
    <span class="badge b-warning"><?=$n('skipped')?> skipped</span>
    <span class="badge b-critical"><?=$n('failed')?> failed</span>
    <?php if ($n('warnings')): ?><span class="badge b-low"><?=$n('warnings')?> warning(s)</span><?php endif; ?>
  </div>

  <?php if (!empty($import_result['errors'])): ?>
  <div style="padding:0 18px 14px;color:var(--red);font-size:12.5px;">
    <?php foreach ($import_result['errors'] as $e): ?><div>• <?=htmlspecialchars((string)($e))?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if (!empty($import_result['warnings'])): ?>
  <div style="padding:0 18px 14px;color:var(--amber);font-size:12.5px;">
    <?php foreach ($import_result['warnings'] as $w): ?><div>• <?=htmlspecialchars((string)($w))?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($import_result['rows'])): ?>
  <?php $shown = array_slice($import_result['rows'], 0, 200); ?>
  <div style="overflow-x:auto;max-height:420px;">
    <table class="tbl">
      <thead>
        <tr><th style="width:60px;">ROW</th><th>MACHINE ID</th><th style="width:100px;">RESULT</th>
            <th>DETAIL</th><th>WARNINGS</th></tr>
      </thead>
      <tbody>
      <?php foreach ($shown as $r):
        $act = $r['action'];
        $cls = $act === 'inserted' ? 'b-completed' : ($act === 'updated' ? 'b-info'
             : ($act === 'skipped' ? 'b-warning' : ($act === 'failed' ? 'b-critical' : 'b-secondary')));
      ?>
      <tr>
        <td class="mono" style="color:var(--t3);"><?=$r['row']?></td>
        <td class="mono fw-600"><?=htmlspecialchars((string)($r['machine_id']))?></td>
        <td><span class="badge <?=$cls?>"><?=strtoupper((string)($act))?></span></td>
        <td style="font-size:11.5px;color:var(--t2);">
          <?=htmlspecialchars((string)(implode(' | ', isset($r['errors']) && $r['errors'] ? $r['errors'] : array())))?>
          <?php if (empty($r['errors']) && !empty($r['values']['type_name'])): ?>
            <span style="color:var(--t3);"><?=htmlspecialchars((string)(trim((string)($r['values']['type_name'].' '.($r['values']['brand'] ?? '').' '.($r['values']['model'] ?? '')))))?></span>
          <?php endif; ?>
        </td>
        <td style="font-size:11.5px;color:var(--amber);"><?=htmlspecialchars((string)(implode(' | ', isset($r['warnings']) ? $r['warnings'] : array())))?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($import_result['rows']) > count($shown)): ?>
  <div style="padding:10px 18px;font-size:11.5px;color:var(--t3);">
    Showing the first <?=count($shown)?> of <?=count($import_result['rows'])?> rows —
    <a href="<?=base_url('reports/asset_import_report/'.$import_result['report_token'])?>">download the full report</a>.
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($role_id == 1): ?>
<!-- Import panel -->
<div class="card mb-4" id="assetImportPanel" style="display:none;">
  <div class="ch" style="padding:12px 18px 10px;border-bottom:1px solid var(--g2);">
    <div>
      <div class="ct" style="font-size:13px;">Import assets from Excel</div>
      <div class="cs">Upload a workbook prepared with the import template (.xlsx)</div>
    </div>
    <a class="ca" href="<?=base_url('reports/asset_template_xlsx')?>">Download template ↓</a>
  </div>
  <?=form_open_multipart('reports/import_asset_register')?>
    <div style="padding:18px;display:grid;gap:16px;grid-template-columns:1.4fr 1fr 1fr;">
      <div>
        <label class="form-label">Excel file (.xlsx) <span class="req">*</span></label>
        <input type="file" name="asset_file" class="form-control" accept=".xlsx" required>
        <div class="cs" style="margin-top:6px;">Required columns: <b>Machine ID</b> and <b>Type</b>. Optional: brand, model, year, serial, engine, hours, km, location, status, purchase date, warranty expiry, notes.</div>
      </div>
      <div>
        <label class="form-label">If a Machine ID already exists</label>
        <select name="duplicate_action" class="form-select">
          <option value="skip" selected>Skip it — keep the current record (safest)</option>
          <option value="update">Update the existing asset with the file values</option>
          <option value="fail">Reject the row and report it as an error</option>
        </select>
        <div class="cs" style="margin-top:6px;">Blank cells never clear an existing value.</div>
      </div>
      <div>
        <label class="form-label">Options</label>
        <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;margin-bottom:8px;">
          <input type="checkbox" name="create_types" value="1">
          Create missing equipment types automatically
        </label>
        <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;">
          <input type="checkbox" name="dry_run" value="1">
          Validate only (dry run) — change nothing
        </label>
      </div>
    </div>
    <div style="padding:0 18px 18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary btn-icon">
        <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Start import
      </button>
      <button type="button" class="btn btn-ghost" onclick="assetImportToggle()">Cancel</button>
      <span class="cs">Every row is reported: created, updated, skipped or failed with the reason. Good rows are imported even when other rows fail.</span>
    </div>
  <?=form_close()?>
</div>
<script>
function assetImportToggle(){
  var p = document.getElementById('assetImportPanel');
  if (!p) return;
  p.style.display = (p.style.display === 'none' || !p.style.display) ? 'block' : 'none';
  if (p.style.display === 'block') p.scrollIntoView({behavior:'smooth', block:'start'});
}
<?php if ($import_result): ?>
document.addEventListener('DOMContentLoaded', function(){
  var r = document.getElementById('importResult');
  if (r) r.scrollIntoView({behavior:'smooth', block:'start'});
});
<?php endif; ?>
</script>
<?php endif; ?>

<?php if (!empty($import_history)): ?>
<div class="cs" style="margin:-6px 0 16px;color:var(--t3);">
  Recent imports:
  <?php $h = array(); foreach ($import_history as $row) {
      $h[] = date('d/m/Y H:i', strtotime($row->created_at)).' — '.htmlspecialchars((string)($row->file_name))
           .' ('.(int)$row->imported.' created, '.(int)$row->updated.' updated, '.(int)$row->failed.' failed)';
  } echo implode(' • ', $h); ?>
</div>
<?php endif; ?>

<!-- KPI strip -->
<div class="grid g-4 mb-4">
  <div class="kpi" style="animation-delay:.04s">
    <div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total Assets</span>
    <div class="kpi-num"><?=$total?><sup>units</sup></div>
  </div>
  <div class="kpi" style="animation-delay:.08s">
    <div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Non-retired Fleet</span>
      <div class="kpi-num"><?=$active?></div>
    <div class="kpi-chip chip-up"><?=round($active/max($total,1)*100)?>%</div>
  </div>
  <div class="kpi" style="animation-delay:.12s">
    <div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">Breakdown</span>
    <div class="kpi-num"><?=$breakdown?></div>
    <?php if($breakdown>0):?>
    <div class="kpi-chip chip-dn"><?=round($breakdown/max($total,1)*100)?>%</div>
    <?php endif;?>
  </div>
  <div class="kpi" style="animation-delay:.16s">
    <div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Warranty Expired</span>
    <div class="kpi-num"><?=$warranty_exp?></div>
    <?php if($warranty_exp>0):?>
    <div class="kpi-chip chip-warn">Review needed</div>
    <?php endif;?>
  </div>
</div>

<!-- Asset register: one searchable fleet table with client-side facets/pagination -->
<div class="asset-register-table card" id="assetRegister" data-view="table">
  <div class="asset-register-toolbar no-print">
    <div class="asset-register-toolbar-main">
      <label class="asset-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <input id="assetSearch" type="search" autocomplete="off" placeholder="Search machine ID, type, serial, location…" aria-label="Search the full asset register">
        <kbd>/</kbd>
      </label>
      <label class="asset-filter-label" for="assetTypeFilter">Type</label>
      <select id="assetTypeFilter" class="form-select asset-type-filter" aria-label="Filter by equipment type">
        <option value="">All types</option>
        <?php foreach ($eq_types as $t): ?>
        <option value="<?= (int)$t->id ?>"><?= $asset_h($t->name) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="asset-view-toggle" role="group" aria-label="Register layout">
        <button type="button" data-asset-view="table" class="is-active" aria-pressed="true">Table</button>
        <button type="button" data-asset-view="cards" aria-pressed="false">Cards</button>
      </div>
    </div>
    <div class="asset-status-tabs" role="group" aria-label="Filter assets by status">
      <?php foreach ($status_counts as $status => $count):
          $status_key = $status === 'All' ? 'all' : $status;
      ?>
      <button type="button" data-asset-status="<?= $asset_h($status_key) ?>" aria-pressed="<?= $status === 'All' ? 'true' : 'false' ?>" class="<?= $status === 'All' ? 'is-active' : '' ?>">
        <span><?= $asset_h($status) ?></span><span class="asset-tab-count"><?= number_format($count) ?></span>
      </button>
      <?php endforeach; ?>
    </div>
    <div class="asset-register-tools-meta">
      <span>Status counts show the full fleet; search and type narrow the current results.</span>
      <label for="assetPageSize">Rows</label>
      <select id="assetPageSize" class="form-select" aria-label="Rows per page">
        <option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option><option value="0">All</option>
      </select>
    </div>
  </div>

  <?php if (!empty($type_summary)): ?>
  <div class="asset-type-summary" aria-label="Average availability by equipment type">
    <?php foreach ($type_summary as $type_name => $summary):
        $type_avg = round($summary['availability'] / max(1, $summary['count']), 1);
        $type_color = $type_avg >= 90 ? 'var(--green)' : ($type_avg >= 70 ? 'var(--amber)' : 'var(--red)');
    ?>
    <div class="asset-type-summary-item">
      <span class="asset-type-summary-label"><?= $asset_h($type_name) ?></span>
      <strong><?= number_format($summary['count']) ?> <small>assets</small></strong>
      <span>Avg availability <b style="color:<?= $type_color ?>;"><?= number_format($type_avg, 1) ?>%</b></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="asset-results-line" aria-live="polite">
    <span id="assetResultSummary">Showing <?= number_format(min(1, count($assets))) ?>–<?= number_format(min(25, count($assets))) ?> of <?= number_format(count($assets)) ?> assets</span>
    <span id="assetSortSummary">Sorted by Machine ID</span>
  </div>

  <div class="asset-table-wrap">
    <table class="tbl asset-table" id="assetDataTable" aria-label="Fleet asset register">
      <thead>
        <tr>
          <?php
          $columns = array(
              array('Machine ID', 'registration_no', 'text', 'primary'),
              array('Type', 'type_name', 'text', 'card'),
              array('Brand / model', 'brand_model', 'text', 'card'),
              array('Year', 'year', 'number', 'card'),
              array('Serial no.', 'serial_number', 'text', 'card'),
              array('Engine', 'engine_model', 'text', 'card'),
              array('Location', 'location', 'text', 'card'),
              array('Status', 'status', 'text', 'card'),
              array('Hours', 'current_hours', 'number', 'card'),
              array('KM', 'current_km', 'number', 'card'),
              array('WOs', 'total_wo', 'number', 'card'),
              array('Breakdowns', 'breakdowns', 'number', 'card'),
              array('Down hrs', 'total_down', 'number', 'card'),
              array('Availability', 'availability', 'number', 'card'),
              array('Warranty', 'warranty_expiry', 'date', 'card'),
              array('Next PM', 'next_pm_date', 'date', 'card'),
              array('Notes', 'notes', 'text', 'card'),
              array('Actions', '', '', '')
          );
          foreach ($columns as $i => $column):
          ?>
          <th scope="col" <?= $column[1] !== '' ? 'data-sort-column="'.$asset_h($column[1]).'"' : '' ?><?= in_array($column[2], array('number', 'date'), true) ? ' class="asset-numeric-head"' : '' ?>>
            <?php if ($column[1] !== ''): ?>
            <button type="button" class="asset-sort-button" data-sort-index="<?= (int)$i ?>" data-sort-key="<?= $asset_h($column[1]) ?>" data-sort-kind="<?= $asset_h($column[2]) ?>" data-label="<?= $asset_h($column[0]) ?>" aria-label="Sort by <?= $asset_h($column[0]) ?>">
              <?= $asset_h($column[0]) ?><span class="asset-sort-indicator" aria-hidden="true"></span>
            </button>
            <?php else: ?><?= $asset_h($column[0]) ?><?php endif; ?>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody id="assetTableBody">
      <?php foreach ($assets as $a):
          $smap = equip_status_map($a['status']);
          $searchable = array($a['registration_no'], $a['type_name'], $a['category'], $a['brand'], $a['model'], $a['year'], $a['serial_number'], $a['engine_model'], $a['location'], $a['status'], $a['current_hours'], $a['current_km'], $a['total_wo'], $a['breakdowns'], $a['total_down'], $a['availability'], $a['warranty_expiry'], $a['next_pm_name'], $a['next_pm_date'], $a['next_pm_hours'], $a['notes']);
          $brand_model = trim((string)$a['brand'].' '.(string)$a['model']);
          $warranty_label = $a['warranty_expiry'] ? date('d/m/Y', strtotime($a['warranty_expiry'])) : '—';
          if ($a['warranty_days'] !== null) {
              if ((int)$a['warranty_days'] < 0) $warranty_label .= ' · Expired';
              elseif ((int)$a['warranty_days'] === 0) $warranty_label .= ' · Expires today';
              elseif ((int)$a['warranty_days'] <= 30) $warranty_label .= ' · '.$a['warranty_days'].'d left';
          }
          $pm_label = trim((string)$a['next_pm_name']);
          if ($a['next_pm_date']) $pm_label .= ($pm_label !== '' ? ' · ' : '').date('d/m/Y', strtotime($a['next_pm_date']));
          elseif ($a['next_pm_hours']) $pm_label .= ($pm_label !== '' ? ' · ' : '').number_format((float)$a['next_pm_hours'], 0).' hrs';
      ?>
        <tr data-asset-id="<?= (int)$a['id'] ?>" data-status="<?= $asset_h($a['status']) ?>" data-type="<?= (int)$a['equipment_type_id'] ?>" data-search="<?= $asset_h(implode(' ', $searchable)) ?>">
          <td data-sort-value="<?= $asset_h($a['registration_no']) ?>" data-card-label="Machine ID">
            <a class="asset-machine-link" href="<?= $asset_h(base_url('equipment/view/'.(int)$a['id'])) ?>"><?= $asset_h($a['registration_no']) ?></a>
          </td>
          <td data-sort-value="<?= $asset_h($a['type_name']) ?>" data-card-label="Type"><?= $asset_h($a['type_name'] ?: 'Other') ?><?php if (!empty($a['category'])): ?><small class="asset-cell-sub"><?= $asset_h($a['category']) ?></small><?php endif; ?></td>
          <td data-sort-value="<?= $asset_h($brand_model) ?>" data-card-label="Brand / model"><?= $asset_h($brand_model ?: '—') ?></td>
          <td data-sort-value="<?= $asset_h($a['year']) ?>" data-card-label="Year" class="asset-numeric"><?= $asset_h($a['year'] ?: '—') ?></td>
          <td data-sort-value="<?= $asset_h($a['serial_number']) ?>" data-card-label="Serial no." class="mono"><?= $asset_h($a['serial_number'] ?: '—') ?></td>
          <td data-sort-value="<?= $asset_h($a['engine_model']) ?>" data-card-label="Engine"><?= $asset_h($a['engine_model'] ?: '—') ?></td>
          <td data-sort-value="<?= $asset_h($a['location']) ?>" data-card-label="Location"><?= $asset_h($a['location'] ?: '—') ?></td>
          <td data-sort-value="<?= $asset_h($a['status']) ?>" data-card-label="Status"><span class="badge <?= $asset_h($smap['badge']) ?>"><span class="led <?= $asset_h($smap['led']) ?>" aria-hidden="true" style="margin-right:4px;"></span><?= $asset_h($a['status']) ?></span></td>
          <td data-sort-value="<?= (float)$a['current_hours'] ?>" data-card-label="Hours" class="asset-numeric"><?= number_format((float)$a['current_hours'], 1) ?></td>
          <td data-sort-value="<?= (float)$a['current_km'] ?>" data-card-label="KM" class="asset-numeric"><?= number_format((float)$a['current_km'], 1) ?></td>
          <td data-sort-value="<?= (int)$a['total_wo'] ?>" data-card-label="Work orders" class="asset-numeric"><?= number_format((int)$a['total_wo']) ?></td>
          <td data-sort-value="<?= (int)$a['breakdowns'] ?>" data-card-label="Breakdowns" class="asset-numeric"><?= number_format((int)$a['breakdowns']) ?></td>
          <td data-sort-value="<?= (float)$a['total_down'] ?>" data-card-label="Downtime hours" class="asset-numeric"><?= number_format((float)$a['total_down'], 1) ?></td>
          <td data-sort-value="<?= (float)$a['availability'] ?>" data-card-label="Availability" class="asset-numeric"><strong><?= number_format((float)$a['availability'], 1) ?>%</strong></td>
          <td data-sort-value="<?= $asset_h($a['warranty_expiry']) ?>" data-card-label="Warranty"><?= $asset_h($warranty_label) ?></td>
          <td data-sort-value="<?= $asset_h($a['next_pm_date']) ?>" data-card-label="Next PM"><?= $asset_h($pm_label ?: '—') ?></td>
          <td data-sort-value="<?= $asset_h($a['notes']) ?>" data-card-label="Notes" class="asset-notes-cell"><?= $asset_h($a['notes'] ?: '—') ?></td>
          <td class="asset-row-actions"><a class="btn btn-ghost btn-sm" href="<?= $asset_h(base_url('equipment/view/'.(int)$a['id'])) ?>" aria-label="View full profile for <?= $asset_h($a['registration_no']) ?>">View profile</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="asset-cards" id="assetCards" aria-label="Asset cards"></div>
  <div class="asset-filter-empty" id="assetFilterEmpty" hidden>
    <strong>No assets match these filters.</strong><span>Try another search, status, or type.</span>
    <button type="button" class="btn btn-ghost btn-sm" id="assetResetFilters">Clear filters</button>
  </div>
  <?php if (empty($assets)): ?>
  <div class="asset-filter-empty asset-no-data"><strong>No assets are registered yet.</strong><span>Add an asset or import the register workbook to get started.</span></div>
  <?php endif; ?>
  <div class="asset-pagination no-print" aria-label="Asset register pagination">
    <span id="assetPageSummary" aria-live="polite"></span>
    <div><button type="button" id="assetPrevPage" class="btn btn-ghost btn-sm" disabled>Previous</button>
      <span id="assetPageIndicator"></span>
      <button type="button" id="assetNextPage" class="btn btn-ghost btn-sm" disabled>Next</button></div>
  </div>
  <noscript><p class="asset-noscript">JavaScript is needed for search, filters, sorting, pagination, and card view. The complete register remains available in the table and Excel export.</p></noscript>
</div>

<style>
.asset-register-table{padding:0;overflow:hidden;--asset-border:var(--g2);}
.asset-register-table [hidden]{display:none!important;}
.asset-register-toolbar{padding:14px 16px 10px;border-bottom:1px solid var(--asset-border);display:grid;gap:12px;}
.asset-register-toolbar-main{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.asset-search{position:relative;display:flex;align-items:center;min-width:240px;flex:1 1 300px;}
.asset-search svg{position:absolute;left:11px;width:16px;height:16px;color:var(--t3);pointer-events:none;}
.asset-search input{height:38px;padding:0 34px 0 36px;}
.asset-search kbd{position:absolute;right:10px;font-size:10px;color:var(--t3);background:var(--g1);border:1px solid var(--asset-border);border-radius:4px;padding:1px 5px;}
.asset-filter-label{font-size:12px;color:var(--t2);font-weight:600;}
.asset-type-filter{width:auto;min-width:145px;max-width:220px;height:38px;}
.asset-view-toggle{display:flex;border:1px solid var(--asset-border);border-radius:8px;padding:2px;margin-left:auto;background:var(--g1);}
.asset-view-toggle button{border:0;background:transparent;color:var(--t2);font-family:inherit;font-size:12px;font-weight:600;border-radius:6px;padding:7px 12px;cursor:pointer;}
.asset-view-toggle button.is-active{background:var(--surface);color:var(--t1);box-shadow:0 1px 3px rgba(0,0,0,.12);}
.asset-view-toggle button:focus-visible,.asset-status-tabs button:focus-visible,.asset-sort-button:focus-visible,.asset-pagination button:focus-visible{outline:2px solid var(--blue);outline-offset:2px;}
.asset-status-tabs{display:flex;gap:4px;overflow-x:auto;padding:0 0 2px;scrollbar-width:thin;}
.asset-status-tabs button{display:flex;align-items:center;gap:7px;white-space:nowrap;border:0;border-bottom:2px solid transparent;background:transparent;color:var(--t2);font-family:inherit;font-size:12px;font-weight:500;padding:8px 10px;cursor:pointer;}
.asset-status-tabs button.is-active{border-bottom-color:var(--blue);color:var(--t1);font-weight:700;}
.asset-tab-count{font:600 10px var(--mono);color:var(--t3);background:var(--g1);border-radius:10px;padding:2px 6px;}
.asset-status-tabs button.is-active .asset-tab-count{color:var(--blue);background:rgba(10,132,255,.10);}
.asset-register-tools-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:var(--t3);font-size:11px;}
.asset-register-tools-meta span{margin-right:auto;}
.asset-register-tools-meta label{font-weight:600;color:var(--t2);}
.asset-register-tools-meta select{height:30px;width:auto;padding:3px 24px 3px 8px;font-size:11.5px;}
.asset-type-summary{display:flex;gap:8px;padding:12px 16px;overflow-x:auto;border-bottom:1px solid var(--asset-border);}
.asset-type-summary-item{min-width:155px;padding:9px 11px;border:1px solid var(--asset-border);border-radius:9px;display:grid;gap:4px;background:var(--surface);font-size:10.5px;color:var(--t3);}
.asset-type-summary-item strong{font-size:15px;color:var(--t1);}
.asset-type-summary-item small{font-size:10px;font-weight:500;color:var(--t3);}
.asset-type-summary-item b{font-weight:700;}
.asset-type-summary-label{font-size:11px;color:var(--t2);font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.asset-results-line{display:flex;justify-content:space-between;gap:10px;padding:9px 16px;font-size:11px;color:var(--t3);}
.asset-table-wrap{width:100%;overflow:auto;}
.asset-table{min-width:1520px;}
.asset-table th{position:sticky;top:0;z-index:1;background:var(--g1);white-space:nowrap;}
.asset-sort-button{border:0;background:transparent;color:inherit;font:inherit;font-weight:700;text-align:inherit;padding:0;cursor:pointer;white-space:nowrap;}
.asset-sort-indicator{display:inline-block;margin-left:5px;color:var(--blue);font-size:10px;}
.asset-numeric-head{text-align:right;}
.asset-numeric{text-align:right!important;font-variant-numeric:tabular-nums;white-space:nowrap;}
.asset-machine-link{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--blue);text-decoration:none;white-space:nowrap;}
.asset-machine-link:hover{text-decoration:underline;}
.asset-cell-sub{display:block;color:var(--t3);font-size:10px;margin-top:3px;}
.asset-notes-cell{max-width:240px;min-width:150px;white-space:normal;line-height:1.4;}
.asset-row-actions{white-space:nowrap;}
.asset-cards{display:none;padding:12px;grid-template-columns:repeat(auto-fill,minmax(min(100%,320px),1fr));gap:10px;}
.asset-card{border:1px solid var(--asset-border);border-radius:10px;background:var(--surface);padding:13px;min-width:0;}
.asset-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding-bottom:10px;border-bottom:1px solid var(--asset-border);}
.asset-card-title{min-width:0;}
.asset-card-title a{font:700 14px var(--mono);color:var(--blue);text-decoration:none;}
.asset-card-title div{font-size:12px;font-weight:600;color:var(--t2);margin-top:4px;overflow-wrap:anywhere;}
.asset-card-type{font-size:10.5px;color:var(--t3);margin-top:3px;}
.asset-card-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 12px;padding:11px 0;}
.asset-card-field{min-width:0;}
.asset-card-field-label{display:block;color:var(--t3);font-size:9.5px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;}
.asset-card-field-value{display:block;color:var(--t1);font-size:11.5px;line-height:1.45;overflow-wrap:anywhere;}
.asset-card-field-value .badge{white-space:normal;}
.asset-card-actions{display:flex;justify-content:flex-end;border-top:1px solid var(--asset-border);padding-top:9px;}
.asset-filter-empty{display:flex;flex-direction:column;align-items:center;gap:6px;padding:36px 16px;text-align:center;color:var(--t3);font-size:12px;}
.asset-filter-empty strong{font-size:13px;color:var(--t1);}
.asset-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:11px 16px;border-top:1px solid var(--asset-border);font-size:11px;color:var(--t3);}
.asset-pagination>div{display:flex;align-items:center;gap:8px;}
.asset-pagination button:disabled{opacity:.45;cursor:not-allowed;}
.asset-page-current{font-variant-numeric:tabular-nums;color:var(--t2);}
.asset-noscript{padding:10px 16px;font-size:11px;color:var(--t3);}
.asset-register-table[data-view="cards"] .asset-table-wrap{display:none;}
.asset-register-table[data-view="cards"] .asset-cards{display:grid;}
.asset-register-table[data-view="cards"] .asset-results-line{display:none;}
@media(max-width:760px){
  .asset-register-toolbar{padding:12px;gap:9px;}
  .asset-search{flex-basis:100%;}
  .asset-type-filter{flex:1;min-width:120px;max-width:none;}
  .asset-view-toggle{margin-left:0;}
  .asset-register-tools-meta span{flex-basis:100%;}
  .asset-results-line{padding:8px 12px;}
  .asset-pagination{padding:10px 12px;}
}
@media(max-width:420px){.asset-card-grid{grid-template-columns:1fr 1fr;gap:9px;}.asset-card{padding:11px;}}
@media(prefers-reduced-motion:reduce){.asset-register-table *{scroll-behavior:auto!important;transition:none!important;}}
@media print {
  #sidebar,#canvas .topbar,.btn,form,.no-print,.asset-register-toolbar,.asset-type-summary,.asset-pagination,.asset-filter-empty,.asset-cards{display:none!important;}
  #canvas{margin-left:0!important;}
  .asset-register-table{box-shadow:none!important;border:1px solid #cbd5e1!important;overflow:visible!important;}
  .asset-register-table[data-view="cards"] .asset-table-wrap{display:block!important;}
  .asset-results-line{display:flex!important;}
  .asset-table-wrap{overflow:visible!important;}
  .asset-table{min-width:0!important;width:100%!important;}
  .tbl td,.tbl th{font-size:7.5pt!important;padding:2px 4px!important;}
  .asset-table th{position:static;}
}
</style>
<script>
(function () {
  var shell = document.getElementById('assetRegister');
  var body = document.getElementById('assetTableBody');
  if (!shell || !body) return;

  var rows = Array.prototype.slice.call(body.querySelectorAll('tr[data-asset-id]'));
  var search = document.getElementById('assetSearch');
  var typeFilter = document.getElementById('assetTypeFilter');
  var pageSize = document.getElementById('assetPageSize');
  var summary = document.getElementById('assetResultSummary');
  var pageSummary = document.getElementById('assetPageSummary');
  var sortSummary = document.getElementById('assetSortSummary');
  var pageIndicator = document.getElementById('assetPageIndicator');
  var previous = document.getElementById('assetPrevPage');
  var next = document.getElementById('assetNextPage');
  var empty = document.getElementById('assetFilterEmpty');
  var cards = document.getElementById('assetCards');
  var page = 1;
  var activeStatus = 'all';
  var sortKey = 'registration_no';
  var sortDirection = 'asc';
  var sortKind = 'text';
  var timer = null;
  var cardById = Object.create(null);
  var cardsReady = false;

  function normalize(text) { return String(text || '').toLocaleLowerCase().trim(); }

  function makeCard(row) {
    var id = row.getAttribute('data-asset-id');
    var card = document.createElement('article');
    card.className = 'asset-card';
    card.setAttribute('data-asset-id', id);
    card.setAttribute('data-status', row.getAttribute('data-status'));
    card.setAttribute('data-type', row.getAttribute('data-type'));
    card.setAttribute('data-search', row.getAttribute('data-search'));

    var head = document.createElement('div');
    head.className = 'asset-card-head';
    var titleWrap = document.createElement('div');
    titleWrap.className = 'asset-card-title';
    var idCell = row.cells[0];
    var link = document.createElement('a');
    link.href = idCell.querySelector('a').href;
    link.textContent = idCell.textContent.trim();
    titleWrap.appendChild(link);
    var brand = document.createElement('div');
    brand.textContent = row.cells[2].textContent.trim();
    titleWrap.appendChild(brand);
    var type = document.createElement('div');
    type.className = 'asset-card-type';
    type.textContent = row.cells[1].textContent.trim().replace(/\s+/g, ' ');
    titleWrap.appendChild(type);
    head.appendChild(titleWrap);
    var badge = row.cells[7].querySelector('.badge');
    if (badge) head.appendChild(badge.cloneNode(true));
    card.appendChild(head);

    var grid = document.createElement('div');
    grid.className = 'asset-card-grid';
    Array.prototype.slice.call(row.querySelectorAll('td[data-card-label]')).forEach(function (cell) {
      if (cell.cellIndex === 0 || cell.cellIndex === 1 || cell.cellIndex === 2 || cell.cellIndex === 7) return;
      var field = document.createElement('div');
      field.className = 'asset-card-field';
      var label = document.createElement('span');
      label.className = 'asset-card-field-label';
      label.textContent = cell.getAttribute('data-card-label');
      var value = document.createElement('span');
      value.className = 'asset-card-field-value';
      var statusBadge = cell.querySelector('.badge');
      if (statusBadge) value.appendChild(statusBadge.cloneNode(true));
      else value.textContent = cell.textContent.trim() || '—';
      field.appendChild(label);
      field.appendChild(value);
      grid.appendChild(field);
    });
    card.appendChild(grid);
    var actions = document.createElement('div');
    actions.className = 'asset-card-actions';
    var profile = document.createElement('a');
    profile.className = 'btn btn-ghost btn-sm';
    profile.href = row.cells[17].querySelector('a').href;
    profile.textContent = 'View full profile';
    actions.appendChild(profile);
    card.appendChild(actions);
    return card;
  }

  function ensureCards() {
    cardsReady = true;
  }

  function sortRows() {
    var header = document.querySelector('.asset-sort-button[data-sort-key="' + sortKey + '"]');
    if (!header) return;
    var index = parseInt(header.getAttribute('data-sort-index'), 10);
    sortKind = header.getAttribute('data-sort-kind') || 'text';
    rows.sort(function (a, b) {
      var av = a.cells[index].getAttribute('data-sort-value') || '';
      var bv = b.cells[index].getAttribute('data-sort-value') || '';
      var result;
      if (sortKind === 'number') result = (parseFloat(av) || 0) - (parseFloat(bv) || 0);
      else result = av.localeCompare(bv, undefined, {numeric:true, sensitivity:'base'});
      return result * (sortDirection === 'asc' ? 1 : -1);
    });
    rows.forEach(function (row) { body.appendChild(row); });
    if (cardsReady) rows.forEach(function (row) { var card = cardById[row.getAttribute('data-asset-id')]; if (card) cards.appendChild(card); });
    Array.prototype.slice.call(document.querySelectorAll('.asset-sort-button')).forEach(function (button) {
      var selected = button === header;
      button.setAttribute('aria-label', (selected ? 'Sorted ' + (sortDirection === 'asc' ? 'ascending' : 'descending') + ' by ' : 'Sort by ') + button.getAttribute('data-label'));
      button.querySelector('.asset-sort-indicator').textContent = selected ? (sortDirection === 'asc' ? '▲' : '▼') : '';
      button.closest('th').setAttribute('aria-sort', selected ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none');
    });
    var directionText = sortKind === 'number'
      ? (sortDirection === 'asc' ? 'low to high' : 'high to low')
      : (sortKind === 'date' ? (sortDirection === 'asc' ? 'oldest first' : 'newest first') : (sortDirection === 'asc' ? 'A–Z' : 'Z–A'));
    if (sortSummary) sortSummary.textContent = 'Sorted by ' + header.getAttribute('data-label') + ' (' + directionText + ')';
  }

  function update() {
    var term = normalize(search.value);
    var selectedType = typeFilter.value;
    var matches = rows.filter(function (row) {
      return (activeStatus === 'all' || row.getAttribute('data-status') === activeStatus)
        && (!selectedType || row.getAttribute('data-type') === selectedType)
        && (!term || normalize(row.getAttribute('data-search')).indexOf(term) !== -1);
    });
    var total = matches.length;
    var perPage = parseInt(pageSize.value, 10) || total || 1;
    var pages = Math.max(1, Math.ceil(total / perPage));
    if (page > pages) page = pages;
    if (page < 1) page = 1;
    var first = total ? ((page - 1) * perPage) : 0;
    var last = perPage === total || perPage === 0 ? total : Math.min(first + perPage, total);
    var visible = Object.create(null);
    matches.slice(first, last).forEach(function (row) { visible[row.getAttribute('data-asset-id')] = true; });
    rows.forEach(function (row) {
      var id = row.getAttribute('data-asset-id');
      var show = !!visible[id];
      row.hidden = !show;
      if (cardsReady && shell.getAttribute('data-view') === 'cards' && show && !cardById[id]) {
        cardById[id] = makeCard(row);
        cards.appendChild(cardById[id]);
      }
      var card = cardById[id];
      if (card) card.hidden = !show;
    });
    empty.hidden = total !== 0 || rows.length === 0;
    var firstShown = total ? first + 1 : 0;
    var lastShown = total ? last : 0;
    summary.textContent = 'Showing ' + firstShown.toLocaleString() + '–' + lastShown.toLocaleString() + ' of ' + total.toLocaleString() + ' matching assets';
    pageSummary.textContent = total ? 'Page ' + page.toLocaleString() + ' of ' + pages.toLocaleString() : 'No matching assets';
    pageIndicator.textContent = total ? page.toLocaleString() + ' / ' + pages.toLocaleString() : '';
    previous.disabled = page <= 1 || total === 0;
    next.disabled = page >= pages || total === 0;
  }

  function setView(view) {
    if (view === 'cards') ensureCards();
    shell.setAttribute('data-view', view);
    Array.prototype.slice.call(document.querySelectorAll('[data-asset-view]')).forEach(function (button) {
      var active = button.getAttribute('data-asset-view') === view;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    update();
  }

  Array.prototype.slice.call(document.querySelectorAll('[data-asset-status]')).forEach(function (tab) {
    tab.addEventListener('click', function () {
      activeStatus = tab.getAttribute('data-asset-status');
      Array.prototype.slice.call(document.querySelectorAll('[data-asset-status]')).forEach(function (other) {
        var active = other === tab;
        other.classList.toggle('is-active', active);
        other.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      page = 1;
      update();
    });
  });
  Array.prototype.slice.call(document.querySelectorAll('[data-asset-view]')).forEach(function (button) {
    button.addEventListener('click', function () { setView(button.getAttribute('data-asset-view')); });
  });
  Array.prototype.slice.call(document.querySelectorAll('.asset-sort-button')).forEach(function (button) {
    button.addEventListener('click', function () {
      var key = button.getAttribute('data-sort-key');
      if (sortKey === key) sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
      else { sortKey = key; sortDirection = button.getAttribute('data-sort-kind') === 'number' ? 'desc' : 'asc'; }
      sortRows();
      page = 1;
      update();
    });
  });
  search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { page = 1; update(); }, 100); });
  typeFilter.addEventListener('change', function () { page = 1; update(); });
  pageSize.addEventListener('change', function () { page = 1; update(); });
  previous.addEventListener('click', function () { page--; update(); });
  next.addEventListener('click', function () { page++; update(); });
  document.getElementById('assetResetFilters').addEventListener('click', function () {
    search.value = '';
    typeFilter.value = '';
    activeStatus = 'all';
    Array.prototype.slice.call(document.querySelectorAll('[data-asset-status]')).forEach(function (tab) {
      var active = tab.getAttribute('data-asset-status') === 'all';
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    page = 1;
    update();
    search.focus();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test((event.target && event.target.tagName) || '')) {
      event.preventDefault();
      search.focus();
    }
  });
  var narrow = window.matchMedia && window.matchMedia('(max-width: 700px)');
  if (narrow && narrow.matches) { pageSize.value = '10'; setView('cards'); }
  var printView = null;
  window.addEventListener('beforeprint', function () {
    printView = shell.getAttribute('data-view');
    rows.forEach(function (row) { row.hidden = false; });
    shell.setAttribute('data-view', 'table');
    if (summary) summary.textContent = 'Showing all ' + rows.length.toLocaleString() + ' assets';
    empty.hidden = true;
  });
  window.addEventListener('afterprint', function () {
    if (printView) setView(printView);
    printView = null;
    update();
  });
  sortRows();
  update();
})();
</script>
