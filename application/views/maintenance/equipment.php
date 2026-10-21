<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <form method="get" class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <input type="text" name="search" class="form-control" style="width:auto;min-width:190px;"
           placeholder="Search Machine ID, SN, Brand, Model…"
           value="<?= htmlspecialchars((string)($filter['search'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <select name="status" class="form-select" style="width:auto;min-width:135px;">
      <option value="">All Status</option>
      <?php foreach (equip_statuses() as $s): ?>
      <option value="<?= $s ?>" <?= (isset($filter['status'])&&$filter['status']==$s)?'selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type" class="form-select" style="width:auto;min-width:140px;">
      <option value="">All Types</option>
      <?php if (isset($eq_types)) foreach ($eq_types as $t): ?>
      <option value="<?= $t->id ?>" <?= (isset($filter['type'])&&$filter['type']==$t->id)?'selected':'' ?>><?= htmlspecialchars((string)$t->name, ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (!empty($eq_categories)): ?>
    <select name="category" class="form-select" style="width:auto;min-width:140px;">
      <option value="">All Categories</option>
      <?php foreach ($eq_categories as $cat): ?>
      <option value="<?= htmlspecialchars((string)$cat, ENT_QUOTES, 'UTF-8') ?>" <?= (isset($filter['category'])&&$filter['category']===$cat)?'selected':'' ?>><?= htmlspecialchars((string)$cat, ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if (!empty($report_groups)): ?>
    <select name="report_group" class="form-select" style="width:auto;min-width:150px;">
      <option value="">All Report Groups</option>
      <?php foreach ($report_groups as $code => $label): ?>
      <option value="<?= htmlspecialchars((string)($code)) ?>" <?= (isset($filter['report_group'])&&$filter['report_group']===$code)?'selected':'' ?>><?= htmlspecialchars((string)($label)) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if (!empty($report_sites)): ?>
    <select name="location" class="form-select" style="width:auto;min-width:150px;">
      <option value="">All Locations</option>
      <?php foreach ($report_sites as $label => $code): ?>
      <option value="<?= htmlspecialchars((string)($label)) ?>" <?= (isset($filter['location'])&&$filter['location']===$label)?'selected':'' ?>><?= htmlspecialchars((string)($label)) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <label class="flex ai-c" style="gap:6px;font-size:12px;color:var(--t2);white-space:nowrap;">
      <input type="checkbox" name="reportable" value="1" <?= !empty($filter['reportable'])?'checked':'' ?>>
      Weekly report only
    </label>
    <button type="submit" class="btn btn-primary btn-sm btn-icon">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Filter
    </button>
    <a href="<?= base_url('equipment') ?>" class="btn btn-ghost btn-sm">Reset</a>
  </form>
  <a href="<?= base_url('equipment/create') ?>" class="btn btn-primary btn-sm btn-icon">
    <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
    Add Equipment
  </a>
</div>

<?php if (isset($reportability) && $reportability['total'] > 0): ?>
<div class="cs" style="margin-bottom:10px;">
  <?= (int)$reportability['reportable'] ?> of <?= (int)$reportability['total'] ?> machines are in the weekly report
  <?php if (!empty($reportability['not_reportable'])): ?> &middot; <?= (int)$reportability['not_reportable'] ?> excluded<?php endif; ?>
  <?php if (!empty($reportability['unclassified'])): ?>
    &middot; <span style="color:var(--amber);"><?= (int)$reportability['unclassified'] ?> without a report group</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($site_summary) && $site_summary['machines'] > 0): ?>
<div class="cs" style="margin-bottom:10px;">
  <?php $worst = $site_summary['unapproved']; $top = $worst ? array_slice($worst, 0, 3, true) : array(); ?>
  Sites: <?= count(array_filter($site_summary['per_site'])) ?> of <?= count($site_summary['per_site']) ?> approved sites in use
  <?php if (!empty($site_summary['blank'])): ?> &middot; <?= (int)$site_summary['blank'] ?> machine(s) with no site<?php endif; ?>
  <?php if (!empty($worst)): ?>
    &middot; <span style="color:var(--amber);"><?= array_sum($worst) ?> machine(s) hold a value that is not an approved site</span>
    <?php if ($top): ?>(<?php $bits = array(); foreach ($top as $name => $n) $bits[] = htmlspecialchars((string)($name)).'&nbsp;×'.$n; echo implode(', ', $bits); ?>)<?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Equipment Grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">
  <?php if (isset($equipment) && count($equipment) > 0): foreach ($equipment as $eq):
    // W0.2: led / icon background / badge come from one map
    $sm  = equip_status_map($eq->status);
    $led = $sm['led']; $ibg = $sm['bg']; $sc = $sm['badge'];
  ?>
  <a href="<?= base_url('equipment/view/'.$eq->id) ?>" style="text-decoration:none;">
  <div class="card" style="cursor:pointer;padding:0;transition:transform .25s cubic-bezier(0.34,1.56,0.64,1),box-shadow .25s;" onmouseover="this.style.transform='translateY(-4px)';this.style.boxShadow='0 16px 40px rgba(0,0,0,0.35)';" onmouseout="this.style.transform='';this.style.boxShadow='';">
    <div style="padding:20px 20px 0;">
      <div class="flex ai-c jb mb-3">
        <div style="width:40px;height:40px;border-radius:11px;background:<?= $ibg ?>;display:flex;align-items:center;justify-content:center;font-size:20px;">⚙️</div>
        <div class="flex ai-c" style="gap:6px;">
          <?php if (!equip_is_reportable($eq)): ?>
          <span class="badge b-secondary" title="Excluded from the weekly report">not in weekly report</span>
          <?php elseif (isset($eq->report_group) && $eq->report_group): ?>
          <span class="badge b-info" title="Weekly report group"><?= htmlspecialchars((string)($eq->report_group)) ?></span>
          <?php else: ?>
          <span class="badge b-warning" title="Reportable but not classified yet">no group</span>
          <?php endif; ?>
          <span class="badge <?= $sc ?>"><?= $eq->status ?></span>
        </div>
      </div>
      <div class="fw-600 mono" style="font-size:16px;letter-spacing:-0.3px;"><?= htmlspecialchars((string)($eq->registration_no), ENT_QUOTES, 'UTF-8') ?></div>
      <div style="font-size:11.5px;color:var(--blue);font-weight:600;margin-top:2px;">
        <?= htmlspecialchars((string)($eq->type_name ?: 'Other'), ENT_QUOTES, 'UTF-8') ?><?= !empty($eq->category) ? ' &middot; ' . htmlspecialchars((string)$eq->category, ENT_QUOTES, 'UTF-8') : '' ?>
      </div>
      <div style="font-size:12px;color:var(--t2);margin-top:3px;"><?= htmlspecialchars((string)(trim((string)$eq->brand . ' ' . (string)$eq->model) ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
      <div class="mono" style="font-size:11px;color:var(--t3);margin-top:3px;">
        SN: <?= htmlspecialchars((string)($eq->serial_number ?: '—'), ENT_QUOTES, 'UTF-8') ?><?= !empty($eq->engine_model) ? ' &middot; Eng: ' . htmlspecialchars((string)$eq->engine_model, ENT_QUOTES, 'UTF-8') : '' ?>
      </div>
      <div style="font-size:11px;color:var(--t3);margin-top:3px;">
        <?php
        // W0.5: the site is shown with the report's own vocabulary; a value that
        // is not an approved site says so instead of silently looking normal.
        $loc = trim((string)(isset($eq->location) ? $eq->location : ''));
        if ($loc === '') {
            echo '<span style="color:var(--amber);">no site</span>';
        } elseif (!empty($report_sites) && equip_is_approved_site($loc, $report_sites)) {
            echo htmlspecialchars((string)(equip_site_label($loc, $report_sites)));
        } else {
            echo htmlspecialchars((string)($loc)) . ' <span class="badge b-warning" title="Not one of the approved sites">not approved</span>';
        }
        ?>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1px;background:var(--sep);margin:14px 0 0;">
      <div style="background:var(--surface);padding:10px 10px;text-align:center;">
        <div class="mono" style="font-size:14px;font-weight:500;color:var(--t1);"><?= number_format((float)($eq->current_hours),0) ?></div>
        <div style="font-size:9.5px;color:var(--t3);font-weight:600;text-transform:none;letter-spacing:0;margin-top:2px;">Current Hours</div>
      </div>
      <div style="background:var(--surface);padding:10px 10px;text-align:center;">
        <div class="mono" style="font-size:14px;font-weight:500;color:var(--t1);"><?= number_format((float)($eq->current_km),0) ?></div>
        <div style="font-size:9.5px;color:var(--t3);font-weight:600;text-transform:none;letter-spacing:0;margin-top:2px;">Current KM</div>
      </div>
      <div style="background:var(--surface);padding:10px 10px;text-align:center;">
        <div class="mono" style="font-size:14px;font-weight:500;color:var(--t1);"><?= $eq->year ?: '—' ?></div>
        <div style="font-size:9.5px;color:var(--t3);font-weight:600;text-transform:none;letter-spacing:0;margin-top:2px;">Year</div>
      </div>
    </div>
  </div>
  </a>
  <?php endforeach; else: ?>
  <div style="grid-column:1/-1;padding:60px;text-align:center;color:var(--t3);">No equipment records found</div>
  <?php endif; ?>
</div>
