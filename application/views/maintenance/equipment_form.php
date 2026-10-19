<div style="max-width:760px;margin:0 auto;">
  <div class="card">
    <div class="ch">
      <div><div class="ct"><?= isset($equipment) ? 'Edit Equipment: '.$equipment->registration_no : 'Add New Equipment' ?></div><div class="cs">Asset register</div></div>
      <a href="<?= base_url('equipment') ?>" class="ca">← Back</a>
    </div>
    <div style="padding:24px;">
      <?= form_open(isset($equipment)?'equipment/edit/'.$equipment->id:'equipment/create',['enctype'=>'multipart/form-data']) ?>
      <div class="form-grid cols-3" style="margin-bottom:16px;">
        <div>
          <label class="form-label">Registration No. <span class="req">*</span></label>
          <input type="text" name="registration_no" class="form-control" required style="text-transform:uppercase;" value="<?= isset($equipment)?$equipment->registration_no:'' ?>" placeholder="e.g. EX-001">
        </div>
        <div>
          <label class="form-label">Equipment Type <span class="req">*</span></label>
          <select name="equipment_type_id" class="form-select" required>
            <option value="">Select...</option>
            <?php if (isset($eq_types)) foreach ($eq_types as $t): ?>
            <option value="<?= $t->id ?>" <?= (isset($equipment)&&$equipment->equipment_type_id==$t->id)?'selected':'' ?>><?= htmlspecialchars((string)$t->name, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (equip_statuses() as $s): ?>
            <option value="<?= $s ?>" <?= (isset($equipment)&&$equipment->status==$s)?'selected':($s==='Available'?'selected':'') ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Brand <span class="req">*</span></label>
          <input type="text" name="brand" class="form-control" required value="<?= isset($equipment)?$equipment->brand:'' ?>" placeholder="e.g. Caterpillar">
        </div>
        <div>
          <label class="form-label">Model <span class="req">*</span></label>
          <input type="text" name="model" class="form-control" required value="<?= isset($equipment)?$equipment->model:'' ?>" placeholder="e.g. 320D">
        </div>
        <div>
          <label class="form-label">Year</label>
          <input type="number" name="year" class="form-control" min="1990" max="<?= date('Y')+1 ?>" value="<?= isset($equipment)?$equipment->year:date('Y') ?>">
        </div>
        <div>
          <label class="form-label">Serial Number</label>
          <input type="text" name="serial_number" class="form-control" value="<?= isset($equipment)?(isset($equipment->serial_number) ? $equipment->serial_number : ''):'' ?>">
        </div>
        <div>
          <label class="form-label">Location / Site</label>
          <?php
          // W0.5 — the LOCATION dropdown of the weekly report. The vocabulary is
          // reference data (`report_sites`), never a literal in this file; a value
          // outside it stays typeable but is flagged for the business to map.
          $sites = isset($report_sites) ? $report_sites : array();
          $cur   = isset($equipment) ? trim((string)$equipment->location) : '';
          $current_approved = $cur === '' || (function_exists('equip_is_approved_site') && equip_is_approved_site($cur, $sites));
          ?>
          <input type="text" name="location" class="form-control" list="w36-site-list"
                 value="<?= htmlspecialchars($cur) ?>" placeholder="e.g. WORKSHOP">
          <datalist id="w36-site-list">
            <?php foreach ($sites as $label => $code): ?>
            <option value="<?= htmlspecialchars($label) ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <?php if (!$current_approved): ?>
          <div class="cs" style="margin-top:4px;color:#ff9c8f;">
            "<?= htmlspecialchars($cur) ?>" is not one of the <?= count($sites) ?> approved sites — the weekly report flags it until it is mapped.
          </div>
          <?php elseif ($sites): ?>
          <div class="cs" style="margin-top:4px;"><?= count($sites) ?> approved sites — start typing to pick one.</div>
          <?php else: ?>
          <div class="cs" style="margin-top:4px;">The site list is not installed yet (see the W36 master-data migration).</div>
          <?php endif; ?>
        </div>
        <div>
          <label class="form-label">Current Hours</label>
          <input type="number" name="current_hours" class="form-control" step="0.1" value="<?= isset($equipment)?(isset($equipment->current_hours) ? $equipment->current_hours : '0'):'' ?>">
        </div>
        <div>
          <label class="form-label">Purchase Date</label>
          <input type="date" name="purchase_date" class="form-control" value="<?= isset($equipment)&&$equipment->purchase_date?$equipment->purchase_date:'' ?>">
        </div>
        <div>
          <label class="form-label">Warranty Expiry</label>
          <input type="date" name="warranty_expiry" class="form-control" value="<?= isset($equipment)&&$equipment->warranty_expiry?$equipment->warranty_expiry:'' ?>">
        </div>
        <div>
          <label class="form-label">Photo</label>
          <input type="file" name="photo" class="form-control" accept="image/*">
        </div>
      </div>

      <?php
      // ── W0.3   weekly-report classification ───────────────────────────
      // The codes/labels/order come from `report_groups` (seeded by the
      // weekly-report master-data migration); the sheet order is the one the business approved.
      $groups  = isset($report_groups) ? $report_groups : array();
      $cur_grp = isset($equipment->report_group) ? $equipment->report_group : '';
      $cur_srt = isset($equipment->report_sort)  ? (int)$equipment->report_sort : 0;
      $cur_inc = !isset($equipment) || !isset($equipment->include_in_weekly_report)
                 ? true : ((int)$equipment->include_in_weekly_report === 1);
      ?>
      <div style="border-top:1px solid var(--sep);margin:18px 0 14px;"></div>
      <div class="ct" style="font-size:13px;margin-bottom:10px;">Weekly Report</div>
      <div class="form-grid cols-3" style="margin-bottom:16px;">
        <div>
          <label class="form-label">Report Group</label>
          <?php if (!empty($groups)): ?>
          <select name="report_group" class="form-select">
            <option value="">— not classified —</option>
            <?php foreach ($groups as $code => $label): ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= $cur_grp === $code ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php else: ?>
          <input type="text" class="form-control" value="" disabled placeholder="report_groups not installed">
          <div class="cs" style="margin-top:4px;">Apply the weekly-report master-data migration to enable this list.</div>
          <?php endif; ?>
        </div>
        <div>
          <label class="form-label">Report Order</label>
          <input type="number" name="report_sort" class="form-control" min="0" step="1" value="<?= $cur_srt ?>">
          <div class="cs" style="margin-top:4px;">Position of this machine inside its group</div>
        </div>
        <div>
          <label class="form-label">Include in Weekly Report</label>
          <label class="flex ai-c" style="gap:7px;font-size:12.5px;color:var(--t2);padding-top:9px;">
            <input type="checkbox" name="include_in_weekly_report" value="1" <?= $cur_inc ? 'checked' : '' ?>>
            Included on the selected weekly report sheet
          </label>
          <div class="cs" style="margin-top:4px;">Retired machines are always excluded</div>
        </div>
      </div>
      <div style="margin-bottom:24px;">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="2"><?= isset($equipment)?htmlspecialchars((isset($equipment->notes) ? $equipment->notes : '')):'' ?></textarea>
      </div>
      <div class="flex ai-c gap-10" style="justify-content:flex-end;">
        <a href="<?= base_url('equipment') ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= isset($equipment)?'✓ Update Equipment':'+ Add Equipment' ?></button>
      </div>
      <?= form_close() ?>
    </div>
  </div>
</div>
