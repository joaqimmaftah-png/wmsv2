<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Helper closure to build clean query URLs preserving active DataTable state
 * across tabs, column sorting, pagination, view mode, and exports.
 */
$p_mode = isset($period['period_mode']) ? (string)$period['period_mode'] : 'full';

$base_params = array(
    'month'    => $period['month'],
    'period'   => $p_mode,
    'from'     => ($p_mode === 'custom') ? $period['start_date'] : '',
    'to'       => ($p_mode === 'custom') ? $period['end_date'] : '',
    'site'     => $site_filter,
    'role'     => ($role_filter > 0) ? (string)$role_filter : '',
    'q'        => $search_query,
    'tab'      => $active_tab,
    'sort'     => $sort_by,
    'dir'      => $sort_dir,
    'per_page' => (string)$per_page,
    'view'     => $view_mode,
);

$ts_url = function ($path, array $overrides = array()) use ($base_params) {
    $merged = array_merge($base_params, $overrides);
    $clean  = array();
    $defaults = array(
        'period'   => 'full',
        'tab'      => 'all',
        'sort'     => 'name',
        'dir'      => 'asc',
        'per_page' => '25',
        'view'     => 'table',
        'page'     => '1',
    );
    foreach ($merged as $k => $v) {
        $sv = trim((string)$v);
        if ($sv === '') continue;
        if (isset($defaults[$k]) && $sv === $defaults[$k]) continue;
        $clean[$k] = $sv;
    }
    $qs = http_build_query($clean);
    return site_url($path) . ($qs !== '' ? ('?' . $qs) : '');
};

// Period & site query suffix for single-worker timesheet PDF/XLSX/JSON calls
$worker_period_params = array(
    'month'  => $period['month'],
    'period' => $p_mode,
);
if ($p_mode === 'custom') {
    $worker_period_params['from'] = $period['start_date'];
    $worker_period_params['to']   = $period['end_date'];
}
if ($site_filter !== '') {
    $worker_period_params['site'] = $site_filter;
}
$worker_qs = http_build_query($worker_period_params);

// Export query string preserving active filters/sort (without UI pagination/view)
$export_params = array(
    'month'  => $period['month'],
    'period' => $p_mode,
);
if ($p_mode === 'custom') {
    $export_params['from'] = $period['start_date'];
    $export_params['to']   = $period['end_date'];
}
if ($site_filter !== '')  $export_params['site'] = $site_filter;
if ($role_filter > 0)     $export_params['role'] = (string)$role_filter;
if ($search_query !== '') $export_params['q']    = $search_query;
if ($active_tab !== 'all') $export_params['tab'] = $active_tab;
if ($sort_by !== 'name')  $export_params['sort'] = $sort_by;
if ($sort_dir !== 'asc')  $export_params['dir']  = $sort_dir;
$export_qs = http_build_query($export_params);

// Column header helper with ARIA sort attributes & directional indicator
$sort_th = function ($col_key, $label, $align = 'left', $default_dir = 'asc') use ($sort_by, $sort_dir, $ts_url) {
    $is_active = ($sort_by === $col_key);
    $next_dir  = $is_active ? (($sort_dir === 'asc') ? 'desc' : 'asc') : $default_dir;
    $aria_sort = $is_active ? (($sort_dir === 'asc') ? 'ascending' : 'descending') : 'none';
    $href      = $ts_url('team/report', array('sort' => $col_key, 'dir' => $next_dir, 'page' => 1));
    $cls       = 'ts-th-sort' . ($align === 'right' ? ' is-right' : '') . ($is_active ? ' is-sorted' : '');

    $icon = '<svg class="ts-sort-ico" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">';
    if ($is_active && $sort_dir === 'asc') {
        $icon .= '<path d="M6 2.5L9.2 6.2H2.8L6 2.5Z" fill="currentColor"/><path d="M6 9.5L2.8 7.2H9.2L6 9.5Z" fill="currentColor" opacity="0.22"/>';
    } elseif ($is_active && $sort_dir === 'desc') {
        $icon .= '<path d="M6 2.5L9.2 4.8H2.8L6 2.5Z" fill="currentColor" opacity="0.22"/><path d="M6 9.5L2.8 5.8H9.2L6 9.5Z" fill="currentColor"/>';
    } else {
        $icon .= '<path d="M6 2.5L8.8 5.3H3.2L6 2.5ZM6 9.5L3.2 6.7H8.8L6 9.5Z" fill="currentColor" opacity="0.35"/>';
    }
    $icon .= '</svg>';

    return '<th scope="col" aria-sort="' . $aria_sort . '" class="' . ($align === 'right' ? 'ts-num' : '') . '">'
         . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . $cls . '">'
         . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>' . $icon
         . '</a></th>';
};

$role_lookup = array();
foreach ($roles_list as $rl) {
    $role_lookup[(int)$rl->id] = ucfirst((string)$rl->name);
}

$period_mode_labels = array(
    'full'         => 'Full Month (2 Sheets)',
    'full_compact' => 'Full Month (1 Page)',
    'h1'           => '1st Half (1–15)',
    'h2'           => '2nd Half (16–End)',
    'custom'       => 'Custom Dates',
);

$tab_defs = array(
    'all'        => array('label' => 'All Staff',     'count' => (int)$tab_counts['all']),
    'recorded'   => array('label' => 'Recorded',      'count' => (int)$tab_counts['recorded']),
    'unrecorded' => array('label' => 'Needs Entry',   'count' => (int)$tab_counts['unrecorded']),
    'absent'     => array('label' => 'With Absences', 'count' => (int)$tab_counts['absent']),
    'leave'      => array('label' => 'On Leave',      'count' => (int)$tab_counts['leave']),
);

$has_active_filters = (
    $site_filter !== '' ||
    $role_filter > 0 ||
    $search_query !== '' ||
    $active_tab !== 'all' ||
    $p_mode !== 'full'
);

$clean_reset_url = site_url('team/report') . '?month=' . rawurlencode($period['month']) . '&view=' . rawurlencode($view_mode);
?>

<style>
/* ═══════════════════════════════════════════════════════════════════════════
   WMSv2 Enterprise Data Table — Attendance Report & AML Timesheets
   Uses strictly native Apple-HIG tokens defined in application/views/layouts/header.php
   ═══════════════════════════════════════════════════════════════════════════ */
.ts-print-banner { display: none; }

.ts-head {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; flex-wrap: wrap; margin-bottom: 20px;
}
.ts-crumb {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 500; color: var(--t3); margin-bottom: 4px;
}
.ts-crumb a { color: var(--t2); }
.ts-crumb a:hover { color: var(--blue); }
.ts-head-title {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.ts-head-title h1 {
  font-size: 24px; font-weight: 700; letter-spacing: -0.025em;
  color: var(--t1); line-height: 1.2; margin: 0;
}
.ts-period-pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 3px 10px; border-radius: var(--r-pill);
  background: var(--blue-lt); color: var(--blue);
  font-size: 11.5px; font-weight: 600; letter-spacing: -0.005em;
}
.ts-sub { font-size: 13px; color: var(--t2); margin-top: 3px; }
.ts-head-actions {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.ts-btn-ico { width: 15px; height: 15px; flex-shrink: 0; }

/* KPI Strip — compact 5-up enterprise metric strip */
.ts-kpis {
  display: grid; grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px; margin-bottom: 18px;
}
.ts-kpi {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-md); padding: 14px 16px;
  box-shadow: var(--shadow-card);
  display: flex; flex-direction: column; justify-content: space-between;
}
.ts-kpi-label {
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--t3); margin-bottom: 6px;
}
.ts-kpi-val {
  font-size: 24px; font-weight: 700; letter-spacing: -0.03em;
  color: var(--t1); line-height: 1.05;
  font-variant-numeric: tabular-nums;
}
.ts-kpi-meta {
  margin-top: 6px; font-size: 11.5px; color: var(--t3);
  display: flex; align-items: center; gap: 5px;
}

/* Main Data Table Card Container */
.ts-shell {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-lg); box-shadow: var(--shadow-card);
  overflow: visible;
}

/* Scope Filter Strip (Period + Site + Role) */
.ts-scope-bar {
  padding: 12px 16px; background: var(--surface-2);
  border-bottom: 1px solid var(--sep);
  border-radius: var(--r-lg) var(--r-lg) 0 0;
  display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap;
}
.ts-field { display: flex; flex-direction: column; gap: 4px; }
.ts-field label {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--t3); margin: 0;
}
.ts-input, .ts-select {
  height: 32px; padding: 0 10px; font-family: var(--font); font-size: 12.5px;
  color: var(--t1); background: var(--surface);
  border: 1px solid var(--sep-opaque); border-radius: var(--r-sm);
  outline: none; transition: border-color var(--ease), box-shadow var(--ease);
}
.ts-select {
  padding-right: 28px; cursor: pointer;
  appearance: none; -webkit-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236e6e73' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 8px center;
}
.ts-input:focus, .ts-select:focus {
  border-color: var(--blue); box-shadow: 0 0 0 3px var(--blue-lt);
}
.ts-custom-dates { display: inline-flex; align-items: flex-end; gap: 8px; flex-wrap: wrap; }
.ts-custom-dates[hidden] { display: none !important; }

/* Status Categorization Tabs + Search + View Toggle Toolbar */
.ts-toolbar {
  padding: 10px 16px; border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; background: var(--surface);
}
.ts-tabs {
  display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap;
}
.ts-tab {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 11px; border-radius: var(--r-sm);
  font-size: 12.5px; font-weight: 500; color: var(--t2);
  text-decoration: none; border: 1px solid transparent;
  transition: background var(--ease), color var(--ease), border-color var(--ease);
}
.ts-tab:hover { background: var(--fill); color: var(--t1); }
.ts-tab.is-active {
  background: var(--blue-lt); color: var(--blue); font-weight: 600;
  border-color: rgba(0, 113, 227, 0.22);
}
.ts-tab-cnt {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 18px; padding: 0 6px;
  border-radius: var(--r-pill); font-size: 11px; font-weight: 600;
  background: var(--fill); color: var(--t2);
  font-variant-numeric: tabular-nums;
}
.ts-tab.is-active .ts-tab-cnt {
  background: var(--blue); color: #ffffff;
}

.ts-tools-right {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto;
}
.ts-search-wrap {
  position: relative; display: inline-flex; align-items: center;
}
.ts-search-ico {
  position: absolute; left: 9px; width: 14px; height: 14px;
  color: var(--t3); pointer-events: none;
}
.ts-search-input {
  height: 32px; width: 235px; padding: 0 54px 0 29px;
  font-family: var(--font); font-size: 12.5px; color: var(--t1);
  background: var(--surface-2); border: 1px solid var(--sep-opaque);
  border-radius: var(--r-sm); outline: none;
  transition: border-color var(--ease), background var(--ease), box-shadow var(--ease);
}
.ts-search-input:focus {
  background: var(--surface); border-color: var(--blue);
  box-shadow: 0 0 0 3px var(--blue-lt);
}
.ts-kbd {
  position: absolute; right: 8px; padding: 1px 5px;
  font-family: var(--mono); font-size: 10.5px; color: var(--t3);
  background: var(--surface); border: 1px solid var(--sep-opaque);
  border-radius: 4px; pointer-events: none; line-height: 1.3;
}
.ts-search-input:focus ~ .ts-kbd { display: none; }
.ts-search-clear {
  position: absolute; right: 6px; width: 20px; height: 20px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 50%; background: var(--fill-2); color: var(--t2);
  font-size: 13px; text-decoration: none; line-height: 1;
}
.ts-search-clear:hover { background: var(--sep-opaque); color: var(--t1); }

/* Segmented View Toggle (Table | Cards) */
.ts-view-seg {
  display: inline-flex; padding: 2px; background: var(--fill);
  border-radius: var(--r-sm); border: 1px solid var(--sep);
}
.ts-view-btn {
  display: inline-flex; align-items: center; gap: 5px;
  height: 26px; padding: 0 9px; border-radius: 6px; border: none;
  background: transparent; color: var(--t2); font-family: var(--font);
  font-size: 12px; font-weight: 500; cursor: pointer; text-decoration: none;
  transition: background var(--ease), color var(--ease), box-shadow var(--ease);
}
.ts-view-btn:hover { color: var(--t1); }
.ts-view-btn.is-active {
  background: var(--surface); color: var(--t1); font-weight: 600;
  box-shadow: 0 1px 2px rgba(0,0,0,0.08);
}

/* Active Filter Chips Strip */
.ts-chips-bar {
  padding: 8px 16px; background: #fbfbfd; border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
  font-size: 12px;
}
.ts-chip-label { color: var(--t3); font-weight: 500; margin-right: 2px; }
.ts-chip {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 2px 8px; border-radius: var(--r-pill);
  background: var(--surface); border: 1px solid var(--sep-opaque);
  color: var(--t1); font-size: 11.5px; font-weight: 500; text-decoration: none;
}
.ts-chip:hover { border-color: var(--red); color: var(--red); }
.ts-chip-x { color: var(--t3); font-weight: 700; }
.ts-chip-reset {
  margin-left: auto; font-size: 12px; font-weight: 500; color: var(--blue);
}
.ts-chip-reset:hover { text-decoration: underline; }

/* Multi-Select Batch Action Bar */
.ts-batch-bar {
  padding: 9px 16px; background: #eef5ff;
  border-bottom: 1px solid rgba(0, 113, 227, 0.24);
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; flex-wrap: wrap;
}
.ts-batch-bar[hidden] { display: none !important; }
.ts-batch-count {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: 12.5px; font-weight: 600; color: #004c99;
}
.ts-batch-actions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

/* Enterprise Data Table */
.ts-table-wrap { width: 100%; overflow-x: auto; }
.ts-table {
  width: 100%; border-collapse: collapse; font-size: 13px;
}
.ts-table thead th {
  position: sticky; top: 0; z-index: 2;
  background: var(--surface-2);
  border-bottom: 1px solid var(--sep-opaque);
  padding: 9px 11px; text-align: left; white-space: nowrap;
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.035em; color: var(--t3);
}
.ts-table thead th.ts-num { text-align: right; }
.ts-th-sort {
  display: inline-flex; align-items: center; gap: 4px;
  color: var(--t3); text-decoration: none; cursor: pointer;
  user-select: none; transition: color var(--ease);
}
.ts-th-sort.is-right { flex-direction: row-reverse; }
.ts-th-sort:hover { color: var(--t1); }
.ts-th-sort.is-sorted { color: var(--blue); }
.ts-sort-ico { flex-shrink: 0; }

.ts-table tbody tr {
  border-bottom: 1px solid var(--sep);
  transition: background var(--ease);
}
.ts-table tbody tr:last-child { border-bottom: none; }
.ts-table tbody tr:hover { background: #f8f9fb; }
.ts-table tbody tr.is-selected { background: #f0f6ff; }
.ts-table tbody td {
  padding: 10px 11px; vertical-align: middle; color: var(--t1);
}
.ts-num {
  text-align: right; font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.ts-chk-cell { width: 34px; padding-left: 14px !important; padding-right: 4px !important; }
.ts-chk {
  width: 15px; height: 15px; accent-color: var(--blue);
  cursor: pointer; vertical-align: middle;
}

/* Worker Primary Identity Cell */
.ts-worker {
  display: flex; align-items: center; gap: 10px; min-width: 190px;
}
.ts-avatar {
  width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
  background: var(--blue-lt); color: var(--blue);
  font-size: 11.5px; font-weight: 700; letter-spacing: 0.02em;
  display: inline-flex; align-items: center; justify-content: center;
}
.ts-worker-info { min-width: 0; }
.ts-worker-name {
  background: none; border: none; padding: 0; margin: 0;
  font-family: var(--font); font-size: 13.5px; font-weight: 600;
  color: var(--t1); cursor: pointer; text-align: left; line-height: 1.25;
  display: inline-flex; align-items: center; gap: 5px;
}
.ts-worker-name:hover { color: var(--blue); text-decoration: underline; }
.ts-worker-meta {
  display: flex; align-items: center; gap: 5px; flex-wrap: wrap;
  font-size: 11.5px; color: var(--t3); margin-top: 2px; line-height: 1.2;
}
.ts-emp-code {
  font-family: var(--mono); font-size: 11px; color: var(--t2); font-weight: 500;
}

/* Site Cell */
.ts-site-badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 2px 8px; border-radius: 6px;
  background: var(--fill); color: var(--t1);
  font-size: 12px; font-weight: 500; white-space: nowrap;
}
.ts-site-dot {
  width: 6px; height: 6px; border-radius: 50%; background: var(--blue);
}
.ts-site-dot.is-qr { background: var(--teal); }
.ts-muted-zero { color: #b0b0b8; font-family: var(--mono); font-size: 12.5px; }
.ts-val-strong { font-family: var(--mono); font-size: 12.5px; font-weight: 600; color: var(--t1); }
.ts-warn-dot {
  display: inline-block; width: 7px; height: 7px; border-radius: 50%;
  background: var(--amber); margin-left: 4px; vertical-align: middle;
}

/* Attendance Rate Cell */
.ts-rate-wrap {
  display: inline-flex; align-items: center; justify-content: flex-end; gap: 7px;
}
.ts-rate-pct {
  font-family: var(--mono); font-size: 12.5px; font-weight: 700; min-width: 34px; text-align: right;
}
.ts-rate-bar {
  width: 44px; height: 5px; border-radius: 99px;
  background: var(--fill-2); overflow: hidden; flex-shrink: 0;
}
.ts-rate-fill { height: 100%; border-radius: 99px; }
.ts-rate-good .ts-rate-pct { color: #1a8f3c; }
.ts-rate-good .ts-rate-fill { background: var(--green); }
.ts-rate-warn .ts-rate-pct { color: #b45309; }
.ts-rate-warn .ts-rate-fill { background: var(--amber); }
.ts-rate-bad .ts-rate-pct  { color: #d9261c; }
.ts-rate-bad .ts-rate-fill  { background: var(--red); }
.ts-no-records {
  display: inline-flex; align-items: center; padding: 2px 8px;
  border-radius: var(--r-pill); background: var(--fill);
  color: var(--t3); font-size: 11px; font-weight: 500;
}

/* Compact Row Actions + Accessible Overflow Menu */
.ts-row-actions {
  display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px;
}
.ts-act-btn {
  display: inline-flex; align-items: center; gap: 4px;
  height: 27px; padding: 0 8px; border-radius: 6px;
  border: 1px solid var(--sep-opaque); background: var(--surface);
  color: var(--t1); font-family: var(--font); font-size: 11.5px; font-weight: 500;
  cursor: pointer; text-decoration: none; white-space: nowrap;
  transition: background var(--ease), border-color var(--ease), color var(--ease);
}
.ts-act-btn:hover { background: var(--fill); border-color: #b8b8bf; color: var(--t1); }
.ts-act-btn.is-primary {
  background: var(--blue-lt); border-color: rgba(0, 113, 227, 0.25); color: var(--blue);
  font-weight: 600;
}
.ts-act-btn.is-primary:hover { background: rgba(0, 113, 227, 0.16); }

.ts-more-wrap { position: relative; display: inline-flex; }
.ts-more-menu {
  position: absolute; right: 0; top: calc(100% + 4px); z-index: 30;
  min-width: 205px; padding: 5px; background: var(--surface);
  border: 1px solid var(--sep-opaque); border-radius: var(--r-md);
  box-shadow: var(--shadow-md); text-align: left;
}
.ts-more-menu[hidden] { display: none !important; }
.ts-menu-item {
  display: flex; align-items: center; gap: 8px; width: 100%;
  padding: 7px 9px; border: none; background: transparent;
  border-radius: 6px; font-family: var(--font); font-size: 12.5px;
  color: var(--t1); text-decoration: none; cursor: pointer; text-align: left;
}
.ts-menu-item:hover { background: var(--fill); color: var(--blue); }
.ts-menu-sep { height: 1px; background: var(--sep); margin: 4px 2px; }

/* Card View Grid */
.ts-cards-sortbar {
  padding: 8px 16px; border-bottom: 1px solid var(--sep);
  background: var(--surface-2); display: flex; align-items: center;
  justify-content: space-between; gap: 8px; flex-wrap: wrap;
}
.ts-cards-grid {
  padding: 16px;
  display: grid; grid-template-columns: repeat(auto-fill, minmax(295px, 1fr));
  gap: 14px;
}
.ts-card-item {
  background: var(--surface); border: 1px solid var(--sep-opaque);
  border-radius: var(--r-md); padding: 14px 16px;
  display: flex; flex-direction: column; gap: 12px;
  transition: box-shadow var(--ease), border-color var(--ease);
}
.ts-card-item:hover {
  border-color: rgba(0, 113, 227, 0.4); box-shadow: var(--shadow-md);
}
.ts-card-top {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 10px;
}
.ts-card-metrics {
  display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 6px; padding: 9px 10px; border-radius: var(--r-sm);
  background: var(--surface-2); border: 1px solid var(--sep);
}
.ts-cm-box { display: flex; flex-direction: column; gap: 2px; }
.ts-cm-lbl { font-size: 10px; font-weight: 600; text-transform: uppercase; color: var(--t3); }
.ts-cm-val { font-family: var(--mono); font-size: 13px; font-weight: 600; color: var(--t1); }
.ts-card-foot {
  display: flex; align-items: center; justify-content: space-between;
  gap: 8px; padding-top: 8px; border-top: 1px solid var(--sep);
}

/* Empty State */
.ts-empty {
  padding: 48px 24px; text-align: center;
}
.ts-empty-ico {
  width: 44px; height: 44px; border-radius: 12px;
  background: var(--fill); color: var(--t3);
  display: inline-flex; align-items: center; justify-content: center;
  margin-bottom: 12px;
}
.ts-empty h3 { font-size: 15px; font-weight: 600; color: var(--t1); margin-bottom: 4px; }
.ts-empty p  { font-size: 13px; color: var(--t2); max-width: 420px; margin: 0 auto 14px; }

/* Pagination Footer */
.ts-footer {
  padding: 11px 16px; border-top: 1px solid var(--sep);
  background: var(--surface-2); border-radius: 0 0 var(--r-lg) var(--r-lg);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; font-size: 12.5px; color: var(--t2);
}
.ts-pages { display: inline-flex; align-items: center; gap: 4px; }
.ts-page-link {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 30px; height: 28px; padding: 0 8px;
  border-radius: 6px; border: 1px solid var(--sep-opaque);
  background: var(--surface); color: var(--t1);
  font-size: 12px; font-weight: 500; text-decoration: none;
  font-variant-numeric: tabular-nums;
}
.ts-page-link:hover { background: var(--fill); }
.ts-page-link.is-current {
  background: var(--blue); border-color: var(--blue); color: #ffffff; font-weight: 600;
}
.ts-page-link.is-disabled {
  opacity: 0.42; pointer-events: none;
}

/* Visibility switching between Table and Card views */
.ts-mode-table .ts-cards-container { display: none; }
.ts-mode-cards .ts-table-wrap      { display: none; }

/* Slide-Over Right Drawer — AML Attendance Sheet Inspector */
.ts-drawer-backdrop {
  position: fixed; inset: 0; z-index: 900;
  background: rgba(29, 29, 31, 0.36); backdrop-filter: blur(2px);
}
.ts-drawer-backdrop[hidden] { display: none !important; }
.ts-drawer {
  position: fixed; top: 0; right: 0; bottom: 0; z-index: 910;
  width: min(600px, 100vw); background: var(--surface);
  border-left: 1px solid var(--sep-opaque);
  box-shadow: -12px 0 40px rgba(0, 0, 0, 0.14);
  display: flex; flex-direction: column;
}
.ts-drawer[hidden] { display: none !important; }
.ts-drawer-head {
  padding: 16px 20px; border-bottom: 1px solid var(--sep);
  background: var(--surface-2);
  display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
}
.ts-drawer-title { font-size: 17px; font-weight: 700; color: var(--t1); line-height: 1.25; }
.ts-drawer-sub { font-size: 12px; color: var(--t2); margin-top: 3px; }
.ts-drawer-nav { display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0; }
.ts-drawer-body {
  flex: 1; overflow-y: auto; padding: 16px 20px;
}
.ts-drawer-kpis {
  display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px; margin-bottom: 16px;
}
.ts-dkpi {
  padding: 10px 12px; border-radius: var(--r-sm);
  background: var(--surface-2); border: 1px solid var(--sep);
}
.ts-dkpi-lbl { font-size: 10.5px; font-weight: 600; text-transform: uppercase; color: var(--t3); }
.ts-dkpi-val { font-family: var(--mono); font-size: 16px; font-weight: 700; color: var(--t1); margin-top: 2px; }

.ts-seg-card {
  border: 1px solid var(--sep-opaque); border-radius: var(--r-md);
  overflow: hidden; margin-bottom: 16px;
}
.ts-seg-head {
  padding: 8px 12px; background: #1d3557; color: #ffffff;
  font-size: 12px; font-weight: 700; letter-spacing: 0.04em;
  display: flex; justify-content: space-between; align-items: center;
}
.ts-wk-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.ts-wk-table th {
  padding: 6px 8px; background: var(--surface-2); color: var(--t3);
  font-size: 10px; font-weight: 700; text-transform: uppercase;
  border-bottom: 1px solid var(--sep-opaque); text-align: left;
}
.ts-wk-table td {
  padding: 5px 8px; border-bottom: 1px solid var(--sep); color: var(--t1);
}
.ts-wk-band td {
  background: #eef3f9; font-weight: 700; font-size: 11px;
  color: #1d3557; letter-spacing: 0.03em; padding: 5px 8px;
}
.ts-wk-sub td {
  background: var(--surface-2); font-weight: 600; font-size: 11.5px;
  border-bottom: 1px solid var(--sep-opaque);
}
.ts-drawer-foot {
  padding: 12px 20px; border-top: 1px solid var(--sep);
  background: var(--surface-2);
  display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
}

/* Responsive Breakpoints */
@media (max-width: 1100px) {
  .ts-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 780px) {
  .ts-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .ts-search-input { width: 100%; }
  .ts-tools-right { width: 100%; justify-content: space-between; }
  /* Auto-adapt to Card View on mobile viewports unless explicitly forced to Table */
  .ts-shell:not(.ts-user-forced-table) .ts-table-wrap { display: none; }
  .ts-shell:not(.ts-user-forced-table) .ts-cards-container { display: block; }
}

/* Clean Print Media Layout */
@media print {
  .ts-print-banner {
    display: block !important; margin-bottom: 14px;
    padding-bottom: 10px; border-bottom: 2px solid #1d1d1f;
  }
  .ts-print-banner h2 { font-size: 16px; margin: 0 0 3px; }
  .ts-print-banner p  { font-size: 11px; color: #444; margin: 0; }
  .ts-head, .ts-scope-bar, .ts-toolbar, .ts-chips-bar, .ts-batch-bar,
  .ts-footer, .ts-cards-container, .ts-drawer, .ts-drawer-backdrop, .no-print {
    display: none !important;
  }
  .ts-shell { border: none !important; box-shadow: none !important; }
  .ts-table-wrap { display: block !important; overflow: visible !important; }
  .ts-table th, .ts-table td { padding: 6px 8px !important; font-size: 11px !important; }
}
</style>

<!-- Print-only Official Report Header -->
<div class="ts-print-banner">
  <h2>AFRICAN MINING LIMITED — ATTENDANCE SUMMARY REPORT</h2>
  <p>
    Period: <strong><?= htmlspecialchars($period['period_label'], ENT_QUOTES, 'UTF-8') ?></strong>
    <?php if ($site_filter !== ''): ?> · Site: <strong><?= htmlspecialchars($site_filter, ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?>
    · Staff Shown: <strong><?= (int)count($report) ?></strong> of <strong><?= (int)$total_filtered ?></strong>
    · Printed <?= date('d M Y H:i') ?>
  </p>
</div>

<!-- Page Header -->
<div class="ts-head no-print">
  <div>
    <nav class="ts-crumb" aria-label="Breadcrumb">
      <a href="<?= site_url('team') ?>">Team</a>
      <span aria-hidden="true">/</span>
      <span>Attendance &amp; Timesheets</span>
    </nav>
    <div class="ts-head-title">
      <h1><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></h1>
      <span class="ts-period-pill">
        <?= htmlspecialchars($period['month_heading'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($period['period_label'], ENT_QUOTES, 'UTF-8') ?>
      </span>
    </div>
    <p class="ts-sub">
      <?php if ($can_batch): ?>
        Consolidated attendance metrics and official AML individual attendance sheets across active workshop &amp; site staff.
      <?php else: ?>
        Personal attendance record and downloadable AML individual attendance sheets for the selected period.
      <?php endif; ?>
    </p>
  </div>

  <div class="ts-head-actions">
    <?php if ($can_batch): ?>
      <a href="<?= site_url('team/timesheets_batch') . ($export_qs !== '' ? '?' . $export_qs : '') ?>"
         target="_blank" rel="noopener"
         class="btn btn-primary"
         title="Download multi-page A4 AML PDF for all matching staff">
        <svg class="ts-btn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>
        All PDF Sheets
      </a>
      <a href="<?= site_url('team/timesheets_batch') . '?' . ($export_qs !== '' ? $export_qs . '&' : '') . 'format=xlsx' ?>"
         class="btn btn-secondary"
         title="Download multi-tab AML Excel workbook for all matching staff">
        <svg class="ts-btn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="9" x2="9" y2="21"/></svg>
        All Excel (.xlsx)
      </a>
    <?php else: ?>
      <a href="<?= site_url('team/timesheet/' . (int)$current_user_id) . ($worker_qs !== '' ? '?' . $worker_qs : '') ?>"
         target="_blank" rel="noopener"
         class="btn btn-primary">
        <svg class="ts-btn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        My PDF Sheet
      </a>
      <a href="<?= site_url('team/timesheet/' . (int)$current_user_id) . '?' . ($worker_qs !== '' ? $worker_qs . '&' : '') . 'format=xlsx' ?>"
         class="btn btn-secondary">
        <svg class="ts-btn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="9" x2="9" y2="21"/></svg>
        My Excel (.xlsx)
      </a>
    <?php endif; ?>

    <a href="<?= site_url('team/report_csv') . ($export_qs !== '' ? '?' . $export_qs : '') ?>"
       class="btn btn-secondary"
       title="Export filtered Attendance Summary table as UTF-8 CSV">
      <svg class="ts-btn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Summary CSV
    </a>

    <button type="button" class="btn btn-secondary" onclick="window.print()">
      <svg class="ts-btn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print
    </button>

    <?php if ($is_supervisor): ?>
      <a href="<?= site_url('team/attendance?date=' . rawurlencode($period['start_date'])) ?>" class="btn btn-secondary">
        Log Attendance
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- Executive KPI Summary Strip -->
<section class="ts-kpis" aria-label="Period attendance summary">
  <div class="ts-kpi">
    <div class="ts-kpi-label">Staff in Scope</div>
    <div class="ts-kpi-val"><?= (int)$summary['staff_count'] ?></div>
    <div class="ts-kpi-meta">
      <span><?= (int)$summary['recorded_staff'] ?> recorded · <?= (int)$summary['unrecorded_staff'] ?> pending</span>
    </div>
  </div>

  <div class="ts-kpi">
    <div class="ts-kpi-label">Present Shifts</div>
    <div class="ts-kpi-val" style="color:#1a8f3c;"><?= (int)$summary['present_days'] ?></div>
    <div class="ts-kpi-meta">Confirmed working days</div>
  </div>

  <div class="ts-kpi">
    <div class="ts-kpi-label">Hours Logged</div>
    <div class="ts-kpi-val"><?= number_format((float)$summary['hours_worked'], 1) ?><span style="font-size:15px;font-weight:500;color:var(--t2);">h</span></div>
    <div class="ts-kpi-meta">
      <?php if ((int)$summary['missing_checkout'] > 0): ?>
        <span style="color:#b45309;font-weight:600;"><?= (int)$summary['missing_checkout'] ?> open check-in<?= (int)$summary['missing_checkout'] === 1 ? '' : 's' ?></span>
      <?php else: ?>
        <span>All check-outs recorded</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="ts-kpi">
    <div class="ts-kpi-label">Absent / Leave</div>
    <div class="ts-kpi-val">
      <span style="color:<?= (int)$summary['absent_days'] > 0 ? '#d9261c' : 'var(--t1)' ?>;"><?= (int)$summary['absent_days'] ?></span>
      <span style="font-size:16px;font-weight:400;color:var(--t3);">/</span>
      <span style="color:var(--t2);font-size:20px;"><?= (int)$summary['leave_days'] ?></span>
    </div>
    <div class="ts-kpi-meta">Unexcused absent · approved leave</div>
  </div>

  <div class="ts-kpi">
    <div class="ts-kpi-label">Attendance Rate</div>
    <div class="ts-kpi-val">
      <?php if ($summary['overall_pct'] === NULL): ?>
        <span style="color:var(--t3);font-weight:600;">—</span>
      <?php else: ?>
        <?= (int)$summary['overall_pct'] ?><span style="font-size:16px;font-weight:500;color:var(--t2);">%</span>
      <?php endif; ?>
    </div>
    <div class="ts-kpi-meta">
      <?= $summary['overall_pct'] === NULL ? 'No working days logged yet' : 'Present ÷ total working days' ?>
    </div>
  </div>
</section>

<!-- Main Enterprise DataTable Surface -->
<div class="ts-shell ts-mode-<?= htmlspecialchars($view_mode, ENT_QUOTES, 'UTF-8') ?>" id="tsShell">

  <!-- Scope Filter Form -->
  <form method="get" action="<?= site_url('team/report') ?>" class="ts-scope-bar no-print" id="tsFilterForm">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="dir" value="<?= htmlspecialchars($sort_dir, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
    <input type="hidden" name="view" id="tsViewHidden" value="<?= htmlspecialchars($view_mode, ENT_QUOTES, 'UTF-8') ?>">

    <div class="ts-field">
      <label for="tsMonth">Month</label>
      <input type="month" id="tsMonth" name="month" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>" class="ts-input ts-autosubmit">
    </div>

    <div class="ts-field">
      <label for="tsPeriod">Period</label>
      <select name="period" id="tsPeriod" class="ts-select">
        <?php foreach ($period_mode_labels as $m_key => $m_lbl): ?>
          <option value="<?= $m_key ?>" <?= $p_mode === $m_key ? 'selected' : '' ?>><?= htmlspecialchars($m_lbl, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="ts-custom-dates" id="tsCustomDates" <?= $p_mode === 'custom' ? '' : 'hidden' ?>>
      <div class="ts-field">
        <label for="tsFrom">From</label>
        <input type="date" id="tsFrom" name="from" value="<?= htmlspecialchars($period['start_date'], ENT_QUOTES, 'UTF-8') ?>" class="ts-input">
      </div>
      <div class="ts-field">
        <label for="tsTo">To</label>
        <input type="date" id="tsTo" name="to" value="<?= htmlspecialchars($period['end_date'], ENT_QUOTES, 'UTF-8') ?>" class="ts-input">
      </div>
    </div>

    <div class="ts-field">
      <label for="tsSite">Site</label>
      <select name="site" id="tsSite" class="ts-select ts-autosubmit">
        <option value="">All Sites</option>
        <?php foreach ($sites as $st):
            $st_lbl = is_object($st) ? (string)$st->label : (string)$st;
        ?>
          <option value="<?= htmlspecialchars($st_lbl, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($site_filter, $st_lbl) === 0 ? 'selected' : '' ?>>
            <?= htmlspecialchars($st_lbl, ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($can_batch): ?>
      <div class="ts-field">
        <label for="tsRole">Role</label>
        <select name="role" id="tsRole" class="ts-select ts-autosubmit">
          <option value="">All Roles</option>
          <?php foreach ($roles_list as $rl): ?>
            <option value="<?= (int)$rl->id ?>" <?= $role_filter === (int)$rl->id ? 'selected' : '' ?>>
              <?= htmlspecialchars(ucfirst((string)$rl->name), ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary btn-sm" style="height:32px;">Apply</button>
  </form>

  <!-- Status Categorization Tabs + Search + View Switcher -->
  <div class="ts-toolbar no-print">
    <div class="ts-tabs" role="tablist" aria-label="Attendance status filter">
      <?php foreach ($tab_defs as $t_key => $t_info):
          $is_on = ($active_tab === $t_key);
      ?>
        <a href="<?= htmlspecialchars($ts_url('team/report', array('tab' => $t_key, 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
           role="tab"
           aria-selected="<?= $is_on ? 'true' : 'false' ?>"
           class="ts-tab <?= $is_on ? 'is-active' : '' ?>">
          <span><?= htmlspecialchars($t_info['label'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="ts-tab-cnt"><?= $t_info['count'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="ts-tools-right">
      <div class="ts-search-wrap">
        <svg class="ts-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search"
               form="tsFilterForm"
               name="q"
               id="tsSearchInput"
               value="<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="Search staff, ID, position, site…"
               aria-label="Search staff by name, employee ID, position, or site"
               class="ts-search-input">
        <?php if ($search_query !== ''): ?>
          <a href="<?= htmlspecialchars($ts_url('team/report', array('q' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
             class="ts-search-clear"
             aria-label="Clear search query"
             title="Clear search">&times;</a>
        <?php else: ?>
          <kbd class="ts-kbd" aria-hidden="true">/</kbd>
        <?php endif; ?>
      </div>

      <div class="ts-view-seg" role="group" aria-label="Display layout">
        <a href="<?= htmlspecialchars($ts_url('team/report', array('view' => 'table')), ENT_QUOTES, 'UTF-8') ?>"
           data-set-view="table"
           aria-pressed="<?= $view_mode === 'table' ? 'true' : 'false' ?>"
           class="ts-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>
          Table
        </a>
        <a href="<?= htmlspecialchars($ts_url('team/report', array('view' => 'cards')), ENT_QUOTES, 'UTF-8') ?>"
           data-set-view="cards"
           aria-pressed="<?= $view_mode === 'cards' ? 'true' : 'false' ?>"
           class="ts-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          Cards
        </a>
      </div>
    </div>
  </div>

  <!-- Active Filter Chips Strip -->
  <?php if ($has_active_filters): ?>
    <div class="ts-chips-bar no-print" aria-label="Active filters">
      <span class="ts-chip-label">Active filters:</span>
      <?php if ($p_mode !== 'full'): ?>
        <a class="ts-chip" href="<?= htmlspecialchars($ts_url('team/report', array('period' => 'full', 'from' => '', 'to' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Period: <?= htmlspecialchars(isset($period_mode_labels[$p_mode]) ? $period_mode_labels[$p_mode] : $p_mode, ENT_QUOTES, 'UTF-8') ?> <span class="ts-chip-x">&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($site_filter !== ''): ?>
        <a class="ts-chip" href="<?= htmlspecialchars($ts_url('team/report', array('site' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Site: <?= htmlspecialchars($site_filter, ENT_QUOTES, 'UTF-8') ?> <span class="ts-chip-x">&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($role_filter > 0 && isset($role_lookup[$role_filter])): ?>
        <a class="ts-chip" href="<?= htmlspecialchars($ts_url('team/report', array('role' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Role: <?= htmlspecialchars($role_lookup[$role_filter], ENT_QUOTES, 'UTF-8') ?> <span class="ts-chip-x">&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($active_tab !== 'all'): ?>
        <a class="ts-chip" href="<?= htmlspecialchars($ts_url('team/report', array('tab' => 'all', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Status: <?= htmlspecialchars($tab_defs[$active_tab]['label'], ENT_QUOTES, 'UTF-8') ?> <span class="ts-chip-x">&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($search_query !== ''): ?>
        <a class="ts-chip" href="<?= htmlspecialchars($ts_url('team/report', array('q' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Search: &ldquo;<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>&rdquo; <span class="ts-chip-x">&times;</span>
        </a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($clean_reset_url, ENT_QUOTES, 'UTF-8') ?>" class="ts-chip-reset">Reset all</a>
    </div>
  <?php endif; ?>

  <!-- Contextual Multi-Select Batch Action Bar -->
  <?php if ($can_batch): ?>
    <div class="ts-batch-bar no-print" id="tsBatchBar" hidden role="region" aria-label="Selected staff batch actions">
      <div class="ts-batch-count">
        <span id="tsSelectedCount">0</span> staff selected
      </div>
      <div class="ts-batch-actions">
        <a href="#" id="tsBatchPdf" target="_blank" rel="noopener" class="btn btn-primary btn-sm">
          Export Selected PDF
        </a>
        <a href="#" id="tsBatchXlsx" class="btn btn-secondary btn-sm">
          Export Selected XLSX
        </a>
        <a href="#" id="tsBatchCsv" class="btn btn-secondary btn-sm">
          Export Selected CSV
        </a>
        <button type="button" id="tsBatchClear" class="btn btn-ghost btn-sm">Clear selection</button>
      </div>
    </div>
  <?php endif; ?>

  <?php if (empty($report)): ?>
    <!-- Empty / Zero-Match State -->
    <div class="ts-empty">
      <div class="ts-empty-ico" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </div>
      <?php if ($has_active_filters): ?>
        <h3>No staff match your current filters</h3>
        <p>No active team members matched the selected status tab, site, role, or search query for <?= htmlspecialchars($period['period_label'], ENT_QUOTES, 'UTF-8') ?>.</p>
        <a href="<?= htmlspecialchars($clean_reset_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Reset all filters</a>
      <?php else: ?>
        <h3>No active staff records found</h3>
        <p>There are currently no active staff accounts available in this attendance roster.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>

    <!-- 1. Enterprise Table View -->
    <div class="ts-table-wrap">
      <table class="ts-table">
        <thead>
          <tr>
            <?php if ($can_batch): ?>
              <th scope="col" class="ts-chk-cell no-print">
                <input type="checkbox" id="tsCheckAll" class="ts-chk" aria-label="Select all staff on this page">
              </th>
            <?php endif; ?>
            <?= $sort_th('name', 'Staff Member', 'left', 'asc') ?>
            <?= $sort_th('site', 'Site', 'left', 'asc') ?>
            <?= $sort_th('present', 'Present', 'right', 'desc') ?>
            <?= $sort_th('absent', 'Absent', 'right', 'desc') ?>
            <?= $sort_th('leave', 'Leave', 'right', 'desc') ?>
            <th scope="col" class="ts-num" title="Rest days and public holidays">Rest / Hol</th>
            <?= $sort_th('hours', 'Hours', 'right', 'desc') ?>
            <?= $sort_th('total', 'Work Days', 'right', 'desc') ?>
            <?= $sort_th('pct', 'Attendance', 'right', 'desc') ?>
            <th scope="col" class="ts-num no-print">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($report as $idx => $r):
              $initials = strtoupper(substr($r->first_name, 0, 1) . substr($r->last_name, 0, 1));
              $can_row_export = ($can_batch || (int)$r->id === (int)$current_user_id);
              $pdf_url   = site_url('team/timesheet/' . (int)$r->id) . ($worker_qs !== '' ? '?' . $worker_qs : '');
              $xlsx_url  = site_url('team/timesheet/' . (int)$r->id) . '?' . ($worker_qs !== '' ? $worker_qs . '&' : '') . 'format=xlsx';
              $json_url  = site_url('team/sheet_json/' . (int)$r->id) . ($worker_qs !== '' ? '?' . $worker_qs : '');
              $prof_url  = site_url('team/view/' . (int)$r->id);

              $rate_cls = '';
              if ($r->pct !== NULL) {
                  $rate_cls = ($r->pct >= 90) ? 'ts-rate-good' : (($r->pct >= 75) ? 'ts-rate-warn' : 'ts-rate-bad');
              }
              $site_title = 'Assigned profile site';
              if ($r->site_source === 'qr_period' || $r->site_source === 'qr_recent') {
                  $site_title = 'Derived from QR check-in history';
              } elseif ($r->site_source === 'override') {
                  $site_title = 'Filtered site view';
              }
          ?>
          <tr data-worker-row="<?= (int)$r->id ?>"
              data-json-url="<?= htmlspecialchars($json_url, ENT_QUOTES, 'UTF-8') ?>"
              data-pdf-url="<?= htmlspecialchars($pdf_url, ENT_QUOTES, 'UTF-8') ?>"
              data-xlsx-url="<?= htmlspecialchars($xlsx_url, ENT_QUOTES, 'UTF-8') ?>"
              data-profile-url="<?= htmlspecialchars($prof_url, ENT_QUOTES, 'UTF-8') ?>"
              data-worker-name="<?= htmlspecialchars($r->full_name, ENT_QUOTES, 'UTF-8') ?>">

            <?php if ($can_batch): ?>
              <td class="ts-chk-cell no-print">
                <input type="checkbox" class="ts-chk ts-row-chk" value="<?= (int)$r->id ?>"
                       aria-label="Select <?= htmlspecialchars($r->full_name, ENT_QUOTES, 'UTF-8') ?>">
              </td>
            <?php endif; ?>

            <td>
              <div class="ts-worker">
                <span class="ts-avatar" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
                <div class="ts-worker-info">
                  <button type="button" class="ts-worker-name js-open-sheet" data-uid="<?= (int)$r->id ?>">
                    <?= htmlspecialchars($r->full_name, ENT_QUOTES, 'UTF-8') ?>
                  </button>
                  <div class="ts-worker-meta">
                    <span class="ts-emp-code"><?= htmlspecialchars($r->employee_id, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($r->position !== ''): ?>
                      <span aria-hidden="true">·</span>
                      <span><?= htmlspecialchars($r->position, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php elseif ($r->role_name !== ''): ?>
                      <span aria-hidden="true">·</span>
                      <span><?= htmlspecialchars(ucfirst($r->role_name), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </td>

            <td>
              <?php if ($r->site !== ''): ?>
                <span class="ts-site-badge" title="<?= htmlspecialchars($site_title, ENT_QUOTES, 'UTF-8') ?>">
                  <span class="ts-site-dot <?= ($r->site_source === 'qr_period' || $r->site_source === 'qr_recent') ? 'is-qr' : '' ?>" aria-hidden="true"></span>
                  <?= htmlspecialchars($r->site, ENT_QUOTES, 'UTF-8') ?>
                </span>
              <?php else: ?>
                <span class="ts-muted-zero">—</span>
              <?php endif; ?>
            </td>

            <td class="ts-num">
              <?php if ((int)$r->present > 0): ?>
                <span class="ts-val-strong"><?= (int)$r->present ?></span>
              <?php else: ?>
                <span class="ts-muted-zero">0</span>
              <?php endif; ?>
            </td>

            <td class="ts-num">
              <?php if ((int)$r->absent > 0): ?>
                <span class="badge badge-red mono"><?= (int)$r->absent ?></span>
              <?php else: ?>
                <span class="ts-muted-zero">0</span>
              <?php endif; ?>
            </td>

            <td class="ts-num">
              <?php if ((int)$r->leave > 0): ?>
                <span class="badge badge-amber mono"><?= (int)$r->leave ?></span>
              <?php else: ?>
                <span class="ts-muted-zero">0</span>
              <?php endif; ?>
            </td>

            <td class="ts-num">
              <?php if ((int)$r->rest > 0 || (int)$r->holiday > 0): ?>
                <span class="mono" style="font-size:12px;color:var(--t2);">
                  <?= (int)$r->rest ?><?php if ((int)$r->holiday > 0): ?><span style="color:var(--blue);">+<?= (int)$r->holiday ?>H</span><?php endif; ?>
                </span>
              <?php else: ?>
                <span class="ts-muted-zero">0</span>
              <?php endif; ?>
            </td>

            <td class="ts-num">
              <?php if ((float)$r->hours_worked > 0): ?>
                <span class="ts-val-strong"><?= number_format((float)$r->hours_worked, 1) ?>h</span>
              <?php elseif ((int)$r->missing_checkout > 0): ?>
                <span class="mono" style="font-size:12px;color:#b45309;">Open</span>
              <?php else: ?>
                <span class="ts-muted-zero">—</span>
              <?php endif; ?>
              <?php if ((int)$r->missing_checkout > 0): ?>
                <span class="ts-warn-dot" title="<?= (int)$r->missing_checkout ?> shift(s) missing check-out time"></span>
              <?php endif; ?>
            </td>

            <td class="ts-num">
              <?php if ((int)$r->total > 0): ?>
                <span class="mono" style="font-size:12.5px;color:var(--t2);"><?= (int)$r->total ?></span>
              <?php else: ?>
                <span class="ts-muted-zero">0</span>
              <?php endif; ?>
            </td>

            <td class="ts-num">
              <?php if ($r->pct === NULL): ?>
                <span class="ts-no-records" title="No working-day attendance entries recorded in this period">No records</span>
              <?php else: ?>
                <div class="ts-rate-wrap <?= $rate_cls ?>">
                  <span class="ts-rate-pct"><?= (int)$r->pct ?>%</span>
                  <span class="ts-rate-bar" aria-hidden="true">
                    <span class="ts-rate-fill" style="width:<?= max(4, min(100, (int)$r->pct)) ?>%"></span>
                  </span>
                </div>
              <?php endif; ?>
            </td>

            <td class="ts-num no-print">
              <?php if ($can_row_export): ?>
                <div class="ts-row-actions">
                  <button type="button"
                          class="ts-act-btn is-primary js-open-sheet"
                          data-uid="<?= (int)$r->id ?>"
                          title="Inspect weekly AML attendance sheet in drawer">
                    Sheet
                  </button>
                  <a href="<?= htmlspecialchars($pdf_url, ENT_QUOTES, 'UTF-8') ?>"
                     target="_blank" rel="noopener"
                     class="ts-act-btn"
                     title="Open official AML A4 PDF sheet"
                     aria-label="Open PDF sheet for <?= htmlspecialchars($r->full_name, ENT_QUOTES, 'UTF-8') ?>">
                    PDF
                  </a>
                  <div class="ts-more-wrap">
                    <button type="button"
                            class="ts-act-btn js-more-toggle"
                            aria-haspopup="menu"
                            aria-expanded="false"
                            aria-label="More actions for <?= htmlspecialchars($r->full_name, ENT_QUOTES, 'UTF-8') ?>">
                      &bull;&bull;&bull;
                    </button>
                    <div class="ts-more-menu" role="menu" hidden>
                      <button type="button" role="menuitem" class="ts-menu-item js-open-sheet" data-uid="<?= (int)$r->id ?>">
                        Inspect AML Sheet
                      </button>
                      <a role="menuitem" href="<?= htmlspecialchars($pdf_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="ts-menu-item">
                        Download PDF (.pdf)
                      </a>
                      <a role="menuitem" href="<?= htmlspecialchars($xlsx_url, ENT_QUOTES, 'UTF-8') ?>" class="ts-menu-item">
                        Download Excel (.xlsx)
                      </a>
                      <div class="ts-menu-sep" role="separator"></div>
                      <a role="menuitem" href="<?= htmlspecialchars($prof_url, ENT_QUOTES, 'UTF-8') ?>" class="ts-menu-item">
                        View Staff Profile
                      </a>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- 2. Responsive Card View -->
    <div class="ts-cards-container">
      <div class="ts-cards-sortbar no-print">
        <span style="font-size:12px;color:var(--t2);">Card layout · Sorted by <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $sort_by)), ENT_QUOTES, 'UTF-8') ?></strong> (<?= strtoupper($sort_dir) ?>)</span>
        <div style="display:inline-flex;align-items:center;gap:6px;">
          <label for="tsCardSort" style="font-size:11.5px;color:var(--t3);margin:0;">Sort:</label>
          <select id="tsCardSort" class="ts-select" style="height:28px;font-size:12px;">
            <?php
            $card_sorts = array(
                'name:asc'        => 'Name (A–Z)',
                'name:desc'       => 'Name (Z–A)',
                'pct:desc'        => 'Attendance % (High–Low)',
                'pct:asc'         => 'Attendance % (Low–High)',
                'hours:desc'      => 'Hours Worked (Most)',
                'absent:desc'     => 'Absences (Most)',
                'present:desc'    => 'Present Days (Most)',
                'site:asc'        => 'Site (A–Z)',
                'employee_id:asc' => 'Employee ID',
            );
            $cur_pair = $sort_by . ':' . $sort_dir;
            foreach ($card_sorts as $pair => $lbl):
                list($s_col, $s_d) = explode(':', $pair);
            ?>
              <option value="<?= htmlspecialchars($ts_url('team/report', array('sort' => $s_col, 'dir' => $s_d, 'view' => 'cards', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
                      <?= $cur_pair === $pair ? 'selected' : '' ?>>
                <?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="ts-cards-grid">
        <?php foreach ($report as $r):
            $initials = strtoupper(substr($r->first_name, 0, 1) . substr($r->last_name, 0, 1));
            $pdf_url  = site_url('team/timesheet/' . (int)$r->id) . ($worker_qs !== '' ? '?' . $worker_qs : '');
            $xlsx_url = site_url('team/timesheet/' . (int)$r->id) . '?' . ($worker_qs !== '' ? $worker_qs . '&' : '') . 'format=xlsx';
            $badge_cls = 'badge-gray';
            if ($r->pct !== NULL) {
                $badge_cls = ($r->pct >= 90) ? 'badge-green' : (($r->pct >= 75) ? 'badge-amber' : 'badge-red');
            }
        ?>
          <article class="ts-card-item">
            <div class="ts-card-top">
              <div class="ts-worker" style="min-width:0;">
                <span class="ts-avatar" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
                <div class="ts-worker-info">
                  <button type="button" class="ts-worker-name js-open-sheet" data-uid="<?= (int)$r->id ?>">
                    <?= htmlspecialchars($r->full_name, ENT_QUOTES, 'UTF-8') ?>
                  </button>
                  <div class="ts-worker-meta">
                    <span class="ts-emp-code"><?= htmlspecialchars($r->employee_id, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($r->position !== ''): ?>
                      <span>· <?= htmlspecialchars($r->position, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php if ($r->pct === NULL): ?>
                <span class="ts-no-records">No records</span>
              <?php else: ?>
                <span class="badge <?= $badge_cls ?> mono"><?= (int)$r->pct ?>%</span>
              <?php endif; ?>
            </div>

            <div class="ts-card-metrics">
              <div class="ts-cm-box">
                <span class="ts-cm-lbl">Present</span>
                <span class="ts-cm-val"><?= (int)$r->present ?>d</span>
              </div>
              <div class="ts-cm-box">
                <span class="ts-cm-lbl">Absent</span>
                <span class="ts-cm-val" style="<?= (int)$r->absent > 0 ? 'color:#d9261c;' : '' ?>"><?= (int)$r->absent ?>d</span>
              </div>
              <div class="ts-cm-box">
                <span class="ts-cm-lbl">Leave</span>
                <span class="ts-cm-val"><?= (int)$r->leave ?>d</span>
              </div>
              <div class="ts-cm-box">
                <span class="ts-cm-lbl">Hours</span>
                <span class="ts-cm-val"><?= (float)$r->hours_worked > 0 ? number_format((float)$r->hours_worked, 1) . 'h' : '—' ?></span>
              </div>
            </div>

            <div class="ts-card-foot">
              <div>
                <?php if ($r->site !== ''): ?>
                  <span class="ts-site-badge"><?= htmlspecialchars($r->site, ENT_QUOTES, 'UTF-8') ?></span>
                <?php else: ?>
                  <span style="font-size:11.5px;color:var(--t3);">No site assigned</span>
                <?php endif; ?>
              </div>
              <div class="ts-row-actions">
                <button type="button" class="ts-act-btn is-primary js-open-sheet" data-uid="<?= (int)$r->id ?>">Inspect</button>
                <a href="<?= htmlspecialchars($pdf_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="ts-act-btn">PDF</a>
                <a href="<?= htmlspecialchars($xlsx_url, ENT_QUOTES, 'UTF-8') ?>" class="ts-act-btn">XLSX</a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>

  <?php endif; ?>

  <!-- Pagination & Page-Size Footer -->
  <div class="ts-footer no-print">
    <div>
      <?php if ($total_filtered > 0): ?>
        Showing <strong class="mono"><?= (int)$showing_from ?>–<?= (int)$showing_to ?></strong>
        of <strong class="mono"><?= (int)$total_filtered ?></strong> staff
        <?php if ($total_filtered < $total_scope): ?>
          <span>(filtered from <?= (int)$total_scope ?>)</span>
        <?php endif; ?>
      <?php else: ?>
        0 matching staff
      <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
      <nav class="ts-pages" aria-label="Attendance roster pagination">
        <a class="ts-page-link <?= $page <= 1 ? 'is-disabled' : '' ?>"
           href="<?= htmlspecialchars($ts_url('team/report', array('page' => max(1, $page - 1))), ENT_QUOTES, 'UTF-8') ?>">
          Prev
        </a>
        <?php
        $win_start = max(1, $page - 2);
        $win_end   = min($total_pages, $page + 2);
        if ($win_start > 1): ?>
          <a class="ts-page-link" href="<?= htmlspecialchars($ts_url('team/report', array('page' => 1)), ENT_QUOTES, 'UTF-8') ?>">1</a>
          <?php if ($win_start > 2): ?><span style="padding:0 4px;color:var(--t3);">&hellip;</span><?php endif; ?>
        <?php endif;
        for ($p = $win_start; $p <= $win_end; $p++): ?>
          <a class="ts-page-link <?= $p === $page ? 'is-current' : '' ?>"
             <?= $p === $page ? 'aria-current="page"' : '' ?>
             href="<?= htmlspecialchars($ts_url('team/report', array('page' => $p)), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
        <?php endfor;
        if ($win_end < $total_pages): ?>
          <?php if ($win_end < $total_pages - 1): ?><span style="padding:0 4px;color:var(--t3);">&hellip;</span><?php endif; ?>
          <a class="ts-page-link" href="<?= htmlspecialchars($ts_url('team/report', array('page' => $total_pages)), ENT_QUOTES, 'UTF-8') ?>"><?= $total_pages ?></a>
        <?php endif; ?>
        <a class="ts-page-link <?= $page >= $total_pages ? 'is-disabled' : '' ?>"
           href="<?= htmlspecialchars($ts_url('team/report', array('page' => min($total_pages, $page + 1))), ENT_QUOTES, 'UTF-8') ?>">
          Next
        </a>
      </nav>
    <?php endif; ?>

    <div style="display:inline-flex;align-items:center;gap:6px;">
      <label for="tsPerPage" style="font-size:12px;color:var(--t3);margin:0;">Rows:</label>
      <select id="tsPerPage" class="ts-select" style="height:28px;font-size:12px;">
        <?php foreach (array(10, 25, 50, 100) as $pp): ?>
          <option value="<?= htmlspecialchars($ts_url('team/report', array('per_page' => $pp, 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
                  <?= $per_page === $pp ? 'selected' : '' ?>>
            <?= $pp ?> / page
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>

<!-- Right-Side Slide-Over Drawer: AML Individual Attendance Sheet Inspector -->
<div class="ts-drawer-backdrop no-print" id="tsDrawerBackdrop" hidden></div>
<aside class="ts-drawer no-print" id="tsDrawer" role="dialog" aria-modal="true" aria-labelledby="tsDrawerTitle" hidden>
  <div class="ts-drawer-head">
    <div>
      <div class="ts-drawer-title" id="tsDrawerTitle">Individual Attendance Sheet</div>
      <div class="ts-drawer-sub" id="tsDrawerSub">Loading worker details…</div>
    </div>
    <div class="ts-drawer-nav">
      <button type="button" class="ts-act-btn" id="tsDrawerPrev" title="Previous staff member (Left arrow)" aria-label="Previous staff member">&larr;</button>
      <button type="button" class="ts-act-btn" id="tsDrawerNext" title="Next staff member (Right arrow)" aria-label="Next staff member">&rarr;</button>
      <button type="button" class="ts-act-btn" id="tsDrawerClose" title="Close inspector (Esc)" aria-label="Close inspector">&times;</button>
    </div>
  </div>

  <div class="ts-drawer-body" id="tsDrawerBody">
    <p style="color:var(--t3);font-size:13px;">Loading attendance sheet…</p>
  </div>

  <div class="ts-drawer-foot">
    <a href="#" id="tsDrawerProfile" class="btn btn-secondary btn-sm">Staff Profile</a>
    <div style="display:inline-flex;gap:8px;flex-wrap:wrap;">
      <a href="#" id="tsDrawerXlsx" class="btn btn-secondary btn-sm">Download Excel (.xlsx)</a>
      <a href="#" id="tsDrawerPdf" target="_blank" rel="noopener" class="btn btn-primary btn-sm">Open Official PDF</a>
    </div>
  </div>
</aside>

<script>
(function () {
  var shell      = document.getElementById('tsShell');
  var form       = document.getElementById('tsFilterForm');
  var periodSel  = document.getElementById('tsPeriod');
  var customWrap = document.getElementById('tsCustomDates');
  var viewHidden = document.getElementById('tsViewHidden');
  var searchInp  = document.getElementById('tsSearchInput');
  var perPageSel = document.getElementById('tsPerPage');
  var cardSort   = document.getElementById('tsCardSort');

  // 1. Period custom-date visibility + smart auto-submit
  if (periodSel && customWrap) {
    periodSel.addEventListener('change', function () {
      var isCustom = (periodSel.value === 'custom');
      customWrap.hidden = !isCustom;
      if (!isCustom && form) form.submit();
    });
  }
  var autoInputs = document.querySelectorAll('.ts-autosubmit');
  for (var i = 0; i < autoInputs.length; i++) {
    autoInputs[i].addEventListener('change', function () {
      if (form) form.submit();
    });
  }
  if (perPageSel) {
    perPageSel.addEventListener('change', function () {
      if (perPageSel.value) window.location.href = perPageSel.value;
    });
  }
  if (cardSort) {
    cardSort.addEventListener('change', function () {
      if (cardSort.value) window.location.href = cardSort.value;
    });
  }

  // 2. Instant Table / Cards View Switcher (updates URL query + hidden input without reload)
  var viewBtns = document.querySelectorAll('[data-set-view]');
  for (var v = 0; v < viewBtns.length; v++) {
    viewBtns[v].addEventListener('click', function (ev) {
      var mode = this.getAttribute('data-set-view');
      if (!shell || !mode) return;
      ev.preventDefault();
      shell.classList.remove('ts-mode-table', 'ts-mode-cards');
      shell.classList.add('ts-mode-' + mode);
      if (mode === 'table') shell.classList.add('ts-user-forced-table');
      else shell.classList.remove('ts-user-forced-table');
      if (viewHidden) viewHidden.value = mode;
      for (var j = 0; j < viewBtns.length; j++) {
        var on = (viewBtns[j].getAttribute('data-set-view') === mode);
        viewBtns[j].classList.toggle('is-active', on);
        viewBtns[j].setAttribute('aria-pressed', on ? 'true' : 'false');
      }
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', this.href);
      }
    });
  }

  // 3. Multi-Select Checkboxes & Contextual Batch Export Bar
  var checkAll    = document.getElementById('tsCheckAll');
  var rowChks     = document.querySelectorAll('.ts-row-chk');
  var batchBar    = document.getElementById('tsBatchBar');
  var selCountEl  = document.getElementById('tsSelectedCount');
  var batchPdf    = document.getElementById('tsBatchPdf');
  var batchXlsx   = document.getElementById('tsBatchXlsx');
  var batchCsv    = document.getElementById('tsBatchCsv');
  var batchClear  = document.getElementById('tsBatchClear');
  var baseExportQ = <?= json_encode($export_qs) ?>;
  var batchBase   = <?= json_encode(site_url('team/timesheets_batch')) ?>;
  var csvBase     = <?= json_encode(site_url('team/report_csv')) ?>;

  function syncSelection() {
    var ids = [];
    for (var k = 0; k < rowChks.length; k++) {
      var tr = rowChks[k].closest('tr');
      if (rowChks[k].checked) {
        ids.push(rowChks[k].value);
        if (tr) tr.classList.add('is-selected');
      } else if (tr) {
        tr.classList.remove('is-selected');
      }
    }
    if (checkAll && rowChks.length > 0) {
      checkAll.checked = (ids.length === rowChks.length);
      checkAll.indeterminate = (ids.length > 0 && ids.length < rowChks.length);
    }
    if (!batchBar) return;
    if (ids.length === 0) {
      batchBar.hidden = true;
      return;
    }
    batchBar.hidden = false;
    if (selCountEl) selCountEl.textContent = String(ids.length);
    var prefix = baseExportQ ? (baseExportQ + '&') : '';
    var idsParam = 'ids=' + encodeURIComponent(ids.join(','));
    if (batchPdf)  batchPdf.href  = batchBase + '?' + prefix + idsParam;
    if (batchXlsx) batchXlsx.href = batchBase + '?' + prefix + idsParam + '&format=xlsx';
    if (batchCsv)  batchCsv.href  = csvBase   + '?' + prefix + idsParam;
  }

  if (checkAll) {
    checkAll.addEventListener('change', function () {
      for (var k = 0; k < rowChks.length; k++) rowChks[k].checked = checkAll.checked;
      syncSelection();
    });
  }
  for (var c = 0; c < rowChks.length; c++) {
    rowChks[c].addEventListener('change', syncSelection);
  }
  if (batchClear) {
    batchClear.addEventListener('click', function () {
      for (var k = 0; k < rowChks.length; k++) rowChks[k].checked = false;
      syncSelection();
    });
  }

  // 4. Accessible Row Overflow Menu ("...")
  function closeAllMenus() {
    var openMenus = document.querySelectorAll('.ts-more-menu:not([hidden])');
    for (var m = 0; m < openMenus.length; m++) {
      openMenus[m].hidden = true;
      var btn = openMenus[m].parentElement.querySelector('.js-more-toggle');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    }
  }
  var moreBtns = document.querySelectorAll('.js-more-toggle');
  for (var b = 0; b < moreBtns.length; b++) {
    moreBtns[b].addEventListener('click', function (ev) {
      ev.stopPropagation();
      var menu = this.nextElementSibling;
      var wasOpen = menu && !menu.hidden;
      closeAllMenus();
      if (menu && !wasOpen) {
        menu.hidden = false;
        this.setAttribute('aria-expanded', 'true');
      }
    });
  }
  document.addEventListener('click', closeAllMenus);

  // 5. Right-Side Slide-Over AML Sheet Inspector Drawer
  var drawer      = document.getElementById('tsDrawer');
  var backdrop    = document.getElementById('tsDrawerBackdrop');
  var dTitle      = document.getElementById('tsDrawerTitle');
  var dSub        = document.getElementById('tsDrawerSub');
  var dBody       = document.getElementById('tsDrawerBody');
  var dClose      = document.getElementById('tsDrawerClose');
  var dPrev       = document.getElementById('tsDrawerPrev');
  var dNext       = document.getElementById('tsDrawerNext');
  var dPdf        = document.getElementById('tsDrawerPdf');
  var dXlsx       = document.getElementById('tsDrawerXlsx');
  var dProfile    = document.getElementById('tsDrawerProfile');
  var sheetCache  = {};
  var orderedUids = [];
  var rowMap      = {};
  var activeUid   = null;
  var lastTrigger = null;

  var allRows = document.querySelectorAll('tr[data-worker-row]');
  for (var r = 0; r < allRows.length; r++) {
    var uid = String(allRows[r].getAttribute('data-worker-row'));
    orderedUids.push(uid);
    rowMap[uid] = {
      name:    allRows[r].getAttribute('data-worker-name') || '',
      jsonUrl: allRows[r].getAttribute('data-json-url') || '',
      pdfUrl:  allRows[r].getAttribute('data-pdf-url') || '#',
      xlsxUrl: allRows[r].getAttribute('data-xlsx-url') || '#',
      profUrl: allRows[r].getAttribute('data-profile-url') || '#'
    };
  }

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function renderSheetPayload(data) {
    var srcLabel = data.site ? String(data.site) : 'No site assigned';
    if (data.site && (data.site_source === 'qr_period' || data.site_source === 'qr_recent')) {
      srcLabel += ' (from QR check-in)';
    }
    dTitle.textContent = data.full_name;
    dSub.textContent = data.employee_id + (data.position ? ' · ' + data.position : '') + ' · ' + srcLabel + ' · ' + data.period_label;

    var outBadge = data.missing_checkout > 0
      ? '<span style="color:#b45309;">' + Number(data.missing_checkout) + ' open</span>'
      : '<span style="color:#1a8f3c;">Complete</span>';

    var html = '<div class="ts-drawer-kpis">'
      + '<div class="ts-dkpi"><div class="ts-dkpi-lbl">Days Worked</div><div class="ts-dkpi-val">' + Number(data.total_days) + '</div></div>'
      + '<div class="ts-dkpi"><div class="ts-dkpi-lbl">Total Hours</div><div class="ts-dkpi-val">' + escHtml(data.total_hours || '0') + 'h</div></div>'
      + '<div class="ts-dkpi"><div class="ts-dkpi-lbl">Check-Outs</div><div class="ts-dkpi-val">' + outBadge + '</div></div>'
      + '</div>';

    var segs = data.segments || [];
    for (var s = 0; s < segs.length; s++) {
      var seg = segs[s];
      html += '<div class="ts-seg-card">'
        + '<div class="ts-seg-head"><span>' + escHtml(seg.month_heading) + '</span>'
        + '<span>' + Number(seg.days_worked) + ' days · ' + escHtml(seg.total_hours || '0') + 'h</span></div>'
        + '<table class="ts-wk-table"><thead><tr>'
        + '<th>Date</th><th>Day</th><th>Time In</th><th>Time Out</th><th style="text-align:right;">Hours</th><th>Observation</th>'
        + '</tr></thead><tbody>';

      var weeks = seg.weeks || [];
      for (var w = 0; w < weeks.length; w++) {
        var wk = weeks[w];
        var wkLabel = wk.label || ('WEEK ' + (w + 1));
        var wkDaysWorked = 0;
        html += '<tr class="ts-wk-band"><td colspan="6">' + escHtml(wkLabel) + '</td></tr>';
        var days = wk.days || [];
        for (var d = 0; d < days.length; d++) {
          var dy = days[d];
          if (dy.worked) wkDaysWorked++;
          var openShift = (dy.time_in && !dy.time_out && dy.status === 'Present');
          var rowStyle = dy.is_sunday ? 'background:#fafafa;color:var(--t3);' : '';
          var obsHtml = escHtml(dy.observation || '');
          if (openShift) {
            obsHtml = '<span class="badge badge-amber">Missing check-out</span>' + (obsHtml ? ' ' + obsHtml : '');
          } else if (dy.status === 'Absent') {
            obsHtml = '<span class="badge badge-red">' + (obsHtml || 'ABSENT') + '</span>';
          } else if (dy.status === 'Leave') {
            obsHtml = '<span class="badge badge-amber">' + (obsHtml || 'LEAVE') + '</span>';
          }
          html += '<tr style="' + rowStyle + '">'
            + '<td class="mono">' + escHtml(dy.date_label) + '</td>'
            + '<td>' + escHtml(dy.day_name) + '</td>'
            + '<td class="mono">' + (dy.time_in ? escHtml(dy.time_in) : '<span style="color:#c0c0c8;">—</span>') + '</td>'
            + '<td class="mono">' + (dy.time_out ? escHtml(dy.time_out) : '<span style="color:#c0c0c8;">—</span>') + '</td>'
            + '<td class="mono" style="text-align:right;font-weight:600;">' + (dy.hours_label ? escHtml(dy.hours_label) : '') + '</td>'
            + '<td>' + obsHtml + '</td>'
            + '</tr>';
        }
        html += '<tr class="ts-wk-sub">'
          + '<td colspan="4">Weekly Total — ' + wkDaysWorked + ' day(s) worked</td>'
          + '<td class="mono" style="text-align:right;">' + escHtml(wk.hours_label || '0') + '</td>'
          + '<td></td></tr>';
      }
      html += '</tbody></table></div>';
    }

    if (data.signatories) {
      var prepTitle = data.signatories.prepared_title || 'Prepared by';
      var prepName  = data.signatories.prepared_by || '—';
      var apprTitle = data.signatories.approved_title || 'Approved by';
      var apprName  = data.signatories.approved_by || '—';
      html += '<div style="font-size:11.5px;color:var(--t3);display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-top:4px;">'
        + '<span>' + escHtml(prepTitle) + ': <strong style="color:var(--t1);">' + escHtml(prepName) + '</strong></span>'
        + '<span>' + escHtml(apprTitle) + ': <strong style="color:var(--t1);">' + escHtml(apprName) + '</strong></span>'
        + '</div>';
    }
    dBody.innerHTML = html;
  }

  function openSheet(uid, triggerEl) {
    var key = String(uid);
    var meta = rowMap[key];
    if (!meta || !drawer) return;
    closeAllMenus();
    activeUid = key;
    if (triggerEl) lastTrigger = triggerEl;

    var idx = orderedUids.indexOf(key);
    if (dPrev) dPrev.disabled = (idx <= 0);
    if (dNext) dNext.disabled = (idx < 0 || idx >= orderedUids.length - 1);

    dTitle.textContent = meta.name || 'Individual Attendance Sheet';
    dSub.textContent   = 'Loading AML attendance breakdown…';
    dPdf.href          = meta.pdfUrl;
    dXlsx.href         = meta.xlsxUrl;
    dProfile.href      = meta.profUrl;

    backdrop.hidden = false;
    drawer.hidden   = false;
    dClose.focus();

    if (sheetCache[meta.jsonUrl]) {
      renderSheetPayload(sheetCache[meta.jsonUrl]);
      return;
    }
    dBody.innerHTML = '<p style="color:var(--t3);font-size:13px;">Loading weekly check-in / check-out records…</p>';
    fetch(meta.jsonUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (activeUid !== key) return;
        if (!json || !json.ok) {
          dBody.innerHTML = '<div class="alert alert-danger">' + escHtml((json && json.error) || 'Unable to load attendance sheet.') + '</div>';
          return;
        }
        sheetCache[meta.jsonUrl] = json;
        renderSheetPayload(json);
      })
      .catch(function () {
        if (activeUid !== key) return;
        dBody.innerHTML = '<div class="alert alert-danger">Network error while loading sheet preview. You can still open the PDF directly below.</div>';
      });
  }

  function closeDrawer() {
    if (!drawer || drawer.hidden) return;
    drawer.hidden = true;
    backdrop.hidden = true;
    activeUid = null;
    if (lastTrigger && typeof lastTrigger.focus === 'function') {
      lastTrigger.focus();
    }
  }

  var openTriggers = document.querySelectorAll('.js-open-sheet');
  for (var o = 0; o < openTriggers.length; o++) {
    openTriggers[o].addEventListener('click', function () {
      openSheet(this.getAttribute('data-uid'), this);
    });
  }
  if (dClose)    dClose.addEventListener('click', closeDrawer);
  if (backdrop)  backdrop.addEventListener('click', closeDrawer);
  if (dPrev) {
    dPrev.addEventListener('click', function () {
      var idx = orderedUids.indexOf(activeUid);
      if (idx > 0) openSheet(orderedUids[idx - 1], null);
    });
  }
  if (dNext) {
    dNext.addEventListener('click', function () {
      var idx = orderedUids.indexOf(activeUid);
      if (idx >= 0 && idx < orderedUids.length - 1) openSheet(orderedUids[idx + 1], null);
    });
  }

  // 6. Global Keyboard Shortcuts ('/' focuses search; Esc closes menu/drawer; Left/Right navigate drawer)
  document.addEventListener('keydown', function (ev) {
    var tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
    var typing = (tag === 'input' || tag === 'textarea' || tag === 'select' || (ev.target && ev.target.isContentEditable));

    if (ev.key === '/' && !typing && searchInp && (!drawer || drawer.hidden)) {
      ev.preventDefault();
      searchInp.focus();
      searchInp.select();
      return;
    }
    if (ev.key === 'Escape') {
      closeAllMenus();
      if (drawer && !drawer.hidden) {
        ev.preventDefault();
        closeDrawer();
      }
      return;
    }
    if (drawer && !drawer.hidden && !typing) {
      var idx = orderedUids.indexOf(activeUid);
      if (ev.key === 'ArrowLeft' && idx > 0) {
        ev.preventDefault();
        openSheet(orderedUids[idx - 1], null);
      } else if (ev.key === 'ArrowRight' && idx >= 0 && idx < orderedUids.length - 1) {
        ev.preventDefault();
        openSheet(orderedUids[idx + 1], null);
      }
    }
  });
})();
</script>
