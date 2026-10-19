<?php $filter = isset($filter) ? $filter : array(); ?>

<!-- Toolbar -->
<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <form method="get" class="flex ai-c gap-10" style="flex-wrap:wrap;">
      <select name="status" class="form-select" style="width:auto;min-width:140px;">
        <option value="">All Status</option>
        <?php foreach (array('Open','In Progress','Waiting Parts','Waiting Tools','Completed','Closed') as $s): ?>
        <option value="<?= $s; ?>" <?= (isset($filter['status']) && $filter['status']==$s)?'selected':''; ?>><?= $s; ?></option>
        <?php endforeach; ?>
      </select>
      <select name="type" class="form-select" style="width:auto;min-width:140px;">
        <option value="">All Types</option>
        <?php foreach (array('Corrective Maintenance','Preventive Maintenance','Incident') as $t): ?>
        <option value="<?= $t; ?>" <?= (isset($filter['type']) && $filter['type']==$t)?'selected':''; ?>><?= $t; ?></option>
        <?php endforeach; ?>
      </select>
      <select name="priority" class="form-select" style="width:auto;min-width:120px;">
        <option value="">All Priority</option>
        <?php foreach (array('Critical','High','Medium','Low') as $p): ?>
        <option value="<?= $p; ?>" <?= (isset($filter['priority']) && $filter['priority']==$p)?'selected':''; ?>><?= $p; ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="search" class="form-control" style="width:200px;" placeholder="Search..." value="<?= isset($filter['search'])?htmlspecialchars($filter['search']):''; ?>">
      <button type="submit" class="btn btn-primary btn-sm btn-icon">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Filter
      </button>
      <a href="<?= base_url('workorders'); ?>" class="btn btn-ghost btn-sm">Reset</a>
    </form>
  </div>
  <div class="flex ai-c gap-10">
    <a href="<?= base_url('reports/export_excel/workorders'); ?>" class="btn btn-success btn-sm btn-icon">
      <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export CSV
    </a>
    <a href="<?= base_url('workorders/create'); ?>" class="btn btn-primary btn-sm btn-icon">
      <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
      New WO
    </a>
  </div>
</div>

<div class="card">
  <div class="ch">
    <div><div class="ct">Work Orders</div><div class="cs"><?= count($work_orders); ?> records</div></div>
  </div>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:12px;">
      <thead>
        <tr>
          <th>WO Number</th><th>Equipment</th><th>Type</th><th>Priority</th><th>Title</th><th>Status</th><th>Assigned</th><th>Date</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (isset($work_orders) && count($work_orders) > 0): foreach ($work_orders as $wo):
          $pc = 'badge b-medium';
          if ($wo->priority==='Critical') $pc='badge b-critical';
          elseif ($wo->priority==='High') $pc='badge b-high';
          elseif ($wo->priority==='Low')  $pc='badge b-low';

          $sc = 'badge b-open';
          if ($wo->status==='In Progress')    $sc='badge b-progress';
          elseif ($wo->status==='Waiting Parts' || $wo->status==='Waiting Tools') $sc='badge b-waiting';
          elseif ($wo->status==='Completed')  $sc='badge b-completed';
          elseif ($wo->status==='Closed')     $sc='badge b-closed';
        ?>
        <tr>
          <td class="mono text-blue" style="font-size:11.5px;font-weight:500;"><?= htmlspecialchars($wo->wo_number); ?></td>
          <td class="fw-600" style="font-size:12.5px;"><?= htmlspecialchars(isset($wo->registration_no) ? $wo->registration_no : '—'); ?></td>
          <td><span class="badge b-primary" style="font-size:10px;"><?= htmlspecialchars($wo->type); ?></span></td>
          <td><span class="<?= $pc; ?>"><?= htmlspecialchars($wo->priority); ?></span></td>
          <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($wo->title); ?></td>
          <td>
            <span class="<?= $sc; ?>"><?= htmlspecialchars($wo->status); ?></span>
            <?php if (!empty($wo->hold_code)): ?>
            <div style="font-size:10px;color:var(--amber);margin-top:3px;white-space:nowrap;"
                 title="<?= htmlspecialchars((string)wo_hold_label($wo->hold_code)) ?>">
              ⏸ <?= htmlspecialchars($wo->hold_code); ?>
            </div>
            <?php endif; ?>
          </td>
          <td style="color:var(--t3);font-size:12px;"><?= htmlspecialchars(isset($wo->assigned_name) ? $wo->assigned_name : '—'); ?></td>
          <td class="mono" style="font-size:11px;color:var(--t3);"><?= $wo->created_at ? date('d M Y',strtotime($wo->created_at)) : '—'; ?></td>
          <td>
            <a href="<?= base_url('workorders/view/'.$wo->id); ?>" class="btn btn-ghost btn-sm" style="font-size:11px;padding:5px 12px;">View</a>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="9" style="text-align:center;padding:48px;color:var(--t3);">No work orders found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
