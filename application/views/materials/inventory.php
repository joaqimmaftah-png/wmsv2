<?php
// Pre-calculate totals safely
$t_init = 0; $t_recv = 0; $t_sort = 0; $t_fin = 0;
if (!empty($materials)) {
    foreach ($materials as $mx) {
        $t_init += (int)$mx->initial_stock;
        $t_recv += (int)$mx->qty_received;
        $t_sort += (int)$mx->qty_sortie;
        $t_fin  += (int)$mx->initial_stock + (int)$mx->qty_received - (int)$mx->qty_sortie;
    }
}
$f = isset($filter) ? $filter : array();
$role_id     = isset($role_id) ? $role_id : 0;
$can_import  = isset($can_import) ? $can_import : ($role_id <= 2);
$import_result  = isset($import_result) ? $import_result : null;
$import_history = isset($import_history) ? $import_history : array();

// Keep the on-screen filter in the export link (no filter = the whole catalogue)
$qs = array();
if (!empty($f['category'])) $qs['category'] = $f['category'];
if (!empty($f['location'])) $qs['location'] = $f['location'];
if (!empty($f['search']))   $qs['search']   = $f['search'];
$export_url = base_url('materials/export_xlsx').($qs ? '?'.http_build_query($qs) : '');

$n = function($k) use ($import_result) {
    return ($import_result && isset($import_result['summary'][$k])) ? (int)$import_result['summary'][$k] : 0;
};
?>

<!-- Toolbar -->
<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <form method="get" class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <select name="category" class="form-select" style="width:160px;">
      <option value="">All Categories</option>
      <?php foreach ($categories as $c): ?>
      <option value="<?= htmlspecialchars((string)($c->category)) ?>"
        <?= (!empty($f['category']) && $f['category'] == $c->category) ? 'selected' : '' ?>>
        <?= htmlspecialchars((string)($c->category)) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <select name="stock" class="form-select" style="width:140px;">
      <option value="">All Levels</option>
      <option value="ok"  <?= (!empty($f['stock']) && $f['stock'] === 'ok')  ? 'selected' : '' ?>>In Stock (&gt;5)</option>
      <option value="low" <?= (!empty($f['stock']) && $f['stock'] === 'low') ? 'selected' : '' ?>>Low (&le;5)</option>
      <option value="out" <?= (!empty($f['stock']) && $f['stock'] === 'out') ? 'selected' : '' ?>>Out of Stock</option>
    </select>
    <input type="text" name="search" class="form-control" style="width:190px;"
      placeholder="Search items…" value="<?= htmlspecialchars((string)(isset($f['search']) ? $f['search'] : '')) ?>">
    <button type="submit" class="btn btn-primary btn-sm btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Filter
    </button>
    <a href="<?= base_url('materials/inventory') ?>" class="btn btn-ghost btn-sm">Reset</a>
  </form>

  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <?php if ($can_import): ?>
    <a href="<?= base_url('materials/create') ?>" class="btn btn-primary btn-sm btn-icon"
       title="Add one inventory item through a form">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      Add Manually
    </a>
    <button type="button" class="btn btn-primary btn-sm btn-icon" onclick="invImportToggle()"
            title="Upload many inventory items at once from an Excel file">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Import from Excel
    </button>
    <?php endif; ?>
    <a href="<?= $export_url ?>" class="btn btn-success btn-sm btn-icon"
       title="Export the inventory list to Excel (reporting, review, backup, re-import)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export Excel
    </a>
    <a href="<?= base_url('materials/template_xlsx') ?>" class="btn btn-ghost btn-sm btn-icon"
       title="Empty workbook with the exact column structure used by the importer">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
      Download Template
    </a>
  </div>
</div>

<?php if ($qs): ?>
<div class="cs" style="margin:-8px 0 14px;color:var(--t3);">
  Filter active — Export Excel contains only the <?= count($materials) ?> filtered item(s).
  <a href="<?= base_url('materials/export_xlsx') ?>">Export the complete catalogue</a> instead.
</div>
<?php endif; ?>

<?php if ($import_result): ?>
<!-- Import result summary -->
<div class="card mb-4" id="importResult">
  <div class="ch" style="padding:12px 18px 10px;border-bottom:1px solid var(--g2);">
    <div>
      <div class="ct" style="font-size:13px;">
        Import summary<?= !empty($import_result['file_name']) ? ' — '.htmlspecialchars((string)($import_result['file_name'])) : '' ?>
      </div>
      <div class="cs">
        <?= $import_result['mode'] === 'validate' ? 'Validation only — nothing was saved' : 'Sheet "'.htmlspecialchars((string)($import_result['sheet'])).'"' ?>
        • duplicates: <?= htmlspecialchars((string)(isset($import_result['duplicate_action']) ? $import_result['duplicate_action'] : 'skip')) ?>
        • stock: <?= htmlspecialchars((string)(str_replace('ignore', 'unchanged', isset($import_result['stock_action']) ? $import_result['stock_action'] : 'ignore'))) ?>
        • <?= (isset($import_result['transaction']) && !$import_result['transaction']) ? 'no DB transaction support' : 'written in one transaction' ?>
      </div>
    </div>
    <?php if (!empty($import_result['report_token'])): ?>
    <a class="ca" href="<?= base_url('materials/import_report/'.$import_result['report_token']) ?>">Download full report ↓</a>
    <?php endif; ?>
  </div>
  <div style="padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;">
    <span class="badge b-secondary"><?= $n('total_rows') ?> rows read</span>
    <span class="badge b-completed"><?= $n('inserted') ?> new items</span>
    <span class="badge b-info"><?= $n('updated') ?> updated</span>
    <span class="badge b-warning"><?= $n('skipped') ?> skipped</span>
    <span class="badge b-secondary"><?= $n('duplicates') ?> duplicate(s)</span>
    <span class="badge b-critical"><?= $n('failed') ?> failed</span>
    <?php if ($n('missing_required')): ?><span class="badge b-low"><?= $n('missing_required') ?> missing required</span><?php endif; ?>
    <?php if ($n('invalid_values')): ?><span class="badge b-low"><?= $n('invalid_values') ?> invalid value(s)</span><?php endif; ?>
    <?php if ($n('warnings')): ?><span class="badge b-low"><?= $n('warnings') ?> warning(s)</span><?php endif; ?>
  </div>

  <?php if (!empty($import_result['rolled_back'])): ?>
  <div style="padding:0 18px 14px;color:var(--red);font-size:12.5px;font-weight:600;">
    ⚠ Nothing was imported — the change was rolled back. <?= htmlspecialchars((string)(isset($import_result['rollback_reason']) ? $import_result['rollback_reason'] : '')) ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($import_result['errors'])): ?>
  <div style="padding:0 18px 14px;color:var(--red);font-size:12.5px;">
    <?php foreach ($import_result['errors'] as $e): ?><div>• <?= htmlspecialchars((string)($e)) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if (!empty($import_result['warnings'])): ?>
  <div style="padding:0 18px 14px;color:var(--amber);font-size:12.5px;">
    <?php foreach ($import_result['warnings'] as $w): ?><div>• <?= htmlspecialchars((string)($w)) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($import_result['rows'])): ?>
  <?php $shown = array_slice($import_result['rows'], 0, 200); ?>
  <div style="overflow-x:auto;max-height:420px;">
    <table class="tbl">
      <thead>
        <tr><th style="width:60px;">ROW</th><th>ITEM CODE</th><th>DESCRIPTION</th>
            <th style="width:100px;">RESULT</th><th>REASON / DETAIL</th><th>WARNINGS</th></tr>
      </thead>
      <tbody>
      <?php foreach ($shown as $r):
        $act = $r['action'];
        $cls = $act === 'inserted' ? 'b-completed' : ($act === 'updated' ? 'b-info'
             : ($act === 'skipped' ? 'b-warning' : ($act === 'failed' ? 'b-critical' : 'b-secondary')));
      ?>
      <tr>
        <td class="mono" style="color:var(--t3);"><?= (int)$r['row'] ?></td>
        <td class="mono fw-600"><?= htmlspecialchars((string)($r['item_code'])) ?></td>
        <td style="font-size:11.5px;color:var(--t3);"><?= htmlspecialchars((string)(isset($r['description']) ? $r['description'] : '')) ?></td>
        <td><span class="badge <?= $cls ?>"><?= strtoupper((string)($act)) ?><?= !empty($r['rolled_back']) ? ' (ROLLED BACK)' : '' ?></span></td>
        <td style="font-size:11.5px;color:var(--t2);">
          <?= htmlspecialchars((string)(!empty($r['errors']) ? implode(' | ', $r['errors']) : '')) ?>
          <?php if (!empty($r['stock_note'])): ?>
            <div style="color:var(--t3);"><?= htmlspecialchars((string)($r['stock_note'])) ?></div>
          <?php endif; ?>
        </td>
        <td style="font-size:11.5px;color:var(--amber);"><?= htmlspecialchars((string)(implode(' | ', isset($r['warnings']) ? $r['warnings'] : array()))) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($import_result['rows']) > count($shown)): ?>
  <div style="padding:10px 18px;font-size:11.5px;color:var(--t3);">
    Showing the first <?= count($shown) ?> of <?= count($import_result['rows']) ?> rows —
    <a href="<?= base_url('materials/import_report/'.$import_result['report_token']) ?>">download the full report</a>.
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($n('failed') > 0 || $n('skipped') > 0): ?>
  <div style="padding:0 18px 18px;">
    <a href="<?= base_url('materials/import_report/'.$import_result['report_token']) ?>" class="btn btn-ghost btn-sm">
      Download error report (failed rows ready to correct &amp; re-import)
    </a>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($can_import): ?>
<!-- Import panel -->
<div class="card mb-4" id="inventoryImportPanel" style="display:none;">
  <div class="ch" style="padding:12px 18px 10px;border-bottom:1px solid var(--g2);">
    <div>
      <div class="ct" style="font-size:13px;">Import inventory from Excel</div>
      <div class="cs">Upload a workbook prepared with the import template (.xlsx) — many items in one batch</div>
    </div>
    <a class="ca" href="<?= base_url('materials/template_xlsx') ?>">Download template ↓</a>
  </div>
  <?= form_open_multipart('materials/import_excel') ?>
    <div style="padding:18px;display:grid;gap:16px;grid-template-columns:1.3fr 1.1fr 1.1fr;">
      <div>
        <label class="form-label">Excel file (.xlsx) <span class="req">*</span></label>
        <input type="file" name="inventory_file" class="form-control" accept=".xlsx" required>
        <div class="cs" style="margin-top:6px;">
          Required columns: <b>Item Code</b> and <b>Description</b>. Optional: Category, Part No / Size, Unit,
          Opening Stock, Application, Location. In an exported workbook the importer reads the
          <b>“Import Data”</b> sheet (report columns such as Qty Received or Final Stock are ignored — stock
          quantities are never taken from them).
        </div>
      </div>
      <div>
        <label class="form-label">If the Item Code already exists</label>
        <select name="duplicate_action" class="form-select">
          <option value="skip" selected>Skip it — keep the current record (safest)</option>
          <option value="update">Update the existing item with the file values</option>
          <option value="fail">Reject the row and report it as an error</option>
        </select>
        <div class="cs" style="margin-top:6px;">Blank cells never clear an existing value.</div>
      </div>
      <div>
        <label class="form-label">Stock quantity (Opening Stock column)</label>
        <select name="stock_action" class="form-select">
          <option value="ignore" selected>Leave the stock of existing items unchanged (safest)</option>
          <option value="receive">Add it to stock as a received movement</option>
          <option value="set">Replace the opening balance (items with no movements yet)</option>
        </select>
        <div class="cs" style="margin-top:6px;">New items always start with the opening balance in the file, logged as a movement.</div>
      </div>
    </div>
    <div style="padding:0 18px 6px;display:flex;gap:22px;flex-wrap:wrap;">
      <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;">
        <input type="checkbox" name="allow_new_categories" value="1">
        Allow new categories
      </label>
      <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;">
        <input type="checkbox" name="allow_new_units" value="1">
        Allow new units
      </label>
      <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;">
        <input type="checkbox" name="allow_duplicate_records" value="1">
        Allow duplicate records (same description/part/unit, different code)
      </label>
      <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;">
        <input type="checkbox" name="dry_run" value="1">
        Validate only (dry run) — change nothing
      </label>
    </div>
    <div style="padding:14px 18px 18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary btn-icon">
        <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Start import
      </button>
      <button type="button" class="btn btn-ghost" onclick="invImportToggle()">Cancel</button>
      <span class="cs">Every row is reported with its Excel row number: new, updated, skipped or failed —
      good rows are imported even when other rows fail, and a database error rolls the whole file back.</span>
    </div>
  <?= form_close() ?>
</div>
<script>
function invImportToggle(){
  var p = document.getElementById('inventoryImportPanel');
  if (!p) return;
  p.style.display = (p.style.display === 'none' || !p.style.display) ? 'block' : 'none';
  if (p.style.display === 'block') p.scrollIntoView({behavior:'smooth', block:'start'});
}
<?php if ($import_result): ?>
document.addEventListener('DOMContentLoaded', function(){
  var r = document.getElementById('importResult');
  if (r) r.scrollIntoView({behavior:'smooth', block:'start'});
});
<?php endif; ?>
// Allow deep links such as materials/inventory#import from the dashboard.
if (location.hash === '#import') invImportToggle();
</script>
<?php endif; ?>

<?php if (!empty($import_history)): ?>
<div class="cs" style="margin:-6px 0 16px;color:var(--t3);">
  Recent imports:
  <?php $h = array(); foreach ($import_history as $row) {
      $h[] = date('d/m/Y H:i', strtotime($row->created_at)).' — '.htmlspecialchars((string)($row->file_name))
           .' ('.(int)$row->inserted.' new, '.(int)$row->updated.' updated, '.(int)$row->failed.' failed)';
  } echo implode(' • ', $h); ?>
</div>
<?php endif; ?>

<!-- KPI strip -->
<div class="grid g-4 mb-4">
  <div class="kpi" style="animation-delay:.04s">
    <div class="kpi-glow" style="background:var(--blue)"></div>
    <span class="kpi-tag">Total Items</span>
    <div class="kpi-num"><?= count($materials) ?></div>
  </div>
  <div class="kpi" style="animation-delay:.08s">
    <div class="kpi-glow" style="background:var(--green)"></div>
    <span class="kpi-tag">Total Received</span>
    <div class="kpi-num"><?= number_format((float)($t_recv)) ?></div>
  </div>
  <div class="kpi" style="animation-delay:.12s">
    <div class="kpi-glow" style="background:var(--amber)"></div>
    <span class="kpi-tag">Total Issued</span>
    <div class="kpi-num"><?= number_format((float)($t_sort)) ?></div>
  </div>
  <div class="kpi" style="animation-delay:.16s">
    <div class="kpi-glow" style="background:var(--cyan)"></div>
    <span class="kpi-tag">In Stock</span>
    <div class="kpi-num"><?= number_format((float)($t_fin)) ?></div>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="ch">
    <div>
      <div class="ct">Inventory List</div>
      <div class="cs"><?= count($materials) ?> items</div>
    </div>
  </div>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:8px;">
      <thead>
        <tr>
          <th>Item Code</th>
          <th>Category</th>
          <th>Description</th>
          <th>Part No / Size</th>
          <th>Unit</th>
          <th style="text-align:right;">Initial</th>
          <th style="text-align:right;">Received</th>
          <th style="text-align:right;">Issued</th>
          <th style="text-align:right;">In Stock</th>
          <th>Location</th>
          <?php if ($role_id <= 2): ?><th style="width:130px;"></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($materials)): ?>
        <tr>
          <td colspan="11" style="text-align:center;padding:52px;color:var(--t3);">
            No inventory items found
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($materials as $m):
          $final = (int)$m->initial_stock + (int)$m->qty_received - (int)$m->qty_sortie;
          if ($final <= 0)     { $clr = 'var(--red)';   $badge = '<span class="badge b-danger" style="font-size:9px;margin-left:4px;">OUT</span>'; }
          elseif ($final <= 5) { $clr = 'var(--amber)'; $badge = '<span class="badge b-warning" style="font-size:9px;margin-left:4px;">LOW</span>'; }
          else                 { $clr = 'var(--t1)';    $badge = ''; }
        ?>
        <tr>
          <td style="font-weight:600;color:var(--blue);font-family:inherit,monospace;font-size:12px;">
            <?= htmlspecialchars((string)($m->item_code)) ?>
          </td>
          <td>
            <span class="badge b-primary" style="font-size:9.5px;">
              <?= htmlspecialchars((string)($m->category)) ?>
            </span>
          </td>
          <td style="font-weight:500;"><?= htmlspecialchars((string)($m->description)) ?></td>
          <td style="font-size:11.5px;color:var(--t3);">
            <?= htmlspecialchars((string)($m->part_number_size !== null ? $m->part_number_size : '')) ?>
          </td>
          <td style="text-align:center;color:var(--t3);font-size:11.5px;"><?= htmlspecialchars((string)($m->unit)) ?></td>
          <td style="text-align:right;font-family:inherit,monospace;font-size:12px;"><?= number_format((int)$m->initial_stock) ?></td>
          <td style="text-align:right;font-family:inherit,monospace;font-size:12px;color:var(--green);"><?= number_format((int)$m->qty_received) ?></td>
          <td style="text-align:right;font-family:inherit,monospace;font-size:12px;color:var(--amber);"><?= number_format((int)$m->qty_sortie) ?></td>
          <td style="text-align:right;">
            <span style="font-family:inherit,monospace;font-size:13px;font-weight:700;color:<?= $clr ?>;"><?= number_format((float)($final)) ?></span>
            <?= $badge ?>
          </td>
          <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)($m->location !== null ? $m->location : '')) ?></td>
          <?php if ($role_id <= 2): ?>
          <td>
            <div class="flex ai-c gap-10" style="justify-content:center;">
              <a href="<?= base_url('materials/receive/'.$m->id) ?>" class="btn btn-success btn-xs">+In</a>
              <a href="<?= base_url('materials/issue/'.$m->id) ?>"   class="btn btn-amber btn-xs">-Out</a>
              <a href="<?= base_url('materials/edit/'.$m->id) ?>"    class="btn btn-ghost btn-xs">Edit</a>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr style="background:var(--g2);border-top:1px solid var(--sep);">
          <td colspan="5" style="padding:10px 18px;font-weight:700;font-size:12px;color:var(--t2);">TOTAL</td>
          <td style="text-align:right;font-weight:700;font-family:inherit,monospace;padding:10px 18px;"><?= number_format((float)($t_init)) ?></td>
          <td style="text-align:right;font-weight:700;font-family:inherit,monospace;padding:10px 18px;color:var(--green);"><?= number_format((float)($t_recv)) ?></td>
          <td style="text-align:right;font-weight:700;font-family:inherit,monospace;padding:10px 18px;color:var(--amber);"><?= number_format((float)($t_sort)) ?></td>
          <td style="text-align:right;font-weight:700;font-family:inherit,monospace;padding:10px 18px;"><?= number_format((float)($t_fin)) ?></td>
          <td colspan="<?= ($role_id <= 2) ? 2 : 1 ?>"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
