<?php
/**
 * PLAN vs ACTUAL — task F6.
 *
 * The learning loop: Plan → Execute → Measure → Explain variance → Improve.
 *
 * The plan side is the FROZEN baseline (D33), never the current draft, so a
 * variance cannot be argued away by editing the plan after the week.  Both
 * sides are `kpi_availability()` on required time (D29), so the variance is a
 * real difference and not a formula artefact like the workbook's typed
 * constant.
 *
 * The variance is also split into its causes, which is the part the Excel
 * workbook could never do: it had no history to compare against.
 */
$pct = function ($v, $d = 2) { return $v === null ? '—' : number_format($v * 100, $d) . '%'; };
$hrs = function ($v) { return $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); };
$col = function ($v) {
    if ($v === null) return 'var(--t3)';
    return $v >= 0.90 ? 'var(--green)' : ($v >= 0.70 ? 'var(--amber)' : 'var(--red)');
};
/** Variance colour: positive = beat the plan, negative = missed it. */
$vcol = function ($v) {
    if ($v === null) return 'var(--t3)';
    if ($v >  0.5) return 'var(--green)';
    if ($v < -0.5) return 'var(--red)';
    return 'var(--t3)';
};
$signed = function ($v) {
    if ($v === null) return '—';
    return ($v > 0 ? '+' : '') . number_format($v, 2);
};
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Plan vs Actual — <?=htmlspecialchars($iso_label)?></div>
    <div class="cs"><?=date('d M Y', strtotime($week_start))?> – <?=date('d M Y', strtotime($week_end))?></div>
  </div>
  <form method="get" class="flex ai-c gap-10">
    <label style="font-size:11px;color:var(--t3);">Week</label>
    <input type="week" name="week" class="form-control" style="width:170px;" value="<?=htmlspecialchars($iso_label)?>">
    <button class="btn btn-sm btn-primary" type="submit">Open</button>
  </form>
</div>

<?php if (!$published): ?>
<div class="card" style="padding:44px;text-align:center;">
  <div style="font-weight:700;margin-bottom:8px;">No published plan for <?=htmlspecialchars($iso_label)?></div>
  <div class="cs" style="font-size:12px;line-height:1.8;max-width:520px;margin:0 auto;">
    A week has to be <strong>published</strong> before it can be compared — the plan is frozen at
    publication so the variance measures what was actually committed, not what the draft says now.
    <?php if (!empty($error)): ?><br><span style="color:var(--red);"><?=htmlspecialchars($error)?></span><?php endif; ?>
  </div>
  <a class="btn btn-sm btn-primary mt-4" href="<?= base_url('forecast?week=' . urlencode($iso_label)) ?>">
    Go to the plan
  </a>
</div>
<?php else: ?>

<div class="cs mb-4" style="font-size:11px;">
  Compared against the <strong>frozen baseline</strong> — revision <?=htmlspecialchars((string)$revision)?>,
  published <?= $published_at ? date('d M Y H:i', strtotime($published_at)) : '(timestamp not recorded)' ?>.
  <?php if ($actual_missing): ?>
    <span style="color:var(--amber);">The weekly actual could not be read for this week, so the actual
    column is blank.</span>
  <?php endif; ?>
</div>

<!-- ── fleet headline ────────────────────────────────────────────────── -->
<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Planned fleet availability</span>
    <div class="kpi-num"><?=$pct($fleet['planned'])?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:<?=$col($fleet['actual'])?>"></div>
    <span class="kpi-tag">Actual fleet availability</span>
    <div class="kpi-num" style="color:<?=$col($fleet['actual'])?>;"><?=$pct($fleet['actual'])?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:<?=$vcol($fleet['variance_pp'])?>"></div>
    <span class="kpi-tag">Variance</span>
    <div class="kpi-num" style="color:<?=$vcol($fleet['variance_pp'])?>;"><?=$signed($fleet['variance_pp'])?><sup>pp</sup></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">
      <?=$fleet['variance_pp'] === null ? 'no actual yet'
        : ($fleet['variance_pp'] >= 0 ? 'better than planned' : 'worse than planned')?>
    </div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Fleet available to production</span>
    <div class="kpi-num"><?=$unit['available']?><sup>/<?=$unit['total']?> units</sup></div>
    <div class="cs" style="font-size:10px;margin-top:3px;"><?=$unit['out_of_service']?> planned out of service</div>
  </div>
</div>

<!-- ── per group, with the variance explained ────────────────────────── -->
<div class="card mb-3" style="overflow-x:auto;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Variance by group, worst first</div>
    <div class="cs">A negative variance is a miss. The last two columns say whether it was PM over-run or unplanned loss.</div>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th>GROUP</th>
        <th style="text-align:center;">MACHINES</th>
        <th style="text-align:right;">PLANNED</th>
        <th style="text-align:right;">ACTUAL</th>
        <th style="text-align:right;">VARIANCE</th>
        <th style="text-align:right;">PM h<br><span style="font-weight:400;color:var(--t3);font-size:9px;">plan → actual</span></th>
        <th style="text-align:right;">PM VARIANCE h</th>
        <th style="text-align:right;">DOWNTIME h<br><span style="font-weight:400;color:var(--t3);font-size:9px;">plan → actual</span></th>
        <th style="text-align:right;">UNPLANNED VARIANCE h</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($groups as $g): ?>
      <tr>
        <td style="font-weight:600;color:var(--t1);"><?=htmlspecialchars($g['group'])?></td>
        <td style="text-align:center;"><?=$g['machines'] ?? '—'?></td>
        <td style="text-align:right;color:var(--t2);"><?=$pct($g['planned_availability'])?></td>
        <td style="text-align:right;font-weight:600;color:<?=$col($g['actual_availability'])?>;"><?=$pct($g['actual_availability'])?></td>
        <td style="text-align:right;font-weight:800;color:<?=$vcol($g['variance_pp'])?>;">
          <?=$signed($g['variance_pp'])?><?=$g['variance_pp'] === null ? '' : ' pp'?>
        </td>
        <td class="mono" style="text-align:right;color:var(--t3);font-size:10.5px;">
          <?=$hrs($g['planned_pm_hours'])?> → <?=$hrs($g['actual_pm_hours'])?>
        </td>
        <td class="mono" style="text-align:right;font-weight:600;<?= $g['pm_variance_hours'] > 0 ? 'color:var(--red);' : 'color:var(--t3);' ?>">
          <?=$signed($g['pm_variance_hours'])?>
        </td>
        <td class="mono" style="text-align:right;color:var(--t3);font-size:10.5px;">
          <?=$hrs($g['planned_downtime_hours'])?> → <?=$hrs($g['actual_downtime_hours'])?>
        </td>
        <td class="mono" style="text-align:right;font-weight:600;<?= $g['unplanned_variance_hours'] > 0 ? 'color:var(--red);' : 'color:var(--t3);' ?>">
          <?=$signed($g['unplanned_variance_hours'])?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$groups): ?>
      <tr><td colspan="9" style="text-align:center;color:var(--t3);padding:26px;">
        The published plan has no group rows.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="cs" style="font-size:11px;line-height:1.8;">
  <strong>Reading this page.</strong>
  <em>PM variance</em> is actual preventive labour minus planned PM hours — a positive number means
  services over-ran, which is a planning estimate to correct.
  <em>Unplanned variance</em> is actual downtime minus planned downtime — a positive number means the
  fleet broke down more than forecast, which is a reliability signal, not a planning one.
  Separating the two is the point: the historical workbook could report that a group missed its
  figure but had no way to say why.
  <br>
  One caveat, stated rather than hidden: the actuals never charge daily-check ("standing") time,
  because nothing records it. A plan that charges it will read low against its actual by roughly
  that amount — look at the unplanned column, not the total, for the reliability signal.
</div>

<?php endif; ?>
