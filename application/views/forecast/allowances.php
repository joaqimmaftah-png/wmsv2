<?php
/**
 * PLANNED ALLOWANCES — task F9, decision D31.
 *
 * The two allowances that drive most of any planned week, shown per machine
 * with their provenance, *before* they are written to the plan:
 *
 *   · Standing  — daily checks / standby. Per equipment TYPE (D31), replacing
 *                 the workbook's single 2 h/day for excavators and tower lights
 *                 alike. 51.0 % of the historical planned downtime was this.
 *   · Unplanned — breakdown allowance. Flat 1 h/day in the workbook on 85 of
 *                 103 machines; in `derived` mode it is each machine's own
 *                 measured loss rate from its corrective history (rule R4).
 */
$num = function ($v) { return rtrim((string)(rtrim((string)(number_format((float)$v, 2, '.', '')), '0')), '.'); };
$derived_n = 0;
foreach ($rows as $r) if ($r['source'] === 'derived') $derived_n++;
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Planned Allowances</div>
    <div class="cs">Standing and unplanned hours per machine — what the generators will write for <?=htmlspecialchars((string)($iso_label))?></div>
  </div>
  <form method="get" class="flex ai-c gap-10">
    <label style="font-size:11px;color:var(--t3);">Week</label>
    <input type="week" name="week" class="form-control" style="width:170px;" value="<?=htmlspecialchars((string)($iso_label))?>">
    <button class="btn btn-sm btn-primary" type="submit">Open</button>
  </form>
</div>

<div class="grid g-3 mb-4">
  <div class="kpi">
    <span class="kpi-tag">Unplanned allowance mode</span>
    <div class="kpi-num" style="font-size:20px;text-transform:uppercase;"><?=htmlspecialchars((string)($mode))?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">
      <?=$mode === 'derived'
        ? 'per machine, from its own corrective history'
        : 'one flat figure for every machine — switch to derived to use measured loss rates'?>
    </div>
  </div>
  <div class="kpi">
    <span class="kpi-tag">Machines with a derived rate</span>
    <div class="kpi-num"><?=$derived_n?><sup>/<?=count($rows)?></sup></div>
    <div class="cs" style="font-size:10px;margin-top:3px;"><?=$lookback?>-week lookback</div>
  </div>
  <div class="kpi">
    <span class="kpi-tag">Standing allowance configured</span>
    <div class="kpi-num"><?=max(0, count($standing) - 1)?><sup>types</sup></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">default <?=isset($standing['__default__']) ? $num($standing['__default__']) : '—'?> h/day</div>
  </div>
</div>

<!-- ── standing allowance by equipment type (D31) ────────────────────── -->
<div class="card mb-4" style="overflow-x:auto;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Standing allowance by equipment type</div>
    <div class="cs">
      Stored in <code>settings.standing_allowance_hours</code> as JSON keyed by type name.
      A type not listed uses the default.
    </div>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:0;border-top:1px solid var(--g2);">
    <?php $i = 0; $n = count($standing); foreach ($standing as $type => $h): $i++; ?>
    <div style="flex:1;min-width:130px;padding:12px 16px;border-right:1px solid var(--g2);<?=$i === $n ? 'border-right:none;' : ''?>">
      <div style="font-size:11px;color:var(--t3);margin-bottom:4px;">
        <?=$type === '__default__' ? '<em>default</em>' : htmlspecialchars((string)($type))?>
      </div>
      <div style="font-size:20px;font-weight:800;line-height:1;"><?=$num($h)?><span style="font-size:11px;font-weight:500;color:var(--t3);"> h/day</span></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── per machine ───────────────────────────────────────────────────── -->
<div class="card" style="overflow-x:auto;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Per-machine allowances</div>
    <div class="cs">What will be written to the plan. A planner's manual override is not touched by the generators.</div>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th>MACHINE</th><th>TYPE</th>
        <th style="text-align:right;">STANDING h/day</th>
        <th style="text-align:right;">UNPLANNED h/day</th>
        <th style="text-align:center;">SOURCE</th>
        <th style="text-align:center;">BREAKDOWNS</th>
        <th>DERIVATION</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono" style="font-weight:600;color:var(--blue);"><?=htmlspecialchars((string)($r['registration_no']))?></td>
        <td style="color:var(--t3);font-size:10.5px;"><?=htmlspecialchars((string)$r['type'])?></td>
        <td class="mono" style="text-align:right;color:var(--t2);"><?=$num($r['standing'])?></td>
        <td class="mono" style="text-align:right;font-weight:600;color:<?= $r['source'] === 'derived' ? 'var(--t1)' : 'var(--t3)' ?>;"><?=$num($r['unplanned'])?></td>
        <td style="text-align:center;">
          <?php if ($r['source'] === 'derived'): ?>
            <span class="badge b-info" style="font-size:9.5px;">DERIVED</span>
          <?php else: ?>
            <span class="badge b-secondary" style="font-size:9.5px;">FLAT</span>
          <?php endif; ?>
        </td>
        <td class="mono" style="text-align:center;color:var(--t3);"><?=$r['events'] ?: '—'?></td>
        <td style="font-size:10px;color:var(--t3);"><?=htmlspecialchars((string)($r['note']))?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="7" style="text-align:center;color:var(--t3);padding:26px;">No machines in the fleet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="cs mt-4" style="font-size:11px;line-height:1.8;">
  <strong>Why these two numbers matter.</strong> In the historical week-40 forecast, 966 of 1 894
  planned downtime hours (51.0 %) were the standing allowance and 253 (13.4 %) the unplanned one —
  together nearly two thirds of the plan, both typed as a single figure for every machine. Making
  the standing allowance per type (D31) and the unplanned allowance per machine's own measured loss
  rate (rule R4) is what turns the largest part of the forecast from an assumption into a measurement.
  <br>
  A derived rate is <strong>floored at 0.5 h/day</strong>: a machine that happened not to break in
  the lookback window is not forecast as incapable of breaking.
</div>
