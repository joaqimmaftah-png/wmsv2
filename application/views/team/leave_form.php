<div class="flex ai-c jb mb-4">
  <div>
    <div class="page-heading">Leave Request</div>
    <div style="font-size:11px;color:var(--t3);margin-top:3px;">Submit a leave request for supervisor approval</div>
  </div>
  <a href="<?= base_url('team') ?>" class="btn btn-ghost">← Back to Team</a>
</div>

<?php
$tracked = array();
if (!empty($balances)) {
    foreach ($balances as $b) {
        if ($b['entitled'] !== null) $tracked[] = $b;
    }
}
$fmt_days = function ($n) { return rtrim((string)(rtrim((string)(number_format((float)$n, 1, '.', '')), '0')), '.'); };
?>

<?php if ($tracked): ?>
<div style="max-width:520px;margin-bottom:16px;">
  <div class="card" style="padding:18px 22px;">
    <div style="font-size:12px;font-weight:600;color:var(--t2);margin-bottom:10px;">
      Your leave balance <?= isset($leave_year) ? (int)$leave_year : date('Y') ?>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
      <thead>
        <tr style="text-align:left;color:var(--t3);font-size:11px;">
          <th style="padding:4px 0;">Type</th>
          <th style="text-align:right;">Entitled</th>
          <th style="text-align:right;">Used</th>
          <th style="text-align:right;">Pending</th>
          <th style="text-align:right;">Remaining</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tracked as $b): ?>
        <tr style="border-top:1px solid var(--sep);">
          <td style="padding:5px 0;"><?= htmlspecialchars((string)($b['type'])) ?></td>
          <td class="mono" style="text-align:right;"><?= $fmt_days($b['entitled']) ?></td>
          <td class="mono" style="text-align:right;"><?= $fmt_days($b['approved']) ?></td>
          <td class="mono" style="text-align:right;color:var(--amber);"><?= $fmt_days($b['pending']) ?></td>
          <td class="mono" style="text-align:right;font-weight:600;color:<?= ($b['remaining'] !== null && $b['remaining'] < 0) ? 'var(--red)' : 'var(--green)' ?>;">
            <?= $fmt_days($b['remaining']) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="font-size:10.5px;color:var(--t4);margin-top:8px;">
      Requests are not blocked by the balance — a supervisor decides. The balance is a guide.
    </div>
  </div>
</div>
<?php endif; ?>

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
