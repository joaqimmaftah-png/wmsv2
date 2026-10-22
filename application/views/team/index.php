<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * URL builder preserving active Workforce Directory filters, sorting,
 * pagination, and view mode cleanly across links.
 */
$base_params = array(
    'site'     => $site_filter,
    'role'     => ($role_filter > 0) ? (string)$role_filter : '',
    'skill'    => $skill_filter,
    'q'        => $search_query,
    'tab'      => $active_tab,
    'sort'     => $sort_by,
    'dir'      => $sort_dir,
    'per_page' => (string)$per_page,
    'view'     => $view_mode,
);

$tm_url = function ($path, array $overrides = array()) use ($base_params) {
    $merged = array_merge($base_params, $overrides);
    $clean  = array();
    $defaults = array(
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

// Export query string preserving active filters/sort without pagination UI state
$export_params = array();
if ($site_filter !== '')   $export_params['site']  = $site_filter;
if ($role_filter > 0)      $export_params['role']  = (string)$role_filter;
if ($skill_filter !== '')  $export_params['skill'] = $skill_filter;
if ($search_query !== '')  $export_params['q']     = $search_query;
if ($active_tab !== 'all') $export_params['tab']   = $active_tab;
if ($sort_by !== 'name')   $export_params['sort']  = $sort_by;
if ($sort_dir !== 'asc')   $export_params['dir']   = $sort_dir;
$export_qs = http_build_query($export_params);

$sort_th = function ($col_key, $label, $align = 'left', $default_dir = 'asc') use ($sort_by, $sort_dir, $tm_url) {
    $is_active = ($sort_by === $col_key);
    $next_dir  = $is_active ? (($sort_dir === 'asc') ? 'desc' : 'asc') : $default_dir;
    $aria_sort = $is_active ? (($sort_dir === 'asc') ? 'ascending' : 'descending') : 'none';
    $href      = $tm_url('team', array('sort' => $col_key, 'dir' => $next_dir, 'page' => 1));
    $cls       = 'tm-th-sort' . ($align === 'right' ? ' is-right' : '') . ($is_active ? ' is-sorted' : '');

    $icon = '<svg class="tm-sort-ico" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">';
    if ($is_active && $sort_dir === 'asc') {
        $icon .= '<path d="M6 2.5L9.2 6.2H2.8L6 2.5Z" fill="currentColor"/><path d="M6 9.5L2.8 7.2H9.2L6 9.5Z" fill="currentColor" opacity="0.22"/>';
    } elseif ($is_active && $sort_dir === 'desc') {
        $icon .= '<path d="M6 2.5L9.2 4.8H2.8L6 2.5Z" fill="currentColor" opacity="0.22"/><path d="M6 9.5L2.8 5.8H9.2L6 9.5Z" fill="currentColor"/>';
    } else {
        $icon .= '<path d="M6 2.5L8.8 5.3H3.2L6 2.5ZM6 9.5L3.2 6.7H8.8L6 9.5Z" fill="currentColor" opacity="0.35"/>';
    }
    $icon .= '</svg>';

    return '<th scope="col" aria-sort="' . $aria_sort . '" class="' . ($align === 'right' ? 'tm-num' : '') . '">'
         . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . $cls . '">'
         . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>' . $icon
         . '</a></th>';
};

$role_lookup = array();
foreach ($roles_list as $rl) {
    $role_lookup[(int)$rl->id] = ucfirst((string)$rl->name);
}

$tab_defs = array(
    'all'        => array('label' => 'All Staff',      'count' => (int)$tab_counts['all']),
    'active'     => array('label' => 'Active',         'count' => (int)$tab_counts['active']),
    'on_site'    => array('label' => 'On Site Today',  'count' => (int)$tab_counts['on_site']),
    'on_leave'   => array('label' => 'On Leave',       'count' => (int)$tab_counts['on_leave']),
    'cert_alert' => array('label' => 'Cert Alerts',    'count' => (int)$tab_counts['cert_alert']),
    'inactive'   => array('label' => 'Inactive',       'count' => (int)$tab_counts['inactive']),
);

$skill_badges = array(
    'Junior'     => 'b-secondary',
    'Senior'     => 'b-primary',
    'Expert'     => 'b-success',
    'Specialist' => 'b-info',
);
$status_badges = array(
    'Active'   => 'b-available',
    'On Leave' => 'b-warning',
    'Inactive' => 'b-secondary',
);

// High-contrast accessible avatar palette (white #ffffff text >= 5.2:1 contrast ratio)
$avatar_palette = array('#0066cc', '#1d3557', '#5856d6', '#1c7c36', '#0c7b7b');

$has_active_filters = (
    $site_filter !== '' ||
    $role_filter > 0 ||
    $skill_filter !== '' ||
    $search_query !== '' ||
    $active_tab !== 'all'
);
$clean_reset_url = site_url('team') . ($view_mode !== 'table' ? ('?view=' . rawurlencode($view_mode)) : '');
?>

<style>
/* ═══════════════════════════════════════════════════════════════════════════
   WMSv2 Enterprise Workforce Directory & Dispatch Table (`/team`)
   Built strictly on shared Apple-HIG CSS custom properties in header.php
   ═══════════════════════════════════════════════════════════════════════════ */
.tm-print-banner { display: none; }

.tm-head {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; flex-wrap: wrap; margin-bottom: 18px;
}
.tm-head-title h1 {
  font-size: 23px; font-weight: 700; letter-spacing: -0.025em;
  color: var(--t1); line-height: 1.2; margin: 0;
}
.tm-sub { font-size: 13px; color: var(--t2); margin-top: 3px; }
.tm-head-actions {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.tm-ico { width: 14px; height: 14px; flex-shrink: 0; }

/* Executive Workforce KPI Strip */
.tm-kpis {
  display: grid; grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px; margin-bottom: 18px;
}
.tm-kpi {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-md); padding: 14px 16px;
  box-shadow: var(--shadow-card);
  display: flex; flex-direction: column; justify-content: space-between;
}
.tm-kpi-lbl {
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--t3); margin-bottom: 6px;
}
.tm-kpi-val {
  font-size: 24px; font-weight: 700; letter-spacing: -0.03em;
  color: var(--t1); line-height: 1.05;
  font-variant-numeric: tabular-nums;
}
.tm-kpi-meta {
  margin-top: 6px; font-size: 11.5px; color: var(--t3);
  display: flex; align-items: center; gap: 5px;
}

/* Pending Leave Approval Banner Card */
.tm-leave-card {
  background: var(--surface);
  border: 1px solid rgba(180, 83, 9, 0.25);
  border-left: 4px solid var(--amber);
  border-radius: var(--r-md);
  box-shadow: var(--shadow-card);
  margin-bottom: 18px;
  overflow: hidden;
}
.tm-leave-head {
  padding: 13px 18px; background: var(--amber-fill);
  border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
}
.tm-leave-title {
  font-size: 13.5px; font-weight: 700; color: var(--t1);
  display: flex; align-items: center; gap: 8px;
}
.tm-leave-tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.tm-leave-tbl th {
  padding: 8px 14px; background: var(--surface-2); color: var(--t3);
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.035em; border-bottom: 1px solid var(--sep); text-align: left;
}
.tm-leave-tbl td {
  padding: 10px 14px; border-bottom: 1px solid var(--sep); vertical-align: middle;
}
.tm-leave-tbl tr:last-child td { border-bottom: none; }

/* Main Roster Surface */
.tm-shell {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-lg); box-shadow: var(--shadow-card);
  overflow: visible;
}

/* Filter Bar */
.tm-filter-bar {
  padding: 12px 16px; background: var(--surface-2);
  border-bottom: 1px solid var(--sep);
  border-radius: var(--r-lg) var(--r-lg) 0 0;
  display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap;
}
.tm-field { display: flex; flex-direction: column; gap: 4px; }
.tm-field label {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--t3); margin: 0;
}
.tm-input, .tm-select {
  height: 32px; padding: 0 10px; font-family: inherit; font-size: 12.5px;
  color: var(--t1); background: var(--surface);
  border: 1px solid var(--sep-strong); border-radius: var(--r-xs);
  outline: none; transition: border-color 0.15s, box-shadow 0.15s;
}
.tm-select {
  padding-right: 28px; cursor: pointer;
  appearance: none; -webkit-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236e6e73' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 8px center;
}
.tm-input:focus, .tm-select:focus {
  border-color: var(--blue); box-shadow: 0 0 0 3px var(--blue-fill);
}

/* Status Categorization Tabs + Search + View Switcher */
.tm-toolbar {
  padding: 10px 16px; border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; background: var(--surface);
}
.tm-tabs { display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.tm-tab {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 10px; border-radius: var(--r-xs);
  font-size: 12.5px; font-weight: 500; color: var(--t2);
  text-decoration: none; border: 1px solid transparent;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.tm-tab:hover { background: var(--gray-fill); color: var(--t1); }
.tm-tab.is-active {
  background: var(--blue-fill); color: var(--blue); font-weight: 600;
  border-color: rgba(0, 113, 227, 0.22);
}
.tm-tab-cnt {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 18px; padding: 0 6px;
  border-radius: 100px; font-size: 11px; font-weight: 600;
  background: var(--gray-fill); color: var(--t2);
  font-variant-numeric: tabular-nums;
}
.tm-tab.is-active .tm-tab-cnt { background: var(--blue); color: #ffffff; }

.tm-tools-right {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto;
}
.tm-search-wrap { position: relative; display: inline-flex; align-items: center; }
.tm-search-ico {
  position: absolute; left: 9px; width: 14px; height: 14px;
  color: var(--t3); pointer-events: none;
}
.tm-search-input {
  height: 32px; width: 245px; padding: 0 52px 0 29px;
  font-family: inherit; font-size: 12.5px; color: var(--t1);
  background: var(--surface-2); border: 1px solid var(--sep-strong);
  border-radius: var(--r-xs); outline: none;
  transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
}
.tm-search-input:focus {
  background: var(--surface); border-color: var(--blue);
  box-shadow: 0 0 0 3px var(--blue-fill);
}
.tm-kbd {
  position: absolute; right: 8px; padding: 1px 5px;
  font-size: 10.5px; color: var(--t3);
  background: var(--surface); border: 1px solid var(--sep-strong);
  border-radius: 4px; pointer-events: none; line-height: 1.3;
}
.tm-search-input:focus ~ .tm-kbd { display: none; }
.tm-search-clear {
  position: absolute; right: 6px; width: 20px; height: 20px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 50%; background: var(--gray-fill); color: var(--t2);
  font-size: 13px; text-decoration: none; line-height: 1;
}
.tm-search-clear:hover { background: var(--sep-strong); color: var(--t1); }

/* View Mode Segmented Switcher */
.tm-view-seg {
  display: inline-flex; padding: 2px; background: var(--gray-fill);
  border-radius: var(--r-xs); border: 1px solid var(--sep);
}
.tm-view-btn {
  display: inline-flex; align-items: center; gap: 5px;
  height: 26px; padding: 0 9px; border-radius: 5px; border: none;
  background: transparent; color: var(--t2); font-family: inherit;
  font-size: 12px; font-weight: 500; cursor: pointer; text-decoration: none;
}
.tm-view-btn:hover { color: var(--t1); }
.tm-view-btn.is-active {
  background: var(--surface); color: var(--t1); font-weight: 600;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}

/* Active Filter Chips */
.tm-chips-bar {
  padding: 8px 16px; background: #fbfbfd; border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 12px;
}
.tm-chip {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 2px 9px; border-radius: 100px;
  background: var(--surface); border: 1px solid var(--sep-strong);
  color: var(--t1); font-size: 11.5px; font-weight: 500; text-decoration: none;
}
.tm-chip:hover { border-color: var(--red); color: var(--red); }
.tm-chip-reset { margin-left: auto; font-size: 12px; font-weight: 500; color: var(--blue); text-decoration: none; }
.tm-chip-reset:hover { text-decoration: underline; }

/* Multi-Select Batch Action Bar */
.tm-batch-bar {
  padding: 9px 16px; background: #eef5ff;
  border-bottom: 1px solid rgba(0, 113, 227, 0.24);
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; flex-wrap: wrap;
}
.tm-batch-bar[hidden] { display: none !important; }
.tm-batch-count { font-size: 12.5px; font-weight: 600; color: #004c99; }
.tm-batch-actions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

/* Enterprise Workforce Table */
.tm-table-wrap { width: 100%; overflow-x: auto; }
.tm-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.tm-table thead th {
  position: sticky; top: 0; z-index: 2;
  background: var(--surface-2); border-bottom: 1px solid var(--sep-strong);
  padding: 9px 11px; text-align: left; white-space: nowrap;
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.035em; color: var(--t3);
}
.tm-table thead th.tm-num { text-align: right; }
.tm-th-sort {
  display: inline-flex; align-items: center; gap: 4px;
  color: var(--t3); text-decoration: none; cursor: pointer;
  user-select: none; transition: color 0.15s;
}
.tm-th-sort.is-right { flex-direction: row-reverse; }
.tm-th-sort:hover { color: var(--t1); }
.tm-th-sort.is-sorted { color: var(--blue); }
.tm-sort-ico { flex-shrink: 0; }

.tm-table tbody tr { border-bottom: 1px solid var(--sep); transition: background 0.12s; }
.tm-table tbody tr:last-child { border-bottom: none; }
.tm-table tbody tr:hover { background: #f8f9fb; }
.tm-table tbody tr.is-selected { background: #f0f6ff; }
.tm-table tbody td { padding: 10px 11px; vertical-align: middle; color: var(--t1); }
.tm-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.tm-chk-cell { width: 34px; padding-left: 14px !important; padding-right: 4px !important; }
.tm-chk { width: 15px; height: 15px; accent-color: var(--blue); cursor: pointer; vertical-align: middle; }

/* Staff Primary Identity Cell */
.tm-staff { display: flex; align-items: center; gap: 10px; min-width: 195px; }
.tm-avatar {
  width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
  color: #ffffff; font-size: 12px; font-weight: 700; letter-spacing: 0.02em;
  display: inline-flex; align-items: center; justify-content: center;
  position: relative; overflow: hidden;
}
.tm-avatar img { width: 100%; height: 100%; object-fit: cover; }
.tm-onsite-pill {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 1px 7px; border-radius: 100px;
  background: var(--green-fill); color: var(--green);
  font-size: 10.5px; font-weight: 600;
}
.tm-staff-btn {
  background: none; border: none; padding: 0; margin: 0;
  font-family: inherit; font-size: 13.5px; font-weight: 600;
  color: var(--t1); cursor: pointer; text-align: left; line-height: 1.25;
}
.tm-staff-btn:hover { color: var(--blue); text-decoration: underline; }
.tm-staff-meta {
  display: flex; align-items: center; gap: 5px; flex-wrap: wrap;
  font-size: 11.5px; color: var(--t3); margin-top: 2px; line-height: 1.2;
}
.tm-emp-code { font-variant-numeric: tabular-nums; font-size: 11px; color: var(--t2); font-weight: 600; }

.tm-site-pill {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 2px 8px; border-radius: 6px;
  background: var(--gray-fill); color: var(--t1);
  font-size: 12px; font-weight: 500; white-space: nowrap;
}
.tm-site-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--blue); }
.tm-site-dot.is-qr { background: var(--teal); }
.tm-muted { color: var(--t4); font-size: 12.5px; }

/* Compact Row Actions & Accessible Overflow Menu */
.tm-row-actions { display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px; }
.tm-act-btn {
  display: inline-flex; align-items: center; gap: 4px;
  height: 27px; padding: 0 9px; border-radius: 6px;
  border: 1px solid var(--sep-strong); background: var(--surface);
  color: var(--t1); font-family: inherit; font-size: 11.5px; font-weight: 500;
  cursor: pointer; text-decoration: none; white-space: nowrap;
  transition: background 0.12s, border-color 0.12s, color 0.12s;
}
.tm-act-btn:hover { background: var(--gray-fill); border-color: rgba(0,0,0,0.24); color: var(--t1); }
.tm-act-btn.is-primary {
  background: var(--blue-fill); border-color: rgba(0, 113, 227, 0.25); color: var(--blue); font-weight: 600;
}
.tm-act-btn.is-primary:hover { background: rgba(0, 113, 227, 0.15); }

.tm-more-wrap { position: relative; display: inline-flex; }
.tm-more-menu {
  position: absolute; right: 0; top: calc(100% + 4px); z-index: 30;
  min-width: 198px; padding: 5px; background: var(--surface);
  border: 1px solid var(--sep-strong); border-radius: var(--r-sm);
  box-shadow: var(--shadow-float); text-align: left;
}
.tm-more-menu[hidden] { display: none !important; }
.tm-menu-item {
  display: flex; align-items: center; gap: 8px; width: 100%;
  padding: 7px 9px; border: none; background: transparent;
  border-radius: 6px; font-family: inherit; font-size: 12.5px;
  color: var(--t1); text-decoration: none; cursor: pointer; text-align: left;
}
.tm-menu-item:hover { background: var(--gray-fill); color: var(--blue); }
.tm-menu-sep { height: 1px; background: var(--sep); margin: 4px 2px; }

/* Dual View Mode — Enhanced Responsive Card Grid */
.tm-cards-sortbar {
  padding: 8px 16px; border-bottom: 1px solid var(--sep);
  background: var(--surface-2); display: flex; align-items: center;
  justify-content: space-between; gap: 8px; flex-wrap: wrap;
}
.tm-cards-grid {
  padding: 16px;
  display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
  gap: 14px;
}
.tm-card-item {
  background: var(--surface); border: 1px solid var(--sep-strong);
  border-radius: var(--r-md); padding: 15px 16px;
  display: flex; flex-direction: column; gap: 12px;
  transition: box-shadow 0.16s, border-color 0.16s;
}
.tm-card-item:hover {
  border-color: rgba(0, 113, 227, 0.38); box-shadow: var(--shadow-raise);
}
.tm-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.tm-card-metrics {
  display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 6px; padding: 9px 10px; border-radius: var(--r-xs);
  background: var(--surface-2); border: 1px solid var(--sep);
}
.tm-cm-box { display: flex; flex-direction: column; gap: 2px; }
.tm-cm-lbl { font-size: 10px; font-weight: 600; text-transform: uppercase; color: var(--t3); }
.tm-cm-val { font-size: 12.5px; font-weight: 600; color: var(--t1); font-variant-numeric: tabular-nums; }
.tm-card-foot {
  display: flex; align-items: center; justify-content: space-between;
  gap: 8px; padding-top: 8px; border-top: 1px solid var(--sep);
}

/* Empty State */
.tm-empty { padding: 48px 24px; text-align: center; }
.tm-empty-ico {
  width: 44px; height: 44px; border-radius: 12px;
  background: var(--gray-fill); color: var(--t3);
  display: inline-flex; align-items: center; justify-content: center;
  margin-bottom: 12px;
}
.tm-empty h3 { font-size: 15px; font-weight: 600; color: var(--t1); margin-bottom: 4px; }
.tm-empty p  { font-size: 13px; color: var(--t2); max-width: 430px; margin: 0 auto 14px; }

/* Pagination Footer */
.tm-footer {
  padding: 11px 16px; border-top: 1px solid var(--sep);
  background: var(--surface-2); border-radius: 0 0 var(--r-lg) var(--r-lg);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; font-size: 12.5px; color: var(--t2);
}
.tm-pages { display: inline-flex; align-items: center; gap: 4px; }
.tm-page-link {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 30px; height: 28px; padding: 0 8px;
  border-radius: 6px; border: 1px solid var(--sep-strong);
  background: var(--surface); color: var(--t1);
  font-size: 12px; font-weight: 500; text-decoration: none;
  font-variant-numeric: tabular-nums;
}
.tm-page-link:hover { background: var(--gray-fill); }
.tm-page-link.is-current { background: var(--blue); border-color: var(--blue); color: #ffffff; font-weight: 600; }
.tm-page-link.is-disabled { opacity: 0.42; pointer-events: none; }

.tm-mode-table .tm-cards-container { display: none; }
.tm-mode-cards .tm-table-wrap      { display: none; }

/* Slide-Over Right Drawer — Staff Inspector */
.tm-drawer-backdrop {
  position: fixed; inset: 0; z-index: 900;
  background: rgba(29, 29, 31, 0.36); backdrop-filter: blur(2px);
}
.tm-drawer-backdrop[hidden] { display: none !important; }
.tm-drawer {
  position: fixed; top: 0; right: 0; bottom: 0; z-index: 910;
  width: min(580px, 100vw); background: var(--surface);
  border-left: 1px solid var(--sep-strong);
  box-shadow: -12px 0 40px rgba(0, 0, 0, 0.14);
  display: flex; flex-direction: column;
}
.tm-drawer[hidden] { display: none !important; }
.tm-drawer-head {
  padding: 16px 20px; border-bottom: 1px solid var(--sep);
  background: var(--surface-2);
  display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
}
.tm-drawer-title { font-size: 17px; font-weight: 700; color: var(--t1); line-height: 1.25; }
.tm-drawer-sub { font-size: 12px; color: var(--t2); margin-top: 3px; }
.tm-drawer-nav { display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0; }
.tm-drawer-body { flex: 1; overflow-y: auto; padding: 16px 20px; }

.tm-dkpis {
  display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px; margin-bottom: 16px;
}
.tm-dkpi {
  padding: 10px 11px; border-radius: var(--r-xs);
  background: var(--surface-2); border: 1px solid var(--sep);
}
.tm-dkpi-lbl { font-size: 10px; font-weight: 600; text-transform: uppercase; color: var(--t3); }
.tm-dkpi-val { font-size: 16px; font-weight: 700; color: var(--t1); margin-top: 2px; font-variant-numeric: tabular-nums; }

.tm-dsec { margin-bottom: 16px; }
.tm-dsec-h {
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
  color: var(--t3); margin-bottom: 8px; padding-bottom: 5px; border-bottom: 1px solid var(--sep);
}
.tm-dl-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px 14px; font-size: 12.5px;
}
.tm-dl-item span { display: block; font-size: 10.5px; color: var(--t3); }
.tm-dl-item strong { font-weight: 600; color: var(--t1); word-break: break-word; }

.tm-mini-list { border: 1px solid var(--sep); border-radius: var(--r-xs); overflow: hidden; }
.tm-mini-row {
  padding: 8px 11px; border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 12px;
}
.tm-mini-row:last-child { border-bottom: none; }

.tm-drawer-foot {
  padding: 12px 20px; border-top: 1px solid var(--sep);
  background: var(--surface-2);
  display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
}

@media (max-width: 1100px) {
  .tm-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 780px) {
  .tm-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .tm-dkpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .tm-dl-grid { grid-template-columns: 1fr; }
  .tm-search-input { width: 100%; }
  .tm-tools-right { width: 100%; justify-content: space-between; }
  .tm-shell:not(.tm-user-forced-table) .tm-table-wrap { display: none; }
  .tm-shell:not(.tm-user-forced-table) .tm-cards-container { display: block; }
}

@media print {
  .tm-print-banner {
    display: block !important; margin-bottom: 14px;
    padding-bottom: 10px; border-bottom: 2px solid #1d1d1f;
  }
  .tm-print-banner h2 { font-size: 16px; margin: 0 0 3px; }
  .tm-print-banner p  { font-size: 11px; color: #444; margin: 0; }
  .tm-head, .tm-leave-card, .tm-filter-bar, .tm-toolbar, .tm-chips-bar,
  .tm-batch-bar, .tm-footer, .tm-cards-container, .tm-drawer, .tm-drawer-backdrop, .no-print {
    display: none !important;
  }
  .tm-shell { border: none !important; box-shadow: none !important; }
  .tm-table-wrap { display: block !important; overflow: visible !important; }
  .tm-table th, .tm-table td { padding: 6px 8px !important; font-size: 11px !important; }
}
</style>

<!-- Print-only Workforce Directory Header -->
<div class="tm-print-banner">
  <h2>WORKFORCE DIRECTORY &amp; COMPLIANCE ROSTER</h2>
  <p>
    Staff Shown: <strong><?= (int)count($workers) ?></strong> of <strong><?= (int)$total_filtered ?></strong>
    <?php if ($site_filter !== ''): ?> · Site: <strong><?= htmlspecialchars($site_filter, ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?>
    · Printed <?= date('d M Y H:i') ?>
  </p>
</div>

<!-- Page Header & Module Quick Navigation -->
<div class="tm-head no-print">
  <div class="tm-head-title">
    <h1>Team Management</h1>
    <p class="tm-sub">Workforce directory, live on-site availability, active work order capacity, and certification compliance.</p>
  </div>

  <div class="tm-head-actions">
    <a href="<?= site_url('team/shift_roster') ?>" class="btn btn-ghost btn-sm btn-icon">
      <svg class="tm-ico" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
      Shift Roster
    </a>
    <a href="<?= site_url('team/certifications') ?>" class="btn btn-ghost btn-sm btn-icon">
      <svg class="tm-ico" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="5"/><polyline points="8.5 13 7 22 12 19 17 22 15.5 13"/></svg>
      Certifications
    </a>
    <a href="<?= site_url('team/report') ?>" class="btn btn-ghost btn-sm btn-icon">
      <svg class="tm-ico" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      AML Timesheets
    </a>
    <a href="<?= site_url('team/leave_request') ?>" class="btn btn-ghost btn-sm btn-icon">
      <svg class="tm-ico" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Leave Request
    </a>
    <a href="<?= site_url('team/roster_csv') . ($export_qs !== '' ? '?' . $export_qs : '') ?>"
       class="btn btn-ghost btn-sm btn-icon"
       title="Export filtered workforce directory as UTF-8 CSV">
      <svg class="tm-ico" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export CSV
    </a>
    <?php if (!empty($is_supervisor)): ?>
      <a href="<?= site_url('team/create') ?>" class="btn btn-primary btn-sm">
        <svg class="tm-ico" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Add Worker
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- Executive Workforce Summary Strip -->
<section class="tm-kpis" aria-label="Workforce summary metrics">
  <div class="tm-kpi">
    <div class="tm-kpi-lbl">Staff in Scope</div>
    <div class="tm-kpi-val"><?= (int)$summary['total_staff'] ?></div>
    <div class="tm-kpi-meta">
      <span><?= (int)$summary['active_staff'] ?> active · <?= (int)$summary['inactive_staff'] ?> inactive</span>
    </div>
  </div>

  <div class="tm-kpi">
    <div class="tm-kpi-lbl">On Site Today</div>
    <div class="tm-kpi-val" style="color:var(--green);"><?= (int)$summary['on_site_today'] ?></div>
    <div class="tm-kpi-meta">
      <span><?= (int)$summary['on_leave_today'] ?> on approved leave</span>
    </div>
  </div>

  <div class="tm-kpi">
    <div class="tm-kpi-lbl">Assigned Open WOs</div>
    <div class="tm-kpi-val"><?= (int)$summary['open_wos'] ?></div>
    <div class="tm-kpi-meta">
      <?php if ((int)$summary['critical_wos'] > 0): ?>
        <span style="color:var(--red);font-weight:600;"><?= (int)$summary['critical_wos'] ?> critical priority</span>
      <?php else: ?>
        <span>No critical backlog</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="tm-kpi">
    <div class="tm-kpi-lbl">Cert Compliance</div>
    <div class="tm-kpi-val">
      <?php $cert_issues = (int)$summary['cert_expired'] + (int)$summary['cert_expiring']; ?>
      <?php if ((int)$summary['cert_expired'] > 0): ?>
        <span style="color:var(--red);"><?= (int)$summary['cert_expired'] ?></span>
        <span style="font-size:13px;font-weight:500;color:var(--t2);">expired</span>
      <?php elseif ((int)$summary['cert_expiring'] > 0): ?>
        <span style="color:var(--amber);"><?= (int)$summary['cert_expiring'] ?></span>
        <span style="font-size:13px;font-weight:500;color:var(--t2);">expiring</span>
      <?php else: ?>
        <span style="color:var(--green);font-size:19px;">All Valid</span>
      <?php endif; ?>
    </div>
    <div class="tm-kpi-meta">
      <?= $cert_issues > 0 ? ((int)$summary['cert_expiring'] . ' expiring within alert window') : 'Zero expired certificates' ?>
    </div>
  </div>

  <div class="tm-kpi">
    <div class="tm-kpi-lbl">Leave Queue</div>
    <div class="tm-kpi-val" style="color:<?= (int)$summary['pending_leave'] > 0 ? 'var(--amber)' : 'var(--t1)' ?>;">
      <?= (int)$summary['pending_leave'] ?>
    </div>
    <div class="tm-kpi-meta">
      <?= (int)$summary['pending_leave'] > 0 ? 'Awaiting supervisor decision' : 'No pending requests' ?>
    </div>
  </div>
</section>

<!-- Pending Leave Approval Queue (Supervisors only) -->
<?php if (!empty($pending_leave)): ?>
<section class="tm-leave-card no-print" aria-label="Leave requests awaiting approval">
  <div class="tm-leave-head">
    <div class="tm-leave-title">
      <span class="badge b-warning"><?= count($pending_leave) ?> Pending</span>
      <span>Leave Requests Awaiting Approval</span>
    </div>
    <span style="font-size:12px;color:var(--t2);">Approving automatically marks dates as Leave on attendance</span>
  </div>
  <div style="overflow-x:auto;">
    <table class="tm-leave-tbl">
      <thead>
        <tr>
          <th>Staff Member</th>
          <th>Leave Type</th>
          <th>Dates</th>
          <th class="tm-num">Days</th>
          <th>Open Workload</th>
          <th>Reason</th>
          <th class="tm-num">Decision</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pending_leave as $lr):
            $lr_uid = (int)$lr->user_id;
            $lr_row = isset($roster_by_id[$lr_uid]) ? $roster_by_id[$lr_uid] : NULL;
            $lr_wos = $lr_row ? (int)$lr_row->active_wo_count : 0;
        ?>
        <tr>
          <td>
            <a href="<?= site_url('team/view/' . $lr_uid) ?>" style="font-weight:600;color:var(--t1);text-decoration:none;">
              <?= htmlspecialchars((string)$lr->worker_name, ENT_QUOTES, 'UTF-8') ?>
            </a>
            <div class="mono" style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)$lr->employee_id, ENT_QUOTES, 'UTF-8') ?></div>
          </td>
          <td><span class="badge b-primary"><?= htmlspecialchars((string)$lr->leave_type, ENT_QUOTES, 'UTF-8') ?></span></td>
          <td class="mono" style="white-space:nowrap;font-size:12px;">
            <?= date('d M Y', strtotime($lr->start_date)) ?> &ndash; <?= date('d M Y', strtotime($lr->end_date)) ?>
          </td>
          <td class="tm-num fw-600"><?= (int)$lr->days_count ?></td>
          <td>
            <?php if ($lr_wos > 0): ?>
              <span class="badge b-warning" title="Assigned to open work orders — reassign coverage before leave starts">
                <?= $lr_wos ?> Open WO<?= $lr_wos === 1 ? '' : 's' ?>
              </span>
            <?php else: ?>
              <span style="font-size:12px;color:var(--t3);">Clear (0 WOs)</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:var(--t2);max-width:260px;"><?= htmlspecialchars((string)$lr->reason, ENT_QUOTES, 'UTF-8') ?></td>
          <td class="tm-num">
            <?= form_open('team/approve_leave/' . (int)$lr->id, array('class' => 'js-leave-form', 'style' => 'display:inline-flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap;')) ?>
              <input type="text" name="notes" class="tm-input" placeholder="Decision note (optional)" aria-label="Decision note for <?= htmlspecialchars((string)$lr->worker_name, ENT_QUOTES, 'UTF-8') ?>" style="width:165px;height:28px;font-size:11.5px;">
              <button type="submit" name="status" value="Approved" class="btn btn-primary btn-sm" style="padding:4px 12px;font-size:11.5px;">Approve</button>
              <button type="submit" name="status" value="Rejected" class="btn btn-danger btn-sm js-reject-btn"
                      data-worker="<?= htmlspecialchars((string)$lr->worker_name, ENT_QUOTES, 'UTF-8') ?>"
                      style="padding:4px 11px;font-size:11.5px;">Reject</button>
            <?= form_close() ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<!-- Main Enterprise Workforce DataTable Surface -->
<div class="tm-shell tm-mode-<?= htmlspecialchars($view_mode, ENT_QUOTES, 'UTF-8') ?>" id="tmShell">

  <!-- Scope Filter Bar -->
  <form method="get" action="<?= site_url('team') ?>" class="tm-filter-bar no-print" id="tmFilterForm">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="dir" value="<?= htmlspecialchars($sort_dir, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
    <input type="hidden" name="view" id="tmViewHidden" value="<?= htmlspecialchars($view_mode, ENT_QUOTES, 'UTF-8') ?>">

    <div class="tm-field">
      <label for="tmSite">Site</label>
      <select name="site" id="tmSite" class="tm-select tm-autosubmit">
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

    <div class="tm-field">
      <label for="tmRole">Role</label>
      <select name="role" id="tmRole" class="tm-select tm-autosubmit">
        <option value="">All Roles</option>
        <?php foreach ($roles_list as $rl): ?>
          <option value="<?= (int)$rl->id ?>" <?= $role_filter === (int)$rl->id ? 'selected' : '' ?>>
            <?= htmlspecialchars(ucfirst((string)$rl->name), ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="tm-field">
      <label for="tmSkill">Skill Level</label>
      <select name="skill" id="tmSkill" class="tm-select tm-autosubmit">
        <option value="">All Levels</option>
        <?php foreach ($skills_list as $sk): ?>
          <option value="<?= htmlspecialchars($sk, ENT_QUOTES, 'UTF-8') ?>" <?= $skill_filter === $sk ? 'selected' : '' ?>>
            <?= htmlspecialchars($sk, ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <button type="submit" class="btn btn-primary btn-sm" style="height:32px;">Apply</button>
  </form>

  <!-- Status Categorization Tabs + Search + View Switcher -->
  <div class="tm-toolbar no-print">
    <div class="tm-tabs" role="tablist" aria-label="Workforce status categories">
      <?php foreach ($tab_defs as $t_key => $t_info):
          $is_on = ($active_tab === $t_key);
      ?>
        <a href="<?= htmlspecialchars($tm_url('team', array('tab' => $t_key, 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
           role="tab"
           aria-selected="<?= $is_on ? 'true' : 'false' ?>"
           class="tm-tab <?= $is_on ? 'is-active' : '' ?>">
          <span><?= htmlspecialchars($t_info['label'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="tm-tab-cnt"><?= $t_info['count'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="tm-tools-right">
      <div class="tm-search-wrap">
        <svg class="tm-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search"
               form="tmFilterForm"
               name="q"
               id="tmSearchInput"
               value="<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="Search name, ID, role, phone, site…"
               aria-label="Search workforce by name, ID, email, phone, position, or site"
               class="tm-search-input">
        <?php if ($search_query !== ''): ?>
          <a href="<?= htmlspecialchars($tm_url('team', array('q' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
             class="tm-search-clear"
             aria-label="Clear search query"
             title="Clear search">&times;</a>
        <?php else: ?>
          <kbd class="tm-kbd" aria-hidden="true">/</kbd>
        <?php endif; ?>
      </div>

      <div class="tm-view-seg" role="group" aria-label="Display layout">
        <a href="<?= htmlspecialchars($tm_url('team', array('view' => 'table')), ENT_QUOTES, 'UTF-8') ?>"
           data-set-view="table"
           aria-pressed="<?= $view_mode === 'table' ? 'true' : 'false' ?>"
           class="tm-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>
          Table
        </a>
        <a href="<?= htmlspecialchars($tm_url('team', array('view' => 'cards')), ENT_QUOTES, 'UTF-8') ?>"
           data-set-view="cards"
           aria-pressed="<?= $view_mode === 'cards' ? 'true' : 'false' ?>"
           class="tm-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          Cards
        </a>
      </div>
    </div>
  </div>

  <!-- Active Filter Chips Strip -->
  <?php if ($has_active_filters): ?>
    <div class="tm-chips-bar no-print" aria-label="Active filters">
      <span style="color:var(--t3);font-weight:500;">Active filters:</span>
      <?php if ($site_filter !== ''): ?>
        <a class="tm-chip" href="<?= htmlspecialchars($tm_url('team', array('site' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Site: <?= htmlspecialchars($site_filter, ENT_QUOTES, 'UTF-8') ?> <span>&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($role_filter > 0 && isset($role_lookup[$role_filter])): ?>
        <a class="tm-chip" href="<?= htmlspecialchars($tm_url('team', array('role' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Role: <?= htmlspecialchars($role_lookup[$role_filter], ENT_QUOTES, 'UTF-8') ?> <span>&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($skill_filter !== ''): ?>
        <a class="tm-chip" href="<?= htmlspecialchars($tm_url('team', array('skill' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Skill: <?= htmlspecialchars($skill_filter, ENT_QUOTES, 'UTF-8') ?> <span>&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($active_tab !== 'all'): ?>
        <a class="tm-chip" href="<?= htmlspecialchars($tm_url('team', array('tab' => 'all', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Status: <?= htmlspecialchars($tab_defs[$active_tab]['label'], ENT_QUOTES, 'UTF-8') ?> <span>&times;</span>
        </a>
      <?php endif; ?>
      <?php if ($search_query !== ''): ?>
        <a class="tm-chip" href="<?= htmlspecialchars($tm_url('team', array('q' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Search: &ldquo;<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>&rdquo; <span>&times;</span>
        </a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($clean_reset_url, ENT_QUOTES, 'UTF-8') ?>" class="tm-chip-reset">Reset all</a>
    </div>
  <?php endif; ?>

  <!-- Contextual Multi-Select Batch Action Bar -->
  <div class="tm-batch-bar no-print" id="tmBatchBar" hidden role="region" aria-label="Selected staff batch actions">
    <div class="tm-batch-count">
      <span id="tmSelectedCount">0</span> staff selected
    </div>
    <div class="tm-batch-actions">
      <a href="#" id="tmBatchPdf" target="_blank" rel="noopener" class="btn btn-primary btn-sm">
        Export Selected AML PDF
      </a>
      <a href="#" id="tmBatchXlsx" class="btn btn-ghost btn-sm">
        Export Selected XLSX
      </a>
      <a href="#" id="tmBatchCsv" class="btn btn-ghost btn-sm">
        Export Selected CSV
      </a>
      <button type="button" id="tmBatchClear" class="btn btn-ghost btn-sm">Clear selection</button>
    </div>
  </div>

  <?php if (empty($workers)): ?>
    <div class="tm-empty">
      <div class="tm-empty-ico" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </div>
      <?php if ($has_active_filters): ?>
        <h3>No staff members match your filters</h3>
        <p>No records matched the current status tab, site, role, skill level, or search query.</p>
        <a href="<?= htmlspecialchars($clean_reset_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost">Reset all filters</a>
      <?php else: ?>
        <h3>No staff members found</h3>
        <p>Add your first workshop or field team member to get started.</p>
        <?php if (!empty($is_supervisor)): ?>
          <a href="<?= site_url('team/create') ?>" class="btn btn-primary">Add Worker</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php else: ?>

    <!-- 1. Enterprise Workforce Table View -->
    <div class="tm-table-wrap">
      <table class="tm-table">
        <thead>
          <tr>
            <th scope="col" class="tm-chk-cell no-print">
              <input type="checkbox" id="tmCheckAll" class="tm-chk" aria-label="Select all staff on this page">
            </th>
            <?= $sort_th('name', 'Staff Member', 'left', 'asc') ?>
            <?= $sort_th('skill', 'Role & Skill', 'left', 'desc') ?>
            <?= $sort_th('site', 'Site & Shift', 'left', 'asc') ?>
            <?= $sort_th('active_wos', 'Open WOs', 'right', 'desc') ?>
            <?= $sort_th('certs', 'Certifications', 'left', 'desc') ?>
            <?= $sort_th('join_date', 'Joined', 'left', 'asc') ?>
            <?= $sort_th('status', 'Status', 'left', 'asc') ?>
            <th scope="col" class="tm-num no-print">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($workers as $w):
              $uid       = (int)$w->id;
              $init      = strtoupper(substr($w->first_name, 0, 1) . substr($w->last_name, 0, 1));
              $av_bg     = $avatar_palette[$uid % count($avatar_palette)];
              $sk_cls    = isset($skill_badges[$w->skill_level]) ? $skill_badges[$w->skill_level] : 'b-secondary';
              $st_cls    = isset($status_badges[$w->effective_status]) ? $status_badges[$w->effective_status] : 'b-secondary';
              $json_url  = site_url('team/member_json/' . $uid);
              $prof_url  = site_url('team/view/' . $uid);
              $edit_url  = site_url('team/edit/' . $uid);
              $pdf_url   = site_url('team/timesheet/' . $uid);
              $cert_url  = site_url('team/certifications/' . $uid);
              $site_tip  = ($w->site_source === 'qr_period' || $w->site_source === 'qr_recent')
                  ? 'Derived from QR check-in history'
                  : 'Assigned profile site';
          ?>
          <tr data-member-row="<?= $uid ?>"
              data-json-url="<?= htmlspecialchars($json_url, ENT_QUOTES, 'UTF-8') ?>"
              data-profile-url="<?= htmlspecialchars($prof_url, ENT_QUOTES, 'UTF-8') ?>"
              data-edit-url="<?= htmlspecialchars($edit_url, ENT_QUOTES, 'UTF-8') ?>"
              data-pdf-url="<?= htmlspecialchars($pdf_url, ENT_QUOTES, 'UTF-8') ?>"
              data-member-name="<?= htmlspecialchars($w->full_name, ENT_QUOTES, 'UTF-8') ?>">

            <td class="tm-chk-cell no-print">
              <input type="checkbox" class="tm-chk tm-row-chk" value="<?= $uid ?>"
                     aria-label="Select <?= htmlspecialchars($w->full_name, ENT_QUOTES, 'UTF-8') ?>">
            </td>

            <td>
              <div class="tm-staff">
                <span class="tm-avatar" style="background:<?= $av_bg ?>;" aria-hidden="true">
                  <?php if ($w->photo !== ''): ?>
                    <img src="<?= base_url('uploads/photos/' . rawurlencode(basename($w->photo))) ?>" alt="">
                  <?php else: ?>
                    <?= htmlspecialchars($init, ENT_QUOTES, 'UTF-8') ?>
                  <?php endif; ?>
                </span>
                <div>
                  <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <button type="button" class="tm-staff-btn js-open-member" data-uid="<?= $uid ?>">
                      <?= htmlspecialchars($w->full_name, ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <?php if ($w->on_site_today): ?>
                      <span class="tm-onsite-pill" title="Checked in on site today"><span class="led led-ok"></span>On Site</span>
                    <?php endif; ?>
                  </div>
                  <div class="tm-staff-meta">
                    <span class="tm-emp-code"><?= htmlspecialchars($w->employee_id, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($w->position !== ''): ?>
                      <span aria-hidden="true">·</span>
                      <span><?= htmlspecialchars($w->position, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </td>

            <td>
              <div style="font-size:12.5px;font-weight:500;color:var(--t1);"><?= htmlspecialchars(ucfirst($w->role_name), ENT_QUOTES, 'UTF-8') ?></div>
              <span class="badge <?= $sk_cls ?>" style="margin-top:3px;font-size:10.5px;"><?= htmlspecialchars($w->skill_level, ENT_QUOTES, 'UTF-8') ?></span>
            </td>

            <td>
              <?php if ($w->site !== ''): ?>
                <span class="tm-site-pill" title="<?= htmlspecialchars($site_tip, ENT_QUOTES, 'UTF-8') ?>">
                  <span class="tm-site-dot <?= ($w->site_source === 'qr_period' || $w->site_source === 'qr_recent') ? 'is-qr' : '' ?>" aria-hidden="true"></span>
                  <?= htmlspecialchars($w->site, ENT_QUOTES, 'UTF-8') ?>
                </span>
              <?php else: ?>
                <span class="tm-muted">—</span>
              <?php endif; ?>
              <?php if ($w->current_shift !== ''): ?>
                <div style="font-size:11px;color:var(--t3);margin-top:3px;"><?= htmlspecialchars($w->current_shift, ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
            </td>

            <td class="tm-num">
              <?php if ($w->active_wo_count > 0): ?>
                <span class="mono fw-600" style="font-size:13px;color:var(--t1);"><?= (int)$w->active_wo_count ?></span>
                <?php if ($w->critical_wo_count > 0): ?>
                  <span class="led led-crit" style="display:inline-block;margin-left:4px;vertical-align:middle;"
                        title="<?= (int)$w->critical_wo_count ?> Critical priority work order(s)"></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="tm-muted mono">0</span>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($w->cert_expired > 0): ?>
                <span class="badge b-danger"><?= (int)$w->cert_expired ?> Expired</span>
              <?php elseif ($w->cert_expiring > 0): ?>
                <span class="badge b-warning"><?= (int)$w->cert_expiring ?> Expiring</span>
              <?php elseif ($w->cert_valid > 0): ?>
                <span class="badge b-available"><?= (int)$w->cert_valid ?> Valid</span>
              <?php else: ?>
                <span class="tm-muted">—</span>
              <?php endif; ?>
            </td>

            <td class="mono" style="font-size:12px;color:var(--t2);white-space:nowrap;">
              <?= $w->join_date !== '' ? date('d M Y', strtotime($w->join_date)) : '<span class="tm-muted">—</span>' ?>
            </td>

            <td>
              <span class="badge <?= $st_cls ?>"
                    <?= $w->active_leave_type !== '' ? ('title="' . htmlspecialchars($w->active_leave_type, ENT_QUOTES, 'UTF-8') . '"') : '' ?>>
                <?= htmlspecialchars($w->effective_status, ENT_QUOTES, 'UTF-8') ?>
              </span>
            </td>

            <td class="tm-num no-print">
              <div class="tm-row-actions">
                <button type="button"
                        class="tm-act-btn is-primary js-open-member"
                        data-uid="<?= $uid ?>"
                        title="Open quick summary drawer">
                  Inspect
                </button>
                <a href="<?= htmlspecialchars($prof_url, ENT_QUOTES, 'UTF-8') ?>" class="tm-act-btn">
                  Profile
                </a>
                <div class="tm-more-wrap">
                  <button type="button"
                          class="tm-act-btn js-more-toggle"
                          aria-haspopup="menu"
                          aria-expanded="false"
                          aria-label="More actions for <?= htmlspecialchars($w->full_name, ENT_QUOTES, 'UTF-8') ?>">
                    &bull;&bull;&bull;
                  </button>
                  <div class="tm-more-menu" role="menu" hidden>
                    <button type="button" role="menuitem" class="tm-menu-item js-open-member" data-uid="<?= $uid ?>">
                      Inspect Summary Drawer
                    </button>
                    <a role="menuitem" href="<?= htmlspecialchars($prof_url, ENT_QUOTES, 'UTF-8') ?>" class="tm-menu-item">
                      Open Full Profile
                    </a>
                    <a role="menuitem" href="<?= htmlspecialchars($pdf_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="tm-menu-item">
                      AML Timesheet (PDF)
                    </a>
                    <a role="menuitem" href="<?= htmlspecialchars($cert_url, ENT_QUOTES, 'UTF-8') ?>" class="tm-menu-item">
                      Certifications (<?= (int)$w->cert_total ?>)
                    </a>
                    <?php if (!empty($is_supervisor)): ?>
                      <div class="tm-menu-sep" role="separator"></div>
                      <a role="menuitem" href="<?= htmlspecialchars($edit_url, ENT_QUOTES, 'UTF-8') ?>" class="tm-menu-item">
                        Edit Worker Profile
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
    <div class="tm-cards-container">
      <div class="tm-cards-sortbar no-print">
        <span style="font-size:12px;color:var(--t2);">Card layout · Sorted by <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $sort_by)), ENT_QUOTES, 'UTF-8') ?></strong> (<?= strtoupper($sort_dir) ?>)</span>
        <div style="display:inline-flex;align-items:center;gap:6px;">
          <label for="tmCardSort" style="font-size:11.5px;color:var(--t3);margin:0;">Sort:</label>
          <select id="tmCardSort" class="tm-select" style="height:28px;font-size:12px;">
            <?php
            $card_sorts = array(
                'name:asc'        => 'Name (A–Z)',
                'name:desc'       => 'Name (Z–A)',
                'active_wos:desc' => 'Open WOs (Most)',
                'active_wos:asc'  => 'Open WOs (Fewest)',
                'skill:desc'      => 'Skill Level (Highest)',
                'certs:desc'      => 'Cert Alerts First',
                'site:asc'        => 'Site (A–Z)',
                'employee_id:asc' => 'Employee ID',
            );
            $cur_pair = $sort_by . ':' . $sort_dir;
            foreach ($card_sorts as $pair => $lbl):
                list($s_col, $s_d) = explode(':', $pair);
            ?>
              <option value="<?= htmlspecialchars($tm_url('team', array('sort' => $s_col, 'dir' => $s_d, 'view' => 'cards', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
                      <?= $cur_pair === $pair ? 'selected' : '' ?>>
                <?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="tm-cards-grid">
        <?php foreach ($workers as $w):
            $uid    = (int)$w->id;
            $init   = strtoupper(substr($w->first_name, 0, 1) . substr($w->last_name, 0, 1));
            $av_bg  = $avatar_palette[$uid % count($avatar_palette)];
            $sk_cls = isset($skill_badges[$w->skill_level]) ? $skill_badges[$w->skill_level] : 'b-secondary';
            $st_cls = isset($status_badges[$w->effective_status]) ? $status_badges[$w->effective_status] : 'b-secondary';
        ?>
          <article class="tm-card-item">
            <div class="tm-card-top">
              <div class="tm-staff" style="min-width:0;">
                <span class="tm-avatar" style="background:<?= $av_bg ?>;" aria-hidden="true">
                  <?php if ($w->photo !== ''): ?>
                    <img src="<?= base_url('uploads/photos/' . rawurlencode(basename($w->photo))) ?>" alt="">
                  <?php else: ?>
                    <?= htmlspecialchars($init, ENT_QUOTES, 'UTF-8') ?>
                  <?php endif; ?>
                </span>
                <div>
                  <button type="button" class="tm-staff-btn js-open-member" data-uid="<?= $uid ?>">
                    <?= htmlspecialchars($w->full_name, ENT_QUOTES, 'UTF-8') ?>
                  </button>
                  <div class="tm-staff-meta">
                    <span class="tm-emp-code"><?= htmlspecialchars($w->employee_id, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($w->position !== ''): ?>
                      <span>· <?= htmlspecialchars($w->position, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <span class="badge <?= $st_cls ?>"><?= htmlspecialchars($w->effective_status, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="tm-card-metrics">
              <div class="tm-cm-box">
                <span class="tm-cm-lbl">Skill</span>
                <span class="tm-cm-val"><?= htmlspecialchars($w->skill_level, ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              <div class="tm-cm-box">
                <span class="tm-cm-lbl">Open WOs</span>
                <span class="tm-cm-val"><?= (int)$w->active_wo_count ?></span>
              </div>
              <div class="tm-cm-box">
                <span class="tm-cm-lbl">Certs</span>
                <span class="tm-cm-val" style="color:<?= $w->cert_expired > 0 ? 'var(--red)' : ($w->cert_expiring > 0 ? 'var(--amber)' : 'var(--t1)') ?>;">
                  <?= $w->cert_expired > 0 ? ($w->cert_expired . ' Exp') : ($w->cert_expiring > 0 ? ($w->cert_expiring . ' Soon') : ($w->cert_valid > 0 ? ($w->cert_valid . ' OK') : '—')) ?>
                </span>
              </div>
            </div>

            <div class="tm-card-foot">
              <div>
                <?php if ($w->on_site_today): ?>
                  <span class="tm-onsite-pill"><span class="led led-ok"></span><?= htmlspecialchars($w->site !== '' ? $w->site : 'On Site', ENT_QUOTES, 'UTF-8') ?></span>
                <?php elseif ($w->site !== ''): ?>
                  <span class="tm-site-pill"><?= htmlspecialchars($w->site, ENT_QUOTES, 'UTF-8') ?></span>
                <?php else: ?>
                  <span style="font-size:11.5px;color:var(--t3);"><?= htmlspecialchars(ucfirst($w->role_name), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
              </div>
              <div class="tm-row-actions">
                <button type="button" class="tm-act-btn is-primary js-open-member" data-uid="<?= $uid ?>">Inspect</button>
                <a href="<?= site_url('team/view/' . $uid) ?>" class="tm-act-btn">Profile</a>
                <?php if (!empty($is_supervisor)): ?>
                  <a href="<?= site_url('team/edit/' . $uid) ?>" class="tm-act-btn">Edit</a>
                <?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>

  <?php endif; ?>

  <!-- Pagination & Page-Size Footer -->
  <div class="tm-footer no-print">
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
      <nav class="tm-pages" aria-label="Workforce pagination">
        <a class="tm-page-link <?= $page <= 1 ? 'is-disabled' : '' ?>"
           href="<?= htmlspecialchars($tm_url('team', array('page' => max(1, $page - 1))), ENT_QUOTES, 'UTF-8') ?>">
          Prev
        </a>
        <?php
        $win_start = max(1, $page - 2);
        $win_end   = min($total_pages, $page + 2);
        if ($win_start > 1): ?>
          <a class="tm-page-link" href="<?= htmlspecialchars($tm_url('team', array('page' => 1)), ENT_QUOTES, 'UTF-8') ?>">1</a>
          <?php if ($win_start > 2): ?><span style="padding:0 4px;color:var(--t3);">&hellip;</span><?php endif; ?>
        <?php endif;
        for ($p = $win_start; $p <= $win_end; $p++): ?>
          <a class="tm-page-link <?= $p === $page ? 'is-current' : '' ?>"
             <?= $p === $page ? 'aria-current="page"' : '' ?>
             href="<?= htmlspecialchars($tm_url('team', array('page' => $p)), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
        <?php endfor;
        if ($win_end < $total_pages): ?>
          <?php if ($win_end < $total_pages - 1): ?><span style="padding:0 4px;color:var(--t3);">&hellip;</span><?php endif; ?>
          <a class="tm-page-link" href="<?= htmlspecialchars($tm_url('team', array('page' => $total_pages)), ENT_QUOTES, 'UTF-8') ?>"><?= $total_pages ?></a>
        <?php endif; ?>
        <a class="tm-page-link <?= $page >= $total_pages ? 'is-disabled' : '' ?>"
           href="<?= htmlspecialchars($tm_url('team', array('page' => min($total_pages, $page + 1))), ENT_QUOTES, 'UTF-8') ?>">
          Next
        </a>
      </nav>
    <?php endif; ?>

    <div style="display:inline-flex;align-items:center;gap:6px;">
      <label for="tmPerPage" style="font-size:12px;color:var(--t3);margin:0;">Rows:</label>
      <select id="tmPerPage" class="tm-select" style="height:28px;font-size:12px;">
        <?php foreach (array(10, 25, 50, 100) as $pp): ?>
          <option value="<?= htmlspecialchars($tm_url('team', array('per_page' => $pp, 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
                  <?= $per_page === $pp ? 'selected' : '' ?>>
            <?= $pp ?> / page
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>

<!-- Right-Side Slide-Over Staff Profile & Workload Inspector Drawer -->
<div class="tm-drawer-backdrop no-print" id="tmDrawerBackdrop" hidden></div>
<aside class="tm-drawer no-print" id="tmDrawer" role="dialog" aria-modal="true" aria-labelledby="tmDrawerTitle" hidden>
  <div class="tm-drawer-head">
    <div>
      <div class="tm-drawer-title" id="tmDrawerTitle">Staff Profile</div>
      <div class="tm-drawer-sub" id="tmDrawerSub">Loading details…</div>
    </div>
    <div class="tm-drawer-nav">
      <button type="button" class="tm-act-btn" id="tmDrawerPrev" title="Previous staff member (Left arrow)" aria-label="Previous staff member">&larr;</button>
      <button type="button" class="tm-act-btn" id="tmDrawerNext" title="Next staff member (Right arrow)" aria-label="Next staff member">&rarr;</button>
      <button type="button" class="tm-act-btn" id="tmDrawerClose" title="Close drawer (Esc)" aria-label="Close drawer">&times;</button>
    </div>
  </div>

  <div class="tm-drawer-body" id="tmDrawerBody">
    <p style="color:var(--t3);font-size:13px;">Loading staff overview…</p>
  </div>

  <div class="tm-drawer-foot">
    <div style="display:inline-flex;gap:8px;flex-wrap:wrap;">
      <a href="#" id="tmDrawerPdf" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">AML Sheet (PDF)</a>
      <?php if (!empty($is_supervisor)): ?>
        <a href="#" id="tmDrawerEdit" class="btn btn-ghost btn-sm">Edit Profile</a>
      <?php endif; ?>
    </div>
    <a href="#" id="tmDrawerProfile" class="btn btn-primary btn-sm">Open Full Profile &rarr;</a>
  </div>
</aside>

<script>
(function () {
  var shell      = document.getElementById('tmShell');
  var form       = document.getElementById('tmFilterForm');
  var viewHidden = document.getElementById('tmViewHidden');
  var searchInp  = document.getElementById('tmSearchInput');
  var perPageSel = document.getElementById('tmPerPage');
  var cardSort   = document.getElementById('tmCardSort');
  var woBaseUrl  = <?= json_encode(site_url('workorders/view')) ?>;

  // 1. Auto-submit select filters & pagination / sort controls
  var autoInputs = document.querySelectorAll('.tm-autosubmit');
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

  // Confirmation + double-submit guard on Pending Leave decisions
  var rejectBtns = document.querySelectorAll('.js-reject-btn');
  for (var rb = 0; rb < rejectBtns.length; rb++) {
    rejectBtns[rb].addEventListener('click', function (ev) {
      var wName = this.getAttribute('data-worker') || 'this staff member';
      if (!window.confirm('Reject leave request for ' + wName + '?')) {
        ev.preventDefault();
      }
    });
  }

  // 2. Instant Table <-> Cards View Switcher
  var viewBtns = document.querySelectorAll('[data-set-view]');
  for (var v = 0; v < viewBtns.length; v++) {
    viewBtns[v].addEventListener('click', function (ev) {
      var mode = this.getAttribute('data-set-view');
      if (!shell || !mode) return;
      ev.preventDefault();
      shell.classList.remove('tm-mode-table', 'tm-mode-cards');
      shell.classList.add('tm-mode-' + mode);
      if (mode === 'table') shell.classList.add('tm-user-forced-table');
      else shell.classList.remove('tm-user-forced-table');
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
  var checkAll    = document.getElementById('tmCheckAll');
  var rowChks     = document.querySelectorAll('.tm-row-chk');
  var batchBar    = document.getElementById('tmBatchBar');
  var selCountEl  = document.getElementById('tmSelectedCount');
  var batchPdf    = document.getElementById('tmBatchPdf');
  var batchXlsx   = document.getElementById('tmBatchXlsx');
  var batchCsv    = document.getElementById('tmBatchCsv');
  var batchClear  = document.getElementById('tmBatchClear');
  var baseExportQ = <?= json_encode($export_qs) ?>;
  var batchTsBase = <?= json_encode(site_url('team/timesheets_batch')) ?>;
  var csvBase     = <?= json_encode(site_url('team/roster_csv')) ?>;

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
    var idsParam = 'ids=' + encodeURIComponent(ids.join(','));
    var csvPrefix = baseExportQ ? (baseExportQ + '&') : '';
    if (batchPdf)  batchPdf.href  = batchTsBase + '?' + idsParam;
    if (batchXlsx) batchXlsx.href = batchTsBase + '?' + idsParam + '&format=xlsx';
    if (batchCsv)  batchCsv.href  = csvBase + '?' + csvPrefix + idsParam;
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
    var openMenus = document.querySelectorAll('.tm-more-menu:not([hidden])');
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

  // 5. Right-Side Slide-Over Staff Inspector Drawer
  var drawer      = document.getElementById('tmDrawer');
  var backdrop    = document.getElementById('tmDrawerBackdrop');
  var dTitle      = document.getElementById('tmDrawerTitle');
  var dSub        = document.getElementById('tmDrawerSub');
  var dBody       = document.getElementById('tmDrawerBody');
  var dClose      = document.getElementById('tmDrawerClose');
  var dPrev       = document.getElementById('tmDrawerPrev');
  var dNext       = document.getElementById('tmDrawerNext');
  var dProfile    = document.getElementById('tmDrawerProfile');
  var dEdit       = document.getElementById('tmDrawerEdit');
  var dPdf        = document.getElementById('tmDrawerPdf');
  var memberCache = {};
  var orderedUids = [];
  var rowMap      = {};
  var activeUid   = null;
  var lastTrigger = null;

  var allRows = document.querySelectorAll('tr[data-member-row]');
  for (var r = 0; r < allRows.length; r++) {
    var uid = String(allRows[r].getAttribute('data-member-row'));
    orderedUids.push(uid);
    rowMap[uid] = {
      name:    allRows[r].getAttribute('data-member-name') || '',
      jsonUrl: allRows[r].getAttribute('data-json-url') || '',
      profUrl: allRows[r].getAttribute('data-profile-url') || '#',
      editUrl: allRows[r].getAttribute('data-edit-url') || '#',
      pdfUrl:  allRows[r].getAttribute('data-pdf-url') || '#'
    };
  }

  function escHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function renderMemberPayload(d) {
    var siteTxt = d.site ? String(d.site) : 'No site assigned';
    dTitle.textContent = d.full_name;
    dSub.textContent   = d.employee_id + ' · ' + (d.position || d.role_name) + ' · ' + siteTxt;

    var attTxt = (d.att_30d && d.att_30d.pct !== null) ? (d.att_30d.pct + '%') : 'No records';
    var wos    = d.open_workorders || [];
    var certs  = d.certifications || [];
    var leaves = d.recent_leaves || [];
    var leaveRem = (d.annual_remaining !== null && d.annual_remaining !== undefined) ? (d.annual_remaining + 'd') : '—';

    var html = '<div class="tm-dkpis">'
      + '<div class="tm-dkpi"><div class="tm-dkpi-lbl">Open WOs</div><div class="tm-dkpi-val">' + wos.length + '</div></div>'
      + '<div class="tm-dkpi"><div class="tm-dkpi-lbl">30d Attendance</div><div class="tm-dkpi-val" style="font-size:14.5px;">' + escHtml(attTxt) + '</div></div>'
      + '<div class="tm-dkpi"><div class="tm-dkpi-lbl">Certifications</div><div class="tm-dkpi-val">' + certs.length + '</div></div>'
      + '<div class="tm-dkpi"><div class="tm-dkpi-lbl">Annual Leave</div><div class="tm-dkpi-val">' + escHtml(leaveRem) + '</div></div>'
      + '</div>';

    // Section 1: Assignment & Contact Details
    html += '<div class="tm-dsec"><div class="tm-dsec-h">Assignment &amp; Contact</div><div class="tm-dl-grid">'
      + '<div class="tm-dl-item"><span>Role &amp; Skill</span><strong>' + escHtml(d.role_name) + ' · ' + escHtml(d.skill_level) + '</strong></div>'
      + '<div class="tm-dl-item"><span>Status</span><strong>' + escHtml(d.status) + (d.on_site_today ? ' (On Site Today)' : '') + '</strong></div>'
      + '<div class="tm-dl-item"><span>Email</span><strong>' + (d.email ? '<a href="mailto:' + escHtml(d.email) + '" style="color:var(--blue);text-decoration:none;">' + escHtml(d.email) + '</a>' : '—') + '</strong></div>'
      + '<div class="tm-dl-item"><span>Phone</span><strong>' + (d.phone ? '<a href="tel:' + escHtml(d.phone) + '" style="color:var(--t1);text-decoration:none;">' + escHtml(d.phone) + '</a>' : '—') + '</strong></div>'
      + '<div class="tm-dl-item"><span>Joined</span><strong>' + escHtml(d.join_date || '—') + '</strong></div>'
      + '<div class="tm-dl-item"><span>Last Login</span><strong>' + escHtml(d.last_login || 'Never') + '</strong></div>'
      + '</div></div>';

    // Section 2: Assigned Open Work Orders
    html += '<div class="tm-dsec"><div class="tm-dsec-h">Assigned Open Work Orders (' + wos.length + ')</div>';
    if (wos.length === 0) {
      html += '<div style="font-size:12.5px;color:var(--t3);">No open work orders currently assigned.</div>';
    } else {
      html += '<div class="tm-mini-list">';
      for (var w = 0; w < wos.length; w++) {
        var wo = wos[w];
        var pCls = wo.priority === 'Critical' ? 'b-danger' : (wo.priority === 'High' ? 'b-warning' : 'b-secondary');
        html += '<div class="tm-mini-row">'
          + '<div><a href="' + escHtml(woBaseUrl + '/' + wo.id) + '" style="font-weight:600;color:var(--blue);text-decoration:none;">' + escHtml(wo.wo_number) + '</a>'
          + (wo.machine ? ' · <strong>' + escHtml(wo.machine) + '</strong>' : '')
          + '<div style="color:var(--t2);font-size:11.5px;">' + escHtml(wo.title) + '</div></div>'
          + '<div style="text-align:right;white-space:nowrap;"><span class="badge ' + pCls + '">' + escHtml(wo.priority) + '</span>'
          + '<div style="font-size:11px;color:var(--t3);margin-top:2px;">' + escHtml(wo.status) + '</div></div>'
          + '</div>';
      }
      html += '</div>';
    }
    html += '</div>';

    // Section 3: Certifications & Compliance
    html += '<div class="tm-dsec"><div class="tm-dsec-h">Certifications &amp; Licenses (' + certs.length + ')</div>';
    if (certs.length === 0) {
      html += '<div style="font-size:12.5px;color:var(--t3);">No certifications recorded on file.</div>';
    } else {
      html += '<div class="tm-mini-list">';
      for (var c = 0; c < certs.length; c++) {
        var ct = certs[c];
        var cCls = ct.status === 'Expired' ? 'b-danger' : (ct.status === 'Expiring Soon' ? 'b-warning' : 'b-available');
        html += '<div class="tm-mini-row">'
          + '<div><strong>' + escHtml(ct.cert_name) + '</strong>'
          + '<div style="font-size:11px;color:var(--t3);">' + escHtml(ct.issuing_body || '—') + (ct.expiry_date ? ' · Expires ' + escHtml(ct.expiry_date) : '') + '</div></div>'
          + '<span class="badge ' + cCls + '">' + escHtml(ct.status) + '</span>'
          + '</div>';
      }
      html += '</div>';
    }
    html += '</div>';

    // Section 4: Recent Leave Requests
    html += '<div class="tm-dsec"><div class="tm-dsec-h">Recent Leave Requests (' + leaves.length + ')</div>';
    if (leaves.length === 0) {
      html += '<div style="font-size:12.5px;color:var(--t3);">No leave history recorded.</div>';
    } else {
      html += '<div class="tm-mini-list">';
      for (var l = 0; l < leaves.length; l++) {
        var lv = leaves[l];
        var lCls = lv.status === 'Approved' ? 'b-available' : (lv.status === 'Rejected' ? 'b-danger' : 'b-warning');
        html += '<div class="tm-mini-row">'
          + '<div><strong>' + escHtml(lv.leave_type) + '</strong> · ' + Number(lv.days_count) + 'd'
          + '<div style="font-size:11px;color:var(--t3);">' + escHtml(lv.start_date) + ' – ' + escHtml(lv.end_date) + '</div></div>'
          + '<span class="badge ' + lCls + '">' + escHtml(lv.status) + '</span>'
          + '</div>';
      }
      html += '</div>';
    }
    html += '</div>';

    dBody.innerHTML = html;
  }

  function openMember(uid, triggerEl) {
    var key  = String(uid);
    var meta = rowMap[key];
    if (!meta || !drawer) return;
    closeAllMenus();
    activeUid = key;
    if (triggerEl) lastTrigger = triggerEl;

    var idx = orderedUids.indexOf(key);
    if (dPrev) dPrev.disabled = (idx <= 0);
    if (dNext) dNext.disabled = (idx < 0 || idx >= orderedUids.length - 1);

    dTitle.textContent = meta.name || 'Staff Profile';
    dSub.textContent   = 'Loading staff details…';
    if (dProfile) dProfile.href = meta.profUrl;
    if (dEdit)    dEdit.href    = meta.editUrl;
    if (dPdf)     dPdf.href     = meta.pdfUrl;

    backdrop.hidden = false;
    drawer.hidden   = false;
    dClose.focus();

    if (memberCache[meta.jsonUrl]) {
      renderMemberPayload(memberCache[meta.jsonUrl]);
      return;
    }
    dBody.innerHTML = '<p style="color:var(--t3);font-size:13px;">Loading workload, attendance &amp; compliance details…</p>';
    fetch(meta.jsonUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (activeUid !== key) return;
        if (!json || !json.ok) {
          dBody.innerHTML = '<div class="flash flash-err">' + escHtml((json && json.error) || 'Unable to load staff summary.') + '</div>';
          return;
        }
        memberCache[meta.jsonUrl] = json;
        renderMemberPayload(json);
      })
      .catch(function () {
        if (activeUid !== key) return;
        dBody.innerHTML = '<div class="flash flash-err">Network error loading summary. Use Open Full Profile below.</div>';
      });
  }

  function closeDrawer() {
    if (!drawer || drawer.hidden) return;
    drawer.hidden   = true;
    backdrop.hidden = true;
    activeUid       = null;
    if (lastTrigger && typeof lastTrigger.focus === 'function') {
      lastTrigger.focus();
    }
  }

  var openTriggers = document.querySelectorAll('.js-open-member');
  for (var o = 0; o < openTriggers.length; o++) {
    openTriggers[o].addEventListener('click', function () {
      openMember(this.getAttribute('data-uid'), this);
    });
  }
  if (dClose)   dClose.addEventListener('click', closeDrawer);
  if (backdrop) backdrop.addEventListener('click', closeDrawer);
  if (dPrev) {
    dPrev.addEventListener('click', function () {
      var idx = orderedUids.indexOf(activeUid);
      if (idx > 0) openMember(orderedUids[idx - 1], null);
    });
  }
  if (dNext) {
    dNext.addEventListener('click', function () {
      var idx = orderedUids.indexOf(activeUid);
      if (idx >= 0 && idx < orderedUids.length - 1) openMember(orderedUids[idx + 1], null);
    });
  }

  // 6. Global Keyboard Shortcuts ('/' search, Esc close drawer/menu, ArrowLeft/Right step staff)
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
        openMember(orderedUids[idx - 1], null);
      } else if (ev.key === 'ArrowRight' && idx >= 0 && idx < orderedUids.length - 1) {
        ev.preventDefault();
        openMember(orderedUids[idx + 1], null);
      }
    }
  });
})();
</script>
