<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$sup_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$f          = isset($filter) && is_array($filter) ? $filter : array();
$tab        = isset($tab) ? (string)$tab : 'all';
$q          = isset($q) ? (string)$q : (string)($f['search'] ?? '');
$sort       = isset($sort) ? (string)$sort : 'company_name';
$dir        = isset($dir) ? (string)$dir : 'asc';
$view_mode  = isset($view_mode) ? (string)$view_mode : 'table';
$per_page   = isset($per_page) ? (int)$per_page : 25;
$allowed_pp = isset($allowed_pp) && is_array($allowed_pp) ? $allowed_pp : array(10, 25, 50, 100, 150);
$cur_page   = isset($current_page) ? (int)$current_page : 1;
$tot_pages  = isset($total_pages) ? (int)$total_pages : 1;
$tot_filt   = isset($total_filtered) ? (int)$total_filtered : count($suppliers ?? array());
$offset_val = isset($offset) ? (int)$offset : 0;
$can_write  = !empty($can_write);
$groups     = isset($groups) && is_array($groups) ? $groups : array();
$countries  = isset($countries) && is_array($countries) ? $countries : array();
$currencies = isset($currencies) && is_array($currencies) ? $currencies : array();
$tab_counts = isset($tab_counts) && is_array($tab_counts) ? $tab_counts : array(
    'all' => count($suppliers ?? array()), 'active' => 0, 'with_catalog' => 0, 'with_orders' => 0, 'flagged' => 0,
);

$base_params = array();
if ($tab !== 'all') $base_params['tab'] = $tab;
if ($q !== '') $base_params['q'] = $q;
if (!empty($f['country'])) $base_params['country'] = $f['country'];
if (!empty($f['currency'])) $base_params['currency'] = $f['currency'];
if (!empty($f['status'])) $base_params['status'] = $f['status'];
if ($sort !== 'company_name') $base_params['sort'] = $sort;
if ($dir !== 'asc') $base_params['dir'] = $dir;
if ($view_mode !== 'table') $base_params['view'] = $view_mode;
if ($per_page !== 25) $base_params['per_page'] = $per_page;

$sup_url = function ($path = 'suppliers', array $override = array(), array $drop = array()) use ($base_params) {
    $p = array_merge($base_params, $override);
    foreach ($drop as $k) unset($p[$k]);
    foreach ($p as $k => $v) {
        if ($v === '' || $v === NULL) unset($p[$k]);
    }
    $qs = http_build_query($p);
    return base_url($path) . ($qs !== '' ? '?' . $qs : '');
};

$sup_sort_url = function ($col) use ($sort, $dir, $sup_url) {
    $nextDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return $sup_url('suppliers', array('sort' => $col, 'dir' => $nextDir, 'page' => 1));
};

$sup_sort_icon = function ($col) use ($sort, $dir) {
    if ($sort !== $col) return '<span class="sup-sort-ind" aria-hidden="true">&#8597;</span>';
    return $dir === 'asc'
        ? '<span class="sup-sort-ind is-active" aria-hidden="true">&#9650;</span>'
        : '<span class="sup-sort-ind is-active" aria-hidden="true">&#9660;</span>';
};

$openCostParts = array();
if (!empty($kpi['open_cost_by_ccy']) && is_array($kpi['open_cost_by_ccy'])) {
    foreach ($kpi['open_cost_by_ccy'] as $ccyCode => $ccySum) {
        $openCostParts[] = $sup_esc($ccyCode) . ' ' . number_format((float)$ccySum, 0);
    }
}
$openCostLabel = !empty($openCostParts) ? implode(' · ', array_slice($openCostParts, 0, 2)) : '0 active POs';
?>
<style>
#supShell { max-width: 100%; overflow-x: clip; }
.sup-hero {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  flex-wrap: wrap; margin-bottom: 14px;
}
.sup-hero-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sup-hero-title h1 { margin: 0; font-size: 18px; font-weight: 700; color: var(--t1); letter-spacing: -0.01em; }
.sup-count-pill {
  padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700;
  background: var(--g2); color: var(--t2); border: 1px solid var(--sep, rgba(148,163,184,0.18));
}
.sup-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.sup-kpi-grid {
  display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px;
}
.sup-kpi {
  position: relative; padding: 12px 14px; border-radius: 12px;
  background: var(--surface); border: 1px solid var(--sep, rgba(148,163,184,0.16));
  overflow: hidden; min-width: 0;
}
.sup-kpi-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--t3); }
.sup-kpi-val { font-size: 21px; font-weight: 800; color: var(--t1); margin-top: 4px; line-height: 1.15; }
.sup-kpi-sub { font-size: 11px; color: var(--t3); margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.sup-tabs {
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 12px;
}
.sup-tab {
  display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 8px;
  font-size: 12px; font-weight: 600; color: var(--t2); text-decoration: none;
  background: var(--surface); border: 1px solid var(--sep, rgba(148,163,184,0.16));
  transition: border-color .15s, background .15s;
}
.sup-tab:hover { border-color: var(--blue); color: var(--t1); }
.sup-tab.is-active { background: var(--blue); color: #ffffff; border-color: var(--blue); }
.sup-tab-cnt {
  padding: 1px 6px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
  background: rgba(148,163,184,0.2); color: inherit;
}
.sup-tab.is-active .sup-tab-cnt { background: rgba(255,255,255,0.22); color: #ffffff; }

.sup-toolbar {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  flex-wrap: wrap; padding: 10px 12px; margin-bottom: 12px; border-radius: 12px;
  background: var(--surface); border: 1px solid var(--sep, rgba(148,163,184,0.16));
}
.sup-filter-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; flex: 1 1 auto; min-width: 0; }
.sup-search-wrap { position: relative; flex: 1 1 210px; min-width: 170px; max-width: 300px; }
.sup-search-wrap input { width: 100%; padding-right: 28px; }
.sup-slash-kbd {
  position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
  padding: 1px 5px; font-size: 10px; font-family: monospace; border-radius: 4px;
  color: var(--t3); border: 1px solid var(--sep, rgba(148,163,184,0.25)); pointer-events: none;
}
.sup-view-switch {
  display: inline-flex; align-items: center; border-radius: 8px; overflow: hidden;
  border: 1px solid var(--sep, rgba(148,163,184,0.2)); background: var(--g1, rgba(15,23,42,0.5));
}
.sup-view-btn {
  border: 0; background: transparent; color: var(--t2); padding: 6px 10px;
  font-size: 11.5px; font-weight: 600; cursor: pointer;
}
.sup-view-btn.is-active { background: var(--blue); color: #ffffff; }

.sup-batch-bar {
  display: none; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
  padding: 9px 14px; margin-bottom: 12px; border-radius: 10px;
  background: rgba(37,99,235,0.14); border: 1px solid rgba(59,130,246,0.45);
}
.sup-batch-bar.is-open { display: flex; }

.sup-table-wrap { width: 100%; overflow-x: auto; }
.sup-table { width: 100%; min-width: 950px; table-layout: fixed; border-collapse: collapse; }
.sup-table th, .sup-table td {
  padding: 10px 8px; vertical-align: middle; overflow: hidden; text-overflow: ellipsis;
  border-bottom: 1px solid var(--g2, rgba(148,163,184,0.12));
}
.sup-table th {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
  color: var(--t3); text-align: left; white-space: nowrap;
}
.sup-table th a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
.sup-table th a:hover { color: var(--t1); }
.sup-sort-ind { font-size: 10px; opacity: 0.45; }
.sup-sort-ind.is-active { opacity: 1; color: var(--blue); }

.sup-col-chk  { width: 34px; text-align: center !important; }
.sup-col-name { width: 22%; }
.sup-col-loc  { width: 13%; }
.sup-col-term { width: 15%; }
.sup-col-cnt  { width: 15%; }
.sup-col-lead { width: 8%; text-align: center !important; }
.sup-col-cat  { width: 8%; text-align: center !important; }
.sup-col-po   { width: 10%; text-align: center !important; }
.sup-col-st   { width: 9%; text-align: center !important; }
.sup-col-act  { width: 112px; text-align: right !important; }

.sup-name-btn {
  background: none; border: 0; padding: 0; text-align: left; cursor: pointer;
  color: var(--t1); font-weight: 700; font-size: 12.5px; max-width: 100%;
  display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.sup-name-btn:hover { color: var(--blue); text-decoration: underline; }
.sup-sub {
  font-size: 11px; color: var(--t3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px;
}
.sup-status-pill {
  display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700; line-height: 1.2;
}
.sup-st-active { background: var(--green-d); color: var(--green); border: 1px solid var(--sep); }
.sup-st-inactive { background: var(--g2); color: var(--t2); border: 1px solid var(--sep); }
.sup-st-blacklisted { background: var(--red-d); color: var(--red); border: 1px solid var(--sep); }

.sup-fleet-chip {
  display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 700;
  font-family: monospace; background: var(--blue-d); color: var(--blue); margin-top: 3px;
}
.sup-groups-container { display: none; }
.sup-group-card {
  margin-bottom: 12px; border-radius: 12px; overflow: hidden;
  background: var(--surface); border: 1px solid var(--sep, rgba(148,163,184,0.16));
}
.sup-group-hdr {
  display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
  padding: 11px 16px; background: rgba(148,163,184,0.06); border-bottom: 1px solid var(--g2);
}
.sup-group-rows { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; padding: 12px 16px; }
.sup-group-item {
  padding: 10px 12px; border-radius: 9px; border: 1px solid var(--sep, rgba(148,163,184,0.15));
  display: flex; align-items: center; justify-content: space-between; gap: 10px; min-width: 0;
}

.sup-cards-container { display: none; }
.sup-cards-grid {
  display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px;
}
.sup-card {
  padding: 14px; border-radius: 12px; background: var(--surface);
  border: 1px solid var(--sep, rgba(148,163,184,0.16)); display: flex; flex-direction: column; gap: 10px; min-width: 0;
}
.sup-card-metrics {
  display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px;
  padding: 8px; border-radius: 8px; background: rgba(148,163,184,0.06); font-size: 11px;
}

#supShell[data-view="groups"] .sup-table-wrap,
#supShell[data-view="groups"] .sup-cards-container { display: none; }
#supShell[data-view="groups"] .sup-groups-container { display: block; }

#supShell[data-view="cards"] .sup-table-wrap,
#supShell[data-view="cards"] .sup-groups-container { display: none; }
#supShell[data-view="cards"] .sup-cards-container { display: block; }

.sup-pager {
  display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
  padding: 12px 16px; border-top: 1px solid var(--g2); font-size: 12px; color: var(--t3);
}
.sup-pager-links { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

/* Slide-Over Vendor Inspector Drawer */
.sup-drawer-backdrop {
  position: fixed; inset: 0; background: rgba(2,6,23,0.62); z-index: 1040;
  opacity: 0; pointer-events: none; transition: opacity .18s ease;
}
.sup-drawer-backdrop.is-open { opacity: 1; pointer-events: auto; }
.sup-drawer {
  position: fixed; top: 0; right: 0; bottom: 0; width: min(520px, 100vw); z-index: 1050;
  background: var(--surface); border-left: 1px solid var(--sep, rgba(148,163,184,0.22));
  box-shadow: -16px 0 40px rgba(0,0,0,0.45); display: flex; flex-direction: column;
  transform: translateX(100%); visibility: hidden; transition: transform .2s ease, visibility .2s;
}
.sup-drawer.is-open { transform: translateX(0); visibility: visible; }
.sup-drawer :focus-visible, #supShell :focus-visible { outline: 2px solid var(--blue); outline-offset: 2px; }
.sup-drawer-hdr {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 10px;
  padding: 16px 18px; border-bottom: 1px solid var(--g2);
}
.sup-drawer-body { padding: 16px 18px; overflow-y: auto; flex: 1 1 auto; }
.sup-drawer-ftr {
  display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
  padding: 12px 18px; border-top: 1px solid var(--g2);
}
.sup-kv-grid {
  display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-bottom: 14px;
}
.sup-kv {
  padding: 9px 11px; border-radius: 8px; background: rgba(148,163,184,0.06); min-width: 0;
}
.sup-kv-k { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--t3); }
.sup-kv-v { font-size: 12.5px; font-weight: 600; color: var(--t1); margin-top: 2px; word-break: break-word; }

@media (max-width: 1100px) {
  .sup-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .sup-cards-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 900px) {
  .sup-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .sup-table-wrap { display: none !important; }
  #supShell:not([data-view="groups"]) .sup-cards-container { display: block !important; }
  .sup-cards-grid { grid-template-columns: minmax(0, 1fr); }
  .sup-group-rows { grid-template-columns: minmax(0, 1fr); }
  .sup-search-wrap { max-width: 100%; flex: 1 1 100%; }
  .sup-kv-grid { grid-template-columns: minmax(0, 1fr); }
  .sup-view-btn[data-sup-view="table"] { display: none; }
}
</style>

<div id="supShell" data-view="<?= $sup_esc($view_mode) ?>">

  <!-- Hero Header -->
  <div class="sup-hero">
    <div class="sup-hero-title">
      <h1>Supplier Directory</h1>
      <span class="sup-count-pill"><?= (int)$tot_filt ?> of <?= (int)$kpi['total'] ?> vendors</span>
    </div>
    <div class="sup-actions">
      <a href="<?= base_url('suppliers/find_part') ?>" class="btn btn-ghost btn-sm">Who Supplies This Part?</a>
      <a href="<?= $sup_esc($sup_url('suppliers/export_csv', array(), array('page', 'per_page'))) ?>" id="supExportCsvBtn" class="btn btn-ghost btn-sm">Export CSV</a>
      <a href="<?= $sup_esc($sup_url('suppliers/export_xlsx', array(), array('page', 'per_page'))) ?>" id="supExportXlsxBtn" class="btn btn-ghost btn-sm">Export Excel</a>
      <?php if ($can_write): ?>
      <a href="<?= base_url('suppliers/create') ?>" id="supAddBtn" class="btn btn-primary btn-sm">+ Add Supplier</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- 5-Card KPI Strip -->
  <div class="sup-kpi-grid">
    <div class="sup-kpi">
      <div class="sup-kpi-label">Total Suppliers</div>
      <div class="sup-kpi-val"><?= (int)$kpi['total'] ?></div>
      <div class="sup-kpi-sub"><?= (int)$kpi['inactive'] ?> inactive &middot; <?= (int)$kpi['blacklisted'] ?> blacklisted</div>
    </div>
    <div class="sup-kpi">
      <div class="sup-kpi-label">Active Vendors</div>
      <div class="sup-kpi-val" style="color:var(--green);"><?= (int)$kpi['active'] ?></div>
      <div class="sup-kpi-sub">Approved for procurement</div>
    </div>
    <div class="sup-kpi">
      <div class="sup-kpi-label">Catalog Coverage</div>
      <div class="sup-kpi-val"><?= (int)$kpi['with_catalog'] ?></div>
      <div class="sup-kpi-sub"><?= (int)$kpi['catalog_items'] ?> priced part quotes</div>
    </div>
    <div class="sup-kpi">
      <div class="sup-kpi-label">Open PO Exposure</div>
      <div class="sup-kpi-val"><?= (int)$kpi['open_orders'] ?></div>
      <div class="sup-kpi-sub"><?= $openCostLabel ?></div>
    </div>
    <div class="sup-kpi">
      <div class="sup-kpi-label">Origin Corridors</div>
      <div class="sup-kpi-val"><?= (int)$kpi['countries'] ?></div>
      <div class="sup-kpi-sub"><?= (int)$kpi['with_orders'] ?> with PO history</div>
    </div>
  </div>

  <!-- Segment Filter Tabs -->
  <div class="sup-tabs" aria-label="Supplier segments">
    <?php
    $tabDefs = array(
        'all'          => array('All Vendors', $tab_counts['all']),
        'active'       => array('Active', $tab_counts['active']),
        'with_catalog' => array('Catalog Quoted', $tab_counts['with_catalog']),
        'with_orders'  => array('With PO History', $tab_counts['with_orders']),
        'flagged'      => array('Inactive / Blacklisted', $tab_counts['flagged']),
    );
    foreach ($tabDefs as $tk => $meta):
    ?>
    <a href="<?= $sup_esc($sup_url('suppliers', array('tab' => $tk === 'all' ? '' : $tk, 'page' => 1))) ?>"
       class="sup-tab <?= $tab === $tk ? 'is-active' : '' ?>"
       aria-current="<?= $tab === $tk ? 'page' : 'false' ?>">
      <span><?= $sup_esc($meta[0]) ?></span>
      <span class="sup-tab-cnt"><?= (int)$meta[1] ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <p class="cs" style="margin:-5px 0 12px;">Segment counts include all suppliers, before search and other filters.</p>

  <!-- Search, Filters & 3-Way View Switcher -->
  <div class="sup-toolbar">
    <form method="get" action="<?= base_url('suppliers') ?>" class="sup-filter-form" id="supFilterForm">
      <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= $sup_esc($tab) ?>"><?php endif; ?>
      <?php if ($sort !== 'company_name'): ?><input type="hidden" name="sort" value="<?= $sup_esc($sort) ?>"><?php endif; ?>
      <?php if ($dir !== 'asc'): ?><input type="hidden" name="dir" value="<?= $sup_esc($dir) ?>"><?php endif; ?>
      <input type="hidden" name="view" id="supViewInput" value="<?= $sup_esc($view_mode) ?>">

      <div class="sup-search-wrap">
        <input type="search" id="supSearchInput" name="q" class="form-control"
               placeholder="Search vendor, tax ID, contact, part, asset…"
               aria-label="Search suppliers"
               value="<?= $sup_esc($q) ?>">
        <span class="sup-slash-kbd" aria-hidden="true">/</span>
      </div>

      <select name="country" class="form-select" style="width:142px;" aria-label="Filter by country">
        <option value="">All Countries</option>
        <?php foreach ($countries as $c): ?>
        <option value="<?= $sup_esc($c) ?>" <?= (!empty($f['country']) && strcasecmp((string)$f['country'], (string)$c) === 0) ? 'selected' : '' ?>><?= $sup_esc($c) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="currency" class="form-select" style="width:112px;" aria-label="Filter by currency">
        <option value="">All CCY</option>
        <?php foreach ($currencies as $ccy): ?>
        <option value="<?= $sup_esc($ccy) ?>" <?= (!empty($f['currency']) && strcasecmp((string)$f['currency'], (string)$ccy) === 0) ? 'selected' : '' ?>><?= $sup_esc($ccy) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="status" class="form-select" style="width:130px;" aria-label="Filter by status">
        <option value="">All Status</option>
        <?php foreach (array('Active', 'Inactive', 'Blacklisted') as $st): ?>
        <option value="<?= $sup_esc($st) ?>" <?= (!empty($f['status']) && $f['status'] === $st) ? 'selected' : '' ?>><?= $sup_esc($st) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="per_page" class="form-select" style="width:94px;" aria-label="Rows per page" onchange="this.form.submit()">
        <?php foreach ($allowed_pp as $pp): ?>
        <option value="<?= (int)$pp ?>" <?= $per_page === (int)$pp ? 'selected' : '' ?>><?= (int)$pp ?> / pg</option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <a href="<?= base_url('suppliers') ?>" class="btn btn-ghost btn-sm">Reset</a>
    </form>

    <div class="sup-view-switch" role="group" aria-label="Directory layout">
      <button type="button" class="sup-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>" aria-pressed="<?= $view_mode === 'table' ? 'true' : 'false' ?>" data-sup-view="table">Table</button>
      <button type="button" class="sup-view-btn <?= $view_mode === 'groups' ? 'is-active' : '' ?>" aria-pressed="<?= $view_mode === 'groups' ? 'true' : 'false' ?>" data-sup-view="groups">By Country</button>
      <button type="button" class="sup-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>" aria-pressed="<?= $view_mode === 'cards' ? 'true' : 'false' ?>" data-sup-view="cards">Cards</button>
    </div>
  </div>

  <!-- Sticky Multi-Select Batch Action Bar -->
  <div class="sup-batch-bar" id="supBatchBar" role="region" aria-label="Selected suppliers actions">
    <div style="font-size:12.5px;font-weight:600;color:var(--t1);">
      <span id="supSelectedCount">0</span> vendor(s) selected
    </div>
    <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
      <a href="<?= $sup_esc(base_url('suppliers/export_csv')) ?>" id="supBatchExportBtn" class="btn btn-ghost btn-sm">Export Selected CSV</a>
      <a href="<?= $sup_esc(base_url('suppliers/export_xlsx')) ?>" id="supBatchXlsxBtn" class="btn btn-ghost btn-sm">Export Selected Excel</a>
      <button type="button" id="supBatchClearBtn" class="btn btn-ghost btn-sm">Clear</button>
    </div>
  </div>

  <!-- Main Card Container -->
  <div class="card">
    <!-- 1. Zero-Overflow Desktop DataTable -->
    <div class="sup-table-wrap">
      <table class="sup-table">
        <thead>
          <tr>
            <th class="sup-col-chk">
              <input type="checkbox" id="supSelectAll" aria-label="Select all visible suppliers">
            </th>
            <th class="sup-col-name">
              <a href="<?= $sup_esc($sup_sort_url('company_name')) ?>">Vendor <?= $sup_sort_icon('company_name') ?></a>
            </th>
            <th class="sup-col-loc">
              <a href="<?= $sup_esc($sup_sort_url('country')) ?>">Corridor <?= $sup_sort_icon('country') ?></a>
            </th>
            <th class="sup-col-term">Terms &amp; CCY</th>
            <th class="sup-col-cnt">Primary Contact</th>
            <th class="sup-col-lead">
              <a href="<?= $sup_esc($sup_sort_url('lead_time')) ?>">Lead <?= $sup_sort_icon('lead_time') ?></a>
            </th>
            <th class="sup-col-cat">
              <a href="<?= $sup_esc($sup_sort_url('item_count')) ?>">Catalog <?= $sup_sort_icon('item_count') ?></a>
            </th>
            <th class="sup-col-po">
              <a href="<?= $sup_esc($sup_sort_url('order_count')) ?>">POs <?= $sup_sort_icon('order_count') ?></a>
            </th>
            <th class="sup-col-st">
              <a href="<?= $sup_esc($sup_sort_url('status')) ?>">Status <?= $sup_sort_icon('status') ?></a>
            </th>
            <th class="sup-col-act">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($suppliers)): ?>
          <tr>
            <td colspan="10" style="text-align:center;padding:48px 16px;color:var(--t3);">
              No suppliers match the current filters.
              <a href="<?= base_url('suppliers') ?>" style="color:var(--blue);margin-left:6px;">Reset filters</a>
            </td>
          </tr>
        <?php else: foreach ($suppliers as $s):
          $stVal = Supplier_model::clean_status($s->status ?? 'Active');
          $stCls = $stVal === 'Active' ? 'sup-st-active' : ($stVal === 'Blacklisted' ? 'sup-st-blacklisted' : 'sup-st-inactive');
          $assets = !empty($s->fleet_assets) && is_array($s->fleet_assets) ? $s->fleet_assets : array();
        ?>
          <tr data-sup-row="<?= (int)$s->id ?>">
            <td class="sup-col-chk">
              <input type="checkbox" class="sup-row-check" value="<?= (int)$s->id ?>"
                     aria-label="Select <?= $sup_esc($s->company_name) ?>">
            </td>
            <td class="sup-col-name">
              <button type="button" class="sup-name-btn" data-sup-open="<?= (int)$s->id ?>"
                      title="<?= $sup_esc($s->company_name) ?>">
                <?= $sup_esc($s->company_name) ?>
              </button>
              <div class="sup-sub">
                <?= $s->tax_id ? 'TIN: ' . $sup_esc($s->tax_id) : 'ID #' . (int)$s->id ?>
              </div>
              <?php if (!empty($assets)): ?>
              <span class="sup-fleet-chip" title="Linked fleet machines"><?= $sup_esc(implode(', ', array_slice($assets, 0, 2))) ?></span>
              <?php endif; ?>
            </td>
            <td class="sup-col-loc">
              <div style="font-weight:600;color:var(--t1);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= $sup_esc($s->country) ?>
              </div>
              <div class="sup-sub"><?= $sup_esc($s->city ?: '—') ?></div>
            </td>
            <td class="sup-col-term">
              <span class="mono" style="font-weight:700;font-size:11.5px;color:var(--t1);"><?= $sup_esc($s->currency ?: 'TZS') ?></span>
              <div class="sup-sub" title="<?= $sup_esc($s->payment_terms ?: 'Standard terms') ?>">
                <?= $sup_esc($s->payment_terms ?: 'Standard terms') ?>
              </div>
            </td>
            <td class="sup-col-cnt">
              <?php if (!empty($s->primary_contact_name)): ?>
              <div style="font-size:12px;font-weight:600;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= $sup_esc($s->primary_contact_name) ?>
              </div>
              <div class="sup-sub"><?= $sup_esc($s->primary_contact_phone ?: ($s->primary_contact_email ?: $s->primary_contact_role)) ?></div>
              <?php else: ?>
              <span style="color:var(--t3);font-size:11.5px;">No contact</span>
              <?php endif; ?>
            </td>
            <td class="sup-col-lead mono">
              <?= $s->lead_time_guarantee_days !== NULL ? ((int)$s->lead_time_guarantee_days . 'd') : '—' ?>
            </td>
            <td class="sup-col-cat mono">
              <span style="font-weight:700;color:var(--t1);"><?= (int)$s->item_count ?></span>
              <?php if (!empty($s->preferred_count)): ?>
              <div class="sup-sub"><?= (int)$s->preferred_count ?> pref</div>
              <?php endif; ?>
            </td>
            <td class="sup-col-po mono">
              <span style="font-weight:700;color:var(--t1);"><?= (int)$s->order_count ?></span>
              <?php if (!empty($s->open_order_count)): ?>
              <div class="sup-sub" style="color:var(--amber, #fbbf24);"><?= (int)$s->open_order_count ?> open</div>
              <?php elseif ($s->on_time_rate !== NULL): ?>
              <div class="sup-sub" style="color:var(--green);"><?= (int)$s->on_time_rate ?>% OT</div>
              <?php endif; ?>
            </td>
            <td class="sup-col-st">
              <span class="sup-status-pill <?= $stCls ?>"><?= $sup_esc($stVal) ?></span>
            </td>
            <td class="sup-col-act">
              <div style="display:inline-flex;align-items:center;gap:4px;">
                <button type="button" class="btn btn-ghost btn-sm" data-sup-open="<?= (int)$s->id ?>">Inspect</button>
                <a href="<?= base_url('suppliers/view/' . (int)$s->id) ?>" class="btn btn-ghost btn-sm">Profile</a>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- 2. Country Corridor Grouped View -->
    <div class="sup-groups-container" style="padding:14px;">
      <?php if (empty($groups)): ?>
      <div style="text-align:center;padding:36px;color:var(--t3);">No supplier corridors match the current filters.</div>
      <?php else: foreach ($groups as $grp): ?>
      <div class="sup-group-card">
        <div class="sup-group-hdr">
          <div style="font-weight:700;color:var(--t1);font-size:13.5px;">
            <?= $sup_esc($grp->country) ?>
            <span class="sup-count-pill" style="margin-left:6px;"><?= (int)$grp->total_count ?> vendor(s) on this page</span>
          </div>
          <div style="font-size:11.5px;color:var(--t3);">
            <?= (int)$grp->active_count ?> active &middot; <?= (int)$grp->item_count ?> catalog quotes &middot; <?= (int)$grp->open_orders ?> open POs
          </div>
        </div>
        <div class="sup-group-rows">
          <?php foreach ($grp->suppliers as $gs):
            $gst = Supplier_model::clean_status($gs->status ?? 'Active');
            $gCls = $gst === 'Active' ? 'sup-st-active' : ($gst === 'Blacklisted' ? 'sup-st-blacklisted' : 'sup-st-inactive');
          ?>
          <div class="sup-group-item">
            <div style="min-width:0;">
              <button type="button" class="sup-name-btn" data-sup-open="<?= (int)$gs->id ?>"><?= $sup_esc($gs->company_name) ?></button>
              <div class="sup-sub">
                <?= $sup_esc($gs->currency ?: 'TZS') ?>
                &middot; <?= (int)$gs->item_count ?> items
                &middot; <?= (int)$gs->order_count ?> POs
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
              <span class="sup-status-pill <?= $gCls ?>"><?= $sup_esc($gst) ?></span>
              <a href="<?= base_url('suppliers/view/' . (int)$gs->id) ?>" class="btn btn-ghost btn-sm">Open</a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- 3. Responsive Cards View (Mobile Default + Desktop Cards Toggle) -->
    <div class="sup-cards-container" style="padding:14px;">
      <?php if (empty($suppliers)): ?>
      <div style="text-align:center;padding:36px;color:var(--t3);">No suppliers found.</div>
      <?php else: ?>
      <div class="sup-cards-grid">
        <?php foreach ($suppliers as $cs):
          $cst = Supplier_model::clean_status($cs->status ?? 'Active');
          $cCls = $cst === 'Active' ? 'sup-st-active' : ($cst === 'Blacklisted' ? 'sup-st-blacklisted' : 'sup-st-inactive');
        ?>
        <div class="sup-card">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
            <div style="min-width:0;">
              <button type="button" class="sup-name-btn" data-sup-open="<?= (int)$cs->id ?>"><?= $sup_esc($cs->company_name) ?></button>
              <div class="sup-sub"><?= $sup_esc($cs->country) ?><?= $cs->city ? ', ' . $sup_esc($cs->city) : '' ?> &middot; <?= $sup_esc($cs->currency ?: 'TZS') ?></div>
            </div>
            <span class="sup-status-pill <?= $cCls ?>"><?= $sup_esc($cst) ?></span>
          </div>

          <div class="sup-card-metrics">
            <div>
              <div style="color:var(--t3);font-size:10px;">Lead Time</div>
              <div class="mono" style="font-weight:700;color:var(--t1);"><?= $cs->lead_time_guarantee_days !== NULL ? ((int)$cs->lead_time_guarantee_days . 'd') : '—' ?></div>
            </div>
            <div>
              <div style="color:var(--t3);font-size:10px;">Catalog</div>
              <div class="mono" style="font-weight:700;color:var(--t1);"><?= (int)$cs->item_count ?></div>
            </div>
            <div>
              <div style="color:var(--t3);font-size:10px;">Orders</div>
              <div class="mono" style="font-weight:700;color:var(--t1);"><?= (int)$cs->order_count ?></div>
            </div>
          </div>

          <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:auto;">
            <span class="sup-sub" style="margin:0;">
              <?= !empty($cs->primary_contact_name) ? $sup_esc($cs->primary_contact_name) : $sup_esc($cs->payment_terms ?: 'Standard terms') ?>
            </span>
            <div style="display:flex;gap:6px;flex-shrink:0;">
              <button type="button" class="btn btn-ghost btn-sm" data-sup-open="<?= (int)$cs->id ?>">Inspect</button>
              <a href="<?= base_url('suppliers/view/' . (int)$cs->id) ?>" class="btn btn-ghost btn-sm">Profile</a>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Pagination Bar -->
    <div class="sup-pager">
      <div>
        <?php if ($tot_filt === 0): ?>
          Showing 0 suppliers
        <?php else: ?>
          Showing <?= (int)($offset_val + 1) ?>–<?= (int)min($tot_filt, $offset_val + count($suppliers)) ?> of <?= (int)$tot_filt ?> supplier(s)
        <?php endif; ?>
      </div>
      <?php if ($tot_pages > 1): ?>
      <div class="sup-pager-links">
        <?php if ($cur_page > 1): ?>
        <a href="<?= $sup_esc($sup_url('suppliers', array('page' => $cur_page - 1))) ?>" class="btn btn-ghost btn-sm">&larr; Prev</a>
        <?php endif; ?>
        <span style="padding:0 8px;">Page <?= (int)$cur_page ?> / <?= (int)$tot_pages ?></span>
        <?php if ($cur_page < $tot_pages): ?>
        <a href="<?= $sup_esc($sup_url('suppliers', array('page' => $cur_page + 1))) ?>" class="btn btn-ghost btn-sm">Next &rarr;</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Accessible Slide-Over Vendor Inspector Drawer -->
<div class="sup-drawer-backdrop" id="supDrawerBackdrop"></div>
<aside class="sup-drawer" id="supDrawer" role="dialog" aria-modal="true" aria-labelledby="supDrawerTitle" aria-hidden="true">
  <div class="sup-drawer-hdr">
    <div style="min-width:0;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--t3);">Vendor Dossier</div>
      <h2 id="supDrawerTitle" style="margin:3px 0 0;font-size:16px;font-weight:700;color:var(--t1);word-break:break-word;">Supplier</h2>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" id="supDrawerClose" aria-label="Close vendor inspector">&times;</button>
  </div>
  <div class="sup-drawer-body" id="supDrawerBody">
    <div style="color:var(--t3);font-size:12.5px;">Select a supplier to inspect commercial terms, contacts, catalog quotes, and recent orders.</div>
  </div>
  <div class="sup-drawer-ftr" id="supDrawerFooter">
    <div style="display:flex;gap:6px;">
      <button type="button" class="btn btn-ghost btn-sm" id="supPrevBtn" title="Previous supplier (ArrowLeft)">&larr; Prev</button>
      <button type="button" class="btn btn-ghost btn-sm" id="supNextBtn" title="Next supplier (ArrowRight)">Next &rarr;</button>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;" id="supDrawerCtas">
      <a href="<?= base_url('suppliers') ?>" id="supDrawerProfileLink" class="btn btn-primary btn-sm">Full Profile</a>
    </div>
  </div>
</aside>

<script>
(function () {
  var shell = document.getElementById('supShell');
  if (!shell) return;
  // At mobile widths the dense table becomes cards; reflect the actual view in the control.
  if (window.matchMedia('(max-width: 900px)').matches && shell.getAttribute('data-view') === 'table') {
    shell.setAttribute('data-view', 'cards');
    var tableButton = document.querySelector('[data-sup-view="table"]');
    var cardsButton = document.querySelector('[data-sup-view="cards"]');
    if (tableButton) { tableButton.classList.remove('is-active'); tableButton.setAttribute('aria-pressed', 'false'); }
    if (cardsButton) { cardsButton.classList.add('is-active'); cardsButton.setAttribute('aria-pressed', 'true'); }
  }

  var summaryBase = <?= json_encode(rtrim(base_url('suppliers/summary_json'), '/') . '/') ?>;
  var viewBase    = <?= json_encode(rtrim(base_url('suppliers/view'), '/') . '/') ?>;
  var orderView   = <?= json_encode(rtrim(base_url('orders/view'), '/') . '/') ?>;
  var exportCsvBase  = <?= json_encode(base_url('suppliers/export_csv')) ?>;
  var exportXlsxBase = <?= json_encode(base_url('suppliers/export_xlsx')) ?>;
<?php if ($can_write): ?>
  var editBase    = <?= json_encode(rtrim(base_url('suppliers/edit'), '/') . '/') ?>;
  var createOrder = <?= json_encode(base_url('orders/create')) ?>;
<?php endif; ?>

  function esc(str) {
    var d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
  }

  // 3-Way View Switcher (Table / By Country / Cards)
  Array.prototype.forEach.call(document.querySelectorAll('[data-sup-view]'), function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.getAttribute('data-sup-view') || 'table';
      // Switch immediately without losing row selection or drawer context.
      // Persist the mode in the URL, form and pager for subsequent navigation.
      shell.setAttribute('data-view', mode);
      var viewInput = document.getElementById('supViewInput');
      if (viewInput) viewInput.value = mode;
      Array.prototype.forEach.call(document.querySelectorAll('[data-sup-view]'), function (b) {
        var active = b === btn;
        b.classList.toggle('is-active', active);
        b.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      var url = new URL(window.location.href);
      if (mode === 'table') url.searchParams.delete('view');
      else url.searchParams.set('view', mode);
      window.history.replaceState(null, '', url.toString());
      Array.prototype.forEach.call(document.querySelectorAll('.sup-pager a[href]'), function (link) {
        var dest = new URL(link.href, window.location.href);
        if (mode === 'table') dest.searchParams.delete('view');
        else dest.searchParams.set('view', mode);
        link.href = dest.toString();
      });
    });
  });

  // Multi-select checkboxes + Batch CSV / Excel export bar
  var selectAll = document.getElementById('supSelectAll');
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.sup-row-check'));
  var batchBar  = document.getElementById('supBatchBar');
  var cntEl     = document.getElementById('supSelectedCount');
  var batchCsv  = document.getElementById('supBatchExportBtn');
  var batchXlsx = document.getElementById('supBatchXlsxBtn');
  var clearBtn  = document.getElementById('supBatchClearBtn');

  function syncSelection() {
    var ids = rowChecks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    if (cntEl) cntEl.textContent = String(ids.length);
    if (batchBar) batchBar.classList.toggle('is-open', ids.length > 0);
    var qs = ids.length ? ('?ids=' + encodeURIComponent(ids.join(','))) : '';
    if (batchCsv) batchCsv.setAttribute('href', exportCsvBase + qs);
    if (batchXlsx) batchXlsx.setAttribute('href', exportXlsxBase + qs);
    if (selectAll && rowChecks.length) {
      selectAll.checked = ids.length === rowChecks.length;
    }
  }
  rowChecks.forEach(function (c) { c.addEventListener('change', syncSelection); });
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      rowChecks.forEach(function (c) { c.checked = selectAll.checked; });
      syncSelection();
    });
  }
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      rowChecks.forEach(function (c) { c.checked = false; });
      syncSelection();
    });
  }

  // Slide-Over Vendor Inspector Drawer
  var drawer    = document.getElementById('supDrawer');
  var backdrop  = document.getElementById('supDrawerBackdrop');
  var titleEl   = document.getElementById('supDrawerTitle');
  var bodyEl    = document.getElementById('supDrawerBody');
  var ctasEl    = document.getElementById('supDrawerCtas');
  var closeBtn  = document.getElementById('supDrawerClose');
  var prevBtn   = document.getElementById('supPrevBtn');
  var nextBtn   = document.getElementById('supNextBtn');
  var lastFocus = null;
  var currentId = null;
  var requestSeq = 0;

  function orderedIds() {
    var seen = {};
    var out = [];
    Array.prototype.forEach.call(document.querySelectorAll('button[data-sup-open]'), function (b) {
      var id = b.getAttribute('data-sup-open');
      if (id && !seen[id]) { seen[id] = true; out.push(id); }
    });
    return out;
  }

  function closeDrawer() {
    requestSeq++;
    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus();
    }
  }

  function openDrawer(id, triggerEl) {
    if (triggerEl) lastFocus = triggerEl;
    currentId = String(id);
    var thisRequest = ++requestSeq;
    drawer.classList.add('is-open');
    backdrop.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    if (closeBtn) closeBtn.focus();
    bodyEl.innerHTML = '<div style="padding:18px 0;color:var(--t3);font-size:12.5px;">Loading vendor dossier…</div>';

    fetch(summaryBase + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (thisRequest !== requestSeq) return;
        if (!res || !res.ok || !res.supplier) {
          bodyEl.innerHTML = '<div style="color:var(--red);">Unable to load supplier details.</div>';
          return;
        }
        var s = res.supplier;
        titleEl.textContent = s.company_name + ' (' + s.country + ')';

        var cHtml = '<div style="margin-bottom:14px;"><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:6px;">Commercial Terms</div>'
          + '<div class="sup-kv-grid">'
          + '<div class="sup-kv"><div class="sup-kv-k">Status &amp; Currency</div><div class="sup-kv-v">' + esc(s.status) + ' · ' + esc(s.currency) + '</div></div>'
          + '<div class="sup-kv"><div class="sup-kv-k">Tax ID</div><div class="sup-kv-v">' + esc(s.tax_id) + '</div></div>'
          + '<div class="sup-kv"><div class="sup-kv-k">Quoted Lead Time</div><div class="sup-kv-v">' + (s.lead_time_guarantee_days !== null ? (s.lead_time_guarantee_days + ' days') : '—') + '</div></div>'
          + '<div class="sup-kv"><div class="sup-kv-k">On-Time Delivery</div><div class="sup-kv-v">' + (s.on_time_rate !== null ? (s.on_time_rate + '% (' + s.on_time_sample + ' closed)') : 'No closed POs yet') + '</div></div>'
          + '<div class="sup-kv" style="grid-column:1 / -1;"><div class="sup-kv-k">Payment Terms</div><div class="sup-kv-v">' + esc(s.payment_terms) + '</div></div>'
          + '</div></div>';

        var contactsHtml = '<div style="margin-bottom:14px;"><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:6px;">Contacts (' + res.contacts.length + ')</div>';
        if (!res.contacts.length) {
          contactsHtml += '<div style="font-size:12px;color:var(--t3);">No contacts registered yet.</div>';
        } else {
          res.contacts.forEach(function (c) {
            contactsHtml += '<div style="padding:7px 10px;border-radius:8px;background:rgba(148,163,184,0.06);margin-bottom:6px;font-size:12px;">'
              + '<div style="font-weight:600;color:var(--t1);">' + esc(c.name) + ' <span style="color:var(--t3);font-weight:400;">— ' + esc(c.role) + '</span></div>'
              + '<div style="color:var(--t3);font-size:11px;">' + esc(c.phone || '—') + (c.email ? (' · ' + esc(c.email)) : '') + '</div></div>';
          });
        }
        contactsHtml += '</div>';

        var catHtml = '<div style="margin-bottom:14px;"><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:6px;">Catalog Quotes (' + res.catalog.length + ')</div>';
        if (!res.catalog.length) {
          catHtml += '<div style="font-size:12px;color:var(--t3);">No catalog items mapped yet.</div>';
        } else {
          res.catalog.slice(0, 6).forEach(function (m) {
            catHtml += '<div style="display:flex;justify-content:space-between;gap:8px;padding:7px 10px;border-radius:8px;background:rgba(148,163,184,0.06);margin-bottom:6px;font-size:12px;">'
              + '<div style="min-width:0;"><div style="font-weight:600;color:var(--t1);">' + esc(m.description) + (m.is_preferred ? ' ★' : '') + '</div>'
              + '<div style="font-size:11px;color:var(--t3);">PN: ' + esc(m.part_number) + (m.item_code ? (' · Store: ' + esc(m.item_code)) : '') + '</div></div>'
              + '<div class="mono" style="white-space:nowrap;font-weight:700;color:var(--t1);">' + (m.unit_price !== null ? (m.unit_price.toFixed(2) + ' ' + esc(m.currency)) : '—') + '</div></div>';
          });
        }
        catHtml += '</div>';

        var ordHtml = '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:6px;">Purchase Orders &amp; Fleet Traceability (' + res.orders.length + ')</div>';
        if (!res.orders.length) {
          ordHtml += '<div style="font-size:12px;color:var(--t3);">No purchase orders placed with this vendor yet.</div>';
        } else {
          res.orders.forEach(function (o) {
            ordHtml += '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:7px 10px;border-radius:8px;background:rgba(148,163,184,0.06);margin-bottom:6px;font-size:12px;">'
              + '<div><a href="' + orderView + encodeURIComponent(o.id) + '" style="color:var(--blue);font-weight:700;">' + esc(o.order_no) + '</a>'
              + '<div style="font-size:11px;color:var(--t3);">' + esc(o.order_date) + ' · ' + esc(o.status)
              + (o.registration_no ? (' · Machine: ' + esc(o.registration_no)) : '') + '</div></div>'
              + '<div class="mono" style="font-weight:700;color:var(--t1);">' + Number(o.total_cost).toFixed(2) + ' ' + esc(o.currency) + '</div></div>';
          });
        }
        ordHtml += '</div>';

        bodyEl.innerHTML = cHtml + contactsHtml + catHtml + ordHtml;

        var ctas = '<a href="' + viewBase + encodeURIComponent(s.id) + '" class="btn btn-primary btn-sm">Full Profile</a>';
<?php if ($can_write): ?>
        ctas = '<a href="' + editBase + encodeURIComponent(s.id) + '" class="btn btn-ghost btn-sm">Edit Vendor</a>'
             + '<a href="' + createOrder + '" class="btn btn-ghost btn-sm">+ New Order</a>' + ctas;
<?php endif; ?>
        ctasEl.innerHTML = ctas;
      })
      .catch(function () {
        if (thisRequest !== requestSeq) return;
        bodyEl.innerHTML = '<div style="color:var(--red);">Network error loading supplier dossier.</div>';
      });
  }

  Array.prototype.forEach.call(document.querySelectorAll('button[data-sup-open]'), function (btn) {
    btn.addEventListener('click', function () {
      openDrawer(btn.getAttribute('data-sup-open'), btn);
    });
  });

  function stepDrawer(delta) {
    var list = orderedIds();
    if (!list.length || !currentId) return;
    var idx = list.indexOf(currentId);
    var nextIdx = (idx + delta + list.length) % list.length;
    openDrawer(list[nextIdx], null);
  }

  if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
  if (backdrop) backdrop.addEventListener('click', closeDrawer);
  if (prevBtn) prevBtn.addEventListener('click', function () { stepDrawer(-1); });
  if (nextBtn) nextBtn.addEventListener('click', function () { stepDrawer(1); });

  document.addEventListener('keydown', function (ev) {
    var tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
    var inField = (tag === 'input' || tag === 'textarea' || tag === 'select' || (ev.target && ev.target.isContentEditable));
    if (ev.key === '/' && !inField && !drawer.classList.contains('is-open')) {
      var sInput = document.getElementById('supSearchInput');
      if (sInput) {
        ev.preventDefault();
        sInput.focus();
      }
      return;
    }
    if (!drawer.classList.contains('is-open')) return;
    if (ev.key === 'Tab') {
      var focusables = Array.prototype.slice.call(drawer.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled])'))
        .filter(function (el) { return el.getClientRects().length > 0; });
      if (focusables.length) {
        var first = focusables[0], last = focusables[focusables.length - 1];
        if (ev.shiftKey && (document.activeElement === first || !drawer.contains(document.activeElement))) {
          ev.preventDefault(); last.focus();
        } else if (!ev.shiftKey && (document.activeElement === last || !drawer.contains(document.activeElement))) {
          ev.preventDefault(); first.focus();
        }
      }
      return;
    }
    if (ev.key === 'Escape') {
      ev.preventDefault();
      closeDrawer();
    } else if (ev.key === 'ArrowRight') {
      ev.preventDefault();
      stepDrawer(1);
    } else if (ev.key === 'ArrowLeft') {
      ev.preventDefault();
      stepDrawer(-1);
    }
  });
})();
</script>
