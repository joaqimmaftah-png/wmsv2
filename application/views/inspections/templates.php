<?php
$counts = isset($record_counts) ? $record_counts : array();
$labels = array('matrix' => 'Matrix (one column per equipment type)', 'compliance' => 'Compliance (Yes / No / N/A)');
?>
<div style="max-width:1100px;margin:0 auto;padding:24px 24px 60px;">

  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
      <div style="font-size:22px;font-weight:700;color:var(--t1);">Inspection Templates</div>
      <div style="font-size:12.5px;color:var(--t2);margin-top:2px;">
        The bilingual checklist rows behind every inspection form. Inactive templates disappear from the picker but keep their history.
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= base_url('inspections/template_edit') ?>"
         style="padding:9px 16px;border-radius:8px;font-size:12.5px;text-decoration:none;color:#fff;background:var(--blue);">+ New Template</a>
      <a href="<?= base_url('inspections') ?>"
         style="padding:9px 14px;border-radius:8px;font-size:12.5px;text-decoration:none;color:var(--t2);background:var(--surface);border:1px solid var(--sep);">← All Forms</a>
    </div>
  </div>

  <?php if ($this->session->flashdata('success')): ?>
  <div style="padding:10px 14px;border-radius:8px;background:var(--blue-fill);color:var(--blue);margin-bottom:16px;font-size:13px;">
    <?= htmlspecialchars((string)($this->session->flashdata('success'))) ?>
  </div>
  <?php endif; ?>
  <?php if ($this->session->flashdata('error')): ?>
  <div style="padding:10px 14px;border-radius:8px;background:var(--red-fill);color:var(--red);margin-bottom:16px;font-size:13px;">
    <?= htmlspecialchars((string)($this->session->flashdata('error'))) ?>
  </div>
  <?php endif; ?>

  <div class="card" style="overflow:hidden;">
    <table class="tbl" style="margin-top:0;">
      <thead>
        <tr>
          <th>Code</th><th>Name</th><th>Category</th><th>Layout</th><th>Records</th><th>Status</th><th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($templates)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--t3);">No templates yet.</td></tr>
        <?php else: foreach ($templates as $t): ?>
        <?php $records = isset($counts[$t->id]) ? (int)$counts[$t->id] : 0; ?>
        <tr>
          <td class="mono" style="font-size:11.5px;color:var(--blue);"><?= htmlspecialchars((string)($t->code)) ?></td>
          <td>
            <div class="fw-600" style="font-size:12.5px;"><?= htmlspecialchars((string)($t->name_en)) ?></div>
            <div style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)($t->name_fr)) ?></div>
          </td>
          <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)$t->category_en) ?></td>
          <td style="font-size:11.5px;color:var(--t3);"><?= isset($labels[$t->layout_type]) ? $labels[$t->layout_type] : htmlspecialchars((string)($t->layout_type)) ?></td>
          <td class="mono" style="font-size:12px;"><?= $records ?></td>
          <td>
            <?php if ((int)$t->active === 1): ?>
              <span class="badge b-success" style="font-size:10px;">Active</span>
            <?php else: ?>
              <span class="badge b-secondary" style="font-size:10px;">Inactive</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;white-space:nowrap;">
            <a href="<?= base_url('inspections/fill/'.$t->code.'/en') ?>" style="font-size:11.5px;margin-right:10px;">Fill</a>
            <a href="<?= base_url('inspections/records/'.$t->code) ?>" style="font-size:11.5px;margin-right:10px;">Records</a>
            <a href="<?= base_url('inspections/template_edit/'.$t->id) ?>" class="btn btn-outline-primary btn-sm"
               style="font-size:11px;padding:3px 10px;">Edit</a>
            <?php if ($records === 0): ?>
              <?= form_open('inspections/template_delete/'.$t->id, array('style' => 'display:inline;')) ?>
                <button type="submit" class="btn btn-outline-danger btn-sm" style="font-size:11px;padding:3px 10px;"
                        onclick="return confirm('Delete this template and its checklist rows?');">Delete</button>
              <?= form_close() ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div style="font-size:11px;color:var(--t3);margin-top:12px;">
    A template with submitted records cannot be deleted — set it inactive in the editor instead, so the
    historical inspections stay readable and exportable.
  </div>
</div>
