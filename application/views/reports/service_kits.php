<?php
$types = array_keys($grouped);
$activeType = isset($_GET['type']) ? strtoupper((string)($_GET['type'])) : ($types[0] ?? '');
if (!in_array($activeType, $types) && !empty($types)) $activeType = $types[0];
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">Service Kit Recommendations</h1>
    <div class="cs">Parts, lubricants and oils per machine — based on historical WO data · Generated <?= $generated_at ?></div>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('reports/export_service_kits_xlsx') ?>"
       class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export Excel (all types)
    </a>
    <button onclick="window.print()" class="btn btn-ghost btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print
    </button>
  </div>
</div>

<!-- KPI strip -->
<div class="grid g-4 mb-4">
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Equipment with Data</span>
    <div class="kpi-num"><?= $total_eq ?><sup>units</sup></div>
  </div>
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Fleet Types</span>
    <div class="kpi-num"><?= count($grouped) ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Unique Parts Tracked</span>
    <div class="kpi-num"><?= count(array_unique(array_map(fn($k)=>$k->part_name??'', array_merge(...array_map(fn($k)=>$k['parts'], $kits))))) ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-glow" style="background:var(--purple)"></div>
    <span class="kpi-tag">Lube Types Tracked</span>
    <div class="kpi-num"><?= count(array_unique(array_map(fn($k)=>$k->name??'', array_merge(...array_map(fn($k)=>$k['lubes'], $kits))))) ?></div>
  </div>
</div>

<?php if(empty($kits)): ?>
<div class="card" style="padding:52px;text-align:center;color:var(--t3);">
  No historical spare parts or lubricant data found. Data will appear here as work orders are completed with parts and oil records.
</div>
<?php else: ?>

<!-- Type tabs -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">
  <?php foreach($grouped as $type => $typeKits): ?>
  <a href="?type=<?= urlencode($type) ?>"
     style="padding:6px 14px;border-radius:6px;font-size:11.5px;font-weight:600;text-decoration:none;
            <?= $type===$activeType
              ? 'background:var(--blue);color:#fff;'
              : 'background:var(--g2);color:var(--t2);border:1px solid var(--g3);' ?>">
    <?= htmlspecialchars((string)($type)) ?>s
    <span style="margin-left:5px;font-size:10px;opacity:.75;"><?= count($typeKits) ?></span>
  </a>
  <?php endforeach; ?>
</div>

<!-- Selected type kits -->
<?php if(isset($grouped[$activeType])): ?>
<?php foreach($grouped[$activeType] as $kit): ?>
<div class="card mb-4" style="padding:0;overflow:hidden;">

  <!-- Machine header -->
  <div style="padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border-bottom:2px solid var(--blue);">
    <div style="display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:10px;background:var(--blue-d);display:flex;align-items:center;justify-content:center;">
        <svg viewBox="0 0 24 24" style="width:22px;height:22px;stroke:var(--blue);fill:none;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round;"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8l4 1 3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
      </div>
      <div>
        <div style="font-size:15px;font-weight:800;color:var(--blue);"><?= htmlspecialchars((string)($kit['machine_id'])) ?></div>
        <div style="font-size:11.5px;color:var(--t2);"><?= htmlspecialchars((string)($kit['model'])) ?> · <?= htmlspecialchars((string)($kit['location'])) ?></div>
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
          <?= htmlspecialchars((string)($s->schedule_name)) ?> (<?= $s->interval_value ?><?= $s->interval_type[0] ?>)
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
            <th style="width:55px;text-align:center;">USED</th>
            <th style="width:60px;text-align:center;">QTY</th>
            <th style="width:55px;text-align:center;">UNIT</th>
            <th style="width:75px;text-align:center;">AVG COST</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($kit['parts'] as $p):
          $hot = $p->times_used >= 3;
        ?>
        <tr style="<?= $hot?'background:rgba(180,83,9,0.04)':'' ?>">
          <td style="font-weight:<?= $hot?'600':'400' ?>;">
            <?= htmlspecialchars((string)($p->part_name)) ?>
            <?php if($hot): ?><span style="font-size:9px;color:var(--amber);margin-left:4px;">★ frequent</span><?php endif; ?>
          </td>
          <td class="mono" style="text-align:center;color:var(--t3);font-size:9.5px;"><?= htmlspecialchars((string)($p->part_number??'—')) ?></td>
          <td style="text-align:center;font-weight:600;color:<?= $p->times_used>=3?'var(--amber)':'var(--t2)' ?>;"><?= (int)$p->times_used ?>×</td>
          <td class="mono" style="text-align:center;"><?= number_format((float)$p->total_qty,1) ?></td>
          <td style="text-align:center;color:var(--t3);"><?= htmlspecialchars((string)($p->unit)) ?></td>
          <td class="mono" style="text-align:center;color:var(--t3);">
            <?= $p->avg_cost > 0 ? '$'.number_format((float)$p->avg_cost,2) : '—' ?>
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
            <th style="width:55px;text-align:center;">TIMES</th>
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
          <td style="text-align:center;color:var(--blue);font-weight:600;"><?= (int)$l->times ?>×</td>
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
            <th style="width:55px;text-align:center;">TIMES</th>
            <th style="width:80px;text-align:center;">TOTAL QTY</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($kit['oils'] as $o): ?>
        <tr>
          <td style="color:var(--t2);"><?= htmlspecialchars((string)($o->system_location??'—')) ?></td>
          <td style="font-weight:500;"><?= htmlspecialchars((string)($o->oil_type??'—')) ?></td>
          <td class="mono" style="text-align:center;color:var(--t3);"><?= htmlspecialchars((string)($o->grade??'—')) ?></td>
          <td style="text-align:center;color:var(--blue);font-weight:600;"><?= (int)$o->times ?>×</td>
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
<?php endif; ?>
<?php endif; ?>

<style>
@media print {
  #sidebar,#canvas .topbar,.btn,a.btn{display:none!important;}
  #canvas{margin-left:0!important;}
  .card{box-shadow:none!important;page-break-inside:avoid;}
  .tbl td,.tbl th{font-size:7pt!important;padding:2px 4px!important;}
  .grid{display:block!important;}
  .grid>*{width:100%;margin-bottom:8px;}
}
</style>
