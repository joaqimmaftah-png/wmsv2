<?php
/** Backlog item — create / edit. */
$src  = isset($post) && $post ? $post : (isset($item) && $item ? $item : null);
$v = function ($k, $d = '') use ($src) {
    if ($src && isset($src->$k) && $src->$k !== null) return $src->$k;
    return $d;
};
$e = isset($errors) ? $errors : array();
$err = function ($k) use ($e) { return isset($e[$k]) ? $e[$k] : ''; };
$action = (isset($item) && $item) ? base_url('backlog/edit/'.$item->id) : base_url('backlog/create');
?>

<div class="flex ai-c jb mb-4">
  <div>
    <div class="page-heading" style="font-size:16px;"><?= (isset($item) && $item) ? 'Edit Backlog Item' : 'New Backlog Item' ?></div>
    <div class="cs">A defect found on a machine — raised, prioritised and tracked to closure</div>
  </div>
  <a href="<?= base_url('backlog') ?>" class="btn btn-ghost btn-sm">Back to register</a>
</div>

<form method="post" action="<?= $action ?>">
<?= csrf_field() ?>
<div class="card" style="padding:20px;">
  <div class="ct mb-4">The defect</div>
  <div class="grid g-2" style="gap:16px;">

    <div>
      <label class="form-label">Machine (asset register)</label>
      <select name="equipment_id" class="form-select">
        <option value="">— not in the register / free text —</option>
        <?php foreach ($equipment as $eq): ?>
        <option value="<?= (int)$eq->id ?>" <?= (string)$v('equipment_id') === (string)$eq->id ? 'selected' : '' ?>>
          <?= htmlspecialchars((string)($eq->registration_no)) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <div class="cs">Choosing a machine links the item to its history.</div>
    </div>

    <div>
      <label class="form-label">Asset reference *</label>
      <input type="text" name="asset_label" class="form-control" maxlength="80"
             value="<?= htmlspecialchars((string)($v('asset_label'))) ?>" placeholder="e.g. DZ 003">
      <?php if ($err('asset_label')): ?><div class="text-red" style="font-size:12px;"><?= $err('asset_label') ?></div><?php endif; ?>
    </div>

    <div>
      <label class="form-label">Model</label>
      <input type="text" name="model" class="form-control" maxlength="100" value="<?= htmlspecialchars((string)($v('model'))) ?>">
    </div>

    <div>
      <label class="form-label">Serial / Chassis no</label>
      <input type="text" name="serial_no" class="form-control" maxlength="100" value="<?= htmlspecialchars((string)($v('serial_no'))) ?>">
    </div>

    <div>
      <label class="form-label">Odometer at raising</label>
      <input type="text" name="start_odometer" class="form-control" maxlength="40"
             value="<?= htmlspecialchars((string)($v('start_odometer'))) ?>" placeholder="e.g. 24 609 Km or 5956.6 Hr">
    </div>

    <div>
      <label class="form-label">Starting date *</label>
      <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars((string)($v('start_date', date('Y-m-d')))) ?>">
      <?php if ($err('start_date')): ?><div class="text-red" style="font-size:12px;"><?= $err('start_date') ?></div><?php endif; ?>
    </div>

    <div style="grid-column:1/-1;">
      <label class="form-label">Description *</label>
      <textarea name="description" class="form-control" rows="4"
                placeholder="One line per defect"><?= htmlspecialchars((string)($v('description'))) ?></textarea>
      <?php if ($err('description')): ?><div class="text-red" style="font-size:12px;"><?= $err('description') ?></div><?php endif; ?>
    </div>

    <div>
      <label class="form-label">Source</label>
      <select name="source" class="form-select">
        <?php foreach ($sources as $s): ?>
        <option value="<?= $s ?>" <?= $v('source','Other') === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="form-label">Priority</label>
      <select name="priority" class="form-select">
        <option value="">—</option>
        <?php foreach ($priorities as $p): ?>
        <option value="<?= $p ?>" <?= $v('priority') === $p ? 'selected' : '' ?>><?= $p ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="form-label">Reported by</label>
      <input type="text" name="reported_by" class="form-control" maxlength="100" value="<?= htmlspecialchars((string)($v('reported_by'))) ?>">
    </div>

    <div>
      <label class="form-label">In charge</label>
      <input type="text" name="in_charge" class="form-control" maxlength="100" value="<?= htmlspecialchars((string)($v('in_charge'))) ?>">
    </div>

    <div>
      <label class="form-label">Deadline</label>
      <input type="date" name="deadline" class="form-control" value="<?= htmlspecialchars((string)($v('deadline'))) ?>">
    </div>

    <div>
      <label class="form-label">Machine condition</label>
      <select name="machine_status" class="form-select">
        <option value="">—</option>
        <?php foreach ($machine_statuses as $m): ?>
        <option value="<?= $m ?>" <?= $v('machine_status') === $m ? 'selected' : '' ?>><?= $m ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="grid-column:1/-1;">
      <label class="form-label">Observation / action required</label>
      <textarea name="observation" class="form-control" rows="2"><?= htmlspecialchars((string)($v('observation'))) ?></textarea>
    </div>
  </div>
</div>

<div class="card mt-4" style="padding:20px;">
  <div class="ct mb-4">Scheduling & Planning</div>
  <div class="grid g-2" style="gap:16px;">
    <div>
      <label class="form-label">Planned Date</label>
      <input type="date" name="planned_date" class="form-control" value="<?= htmlspecialchars((string)($v('planned_date'))) ?>">
      <div class="cs">When is this job scheduled to be done?</div>
    </div>
    <div>
      <label class="form-label">Estimated Duration (minutes)</label>
      <input type="number" name="estimated_duration" class="form-control" min="0" value="<?= htmlspecialchars((string)($v('estimated_duration'))) ?>">
      <div class="cs">How long will it take? (e.g., 60 for 1 hour)</div>
    </div>
  </div>
</div>

<div class="card mt-4" style="padding:20px;">
  <div class="ct mb-4">Closure</div>
  <div class="grid g-2" style="gap:16px;">
    <div>
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <?php foreach ($statuses as $s): ?>
        <option value="<?= $s ?>" <?= $v('status','Open') === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">End date</label>
      <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars((string)($v('end_date'))) ?>">
      <?php if ($err('end_date')): ?><div class="text-red" style="font-size:12px;"><?= $err('end_date') ?></div><?php endif; ?>
    </div>
    <div>
      <label class="form-label">Odometer at closure</label>
      <input type="text" name="end_odometer" class="form-control" maxlength="40" value="<?= htmlspecialchars((string)($v('end_odometer'))) ?>">
    </div>
    <div>
      <label class="form-label">Mechanic in charge</label>
      <input type="text" name="mechanic" class="form-control" maxlength="150" value="<?= htmlspecialchars((string)($v('mechanic'))) ?>">
    </div>
    <div>
      <label class="form-label">Supervisor</label>
      <input type="text" name="supervisor" class="form-control" maxlength="100" value="<?= htmlspecialchars((string)($v('supervisor'))) ?>">
    </div>
    <div style="grid-column:1/-1;">
      <label class="form-label">What was done</label>
      <textarea name="closing_remarks" class="form-control" rows="3"><?= htmlspecialchars((string)($v('closing_remarks'))) ?></textarea>
    </div>
  </div>
</div>

<div class="flex ai-c gap-10 mt-4">
  <button type="submit" class="btn btn-primary">Save</button>
  <a href="<?= base_url('backlog') ?>" class="btn btn-ghost">Cancel</a>
</div>
</form>
