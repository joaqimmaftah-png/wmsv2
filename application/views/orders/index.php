<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$h = function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};

$fmtQty = function ($n) {
    return rtrim(rtrim(number_format((float)$n, 2, '.', ','), '0'), '.');
};

$tab          = isset($tab) ? (string)$tab : 'all';
$q            = isset($q) ? (string)$q : '';
$filter       = isset($filter) && is_array($filter) ? $filter : array();
$orders       = isset($orders) && is_array($orders) ? $orders : array();
$suppliers    = isset($suppliers) && is_array($suppliers) ? $suppliers : array();
$countries    = isset($countries) && is_array($countries) ? $countries : array();
$statuses     = isset($statuses) && is_array($statuses) ? $statuses : array();
$methods      = isset($methods) && is_array($methods) ? $methods : array('Air Freight', 'Sea Freight', 'Road Transport', 'Rail');
$kpi          = isset($kpi) && is_array($kpi) ? $kpi : array(
    'total_all' => 0, 'open_orders' => 0, 'in_transit' => 0,
    'overdue' => 0, 'due_soon' => 0, 'discrepancies' => 0,
    'closed_orders' => 0, 'open_spend_by_ccy' => array(),
);
$tab_counts   = isset($tab_counts) && is_array($tab_counts) ? $tab_counts : array();
$filtered_totals = isset($filtered_totals) && is_array($filtered_totals)
    ? $filtered_totals
    : array('qty_ordered' => 0.0, 'qty_received' => 0.0, 'by_currency' => array());
$sort         = isset($sort) ? (string)$sort : 'order_date';
$dir          = isset($dir) && strtolower($dir) === 'asc' ? 'asc' : 'desc';
$view_mode    = isset($view_mode) && $view_mode === 'cards' ? 'cards' : 'table';
$per_page     = isset($per_page) ? (int)$per_page : 25;
$allowed_pp   = isset($allowed_pp) && is_array($allowed_pp) ? $allowed_pp : array(10, 25, 50, 100, 150);
$current_page = isset($current_page) ? (int)$current_page : 1;
$total_pages  = isset($total_pages) ? (int)$total_pages : 1;
$total_filtered = isset($total_filtered) ? (int)$total_filtered : count($orders);
$offset       = isset($offset) ? (int)$offset : 0;
$can_write    = !empty($can_write);

$g = function ($key) use ($filter) {
    return isset($filter[$key]) ? (string)$filter[$key] : '';
};

$fmtSpendMap = function ($map) {
    if (empty($map) || !is_array($map)) return '0.00';
    $parts = array();
    foreach ($map as $ccy => $amt) {
        $parts[] = strtoupper((string)$ccy) . ' ' . number_format((float)$amt, 2);
    }
    return implode(' · ', $parts);
};

$baseParams = array(
    'tab'             => $tab,
    'q'               => $q,
    'status'          => $g('status'),
    'supplier'        => $g('supplier'),
    'country'         => $g('country'),
    'shipping_method' => $g('shipping_method'),
    'sort'            => $sort,
    'dir'             => $dir,
    'view'            => $view_mode,
    'per_page'        => $per_page,
    'page'            => $current_page,
);

$buildUrl = function ($overrides = array(), $endpoint = 'orders') use ($baseParams) {
    $merged = array_merge($baseParams, $overrides);
    $clean  = array();
    foreach ($merged as $k => $v) {
        if ($v === '' || $v === null) continue;
        if ($k === 'tab' && $v === 'all') continue;
        if ($k === 'sort' && $v === 'order_date' && (!isset($merged['dir']) || $merged['dir'] === 'desc')) continue;
        if ($k === 'dir' && $v === 'desc' && (!isset($merged['sort']) || $merged['sort'] === 'order_date')) continue;
        if ($k === 'view' && $v === 'table') continue;
        if ($k === 'per_page' && (int)$v === 25) continue;
        if ($k === 'page' && (int)$v === 1) continue;
        $clean[$k] = $v;
    }
    $qs = http_build_query($clean);
    return base_url($endpoint) . ($qs !== '' ? '?' . $qs : '');
};

$csvExportUrl  = $buildUrl(array('page' => null, 'per_page' => null), 'orders/export_csv');
$xlsxExportUrl = $buildUrl(array('page' => null, 'per_page' => null), 'orders/export_xlsx');

$sortTh = function ($col, $label, $cls = '') use ($sort, $dir, $buildUrl, $h) {
    $isCurr  = ($sort === $col);
    $nextDir = ($isCurr && $dir === 'asc') ? 'desc' : 'asc';
    $aria    = $isCurr ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $arrow   = $isCurr ? ($dir === 'asc' ? '↑' : '↓') : '↕';
    $url     = $buildUrl(array('sort' => $col, 'dir' => $nextDir, 'page' => 1));
    return '<th class="' . $h($cls) . '" aria-sort="' . $aria . '">'
        . '<a class="ord-sort-link" href="' . $h($url) . '">'
        . '<span>' . $h($label) . '</span>'
        . '<span class="ord-sort-arrow' . ($isCurr ? ' is-on' : '') . '">' . $arrow . '</span>'
        . '</a></th>';
};

$supNameMap = array();
foreach ($suppliers as $sp) {
    $supNameMap[(string)$sp->id] = (string)$sp->company_name;
}

$chips = array();
if ($q !== '')                    $chips[] = array('Search: ' . $q, array('q' => '', 'page' => 1));
if ($g('status') !== '')          $chips[] = array('Status: ' . $g('status'), array('status' => '', 'page' => 1));
if ($g('supplier') !== '')        $chips[] = array('Supplier: ' . (isset($supNameMap[$g('supplier')]) ? $supNameMap[$g('supplier')] : '#' . $g('supplier')), array('supplier' => '', 'page' => 1));
if ($g('country') !== '')         $chips[] = array('Origin: ' . $g('country'), array('country' => '', 'page' => 1));
if ($g('shipping_method') !== '') $chips[] = array('Freight: ' . $g('shipping_method'), array('shipping_method' => '', 'page' => 1));
?>

<style>
  .ord-shell { display: flex; flex-direction: column; gap: 16px; max-width: 100%; }
  .ord-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 14px; flex-wrap: wrap;
  }
  .ord-title { font-size: 20px; font-weight: 700; color: #0F172A; letter-spacing: -0.02em; margin: 0; }
  .ord-subtitle { font-size: 12.5px; color: #64748B; margin-top: 3px; }
  .ord-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

  .ord-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px;
    font-size: 12.5px; font-weight: 600; border-radius: 8px; border: 1px solid #CBD5E1;
    background: #FFFFFF; color: #1E293B; text-decoration: none; cursor: pointer;
    transition: background .14s ease, border-color .14s ease; white-space: nowrap;
  }
  .ord-btn:hover { background: #F8FAFC; border-color: #94A3B8; color: #0F172A; text-decoration: none; }
  .ord-btn-primary { background: #1D4ED8; border-color: #1D4ED8; color: #FFFFFF; }
  .ord-btn-primary:hover { background: #1E40AF; border-color: #1E40AF; color: #FFFFFF; }
  .ord-btn-sm { padding: 4px 9px; font-size: 11.5px; border-radius: 6px; }

  /* 5-Card KPI Telemetry Strip */
  .ord-kpi-grid {
    display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px;
  }
  .ord-kpi-card {
    background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px;
    padding: 13px 15px; display: flex; flex-direction: column; gap: 4px;
  }
  .ord-kpi-lbl {
    font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #64748B;
  }
  .ord-kpi-val {
    font-size: 22px; font-weight: 700; color: #0F172A; font-variant-numeric: tabular-nums; line-height: 1.15;
  }
  .ord-kpi-meta {
    font-size: 11.5px; color: #64748B; font-variant-numeric: tabular-nums;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .ord-val-blue  { color: #1D4ED8; }
  .ord-val-cyan  { color: #0E7490; }
  .ord-val-amber { color: #B45309; }
  .ord-val-red   { color: #DC2626; }

  /* Unified Surface */
  .ord-surface {
    background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px; overflow: hidden; max-width: 100%;
  }
  .ord-tabs-bar {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 10px 14px 0; border-bottom: 1px solid #E2E8F0; background: #F8FAFC; flex-wrap: wrap;
  }
  .ord-tabs { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
  .ord-tab {
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 11px;
    font-size: 12.5px; font-weight: 600; color: #475569; text-decoration: none;
    border-bottom: 2px solid transparent; margin-bottom: -1px;
  }
  .ord-tab:hover { color: #0F172A; text-decoration: none; }
  .ord-tab.is-active { color: #1D4ED8; border-bottom-color: #1D4ED8; background: #FFFFFF; border-radius: 8px 8px 0 0; }
  .ord-tab-cnt {
    font-size: 10.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px;
    background: #E2E8F0; color: #334155; font-variant-numeric: tabular-nums;
  }
  .ord-tab.is-active .ord-tab-cnt { background: #DBEAFE; color: #1D4ED8; }

  .ord-view-seg {
    display: inline-flex; border: 1px solid #CBD5E1; border-radius: 8px; overflow: hidden;
    background: #FFFFFF; margin-bottom: 8px;
  }
  .ord-view-btn {
    border: 0; background: transparent; padding: 5px 10px; font-size: 11.5px;
    font-weight: 600; color: #475569; cursor: pointer;
  }
  .ord-view-btn.is-active { background: #1E293B; color: #FFFFFF; }

  /* Filter Toolbar */
  .ord-filter-panel { padding: 12px 14px; border-bottom: 1px solid #E2E8F0; background: #FFFFFF; }
  .ord-filter-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .ord-search-wrap { position: relative; flex: 1 1 240px; min-width: 190px; }
  .ord-input, .ord-select {
    height: 35px; padding: 0 10px; font-size: 12.5px; border: 1px solid #CBD5E1;
    border-radius: 8px; background: #FFFFFF; color: #0F172A;
  }
  .ord-search-wrap .ord-input { width: 100%; padding-right: 28px; }
  .ord-kbd {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    font-size: 10.5px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    padding: 1px 5px; border-radius: 4px; border: 1px solid #CBD5E1; color: #64748B; background: #F8FAFC;
    pointer-events: none;
  }

  .ord-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 9px; }
  .ord-chip {
    display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px;
    font-size: 11.5px; font-weight: 600; background: #EFF6FF; color: #1D4ED8; text-decoration: none;
  }
  .ord-chip:hover { background: #DBEAFE; text-decoration: none; }

  /* Sticky Batch Bar */
  .ord-batch-bar {
    display: none; align-items: center; justify-content: space-between; gap: 10px;
    padding: 9px 14px; background: #0F172A; color: #FFFFFF; font-size: 12.5px;
  }
  .ord-batch-bar.is-open { display: flex; }

  /* Zero-Overflow Fixed Desktop DataTable */
  .ord-table-wrap { width: 100%; max-width: 100%; overflow-x: hidden; }
  .ord-table {
    width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 12.5px;
  }
  .ord-table thead th {
    padding: 9px 9px; background: #F8FAFC; border-bottom: 1px solid #E2E8F0;
    text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .04em; color: #475569; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .ord-table thead th.num, .ord-table tbody td.num, .ord-table tfoot td.num {
    text-align: right; font-variant-numeric: tabular-nums;
  }
  .ord-table thead th.ctr, .ord-table tbody td.ctr { text-align: center; }
  .ord-sort-link {
    display: inline-flex; align-items: center; gap: 4px; color: inherit; text-decoration: none; max-width: 100%;
  }
  .ord-sort-link:hover { color: #0F172A; text-decoration: none; }
  .ord-sort-arrow { font-size: 10.5px; color: #94A3B8; }
  .ord-sort-arrow.is-on { color: #1D4ED8; font-weight: 700; }

  .ord-table tbody tr { border-bottom: 1px solid #F1F5F9; transition: background .1s ease; }
  .ord-table tbody tr:hover { background: #F8FAFC; }
  .ord-table tbody td {
    padding: 9px 9px; vertical-align: middle; color: #1E293B;
    overflow: hidden; text-overflow: ellipsis;
  }
  .ord-table tfoot td {
    padding: 10px 9px; background: #F8FAFC; border-top: 2px solid #E2E8F0;
    font-size: 12px; font-weight: 700; color: #0F172A;
  }

  .ord-cell-main {
    font-weight: 600; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .ord-cell-sub {
    font-size: 11px; color: #64748B; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px;
  }
  .ord-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11.5px; }
  .ord-link-btn {
    border: 0; background: none; padding: 0; color: #1D4ED8; font-weight: 700;
    cursor: pointer; text-align: left; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .ord-link-btn:hover { text-decoration: underline; }

  .ord-badge {
    display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px;
    border-radius: 999px; font-size: 10.5px; font-weight: 700; white-space: nowrap;
  }
  .ord-badge-open  { background: #EFF6FF; color: #1D4ED8; }
  .ord-badge-ship  { background: #ECFEFF; color: #0E7490; }
  .ord-badge-done  { background: #DCFCE7; color: #166534; }
  .ord-badge-late  { background: #FEE2E2; color: #991B1B; }
  .ord-badge-warn  { background: #FEF3C7; color: #92400E; }
  .ord-badge-muted { background: #F1F5F9; color: #475569; }

  .ord-row-actions { display: inline-flex; align-items: center; gap: 4px; justify-content: flex-end; }

  /* Responsive Card View */
  .ord-cards-container { display: none; padding: 14px; }
  .ord-cards-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(295px, 1fr)); gap: 12px;
  }
  .ord-card {
    border: 1px solid #E2E8F0; border-radius: 12px; padding: 13px; background: #FFFFFF;
    display: flex; flex-direction: column; gap: 8px;
  }

  #ordShell[data-view="cards"] .ord-table-wrap { display: none; }
  #ordShell[data-view="cards"] .ord-cards-container { display: block; }

  /* Pagination Footer */
  .ord-pager {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 11px 14px; border-top: 1px solid #E2E8F0; background: #FFFFFF; flex-wrap: wrap;
    font-size: 12.5px; color: #475569;
  }
  .ord-page-links { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
  .ord-page-link {
    padding: 5px 10px; border-radius: 7px; border: 1px solid #CBD5E1; background: #FFFFFF;
    color: #1E293B; font-size: 12px; font-weight: 600; text-decoration: none;
  }
  .ord-page-link:hover { background: #F1F5F9; text-decoration: none; }
  .ord-page-link.is-active { background: #1D4ED8; border-color: #1D4ED8; color: #FFFFFF; }
  .ord-page-link.is-disabled { opacity: .45; pointer-events: none; }

  /* Slide-Over PO & GRN Inspector Drawer */
  .ord-drawer { position: fixed; inset: 0; z-index: 1050; display: none; }
  .ord-drawer.is-open { display: block; }
  .ord-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, .45); }
  .ord-drawer-panel {
    position: fixed; top: 0; right: 0; bottom: 0; width: min(520px, 100vw);
    background: #FFFFFF; box-shadow: -12px 0 32px rgba(15, 23, 42, .18);
    display: flex; flex-direction: column; z-index: 1;
  }
  .ord-drawer-head {
    padding: 15px 18px; border-bottom: 1px solid #E2E8F0;
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
  }
  .ord-drawer-body { padding: 16px 18px; overflow-y: auto; flex: 1; font-size: 13px; color: #1E293B; }
  .ord-drawer-foot {
    padding: 12px 18px; border-top: 1px solid #E2E8F0; background: #F8FAFC;
    display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
  }
  .ord-kv-grid {
    display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px;
  }
  .ord-kv-box {
    background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 9px; padding: 9px 11px;
  }
  .ord-kv-lbl { font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: #64748B; }
  .ord-kv-val { font-size: 13px; font-weight: 600; color: #0F172A; margin-top: 2px; word-break: break-word; }

  @media (max-width: 1099px) {
    .ord-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 768px) {
    .ord-kpi-grid { grid-template-columns: 1fr; }
    .ord-table-wrap { display: none !important; }
    .ord-cards-container { display: block !important; }
    .ord-view-seg { display: none; }
  }
  @media print {
    .sidebar, .topbar, .ord-actions, .ord-tabs-bar, .ord-filter-panel,
    .ord-batch-bar, .ord-pager, .ord-drawer, .ord-col-chk, .ord-col-act { display: none !important; }
    .ord-surface, .ord-kpi-card { border: 1px solid #CBD5E1; box-shadow: none; }
    .ord-table-wrap { display: block !important; overflow: visible !important; }
  }
</style>

<div class="ord-shell" id="ordShell" data-view="<?= $h($view_mode) ?>">

  <!-- Page Header & RBAC Action Bar -->
  <div class="ord-head">
    <div>
      <h1 class="ord-title">Spare Parts Purchase Orders</h1>
      <div class="ord-subtitle">
        Procurement pipeline, international freight &amp; customs tracking, GRN fulfillment &amp; fleet work-order linkage
      </div>
    </div>
    <div class="ord-actions">
      <a class="ord-btn" href="<?= $h($csvExportUrl) ?>">↓ Export CSV</a>
      <a class="ord-btn" href="<?= $h($xlsxExportUrl) ?>">↓ Export Excel</a>
      <?php if ($can_write): ?>
        <a class="ord-btn ord-btn-primary" id="ordNewBtn" href="<?= $h(base_url('orders/create')) ?>">+ New Order</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- 5-Card KPI Telemetry Strip -->
  <div class="ord-kpi-grid">
    <div class="ord-kpi-card">
      <div class="ord-kpi-lbl">Open Pipeline</div>
      <div class="ord-kpi-val ord-val-blue"><?= number_format((int)$kpi['open_orders']) ?></div>
      <div class="ord-kpi-meta"><?= number_format((int)$kpi['total_all']) ?> total POs registered</div>
    </div>
    <div class="ord-kpi-card">
      <div class="ord-kpi-lbl">In Transit / Customs</div>
      <div class="ord-kpi-val ord-val-cyan"><?= number_format((int)$kpi['in_transit']) ?></div>
      <div class="ord-kpi-meta">Active inbound shipments</div>
    </div>
    <div class="ord-kpi-card">
      <div class="ord-kpi-lbl">Due Within 7 Days</div>
      <div class="ord-kpi-val ord-val-amber"><?= number_format((int)$kpi['due_soon']) ?></div>
      <div class="ord-kpi-meta">Expected at store this week</div>
    </div>
    <div class="ord-kpi-card">
      <div class="ord-kpi-lbl">Overdue Deliveries</div>
      <div class="ord-kpi-val ord-val-red"><?= number_format((int)$kpi['overdue']) ?></div>
      <div class="ord-kpi-meta"><?= number_format((int)$kpi['discrepancies']) ?> reported discrepancy(ies)</div>
    </div>
    <div class="ord-kpi-card">
      <div class="ord-kpi-lbl">Open Commitment</div>
      <div class="ord-kpi-val" style="font-size:15.5px;line-height:1.35;" title="<?= $h($fmtSpendMap($kpi['open_spend_by_ccy'])) ?>">
        <?= $h($fmtSpendMap($kpi['open_spend_by_ccy'])) ?>
      </div>
      <div class="ord-kpi-meta">Grouped per currency (ISO)</div>
    </div>
  </div>

  <!-- Unified Register Surface -->
  <div class="ord-surface">

    <!-- 6 Governance Tabs + Table/Cards Switcher -->
    <div class="ord-tabs-bar">
      <nav class="ord-tabs" role="tablist" aria-label="Purchase order governance views">
        <?php
        $tabsDef = array(
            'all'         => array('All Orders',          isset($tab_counts['all']) ? (int)$tab_counts['all'] : 0),
            'open'        => array('Open Pipeline',       isset($tab_counts['open']) ? (int)$tab_counts['open'] : 0),
            'transit'     => array('In Transit / Customs', isset($tab_counts['transit']) ? (int)$tab_counts['transit'] : 0),
            'overdue'     => array('Overdue',             isset($tab_counts['overdue']) ? (int)$tab_counts['overdue'] : 0),
            'discrepancy' => array('Discrepancies',       isset($tab_counts['discrepancy']) ? (int)$tab_counts['discrepancy'] : 0),
            'closed'      => array('Received / Closed',   isset($tab_counts['closed']) ? (int)$tab_counts['closed'] : 0),
        );
        foreach ($tabsDef as $tk => $meta):
            list($tLabel, $tCnt) = $meta;
        ?>
          <a class="ord-tab <?= $tab === $tk ? 'is-active' : '' ?>"
             href="<?= $h($buildUrl(array('tab' => $tk, 'page' => 1))) ?>">
            <span><?= $h($tLabel) ?></span>
            <span class="ord-tab-cnt"><?= number_format($tCnt) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="ord-view-seg" role="group" aria-label="Layout view">
        <button type="button" class="ord-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>" data-ord-view="table">Table</button>
        <button type="button" class="ord-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>" data-ord-view="cards">Cards</button>
      </div>
    </div>

    <!-- Search & Facet Filter Toolbar -->
    <div class="ord-filter-panel">
      <form method="get" action="<?= $h(base_url('orders')) ?>" class="ord-filter-form">
        <input type="hidden" name="tab" value="<?= $h($tab) ?>">
        <input type="hidden" name="view" value="<?= $h($view_mode) ?>" id="ordViewHiddenInput">

        <div class="ord-search-wrap">
          <input type="search" name="q" id="ordSearchInput" class="ord-input"
                 value="<?= $h($q) ?>"
                 placeholder="Search PO no, supplier, part no, tracking #, WO, asset (EX 006)…">
          <span class="ord-kbd">/</span>
        </div>

        <select name="status" class="ord-select" aria-label="Status" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <?php foreach ($statuses as $st): ?>
            <option value="<?= $h($st) ?>" <?= $g('status') === (string)$st ? 'selected' : '' ?>><?= $h($st) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="supplier" class="ord-select" aria-label="Supplier" onchange="this.form.submit()">
          <option value="">All Suppliers</option>
          <?php foreach ($suppliers as $sp): ?>
            <option value="<?= (int)$sp->id ?>" <?= $g('supplier') === (string)$sp->id ? 'selected' : '' ?>>
              <?= $h($sp->company_name) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select name="country" class="ord-select" aria-label="Origin Country" onchange="this.form.submit()">
          <option value="">All Origins</option>
          <?php foreach ($countries as $cy): ?>
            <option value="<?= $h($cy) ?>" <?= strcasecmp($g('country'), (string)$cy) === 0 ? 'selected' : '' ?>>
              <?= $h($cy) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select name="shipping_method" class="ord-select" aria-label="Shipping Method" onchange="this.form.submit()">
          <option value="">All Freight</option>
          <?php foreach ($methods as $m): ?>
            <option value="<?= $h($m) ?>" <?= strcasecmp($g('shipping_method'), (string)$m) === 0 ? 'selected' : '' ?>>
              <?= $h($m) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="ord-btn ord-btn-primary">Filter</button>
        <?php if (!empty($chips) || $tab !== 'all'): ?>
          <a class="ord-btn" href="<?= $h(base_url('orders')) ?>">Clear all</a>
        <?php endif; ?>
      </form>

      <?php if (!empty($chips)): ?>
        <div class="ord-chips">
          <?php foreach ($chips as $ch): ?>
            <a class="ord-chip" href="<?= $h($buildUrl($ch[1])) ?>">
              <span><?= $h($ch[0]) ?></span>
              <span aria-hidden="true">×</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Sticky Multi-Select Batch Bar -->
    <div class="ord-batch-bar" id="ordBatchBar" role="region" aria-label="Selected purchase orders">
      <div><strong id="ordSelectedCount">0</strong> order(s) selected</div>
      <div style="display:flex;align-items:center;gap:8px;">
        <a class="ord-btn ord-btn-primary ord-btn-sm" id="ordBatchExportBtn" href="<?= $h($csvExportUrl) ?>">↓ Export Selected CSV</a>
        <a class="ord-btn ord-btn-sm" id="ordBatchXlsxBtn" href="<?= $h($xlsxExportUrl) ?>">↓ Export Selected Excel</a>
        <button type="button" class="ord-btn ord-btn-sm" id="ordBatchClearBtn">Clear</button>
      </div>
    </div>

    <?php if (empty($orders)): ?>
      <div style="padding:56px 24px;text-align:center;color:#64748B;">
        <div style="font-size:15px;font-weight:700;color:#0F172A;margin-bottom:6px;">No purchase orders match this view</div>
        <div style="font-size:12.5px;margin-bottom:14px;">
          Clear active filters to inspect all orders, or create a new spare parts purchase order.
        </div>
        <div style="display:inline-flex;gap:8px;">
          <a class="ord-btn" href="<?= $h(base_url('orders')) ?>">Reset Filters</a>
          <?php if ($can_write): ?>
            <a class="ord-btn ord-btn-primary" href="<?= $h(base_url('orders/create')) ?>">+ New Order</a>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>

      <!-- Desktop 1440px Zero-Overflow 9-Column DataTable -->
      <div class="ord-table-wrap">
        <table class="ord-table">
          <colgroup>
            <col style="width:36px;">
            <col style="width:12%;">
            <col style="width:18%;">
            <col style="width:13%;">
            <col style="width:12%;">
            <col style="width:11%;">
            <col style="width:10%;">
            <col style="width:12%;">
            <col style="width:auto;">
          </colgroup>
          <thead>
            <tr>
              <th class="ord-col-chk ctr">
                <input type="checkbox" id="ordCheckAll" aria-label="Select all visible orders">
              </th>
              <?= $sortTh('order_no', 'Order No') ?>
              <?= $sortTh('supplier', 'Supplier & Origin') ?>
              <th>Linked WO / Asset</th>
              <th>Freight & Tracking</th>
              <?= $sortTh('expected_delivery_date', 'Expected ETA') ?>
              <?= $sortTh('fulfillment', 'Fulfillment', 'num') ?>
              <?= $sortTh('status', 'Status') ?>
              <?= $sortTh('total_cost', 'Cost & Actions', 'num') ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o):
                $oid   = (int)$o->id;
                $oDate = !empty($o->order_date) ? strtotime($o->order_date) : false;
                $eDate = !empty($o->expected_delivery_date) ? strtotime($o->expected_delivery_date) : false;
                $bCls  = 'ord-badge-open';
                if (!empty($o->is_overdue)) {
                    $bCls = 'ord-badge-late';
                } elseif (in_array($o->status, array('Received at Store', 'Verified & Closed'), TRUE)) {
                    $bCls = 'ord-badge-done';
                } elseif (in_array($o->status, array('In Transit', 'Customs Clearance'), TRUE)) {
                    $bCls = 'ord-badge-ship';
                } elseif ($o->status === 'Cancelled') {
                    $bCls = 'ord-badge-muted';
                }
            ?>
              <tr data-ord-id="<?= $oid ?>">
                <td class="ord-col-chk ctr">
                  <input type="checkbox" class="ord-row-check" value="<?= $oid ?>"
                         aria-label="Select order <?= $h($o->order_no) ?>">
                </td>
                <td>
                  <button type="button" class="ord-link-btn ord-mono" data-ord-open="<?= $oid ?>">
                    <?= $h($o->order_no) ?>
                  </button>
                  <div class="ord-cell-sub ord-mono"><?= $oDate ? $h(date('d M Y', $oDate)) : '—' ?></div>
                </td>
                <td>
                  <div class="ord-cell-main" title="<?= $h($o->supplier_name ?? '') ?>">
                    <?= $h($o->supplier_name ?? '—') ?>
                  </div>
                  <div class="ord-cell-sub"><?= $h($o->country ?? '—') ?></div>
                </td>
                <td>
                  <?php if (!empty($o->linked_wo_id) && !empty($o->wo_number)): ?>
                    <a class="ord-mono" style="font-weight:700;color:#1D4ED8;" href="<?= $h(base_url('workorders/view/' . (int)$o->linked_wo_id)) ?>">
                      <?= $h($o->wo_number) ?>
                    </a>
                    <div class="ord-cell-sub"><?= $h(!empty($o->equipment_code) ? $o->equipment_code : 'Fleet WO') ?></div>
                  <?php else: ?>
                    <span style="color:#94A3B8;font-size:11.5px;">Stock Replenish</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="ord-cell-main" style="font-weight:500;"><?= $h($o->shipping_method) ?></div>
                  <div class="ord-cell-sub ord-mono" title="<?= $h(($o->carrier ?? '') . ' ' . ($o->tracking_number ?? '')) ?>">
                    <?= $h(!empty($o->tracking_number) ? $o->tracking_number : (!empty($o->carrier) ? $o->carrier : '—')) ?>
                  </div>
                </td>
                <td>
                  <div class="ord-cell-main ord-mono" style="<?= !empty($o->is_overdue) ? 'color:#DC2626;' : '' ?>">
                    <?= $eDate ? $h(date('d M Y', $eDate)) : '—' ?>
                  </div>
                  <div class="ord-cell-sub" style="<?= !empty($o->is_overdue) ? 'color:#DC2626;font-weight:600;' : '' ?>">
                    <?= !empty($o->is_overdue) ? 'Overdue' : (!empty($o->is_due_soon) ? 'Due ≤ 7d' : (!empty($o->actual_delivery_date) ? 'Arrived' : 'Scheduled')) ?>
                  </div>
                </td>
                <td class="num">
                  <div class="ord-cell-main ord-mono">
                    <?= $h($fmtQty($o->qty_received_sum)) ?> / <?= $h($fmtQty($o->qty_ordered_sum)) ?>
                  </div>
                  <div class="ord-cell-sub"><?= (int)$o->line_count ?> line(s) · <?= (int)$o->fulfillment_pct ?>%</div>
                </td>
                <td>
                  <span class="ord-badge <?= $bCls ?>"><?= $h($o->status) ?></span>
                  <?php if (!empty($o->has_discrepancy)): ?>
                    <div class="ord-cell-sub" style="color:#B45309;font-weight:600;">⚠ <?= $h($o->discrepancy_flag) ?></div>
                  <?php endif; ?>
                </td>
                <td class="num ord-col-act">
                  <div class="ord-cell-main ord-mono" style="margin-bottom:4px;">
                    <?= $h($o->currency) ?> <?= number_format((float)$o->total_cost, 2) ?>
                  </div>
                  <div class="ord-row-actions">
                    <button type="button" class="ord-btn ord-btn-sm" data-ord-open="<?= $oid ?>">Quick</button>
                    <a class="ord-btn ord-btn-sm" href="<?= $h(base_url('orders/view/' . $oid)) ?>">Open</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td class="ord-col-chk"></td>
              <td colspan="5">Filtered Totals (<?= number_format($total_filtered) ?> order(s))</td>
              <td class="num ord-mono">
                <?= $h($fmtQty($filtered_totals['qty_received'])) ?> / <?= $h($fmtQty($filtered_totals['qty_ordered'])) ?>
              </td>
              <td class="num ord-mono" colspan="2">
                <?= $h($fmtSpendMap($filtered_totals['by_currency'])) ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Responsive Mobile & Optional Desktop Card View -->
      <div class="ord-cards-container">
        <div class="ord-cards-grid">
          <?php foreach ($orders as $o):
              $oid = (int)$o->id;
          ?>
            <div class="ord-card">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                <span class="ord-mono" style="font-weight:700;color:#1D4ED8;"><?= $h($o->order_no) ?></span>
                <span class="ord-badge <?= !empty($o->is_overdue) ? 'ord-badge-late' : 'ord-badge-open' ?>"><?= $h($o->status) ?></span>
              </div>
              <div>
                <div style="font-weight:600;color:#0F172A;"><?= $h($o->supplier_name ?? '—') ?></div>
                <div style="font-size:11.5px;color:#64748B;">
                  <?= $h($o->country ?? '—') ?> · <?= $h($o->shipping_method) ?>
                  <?= !empty($o->equipment_code) ? ' · ' . $h($o->equipment_code) : '' ?>
                </div>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:12px;color:#334155;">
                <span>ETA: <?= $h($o->expected_delivery_date ?: '—') ?></span>
                <strong class="ord-mono"><?= $h($o->currency) ?> <?= number_format((float)$o->total_cost, 2) ?></strong>
              </div>
              <div style="display:flex;justify-content:flex-end;gap:8px;padding-top:4px;border-top:1px solid #F1F5F9;">
                <button type="button" class="ord-btn ord-btn-sm" data-ord-open="<?= $oid ?>">Quick View</button>
                <a class="ord-btn ord-btn-primary ord-btn-sm" href="<?= $h(base_url('orders/view/' . $oid)) ?>">Open PO</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php endif; ?>

    <!-- Pagination Footer -->
    <div class="ord-pager">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span>
          Showing <strong><?= $total_filtered > 0 ? number_format($offset + 1) : '0' ?></strong>–<strong><?= number_format(min($offset + count($orders), $total_filtered)) ?></strong>
          of <strong><?= number_format($total_filtered) ?></strong> order(s)
        </span>
        <form method="get" action="<?= $h(base_url('orders')) ?>" style="display:inline-flex;align-items:center;gap:6px;">
          <?php foreach ($baseParams as $pk => $pv):
              if ($pk === 'per_page' || $pk === 'page' || $pv === '' || $pv === null) continue;
          ?>
            <input type="hidden" name="<?= $h($pk) ?>" value="<?= $h($pv) ?>">
          <?php endforeach; ?>
          <label for="ordPerPage" style="font-size:12px;color:#64748B;">Rows:</label>
          <select name="per_page" id="ordPerPage" class="ord-select" style="height:30px;" onchange="this.form.submit()">
            <?php foreach ($allowed_pp as $pp): ?>
              <option value="<?= (int)$pp ?>" <?= (int)$per_page === (int)$pp ? 'selected' : '' ?>><?= (int)$pp ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <div class="ord-page-links">
        <a class="ord-page-link <?= $current_page <= 1 ? 'is-disabled' : '' ?>"
           href="<?= $h($buildUrl(array('page' => max(1, $current_page - 1)))) ?>">← Prev</a>
        <?php
        $startP = max(1, $current_page - 2);
        $endP   = min($total_pages, $current_page + 2);
        for ($p = $startP; $p <= $endP; $p++):
        ?>
          <a class="ord-page-link <?= $p === $current_page ? 'is-active' : '' ?>"
             href="<?= $h($buildUrl(array('page' => $p))) ?>"><?= (int)$p ?></a>
        <?php endfor; ?>
        <a class="ord-page-link <?= $current_page >= $total_pages ? 'is-disabled' : '' ?>"
           href="<?= $h($buildUrl(array('page' => min($total_pages, $current_page + 1)))) ?>">Next →</a>
      </div>
    </div>

  </div>
</div>

<!-- Accessible Slide-Over Purchase Order & GRN Inspector Drawer -->
<div class="ord-drawer" id="ordDrawer" role="dialog" aria-modal="true" aria-labelledby="ordDrawerTitle">
  <div class="ord-backdrop" data-ord-close="1"></div>
  <div class="ord-drawer-panel">
    <div class="ord-drawer-head">
      <div>
        <div id="ordDrawerTitle" style="font-size:16px;font-weight:700;color:#0F172A;">Purchase Order</div>
        <div style="font-size:11.5px;color:#64748B;">Use ← / → keys to step across orders · Esc to close</div>
      </div>
      <button type="button" class="ord-btn ord-btn-sm" data-ord-close="1">✕</button>
    </div>
    <div class="ord-drawer-body" id="ordDrawerBody"></div>
    <div class="ord-drawer-foot" id="ordDrawerFoot"></div>
  </div>
</div>

<script>
(function () {
  var shell       = document.getElementById('ordShell');
  var summaryBase = <?= json_encode(rtrim(base_url('orders/summary_json'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var viewBase    = <?= json_encode(rtrim(base_url('orders/view'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var receiveBase = <?= json_encode(rtrim(base_url('orders/receive'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var grnBase     = <?= json_encode(rtrim(base_url('orders/grn'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var woBase      = <?= json_encode(rtrim(base_url('workorders/view'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
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
  var viewBtns  = document.querySelectorAll('[data-ord-view]');
  var viewInput = document.getElementById('ordViewHiddenInput');
  viewBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.getAttribute('data-ord-view') === 'cards' ? 'cards' : 'table';
      shell.setAttribute('data-view', mode);
      viewBtns.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      if (viewInput) viewInput.value = mode;
    });
  });

  // Multi-select checkboxes & sticky CSV/Excel batch bar
  var checkAll  = document.getElementById('ordCheckAll');
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.ord-row-check'));
  var batchBar  = document.getElementById('ordBatchBar');
  var countEl   = document.getElementById('ordSelectedCount');
  var exportBtn = document.getElementById('ordBatchExportBtn');
  var xlsxBtn   = document.getElementById('ordBatchXlsxBtn');
  var clearBtn  = document.getElementById('ordBatchClearBtn');

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

  // Slide-Over Purchase Order Drawer
  var drawer     = document.getElementById('ordDrawer');
  var drawerBody = document.getElementById('ordDrawerBody');
  var drawerFoot = document.getElementById('ordDrawerFoot');
  var drawerTit  = document.getElementById('ordDrawerTitle');
  var orderedIds = Array.prototype.slice.call(document.querySelectorAll('tr[data-ord-id]')).map(function (tr) {
    return parseInt(tr.getAttribute('data-ord-id'), 10);
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
    drawerTit.textContent = 'Order #' + activeId;
    drawerBody.innerHTML = '<div style="padding:24px;text-align:center;color:#64748B;">Loading purchase order &amp; line items…</div>';
    drawerFoot.innerHTML = '';

    fetch(summaryBase + activeId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok || !res.order) {
          drawerBody.innerHTML = '<div style="padding:18px;color:#B91C1C;">Could not load purchase order details.</div>';
          return;
        }
        var o = res.order;
        var items = res.items || [];
        var grns  = res.receipts || [];
        drawerTit.textContent = o.order_no + ' · ' + o.supplier_name;

        var woTxt = o.linked_wo
          ? ('<a href="' + esc(woBase + o.linked_wo.id) + '">' + esc(o.linked_wo.wo_number) + '</a>'
             + (o.linked_wo.registration_no ? ' · ' + esc(o.linked_wo.registration_no) : ''))
          : 'Stock Replenishment';

        var html = '<div style="margin-bottom:14px;">'
          + '<span class="ord-badge ord-badge-open">' + esc(o.status) + '</span> '
          + (o.discrepancy_flag !== 'None' ? '<span class="ord-badge ord-badge-warn">⚠ ' + esc(o.discrepancy_flag) + '</span>' : '')
          + '</div>';

        html += '<div class="ord-kv-grid">'
          + '<div class="ord-kv-box"><div class="ord-kv-lbl">Supplier &amp; Origin</div><div class="ord-kv-val">' + esc(o.supplier_name) + ' (' + esc(o.country) + ')</div></div>'
          + '<div class="ord-kv-box"><div class="ord-kv-lbl">Total Order Value</div><div class="ord-kv-val">' + esc(o.currency) + ' ' + Number(o.total_cost).toLocaleString(undefined, { minimumFractionDigits: 2 }) + '</div></div>'
          + '<div class="ord-kv-box"><div class="ord-kv-lbl">Order / Expected Date</div><div class="ord-kv-val">' + esc(o.order_date) + ' → ' + esc(o.expected_delivery_date) + '</div></div>'
          + '<div class="ord-kv-box"><div class="ord-kv-lbl">Freight &amp; Tracking</div><div class="ord-kv-val">' + esc(o.shipping_method) + ' · ' + esc(o.tracking_number) + '</div></div>'
          + '<div class="ord-kv-box" style="grid-column:span 2;"><div class="ord-kv-lbl">Linked Work Order &amp; Fleet Asset</div><div class="ord-kv-val">' + woTxt + '</div></div>'
          + '</div>';

        html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:10px 0 6px;">Order Line Items (' + items.length + ')</div>';
        if (!items.length) {
          html += '<div style="padding:12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:9px;color:#64748B;">No line items recorded.</div>';
        } else {
          html += '<div style="border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;margin-bottom:12px;">';
          items.forEach(function (it, idx) {
            html += '<div style="padding:9px 12px;display:flex;justify-content:space-between;gap:10px;' + (idx > 0 ? 'border-top:1px solid #E2E8F0;' : '') + '">'
              + '<div><div style="font-weight:600;color:#0F172A;">' + esc(it.description) + '</div>'
              + '<div style="font-size:11.5px;color:#64748B;">P/N: ' + esc(it.part_number) + '</div></div>'
              + '<div style="text-align:right;white-space:nowrap;">'
              + '<div style="font-weight:700;color:#0F172A;">' + esc(it.qty_received) + ' / ' + esc(it.qty_ordered) + ' ' + esc(it.unit) + '</div>'
              + '<div style="font-size:11px;color:' + (it.qty_outstanding > 0 ? '#B45309' : '#047857') + ';">'
              + (it.qty_outstanding > 0 ? ('Open: ' + esc(it.qty_outstanding)) : '✓ Complete') + '</div></div>'
              + '</div>';
          });
          html += '</div>';
        }

        if (grns.length) {
          html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:10px 0 6px;">Goods Receipts (GRN)</div>'
            + '<div style="border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;">';
          grns.forEach(function (g, idx) {
            html += '<div style="padding:8px 12px;display:flex;justify-content:space-between;align-items:center;' + (idx > 0 ? 'border-top:1px solid #E2E8F0;' : '') + '">'
              + '<div><strong>' + esc(g.grn_no) + '</strong> · ' + esc(g.receipt_date) + '</div>'
              + '<a class="ord-btn ord-btn-sm" target="_blank" href="' + esc(grnBase + o.id + '/' + g.id) + '">Print GRN</a>'
              + '</div>';
          });
          html += '</div>';
        }

        drawerBody.innerHTML = html;

        var foot = '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
          + '<a class="ord-btn ord-btn-primary ord-btn-sm" href="' + esc(viewBase + o.id) + '">Open Full PO</a>'
          + (o.can_receive ? '<a class="ord-btn ord-btn-sm" href="' + esc(receiveBase + o.id) + '">Receive Goods</a>' : '')
          + '</div>'
          + '<button type="button" class="ord-btn ord-btn-sm" id="ordDrawerDoneBtn">Close</button>';
        drawerFoot.innerHTML = foot;
        var doneBtn = document.getElementById('ordDrawerDoneBtn');
        if (doneBtn) doneBtn.addEventListener('click', closeDrawer);
      })
      .catch(function () {
        drawerBody.innerHTML = '<div style="padding:18px;color:#B91C1C;">Network error while loading purchase order.</div>';
      });
  }

  document.addEventListener('click', function (ev) {
    var openBtn = ev.target.closest('[data-ord-open]');
    if (openBtn) {
      ev.preventDefault();
      openDrawer(openBtn.getAttribute('data-ord-open'), openBtn);
      return;
    }
    if (ev.target.closest('[data-ord-close]')) {
      closeDrawer();
    }
  });

  document.addEventListener('keydown', function (ev) {
    var tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
    var inEditable = (tag === 'input' || tag === 'textarea' || tag === 'select' || (ev.target && ev.target.isContentEditable));

    if (ev.key === '/' && !inEditable && !ev.ctrlKey && !ev.metaKey && !ev.altKey) {
      var sInput = document.getElementById('ordSearchInput');
      if (sInput) {
        ev.preventDefault();
        sInput.focus();
        sInput.select();
      }
      return;
    }

    if (drawer && drawer.classList.contains('is-open')) {
      if (ev.key === 'Escape') {
        ev.preventDefault();
        closeDrawer();
      } else if (ev.key === 'ArrowRight' && orderedIds.length > 1) {
        ev.preventDefault();
        var idxR = orderedIds.indexOf(activeId);
        var nextId = orderedIds[(idxR + 1 + orderedIds.length) % orderedIds.length];
        openDrawer(nextId, null);
      } else if (ev.key === 'ArrowLeft' && orderedIds.length > 1) {
        ev.preventDefault();
        var idxL = orderedIds.indexOf(activeId);
        var prevId = orderedIds[(idxL - 1 + orderedIds.length) % orderedIds.length];
        openDrawer(prevId, null);
      }
    }
  });
})();
</script>
