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
$categories   = isset($categories) && is_array($categories) ? $categories : array();
$transactions = isset($transactions) && is_array($transactions) ? $transactions : array();
$kpi          = isset($kpi) && is_array($kpi) ? $kpi : array(
    'total_all' => 0, 'receive_count' => 0, 'issue_count' => 0,
    'equip_issues' => 0, 'valued_receipts' => 0, 'live_entries' => 0,
    'total_received_qty' => 0.0, 'total_issued_qty' => 0.0,
);
$tab_counts   = isset($tab_counts) && is_array($tab_counts) ? $tab_counts : array();
$filtered_totals = isset($filtered_totals) && is_array($filtered_totals) ? $filtered_totals : array('in_qty' => 0.0, 'out_qty' => 0.0);
$sort         = isset($sort) ? (string)$sort : 'txn_date';
$dir          = isset($dir) && strtolower($dir) === 'asc' ? 'asc' : 'desc';
$view_mode    = isset($view_mode) && $view_mode === 'cards' ? 'cards' : 'table';
$per_page     = isset($per_page) ? (int)$per_page : 25;
$allowed_pp   = isset($allowed_pp) && is_array($allowed_pp) ? $allowed_pp : array(10, 25, 50, 100, 150);
$current_page = isset($current_page) ? (int)$current_page : 1;
$total_pages  = isset($total_pages) ? (int)$total_pages : 1;
$total_filtered = isset($total_filtered) ? (int)$total_filtered : count($transactions);
$offset       = isset($offset) ? (int)$offset : 0;
$can_write    = !empty($can_write);

$g = function ($key) use ($filter) {
    return isset($filter[$key]) ? (string)$filter[$key] : '';
};

$baseParams = array(
    'tab'             => $tab,
    'q'               => $q,
    'type'            => $g('type'),
    'source'          => $g('source'),
    'category'        => $g('category'),
    'allocation_type' => $g('allocation_type'),
    'item_code'       => $g('item_code'),
    'date_from'       => $g('date_from'),
    'date_to'         => $g('date_to'),
    'sort'            => $sort,
    'dir'             => $dir,
    'view'            => $view_mode,
    'per_page'        => $per_page,
    'page'            => $current_page,
);

$buildUrl = function ($overrides = array(), $endpoint = 'materials/transactions') use ($baseParams) {
    $merged = array_merge($baseParams, $overrides);
    $clean  = array();
    foreach ($merged as $k => $v) {
        if ($v === '' || $v === null) continue;
        if ($k === 'tab' && $v === 'all') continue;
        if ($k === 'sort' && $v === 'txn_date' && (!isset($merged['dir']) || $merged['dir'] === 'desc')) continue;
        if ($k === 'dir' && $v === 'desc' && (!isset($merged['sort']) || $merged['sort'] === 'txn_date')) continue;
        if ($k === 'view' && $v === 'table') continue;
        if ($k === 'per_page' && (int)$v === 25) continue;
        if ($k === 'page' && (int)$v === 1) continue;
        $clean[$k] = $v;
    }
    $qs = http_build_query($clean);
    return base_url($endpoint) . ($qs !== '' ? '?' . $qs : '');
};

$csvExportUrl  = $buildUrl(array('page' => null, 'per_page' => null), 'materials/transactions_csv');
$xlsxExportUrl = $buildUrl(array('page' => null, 'per_page' => null), 'materials/transactions_xlsx');

$sortTh = function ($col, $label, $cls = '') use ($sort, $dir, $buildUrl, $h) {
    $isCurr  = ($sort === $col);
    $nextDir = ($isCurr && $dir === 'asc') ? 'desc' : 'asc';
    $aria    = $isCurr ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $arrow   = $isCurr ? ($dir === 'asc' ? '↑' : '↓') : '↕';
    $url     = $buildUrl(array('sort' => $col, 'dir' => $nextDir, 'page' => 1));
    return '<th class="' . $h($cls) . '" aria-sort="' . $aria . '">'
        . '<a class="txn-sort-link" href="' . $h($url) . '">'
        . '<span>' . $h($label) . '</span>'
        . '<span class="txn-sort-arrow' . ($isCurr ? ' is-on' : '') . '">' . $arrow . '</span>'
        . '</a></th>';
};

$chips = array();
if ($q !== '')                  $chips[] = array('Search: ' . $q, array('q' => '', 'page' => 1));
if ($g('item_code') !== '')     $chips[] = array('SKU: ' . $g('item_code'), array('item_code' => '', 'page' => 1));
if ($g('type') !== '')          $chips[] = array('Type: ' . $g('type'), array('type' => '', 'page' => 1));
if ($g('category') !== '')      $chips[] = array('Category: ' . $g('category'), array('category' => '', 'page' => 1));
if ($g('source') !== '')        $chips[] = array('Channel: ' . $g('source'), array('source' => '', 'page' => 1));
if ($g('allocation_type') !== '') $chips[] = array('Charged: ' . $g('allocation_type'), array('allocation_type' => '', 'page' => 1));
if ($g('date_from') !== '')     $chips[] = array('From: ' . $g('date_from'), array('date_from' => '', 'page' => 1));
if ($g('date_to') !== '')       $chips[] = array('To: ' . $g('date_to'), array('date_to' => '', 'page' => 1));
?>

<style>
  .txn-shell { display: flex; flex-direction: column; gap: 16px; max-width: 100%; }
  .txn-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 14px; flex-wrap: wrap;
  }
  .txn-title { font-size: 20px; font-weight: 700; color: #0F172A; letter-spacing: -0.02em; margin: 0; }
  .txn-subtitle { font-size: 12.5px; color: #64748B; margin-top: 3px; }
  .txn-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

  .txn-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px;
    font-size: 12.5px; font-weight: 600; border-radius: 8px; border: 1px solid #CBD5E1;
    background: #FFFFFF; color: #1E293B; text-decoration: none; cursor: pointer;
    transition: background .14s ease, border-color .14s ease; white-space: nowrap;
  }
  .txn-btn:hover { background: #F8FAFC; border-color: #94A3B8; color: #0F172A; text-decoration: none; }
  .txn-btn-primary { background: #1D4ED8; border-color: #1D4ED8; color: #FFFFFF; }
  .txn-btn-primary:hover { background: #1E40AF; border-color: #1E40AF; color: #FFFFFF; }
  .txn-btn-sm { padding: 4px 9px; font-size: 11.5px; border-radius: 6px; }

  /* KPI Telemetry Strip */
  .txn-kpi-grid {
    display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px;
  }
  .txn-kpi-card {
    background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px;
    padding: 13px 15px; display: flex; flex-direction: column; gap: 4px;
  }
  .txn-kpi-lbl {
    font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #64748B;
  }
  .txn-kpi-val {
    font-size: 22px; font-weight: 700; color: #0F172A; font-variant-numeric: tabular-nums; line-height: 1.15;
  }
  .txn-kpi-meta { font-size: 11.5px; color: #64748B; font-variant-numeric: tabular-nums; }
  .txn-val-green { color: #047857; }
  .txn-val-amber { color: #B45309; }
  .txn-val-blue  { color: #1D4ED8; }

  /* Unified Ledger Surface */
  .txn-surface {
    background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px; overflow: hidden; max-width: 100%;
  }
  .txn-tabs-bar {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 10px 14px 0; border-bottom: 1px solid #E2E8F0; background: #F8FAFC; flex-wrap: wrap;
  }
  .txn-tabs { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
  .txn-tab {
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 11px;
    font-size: 12.5px; font-weight: 600; color: #475569; text-decoration: none;
    border-bottom: 2px solid transparent; margin-bottom: -1px;
  }
  .txn-tab:hover { color: #0F172A; text-decoration: none; }
  .txn-tab.is-active { color: #1D4ED8; border-bottom-color: #1D4ED8; background: #FFFFFF; border-radius: 8px 8px 0 0; }
  .txn-tab-cnt {
    font-size: 10.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px;
    background: #E2E8F0; color: #334155; font-variant-numeric: tabular-nums;
  }
  .txn-tab.is-active .txn-tab-cnt { background: #DBEAFE; color: #1D4ED8; }

  .txn-view-seg {
    display: inline-flex; border: 1px solid #CBD5E1; border-radius: 8px; overflow: hidden;
    background: #FFFFFF; margin-bottom: 8px;
  }
  .txn-view-btn {
    border: 0; background: transparent; padding: 5px 10px; font-size: 11.5px;
    font-weight: 600; color: #475569; cursor: pointer;
  }
  .txn-view-btn.is-active { background: #1E293B; color: #FFFFFF; }

  /* Filter Toolbar */
  .txn-filter-panel { padding: 12px 14px; border-bottom: 1px solid #E2E8F0; background: #FFFFFF; }
  .txn-filter-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .txn-search-wrap { position: relative; flex: 1 1 240px; min-width: 190px; }
  .txn-input, .txn-select {
    height: 35px; padding: 0 10px; font-size: 12.5px; border: 1px solid #CBD5E1;
    border-radius: 8px; background: #FFFFFF; color: #0F172A;
  }
  .txn-search-wrap .txn-input { width: 100%; padding-right: 28px; }
  .txn-kbd {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    font-size: 10.5px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    padding: 1px 5px; border-radius: 4px; border: 1px solid #CBD5E1; color: #64748B; background: #F8FAFC;
    pointer-events: none;
  }

  .txn-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 9px; }
  .txn-chip {
    display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px;
    font-size: 11.5px; font-weight: 600; background: #EFF6FF; color: #1D4ED8; text-decoration: none;
  }
  .txn-chip:hover { background: #DBEAFE; text-decoration: none; }

  /* Sticky Batch Selection Bar */
  .txn-batch-bar {
    display: none; align-items: center; justify-content: space-between; gap: 10px;
    padding: 9px 14px; background: #0F172A; color: #FFFFFF; font-size: 12.5px;
  }
  .txn-batch-bar.is-open { display: flex; }

  /* Zero-Overflow Fixed Desktop DataTable */
  .txn-table-wrap { width: 100%; max-width: 100%; overflow-x: hidden; }
  .txn-table {
    width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 12.5px;
  }
  .txn-table thead th {
    padding: 9px 9px; background: #F8FAFC; border-bottom: 1px solid #E2E8F0;
    text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .04em; color: #475569; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .txn-table thead th.num, .txn-table tbody td.num, .txn-table tfoot td.num {
    text-align: right; font-variant-numeric: tabular-nums;
  }
  .txn-table thead th.ctr, .txn-table tbody td.ctr { text-align: center; }
  .txn-sort-link {
    display: inline-flex; align-items: center; gap: 4px; color: inherit; text-decoration: none; max-width: 100%;
  }
  .txn-sort-link:hover { color: #0F172A; text-decoration: none; }
  .txn-sort-arrow { font-size: 10.5px; color: #94A3B8; }
  .txn-sort-arrow.is-on { color: #1D4ED8; font-weight: 700; }

  .txn-table tbody tr { border-bottom: 1px solid #F1F5F9; transition: background .1s ease; }
  .txn-table tbody tr:hover { background: #F8FAFC; }
  .txn-table tbody td {
    padding: 9px 9px; vertical-align: middle; color: #1E293B;
    overflow: hidden; text-overflow: ellipsis;
  }
  .txn-table tfoot td {
    padding: 10px 9px; background: #F8FAFC; border-top: 2px solid #E2E8F0;
    font-size: 12px; font-weight: 700; color: #0F172A;
  }

  .txn-cell-main {
    font-weight: 600; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .txn-cell-sub {
    font-size: 11px; color: #64748B; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px;
  }
  .txn-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11.5px; }
  .txn-link-btn {
    border: 0; background: none; padding: 0; color: #1D4ED8; font-weight: 700;
    cursor: pointer; text-align: left; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .txn-link-btn:hover { text-decoration: underline; }

  .txn-badge {
    display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px;
    border-radius: 999px; font-size: 10.5px; font-weight: 700; white-space: nowrap;
  }
  .txn-badge-in   { background: #DCFCE7; color: #166534; }
  .txn-badge-out  { background: #FEF3C7; color: #92400E; }
  .txn-badge-hist { background: #F1F5F9; color: #475569; }
  .txn-badge-eq   { background: #EFF6FF; color: #1D4ED8; }

  .txn-row-actions { display: inline-flex; align-items: center; gap: 4px; justify-content: flex-end; }

  /* Responsive Card View */
  .txn-cards-container { display: none; padding: 14px; }
  .txn-cards-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 12px;
  }
  .txn-card {
    border: 1px solid #E2E8F0; border-radius: 12px; padding: 13px; background: #FFFFFF;
    display: flex; flex-direction: column; gap: 8px;
  }

  #txnShell[data-view="cards"] .txn-table-wrap { display: none; }
  #txnShell[data-view="cards"] .txn-cards-container { display: block; }

  /* Pagination Footer */
  .txn-pager {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 11px 14px; border-top: 1px solid #E2E8F0; background: #FFFFFF; flex-wrap: wrap;
    font-size: 12.5px; color: #475569;
  }
  .txn-page-links { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
  .txn-page-link {
    padding: 5px 10px; border-radius: 7px; border: 1px solid #CBD5E1; background: #FFFFFF;
    color: #1E293B; font-size: 12px; font-weight: 600; text-decoration: none;
  }
  .txn-page-link:hover { background: #F1F5F9; text-decoration: none; }
  .txn-page-link.is-active { background: #1D4ED8; border-color: #1D4ED8; color: #FFFFFF; }
  .txn-page-link.is-disabled { opacity: .45; pointer-events: none; }

  /* Slide-Over Voucher Inspector Drawer */
  .txn-drawer {
    position: fixed; inset: 0; z-index: 1050; display: none;
  }
  .txn-drawer.is-open { display: block; }
  .txn-backdrop {
    position: fixed; inset: 0; background: rgba(15, 23, 42, .45);
  }
  .txn-drawer-panel {
    position: fixed; top: 0; right: 0; bottom: 0; width: min(500px, 100vw);
    background: #FFFFFF; box-shadow: -12px 0 32px rgba(15, 23, 42, .18);
    display: flex; flex-direction: column; z-index: 1;
  }
  .txn-drawer-head {
    padding: 15px 18px; border-bottom: 1px solid #E2E8F0;
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
  }
  .txn-drawer-body { padding: 16px 18px; overflow-y: auto; flex: 1; font-size: 13px; color: #1E293B; }
  .txn-drawer-foot {
    padding: 12px 18px; border-top: 1px solid #E2E8F0; background: #F8FAFC;
    display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
  }
  .txn-kv-grid {
    display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px;
  }
  .txn-kv-box {
    background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 9px; padding: 9px 11px;
  }
  .txn-kv-lbl { font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: #64748B; }
  .txn-kv-val { font-size: 13px; font-weight: 600; color: #0F172A; margin-top: 2px; word-break: break-word; }

  @media (max-width: 1099px) {
    .txn-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 768px) {
    .txn-kpi-grid { grid-template-columns: 1fr; }
    .txn-table-wrap { display: none !important; }
    .txn-cards-container { display: block !important; }
    .txn-view-seg { display: none; }
  }
  @media print {
    .sidebar, .topbar, .txn-actions, .txn-tabs-bar, .txn-filter-panel,
    .txn-batch-bar, .txn-pager, .txn-drawer, .txn-col-chk, .txn-col-act { display: none !important; }
    .txn-surface, .txn-kpi-card { border: 1px solid #CBD5E1; box-shadow: none; }
    .txn-table-wrap { display: block !important; overflow: visible !important; }
  }
</style>

<div class="txn-shell" id="txnShell" data-view="<?= $h($view_mode) ?>">

  <!-- Page Header & Export Toolbar -->
  <div class="txn-head">
    <div>
      <h1 class="txn-title">Stock Transaction Ledger</h1>
      <div class="txn-subtitle">
        Audit register of goods receipts (MRN), workshop requisitions (R.non), fleet machine allocations &amp; stock balances
      </div>
    </div>
    <div class="txn-actions">
      <a class="txn-btn" href="<?= $h($csvExportUrl) ?>">↓ Export CSV</a>
      <a class="txn-btn" href="<?= $h($xlsxExportUrl) ?>">↓ Export Excel</a>
      <a class="txn-btn txn-btn-primary" href="<?= $h(base_url('materials/inventory')) ?>">← Inventory Catalogue</a>
    </div>
  </div>

  <!-- 5-Card KPI Telemetry Strip -->
  <div class="txn-kpi-grid">
    <div class="txn-kpi-card">
      <div class="txn-kpi-lbl">Total Ledger Entries</div>
      <div class="txn-kpi-val"><?= number_format((int)$kpi['total_all']) ?></div>
      <div class="txn-kpi-meta"><?= number_format((int)$kpi['live_entries']) ?> manual / live postings</div>
    </div>
    <div class="txn-kpi-card">
      <div class="txn-kpi-lbl">Stock Receipts (+In)</div>
      <div class="txn-kpi-val txn-val-green"><?= number_format((int)$kpi['receive_count']) ?></div>
      <div class="txn-kpi-meta">+<?= $h($fmtQty($kpi['total_received_qty'])) ?> units received</div>
    </div>
    <div class="txn-kpi-card">
      <div class="txn-kpi-lbl">Workshop Issues (−Out)</div>
      <div class="txn-kpi-val txn-val-amber"><?= number_format((int)$kpi['issue_count']) ?></div>
      <div class="txn-kpi-meta">−<?= $h($fmtQty($kpi['total_issued_qty'])) ?> units issued</div>
    </div>
    <div class="txn-kpi-card">
      <div class="txn-kpi-lbl">Charged to Fleet Assets</div>
      <div class="txn-kpi-val txn-val-blue"><?= number_format((int)$kpi['equip_issues']) ?></div>
      <div class="txn-kpi-meta">Direct machine spare issues</div>
    </div>
    <div class="txn-kpi-card">
      <div class="txn-kpi-lbl">Valued Receipts</div>
      <div class="txn-kpi-val"><?= number_format((int)$kpi['valued_receipts']) ?></div>
      <div class="txn-kpi-meta">Receipts with unit pricing</div>
    </div>
  </div>

  <!-- Unified Ledger Surface -->
  <div class="txn-surface">

    <!-- 6 Governance Tabs + Table/Cards Switcher -->
    <div class="txn-tabs-bar">
      <nav class="txn-tabs" role="tablist" aria-label="Stock transaction views">
        <?php
        $tabsDef = array(
            'all'       => array('All Movements',    isset($tab_counts['all']) ? (int)$tab_counts['all'] : 0),
            'receive'   => array('Receipts (+In)',   isset($tab_counts['receive']) ? (int)$tab_counts['receive'] : 0),
            'issue'     => array('Issues (−Out)',    isset($tab_counts['issue']) ? (int)$tab_counts['issue'] : 0),
            'equipment' => array('Fleet Equipment',  isset($tab_counts['equipment']) ? (int)$tab_counts['equipment'] : 0),
            'valued'    => array('Valued Receipts',  isset($tab_counts['valued']) ? (int)$tab_counts['valued'] : 0),
            'live'      => array('Live Postings',    isset($tab_counts['live']) ? (int)$tab_counts['live'] : 0),
        );
        foreach ($tabsDef as $tk => $meta):
            list($tLabel, $tCnt) = $meta;
        ?>
          <a class="txn-tab <?= $tab === $tk ? 'is-active' : '' ?>"
             href="<?= $h($buildUrl(array('tab' => $tk, 'page' => 1))) ?>">
            <span><?= $h($tLabel) ?></span>
            <span class="txn-tab-cnt"><?= number_format($tCnt) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="txn-view-seg" role="group" aria-label="Layout view">
        <button type="button" class="txn-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>" data-txn-view="table">Table</button>
        <button type="button" class="txn-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>" data-txn-view="cards">Cards</button>
      </div>
    </div>

    <!-- Search & Facet Filter Toolbar -->
    <div class="txn-filter-panel">
      <form method="get" action="<?= $h(base_url('materials/transactions')) ?>" class="txn-filter-form">
        <input type="hidden" name="tab" value="<?= $h($tab) ?>">
        <input type="hidden" name="view" value="<?= $h($view_mode) ?>" id="txnViewHiddenInput">

        <div class="txn-search-wrap">
          <input type="search" name="q" id="txnSearchInput" class="txn-input"
                 value="<?= $h($q) ?>"
                 placeholder="Search SKU, MRN / R.non ref, machine (EX 006), supplier, collector…">
          <span class="txn-kbd">/</span>
        </div>

        <select name="type" class="txn-select" aria-label="Movement Type" onchange="this.form.submit()">
          <option value="">All Types</option>
          <option value="RECEIVE" <?= $g('type') === 'RECEIVE' ? 'selected' : '' ?>>Receive (+In)</option>
          <option value="ISSUE"   <?= $g('type') === 'ISSUE' ? 'selected' : '' ?>>Issue (−Out)</option>
        </select>

        <select name="category" class="txn-select" aria-label="Category" onchange="this.form.submit()">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $h($c->category) ?>" <?= $g('category') === (string)$c->category ? 'selected' : '' ?>>
              <?= $h($c->category) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select name="source" class="txn-select" aria-label="Source Channel" onchange="this.form.submit()">
          <option value="">All Channels</option>
          <?php foreach (array('Manual', 'Order GRN', 'Work Order', 'Excel Import') as $srcOpt): ?>
            <option value="<?= $h($srcOpt) ?>" <?= $g('source') === $srcOpt ? 'selected' : '' ?>><?= $h($srcOpt) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="allocation_type" class="txn-select" aria-label="Allocation Type" onchange="this.form.submit()">
          <option value="">All Allocations</option>
          <?php foreach (array('Equipment', 'Department', 'Other') as $alOpt): ?>
            <option value="<?= $h($alOpt) ?>" <?= $g('allocation_type') === $alOpt ? 'selected' : '' ?>><?= $h($alOpt) ?></option>
          <?php endforeach; ?>
        </select>

        <input type="date" name="date_from" class="txn-input" aria-label="From date"
               value="<?= $h($g('date_from')) ?>" title="Business date from">
        <input type="date" name="date_to" class="txn-input" aria-label="To date"
               value="<?= $h($g('date_to')) ?>" title="Business date to">

        <button type="submit" class="txn-btn txn-btn-primary">Filter</button>
        <?php if (!empty($chips) || $tab !== 'all'): ?>
          <a class="txn-btn" href="<?= $h(base_url('materials/transactions')) ?>">Clear all</a>
        <?php endif; ?>
      </form>

      <?php if (!empty($chips)): ?>
        <div class="txn-chips">
          <?php foreach ($chips as $ch): ?>
            <a class="txn-chip" href="<?= $h($buildUrl($ch[1])) ?>">
              <span><?= $h($ch[0]) ?></span>
              <span aria-hidden="true">×</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Sticky Multi-Select Batch Bar -->
    <div class="txn-batch-bar" id="txnBatchBar" role="region" aria-label="Selected stock movements">
      <div><strong id="txnSelectedCount">0</strong> movement(s) selected</div>
      <div style="display:flex;align-items:center;gap:8px;">
        <a class="txn-btn txn-btn-primary txn-btn-sm" id="txnBatchExportBtn" href="<?= $h($csvExportUrl) ?>">↓ Export Selected CSV</a>
        <a class="txn-btn txn-btn-sm" id="txnBatchXlsxBtn" href="<?= $h($xlsxExportUrl) ?>">↓ Export Selected Excel</a>
        <button type="button" class="txn-btn txn-btn-sm" id="txnBatchClearBtn">Clear</button>
      </div>
    </div>

    <?php if (empty($transactions)): ?>
      <div style="padding:56px 24px;text-align:center;color:#64748B;">
        <div style="font-size:15px;font-weight:700;color:#0F172A;margin-bottom:6px;">No matching stock movements</div>
        <div style="font-size:12.5px;margin-bottom:14px;">
          Adjust your search query, date window, or governance tab to inspect historical receipts and issues.
        </div>
        <a class="txn-btn" href="<?= $h(base_url('materials/transactions')) ?>">Reset Ledger Filters</a>
      </div>
    <?php else: ?>

      <!-- Desktop 1440px Zero-Overflow 9-Column DataTable -->
      <div class="txn-table-wrap">
        <table class="txn-table">
          <colgroup>
            <col style="width:36px;">
            <col style="width:11%;">
            <col style="width:12%;">
            <col style="width:22%;">
            <col style="width:16%;">
            <col style="width:9%;">
            <col style="width:10%;">
            <col style="width:11%;">
            <col style="width:auto;">
          </colgroup>
          <thead>
            <tr>
              <th class="txn-col-chk ctr">
                <input type="checkbox" id="txnCheckAll" aria-label="Select all visible movements">
              </th>
              <?= $sortTh('txn_date', 'Business Date') ?>
              <?= $sortTh('type', 'Type & Ref') ?>
              <?= $sortTh('item_code', 'SKU & Item') ?>
              <?= $sortTh('allocation', 'Charged To / Supplier') ?>
              <?= $sortTh('qty', 'Qty', 'num') ?>
              <?= $sortTh('stock_after', 'Balance', 'num') ?>
              <?= $sortTh('unit_price', 'Value / By', 'num') ?>
              <th class="txn-col-act" style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transactions as $t):
                $tid   = (int)$t->id;
                $isRec = ($t->type === 'RECEIVE');
                $mvTs  = !empty($t->movement_date) ? strtotime($t->movement_date) : false;
                $src   = isset($t->source) && $t->source !== '' ? (string)$t->source : 'Manual';
                $refNo = isset($t->reference_no) && trim((string)$t->reference_no) !== '' ? trim((string)$t->reference_no) : '';
                $eqId  = isset($t->equipment_id) && (int)$t->equipment_id > 0 ? (int)$t->equipment_id : 0;
                $price = isset($t->unit_price) && $t->unit_price !== null && (float)$t->unit_price > 0 ? (float)$t->unit_price : null;
                $curr  = isset($t->currency) && $t->currency !== '' ? (string)$t->currency : '';
            ?>
              <tr data-txn-id="<?= $tid ?>">
                <td class="txn-col-chk ctr">
                  <input type="checkbox" class="txn-row-check" value="<?= $tid ?>"
                         aria-label="Select transaction <?= $tid ?>">
                </td>
                <td>
                  <div class="txn-cell-main txn-mono">
                    <?= $mvTs ? $h(date('d M Y', $mvTs)) : $h($t->movement_date ?: '—') ?>
                  </div>
                  <div class="txn-cell-sub"><?= $h($src) ?></div>
                </td>
                <td>
                  <span class="txn-badge <?= $isRec ? 'txn-badge-in' : 'txn-badge-out' ?>">
                    <?= $isRec ? '+ Receive' : '− Issue' ?>
                  </span>
                  <div class="txn-cell-sub txn-mono">
                    <?= $refNo !== '' ? $h(($isRec ? 'MRN: ' : 'Ref: ') . $refNo) : 'TXN-' . $tid ?>
                  </div>
                </td>
                <td>
                  <button type="button" class="txn-link-btn txn-mono" data-txn-open="<?= $tid ?>">
                    <?= $h($t->item_code ?: ('ITEM #' . (int)$t->material_id)) ?>
                  </button>
                  <div class="txn-cell-main" style="font-weight:500;" title="<?= $h($t->description ?? '') ?>">
                    <?= $h($t->description ?? '—') ?>
                  </div>
                  <div class="txn-cell-sub">
                    <?= $h($t->category ?? '—') ?><?= !empty($t->part_number_size) ? ' · ' . $h($t->part_number_size) : '' ?>
                  </div>
                </td>
                <td>
                  <?php if ($eqId > 0 && !empty($t->equipment_code)): ?>
                    <a class="txn-badge txn-badge-eq txn-mono" href="<?= $h(base_url('equipment/view/' . $eqId)) ?>">
                      <?= $h($t->equipment_code) ?>
                    </a>
                    <div class="txn-cell-sub">Fleet Equipment</div>
                  <?php elseif (!empty($t->counterparty)): ?>
                    <div class="txn-cell-main" title="<?= $h($t->counterparty) ?>"><?= $h($t->counterparty) ?></div>
                    <div class="txn-cell-sub"><?= $h($isRec ? 'Supplier' : ($t->allocation_type ?? 'Allocation')) ?></div>
                  <?php else: ?>
                    <span style="color:#94A3B8;">—</span>
                  <?php endif; ?>
                </td>
                <td class="num">
                  <span class="txn-mono" style="font-weight:700;color:<?= $isRec ? '#047857' : '#B45309' ?>;">
                    <?= $isRec ? '+' : '−' ?><?= $h($fmtQty(abs((float)$t->qty))) ?>
                  </span>
                  <div class="txn-cell-sub"><?= $h($t->unit ?? 'pcs') ?></div>
                </td>
                <td class="num">
                  <?php if (!empty($t->is_live_balance) && $t->stock_after !== null): ?>
                    <div class="txn-cell-main txn-mono">
                      <?= $t->stock_before !== null ? $h($fmtQty($t->stock_before)) . ' → ' : '' ?><?= $h($fmtQty($t->stock_after)) ?>
                    </div>
                  <?php else: ?>
                    <span class="txn-badge txn-badge-hist" title="Imported historical register movement (affects_balance = 0)">Imported</span>
                  <?php endif; ?>
                </td>
                <td class="num">
                  <?php if ($price !== null): ?>
                    <div class="txn-cell-main txn-mono"><?= $h(($curr !== '' ? $curr . ' ' : '') . number_format($price, 2)) ?></div>
                  <?php endif; ?>
                  <div class="txn-cell-sub" title="<?= $h($t->operator_display ?: ($t->notes ?? '')) ?>">
                    <?= $h($t->operator_display !== '' ? $t->operator_display : ($t->notes ? $t->notes : '—')) ?>
                  </div>
                </td>
                <td class="txn-col-act" style="text-align:right;">
                  <div class="txn-row-actions">
                    <button type="button" class="txn-btn txn-btn-sm" data-txn-open="<?= $tid ?>">View</button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td class="txn-col-chk"></td>
              <td colspan="4">
                Filtered Totals (<?= number_format($total_filtered) ?> matching movements)
              </td>
              <td class="num txn-mono" colspan="2">
                <span style="color:#047857;">+<?= $h($fmtQty($filtered_totals['in_qty'])) ?></span>
                &nbsp;/&nbsp;
                <span style="color:#B45309;">−<?= $h($fmtQty($filtered_totals['out_qty'])) ?></span>
              </td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Responsive Mobile & Optional Desktop Card View -->
      <div class="txn-cards-container">
        <div class="txn-cards-grid">
          <?php foreach ($transactions as $t):
              $tid   = (int)$t->id;
              $isRec = ($t->type === 'RECEIVE');
              $mvTs  = !empty($t->movement_date) ? strtotime($t->movement_date) : false;
              $refNo = isset($t->reference_no) && trim((string)$t->reference_no) !== '' ? trim((string)$t->reference_no) : '';
          ?>
            <div class="txn-card">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                <span class="txn-badge <?= $isRec ? 'txn-badge-in' : 'txn-badge-out' ?>">
                  <?= $isRec ? '+ Receive' : '− Issue' ?>
                </span>
                <span class="txn-mono" style="font-size:11.5px;color:#475569;">
                  <?= $mvTs ? $h(date('d M Y', $mvTs)) : $h($t->movement_date ?: '—') ?>
                </span>
              </div>
              <div>
                <div class="txn-mono" style="font-weight:700;color:#1D4ED8;"><?= $h($t->item_code ?? '—') ?></div>
                <div style="font-weight:600;color:#0F172A;"><?= $h($t->description ?? '—') ?></div>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:12px;color:#475569;">
                <span><?= $h($t->counterparty !== '' ? $t->counterparty : ($refNo !== '' ? 'Ref: ' . $refNo : 'TXN-' . $tid)) ?></span>
                <strong class="txn-mono" style="color:<?= $isRec ? '#047857' : '#B45309' ?>;">
                  <?= $isRec ? '+' : '−' ?><?= $h($fmtQty(abs((float)$t->qty))) ?> <?= $h($t->unit ?? 'pcs') ?>
                </strong>
              </div>
              <div style="display:flex;justify-content:flex-end;gap:8px;padding-top:4px;border-top:1px solid #F1F5F9;">
                <button type="button" class="txn-btn txn-btn-sm" data-txn-open="<?= $tid ?>">Inspect Voucher</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php endif; ?>

    <!-- Pagination Footer -->
    <div class="txn-pager">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span>
          Showing <strong><?= $total_filtered > 0 ? number_format($offset + 1) : '0' ?></strong>–<strong><?= number_format(min($offset + count($transactions), $total_filtered)) ?></strong>
          of <strong><?= number_format($total_filtered) ?></strong> movements
        </span>
        <form method="get" action="<?= $h(base_url('materials/transactions')) ?>" style="display:inline-flex;align-items:center;gap:6px;">
          <?php foreach ($baseParams as $pk => $pv):
              if ($pk === 'per_page' || $pk === 'page' || $pv === '' || $pv === null) continue;
          ?>
            <input type="hidden" name="<?= $h($pk) ?>" value="<?= $h($pv) ?>">
          <?php endforeach; ?>
          <label for="txnPerPage" style="font-size:12px;color:#64748B;">Rows:</label>
          <select name="per_page" id="txnPerPage" class="txn-select" style="height:30px;" onchange="this.form.submit()">
            <?php foreach ($allowed_pp as $pp): ?>
              <option value="<?= (int)$pp ?>" <?= (int)$per_page === (int)$pp ? 'selected' : '' ?>><?= (int)$pp ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <div class="txn-page-links">
        <a class="txn-page-link <?= $current_page <= 1 ? 'is-disabled' : '' ?>"
           href="<?= $h($buildUrl(array('page' => max(1, $current_page - 1)))) ?>">← Prev</a>
        <?php
        $startP = max(1, $current_page - 2);
        $endP   = min($total_pages, $current_page + 2);
        for ($p = $startP; $p <= $endP; $p++):
        ?>
          <a class="txn-page-link <?= $p === $current_page ? 'is-active' : '' ?>"
             href="<?= $h($buildUrl(array('page' => $p))) ?>"><?= (int)$p ?></a>
        <?php endfor; ?>
        <a class="txn-page-link <?= $current_page >= $total_pages ? 'is-disabled' : '' ?>"
           href="<?= $h($buildUrl(array('page' => min($total_pages, $current_page + 1)))) ?>">Next →</a>
      </div>
    </div>

  </div>
</div>

<!-- Accessible Slide-Over Stock Voucher Drawer -->
<div class="txn-drawer" id="txnDrawer" role="dialog" aria-modal="true" aria-labelledby="txnDrawerTitle">
  <div class="txn-backdrop" data-txn-close="1"></div>
  <div class="txn-drawer-panel">
    <div class="txn-drawer-head">
      <div>
        <div id="txnDrawerTitle" style="font-size:16px;font-weight:700;color:#0F172A;">Movement Voucher</div>
        <div style="font-size:11.5px;color:#64748B;">Use ← / → keys to step across movements · Esc to close</div>
      </div>
      <button type="button" class="txn-btn txn-btn-sm" data-txn-close="1">✕</button>
    </div>
    <div class="txn-drawer-body" id="txnDrawerBody"></div>
    <div class="txn-drawer-foot" id="txnDrawerFoot"></div>
  </div>
</div>

<script>
(function () {
  var shell       = document.getElementById('txnShell');
  var detailBase  = <?= json_encode(rtrim(base_url('materials/transaction_json'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var invBase     = <?= json_encode(base_url('materials/inventory'), JSON_UNESCAPED_SLASHES) ?>;
  var eqBase      = <?= json_encode(rtrim(base_url('equipment/view'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
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
  var viewBtns  = document.querySelectorAll('[data-txn-view]');
  var viewInput = document.getElementById('txnViewHiddenInput');
  viewBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.getAttribute('data-txn-view') === 'cards' ? 'cards' : 'table';
      shell.setAttribute('data-view', mode);
      viewBtns.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      if (viewInput) viewInput.value = mode;
    });
  });

  // Multi-select checkboxes & sticky CSV/Excel batch bar
  var checkAll  = document.getElementById('txnCheckAll');
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.txn-row-check'));
  var batchBar  = document.getElementById('txnBatchBar');
  var countEl   = document.getElementById('txnSelectedCount');
  var exportBtn = document.getElementById('txnBatchExportBtn');
  var xlsxBtn   = document.getElementById('txnBatchXlsxBtn');
  var clearBtn  = document.getElementById('txnBatchClearBtn');

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

  // Slide-Over Movement Voucher Drawer
  var drawer     = document.getElementById('txnDrawer');
  var drawerBody = document.getElementById('txnDrawerBody');
  var drawerFoot = document.getElementById('txnDrawerFoot');
  var drawerTit  = document.getElementById('txnDrawerTitle');
  var orderedIds = Array.prototype.slice.call(document.querySelectorAll('tr[data-txn-id]')).map(function (tr) {
    return parseInt(tr.getAttribute('data-txn-id'), 10);
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
    drawerTit.textContent = 'Voucher TXN-' + activeId;
    drawerBody.innerHTML = '<div style="padding:24px;text-align:center;color:#64748B;">Loading voucher details…</div>';
    drawerFoot.innerHTML = '';

    fetch(detailBase + activeId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok || !res.txn) {
          drawerBody.innerHTML = '<div style="padding:18px;color:#B91C1C;">Could not load movement voucher.</div>';
          return;
        }
        var t = res.txn;
        var isRec = (t.type === 'RECEIVE');
        drawerTit.textContent = 'TXN-' + t.id + ' · ' + t.item_code;

        var balTxt = t.is_live_balance && t.stock_after != null
          ? ((t.stock_before != null ? t.stock_before + ' → ' : '') + t.stock_after + ' ' + t.unit)
          : 'Imported Register Row';
        var priceTxt = t.unit_price != null
          ? ((t.currency ? t.currency + ' ' : '') + Number(t.unit_price).toLocaleString())
          : '—';

        var html = '<div style="margin-bottom:14px;">'
          + '<span class="txn-badge ' + (isRec ? 'txn-badge-in' : 'txn-badge-out') + '">'
          + (isRec ? '+ Stock Receipt' : '− Workshop Issue') + '</span> '
          + '<span class="txn-badge txn-badge-hist">' + esc(t.source) + '</span>'
          + '<div style="font-size:15px;font-weight:700;color:#0F172A;margin-top:8px;">' + esc(t.description) + '</div>'
          + '<div style="font-size:12px;color:#64748B;margin-top:2px;">SKU ' + esc(t.item_code)
          + ' · Part No: ' + esc(t.part_number_size) + ' · Bin: ' + esc(t.location) + '</div>'
          + '</div>';

        html += '<div class="txn-kv-grid">'
          + '<div class="txn-kv-box"><div class="txn-kv-lbl">Business Date</div><div class="txn-kv-val">' + esc(t.movement_date) + '</div></div>'
          + '<div class="txn-kv-box"><div class="txn-kv-lbl">Reference (MRN / R.non)</div><div class="txn-kv-val">' + esc(t.reference_no) + '</div></div>'
          + '<div class="txn-kv-box"><div class="txn-kv-lbl">Quantity Moved</div><div class="txn-kv-val">' + (isRec ? '+' : '−') + esc(t.qty) + ' ' + esc(t.unit) + '</div></div>'
          + '<div class="txn-kv-box"><div class="txn-kv-lbl">Stock Before → After</div><div class="txn-kv-val">' + esc(balTxt) + '</div></div>'
          + '<div class="txn-kv-box"><div class="txn-kv-lbl">' + (isRec ? 'Supplier' : 'Charged To / Asset') + '</div><div class="txn-kv-val">'
          + (t.equipment_id ? '<a href="' + esc(eqBase + t.equipment_id) + '">' + esc(t.equipment_code) + '</a>' : esc(t.counterparty))
          + '</div></div>'
          + '<div class="txn-kv-box"><div class="txn-kv-lbl">Unit Valuation</div><div class="txn-kv-val">' + esc(priceTxt) + '</div></div>'
          + '<div class="txn-kv-box"><div class="txn-kv-lbl">Operator / Collector</div><div class="txn-kv-val">' + esc(t.operator_display) + '</div></div>'
          + '<div class="txn-kv-box"><div class="txn-kv-lbl">Current SKU Balance</div><div class="txn-kv-val">'
          + esc(t.current_sku_balance != null ? (t.current_sku_balance + ' ' + t.unit) : '—') + '</div></div>'
          + '</div>';

        html += '<div class="txn-kv-box" style="margin-bottom:8px;">'
          + '<div class="txn-kv-lbl">Audit Notes / Remarks</div>'
          + '<div class="txn-kv-val" style="font-weight:500;">' + esc(t.notes) + '</div>'
          + '</div>';

        drawerBody.innerHTML = html;

        var foot = '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
          + '<a class="txn-btn txn-btn-sm" href="' + esc(invBase + '?q=' + encodeURIComponent(t.item_code)) + '">View SKU in Catalogue</a>'
          + (t.can_write && t.material_id ? '<a class="txn-btn txn-btn-primary txn-btn-sm" href="' + esc(receiveBase + t.material_id) + '">+ Receive</a>' : '')
          + (t.can_write && t.material_id ? '<a class="txn-btn txn-btn-sm" href="' + esc(issueBase + t.material_id) + '">− Issue</a>' : '')
          + '</div>'
          + '<button type="button" class="txn-btn txn-btn-sm" id="txnDrawerDoneBtn">Close</button>';
        drawerFoot.innerHTML = foot;
        var doneBtn = document.getElementById('txnDrawerDoneBtn');
        if (doneBtn) doneBtn.addEventListener('click', closeDrawer);
      })
      .catch(function () {
        drawerBody.innerHTML = '<div style="padding:18px;color:#B91C1C;">Network error loading voucher details.</div>';
      });
  }

  document.addEventListener('click', function (ev) {
    var openBtn = ev.target.closest('[data-txn-open]');
    if (openBtn) {
      ev.preventDefault();
      openDrawer(openBtn.getAttribute('data-txn-open'), openBtn);
      return;
    }
    if (ev.target.closest('[data-txn-close]')) {
      closeDrawer();
    }
  });

  document.addEventListener('keydown', function (ev) {
    var tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
    var inEditable = (tag === 'input' || tag === 'textarea' || tag === 'select' || (ev.target && ev.target.isContentEditable));

    if (ev.key === '/' && !inEditable && !ev.ctrlKey && !ev.metaKey && !ev.altKey) {
      var sInput = document.getElementById('txnSearchInput');
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
