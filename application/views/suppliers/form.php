<?php
$s = isset($supplier) ? $supplier : (object)array();
$is_edit = !empty($s->id);
?>
<div style="max-width:760px;margin:0 auto;">
<div class="card">
  <div class="ch">
    <div>
      <div class="ct"><?= $is_edit ? 'Edit: '.htmlspecialchars($s->company_name) : 'Add Supplier' ?></div>
      <div class="cs"><?= $is_edit ? 'Update supplier profile' : 'Register a new supplier in the database' ?></div>
    </div>
    <a href="<?= base_url($is_edit ? 'suppliers/view/'.$s->id : 'suppliers') ?>" class="ca">&larr; Back</a>
  </div>
  <div style="padding:24px 28px 32px;">
    <?= form_open($is_edit ? 'suppliers/edit/'.$s->id : 'suppliers/create') ?>

    <div class="form-grid cols-2" style="margin-bottom:16px;">
      <div class="col-2">
        <label class="form-label">Company Name <span class="req">*</span></label>
        <input type="text" name="company_name" class="form-control" required
          value="<?= htmlspecialchars($s->company_name ?? '') ?>" placeholder="e.g. Guangzhou Parts Co. Ltd">
      </div>
      <div>
        <label class="form-label">Tax ID / Registration No.</label>
        <input type="text" name="tax_id" class="form-control" value="<?= htmlspecialchars($s->tax_id ?? '') ?>">
      </div>
      <div>
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php $cur = $s->status ?? 'Active'; foreach (array('Active','Inactive','Blacklisted') as $st): ?>
          <option value="<?= $st ?>" <?= ($cur===$st)?'selected':'' ?>><?= $st ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Country <span class="req">*</span></label>
        <input type="text" name="country" class="form-control" required list="country-list"
          value="<?= htmlspecialchars($s->country ?? '') ?>" placeholder="e.g. China, Tanzania, Uganda, Congo (DRC)">
        <datalist id="country-list">
          <option value="Tanzania"><option value="China"><option value="Uganda"><option value="Congo (DRC)"><option value="Kenya"><option value="South Africa"><option value="UAE">
        </datalist>
      </div>
      <div>
        <label class="form-label">City</label>
        <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($s->city ?? '') ?>">
      </div>
      <div class="col-2">
        <label class="form-label">Address</label>
        <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($s->address ?? '') ?>">
      </div>
    </div>

    <div class="sec-head">Commercial Terms</div>
    <div class="form-grid cols-3" style="margin-bottom:16px;">
      <div>
        <label class="form-label">Default Currency</label>
        <select name="currency" class="form-select">
          <?php $cur = isset($s->currency) && $s->currency !== '' ? strtoupper($s->currency) : wms_site_currency();
              foreach (wms_currencies() as $c): ?>
          <option value="<?= $c ?>" <?= ($cur===$c)?'selected':'' ?>><?= $c ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Payment Terms</label>
        <input type="text" name="payment_terms" class="form-control"
          value="<?= htmlspecialchars($s->payment_terms ?? '') ?>" placeholder="e.g. 50% advance, 50% on delivery">
      </div>
      <div>
        <label class="form-label">Quoted Lead Time (days)</label>
        <input type="number" name="lead_time_guarantee_days" class="form-control" min="0"
          value="<?= htmlspecialchars($s->lead_time_guarantee_days ?? '') ?>" placeholder="e.g. 21">
      </div>
    </div>

    <div class="sec-head">Notes</div>
    <div style="margin-bottom:28px;">
      <textarea name="notes" class="form-control" rows="3" placeholder="Any additional notes about this supplier"><?= htmlspecialchars($s->notes ?? '') ?></textarea>
    </div>

    <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;border-top:1px solid var(--sep);padding-top:16px;">
      <a href="<?= base_url($is_edit ? 'suppliers/view/'.$s->id : 'suppliers') ?>" class="btn btn-ghost">Cancel</a>
      <button type="submit" class="btn btn-primary"><?= $is_edit ? '✓ Save Changes' : '+ Add Supplier' ?></button>
    </div>
    <?= form_close() ?>
  </div>
</div>
</div>
