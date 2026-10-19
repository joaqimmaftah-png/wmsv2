<?php
/**
 * PARTS A PM SERVICE CONSUMES — FORECAST_REVIEW.md task F10.
 *
 * The entry path for `pm_part_requirements`. The spares forecast reads these
 * rows; without a way to write them the forecast can only ever report that
 * nothing is configured.
 *
 * `qty` is per ONE occurrence of the schedule — the forecast multiplies by the
 * occurrences in the window, so entering a total here would multiply twice.
 */
$role_id       = isset($role_id) ? (int)$role_id : 0;
$is_supervisor = $role_id === 1;
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Parts — <?=htmlspecialchars($schedule->schedule_name)?></div>
    <div class="cs">
      <?=htmlspecialchars(isset($schedule->registration_no) ? (string)$schedule->registration_no : '—')?>
      · every <?=htmlspecialchars((string)$schedule->interval_value)?> <?=htmlspecialchars((string)$schedule->interval_type)?>
      <?php if (!empty($schedule->next_due_date)): ?>
        · next due <?=date('d M Y', strtotime($schedule->next_due_date))?>
      <?php endif; ?>
    </div>
  </div>
  <a class="btn btn-sm" href="<?= base_url('pm') ?>">Back to the PM planner</a>
</div>

<?php if (!$table_ready): ?>
<div class="card" style="padding:34px;border-left:3px solid var(--amber);">
  <div style="font-weight:700;margin-bottom:6px;">Migration 008 has not been applied</div>
  <div style="font-size:12px;color:var(--t2);line-height:1.7;">
    The <code>pm_part_requirements</code> table does not exist yet, so there is nowhere to store
    this. Run <code>migrations/008_create_forecast_tables.sql</code> first.
  </div>
</div>
<?php else: ?>

<div class="card mb-4" style="padding:14px 18px;border-left:3px solid var(--blue);">
  <div style="font-size:11.5px;color:var(--t2);line-height:1.8;">
    <strong>Quantity is per one occurrence of this service</strong>, not per week. A 250-hour
    service taking four filters is <strong>4</strong> — the forecast multiplies by however many
    times the service falls due in the window it is looking at. Entering a total here would count
    it twice.
  </div>
</div>

<!-- ── what this service consumes ────────────────────────────────────── -->
<div class="card mb-4" style="overflow-x:auto;">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Parts list</div>
    <div class="cs"><?=count($requirements)?> part<?=count($requirements) === 1 ? '' : 's'?> recorded for this service</div>
  </div>
  <table class="tbl">
    <thead>
      <tr>
        <th>ITEM</th><th>DESCRIPTION</th>
        <th style="text-align:right;">QTY / SERVICE</th>
        <th style="text-align:right;">ON HAND</th>
        <th style="text-align:center;">CRITICAL</th>
        <th>NOTE</th>
        <?php if ($is_supervisor): ?><th style="width:1%;"></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($requirements as $r): ?>
      <tr>
        <td class="mono" style="font-weight:600;color:var(--blue);">
          <?=htmlspecialchars((string)$r['item_code'])?>
        </td>
        <td style="color:var(--t2);font-size:11px;"><?=htmlspecialchars((string)$r['description'])?></td>
        <td class="mono" style="text-align:right;font-weight:700;">
          <?=rtrim(rtrim(number_format($r['qty'], 3, '.', ''), '0'), '.')?>
          <span style="color:var(--t3);font-size:9.5px;"><?=htmlspecialchars((string)$r['unit'])?></span>
        </td>
        <td class="mono" style="text-align:right;color:<?= $r['on_hand'] < $r['qty'] ? 'var(--red)' : 'var(--t3)' ?>;">
          <?=rtrim(rtrim(number_format($r['on_hand'], 3, '.', ''), '0'), '.')?>
        </td>
        <td style="text-align:center;">
          <?php if ($r['is_critical']): ?>
            <span style="padding:2px 7px;border-radius:5px;font-size:9.5px;font-weight:700;color:var(--red);background:var(--red-d);">CRITICAL</span>
          <?php else: ?>
            <span style="color:var(--t3);">—</span>
          <?php endif; ?>
        </td>
        <td style="font-size:10.5px;color:var(--t3);"><?=htmlspecialchars((string)$r['note'])?></td>
        <?php if ($is_supervisor): ?>
        <td>
          <form method="post" action="<?= base_url('forecast/delete_part') ?>"
                onsubmit="return confirm('Remove <?=htmlspecialchars((string)$r['item_code'], ENT_QUOTES)?> from this service?');">
            <?= csrf_field() ?>
            <input type="hidden" name="pm_schedule_id" value="<?= (int)$schedule->id ?>">
            <input type="hidden" name="requirement_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-ghost" type="submit" style="color:var(--red);">Remove</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$requirements): ?>
      <tr><td colspan="<?=$is_supervisor ? 7 : 6?>" style="text-align:center;color:var(--t3);padding:26px;">
        No parts recorded. Until this service has a parts list it is counted as a gap on
        <a href="<?= base_url('forecast/spares') ?>" style="color:var(--blue);">the spares forecast</a>.
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- ── add or change one ─────────────────────────────────────────────── -->
<?php if ($is_supervisor): ?>
<div class="card" style="padding:16px 18px;">
  <div class="ct" style="font-size:12px;margin-bottom:12px;">Add a part</div>
  <form method="post" action="<?= base_url('forecast/save_part') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="pm_schedule_id" value="<?= (int)$schedule->id ?>">

    <div class="form-grid cols-2" style="gap:14px;">
      <div>
        <label class="form-label">Part</label>
        <select name="material_id" class="form-select" required>
          <option value="">— choose a part —</option>
          <?php foreach ($materials as $mat): ?>
          <option value="<?= (int)$mat->id ?>">
            <?=htmlspecialchars($mat->item_code . ' — ' . $mat->description)?>
            (<?= (int)$mat->final_qty ?> on hand)
          </option>
          <?php endforeach; ?>
        </select>
        <div class="cs" style="margin-top:4px;">
          Choosing a part already on this list updates its quantity rather than adding a second line.
        </div>
      </div>
      <div>
        <label class="form-label">Quantity per service</label>
        <input type="number" name="qty" class="form-control" min="0.001" step="0.001" value="1" required>
        <div class="cs" style="margin-top:4px;">Per one occurrence of this service.</div>
      </div>
    </div>

    <div class="form-grid cols-2" style="gap:14px;margin-top:14px;">
      <div>
        <label class="form-label">Note <span style="color:var(--t3);font-weight:400;">(optional)</span></label>
        <input type="text" name="note" class="form-control" maxlength="255"
               placeholder="e.g. change at every 250 h service">
      </div>
      <div style="display:flex;align-items:flex-end;padding-bottom:6px;">
        <label style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer;">
          <input type="checkbox" name="is_critical" value="1">
          <span>Critical — the service cannot proceed without it</span>
        </label>
      </div>
    </div>

    <div class="flex gap-10" style="justify-content:flex-end;margin-top:16px;">
      <button class="btn btn-sm btn-primary" type="submit">Save part</button>
    </div>
  </form>
</div>
<?php else: ?>
<div class="card" style="padding:14px 18px;">
  <div class="cs" style="font-size:11.5px;">
    You have view-only access. A supervisor records the parts a service consumes.
  </div>
</div>
<?php endif; ?>

<div class="cs mt-4" style="font-size:11px;line-height:1.8;">
  These rows feed <a href="<?= base_url('forecast/spares') ?>" style="color:var(--blue);">Forecast &rsaquo;
  PM Spare-Parts Forecast</a>, which turns them into
  <em>required − on hand − on order = shortage</em> for whatever window the plan covers.
  A service with no parts list is counted and shown as a gap there, so an incomplete bill is never
  mistaken for a complete one.
</div>

<?php endif; ?>
