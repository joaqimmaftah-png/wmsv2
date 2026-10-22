<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enterprise Backlog & Defect Register (`/backlog`)
 * Replaces the legacy 15-column horizontal-scroll table with a zero-overflow
 * 1440x900 DataTable, 5-card telemetry strip, hotspot asset quick-filters,
 * 7 compliance tabs, multi-select CSV export, original two-sheet Excel export,
 * accessible Slide-Over Defect Inspector (`#blDrawer`), and Supervisor Verify
 * & Close Modal (`#blCloseModal`).
 */
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$items          = isset($items) && is_array($items) ? $items : array();
$by_asset       = isset($by_asset) && is_array($by_asset) ? $by_asset : array();
$equipment      = isset($equipment) && is_array($equipment) ? $equipment : array();
$available      = isset($available) ? !empty($available) : TRUE;
$can_write      = !empty($can_write);
$is_supervisor  = !empty($is_supervisor);

$kpi = isset($kpi) && is_array($kpi) ? $kpi : array(
    'total' => count($items), 'open' => 0, 'closed' => 0, 'p1' => 0,
    'overdue' => 0, 'breakdown' => 0, 'scheduled' => 0, 'avg_days' => 0, 'oldest' => 0,
);
$tab_counts = isset($tab_counts) && is_array($tab_counts) ? $tab_counts : array(
    'all' => count($items), 'active' => 0, 'p1' => 0, 'breakdown' => 0,
    'overdue' => 0, 'scheduled' => 0, 'closed' => 0,
);

$f              = isset($filter) && is_array($filter) ? $filter : array();
$g              = function ($k) use ($f) { return isset($f[$k]) ? (string)$f[$k] : ''; };
$tab            = isset($tab) ? (string)$tab : 'all';
$q              = isset($q) ? (string)$q : $g('search');
$sort           = isset($sort) ? (string)$sort : 'default';
$dir            = isset($dir) && $dir === 'desc' ? 'desc' : 'asc';
$view_mode      = isset($view_mode) && $view_mode === 'cards' ? 'cards' : 'table';
$per_page       = isset($per_page) ? (int)$per_page : 25;
$allowed_pp     = isset($allowed_pp) && is_array($allowed_pp) ? $allowed_pp : array(10, 25, 50, 100, 150);
$current_page   = isset($current_page) ? max(1, (int)$current_page) : 1;
$total_pages    = isset($total_pages) ? max(1, (int)$total_pages) : 1;
$total_filtered = isset($total_filtered) ? (int)$total_filtered : count($items);
$offset         = isset($offset) ? (int)$offset : 0;

$baseParams = array();
if ($tab !== 'all')                 $baseParams['tab']            = $tab;
if ($q !== '')                      $baseParams['q']              = $q;
if ($g('status') !== '')            $baseParams['status']         = $g('status');
if ($g('priority') !== '')          $baseParams['priority']       = $g('priority');
if ($g('source') !== '')            $baseParams['source']         = $g('source');
if ($g('machine_status') !== '')    $baseParams['machine_status'] = $g('machine_status');
if ($g('equipment_id') !== '')      $baseParams['equipment_id']   = $g('equipment_id');
if ($g('date_from') !== '')         $baseParams['date_from']      = $g('date_from');
if ($g('date_to') !== '')           $baseParams['date_to']        = $g('date_to');
if ($g('overdue') !== '')           $baseParams['overdue']        = '1';
if ($sort !== 'default') {
    $baseParams['sort'] = $sort;
    $baseParams['dir']  = $dir;
}
if ($view_mode !== 'table')         $baseParams['view']           = $view_mode;
if ($per_page !== 25)               $baseParams['per_page']       = $per_page;

$buildUrl = function (array $overrides = array()) use ($baseParams) {
    $params = array_merge($baseParams, $overrides);
    foreach ($params as $k => $v) {
        if ($v === NULL || $v === '' || $v === 0 || ($k === 'tab' && $v === 'all') || ($k === 'page' && (int)$v <= 1)) {
            unset($params[$k]);
        }
    }
    $qs = http_build_query($params);
    return base_url('backlog' . ($qs !== '' ? '?' . $qs : ''));
};

$exportQs     = http_build_query($baseParams);
$csvExportUrl = base_url('backlog/export_csv' . ($exportQs !== '' ? '?' . $exportQs : ''));
$xlsxQs       = http_build_query(array_filter(array(
    'status'         => $g('status'),
    'priority'       => $g('priority'),
    'source'         => $g('source'),
    'machine_status' => $g('machine_status'),
    'equipment_id'   => $g('equipment_id'),
    'date_from'      => $g('date_from'),
    'date_to'        => $g('date_to'),
    'overdue'        => $g('overdue'),
    'search'         => $q,
), function ($v) { return $v !== ''; }));
$xlsxExportUrl = base_url('backlog/export_xlsx' . ($xlsxQs !== '' ? '?' . $xlsxQs : ''));

$sortTh = function ($colKey, $label, $align = 'left') use ($sort, $dir, $buildUrl, $h) {
    $isCurrent = ($sort === $colKey);
    $nextDir   = ($isCurrent && $dir === 'asc') ? 'desc' : 'asc';
    $ariaSort  = $isCurrent ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $arrow     = $isCurrent ? ($dir === 'asc' ? '↑' : '↓') : '↕';
    $cls       = ($isCurrent ? 'is-sorted ' : '') . ($align === 'right' ? 'num' : ($align === 'center' ? 'ctr' : ''));
    return '<th class="' . trim($cls) . '" aria-sort="' . $ariaSort . '">'
        . '<a class="bl-sort-link" href="' . $h($buildUrl(array('sort' => $colKey, 'dir' => $nextDir, 'page' => 1))) . '">'
        . '<span>' . $h($label) . '</span><span class="bl-sort-arrow" aria-hidden="true">' . $arrow . '</span>'
        . '</a></th>';
};

$equipMap = array();
foreach ($equipment as $eqObj) {
    $equipMap[(int)$eqObj->id] = (string)$eqObj->registration_no;
}

$activeChips = array();
if ($g('status') !== '') {
    $activeChips[] = array('label' => 'Status: ' . $g('status'), 'url' => $buildUrl(array('status' => NULL, 'page' => 1)));
}
if ($g('priority') !== '') {
    $activeChips[] = array('label' => 'Priority: ' . $g('priority'), 'url' => $buildUrl(array('priority' => NULL, 'page' => 1)));
}
if ($g('source') !== '') {
    $activeChips[] = array('label' => 'Source: ' . $g('source'), 'url' => $buildUrl(array('source' => NULL, 'page' => 1)));
}
if ($g('machine_status') !== '') {
    $activeChips[] = array('label' => 'Condition: ' . $g('machine_status'), 'url' => $buildUrl(array('machine_status' => NULL, 'page' => 1)));
}
if ((int)$g('equipment_id') > 0) {
    $eid = (int)$g('equipment_id');
    $activeChips[] = array('label' => 'Machine: ' . (isset($equipMap[$eid]) ? $equipMap[$eid] : ('#' . $eid)), 'url' => $buildUrl(array('equipment_id' => NULL, 'page' => 1)));
}
if ($g('date_from') !== '') {
    $activeChips[] = array('label' => 'From: ' . $g('date_from'), 'url' => $buildUrl(array('date_from' => NULL, 'page' => 1)));
}
if ($g('date_to') !== '') {
    $activeChips[] = array('label' => 'To: ' . $g('date_to'), 'url' => $buildUrl(array('date_to' => NULL, 'page' => 1)));
}
if ($g('overdue') !== '') {
    $activeChips[] = array('label' => 'Overdue only', 'url' => $buildUrl(array('overdue' => NULL, 'page' => 1)));
}
if ($q !== '') {
    $activeChips[] = array('label' => 'Search: “' . $q . '”', 'url' => $buildUrl(array('q' => NULL, 'page' => 1)));
}
?>

<style>
.bl-shell {
  --bl-bg: #F8FAFC;
  --bl-surface: #FFFFFF;
  --bl-border: #E2E8F0;
  --bl-border-strong: #CBD5E1;
  --bl-text: #0F172A;
  --bl-muted: #475569;
  --bl-subtle: #64748B;
  --bl-accent: #1D4ED8;
  --bl-accent-soft: #EFF6FF;
  --bl-ok-bg: #ECFDF5;
  --bl-ok-fg: #047857;
  --bl-ok-bd: #A7F3D0;
  --bl-warn-bg: #FFFBEB;
  --bl-warn-fg: #B45309;
  --bl-warn-bd: #FDE68A;
  --bl-crit-bg: #FEF2F2;
  --bl-crit-fg: #B91C1C;
  --bl-crit-bd: #FECACA;
  max-width: 100%;
  overflow-x: clip;
  color: var(--bl-text);
  font-variant-numeric: tabular-nums;
}
.bl-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.bl-title { font-size: 21px; font-weight: 700; letter-spacing: -0.02em; color: var(--bl-text); margin: 0; }
.bl-sub { font-size: 13px; color: var(--bl-muted); margin-top: 3px; }
.bl-head-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.bl-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  border-radius: 8px;
  font-size: 12.5px;
  font-weight: 600;
  text-decoration: none;
  border: 1px solid var(--bl-border);
  background: var(--bl-surface);
  color: var(--bl-text);
  cursor: pointer;
  line-height: 1.25;
  transition: background .14s, border-color .14s;
}
.bl-btn:hover { background: #F1F5F9; border-color: var(--bl-border-strong); color: var(--bl-text); }
.bl-btn-primary { background: var(--bl-accent); border-color: var(--bl-accent); color: #FFFFFF; }
.bl-btn-primary:hover { background: #1E40AF; border-color: #1E40AF; color: #FFFFFF; }
.bl-btn-sm { padding: 4px 8px; font-size: 11.5px; border-radius: 6px; }

/* 5-Card KPI Telemetry Strip */
.bl-kpi-strip {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}
.bl-kpi {
  background: var(--bl-surface);
  border: 1px solid var(--bl-border);
  border-radius: 11px;
  padding: 13px 15px;
  text-decoration: none;
  color: inherit;
  display: flex;
  flex-direction: column;
  gap: 4px;
  transition: border-color .14s, box-shadow .14s;
}
.bl-kpi:hover { border-color: #93C5FD; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05); }
.bl-kpi-lbl { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--bl-subtle); }
.bl-kpi-val { font-size: 22px; font-weight: 700; color: var(--bl-text); line-height: 1.15; }
.bl-kpi-meta { font-size: 11.5px; color: var(--bl-muted); }
.bl-kpi.is-crit .bl-kpi-val { color: var(--bl-crit-fg); }
.bl-kpi.is-warn .bl-kpi-val { color: var(--bl-warn-fg); }
.bl-kpi.is-ok .bl-kpi-val { color: var(--bl-ok-fg); }

/* Hotspot Assets Strip */
.bl-hotspots {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 12px;
  padding: 8px 12px;
  background: var(--bl-surface);
  border: 1px solid var(--bl-border);
  border-radius: 10px;
  font-size: 12px;
}
.bl-hotspot-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
  background: #F8FAFC;
  border: 1px solid var(--bl-border);
  color: var(--bl-text);
  text-decoration: none;
}
.bl-hotspot-pill:hover { border-color: #93C5FD; background: var(--bl-accent-soft); color: var(--bl-accent); }

/* Tabs Row + View Switcher */
.bl-tabs-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.bl-tabs { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.bl-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 11px;
  border-radius: 8px;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--bl-muted);
  background: var(--bl-surface);
  border: 1px solid var(--bl-border);
  text-decoration: none;
}
.bl-tab:hover { color: var(--bl-text); border-color: var(--bl-border-strong); }
.bl-tab.is-active { background: var(--bl-accent-soft); color: var(--bl-accent); border-color: #93C5FD; }
.bl-tab-cnt { padding: 1px 6px; border-radius: 999px; font-size: 11px; background: #F1F5F9; color: var(--bl-muted); }
.bl-tab.is-active .bl-tab-cnt { background: #DBEAFE; color: var(--bl-accent); }

.bl-view-seg {
  display: inline-flex;
  border: 1px solid var(--bl-border);
  border-radius: 8px;
  background: var(--bl-surface);
  padding: 2px;
}
.bl-view-btn {
  border: 0;
  background: transparent;
  padding: 5px 10px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 6px;
  color: var(--bl-muted);
  cursor: pointer;
}
.bl-view-btn.is-active { background: var(--bl-accent-soft); color: var(--bl-accent); }

/* Filter Bar */
.bl-filter-panel {
  background: var(--bl-surface);
  border: 1px solid var(--bl-border);
  border-radius: 11px;
  padding: 11px 13px;
  margin-bottom: 12px;
}
.bl-filter-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bl-search-wrap { position: relative; flex: 1 1 200px; min-width: 175px; }
.bl-input, .bl-select {
  height: 34px;
  padding: 0 10px;
  border-radius: 8px;
  border: 1px solid var(--bl-border);
  background: var(--bl-surface);
  color: var(--bl-text);
  font-size: 12.5px;
}
.bl-search-wrap .bl-input { width: 100%; padding-right: 28px; }
.bl-kbd {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 10.5px;
  font-weight: 600;
  color: var(--bl-subtle);
  border: 1px solid var(--bl-border);
  border-radius: 4px;
  padding: 0 5px;
  background: #F8FAFC;
  pointer-events: none;
}
.bl-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 9px; }
.bl-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
  background: var(--bl-accent-soft);
  color: var(--bl-accent);
  text-decoration: none;
  border: 1px solid #BFDBFE;
}

/* Sticky Batch Bar */
.bl-batch-bar {
  display: none;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 9px 14px;
  margin-bottom: 10px;
  border-radius: 10px;
  background: #0F172A;
  color: #FFFFFF;
  font-size: 12.5px;
}
.bl-batch-bar.is-open { display: flex; }
.bl-batch-bar .bl-btn { background: #1E293B; border-color: #334155; color: #FFFFFF; }
.bl-batch-bar .bl-btn-primary { background: #2563EB; border-color: #2563EB; }

/* Zero-Overflow 1440px DataTable */
.bl-table-wrap {
  background: var(--bl-surface);
  border: 1px solid var(--bl-border);
  border-radius: 12px;
  overflow: hidden;
  width: 100%;
}
.bl-table {
  width: 100%;
  table-layout: fixed;
  border-collapse: collapse;
  font-size: 12.5px;
}
.bl-table thead th {
  background: #F8FAFC;
  border-bottom: 1px solid var(--bl-border);
  padding: 10px 9px;
  text-align: left;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--bl-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bl-table thead th.num, .bl-table tbody td.num { text-align: right; }
.bl-table thead th.ctr, .bl-table tbody td.ctr { text-align: center; }
.bl-sort-link { display: inline-flex; align-items: center; gap: 4px; color: inherit; text-decoration: none; max-width: 100%; }
.bl-sort-arrow { font-size: 11px; color: var(--bl-subtle); }
.bl-table thead th.is-sorted .bl-sort-link,
.bl-table thead th.is-sorted .bl-sort-arrow { color: var(--bl-accent); }

.bl-table tbody tr { border-bottom: 1px solid var(--bl-border); transition: background .1s; }
.bl-table tbody tr:last-child { border-bottom: 0; }
.bl-table tbody tr:hover { background: #F8FAFC; }
.bl-table tbody td {
  padding: 10px 9px;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bl-primary-btn {
  border: 0;
  background: transparent;
  padding: 0;
  margin: 0;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--bl-accent);
  cursor: pointer;
  text-align: left;
}
.bl-primary-btn:hover { text-decoration: underline; }
.bl-cell-main { font-weight: 600; color: var(--bl-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bl-cell-sub { font-size: 11.5px; color: var(--bl-subtle); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.bl-equip-link { font-weight: 700; color: var(--bl-text); text-decoration: none; }
.bl-equip-link:hover { color: var(--bl-accent); text-decoration: underline; }

.bl-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  border: 1px solid transparent;
  white-space: nowrap;
}
.bl-badge-crit { background: var(--bl-crit-bg); color: var(--bl-crit-fg); border-color: var(--bl-crit-bd); }
.bl-badge-warn { background: var(--bl-warn-bg); color: var(--bl-warn-fg); border-color: var(--bl-warn-bd); }
.bl-badge-ok   { background: var(--bl-ok-bg);   color: var(--bl-ok-fg);   border-color: var(--bl-ok-bd); }
.bl-badge-neutral { background: #F1F5F9; color: #334155; border-color: #CBD5E1; }

.bl-row-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 5px;
  flex-wrap: nowrap;
}

/* Dual Card Grid & < 768px Mobile Fallback */
.bl-cards-container { display: none; }
.bl-cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 12px;
}
.bl-card {
  background: var(--bl-surface);
  border: 1px solid var(--bl-border);
  border-radius: 12px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.bl-shell[data-view="cards"] .bl-table-wrap { display: none; }
.bl-shell[data-view="cards"] .bl-cards-container { display: block; }

.bl-pager {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 12px;
  font-size: 12.5px;
  color: var(--bl-muted);
}
.bl-page-links { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.bl-page-link {
  padding: 5px 10px;
  border-radius: 7px;
  border: 1px solid var(--bl-border);
  background: var(--bl-surface);
  color: var(--bl-text);
  text-decoration: none;
  font-weight: 600;
  font-size: 12px;
}
.bl-page-link.is-active { background: var(--bl-accent); border-color: var(--bl-accent); color: #FFFFFF; }

/* Slide-Over Defect Inspector Drawer & Verify Modal */
.bl-drawer { position: fixed; inset: 0; z-index: 1050; display: none; }
.bl-drawer.is-open { display: block; }
.bl-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.42); }
.bl-drawer-panel {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  width: min(520px, 100vw);
  background: var(--bl-surface);
  border-left: 1px solid var(--bl-border);
  box-shadow: -12px 0 32px rgba(15, 23, 42, 0.14);
  display: flex;
  flex-direction: column;
  z-index: 1;
}
.bl-drawer-head {
  padding: 15px 18px;
  border-bottom: 1px solid var(--bl-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.bl-drawer-body { padding: 16px 18px; overflow-y: auto; flex: 1 1 auto; font-size: 12.5px; }
.bl-drawer-foot {
  padding: 12px 18px;
  border-top: 1px solid var(--bl-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
}
.bl-kv-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  background: #F8FAFC;
  border: 1px solid var(--bl-border);
  border-radius: 10px;
  padding: 12px;
  margin-bottom: 14px;
}
.bl-kv-lbl { font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: var(--bl-subtle); }
.bl-kv-val { font-size: 12.5px; font-weight: 600; color: var(--bl-text); margin-top: 2px; }

.bl-modal {
  position: fixed;
  inset: 0;
  z-index: 1060;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.bl-modal.is-open { display: flex; }
.bl-modal-dialog {
  position: relative;
  z-index: 1;
  width: min(500px, 100%);
  background: var(--bl-surface);
  border: 1px solid var(--bl-border);
  border-radius: 14px;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.18);
  overflow: hidden;
}

@media (max-width: 1100px) {
  .bl-kpi-strip { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 767px) {
  .bl-kpi-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .bl-table-wrap { display: none !important; }
  .bl-cards-container { display: block !important; }
  .bl-cards-grid { grid-template-columns: 1fr; }
  .bl-view-seg { display: none; }
}
@media print {
  .bl-head-actions, .bl-filter-panel, .bl-tabs-bar, .bl-batch-bar, .bl-hotspots, .bl-pager, .bl-row-actions, .bl-col-chk { display: none !important; }
  .bl-table-wrap { border: 0; }
}
</style>

<div class="bl-shell" id="blShell" data-view="<?= $h($view_mode) ?>">

  <!-- Header Command Bar -->
  <div class="bl-header">
    <div>
      <h1 class="bl-title">Backlog &amp; Defect Register</h1>
      <div class="bl-sub">Defects raised by pre-shift inspections, daily checklists, and job cards — tracked to verified closure.</div>
    </div>

    <div class="bl-head-actions">
      <a class="bl-btn" href="<?= $h($csvExportUrl) ?>">↓ Export CSV</a>
      <a class="bl-btn" href="<?= $h($xlsxExportUrl) ?>">↓ Download Excel</a>
      <?php if ($can_write): ?>
        <a class="bl-btn bl-btn-primary" href="<?= $h(base_url('backlog/create')) ?>">+ New Defect</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$available): ?>
    <div style="padding:18px;border-radius:12px;background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;">
      <strong>The backlog table is not installed yet.</strong><br>
      Import <code>database/schema.sql</code> to enable the Defect / Backlog Register.
    </div>
  <?php else: ?>

    <!-- 5-Card Executive Telemetry Strip -->
    <div class="bl-kpi-strip">
      <a class="bl-kpi" href="<?= $h($buildUrl(array('tab' => 'active', 'page' => 1))) ?>">
        <span class="bl-kpi-lbl">Active Open</span>
        <span class="bl-kpi-val"><?= (int)$kpi['open'] ?></span>
        <span class="bl-kpi-meta"><?= (int)$kpi['total'] ?> total defects logged</span>
      </a>

      <a class="bl-kpi <?= (int)$kpi['p1'] > 0 ? 'is-crit' : '' ?>" href="<?= $h($buildUrl(array('tab' => 'p1', 'page' => 1))) ?>">
        <span class="bl-kpi-lbl">P1 Critical Open</span>
        <span class="bl-kpi-val"><?= (int)$kpi['p1'] ?></span>
        <span class="bl-kpi-meta"><?= (int)$kpi['breakdown'] ?> machine(s) in BREAKDOWN</span>
      </a>

      <a class="bl-kpi <?= (int)$kpi['overdue'] > 0 ? 'is-warn' : '' ?>" href="<?= $h($buildUrl(array('tab' => 'overdue', 'page' => 1))) ?>">
        <span class="bl-kpi-lbl">Overdue Deadlines</span>
        <span class="bl-kpi-val"><?= (int)$kpi['overdue'] ?></span>
        <span class="bl-kpi-meta"><?= (int)$kpi['scheduled'] ?> scheduled on plan</span>
      </a>

      <a class="bl-kpi" href="<?= $h($buildUrl(array('tab' => 'active', 'sort' => 'days_down', 'dir' => 'desc', 'page' => 1))) ?>">
        <span class="bl-kpi-lbl">Avg. Age Open</span>
        <span class="bl-kpi-val"><?= $h($kpi['avg_days']) ?>d</span>
        <span class="bl-kpi-meta">Oldest active defect: <?= (int)$kpi['oldest'] ?>d</span>
      </a>

      <a class="bl-kpi is-ok" href="<?= $h($buildUrl(array('tab' => 'closed', 'page' => 1))) ?>">
        <span class="bl-kpi-lbl">Verified &amp; Closed</span>
        <span class="bl-kpi-val"><?= (int)$kpi['closed'] ?></span>
        <span class="bl-kpi-meta">Supervisor-verified closures</span>
      </a>
    </div>

    <!-- Top Affected Machines Quick-Filter Strip -->
    <?php if (!empty($by_asset)): ?>
      <div class="bl-hotspots">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--bl-subtle);margin-right:4px;">Hotspot Machines:</span>
        <?php foreach ($by_asset as $a):
          $hotspotUrl = !empty($a->equipment_id)
              ? $buildUrl(array('equipment_id' => (int)$a->equipment_id, 'tab' => 'active', 'page' => 1))
              : $buildUrl(array('q' => (string)$a->asset, 'tab' => 'active', 'page' => 1));
        ?>
          <a class="bl-hotspot-pill" href="<?= $h($hotspotUrl) ?>">
            <span><?= $h($a->asset) ?></span>
            <span class="bl-badge <?= (int)$a->p1 > 0 ? 'bl-badge-crit' : 'bl-badge-neutral' ?>">
              <?= (int)$a->items ?><?= (int)$a->p1 > 0 ? ' (' . (int)$a->p1 . ' P1)' : '' ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Governance Tabs & Table/Cards View Switcher -->
    <div class="bl-tabs-bar">
      <nav class="bl-tabs" aria-label="Backlog governance views">
        <?php
        $tabDefs = array(
            'all'       => 'All Items',
            'active'    => 'Open Backlog',
            'p1'        => 'P1 Critical',
            'breakdown' => 'Breakdowns',
            'overdue'   => 'Overdue',
            'scheduled' => 'Planned',
            'closed'    => 'Closed',
        );
        foreach ($tabDefs as $tk => $tl):
            $cnt = isset($tab_counts[$tk]) ? (int)$tab_counts[$tk] : 0;
        ?>
          <a class="bl-tab <?= $tab === $tk ? 'is-active' : '' ?>"
             href="<?= $h($buildUrl(array('tab' => $tk, 'page' => 1))) ?>">
            <span><?= $h($tl) ?></span>
            <span class="bl-tab-cnt"><?= $cnt ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="bl-view-seg" role="group" aria-label="Layout view">
        <button type="button" class="bl-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>" data-bl-view="table">Table</button>
        <button type="button" class="bl-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>" data-bl-view="cards">Cards</button>
      </div>
    </div>

    <!-- Search & Facet Filter Panel -->
    <div class="bl-filter-panel">
      <form method="get" action="<?= $h(base_url('backlog')) ?>" class="bl-filter-form">
        <input type="hidden" name="tab" value="<?= $h($tab) ?>">
        <input type="hidden" name="view" value="<?= $h($view_mode) ?>" id="blViewHiddenInput">

        <div class="bl-search-wrap">
          <input type="search" name="q" id="blSearchInput" class="bl-input"
                 value="<?= $h($q) ?>"
                 placeholder="Search machine ID, defect, WO #, person, remarks…">
          <span class="bl-kbd">/</span>
        </div>

        <select name="status" class="bl-select" aria-label="Status" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <?php foreach (array('OPEN' => 'Open + In Progress', 'Open' => 'Open', 'In Progress' => 'In Progress', 'Closed' => 'Closed', 'Cancelled' => 'Cancelled') as $sk => $sl): ?>
            <option value="<?= $h($sk) ?>" <?= $g('status') === $sk ? 'selected' : '' ?>><?= $h($sl) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="priority" class="bl-select" aria-label="Priority" onchange="this.form.submit()">
          <option value="">All Priorities</option>
          <?php foreach (array('P1', 'P2', 'P3') as $p): ?>
            <option value="<?= $p ?>" <?= $g('priority') === $p ? 'selected' : '' ?>><?= $p ?></option>
          <?php endforeach; ?>
        </select>

        <select name="source" class="bl-select" aria-label="Source" onchange="this.form.submit()">
          <option value="">All Sources</option>
          <?php foreach (array('Inspection', 'Checklist', 'Job Card', 'Operator', 'Other') as $s): ?>
            <option value="<?= $s ?>" <?= $g('source') === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>

        <select name="equipment_id" class="bl-select" aria-label="Machine" onchange="this.form.submit()">
          <option value="">All Machines</option>
          <?php foreach ($equipment as $eq): ?>
            <option value="<?= (int)$eq->id ?>" <?= (string)$g('equipment_id') === (string)$eq->id ? 'selected' : '' ?>>
              <?= $h($eq->registration_no) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <input type="date" name="date_from" class="bl-input" value="<?= $h($g('date_from')) ?>" title="Raised from" onchange="this.form.submit()">
        <input type="date" name="date_to" class="bl-input" value="<?= $h($g('date_to')) ?>" title="Raised to" onchange="this.form.submit()">

        <button type="submit" class="bl-btn">Filter</button>
        <?php if (!empty($activeChips) || $tab !== 'all'): ?>
          <a class="bl-btn" href="<?= $h(base_url('backlog')) ?>">Reset</a>
        <?php endif; ?>
      </form>

      <?php if (!empty($activeChips)): ?>
        <div class="bl-chips">
          <?php foreach ($activeChips as $chip): ?>
            <a class="bl-chip" href="<?= $h($chip['url']) ?>">
              <span><?= $h($chip['label']) ?></span>
              <span aria-hidden="true">×</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Sticky Multi-Select Batch CSV Action Bar -->
    <div class="bl-batch-bar" id="blBatchBar" role="region" aria-label="Selected defects">
      <div><strong id="blSelectedCount">0</strong> defect(s) selected</div>
      <div style="display:flex;align-items:center;gap:8px;">
        <a class="bl-btn bl-btn-primary bl-btn-sm" id="blBatchExportBtn" href="<?= $h($csvExportUrl) ?>">↓ Export Selected CSV</a>
        <button type="button" class="bl-btn bl-btn-sm" id="blBatchClearBtn">Clear</button>
      </div>
    </div>

    <!-- Desktop 1440px Zero-Overflow DataTable -->
    <div class="bl-table-wrap">
      <table class="bl-table">
        <colgroup>
          <col style="width:38px;">
          <col style="width:11%;">
          <col style="width:13%;">
          <col style="width:23%;">
          <col style="width:9%;">
          <col style="width:11%;">
          <col style="width:12%;">
          <col style="width:9%;">
          <col style="width:auto;">
        </colgroup>
        <thead>
          <tr>
            <th class="bl-col-chk ctr">
              <input type="checkbox" id="blCheckAll" aria-label="Select all visible backlog items">
            </th>
            <?= $sortTh('start_date', 'ID & Raised') ?>
            <?= $sortTh('asset', 'Machine ID') ?>
            <?= $sortTh('source', 'Defect & Source') ?>
            <?= $sortTh('priority', 'Priority', 'center') ?>
            <?= $sortTh('days_down', 'Age & Due', 'right') ?>
            <?= $sortTh('in_charge', 'Owner') ?>
            <?= $sortTh('status', 'Status') ?>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
            <tr>
              <td colspan="9" style="padding:40px 18px;text-align:center;color:var(--bl-muted);">
                <div style="font-size:14.5px;font-weight:700;color:var(--bl-text);margin-bottom:4px;">No matching defects in the register</div>
                <div style="font-size:12.5px;margin-bottom:14px;">Adjust your filters or record a new machine defect.</div>
                <?php if ($can_write): ?>
                  <a class="bl-btn bl-btn-primary bl-btn-sm" href="<?= $h(base_url('backlog/create')) ?>">+ New Defect</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($items as $b):
              $bid     = (int)$b->id;
              $mDisp   = isset($b->machine_display) ? $b->machine_display : ($b->equipment_code ?: $b->asset_label);
              $isOpen  = isset($b->is_open) ? (bool)$b->is_open : in_array((string)$b->status, array('Open', 'In Progress'), TRUE);
              $overdue = isset($b->is_overdue) ? (bool)$b->is_overdue : ($isOpen && !empty($b->deadline) && (string)$b->deadline < date('Y-m-d'));
              $owner   = isset($b->owner_display) ? $b->owner_display : ($b->in_charge ?: ($b->reported_by ?: '—'));
              $inspId  = isset($b->inspection_record_id) ? (int)$b->inspection_record_id : 0;

              $pCls = $b->priority === 'P1' ? 'bl-badge-crit' : ($b->priority === 'P2' ? 'bl-badge-warn' : 'bl-badge-neutral');
              $sCls = $b->status === 'Closed' ? 'bl-badge-ok' : ($b->status === 'Cancelled' ? 'bl-badge-neutral' : ($overdue ? 'bl-badge-crit' : 'bl-badge-warn'));
            ?>
              <tr data-bl-id="<?= $bid ?>">
                <td class="bl-col-chk ctr">
                  <input type="checkbox" class="bl-row-check" value="<?= $bid ?>" aria-label="Select defect BL-<?= $bid ?>">
                </td>
                <td>
                  <button type="button" class="bl-primary-btn bl-inspect-trigger" data-bl-open="<?= $bid ?>">
                    BL-<?= str_pad((string)$bid, 4, '0', STR_PAD_LEFT) ?>
                  </button>
                  <div class="bl-cell-sub"><?= $h($b->start_date ?: '—') ?></div>
                </td>
                <td>
                  <div class="bl-cell-main">
                    <?php if (!empty($b->equipment_id)): ?>
                      <a class="bl-equip-link" href="<?= $h(base_url('equipment/view/' . (int)$b->equipment_id)) ?>">
                        <?= $h($mDisp) ?>
                      </a>
                    <?php else: ?>
                      <?= $h($mDisp) ?>
                    <?php endif; ?>
                  </div>
                  <div class="bl-cell-sub">
                    <?= $h($b->machine_status ?: ($b->model ?: 'Unclassified')) ?>
                  </div>
                </td>
                <td>
                  <div class="bl-cell-main" title="<?= $h($b->description) ?>"><?= $h($b->description) ?></div>
                  <div class="bl-cell-sub">
                    <?= $h($b->source ?: 'Other') ?>
                    <?php if (!empty($b->wo_number)): ?>
                      · <a href="<?= $h(base_url('workorders/view/' . (int)$b->work_order_id)) ?>" style="color:var(--bl-accent);text-decoration:none;font-weight:600;"><?= $h($b->wo_number) ?></a>
                    <?php endif; ?>
                    <?php if ($inspId > 0): ?>
                      · <a href="<?= $h(base_url('inspections/view/' . $inspId)) ?>" style="color:var(--bl-accent);text-decoration:none;font-weight:600;">INSP-<?= $inspId ?></a>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="ctr">
                  <?php if (!empty($b->priority)): ?>
                    <span class="bl-badge <?= $pCls ?>"><?= $h($b->priority) ?></span>
                  <?php else: ?>
                    <span style="color:var(--bl-subtle);">—</span>
                  <?php endif; ?>
                </td>
                <td class="num">
                  <div class="bl-cell-main" style="<?= ($isOpen && (int)$b->days_down > 30) ? 'color:var(--bl-crit-fg);' : '' ?>">
                    <?= (int)$b->days_down ?>d
                  </div>
                  <div class="bl-cell-sub" style="<?= $overdue ? 'color:var(--bl-crit-fg);font-weight:600;' : '' ?>">
                    <?= !empty($b->deadline) ? ('Due ' . $h($b->deadline)) : (!empty($b->planned_date) ? ('Plan ' . $h($b->planned_date)) : 'No deadline') ?>
                  </div>
                </td>
                <td>
                  <div class="bl-cell-main"><?= $h($owner) ?></div>
                  <div class="bl-cell-sub">
                    <?php if (!empty($b->verifier_name) && !$isOpen): ?>
                      <span style="color:var(--bl-ok-fg);font-weight:600;">✓ <?= $h($b->verifier_name) ?></span>
                    <?php else: ?>
                      <?= $h($b->reported_by ? ('By ' . $b->reported_by) : '—') ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <span class="bl-badge <?= $sCls ?>"><?= $h($overdue && $isOpen ? 'Overdue' : $b->status) ?></span>
                </td>
                <td>
                  <div class="bl-row-actions">
                    <button type="button" class="bl-btn bl-btn-sm" data-bl-open="<?= $bid ?>">Inspect</button>
                    <?php if ($can_write): ?>
                      <a class="bl-btn bl-btn-sm" href="<?= $h(base_url('backlog/edit/' . $bid)) ?>">Edit</a>
                      <?php if ($isOpen && $is_supervisor): ?>
                        <button type="button" class="bl-btn bl-btn-sm"
                                data-bl-close-id="<?= $bid ?>"
                                data-bl-close-label="<?= $h('BL-' . str_pad((string)$bid, 4, '0', STR_PAD_LEFT) . ' · ' . $mDisp) ?>"
                                data-bl-close-mech="<?= $h($b->in_charge ?: '') ?>">
                          Verify
                        </button>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Dual Card Grid & Automatic < 768px Mobile Card View -->
    <div class="bl-cards-container">
      <?php if (empty($items)): ?>
        <div style="padding:34px 16px;text-align:center;background:var(--bl-surface);border:1px solid var(--bl-border);border-radius:12px;color:var(--bl-muted);">
          No matching backlog items found.
        </div>
      <?php else: ?>
        <div class="bl-cards-grid">
          <?php foreach ($items as $b):
            $bid     = (int)$b->id;
            $mDisp   = isset($b->machine_display) ? $b->machine_display : ($b->equipment_code ?: $b->asset_label);
            $isOpen  = isset($b->is_open) ? (bool)$b->is_open : in_array((string)$b->status, array('Open', 'In Progress'), TRUE);
            $overdue = isset($b->is_overdue) ? (bool)$b->is_overdue : ($isOpen && !empty($b->deadline) && (string)$b->deadline < date('Y-m-d'));
            $pCls    = $b->priority === 'P1' ? 'bl-badge-crit' : ($b->priority === 'P2' ? 'bl-badge-warn' : 'bl-badge-neutral');
          ?>
            <div class="bl-card">
              <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
                <div>
                  <button type="button" class="bl-primary-btn" data-bl-open="<?= $bid ?>">
                    BL-<?= str_pad((string)$bid, 4, '0', STR_PAD_LEFT) ?> · <?= $h($mDisp) ?>
                  </button>
                  <div class="bl-cell-sub"><?= $h($b->start_date ?: '—') ?> · <?= (int)$b->days_down ?>d open</div>
                </div>
                <span class="bl-badge <?= $pCls ?>"><?= $h($b->priority ?: $b->status) ?></span>
              </div>

              <div style="font-size:12.5px;color:var(--bl-text);line-height:1.4;"><?= $h($b->description) ?></div>

              <div style="display:flex;gap:6px;flex-wrap:wrap;padding-top:8px;border-top:1px solid var(--bl-border);">
                <button type="button" class="bl-btn bl-btn-sm" data-bl-open="<?= $bid ?>">Inspect</button>
                <?php if ($can_write): ?>
                  <a class="bl-btn bl-btn-sm" href="<?= $h(base_url('backlog/edit/' . $bid)) ?>">Edit</a>
                  <?php if ($isOpen && $is_supervisor): ?>
                    <button type="button" class="bl-btn bl-btn-sm"
                            data-bl-close-id="<?= $bid ?>"
                            data-bl-close-label="<?= $h('BL-' . str_pad((string)$bid, 4, '0', STR_PAD_LEFT) . ' · ' . $mDisp) ?>"
                            data-bl-close-mech="<?= $h($b->in_charge ?: '') ?>">Verify</button>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Pagination Bar -->
    <div class="bl-pager">
      <div>
        <?php
        $showFrom = $total_filtered > 0 ? ($offset + 1) : 0;
        $showTo   = min($total_filtered, $offset + count($items));
        ?>
        Showing <?= $showFrom ?>–<?= $showTo ?> of <?= $total_filtered ?> backlog item(s)
      </div>

      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <form method="get" action="<?= $h(base_url('backlog')) ?>" style="display:inline-flex;align-items:center;gap:6px;">
          <?php foreach ($baseParams as $pk => $pv): if ($pk === 'per_page' || $pk === 'page') continue; ?>
            <input type="hidden" name="<?= $h($pk) ?>" value="<?= $h($pv) ?>">
          <?php endforeach; ?>
          <label for="blPerPage" style="font-size:12px;">Rows:</label>
          <select id="blPerPage" name="per_page" class="bl-select" style="height:30px;" onchange="this.form.submit()">
            <?php foreach ($allowed_pp as $pp): ?>
              <option value="<?= (int)$pp ?>" <?= $per_page === (int)$pp ? 'selected' : '' ?>><?= (int)$pp ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <div class="bl-page-links">
          <?php if ($current_page > 1): ?>
            <a class="bl-page-link" href="<?= $h($buildUrl(array('page' => $current_page - 1))) ?>">‹ Prev</a>
          <?php endif; ?>
          <?php
          $pStart = max(1, $current_page - 2);
          $pEnd   = min($total_pages, $current_page + 2);
          for ($p = $pStart; $p <= $pEnd; $p++):
          ?>
            <a class="bl-page-link <?= $p === $current_page ? 'is-active' : '' ?>"
               href="<?= $h($buildUrl(array('page' => $p))) ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($current_page < $total_pages): ?>
            <a class="bl-page-link" href="<?= $h($buildUrl(array('page' => $current_page + 1))) ?>">Next ›</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

  <?php endif; ?>
</div>

<!-- Accessible Slide-Over Defect & Traceability Inspector Drawer (`#blDrawer`) -->
<div class="bl-drawer" id="blDrawer" role="dialog" aria-modal="true" aria-labelledby="blDrawerTitle">
  <div class="bl-backdrop" data-bl-close></div>
  <div class="bl-drawer-panel">
    <div class="bl-drawer-head">
      <div>
        <div id="blDrawerTitle" style="font-size:15px;font-weight:700;color:var(--bl-text);">Defect Details</div>
        <div style="font-size:11.5px;color:var(--bl-subtle);margin-top:2px;">Use ← / → to step through defects · Esc to close</div>
      </div>
      <button type="button" class="bl-btn bl-btn-sm" data-bl-close aria-label="Close defect drawer">✕</button>
    </div>
    <div class="bl-drawer-body" id="blDrawerBody"></div>
    <div class="bl-drawer-foot" id="blDrawerFoot"></div>
  </div>
</div>

<?php if ($is_supervisor): ?>
<!-- Supervisor Verify & Close Defect Modal (`#blCloseModal`) -->
<div class="bl-modal" id="blCloseModal" role="dialog" aria-modal="true" aria-labelledby="blCloseModalTitle">
  <div class="bl-backdrop" data-bl-modal-close></div>
  <div class="bl-modal-dialog">
    <div class="bl-drawer-head">
      <div>
        <div id="blCloseModalTitle" style="font-size:15px;font-weight:700;color:var(--bl-text);">Verify &amp; Close Defect</div>
        <div style="font-size:12px;color:var(--bl-subtle);">Records your supervisor sign-off, completion meter, and repair remarks.</div>
      </div>
      <button type="button" class="bl-btn bl-btn-sm" data-bl-modal-close aria-label="Close verification dialog">✕</button>
    </div>
    <form method="post" id="blCloseForm" action="<?= $h(base_url('backlog/close/0')) ?>" style="padding:16px 18px;">
      <?= function_exists('csrf_field') ? csrf_field() : '' ?>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:12px;">
        <div>
          <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">Completion Date</label>
          <input type="date" name="end_date" class="bl-input" style="width:100%;" value="<?= $h(date('Y-m-d')) ?>">
        </div>
        <div>
          <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">End Odometer / Hours</label>
          <input type="text" name="end_odometer" class="bl-input" style="width:100%;" placeholder="e.g. 1425.0 Hr">
        </div>
      </div>
      <div style="margin-bottom:12px;">
        <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">Mechanic who performed repair</label>
        <input type="text" name="mechanic" id="blCloseMechanic" class="bl-input" style="width:100%;">
      </div>
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">What was done / Verification remarks</label>
        <textarea name="closing_remarks" rows="3" style="width:100%;padding:8px 10px;border:1px solid var(--bl-border);border-radius:8px;font-size:12.5px;"
                  placeholder="Describe repair completed and on-site verification…"></textarea>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="bl-btn" data-bl-modal-close>Cancel</button>
        <button type="submit" class="bl-btn bl-btn-primary">Close &amp; Verify Defect</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var shell       = document.getElementById('blShell');
  var summaryBase = <?= json_encode(base_url('backlog/summary_json/'), JSON_UNESCAPED_SLASHES) ?>;
  var editBase    = <?= json_encode(base_url('backlog/edit/'), JSON_UNESCAPED_SLASHES) ?>;
  var closeBase   = <?= json_encode(base_url('backlog/close/'), JSON_UNESCAPED_SLASHES) ?>;
  var equipBase   = <?= json_encode(base_url('equipment/view/'), JSON_UNESCAPED_SLASHES) ?>;
  var woBase      = <?= json_encode(base_url('workorders/view/'), JSON_UNESCAPED_SLASHES) ?>;
  var inspBase    = <?= json_encode(base_url('inspections/view/'), JSON_UNESCAPED_SLASHES) ?>;
  var baseExport  = <?= json_encode($csvExportUrl, JSON_UNESCAPED_SLASHES) ?>;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // Table <-> Cards switcher
  var viewBtns  = document.querySelectorAll('[data-bl-view]');
  var viewInput = document.getElementById('blViewHiddenInput');
  viewBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.getAttribute('data-bl-view') === 'cards' ? 'cards' : 'table';
      shell.setAttribute('data-view', mode);
      viewBtns.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      if (viewInput) viewInput.value = mode;
      try { localStorage.setItem('wms_backlog_view', mode); } catch (e) {}
    });
  });

  // Multi-select checkboxes & sticky CSV batch bar
  var checkAll  = document.getElementById('blCheckAll');
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.bl-row-check'));
  var batchBar  = document.getElementById('blBatchBar');
  var countEl   = document.getElementById('blSelectedCount');
  var exportBtn = document.getElementById('blBatchExportBtn');
  var clearBtn  = document.getElementById('blBatchClearBtn');

  function syncBatch() {
    if (!batchBar) return;
    var ids = rowChecks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    countEl.textContent = String(ids.length);
    batchBar.classList.toggle('is-open', ids.length > 0);
    if (exportBtn) {
      var sep = baseExport.indexOf('?') === -1 ? '?' : '&';
      exportBtn.setAttribute('href', ids.length ? (baseExport + sep + 'ids=' + encodeURIComponent(ids.join(','))) : baseExport);
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

  // Supervisor Verify & Close Modal
  var closeModal = document.getElementById('blCloseModal');
  var closeForm  = document.getElementById('blCloseForm');
  var closeTitle = document.getElementById('blCloseModalTitle');
  var closeMech  = document.getElementById('blCloseMechanic');
  var closeCaller = null;

  function hideCloseModal() {
    if (!closeModal) return;
    closeModal.classList.remove('is-open');
    if (closeCaller && typeof closeCaller.focus === 'function') closeCaller.focus();
  }
  if (closeModal) {
    closeModal.querySelectorAll('[data-bl-modal-close]').forEach(function (el) {
      el.addEventListener('click', hideCloseModal);
    });
  }

  // Slide-Over Defect Inspector Drawer (`#blDrawer`)
  var drawer     = document.getElementById('blDrawer');
  var drawerBody = document.getElementById('blDrawerBody');
  var drawerFoot = document.getElementById('blDrawerFoot');
  var drawerTit  = document.getElementById('blDrawerTitle');
  var orderedIds = Array.prototype.slice.call(document.querySelectorAll('tr[data-bl-id]')).map(function (tr) {
    return parseInt(tr.getAttribute('data-bl-id'), 10);
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
    drawerTit.textContent = 'BL-' + String(activeId).padStart(4, '0');
    drawerBody.innerHTML = '<div style="padding:24px;text-align:center;color:#64748B;">Loading defect details…</div>';
    drawerFoot.innerHTML = '';

    fetch(summaryBase + activeId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || !payload.ok) {
          drawerBody.innerHTML = '<div style="padding:18px;color:#B91C1C;">Could not load defect details.</div>';
          return;
        }
        var it = payload.item;
        drawerTit.textContent = 'BL-' + String(it.id).padStart(4, '0') + ' · ' + it.machine_display;

        var machHtml = it.equipment_id
          ? '<a class="bl-equip-link" href="' + esc(equipBase + it.equipment_id) + '">' + esc(it.machine_display) + '</a>'
          : esc(it.machine_display);

        var html = '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin-bottom:6px;">Defect Parameters</div>';
        html += '<div class="bl-kv-grid">'
          + '<div><div class="bl-kv-lbl">Machine / Asset</div><div class="bl-kv-val">' + machHtml + '</div></div>'
          + '<div><div class="bl-kv-lbl">Priority &amp; Status</div><div class="bl-kv-val">' + esc(it.priority) + ' · ' + esc(it.status) + '</div></div>'
          + '<div><div class="bl-kv-lbl">Raised Date &amp; Age</div><div class="bl-kv-val">' + esc(it.start_date) + ' (' + esc(it.days_down) + 'd)</div></div>'
          + '<div><div class="bl-kv-lbl">Deadline</div><div class="bl-kv-val">' + esc(it.deadline) + '</div></div>'
          + '<div><div class="bl-kv-lbl">Machine Condition</div><div class="bl-kv-val">' + esc(it.machine_status) + '</div></div>'
          + '<div><div class="bl-kv-lbl">Source</div><div class="bl-kv-val">' + esc(it.source) + '</div></div>'
          + '<div><div class="bl-kv-lbl">Reported By</div><div class="bl-kv-val">' + esc(it.reported_by) + '</div></div>'
          + '<div><div class="bl-kv-lbl">In Charge</div><div class="bl-kv-val">' + esc(it.in_charge) + '</div></div>'
          + '</div>';

        html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:12px 0 6px;">Defect Description</div>'
          + '<div style="padding:10px 12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:9px;white-space:pre-line;margin-bottom:12px;">' + esc(it.description) + '</div>';

        if (it.observation) {
          html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:12px 0 6px;">Observation &amp; Sightings</div>'
            + '<div style="padding:10px 12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:9px;white-space:pre-line;margin-bottom:12px;">' + esc(it.observation) + '</div>';
        }

        // Traceability & Planning section
        html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:12px 0 6px;">Traceability &amp; Maintenance Plan</div>'
          + '<div class="bl-kv-grid">'
          + '<div><div class="bl-kv-lbl">Linked Work Order</div><div class="bl-kv-val">'
          + (it.wo_number ? '<a class="bl-equip-link" href="' + esc(woBase + it.work_order_id) + '">' + esc(it.wo_number) + ' (' + esc(it.wo_status) + ')</a>' : 'None linked')
          + '</div></div>'
          + '<div><div class="bl-kv-lbl">Originating Inspection</div><div class="bl-kv-val">'
          + (it.inspection_record_id ? '<a class="bl-equip-link" href="' + esc(inspBase + it.inspection_record_id) + '">INSP-' + esc(it.inspection_record_id) + '</a>' : '—')
          + '</div></div>'
          + '<div><div class="bl-kv-lbl">Planned Date</div><div class="bl-kv-val">' + esc(it.planned_date) + '</div></div>'
          + '<div><div class="bl-kv-lbl">Est. Duration</div><div class="bl-kv-val">' + esc(it.estimated_duration) + '</div></div>'
          + '</div>';

        if (!it.is_open || it.closing_remarks) {
          html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:12px 0 6px;">Closure &amp; Verification Evidence</div>'
            + '<div class="bl-kv-grid">'
            + '<div><div class="bl-kv-lbl">Closed Date</div><div class="bl-kv-val">' + esc(it.end_date) + '</div></div>'
            + '<div><div class="bl-kv-lbl">Verified By</div><div class="bl-kv-val">' + esc(it.verifier_name) + '</div></div>'
            + '</div>'
            + (it.closing_remarks ? '<div style="padding:10px 12px;background:#ECFDF5;border:1px solid #A7F3D0;border-radius:9px;color:#065F46;">' + esc(it.closing_remarks) + '</div>' : '');
        }

        drawerBody.innerHTML = html;

        var foot = '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
          + (it.can_edit ? '<a class="bl-btn bl-btn-primary bl-btn-sm" href="' + esc(editBase + it.id) + '">Edit Defect</a>' : '')
          + '</div>'
          + '<button type="button" class="bl-btn bl-btn-sm" id="blDrawerDoneBtn">Close</button>';
        drawerFoot.innerHTML = foot;
        var doneBtn = document.getElementById('blDrawerDoneBtn');
        if (doneBtn) doneBtn.addEventListener('click', closeDrawer);
      })
      .catch(function () {
        drawerBody.innerHTML = '<div style="padding:18px;color:#B91C1C;">Network error while loading defect details.</div>';
      });
  }

  document.addEventListener('click', function (ev) {
    var closeTrigger = ev.target.closest('[data-bl-close-id]');
    if (closeTrigger && closeModal && closeForm) {
      ev.preventDefault();
      closeCaller = closeTrigger;
      var cid = closeTrigger.getAttribute('data-bl-close-id');
      closeForm.setAttribute('action', closeBase + cid);
      closeTitle.textContent = 'Verify & Close · ' + (closeTrigger.getAttribute('data-bl-close-label') || ('BL-' + cid));
      if (closeMech) closeMech.value = closeTrigger.getAttribute('data-bl-close-mech') || '';
      closeModal.classList.add('is-open');
      return;
    }

    var opener = ev.target.closest('[data-bl-open]');
    if (opener) {
      ev.preventDefault();
      openDrawer(opener.getAttribute('data-bl-open'), opener);
      return;
    }

    if (ev.target.closest('[data-bl-close]')) {
      ev.preventDefault();
      closeDrawer();
    }
  });

  document.addEventListener('keydown', function (ev) {
    var tag = ev.target && ev.target.tagName ? ev.target.tagName.toLowerCase() : '';
    var inField = (tag === 'input' || tag === 'textarea' || tag === 'select' || (ev.target && ev.target.isContentEditable));

    if (ev.key === '/' && !inField && (!drawer || !drawer.classList.contains('is-open'))) {
      var sInput = document.getElementById('blSearchInput');
      if (sInput) {
        ev.preventDefault();
        sInput.focus();
      }
      return;
    }

    if (ev.key === 'Escape') {
      if (closeModal && closeModal.classList.contains('is-open')) {
        ev.preventDefault();
        hideCloseModal();
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
