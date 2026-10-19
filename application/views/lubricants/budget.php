<?php $month_name = date('F', mktime(0,0,0,$month,1)) . ' ' . $year; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div></div>
  <form method="get" class="d-flex gap-2 align-items-end">
    <div>
      <label class="form-label mb-1" style="font-size:11px;">Year</label>
      <select name="year" class="form-select form-select-sm">
        <?php for ($y = date('Y'); $y >= date('Y')-2; $y--): ?>
        <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
        <option value="<?= date('Y')+1 ?>" <?= $year == date('Y')+1 ? 'selected' : '' ?>><?= date('Y')+1 ?></option>
      </select>
    </div>
    <div>
      <label class="form-label mb-1" style="font-size:11px;">Month</label>
      <select name="month" class="form-select form-select-sm">
        <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <button class="btn btn-primary btn-sm mt-auto">Load</button>
  </form>
</div>

<div class="wms-card">
  <div class="card-head"><i class="bi bi-currency-dollar text-primary me-1"></i>Lube Budget — <?= $month_name ?></div>
  <div class="card-body">
    <?= form_open('lubricants/budget') ?>
    <input type="hidden" name="year" value="<?= $year ?>">
    <input type="hidden" name="month" value="<?= $month ?>">

    <div class="table-responsive">
      <table class="table wms-table mb-0">
        <thead>
          <tr>
            <th>Lube Type</th>
            <th>Category</th>
            <th>Unit</th>
            <th>Budget Qty</th>
            <th>Unit Cost ($)</th>
            <th>Budget Cost ($)</th>
          </tr>
        </thead>
        <tbody>
          <?php $total_budget = 0; foreach ($lube_types as $lt):
            $b = (isset($budget_data[$lt->id]) ? $budget_data[$lt->id] : null);
            $b_qty  = $b ? $b->budget_qty : '';
            $b_cost = $b ? $b->unit_cost : '';
            $b_total= $b ? $b->budget_cost : 0;
            $total_budget += $b_total;
          ?>
          <tr>
            <td class="fw-500"><?= htmlspecialchars((string)$lt->name, ENT_QUOTES, 'UTF-8') ?></td>
            <td><span class="badge bg-primary bg-opacity-15 text-primary" style="font-size:11px;"><?= $lt->category ?></span></td>
            <td style="font-size:12px;color:#64748b;"><?= $lt->unit ?></td>
            <td>
              <input type="number" name="budget[<?= $lt->id ?>][qty]" class="form-control form-control-sm budget-qty" style="width:90px;" step="0.1" value="<?= $b_qty ?>" data-id="<?= $lt->id ?>">
            </td>
            <td>
              <input type="number" name="budget[<?= $lt->id ?>][cost]" class="form-control form-control-sm budget-cost" style="width:90px;" step="0.01" value="<?= $b_cost ?>" data-id="<?= $lt->id ?>">
            </td>
            <td>
              <span class="budget-total" id="total-<?= $lt->id ?>" style="font-weight:600;">
                <?= $b_total ? '$' . number_format($b_total, 2) : '—' ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
          <tr style="background:#f8fafc;">
            <td colspan="5" class="text-end fw-700">TOTAL BUDGET:</td>
            <td class="fw-700 text-primary" id="grandTotal">$<?= number_format($total_budget, 2) ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="mt-3 text-end">
      <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Budget</button>
    </div>
    <?= form_close() ?>
  </div>
</div>

<script>
document.querySelectorAll('.budget-qty, .budget-cost').forEach(input => {
  input.addEventListener('input', function() {
    const id = this.dataset.id;
    const qty  = parseFloat(document.querySelector(`.budget-qty[data-id="${id}"]`).value) || 0;
    const cost = parseFloat(document.querySelector(`.budget-cost[data-id="${id}"]`).value) || 0;
    const total = qty * cost;
    document.getElementById('total-' + id).textContent = total > 0 ? '$' + total.toFixed(2) : '—';

    // Update grand total
    let grand = 0;
    document.querySelectorAll('.budget-qty').forEach(q => {
      const qid  = q.dataset.id;
      const qval = parseFloat(q.value) || 0;
      const cval = parseFloat(document.querySelector(`.budget-cost[data-id="${qid}"]`).value) || 0;
      grand += qval * cval;
    });
    document.getElementById('grandTotal').textContent = '$' + grand.toFixed(2);
  });
});
</script>
