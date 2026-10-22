<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$h = function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};

$materials       = isset($materials) && is_array($materials) ? $materials : array();
$categories      = isset($categories) && is_array($categories) ? $categories : array();
$locations       = isset($locations) && is_array($locations) ? $locations : array();
$suppliers       = isset($suppliers) && is_array($suppliers) ? $suppliers : array();
$f               = isset($filter) && is_array($filter) ? $filter : array();
$role_id         = isset($role_id) ? (int)$role_id : 0;
$can_write       = isset($can_write) ? (bool)$can_write : in_array($role_id, array(1, 2, 3), TRUE);
$can_import      = isset($can_import) ? (bool)$can_import : $can_write;
$is_supervisor   = isset($is_supervisor) ? (bool)$is_supervisor : ($role_id === 1);
$import_result   = isset($import_result) ? $import_result : NULL;
$import_history  = isset($import_history) && is_array($import_history) ? $import_history : array();

$tab            = isset($tab) ? (string)$tab : 'all';
$q              = isset($q) ? (string)$q : (isset($f['search']) ? (string)$f['search'] : '');
$sort           = isset($sort) ? (string)$sort : 'default';
$dir            = isset($dir) ? (string)$dir : 'asc';
$view_mode      = isset($view_mode) && $view_mode === 'cards' ? 'cards' : 'table';
$per_page       = isset($per_page) ? (int)$per_page : 25;
$allowed_pp     = isset($allowed_pp) && is_array($allowed_pp) ? $allowed_pp : array(10, 25, 50, 100, 150);
$current_page   = isset($current_page) ? (int)$current_page : 1;
$total_pages    = isset($total_pages) ? (int)$total_pages : 1;
$total_filtered = isset($total_filtered) ? (int)$total_filtered : count($materials);
$offset         = isset($offset) ? (int)$offset : 0;

// Fallback if view rendered directly without _build_inventory_dataset()
$pInit = 0; $pRecv = 0; $pSort = 0; $pBal = 0;
foreach ($materials as $mx) {
    $bal = isset($mx->balance) ? (int)$mx->balance : ((int)$mx->initial_stock + (int)$mx->qty_received - (int)$mx->qty_sortie);
    $pInit += (int)$mx->initial_stock;
    $pRecv += (int)$mx->qty_received;
    $pSort += (int)$mx->qty_sortie;
    $pBal  += $bal;
}
$filtered_totals = isset($filtered_totals) && is_array($filtered_totals)
    ? $filtered_totals
    : array('initial' => $pInit, 'received' => $pRecv, 'issued' => $pSort, 'balance' => $pBal);

$kpi = isset($kpi) && is_array($kpi) ? $kpi : array(
    'total_items'    => $total_filtered,
    'in_stock_items' => 0,
    'low_stock'      => 0,
    'out_of_stock'   => 0,
    'total_units'    => max(0, $pBal),
    'total_received' => $pRecv,
    'total_issued'   => $pSort,
    'reorder_needed' => 0,
);
$tab_counts = isset($tab_counts) && is_array($tab_counts) ? $tab_counts : array(
    'all'      => $total_filtered,
    'in_stock' => 0,
    'low'      => 0,
    'out'      => 0,
    'movers'   => 0,
    'valued'   => 0,
);

$g = function ($k) use ($f) {
    return isset($f[$k]) ? (string)$f[$k] : '';
};

$baseParams = array();
if ($tab !== 'all')            $baseParams['tab']      = $tab;
if ($q !== '')                 $baseParams['q']        = $q;
if ($g('category') !== '')     $baseParams['category'] = $g('category');
if ($g('stock') !== '')        $baseParams['stock']    = $g('stock');
if ($g('location') !== '')     $baseParams['location'] = $g('location');
if ($g('supplier') !== '')     $baseParams['supplier'] = $g('supplier');
if ($sort !== 'default') {
    $baseParams['sort'] = $sort;
    $baseParams['dir']  = $dir;
}
if ($view_mode !== 'table')    $baseParams['view']     = $view_mode;
if ($per_page !== 25)          $baseParams['per_page'] = $per_page;

$buildUrl = function ($overrides = array(), $endpoint = 'materials/inventory') use ($baseParams) {
    $p = array_merge($baseParams, $overrides);
    foreach ($p as $k => $v) {
        if ($v === '' || $v === NULL || ($k === 'tab' && $v === 'all') || ($k === 'page' && (int)$v <= 1)) {
            unset($p[$k]);
        }
    }
    $qs = http_build_query($p);
    return base_url($endpoint) . ($qs !== '' ? '?' . $qs : '');
};

$csvExportUrl  = $buildUrl(array('page' => NULL), 'materials/export_csv');
$xlsxExportUrl = $buildUrl(array('page' => NULL), 'materials/export_xlsx');

$activeChips = array();
if ($q !== '')                 $activeChips[] = array('label' => 'Search: ' . $q,             'url' => $buildUrl(array('q' => '', 'page' => 1)));
if ($g('category') !== '')     $activeChips[] = array('label' => 'Category: ' . $g('category'), 'url' => $buildUrl(array('category' => '', 'page' => 1)));
if ($g('stock') !== '')        $activeChips[] = array('label' => 'Stock: ' . strtoupper($g('stock')), 'url' => $buildUrl(array('stock' => '', 'page' => 1)));
if ($g('location') !== '')     $activeChips[] = array('label' => 'Bin: ' . $g('location'),    'url' => $buildUrl(array('location' => '', 'page' => 1)));
if ($g('supplier') !== '')     $activeChips[] = array('label' => 'Supplier: ' . $g('supplier'), 'url' => $buildUrl(array('supplier' => '', 'page' => 1)));

$sortTh = function ($key, $label, $align = 'left') use ($sort, $dir, $buildUrl, $h) {
    $active  = ($sort === $key);
    $nextDir = ($active && $dir === 'asc') ? 'desc' : 'asc';
    $arrow   = $active ? ($dir === 'asc' ? '↑' : '↓') : '↕';
    $aria    = $active ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $cls     = ($align === 'right' ? 'num ' : ($align === 'center' ? 'ctr ' : '')) . ($active ? 'is-sorted' : '');
    return '<th class="' . trim($cls) . '" aria-sort="' . $aria . '">'
        . '<a class="inv-sort-link" href="' . $h($buildUrl(array('sort' => $key, 'dir' => $nextDir, 'page' => 1))) . '">'
        . '<span>' . $h($label) . '</span><span class="inv-sort-arrow">' . $arrow . '</span></a></th>';
};

$n = function ($k) use ($import_result) {
    return ($import_result && isset($import_result['summary'][$k])) ? (int)$import_result['summary'][$k] : 0;
};
?>

<style>
  #invShell {
    --inv-bg: #F8FAFC;
    --inv-surface: #FFFFFF;
    --inv-border: #E2E8F0;
    --inv-border-strong: #CBD5E1;
    --inv-text: #0F172A;
    --inv-muted: #475569;
    --inv-subtle: #64748B;
    --inv-accent: #1D4ED8;
    --inv-accent-soft: #EFF6FF;
    --inv-ok-bg: #ECFDF5;
    --inv-ok-fg: #047857;
    --inv-ok-bd: #A7F3D0;
    --inv-warn-bg: #FFFBEB;
    --inv-warn-fg: #B45309;
    --inv-warn-bd: #FDE68A;
    --inv-crit-bg: #FEF2F2;
    --inv-crit-fg: #B91C1C;
    --inv-crit-bd: #FECACA;
    color: var(--inv-text);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    max-width: 100%;
  }
  #invShell * { box-sizing: border-box; }

  .inv-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 14px; flex-wrap: wrap; margin-bottom: 16px;
  }
  .inv-title { font-size: 20px; font-weight: 700; letter-spacing: -0.02em; margin: 0; color: var(--inv-text); }
  .inv-subtitle { font-size: 12.5px; color: var(--inv-subtle); margin-top: 3px; }
  .inv-actions { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }

  .inv-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    height: 34px; padding: 0 12px; border-radius: 8px;
    border: 1px solid var(--inv-border-strong); background: var(--inv-surface);
    color: var(--inv-text); font-size: 12.5px; font-weight: 600;
    text-decoration: none; cursor: pointer; white-space: nowrap;
    transition: background .14s ease, border-color .14s ease, color .14s ease;
  }
  .inv-btn:hover { background: #F1F5F9; border-color: #94A3B8; color: var(--inv-text); }
  .inv-btn-primary { background: var(--inv-accent); border-color: var(--inv-accent); color: #FFFFFF; }
  .inv-btn-primary:hover { background: #1E40AF; border-color: #1E40AF; color: #FFFFFF; }
  .inv-btn-in { background: var(--inv-ok-bg); border-color: var(--inv-ok-bd); color: var(--inv-ok-fg); }
  .inv-btn-in:hover { background: #D1FAE5; color: #065F46; }
  .inv-btn-out { background: var(--inv-warn-bg); border-color: var(--inv-warn-bd); color: var(--inv-warn-fg); }
  .inv-btn-out:hover { background: #FEF3C7; color: #92400E; }
  .inv-btn-sm { height: 27px; padding: 0 8px; font-size: 11.5px; border-radius: 7px; }

  /* 5-Card KPI Telemetry Strip */
  .inv-kpi-grid {
    display: grid; grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px; margin-bottom: 14px;
  }
  .inv-kpi-card {
    background: var(--inv-surface); border: 1px solid var(--inv-border);
    border-radius: 12px; padding: 14px 16px;
    display: flex; flex-direction: column; gap: 4px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
  }
  .inv-kpi-lbl {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .04em; color: var(--inv-subtle);
  }
  .inv-kpi-val {
    font-size: 24px; font-weight: 700; letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums; line-height: 1.15; color: var(--inv-text);
  }
  .inv-kpi-meta { font-size: 11.5px; color: var(--inv-muted); font-variant-numeric: tabular-nums; }

  /* Surface Card Container */
  .inv-surface {
    background: var(--inv-surface); border: 1px solid var(--inv-border);
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
  }

  /* Governance Tabs + View Switcher */
  .inv-tabs-bar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 10px; padding: 0 16px; border-bottom: 1px solid var(--inv-border);
    background: #FCFDFF; flex-wrap: wrap;
  }
  .inv-tabs { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
  .inv-tab {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 11px 11px; font-size: 12.5px; font-weight: 600;
    color: var(--inv-muted); text-decoration: none;
    border-bottom: 2px solid transparent; transition: color .14s ease;
  }
  .inv-tab:hover { color: var(--inv-text); }
  .inv-tab.is-active { color: var(--inv-accent); border-bottom-color: var(--inv-accent); }
  .inv-tab-cnt {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 20px; height: 18px; padding: 0 6px; border-radius: 999px;
    font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums;
    background: #F1F5F9; color: var(--inv-muted);
  }
  .inv-tab.is-active .inv-tab-cnt { background: var(--inv-accent-soft); color: var(--inv-accent); }

  .inv-view-seg {
    display: inline-flex; background: #F1F5F9; padding: 3px; border-radius: 8px; gap: 2px;
  }
  .inv-view-btn {
    border: 0; background: transparent; padding: 4px 10px; border-radius: 6px;
    font-size: 11.5px; font-weight: 600; color: var(--inv-muted); cursor: pointer;
  }
  .inv-view-btn.is-active { background: #FFFFFF; color: var(--inv-text); box-shadow: 0 1px 2px rgba(15,23,42,.08); }

  /* Filter Toolbar */
  .inv-filter-panel { padding: 12px 16px; border-bottom: 1px solid var(--inv-border); background: var(--inv-surface); }
  .inv-filter-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .inv-search-wrap { position: relative; flex: 1 1 230px; min-width: 190px; }
  .inv-input, .inv-select {
    height: 34px; border: 1px solid var(--inv-border-strong); border-radius: 8px;
    padding: 0 10px; font-size: 12.5px; color: var(--inv-text); background: #FFFFFF;
    outline: none;
  }
  .inv-search-wrap .inv-input { width: 100%; padding-right: 28px; }
  .inv-input:focus, .inv-select:focus { border-color: var(--inv-accent); box-shadow: 0 0 0 3px rgba(29,78,216,.12); }
  .inv-kbd {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    font-size: 10.5px; font-weight: 700; color: var(--inv-subtle);
    border: 1px solid var(--inv-border); border-radius: 4px; padding: 1px 5px;
    pointer-events: none; background: #F8FAFC;
  }
  .inv-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 9px; }
  .inv-chip {
    display: inline-flex; align-items: center; gap: 6px; padding: 3px 9px;
    border-radius: 999px; background: var(--inv-accent-soft); color: var(--inv-accent);
    font-size: 11.5px; font-weight: 600; text-decoration: none;
    border: 1px solid #BFDBFE;
  }

  /* Sticky Selection Batch Action Bar */
  .inv-batch-bar {
    display: none; align-items: center; justify-content: space-between; gap: 12px;
    padding: 9px 16px; background: #EFF6FF; border-bottom: 1px solid #BFDBFE;
    font-size: 12.5px; font-weight: 600; color: #1E3A8A;
  }
  .inv-batch-bar.is-open { display: flex; }

  /* Zero-Overflow 1440px Desktop DataTable */
  .inv-table-wrap { width: 100%; overflow-x: hidden; }
  .inv-table {
    width: 100%; border-collapse: collapse; table-layout: fixed;
  }
  .inv-table thead th {
    background: #F8FAFC; border-bottom: 1px solid var(--inv-border);
    padding: 9px 9px; text-align: left; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em; color: var(--inv-subtle);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .inv-table th.num, .inv-table td.num { text-align: right; }
  .inv-table th.ctr, .inv-table td.ctr { text-align: center; }
  .inv-sort-link {
    display: inline-flex; align-items: center; gap: 4px; color: inherit;
    text-decoration: none; max-width: 100%;
  }
  .inv-sort-link:hover { color: var(--inv-text); }
  .inv-sort-arrow { font-size: 10px; opacity: .55; }
  th.is-sorted .inv-sort-arrow { opacity: 1; color: var(--inv-accent); }

  .inv-table tbody tr {
    border-bottom: 1px solid var(--inv-border);
    transition: background .12s ease;
  }
  .inv-table tbody tr:last-child { border-bottom: 0; }
  .inv-table tbody tr:hover { background: #F8FAFC; }
  .inv-table tbody td {
    padding: 10px 9px; vertical-align: middle; font-size: 13px;
    color: var(--inv-text); overflow: hidden; text-overflow: ellipsis;
  }
  .inv-table tfoot td {
    padding: 10px 9px; background: #F8FAFC; border-top: 1px solid var(--inv-border-strong);
    font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums;
    overflow: hidden; text-overflow: ellipsis;
  }
  .inv-col-chk { width: 36px; text-align: center; }

  .inv-primary-btn {
    background: none; border: 0; padding: 0; margin: 0; text-align: left;
    font: inherit; font-weight: 700; color: var(--inv-accent); cursor: pointer;
    font-variant-numeric: tabular-nums; max-width: 100%;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block;
  }
  .inv-primary-btn:hover { text-decoration: underline; }
  .inv-cell-main {
    font-weight: 600; color: var(--inv-text); white-space: nowrap;
    overflow: hidden; text-overflow: ellipsis;
  }
  .inv-cell-sub {
    font-size: 11.5px; color: var(--inv-subtle); white-space: nowrap;
    overflow: hidden; text-overflow: ellipsis; margin-top: 2px;
    font-variant-numeric: tabular-nums;
  }

  .inv-badge {
    display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px;
    border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap;
    border: 1px solid transparent; font-variant-numeric: tabular-nums;
  }
  .inv-badge-crit    { background: var(--inv-crit-bg); color: var(--inv-crit-fg); border-color: var(--inv-crit-bd); }
  .inv-badge-warn    { background: var(--inv-warn-bg); color: var(--inv-warn-fg); border-color: var(--inv-warn-bd); }
  .inv-badge-ok      { background: var(--inv-ok-bg);   color: var(--inv-ok-fg);   border-color: var(--inv-ok-bd); }
  .inv-badge-neutral { background: #F1F5F9; color: #334155; border-color: #CBD5E1; }

  .inv-row-actions {
    display: flex; align-items: center; justify-content: flex-end; gap: 4px; flex-wrap: nowrap;
  }

  /* Dual Card Grid & Mobile Responsive View (< 768px) */
  .inv-cards-container { display: none; padding: 14px 16px; }
  .inv-cards-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(295px, 1fr)); gap: 12px;
  }
  .inv-card {
    border: 1px solid var(--inv-border); border-radius: 12px; padding: 14px;
    background: #FFFFFF; display: flex; flex-direction: column; gap: 9px;
  }
  #invShell[data-view="cards"] .inv-table-wrap { display: none; }
  #invShell[data-view="cards"] .inv-cards-container { display: block; }

  /* Pagination Footer */
  .inv-pager {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 12px 16px; border-top: 1px solid var(--inv-border);
    background: #FCFDFF; flex-wrap: wrap; font-size: 12.5px; color: var(--inv-muted);
  }
  .inv-page-links { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
  .inv-page-link {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 30px; height: 30px; padding: 0 8px; border-radius: 7px;
    border: 1px solid var(--inv-border); background: #FFFFFF; color: var(--inv-text);
    font-size: 12px; font-weight: 600; text-decoration: none;
  }
  .inv-page-link.is-current { background: var(--inv-accent); border-color: var(--inv-accent); color: #FFFFFF; }

  /* Slide-Over Item & Ledger Inspector Drawer + Quick Movement Modal */
  .inv-drawer, .inv-modal { position: fixed; inset: 0; z-index: 1200; display: none; }
  .inv-drawer.is-open, .inv-modal.is-open { display: block; }
  .inv-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.44); }
  .inv-drawer-panel {
    position: fixed; top: 0; right: 0; bottom: 0; width: min(540px, 100vw);
    background: #FFFFFF; border-left: 1px solid var(--inv-border);
    display: flex; flex-direction: column; z-index: 1201;
    box-shadow: -12px 0 32px rgba(15, 23, 42, 0.14);
  }
  .inv-drawer-head {
    padding: 16px 20px; border-bottom: 1px solid var(--inv-border);
    display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
  }
  .inv-drawer-body { padding: 18px 20px; overflow-y: auto; flex: 1; font-size: 13px; }
  .inv-drawer-foot {
    padding: 12px 20px; border-top: 1px solid var(--inv-border); background: #F8FAFC;
    display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
  }
  .inv-kv-grid {
    display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px; background: #F8FAFC; border: 1px solid var(--inv-border);
    border-radius: 10px; padding: 12px; margin-bottom: 14px;
  }
  .inv-kv-lbl { font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: var(--inv-subtle); }
  .inv-kv-val { font-size: 12.5px; font-weight: 600; color: var(--inv-text); margin-top: 2px; word-break: break-word; }

  .inv-modal-dialog {
    position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
    width: min(460px, calc(100vw - 28px)); background: #FFFFFF;
    border-radius: 14px; border: 1px solid var(--inv-border); z-index: 1202;
    box-shadow: 0 20px 44px rgba(15, 23, 42, 0.2); overflow: hidden;
  }

  @media (max-width: 1024px) {
    .inv-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 767.98px) {
    .inv-kpi-grid { grid-template-columns: 1fr; }
    .inv-table-wrap { display: none !important; }
    .inv-cards-container { display: block !important; }
    .inv-view-seg { display: none; }
  }
  @media print {
    .inv-actions, .inv-filter-panel, .inv-view-seg, .inv-batch-bar, .inv-pager,
    .inv-row-actions, .inv-col-chk, .inv-drawer, .inv-modal, #inventoryImportPanel { display: none !important; }
    .inv-surface, .inv-kpi-card { box-shadow: none !important; border-color: #CBD5E1 !important; }
  }
</style>

<div id="invShell" data-view="<?= $h($view_mode) ?>">
  <!-- Enterprise Page Header -->
  <div class="inv-head">
    <div>
      <h1 class="inv-title">Inventory Catalogue</h1>
      <div class="inv-subtitle">
        Warehouse stock master, min/max reorder governance, and audited movement ledger across <?= number_format((int)$kpi['total_items']) ?> SKU(s).
      </div>
    </div>

    <div class="inv-actions">
      <a class="inv-btn" href="<?= $h($csvExportUrl) ?>">↓ Export CSV</a>
      <a class="inv-btn" href="<?= $h($xlsxExportUrl) ?>">↓ Export Excel</a>
      <a class="inv-btn" href="<?= $h(base_url('materials/template_xlsx')) ?>">↓ Template</a>
      <a class="inv-btn" href="<?= $h(base_url('materials/transactions')) ?>">Ledger</a>
      <?php if ($can_import): ?>
        <button type="button" class="inv-btn" id="invImportToggleBtn" onclick="invImportToggle()">↑ Import Excel</button>
        <a class="inv-btn inv-btn-primary" id="invAddManualBtn" href="<?= $h(base_url('materials/create')) ?>">+ Add Item</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($import_result): ?>
    <!-- Import Result Summary Banner -->
    <div class="inv-surface" id="importResult" style="margin-bottom:14px;">
      <div style="padding:12px 16px;border-bottom:1px solid var(--inv-border);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <div>
          <div style="font-size:13.5px;font-weight:700;">
            Import Summary<?= !empty($import_result['file_name']) ? ' — ' . $h($import_result['file_name']) : '' ?>
          </div>
          <div style="font-size:12px;color:var(--inv-subtle);">
            <?= $import_result['mode'] === 'validate' ? 'Validation dry-run — nothing saved' : 'Sheet "' . $h($import_result['sheet']) . '"' ?>
            · duplicates: <?= $h(isset($import_result['duplicate_action']) ? $import_result['duplicate_action'] : 'skip') ?>
            · stock: <?= $h(str_replace('ignore', 'unchanged', isset($import_result['stock_action']) ? $import_result['stock_action'] : 'ignore')) ?>
          </div>
        </div>
        <?php if (!empty($import_result['report_token'])): ?>
          <a class="inv-btn inv-btn-sm" href="<?= $h(base_url('materials/import_report/' . $import_result['report_token'])) ?>">↓ Download Full Report</a>
        <?php endif; ?>
      </div>
      <div style="padding:12px 16px;display:flex;gap:8px;flex-wrap:wrap;">
        <span class="inv-badge inv-badge-neutral"><?= $n('total_rows') ?> rows read</span>
        <span class="inv-badge inv-badge-ok"><?= $n('inserted') ?> new items</span>
        <span class="inv-badge inv-badge-neutral"><?= $n('updated') ?> updated</span>
        <span class="inv-badge inv-badge-warn"><?= $n('skipped') ?> skipped</span>
        <span class="inv-badge inv-badge-crit"><?= $n('failed') ?> failed</span>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($can_import): ?>
    <!-- Collapsible Excel Import Panel (supports #import deep-link) -->
    <div class="inv-surface" id="inventoryImportPanel" style="display:none;margin-bottom:14px;">
      <div style="padding:12px 16px;border-bottom:1px solid var(--inv-border);display:flex;align-items:center;justify-content:space-between;gap:10px;">
        <div>
          <div style="font-size:13.5px;font-weight:700;">Import Inventory from Excel (.xlsx)</div>
          <div style="font-size:12px;color:var(--inv-subtle);">Upload a batch workbook prepared with the official import template</div>
        </div>
        <a class="inv-btn inv-btn-sm" href="<?= $h(base_url('materials/template_xlsx')) ?>">↓ Download Template</a>
      </div>
      <?= form_open_multipart('materials/import_excel') ?>
        <div style="padding:16px;display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));">
          <div>
            <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">Excel Workbook (.xlsx) *</label>
            <input type="file" name="inventory_file" class="inv-input" style="padding:5px 8px;width:100%;" accept=".xlsx" required>
            <div style="font-size:11.5px;color:var(--inv-subtle);margin-top:4px;">
              Required columns: <strong>Item Code</strong> and <strong>Description</strong>.
            </div>
          </div>
          <div>
            <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">Duplicate Item Codes</label>
            <select name="duplicate_action" class="inv-select" style="width:100%;">
              <option value="skip" selected>Skip — keep current record (safest)</option>
              <option value="update">Update existing item metadata</option>
              <option value="fail">Reject duplicate row as error</option>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">Opening Stock Action</label>
            <select name="stock_action" class="inv-select" style="width:100%;">
              <option value="ignore" selected>Leave existing stock unchanged (safest)</option>
              <option value="receive">Post as RECEIVED movement</option>
              <option value="set">Replace opening balance (unmoved items)</option>
            </select>
          </div>
        </div>
        <div style="padding:0 16px 12px;display:flex;gap:18px;flex-wrap:wrap;font-size:12px;">
          <label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="allow_new_categories" value="1"> Allow new categories</label>
          <label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="allow_new_units" value="1"> Allow new units</label>
          <label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="allow_duplicate_records" value="1"> Allow duplicate signatures</label>
          <label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="dry_run" value="1"> Validate only (dry run)</label>
        </div>
        <div style="padding:0 16px 16px;display:flex;gap:8px;align-items:center;">
          <button type="submit" class="inv-btn inv-btn-primary">Start Import</button>
          <button type="button" class="inv-btn" onclick="invImportToggle()">Cancel</button>
        </div>
      <?= form_close() ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($import_history)): ?>
    <div style="margin:0 0 12px;font-size:11.5px;color:var(--inv-subtle);">
      Recent imports:
      <?php
      $histBits = array();
      foreach ($import_history as $row) {
          $histBits[] = date('d/m/Y H:i', strtotime($row->created_at)) . ' — ' . $h($row->file_name)
              . ' (' . (int)$row->inserted . ' new, ' . (int)$row->updated . ' updated, ' . (int)$row->failed . ' failed)';
      }
      echo implode(' · ', $histBits);
      ?>
    </div>
  <?php endif; ?>

  <!-- 5-Card KPI Telemetry Strip -->
  <div class="inv-kpi-grid">
    <div class="inv-kpi-card">
      <span class="inv-kpi-lbl">Catalogue SKUs</span>
      <span class="inv-kpi-val"><?= number_format((int)$kpi['total_items']) ?></span>
      <span class="inv-kpi-meta"><?= number_format((int)$kpi['in_stock_items']) ?> healthy in stock</span>
    </div>
    <div class="inv-kpi-card">
      <span class="inv-kpi-lbl">Units On Hand</span>
      <span class="inv-kpi-val" style="color:var(--inv-accent);"><?= number_format((int)$kpi['total_units']) ?></span>
      <span class="inv-kpi-meta">+<?= number_format((int)$kpi['total_received']) ?> in · −<?= number_format((int)$kpi['total_issued']) ?> out</span>
    </div>
    <div class="inv-kpi-card">
      <span class="inv-kpi-lbl">Low / Reorder</span>
      <span class="inv-kpi-val" style="color:<?= (int)$kpi['low_stock'] > 0 ? 'var(--inv-warn-fg)' : 'var(--inv-text)' ?>;">
        <?= number_format((int)$kpi['low_stock']) ?>
      </span>
      <span class="inv-kpi-meta">At or below min stock</span>
    </div>
    <div class="inv-kpi-card">
      <span class="inv-kpi-lbl">Out of Stock</span>
      <span class="inv-kpi-val" style="color:<?= (int)$kpi['out_of_stock'] > 0 ? 'var(--inv-crit-fg)' : 'var(--inv-text)' ?>;">
        <?= number_format((int)$kpi['out_of_stock']) ?>
      </span>
      <span class="inv-kpi-meta"><?= number_format((int)$kpi['reorder_needed']) ?> units to order</span>
    </div>
    <div class="inv-kpi-card">
      <span class="inv-kpi-lbl">Filtered Slice</span>
      <span class="inv-kpi-val"><?= number_format((int)$total_filtered) ?></span>
      <span class="inv-kpi-meta"><?= number_format((int)$filtered_totals['balance']) ?> net balance</span>
    </div>
  </div>

  <!-- Main Enterprise DataTable Surface -->
  <div class="inv-surface">
    <!-- Governance Tabs + Dual Table / Cards Switcher -->
    <div class="inv-tabs-bar">
      <nav class="inv-tabs" aria-label="Inventory governance views">
        <?php
        $tabsDef = array(
            'all'      => array('All Items',       isset($tab_counts['all']) ? (int)$tab_counts['all'] : 0),
            'in_stock' => array('In Stock',        isset($tab_counts['in_stock']) ? (int)$tab_counts['in_stock'] : 0),
            'low'      => array('Low / Reorder',   isset($tab_counts['low']) ? (int)$tab_counts['low'] : 0),
            'out'      => array('Out of Stock',    isset($tab_counts['out']) ? (int)$tab_counts['out'] : 0),
            'movers'   => array('Active Movers',   isset($tab_counts['movers']) ? (int)$tab_counts['movers'] : 0),
            'valued'   => array('Valued / Priced', isset($tab_counts['valued']) ? (int)$tab_counts['valued'] : 0),
        );
        foreach ($tabsDef as $tk => $meta):
            list($tLabel, $tCnt) = $meta;
        ?>
          <a class="inv-tab <?= $tab === $tk ? 'is-active' : '' ?>"
             href="<?= $h($buildUrl(array('tab' => $tk, 'page' => 1))) ?>">
            <span><?= $h($tLabel) ?></span>
            <span class="inv-tab-cnt"><?= number_format($tCnt) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="inv-view-seg" role="group" aria-label="Layout view">
        <button type="button" class="inv-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>" data-inv-view="table">Table</button>
        <button type="button" class="inv-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>" data-inv-view="cards">Cards</button>
      </div>
    </div>

    <!-- Search & Facet Filter Toolbar -->
    <div class="inv-filter-panel">
      <form method="get" action="<?= $h(base_url('materials/inventory')) ?>" class="inv-filter-form">
        <input type="hidden" name="tab" value="<?= $h($tab) ?>">
        <input type="hidden" name="view" value="<?= $h($view_mode) ?>" id="invViewHiddenInput">

        <div class="inv-search-wrap">
          <input type="search" name="q" id="invSearchInput" class="inv-input"
                 value="<?= $h($q) ?>"
                 placeholder="Search SKU code, description, part no, application, bin, supplier…">
          <span class="inv-kbd">/</span>
        </div>

        <select name="category" class="inv-select" aria-label="Category" onchange="this.form.submit()">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $h($c->category) ?>" <?= $g('category') === (string)$c->category ? 'selected' : '' ?>>
              <?= $h($c->category) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select name="stock" class="inv-select" aria-label="Stock Level" onchange="this.form.submit()">
          <option value="">All Stock Health</option>
          <option value="ok"  <?= $g('stock') === 'ok'  ? 'selected' : '' ?>>Healthy In Stock</option>
          <option value="low" <?= $g('stock') === 'low' ? 'selected' : '' ?>>Low / At Reorder</option>
          <option value="out" <?= $g('stock') === 'out' ? 'selected' : '' ?>>Out of Stock (&le; 0)</option>
        </select>

        <select name="location" class="inv-select" aria-label="Warehouse Bin / Location" onchange="this.form.submit()">
          <option value="">All Bins / Locations</option>
          <?php foreach ($locations as $loc): ?>
            <option value="<?= $h($loc->location) ?>" <?= $g('location') === (string)$loc->location ? 'selected' : '' ?>>
              <?= $h($loc->location) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <?php if (!empty($suppliers)): ?>
          <select name="supplier" class="inv-select" aria-label="Preferred Supplier" onchange="this.form.submit()">
            <option value="">All Suppliers</option>
            <?php foreach ($suppliers as $supName): ?>
              <option value="<?= $h($supName) ?>" <?= $g('supplier') === (string)$supName ? 'selected' : '' ?>>
                <?= $h($supName) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>

        <button type="submit" class="inv-btn">Filter</button>
        <?php if (!empty($activeChips) || $tab !== 'all'): ?>
          <a class="inv-btn" href="<?= $h(base_url('materials/inventory')) ?>">Reset</a>
        <?php endif; ?>
      </form>

      <?php if (!empty($activeChips)): ?>
        <div class="inv-chips">
          <?php foreach ($activeChips as $chip): ?>
            <a class="inv-chip" href="<?= $h($chip['url']) ?>">
              <span><?= $h($chip['label']) ?></span>
              <span aria-hidden="true">×</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Sticky Multi-Select Batch CSV Action Bar -->
    <div class="inv-batch-bar" id="invBatchBar" role="region" aria-label="Selected inventory items">
      <div><strong id="invSelectedCount">0</strong> SKU(s) selected</div>
      <div style="display:flex;align-items:center;gap:8px;">
        <a class="inv-btn inv-btn-primary inv-btn-sm" id="invBatchExportBtn" href="<?= $h($csvExportUrl) ?>">↓ Export Selected CSV</a>
        <a class="inv-btn inv-btn-sm" id="invBatchXlsxBtn" href="<?= $h($xlsxExportUrl) ?>">↓ Export Selected Excel</a>
        <button type="button" class="inv-btn inv-btn-sm" id="invBatchClearBtn">Clear</button>
      </div>
    </div>

    <!-- Desktop 1440px Zero-Overflow DataTable -->
    <div class="inv-table-wrap">
      <table class="inv-table">
        <colgroup>
          <col style="width:36px;">
          <col style="width:11%;">
          <col style="width:23%;">
          <col style="width:12%;">
          <col style="width:9%;">
          <col style="width:9%;">
          <col style="width:10%;">
          <col style="width:9%;">
          <col style="width:auto;">
        </colgroup>
        <thead>
          <tr>
            <th class="inv-col-chk ctr">
              <input type="checkbox" id="invCheckAll" aria-label="Select all visible inventory items">
            </th>
            <?= $sortTh('item_code', 'SKU Code') ?>
            <?= $sortTh('category', 'Item & Category') ?>
            <?= $sortTh('part_number', 'Part No / Size') ?>
            <?= $sortTh('location', 'Bin / Loc') ?>
            <?= $sortTh('qty_received', 'In / Out', 'right') ?>
            <?= $sortTh('balance', 'Balance', 'right') ?>
            <th class="ctr">Health</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($materials)): ?>
            <tr>
              <td colspan="9" style="padding:42px 18px;text-align:center;color:var(--inv-muted);">
                <div style="font-size:14.5px;font-weight:700;color:var(--inv-text);margin-bottom:4px;">No matching inventory items found</div>
                <div style="font-size:12.5px;margin-bottom:14px;">Adjust your filters or add a catalogue item.</div>
                <?php if ($can_write): ?>
                  <a class="inv-btn inv-btn-primary inv-btn-sm" href="<?= $h(base_url('materials/create')) ?>">+ Add Item</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($materials as $m):
              $mid    = (int)$m->id;
              $bal    = isset($m->balance) ? (int)$m->balance : ((int)$m->initial_stock + (int)$m->qty_received - (int)$m->qty_sortie);
              $state  = isset($m->stock_state) ? (string)$m->stock_state : ($bal <= 0 ? 'out' : ($bal <= 5 ? 'low' : 'ok'));
              $effMin = isset($m->effective_min) ? (int)$m->effective_min : 5;
              $toOrd  = isset($m->qty_to_order) ? (int)$m->qty_to_order : 0;
              $sup    = isset($m->supplier_display) ? (string)$m->supplier_display : (isset($m->supplier_name) ? (string)$m->supplier_name : '');
              $stCls  = $state === 'out' ? 'inv-badge-crit' : ($state === 'low' ? 'inv-badge-warn' : 'inv-badge-ok');
              $stLbl  = $state === 'out' ? 'Out' : ($state === 'low' ? 'Low' : 'In Stock');
            ?>
              <tr data-inv-id="<?= $mid ?>">
                <td class="inv-col-chk ctr">
                  <input type="checkbox" class="inv-row-check" value="<?= $mid ?>" aria-label="Select <?= $h($m->item_code) ?>">
                </td>
                <td>
                  <button type="button" class="inv-primary-btn inv-inspect-trigger" data-inv-open="<?= $mid ?>" title="<?= $h($m->item_code) ?>">
                    <?= $h($m->item_code) ?>
                  </button>
                  <div class="inv-cell-sub">
                    <?= !empty($m->last_movement_date) ? ('Moved ' . $h($m->last_movement_date)) : ('Open ' . number_format((int)$m->initial_stock) . ' ' . $h($m->unit ?: 'pcs')) ?>
                  </div>
                </td>
                <td>
                  <div class="inv-cell-main" title="<?= $h($m->description) ?>"><?= $h($m->description) ?></div>
                  <div class="inv-cell-sub">
                    <?= $h($m->category) ?><?= !empty($m->application) ? (' · ' . $h($m->application)) : '' ?>
                  </div>
                </td>
                <td>
                  <div class="inv-cell-main"><?= $h($m->part_number_size ?: '—') ?></div>
                  <div class="inv-cell-sub"><?= $h($sup !== '' ? $sup : 'No preferred vendor') ?></div>
                </td>
                <td>
                  <div class="inv-cell-main"><?= $h($m->location ?: '—') ?></div>
                  <div class="inv-cell-sub"><?= $h($m->unit ?: 'pcs') ?></div>
                </td>
                <td class="num">
                  <div class="inv-cell-main" style="color:var(--inv-ok-fg);">+<?= number_format((int)$m->qty_received) ?></div>
                  <div class="inv-cell-sub" style="color:var(--inv-warn-fg);">−<?= number_format((int)$m->qty_sortie) ?></div>
                </td>
                <td class="num">
                  <div class="inv-cell-main" style="<?= $state === 'out' ? 'color:var(--inv-crit-fg);' : ($state === 'low' ? 'color:var(--inv-warn-fg);' : '') ?>">
                    <?= number_format($bal) ?> <span style="font-weight:500;font-size:11px;color:var(--inv-subtle);"><?= $h($m->unit ?: 'pcs') ?></span>
                  </div>
                  <div class="inv-cell-sub">
                    Min <?= $effMin ?><?= ($state !== 'ok' && $toOrd > 0) ? (' · Order ' . $toOrd) : '' ?>
                  </div>
                </td>
                <td class="ctr">
                  <span class="inv-badge <?= $stCls ?>"><?= $h($stLbl) ?></span>
                </td>
                <td>
                  <div class="inv-row-actions">
                    <button type="button" class="inv-btn inv-btn-sm" data-inv-open="<?= $mid ?>">Inspect</button>
                    <?php if ($can_write): ?>
                      <button type="button" class="inv-btn inv-btn-in inv-btn-sm"
                              data-inv-move="receive"
                              data-inv-id="<?= $mid ?>"
                              data-inv-code="<?= $h($m->item_code) ?>"
                              data-inv-desc="<?= $h($m->description) ?>"
                              data-inv-bal="<?= $bal ?>"
                              data-inv-unit="<?= $h($m->unit ?: 'pcs') ?>">+In</button>
                      <button type="button" class="inv-btn inv-btn-out inv-btn-sm"
                              data-inv-move="issue"
                              data-inv-id="<?= $mid ?>"
                              data-inv-code="<?= $h($m->item_code) ?>"
                              data-inv-desc="<?= $h($m->description) ?>"
                              data-inv-bal="<?= $bal ?>"
                              data-inv-unit="<?= $h($m->unit ?: 'pcs') ?>">-Out</button>
                      <a class="inv-btn inv-btn-sm" href="<?= $h(base_url('materials/edit/' . $mid)) ?>">Edit</a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <?php if (!empty($materials)): ?>
          <tfoot>
            <tr>
              <td colspan="5">
                Filtered Totals (<?= number_format((int)$total_filtered) ?> SKU<?= $total_filtered === 1 ? '' : 's' ?> · Opening <?= number_format((int)$filtered_totals['initial']) ?>)
              </td>
              <td class="num">
                <span style="color:var(--inv-ok-fg);">+<?= number_format((int)$filtered_totals['received']) ?></span>
                / <span style="color:var(--inv-warn-fg);">−<?= number_format((int)$filtered_totals['issued']) ?></span>
              </td>
              <td class="num"><?= number_format((int)$filtered_totals['balance']) ?></td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        <?php endif; ?>
      </table>
    </div>

    <!-- Dual Card Grid & Automatic < 768px Mobile Card View -->
    <div class="inv-cards-container">
      <?php if (empty($materials)): ?>
        <div style="padding:34px 16px;text-align:center;color:var(--inv-muted);">
          No matching inventory items found.
        </div>
      <?php else: ?>
        <div class="inv-cards-grid">
          <?php foreach ($materials as $m):
            $mid   = (int)$m->id;
            $bal   = isset($m->balance) ? (int)$m->balance : ((int)$m->initial_stock + (int)$m->qty_received - (int)$m->qty_sortie);
            $state = isset($m->stock_state) ? (string)$m->stock_state : ($bal <= 0 ? 'out' : ($bal <= 5 ? 'low' : 'ok'));
            $stCls = $state === 'out' ? 'inv-badge-crit' : ($state === 'low' ? 'inv-badge-warn' : 'inv-badge-ok');
            $stLbl = $state === 'out' ? 'Out of Stock' : ($state === 'low' ? 'Low Stock' : 'In Stock');
          ?>
            <div class="inv-card">
              <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
                <div style="min-width:0;">
                  <button type="button" class="inv-primary-btn" data-inv-open="<?= $mid ?>">
                    <?= $h($m->item_code) ?>
                  </button>
                  <div class="inv-cell-sub"><?= $h($m->category) ?><?= !empty($m->location) ? (' · ' . $h($m->location)) : '' ?></div>
                </div>
                <span class="inv-badge <?= $stCls ?>"><?= $h($stLbl) ?></span>
              </div>

              <div style="font-size:13px;font-weight:600;color:var(--inv-text);line-height:1.35;"><?= $h($m->description) ?></div>

              <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--inv-muted);font-variant-numeric:tabular-nums;">
                <span>Part: <?= $h($m->part_number_size ?: '—') ?></span>
                <span>Balance: <strong><?= number_format($bal) ?> <?= $h($m->unit ?: 'pcs') ?></strong></span>
              </div>

              <div style="display:flex;gap:6px;flex-wrap:wrap;padding-top:8px;border-top:1px solid var(--inv-border);">
                <button type="button" class="inv-btn inv-btn-sm" data-inv-open="<?= $mid ?>">Inspect</button>
                <?php if ($can_write): ?>
                  <button type="button" class="inv-btn inv-btn-in inv-btn-sm"
                          data-inv-move="receive"
                          data-inv-id="<?= $mid ?>"
                          data-inv-code="<?= $h($m->item_code) ?>"
                          data-inv-desc="<?= $h($m->description) ?>"
                          data-inv-bal="<?= $bal ?>"
                          data-inv-unit="<?= $h($m->unit ?: 'pcs') ?>">+In</button>
                  <button type="button" class="inv-btn inv-btn-out inv-btn-sm"
                          data-inv-move="issue"
                          data-inv-id="<?= $mid ?>"
                          data-inv-code="<?= $h($m->item_code) ?>"
                          data-inv-desc="<?= $h($m->description) ?>"
                          data-inv-bal="<?= $bal ?>"
                          data-inv-unit="<?= $h($m->unit ?: 'pcs') ?>">-Out</button>
                  <a class="inv-btn inv-btn-sm" href="<?= $h(base_url('materials/edit/' . $mid)) ?>">Edit</a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Server-Side Pagination Footer -->
    <div class="inv-pager">
      <div>
        <?php
        $showFrom = $total_filtered > 0 ? ($offset + 1) : 0;
        $showTo   = min($total_filtered, $offset + count($materials));
        ?>
        Showing <?= number_format($showFrom) ?>–<?= number_format($showTo) ?> of <?= number_format($total_filtered) ?> SKU(s)
      </div>

      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <form method="get" action="<?= $h(base_url('materials/inventory')) ?>" style="display:inline-flex;align-items:center;gap:6px;">
          <?php foreach ($baseParams as $pk => $pv): if ($pk === 'per_page' || $pk === 'page') continue; ?>
            <input type="hidden" name="<?= $h($pk) ?>" value="<?= $h($pv) ?>">
          <?php endforeach; ?>
          <label for="invPerPage" style="font-size:12px;">Rows:</label>
          <select id="invPerPage" name="per_page" class="inv-select" style="height:30px;" onchange="this.form.submit()">
            <?php foreach ($allowed_pp as $pp): ?>
              <option value="<?= (int)$pp ?>" <?= $per_page === (int)$pp ? 'selected' : '' ?>><?= (int)$pp ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <div class="inv-page-links">
          <?php if ($current_page > 1): ?>
            <a class="inv-page-link" href="<?= $h($buildUrl(array('page' => $current_page - 1))) ?>">‹ Prev</a>
          <?php endif; ?>
          <?php
          $startP = max(1, $current_page - 2);
          $endP   = min($total_pages, $current_page + 2);
          for ($p = $startP; $p <= $endP; $p++):
          ?>
            <a class="inv-page-link <?= $p === $current_page ? 'is-current' : '' ?>"
               href="<?= $h($buildUrl(array('page' => $p))) ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($current_page < $total_pages): ?>
            <a class="inv-page-link" href="<?= $h($buildUrl(array('page' => $current_page + 1))) ?>">Next ›</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Accessible Slide-Over Item & Ledger Inspector Drawer (`#invDrawer`) -->
<div class="inv-drawer" id="invDrawer" role="dialog" aria-modal="true" aria-labelledby="invDrawerTitle">
  <div class="inv-backdrop" data-inv-close></div>
  <div class="inv-drawer-panel">
    <div class="inv-drawer-head">
      <div>
        <div id="invDrawerTitle" style="font-size:15px;font-weight:700;color:var(--inv-text);">SKU Details</div>
        <div style="font-size:11.5px;color:var(--inv-subtle);margin-top:2px;">Use ← / → to step through items · Esc to close</div>
      </div>
      <button type="button" class="inv-btn inv-btn-sm" data-inv-close aria-label="Close item drawer">✕</button>
    </div>
    <div class="inv-drawer-body" id="invDrawerBody"></div>
    <div class="inv-drawer-foot" id="invDrawerFoot"></div>
  </div>
</div>

<?php if ($can_write): ?>
<!-- Quick Audited Stock Movement Modal (`#invMoveModal`) -->
<div class="inv-modal" id="invMoveModal" role="dialog" aria-modal="true" aria-labelledby="invMoveTitle">
  <div class="inv-backdrop" data-inv-modal-close></div>
  <div class="inv-modal-dialog">
    <div class="inv-drawer-head">
      <div>
        <div id="invMoveTitle" style="font-size:15px;font-weight:700;color:var(--inv-text);">Record Stock Movement</div>
        <div id="invMoveSub" style="font-size:12px;color:var(--inv-subtle);margin-top:2px;"></div>
      </div>
      <button type="button" class="inv-btn inv-btn-sm" data-inv-modal-close aria-label="Close movement modal">✕</button>
    </div>
    <form method="post" id="invMoveForm" action="<?= $h(base_url('materials/receive/0')) ?>" style="padding:16px 18px;">
      <?= function_exists('csrf_field') ? csrf_field() : '' ?>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:12px;">
        <div>
          <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">Quantity *</label>
          <input type="number" name="qty" id="invMoveQty" min="1" step="1" class="inv-input" style="width:100%;" value="1" required>
        </div>
        <div>
          <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">Movement Date</label>
          <input type="date" name="txn_date" class="inv-input" style="width:100%;" value="<?= $h(date('Y-m-d')) ?>">
        </div>
      </div>
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">Reference / MRN / Requisition No</label>
        <input type="text" name="reference_no" class="inv-input" style="width:100%;" placeholder="e.g. MRN-2026-041 or REQ-108">
      </div>
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">Notes / Allocation Remark</label>
        <textarea name="notes" rows="2" style="width:100%;padding:8px 10px;border:1px solid var(--inv-border-strong);border-radius:8px;font-size:12.5px;"
                  placeholder="Optional receipt or issue note…"></textarea>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
        <a id="invMoveFullLink" class="inv-btn inv-btn-sm" href="<?= $h(base_url('materials/receive/0')) ?>">Full Movement Form →</a>
        <div style="display:flex;gap:8px;">
          <button type="button" class="inv-btn" data-inv-modal-close>Cancel</button>
          <button type="submit" class="inv-btn inv-btn-primary" id="invMoveSubmitBtn">Post Movement</button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function invImportToggle() {
  var p = document.getElementById('inventoryImportPanel');
  if (!p) return;
  p.style.display = (p.style.display === 'none' || !p.style.display) ? 'block' : 'none';
  if (p.style.display === 'block') p.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

(function () {
  if (window.location.hash === '#import') {
    invImportToggle();
  }

  var shell       = document.getElementById('invShell');
  var summaryBase = <?= json_encode(rtrim(base_url('materials/summary_json'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var editBase    = <?= json_encode(rtrim(base_url('materials/edit'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var receiveBase = <?= json_encode(rtrim(base_url('materials/receive'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var issueBase   = <?= json_encode(rtrim(base_url('materials/issue'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var baseExport  = <?= json_encode($csvExportUrl, JSON_UNESCAPED_SLASHES) ?>;
  var baseXlsx    = <?= json_encode($xlsxExportUrl, JSON_UNESCAPED_SLASHES) ?>;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // Table <-> Cards switcher
  var viewBtns  = document.querySelectorAll('[data-inv-view]');
  var viewInput = document.getElementById('invViewHiddenInput');
  viewBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.getAttribute('data-inv-view') === 'cards' ? 'cards' : 'table';
      shell.setAttribute('data-view', mode);
      viewBtns.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      if (viewInput) viewInput.value = mode;
      try { localStorage.setItem('wms_inventory_view', mode); } catch (e) {}
    });
  });

  // Multi-select checkboxes & sticky CSV batch bar
  var checkAll  = document.getElementById('invCheckAll');
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.inv-row-check'));
  var batchBar  = document.getElementById('invBatchBar');
  var countEl   = document.getElementById('invSelectedCount');
  var exportBtn = document.getElementById('invBatchExportBtn');
  var xlsxBtn   = document.getElementById('invBatchXlsxBtn');
  var clearBtn  = document.getElementById('invBatchClearBtn');

  function syncBatch() {
    if (!batchBar) return;
    var ids = rowChecks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    countEl.textContent = String(ids.length);
    batchBar.classList.toggle('is-open', ids.length > 0);
    if (exportBtn) {
      var sep = baseExport.indexOf('?') === -1 ? '?' : '&';
      exportBtn.setAttribute('href', ids.length ? (baseExport + sep + 'ids=' + encodeURIComponent(ids.join(','))) : baseExport);
    }
    if (xlsxBtn) {
      var xsep = baseXlsx.indexOf('?') === -1 ? '?' : '&';
      xlsxBtn.setAttribute('href', ids.length ? (baseXlsx + xsep + 'ids=' + encodeURIComponent(ids.join(','))) : baseXlsx);
    }
    if (checkAll && rowChecks.length) {
      checkAll.checked = (ids.length === rowChecks.length);
    }
  }
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      rowChecks.forEach(function (c) { c.checked = checkAll.checked; });
      syncBatch();
    });
  }
  rowChecks.forEach(function (c) { c.addEventListener('change', syncBatch); });
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      rowChecks.forEach(function (c) { c.checked = false; });
      if (checkAll) checkAll.checked = false;
      syncBatch();
    });
  }

  // Quick Receive / Issue Modal
  var moveModal  = document.getElementById('invMoveModal');
  var moveForm   = document.getElementById('invMoveForm');
  var moveTitle  = document.getElementById('invMoveTitle');
  var moveSub    = document.getElementById('invMoveSub');
  var moveFull   = document.getElementById('invMoveFullLink');
  var moveSubmit = document.getElementById('invMoveSubmitBtn');
  var moveCaller = null;

  function hideMoveModal() {
    if (!moveModal) return;
    moveModal.classList.remove('is-open');
    if (moveCaller && typeof moveCaller.focus === 'function') moveCaller.focus();
  }
  if (moveModal) {
    moveModal.querySelectorAll('[data-inv-modal-close]').forEach(function (el) {
      el.addEventListener('click', hideMoveModal);
    });
  }

  // Slide-Over Item & Ledger Inspector Drawer (`#invDrawer`)
  var drawer     = document.getElementById('invDrawer');
  var drawerBody = document.getElementById('invDrawerBody');
  var drawerFoot = document.getElementById('invDrawerFoot');
  var drawerTit  = document.getElementById('invDrawerTitle');
  var orderedIds = Array.prototype.slice.call(document.querySelectorAll('tr[data-inv-id]')).map(function (tr) {
    return parseInt(tr.getAttribute('data-inv-id'), 10);
  });
  var activeId   = 0;
  var lastOpener = null;

  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('is-open');
    activeId = 0;
    if (lastOpener && typeof lastOpener.focus === 'function') lastOpener.focus();
  }

  function openDrawer(id, opener) {
    if (!drawer) return;
    if (opener) lastOpener = opener;
    activeId = parseInt(id, 10);
    drawer.classList.add('is-open');
    drawerTit.textContent = 'SKU #' + activeId;
    drawerBody.innerHTML = '<div style="padding:24px;text-align:center;color:#64748B;">Loading catalogue &amp; stock ledger…</div>';
    drawerFoot.innerHTML = '';

    fetch(summaryBase + activeId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || !payload.ok) {
          drawerBody.innerHTML = '<div style="padding:18px;color:#B91C1C;">Could not load item details.</div>';
          return;
        }
        var it  = payload.item;
        var mvs = payload.movements || [];
        drawerTit.textContent = it.item_code + ' · ' + it.description;

        var html = '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin-bottom:6px;">Catalogue Specification</div>'
          + '<div class="inv-kv-grid">'
          + '<div><div class="inv-kv-lbl">SKU Code &amp; Category</div><div class="inv-kv-val">' + esc(it.item_code) + ' · ' + esc(it.category) + '</div></div>'
          + '<div><div class="inv-kv-lbl">Part No / Size</div><div class="inv-kv-val">' + esc(it.part_number_size) + '</div></div>'
          + '<div><div class="inv-kv-lbl">Fleet Application</div><div class="inv-kv-val">' + esc(it.application) + '</div></div>'
          + '<div><div class="inv-kv-lbl">Warehouse Location</div><div class="inv-kv-val">' + esc(it.location) + '</div></div>'
          + '<div><div class="inv-kv-lbl">Preferred Supplier</div><div class="inv-kv-val">' + esc(it.supplier_display) + '</div></div>'
          + '<div><div class="inv-kv-lbl">Unit Valuation</div><div class="inv-kv-val">'
          + (it.unit_price_usd ? ('USD ' + Number(it.unit_price_usd).toFixed(2)) : (it.unit_price_ugx ? Number(it.unit_price_ugx).toLocaleString() : '—'))
          + '</div></div>'
          + '</div>';

        html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:12px 0 6px;">Stock Reconciliation &amp; Reorder Control</div>'
          + '<div class="inv-kv-grid">'
          + '<div><div class="inv-kv-lbl">Stock Equation</div><div class="inv-kv-val">'
          + esc(it.initial_stock) + ' + ' + esc(it.qty_received) + ' − ' + esc(it.qty_sortie)
          + ' = ' + esc(it.balance) + ' ' + esc(it.unit) + '</div></div>'
          + '<div><div class="inv-kv-lbl">Min / Max Level</div><div class="inv-kv-val">'
          + 'Min ' + esc(it.effective_min) + ' / Max ' + esc(it.max_stock != null ? it.max_stock : '—') + '</div></div>'
          + '<div><div class="inv-kv-lbl">Suggested Qty to Order</div><div class="inv-kv-val">'
          + esc(it.qty_to_order) + ' ' + esc(it.unit) + '</div></div>'
          + '<div><div class="inv-kv-lbl">Recorded Movements</div><div class="inv-kv-val">'
          + esc(it.txn_count) + ' (' + esc(it.last_movement_date) + ')</div></div>'
          + '</div>';

        html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:12px 0 6px;">Recent Stock Ledger</div>';
        if (!mvs.length) {
          html += '<div style="padding:12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:9px;color:#64748B;">No movement rows logged yet for this item.</div>';
        } else {
          html += '<div style="border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;">';
          mvs.forEach(function (mv, idx) {
            var sign = mv.type === 'RECEIVE' ? '+' : '−';
            var clr  = mv.type === 'RECEIVE' ? '#047857' : '#B45309';
            html += '<div style="padding:9px 12px;display:flex;justify-content:space-between;gap:10px;' + (idx > 0 ? 'border-top:1px solid #E2E8F0;' : '') + '">'
              + '<div><div style="font-weight:600;color:#0F172A;">' + esc(mv.date)
              + (mv.reference_no ? ' · ' + esc(mv.reference_no) : '')
              + (mv.allocation ? ' → ' + esc(mv.allocation) : '') + '</div>'
              + '<div style="font-size:11.5px;color:#64748B;">' + esc(mv.person || 'System') + (mv.notes ? ' — ' + esc(mv.notes) : '') + '</div></div>'
              + '<div style="text-align:right;white-space:nowrap;">'
              + '<div style="font-weight:700;color:' + clr + ';">' + sign + esc(mv.qty) + ' ' + esc(it.unit) + '</div>'
              + '<div style="font-size:11px;color:#64748B;">After: ' + esc(mv.stock_after != null ? mv.stock_after : '—') + '</div></div>'
              + '</div>';
          });
          html += '</div>';
        }

        drawerBody.innerHTML = html;

        var foot = '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
          + (it.can_write ? '<a class="inv-btn inv-btn-in inv-btn-sm" href="' + esc(receiveBase + it.id) + '">+ Receive</a>' : '')
          + (it.can_write ? '<a class="inv-btn inv-btn-out inv-btn-sm" href="' + esc(issueBase + it.id) + '">− Issue</a>' : '')
          + (it.can_write ? '<a class="inv-btn inv-btn-primary inv-btn-sm" href="' + esc(editBase + it.id) + '">Edit Item</a>' : '')
          + '</div>'
          + '<button type="button" class="inv-btn inv-btn-sm" id="invDrawerDoneBtn">Close</button>';
        drawerFoot.innerHTML = foot;
        var doneBtn = document.getElementById('invDrawerDoneBtn');
        if (doneBtn) doneBtn.addEventListener('click', closeDrawer);
      })
      .catch(function () {
        drawerBody.innerHTML = '<div style="padding:18px;color:#B91C1C;">Network error while loading item ledger.</div>';
      });
  }

  document.addEventListener('click', function (ev) {
    var moveBtn = ev.target.closest('[data-inv-move]');
    if (moveBtn && moveModal && moveForm) {
      ev.preventDefault();
      moveCaller = moveBtn;
      var kind = moveBtn.getAttribute('data-inv-move') === 'issue' ? 'issue' : 'receive';
      var mid  = moveBtn.getAttribute('data-inv-id');
      var code = moveBtn.getAttribute('data-inv-code') || ('SKU #' + mid);
      var desc = moveBtn.getAttribute('data-inv-desc') || '';
      var bal  = moveBtn.getAttribute('data-inv-bal') || '0';
      var unit = moveBtn.getAttribute('data-inv-unit') || 'pcs';
      var targetUrl = (kind === 'issue' ? issueBase : receiveBase) + mid;

      moveForm.setAttribute('action', targetUrl);
      if (moveFull) moveFull.setAttribute('href', targetUrl);
      moveTitle.textContent = (kind === 'issue' ? 'Issue Stock (−Out) · ' : 'Receive Stock (+In) · ') + code;
      moveSub.textContent = desc + ' · Current balance: ' + bal + ' ' + unit;
      if (moveSubmit) moveSubmit.textContent = kind === 'issue' ? 'Post Issue (−Out)' : 'Post Receipt (+In)';
      moveModal.classList.add('is-open');
      return;
    }

    var opener = ev.target.closest('[data-inv-open]');
    if (opener) {
      ev.preventDefault();
      openDrawer(opener.getAttribute('data-inv-open'), opener);
      return;
    }

    if (ev.target.closest('[data-inv-close]')) {
      ev.preventDefault();
      closeDrawer();
    }
  });

  document.addEventListener('keydown', function (ev) {
    var tag = ev.target && ev.target.tagName ? ev.target.tagName.toLowerCase() : '';
    var inField = (tag === 'input' || tag === 'textarea' || tag === 'select' || (ev.target && ev.target.isContentEditable));

    if (ev.key === '/' && !inField && (!drawer || !drawer.classList.contains('is-open'))) {
      var sInput = document.getElementById('invSearchInput');
      if (sInput) {
        ev.preventDefault();
        sInput.focus();
      }
      return;
    }

    if (ev.key === 'Escape') {
      if (moveModal && moveModal.classList.contains('is-open')) {
        ev.preventDefault();
        hideMoveModal();
        return;
      }
      if (drawer && drawer.classList.contains('is-open')) {
        ev.preventDefault();
        closeDrawer();
      }
      return;
    }

    if (drawer && drawer.classList.contains('is-open') && orderedIds.length > 1) {
      var idx = orderedIds.indexOf(activeId);
      if (ev.key === 'ArrowRight' && idx !== -1) {
        ev.preventDefault();
        openDrawer(orderedIds[(idx + 1) % orderedIds.length], null);
      } else if (ev.key === 'ArrowLeft' && idx !== -1) {
        ev.preventDefault();
        openDrawer(orderedIds[(idx - 1 + orderedIds.length) % orderedIds.length], null);
      }
    }
  });
})();
</script>
