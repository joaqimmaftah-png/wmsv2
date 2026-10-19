<div class="flex ai-c jb mb-4">
  <div>
    <div class="page-heading">Leave Request</div>
    <div style="font-size:11px;color:var(--t3);margin-top:3px;">Submit a leave request for supervisor approval</div>
  </div>
  <a href="<?= base_url('team') ?>" class="btn btn-ghost">← Back to Team</a>
</div>

<div style="max-width:520px;">
<div class="card" style="padding:28px;">
  <?= form_open('team/leave_request') ?>
  <?= form_hidden('csrf_token_wms', $this->security->get_csrf_hash()) ?>

  <div style="margin-bottom:18px;">
    <label class="form-label">Leave Type <span class="req">*</span></label>
    <select name="leave_type" class="form-select" required>
      <option value="">Select leave type…</option>
      <?php
      $leave_types = array(
        'Annual Leave'   => 'Annual Leave',
        'Sick Leave'     => 'Sick Leave',
        'Emergency'      => 'Emergency',
        'Unpaid'         => 'Unpaid',
        'Maternity'      => 'Maternity',
        'Paternity'      => 'Paternity',
        'Training'       => 'Training',
      );
      foreach ($leave_types as $val => $label): ?>
      <option value="<?= $val ?>"><?= $label ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
    <div>
      <label class="form-label">Start Date <span class="req">*</span></label>
      <input type="date" name="start_date" class="form-control" required>
    </div>
    <div>
      <label class="form-label">End Date <span class="req">*</span></label>
      <input type="date" name="end_date" class="form-control" required>
    </div>
  </div>

  <div style="margin-bottom:22px;">
    <label class="form-label">Reason</label>
    <textarea name="reason" class="form-control" rows="3" placeholder="Brief reason for your leave request…"></textarea>
  </div>

  <div style="display:flex;gap:10px;justify-content:flex-end;">
    <a href="<?= base_url('team') ?>" class="btn btn-ghost">Cancel</a>
    <button type="submit" class="btn btn-primary">Submit Request</button>
  </div>

  <?= form_close() ?>
</div>
</div>
