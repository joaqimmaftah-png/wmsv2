<?php
/**
 * PM SPARE-PARTS FORECAST — task F10.
 *
 * The half of the workbook's forecast it never had: the plan's PM hours turned
 * into a parts bill, compared with the store and with what is already ordered.
 *
 *     required − on hand − on order = shortage
 *
 * Measured supplier lead times sit beside it, so "will it arrive in time?" is
 * answerable rather than hopeful.
 */
$num = function ($v) { return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.'); };
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">PM Spare-Parts Forecast</div>
    <div class="cs">What the PM plan needs, from <?=htmlspecialchars($iso_label)?> over <?= (int)$horizon ?> weeks</div>
  </div>
  <form method="get" class="flex ai-c gap-10">
    <label style="font-size:11px;color:var(--t3);">From week</label>
    <input type="week" name="week" class="form-control" style="width:170px;" value="<?=htmlspecialchars($iso_label)?>">
    <select name="weeks" class="form-control" style="width:110px;">
      <?php foreach (array(1,2,4,6,8,13) as $w): ?>
      <option value="<?=$w?>" <?=$w===(int)$horizon?'selected':''?>><?=$w?> wk</option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-primary" type="submit">Open</button>
  </form>
</div>

<?php if (!$ready): ?>
<div class="card" style="padding:34px;border-left:3px solid var(--amber);">
  <div style="font-weight:700;margin-bottom:6px;">No parts requirements on file</div>
  <div style="font-size:12px;color:var(--t2);line-height:1.7;">
    <?php if (empty($table_ready)): ?>
      The <code>pm_part_requirements</code> table does not exist on this database yet.
    <?php else: ?>
      Nothing links a PM schedule to the parts it consumes yet. Add rows to
      <code>pm_part_requirements</code> (<code>pm_schedule_id</code>, <code>material_id</code>,
      <code>qty</code>) and this page becomes the procurement list for the plan.
      Lubricants already have their own forecast from <code>lube_specifications</code>; this
      generalises that pattern to every other part.
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">PM services in the window</span>
    <div class="kpi-num"><?=$schedules?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;"><?=date('d M', strtotime($from))?> – <?=date('d M', strtotime($to))?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--purple,var(--blue))"></div>
    <span class="kpi-tag">Distinct parts required</span>
    <div class="kpi-num"><?=count($rows)?></div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:<?= $shortage_count > 0 ? 'var(--red)' : 'var(--green)' ?>"></div>
    <span class="kpi-tag">Short of stock</span>
    <div class="kpi-num" style="color:<?= $shortage_count > 0 ? 'var(--red)' : 'var(--green)' ?>;"><?=$shortage_count?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">after on-hand and on-order</div>
  </div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">PM services with no parts list</span>
    <div class="kpi-num"><?=$no_requirements?></div>
    <div class="cs" style="font-size:10px;margin-top:3px;">their parts are not forecast</div>
  </div>
</div>

<?php if ($no_requirements > 0): ?>
<?php
  /*
   * Shown whenever anything is uncosted, not only when something else made the
   * list.  It used to be gated on count($rows) > 0, so a workshop that had not
   * entered any parts bill at all — the state every installation starts in —
   * saw an empty table with no explanation of why.
   */
  $missing = isset($no_requirements_list) ? $no_requirements_list : array();
?>
<div class="card mb-4" style="padding:12px 18px;border-left:3px solid var(--amber);">
  <div style="font-size:11.5px;color:var(--t2);">
    <?=$no_requirements?> of <?=$schedules?> PM services in this window have no parts list, so
    their parts are missing from this forecast. The count is reported rather than hidden — a
    parts bill that looks complete but is not is worse than an obviously incomplete one.
  </div>
  <?php if ($missing): ?>
  <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px;">
    <?php foreach ($missing as $g): ?>
    <a href="<?= base_url('forecast/parts/' . (int)$g['pm_schedule_id']) ?>"
       class="btn btn-ghost btn-sm" style="font-size:11px;padding:4px 10px;"
       title="<?= $g['has_hours']
                  ? 'List the parts this service consumes'
                  : 'This service also has no standard hours, so it is not in the availability forecast either' ?>">
      <?=htmlspecialchars($g['registration_no'], ENT_QUOTES, 'UTF-8')?>
      · <?=htmlspecialchars($g['schedule_name'], ENT_QUOTES, 'UTF-8')?>
      <span class="text-300"><?= date('d M', strtotime($g['next_due_date'])) ?></span>
      <?php if (!$g['has_hours']): ?><span style="color:var(--amber);">· no hours</span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card mb-4" style="overflow-x:auto;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Parts requirement vs the store</div>
    <div class="cs">Critical parts and shortages first. Required − on hand − on order = shortage.</div>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th>ITEM</th><th>DESCRIPTION</th>
        <th style="text-align:right;">REQUIRED</th>
        <th style="text-align:right;">ON HAND</th>
        <th style="text-align:right;">ON ORDER</th>
        <th style="text-align:right;">SHORTAGE</th>
        <th style="text-align:center;">NEEDED BY</th>
        <th style="text-align:center;">STATUS</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr style="<?= $r['shortage'] > 0 ? 'background:rgba(192,57,43,0.04);' : '' ?>">
        <td class="mono" style="font-weight:600;color:var(--blue);">
          <?=htmlspecialchars((string)$r['item_code'])?>
          <?php if ($r['is_critical']): ?>
            <span style="margin-left:6px;padding:1px 5px;border-radius:4px;font-size:9px;font-weight:700;color:var(--red);background:var(--red-d);">CRITICAL</span>
          <?php endif; ?>
        </td>
        <td style="color:var(--t2);font-size:11px;"><?=htmlspecialchars((string)$r['description'])?></td>
        <td class="mono" style="text-align:right;font-weight:600;"><?=$num($r['required'])?> <span style="color:var(--t3);font-size:9.5px;"><?=htmlspecialchars((string)$r['unit'])?></span></td>
        <td class="mono" style="text-align:right;color:var(--t2);"><?=$num($r['on_hand'])?></td>
        <td class="mono" style="text-align:right;color:var(--t2);"><?=$num($r['on_order'])?></td>
        <td class="mono" style="text-align:right;font-weight:800;color:<?= $r['shortage'] > 0 ? 'var(--red)' : 'var(--t3)' ?>;"><?=$num($r['shortage'])?></td>
        <td style="font-size:10px;color:var(--t3);text-align:center;">
          <?=htmlspecialchars(implode(', ', array_slice($r['needed_by'], 0, 4)))?>
          <?= count($r['needed_by']) > 4 ? ' +' . (count($r['needed_by']) - 4) . ' more' : '' ?>
          <?php if (!empty($r['earliest'])): ?>
            <br><span style="color:var(--amber);font-weight:600;">first needed <?=date('d M', strtotime($r['earliest']))?></span>
          <?php endif; ?>
        </td>
        <td style="text-align:center;">
          <?php if ($r['covered']): ?>
            <span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;color:var(--green);background:var(--green-d);">COVERED</span>
          <?php else: ?>
            <span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;color:var(--red);background:var(--red-d);">ORDER</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="8" style="text-align:center;color:var(--t3);padding:26px;">
        No PM services fall due in this window.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if (!empty($lead_times)): ?>
<div class="card" style="overflow-x:auto;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Measured supplier lead times</div>
    <div class="cs">Actual delivery history — compare against each shortage's "first needed" date.</div>
  </div>
  <table class="tbl">
    <thead>
      <tr><th>ROUTE (COUNTRY)</th><th>SHIPPING METHOD</th>
          <th style="text-align:right;">AVG DAYS</th><th style="text-align:right;">ORDERS</th></tr>
    </thead>
    <tbody>
    <?php foreach ($lead_times as $lt): ?>
      <tr>
        <td><?=htmlspecialchars((string)$lt->country)?></td>
        <td style="color:var(--t2);"><?=htmlspecialchars((string)$lt->shipping_method)?></td>
        <td class="mono" style="text-align:right;font-weight:600;"><?=number_format((float)$lt->avg_days, 1)?></td>
        <td class="mono" style="text-align:right;color:var(--t3);"><?=$lt->n?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="cs mt-4" style="font-size:11px;line-height:1.8;">
  <strong>Why this page exists.</strong> The historical forecast planned 675 hours of PM across 44
  machines and never joined any of it to a part — so the store found out on Thursday. This is the
  same join <code>Lubricant_model::get_monthly_forecast()</code> already does for lubricants,
  generalised to every part, and compared with
  <code>Spareparts_model::get_on_order_by_material()</code> so an open order is not ordered twice.
  See <code>reports/reorder</code> for the reactive (min/max) view; this one is driven by the plan.
</div>

<?php endif; ?>
