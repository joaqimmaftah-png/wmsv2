<?php
$sk_h = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$types = array_keys($grouped);
$activeType = isset($_GET['type']) && is_scalar($_GET['type']) ? strtoupper(trim((string)$_GET['type'])) : ($types[0] ?? '');
if (!in_array($activeType, $types, true) && !empty($types)) $activeType = $types[0];
$activeKits = isset($grouped[$activeType]) ? $grouped[$activeType] : array();
$initialSearch = isset($_GET['q']) && is_scalar($_GET['q']) ? substr(trim((string)$_GET['q']), 0, 200) : '';
$initialStatus = isset($_GET['status']) && is_scalar($_GET['status']) ? trim((string)$_GET['status']) : 'all';
$initialPageSize = isset($_GET['per_page']) && is_scalar($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($initialPageSize, array(0, 10, 25, 50, 100), true)) $initialPageSize = 25;
$initialPage = isset($_GET['page']) && is_scalar($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Keep the report's existing unique-by-name KPI definitions while making the
// zero-kit state safe (array_merge() without arguments throws on PHP 8+).
$allPartRows = array();
$allLubeRows = array();
foreach ($kits as $kitForKpi) {
    foreach ($kitForKpi['parts'] as $partForKpi) $allPartRows[] = $partForKpi;
    foreach ($kitForKpi['lubes'] as $lubeForKpi) $allLubeRows[] = $lubeForKpi;
}
$uniquePartCount = count(array_unique(array_map(function ($part) { return $part->part_name ?? ''; }, $allPartRows)));
$uniqueLubeCount = count(array_unique(array_map(function ($lube) { return $lube->name ?? ''; }, $allLubeRows)));
$siteCurrency = wms_site_currency();

$statusOrder = array('Breakdown', 'In Maintenance', 'Available', 'Standby');
foreach ($activeKits as $statusKit) {
    $observedStatus = (string)$statusKit['status'];
    if ($observedStatus !== '' && !in_array($observedStatus, $statusOrder, true)) $statusOrder[] = $observedStatus;
}
$statusCounts = array('all' => count($activeKits));
foreach ($statusOrder as $statusName) {
    $statusCounts[$statusName] = count(array_filter($activeKits, function ($kit) use ($statusName) {
        return (string)$kit['status'] === $statusName;
    }));
}
if (!isset($statusCounts[$initialStatus])) $initialStatus = 'all';
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">Service Kit Recommendations</h1>
    <div class="cs">Parts, lubricants and oils from all recorded work-order history · Generated <?= $sk_h($generated_at) ?></div>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('reports/export_service_kits_xlsx') ?>"
       class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export Excel (all types)
    </a>
    <button onclick="window.print()" class="btn btn-ghost btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print visible page
    </button>
  </div>
</div>

<!-- KPI strip -->
<div class="grid g-4 mb-4">
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Machines with Report Data</span>
    <div class="kpi-num"><?= $total_eq ?><sup>units</sup></div>
  </div>
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Types Represented</span>
    <div class="kpi-num"><?= count($grouped) ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Unique Part Names Shown</span>
    <div class="kpi-num"><?= number_format($uniquePartCount) ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--purple)"></div>
    <span class="kpi-tag">Unique Lubricant Names Shown</span>
    <div class="kpi-num"><?= number_format($uniqueLubeCount) ?></div>
  </div>
</div>

<?php if(empty($kits)): ?>
<div class="card" style="padding:52px;text-align:center;color:var(--t3);">
  No recommendation history or active PM schedule data found. This report includes recorded spare-part, lubricant and oil rows, plus active PM schedules.
</div>
<?php else: ?>

<!-- Equipment type tabs retain the live search/status/page state when switching types. -->
<nav class="sk-type-tabs no-print" aria-label="Filter service kits by equipment type">
  <?php foreach($grouped as $type => $typeKits):
      $typeParams = array('type' => $type);
      if ($initialSearch !== '') $typeParams['q'] = $initialSearch;
      if ($initialStatus !== 'all') $typeParams['status'] = $initialStatus;
      if ($initialPageSize !== 25) $typeParams['per_page'] = $initialPageSize;
      if ($initialPage > 1) $typeParams['page'] = $initialPage;
  ?>
  <a data-sk-type-link="<?= $sk_h($type) ?>" href="?<?= $sk_h(http_build_query($typeParams)) ?>"
     class="sk-type-tab <?= $type === $activeType ? 'is-active' : '' ?>" <?= $type === $activeType ? 'aria-current="page"' : '' ?>>
    <?= $sk_h($type) ?> <span><?= number_format(count($typeKits)) ?></span>
  </a>
  <?php endforeach; ?>
</nav>

<?php if (!empty($activeKits)): ?>
<div class="sk-toolbar card no-print" id="skToolbar" data-active-type="<?= $sk_h($activeType) ?>" hidden>
  <label class="sk-search">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
    <input type="search" id="skSearch" value="<?= $sk_h($initialSearch) ?>" placeholder="Search machine, part, lubricant, oil, PM…" aria-label="Search recommendations in this type">
    <kbd>/</kbd>
  </label>
  <div class="sk-status-tabs" role="group" aria-label="Filter by equipment status">
    <button type="button" data-sk-status="all" aria-pressed="<?= $initialStatus === 'all' ? 'true' : 'false' ?>" class="<?= $initialStatus === 'all' ? 'is-active' : '' ?>">All <span><?= number_format($statusCounts['all']) ?></span></button>
    <?php foreach ($statusOrder as $statusName): ?>
    <button type="button" data-sk-status="<?= $sk_h($statusName) ?>" aria-pressed="<?= $initialStatus === $statusName ? 'true' : 'false' ?>" class="<?= $initialStatus === $statusName ? 'is-active' : '' ?>">
      <?= $sk_h($statusName) ?> <span><?= number_format($statusCounts[$statusName]) ?></span>
    </button>
    <?php endforeach; ?>
  </div>
  <div class="sk-toolbar-meta">
    <span>Status counts include all kits in the selected type; search does not change them. Entry counts below are historical rows, not unique work orders.</span>
    <label for="skPageSize">Machines</label>
    <select id="skPageSize" class="form-select" aria-label="Recommendations per page">
      <?php foreach (array(10,25,50,100,0) as $pageOption): ?>
      <option value="<?= (int)$pageOption ?>" <?= $initialPageSize === $pageOption ? 'selected' : '' ?>><?= $pageOption === 0 ? 'All' : (int)$pageOption ?></option>
      <?php endforeach; ?>
    </select>
    <button type="button" id="skResetFilters" class="btn btn-ghost btn-sm">Clear search/status</button>
  </div>
</div>
<div class="sk-results-line" aria-live="polite" hidden>
  <span id="skResultSummary"></span><span id="skPageSummary"></span>
</div>
<div id="skNoMatches" class="card sk-empty" hidden>
  <strong>No service kits match this search/status.</strong>
  <span>Try another search term or select All statuses.</span>
  <button type="button" class="btn btn-ghost btn-sm" id="skEmptyReset">Clear filters</button>
</div>
<?php endif; ?>

<!-- One expandable recommendation card per machine; the card pattern fits the multi-section kit content. -->
<?php if(isset($grouped[$activeType])): ?>
<?php foreach($activeKits as $kit):
    $skStatus = (string)$kit['status'];
    $skStatusMap = equip_status_map($skStatus);
    $skSearchValues = array($kit['machine_id'], $kit['model'], $kit['location'], $skStatus, $kit['next_pm']);
    foreach ($kit['schedules'] as $scheduleForSearch) $skSearchValues[] = $scheduleForSearch->schedule_name.' '.$scheduleForSearch->interval_value.' '.$scheduleForSearch->interval_type;
    foreach ($kit['parts'] as $partForSearch) $skSearchValues[] = $partForSearch->part_name.' '.$partForSearch->part_number;
    foreach ($kit['lubes'] as $lubeForSearch) $skSearchValues[] = $lubeForSearch->name.' '.$lubeForSearch->category.' '.$lubeForSearch->grade;
    foreach ($kit['oils'] as $oilForSearch) $skSearchValues[] = $oilForSearch->system_location.' '.$oilForSearch->oil_type.' '.$oilForSearch->grade;
?>
<div class="card mb-4 sk-kit" data-sk-status="<?= $sk_h($skStatus) ?>" data-sk-search="<?= $sk_h(implode(' ', $skSearchValues)) ?>">

  <!-- Machine header -->
  <div style="padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border-bottom:2px solid var(--blue);">
    <div style="display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:10px;background:var(--blue-d);display:flex;align-items:center;justify-content:center;">
        <svg viewBox="0 0 24 24" style="width:22px;height:22px;stroke:var(--blue);fill:none;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round;"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8l4 1 3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
      </div>
      <div>
        <a class="sk-machine-link" href="<?= $sk_h(base_url('equipment/view/'.(int)$kit['equipment_id'])) ?>"><?= $sk_h($kit['machine_id']) ?></a>
        <div style="font-size:11.5px;color:var(--t2);"><?= $sk_h($kit['model'] ?: '—') ?> · <?= $sk_h($kit['location'] ?: '—') ?></div>
        <div class="sk-machine-meta"><span><?= $sk_h($activeType) ?></span><span class="badge <?= $sk_h($skStatusMap['badge']) ?>"><span class="led <?= $sk_h($skStatusMap['led']) ?>" aria-hidden="true" style="margin-right:4px;"></span><?= $sk_h($skStatusMap['label']) ?></span><a href="<?= $sk_h(base_url('equipment/view/'.(int)$kit['equipment_id'])) ?>">Full profile</a></div>
      </div>
    </div>
    <div class="flex ai-c gap-16" style="flex-wrap:wrap;">
      <div style="text-align:center;">
        <div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Hours</div>
        <div class="mono" style="font-size:16px;font-weight:700;"><?= number_format((float)($kit['hours']),0) ?></div>
      </div>
      <?php if($kit['next_pm']): ?>
      <div style="text-align:center;">
        <div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Next PM</div>
        <div style="font-size:12px;font-weight:600;color:var(--blue);"><?= htmlspecialchars((string)($kit['next_pm'])) ?></div>
        <?php if($kit['next_pm_date']): ?>
        <?php
          $du = $kit['days_until'];
          $dcol = $du===null?'var(--t3)':($du<0?'var(--red)':($du<=14?'var(--amber)':'var(--green)'));
        ?>
        <div style="font-size:10.5px;color:<?= $dcol ?>;font-weight:600;">
          <?= $kit['next_pm_date'] ? date('d/m/Y',strtotime($kit['next_pm_date'])) : '' ?>
          <?php if($du!==null): ?>
          (<?= $du<0?abs($du).'d overdue':$du.'d' ?>)
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if(!empty($kit['schedules'])): ?>
      <div>
        <div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">PM Schedules</div>
        <?php foreach($kit['schedules'] as $s): ?>
        <span style="display:inline-block;margin:1px 2px;padding:2px 7px;border-radius:4px;font-size:9.5px;background:var(--blue-d);color:var(--blue);font-weight:600;">
          <?= $sk_h($s->schedule_name) ?> (<?= (int)$s->interval_value ?><?= $sk_h(substr((string)$s->interval_type, 0, 1)) ?>)
        </span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid g-2" style="gap:0;">

    <!-- Spare Parts -->
    <div style="border-right:1px solid var(--g2);">
      <div style="padding:10px 16px;border-bottom:1px solid var(--g2);display:flex;align-items:center;justify-content:space-between;">
        <div style="font-size:11px;font-weight:700;color:var(--t1);text-transform:uppercase;letter-spacing:.5px;">
          🔩 Spare Parts
        </div>
        <div style="font-size:10px;color:var(--t3);"><?= count($kit['parts']) ?> item<?= count($kit['parts'])===1?'':'s' ?></div>
      </div>
      <?php if(empty($kit['parts'])): ?>
      <div style="padding:20px;text-align:center;color:var(--t4);font-size:10.5px;">No parts history</div>
      <?php else: ?>
      <table class="tbl" style="font-size:10.5px;">
        <thead>
          <tr>
            <th>PART NAME</th>
            <th style="width:90px;">PART NO.</th>
            <th style="width:70px;text-align:center;">ENTRIES</th>
            <th style="width:60px;text-align:center;">QTY</th>
            <th style="width:55px;text-align:center;">UNIT</th>
            <th style="width:100px;text-align:right;">AVG COST (<?= $sk_h($siteCurrency) ?>)</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($kit['parts'] as $p):
          $hot = $p->times_used >= 3;
        ?>
        <tr style="<?= $hot?'background:rgba(180,83,9,0.04)':'' ?>">
          <td style="font-weight:<?= $hot?'600':'400' ?>;">
            <?= htmlspecialchars((string)($p->part_name)) ?>
            <?php if($hot): ?><span style="font-size:9px;color:var(--amber);margin-left:4px;" title="At least three recorded spare-part rows">★ 3+ entries</span><?php endif; ?>
          </td>
          <td class="mono" style="text-align:center;color:var(--t3);font-size:9.5px;"><?= htmlspecialchars((string)($p->part_number??'—')) ?></td>
          <td style="text-align:center;font-weight:600;color:<?= $p->times_used>=3?'var(--amber)':'var(--t2)' ?>;"><?= number_format((int)$p->times_used) ?></td>
          <td class="mono" style="text-align:center;"><?= number_format((float)$p->total_qty,1) ?></td>
          <td style="text-align:center;color:var(--t3);"><?= htmlspecialchars((string)($p->unit)) ?></td>
          <td class="mono" style="text-align:right;color:var(--t3);">
            <?= (float)$p->avg_cost > 0 ? $sk_h(wms_money($p->avg_cost, $siteCurrency)) : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Lubricants & Oils -->
    <div>
      <div style="padding:10px 16px;border-bottom:1px solid var(--g2);display:flex;align-items:center;justify-content:space-between;">
        <div style="font-size:11px;font-weight:700;color:var(--t1);text-transform:uppercase;letter-spacing:.5px;">
          🛢 Lubricants &amp; Oils
        </div>
        <div style="font-size:10px;color:var(--t3);"><?= count($kit['lubes']) + count($kit['oils']) ?> item<?= (count($kit['lubes'])+count($kit['oils']))===1?'':'s' ?></div>
      </div>

      <?php if(empty($kit['lubes']) && empty($kit['oils'])): ?>
      <div style="padding:20px;text-align:center;color:var(--t4);font-size:10.5px;">No lubricant history</div>
      <?php else: ?>

      <?php if(!empty($kit['lubes'])): ?>
      <div style="padding:6px 16px 3px;font-size:11px;font-weight:500;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--g2);">From Lube Records</div>
      <table class="tbl" style="font-size:10.5px;">
        <thead>
          <tr>
            <th>LUBRICANT</th>
            <th style="width:110px;">CATEGORY</th>
            <th style="width:70px;">GRADE</th>
            <th style="width:70px;text-align:center;">ENTRIES</th>
            <th style="width:80px;text-align:center;">TOTAL USED</th>
            <th style="width:50px;text-align:center;">UNIT</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($kit['lubes'] as $l): ?>
        <tr>
          <td style="font-weight:500;"><?= htmlspecialchars((string)($l->name)) ?></td>
          <td style="font-size:9.5px;color:var(--t3);"><?= htmlspecialchars((string)($l->category)) ?></td>
          <td class="mono" style="text-align:center;color:var(--t3);"><?= htmlspecialchars((string)($l->grade??'—')) ?></td>
          <td style="text-align:center;color:var(--blue);font-weight:600;"><?= number_format((int)$l->times) ?></td>
          <td class="mono" style="text-align:center;"><?= number_format((float)$l->total_used,1) ?></td>
          <td style="text-align:center;color:var(--t3);"><?= htmlspecialchars((string)($l->unit)) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <?php if(!empty($kit['oils'])): ?>
      <div style="padding:6px 16px 3px;font-size:11px;font-weight:500;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--g2);border-top:1px solid var(--g2);">From Work Order Oil Records</div>
      <table class="tbl" style="font-size:10.5px;">
        <thead>
          <tr>
            <th>SYSTEM / LOCATION</th>
            <th style="width:130px;">OIL TYPE</th>
            <th style="width:70px;">GRADE</th>
            <th style="width:70px;text-align:center;">ENTRIES</th>
            <th style="width:80px;text-align:center;">TOTAL QTY</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($kit['oils'] as $o): ?>
        <tr>
          <td style="color:var(--t2);"><?= htmlspecialchars((string)($o->system_location??'—')) ?></td>
          <td style="font-weight:500;"><?= htmlspecialchars((string)($o->oil_type??'—')) ?></td>
          <td class="mono" style="text-align:center;color:var(--t3);"><?= htmlspecialchars((string)($o->grade??'—')) ?></td>
          <td style="text-align:center;color:var(--blue);font-weight:600;"><?= number_format((int)$o->times) ?></td>
          <td class="mono" style="text-align:center;"><?= number_format((float)($o->total_qty??0),1) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <?php endif; ?>
    </div>

  </div><!-- /grid -->
</div><!-- /card -->
<?php endforeach; ?>
<div class="sk-pagination no-print" id="skPagination" aria-label="Service kit pages" hidden></div>
<?php endif; ?>
<?php endif; ?>

<style>
.sk-type-tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px}
.sk-type-tab{padding:7px 12px;border-radius:7px;font-size:12px;font-weight:700;text-decoration:none;background:var(--g2);color:var(--t2);border:1px solid var(--g3)}
.sk-type-tab.is-active{background:var(--blue);border-color:var(--blue);color:#fff}
.sk-type-tab span{font-size:10px;opacity:.8;margin-left:5px}
.sk-toolbar{padding:12px;display:grid;grid-template-columns:minmax(230px,1fr) auto;gap:12px;align-items:center;margin-bottom:10px}
.sk-search{height:38px;display:flex;align-items:center;gap:8px;padding:0 10px;border:1px solid var(--g3);border-radius:7px;background:var(--g1)}
.sk-search svg{width:16px;height:16px;color:var(--t3);flex:none}.sk-search input{border:0!important;outline:0!important;background:transparent!important;box-shadow:none!important;width:100%;min-width:0;color:var(--t1)}
.sk-search kbd{color:var(--t3);border:1px solid var(--g3);border-radius:4px;padding:1px 5px;font-size:10px}
.sk-status-tabs{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end}
.sk-status-tabs button{border:1px solid var(--g3);border-radius:6px;padding:6px 8px;background:var(--g1);color:var(--t2);font:inherit;font-size:10.5px;cursor:pointer}
.sk-status-tabs button.is-active{background:var(--blue-d);border-color:var(--blue);color:var(--blue);font-weight:700}
.sk-status-tabs button span{font-weight:700;margin-left:3px}
.sk-toolbar-meta{grid-column:1/-1;display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:var(--t3);font-size:10.5px}
.sk-toolbar-meta>span{margin-right:auto}.sk-toolbar-meta select{width:auto;min-width:72px;padding:5px 24px 5px 8px;font-size:11px}
.sk-results-line{display:flex;justify-content:space-between;gap:8px;color:var(--t3);font-size:11px;margin:6px 2px 10px}
.sk-toolbar[hidden],.sk-results-line[hidden],.sk-pagination[hidden],.sk-empty[hidden]{display:none!important}
.sk-empty{padding:28px;display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;color:var(--t2)}
.sk-empty strong{color:var(--t1)}.sk-kit{padding:0;overflow:hidden}.sk-machine-link{font-size:15px;font-weight:800;color:var(--blue);text-decoration:none}.sk-machine-link:hover{text-decoration:underline}
.sk-machine-meta{display:flex;align-items:center;gap:7px;margin-top:5px;font-size:10px;color:var(--t3)}.sk-machine-meta>a{color:var(--blue);text-decoration:none}.sk-machine-meta .badge{font-size:9px}
.sk-pagination{display:flex;justify-content:center;align-items:center;gap:5px;flex-wrap:wrap;margin:16px 0 24px}
.sk-pagination button{border:1px solid var(--g3);border-radius:6px;background:var(--g1);color:var(--t2);padding:6px 10px;cursor:pointer}.sk-pagination button[aria-current="page"]{background:var(--blue);color:#fff;border-color:var(--blue)}.sk-pagination button:disabled{opacity:.45;cursor:not-allowed}
@media(max-width:850px){.sk-toolbar{grid-template-columns:1fr}.sk-status-tabs{justify-content:flex-start}.sk-toolbar-meta{grid-column:1}.sk-toolbar-meta>span{width:100%}}
@media print {
  .no-print,#sidebar,#canvas .topbar,.btn,a.btn{display:none!important;}
  #canvas{margin-left:0!important;}
  .card{box-shadow:none!important;page-break-inside:avoid;}
  .tbl td,.tbl th{font-size:7pt!important;padding:2px 4px!important;}
  .grid{display:block!important;}
  .grid>*{width:100%;margin-bottom:8px;}
}
</style>
<script>
(function () {
  var toolbar = document.getElementById('skToolbar');
  if (!toolbar) return;
  toolbar.hidden = false;
  document.querySelector('.sk-results-line').hidden = false;
  var cards = Array.prototype.slice.call(document.querySelectorAll('.sk-kit'));
  var search = document.getElementById('skSearch');
  var pageSize = document.getElementById('skPageSize');
  var pager = document.getElementById('skPagination');
  pager.hidden = false;
  var resultSummary = document.getElementById('skResultSummary');
  var pageSummary = document.getElementById('skPageSummary');
  var empty = document.getElementById('skNoMatches');
  var status = <?= json_encode($initialStatus, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  var page = <?= (int)$initialPage ?>;
  var perPage = <?= (int)$initialPageSize ?>;
  var type = toolbar.getAttribute('data-active-type');
  var current = [];

  function updateUrl() {
    var params = new URLSearchParams(window.location.search);
    params.set('type', type);
    if (search.value.trim()) params.set('q', search.value.trim()); else params.delete('q');
    if (status !== 'all') params.set('status', status); else params.delete('status');
    if (perPage !== 25) params.set('per_page', String(perPage)); else params.delete('per_page');
    if (page > 1) params.set('page', String(page)); else params.delete('page');
    window.history.replaceState({}, '', window.location.pathname + '?' + params.toString());
    document.querySelectorAll('[data-sk-type-link]').forEach(function (link) {
      var next = new URLSearchParams(params.toString());
      next.set('type', link.getAttribute('data-sk-type-link'));
      link.href = window.location.pathname + '?' + next.toString();
    });
  }
  function render() {
    var term = search.value.trim().toLocaleLowerCase();
    current = cards.filter(function (card) {
      var statusMatch = status === 'all' || card.getAttribute('data-sk-status') === status;
      var searchMatch = !term || (card.getAttribute('data-sk-search') || '').toLocaleLowerCase().indexOf(term) !== -1;
      return statusMatch && searchMatch;
    });
    var totalPages = perPage === 0 ? 1 : Math.max(1, Math.ceil(current.length / perPage));
    page = Math.min(Math.max(1, page), totalPages);
    var first = perPage === 0 ? 0 : (page - 1) * perPage;
    var last = perPage === 0 ? current.length : Math.min(first + perPage, current.length);
    cards.forEach(function (card) { card.hidden = true; });
    current.slice(first, last).forEach(function (card) { card.hidden = false; });
    resultSummary.textContent = current.length ? 'Showing ' + (first + 1) + '–' + last + ' of ' + current.length + ' matching machine' + (current.length === 1 ? '' : 's') : '0 matching machines';
    pageSummary.textContent = current.length > 0 && totalPages > 1 ? 'Page ' + page + ' of ' + totalPages : '';
    empty.hidden = current.length !== 0;
    pager.innerHTML = '';
    if (totalPages > 1) {
      function addPageButton(label, target, disabled, active) {
        var button = document.createElement('button');
        button.type = 'button'; button.textContent = label; button.disabled = disabled;
        if (active) button.setAttribute('aria-current', 'page');
        button.addEventListener('click', function () { page = target; render(); window.scrollTo({top: toolbar.offsetTop, behavior: 'smooth'}); });
        pager.appendChild(button);
      }
      addPageButton('Previous', page - 1, page <= 1, false);
      for (var p = 1; p <= totalPages; p++) addPageButton(String(p), p, false, p === page);
      addPageButton('Next', page + 1, page >= totalPages, false);
    }
    document.querySelectorAll('[data-sk-status]').forEach(function (button) {
      if (button.tagName !== 'BUTTON') return;
      var active = button.getAttribute('data-sk-status') === status;
      button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    updateUrl();
  }
  function clearFilters() { search.value = ''; status = 'all'; page = 1; render(); search.focus(); }
  search.addEventListener('input', function () { page = 1; render(); });
  pageSize.addEventListener('change', function () { perPage = parseInt(pageSize.value, 10) || 0; page = 1; render(); });
  document.querySelectorAll('.sk-status-tabs button').forEach(function (button) {
    button.addEventListener('click', function () { status = button.getAttribute('data-sk-status'); page = 1; render(); });
  });
  document.getElementById('skResetFilters').addEventListener('click', clearFilters);
  document.getElementById('skEmptyReset').addEventListener('click', clearFilters);
  document.addEventListener('keydown', function (event) {
    if (event.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { event.preventDefault(); search.focus(); }
    if (event.key === 'Escape' && document.activeElement === search && (search.value || status !== 'all')) clearFilters();
  });
  render();
})();
</script>
