<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$h = function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};

$fmtQty = function ($n) {
    return rtrim(rtrim(number_format((float)$n, 2, '.', ','), '0'), '.');
};

$tab             = isset($tab) ? (string)$tab : 'all';
$q               = isset($q) ? (string)$q : '';
$filter          = isset($filter) && is_array($filter) ? $filter : array();
$lines           = isset($lines) && is_array($lines) ? $lines : array();
$groups          = isset($groups) && is_array($groups) ? $groups : array();
$unlinked        = isset($unlinked) && is_array($unlinked) ? $unlinked : array();
$line_allocs     = isset($line_allocs) && is_array($line_allocs) ? $line_allocs : array();
$on_order        = isset($on_order) && is_array($on_order) ? $on_order : array();
$ready_wos       = isset($ready_wos) && is_array($ready_wos) ? $ready_wos : array();
$new_alerts      = isset($new_alerts) && is_array($new_alerts) ? $new_alerts : array();
$summary         = isset($summary) && is_array($summary) ? $summary : array(
    'lines' => 0, 'available_lines' => 0, 'pending_lines' => 0, 'partial_lines' => 0,
    'allocated_lines' => 0, 'unlinked_lines' => 0, 'wos' => 0, 'ready_wos' => 0,
    'on_order_parts' => 0, 'new_alerts' => 0,
);
$tab_counts      = isset($tab_counts) && is_array($tab_counts) ? $tab_counts : array();
$filtered_totals = isset($filtered_totals) && is_array($filtered_totals)
    ? $filtered_totals
    : array('qty_required' => 0.0, 'qty_available' => 0.0, 'qty_allocated' => 0.0, 'qty_shortfall' => 0.0);
$statuses        = isset($statuses) && is_array($statuses) ? $statuses : array('Pending', 'Partial', 'Available', 'Allocated', 'Unlinked');
$priorities      = isset($priorities) && is_array($priorities) ? $priorities : array('Critical', 'High', 'Medium', 'Low');
$wo_statuses     = isset($wo_statuses) && is_array($wo_statuses) ? $wo_statuses : array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools');
$sort            = isset($sort) ? (string)$sort : 'priority';
$dir             = isset($dir) && strtolower($dir) === 'desc' ? 'desc' : 'asc';
$view_mode       = isset($view_mode) && in_array($view_mode, array('table', 'groups', 'cards'), TRUE) ? $view_mode : 'table';
$per_page        = isset($per_page) ? (int)$per_page : 25;
$allowed_pp      = isset($allowed_pp) && is_array($allowed_pp) ? $allowed_pp : array(10, 25, 50, 100, 150);
$current_page    = isset($current_page) ? (int)$current_page : 1;
$total_pages     = isset($total_pages) ? (int)$total_pages : 1;
$total_filtered  = isset($total_filtered) ? (int)$total_filtered : count($lines);
$offset          = isset($offset) ? (int)$offset : 0;
$can_write       = !empty($can_write);
$can_order       = !empty($can_order);
$can_view_orders = !empty($can_view_orders);

$csrfName = function_exists('get_instance') && isset(get_instance()->security) ? get_instance()->security->get_csrf_token_name() : 'csrf_token_wms';
$csrfHash = function_exists('get_instance') && isset(get_instance()->security) ? get_instance()->security->get_csrf_hash() : '';

$g = function ($key) use ($filter) {
    return isset($filter[$key]) ? (string)$filter[$key] : '';
};

$badgeCls = function ($st) {
    switch ($st) {
        case 'Allocated': return 'sp-badge-alloc';
        case 'Available': return 'sp-badge-ok';
        case 'Partial':   return 'sp-badge-warn';
        case 'Pending':   return 'sp-badge-crit';
        default:          return 'sp-badge-muted';
    }
};

$baseParams = array(
    'tab'       => $tab,
    'q'         => $q,
    'status'    => $g('status'),
    'priority'  => $g('priority'),
    'wo_status' => $g('wo_status'),
    'sort'      => $sort,
    'dir'       => $dir,
    'view'      => $view_mode,
    'per_page'  => $per_page,
    'page'      => $current_page,
);

$buildUrl = function ($overrides = array(), $endpoint = 'spareparts') use ($baseParams) {
    $merged = array_merge($baseParams, $overrides);
    $clean  = array();
    foreach ($merged as $k => $v) {
        if ($v === '' || $v === null) continue;
        if ($k === 'tab' && $v === 'all') continue;
        if ($k === 'sort' && $v === 'priority' && (!isset($merged['dir']) || $merged['dir'] === 'asc')) continue;
        if ($k === 'dir' && $v === 'asc' && (!isset($merged['sort']) || $merged['sort'] === 'priority')) continue;
        if ($k === 'view' && $v === 'table') continue;
        if ($k === 'per_page' && (int)$v === 25) continue;
        if ($k === 'page' && (int)$v === 1) continue;
        $clean[$k] = $v;
    }
    $qs = http_build_query($clean);
    return base_url($endpoint) . ($qs !== '' ? '?' . $qs : '');
};

$csvExportUrl  = $buildUrl(array('page' => null, 'per_page' => null), 'spareparts/export_csv');
$xlsxExportUrl = $buildUrl(array('page' => null, 'per_page' => null), 'spareparts/export_xlsx');

$sortTh = function ($col, $label, $cls = '') use ($sort, $dir, $buildUrl, $h) {
    $isCurr  = ($sort === $col);
    $nextDir = ($isCurr && $dir === 'asc') ? 'desc' : 'asc';
    $aria    = $isCurr ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $arrow   = $isCurr ? ($dir === 'asc' ? '↑' : '↓') : '↕';
    $url     = $buildUrl(array('sort' => $col, 'dir' => $nextDir, 'page' => 1));
    return '<th class="' . $h($cls) . '" aria-sort="' . $aria . '">'
        . '<a class="sp-sort-link" href="' . $h($url) . '">'
        . '<span>' . $h($label) . '</span>'
        . '<span class="sp-sort-arrow' . ($isCurr ? ' is-on' : '') . '">' . $arrow . '</span>'
        . '</a></th>';
};

$chips = array();
if ($q !== '')              $chips[] = array('Search: ' . $q, array('q' => '', 'page' => 1));
if ($g('status') !== '')    $chips[] = array('Availability: ' . $g('status'), array('status' => '', 'page' => 1));
if ($g('priority') !== '')  $chips[] = array('Priority: ' . $g('priority'), array('priority' => '', 'page' => 1));
if ($g('wo_status') !== '') $chips[] = array('WO State: ' . $g('wo_status'), array('wo_status' => '', 'page' => 1));
?>

<style>
  .sp-shell { display: flex; flex-direction: column; gap: 16px; max-width: 100%; }
  .sp-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 14px; flex-wrap: wrap;
  }
  .sp-title { font-size: 20px; font-weight: 700; color: #0F172A; letter-spacing: -0.02em; margin: 0; }
  .sp-subtitle { font-size: 12.5px; color: #64748B; margin-top: 3px; }
  .sp-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

  .sp-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px;
    font-size: 12.5px; font-weight: 600; border-radius: 8px; border: 1px solid #CBD5E1;
    background: #FFFFFF; color: #1E293B; text-decoration: none; cursor: pointer;
    transition: background .14s ease, border-color .14s ease; white-space: nowrap;
  }
  .sp-btn:hover { background: #F8FAFC; border-color: #94A3B8; color: #0F172A; text-decoration: none; }
  .sp-btn-primary { background: #1D4ED8; border-color: #1D4ED8; color: #FFFFFF; }
  .sp-btn-primary:hover { background: #1E40AF; border-color: #1E40AF; color: #FFFFFF; }
  .sp-btn-sm { padding: 4px 8px; font-size: 11.5px; border-radius: 6px; }

  /* 5-Card KPI Telemetry Strip */
  .sp-kpi-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; }
  .sp-kpi-card {
    background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px;
    padding: 13px 15px; display: flex; flex-direction: column; gap: 4px;
  }
  .sp-kpi-lbl { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #64748B; }
  .sp-kpi-val { font-size: 22px; font-weight: 700; color: #0F172A; font-variant-numeric: tabular-nums; line-height: 1.15; }
  .sp-kpi-meta { font-size: 11.5px; color: #64748B; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .sp-val-red   { color: #DC2626; }
  .sp-val-green { color: #15803D; }
  .sp-val-blue  { color: #1D4ED8; }
  .sp-val-cyan  { color: #0E7490; }
  .sp-val-amber { color: #B45309; }

  /* Ready Strip & Alerts Strip */
  .sp-strip {
    background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 11px 15px;
    display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
  }
  .sp-strip-ready { background: #F0FDF4; border-color: #BBF7D0; }

  /* Unified Surface */
  .sp-surface { background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px; overflow: hidden; max-width: 100%; }
  .sp-tabs-bar {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 10px 14px 0; border-bottom: 1px solid #E2E8F0; background: #F8FAFC; flex-wrap: wrap;
  }
  .sp-tabs { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
  .sp-tab {
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 11px;
    font-size: 12.5px; font-weight: 600; color: #475569; text-decoration: none;
    border-bottom: 2px solid transparent; margin-bottom: -1px;
  }
  .sp-tab:hover { color: #0F172A; text-decoration: none; }
  .sp-tab.is-active { color: #1D4ED8; border-bottom-color: #1D4ED8; background: #FFFFFF; border-radius: 8px 8px 0 0; }
  .sp-tab-cnt {
    font-size: 10.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px;
    background: #E2E8F0; color: #334155; font-variant-numeric: tabular-nums;
  }
  .sp-tab.is-active .sp-tab-cnt { background: #DBEAFE; color: #1D4ED8; }

  .sp-view-seg {
    display: inline-flex; border: 1px solid #CBD5E1; border-radius: 8px; overflow: hidden;
    background: #FFFFFF; margin-bottom: 8px;
  }
  .sp-view-btn {
    border: 0; background: transparent; padding: 5px 10px; font-size: 11.5px;
    font-weight: 600; color: #475569; cursor: pointer;
  }
  .sp-view-btn.is-active { background: #1E293B; color: #FFFFFF; }

  /* Filter Toolbar */
  .sp-filter-panel { padding: 12px 14px; border-bottom: 1px solid #E2E8F0; background: #FFFFFF; }
  .sp-filter-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .sp-search-wrap { position: relative; flex: 1 1 240px; min-width: 190px; }
  .sp-input, .sp-select {
    height: 35px; padding: 0 10px; font-size: 12.5px; border: 1px solid #CBD5E1;
    border-radius: 8px; background: #FFFFFF; color: #0F172A;
  }
  .sp-search-wrap .sp-input { width: 100%; padding-right: 28px; }
  .sp-kbd {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    font-size: 10.5px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    padding: 1px 5px; border-radius: 4px; border: 1px solid #CBD5E1; color: #64748B; background: #F8FAFC;
    pointer-events: none;
  }
  .sp-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 9px; }
  .sp-chip {
    display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px;
    font-size: 11.5px; font-weight: 600; background: #EFF6FF; color: #1D4ED8; text-decoration: none;
  }
  .sp-chip:hover { background: #DBEAFE; text-decoration: none; }

  /* Sticky Batch Bar */
  .sp-batch-bar {
    display: none; align-items: center; justify-content: space-between; gap: 10px;
    padding: 9px 14px; background: #0F172A; color: #FFFFFF; font-size: 12.5px;
  }
  .sp-batch-bar.is-open { display: flex; }

  /* Zero-Overflow Fixed Desktop DataTable */
  .sp-table-wrap { width: 100%; max-width: 100%; overflow-x: hidden; }
  .sp-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 12.5px; }
  .sp-table thead th {
    padding: 9px 9px; background: #F8FAFC; border-bottom: 1px solid #E2E8F0;
    text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .04em; color: #475569; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .sp-table thead th.num, .sp-table tbody td.num, .sp-table tfoot td.num {
    text-align: right; font-variant-numeric: tabular-nums;
  }
  .sp-table thead th.ctr, .sp-table tbody td.ctr { text-align: center; }
  .sp-sort-link { display: inline-flex; align-items: center; gap: 4px; color: inherit; text-decoration: none; max-width: 100%; }
  .sp-sort-link:hover { color: #0F172A; text-decoration: none; }
  .sp-sort-arrow { font-size: 10.5px; color: #94A3B8; }
  .sp-sort-arrow.is-on { color: #1D4ED8; font-weight: 700; }

  .sp-table tbody tr { border-bottom: 1px solid #F1F5F9; transition: background .1s ease; }
  .sp-table tbody tr:hover { background: #F8FAFC; }
  .sp-table tbody td {
    padding: 9px 9px; vertical-align: middle; color: #1E293B;
    overflow: hidden; text-overflow: ellipsis;
  }
  .sp-table tfoot td {
    padding: 10px 9px; background: #F8FAFC; border-top: 2px solid #E2E8F0;
    font-size: 12px; font-weight: 700; color: #0F172A;
  }

  .sp-cell-main { font-weight: 600; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .sp-cell-sub { font-size: 11px; color: #64748B; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
  .sp-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11.5px; }
  .sp-link-btn {
    border: 0; background: none; padding: 0; color: #1D4ED8; font-weight: 700;
    cursor: pointer; text-align: left; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .sp-link-btn:hover { text-decoration: underline; }

  .sp-badge {
    display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px;
    border-radius: 999px; font-size: 10.5px; font-weight: 700; white-space: nowrap;
  }
  .sp-badge-alloc { background: #DBEAFE; color: #1D4ED8; }
  .sp-badge-ok    { background: #DCFCE7; color: #166534; }
  .sp-badge-warn  { background: #FEF3C7; color: #92400E; }
  .sp-badge-crit  { background: #FEE2E2; color: #991B1B; }
  .sp-badge-muted { background: #F1F5F9; color: #475569; }

  /* Grouped-by-Part & Responsive Card Views */
  .sp-groups-container { display: none; padding: 14px; }
  .sp-cards-container { display: none; padding: 14px; }
  .sp-cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(295px, 1fr)); gap: 12px; }
  .sp-card {
    border: 1px solid #E2E8F0; border-radius: 12px; padding: 13px; background: #FFFFFF;
    display: flex; flex-direction: column; gap: 8px;
  }

  #spShell[data-view="groups"] .sp-table-wrap,
  #spShell[data-view="groups"] .sp-cards-container { display: none; }
  #spShell[data-view="groups"] .sp-groups-container { display: block; }

  #spShell[data-view="cards"] .sp-table-wrap,
  #spShell[data-view="cards"] .sp-groups-container { display: none; }
  #spShell[data-view="cards"] .sp-cards-container { display: block; }

  /* Pagination Footer */
  .sp-pager {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 11px 14px; border-top: 1px solid #E2E8F0; background: #FFFFFF; flex-wrap: wrap;
    font-size: 12.5px; color: #475569;
  }
  .sp-page-links { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
  .sp-page-link {
    padding: 5px 10px; border-radius: 7px; border: 1px solid #CBD5E1; background: #FFFFFF;
    color: #1E293B; font-size: 12px; font-weight: 600; text-decoration: none;
  }
  .sp-page-link:hover { background: #F1F5F9; text-decoration: none; }
  .sp-page-link.is-active { background: #1D4ED8; border-color: #1D4ED8; color: #FFFFFF; }
  .sp-page-link.is-disabled { opacity: .45; pointer-events: none; }

  /* Slide-Over Demand & Allocation Inspector Drawer */
  .sp-drawer { position: fixed; inset: 0; z-index: 1050; display: none; }
  .sp-drawer.is-open { display: block; }
  .sp-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, .45); }
  .sp-drawer-panel {
    position: fixed; top: 0; right: 0; bottom: 0; width: min(520px, 100vw);
    background: #FFFFFF; box-shadow: -12px 0 32px rgba(15, 23, 42, .18);
    display: flex; flex-direction: column; z-index: 1;
  }
  .sp-drawer-head {
    padding: 15px 18px; border-bottom: 1px solid #E2E8F0;
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
  }
  .sp-drawer-body { padding: 16px 18px; overflow-y: auto; flex: 1; font-size: 13px; color: #1E293B; }
  .sp-drawer-foot {
    padding: 12px 18px; border-top: 1px solid #E2E8F0; background: #F8FAFC;
    display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
  }
  .sp-kv-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
  .sp-kv-box { background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 9px; padding: 9px 11px; }
  .sp-kv-lbl { font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: #64748B; }
  .sp-kv-val { font-size: 13px; font-weight: 600; color: #0F172A; margin-top: 2px; word-break: break-word; }

  @media (max-width: 1099px) {
    .sp-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 768px) {
    .sp-kpi-grid { grid-template-columns: 1fr; }
    .sp-table-wrap, .sp-groups-container { display: none !important; }
    .sp-cards-container { display: block !important; }
    .sp-view-seg { display: none; }
  }
  @media print {
    .sidebar, .topbar, .sp-actions, .sp-tabs-bar, .sp-filter-panel,
    .sp-batch-bar, .sp-pager, .sp-drawer, .sp-col-chk, .sp-col-act { display: none !important; }
    .sp-surface, .sp-kpi-card { border: 1px solid #CBD5E1; box-shadow: none; }
    .sp-table-wrap { display: block !important; overflow: visible !important; }
  }
</style>

<div class="sp-shell" id="spShell" data-view="<?= $h($view_mode) ?>">

  <!-- Page Header & RBAC Action Bar -->
  <div class="sp-head">
    <div>
      <h1 class="sp-title">Pending Spare Parts &amp; Demand Board</h1>
      <div class="sp-subtitle">
        Live work-order spare part demand, stock reservations, direct workshop hand-out &amp; inbound PO coverage
      </div>
    </div>
    <div class="sp-actions">
      <a class="sp-btn" href="<?= $h(base_url('spareparts/alerts')) ?>">
        🔔 Alerts<?php if ((int)$summary['new_alerts'] > 0): ?> <strong>(<?= (int)$summary['new_alerts'] ?>)</strong><?php endif; ?>
      </a>
      <a class="sp-btn" href="<?= $h($csvExportUrl) ?>">↓ Export CSV</a>
      <a class="sp-btn" href="<?= $h($xlsxExportUrl) ?>">↓ Export Excel</a>
      <?php if ($can_order): ?>
        <a class="sp-btn sp-btn-primary" id="spOrderBtn" href="<?= $h(base_url('orders/create')) ?>">+ Order Missing Part</a>
      <?php elseif ($can_view_orders): ?>
        <a class="sp-btn" href="<?= $h(base_url('orders')) ?>">View Orders</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- 5-Card KPI Telemetry Strip -->
  <div class="sp-kpi-grid">
    <div class="sp-kpi-card">
      <div class="sp-kpi-lbl">Waiting Part Lines</div>
      <div class="sp-kpi-val <?= (int)$summary['pending_lines'] > 0 ? 'sp-val-red' : '' ?>"><?= number_format((int)$summary['lines']) ?></div>
      <div class="sp-kpi-meta">Across <?= number_format((int)$summary['wos']) ?> active work order(s)</div>
    </div>
    <div class="sp-kpi-card">
      <div class="sp-kpi-lbl">Fully Covered Lines</div>
      <div class="sp-kpi-val sp-val-green"><?= number_format((int)$summary['available_lines']) ?></div>
      <div class="sp-kpi-meta"><?= number_format((int)$summary['allocated_lines']) ?> reserved in store</div>
    </div>
    <div class="sp-kpi-card">
      <div class="sp-kpi-lbl">Ready Work Orders</div>
      <div class="sp-kpi-val sp-val-blue"><?= number_format((int)$summary['ready_wos']) ?></div>
      <div class="sp-kpi-meta">100% of requested spares ready</div>
    </div>
    <div class="sp-kpi-card">
      <div class="sp-kpi-lbl">Inbound on PO</div>
      <div class="sp-kpi-val sp-val-cyan"><?= number_format((int)$summary['on_order_parts']) ?></div>
      <div class="sp-kpi-meta">Catalog part(s) with open supplier PO</div>
    </div>
    <div class="sp-kpi-card">
      <div class="sp-kpi-lbl">Unread Arrival Alerts</div>
      <div class="sp-kpi-val <?= (int)$summary['new_alerts'] > 0 ? 'sp-val-amber' : '' ?>"><?= number_format((int)$summary['new_alerts']) ?></div>
      <div class="sp-kpi-meta">Role-scoped receipt notifications</div>
    </div>
  </div>

  <!-- Ready-to-Proceed Work Orders Strip -->
  <?php if (!empty($ready_wos)): ?>
    <div class="sp-strip sp-strip-ready">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="font-size:12.5px;font-weight:700;color:#15803D;">✓ Ready to Proceed Now:</span>
        <?php foreach ($ready_wos as $rw): ?>
          <a class="sp-badge sp-badge-ok" style="text-decoration:none;font-size:11.5px;padding:4px 9px;"
             href="<?= $h(base_url('workorders/view/' . (int)$rw['work_order_id'])) ?>">
            <?= $h($rw['wo_number']) ?> · <?= $h($rw['registration_no'] ?: 'Fleet') ?> · <?= (int)$rw['parts'] ?> part(s)
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Unread Arrival Alerts Banner -->
  <?php if (!empty($new_alerts)): ?>
    <div class="sp-strip" style="border-left:4px solid #16A34A;">
      <div style="flex:1;min-width:220px;">
        <div style="font-size:12.5px;font-weight:700;color:#0F172A;margin-bottom:4px;">
          🔔 Recent Spare Part Arrival Alerts
          <a href="<?= $h(base_url('spareparts/alerts')) ?>" style="font-weight:600;color:#1D4ED8;margin-left:8px;">All alerts →</a>
        </div>
        <?php foreach ($new_alerts as $al): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:4px 0;font-size:12px;">
            <span>
              <strong><?= $h($al->alert_type === 'Partial' ? 'Partial:' : 'Arrived:') ?></strong>
              <?= $h($al->message) ?>
            </span>
            <?php if ($al->status === 'New'): ?>
              <?= form_open('spareparts/mark_read/' . (int)$al->id, array('style' => 'display:inline;margin:0;')) ?>
                <button type="submit" class="sp-btn sp-btn-sm">Mark read</button>
              <?= form_close() ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ((int)$summary['new_alerts'] > 0): ?>
        <?= form_open('spareparts/mark_all_read', array('style' => 'margin:0;')) ?>
          <button type="submit" class="sp-btn sp-btn-sm">Mark all read</button>
        <?= form_close() ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Unified Register Surface -->
  <div class="sp-surface">

    <!-- 6 Governance Tabs + Table / By Part / Cards Switcher -->
    <div class="sp-tabs-bar">
      <nav class="sp-tabs" role="tablist" aria-label="Spare parts demand views">
        <?php
        $tabsDef = array(
            'all'       => array('All Demand',      isset($tab_counts['all']) ? (int)$tab_counts['all'] : 0),
            'pending'   => array('Pending Stock',   isset($tab_counts['pending']) ? (int)$tab_counts['pending'] : 0),
            'available' => array('Coverable Now',   isset($tab_counts['available']) ? (int)$tab_counts['available'] : 0),
            'allocated' => array('Reserved',        isset($tab_counts['allocated']) ? (int)$tab_counts['allocated'] : 0),
            'unlinked'  => array('Unlinked',        isset($tab_counts['unlinked']) ? (int)$tab_counts['unlinked'] : 0),
            'ready_wos' => array('Ready WOs',       isset($tab_counts['ready_wos']) ? (int)$tab_counts['ready_wos'] : 0),
        );
        foreach ($tabsDef as $tk => $meta):
            list($tLabel, $tCnt) = $meta;
        ?>
          <a class="sp-tab <?= $tab === $tk ? 'is-active' : '' ?>"
             href="<?= $h($buildUrl(array('tab' => $tk, 'page' => 1))) ?>">
            <span><?= $h($tLabel) ?></span>
            <span class="sp-tab-cnt"><?= number_format($tCnt) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="sp-view-seg" role="group" aria-label="Layout view">
        <button type="button" class="sp-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>" data-sp-view="table">Table</button>
        <button type="button" class="sp-view-btn <?= $view_mode === 'groups' ? 'is-active' : '' ?>" data-sp-view="groups">By Part</button>
        <button type="button" class="sp-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>" data-sp-view="cards">Cards</button>
      </div>
    </div>

    <!-- Search & Facet Filter Toolbar -->
    <div class="sp-filter-panel">
      <form method="get" action="<?= $h(base_url('spareparts')) ?>" class="sp-filter-form">
        <input type="hidden" name="tab" value="<?= $h($tab) ?>">
        <input type="hidden" name="view" value="<?= $h($view_mode) ?>" id="spViewHiddenInput">

        <div class="sp-search-wrap">
          <input type="search" name="q" id="spSearchInput" class="sp-input"
                 value="<?= $h($q) ?>"
                 placeholder="Search WO, fleet asset (EX 006), item code, part no, description…">
          <span class="sp-kbd">/</span>
        </div>

        <select name="status" class="sp-select" aria-label="Availability Status" onchange="this.form.submit()">
          <option value="">All Availability</option>
          <?php foreach ($statuses as $st): ?>
            <option value="<?= $h($st) ?>" <?= strcasecmp($g('status'), (string)$st) === 0 ? 'selected' : '' ?>><?= $h($st) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="priority" class="sp-select" aria-label="Work Order Priority" onchange="this.form.submit()">
          <option value="">All Priorities</option>
          <?php foreach ($priorities as $pr): ?>
            <option value="<?= $h($pr) ?>" <?= strcasecmp($g('priority'), (string)$pr) === 0 ? 'selected' : '' ?>><?= $h($pr) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="wo_status" class="sp-select" aria-label="Work Order State" onchange="this.form.submit()">
          <option value="">All WO States</option>
          <?php foreach ($wo_statuses as $ws): ?>
            <option value="<?= $h($ws) ?>" <?= strcasecmp($g('wo_status'), (string)$ws) === 0 ? 'selected' : '' ?>><?= $h($ws) ?></option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="sp-btn sp-btn-primary">Filter</button>
        <?php if (!empty($chips) || $tab !== 'all'): ?>
          <a class="sp-btn" href="<?= $h(base_url('spareparts')) ?>">Clear all</a>
        <?php endif; ?>
      </form>

      <?php if (!empty($chips)): ?>
        <div class="sp-chips">
          <?php foreach ($chips as $ch): ?>
            <a class="sp-chip" href="<?= $h($buildUrl($ch[1])) ?>">
              <span><?= $h($ch[0]) ?></span>
              <span aria-hidden="true">×</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Sticky Multi-Select Batch Bar -->
    <div class="sp-batch-bar" id="spBatchBar" role="region" aria-label="Selected spare part lines">
      <div><strong id="spSelectedCount">0</strong> demand line(s) selected</div>
      <div style="display:flex;align-items:center;gap:8px;">
        <a class="sp-btn sp-btn-primary sp-btn-sm" id="spBatchExportBtn" href="<?= $h($csvExportUrl) ?>">↓ Export Selected CSV</a>
        <a class="sp-btn sp-btn-sm" id="spBatchXlsxBtn" href="<?= $h($xlsxExportUrl) ?>">↓ Export Selected Excel</a>
        <button type="button" class="sp-btn sp-btn-sm" id="spBatchClearBtn">Clear</button>
      </div>
    </div>

    <?php if (empty($lines)): ?>
      <div style="padding:56px 24px;text-align:center;color:#64748B;">
        <div style="font-size:15px;font-weight:700;color:#0F172A;margin-bottom:6px;">
          <?= ($q !== '' || !empty($chips) || $tab !== 'all')
                ? 'No pending spare part lines match this filter'
                : 'No pending spare part requests across active work orders' ?>
        </div>
        <div style="font-size:12.5px;margin-bottom:14px;">
          Every open work order currently has its required catalog parts satisfied, or clear filters above to inspect all demand.
        </div>
        <a class="sp-btn" href="<?= $h(base_url('spareparts')) ?>">Reset Filters</a>
      </div>
    <?php else: ?>

      <!-- 1. Unified Desktop 1440px Zero-Overflow 9-Column DataTable -->
      <div class="sp-table-wrap">
        <table class="sp-table">
          <colgroup>
            <col style="width:36px;">
            <col style="width:12%;">
            <col style="width:12%;">
            <col style="width:20%;">
            <col style="width:10%;">
            <col style="width:11%;">
            <col style="width:10%;">
            <col style="width:11%;">
            <col style="width:auto;">
          </colgroup>
          <thead>
            <tr>
              <th class="sp-col-chk ctr">
                <input type="checkbox" id="spCheckAll" aria-label="Select all visible demand lines">
              </th>
              <?= $sortTh('wo_number', 'Work Order') ?>
              <?= $sortTh('equipment', 'Fleet Asset') ?>
              <?= $sortTh('part_name', 'Spare Part & Code') ?>
              <?= $sortTh('qty_required', 'Required', 'num') ?>
              <?= $sortTh('qty_available', 'Stock / Free', 'num') ?>
              <th class="num">Inbound PO</th>
              <?= $sortTh('status', 'Status') ?>
              <th class="num sp-col-act">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lines as $l):
                $lid    = (int)$l->id;
                $needed = max(0.0, round($l->qty_required - $l->qty_allocated - $l->qty_issued, 2));
                $allocs = isset($line_allocs[$lid]) ? $line_allocs[$lid] : array();
                $firstActiveAlloc = null;
                foreach ($allocs as $ar) {
                    if ($ar->status === 'Allocated') { $firstActiveAlloc = $ar; break; }
                }
                $mCode = $l->material ? $l->material->item_code : ($l->item_code ?: 'Unlinked');
            ?>
              <tr data-sp-id="<?= $lid ?>">
                <td class="sp-col-chk ctr">
                  <input type="checkbox" class="sp-row-check" value="<?= $lid ?>"
                         aria-label="Select demand line <?= $h($l->wo_number . ' ' . $l->part_name) ?>">
                </td>
                <td>
                  <a class="sp-mono" style="font-weight:700;color:#1D4ED8;" href="<?= $h(base_url('workorders/view/' . (int)$l->work_order_id)) ?>">
                    <?= $h($l->wo_number) ?>
                  </a>
                  <div class="sp-cell-sub"><?= $h($l->priority) ?> · <?= $h($l->wo_status) ?></div>
                </td>
                <td>
                  <div class="sp-cell-main"><?= $h($l->registration_no ?: '—') ?></div>
                  <div class="sp-cell-sub"><?= $h(trim((string)(($l->brand ?? '') . ' ' . ($l->model ?? ''))) ?: 'Fleet Asset') ?></div>
                </td>
                <td>
                  <button type="button" class="sp-link-btn" data-sp-open="<?= $lid ?>" title="<?= $h($l->part_name) ?>">
                    <?= $h($l->part_name) ?>
                  </button>
                  <div class="sp-cell-sub sp-mono">
                    <?= $h($mCode) ?><?= !empty($l->part_number) ? ' · P/N ' . $h($l->part_number) : '' ?>
                  </div>
                </td>
                <td class="num">
                  <div class="sp-cell-main sp-mono"><?= $h($fmtQty($l->qty_required)) ?> <?= $h($l->item_unit) ?></div>
                  <?php if ($l->qty_shortfall > 0): ?>
                    <div class="sp-cell-sub" style="color:#DC2626;font-weight:600;">Short: <?= $h($fmtQty($l->qty_shortfall)) ?></div>
                  <?php else: ?>
                    <div class="sp-cell-sub" style="color:#15803D;">Covered</div>
                  <?php endif; ?>
                </td>
                <td class="num">
                  <div class="sp-cell-main sp-mono"><?= $h($fmtQty($l->stock_qty)) ?> on hand</div>
                  <div class="sp-cell-sub sp-mono">Free: <?= $h($fmtQty($l->free_qty)) ?></div>
                </td>
                <td class="num sp-mono">
                  <?php if ($l->on_order_qty > 0): ?>
                    <span style="color:#0E7490;font-weight:700;">+<?= $h($fmtQty($l->on_order_qty)) ?></span>
                  <?php else: ?>
                    <span style="color:#94A3B8;">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="sp-badge <?= $badgeCls($l->avail_status) ?>"><?= $h($l->avail_status) ?></span>
                  <?php if ($l->qty_allocated > 0): ?>
                    <div class="sp-cell-sub">Res: <?= $h($fmtQty($l->qty_allocated)) ?></div>
                  <?php endif; ?>
                </td>
                <td class="num sp-col-act">
                  <div style="display:inline-flex;align-items:center;gap:4px;justify-content:flex-end;flex-wrap:wrap;">
                    <?php if ($can_write && $needed > 0 && $l->free_qty > 0): ?>
                      <?= form_open('spareparts/allocate/' . $lid, array('style' => 'display:inline;margin:0;')) ?>
                        <input type="hidden" name="qty" value="<?= $h($fmtQty(min($needed, $l->free_qty))) ?>">
                        <button type="submit" class="sp-btn sp-btn-primary sp-btn-sm">Allocate</button>
                      <?= form_close() ?>
                    <?php elseif ($can_write && $firstActiveAlloc): ?>
                      <?= form_open('spareparts/issue/' . (int)$firstActiveAlloc->id, array('style' => 'display:inline;margin:0;')) ?>
                        <button type="submit" class="sp-btn sp-btn-primary sp-btn-sm">Issue</button>
                      <?= form_close() ?>
                    <?php endif; ?>
                    <button type="button" class="sp-btn sp-btn-sm" data-sp-open="<?= $lid ?>">Quick</button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td class="sp-col-chk"></td>
              <td colspan="3">Filtered Totals (<?= number_format($total_filtered) ?> demand line(s))</td>
              <td class="num sp-mono"><?= $h($fmtQty($filtered_totals['qty_required'])) ?> req</td>
              <td class="num sp-mono"><?= $h($fmtQty($filtered_totals['qty_allocated'])) ?> res</td>
              <td class="num sp-mono" colspan="3" style="color:<?= $filtered_totals['qty_shortfall'] > 0 ? '#DC2626' : '#15803D' ?>;">
                Net Shortfall: <?= $h($fmtQty($filtered_totals['qty_shortfall'])) ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- 2. Preserved Grouped-by-Part Store View -->
      <div class="sp-groups-container">
        <?php foreach ($groups as $gid => $grp):
            $m = $grp['material'];
            $oo = isset($on_order[$gid]) ? (float)$on_order[$gid] : 0.0;
        ?>
          <div style="border:1px solid #E2E8F0;border-radius:12px;margin-bottom:12px;overflow:hidden;">
            <div style="padding:11px 14px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;">
              <div>
                <span class="sp-mono" style="font-weight:700;color:#1D4ED8;"><?= $h($m->item_code) ?></span>
                <strong style="margin-left:6px;color:#0F172A;"><?= $h($m->description) ?></strong>
              </div>
              <div style="font-size:12px;color:#475569;">
                Stock: <strong><?= $h($fmtQty($grp['stock'])) ?></strong> · Free: <strong><?= $h($fmtQty($grp['free'])) ?></strong>
                · Open Demand: <strong><?= $h($fmtQty($grp['demand'])) ?></strong>
                <?php if ($oo > 0): ?> · Inbound PO: <strong style="color:#0E7490;">+<?= $h($fmtQty($oo)) ?></strong><?php endif; ?>
              </div>
            </div>
            <?php foreach ($grp['lines'] as $gl): ?>
              <div style="padding:9px 14px;display:flex;justify-content:space-between;align-items:center;gap:10px;border-top:1px solid #F1F5F9;">
                <div>
                  <a class="sp-mono" style="font-weight:700;color:#1D4ED8;" href="<?= $h(base_url('workorders/view/' . (int)$gl->work_order_id)) ?>"><?= $h($gl->wo_number) ?></a>
                  · <strong><?= $h($gl->registration_no ?: '—') ?></strong>
                  · Req: <span class="sp-mono"><?= $h($fmtQty($gl->qty_required)) ?> <?= $h($gl->item_unit) ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                  <span class="sp-badge <?= $badgeCls($gl->avail_status) ?>"><?= $h($gl->avail_status) ?></span>
                  <button type="button" class="sp-btn sp-btn-sm" data-sp-open="<?= (int)$gl->id ?>">Inspect</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!empty($unlinked)): ?>
          <div style="border:1px solid #FDE68A;border-radius:12px;padding:12px 14px;background:#FFFBEB;">
            <strong style="font-size:12.5px;color:#92400E;">Unlinked Work-Order Part Lines (<?= count($unlinked) ?>)</strong>
            <?php foreach ($unlinked as $ul): ?>
              <div style="padding-top:6px;font-size:12px;display:flex;justify-content:space-between;">
                <span><?= $h($ul->wo_number) ?> · <?= $h($ul->registration_no ?: '—') ?> · <?= $h($ul->part_name) ?></span>
                <button type="button" class="sp-btn sp-btn-sm" data-sp-open="<?= (int)$ul->id ?>">Inspect</button>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- 3. Responsive Mobile & Optional Desktop Card View -->
      <div class="sp-cards-container">
        <div class="sp-cards-grid">
          <?php foreach ($lines as $l):
              $lid = (int)$l->id;
          ?>
            <div class="sp-card">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                <a class="sp-mono" style="font-weight:700;color:#1D4ED8;" href="<?= $h(base_url('workorders/view/' . (int)$l->work_order_id)) ?>">
                  <?= $h($l->wo_number) ?>
                </a>
                <span class="sp-badge <?= $badgeCls($l->avail_status) ?>"><?= $h($l->avail_status) ?></span>
              </div>
              <div>
                <div style="font-weight:600;color:#0F172A;"><?= $h($l->part_name) ?></div>
                <div style="font-size:11.5px;color:#64748B;">
                  <?= $h($l->registration_no ?: '—') ?> · <?= $h($l->priority) ?>
                </div>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:12px;color:#334155;">
                <span>Req: <strong><?= $h($fmtQty($l->qty_required)) ?> <?= $h($l->item_unit) ?></strong></span>
                <span>Stock: <strong><?= $h($fmtQty($l->stock_qty)) ?></strong> (Free <?= $h($fmtQty($l->free_qty)) ?>)</span>
              </div>
              <div style="display:flex;justify-content:flex-end;gap:8px;padding-top:4px;border-top:1px solid #F1F5F9;">
                <button type="button" class="sp-btn sp-btn-primary sp-btn-sm" data-sp-open="<?= $lid ?>">Manage / Inspect</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php endif; ?>

    <!-- Pagination Footer -->
    <div class="sp-pager">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span>
          Showing <strong><?= $total_filtered > 0 ? number_format($offset + 1) : '0' ?></strong>–<strong><?= number_format(min($offset + count($lines), $total_filtered)) ?></strong>
          of <strong><?= number_format($total_filtered) ?></strong> line(s)
        </span>
        <form method="get" action="<?= $h(base_url('spareparts')) ?>" style="display:inline-flex;align-items:center;gap:6px;">
          <?php foreach ($baseParams as $pk => $pv):
              if ($pk === 'per_page' || $pk === 'page' || $pv === '' || $pv === null) continue;
          ?>
            <input type="hidden" name="<?= $h($pk) ?>" value="<?= $h($pv) ?>">
          <?php endforeach; ?>
          <label for="spPerPage" style="font-size:12px;color:#64748B;">Rows:</label>
          <select name="per_page" id="spPerPage" class="sp-select" style="height:30px;" onchange="this.form.submit()">
            <?php foreach ($allowed_pp as $pp): ?>
              <option value="<?= (int)$pp ?>" <?= (int)$per_page === (int)$pp ? 'selected' : '' ?>><?= (int)$pp ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <div class="sp-page-links">
        <a class="sp-page-link <?= $current_page <= 1 ? 'is-disabled' : '' ?>"
           href="<?= $h($buildUrl(array('page' => max(1, $current_page - 1)))) ?>">← Prev</a>
        <?php
        $startP = max(1, $current_page - 2);
        $endP   = min($total_pages, $current_page + 2);
        for ($p = $startP; $p <= $endP; $p++):
        ?>
          <a class="sp-page-link <?= $p === $current_page ? 'is-active' : '' ?>"
             href="<?= $h($buildUrl(array('page' => $p))) ?>"><?= (int)$p ?></a>
        <?php endfor; ?>
        <a class="sp-page-link <?= $current_page >= $total_pages ? 'is-disabled' : '' ?>"
           href="<?= $h($buildUrl(array('page' => min($total_pages, $current_page + 1)))) ?>">Next →</a>
      </div>
    </div>

  </div>
</div>

<!-- Accessible Slide-Over Demand & Allocation Inspector Drawer -->
<div class="sp-drawer" id="spDrawer" role="dialog" aria-modal="true" aria-labelledby="spDrawerTitle">
  <div class="sp-backdrop" data-sp-close="1"></div>
  <div class="sp-drawer-panel">
    <div class="sp-drawer-head">
      <div>
        <div id="spDrawerTitle" style="font-size:16px;font-weight:700;color:#0F172A;">Spare Part Demand</div>
        <div style="font-size:11.5px;color:#64748B;">Use ← / → keys to step across lines · Esc to close</div>
      </div>
      <button type="button" class="sp-btn sp-btn-sm" data-sp-close="1">✕</button>
    </div>
    <div class="sp-drawer-body" id="spDrawerBody"></div>
    <div class="sp-drawer-foot" id="spDrawerFoot"></div>
  </div>
</div>

<script>
(function () {
  var shell       = document.getElementById('spShell');
  var summaryBase = <?= json_encode(rtrim(base_url('spareparts/summary_json'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var allocBase   = <?= json_encode(rtrim(base_url('spareparts/allocate'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var issueBase   = <?= json_encode(rtrim(base_url('spareparts/issue'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var releaseBase = <?= json_encode(rtrim(base_url('spareparts/release'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var woBase      = <?= json_encode(rtrim(base_url('workorders/view'), '/') . '/', JSON_UNESCAPED_SLASHES) ?>;
  var orderCreate = <?= json_encode(base_url('orders/create'), JSON_UNESCAPED_SLASHES) ?>;
  var baseExport  = <?= json_encode($csvExportUrl, JSON_UNESCAPED_SLASHES) ?>;
  var baseXlsx    = <?= json_encode($xlsxExportUrl, JSON_UNESCAPED_SLASHES) ?>;
  var csrfName    = <?= json_encode($csrfName) ?>;
  var csrfHash    = <?= json_encode($csrfHash) ?>;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // Table <-> Groups <-> Cards switcher
  var viewBtns  = document.querySelectorAll('[data-sp-view]');
  var viewInput = document.getElementById('spViewHiddenInput');
  viewBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.getAttribute('data-sp-view');
      if (mode !== 'groups' && mode !== 'cards') mode = 'table';
      shell.setAttribute('data-view', mode);
      viewBtns.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      if (viewInput) viewInput.value = mode;
    });
  });

  // Multi-select checkboxes & sticky CSV/Excel batch bar
  var checkAll  = document.getElementById('spCheckAll');
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.sp-row-check'));
  var batchBar  = document.getElementById('spBatchBar');
  var countEl   = document.getElementById('spSelectedCount');
  var exportBtn = document.getElementById('spBatchExportBtn');
  var xlsxBtn   = document.getElementById('spBatchXlsxBtn');
  var clearBtn  = document.getElementById('spBatchClearBtn');

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

  // Slide-Over Demand & Allocation Inspector Drawer
  var drawer     = document.getElementById('spDrawer');
  var drawerBody = document.getElementById('spDrawerBody');
  var drawerFoot = document.getElementById('spDrawerFoot');
  var drawerTit  = document.getElementById('spDrawerTitle');
  var orderedIds = Array.prototype.slice.call(document.querySelectorAll('tr[data-sp-id]')).map(function (tr) {
    return parseInt(tr.getAttribute('data-sp-id'), 10);
  });
  var activeId   = 0;
  var lastOpener = null;

  function csrfHidden() {
    return '<input type="hidden" name="' + esc(csrfName) + '" value="' + esc(csrfHash) + '">';
  }

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
    drawerTit.textContent = 'Demand Line #' + activeId;
    drawerBody.innerHTML = '<div style="padding:24px;text-align:center;color:#64748B;">Loading part availability &amp; allocations…</div>';
    drawerFoot.innerHTML = '';

    fetch(summaryBase + activeId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok || !res.line) {
          drawerBody.innerHTML = '<div style="padding:18px;color:#B91C1C;">Could not load spare part demand details.</div>';
          return;
        }
        var l = res.line;
        var allocs = res.allocations || [];
        drawerTit.textContent = l.wo_number + ' · ' + l.part_name;

        var html = '<div style="margin-bottom:14px;">'
          + '<span class="sp-badge sp-badge-alloc">' + esc(l.avail_status) + '</span> '
          + '<span class="sp-badge sp-badge-muted">' + esc(l.priority) + ' · ' + esc(l.wo_status) + '</span>'
          + '</div>';

        html += '<div class="sp-kv-grid">'
          + '<div class="sp-kv-box"><div class="sp-kv-lbl">Work Order &amp; Fleet Asset</div><div class="sp-kv-val"><a href="' + esc(woBase + l.work_order_id) + '">' + esc(l.wo_number) + '</a> · ' + esc(l.registration_no) + '</div></div>'
          + '<div class="sp-kv-box"><div class="sp-kv-lbl">Catalog Item &amp; Bin</div><div class="sp-kv-val">' + esc(l.item_code) + ' · Bin ' + esc(l.bin_location) + '</div></div>'
          + '<div class="sp-kv-box"><div class="sp-kv-lbl">Required / Reserved / Issued</div><div class="sp-kv-val">' + esc(l.qty_required) + ' / ' + esc(l.qty_allocated) + ' / ' + esc(l.qty_issued) + ' ' + esc(l.unit) + '</div></div>'
          + '<div class="sp-kv-box"><div class="sp-kv-lbl">Stock On Hand / Free / On PO</div><div class="sp-kv-val">' + esc(l.stock_qty) + ' / ' + esc(l.free_qty) + ' / +' + esc(l.on_order_qty) + '</div></div>'
          + '</div>';

        if (l.can_write && l.max_alloc_qty > 0) {
          html += '<div style="padding:12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;margin-bottom:14px;">'
            + '<div style="font-size:12px;font-weight:700;color:#1E3A8A;margin-bottom:6px;">Reserve Free Stock for ' + esc(l.wo_number) + '</div>'
            + '<form method="post" action="' + esc(allocBase + l.id) + '" style="display:flex;gap:8px;align-items:center;">'
            + csrfHidden()
            + '<input type="number" name="qty" class="sp-input" style="width:110px;" min="0.01" max="' + esc(l.max_alloc_qty) + '" step="0.01" value="' + esc(l.max_alloc_qty) + '">'
            + '<button type="submit" class="sp-btn sp-btn-primary sp-btn-sm">Allocate Stock</button>'
            + '</form></div>';
        }

        html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:10px 0 6px;">Stock Reservations (' + allocs.length + ')</div>';
        if (!allocs.length) {
          html += '<div style="padding:12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:9px;color:#64748B;">No active or issued reservations recorded yet.</div>';
        } else {
          html += '<div style="border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;">';
          allocs.forEach(function (a, idx) {
            html += '<div style="padding:9px 12px;display:flex;justify-content:space-between;align-items:center;gap:10px;' + (idx > 0 ? 'border-top:1px solid #E2E8F0;' : '') + '">'
              + '<div><strong>' + esc(a.qty_allocated) + ' ' + esc(l.unit) + '</strong> · ' + esc(a.status) + '<div style="font-size:11px;color:#64748B;">' + esc(a.allocated_at) + '</div></div>';
            if (l.can_write && a.status === 'Allocated') {
              html += '<div style="display:flex;gap:6px;">'
                + '<form method="post" action="' + esc(issueBase + a.id) + '" style="margin:0;">' + csrfHidden() + '<button type="submit" class="sp-btn sp-btn-primary sp-btn-sm">Issue</button></form>'
                + '<form method="post" action="' + esc(releaseBase + a.id) + '" style="margin:0;">' + csrfHidden() + '<button type="submit" class="sp-btn sp-btn-sm">Release</button></form>'
                + '</div>';
            }
            html += '</div>';
          });
          html += '</div>';
        }

        drawerBody.innerHTML = html;

        var foot = '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
          + '<a class="sp-btn sp-btn-primary sp-btn-sm" href="' + esc(woBase + l.work_order_id) + '">Open Work Order</a>'
          + (l.can_order && l.qty_shortfall > 0 ? '<a class="sp-btn sp-btn-sm" href="' + esc(orderCreate) + '">+ Order Part</a>' : '')
          + '</div>'
          + '<button type="button" class="sp-btn sp-btn-sm" id="spDrawerDoneBtn">Close</button>';
        drawerFoot.innerHTML = foot;
        var doneBtn = document.getElementById('spDrawerDoneBtn');
        if (doneBtn) doneBtn.addEventListener('click', closeDrawer);
      })
      .catch(function () {
        drawerBody.innerHTML = '<div style="padding:18px;color:#B91C1C;">Network error while loading demand details.</div>';
      });
  }

  document.addEventListener('click', function (ev) {
    var openBtn = ev.target.closest('[data-sp-open]');
    if (openBtn) {
      ev.preventDefault();
      openDrawer(openBtn.getAttribute('data-sp-open'), openBtn);
      return;
    }
    if (ev.target.closest('[data-sp-close]')) {
      closeDrawer();
    }
  });

  document.addEventListener('keydown', function (ev) {
    var tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
    var inEditable = (tag === 'input' || tag === 'textarea' || tag === 'select' || (ev.target && ev.target.isContentEditable));

    if (ev.key === '/' && !inEditable && !ev.ctrlKey && !ev.metaKey && !ev.altKey) {
      var sInput = document.getElementById('spSearchInput');
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
