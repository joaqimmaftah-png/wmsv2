<?php
/** Create/edit one independently actionable defect. Existing descriptions are never split. */
$src = isset($post) && $post ? $post : (isset($item) && $item ? $item : NULL);
$v = function ($key, $default = '') use ($src) {
    return $src && isset($src->$key) && $src->$key !== NULL ? $src->$key : $default;
};
$h = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$errors = isset($errors) && is_array($errors) ? $errors : array();
$err = function ($name) use ($errors) { return isset($errors[$name]) ? $errors[$name] : ''; };
$described = function ($name, $hint = '') use ($errors, $h) {
    $ids = array();
    if ($hint !== '') $ids[] = $hint;
    if (isset($errors[$name])) $ids[] = 'error-'.$name;
    return $ids ? ' aria-describedby="'.$h(implode(' ', $ids)).'"'.(isset($errors[$name]) ? ' aria-invalid="true"' : '') : '';
};
$showError = function ($name) use ($err, $h) {
    if ($err($name)) echo '<div class="defect-error" id="error-'.$h($name).'">'.$h($err($name)).'</div>';
};
$isEdit = isset($item) && $item;
$isSupervisor = isset($is_supervisor) ? (bool)$is_supervisor : (isset($role_id) && (int)$role_id === 1);
$workflowReady = isset($workflow_ready) ? (bool)$workflow_ready : TRUE;
$work_orders = isset($work_orders) && is_array($work_orders) ? $work_orders : array();
$active_plan = isset($active_plan) ? $active_plan : NULL;
$action = $isEdit ? base_url('backlog/edit/'.$item->id) : base_url('backlog/create');
$statusValue = (string)$v('status', 'Open');
$closureOpen = !empty($errors) || in_array($statusValue, array('Closed', 'Cancelled'), TRUE);
$planOpen = !empty($v('requested_date')) || !empty($v('requested_duration')) || !empty($active_plan)
    || ($isEdit && !empty($item->schedule_message));
?>
<style>
.defect-form{max-width:1000px}.defect-form .defect-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.defect-form .defect-wide{grid-column:1/-1}.defect-form .defect-hint{font-size:12px;line-height:1.45;color:#475569;margin-top:5px}
.defect-form .defect-error{font-size:12px;color:#a31616;font-weight:600;margin-top:5px}
.defect-form .defect-summary{border:2px solid #b42318;padding:16px;margin-bottom:18px;border-radius:8px;background:#fff;color:#7f1d1d}
.defect-form .defect-summary a{color:#8d1010;text-decoration:underline}.defect-form summary{cursor:pointer;font-weight:700;padding:4px 0}
.defect-form .defect-actions{display:flex;gap:10px;flex-wrap:wrap}.defect-form .defect-actions .btn{min-height:40px}
.defect-form .defect-note{padding:10px 12px;border-left:3px solid #a15c00;background:#fff8e8;color:#663c00;margin:12px 0;line-height:1.5}
@media(max-width:600px){#canvas .page{padding:16px 16px 48px}.defect-form .defect-grid{grid-template-columns:minmax(0,1fr)}.defect-form .card{padding:16px!important}.defect-form .defect-actions .btn{width:100%;justify-content:center}}
</style>

<div class="defect-form">
  <div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:12px;">
    <div>
      <h1 class="page-heading" style="font-size:18px;"><?= $isEdit ? 'Edit defect' : 'Report a defect' ?></h1>
      <div class="defect-hint">Record one independently actionable defect. You can add another for the same machine afterwards.</div>
    </div>
    <a href="<?= $h(base_url('backlog')) ?>" class="btn btn-ghost btn-sm">Back to register</a>
  </div>
  <?php if (!$workflowReady): ?>
  <div class="defect-note" role="status">This database needs migration 0015 for planning, closure and audit features. You can still report an open defect; planning requests are recorded as notes and are not scheduled.</div>
  <?php endif; ?>
  <?php if ($errors): ?>
  <div class="defect-summary" id="defect-error-summary" role="alert" tabindex="-1">
    <strong>There is a problem</strong>
    <ul style="margin:8px 0 0;padding-left:20px;">
      <?php foreach ($errors as $key => $message): ?>
      <li><?php if ($key === 'record_version'): ?><?= $h($message) ?><?php else: ?><a href="#field-<?= $h($key) ?>"><?= $h($message) ?></a><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <form method="post" action="<?= $h($action) ?>" id="defect-form" novalidate>
    <?= csrf_field() ?>
    <?php if (!$isEdit): ?><input type="hidden" name="submit_token" value="<?= $h(isset($submit_token) ? $submit_token : '') ?>"><?php else: ?><input type="hidden" name="record_version" value="<?= $h(isset($record_version) ? $record_version : '') ?>"><?php endif; ?>
    <section class="card" style="padding:20px;" aria-labelledby="defect-heading">
      <h2 class="ct mb-4" id="defect-heading">The defect</h2>
      <div class="defect-grid">
        <div>
          <label class="form-label" for="field-equipment_id">Machine in asset register</label>
          <select name="equipment_id" id="field-equipment_id" class="form-select"<?= $described('equipment_id', 'machine-hint') ?>>
            <option value="">Not in the register / type a reference</option>
            <?php foreach ($equipment as $eq): ?>
            <option value="<?= (int)$eq->id ?>" <?= (string)$v('equipment_id') === (string)$eq->id ? 'selected' : '' ?>><?= $h($eq->registration_no) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="defect-hint" id="machine-hint">A registered machine links the defect to its history and allows scheduling.</div>
          <?php $showError('equipment_id'); ?>
        </div>
        <div>
          <label class="form-label" for="field-asset_label">Asset reference</label>
          <input type="text" name="asset_label" id="field-asset_label" class="form-control" maxlength="80" value="<?= $h($v('asset_label')) ?>" placeholder="e.g. DZ 003"<?= $described('asset_label', 'asset-hint') ?>>
          <div class="defect-hint" id="asset-hint">Choose a machine or type its reference. If you do both, they must match.</div>
          <?php $showError('asset_label'); ?>
        </div>
        <div>
          <label class="form-label" for="field-start_date">Date found <span aria-hidden="true">*</span></label>
          <input type="date" name="start_date" id="field-start_date" class="form-control" value="<?= $h($v('start_date', date('Y-m-d'))) ?>" required<?= $described('start_date') ?>>
          <?php $showError('start_date'); ?>
        </div>
        <div>
          <label class="form-label" for="field-priority">Priority</label>
          <select name="priority" id="field-priority" class="form-select">
            <option value="">Not yet assessed</option>
            <?php foreach ($priorities as $priority): ?><option value="<?= $h($priority) ?>" <?= $v('priority') === $priority ? 'selected' : '' ?>><?= $h($priority) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="defect-wide">
          <label class="form-label" for="field-description">Describe this defect <span aria-hidden="true">*</span></label>
          <textarea name="description" id="field-description" class="form-control" rows="3" required<?= $described('description', 'description-hint') ?>><?= $h($v('description')) ?></textarea>
          <div class="defect-hint" id="description-hint">Include the location and symptoms of this one problem. Different problems need separate records.</div>
          <?php $showError('description'); ?>
          <div class="defect-hint" id="defect-similar" role="status" aria-live="polite"></div>
        </div>
        <div>
          <label class="form-label" for="field-source">Source</label>
          <select name="source" id="field-source" class="form-select">
            <?php foreach ($sources as $source): ?><option value="<?= $h($source) ?>" <?= $v('source', 'Other') === $source ? 'selected' : '' ?>><?= $h($source) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" for="field-machine_status">Machine condition</label>
          <select name="machine_status" id="field-machine_status" class="form-select" aria-describedby="condition-hint">
            <option value="">Not yet assessed</option>
            <?php foreach ($machine_statuses as $condition): ?><option value="<?= $h($condition) ?>" <?= $v('machine_status') === $condition ? 'selected' : '' ?>><?= $h($condition) ?></option><?php endforeach; ?>
          </select>
          <div class="defect-hint" id="condition-hint">This record is not an equipment-isolation control; follow site safety procedures.</div>
        </div>
        <div>
          <label class="form-label" for="field-reported_by">Reported by</label>
          <input type="text" name="reported_by" id="field-reported_by" class="form-control" maxlength="100" value="<?= $h($v('reported_by')) ?>">
        </div>
        <div>
          <label class="form-label" for="field-in_charge">In charge</label>
          <input type="text" name="in_charge" id="field-in_charge" class="form-control" maxlength="100" value="<?= $h($v('in_charge')) ?>">
        </div>
        <div>
          <label class="form-label" for="field-deadline">Deadline</label>
          <input type="date" name="deadline" id="field-deadline" class="form-control" value="<?= $h($v('deadline')) ?>"<?= $described('deadline') ?>><?php $showError('deadline'); ?>
        </div>
        <div>
          <label class="form-label" for="field-work_order_id">Related work order</label>
          <select name="work_order_id" id="field-work_order_id" class="form-select"<?= $described('work_order_id', 'wo-hint') ?>>
            <option value="">No work order linked</option>
            <?php foreach ($work_orders as $wo): ?>
            <option value="<?= (int)$wo->id ?>" data-equipment="<?= (int)$wo->equipment_id ?>" <?= (string)$v('work_order_id') === (string)$wo->id ? 'selected' : '' ?>><?= $h($wo->wo_number.' · '.$wo->title.' ('.$wo->status.')') ?></option>
            <?php endforeach; ?>
          </select>
          <div class="defect-hint" id="wo-hint">Optional. The work order must belong to this machine. Several defects may share it.</div>
          <?php $showError('work_order_id'); ?>
        </div>
        <?php if ($isSupervisor && $isEdit && !empty($item->work_order_id)): ?>
        <div class="defect-wide"><label class="form-label" for="field-link_change_reason">Reason for changing the existing work-order link</label><input type="text" name="link_change_reason" id="field-link_change_reason" class="form-control" maxlength="500" value="<?= $h($v('link_change_reason')) ?>"<?= $described('link_change_reason') ?>><div class="defect-hint">Only needed if you remove or replace the existing link. Save the correction before verifying the defect.</div><?php $showError('link_change_reason'); ?></div>
        <?php endif; ?>
        <div class="defect-wide">
          <label class="form-label" for="field-observation">Observation / action required</label>
          <textarea name="observation" id="field-observation" class="form-control" rows="2"><?= $h($v('observation')) ?></textarea>
        </div>
      </div>
      <details style="margin-top:16px;">
        <summary>Additional machine details</summary>
        <div class="defect-grid" style="margin-top:12px;">
          <div><label class="form-label" for="field-model">Model</label><input type="text" name="model" id="field-model" class="form-control" maxlength="100" value="<?= $h($v('model')) ?>"></div>
          <div><label class="form-label" for="field-serial_no">Serial / chassis number</label><input type="text" name="serial_no" id="field-serial_no" class="form-control" maxlength="100" value="<?= $h($v('serial_no')) ?>"></div>
          <div><label class="form-label" for="field-start_odometer">Odometer or hours at reporting</label><input type="text" name="start_odometer" id="field-start_odometer" class="form-control" maxlength="40" value="<?= $h($v('start_odometer')) ?>" placeholder="e.g. 24 609 Km or 5956.6 Hr"></div>
        </div>
      </details>
    </section>

    <details class="card mt-4" style="padding:20px;" <?= $planOpen ? 'open' : '' ?>>
      <summary>Scheduling and planning <span style="font-weight:400;">(optional)</span></summary>
      <div class="defect-hint">Saving a defect or proposal does not itself create a work order. A supervisor must commit a plan.</div>
      <?php if ($active_plan): ?>
      <div class="defect-note">Existing plan: <?= $h($active_plan->start_date) ?> · <?= $h($active_plan->status) ?>. A failed change leaves this plan in place.</div>
      <?php endif; ?>
      <?php if ($isEdit && !empty($item->schedule_message)): ?><div class="defect-note"><?= $h($item->schedule_message) ?></div><?php endif; ?>
      <div class="defect-grid" style="margin-top:14px;">
        <div><label class="form-label" for="field-requested_date">Proposed date</label><input type="date" name="requested_date" id="field-requested_date" class="form-control" value="<?= $h($v('requested_date')) ?>"></div>
        <div><label class="form-label" for="field-requested_duration">Estimated duration (minutes)</label><input type="number" name="requested_duration" id="field-requested_duration" class="form-control" min="1" max="1440" value="<?= $h($v('requested_duration')) ?>" aria-describedby="duration-hint"><div class="defect-hint" id="duration-hint">For a single-day job, enter 1–1440 minutes.</div></div>
      </div>
      <?php if ($isSupervisor && $workflowReady): ?>
      <div class="defect-actions" style="margin-top:14px;">
        <button type="submit" name="plan_action" value="schedule" class="btn btn-ghost">Save defect and schedule</button>
        <?php if ($active_plan): ?><button type="submit" name="plan_action" value="remove" class="btn btn-ghost" data-confirm="Remove this active plan job? Published weeks cannot be changed.">Save and remove existing plan</button><?php endif; ?>
      </div>
      <?php endif; ?>
    </details>

    <details class="card mt-4" style="padding:20px;" <?= $closureOpen ? 'open' : '' ?>>
      <summary><?= $isSupervisor ? 'Status, closure and historical records' : 'Progress' ?></summary>
      <div class="defect-grid" style="margin-top:14px;">
        <div>
          <label class="form-label" for="field-status">Status</label>
          <select name="status" id="field-status" class="form-select"<?= $described('status') ?>>
            <?php foreach ($statuses as $status): ?><option value="<?= $h($status) ?>" <?= $statusValue === $status ? 'selected' : '' ?>><?= $h($status) ?></option><?php endforeach; ?>
          </select>
          <?php $showError('status'); ?>
        </div>
        <?php if ($isSupervisor): ?>
        <?php if ($isEdit && in_array($item->status, array('Closed', 'Cancelled'), TRUE)): ?>
        <div class="defect-wide"><label class="form-label" for="field-change_reason">Reason for correcting this record <span aria-hidden="true">*</span></label><input type="text" name="change_reason" id="field-change_reason" class="form-control" maxlength="500" value="<?= $h($v('change_reason')) ?>"<?= $described('change_reason') ?>><?php $showError('change_reason'); ?><div class="defect-hint">Your changes and their previous values are recorded in the activity log.</div></div>
        <?php endif; ?>
        <div id="closure-fields" class="defect-wide">
          <div class="defect-grid">
            <div><label class="form-label" for="field-end_date">Completion date</label><input type="date" name="end_date" id="field-end_date" class="form-control" value="<?= $h($v('end_date')) ?>"<?= $described('end_date', 'completion-date-hint') ?>><div class="defect-hint" id="completion-date-hint">For historical records, leave blank only if the date is unknown.</div><?php $showError('end_date'); ?></div>
            <div><label class="form-label" for="field-end_odometer">Odometer or hours at completion</label><input type="text" name="end_odometer" id="field-end_odometer" class="form-control" maxlength="40" value="<?= $h($v('end_odometer')) ?>"></div>
            <div><label class="form-label" for="field-mechanic">Mechanic in charge</label><input type="text" name="mechanic" id="field-mechanic" class="form-control" maxlength="150" value="<?= $h($v('mechanic')) ?>"></div>
            <div><label class="form-label" for="field-supervisor">Recorded supervisor name</label><input type="text" name="supervisor" id="field-supervisor" class="form-control" maxlength="100" value="<?= $h($v('supervisor')) ?>"></div>
            <div class="defect-wide"><label class="form-label" for="field-closing_remarks">What was done</label><textarea name="closing_remarks" id="field-closing_remarks" class="form-control" rows="3"<?= $described('closing_remarks') ?>><?= $h($v('closing_remarks')) ?></textarea><?php $showError('closing_remarks'); ?></div>
            <?php if (!$isEdit || $item->status !== 'Closed'): ?>
            <div class="defect-wide"><label for="field-historical"><input type="checkbox" name="historical" id="field-historical" value="1" <?= (string)$v('historical') === '1' ? 'checked' : '' ?>> This is a historical completion recorded after the fact</label></div>
            <div class="defect-wide"><label class="form-label" for="field-completion_source">Historical completion source</label><input type="text" name="completion_source" id="field-completion_source" class="form-control" maxlength="500" value="<?= $h($v('completion_source')) ?>"<?= $described('completion_source', 'source-hint') ?>><div class="defect-hint" id="source-hint">For example, a job card, maintenance log or named firsthand reporter.</div><?php $showError('completion_source'); ?></div>
            <div class="defect-wide"><label for="field-verified_now"><input type="checkbox" name="verified_now" id="field-verified_now" value="1" <?= (string)$v('verified_now') === '1' ? 'checked' : '' ?><?= $described('verified_now') ?>> I personally verified this repair</label><?php $showError('verified_now'); ?><div class="defect-hint">If historical verification is unknown, leave this unchecked; the record will say verification was not recorded.</div></div>
            <div class="defect-wide"><label class="form-label" for="field-verification_notes">What you personally checked</label><textarea name="verification_notes" id="field-verification_notes" class="form-control" rows="2" maxlength="300"<?= $described('verification_notes') ?>><?= $h($v('verification_notes')) ?></textarea><?php $showError('verification_notes'); ?></div>
            <?php else: ?>
            <div class="defect-wide"><p class="defect-hint">Existing verification evidence is retained; corrections are recorded in the activity log.</p><label class="form-label" for="field-completion_source">Historical completion source</label><input type="text" name="completion_source" id="field-completion_source" class="form-control" maxlength="500" value="<?= $h($v('completion_source')) ?>"></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="defect-wide" id="cancellation-fields"><label class="form-label" for="field-cancellation_reason">Reason for cancellation</label><input type="text" name="cancellation_reason" id="field-cancellation_reason" class="form-control" maxlength="500" value="<?= $h($v('cancellation_reason')) ?>"<?= $described('cancellation_reason') ?>><?php $showError('cancellation_reason'); ?></div>
        <?php else: ?>
        <div class="defect-hint">A supervisor reviews cancellation and verifies closure.</div>
        <?php endif; ?>
      </div>
    </details>

    <div class="defect-actions mt-4">
      <button type="submit" class="btn btn-primary">Save defect</button>
      <?php if (!$isEdit): ?><button type="submit" name="after_save" value="add_another" class="btn btn-ghost">Save and add another</button><?php endif; ?>
      <a href="<?= $h(base_url('backlog')) ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
<script>
(function () {
  var form = document.getElementById('defect-form'), equip = document.getElementById('field-equipment_id');
  var asset = document.getElementById('field-asset_label'), status = document.getElementById('field-status');
  var wo = document.getElementById('field-work_order_id'), desc = document.getElementById('field-description');
  var summary = document.getElementById('defect-error-summary'), dirty = false, submitting = false, timer;
  if (summary) summary.focus();
  function visibility() {
    var close = document.getElementById('closure-fields'), cancel = document.getElementById('cancellation-fields');
    if (close) close.hidden = status.value !== 'Closed';
    if (cancel) cancel.hidden = status.value !== 'Cancelled';
  }
  function filterOrders() {
    if (!wo || !equip) return;
    Array.prototype.forEach.call(wo.options, function (o) {
      if (!o.value) return;
      o.hidden = !!equip.value && o.getAttribute('data-equipment') !== equip.value && !o.selected;
    });
  }
  function findSimilar() {
    clearTimeout(timer);
    var box = document.getElementById('defect-similar');
    if (!box || !equip.value || desc.value.trim().length < 4) { if (box) box.textContent = ''; return; }
    timer = setTimeout(function () {
      var machine = equip.value, text = desc.value.trim();
      var url = new URL(<?= json_encode(base_url('backlog/similar'), JSON_UNESCAPED_SLASHES) ?>, window.location.href);
      url.searchParams.set('equipment_id', machine); url.searchParams.set('q', text);
      <?php if ($isEdit): ?>url.searchParams.set('exclude_id', '<?= (int)$item->id ?>');<?php endif; ?>
      fetch(url.toString(), {credentials:'same-origin'}).then(function (r) { return r.json(); }).then(function (data) {
        if (equip.value !== machine || desc.value.trim() !== text) return;
        box.textContent = '';
        if (!data.matches || !data.matches.length) return;
        box.appendChild(document.createTextNode('Possible existing open defects: '));
        data.matches.forEach(function (m, index) {
          if (index) box.appendChild(document.createTextNode('; '));
          var link = document.createElement('a');
          link.href = <?= json_encode(base_url('backlog?q=BL-'), JSON_UNESCAPED_SLASHES) ?> + encodeURIComponent(m.id);
          link.textContent = 'BL-' + m.id + ' ' + m.description;
          box.appendChild(link);
        });
        box.appendChild(document.createTextNode('. Review before adding a duplicate.'));
      }).catch(function () { box.textContent = ''; });
    }, 450);
  }
  if (equip) equip.addEventListener('change', function () { if (!asset.value && equip.value) asset.value = equip.options[equip.selectedIndex].textContent.trim(); filterOrders(); findSimilar(); });
  if (desc) desc.addEventListener('input', findSimilar);
  if (status) status.addEventListener('change', visibility);
  if (form) {
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('change', function () { dirty = true; });
    form.addEventListener('submit', function (event) {
      var button = event.submitter;
      if (button && button.dataset.confirm && !window.confirm(button.dataset.confirm)) { event.preventDefault(); return; }
      submitting = true;
    });
    window.addEventListener('beforeunload', function (event) { if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; } });
  }
  visibility(); filterOrders();
})();
</script>
