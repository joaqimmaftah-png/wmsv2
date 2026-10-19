<?php
/*
 * Availability targets — plan tasks W0.4 (screen) and W1.5 (fallback chain).
 *
 * One screen answers the two questions the business asked for:
 *   · what availability target applies to each machine, each day?
 *   · how do I set a different target for one single day?
 *
 * Nothing on this page is hard-coded: the week comes from
 * settings.report_week_start_day, the fallback from
 * settings.default_availability_target, the group list from the report_groups
 * table and every value shown is resolved by Kpi_target_model.
 */

// every POST form must carry the CSRF token (`config.php`: csrf_protection = TRUE);
// the controller passes it in — and `csrf_field()` (application/helpers) falls
// back to the security class, so the view also renders outside the application
$csrf_token = isset($csrf_token) ? (string)$csrf_token : '';
$csrf_name  = isset($csrf_name) && $csrf_name !== '' ? (string)$csrf_name : null;
$csrf_field = function () use ($csrf_token, $csrf_name) { return csrf_field($csrf_token, $csrf_name); };
$week_start   = isset($week_start) ? $week_start : date('Y-m-d');
$week_end     = isset($week_end) ? $week_end : date('Y-m-d', strtotime($week_start . ' +6 days'));
$days         = isset($days) ? $days : array();
$machines     = isset($machines) ? $machines : array();
$groups       = isset($groups) ? $groups : array();
$target_rows  = isset($target_rows) ? $target_rows : array();
$coverage     = isset($coverage) ? $coverage : array();
$resolved     = isset($resolved) ? $resolved : array();
$can_edit     = !empty($can_edit);
$has_table    = !empty($has_table);
$fallback     = isset($fallback_target) ? $fallback_target : null;
$edit_row     = isset($edit_row) ? $edit_row : null;
$prev_week    = date('Y-m-d', strtotime($week_start . ' -7 days'));
$next_week    = date('Y-m-d', strtotime($week_start . ' +7 days'));
$report_name  = isset($report_name) && $report_name !== '' ? $report_name : 'W'.(int)date('W', strtotime($week_start));

$pct = function ($v) { return $v === null || $v === '' ? '—' : number_format(((float)$v) * 100, 2, '.', '') . ' %'; };
$src_short = array(
    'equipment-day'     => 'machine · day',
    'equipment-default' => 'machine',
    'group-day'         => 'group · day',
    'group-default'     => 'group',
    'settings'          => 'default',
);
?>
<style>
  .tgt-wrap  { max-width: 1180px; margin: 0 auto; }
  .tgt-bar   { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
  .tgt-count { display:flex; gap:10px; flex-wrap:wrap; margin:14px 0 18px; }
  .tgt-chip  { background:rgba(255,255,255,.05); border:1px solid var(--sep); border-radius:10px; padding:9px 13px;
               font-size:11.5px; color:var(--t3); }
  .tgt-chip b { color:var(--t1); font-weight:600; }
  .tgt-table { width:100%; border-collapse:collapse; font-size:12px; }
  .tgt-table th { text-align:left; font-size:9.5px; letter-spacing:.9px; text-transform:uppercase; color:var(--t4);
                  padding:9px 8px; border-bottom:1px solid var(--sep); white-space:nowrap; }
  .tgt-table td { padding:8px; border-bottom:1px solid var(--sep); color:var(--t2); vertical-align:middle; }
  .tgt-table tr:last-child td { border-bottom:none; }
  .tgt-num   { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .tgt-strong{ color:var(--t1); font-weight:600; }
  .tgt-day   { text-align:center; }
  .tgt-src   { display:block; font-size:9px; color:var(--t4); letter-spacing:.3px; }
  .tgt-pill  { display:inline-block; padding:2px 7px; border-radius:20px; font-size:10px; font-weight:600;
               background:rgba(255,255,255,.06); color:var(--t3); }
  .tgt-pill.grp { background:rgba(0,113,227,.14); color:#5aa9ff; }
  .tgt-pill.eq  { background:rgba(28,140,60,.16);  color:#4fd07f; }
  .tgt-form  { display:grid; gap:12px; }
  .tgt-form .row { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; align-items:end; }
  .tgt-hint  { font-size:10.5px; color:var(--t4); margin-top:4px; }
  .tgt-warn  { background:rgba(192,57,43,.10); border:1px solid rgba(192,57,43,.35); color:#ff9c8f;
               border-radius:10px; padding:12px 14px; font-size:12px; margin-bottom:16px; }
  .tgt-scroll{ max-height:520px; overflow:auto; border:1px solid var(--sep); border-radius:10px; }
</style>

<div class="tgt-wrap">

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Availability Targets</div>
        <div class="cs"><?= htmlspecialchars($report_name) ?> — daily target per machine and per group, with single-day overrides</div>
      </div>
      <div class="tgt-bar" style="margin:0;">
        <a class="btn btn-sm" href="<?= base_url('reports/weekly_targets?week_start=' . $prev_week) ?>">&larr; Previous week</a>
        <form method="get" action="<?= base_url('reports/weekly_targets') ?>" style="display:flex;gap:8px;align-items:center;">
          <input type="date" name="week_start" class="form-control" style="width:160px;" value="<?= htmlspecialchars($week_start) ?>">
          <button class="btn btn-sm" type="submit">Go</button>
        </form>
        <a class="btn btn-sm" href="<?= base_url('reports/weekly_targets?week_start=' . $next_week) ?>">Next week &rarr;</a>
      </div>
    </div>

    <div style="padding:20px 24px 26px;">

      <?php if (!$has_table): ?>
      <div class="tgt-warn">
        The targets table (<code>equipment_kpi_targets</code>) is not installed on this database, so nothing can be
        shown or edited yet. Apply the weekly-report schema migration before using this screen.
      </div>
      <?php endif; ?>

      <div class="tgt-count">
        <div class="tgt-chip">Reportable machines <b><?= (int)($coverage['machines'] ?? 0) ?></b></div>
        <div class="tgt-chip">With a standing default <b><?= (int)($coverage['with_default'] ?? 0) ?></b></div>
        <div class="tgt-chip">With a day-specific target this week <b><?= (int)($coverage['with_override'] ?? 0) ?></b></div>
        <div class="tgt-chip">Machine-days on the fallback (<b><?= $pct($fallback) ?></b>) <b><?= (int)($coverage['unresolved'] ?? 0) ?></b></div>
        <div class="tgt-chip">Week <b><?= date('d M', strtotime($week_start)) ?> – <?= date('d M Y', strtotime($week_end)) ?></b></div>
      </div>

      <?php if ($can_edit && $has_table): ?>
      <div class="form-grid cols-2" style="gap:22px;align-items:start;">

        <!-- ── one target ────────────────────────────────────────────── -->
        <div>
          <div class="ct" style="font-size:12.5px;margin-bottom:10px;"><?= $edit_row ? 'Edit target #' . (int)$edit_row->id : 'Add a target' ?></div>
          <form method="post" action="<?= base_url('reports/weekly_targets_save') ?>" class="tgt-form">
            <?= $csrf_field() ?>
            <input type="hidden" name="week_start" value="<?= htmlspecialchars($week_start) ?>">
            <?php if ($edit_row): ?><input type="hidden" name="id" value="<?= (int)$edit_row->id ?>"><?php endif; ?>

            <div class="row">
              <div>
                <label class="form-label">Applies to</label>
                <select name="scope" class="form-select" id="tgt-scope">
                  <?php $cur_scope = $edit_row ? $edit_row->scope : 'Equipment'; ?>
                  <option value="Equipment" <?= $cur_scope === 'Equipment' ? 'selected' : '' ?>>One machine</option>
                  <option value="Group"     <?= $cur_scope === 'Group'     ? 'selected' : '' ?>>One group</option>
                </select>
              </div>
              <div>
                <label class="form-label">Target</label>
                <input type="text" name="target_pct" class="form-control" placeholder="88 or 0.88"
                       value="<?= $edit_row ? htmlspecialchars($edit_row->target_pct) : '' ?>" required>
                <div class="tgt-hint">88 or 88 % or 0.88 — all mean 88 %.</div>
              </div>
            </div>

            <div>
              <label class="form-label">Machine</label>
              <select name="equipment_id" class="form-select">
                <option value="">— choose a machine —</option>
                <?php foreach ($machines as $m): ?>
                <option value="<?= (int)$m->id ?>" <?= ($edit_row && $edit_row->scope === 'Equipment' && (int)$edit_row->equipment_id === (int)$m->id) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($m->registration_no) ?><?= !empty($m->type_name) ? ' — ' . htmlspecialchars($m->type_name) : '' ?><?= empty($m->report_group) ? ' (no report group)' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Group</label>
              <select name="group_code" class="form-select">
                <option value="">— choose a group —</option>
                <?php foreach ($groups as $code => $label): ?>
                <option value="<?= htmlspecialchars($code) ?>" <?= ($edit_row && $edit_row->scope === 'Group' && $edit_row->group_code === $code) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($label) ?> (<?= htmlspecialchars($code) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Day</label>
              <input type="date" name="target_date" class="form-control"
                     value="<?= $edit_row && $edit_row->target_date ? htmlspecialchars($edit_row->target_date) : '' ?>">
              <div class="tgt-hint">Leave empty to make this the <b>standing default</b>; pick a date to override one single day.</div>
            </div>

            <div>
              <label class="form-label">Note (optional)</label>
              <input type="text" name="note" class="form-control" maxlength="255"
                     value="<?= $edit_row && $edit_row->note ? htmlspecialchars($edit_row->note) : '' ?>">
            </div>

            <div style="display:flex;gap:10px;">
              <button class="btn btn-primary" type="submit"><?= $edit_row ? 'Save changes' : 'Add target' ?></button>
              <?php if ($edit_row): ?><a class="btn" href="<?= base_url('reports/weekly_targets?week_start=' . $week_start) ?>">Cancel</a><?php endif; ?>
            </div>
          </form>
        </div>

        <!-- ── bulk apply / copy ─────────────────────────────────────── -->
        <div>
          <div class="ct" style="font-size:12.5px;margin-bottom:10px;">Apply to many machines at once</div>
          <form method="post" action="<?= base_url('reports/weekly_targets_bulk') ?>" class="tgt-form">
            <?= $csrf_field() ?>
            <input type="hidden" name="week_start" value="<?= htmlspecialchars($week_start) ?>">
            <div class="row">
              <div>
                <label class="form-label">Target</label>
                <input type="text" name="target_pct" class="form-control" placeholder="88" required>
              </div>
              <div>
                <label class="form-label">Group</label>
                <select name="group_code" class="form-select">
                  <option value="">All reportable machines (<?= (int)($coverage['machines'] ?? 0) ?>)</option>
                  <?php foreach ($groups as $code => $label): ?>
                  <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="form-label">Day</label>
                <input type="date" name="target_date" class="form-control" value="">
              </div>
            </div>
            <div class="tgt-hint">Leave the day empty to set the standing default; pick a day to set that day only for every machine in scope.</div>
            <div><button class="btn btn-primary" type="submit">Apply</button></div>
          </form>

          <div class="ct" style="font-size:12.5px;margin:20px 0 10px;">Copy the previous week</div>
          <form method="post" action="<?= base_url('reports/weekly_targets_copy') ?>">
            <?= $csrf_field() ?>
            <input type="hidden" name="week_start" value="<?= htmlspecialchars($week_start) ?>">
            <div class="tgt-hint" style="margin:0 0 10px;">
              Copies every day-specific target of the week of <?= date('d M Y', strtotime($prev_week)) ?> onto the same
              weekday of this week. A target that already exists here is updated, never duplicated.
            </div>
            <button class="btn" type="submit">Copy from <?= date('d M', strtotime($prev_week)) ?> week</button>
          </form>
        </div>
      </div>
      <?php elseif (!$can_edit): ?>
      <div class="tgt-hint" style="margin-bottom:16px;">Your role can view the targets but not change them.</div>
      <?php endif; ?>

      <!-- ── the targets that exist ───────────────────────────────────── -->
      <div class="ct" style="font-size:12.5px;margin:26px 0 10px;">Targets in force for this week</div>
      <?php if (!$target_rows): ?>
        <div class="tgt-hint">No target is stored yet — every machine will use the fallback of <?= $pct($fallback) ?>.</div>
      <?php else: ?>
      <table class="tgt-table">
        <thead>
          <tr>
            <th>Applies to</th><th>Who</th><th>When</th><th class="tgt-num">Target</th><th>Note</th>
            <?php if ($can_edit): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($target_rows as $t): $r = $t['row']; ?>
          <tr>
            <td><span class="tgt-pill <?= $r->scope === 'Group' ? 'grp' : 'eq' ?>"><?= htmlspecialchars($t['scope']) ?></span></td>
            <td class="tgt-strong"><?= htmlspecialchars($t['who']) ?></td>
            <td><?= htmlspecialchars($t['when']) ?></td>
            <td class="tgt-num tgt-strong"><?= htmlspecialchars($t['pct']) ?></td>
            <td><?= htmlspecialchars((string)$r->note) ?></td>
            <?php if ($can_edit): ?>
            <td class="tgt-num" style="white-space:nowrap;">
              <a class="btn btn-sm" href="<?= base_url('reports/weekly_targets?week_start=' . $week_start . '&edit=' . (int)$r->id) ?>">Edit</a>
              <?= form_open('reports/weekly_targets_delete/' . (int)$r->id, array('style' => 'display:inline;margin:0;', 'onsubmit' => "return confirm('Delete this target?')")) ?>
                <input type="hidden" name="week_start" value="<?= htmlspecialchars($week_start, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-sm">Delete</button>
              <?= form_close() ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <!-- ── what the report will print ──────────────────────────────── -->
      <div class="ct" style="font-size:12.5px;margin:30px 0 6px;">What the report will print for this week</div>
      <div class="tgt-hint" style="margin-bottom:10px;">
        Every cell is resolved by the same chain the generator uses:
        machine · day → machine default → group · day → group default → fallback (<?= $pct($fallback) ?>).
        The small caption under each value says where it came from.
      </div>
      <?php if (!$machines): ?>
        <div class="tgt-hint">No reportable machine on this database yet.</div>
      <?php else: ?>
      <div class="tgt-scroll">
        <table class="tgt-table">
          <thead>
            <tr>
              <th>Machine</th><th>Group</th><th>Default</th>
              <?php foreach ($days as $d): ?>
              <th class="tgt-day"><?= date('D', strtotime($d)) ?><br><span style="font-weight:400;text-transform:none;letter-spacing:0;"><?= date('d/m', strtotime($d)) ?></span></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($machines as $m): $rm = isset($resolved[(int)$m->id]) ? $resolved[(int)$m->id] : null; if (!$rm) continue; ?>
            <tr>
              <td class="tgt-strong"><?= htmlspecialchars($m->registration_no) ?></td>
              <td><?= empty($m->report_group) ? '<span class="tgt-pill">no group</span>' : htmlspecialchars($m->report_group) ?></td>
              <td class="tgt-num">
                <?= $pct($rm['standing'] ? $rm['standing']['pct'] : null) ?>
                <?php if ($rm['standing']): ?><span class="tgt-src"><?= htmlspecialchars($src_short[$rm['standing']['source']] ?? $rm['standing']['source']) ?></span><?php endif; ?>
              </td>
              <?php foreach ($days as $d): $cell = isset($rm['days'][$d]) ? $rm['days'][$d] : null; ?>
              <td class="tgt-num">
                <?php if ($cell === null): ?>
                  <span style="color:#ff9c8f;">none</span>
                <?php else: ?>
                  <?= $pct($cell['pct']) ?><span class="tgt-src"><?= htmlspecialchars($src_short[$cell['source']] ?? $cell['source']) ?></span>
                <?php endif; ?>
              </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="tgt-hint" style="margin-top:8px;">
        <b>Open question to the business (OPEN-1)</b> — a different target for each individual day is supported (pick a
        date above); the standing default is what applies on every other day.
      </div>
      <?php endif; ?>

      <div style="margin-top:22px;">
        <a class="btn" href="<?= base_url('reports') ?>">&larr; All reports</a>
      </div>

    </div>
  </div>
</div>
