<?php
/**
 * ROLLING THREE-WEEK MAINTENANCE PLAN — task F11.
 *
 * The database-driven replacement for the `WEEK 40 SCHEDULE` sheet, whose
 * week-41 and week-42 blocks are `#REF!` and whose block headers disagree with
 * their own week numbers.  Here every cell is read from
 * `equipment_planned_downtime`, so a stale formula reference cannot exist.
 */
$pct = function ($v, $d = 1) { return $v === null ? '—' : number_format($v * 100, $d) . '%'; };
$hrs = function ($v) { return $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); };
$col = function ($v) {
    if ($v === null) return 'var(--t3)';
    return $v >= 0.90 ? 'var(--green)' : ($v >= 0.70 ? 'var(--amber)' : 'var(--red)');
};
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Rolling Maintenance Plan</div>
    <div class="cs">Three weeks from <?=htmlspecialchars($base_label)?> — machine, planned hours, availability</div>
  </div>
  <div class="flex ai-c gap-10">
    <form method="get" class="flex ai-c gap-10">
      <label style="font-size:11px;color:var(--t3);">From week</label>
      <input type="week" name="week" class="form-control" style="width:170px;" value="<?=htmlspecialchars($base_label)?>">
      <button class="btn btn-sm btn-primary" type="submit">Open</button>
    </form>
    <a class="btn btn-sm" href="<?= base_url('forecast/export/rolling?week=' . urlencode($base_label)) ?>">
      Export XLSX
    </a>
  </div>
</div>

<!-- ── the three week headlines ──────────────────────────────────────── -->
<div class="grid g-3 mb-4">
  <?php foreach ($weeks as $w): $f = $w['plan']['fleet']; ?>
  <div class="kpi"><div class="kpi-glow" style="background:<?=$col($f['availability'])?>"></div>
    <span class="kpi-tag">
      <?=$w['offset'] === 0 ? 'This week' : '+' . $w['offset'] . ' week' . ($w['offset'] > 1 ? 's' : '')?>
      · <?=htmlspecialchars($w['iso_label'])?>
    </span>
    <div class="kpi-num" style="color:<?=$col($f['availability'])?>;"><?=$pct($f['availability'])?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">
      <?=date('d M', strtotime($w['week_start']))?> –
      <?=date('d M', strtotime($w['plan']['week_end']))?> ·
      <?=$w['plan']['unit']['available']?>/<?=$w['plan']['unit']['total']?> units ·
      <?=$hrs($f['pm_hours'])?> h PM
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── one row per machine, three weeks across ───────────────────────── -->
<div class="card" style="overflow-x:auto;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Fleet across the three weeks</div>
    <div class="cs">Planned downtime hours and the availability they produce. Out-of-service machines are flagged, not scored.</div>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th>MACHINE</th><th>TYPE</th><th>LOCATION</th>
        <?php foreach ($weeks as $w): ?>
        <th style="text-align:center;border-left:1px solid var(--g2);">
          <?=htmlspecialchars($w['iso_label'])?><br>
          <span style="font-weight:400;color:var(--t3);font-size:9.5px;"><?=date('d M', strtotime($w['week_start']))?></span>
        </th>
        <th style="text-align:right;">DT h</th>
        <th style="text-align:right;">AVAIL</th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($machines as $m): ?>
      <tr>
        <td class="mono" style="font-weight:600;color:var(--blue);"><?=htmlspecialchars($m['registration_no'])?></td>
        <td style="color:var(--t3);font-size:10.5px;"><?=htmlspecialchars((string)$m['type'])?></td>
        <td style="color:var(--t3);font-size:10.5px;"><?=htmlspecialchars((string)$m['location'])?></td>
        <?php foreach ($weeks as $w):
          $r = isset($m['weeks'][$w['offset']]) ? $m['weeks'][$w['offset']] : null;
          $dt = $r ? ($r['standing_hours'] + $r['unplanned_hours']) : null;
        ?>
        <td style="text-align:center;border-left:1px solid var(--g2);">
          <?php if (!$r): ?>
            <span style="color:var(--t3);">—</span>
          <?php elseif ($r['out_of_service']): ?>
            <span style="padding:2px 7px;border-radius:5px;font-size:9.5px;font-weight:700;color:var(--amber);background:var(--amber-d);">OUT</span>
          <?php else: ?>
            <span class="mono" style="font-size:10.5px;color:<?= $dt > 0 ? 'var(--t2)' : 'var(--g3)' ?>;"><?=$hrs($dt)?></span>
          <?php endif; ?>
        </td>
        <td class="mono" style="text-align:right;color:var(--t3);font-size:10.5px;"><?=$hrs($dt)?></td>
        <td style="text-align:right;font-weight:600;color:<?=$col($r ? $r['availability'] : null)?>;font-size:11px;">
          <?= $r && !$r['out_of_service'] ? $pct($r['availability']) : '—' ?>
        </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="cs mt-4" style="font-size:11px;">
  A week with no published plan shows its current draft. Publish each week on
  <a href="<?= base_url('forecast') ?>">Planned Availability</a> to freeze it.
</div>
