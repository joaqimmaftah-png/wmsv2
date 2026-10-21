<?php
/**
 * Backlog register — replaces the manual "Backlog on going" / "Backlog done"
 * sheets of asset list.xlsx.
 */
$f = isset($filter) ? $filter : array();
$g = function ($k) use ($f) { return isset($f[$k]) ? $f[$k] : ''; };
$qs = array_filter((array)$f, function ($v) { return $v !== null && $v !== ''; });
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="page-heading" style="font-size:16px;">Backlog Register</div>
    <div class="cs">Defects raised by inspections, checklists and job cards — tracked to closure</div>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('backlog/export_xlsx?'.http_build_query($qs)) ?>" class="btn btn-success btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Download Excel
    </a>
    <?php if (!empty($can_write)): ?>
    <a href="<?= base_url('backlog/create') ?>" class="btn btn-primary btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
      New Item
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($available)): ?>
<div class="card" style="padding:18px;border-left:3px solid var(--amber);">
  <strong>The backlog table is not installed yet.</strong><br>
  Import the database dump shipped with this repository
  (<code>database/schema.sql</code>) to enable this register.
</div>
<?php else: ?>

<!-- KPIs -->
<div class="grid g-4 mb-4">
  <div class="kpi"><div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Open Items</span>
    <div class="kpi-num"><?= (int)$kpi['open'] ?></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--red)"></div>
    <span class="kpi-tag">P1 Open</span>
    <div class="kpi-num"><?= (int)$kpi['p1'] ?></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Avg. Days Open</span>
    <div class="kpi-num"><?= $kpi['avg_days'] ?><sup style="font-size:11px;color:var(--t3);"> oldest <?= (int)$kpi['oldest'] ?>d</sup></div></div>
  <div class="kpi"><div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Closed</span>
    <div class="kpi-num"><?= (int)$kpi['closed'] ?></div></div>
</div>

<!-- Filters -->
<form method="get" class="flex ai-c gap-10 mb-4" style="flex-wrap:wrap;">
  <select name="status" class="form-select" style="width:140px;">
    <option value="">All statuses</option>
    <?php foreach (array('OPEN' => 'Open + In Progress', 'Open' => 'Open', 'In Progress' => 'In Progress',
                         'Closed' => 'Closed', 'Cancelled' => 'Cancelled') as $k => $lbl): ?>
    <option value="<?= $k ?>" <?= $g('status') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
    <?php endforeach; ?>
  </select>
  <select name="priority" class="form-select" style="width:110px;">
    <option value="">Priority</option>
    <?php foreach (array('P1','P2','P3') as $p): ?>
    <option value="<?= $p ?>" <?= $g('priority') === $p ? 'selected' : '' ?>><?= $p ?></option>
    <?php endforeach; ?>
  </select>
  <select name="source" class="form-select" style="width:130px;">
    <option value="">Source</option>
    <?php foreach (array('Inspection','Checklist','Job Card','Operator','Other') as $s): ?>
    <option value="<?= $s ?>" <?= $g('source') === $s ? 'selected' : '' ?>><?= $s ?></option>
    <?php endforeach; ?>
  </select>
  <select name="equipment_id" class="form-select" style="width:150px;">
    <option value="">All machines</option>
    <?php foreach ($equipment as $e): ?>
    <option value="<?= (int)$e->id ?>" <?= (string)$g('equipment_id') === (string)$e->id ? 'selected' : '' ?>><?= htmlspecialchars((string)($e->registration_no)) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="date_from" class="form-control" style="width:150px;" value="<?= htmlspecialchars((string)($g('date_from'))) ?>" title="Raised from">
  <input type="date" name="date_to" class="form-control" style="width:150px;" value="<?= htmlspecialchars((string)($g('date_to'))) ?>" title="Raised to">
  <label class="flex ai-c gap-10" style="font-size:12px;">
    <input type="checkbox" name="overdue" value="1" <?= $g('overdue') ? 'checked' : '' ?>> Overdue only
  </label>
  <input type="text" name="search" class="form-control" style="width:200px;" placeholder="Search defect / person…" value="<?= htmlspecialchars((string)($g('search'))) ?>">
  <button type="submit" class="btn btn-primary btn-sm">Filter</button>
  <a href="<?= base_url('backlog') ?>" class="btn btn-ghost btn-sm">Reset</a>
</form>

<!-- Table -->
<div class="card">
  <div class="ch">
    <div><div class="ct">Backlog Items</div><div class="cs"><?= count($items) ?> matching item(s)</div></div>
  </div>
  <div style="overflow-x:auto;margin-top:4px;">
    <table class="tbl">
      <thead>
        <tr>
          <th>Asset</th><th>Model</th><th>Odometer</th><th>Raised</th>
          <th style="min-width:260px;">Description</th>
          <th>Source</th><th>Reported By</th><th>In Charge</th>
          <th class="text-center">Pri</th>
          <th class="text-right">Days</th>
          <th>Deadline</th><th style="min-width:200px;">Observation</th>
          <th>Machine</th><th>Status</th>
          <?php if (!empty($can_write)): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($items)): ?>
        <tr><td colspan="15" style="text-align:center;padding:52px;color:var(--t3);">No backlog items match this filter</td></tr>
      <?php else: foreach ($items as $b):
        $open    = in_array($b->status, array('Open','In Progress'), true);
        $overdue = $open && $b->deadline && $b->deadline < date('Y-m-d');
        $pcls    = $b->priority === 'P1' ? 'b-danger' : ($b->priority === 'P2' ? 'b-warning' : 'b-primary');
        $scls    = $b->status === 'Closed' ? 'b-success' : ($b->status === 'Cancelled' ? 'b-secondary' : ($overdue ? 'b-danger' : 'b-warning'));
      ?>
        <tr>
          <td class="mono" style="font-weight:600;color:var(--blue);"><?= htmlspecialchars((string)($b->equipment_code ?: $b->asset_label)) ?>
            <?php if (!$b->equipment_id): ?><span class="badge b-secondary" style="font-size:9px;" title="Not linked to a machine in the asset register">?</span><?php endif; ?>
          </td>
          <td style="font-size:12px;"><?= htmlspecialchars((string)$b->model) ?></td>
          <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)$b->start_odometer) ?></td>
          <td style="font-size:12px;"><?= htmlspecialchars((string)$b->start_date) ?></td>
          <td style="font-size:12px;white-space:pre-line;"><?= htmlspecialchars((string)$b->description) ?></td>
          <td style="font-size:11px;"><span class="badge b-primary" style="font-size:10px;"><?= htmlspecialchars((string)$b->source) ?></span></td>
          <td style="font-size:12px;white-space:pre-line;"><?= htmlspecialchars((string)$b->reported_by) ?></td>
          <td style="font-size:12px;white-space:pre-line;"><?= htmlspecialchars((string)$b->in_charge) ?></td>
          <td class="text-center"><?php if ($b->priority): ?><span class="badge <?= $pcls ?>" style="font-size:10px;"><?= $b->priority ?></span><?php endif; ?></td>
          <td class="mono text-right" style="<?= ($open && $b->days_down > 60) ? 'color:var(--red);font-weight:700;' : '' ?>"><?= (int)$b->days_down ?></td>
          <td style="font-size:12px;<?= $overdue ? 'color:var(--red);font-weight:600;' : '' ?>"><?= htmlspecialchars((string)$b->deadline) ?></td>
          <td style="font-size:12px;white-space:pre-line;color:var(--t3);"><?= htmlspecialchars((string)($b->status === 'Closed' ? $b->closing_remarks : $b->observation)) ?></td>
          <td style="font-size:11px;"><?= htmlspecialchars((string)$b->machine_status) ?></td>
          <td><span class="badge <?= $scls ?>" style="font-size:10px;"><?= htmlspecialchars((string)($b->status)) ?></span></td>
          <?php if (!empty($can_write)): ?>
          <td style="white-space:nowrap;">
            <a href="<?= base_url('backlog/edit/'.$b->id) ?>" class="btn btn-ghost btn-xs">Edit</a>
            <?php if ($open && !empty($is_supervisor)): ?>
            <?php /* Closing a defect is the supervisor's verification (Wave 3, Q5).
                    A defect with a work order is closed by closing that work order. */ ?>
            <form method="post" action="<?= base_url('backlog/close/'.$b->id) ?>" style="display:inline;"
                  onsubmit="return confirm('Close this defect as verified?');">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-ghost btn-xs">Close (verify)</button>
            </form>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($by_asset)): ?>
<div class="card mt-4">
  <div class="ch"><div><div class="ct">Open Backlog by Machine</div>
    <div class="cs">Where the open defects are concentrated</div></div></div>
  <div style="overflow-x:auto;margin-top:4px;">
    <table class="tbl">
      <thead><tr><th>Asset</th><th class="text-right">Open Items</th><th class="text-right">P1</th><th class="text-right">Oldest (days)</th></tr></thead>
      <tbody>
      <?php foreach ($by_asset as $a): ?>
        <tr>
          <td class="mono" style="font-weight:600;"><?= htmlspecialchars((string)($a->asset)) ?></td>
          <td class="mono text-right"><?= (int)$a->items ?></td>
          <td class="mono text-right text-red"><?= (int)$a->p1 ?></td>
          <td class="mono text-right"><?= (int)$a->oldest_days ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>
