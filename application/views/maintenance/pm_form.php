<?php
/**
 * PM schedule form — used for both create and edit.
 *
 * The same form serves both so the two cannot drift: a field added to one is
 * automatically in the other.  $pm is null when creating and the schedule row
 * when editing.
 *
 * $has_standard_hours is supplied by the controller from field_exists(): on a
 * database without the standard_hours column the field is not offered at all
 * rather than posting into a column
 * that does not exist.
 */
$pm      = isset($pm) ? $pm : null;
$editing = !empty($pm);

/**
 * A value for an input: the schedule's stored value, or a blank.
 *
 * NULL must render as an empty attribute, not as the literal string "", which
 * is what happens when a null is echoed straight into value="" — and not as
 * "0", which would silently re-date a schedule that has never been done.
 */
$pm_val = function ($field) use ($editing, $pm) {
    if (!$editing || !isset($pm->$field) || $pm->$field === null || $pm->$field === '') {
        return '';
    }
    return htmlspecialchars((string)$pm->$field, ENT_QUOTES, 'UTF-8');
};

// a numeric field must not be quoted into "" for an unset meter reading
$pm_num = function ($field) use ($pm_val) {
    $v = $pm_val($field);
    return ($v === '' || !is_numeric($v)) ? '' : $v;
};
?>
<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="wms-card">
      <div class="card-head">
        <i class="bi <?= $editing ? 'bi-pencil-square text-primary' : 'bi-calendar-plus text-success' ?> me-1"></i>
        <?= $editing ? 'Edit PM Schedule' : 'Create PM Schedule' ?>
        <?php if ($editing): ?>
          <span class="fw-400 text-300" style="font-size:12px;">— <?= $pm_val('schedule_name') ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?= $editing ? form_open('pm/edit/' . (int)$pm->id) : form_open('pm/create') ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-500">Equipment <span class="text-danger">*</span></label>
            <select name="equipment_id" class="form-select" required>
              <option value="">Select Equipment...</option>
              <?php foreach ($equipment as $eq): ?>
              <option value="<?= (int)$eq->id ?>"<?= ($editing && (int)$pm->equipment_id === (int)$eq->id) ? ' selected' : '' ?>>
                <?= htmlspecialchars((string)($eq->registration_no), ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string)($eq->brand . ' ' . $eq->model), ENT_QUOTES, 'UTF-8') ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-500">Schedule Name <span class="text-danger">*</span></label>
            <input type="text" name="schedule_name" class="form-control" required
                   placeholder="e.g. 250-Hour Service, Monthly Lubrication"
                   value="<?= $pm_val('schedule_name') ?>">
          </div>
          <div class="col-12">
            <label class="form-label fw-500">Description / Tasks</label>
            <textarea name="description" class="form-control" rows="2"
                      placeholder="What needs to be done during this PM..."><?= $pm_val('description') ?></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-500">Interval Type <span class="text-danger">*</span></label>
            <select name="interval_type" class="form-select" required>
              <?php foreach (array('Hours' => 'Hours (e.g. every 250 hrs)',
                                   'Days'  => 'Days (e.g. every 7 days)',
                                   'Months'=> 'Months (e.g. every 3 months)',
                                   'Km'    => 'Km (e.g. every 5000 km)') as $code => $label): ?>
              <option value="<?= $code ?>"<?= ($editing && $pm->interval_type === $code) ? ' selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-500">Interval Value <span class="text-danger">*</span></label>
            <input type="number" name="interval_value" class="form-control" required min="1"
                   placeholder="e.g. 250" value="<?= $pm_num('interval_value') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-500">Last Done Date</label>
            <input type="date" name="last_done_date" class="form-control" value="<?= $pm_val('last_done_date') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-500">Last Done Hours</label>
            <input type="number" name="last_done_hours" class="form-control" step="0.1"
                   placeholder="Hour meter when last done" value="<?= $pm_num('last_done_hours') ?>">
          </div>

          <?php if (!empty($has_standard_hours)): ?>
          <div class="col-md-6">
            <label class="form-label fw-500">Standard Hours <span class="fw-400 text-300">(planned duration)</span></label>
            <div class="input-group">
              <input type="number" name="standard_hours" class="form-control" step="0.5" min="0" max="999.99"
                     placeholder="e.g. 12" aria-describedby="std-hours-help" value="<?= $pm_num('standard_hours') ?>">
              <span class="input-group-text">h</span>
            </div>
            <div id="std-hours-help" class="form-text">
              How long this service takes the machine out of the shed. The Availability
              Forecast places this much planned downtime on the next due date and counts
              it as <strong>Preventive</strong>, which is excluded from the availability
              denominator. A service with no standard hours cannot be forecast.
            </div>
          </div>
          <?php endif; ?>

          <?php if ($editing): ?>
          <div class="col-md-4">
            <label class="form-label fw-500">Status</label>
            <select name="status" class="form-select">
              <?php foreach (array('Active', 'Inactive') as $st): ?>
              <option value="<?= $st ?>"<?= ($pm->status === $st) ? ' selected' : '' ?>><?= $st ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-8">
            <label class="form-label fw-500">Next Due</label>
            <div class="form-text" style="padding-top:6px;">
              <?php if (!empty($pm->next_due_date)): ?>
                Due <strong><?= $pm_val('next_due_date') ?></strong>
                <?php if (!empty($pm->next_due_hours)): ?> at <strong><?= $pm_num('next_due_hours') ?></strong> h<?php endif; ?>.
                Recalculated from the last-done date and interval when this form is saved.
              <?php else: ?>
                Never scheduled. It is calculated from the last-done date and interval when
                this form is saved, and by marking the service Done.
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <hr class="my-3">
        <div class="d-flex gap-2 justify-content-end">
          <a href="<?= base_url('pm') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Cancel</a>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1"></i><?= $editing ? 'Save Changes' : 'Create PM Schedule' ?>
          </button>
        </div>
        <?= form_close() ?>
      </div>
    </div>
  </div>
</div>
