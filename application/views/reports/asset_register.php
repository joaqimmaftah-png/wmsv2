<?php
// W0.2: colours come from the shared status map — the old keys spelled without
// the "In " prefix matched nothing, because the enum value is 'In Maintenance'.
$statusColor = array(); $statusBg = array();
foreach (equip_statuses() as $s) {
    $m = equip_status_map($s);
    $statusColor[$s] = $m['color'];
    $statusBg[$s]    = $m['bg'];
}
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Asset Register</div>
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
      <div class="ct" style="font-size:13px;">Import summary<?= $import_result['file_name'] ? ' — '.htmlspecialchars($import_result['file_name']) : '' ?></div>
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
    <?php foreach ($import_result['errors'] as $e): ?><div>• <?=htmlspecialchars($e)?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if (!empty($import_result['warnings'])): ?>
  <div style="padding:0 18px 14px;color:var(--amber);font-size:12.5px;">
    <?php foreach ($import_result['warnings'] as $w): ?><div>• <?=htmlspecialchars($w)?></div><?php endforeach; ?>
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
        <td class="mono fw-600"><?=htmlspecialchars($r['machine_id'])?></td>
        <td><span class="badge <?=$cls?>"><?=strtoupper($act)?></span></td>
        <td style="font-size:11.5px;color:var(--t2);">
          <?=htmlspecialchars(implode(' | ', isset($r['errors']) && $r['errors'] ? $r['errors'] : array()))?>
          <?php if (empty($r['errors']) && !empty($r['values']['type_name'])): ?>
            <span style="color:var(--t3);"><?=htmlspecialchars(trim($r['values']['type_name'].' '.($r['values']['brand'] ?? '').' '.($r['values']['model'] ?? '')))?></span>
          <?php endif; ?>
        </td>
        <td style="font-size:11.5px;color:var(--amber);"><?=htmlspecialchars(implode(' | ', isset($r['warnings']) ? $r['warnings'] : array()))?></td>
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
      $h[] = date('d/m/Y H:i', strtotime($row->created_at)).' — '.htmlspecialchars($row->file_name)
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
    <span class="kpi-tag">Active Fleet</span>
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

<!-- Per-type asset tables -->
<?php foreach($grouped as $type => $assets): ?>
<div class="card mb-4" style="padding:0;overflow:hidden;">
  <div class="ch" style="padding:12px 18px 10px;border-bottom:1px solid var(--g2);">
    <div>
      <div class="ct" style="font-size:13px;"><?=htmlspecialchars($type)?>s</div>
      <div class="cs"><?=count($assets)?> unit<?=count($assets)>1?'s':''?></div>
    </div>
    <?php
    $typeAvail = round(array_sum(array_column($assets,'availability'))/count($assets),1);
    $col = $typeAvail>=90?'var(--green)':($typeAvail>=70?'var(--amber)':'var(--red)');
    ?>
    <div style="font-size:22px;font-weight:800;color:<?=$col?>;"><?=$typeAvail?>%<span style="font-size:11px;color:var(--t3);font-weight:400;margin-left:4px;">avg avail</span></div>
  </div>
  <div style="overflow-x:auto;">
  <table class="tbl" style="min-width:1100px;">
    <thead>
      <tr>
        <th>MACHINE ID</th><th>BRAND</th><th>MODEL</th><th>YEAR</th>
        <th>SERIAL No.</th><th>ENGINE</th><th>LOCATION</th><th style="text-align:center;">STATUS</th>
        <th style="text-align:center;">HOURS</th><th style="text-align:center;">WOs</th>
        <th style="text-align:center;">BREAKDOWNS</th><th style="text-align:center;">DOWN HRS</th>
        <th style="text-align:center;">AVAIL%</th><th style="text-align:center;">WARRANTY</th>
        <th>NEXT PM</th><th style="text-align:center;">PM DATE</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($assets as $a):
      $av  = $a['availability'];
      $avC = $av>=90?'var(--green)':($av>=70?'var(--amber)':'var(--red)');
      $sc  = $statusColor[$a['status']] ?? 'var(--t2)';
      $sb  = $statusBg[$a['status']]   ?? 'var(--g2)';
      $wd  = $a['warranty_days'];
      $wc  = $wd===null?'var(--t3)':($wd>0?'var(--green)':'var(--red)');
    ?>
    <tr>
      <td class="mono" style="font-weight:700;color:var(--blue);"><?=htmlspecialchars($a['registration_no'])?></td>
      <td style="color:var(--t2);"><?=htmlspecialchars($a['brand']??'')?></td>
      <td style="color:var(--t2);"><?=htmlspecialchars($a['model']??'')?></td>
      <td style="text-align:center;color:var(--t3);"><?=$a['year']?></td>
      <td class="mono" style="font-size:10.5px;color:var(--t3);"><?=htmlspecialchars($a['serial_number']??'')?></td>
      <td style="font-size:10.5px;color:var(--t3);"><?=htmlspecialchars($a['engine_model']??'—')?></td>
      <td style="text-align:center;font-size:10.5px;color:var(--t3);"><?=htmlspecialchars($a['location']??'')?></td>
      <td style="text-align:center;">
        <span style="padding:2px 8px;border-radius:5px;font-size:10.5px;font-weight:600;color:<?=$sc?>;background:<?=$sb?>;"><?=$a['status']?></span>
      </td>
      <td class="mono" style="text-align:center;font-weight:600;"><?=number_format($a['current_hours'],0)?></td>
      <td style="text-align:center;color:var(--t2);"><?=$a['total_wo']?></td>
      <td style="text-align:center;font-weight:600;color:<?=$a['breakdowns']>5?'var(--red)':($a['breakdowns']>2?'var(--amber)':'var(--t2)')?>;"><?=$a['breakdowns']?></td>
      <td class="mono" style="text-align:center;color:var(--t3);"><?=number_format($a['total_down'],1)?></td>
      <td style="text-align:center;font-weight:700;color:<?=$avC?>;"><?=$av?>%</td>
      <td style="text-align:center;font-size:10.5px;color:<?=$wc?>;">
        <?=$a['warranty_expiry'] ? date('d/m/Y',strtotime($a['warranty_expiry'])) : '—'?>
        <?php if($wd!==null && $wd<=30 && $wd>0):?>
        <div style="font-size:9px;color:var(--amber);">⚠ <?=$wd?>d left</div>
        <?php elseif($wd!==null && $wd<=0):?>
        <div style="font-size:9px;color:var(--red);">Expired</div>
        <?php endif;?>
      </td>
      <td style="font-size:10.5px;color:var(--t2);"><?=htmlspecialchars($a['next_pm_name']??'—')?></td>
      <td class="mono" style="text-align:center;font-size:10.5px;color:<?=$a['next_pm_date']&&$a['next_pm_date']<date('Y-m-d')?'var(--red)':'var(--t3)'?>;">
        <?=$a['next_pm_date'] ? date('d/m/Y',strtotime($a['next_pm_date'])) : '—'?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>

<!-- Notes column if any have notes -->
<?php $withNotes = array_filter($assets, fn($a)=>!empty($a['notes'])); ?>
<?php if(!empty($withNotes)): ?>
<div class="card" style="padding:0;overflow:hidden;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Equipment Notes</div>
  </div>
  <table class="tbl">
    <thead><tr><th>MACHINE ID</th><th>TYPE</th><th>NOTES</th></tr></thead>
    <tbody>
    <?php foreach($withNotes as $a):?>
    <tr>
      <td class="mono" style="font-weight:600;color:var(--blue);width:100px;"><?=htmlspecialchars($a['registration_no'])?></td>
      <td style="color:var(--t3);font-size:10.5px;width:100px;"><?=htmlspecialchars($a['type'])?></td>
      <td style="color:var(--t2);font-size:10.5px;"><?=htmlspecialchars($a['notes'])?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
</div>
<?php endif;?>

<style>
@media print {
  #sidebar,#canvas .topbar,.btn,form{display:none!important;}
  #canvas{margin-left:0!important;}
  .card{box-shadow:none!important;page-break-inside:avoid;}
  .tbl td,.tbl th{font-size:7.5pt!important;padding:2px 4px!important;}
}
</style>
