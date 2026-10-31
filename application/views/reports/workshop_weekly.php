<?php
$today = date('Y-m-d');
$ws = isset($week_start) ? $week_start : date('Y-m-d');
$we = isset($week_end) ? $week_end : date('Y-m-d', strtotime($ws.' +6 days'));
$wkLabel = date('d M', strtotime($ws)).' – '.date('d M Y', strtotime($we));
$h = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$fmt = function ($value, $digits = 1) {
    return $value === null || $value === '' ? '—' : number_format((float)$value, $digits, '.', ',');
};
$pct = function ($value, $digits = 1) {
    return $value === null || $value === '' ? '—' : number_format((float)$value * 100, $digits, '.', ',').'%';
};
$lower = function ($value) {
    $value = (string)$value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
};
$days = [];
for ($i = 0; $i < 7; $i++) $days[] = date('Y-m-d', strtotime($ws.' +'.$i.' days'));

$totalEquipment = $totalOperational = $totalDown = 0;
$locations = [];
$fleet = null;
$fleetDaily = [];
foreach ($report as $g) {
    $totalEquipment += isset($g['total']) ? (int)$g['total'] : 0;
    $totalOperational += isset($g['available']) ? (int)$g['available'] : 0;
    $totalDown += isset($g['down']) ? (int)$g['down'] : 0;
    if ($fleet === null && isset($g['fleet_kpi'])) $fleet = $g['fleet_kpi'];
    if (!$fleetDaily && isset($g['fleet_daily'])) $fleetDaily = $g['fleet_daily'];
    foreach ($g['machines'] as $m) {
        $location = trim((string)$m['location']);
        if ($location !== '') $locations[$location] = $location;
    }
}
ksort($locations, SORT_NATURAL | SORT_FLAG_CASE);
$fleetAvailability = $fleet && isset($fleet['availability']) ? $fleet['availability'] : null;
$fleetTarget = null;
foreach ($report as $g) {
    if (isset($g['fleet_target']) && $g['fleet_target'] !== null) { $fleetTarget = $g['fleet_target']; break; }
}
$availabilityTone = function ($availability, $target) {
    if ($availability === null) return 'neutral';
    if ($target !== null && $availability >= $target) return 'good';
    if ($target !== null && $availability >= $target - .15) return 'warn';
    return 'bad';
};
$availabilityBar = function ($availability, $target) use ($h, $pct, $availabilityTone) {
    $tone = $availabilityTone($availability, $target);
    $width = $availability === null ? 0 : max(0, min(100, (float)$availability * 100));
    $label = $pct($availability);
    return '<span class="wwr-avbar" aria-label="Availability '.$h($label).'"><span class="wwr-av-track"><i class="wwr-av-fill '.$h($tone).'" style="width:'.$width.'%"></i></span><span class="wwr-av-value '.$h($tone).'">'.$h($label).'</span></span>';
};
$dayTone = function ($cell, $target) use ($availabilityTone) {
    if (!$cell || !isset($cell['availability']) || $cell['availability'] === null) return 'empty';
    $preventive = isset($cell['preventive_hours']) ? (float)$cell['preventive_hours'] : 0;
    if ($preventive > 0 && (float)$cell['availability'] >= .99) return 'prev';
    return $availabilityTone((float)$cell['availability'], $target);
};
?>

<div class="wwr">
  <header class="wwr-head">
    <div>
      <div class="wwr-eyebrow">Operations / Reports</div>
      <h1>Weekly Report</h1>
      <div class="wwr-subtitle">
        <span>Workshop fleet availability and reliability</span>
        <span class="wwr-week"><?= $h($wkLabel) ?></span>
      </div>
    </div>
    <div class="wwr-actions">
      <form method="get" action="<?= $h(base_url('reports/workshop_weekly')) ?>" class="wwr-week-form">
        <label for="wwr-week-start">Week starting</label>
        <input id="wwr-week-start" type="date" name="week_start" value="<?= $h($ws) ?>" max="<?= $h($today) ?>">
        <button class="wwr-btn" type="submit">Load week</button>
      </form>
      <a class="wwr-btn" href="<?= $h(base_url('reports/export_workshop_weekly_xlsx?week_start='.$ws)) ?>" aria-label="Download weekly report as Excel">Excel</a>
      <a class="wwr-btn" href="<?= $h(base_url('reports/export_workshop_weekly_pdf?week_start='.$ws)) ?>" target="_blank" rel="noopener" aria-label="Open printable weekly report">Print / PDF</a>
    </div>
  </header>

  <section class="wwr-summary" aria-label="Fleet summary for selected week">
    <article class="wwr-kpi wwr-kpi-primary">
      <span class="wwr-kpi-label">Fleet availability</span>
      <strong class="wwr-kpi-value <?= $availabilityTone($fleetAvailability, $fleetTarget) ?>"><?= $pct($fleetAvailability) ?></strong>
      <div class="wwr-meter" aria-label="Fleet availability <?= $h($pct($fleetAvailability)) ?>">
        <i style="width:<?= $fleetAvailability === null ? 0 : max(0, min(100, $fleetAvailability * 100)) ?>%"></i>
        <?php if ($fleetTarget !== null): ?><u style="left:<?= max(0, min(100, $fleetTarget * 100)) ?>%"></u><?php endif; ?>
      </div>
      <span class="wwr-kpi-note">Weighted by required operating hours<?= $fleetTarget === null ? '' : ' · target '.$pct($fleetTarget, 0) ?></span>
    </article>
    <article class="wwr-kpi">
      <span class="wwr-kpi-label">Equipment</span>
      <strong class="wwr-kpi-value"><?= number_format($totalEquipment) ?></strong>
      <span class="wwr-kpi-note"><?= number_format($totalOperational) ?> operational now · <?= number_format($totalDown) ?> not operational</span>
    </article>
    <article class="wwr-kpi">
      <span class="wwr-kpi-label">Downtime</span>
      <strong class="wwr-kpi-value"><?= $fmt($fleet && isset($fleet['downtime_hours']) ? $fleet['downtime_hours'] : null) ?><small>h</small></strong>
      <span class="wwr-kpi-note">Breakdowns: <?= number_format($fleet && isset($fleet['breakdowns']) ? (int)$fleet['breakdowns'] : 0) ?></span>
    </article>
    <article class="wwr-kpi">
      <span class="wwr-kpi-label">Preventive work</span>
      <strong class="wwr-kpi-value"><?= $fmt($fleet && isset($fleet['preventive_hours']) ? $fleet['preventive_hours'] : null) ?><small>h</small></strong>
      <span class="wwr-kpi-note">MTBF <?= $fmt($fleet && isset($fleet['mtbf_hours']) ? $fleet['mtbf_hours'] : null) ?>h · MTTR <?= $fmt($fleet && isset($fleet['mttr_hours']) ? $fleet['mttr_hours'] : null) ?>h</span>
    </article>
  </section>

  <section class="wwr-report panel" aria-labelledby="wwr-table-title">
    <div class="wwr-section-head">
      <div>
        <h2 id="wwr-table-title">Availability by equipment type</h2>
        <p>Expand a type to inspect machines. Daily bars, reliability metrics and targets use WMS work orders and KPI settings.</p>
      </div>
      <div class="wwr-expand-controls">
        <button type="button" class="wwr-btn wwr-btn-quiet" data-wwr-action="expand">Expand all</button>
        <button type="button" class="wwr-btn wwr-btn-quiet" data-wwr-action="collapse">Collapse all</button>
      </div>
    </div>

    <div class="wwr-toolbar" role="search" aria-label="Filter equipment">
      <label class="wwr-search"><span class="wwr-sr-only">Search machine, model or location</span>
        <input type="search" id="wwr-search" placeholder="Search machine, model or location" autocomplete="off">
      </label>
      <label class="wwr-location"><span class="wwr-sr-only">Filter by location</span>
        <select id="wwr-location"><option value="">All locations</option><?php foreach ($locations as $location): ?><option value="<?= $h($lower($location)) ?>"><?= $h($location) ?></option><?php endforeach; ?></select>
      </label>
      <label class="wwr-check"><input type="checkbox" id="wwr-below-target"> <span>Below target only</span></label>
      <span id="wwr-match-count" class="wwr-match-count" aria-live="polite"><?= number_format($totalEquipment) ?> machines</span>
    </div>

    <div class="wwr-table-wrap">
      <table class="wwr-table" id="wwr-machine-table">
        <thead><tr>
          <th class="wwr-left">Equipment</th>
          <th class="wwr-left">Daily availability · Mon–Sun</th>
          <th>Preventive h</th><th>Down h</th><th>Failures</th><th>MTBF h</th><th>MTTR h</th><th>Availability</th><th>Target</th>
        </tr></thead>
        <tbody>
        <?php if (empty($report)): ?>
          <tr><td colspan="9" class="wwr-empty">No equipment data found for this week.</td></tr>
        <?php else: ?>
          <?php foreach ($report as $type => $g):
            $groupId = 'wwr-group-'.substr(sha1((string)$type), 0, 10);
            $roll = isset($g['kpi_rollup']) ? $g['kpi_rollup'] : [];
            $groupAv = isset($roll['availability']) ? $roll['availability'] : null;
            $groupTarget = isset($g['target']) ? $g['target'] : null;
          ?>
          <tr class="wwr-group-row" data-group-row="<?= $h($groupId) ?>" data-open="0" data-search="<?= $h($lower((string)$type)) ?>">
            <td class="wwr-left"><button type="button" class="wwr-group-toggle" data-wwr-action="toggle" data-group="<?= $h($groupId) ?>" aria-expanded="false" aria-controls="<?= $h($groupId) ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg><span><?= $h($g['type']) ?></span><small><?= number_format(count($g['machines'])) ?> machines</small>
            </button></td>
            <td class="wwr-left"><span class="wwr-strip" aria-label="Daily group availability">
              <?php foreach ($days as $index => $day): $cell = isset($g['daily'][$day]) ? $g['daily'][$day] : null; ?>
                <i class="<?= $h($dayTone($cell, $groupTarget)) ?>" title="<?= $h(date('D d M', strtotime($day)).': '.($cell && isset($cell['availability']) ? $pct($cell['availability']) : 'No reading')) ?>"></i>
              <?php endforeach; ?>
            </span></td>
            <td><?= $fmt(isset($roll['preventive_hours']) ? $roll['preventive_hours'] : null) ?></td>
            <td><?= $fmt(isset($roll['downtime_hours']) ? $roll['downtime_hours'] : null) ?></td>
            <td><?= number_format(isset($roll['breakdowns']) ? (int)$roll['breakdowns'] : 0) ?></td>
            <td><?= $fmt(isset($roll['mtbf_hours']) ? $roll['mtbf_hours'] : null) ?></td>
            <td><?= $fmt(isset($roll['mttr_hours']) ? $roll['mttr_hours'] : null) ?></td>
            <td><?= $availabilityBar($groupAv, $groupTarget) ?></td>
            <td><?= $pct($groupTarget, 0) ?></td>
          </tr>
          <?php foreach ($g['machines'] as $m):
            $equipmentId = (int)$m['equipment_id'];
            $machineId = 'wwr-machine-'.$equipmentId;
            $machineTarget = isset($m['target']) ? $m['target'] : null;
            $machineAv = isset($m['availability']) ? $m['availability'] : null;
            $machineTone = $availabilityTone($machineAv, $machineTarget);
            $searchText = $lower(trim($m['machine_id'].' '.$m['model'].' '.$m['location']));
            $belowTarget = $machineAv !== null && $machineTarget !== null && $machineAv < $machineTarget;
          ?>
          <tr class="wwr-machine-row" id="<?= $h($machineId) ?>" data-parent="<?= $h($groupId) ?>" data-search="<?= $h($searchText) ?>" data-location="<?= $h($lower(trim((string)$m['location']))) ?>" data-below="<?= $belowTarget ? '1' : '0' ?>" hidden>
            <td class="wwr-left wwr-equipment-cell">
              <button type="button" class="wwr-machine-button" data-wwr-action="drawer" data-detail="wwr-detail-<?= $equipmentId ?>">
                <b><?= $h($m['machine_id']) ?></b><span><?= $h($m['model']) ?> · <?= $h($m['location'] !== '' ? $m['location'] : 'Location not set') ?></span>
              </button>
            </td>
            <td class="wwr-left"><span class="wwr-strip" aria-label="Daily machine availability">
              <?php foreach ($days as $day): $cell = isset($m['daily'][$day]) ? $m['daily'][$day] : null; ?>
                <i class="<?= $h($dayTone($cell, $machineTarget)) ?>" title="<?= $h(date('D d M', strtotime($day)).': '.($cell && isset($cell['availability']) ? $pct($cell['availability']) : 'No reading')) ?>"></i>
              <?php endforeach; ?>
            </span></td>
            <td><?= $fmt(isset($m['preventive_hours']) ? $m['preventive_hours'] : null) ?></td>
            <td><?= $fmt(isset($m['total_dt']) ? $m['total_dt'] : null) ?></td>
            <td><?= number_format(isset($m['failures']) ? (int)$m['failures'] : 0) ?></td>
            <td><?= $fmt(isset($m['mtbf_hours']) ? $m['mtbf_hours'] : null) ?></td>
            <td><?= $fmt(isset($m['mttr_hours']) ? $m['mttr_hours'] : null) ?></td>
            <td><?= $availabilityBar($machineAv, $machineTarget) ?></td>
            <td><?= $pct($machineTarget, 0) ?></td>
          </tr>
          <template id="wwr-detail-<?= $equipmentId ?>">
            <div class="wwr-drawer-head"><div><span class="wwr-eyebrow">Equipment details</span><h2><?= $h($m['machine_id']) ?></h2><p><?= $h($m['model']) ?> · <?= $h($m['location'] !== '' ? $m['location'] : 'Location not set') ?></p></div><button type="button" class="wwr-close" data-wwr-action="close" aria-label="Close details">×</button></div>
            <div class="wwr-drawer-links"><a href="<?= $h(base_url('equipment/view/'.$equipmentId)) ?>">Open equipment profile <span aria-hidden="true">↗</span></a></div>
            <div class="wwr-drawer-status"><span class="wwr-av <?= $h($machineTone) ?>"><?= $pct($machineAv) ?> availability</span><span>Target <?= $pct($machineTarget, 0) ?></span><span><?= $h($m['status_label']) ?></span></div>
            <div class="wwr-drawer-kpis">
              <div><small>Preventive h</small><b><?= $fmt(isset($m['preventive_hours']) ? $m['preventive_hours'] : null) ?></b></div>
              <div><small>Down h</small><b><?= $fmt(isset($m['total_dt']) ? $m['total_dt'] : null) ?></b></div>
              <div><small>Failures</small><b><?= number_format(isset($m['failures']) ? (int)$m['failures'] : 0) ?></b></div>
              <div><small>MTBF h</small><b><?= $fmt(isset($m['mtbf_hours']) ? $m['mtbf_hours'] : null) ?></b></div>
              <div><small>MTTR h</small><b><?= $fmt(isset($m['mttr_hours']) ? $m['mttr_hours'] : null) ?></b></div>
              <div><small>Required h</small><b><?= $fmt(isset($m['required_time_hours']) ? $m['required_time_hours'] : null) ?></b></div>
            </div>
            <section class="wwr-drawer-section"><h3>Daily downtime and preventive work</h3><div class="wwr-day-chart">
              <?php foreach ($days as $day): $cell = isset($m['daily'][$day]) ? $m['daily'][$day] : null; $downHours = $cell && isset($cell['downtime_hours']) ? (float)$cell['downtime_hours'] : 0; $pmHours = $cell && isset($cell['preventive_hours']) ? (float)$cell['preventive_hours'] : 0; $availPct = $cell && isset($cell['availability']) && $cell['availability'] !== null ? (float)$cell['availability'] * 100 : null; ?>
                <div class="wwr-day-column" title="<?= $h(date('D d M', strtotime($day)).' · '.number_format($downHours, 1).'h down · '.number_format($pmHours, 1).'h preventive') ?>">
                  <div class="wwr-day-stack"><i class="wwr-down-segment" style="height:<?= max(0, min(100, $downHours / 24 * 100)) ?>%"></i><i class="wwr-pm-segment" style="height:<?= max(0, min(100, $pmHours / 24 * 100)) ?>%"></i><i class="wwr-up-segment" style="height:<?= max(0, 100 - min(100, ($downHours + $pmHours) / 24 * 100)) ?>%"></i></div>
                  <b><?= $availPct === null ? '—' : number_format($availPct, 0).'%' ?></b><span><?= $h(date('D', strtotime($day))) ?></span>
                </div>
              <?php endforeach; ?>
            </div><div class="wwr-legend"><span><i class="wwr-key wwr-key-up"></i>Available</span><span><i class="wwr-key wwr-key-pm"></i>Preventive</span><span><i class="wwr-key wwr-key-down"></i>Down</span></div></section>
            <section class="wwr-drawer-section"><h3>Work orders in this report window</h3>
              <?php if (empty($m['work_orders'])): ?><p class="wwr-muted">No overlapping work orders for this equipment in the selected week.</p>
              <?php else: ?><ul class="wwr-work-orders"><?php foreach ($m['work_orders'] as $wo): ?>
                <li><div><a href="<?= $h(base_url('workorders/view/'.(int)$wo['id'])) ?>"><?= $h($wo['number']) ?></a><span><?= $h($wo['date'] ? date('d M Y', strtotime($wo['date'])) : 'Date not set') ?> · <?= $h($wo['status']) ?></span><?php if ($wo['observation'] !== ''): ?><p><?= $h($wo['observation']) ?></p><?php endif; ?><?php if ($wo['action'] !== ''): ?><p><b>Action:</b> <?= $h($wo['action']) ?></p><?php endif; ?></div></li>
              <?php endforeach; ?></ul><?php endif; ?>
            </section>
          </template>
          <?php endforeach; ?>
          <?php endforeach; ?>
          <tr class="wwr-total-row">
            <td class="wwr-left"><strong>Fleet total</strong></td>
            <td class="wwr-left"><span class="wwr-strip" aria-label="Daily fleet availability">
              <?php foreach ($days as $day): $cell = isset($fleetDaily[$day]) ? $fleetDaily[$day] : null; ?>
                <i class="<?= $h($dayTone($cell, $fleetTarget)) ?>" title="<?= $h(date('D d M', strtotime($day)).': '.($cell && isset($cell['availability']) ? $pct($cell['availability']) : 'No reading')) ?>"></i>
              <?php endforeach; ?>
            </span></td>
            <td><?= $fmt($fleet && isset($fleet['preventive_hours']) ? $fleet['preventive_hours'] : null) ?></td>
            <td><?= $fmt($fleet && isset($fleet['downtime_hours']) ? $fleet['downtime_hours'] : null) ?></td>
            <td><?= number_format($fleet && isset($fleet['breakdowns']) ? (int)$fleet['breakdowns'] : 0) ?></td>
            <td><?= $fmt($fleet && isset($fleet['mtbf_hours']) ? $fleet['mtbf_hours'] : null) ?></td>
            <td><?= $fmt($fleet && isset($fleet['mttr_hours']) ? $fleet['mttr_hours'] : null) ?></td>
            <td><?= $availabilityBar($fleetAvailability, $fleetTarget) ?></td>
            <td><?= $pct($fleetTarget, 0) ?></td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="wwr-legend wwr-main-legend"><span><i class="wwr-key wwr-key-up"></i>On target</span><span><i class="wwr-key wwr-key-prev"></i>Preventive work</span><span><i class="wwr-key wwr-key-warn"></i>Near target</span><span><i class="wwr-key wwr-key-down"></i>Below target</span><span class="wwr-muted">Daily and weekly rollups are required-time weighted.</span></div>
    <div id="wwr-no-matches" class="wwr-no-matches" hidden>No machines match these filters.</div>
  </section>
  <div id="wwr-layer" class="wwr-layer" hidden></div>
</div>

<style>
.wwr{--wwr-bg:#ECEFF0;--wwr-surface:#F8F9F9;--wwr-surface2:#E2E8EA;--wwr-ink:#14232B;--wwr-muted:#586A72;--wwr-line:#D0D8DB;--wwr-accent:#0B6E7F;--wwr-accent-soft:#D5EAEE;--wwr-good:#1F8A55;--wwr-good-bg:#D4ECDF;--wwr-warn:#C28A12;--wwr-warn-bg:#F6E6C2;--wwr-bad:#C0392B;--wwr-bad-bg:#F5D5D0;--wwr-prev:#3A6FD0;--wwr-prev-bg:#D6E2F7;color:var(--wwr-ink);font-family:inherit;line-height:1.45;-webkit-font-smoothing:antialiased}
.wwr *{box-sizing:border-box}.wwr button,.wwr input,.wwr select{font:inherit;color:inherit}.wwr button{cursor:pointer}.wwr :focus-visible,.wwr-layer :focus-visible{outline:2px solid var(--wwr-accent,#0B6E7F);outline-offset:2px}
.wwr-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px 24px;flex-wrap:wrap;margin:0 0 18px}.wwr-eyebrow{font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--wwr-muted);font-weight:600}.wwr h1,.wwr h2,.wwr h3,.wwr-layer h2,.wwr-layer h3{font-family:inherit;letter-spacing:.01em;font-weight:600;margin:0}.wwr h1{font-size:32px;line-height:1.05}.wwr-subtitle{display:flex;align-items:center;gap:9px;flex-wrap:wrap;color:var(--wwr-muted);margin-top:6px}.wwr-week{font:inherit;font-size:11px;font-variant-numeric:tabular-nums;color:var(--wwr-ink);background:var(--wwr-surface2);border-radius:4px;padding:3px 8px}.wwr-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.wwr-week-form{display:flex;align-items:center;gap:7px;margin-right:3px}.wwr-week-form label{color:var(--wwr-muted);font-size:12px;white-space:nowrap}.wwr-week-form input,.wwr-toolbar input,.wwr-toolbar select{height:36px;border:1px solid var(--wwr-line);border-radius:6px;background:var(--wwr-surface);padding:0 10px}.wwr-week-form input{width:145px}.wwr-btn{min-height:36px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--wwr-line);background:var(--wwr-surface);color:var(--wwr-ink);border-radius:6px;padding:7px 11px;text-decoration:none;font-size:12px;font-weight:600;white-space:nowrap}.wwr-btn:hover{border-color:var(--wwr-accent);color:var(--wwr-accent)}.wwr-btn-quiet{background:transparent}
.wwr-summary{display:grid;grid-template-columns:1.35fr repeat(3,minmax(150px,1fr));gap:12px;margin-bottom:16px}.wwr-kpi,.wwr-report{background:var(--wwr-surface);border:1px solid var(--wwr-line);border-radius:8px}.wwr-kpi{min-width:0;padding:14px 16px;display:flex;flex-direction:column;gap:4px}.wwr-kpi-primary{grid-row:span 1}.wwr-kpi-label{font-size:10px;letter-spacing:.09em;text-transform:uppercase;color:var(--wwr-muted);font-weight:600}.wwr-kpi-value{font:inherit;font-size:31px;line-height:1.08;font-weight:600;font-variant-numeric:tabular-nums}.wwr-kpi-value small{font:inherit;font-size:14px;font-weight:500;color:var(--wwr-muted);margin-left:4px}.wwr-kpi-value.good{color:var(--wwr-good)}.wwr-kpi-value.warn{color:var(--wwr-warn)}.wwr-kpi-value.bad{color:var(--wwr-bad)}.wwr-kpi-note{font-size:11px;color:var(--wwr-muted);line-height:1.4}.wwr-meter{height:7px;position:relative;background:var(--wwr-surface2);border-radius:4px;margin:5px 0 2px}.wwr-meter i{position:absolute;inset:0 auto 0 0;background:var(--wwr-good);border-radius:4px}.wwr-meter u{position:absolute;top:-3px;bottom:-3px;width:2px;background:var(--wwr-ink);text-decoration:none}
.wwr-report{padding:16px 18px;min-width:0}.wwr-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px 20px;flex-wrap:wrap;margin-bottom:13px}.wwr-section-head h2{font-size:21px}.wwr-section-head p{font-size:12px;color:var(--wwr-muted);margin:3px 0 0}.wwr-expand-controls{display:flex;gap:7px;flex-wrap:wrap}.wwr-toolbar{display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding:10px 0 12px;border-top:1px solid var(--wwr-line)}.wwr-search input{width:min(310px,70vw)}.wwr-location select{min-width:145px}.wwr-check{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--wwr-muted);white-space:nowrap}.wwr-check input{accent-color:var(--wwr-accent)}.wwr-match-count{margin-left:auto;color:var(--wwr-muted);font-size:11px;white-space:nowrap}.wwr-table-wrap{overflow:auto;border:1px solid var(--wwr-line);border-radius:7px;background:var(--wwr-surface)}.wwr-table{border-collapse:collapse;width:100%;min-width:980px;font-size:12px}.wwr-table th{position:sticky;top:0;z-index:1;background:var(--wwr-surface);color:var(--wwr-muted);font-size:10px;font-weight:600;letter-spacing:.065em;text-transform:uppercase;text-align:right;padding:9px 10px;border-bottom:1px solid var(--wwr-line);white-space:nowrap}.wwr-table th.wwr-left,.wwr-table td.wwr-left{text-align:left}.wwr-table td{padding:8px 10px;border-bottom:1px solid var(--wwr-line);text-align:right;white-space:nowrap;vertical-align:middle}.wwr-table tbody tr:last-child td{border-bottom:0}.wwr-group-row td{background:var(--wwr-surface2);font-weight:600}.wwr-group-row:hover td,.wwr-machine-row:hover td{background:var(--wwr-accent-soft)}.wwr-group-toggle{display:inline-flex;align-items:center;gap:8px;padding:0;border:0;background:transparent;text-align:left;font-weight:700;color:var(--wwr-ink)}.wwr-group-toggle svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round;transition:transform .15s}.wwr-group-toggle[aria-expanded="true"] svg{transform:rotate(90deg)}.wwr-group-toggle small{font-weight:400;font-size:11px;color:var(--wwr-muted);margin-left:2px}.wwr-machine-row td:first-child{padding-left:40px}.wwr-machine-button{display:flex;flex-direction:column;align-items:flex-start;gap:1px;border:0;background:none;padding:0;text-align:left;max-width:250px}.wwr-machine-button b{font:inherit;font-size:12px;font-weight:600;font-variant-numeric:tabular-nums;color:var(--wwr-accent)}.wwr-machine-button span{font-size:10.5px;color:var(--wwr-muted);max-width:250px;overflow:hidden;text-overflow:ellipsis}.wwr-strip{display:inline-flex;gap:2px;vertical-align:middle}.wwr-strip i{display:block;width:13px;height:17px;border-radius:2px;background:var(--wwr-surface2)}.wwr-strip i.good{background:var(--wwr-good-bg);box-shadow:inset 0 0 0 1px var(--wwr-good)}.wwr-strip i.warn{background:var(--wwr-warn)}.wwr-strip i.bad{background:var(--wwr-bad)}.wwr-strip i.prev{background:var(--wwr-prev)}.wwr-strip i.empty{background:var(--wwr-surface2);background-image:repeating-linear-gradient(135deg,transparent,transparent 3px,var(--wwr-line) 3px,var(--wwr-line) 4px)}.wwr-av{display:inline-block;min-width:53px;padding:3px 7px;border-radius:4px;text-align:center;font:inherit;font-size:11px;font-variant-numeric:tabular-nums}.wwr-av.good{background:var(--wwr-good-bg);color:var(--wwr-good)}.wwr-av.warn{background:var(--wwr-warn-bg);color:#80570A}.wwr-av.bad{background:var(--wwr-bad-bg);color:var(--wwr-bad)}.wwr-av.neutral{background:var(--wwr-surface2);color:var(--wwr-muted)}.wwr-avbar{display:inline-flex;align-items:center;justify-content:flex-end;gap:8px;min-width:96px}.wwr-av-track{position:relative;display:inline-block;width:54px;height:6px;flex:none;overflow:hidden;border-radius:3px;background:var(--wwr-surface2)}.wwr-av-fill{position:absolute;inset:0 auto 0 0;border-radius:3px}.wwr-av-fill.good{background:var(--wwr-good)}.wwr-av-fill.warn{background:var(--wwr-warn)}.wwr-av-fill.bad{background:var(--wwr-bad)}.wwr-av-fill.neutral{background:var(--wwr-muted)}.wwr-av-value{min-width:42px;text-align:right;font-size:11.5px;font-variant-numeric:tabular-nums}.wwr-av-value.good{color:var(--wwr-good)}.wwr-av-value.warn{color:#80570A}.wwr-av-value.bad{color:var(--wwr-bad)}.wwr-av-value.neutral{color:var(--wwr-muted)}.wwr-total-row td{border-top:2px solid var(--wwr-ink);border-bottom:0;font-weight:700}.wwr-total-row strong{font-family:inherit;font-size:15px}.wwr-empty,.wwr-no-matches{text-align:center!important;color:var(--wwr-muted);padding:30px!important}.wwr-no-matches{padding:18px 4px!important}.wwr-legend{display:flex;gap:12px 16px;flex-wrap:wrap;align-items:center;color:var(--wwr-muted);font-size:11px}.wwr-main-legend{margin-top:12px}.wwr-key{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}.wwr-key-up{background:var(--wwr-good-bg);border:1px solid var(--wwr-good)}.wwr-key-prev{background:var(--wwr-prev)}.wwr-key-pm{background:var(--wwr-prev)}.wwr-key-warn{background:var(--wwr-warn)}.wwr-key-down{background:var(--wwr-bad)}.wwr-muted{color:var(--wwr-muted)}.wwr-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.wwr-layer[hidden]{display:none!important}.wwr-layer{position:fixed;inset:0;z-index:10000}.wwr-scrim{position:absolute;inset:0;background:rgba(8,20,24,.48)}.wwr-drawer{position:absolute;top:0;right:0;bottom:0;width:min(470px,100%);overflow:auto;background:var(--wwr-surface,#F8F9F9);color:var(--wwr-ink,#14232B);border-left:1px solid var(--wwr-line,#D0D8DB);padding:20px max(20px,env(safe-area-inset-right,0px)) 28px 20px;box-shadow:-10px 0 35px rgba(0,0,0,.13)}.wwr-drawer-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:13px}.wwr-drawer-head h2{font-size:27px;margin-top:2px}.wwr-drawer-head p{color:var(--wwr-muted);font-size:12px;margin:3px 0 0}.wwr-close{width:34px;height:34px;border:1px solid var(--wwr-line);border-radius:6px;background:transparent;font-size:23px;line-height:1}.wwr-drawer-links{margin:0 0 13px}.wwr-drawer-links a,.wwr-work-orders a{color:var(--wwr-accent);font-weight:600;text-decoration:none}.wwr-drawer-links a:hover,.wwr-work-orders a:hover{text-decoration:underline}.wwr-drawer-status{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px;font-size:11px;color:var(--wwr-muted)}.wwr-drawer-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;background:var(--wwr-line);border:1px solid var(--wwr-line);border-radius:7px;overflow:hidden;margin:12px 0 19px}.wwr-drawer-kpis div{background:var(--wwr-surface);padding:10px}.wwr-drawer-kpis small{display:block;color:var(--wwr-muted);font-size:10px}.wwr-drawer-kpis b{font:inherit;font-size:17px;font-weight:600}.wwr-drawer-section{margin-top:18px}.wwr-drawer-section h3{font-size:18px;margin-bottom:9px}.wwr-day-chart{height:135px;display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:9px;align-items:end;border-bottom:1px solid var(--wwr-line);padding:0 2px}.wwr-day-column{display:flex;height:100%;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px;font-size:10px;color:var(--wwr-muted)}.wwr-day-column b{font:inherit;font-size:9px;font-variant-numeric:tabular-nums;color:var(--wwr-ink)}.wwr-day-stack{height:82px;width:18px;display:flex;flex-direction:column;justify-content:flex-end;overflow:hidden;border-radius:3px;background:var(--wwr-surface2)}.wwr-day-stack i{display:block;min-height:0}.wwr-down-segment{background:var(--wwr-bad)}.wwr-pm-segment{background:var(--wwr-prev)}.wwr-up-segment{background:var(--wwr-good-bg)}.wwr-work-orders{list-style:none;padding:0;margin:0}.wwr-work-orders li{padding:10px 0;border-top:1px solid var(--wwr-line)}.wwr-work-orders li>div{display:grid;grid-template-columns:auto 1fr;gap:2px 10px}.wwr-work-orders span{justify-self:end;color:var(--wwr-muted);font-size:10px}.wwr-work-orders p{grid-column:1/-1;margin:3px 0 0;font-size:11px;white-space:normal;color:var(--wwr-muted)}
@media(max-width:1050px){.wwr-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.wwr-kpi-primary{grid-column:span 2}}@media(max-width:640px){.wwr-head{align-items:flex-start}.wwr-actions{width:100%}.wwr-week-form{width:100%;margin:0}.wwr-week-form input{flex:1}.wwr-summary{grid-template-columns:1fr 1fr;gap:8px}.wwr-kpi{padding:12px}.wwr-kpi-value{font-size:27px}.wwr-report{padding:13px 11px}.wwr-toolbar{align-items:stretch}.wwr-search{flex:1 1 100%}.wwr-search input{width:100%}.wwr-location{flex:1}.wwr-location select{width:100%}.wwr-match-count{width:100%;margin:0}.wwr-drawer{padding:17px 15px 24px}.wwr-drawer-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(prefers-color-scheme:dark){.wwr{--wwr-bg:#0E171B;--wwr-surface:#152227;--wwr-surface2:#1F3037;--wwr-ink:#E5EEF0;--wwr-muted:#8FA4AC;--wwr-line:#263840;--wwr-accent:#55C2D4;--wwr-accent-soft:#17343B;--wwr-good:#45C284;--wwr-good-bg:#1B3A2C;--wwr-warn:#E3AE3F;--wwr-warn-bg:#3B3116;--wwr-bad:#EE6D5E;--wwr-bad-bg:#432322;--wwr-prev:#6C9BEA;--wwr-prev-bg:#1E2F52}}
@media print{.wwr-head .wwr-actions,.wwr-toolbar,.wwr-expand-controls,.wwr-layer{display:none!important}.wwr{font-size:10px}.wwr-summary{grid-template-columns:repeat(4,1fr)}.wwr-report{padding:8px;border:0}.wwr-table-wrap{overflow:visible}.wwr-table{min-width:0;font-size:8px}.wwr-table th,.wwr-table td{position:static;padding:4px}.wwr-machine-row[hidden]{display:none!important}.wwr-group-row{break-after:avoid}.wwr-total-row{break-inside:avoid}}
</style>

<script>
(function(){
  var root = document.querySelector('.wwr');
  if (!root) return;
  var search = document.getElementById('wwr-search');
  var location = document.getElementById('wwr-location');
  var below = document.getElementById('wwr-below-target');
  var count = document.getElementById('wwr-match-count');
  var noMatches = document.getElementById('wwr-no-matches');
  var table = document.getElementById('wwr-machine-table');
  var layer = document.getElementById('wwr-layer');
  var lastFocus = null;
  var rows = table ? Array.prototype.slice.call(table.querySelectorAll('.wwr-machine-row')) : [];
  var groups = table ? Array.prototype.slice.call(table.querySelectorAll('.wwr-group-row')) : [];
  function activeFilter(){ return !!((search && search.value.trim()) || (location && location.value) || (below && below.checked)); }
  function applyFilters(){
    var q = search ? search.value.trim().toLocaleLowerCase() : '';
    var loc = location ? location.value : '';
    var onlyBelow = below ? below.checked : false;
    var shown = 0;
    rows.forEach(function(row){
      var matches = (!q || (row.dataset.search || '').toLocaleLowerCase().indexOf(q) !== -1) &&
        (!loc || row.dataset.location === loc) && (!onlyBelow || row.dataset.below === '1');
      row.dataset.matches = matches ? '1' : '0';
      if (matches) shown++;
    });
    groups.forEach(function(group){
      var id = group.dataset.groupRow;
      var children = rows.filter(function(row){ return row.dataset.parent === id; });
      var matches = children.some(function(row){ return row.dataset.matches === '1'; });
      var filtered = activeFilter();
      group.hidden = filtered && !matches;
      var open = filtered ? matches : group.dataset.open === '1';
      var button = group.querySelector('[data-wwr-action="toggle"]');
      if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false');
      children.forEach(function(row){ row.hidden = !(open && row.dataset.matches === '1'); });
    });
    if (count) count.textContent = shown + (shown === 1 ? ' machine' : ' machines') + ' shown';
    if (noMatches) noMatches.hidden = shown !== 0;
  }
  function setAll(open){
    groups.forEach(function(group){
      group.dataset.open = open ? '1' : '0';
      var button = group.querySelector('[data-wwr-action="toggle"]');
      if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false');
      rows.forEach(function(row){ if (row.dataset.parent === group.dataset.groupRow) row.hidden = !open || row.dataset.matches === '0'; });
    });
    applyFilters();
  }
  function closeDrawer(){
    if (!layer || layer.hidden) return;
    layer.hidden = true;
    layer.innerHTML = '';
    document.body.style.overflow = '';
    if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
  }
  function openDrawer(button){
    var template = document.getElementById(button.dataset.detail || '');
    if (!template || !layer) return;
    lastFocus = button;
    layer.innerHTML = '<div class="wwr-scrim" data-wwr-action="close"></div><aside class="wwr-drawer" role="dialog" aria-modal="true" aria-label="Equipment details">' + template.innerHTML + '</aside>';
    layer.hidden = false;
    document.body.style.overflow = 'hidden';
    var close = layer.querySelector('.wwr-close');
    if (close) close.focus();
  }
  if (search) search.addEventListener('input', applyFilters);
  if (location) location.addEventListener('change', applyFilters);
  if (below) below.addEventListener('change', applyFilters);
  root.addEventListener('click', function(event){
    var button = event.target.closest('[data-wwr-action]');
    if (!button) return;
    var action = button.dataset.wwrAction;
    if (action === 'toggle') {
      var group = groups.find(function(item){ return item.dataset.groupRow === button.dataset.group; });
      if (!group) return;
      group.dataset.open = group.dataset.open === '1' ? '0' : '1';
      applyFilters();
    } else if (action === 'expand') setAll(true);
    else if (action === 'collapse') setAll(false);
    else if (action === 'drawer') openDrawer(button);
  });
  if (layer) {
    layer.addEventListener('click', function(event){
      if (event.target.closest('[data-wwr-action="close"]')) closeDrawer();
    });
    document.addEventListener('keydown', function(event){
      if (layer.hidden) return;
      if (event.key === 'Escape') { closeDrawer(); return; }
      if (event.key === 'Tab') {
        var focusables = layer.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])');
        if (!focusables.length) return;
        var first = focusables[0], last = focusables[focusables.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });
  }
  applyFilters();
})();
</script>
