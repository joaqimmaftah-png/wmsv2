<?php
/**
 * PLANNED AVAILABILITY — one week, machine → group → fleet.
 * Task F5, with the unit view of F7 and the freeze of D33.
 *
 * Nothing here is computed: every figure arrives from Forecast_model, which
 * calls kpi_availability() and kpi_rollup() (D29/D30).  A view that recomputes
 * a KPI is how a system ends up with two answers.
 */
$pct = function ($v, $d = 2) {
    return $v === null ? '—' : number_format($v * 100, $d) . '%';
};
$hrs = function ($v) {
    return $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
};
$col = function ($v) {
    if ($v === null) return 'var(--t3)';
    return $v >= 0.90 ? 'var(--green)' : ($v >= 0.70 ? 'var(--amber)' : 'var(--red)');
};
// MY_Controller::render() hands every view $role_id, not a role flag — and
// load->view() scopes each include on its own, so the $is_supervisor that
// layouts/sidebar.php derives never reaches this file.  Derive it here, the
// same way the sidebar does.
$is_supervisor = isset($role_id) && (int)$role_id === 1;
$is_manager    = isset($role_id) && (int)$role_id === 4;

$frozen  = !empty($snapshot);
$days    = isset($days) ? $days : array();
$day_lbl = array();
foreach ($days as $d) $day_lbl[$d] = date('D', strtotime($d));

// group the machine rows by their group, keeping the model's worst-first order
$group_order = array();
foreach ($groups as $g) $group_order[] = $g['group'];
$by_group = array();
foreach ($rows as $r) $by_group[$r['group']][] = $r;

// MP-0 / MP-2 — optional inputs, so an older payload or caller still renders
$warnings   = isset($warnings) && is_array($warnings) ? $warnings : array();
$pm_issues  = isset($pm_issues) && is_array($pm_issues) ? $pm_issues
            : array('not_placed' => array(), 'missing' => array(), 'estimated' => array(), 'replaced' => array(), 'later' => array());
$job_codes  = isset($job_codes) && is_array($job_codes) ? $job_codes : array();
$jobs_ready = !empty($jobs_ready);
$can_plan   = isset($can_plan) ? (bool)$can_plan : $is_supervisor;
$overbooked = array();
foreach ($warnings as $w) $overbooked[$w['equipment_id'] . '|' . $w['date']] = $w;
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Planned Availability — <?=htmlspecialchars($iso_label)?></div>
    <div class="cs"><?=date('d M Y', strtotime($week_start))?> – <?=date('d M Y', strtotime($week_end))?></div>
  </div>
  <div class="flex ai-c gap-10">
    <form method="get" class="flex ai-c gap-10">
      <label style="font-size:11px;color:var(--t3);">Week</label>
      <input type="week" name="week" class="form-control" style="width:170px;"
             value="<?=htmlspecialchars($iso_label)?>">
      <button class="btn btn-sm btn-primary" type="submit">Open</button>
    </form>
    <a class="btn btn-sm" href="<?= base_url('forecast/export?week=' . urlencode($iso_label)) ?>">
      Export XLSX
    </a>
  </div>
</div>

<?php if (!$tables_ready): ?>
<div class="card" style="padding:34px;border-left:3px solid var(--amber);">
  <div style="font-weight:700;margin-bottom:6px;">The forecast tables are not set up yet</div>
  <div style="font-size:12px;color:var(--t2);line-height:1.7;">
    The planned-downtime tables do not exist yet, so there is nowhere to store a plan.
    Import the database dump shipped with this repository, then reload this page.
  </div>
</div>
<?php else: ?>

<!-- ── KPI strip ─────────────────────────────────────────────────────── -->
<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:<?=$col($fleet['availability'])?>"></div>
    <span class="kpi-tag">Planned fleet availability</span>
    <div class="kpi-num" style="color:<?=$col($fleet['availability'])?>;"><?=$pct($fleet['availability'])?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">capacity-weighted (D30)</div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Available to production</span>
    <div class="kpi-num"><?=$unit['available']?><sup>/<?=$unit['total']?> units</sup></div>
    <div class="cs" style="font-size:10px;margin-top:3px;"><?=$unit['out_of_service']?> planned out of service · <?=$unit['stand_by']?> stand-by reserve (not in the %)</div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Planned PM</span>
    <div class="kpi-num"><?=$hrs($fleet['pm_hours'])?><sup>h</sup></div>
    <div class="cs" style="font-size:10px;margin-top:3px;"><?=$machines_with_plan?> machines carry a plan</div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Planned downtime</span>
    <div class="kpi-num"><?=$hrs($fleet['downtime_hours'])?><sup>h</sup></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">standing + unplanned</div>
  </div>
</div>

<?php
  /*
   * `forecast_show_excel_reference` — set by an authorised user in Settings.
   *
   * Off means system figures only, on screen and in the export alike.  The
   * setting already governed the workbook; it did not govern this page, so
   * turning it off changed nothing a planner could see.  The reference columns
   * are secondary by decision (D29/D30) and must never displace the KPI, so
   * hiding them cleanly has to be possible.
   */
  $show_reference = !isset($show_reference) || !empty($show_reference);
?>
<?php if ($show_reference && !empty($fleet['availability_unweighted']) && $fleet['availability_unweighted'] !== $fleet['availability']): ?>
<div class="cs mb-4" style="font-size:11px;">
  Reference: the historical workbook's unweighted machine average would read
  <strong><?=$pct($fleet['availability_unweighted'])?></strong> for this week. It is shown for
  comparison only — the capacity-weighted figure above is the KPI (D30).
</div>
<?php endif; ?>

<!-- ── Freeze / build controls ───────────────────────────────────────── -->
<div class="card mb-4" style="padding:16px 18px;">
  <?php if ($frozen): ?>
  <div class="flex ai-c jb" style="flex-wrap:wrap;gap:12px;">
    <div>
      <div style="font-weight:700;font-size:12px;color:var(--green);">
        Published — this plan is frozen
      </div>
      <div class="cs" style="font-size:11px;margin-top:4px;">
        Revision <?=htmlspecialchars((string)$snapshot->revision)?> ·
        published <?=date('d M Y H:i', strtotime($snapshot->published_at))?>
        <?php if (!empty($history) && count($history) > 1): ?>
          · <?=count($history)?> revisions on file; plan-vs-actual still measures against revision 1
        <?php endif; ?>
      </div>
    </div>
    <div class="flex gap-10">
      <a class="btn btn-sm" href="<?= base_url('forecast/plan_vs_actual?week=' . urlencode($iso_label)) ?>">
        Compare with the actual
      </a>
      <?php if ($is_supervisor): ?>
      <form method="post" action="<?= base_url('forecast/publish?week=' . urlencode($iso_label)) ?>"
            class="flex gap-10 ai-c"
            onsubmit="return confirm('Raise a new revision? The original baseline is preserved.');">
        <?= csrf_field() ?>
        <input type="text" name="revision_note" class="form-control" style="width:250px;"
               placeholder="Why is this revision raised? (required)" required>
        <button class="btn btn-sm btn-primary" type="submit">Publish revision</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php else: ?>
  <div class="flex ai-c jb" style="flex-wrap:wrap;gap:12px;">
    <div>
      <div style="font-weight:700;font-size:12px;">Draft — not yet published</div>
      <div class="cs" style="font-size:11px;margin-top:4px;">
        Build the plan, adjust it by hand, then publish to freeze it as the baseline.
      </div>
    </div>
    <?php if ($is_supervisor): ?>
    <?php $insp = isset($inspections) ? $inspections : null; ?>
    <?php if ($insp && $insp['ready']): ?>
    <div style="width:100%;font-size:11px;color:var(--t2);">
      Weekly inspection rounds: <b><?= (int)$insp['rounds'] ?></b> of <?= (int)$insp['machines'] ?> machines
      (<?= rtrim(rtrim(number_format((float)$insp['default_hours'], 2, '.', ''), '0'), '.') ?> h each, set in Settings).
      <?php if (!empty($insp['missing'])): ?>
      <form method="post" action="<?= base_url('forecast/create_inspection_rounds?week=' . urlencode($iso_label)) ?>" style="display:inline;">
        <?= csrf_field() ?>
        <button class="btn btn-sm" type="submit">Create weekly inspection rounds (<?= count($insp['missing']) ?> missing)</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="flex gap-10" style="flex-wrap:wrap;">
      <form method="post" action="<?= base_url('forecast/generate?week=' . urlencode($iso_label)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="what" value="pm">
        <button class="btn btn-sm" type="submit">Place PM from schedules</button>
      </form>
      <form method="post" action="<?= base_url('forecast/generate?week=' . urlencode($iso_label)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="what" value="inspections">
        <button class="btn btn-sm" type="submit" title="One 1 h inspection per machine on its weekday. Standby and out-of-service machines are reported, not booked.">Place weekly inspections</button>
      </form>
      <form method="post" action="<?= base_url('forecast/generate?week=' . urlencode($iso_label)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="what" value="standing">
        <button class="btn btn-sm" type="submit">Apply standing allowance</button>
      </form>
      <form method="post" action="<?= base_url('forecast/generate?week=' . urlencode($iso_label)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="what" value="unplanned">
        <button class="btn btn-sm" type="submit">Apply unplanned allowance</button>
      </form>
      <form method="post" action="<?= base_url('forecast/publish?week=' . urlencode($iso_label)) ?>"
            class="flex gap-10 ai-c"
            onsubmit="return confirm('Publish and freeze <?=$iso_label?> It becomes the baseline plan-vs-actual is measured against.');">
        <?= csrf_field() ?>
        <?php if ($warnings): ?>
        <input type="text" name="revision_note" class="form-control" style="width:250px;" required
               placeholder="<?=count($warnings)?> overbooked day(s) — note required">
        <?php endif; ?>
        <button class="btn btn-sm btn-primary" type="submit">Publish &amp; freeze</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($warnings): ?>
<!-- ── MP-0: overbooked machine-days — reported, never silently clamped ── -->
<div class="card mb-4" style="padding:14px 18px;border-left:3px solid var(--red);">
  <div style="font-weight:700;font-size:12px;color:var(--red);">
    <?=count($warnings)?> machine-day<?=count($warnings) === 1 ? ' is' : 's are'?> booked for more hours than the day has
  </div>
  <div class="cs" style="font-size:11px;margin:4px 0 8px;">
    Only the day's open hours count as downtime; the cells are outlined in red below. Re-apply the
    standing allowance to clear allowances on held days, or correct the bookings. Publishing an
    overbooked plan needs a note.
  </div>
  <ul style="margin:0;padding-left:18px;font-size:11.5px;line-height:1.7;">
    <?php foreach (array_slice($warnings, 0, 30) as $w): ?>
    <li class="overbooked-warning"><?=htmlspecialchars($w['message'])?> (<?=$hrs($w['excess_hours'])?> h too many)</li>
    <?php endforeach; ?>
    <?php if (count($warnings) > 30): ?><li>… and <?=count($warnings) - 30?> more</li><?php endif; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($pm_issues['not_placed'] || $pm_issues['missing'] || $pm_issues['estimated'] || $pm_issues['replaced'] || $pm_issues['later']): ?>
<!-- ── MP-0: PM the plan cannot place, or places on an estimate ─────────── -->
<div class="card mb-4" style="padding:14px 18px;border-left:3px solid var(--amber);" id="pm-issues">
  <div style="font-weight:700;font-size:12px;color:var(--amber);">PM schedules that need attention</div>
  <table class="tbl" style="margin-top:8px;">
    <thead><tr><th>MACHINE</th><th>SCHEDULE</th><th>INTERVAL</th><th>DUE</th><th>ISSUE</th></tr></thead>
    <tbody>
    <?php foreach (array('not_placed' => 'Cannot be placed', 'missing' => 'No duration', 'estimated' => 'Estimated date', 'replaced' => 'Replaced', 'later' => 'Later week') as $k => $lbl):
            foreach ($pm_issues[$k] as $i): ?>
      <tr class="pm-issue pm-issue-<?=$k?>">
        <td class="mono"><?=htmlspecialchars((string)($i['registration_no'] !== null ? $i['registration_no'] : '#' . $i['equipment_id']))?></td>
        <td><?=htmlspecialchars($i['schedule_name'])?></td>
        <td><?=htmlspecialchars($i['interval'])?></td>
        <td><?=htmlspecialchars((string)($i['next_due_date'] ?: '—'))?></td>
        <td><strong><?=$lbl?></strong> — <?=htmlspecialchars($i['reason'])?></td>
      </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── Group roll-up (D30) ───────────────────────────────────────────── -->
<div class="card mb-4" style="overflow-x:auto;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Planned availability by group</div>
    <div class="cs">Worst first — where the crew is needed. Weighted by required time, as the actual is.</div>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th>GROUP</th>
        <th style="text-align:center;">MACHINES</th>
        <th style="text-align:right;">REQUIRED h</th>
        <th style="text-align:right;">PM h</th>
        <th style="text-align:right;">DOWNTIME h</th>
        <th style="text-align:right;">PLANNED AVAILABILITY</th>
        <?php if ($show_reference): ?>
        <th style="text-align:right;">UNWEIGHTED (ref)</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($groups as $g): ?>
      <tr>
        <td style="font-weight:600;color:var(--t1);"><?=htmlspecialchars($g['group'])?></td>
        <td style="text-align:center;"><?=$g['machines']?></td>
        <td class="mono" style="text-align:right;color:var(--t3);"><?=$hrs($g['required_hours'])?></td>
        <td class="mono" style="text-align:right;color:var(--t3);"><?=$hrs($g['pm_hours'])?></td>
        <td class="mono" style="text-align:right;color:var(--t3);"><?=$hrs($g['downtime_hours'])?></td>
        <td style="text-align:right;font-weight:700;color:<?=$col($g['availability'])?>;"><?=$pct($g['availability'])?></td>
        <?php if ($show_reference): ?>
        <td class="mono" style="text-align:right;color:var(--t3);font-size:10.5px;"><?=$pct($g['availability_unweighted'])?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$groups): ?>
      <tr><td colspan="<?=$show_reference ? 7 : 6?>" style="text-align:center;color:var(--t3);padding:26px;">No machines in the forecast.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ── F7: the unit view — machines available per group per day ──────── -->
<?php if (!empty($units_by_day)): ?>
<div class="card mb-4" style="overflow-x:auto;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Machines available to production, per day</div>
    <div class="cs">
      A headcount, not a percentage — a machine at 60 % is still a machine an
      operator can be put on. Out-of-service machines are shown separately (D32).
    </div>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th>GROUP</th>
        <?php foreach ($day_lbl as $d => $lbl): ?>
        <th style="text-align:center;"><?=$lbl?><br>
          <span style="font-weight:400;color:var(--t3);font-size:9.5px;"><?=date('d M', strtotime($d))?></span></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($group_order as $gname):
            if (empty($units_by_day[$gname])) continue; ?>
      <tr>
        <td style="font-weight:600;color:var(--t1);"><?=htmlspecialchars($gname)?></td>
        <?php foreach ($days as $d):
                $c = isset($units_by_day[$gname][$d]) ? $units_by_day[$gname][$d] : null; ?>
        <td style="text-align:center;">
          <?php if (!$c): ?><span style="color:var(--t3);">—</span>
          <?php else: ?>
            <span class="mono" style="font-weight:700;color:<?= $c['available'] > 0 ? 'var(--t1)' : 'var(--red)' ?>;"><?=$c['available']?></span>
            <span class="mono" style="color:var(--t3);font-size:10px;">/<?=$c['total']?></span>
            <?php if ($c['out_of_service'] > 0): ?>
              <span class="mono" style="color:var(--amber);font-size:9.5px;" title="planned out of service">−<?=$c['out_of_service']?></span>
            <?php endif; ?>
            <?php if (!empty($c['stand_by'])): ?>
              <span class="mono" style="color:var(--blue);font-size:9.5px;" title="stand-by reserve — not in the availability %">R<?=$c['stand_by']?></span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!empty($units_by_day['__fleet__'])): ?>
      <tr style="border-top:2px solid var(--g2);background:var(--tint,var(--g1));">
        <td style="font-weight:800;color:var(--t1);">FLEET</td>
        <?php foreach ($days as $d): $c = $units_by_day['__fleet__'][$d]; ?>
        <td style="text-align:center;">
          <span class="mono" style="font-weight:800;"><?=$c['available']?></span>
          <span class="mono" style="color:var(--t3);font-size:10px;">/<?=$c['total']?></span>
          <?php if ($c['out_of_service'] > 0): ?>
            <span class="mono" style="color:var(--amber);font-size:9.5px;">−<?=$c['out_of_service']?></span>
          <?php endif; ?>
          <?php if (!empty($c['stand_by'])): ?>
            <span class="mono" style="color:var(--blue);font-size:9.5px;" title="stand-by reserve — not in the availability %">R<?=$c['stand_by']?></span>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── The machine grid — the workbook's own shape ───────────────────── -->
<?php foreach ($group_order as $gname): if (empty($by_group[$gname])) continue; ?>
<div class="card mb-3" style="overflow-x:auto;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;"><?=htmlspecialchars($gname)?> — <?=count($by_group[$gname])?> machines</div>
    <div class="cs">Planned hours per day<?= $jobs_ready ? ', with the job codes. Click a cell to see or edit its jobs' : '' ?><?= ($jobs_ready && !$frozen) ? ' (draft only)' : '' ?>.</div>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th>MACHINE</th><th>LOCATION</th>
        <?php foreach ($days as $d): ?>
        <th style="text-align:center;" title="<?=htmlspecialchars($d)?>"><?=$day_lbl[$d]?><br>
          <span style="font-weight:400;color:var(--t3);font-size:9.5px;"><?=date('d/m', strtotime($d))?></span></th>
        <?php endforeach; ?>
        <th style="text-align:right;">PM h</th>
        <th style="text-align:right;">AVAILABILITY</th>
        <th style="text-align:center;">STATUS</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($by_group[$gname] as $r): ?>
      <tr<?= $r['out_of_service'] ? ' style="background:rgba(180,83,9,0.05);"' : '' ?>>
        <td class="mono" style="font-weight:600;color:var(--blue);"><?=htmlspecialchars($r['registration_no'])?></td>
        <td style="color:var(--t3);font-size:10.5px;"><?=htmlspecialchars((string)$r['location'])?></td>
        <?php foreach ($days as $d):
          $cell = isset($r['daily'][$d]) ? $r['daily'][$d] : null;
          $co    = ($cell && isset($cell['corrective_hours'])) ? $cell['corrective_hours'] : 0;
          $total = $cell ? ($cell['pm_hours'] + $cell['standing_hours'] + $cell['unplanned_hours'] + $co + $cell['out_hours']) : 0;
          $ob    = isset($overbooked[$r['equipment_id'] . '|' . $d]);
          $cjobs = ($cell && !empty($cell['jobs'])) ? $cell['jobs'] : array();
          $tip = $cell
            ? sprintf('PM %s h · standing %s h · unplanned %s h · corrective %s h · out %s h',
                      $hrs($cell['pm_hours']), $hrs($cell['standing_hours']),
                      $hrs($cell['unplanned_hours']), $hrs($co), $hrs($cell['out_hours']))
            : 'no plan';
          foreach ($cjobs as $j) $tip .= "\n" . $j['job_type'] . ': ' . $j['title'] . ' (' . $hrs($j['hours_per_day']) . ' h)';
          if ($ob) $tip .= "\nOVERBOOKED: " . $overbooked[$r['equipment_id'] . '|' . $d]['message'];
          $codes = array();
          foreach ($cjobs as $j) $codes[] = isset($job_codes[$j['job_type']]) ? $job_codes[$j['job_type']] : $j['job_type'];
          $href = $jobs_ready ? base_url('forecast/jobs?week=' . urlencode($iso_label) . '&equipment_id='
                                         . (int)$r['equipment_id'] . '&date=' . $d) : null;
        ?>
        <td class="<?=$ob ? 'cell-overbooked' : ''?>" style="text-align:center;<?=$total > 0 ? 'background:rgba(192,57,43,' . min(0.28, $total / 24 * 0.9) . ');' : ''?><?=$ob ? 'outline:2px solid var(--red);outline-offset:-2px;' : ''?>"
            title="<?=htmlspecialchars($tip)?>">
          <?php if ($href): ?><a href="<?=$href?>" style="color:inherit;text-decoration:none;display:block;"><?php endif; ?>
          <?php if ($total > 0): ?>
            <span class="mono" style="font-size:10.5px;font-weight:600;<?=$ob ? 'color:var(--red);' : ''?>"><?=$hrs($total)?></span>
          <?php else: ?>
            <span style="color:var(--g3);">·</span>
          <?php endif; ?>
          <?php if ($codes): ?>
            <div class="job-codes" style="font-size:8.5px;font-weight:700;color:var(--t2);letter-spacing:.02em;"><?=htmlspecialchars(implode(' ', array_unique($codes)))?></div>
          <?php endif; ?>
          <?php if ($href): ?></a><?php endif; ?>
        </td>
        <?php endforeach; ?>
        <td class="mono" style="text-align:right;color:var(--t2);"><?=$hrs($r['pm_hours'])?></td>
        <td style="text-align:right;font-weight:700;color:<?=$col($r['availability'])?>;"><?=$pct($r['availability'])?></td>
        <td style="text-align:center;">
          <?php if ($r['out_of_service']): ?>
            <span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;color:var(--amber);background:var(--amber-d);">OUT OF SERVICE</span>
          <?php elseif (!empty($r['standby'])): ?>
            <span class="badge-standby" style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;color:var(--blue);background:var(--g2);" title="Reserve capacity — no standing allowance, and not in the availability %">STAND-BY</span>
          <?php elseif (!empty($r['out_days'])): ?>
            <span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;color:var(--amber);background:var(--amber-d);">OUT <?=(int)$r['out_days']?> DAY<?=$r['out_days'] == 1 ? '' : 'S'?></span>
          <?php elseif (!$r['has_plan']): ?>
            <span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:600;color:var(--t3);background:var(--g2);">NO PLAN</span>
          <?php else: ?>
            <span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;color:var(--green);background:var(--green-d);">PLANNED</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<?php if ($is_supervisor && !$frozen): ?>
<!-- ── Manual override ───────────────────────────────────────────────── -->
<div class="card" style="padding:16px 18px;">
  <div style="font-weight:700;font-size:12px;margin-bottom:4px;">Adjust a machine-day by hand</div>
  <div class="cs" style="font-size:11px;margin-bottom:12px;">
    A manual figure is recorded as <em>Manual Override</em> (D31) and the generators leave it alone.
  </div>
  <form method="post" action="<?= base_url('forecast/save_cell?week=' . urlencode($iso_label)) ?>"
        class="flex gap-10 ai-c" style="flex-wrap:wrap;">
    <?= csrf_field() ?>
    <select name="equipment_id" class="form-control" style="width:190px;" required>
      <option value="">Machine…</option>
      <?php foreach ($rows as $r): ?>
      <option value="<?=$r['equipment_id']?>"><?=htmlspecialchars($r['registration_no'])?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="plan_date" class="form-control" style="width:160px;"
           min="<?=$week_start?>" max="<?=$week_end?>" required>
    <select name="category" class="form-control" style="width:160px;">
      <option value="PM">PM</option>
      <option value="Standing">Standing</option>
      <option value="Unplanned">Unplanned</option>
      <option value="OutOfService">Out of service</option>
      <?php if ($jobs_ready): ?><option value="Corrective">Corrective (known repair)</option><?php endif; ?>
    </select>
    <input type="number" name="hours" class="form-control" style="width:100px;"
           step="0.5" min="0" max="24" placeholder="hours" required>
    <button class="btn btn-sm btn-primary" type="submit">Save</button>
  </form>
</div>
<?php endif; ?>

<div class="cs mt-4" style="font-size:11px;line-height:1.8;">
  <strong>How this figure is built.</strong>
  Required time = open hours − planned PM − planned out-of-service time, day by day;
  downtime = planned standing + unplanned<?= $jobs_ready ? ' + corrective (known repairs)' : '' ?>,
  never more than the day's required time (an overbooked day is flagged, not hidden);
  availability = <code>kpi_availability(required, downtime)</code> — the same function the weekly
  actual report uses (D29), so plan and actual are one metric. Groups and the fleet roll up with
  <code>kpi_rollup()</code>, capacity-weighted (D30). A machine planned out for the whole week is
  <em>Out of service</em>: excluded from availability, counted in the unit view above (D32).
  <?php if ($allowance_mode !== 'derived'): ?>
    The unplanned allowance is the flat setting; switch
    <code>unplanned_allowance_mode</code> to <code>derived</code> to use each machine's own
    measured loss rate instead.
  <?php endif; ?>
</div>

<?php endif; ?>
