<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * URL builder preserving active Work Orders filters, sorting, pagination,
 * and view mode cleanly across tabs, column headers, and page links.
 */
$base_params = array(
    'tab'       => $active_tab,
    'status'    => $status_exact,
    'type'      => $type_filter,
    'priority'  => $priority_filter,
    'equipment' => ($equip_filter > 0) ? (string)$equip_filter : '',
    'tech'      => ($tech_filter > 0) ? (string)$tech_filter : '',
    'q'         => $search_query,
    'sort'      => $sort_by,
    'dir'       => $sort_dir,
    'per_page'  => (string)$per_page,
    'view'      => $view_mode,
);

$wo_url = function ($path, array $overrides = array()) use ($base_params) {
    $merged = array_merge($base_params, $overrides);
    $clean  = array();
    $defaults = array(
        'tab'      => 'all',
        'sort'     => 'created',
        'dir'      => 'desc',
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

// Export query string synced cleanly with active filters & sort order
$export_params = array();
if ($active_tab !== 'all')    $export_params['tab']       = $active_tab;
if ($status_exact !== '')     $export_params['status']    = $status_exact;
if ($type_filter !== '')      $export_params['type']      = $type_filter;
if ($priority_filter !== '')  $export_params['priority']  = $priority_filter;
if ($equip_filter > 0)        $export_params['equipment'] = (string)$equip_filter;
if ($tech_filter > 0)         $export_params['tech']      = (string)$tech_filter;
if ($search_query !== '')     $export_params['q']         = $search_query;
if ($sort_by !== 'created')   $export_params['sort']      = $sort_by;
if ($sort_dir !== 'desc')     $export_params['dir']       = $sort_dir;
$export_qs = http_build_query($export_params);

$sort_th = function ($col_key, $label, $align = 'left', $default_dir = 'asc') use ($sort_by, $sort_dir, $wo_url) {
    $is_active = ($sort_by === $col_key);
    $next_dir  = $is_active ? (($sort_dir === 'asc') ? 'desc' : 'asc') : $default_dir;
    $aria_sort = $is_active ? (($sort_dir === 'asc') ? 'ascending' : 'descending') : 'none';
    $href      = $wo_url('workorders', array('sort' => $col_key, 'dir' => $next_dir, 'page' => 1));
    $cls       = 'wo-th-sort' . ($align === 'right' ? ' is-right' : '') . ($is_active ? ' is-sorted' : '');

    $icon = '<svg class="wo-sort-ico" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">';
    if ($is_active && $sort_dir === 'asc') {
        $icon .= '<path d="M6 2.5L9.2 6.2H2.8L6 2.5Z" fill="currentColor"/><path d="M6 9.5L2.8 7.2H9.2L6 9.5Z" fill="currentColor" opacity="0.22"/>';
    } elseif ($is_active && $sort_dir === 'desc') {
        $icon .= '<path d="M6 2.5L9.2 4.8H2.8L6 2.5Z" fill="currentColor" opacity="0.22"/><path d="M6 9.5L2.8 5.8H9.2L6 9.5Z" fill="currentColor"/>';
    } else {
        $icon .= '<path d="M6 2.5L8.8 5.3H3.2L6 2.5ZM6 9.5L3.2 6.7H8.8L6 9.5Z" fill="currentColor" opacity="0.35"/>';
    }
    $icon .= '</svg>';

    return '<th scope="col" aria-sort="' . $aria_sort . '" class="' . ($align === 'right' ? 'wo-num' : '') . '">'
         . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . $cls . '">'
         . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>' . $icon
         . '</a></th>';
};

$equip_lookup = array();
foreach ($equipment_list as $eq) {
    $equip_lookup[(int)$eq->id] = (string)$eq->registration_no;
}
$tech_lookup = array();
foreach ($tech_options as $tc_opt) {
    $tech_lookup[(int)$tc_opt->id] = trim((string)$tc_opt->first_name . ' ' . (string)$tc_opt->last_name);
}

$tab_defs = array(
    'all'         => array('label' => 'All',            'count' => (int)$tab_counts['all']),
    'active'      => array('label' => 'Active Queue',   'count' => (int)$tab_counts['active']),
    'open'        => array('label' => 'Open',           'count' => (int)$tab_counts['open']),
    'in_progress' => array('label' => 'In Progress',    'count' => (int)$tab_counts['in_progress']),
    'on_hold'     => array('label' => 'On Hold',        'count' => (int)$tab_counts['on_hold']),
    'completed'   => array('label' => 'Verify & Close', 'count' => (int)$tab_counts['completed']),
    'closed'      => array('label' => 'Closed',         'count' => (int)$tab_counts['closed']),
);

$prio_badges = array(
    'Critical' => 'b-critical',
    'High'     => 'b-high',
    'Medium'   => 'b-medium',
    'Low'      => 'b-low',
);
$status_badges = array(
    'Open'          => 'b-open',
    'In Progress'   => 'b-progress',
    'Waiting Parts' => 'b-waiting',
    'Waiting Tools' => 'b-waiting',
    'Completed'     => 'b-completed',
    'Closed'        => 'b-closed',
);

$has_active_filters = (
    $active_tab !== 'all' ||
    $status_exact !== '' ||
    $type_filter !== '' ||
    $priority_filter !== '' ||
    $equip_filter > 0 ||
    $tech_filter > 0 ||
    $search_query !== ''
);
$clean_reset_url = site_url('workorders') . ($view_mode !== 'table' ? ('?view=' . rawurlencode($view_mode)) : '');
?>

<style>
/* ═══════════════════════════════════════════════════════════════════════════
   WMSv2 Enterprise Work Orders & Maintenance Dispatch Table (`/workorders`)
   Built strictly on shared Apple-HIG CSS custom properties in header.php
   ═══════════════════════════════════════════════════════════════════════════ */
.wo-print-banner { display: none; }

.wo-head {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; flex-wrap: wrap; margin-bottom: 18px;
}
.wo-head-title h1 {
  font-size: 23px; font-weight: 700; letter-spacing: -0.025em;
  color: var(--t1); line-height: 1.2; margin: 0;
}
.wo-sub { font-size: 13px; color: var(--t2); margin-top: 3px; }
.wo-head-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.wo-ico { width: 14px; height: 14px; flex-shrink: 0; }

/* Executive Maintenance Queue KPI Strip */
.wo-kpis {
  display: grid; grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px; margin-bottom: 18px;
}
.wo-kpi {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-md); padding: 14px 16px;
  box-shadow: var(--shadow-card);
  display: flex; flex-direction: column; justify-content: space-between;
}
.wo-kpi-lbl {
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--t3); margin-bottom: 6px;
}
.wo-kpi-val {
  font-size: 24px; font-weight: 700; letter-spacing: -0.03em;
  color: var(--t1); line-height: 1.05;
  font-variant-numeric: tabular-nums;
}
.wo-kpi-meta {
  margin-top: 6px; font-size: 11.5px; color: var(--t3);
  display: flex; align-items: center; gap: 5px;
}

/* Main DataTable Card Shell */
.wo-shell {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-lg); box-shadow: var(--shadow-card);
  overflow: visible;
}

/* Scope Filter Bar */
.wo-filter-bar {
  padding: 12px 16px; background: var(--surface-2);
  border-bottom: 1px solid var(--sep);
  border-radius: var(--r-lg) var(--r-lg) 0 0;
  display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap;
}
.wo-field { display: flex; flex-direction: column; gap: 4px; }
.wo-field label {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--t3); margin: 0;
}
.wo-input, .wo-select {
  height: 32px; padding: 0 10px; font-family: inherit; font-size: 12.5px;
  color: var(--t1); background: var(--surface);
  border: 1px solid var(--sep-strong); border-radius: var(--r-xs);
  outline: none; transition: border-color 0.15s, box-shadow 0.15s;
}
.wo-select {
  padding-right: 28px; cursor: pointer;
  appearance: none; -webkit-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236e6e73' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 8px center;
}
.wo-input:focus, .wo-select:focus {
  border-color: var(--blue); box-shadow: 0 0 0 3px var(--blue-fill);
}

/* Workflow Tabs + Search + View Switcher */
.wo-toolbar {
  padding: 10px 16px; border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; background: var(--surface);
}
.wo-tabs { display: inline-flex; align-items: center; gap: 3px; flex-wrap: wrap; }
.wo-tab {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 6px 9px; border-radius: var(--r-xs);
  font-size: 12px; font-weight: 500; color: var(--t2);
  text-decoration: none; border: 1px solid transparent;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.wo-tab:hover { background: var(--gray-fill); color: var(--t1); }
.wo-tab.is-active {
  background: var(--blue-fill); color: var(--blue); font-weight: 600;
  border-color: rgba(0, 113, 227, 0.22);
}
.wo-tab-cnt {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 19px; height: 17px; padding: 0 5px;
  border-radius: 100px; font-size: 10.5px; font-weight: 600;
  background: var(--gray-fill); color: var(--t2);
  font-variant-numeric: tabular-nums;
}
.wo-tab.is-active .wo-tab-cnt { background: var(--blue); color: #ffffff; }

.wo-tools-right {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto;
}
.wo-search-wrap { position: relative; display: inline-flex; align-items: center; }
.wo-search-ico {
  position: absolute; left: 9px; width: 14px; height: 14px;
  color: var(--t3); pointer-events: none;
}
.wo-search-input {
  height: 32px; width: 235px; padding: 0 50px 0 29px;
  font-family: inherit; font-size: 12.5px; color: var(--t1);
  background: var(--surface-2); border: 1px solid var(--sep-strong);
  border-radius: var(--r-xs); outline: none;
  transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
}
.wo-search-input:focus {
  background: var(--surface); border-color: var(--blue);
  box-shadow: 0 0 0 3px var(--blue-fill);
}
.wo-kbd {
  position: absolute; right: 8px; padding: 1px 5px;
  font-size: 10.5px; color: var(--t3);
  background: var(--surface); border: 1px solid var(--sep-strong);
  border-radius: 4px; pointer-events: none; line-height: 1.3;
}
.wo-search-input:focus ~ .wo-kbd { display: none; }
.wo-search-clear {
  position: absolute; right: 6px; width: 20px; height: 20px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 50%; background: var(--gray-fill); color: var(--t2);
  font-size: 13px; text-decoration: none; line-height: 1;
}
.wo-search-clear:hover { background: var(--sep-strong); color: var(--t1); }

/* View Mode Segmented Switcher */
.wo-view-seg {
  display: inline-flex; padding: 2px; background: var(--gray-fill);
  border-radius: var(--r-xs); border: 1px solid var(--sep);
}
.wo-view-btn {
  display: inline-flex; align-items: center; gap: 5px;
  height: 26px; padding: 0 9px; border-radius: 5px; border: none;
  background: transparent; color: var(--t2); font-family: inherit;
  font-size: 12px; font-weight: 500; cursor: pointer; text-decoration: none;
}
.wo-view-btn:hover { color: var(--t1); }
.wo-view-btn.is-active {
  background: var(--surface); color: var(--t1); font-weight: 600;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}

/* Active Filter Chips */
.wo-chips-bar {
  padding: 8px 16px; background: #fbfbfd; border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 12px;
}
.wo-chip {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 2px 9px; border-radius: 100px;
  background: var(--surface); border: 1px solid var(--sep-strong);
  color: var(--t1); font-size: 11.5px; font-weight: 500; text-decoration: none;
}
.wo-chip:hover { border-color: var(--red); color: var(--red); }
.wo-chip-reset { margin-left: auto; font-size: 12px; font-weight: 500; color: var(--blue); text-decoration: none; }
.wo-chip-reset:hover { text-decoration: underline; }

/* Multi-Select Batch Action Bar */
.wo-batch-bar {
  padding: 9px 16px; background: #eef5ff;
  border-bottom: 1px solid rgba(0, 113, 227, 0.24);
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; flex-wrap: wrap;
}
.wo-batch-bar[hidden] { display: none !important; }
.wo-batch-count { font-size: 12.5px; font-weight: 600; color: #004c99; }
.wo-batch-actions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

/* Enterprise Work Orders Table */
.wo-table-wrap { width: 100%; overflow-x: auto; }
.wo-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.wo-table thead th {
  position: sticky; top: 0; z-index: 2;
  background: var(--surface-2); border-bottom: 1px solid var(--sep-strong);
  padding: 9px 10px; text-align: left; white-space: nowrap;
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.035em; color: var(--t3);
}
.wo-table thead th.wo-num { text-align: right; }
.wo-th-sort {
  display: inline-flex; align-items: center; gap: 4px;
  color: var(--t3); text-decoration: none; cursor: pointer;
  user-select: none; transition: color 0.15s;
}
.wo-th-sort.is-right { flex-direction: row-reverse; }
.wo-th-sort:hover { color: var(--t1); }
.wo-th-sort.is-sorted { color: var(--blue); }
.wo-sort-ico { flex-shrink: 0; }

.wo-table tbody tr { border-bottom: 1px solid var(--sep); transition: background 0.12s; }
.wo-table tbody tr:last-child { border-bottom: none; }
.wo-table tbody tr:hover { background: #f8f9fb; }
.wo-table tbody tr.is-selected { background: #f0f6ff; }
.wo-table tbody td { padding: 10px 10px; vertical-align: middle; color: var(--t1); }
.wo-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.wo-chk-cell { width: 32px; padding-left: 14px !important; padding-right: 4px !important; }
.wo-chk { width: 15px; height: 15px; accent-color: var(--blue); cursor: pointer; vertical-align: middle; }

/* Primary WO Identity & Summary Cell */
.wo-id-btn {
  background: none; border: none; padding: 0; margin: 0;
  font-family: 'SF Mono', ui-monospace, Menlo, monospace;
  font-size: 12.5px; font-weight: 700; color: var(--blue);
  cursor: pointer; text-align: left; line-height: 1.25;
}
.wo-id-btn:hover { text-decoration: underline; }
.wo-title-line {
  font-size: 13px; font-weight: 600; color: var(--t1); line-height: 1.3;
  max-width: 245px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.wo-sub-line {
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
  font-size: 11px; color: var(--t3); margin-top: 2px; line-height: 1.2;
}
.wo-live-pill {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 1px 6px; border-radius: 100px;
  background: var(--green-fill); color: var(--green);
  font-size: 10px; font-weight: 600;
}
.wo-hold-line {
  font-size: 10.5px; font-weight: 600; color: var(--amber);
  margin-top: 3px; white-space: nowrap;
}
.wo-overdue-line {
  font-size: 10.5px; font-weight: 600; color: var(--red);
  margin-top: 3px; white-space: nowrap;
}
.wo-muted { color: var(--t4); font-size: 12px; }

/* Compact Row Actions & Overflow Menu */
.wo-row-actions { display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px; }
.wo-act-btn {
  display: inline-flex; align-items: center; gap: 4px;
  height: 27px; padding: 0 8px; border-radius: 6px;
  border: 1px solid var(--sep-strong); background: var(--surface);
  color: var(--t1); font-family: inherit; font-size: 11.5px; font-weight: 500;
  cursor: pointer; text-decoration: none; white-space: nowrap;
  transition: background 0.12s, border-color 0.12s;
}
.wo-act-btn:hover { background: var(--gray-fill); border-color: rgba(0,0,0,0.24); color: var(--t1); }
.wo-act-btn.is-primary {
  background: var(--blue-fill); border-color: rgba(0, 113, 227, 0.25); color: var(--blue); font-weight: 600;
}
.wo-act-btn.is-primary:hover { background: rgba(0, 113, 227, 0.15); }

.wo-more-wrap { position: relative; display: inline-flex; }
.wo-more-menu {
  position: absolute; right: 0; top: calc(100% + 4px); z-index: 30;
  min-width: 194px; padding: 5px; background: var(--surface);
  border: 1px solid var(--sep-strong); border-radius: var(--r-sm);
  box-shadow: var(--shadow-float); text-align: left;
}
.wo-more-menu[hidden] { display: none !important; }
.wo-menu-item {
  display: flex; align-items: center; gap: 8px; width: 100%;
  padding: 7px 9px; border: none; background: transparent;
  border-radius: 6px; font-family: inherit; font-size: 12.5px;
  color: var(--t1); text-decoration: none; cursor: pointer; text-align: left;
}
.wo-menu-item:hover { background: var(--gray-fill); color: var(--blue); }
.wo-menu-sep { height: 1px; background: var(--sep); margin: 4px 2px; }

/* Responsive Card Grid View */
.wo-cards-sortbar {
  padding: 8px 16px; border-bottom: 1px solid var(--sep);
  background: var(--surface-2); display: flex; align-items: center;
  justify-content: space-between; gap: 8px; flex-wrap: wrap;
}
.wo-cards-grid {
  padding: 16px;
  display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 14px;
}
.wo-card-item {
  background: var(--surface); border: 1px solid var(--sep-strong);
  border-radius: var(--r-md); padding: 15px 16px;
  display: flex; flex-direction: column; gap: 11px;
  transition: box-shadow 0.16s, border-color 0.16s;
}
.wo-card-item:hover {
  border-color: rgba(0, 113, 227, 0.38); box-shadow: var(--shadow-raise);
}
.wo-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.wo-card-metrics {
  display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 6px; padding: 9px 10px; border-radius: var(--r-xs);
  background: var(--surface-2); border: 1px solid var(--sep);
}
.wo-cm-box { display: flex; flex-direction: column; gap: 2px; }
.wo-cm-lbl { font-size: 10px; font-weight: 600; text-transform: uppercase; color: var(--t3); }
.wo-cm-val { font-size: 12px; font-weight: 600; color: var(--t1); font-variant-numeric: tabular-nums; }
.wo-card-foot {
  display: flex; align-items: center; justify-content: space-between;
  gap: 8px; padding-top: 8px; border-top: 1px solid var(--sep);
}

/* Empty State */
.wo-empty { padding: 48px 24px; text-align: center; }
.wo-empty-ico {
  width: 44px; height: 44px; border-radius: 12px;
  background: var(--gray-fill); color: var(--t3);
  display: inline-flex; align-items: center; justify-content: center;
  margin-bottom: 12px;
}
.wo-empty h3 { font-size: 15px; font-weight: 600; color: var(--t1); margin-bottom: 4px; }
.wo-empty p  { font-size: 13px; color: var(--t2); max-width: 430px; margin: 0 auto 14px; }

/* Pagination Footer */
.wo-footer {
  padding: 11px 16px; border-top: 1px solid var(--sep);
  background: var(--surface-2); border-radius: 0 0 var(--r-lg) var(--r-lg);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; font-size: 12.5px; color: var(--t2);
}
.wo-pages { display: inline-flex; align-items: center; gap: 4px; }
.wo-page-link {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 30px; height: 28px; padding: 0 8px;
  border-radius: 6px; border: 1px solid var(--sep-strong);
  background: var(--surface); color: var(--t1);
  font-size: 12px; font-weight: 500; text-decoration: none;
  font-variant-numeric: tabular-nums;
}
.wo-page-link:hover { background: var(--gray-fill); }
.wo-page-link.is-current { background: var(--blue); border-color: var(--blue); color: #ffffff; font-weight: 600; }
.wo-page-link.is-disabled { opacity: 0.42; pointer-events: none; }

.wo-mode-table .wo-cards-container { display: none; }
.wo-mode-cards .wo-table-wrap      { display: none; }

/* Slide-Over Right Drawer — Work Order Inspector */
.wo-drawer-backdrop {
  position: fixed; inset: 0; z-index: 900;
  background: rgba(29, 29, 31, 0.36); backdrop-filter: blur(2px);
}
.wo-drawer-backdrop[hidden] { display: none !important; }
.wo-drawer {
  position: fixed; top: 0; right: 0; bottom: 0; z-index: 910;
  width: min(600px, 100vw); background: var(--surface);
  border-left: 1px solid var(--sep-strong);
  box-shadow: -12px 0 40px rgba(0, 0, 0, 0.14);
  display: flex; flex-direction: column;
}
.wo-drawer[hidden] { display: none !important; }
.wo-drawer-head {
  padding: 16px 20px; border-bottom: 1px solid var(--sep);
  background: var(--surface-2);
  display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
}
.wo-drawer-title { font-size: 17px; font-weight: 700; color: var(--t1); line-height: 1.25; }
.wo-drawer-sub { font-size: 12px; color: var(--t2); margin-top: 3px; }
.wo-drawer-nav { display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0; }
.wo-drawer-body { flex: 1; overflow-y: auto; padding: 16px 20px; }

.wo-dkpis {
  display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px; margin-bottom: 16px;
}
.wo-dkpi {
  padding: 10px 11px; border-radius: var(--r-xs);
  background: var(--surface-2); border: 1px solid var(--sep);
}
.wo-dkpi-lbl { font-size: 10px; font-weight: 600; text-transform: uppercase; color: var(--t3); }
.wo-dkpi-val { font-size: 15px; font-weight: 700; color: var(--t1); margin-top: 2px; font-variant-numeric: tabular-nums; }

.wo-dsec { margin-bottom: 16px; }
.wo-dsec-h {
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
  color: var(--t3); margin-bottom: 8px; padding-bottom: 5px; border-bottom: 1px solid var(--sep);
}
.wo-dl-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px 14px; font-size: 12.5px;
}
.wo-dl-item span { display: block; font-size: 10.5px; color: var(--t3); }
.wo-dl-item strong { font-weight: 600; color: var(--t1); word-break: break-word; }

.wo-mini-list { border: 1px solid var(--sep); border-radius: var(--r-xs); overflow: hidden; }
.wo-mini-row {
  padding: 8px 11px; border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 12px;
}
.wo-mini-row:last-child { border-bottom: none; }

.wo-drawer-foot {
  padding: 12px 20px; border-top: 1px solid var(--sep);
  background: var(--surface-2);
  display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
}

@media (max-width: 1100px) {
  .wo-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 780px) {
  .wo-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .wo-dkpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .wo-dl-grid { grid-template-columns: 1fr; }
  .wo-search-input { width: 100%; }
  .wo-tools-right { width: 100%; justify-content: space-between; }
  .wo-shell:not(.wo-user-forced-table) .wo-table-wrap { display: none; }
  .wo-shell:not(.wo-user-forced-table) .wo-cards-container { display: block; }
}

@media print {
  .wo-print-banner {
    display: block !important; margin-bottom: 14px;
    padding-bottom: 10px; border-bottom: 2px solid #1d1d1f;
  }
  .wo-print-banner h2 { font-size: 16px; margin: 0 0 3px; }
  .wo-print-banner p  { font-size: 11px; color: #444; margin: 0; }
  .wo-head, .wo-filter-bar, .wo-toolbar, .wo-chips-bar,
  .wo-batch-bar, .wo-footer, .wo-cards-container, .wo-drawer, .wo-drawer-backdrop, .no-print {
    display: none !important;
  }
  .wo-shell { border: none !important; box-shadow: none !important; }
  .wo-table-wrap { display: block !important; overflow: visible !important; }
  .wo-table th, .wo-table td { padding: 6px 8px !important; font-size: 11px !important; }
  .wo-title-line { max-width: none !important; white-space: normal !important; }
}
</style>

<!-- Print-only Work Orders Register Banner -->
<div class="wo-print-banner">
  <h2>WORKSHOP WORK ORDERS REGISTER</h2>
  <p>
    Showing <strong><?= (int)count($work_orders) ?></strong> of <strong><?= (int)$total_filtered ?></strong> work orders
    · Status Filter: <strong><?= htmlspecialchars($tab_defs[$active_tab]['label'], ENT_QUOTES, 'UTF-8') ?></strong>
    · Printed <?= date('d M Y H:i') ?>
  </p>
</div>

<!-- Page Header & Primary Actions -->
<div class="wo-head no-print">
  <div class="wo-head-title">
    <h1>Work Orders</h1>
    <p class="wo-sub">Corrective breakdowns, preventive maintenance, labor sessions, spare-part holds, and supervisor verification queue.</p>
  </div>

  <div class="wo-head-actions">
    <a href="<?= site_url('workorders/export_csv') . ($export_qs !== '' ? '?' . $export_qs : '') ?>"
       class="btn btn-ghost btn-sm btn-icon"
       title="Export filtered work orders as UTF-8 CSV">
      <svg class="wo-ico" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export CSV
    </a>
    <?php if (!empty($can_write)): ?>
      <a href="<?= site_url('workorders/create') ?>" class="btn btn-primary btn-sm btn-icon">
        <svg class="wo-ico" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        New WO
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- Executive Maintenance KPI Strip -->
<section class="wo-kpis" aria-label="Work order summary metrics">
  <div class="wo-kpi">
    <div class="wo-kpi-lbl">Work Orders in Scope</div>
    <div class="wo-kpi-val"><?= (int)$summary['total_wos'] ?></div>
    <div class="wo-kpi-meta">
      <span><?= (int)$summary['active_queue'] ?> unfinished in active queue</span>
    </div>
  </div>

  <div class="wo-kpi">
    <div class="wo-kpi-lbl">Critical &amp; Breakdowns</div>
    <div class="wo-kpi-val" style="color:<?= (int)$summary['critical_active'] > 0 ? 'var(--red)' : 'var(--t1)' ?>;">
      <?= (int)$summary['critical_active'] ?>
    </div>
    <div class="wo-kpi-meta">
      <span><?= (int)$summary['breakdowns_open'] ?> open machine breakdown<?= (int)$summary['breakdowns_open'] === 1 ? '' : 's' ?></span>
    </div>
  </div>

  <div class="wo-kpi">
    <div class="wo-kpi-lbl">In Progress</div>
    <div class="wo-kpi-val" style="color:var(--blue);"><?= (int)$summary['in_progress'] ?></div>
    <div class="wo-kpi-meta">
      <?php if ((int)$summary['live_sessions'] > 0): ?>
        <span style="color:var(--green);font-weight:600;"><?= (int)$summary['live_sessions'] ?> active labor session<?= (int)$summary['live_sessions'] === 1 ? '' : 's' ?></span>
      <?php else: ?>
        <span>No live labor clocks open</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="wo-kpi">
    <div class="wo-kpi-lbl">On Hold (Parts / Tools)</div>
    <div class="wo-kpi-val" style="color:<?= (int)$summary['on_hold'] > 0 ? 'var(--amber)' : 'var(--t1)' ?>;">
      <?= (int)$summary['on_hold'] ?>
    </div>
    <div class="wo-kpi-meta">
      <?php if ((int)$summary['overdue_eta'] > 0): ?>
        <span style="color:var(--red);font-weight:600;"><?= (int)$summary['overdue_eta'] ?> past expected return</span>
      <?php else: ?>
        <span>No overdue return dates</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="wo-kpi">
    <div class="wo-kpi-lbl">Verify &amp; Close</div>
    <div class="wo-kpi-val" style="color:<?= (int)$summary['awaiting_verify'] > 0 ? 'var(--teal)' : 'var(--t1)' ?>;">
      <?= (int)$summary['awaiting_verify'] ?>
    </div>
    <div class="wo-kpi-meta">
      <span><?= (int)$summary['awaiting_verify'] > 0 ? 'Completed — awaiting supervisor check' : 'All completed jobs verified' ?></span>
    </div>
  </div>
</section>

<!-- Main Enterprise Work Orders DataTable Shell -->
<div class="wo-shell wo-mode-<?= htmlspecialchars($view_mode, ENT_QUOTES, 'UTF-8') ?>" id="woShell">

  <!-- Scope Filter Bar -->
  <form method="get" action="<?= site_url('workorders') ?>" class="wo-filter-bar no-print" id="woFilterForm">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="dir" value="<?= htmlspecialchars($sort_dir, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
    <input type="hidden" name="view" id="woViewHidden" value="<?= htmlspecialchars($view_mode, ENT_QUOTES, 'UTF-8') ?>">

    <div class="wo-field">
      <label for="woType">Type</label>
      <select name="type" id="woType" class="wo-select wo-autosubmit">
        <option value="">All Types</option>
        <?php foreach (array('Corrective Maintenance', 'Preventive Maintenance', 'Incident') as $tp): ?>
          <option value="<?= htmlspecialchars($tp, ENT_QUOTES, 'UTF-8') ?>" <?= $type_filter === $tp ? 'selected' : '' ?>>
            <?= htmlspecialchars($tp, ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="wo-field">
      <label for="woPriority">Priority</label>
      <select name="priority" id="woPriority" class="wo-select wo-autosubmit">
        <option value="">All Priority</option>
        <?php foreach (array('Critical', 'High', 'Medium', 'Low') as $pr): ?>
          <option value="<?= htmlspecialchars($pr, ENT_QUOTES, 'UTF-8') ?>" <?= $priority_filter === $pr ? 'selected' : '' ?>>
            <?= htmlspecialchars($pr, ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="wo-field">
      <label for="woEquipment">Machine</label>
      <select name="equipment" id="woEquipment" class="wo-select wo-autosubmit">
        <option value="">All Equipment</option>
        <?php foreach ($equipment_list as $eq): ?>
          <option value="<?= (int)$eq->id ?>" <?= $equip_filter === (int)$eq->id ? 'selected' : '' ?>>
            <?= htmlspecialchars((string)$eq->registration_no, ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="wo-field">
      <label for="woTech">Technician</label>
      <select name="tech" id="woTech" class="wo-select wo-autosubmit">
        <option value="">All Technicians</option>
        <?php foreach ($tech_options as $tc_opt): ?>
          <option value="<?= (int)$tc_opt->id ?>" <?= $tech_filter === (int)$tc_opt->id ? 'selected' : '' ?>>
            <?= htmlspecialchars(trim((string)$tc_opt->first_name . ' ' . (string)$tc_opt->last_name), ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <button type="submit" class="btn btn-primary btn-sm" style="height:32px;">Apply</button>
  </form>

  <!-- Workflow Status Categorization Tabs + Search + View Switcher -->
  <div class="wo-toolbar no-print">
    <div class="wo-tabs" role="tablist" aria-label="Work order workflow status categories">
      <?php foreach ($tab_defs as $t_key => $t_info):
          $is_on = ($active_tab === $t_key);
      ?>
        <a href="<?= htmlspecialchars($wo_url('workorders', array('tab' => $t_key, 'status' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
           role="tab"
           aria-selected="<?= $is_on ? 'true' : 'false' ?>"
           class="wo-tab <?= $is_on ? 'is-active' : '' ?>">
          <span><?= htmlspecialchars($t_info['label'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="wo-tab-cnt"><?= $t_info['count'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="wo-tools-right">
      <div class="wo-search-wrap">
        <svg class="wo-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search"
               form="woFilterForm"
               name="q"
               id="woSearchInput"
               value="<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="Search WO #, machine, title, tech…"
               aria-label="Search work orders by WO number, machine ID, title, symptom, hold code, or technician"
               class="wo-search-input">
        <?php if ($search_query !== ''): ?>
          <a href="<?= htmlspecialchars($wo_url('workorders', array('q' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
             class="wo-search-clear"
             aria-label="Clear search query"
             title="Clear search">&times;</a>
        <?php else: ?>
          <kbd class="wo-kbd" aria-hidden="true">/</kbd>
        <?php endif; ?>
      </div>

      <div class="wo-view-seg" role="group" aria-label="Display layout">
        <a href="<?= htmlspecialchars($wo_url('workorders', array('view' => 'table')), ENT_QUOTES, 'UTF-8') ?>"
           data-set-view="table"
           aria-pressed="<?= $view_mode === 'table' ? 'true' : 'false' ?>"
           class="wo-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>
          Table
        </a>
        <a href="<?= htmlspecialchars($wo_url('workorders', array('view' => 'cards')), ENT_QUOTES, 'UTF-8') ?>"
           data-set-view="cards"
           aria-pressed="<?= $view_mode === 'cards' ? 'true' : 'false' ?>"
           class="wo-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          Cards
        </a>
      </div>
    </div>
  </div>

  <!-- Active Filter Chips Strip -->
  <?php if ($has_active_filters): ?>
    <div class="wo-chips-bar no-print" aria-label="Active filters">
      <span style="color:var(--t3);font-weight:500;">Active filters:</span>
      <?php if ($active_tab !== 'all'): ?>
        <a class="wo-chip" href="<?= htmlspecialchars($wo_url('workorders', array('tab' => 'all', 'status' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Queue: <?= htmlspecialchars($tab_defs[$active_tab]['label'], ENT_QUOTES, 'UTF-8') ?> <span>&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($type_filter !== ''): ?>
        <a class="wo-chip" href="<?= htmlspecialchars($wo_url('workorders', array('type' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Type: <?= htmlspecialchars($type_filter, ENT_QUOTES, 'UTF-8') ?> <span>&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($priority_filter !== ''): ?>
        <a class="wo-chip" href="<?= htmlspecialchars($wo_url('workorders', array('priority' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Priority: <?= htmlspecialchars($priority_filter, ENT_QUOTES, 'UTF-8') ?> <span>&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($equip_filter > 0 && isset($equip_lookup[$equip_filter])): ?>
        <a class="wo-chip" href="<?= htmlspecialchars($wo_url('workorders', array('equipment' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Machine: <?= htmlspecialchars($equip_lookup[$equip_filter], ENT_QUOTES, 'UTF-8') ?> <span>&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($tech_filter > 0 && isset($tech_lookup[$tech_filter])): ?>
        <a class="wo-chip" href="<?= htmlspecialchars($wo_url('workorders', array('tech' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Technician: <?= htmlspecialchars($tech_lookup[$tech_filter], ENT_QUOTES, 'UTF-8') ?> <span>&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($search_query !== ''): ?>
        <a class="wo-chip" href="<?= htmlspecialchars($wo_url('workorders', array('q' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Search: &ldquo;<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>&rdquo; <span>&times;</span>
        </a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($clean_reset_url, ENT_QUOTES, 'UTF-8') ?>" class="wo-chip-reset">Reset all</a>
    </div>
  <?php endif; ?>

  <!-- Multi-Select Batch Action Bar -->
  <div class="wo-batch-bar no-print" id="woBatchBar" hidden role="region" aria-label="Selected work orders batch actions">
    <div class="wo-batch-count">
      <span id="woSelectedCount">0</span> work orders selected
    </div>
    <div class="wo-batch-actions">
      <a href="#" id="woBatchCsv" class="btn btn-primary btn-sm">Export Selected CSV</a>
      <button type="button" id="woBatchClear" class="btn btn-ghost btn-sm">Clear selection</button>
    </div>
  </div>

  <?php if (empty($work_orders)): ?>
    <div class="wo-empty">
      <div class="wo-empty-ico" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </div>
      <?php if ($has_active_filters): ?>
        <h3>No work orders match your filters</h3>
        <p>No records matched the current status tab, machine, type, priority, technician, or search query.</p>
        <a href="<?= htmlspecialchars($clean_reset_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost">Reset all filters</a>
      <?php else: ?>
        <h3>No work orders recorded yet</h3>
        <p>Create a corrective, preventive, or incident work order to track workshop repairs and labor hours.</p>
        <?php if (!empty($can_write)): ?>
          <a href="<?= site_url('workorders/create') ?>" class="btn btn-primary">New WO</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php else: ?>

    <!-- 1. Enterprise Work Orders Table View -->
    <div class="wo-table-wrap">
      <table class="wo-table">
        <thead>
          <tr>
            <th scope="col" class="wo-chk-cell no-print">
              <input type="checkbox" id="woCheckAll" class="wo-chk" aria-label="Select all work orders on this page">
            </th>
            <?= $sort_th('wo_number', 'Work Order', 'left', 'asc') ?>
            <?= $sort_th('equipment', 'Machine', 'left', 'asc') ?>
            <?= $sort_th('type', 'Type', 'left', 'asc') ?>
            <?= $sort_th('priority', 'Priority', 'left', 'desc') ?>
            <?= $sort_th('status', 'Status & Hold', 'left', 'asc') ?>
            <?= $sort_th('assigned', 'Assigned', 'left', 'asc') ?>
            <?= $sort_th('hours', 'Hours', 'right', 'desc') ?>
            <?= $sort_th('created', 'Opened', 'left', 'desc') ?>
            <th scope="col" class="wo-num no-print">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($work_orders as $wo):
              $wid       = (int)$wo->id;
              $is_bd     = (function_exists('wo_is_breakdown') ? wo_is_breakdown($wo) : !empty($wo->is_breakdown));
              $type_cls  = $is_bd ? 'b-danger' : ($wo->type === 'Incident' ? 'b-warning' : 'b-primary');
              $type_short= $is_bd ? 'Breakdown' : ($wo->type === 'Preventive Maintenance' ? 'Preventive' : 'Incident');
              $prio_cls  = isset($prio_badges[$wo->priority]) ? $prio_badges[$wo->priority] : 'b-secondary';
              $stat_cls  = isset($status_badges[$wo->status]) ? $status_badges[$wo->status] : 'b-secondary';
              $hold_lbl  = (!empty($wo->hold_code) && function_exists('wo_hold_label')) ? wo_hold_label($wo->hold_code) : NULL;
              $json_url  = site_url('workorders/summary_json/' . $wid);
              $view_url  = site_url('workorders/view/' . $wid);
              $edit_url  = site_url('workorders/edit/' . $wid);
              $card_url  = site_url('workorders/jobcard/' . $wid);
          ?>
          <tr data-wo-row="<?= $wid ?>"
              data-json-url="<?= htmlspecialchars($json_url, ENT_QUOTES, 'UTF-8') ?>"
              data-view-url="<?= htmlspecialchars($view_url, ENT_QUOTES, 'UTF-8') ?>"
              data-edit-url="<?= htmlspecialchars($edit_url, ENT_QUOTES, 'UTF-8') ?>"
              data-card-url="<?= htmlspecialchars($card_url, ENT_QUOTES, 'UTF-8') ?>"
              data-wo-num="<?= htmlspecialchars((string)$wo->wo_number, ENT_QUOTES, 'UTF-8') ?>">

            <td class="wo-chk-cell no-print">
              <input type="checkbox" class="wo-chk wo-row-chk" value="<?= $wid ?>"
                     aria-label="Select <?= htmlspecialchars((string)$wo->wo_number, ENT_QUOTES, 'UTF-8') ?>">
            </td>

            <td>
              <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <button type="button" class="wo-id-btn js-open-wo" data-wid="<?= $wid ?>">
                  <?= htmlspecialchars((string)$wo->wo_number, ENT_QUOTES, 'UTF-8') ?>
                </button>
                <?php if (!empty($wo->has_open_session)): ?>
                  <span class="wo-live-pill" title="Technician actively clocked onto this work order"><span class="led led-ok"></span>Live</span>
                <?php endif; ?>
              </div>
              <div class="wo-title-line" title="<?= htmlspecialchars((string)$wo->title, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars((string)$wo->title, ENT_QUOTES, 'UTF-8') ?>
              </div>
              <?php if ((int)$wo->spare_lines_count > 0): ?>
                <div class="wo-sub-line">
                  <span><?= (int)$wo->spare_lines_count ?> spare part line<?= (int)$wo->spare_lines_count === 1 ? '' : 's' ?></span>
                </div>
              <?php endif; ?>
            </td>

            <td style="white-space:nowrap;">
              <div class="mono fw-600" style="font-size:12.5px;color:var(--t1);">
                <?= htmlspecialchars((string)(!empty($wo->registration_no) ? $wo->registration_no : '—'), ENT_QUOTES, 'UTF-8') ?>
              </div>
              <?php $bm = trim((string)$wo->brand . ' ' . (string)$wo->model); ?>
              <?php if ($bm !== ''): ?>
                <div style="font-size:11px;color:var(--t3);max-width:135px;overflow:hidden;text-overflow:ellipsis;">
                  <?= htmlspecialchars($bm, ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endif; ?>
            </td>

            <td style="white-space:nowrap;">
              <span class="badge <?= $type_cls ?>" title="<?= htmlspecialchars((string)$wo->type, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($type_short, ENT_QUOTES, 'UTF-8') ?>
              </span>
            </td>

            <td style="white-space:nowrap;">
              <span class="badge <?= $prio_cls ?>"><?= htmlspecialchars((string)$wo->priority, ENT_QUOTES, 'UTF-8') ?></span>
            </td>

            <td>
              <span class="badge <?= $stat_cls ?>"><?= htmlspecialchars((string)$wo->status, ENT_QUOTES, 'UTF-8') ?></span>
              <?php if (!empty($wo->hold_code)): ?>
                <div class="wo-hold-line" title="<?= htmlspecialchars((string)($hold_lbl ? $hold_lbl : $wo->hold_code), ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars((string)$wo->hold_code, ENT_QUOTES, 'UTF-8') ?><?= $hold_lbl ? (' · ' . htmlspecialchars((string)$hold_lbl, ENT_QUOTES, 'UTF-8')) : '' ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($wo->is_overdue_eta)): ?>
                <div class="wo-overdue-line">Overdue return (<?= date('d M', strtotime((string)$wo->expected_return_date)) ?>)</div>
              <?php endif; ?>
            </td>

            <td style="max-width:155px;">
              <?php if (!empty($wo->assigned_name)): ?>
                <div style="font-size:12.5px;font-weight:500;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                     title="<?= htmlspecialchars((string)$wo->assigned_name, ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars((string)$wo->assigned_name, ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php else: ?>
                <span class="wo-muted">Unassigned</span>
              <?php endif; ?>
            </td>

            <td class="wo-num">
              <?php if ($wo->effective_downtime !== NULL): ?>
                <span class="mono fw-600" style="font-size:12.5px;"><?= number_format((float)$wo->effective_downtime, 1) ?>h</span>
                <div style="font-size:10px;color:var(--t3);">downtime</div>
              <?php elseif ((float)$wo->logged_hours > 0): ?>
                <span class="mono fw-600" style="font-size:12.5px;"><?= number_format((float)$wo->logged_hours, 1) ?>h</span>
                <div style="font-size:10px;color:var(--t3);">labor</div>
              <?php else: ?>
                <span class="wo-muted mono">—</span>
              <?php endif; ?>
            </td>

            <td class="mono" style="font-size:11.5px;color:var(--t2);white-space:nowrap;">
              <?= !empty($wo->created_at) ? date('d M Y', strtotime((string)$wo->created_at)) : '—' ?>
              <?php if (!empty($wo->is_active_queue) && (int)$wo->open_age_days > 0): ?>
                <div style="font-size:10.5px;color:<?= (int)$wo->open_age_days >= 7 ? 'var(--amber)' : 'var(--t3)' ?>;">
                  <?= (int)$wo->open_age_days ?>d open
                </div>
              <?php endif; ?>
            </td>

            <td class="wo-num no-print">
              <div class="wo-row-actions">
                <button type="button"
                        class="wo-act-btn is-primary js-open-wo"
                        data-wid="<?= $wid ?>"
                        title="Inspect work order details in drawer">
                  Inspect
                </button>
                <a href="<?= htmlspecialchars($view_url, ENT_QUOTES, 'UTF-8') ?>" class="wo-act-btn">Open</a>
                <div class="wo-more-wrap">
                  <button type="button"
                          class="wo-act-btn js-more-toggle"
                          aria-haspopup="menu"
                          aria-expanded="false"
                          aria-label="More actions for <?= htmlspecialchars((string)$wo->wo_number, ENT_QUOTES, 'UTF-8') ?>">
                    &bull;&bull;&bull;
                  </button>
                  <div class="wo-more-menu" role="menu" hidden>
                    <button type="button" role="menuitem" class="wo-menu-item js-open-wo" data-wid="<?= $wid ?>">
                      Inspect Summary Drawer
                    </button>
                    <a role="menuitem" href="<?= htmlspecialchars($view_url, ENT_QUOTES, 'UTF-8') ?>" class="wo-menu-item">
                      Open Full Work Order
                    </a>
                    <a role="menuitem" href="<?= htmlspecialchars($card_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="wo-menu-item">
                      Print Job Card
                    </a>
                    <?php if (!empty($can_write)): ?>
                      <div class="wo-menu-sep" role="separator"></div>
                      <a role="menuitem" href="<?= htmlspecialchars($edit_url, ENT_QUOTES, 'UTF-8') ?>" class="wo-menu-item">
                        Edit Work Order
                      </a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- 2. Responsive Card Grid View -->
    <div class="wo-cards-container">
      <div class="wo-cards-sortbar no-print">
        <span style="font-size:12px;color:var(--t2);">Card layout · Sorted by <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $sort_by)), ENT_QUOTES, 'UTF-8') ?></strong> (<?= strtoupper($sort_dir) ?>)</span>
        <div style="display:inline-flex;align-items:center;gap:6px;">
          <label for="woCardSort" style="font-size:11.5px;color:var(--t3);margin:0;">Sort:</label>
          <select id="woCardSort" class="wo-select" style="height:28px;font-size:12px;">
            <?php
            $card_sorts = array(
                'created:desc'   => 'Newest First',
                'created:asc'    => 'Oldest First',
                'priority:desc'  => 'Priority (Critical First)',
                'status:asc'     => 'Workflow Stage',
                'hours:desc'     => 'Hours (Highest)',
                'equipment:asc'  => 'Machine ID (A–Z)',
                'wo_number:desc' => 'WO Number (Desc)',
            );
            $cur_pair = $sort_by . ':' . $sort_dir;
            foreach ($card_sorts as $pair => $lbl):
                list($s_col, $s_d) = explode(':', $pair);
            ?>
              <option value="<?= htmlspecialchars($wo_url('workorders', array('sort' => $s_col, 'dir' => $s_d, 'view' => 'cards', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
                      <?= $cur_pair === $pair ? 'selected' : '' ?>>
                <?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="wo-cards-grid">
        <?php foreach ($work_orders as $wo):
            $wid       = (int)$wo->id;
            $is_bd     = (function_exists('wo_is_breakdown') ? wo_is_breakdown($wo) : !empty($wo->is_breakdown));
            $type_cls  = $is_bd ? 'b-danger' : ($wo->type === 'Incident' ? 'b-warning' : 'b-primary');
            $type_short= $is_bd ? 'Breakdown' : ($wo->type === 'Preventive Maintenance' ? 'Preventive' : 'Incident');
            $prio_cls  = isset($prio_badges[$wo->priority]) ? $prio_badges[$wo->priority] : 'b-secondary';
            $stat_cls  = isset($status_badges[$wo->status]) ? $status_badges[$wo->status] : 'b-secondary';
        ?>
          <article class="wo-card-item">
            <div class="wo-card-top">
              <div>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                  <button type="button" class="wo-id-btn js-open-wo" data-wid="<?= $wid ?>">
                    <?= htmlspecialchars((string)$wo->wo_number, ENT_QUOTES, 'UTF-8') ?>
                  </button>
                  <span class="badge <?= $type_cls ?>"><?= htmlspecialchars($type_short, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div style="font-size:13.5px;font-weight:600;color:var(--t1);margin-top:4px;">
                  <?= htmlspecialchars((string)$wo->title, ENT_QUOTES, 'UTF-8') ?>
                </div>
              </div>
              <span class="badge <?= $prio_cls ?>"><?= htmlspecialchars((string)$wo->priority, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="wo-card-metrics">
              <div class="wo-cm-box">
                <span class="wo-cm-lbl">Machine</span>
                <span class="wo-cm-val"><?= htmlspecialchars((string)(!empty($wo->registration_no) ? $wo->registration_no : '—'), ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              <div class="wo-cm-box">
                <span class="wo-cm-lbl">Status</span>
                <span class="wo-cm-val"><?= htmlspecialchars((string)$wo->status, ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              <div class="wo-cm-box">
                <span class="wo-cm-lbl">Hours</span>
                <span class="wo-cm-val">
                  <?= $wo->effective_downtime !== NULL ? (number_format((float)$wo->effective_downtime, 1) . 'h') : ((float)$wo->logged_hours > 0 ? (number_format((float)$wo->logged_hours, 1) . 'h') : '—') ?>
                </span>
              </div>
            </div>

            <div class="wo-card-foot">
              <span style="font-size:11.5px;color:var(--t2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= !empty($wo->assigned_name) ? htmlspecialchars((string)$wo->assigned_name, ENT_QUOTES, 'UTF-8') : 'Unassigned' ?>
              </span>
              <div class="wo-row-actions">
                <button type="button" class="wo-act-btn is-primary js-open-wo" data-wid="<?= $wid ?>">Inspect</button>
                <a href="<?= site_url('workorders/view/' . $wid) ?>" class="wo-act-btn">Open</a>
                <a href="<?= site_url('workorders/jobcard/' . $wid) ?>" target="_blank" rel="noopener" class="wo-act-btn">Card</a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>

  <?php endif; ?>

  <!-- Pagination & Page-Size Footer -->
  <div class="wo-footer no-print">
    <div>
      <?php if ($total_filtered > 0): ?>
        Showing <strong class="mono"><?= (int)$showing_from ?>–<?= (int)$showing_to ?></strong>
        of <strong class="mono"><?= (int)$total_filtered ?></strong> work orders
        <?php if ($total_filtered < $total_scope): ?>
          <span>(filtered from <?= (int)$total_scope ?>)</span>
        <?php endif; ?>
      <?php else: ?>
        0 matching work orders
      <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
      <nav class="wo-pages" aria-label="Work orders pagination">
        <a class="wo-page-link <?= $page <= 1 ? 'is-disabled' : '' ?>"
           href="<?= htmlspecialchars($wo_url('workorders', array('page' => max(1, $page - 1))), ENT_QUOTES, 'UTF-8') ?>">
          Prev
        </a>
        <?php
        $win_start = max(1, $page - 2);
        $win_end   = min($total_pages, $page + 2);
        if ($win_start > 1): ?>
          <a class="wo-page-link" href="<?= htmlspecialchars($wo_url('workorders', array('page' => 1)), ENT_QUOTES, 'UTF-8') ?>">1</a>
          <?php if ($win_start > 2): ?><span style="padding:0 4px;color:var(--t3);">&hellip;</span><?php endif; ?>
        <?php endif;
        for ($p = $win_start; $p <= $win_end; $p++): ?>
          <a class="wo-page-link <?= $p === $page ? 'is-current' : '' ?>"
             <?= $p === $page ? 'aria-current="page"' : '' ?>
             href="<?= htmlspecialchars($wo_url('workorders', array('page' => $p)), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
        <?php endfor;
        if ($win_end < $total_pages): ?>
          <?php if ($win_end < $total_pages - 1): ?><span style="padding:0 4px;color:var(--t3);">&hellip;</span><?php endif; ?>
          <a class="wo-page-link" href="<?= htmlspecialchars($wo_url('workorders', array('page' => $total_pages)), ENT_QUOTES, 'UTF-8') ?>"><?= $total_pages ?></a>
        <?php endif; ?>
        <a class="wo-page-link <?= $page >= $total_pages ? 'is-disabled' : '' ?>"
           href="<?= htmlspecialchars($wo_url('workorders', array('page' => min($total_pages, $page + 1))), ENT_QUOTES, 'UTF-8') ?>">
          Next
        </a>
      </nav>
    <?php endif; ?>

    <div style="display:inline-flex;align-items:center;gap:6px;">
      <label for="woPerPage" style="font-size:12px;color:var(--t3);margin:0;">Rows:</label>
      <select id="woPerPage" class="wo-select" style="height:28px;font-size:12px;">
        <?php foreach (array(10, 25, 50, 100) as $pp): ?>
          <option value="<?= htmlspecialchars($wo_url('workorders', array('per_page' => $pp, 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
                  <?= $per_page === $pp ? 'selected' : '' ?>>
            <?= $pp ?> / page
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>

<!-- Right-Side Slide-Over Work Order Inspector Drawer -->
<div class="wo-drawer-backdrop no-print" id="woDrawerBackdrop" hidden></div>
<aside class="wo-drawer no-print" id="woDrawer" role="dialog" aria-modal="true" aria-labelledby="woDrawerTitle" hidden>
  <div class="wo-drawer-head">
    <div>
      <div class="wo-drawer-title" id="woDrawerTitle">Work Order</div>
      <div class="wo-drawer-sub" id="woDrawerSub">Loading summary…</div>
    </div>
    <div class="wo-drawer-nav">
      <button type="button" class="wo-act-btn" id="woDrawerPrev" title="Previous work order (Left arrow)" aria-label="Previous work order">&larr;</button>
      <button type="button" class="wo-act-btn" id="woDrawerNext" title="Next work order (Right arrow)" aria-label="Next work order">&rarr;</button>
      <button type="button" class="wo-act-btn" id="woDrawerClose" title="Close drawer (Esc)" aria-label="Close drawer">&times;</button>
    </div>
  </div>

  <div class="wo-drawer-body" id="woDrawerBody">
    <p style="color:var(--t3);font-size:13px;">Loading work order details…</p>
  </div>

  <div class="wo-drawer-foot">
    <div style="display:inline-flex;gap:8px;flex-wrap:wrap;">
      <a href="#" id="woDrawerCard" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">Print Job Card</a>
      <?php if (!empty($can_write)): ?>
        <a href="#" id="woDrawerEdit" class="btn btn-ghost btn-sm">Edit WO</a>
      <?php endif; ?>
    </div>
    <a href="#" id="woDrawerView" class="btn btn-primary btn-sm">Open Full Work Order &rarr;</a>
  </div>
</aside>

<script>
(function () {
  var shell      = document.getElementById('woShell');
  var form       = document.getElementById('woFilterForm');
  var viewHidden = document.getElementById('woViewHidden');
  var searchInp  = document.getElementById('woSearchInput');
  var perPageSel = document.getElementById('woPerPage');
  var cardSort   = document.getElementById('woCardSort');

  // 1. Auto-submit select filters & pagination / sort controls
  var autoInputs = document.querySelectorAll('.wo-autosubmit');
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

  // 2. Instant Table <-> Cards View Switcher
  var viewBtns = document.querySelectorAll('[data-set-view]');
  for (var v = 0; v < viewBtns.length; v++) {
    viewBtns[v].addEventListener('click', function (ev) {
      var mode = this.getAttribute('data-set-view');
      if (!shell || !mode) return;
      ev.preventDefault();
      shell.classList.remove('wo-mode-table', 'wo-mode-cards');
      shell.classList.add('wo-mode-' + mode);
      if (mode === 'table') shell.classList.add('wo-user-forced-table');
      else shell.classList.remove('wo-user-forced-table');
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

  // 3. Multi-Select Checkboxes & Contextual Batch Action Bar
  var checkAll    = document.getElementById('woCheckAll');
  var rowChks     = document.querySelectorAll('.wo-row-chk');
  var batchBar    = document.getElementById('woBatchBar');
  var selCountEl  = document.getElementById('woSelectedCount');
  var batchCsv    = document.getElementById('woBatchCsv');
  var batchClear  = document.getElementById('woBatchClear');
  var baseExportQ = <?= json_encode($export_qs) ?>;
  var csvBase     = <?= json_encode(site_url('workorders/export_csv')) ?>;

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
    var idsParam  = 'ids=' + encodeURIComponent(ids.join(','));
    var csvPrefix = baseExportQ ? (baseExportQ + '&') : '';
    if (batchCsv) batchCsv.href = csvBase + '?' + csvPrefix + idsParam;
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
    var openMenus = document.querySelectorAll('.wo-more-menu:not([hidden])');
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

  // 5. Right-Side Slide-Over Work Order Inspector Drawer
  var drawer      = document.getElementById('woDrawer');
  var backdrop    = document.getElementById('woDrawerBackdrop');
  var dTitle      = document.getElementById('woDrawerTitle');
  var dSub        = document.getElementById('woDrawerSub');
  var dBody       = document.getElementById('woDrawerBody');
  var dClose      = document.getElementById('woDrawerClose');
  var dPrev       = document.getElementById('woDrawerPrev');
  var dNext       = document.getElementById('woDrawerNext');
  var dView       = document.getElementById('woDrawerView');
  var dEdit       = document.getElementById('woDrawerEdit');
  var dCard       = document.getElementById('woDrawerCard');
  var woCache     = {};
  var orderedWids = [];
  var rowMap      = {};
  var activeWid   = null;
  var lastTrigger = null;

  var allRows = document.querySelectorAll('tr[data-wo-row]');
  for (var r = 0; r < allRows.length; r++) {
    var wid = String(allRows[r].getAttribute('data-wo-row'));
    orderedWids.push(wid);
    rowMap[wid] = {
      num:     allRows[r].getAttribute('data-wo-num') || '',
      jsonUrl: allRows[r].getAttribute('data-json-url') || '',
      viewUrl: allRows[r].getAttribute('data-view-url') || '#',
      editUrl: allRows[r].getAttribute('data-edit-url') || '#',
      cardUrl: allRows[r].getAttribute('data-card-url') || '#'
    };
  }

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function renderWoPayload(d) {
    dTitle.textContent = d.wo_number + ' · ' + d.registration_no;
    dSub.textContent   = d.status + ' · ' + d.priority + ' Priority · ' + (d.brand_model || d.type);

    var smrTxt  = (d.hours_reading !== null && d.hours_reading !== undefined) ? (d.hours_reading + 'h') : '—';
    var downTxt = (d.downtime_hours !== null && d.downtime_hours !== undefined) ? (d.downtime_hours + 'h') : '—';
    var parts   = d.spare_parts || [];
    var sess    = d.sessions || [];
    var hist    = d.history || [];

    var html = '<div class="wo-dkpis">'
      + '<div class="wo-dkpi"><div class="wo-dkpi-lbl">Machine Status</div><div class="wo-dkpi-val" style="font-size:13.5px;">' + escHtml(d.machine_status) + '</div></div>'
      + '<div class="wo-dkpi"><div class="wo-dkpi-lbl">SMR Reading</div><div class="wo-dkpi-val">' + escHtml(smrTxt) + '</div></div>'
      + '<div class="wo-dkpi"><div class="wo-dkpi-lbl">Logged Labor</div><div class="wo-dkpi-val">' + Number(d.logged_hours || 0).toFixed(1) + 'h</div></div>'
      + '<div class="wo-dkpi"><div class="wo-dkpi-lbl">Downtime</div><div class="wo-dkpi-val">' + escHtml(downTxt) + '</div></div>'
      + '</div>';

    // Section 1: Problem, Hold & Assignment Summary
    html += '<div class="wo-dsec"><div class="wo-dsec-h">Repair Summary &amp; Assignment</div>'
      + '<div style="font-size:13.5px;font-weight:600;color:var(--t1);margin-bottom:6px;">' + escHtml(d.title) + '</div>';
    if (d.description) {
      html += '<p style="font-size:12.5px;color:var(--t2);margin:0 0 10px;">' + escHtml(d.description) + '</p>';
    }
    html += '<div class="wo-dl-grid">'
      + '<div class="wo-dl-item"><span>Type</span><strong>' + escHtml(d.type) + (d.is_breakdown ? ' (Breakdown)' : '') + '</strong></div>'
      + '<div class="wo-dl-item"><span>Assigned Technicians</span><strong>' + escHtml(d.assigned_name || 'Unassigned') + '</strong></div>'
      + '<div class="wo-dl-item"><span>Hold Reason</span><strong>' + escHtml(d.hold_code ? (d.hold_code + (d.hold_label ? ' — ' + d.hold_label : '')) : 'None') + '</strong></div>'
      + '<div class="wo-dl-item"><span>Expected Return</span><strong>' + escHtml(d.expected_return_date || '—') + '</strong></div>'
      + '<div class="wo-dl-item"><span>Root Cause</span><strong>' + escHtml(d.root_cause || '—') + '</strong></div>'
      + '<div class="wo-dl-item"><span>Action Taken</span><strong>' + escHtml(d.action_taken || '—') + '</strong></div>'
      + '</div></div>';

    // Section 2: Requested Spare Parts
    html += '<div class="wo-dsec"><div class="wo-dsec-h">Requested Spare Parts (' + parts.length + ')</div>';
    if (parts.length === 0) {
      html += '<div style="font-size:12.5px;color:var(--t3);">No spare parts listed on this work order.</div>';
    } else {
      html += '<div class="wo-mini-list">';
      for (var p = 0; p < parts.length; p++) {
        var sp = parts[p];
        html += '<div class="wo-mini-row">'
          + '<div><strong>' + escHtml(sp.part_name) + '</strong>'
          + '<div style="font-size:11px;color:var(--t3);">' + escHtml(sp.item_code || sp.part_number || 'Manual line') + '</div></div>'
          + '<span class="mono fw-600">' + Number(sp.quantity) + ' ' + escHtml(sp.unit) + '</span>'
          + '</div>';
      }
      html += '</div>';
    }
    html += '</div>';

    // Section 3: Recent Labor Sessions
    html += '<div class="wo-dsec"><div class="wo-dsec-h">Labor Sessions (' + sess.length + ')</div>';
    if (sess.length === 0) {
      html += '<div style="font-size:12.5px;color:var(--t3);">No labor sessions clocked yet.</div>';
    } else {
      html += '<div class="wo-mini-list">';
      for (var s = 0; s < sess.length; s++) {
        var sn = sess[s];
        var sCls = sn.status === 'Open' ? 'b-available' : 'b-secondary';
        html += '<div class="wo-mini-row">'
          + '<div><strong>' + escHtml(sn.technician_name) + '</strong>'
          + '<div style="font-size:11px;color:var(--t3);">Started ' + escHtml(sn.started_at || '—') + '</div></div>'
          + '<span class="badge ' + sCls + '">' + (sn.status === 'Open' ? 'Open Session' : (Number(sn.hours_worked || 0).toFixed(2) + 'h')) + '</span>'
          + '</div>';
      }
      html += '</div>';
    }
    html += '</div>';

    // Section 4: Recent Status Audit History
    if (hist.length > 0) {
      html += '<div class="wo-dsec"><div class="wo-dsec-h">Status History</div><div class="wo-mini-list">';
      for (var h = 0; h < hist.length; h++) {
        var hv = hist[h];
        html += '<div class="wo-mini-row">'
          + '<div><strong>' + escHtml((hv.old_status ? hv.old_status + ' → ' : '') + hv.new_status) + '</strong>'
          + (hv.notes ? '<div style="font-size:11px;color:var(--t2);">' + escHtml(hv.notes) + '</div>' : '') + '</div>'
          + '<div style="font-size:11px;color:var(--t3);white-space:nowrap;">' + escHtml(hv.created_at) + '</div>'
          + '</div>';
      }
      html += '</div></div>';
    }

    dBody.innerHTML = html;
  }

  function openWo(wid, triggerEl) {
    var key  = String(wid);
    var meta = rowMap[key];
    if (!meta || !drawer) return;
    closeAllMenus();
    activeWid = key;
    if (triggerEl) lastTrigger = triggerEl;

    var idx = orderedWids.indexOf(key);
    if (dPrev) dPrev.disabled = (idx <= 0);
    if (dNext) dNext.disabled = (idx < 0 || idx >= orderedWids.length - 1);

    dTitle.textContent = meta.num || 'Work Order';
    dSub.textContent   = 'Loading summary…';
    if (dView) dView.href = meta.viewUrl;
    if (dEdit) dEdit.href = meta.editUrl;
    if (dCard) dCard.href = meta.cardUrl;

    backdrop.hidden = false;
    drawer.hidden   = false;
    dClose.focus();

    if (woCache[meta.jsonUrl]) {
      renderWoPayload(woCache[meta.jsonUrl]);
      return;
    }
    dBody.innerHTML = '<p style="color:var(--t3);font-size:13px;">Loading repair details, spare parts &amp; labor sessions…</p>';
    fetch(meta.jsonUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (activeWid !== key) return;
        if (!json || !json.ok) {
          dBody.innerHTML = '<div class="flash flash-err">' + escHtml((json && json.error) || 'Unable to load work order summary.') + '</div>';
          return;
        }
        woCache[meta.jsonUrl] = json;
        renderWoPayload(json);
      })
      .catch(function () {
        if (activeWid !== key) return;
        dBody.innerHTML = '<div class="flash flash-err">Network error loading summary. Use Open Full Work Order below.</div>';
      });
  }

  function closeDrawer() {
    if (!drawer || drawer.hidden) return;
    drawer.hidden   = true;
    backdrop.hidden = true;
    activeWid       = null;
    if (lastTrigger && typeof lastTrigger.focus === 'function') {
      lastTrigger.focus();
    }
  }

  var openTriggers = document.querySelectorAll('.js-open-wo');
  for (var o = 0; o < openTriggers.length; o++) {
    openTriggers[o].addEventListener('click', function () {
      openWo(this.getAttribute('data-wid'), this);
    });
  }
  if (dClose)   dClose.addEventListener('click', closeDrawer);
  if (backdrop) backdrop.addEventListener('click', closeDrawer);
  if (dPrev) {
    dPrev.addEventListener('click', function () {
      var idx = orderedWids.indexOf(activeWid);
      if (idx > 0) openWo(orderedWids[idx - 1], null);
    });
  }
  if (dNext) {
    dNext.addEventListener('click', function () {
      var idx = orderedWids.indexOf(activeWid);
      if (idx >= 0 && idx < orderedWids.length - 1) openWo(orderedWids[idx + 1], null);
    });
  }

  // 6. Global Keyboard Shortcuts ('/' search, Esc close drawer/menu, ArrowLeft/Right step work orders)
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
      var idx = orderedWids.indexOf(activeWid);
      if (ev.key === 'ArrowLeft' && idx > 0) {
        ev.preventDefault();
        openWo(orderedWids[idx - 1], null);
      } else if (ev.key === 'ArrowRight' && idx >= 0 && idx < orderedWids.length - 1) {
        ev.preventDefault();
        openWo(orderedWids[idx + 1], null);
      }
    }
  });
})();
</script>
