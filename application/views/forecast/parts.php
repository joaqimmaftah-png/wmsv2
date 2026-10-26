<?php
/**
 * PARTS A PM SERVICE CONSUMES — task F10.
 *
 * The entry path for `pm_part_requirements`. The spares forecast reads these
 * rows; without a way to write them the forecast can only ever report that
 * nothing is configured.
 *
 * `qty` is per ONE occurrence of the schedule. The current spares preview
 * counts one occurrence for each active schedule whose recorded next_due_date
 * falls in the window; it does not infer later recurring dates from the interval or meter.
 */
$role_id       = isset($role_id) ? (int)$role_id : 0;
$is_supervisor = $role_id === 1;
$materials     = isset($materials) && is_array($materials) ? $materials : array();
$materials_ready = isset($materials_ready) ? (bool)$materials_ready : true;
$parts_h = function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$inventory_url = function ($row) {
    return base_url('materials/inventory?' . http_build_query(array(
        'q'    => (string)$row['item_code'],
        'item' => (int)$row['material_id'],
    )));
};
?>

<style>
  .parts-sr-only { position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important; }
  .parts-table-wrap { overflow-x:auto; }
  .parts-table { min-width:860px; }
  .parts-table th:first-child,.parts-table td:first-child { position:sticky;left:0;z-index:1;background:var(--surface); }
  .parts-table thead th:first-child { z-index:2;background:var(--surface-2); }
  .parts-item-link { color:var(--blue);text-decoration:none; }
  .parts-item-link:hover { text-decoration:underline; }
  .parts-item-link:focus-visible { outline:2px solid var(--blue);outline-offset:2px;border-radius:2px; }
  .parts-form-grid { gap:14px; }
  .parts-search { margin-top:6px; }
  .parts-no-match { margin-top:6px;color:var(--t3);font-size:11px; }
  @media (max-width:760px) {
    .parts-form-grid { grid-template-columns:1fr; }
    .parts-table { min-width:780px; }
  }
</style>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="page-heading" style="font-size:16px;">Parts — <?=$parts_h($schedule->schedule_name)?></h1>
    <div class="cs">
      <?=$parts_h(isset($schedule->registration_no) ? $schedule->registration_no : '—')?>
      · every <?=$parts_h($schedule->interval_value)?> <?=$parts_h($schedule->interval_type)?>
      <?php if (!empty($schedule->next_due_date)): ?>
        · next due <?=date('d M Y', strtotime($schedule->next_due_date))?>
      <?php endif; ?>
    </div>
  </div>
  <a class="btn btn-sm" href="<?=base_url('pm')?>">Back to the PM planner</a>
</div>

<?php if (!$table_ready): ?>
<div class="card" style="padding:34px;border-left:3px solid var(--amber);">
  <div style="font-weight:700;margin-bottom:6px;">The forecast tables are not set up yet</div>
  <div style="font-size:12px;color:var(--t2);line-height:1.7;">
    The <code>pm_part_requirements</code> table does not exist yet, so there is nowhere to store
    this. Import the database dump shipped with this repository first.
  </div>
</div>
<?php else: ?>

<div class="card mb-4" style="padding:14px 18px;border-left:3px solid var(--blue);">
  <div style="font-size:11.5px;color:var(--t2);line-height:1.8;">
    <strong>Quantity is per one occurrence of this service</strong>, not per week. A 250-hour
    service taking four filters is <strong>4</strong>. The current spares preview includes one
    occurrence for each active schedule whose recorded next due date falls in the selected window;
    it does not estimate additional recurring due dates from the schedule interval or meter. Enter
    the per-service quantity, not a window total.
  </div>
</div>

<!-- ── what this service consumes ────────────────────────────────────── -->
<div class="card mb-4 parts-table-wrap">
  <div class="ch" style="padding:12px 18px 10px;">
    <div class="ct" style="font-size:12px;">Parts list</div>
    <div class="cs"><?=count($requirements)?> part<?=count($requirements) === 1 ? '' : 's'?> recorded for this service</div>
  </div>
  <table class="tbl parts-table">
    <caption class="parts-sr-only">Parts configured for <?=$parts_h($schedule->schedule_name)?></caption>
    <thead>
      <tr>
        <th scope="col">ITEM</th><th scope="col">DESCRIPTION</th>
        <th scope="col" style="text-align:right;">QTY / SERVICE</th>
        <th scope="col" style="text-align:right;">ON HAND</th>
        <th scope="col" style="text-align:center;">CRITICAL</th>
        <th scope="col">NOTE</th>
        <?php if ($is_supervisor): ?><th scope="col" style="width:1%;">ACTIONS</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($requirements as $r): ?>
      <?php $unit = isset($r['unit']) ? trim((string)$r['unit']) : ''; ?>
      <tr>
        <td class="mono" style="font-weight:600;">
          <?php if ((int)$r['material_id'] > 0 && !empty($r['item_code'])): ?>
            <a class="parts-item-link" href="<?=$parts_h($inventory_url($r))?>"
               aria-label="View inventory for <?=$parts_h($r['item_code'])?>">
              <?=$parts_h($r['item_code'])?>
            </a>
          <?php else: ?>
            <?=$parts_h($r['item_code'] !== null ? $r['item_code'] : 'Unavailable')?>
          <?php endif; ?>
        </td>
        <td style="color:var(--t2);font-size:11px;"><?=$parts_h($r['description'])?></td>
        <td class="mono" style="text-align:right;font-weight:700;">
          <?=rtrim(rtrim((string)(number_format((float)$r['qty'], 3, '.', '')), '0'), '.')?>
          <?php if ($unit !== ''): ?><span style="color:var(--t3);font-size:9.5px;"> <?=$parts_h($unit)?></span><?php endif; ?>
        </td>
        <td class="mono" style="text-align:right;color:var(--t2);">
          <?=rtrim(rtrim((string)(number_format((float)$r['on_hand'], 3, '.', '')), '0'), '.')?>
          <?php if ($unit !== ''): ?><span style="color:var(--t3);font-size:9.5px;"> <?=$parts_h($unit)?></span><?php endif; ?>
        </td>
        <td style="text-align:center;">
          <?php if ($r['is_critical']): ?>
            <span class="badge b-critical">CRITICAL</span>
          <?php else: ?>
            <span style="color:var(--t3);">—</span>
          <?php endif; ?>
        </td>
        <td style="font-size:10.5px;color:var(--t3);"><?=$parts_h($r['note'])?></td>
        <?php if ($is_supervisor): ?>
        <td>
          <form method="post" action="<?=base_url('forecast/delete_part')?>"
                onsubmit="return confirm('Remove this part from the service?');">
            <?=csrf_field()?>
            <input type="hidden" name="pm_schedule_id" value="<?=(int)$schedule->id?>">
            <input type="hidden" name="requirement_id" value="<?=(int)$r['id']?>">
            <button class="btn btn-sm btn-ghost" type="submit" aria-label="Remove <?=$parts_h($r['item_code'])?> from this service" style="color:var(--red);">Remove</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$requirements): ?>
      <tr><td colspan="<?=$is_supervisor ? 7 : 6?>" style="text-align:center;color:var(--t3);padding:26px;">
        No parts recorded. Until this service has a parts list it is counted as a gap on
        <a href="<?=base_url('forecast/spares')?>" style="color:var(--blue);">the spares forecast</a>.
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<div class="cs mb-4" style="font-size:11px;line-height:1.7;">
  On-hand values show current inventory. Where a material unit is defined, it appears beside both quantities.
  Comparing stock with one service’s requirement is a quick reference, not the forecast-window shortage; the
  spares forecast accounts for open orders when it calculates shortage.
</div>

<!-- ── add or change one ─────────────────────────────────────────────── -->
<?php if ($is_supervisor): ?>
<div class="card" style="padding:16px 18px;">
  <div class="ct" style="font-size:12px;margin-bottom:12px;">Add a part</div>
  <?php if (!$materials_ready): ?>
    <div class="cs" role="status" style="font-size:11.5px;line-height:1.7;">
      The inventory catalogue is not available right now, so a part cannot be selected. Contact an administrator.
    </div>
  <?php elseif (!$materials): ?>
    <div class="cs" role="status" style="font-size:11.5px;line-height:1.7;">
      No inventory items are available to choose. Add the item in
      <a href="<?=base_url('materials/inventory')?>" style="color:var(--blue);">Inventory</a> first.
    </div>
  <?php else: ?>
  <form method="post" action="<?=base_url('forecast/save_part')?>">
    <?=csrf_field()?>
    <input type="hidden" name="pm_schedule_id" value="<?=(int)$schedule->id?>">

    <div class="form-grid cols-2 parts-form-grid">
      <div>
        <div id="parts-search-controls" hidden>
          <label class="form-label" for="parts-material-search">Search available parts</label>
          <input type="search" id="parts-material-search" class="form-control parts-search"
                 placeholder="Item code or description" autocomplete="off" aria-controls="parts-material-id">
        </div>
        <label class="form-label" for="parts-material-id" style="margin-top:10px;">Part</label>
        <select id="parts-material-id" name="material_id" class="form-select" required>
          <option value="">— choose a part —</option>
          <?php foreach ($materials as $mat): ?>
          <?php $mat_unit = isset($mat->unit) ? trim((string)$mat->unit) : ''; ?>
          <option value="<?=(int)$mat->id?>">
            <?=$parts_h($mat->item_code . ' — ' . $mat->description)?>
            (<?=(int)$mat->final_qty?><?php if ($mat_unit !== ''): ?> <?=$parts_h($mat_unit)?><?php endif; ?> on hand)
          </option>
          <?php endforeach; ?>
        </select>
        <div id="parts-no-match" class="parts-no-match" role="status" aria-live="polite" hidden>
          No inventory items match that search. Clear the search to see all parts.
        </div>
        <div class="cs" style="margin-top:4px;">
          Choosing a part already on this list updates its quantity rather than adding a second line.
        </div>
      </div>
      <div>
        <label class="form-label" for="parts-qty">Quantity per service</label>
        <input id="parts-qty" type="number" name="qty" class="form-control" min="0.001" step="0.001" value="1" required>
        <div class="cs" style="margin-top:4px;">Per one occurrence of this service.</div>
      </div>
    </div>

    <div class="form-grid cols-2 parts-form-grid" style="margin-top:14px;">
      <div>
        <label class="form-label" for="parts-note">Note <span style="color:var(--t3);font-weight:400;">(optional)</span></label>
        <input id="parts-note" type="text" name="note" class="form-control" maxlength="255"
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
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card" style="padding:14px 18px;">
  <div class="cs" style="font-size:11.5px;">
    You have view-only access. A supervisor records the parts a service consumes.
  </div>
</div>
<?php endif; ?>

<div class="cs mt-4" style="font-size:11px;line-height:1.8;">
  These rows feed <a href="<?=base_url('forecast/spares')?>" style="color:var(--blue);">Forecast &rsaquo;
  PM Spare-Parts Forecast</a>, which turns them into
  <em>required − on hand − on order = shortage</em> for active PM schedules whose recorded next due dates fall in the selected window.
  A service with no parts list is counted and shown as a gap there, so an incomplete bill is never
  mistaken for a complete one.
</div>

<script>
(function () {
  var controls = document.getElementById('parts-search-controls');
  var search = document.getElementById('parts-material-search');
  var select = document.getElementById('parts-material-id');
  var noMatch = document.getElementById('parts-no-match');
  if (!controls || !search || !select || !noMatch) return;
  controls.hidden = false;

  search.addEventListener('input', function () {
    var query = search.value.trim().toLocaleLowerCase();
    var visible = 0;
    Array.prototype.forEach.call(select.options, function (option, index) {
      if (index === 0) return;
      var matches = option.textContent.toLocaleLowerCase().indexOf(query) !== -1;
      option.hidden = !matches;
      if (matches) visible++;
      if (option.selected && !matches) select.value = '';
    });
    noMatch.hidden = visible > 0;
  });
}());
</script>

<?php endif; ?>
