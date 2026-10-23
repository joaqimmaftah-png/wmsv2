<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
// ── Helpers & context ──────────────────────────────────────────────
$current_user_id = (int)$this->session->userdata('user_id');
$is_me           = function ($id) use ($current_user_id) { return (int)$id === $current_user_id; };

$role_colors  = array('Supervisor'=>'b-critical','Technician'=>'b-primary','Store'=>'b-warning','Store Keeper'=>'b-warning','Manager'=>'b-info','CEO'=>'b-success');
$status_colors= array('Active'=>'b-available','Inactive'=>'b-danger','On Leave'=>'b-warning');

$tab_defs = array(
    'all'      => array('label' => 'All Users',     'count' => (int)($tab_counts['all'] ?? $stats['total'] ?? 0)),
    'active'   => array('label' => 'Active',        'count' => (int)($tab_counts['active'] ?? $stats['active'] ?? 0)),
    'inactive' => array('label' => 'Inactive',      'count' => (int)($tab_counts['inactive'] ?? $stats['inactive'] ?? 0)),
    'on_leave' => array('label' => 'On Leave',      'count' => (int)($tab_counts['on_leave'] ?? 0)),
);
$active_tab = isset($active_tab) ? (string)$active_tab : 'all';

$base_params = array(
    'role'     => isset($filter['role_id']) ? (string)$filter['role_id'] : '',
    'status'   => isset($filter['status']) ? (string)$filter['status'] : '',
    'q'        => isset($filter['search']) ? (string)$filter['search'] : '',
    'sort'     => isset($sort_by) ? $sort_by : 'name',
    'dir'      => isset($sort_dir) ? $sort_dir : 'asc',
    'per_page' => (string)(isset($per_page) ? $per_page : 25),
);
$us_url = function ($path, array $overrides = array()) use ($base_params) {
    $merged = array_merge($base_params, $overrides);
    $clean  = array();
    $defaults = array(
        'role'     => '',
        'status'   => '',
        'q'        => '',
        'sort'     => 'name',
        'dir'      => 'asc',
        'per_page' => '25',
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

$has_active_filters = (
    (isset($filter['role_id']) && $filter['role_id'] !== '') ||
    (isset($filter['status']) && $filter['status'] !== '') ||
    (isset($filter['search']) && $filter['search'] !== '')
);

$clean_reset_url = site_url('users') . '?sort=' . rawurlencode(isset($sort_by) ? $sort_by : 'name')
                 . '&dir=' . rawurlencode(isset($sort_dir) ? $sort_dir : 'asc');

$sort_th = function ($col_key, $label, $align = 'left') use ($sort_by, $sort_dir, $us_url) {
    $is_active = ($sort_by === $col_key);
    $next_dir  = $is_active ? (($sort_dir === 'asc') ? 'desc' : 'asc') : 'asc';
    $aria_sort = $is_active ? (($sort_dir === 'asc') ? 'ascending' : 'descending') : 'none';
    $href      = $us_url('users', array('sort' => $col_key, 'dir' => $next_dir, 'page' => 1));
    $cls       = 'us-th-sort' . ($align === 'right' ? ' is-right' : '') . ($is_active ? ' is-sorted' : '');

    $icon = '<svg class="us-sort-ico" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">';
    if ($is_active && $sort_dir === 'asc') {
        $icon .= '<path d="M6 2.5L9.2 6.2H2.8L6 2.5Z" fill="currentColor"/><path d="M6 9.5L2.8 7.2H9.2L6 9.5Z" fill="currentColor" opacity="0.22"/>';
    } elseif ($is_active && $sort_dir === 'desc') {
        $icon .= '<path d="M6 2.5L9.2 4.8H2.8L6 2.5Z" fill="currentColor" opacity="0.22"/><path d="M6 9.5L2.8 5.8H9.2L6 9.5Z" fill="currentColor"/>';
    } else {
        $icon .= '<path d="M6 2.5L8.8 5.3H3.2L6 2.5ZM6 9.5L3.2 6.7H8.8L6 9.5Z" fill="currentColor" opacity="0.35"/>';
    }
    $icon .= '</svg>';

    return '<th scope="col" aria-sort="' . $aria_sort . '" class="' . ($align === 'right' ? 'us-num' : '') . '">'
         . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . $cls . '">'
         . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>' . $icon
         . '</a></th>';
};

// Last login relative time (re-uses the same logic as the previous view).
$last_login_of = function ($u) {
    if (empty($u->last_login)) return array('txt' => 'Never logged in', 'sub' => '', 'none' => TRUE);
    $ts = strtotime((string)$u->last_login);
    if (!$ts) return array('txt' => '—', 'sub' => '', 'none' => TRUE);
    $diff = time() - $ts;
    if ($diff < 0)            { $txt = 'Just now'; $sub = date('H:i', $ts); }
    elseif ($diff < 3600)     { $txt = round($diff / 60) . 'm ago'; $sub = date('H:i', $ts); }
    elseif ($diff < 86400)    { $txt = round($diff / 3600) . 'h ago'; $sub = date('H:i', $ts); }
    elseif ($diff < 604800)   { $txt = round($diff / 86400) . 'd ago'; $sub = date('H:i', $ts); }
    else                      { $txt = date('d M Y', $ts); $sub = date('H:i', $ts); }
    return array('txt' => $txt, 'sub' => $sub, 'none' => FALSE);
};
?>
<style>
/* ════════════════════════════════════════════════════════════════════════════════════════════
   WMSv2 Enterprise Data Table — User Access Management
   Mirrors /team/report design system; native Apple-HIG tokens from layouts/header.php
   plus local tokens not defined there.
═════════════════════════════════════════════════════════════════════════════════════════════ */
:root {
  --us-font: 'Inter', -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", sans-serif;
  --us-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  --us-fill: #f1f1f4;
  --us-fill-2: rgba(0,0,0,0.06);
  --us-sep-opaque: rgba(0,0,0,0.12);
  --us-r-pill: 100px;
  --us-ease: cubic-bezier(0.4, 0, 0.2, 1);
}
.us-print-banner { display: none; }

/* ── Page header ── */
.us-head {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; flex-wrap: wrap; margin-bottom: 20px;
}
.us-crumb {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 500; color: var(--t3); margin-bottom: 4px;
}
.us-crumb a { color: var(--t2); text-decoration: none; }
.us-crumb a:hover { color: var(--blue); }
.us-head-title {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.us-head-title h1 {
  font-size: 24px; font-weight: 700; letter-spacing: -0.025em;
  color: var(--t1); line-height: 1.2; margin: 0;
}
.us-sub { font-size: 13px; color: var(--t2); margin-top: 3px; }
.us-head-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* ── KPI strip ── */
.us-kpis {
  display: grid; grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px; margin-bottom: 18px;
}
.us-kpi {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-md); padding: 14px 16px;
  box-shadow: var(--shadow-card);
  display: flex; flex-direction: column; justify-content: space-between;
}
.us-kpi-label {
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--t3); margin-bottom: 6px;
}
.us-kpi-val {
  font-size: 24px; font-weight: 700; letter-spacing: -0.03em;
  color: var(--t1); line-height: 1.05;
  font-variant-numeric: tabular-nums;
}
.us-kpi-meta {
  margin-top: 6px; font-size: 11.5px; color: var(--t3);
  display: flex; align-items: center; gap: 5px;
}

/* ── Main surface ── */
.us-shell {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-lg); box-shadow: var(--shadow-card);
  overflow: visible;
}

/* ── Scope filter bar ── */
.us-scope-bar {
  padding: 12px 16px; background: var(--surface-2);
  border-bottom: 1px solid var(--sep);
  border-radius: var(--r-lg) var(--r-lg) 0 0;
  display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap;
}
.us-field { display: flex; flex-direction: column; gap: 4px; }
.us-field label {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--t3); margin: 0;
}
.us-input, .us-select {
  height: 32px; padding: 0 10px; font-family: var(--us-font); font-size: 12.5px;
  color: var(--t1); background: var(--surface);
  border: 1px solid var(--sep-opaque); border-radius: var(--r-sm);
  outline: none; transition: border-color var(--us-ease), box-shadow var(--us-ease);
}
.us-select {
  padding-right: 28px; cursor: pointer; appearance: none; -webkit-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236e6e73' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 8px center;
}
.us-input:focus, .us-select:focus {
  border-color: var(--blue); box-shadow: 0 0 0 3px var(--blue-fill);
}

/* ── Toolbar: tabs + search ── */
.us-toolbar {
  padding: 10px 16px; border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; background: var(--surface);
}
.us-tabs {
  display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap;
}
.us-tab {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 11px; border-radius: var(--r-sm);
  font-size: 12.5px; font-weight: 500; color: var(--t2);
  text-decoration: none; border: 1px solid transparent;
  transition: background var(--us-ease), color var(--us-ease), border-color var(--us-ease);
}
.us-tab:hover { background: var(--us-fill); color: var(--t1); }
.us-tab.is-active {
  background: var(--blue-fill); color: var(--blue); font-weight: 600;
  border-color: rgba(0, 113, 227, 0.22);
}
.us-tab-cnt {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 18px; padding: 0 6px;
  border-radius: var(--r-pill); font-size: 11px; font-weight: 600;
  background: var(--us-fill); color: var(--t2);
  font-variant-numeric: tabular-nums;
}
.us-tab.is-active .us-tab-cnt { background: var(--blue); color: #ffffff; }

.us-tools-right {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto;
}
.us-search-wrap { position: relative; display: inline-flex; align-items: center; }
.us-search-ico {
  position: absolute; left: 9px; width: 14px; height: 14px;
  color: var(--t3); pointer-events: none;
}
.us-search-input {
  height: 32px; width: 235px; padding: 0 54px 0 29px;
  font-family: var(--us-font); font-size: 12.5px; color: var(--t1);
  background: var(--surface-2); border: 1px solid var(--sep-opaque);
  border-radius: var(--r-sm); outline: none;
  transition: border-color var(--us-ease), background var(--us-ease), box-shadow var(--us-ease);
}
.us-search-input:focus {
  background: var(--surface); border-color: var(--blue);
  box-shadow: 0 0 0 3px var(--blue-fill);
}
.us-kbd {
  position: absolute; right: 8px; padding: 1px 5px;
  font-family: var(--us-mono); font-size: 10.5px; color: var(--t3);
  background: var(--surface); border: 1px solid var(--sep-opaque);
  border-radius: 4px; pointer-events: none; line-height: 1.3;
}
.us-search-input:focus ~ .us-kbd { display: none; }
.us-search-clear {
  position: absolute; right: 6px; width: 20px; height: 20px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 50%; background: var(--us-fill); color: var(--t2);
  font-size: 13px; text-decoration: none; line-height: 1;
}
.us-search-clear:hover { background: var(--sep-opaque); color: var(--t1); }

/* ── Active filter chips ── */
.us-chips-bar {
  padding: 8px 16px; background: #fbfbfd; border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 12px;
}
.us-chip-label { color: var(--t3); font-weight: 500; margin-right: 2px; }
.us-chip {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 2px 8px; border-radius: var(--r-pill);
  background: var(--surface); border: 1px solid var(--sep-opaque);
  color: var(--t1); font-size: 11.5px; font-weight: 500; text-decoration: none;
}
.us-chip:hover { border-color: var(--red); color: var(--red); }
.us-chip-x { color: var(--t3); font-weight: 700; }
.us-chip-reset {
  margin-left: auto; font-size: 12px; font-weight: 500; color: var(--blue);
}
.us-chip-reset:hover { text-decoration: underline; }

/* ── Enterprise table ── */
.us-table-wrap { width: 100%; overflow-x: auto; }
.us-table {
  width: 100%; border-collapse: collapse; font-size: 13px;
}
.us-table thead th {
  position: sticky; top: 0; z-index: 2;
  background: var(--surface-2);
  border-bottom: 1px solid var(--sep-opaque);
  padding: 9px 11px; text-align: left; white-space: nowrap;
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.035em; color: var(--t3);
}
.us-table thead th.us-num { text-align: right; }
.us-th-sort {
  display: inline-flex; align-items: center; gap: 4px;
  color: var(--t3); text-decoration: none; cursor: pointer;
  user-select: none; transition: color var(--us-ease);
}
.us-th-sort.is-right { flex-direction: row-reverse; }
.us-th-sort:hover { color: var(--t1); }
.us-th-sort.is-sorted { color: var(--blue); }
.us-sort-ico { flex-shrink: 0; }

.us-table tbody tr {
  border-bottom: 1px solid var(--sep);
  transition: background var(--us-ease);
}
.us-table tbody tr:last-child { border-bottom: none; }
.us-table tbody tr:hover { background: #f8f9fb; }
.us-table tbody td {
  padding: 10px 11px; vertical-align: middle; color: var(--t1);
}
.us-num {
  text-align: right; font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.us-chk-cell { width: 34px; padding-left: 14px !important; padding-right: 4px !important; }

/* ── Worker identity cell ── */
.us-worker {
  display: flex; align-items: center; gap: 10px; min-width: 190px;
}
.us-avatar {
  width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
  background: var(--blue-fill); color: var(--blue);
  font-size: 11.5px; font-weight: 700; letter-spacing: 0.02em;
  display: inline-flex; align-items: center; justify-content: center;
}
.us-worker-info { min-width: 0; }
.us-worker-name {
  background: none; border: none; padding: 0; margin: 0;
  font-family: var(--us-font); font-size: 13.5px; font-weight: 600;
  color: var(--t1); cursor: pointer; text-align: left; line-height: 1.25;
  display: inline-flex; align-items: center; gap: 5px; text-decoration: none;
}
.us-worker-name:hover { color: var(--blue); text-decoration: underline; }
.us-worker-meta {
  display: flex; align-items: center; gap: 5px; flex-wrap: wrap;
  font-size: 11.5px; color: var(--t3); margin-top: 2px; line-height: 1.2;
}
.us-emp-code {
  font-family: var(--us-mono); font-size: 11px; color: var(--t2); font-weight: 500;
}
.us-you {
  font-size: 9.5px; color: var(--blue); font-weight: 700; letter-spacing: 0;
}

/* ── Row actions ── */
.us-row-actions {
  display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px;
}
.us-act-btn {
  display: inline-flex; align-items: center; gap: 4px;
  height: 27px; padding: 0 8px; border-radius: 6px;
  border: 1px solid var(--sep-opaque); background: var(--surface);
  color: var(--t1); font-family: var(--us-font); font-size: 11.5px; font-weight: 500;
  cursor: pointer; text-decoration: none; white-space: nowrap;
  transition: background var(--us-ease), border-color var(--us-ease), color var(--us-ease);
}
.us-act-btn:hover { background: var(--us-fill); border-color: #b8b8bf; color: var(--t1); }
.us-act-btn.is-primary {
  background: var(--blue-fill); border-color: rgba(0, 113, 227, 0.25); color: var(--blue);
  font-weight: 600;
}
.us-act-btn.is-primary:hover { background: rgba(0, 113, 227, 0.16); }
.us-act-btn.is-danger { color: var(--red); border-color: rgba(192,57,43,0.2); }
.us-act-btn.is-danger:hover { background: rgba(192,57,43,0.1); }
.us-act-btn.is-success { color: var(--green); border-color: rgba(28,140,60,0.2); }
.us-act-btn.is-success:hover { background: rgba(28,140,60,0.1); }

.us-more-wrap { position: relative; display: inline-flex; }
.us-more-menu {
  position: absolute; right: 0; top: calc(100% + 4px); z-index: 30;
  min-width: 205px; padding: 5px; background: var(--surface);
  border: 1px solid var(--sep-opaque); border-radius: var(--r-md);
  box-shadow: var(--shadow-md); text-align: left;
}
.us-more-menu[hidden] { display: none !important; }
.us-menu-item {
  display: flex; align-items: center; gap: 8px; width: 100%;
  padding: 7px 9px; border: none; background: transparent;
  border-radius: 6px; font-family: var(--us-font); font-size: 12.5px;
  color: var(--t1); text-decoration: none; cursor: pointer; text-align: left;
}
.us-menu-item:hover { background: var(--us-fill); color: var(--blue); }
.us-menu-sep { height: 1px; background: var(--sep); margin: 4px 2px; }

/* ── Card view (mobile) ── */
.us-cards-sortbar {
  padding: 8px 16px; border-bottom: 1px solid var(--sep);
  background: var(--surface-2); display: flex; align-items: center;
  justify-content: space-between; gap: 8px; flex-wrap: wrap;
}
.us-cards-grid {
  padding: 16px;
  display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 14px;
}
.us-card-item {
  background: var(--surface); border: 1px solid var(--sep-opaque);
  border-radius: var(--r-md); padding: 14px 16px;
  display: flex; flex-direction: column; gap: 12px;
  transition: box-shadow var(--us-ease), border-color var(--us-ease);
}
.us-card-item:hover {
  border-color: rgba(0, 113, 227, 0.4); box-shadow: var(--shadow-md);
}
.us-card-top {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 10px;
}
.us-card-metrics {
  display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 6px; padding: 9px 10px; border-radius: var(--r-sm);
  background: var(--surface-2); border: 1px solid var(--sep);
}
.us-cm-box { display: flex; flex-direction: column; gap: 2px; }
.us-cm-lbl { font-size: 10px; font-weight: 600; text-transform: uppercase; color: var(--t3); }
.us-cm-val { font-family: var(--us-mono); font-size: 13px; font-weight: 600; color: var(--t1); }
.us-card-foot {
  display: flex; align-items: center; justify-content: space-between;
  gap: 8px; padding-top: 8px; border-top: 1px solid var(--sep);
}

/* ── Empty state ── */
.us-empty {
  padding: 48px 24px; text-align: center;
}
.us-empty-ico {
  width: 44px; height: 44px; border-radius: 12px;
  background: var(--us-fill); color: var(--t3);
  display: inline-flex; align-items: center; justify-content: center;
  margin-bottom: 12px;
}
.us-empty h3 { font-size: 15px; font-weight: 600; color: var(--t1); margin-bottom: 4px; }
.us-empty p { font-size: 13px; color: var(--t2); max-width: 420px; margin: 0 auto 14px; }

/* ── Pagination footer ── */
.us-footer {
  padding: 11px 16px; border-top: 1px solid var(--sep);
  background: var(--surface-2); border-radius: 0 0 var(--r-lg) var(--r-lg);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; font-size: 12.5px; color: var(--t2);
}
.us-pages { display: inline-flex; align-items: center; gap: 4px; }
.us-page-link {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 30px; height: 28px; padding: 0 8px;
  border-radius: 6px; border: 1px solid var(--sep-opaque);
  background: var(--surface); color: var(--t1);
  font-size: 12px; font-weight: 500; text-decoration: none;
  font-variant-numeric: tabular-nums;
}
.us-page-link:hover { background: var(--us-fill); }
.us-page-link.is-current {
  background: var(--blue); border-color: var(--blue); color: #ffffff; font-weight: 600;
}
.us-page-link.is-disabled { opacity: 0.42; pointer-events: none; }

/* ── View switching ── */
.us-mode-table .us-cards-container { display: none; }
.us-mode-cards .us-table-wrap      { display: none; }

@media (max-width: 780px) {
  .us-shell:not(.us-user-forced-table) .us-table-wrap { display: none; }
  .us-shell:not(.us-user-forced-table) .us-cards-container { display: block; }
  .us-search-input { width: 100%; }
  .us-tools-right { width: 100%; justify-content: space-between; }
}

/* ── Clean print ── */
@media print {
  .us-head, .us-scope-bar, .us-toolbar, .us-chips-bar, .us-footer,
  .us-cards-container, .no-print { display: none !important; }
  .us-shell { border: none !important; box-shadow: none !important; }
  .us-table-wrap { display: block !important; overflow: visible !important; }
  .us-table th, .us-table td { padding: 6px 8px !important; font-size: 11px !important; }
}
</style>

<!-- Page Header -->
<div class="us-head no-print">
  <div>
    <nav class="us-crumb" aria-label="Breadcrumb">
      <a href="<?= site_url('team') ?>">Team</a>
      <span aria-hidden="true">/</span>
      <a href="<?= site_url('users') ?>">Users</a>
      <span aria-hidden="true">/</span>
      <span>User Access Management</span>
    </nav>
    <div class="us-head-title">
      <h1><?= htmlspecialchars((string)$page_title, ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <p class="us-sub">Manage system accounts, roles, and access status for workshop staff.</p>
  </div>
  <div class="us-head-actions">
    <a href="<?= base_url('users/create') ?>" class="btn btn-primary">
      <svg class="ts-btn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      Add User
    </a>
  </div>
</div>

<!-- Executive KPI Summary Strip -->
<section class="us-kpis" aria-label="User account summary">
  <div class="us-kpi">
    <div class="us-kpi-label">Total Accounts</div>
    <div class="us-kpi-val"><?= (int)($stats['total'] ?? 0) ?></div>
    <div class="us-kpi-meta">All registered users</div>
  </div>
  <div class="us-kpi">
    <div class="us-kpi-label">Active</div>
    <div class="us-kpi-val" style="color:var(--green);"><?= (int)($stats['active'] ?? 0) ?></div>
    <div class="us-kpi-meta">Can sign in</div>
  </div>
  <div class="us-kpi">
    <div class="us-kpi-label">Inactive</div>
    <div class="us-kpi-val" style="color:var(--red);"><?= (int)($stats['inactive'] ?? 0) ?></div>
    <div class="us-kpi-meta">Access disabled</div>
  </div>
  <div class="us-kpi">
    <div class="us-kpi-label">Roles</div>
    <div class="us-kpi-val"><?= count($roles ?? array()) ?></div>
    <div class="us-kpi-meta">Defined in system</div>
  </div>
  <?php if (!empty($stats['legacy'])): ?>
    <div class="us-kpi">
      <div class="us-kpi-label">Legacy Hash</div>
      <div class="us-kpi-val" style="color:var(--amber);"><?= (int)$stats['legacy'] ?></div>
      <div class="us-kpi-meta">MD5/SHA1 — reset required</div>
    </div>
  <?php endif; ?>
</section>

<!-- Main Enterprise Data Surface -->
<div class="us-shell us-mode-table" id="usShell">

  <!-- Scope Filter Form -->
  <form method="get" action="<?= site_url('users') ?>" class="us-scope-bar no-print" id="usFilterForm">
    <input type="hidden" name="sort" value="<?= htmlspecialchars(isset($sort_by) ? $sort_by : 'name', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="dir" value="<?= htmlspecialchars(isset($sort_dir) ? $sort_dir : 'asc', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="per_page" value="<?= (int)(isset($per_page) ? $per_page : 25) ?>">
    <input type="hidden" id="usViewHidden" name="view" value="table">

    <div class="us-field">
      <label for="usRole">Role</label>
      <select name="role" id="usRole" class="us-select us-autosubmit">
        <option value="">All Roles</option>
        <?php foreach ($roles ?? array() as $r): ?>
          <option value="<?= (int)$r->id ?>" <?= ((isset($filter['role_id']) && $filter['role_id'] == $r->id) ? 'selected' : '') ?>>
            <?= htmlspecialchars((string)$r->name, ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="us-field">
      <label for="usStatus">Status</label>
      <select name="status" id="usStatus" class="us-select us-autosubmit">
        <option value="">All Status</option>
        <option value="Active"   <?= ((isset($filter['status']) && $filter['status'] === 'Active')   ? 'selected' : '') ?>>Active</option>
        <option value="Inactive" <?= ((isset($filter['status']) && $filter['status'] === 'Inactive') ? 'selected' : '') ?>>Inactive</option>
        <option value="On Leave" <?= ((isset($filter['status']) && $filter['status'] === 'On Leave') ? 'selected' : '') ?>>On Leave</option>
      </select>
    </div>

    <button type="submit" class="btn btn-primary btn-sm" style="height:32px;">Apply</button>
    <a href="<?= htmlspecialchars($clean_reset_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost btn-sm">Reset</a>
  </form>

  <!-- Status Categorization Tabs + Search + View Toggle -->
  <div class="us-toolbar no-print">
    <div class="us-tabs" role="tablist" aria-label="User status filter">
      <?php
      $tab_status_overrides = array(
          'all'      => array('status' => ''),
          'active'   => array('status' => 'Active'),
          'inactive' => array('status' => 'Inactive'),
          'on_leave' => array('status' => 'On Leave'),
      );
      foreach ($tab_defs as $t_key => $t_info):
          $is_on = ($active_tab === $t_key);
          $status_override = $tab_status_overrides[$t_key];
      ?>
        <a href="<?= htmlspecialchars($us_url('users', array_merge($status_override, array('page' => 1))), ENT_QUOTES, 'UTF-8') ?>"
           role="tab" aria-selected="<?= $is_on ? 'true' : 'false' ?>"
           class="us-tab <?= $is_on ? 'is-active' : '' ?>">
          <span><?= htmlspecialchars($t_info['label'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="us-tab-cnt"><?= $t_info['count'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="us-tools-right">
      <div class="us-search-wrap">
        <svg class="us-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" form="usFilterForm" name="q" id="usSearchInput"
               value="<?= htmlspecialchars((string)(isset($filter['search']) ? $filter['search'] : ''), ENT_QUOTES, 'UTF-8') ?>"
               placeholder="Search name, email, ID…"
               aria-label="Search users by name, email, or employee ID"
               class="us-search-input">
        <?php if ((isset($filter['search']) && $filter['search'] !== '')): ?>
          <a href="<?= htmlspecialchars($us_url('users', array('q' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
             class="us-search-clear" aria-label="Clear search query" title="Clear search">&times;</a>
        <?php else: ?>
          <kbd class="us-kbd" aria-hidden="true">/</kbd>
        <?php endif; ?>
      </div>

      <div style="display:inline-flex;align-items:center;gap:6px;">
        <label for="usPerPage" style="font-size:12px;color:var(--t3);margin:0;">Rows:</label>
        <select id="usPerPage" class="us-select" style="height:28px;font-size:12px;">
          <?php foreach (array(10, 25, 50, 100) as $pp): ?>
            <option value="<?= htmlspecialchars($us_url('users', array('per_page' => $pp, 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
                    <?= ((isset($per_page) && $per_page == $pp) ? 'selected' : '') ?>>
              <?= $pp ?> / page
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <!-- Active Filter Chips Strip -->
  <?php if ($has_active_filters): ?>
    <div class="us-chips-bar no-print" aria-label="Active filters">
      <span class="us-chip-label">Active filters:</span>
      <?php if (isset($filter['role_id']) && $filter['role_id'] !== ''):
          $rl = null;
          foreach ($roles ?? array() as $r) { if ((int)$r->id === (int)$filter['role_id']) { $rl = $r; break; } }
      ?>
        <a class="us-chip" href="<?= htmlspecialchars($us_url('users', array('role' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Role: <?= htmlspecialchars($rl ? (string)$rl->name : (string)$filter['role_id'], ENT_QUOTES, 'UTF-8') ?> <span class="us-chip-x">×</span>
        </a>
      <?php endif; ?>
      <?php if (isset($filter['status']) && $filter['status'] !== ''): ?>
        <a class="us-chip" href="<?= htmlspecialchars($us_url('users', array('status' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Status: <?= htmlspecialchars((string)$filter['status'], ENT_QUOTES, 'UTF-8') ?> <span class="us-chip-x">×</span>
        </a>
      <?php endif; ?>
      <?php if (isset($filter['search']) && $filter['search'] !== ''): ?>
        <a class="us-chip" href="<?= htmlspecialchars($us_url('users', array('q' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>">
          Search: &ldquo;<?= htmlspecialchars((string)$filter['search'], ENT_QUOTES, 'UTF-8') ?>&rdquo; <span class="us-chip-x">×</span>
        </a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($clean_reset_url, ENT_QUOTES, 'UTF-8') ?>" class="us-chip-reset">Reset all</a>
    </div>
  <?php endif; ?>

  <?php if (empty($users)): ?>
    <!-- Empty / Zero-Match State -->
    <div class="us-empty">
      <div class="us-empty-ico" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </div>
      <?php if ($has_active_filters): ?>
        <h3>No users match your current filters</h3>
        <p>No accounts matched the selected status, role, or search query.</p>
        <a href="<?= htmlspecialchars($clean_reset_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Reset all filters</a>
      <?php else: ?>
        <h3>No user accounts found</h3>
        <p>There are currently no user accounts in the system.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>

    <!-- Enterprise Table View -->
    <div class="us-table-wrap">
      <table class="us-table">
        <thead>
          <tr>
            <?= $sort_th('name', 'User', 'left', 'asc') ?>
            <?= $sort_th('employee_id', 'Employee ID', 'left', 'asc') ?>
            <?= $sort_th('role', 'Role', 'left', 'asc') ?>
            <?= $sort_th('status', 'Status', 'left', 'asc') ?>
            <?= $sort_th('skill_level', 'Skill', 'left', 'asc') ?>
            <?= $sort_th('last_login', 'Last Login', 'right', 'desc') ?>
            <th scope="col" class="us-num no-print">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u):
              $initials = strtoupper((string)(substr($u->first_name, 0, 1) . substr($u->last_name, 0, 1)));
              $rc  = isset($role_colors[$u->role_name])  ? $role_colors[$u->role_name]  : 'b-secondary';
              $sc  = isset($status_colors[$u->status])   ? $status_colors[$u->status]   : 'b-secondary';
              $me  = $is_me($u->id);
              $ll  = $last_login_of($u);

              $edit_url    = base_url('users/edit/' . $u->id);
              $reset_url   = base_url('users/reset_password/' . $u->id);
              $toggle_url  = base_url('users/toggle_status/' . $u->id);
              $role_change = base_url('users/change_role/' . $u->id);
          ?>
          <tr>
            <td>
              <div class="us-worker">
                <span class="us-avatar" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
                <div class="us-worker-info">
                  <a href="<?= htmlspecialchars($edit_url, ENT_QUOTES, 'UTF-8') ?>" class="us-worker-name">
                    <?= htmlspecialchars((string)($u->first_name . ' ' . $u->last_name), ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($me): ?>
                      <span class="us-you">YOU</span>
                    <?php endif; ?>
                  </a>
                  <div class="us-worker-meta">
                    <span class="us-emp-code"><?= htmlspecialchars((string)$u->employee_id, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (!empty($u->email)): ?>
                      <span aria-hidden="true">·</span>
                      <span><?= htmlspecialchars((string)$u->email, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <?php if (!empty($stats['legacy_ids'][$u->id])): ?>
                      <span class="badge b-warning" style="font-size:9px;margin-left:6px;" title="Legacy MD5/SHA1 password hash — reset required">legacy hash</span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </td>
            <td>
              <span class="us-mono" style="font-size:12px;color:var(--t2);"><?= htmlspecialchars((string)$u->employee_id, ENT_QUOTES, 'UTF-8') ?></span>
            </td>
            <td>
              <!-- Quick role change (inline dropdown, POST with CSRF) -->
              <form method="post" action="<?= htmlspecialchars($role_change, ENT_QUOTES, 'UTF-8') ?>" style="display:inline-flex;align-items:center;gap:6px;margin:0;">
                <?= form_hidden('csrf_token_wms', $this->security->get_csrf_hash()) ?>
                <select name="role_id" class="form-select" style="width:128px;padding:4px 8px;font-size:11.5px;background:var(--surface);border:1px solid var(--sep-opaque);border-radius:var(--r-sm);color:var(--t1);" <?= $me ? 'disabled title="Cannot change your own role"' : 'onchange="this.form.submit()"' ?>>
                  <?php foreach ($roles ?? array() as $r): ?>
                    <option value="<?= (int)$r->id ?>" <?= ($u->role_id == $r->id ? 'selected' : '') ?>>
                      <?= htmlspecialchars((string)$r->name, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </form>
              <span class="badge <?= $rc ?>" style="font-size:10px;margin-left:6px;"><?= htmlspecialchars((string)$u->role_name, ENT_QUOTES, 'UTF-8') ?></span>
            </td>
            <td><span class="badge <?= $sc ?>"><?= htmlspecialchars((string)$u->status, ENT_QUOTES, 'UTF-8') ?></span></td>
            <td style="font-size:12px;color:var(--t3);"><?= isset($u->skill_level) ? htmlspecialchars((string)$u->skill_level, ENT_QUOTES, 'UTF-8') : '—' ?></td>
            <td class="us-num">
              <?php if (!$ll['none']): ?>
                <div style="font-size:12px;font-weight:600;color:var(--t2);"><?= htmlspecialchars($ll['txt'], ENT_QUOTES, 'UTF-8') ?></div>
                <div style="font-size:10px;color:var(--t4);"><?= htmlspecialchars($ll['sub'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php else: ?>
                <span style="font-size:11px;color:var(--t4);"><?= htmlspecialchars($ll['txt'], ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </td>
            <td class="us-num no-print">
              <div class="us-row-actions">
                <a href="<?= htmlspecialchars($edit_url, ENT_QUOTES, 'UTF-8') ?>" class="us-act-btn is-primary" title="Edit user">Edit</a>
                <a href="<?= htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8') ?>" class="us-act-btn" title="Reset password">Reset password</a>
                <?php if (!$me): ?>
                  <?php
                    $verb = ($u->status === 'Active') ? 'Deactivate' : 'Activate';
                    $btn_cls = ($u->status === 'Active') ? 'is-danger' : 'is-success';
                  ?>
                  <?= form_open($toggle_url, array(
                        'style'   => 'display:inline;margin:0;',
                        'onsubmit' => "return confirm('" . $verb . " " . htmlspecialchars((string)($u->first_name), ENT_QUOTES, 'UTF-8') . "?')",
                      )) ?>
                  <button type="submit" class="us-act-btn <?= $btn_cls ?>" title="<?= $verb ?>"><?= $verb ?></button>
                  <?= form_close() ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Responsive Card View -->
    <div class="us-cards-container">
      <div class="us-cards-sortbar no-print">
        <span style="font-size:12px;color:var(--t2);">Card layout · Sorted by <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', isset($sort_by) ? $sort_by : 'name')), ENT_QUOTES, 'UTF-8') ?></strong> (<?= strtoupper(isset($sort_dir) ? $sort_dir : 'asc') ?>)</span>
      </div>
      <div class="us-cards-grid">
        <?php foreach ($users as $u):
            $initials = strtoupper((string)(substr($u->first_name, 0, 1) . substr($u->last_name, 0, 1)));
            $rc  = isset($role_colors[$u->role_name])  ? $role_colors[$u->role_name]  : 'b-secondary';
            $sc  = isset($status_colors[$u->status])   ? $status_colors[$u->status]   : 'b-secondary';
            $me  = $is_me($u->id);
            $ll  = $last_login_of($u);
            $edit_url   = base_url('users/edit/' . $u->id);
            $reset_url  = base_url('users/reset_password/' . $u->id);
        ?>
          <article class="us-card-item">
            <div class="us-card-top">
              <div class="us-worker" style="min-width:0;">
                <span class="us-avatar" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
                <div class="us-worker-info">
                  <a href="<?= htmlspecialchars($edit_url, ENT_QUOTES, 'UTF-8') ?>" class="us-worker-name">
                    <?= htmlspecialchars((string)($u->first_name . ' ' . $u->last_name), ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($me): ?><span class="us-you">YOU</span><?php endif; ?>
                  </a>
                  <div class="us-worker-meta">
                    <span class="us-emp-code"><?= htmlspecialchars((string)$u->employee_id, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (!empty($u->email)): ?><span>· <?= htmlspecialchars((string)$u->email, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    <?php if (!empty($stats['legacy_ids'][$u->id])): ?><span class="badge b-warning" style="font-size:9px;margin-left:6px;">legacy hash</span><?php endif; ?>
                  </div>
                </div>
              </div>
              <span class="badge <?= $sc ?> mono"><?= htmlspecialchars((string)$u->status, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="us-card-metrics">
              <div class="us-cm-box">
                <span class="us-cm-lbl">Role</span>
                <span class="us-cm-val" style="font-size:11.5px;"><?= htmlspecialchars((string)$u->role_name, ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              <div class="us-cm-box">
                <span class="us-cm-lbl">Skill</span>
                <span class="us-cm-val"><?= isset($u->skill_level) ? htmlspecialchars((string)$u->skill_level, ENT_QUOTES, 'UTF-8') : '—' ?></span>
              </div>
              <div class="us-cm-box">
                <span class="us-cm-lbl">Last Login</span>
                <span class="us-cm-val" style="font-size:11.5px;"><?= $ll['none'] ? 'Never' : htmlspecialchars($ll['txt'], ENT_QUOTES, 'UTF-8') ?></span>
              </div>
            </div>
            <div class="us-card-foot">
              <div class="us-row-actions">
                <a href="<?= htmlspecialchars($edit_url, ENT_QUOTES, 'UTF-8') ?>" class="us-act-btn is-primary">Edit</a>
                <a href="<?= htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8') ?>" class="us-act-btn">Reset password</a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Pagination & Page-Size Footer -->
    <div class="us-footer no-print">
      <div>
        <?php if ((int)$total_filtered > 0): ?>
          Showing <strong class="us-mono"><?= (int)$showing_from ?>–<?= (int)$showing_to ?></strong>
          of <strong class="us-mono"><?= (int)$total_filtered ?></strong> users
          <?php if ((int)$total_filtered < (int)$total_scope): ?>
            <span>(filtered from <?= (int)$total_scope ?>)</span>
          <?php endif; ?>
        <?php else: ?>
          0 matching users
        <?php endif; ?>
      </div>

      <?php if ((int)$total_pages > 1): ?>
        <nav class="us-pages" aria-label="User accounts pagination">
          <a class="us-page-link <?= ($page <= 1) ? 'is-disabled' : '' ?>"
             href="<?= htmlspecialchars($us_url('users', array('page' => max(1, $page - 1))), ENT_QUOTES, 'UTF-8') ?>">Prev</a>
          <?php
          $win_start = max(1, $page - 2);
          $win_end   = min($total_pages, $page + 2);
          if ($win_start > 1):
          ?>
            <a class="us-page-link" href="<?= htmlspecialchars($us_url('users', array('page' => 1)), ENT_QUOTES, 'UTF-8') ?>">1</a>
            <?php if ($win_start > 2): ?><span style="padding:0 4px;color:var(--t3);">&hellip;</span><?php endif; ?>
          <?php endif;
          for ($p = $win_start; $p <= $win_end; $p++): ?>
            <a class="us-page-link <?= $p === $page ? 'is-current' : '' ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>
               href="<?= htmlspecialchars($us_url('users', array('page' => $p)), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
          <?php endfor;
          if ($win_end < $total_pages): ?>
            <?php if ($win_end < $total_pages - 1): ?><span style="padding:0 4px;color:var(--t3);">&hellip;</span><?php endif; ?>
            <a class="us-page-link" href="<?= htmlspecialchars($us_url('users', array('page' => $total_pages)), ENT_QUOTES, 'UTF-8') ?>"><?= $total_pages ?></a>
          <?php endif; ?>
          <a class="us-page-link <?= ($page >= $total_pages) ? 'is-disabled' : '' ?>"
             href="<?= htmlspecialchars($us_url('users', array('page' => min($total_pages, $page + 1))), ENT_QUOTES, 'UTF-8') ?>">Next</a>
        </nav>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var form  = document.getElementById('usFilterForm');
  var inputs = document.querySelectorAll('.us-autosubmit');
  for (var i = 0; i < inputs.length; i++) {
    inputs[i].addEventListener('change', function () {
      if (form) form.submit();
    });
  }
  var perPage = document.getElementById('usPerPage');
  if (perPage) {
    perPage.addEventListener('change', function () {
      if (perPage.value) window.location.href = perPage.value;
    });
  }

  // Instant table / cards view switcher (updates URL without reload)
  var shell = document.getElementById('usShell');
  // (UI toggle omitted for users — table is the canonical layout; cards auto on mobile)

  // Global keyboard shortcut: '/' focuses search
  var searchInp = document.getElementById('usSearchInput');
  document.addEventListener('keydown', function (ev) {
    var tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
    var typing = (tag === 'input' || tag === 'textarea' || tag === 'select' || (ev.target && ev.target.isContentEditable));
    if (ev.key === '/' && !typing && searchInp) {
      ev.preventDefault();
      searchInp.focus();
      searchInp.select();
    }
  });
})();
</script>
```

<tool_call>
<function=bash>