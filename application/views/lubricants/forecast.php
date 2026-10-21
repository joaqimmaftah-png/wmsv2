<div class="wms-card">
  <div class="card-head"><i class="bi bi-graph-up-arrow text-primary me-1"></i>Lubricant Consumption Forecast (Based on PM Plan)</div>
  <div class="card-body">
    <?php if (empty($forecast)): ?>
      <div class="text-center py-5 text-muted">No forecast data available. Please set up PM schedules and lube specifications first.</div>
    <?php else: ?>
      <?php
      $this->load->model('Lubricant_model');
      $lube_types = $this->db->get('lube_types')->result();
      $lube_map = [];
      foreach ($lube_types as $lt) $lube_map[$lt->id] = $lt;
      ?>
      <div class="table-responsive">
        <table class="table wms-table mb-0">
          <thead>
            <tr>
              <th>Month</th>
              <?php foreach ($lube_map as $lt): ?>
              <th><?= htmlspecialchars((string)$lt->name, ENT_QUOTES, 'UTF-8') ?><br><span style="font-size:10px;color:#94a3b8;"><?= $lt->unit ?></span></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($forecast as $month => $lubes): ?>
            <tr <?= $month == date('Y-m') ? 'style="background:#dbeafe;"' : '' ?>>
              <td class="fw-600"><?= date('M Y', strtotime($month . '-01')) ?></td>
              <?php foreach ($lube_map as $lid => $lt): ?>
              <td style="font-size:13px;">
                <?= isset($lubes[$lid]) && $lubes[$lid] > 0 ? '<strong>' . number_format((float)($lubes[$lid]), 1) . '</strong> ' . $lt->unit : '<span class="text-muted">—</span>' ?>
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-3" style="font-size:12px;color:#64748b;">
        <i class="bi bi-info-circle me-1"></i>Forecast is based on scheduled PM work and standard lube specifications per equipment type.
      </div>
    <?php endif; ?>
  </div>
</div>
