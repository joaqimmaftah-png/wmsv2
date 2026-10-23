<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Supplier Directory — presentation layer.
 *
 * Layout, tokens, and component behaviour mirror application/views/team/report.php
 * (the reference enterprise data-table design system): print banner, page header,
 * KPI strip, scope filter bar, status tabs, search + view toggle toolbar, active
 * filter chips, contextual batch bar, sticky enterprise table / grouped / card
 * views, windowed pagination, and a slide-over vendor inspector drawer.
 *
 * No data is invented here: every value rendered comes from _build_suppliers_dataset().
 */

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
        if ($v === '' || $v === NULL) {
            unset($p[$k]);
        } elseif ($k === 'page' && (int)$v === 1) {
            unset($p[$k]);
        } elseif ($k === 'per_page' && (int)$v === 25) {
            unset($p[$k]);
        }
    }
    $qs = http_build_query($p);
    return base_url($path) . ($qs !== '' ? '?' . $qs : '');
};

/* ── Active filter state (drives chips strip, empty state, and print banner) ── */
$filter_country  = isset($f['country']) ? (string)$f['country'] : '';
$filter_currency = isset($f['currency']) ? (string)$f['currency'] : '';
$filter_status   = isset($f['status']) ? (string)$f['status'] : '';

$tab_labels = array(
    'all'          => 'All Vendors',
    'active'       => 'Active',
    'with_catalog' => 'Catalog Quoted',
    'with_orders'  => 'PO History',
    'flagged'      => 'Inactive / Blacklisted',
);
$tab_label = isset($tab_labels[$tab]) ? $tab_labels[$tab] : $tab;

$has_active_filters = ($tab !== 'all' || $q !== '' || $filter_country !== '' || $filter_currency !== '' || $filter_status !== '');
$clean_reset_url    = base_url('suppliers');

/* ── Column header helper: shared SVG sort indicator + ARIA sort state ── */
$sup_th = function ($col, $label, $align = 'left', $col_class = '') use ($sort, $dir, $sup_url) {
    $is_active = ($sort === $col);
    $next_dir  = $is_active ? (($dir === 'asc') ? 'desc' : 'asc') : 'asc';
    $aria_sort = $is_active ? (($dir === 'asc') ? 'ascending' : 'descending') : 'none';
    $href      = $sup_url('suppliers', array('sort' => $col, 'dir' => $next_dir, 'page' => 1));
    $cls       = 'sup-th-sort' . ($align === 'right' ? ' is-right' : '') . ($is_active ? ' is-sorted' : '');

    $icon = '<svg class="sup-sort-ico" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">';
    if ($is_active && $dir === 'asc') {
        $icon .= '<path d="M6 2.5L9.2 6.2H2.8L6 2.5Z" fill="currentColor"/><path d="M6 9.5L2.8 7.2H9.2L6 9.5Z" fill="currentColor" opacity="0.22"/>';
    } elseif ($is_active && $dir === 'desc') {
        $icon .= '<path d="M6 2.5L9.2 4.8H2.8L6 2.5Z" fill="currentColor" opacity="0.22"/><path d="M6 9.5L2.8 5.8H9.2L6 9.5Z" fill="currentColor"/>';
    } else {
        $icon .= '<path d="M6 2.5L8.8 5.3H3.2L6 2.5ZM6 9.5L3.2 6.7H8.8L6 9.5Z" fill="currentColor" opacity="0.35"/>';
    }
    $icon .= '</svg>';

    $th_class = trim(($col_class !== '' ? $col_class . ' ' : '') . ($align === 'right' ? 'sup-num' : ''));

    return '<th scope="col" aria-sort="' . $aria_sort . '" class="' . $th_class . '">'
         . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . $cls . '">'
         . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>' . $icon
         . '</a></th>';
};

/* ── Shared cell helpers ── */
$sup_status_badge = function ($status) use ($sup_esc) {
    $v   = Supplier_model::clean_status($status ?: 'Active');
    $map = array('Active' => 'b-success', 'Inactive' => 'b-secondary', 'Blacklisted' => 'b-danger');
    $cls = isset($map[$v]) ? $map[$v] : 'b-secondary';
    return '<span class="badge ' . $cls . '">' . $sup_esc($v) . '</span>';
};

$sup_rate = function ($pct) {
    if ($pct === NULL) {
        return '<span class="sup-no-records">No closed POs</span>';
    }
    $p    = max(0, min(100, (int)$pct));
    $cls  = ($p >= 90) ? 'sup-rate-good' : (($p >= 75) ? 'sup-rate-warn' : 'sup-rate-bad');
    return '<span class="sup-rate-wrap ' . $cls . '">'
         . '<span class="sup-rate-pct">' . $p . '%</span>'
         . '<span class="sup-rate-bar" aria-hidden="true">'
         . '<span class="sup-rate-fill" style="width:' . max(4, $p) . '%"></span></span></span>';
};

$sup_initials = function ($name) {
    $parts = preg_split('/\s+/u', trim((string)$name));
    $ini   = '';
    $taken = 0;
    foreach ((array)$parts as $word) {
        if ($word === '') continue;
        $first = function_exists('mb_substr')
            ? mb_substr($word, 0, 1, 'UTF-8')
            : substr($word, 0, 1);
        $ini .= (function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first));
        if (++$taken >= 2) break;
    }
    return $ini !== '' ? $ini : '—';
};

$openCostParts = array();
if (!empty($kpi['open_cost_by_ccy']) && is_array($kpi['open_cost_by_ccy'])) {
    foreach ($kpi['open_cost_by_ccy'] as $ccyCode => $ccySum) {
        $openCostParts[] = $sup_esc($ccyCode) . ' ' . number_format((float)$ccySum, 0);
    }
}
$openCostLabel = !empty($openCostParts) ? implode(' · ', array_slice($openCostParts, 0, 2)) : '0 active POs';

$company_name = isset($site_settings['company_name']) && $site_settings['company_name'] !== ''
    ? (string)$site_settings['company_name']
    : 'Workshop WMS';

$print_filters = array();
if ($filter_country !== '')  $print_filters[] = 'Country: ' . $filter_country;
if ($filter_currency !== '') $print_filters[] = 'Currency: ' . $filter_currency;
if ($filter_status !== '')   $print_filters[] = 'Status: ' . $filter_status;
if ($q !== '')               $print_filters[] = 'Search: "' . $q . '"';
if ($tab !== 'all')          $print_filters[] = 'Segment: ' . $tab_label;
?>
<style>
/* ─────────────────────────────────────────────────────────────────────────────
   SUPPLIER DIRECTORY — mirrors the /team/report enterprise table design system.
   Local tokens keep the surface self-contained; all base tokens (--surface,
   --sep, --t1..--t3, --blue/--green/--amber/--red, --r-*, --shadow-card) come
   from the layout.
   ───────────────────────────────────────────────────────────────────────────── */
#supShell, .sup-drawer, .sup-print-banner, .sup-menu {
  --sup-fill: rgba(0,0,0,0.035);
  --sup-fill-2: rgba(0,0,0,0.06);
  --sup-sep-strong: rgba(0,0,0,0.14);
  --sup-blue-lt: rgba(0, 113, 227, 0.08);
  --sup-ease: cubic-bezier(.4, 0, .2, 1);
}

#supShell { max-width: 100%; }

/* Print-only official header (hidden on screen) */
.sup-print-banner { display: none; }

/* ── Page header ── */
.sup-head { margin-bottom: 14px; }
.sup-crumb {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; color: var(--t3); margin-bottom: 6px;
}
.sup-crumb a { color: var(--t3); text-decoration: none; }
.sup-crumb a:hover { color: var(--blue); }
.sup-head-row {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 14px; flex-wrap: wrap;
}
.sup-head h1 {
  margin: 0; font-size: 21px; font-weight: 700; letter-spacing: -0.02em; color: var(--t1);
}
.sup-head-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sup-count-pill {
  display: inline-flex; align-items: center; padding: 3px 10px;
  border-radius: 100px; font-size: 11.5px; font-weight: 600;
  background: var(--surface); border: 1px solid var(--sep); color: var(--t2);
  font-variant-numeric: tabular-nums;
}
.sup-sub { margin: 5px 0 0; font-size: 12.5px; color: var(--t2); }
.sup-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* ── Executive KPI strip ── */
.sup-kpi-grid {
  display: grid; grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 10px; margin-bottom: 14px;
}
.sup-kpi {
  padding: 12px 14px; border-radius: var(--r-md, 14px);
  background: var(--surface); border: 1px solid var(--sep);
  box-shadow: var(--shadow-card); min-width: 0;
}
.sup-kpi-label {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.045em; color: var(--t3);
}
.sup-kpi-val {
  font-size: 22px; font-weight: 700; color: var(--t1);
  margin-top: 4px; line-height: 1.15; font-variant-numeric: tabular-nums;
}
.sup-kpi-sub {
  font-size: 11.5px; color: var(--t3); margin-top: 3px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* ── Main surface card ── */
.sup-surface {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-lg, 18px); box-shadow: var(--shadow-card);
  overflow: hidden;
}

/* ── Scope filter bar (coarse filters, auto-submit) ── */
.sup-scope-bar {
  padding: 12px 16px; background: var(--surface-2);
  border-bottom: 1px solid var(--sep);
  display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap;
}
.sup-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.sup-field label {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--t3); margin: 0;
}
.sup-select {
  height: 32px; padding: 0 28px 0 10px; max-width: 180px;
  font-family: inherit; font-size: 12.5px; color: var(--t1);
  background: var(--surface) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2386868b' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 8px center;
  border: 1px solid var(--sep-strong); border-radius: var(--r-sm, 10px);
  appearance: none; -webkit-appearance: none; cursor: pointer; outline: none;
  transition: border-color var(--sup-ease), box-shadow var(--sup-ease);
}
.sup-select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px var(--sup-blue-lt); }
.sup-scope-actions { display: inline-flex; align-items: center; gap: 6px; margin-left: auto; }

/* ── Tabs + search + view toggle toolbar ── */
.sup-toolbar {
  padding: 10px 16px; border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; background: var(--surface);
}
.sup-tabs { display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.sup-tab {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 11px; border-radius: var(--r-sm, 10px);
  font-size: 12.5px; font-weight: 500; color: var(--t2);
  text-decoration: none; border: 1px solid transparent;
  transition: background var(--sup-ease), color var(--sup-ease), border-color var(--sup-ease);
}
.sup-tab:hover { background: var(--sup-fill); color: var(--t1); }
.sup-tab.is-active {
  background: var(--sup-blue-lt); color: var(--blue); font-weight: 600;
  border-color: rgba(0, 113, 227, 0.22);
}
.sup-tab-cnt {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 18px; padding: 0 6px;
  border-radius: 100px; font-size: 11px; font-weight: 600;
  background: var(--sup-fill); color: var(--t2); font-variant-numeric: tabular-nums;
}
.sup-tab.is-active .sup-tab-cnt { background: var(--blue); color: #ffffff; }

.sup-tools-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto; }
.sup-search-wrap { position: relative; display: inline-flex; align-items: center; }
.sup-search-ico {
  position: absolute; left: 9px; width: 14px; height: 14px;
  color: var(--t3); pointer-events: none;
}
.sup-search-input {
  height: 32px; width: 250px; padding: 0 54px 0 29px;
  font-family: inherit; font-size: 12.5px; color: var(--t1);
  background: var(--surface); border: 1px solid var(--sep-strong);
  border-radius: var(--r-sm, 10px); outline: none;
  transition: border-color var(--sup-ease), box-shadow var(--sup-ease);
}
.sup-search-input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px var(--sup-blue-lt); }
.sup-kbd {
  position: absolute; right: 8px; padding: 1px 5px;
  font-family: ui-monospace, Menlo, monospace; font-size: 10.5px; color: var(--t3);
  background: var(--surface); border: 1px solid var(--sep-strong);
  border-radius: 4px; pointer-events: none; line-height: 1.3;
}
.sup-search-input:focus ~ .sup-kbd { display: none; }
.sup-search-clear {
  position: absolute; right: 6px; width: 20px; height: 20px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 50%; background: var(--sup-fill-2); color: var(--t2);
  font-size: 13px; text-decoration: none; line-height: 1;
}
.sup-search-clear:hover { background: var(--sup-sep-strong); color: var(--t1); }

.sup-view-seg {
  display: inline-flex; padding: 2px; background: var(--sup-fill);
  border-radius: var(--r-sm, 10px); border: 1px solid var(--sep);
}
.sup-view-btn {
  display: inline-flex; align-items: center; gap: 5px;
  height: 26px; padding: 0 9px; border-radius: 6px; border: none;
  background: transparent; color: var(--t2); font-family: inherit;
  font-size: 12px; font-weight: 500; cursor: pointer; text-decoration: none;
  transition: background var(--sup-ease), color var(--sup-ease);
}
.sup-view-btn:hover { color: var(--t1); }
.sup-view-btn.is-active {
  background: var(--surface); color: var(--t1); font-weight: 600;
  box-shadow: 0 1px 2px rgba(0,0,0,0.08);
}

.sup-hint { margin: 0; padding: 7px 16px; font-size: 11.5px; color: var(--t3); border-bottom: 1px solid var(--sep); }

/* ── Active filter chips ── */
.sup-chips-bar {
  padding: 8px 16px; background: var(--surface-2); border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 12px;
}
.sup-chip-label { color: var(--t3); font-weight: 500; margin-right: 2px; }
.sup-chip {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 2px 8px; border-radius: 100px;
  background: var(--surface); border: 1px solid var(--sep-strong);
  color: var(--t1); font-size: 11.5px; font-weight: 500; text-decoration: none;
  transition: border-color var(--sup-ease), color var(--sup-ease);
}
.sup-chip:hover { border-color: var(--red); color: var(--red); }
.sup-chip-x { color: var(--t3); font-weight: 700; }
.sup-chip:hover .sup-chip-x { color: var(--red); }
.sup-chip-reset { margin-left: auto; font-size: 12px; font-weight: 500; color: var(--blue); text-decoration: none; }
.sup-chip-reset:hover { text-decoration: underline; }

/* ── Contextual batch action bar ── */
.sup-batch-bar {
  padding: 9px 16px; background: rgba(0, 113, 227, 0.06);
  border-bottom: 1px solid rgba(0, 113, 227, 0.24);
  display: none; align-items: center; justify-content: space-between;
  gap: 10px; flex-wrap: wrap;
}
.sup-batch-bar.is-open { display: flex; }
.sup-batch-count { font-size: 12.5px; font-weight: 600; color: var(--t1); }
.sup-batch-actions { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }

/* ── Enterprise data table ── */
.sup-table-wrap { width: 100%; overflow: auto; max-height: calc(100vh - 210px); }
.sup-table { width: 100%; min-width: 1120px; table-layout: fixed; border-collapse: separate; border-spacing: 0; }
.sup-table thead th {
  position: sticky; top: 0; z-index: 2;
  background: var(--surface-2);
  padding: 9px 8px; text-align: left; white-space: nowrap;
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.035em; color: var(--t3);
  border-bottom: 1px solid var(--sep);
}
.sup-table thead th.sup-num { text-align: right; }
.sup-th-sort {
  display: inline-flex; align-items: center; gap: 4px;
  color: var(--t3); text-decoration: none; cursor: pointer; user-select: none;
  transition: color var(--sup-ease);
}
.sup-th-sort.is-right { flex-direction: row-reverse; }
.sup-th-sort:hover { color: var(--t1); }
.sup-th-sort.is-sorted { color: var(--blue); }
.sup-sort-ico { flex-shrink: 0; }

.sup-table tbody td {
  padding: 10px 8px; vertical-align: middle;
  border-bottom: 1px solid var(--sep);
  overflow: hidden; text-overflow: ellipsis;
}
.sup-table tbody tr { transition: background var(--sup-ease); }
.sup-table tbody tr:last-child td { border-bottom: none; }
.sup-table tbody tr:hover { background: #f8f9fb; }
.sup-table tbody td.sup-num { text-align: right; }

.sup-col-chk  { width: 34px; text-align: center !important; }
.sup-col-name { width: 20%; }
.sup-col-loc  { width: 11%; }
.sup-col-term { width: 12%; }
.sup-col-cnt  { width: 12%; }
.sup-col-lead { width: 6%; }
.sup-col-cat  { width: 7%; }
.sup-col-po   { width: 8%; }
.sup-col-ot   { width: 9%; }
.sup-col-st   { width: 7%; }
.sup-col-act  { width: 176px; }

/* Vendor identity cell: avatar + name + meta */
.sup-vendor { display: flex; align-items: center; gap: 9px; min-width: 0; }
.sup-avatar {
  flex-shrink: 0; width: 30px; height: 30px; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  background: var(--sup-blue-lt); color: var(--blue);
  font-size: 11px; font-weight: 700; letter-spacing: 0.02em;
}
.sup-vendor-info { min-width: 0; flex: 1 1 auto; overflow: hidden; }
.sup-name-btn {
  display: block; max-width: 100%; padding: 0; border: 0; background: none;
  text-align: left; cursor: pointer; color: var(--t1);
  font-family: inherit; font-size: 12.5px; font-weight: 600;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.sup-name-btn:hover { color: var(--blue); text-decoration: underline; }
.sup-vendor-meta {
  display: flex; align-items: center; gap: 5px; flex-wrap: wrap;
  margin-top: 2px; font-size: 11px; color: var(--t3);
  overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
}
.sup-tax-id { font-family: ui-monospace, Menlo, monospace; font-size: 10.5px; }
.sup-fleet-chip {
  display: inline-block; padding: 1px 6px; border-radius: 4px;
  font-family: ui-monospace, Menlo, monospace; font-size: 10px; font-weight: 600;
  background: var(--blue-fill, rgba(0,113,227,0.08)); color: var(--blue);
}
.sup-cell-strong { font-size: 12px; font-weight: 600; color: var(--t1); }
.sup-cell-sub {
  font-size: 11px; color: var(--t3); margin-top: 2px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.sup-muted-zero { color: var(--t3); font-variant-numeric: tabular-nums; }
.sup-open-badge { padding: 1px 6px !important; font-size: 10px !important; line-height: 1.5; }
.sup-val-strong { font-weight: 700; color: var(--t1); font-variant-numeric: tabular-nums; }
.sup-num-txt { font-variant-numeric: tabular-nums; }

/* On-time delivery rate bar */
.sup-rate-wrap { display: inline-flex; align-items: center; justify-content: flex-end; gap: 7px; }
.sup-rate-pct {
  font-family: ui-monospace, Menlo, monospace; font-size: 12px; font-weight: 700;
  min-width: 34px; text-align: right; font-variant-numeric: tabular-nums;
}
.sup-rate-bar { width: 40px; height: 5px; border-radius: 99px; background: var(--sup-fill-2); overflow: hidden; flex-shrink: 0; }
.sup-rate-fill { display: block; height: 100%; border-radius: 99px; }
.sup-rate-good .sup-rate-pct { color: var(--green); }
.sup-rate-good .sup-rate-fill { background: var(--green); }
.sup-rate-warn .sup-rate-pct { color: var(--amber); }
.sup-rate-warn .sup-rate-fill { background: var(--amber); }
.sup-rate-bad  .sup-rate-pct { color: var(--red); }
.sup-rate-bad  .sup-rate-fill { background: var(--red); }
.sup-no-records {
  display: inline-flex; align-items: center; padding: 2px 6px;
  border-radius: 100px; background: var(--sup-fill);
  color: var(--t3); font-size: 10.5px; font-weight: 500; white-space: nowrap;
}

/* Row actions: frequent actions visible, occasional actions in overflow */
.sup-row-actions { display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px; }
.sup-act-btn {
  display: inline-flex; align-items: center; gap: 4px;
  height: 27px; padding: 0 8px; border-radius: 6px;
  border: 1px solid var(--sep-strong); background: var(--surface);
  color: var(--t1); font-family: inherit; font-size: 11.5px; font-weight: 500;
  cursor: pointer; text-decoration: none; white-space: nowrap;
  transition: background var(--sup-ease), border-color var(--sup-ease), color var(--sup-ease);
}
.sup-act-btn:hover { background: var(--sup-fill); border-color: #b8b8bf; color: var(--t1); }
.sup-act-btn.is-primary {
  background: var(--sup-blue-lt); border-color: rgba(0, 113, 227, 0.25);
  color: var(--blue); font-weight: 600;
}
.sup-act-btn.is-primary:hover { background: rgba(0, 113, 227, 0.16); }

.sup-act-menu { position: relative; display: inline-flex; }
.sup-menu-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 27px; height: 27px; padding: 0; border-radius: 6px;
  border: 1px solid var(--sep-strong); background: var(--surface);
  color: var(--t2); font-size: 13px; line-height: 1; cursor: pointer;
  transition: background var(--sup-ease), border-color var(--sup-ease), color var(--sup-ease);
}
.sup-menu-btn:hover { background: var(--sup-fill); color: var(--t1); }

/* Portal-rendered overflow menu (escapes overflow/transform ancestors) */
.sup-menu {
  position: fixed; display: none; z-index: 1200;
  min-width: 176px; padding: 5px;
  background: var(--surface); border: 1px solid var(--sep-strong);
  border-radius: var(--r-md, 14px); box-shadow: 0 8px 28px rgba(0,0,0,0.16);
  text-align: left;
}
.sup-menu.is-open { display: block; }
.sup-menu-item {
  display: flex; align-items: center; width: 100%;
  padding: 7px 9px; border: none; background: transparent; border-radius: 6px;
  font-family: inherit; font-size: 12.5px; color: var(--t1);
  text-decoration: none; cursor: pointer; text-align: left;
  transition: background var(--sup-ease), color var(--sup-ease);
}
.sup-menu-item:hover, .sup-menu-item:focus-visible { background: var(--sup-fill); color: var(--blue); outline: none; }
.sup-menu-item--danger { color: var(--red); }
.sup-menu-item--danger:hover, .sup-menu-item--danger:focus-visible { background: var(--red-fill, rgba(192,57,43,0.08)); color: var(--red); }
.sup-menu-sep { height: 1px; background: var(--sep); margin: 4px 2px; }
.sup-menu-item-form { margin: 0; padding: 0; }

.visually-hidden {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
}

/* ── Empty state ── */
.sup-empty { padding: 48px 24px; text-align: center; }
.sup-empty-ico {
  width: 44px; height: 44px; border-radius: 12px;
  background: var(--sup-fill); color: var(--t3);
  display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px;
}
.sup-empty h3 { font-size: 15px; font-weight: 600; color: var(--t1); margin-bottom: 4px; }
.sup-empty p { font-size: 13px; color: var(--t2); max-width: 420px; margin: 0 auto 14px; }

/* ── Country corridor groups ── */
.sup-groups-container { display: none; padding: 14px; }
.sup-group-card {
  margin-bottom: 12px; border-radius: var(--r-md, 14px); overflow: hidden;
  background: var(--surface); border: 1px solid var(--sep);
}
.sup-group-hdr {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; flex-wrap: wrap; padding: 11px 16px;
  background: var(--surface-2); border-bottom: 1px solid var(--sep);
}
.sup-group-title { font-weight: 600; color: var(--t1); font-size: 13.5px; }
.sup-group-meta { font-size: 11.5px; color: var(--t3); }
.sup-group-rows { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; padding: 12px 16px; }
.sup-group-item {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  padding: 10px 12px; border-radius: 9px; border: 1px solid var(--sep); min-width: 0;
}
.sup-group-item:hover { background: var(--sup-fill); }

/* ── Card view ── */
.sup-cards-container { display: none; padding: 14px; }
.sup-cards-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.sup-card {
  display: flex; flex-direction: column; gap: 10px;
  padding: 14px; border-radius: var(--r-md, 14px);
  background: var(--surface); border: 1px solid var(--sep); min-width: 0;
}
.sup-card:hover { border-color: var(--sep-strong); }
.sup-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.sup-card-metrics {
  display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px;
  padding: 8px; border-radius: 8px; background: var(--surface-2); font-size: 11px;
}
.sup-card-metric-k { color: var(--t3); font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em; }
.sup-card-metric-v { font-weight: 700; color: var(--t1); margin-top: 2px; font-variant-numeric: tabular-nums; }
.sup-card-foot {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding-top: 8px; border-top: 1px solid var(--sep); margin-top: auto;
}

/* ── View mode switching (table | groups | cards) ── */
#supShell[data-view="groups"] .sup-table-wrap,
#supShell[data-view="groups"] .sup-cards-container { display: none; }
#supShell[data-view="groups"] .sup-groups-container { display: block; }

#supShell[data-view="cards"] .sup-table-wrap,
#supShell[data-view="cards"] .sup-groups-container { display: none; }
#supShell[data-view="cards"] .sup-cards-container { display: block; }

/* ── Pagination footer ── */
.sup-footer {
  padding: 11px 16px; border-top: 1px solid var(--sep);
  background: var(--surface-2);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; font-size: 12.5px; color: var(--t2);
}
.sup-pages { display: inline-flex; align-items: center; gap: 4px; }
.sup-page-link {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 30px; height: 28px; padding: 0 8px; border-radius: 6px;
  border: 1px solid var(--sep-strong); background: var(--surface);
  color: var(--t1); font-size: 12px; text-decoration: none;
  transition: background var(--sup-ease), border-color var(--sup-ease);
}
.sup-page-link:hover { background: var(--sup-fill); border-color: #b8b8bf; }
.sup-page-link.is-current {
  background: var(--blue); border-color: var(--blue); color: #ffffff; font-weight: 600;
}
.sup-page-link.is-disabled { opacity: 0.4; pointer-events: none; }
.sup-pager-ellipsis { padding: 0 4px; color: var(--t3); }
.sup-rows { display: inline-flex; align-items: center; gap: 6px; }
.sup-rows label { font-size: 12px; color: var(--t3); margin: 0; }
.sup-rows .sup-select { height: 28px; font-size: 12px; padding-right: 24px; }

/* ── Slide-over vendor inspector drawer ── */
.sup-drawer-backdrop {
  position: fixed; inset: 0; background: rgba(2,6,23,0.36); z-index: 1040;
  opacity: 0; pointer-events: none; transition: opacity .18s ease;
}
.sup-drawer-backdrop.is-open { opacity: 1; pointer-events: auto; }
.sup-drawer {
  position: fixed; top: 0; right: 0; bottom: 0; width: min(520px, 100vw); z-index: 1050;
  background: var(--surface); border-left: 1px solid var(--sep-strong);
  box-shadow: -16px 0 40px rgba(0,0,0,0.18);
  display: flex; flex-direction: column;
  transform: translateX(100%); visibility: hidden;
  transition: transform .2s ease, visibility .2s;
}
.sup-drawer.is-open { transform: translateX(0); visibility: visible; }
.sup-drawer :focus-visible, #supShell :focus-visible { outline: 2px solid var(--blue); outline-offset: 2px; }
.sup-drawer-hdr {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 10px;
  padding: 16px 18px; border-bottom: 1px solid var(--sep);
}
.sup-drawer-eyebrow {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.05em; color: var(--t3);
}
.sup-drawer-title { margin: 3px 0 0; font-size: 16px; font-weight: 700; color: var(--t1); word-break: break-word; }
.sup-drawer-body { padding: 16px 18px; overflow-y: auto; flex: 1 1 auto; }
.sup-drawer-ftr {
  display: flex; align-items: center; justify-content: space-between;
  gap: 8px; flex-wrap: wrap; padding: 12px 18px; border-top: 1px solid var(--sep);
}
.sup-drawer-nav { display: inline-flex; gap: 6px; }
.sup-drawer-ctas { display: inline-flex; gap: 6px; flex-wrap: wrap; }
.sup-drawer-na { opacity: 0.4; pointer-events: none; }
.sup-kv-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-bottom: 14px; }
.sup-kv { padding: 9px 11px; border-radius: 8px; background: var(--surface-2); min-width: 0; }
.sup-kv-k { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--t3); }
.sup-kv-v { font-size: 12.5px; font-weight: 600; color: var(--t1); margin-top: 2px; word-break: break-word; }
.sup-section-title {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.045em; color: var(--t3); margin-bottom: 6px;
}
.sup-list-row {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding: 7px 10px; border-radius: 8px; background: var(--surface-2);
  margin-bottom: 6px; font-size: 12px; min-width: 0;
}
.sup-list-row .sup-list-main { min-width: 0; }

/* ── Responsive ── */
@media (max-width: 1100px) {
  .sup-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .sup-cards-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 900px) {
  .sup-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  /* Mobile defaults to cards unless the reader explicitly chose the table. */
  #supShell:not([data-view="groups"]) .sup-cards-container { display: block !important; }
  #supShell:not([data-view="groups"]).sup-user-forced-table .sup-cards-container { display: none !important; }
  #supShell[data-view="groups"] .sup-cards-container { display: none !important; }
  #supShell:not(.sup-user-forced-table) .sup-table-wrap { display: none !important; }
  .sup-cards-grid { grid-template-columns: minmax(0, 1fr); }
  .sup-group-rows { grid-template-columns: minmax(0, 1fr); }
  .sup-search-wrap { flex: 1 1 100%; }
  .sup-search-input { width: 100%; }
  .sup-tools-right { width: 100%; justify-content: space-between; }
  .sup-kv-grid { grid-template-columns: minmax(0, 1fr); }
  .sup-view-btn[data-sup-view="table"] { display: none; }
  .sup-table-wrap { max-height: none; }
  .sup-scope-actions { margin-left: 0; width: 100%; }
}

/* ── Clean print layout ── */
@media print {
  .sup-print-banner {
    display: block !important; margin-bottom: 14px;
    padding-bottom: 10px; border-bottom: 2px solid #1d1d1f;
  }
  .sup-print-banner h2 { font-size: 16px; margin: 0 0 3px; }
  .sup-print-banner p { font-size: 11px; color: #444; margin: 0; }
  .sup-head, .sup-scope-bar, .sup-toolbar, .sup-hint, .sup-chips-bar, .sup-batch-bar,
  .sup-footer, .sup-cards-container, .sup-groups-container, .sup-drawer,
  .sup-drawer-backdrop, .sup-menu, .no-print { display: none !important; }
  .sup-surface { border: none !important; box-shadow: none !important; }
  .sup-table-wrap { display: block !important; overflow: visible !important; max-height: none !important; }
  .sup-table { min-width: 0 !important; }
  .sup-table thead th { position: static !important; background: #ffffff !important; }
  .sup-table th, .sup-table td { padding: 6px 8px !important; font-size: 11px !important; }
  .sup-table tbody tr:hover { background: transparent !important; }
  .sup-kpi-grid { grid-template-columns: repeat(5, minmax(0, 1fr)) !important; gap: 6px !important; margin-bottom: 10px !important; }
  .sup-kpi { box-shadow: none !important; border: 1px solid #cccccc !important; padding: 7px 9px !important; }
  .sup-kpi-val { font-size: 15px !important; }
}
</style>

<!-- Print-only official header -->
<div class="sup-print-banner">
  <h2><?= $sup_esc(strtoupper($company_name)) ?> — SUPPLIER DIRECTORY</h2>
  <p>
    Vendors shown: <strong><?= (int)$tot_filt ?></strong> of <strong><?= (int)$kpi['total'] ?></strong>
    <?php if (!empty($print_filters)): ?>
     · <?= $sup_esc(implode(' · ', $print_filters)) ?>
    <?php endif; ?>
     · Printed <?= date('d M Y H:i') ?>
  </p>
</div>

<div id="supShell" data-view="<?= $sup_esc($view_mode) ?>">

  <!-- ── Page header ── -->
  <div class="sup-head no-print">
    <nav class="sup-crumb" aria-label="Breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span aria-hidden="true">/</span>
      <span>Suppliers</span>
    </nav>
    <div class="sup-head-row">
      <div>
        <div class="sup-head-title">
          <h1>Supplier Directory</h1>
          <span class="sup-count-pill"><?= (int)$tot_filt ?> of <?= (int)$kpi['total'] ?> vendors</span>
        </div>
        <p class="sup-sub">Approved vendors, commercial terms, catalog coverage, and purchase-order performance.</p>
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
  </div>

  <!-- ── Executive KPI strip ── -->
  <div class="sup-kpi-grid">
    <div class="sup-kpi">
      <div class="sup-kpi-label">Total Suppliers</div>
      <div class="sup-kpi-val"><?= (int)$kpi['total'] ?></div>
      <div class="sup-kpi-sub"><?= (int)$kpi['inactive'] ?> inactive · <?= (int)$kpi['blacklisted'] ?> blacklisted</div>
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

  <div class="sup-surface">

    <!-- ── Scope filter bar ── -->
    <form method="get" action="<?= base_url('suppliers') ?>" id="supFilterForm" class="sup-scope-bar no-print">
      <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= $sup_esc($tab) ?>"><?php endif; ?>
      <?php if ($sort !== 'company_name'): ?><input type="hidden" name="sort" value="<?= $sup_esc($sort) ?>"><?php endif; ?>
      <?php if ($dir !== 'asc'): ?><input type="hidden" name="dir" value="<?= $sup_esc($dir) ?>"><?php endif; ?>
      <input type="hidden" name="view" id="supViewInput" value="<?= $sup_esc($view_mode) ?>">

      <div class="sup-field">
        <label for="supCountry">Country</label>
        <select name="country" id="supCountry" class="sup-select sup-autosubmit">
          <option value="">All Countries</option>
          <?php foreach ($countries as $c): ?>
          <option value="<?= $sup_esc($c) ?>" <?= ($filter_country !== '' && strcasecmp($filter_country, (string)$c) === 0) ? 'selected' : '' ?>><?= $sup_esc($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="sup-field">
        <label for="supCurrency">Currency</label>
        <select name="currency" id="supCurrency" class="sup-select sup-autosubmit">
          <option value="">All Currencies</option>
          <?php foreach ($currencies as $ccy): ?>
          <option value="<?= $sup_esc($ccy) ?>" <?= ($filter_currency !== '' && strcasecmp($filter_currency, (string)$ccy) === 0) ? 'selected' : '' ?>><?= $sup_esc($ccy) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="sup-field">
        <label for="supStatus">Status</label>
        <select name="status" id="supStatus" class="sup-select sup-autosubmit">
          <option value="">All Statuses</option>
          <?php foreach (array('Active', 'Inactive', 'Blacklisted') as $st): ?>
          <option value="<?= $sup_esc($st) ?>" <?= ($filter_status === $st) ? 'selected' : '' ?>><?= $sup_esc($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="sup-scope-actions">
        <button type="submit" class="btn btn-ghost btn-sm">Apply</button>
        <a href="<?= $sup_esc($clean_reset_url) ?>" class="btn btn-ghost btn-sm">Reset</a>
      </div>
    </form>

    <!-- ── Status tabs + search + view toggle ── -->
    <div class="sup-toolbar no-print">
      <div class="sup-tabs" role="tablist" aria-label="Supplier segments">
        <?php
        $tabDefs = array(
            'all'          => array('All Vendors', $tab_counts['all']),
            'active'       => array('Active', $tab_counts['active']),
            'with_catalog' => array('Catalog Quoted', $tab_counts['with_catalog']),
            'with_orders'  => array('PO History', $tab_counts['with_orders']),
            'flagged'      => array('Inactive / Blacklisted', $tab_counts['flagged']),
        );
        foreach ($tabDefs as $tk => $meta):
        ?>
        <a href="<?= $sup_esc($sup_url('suppliers', array('tab' => $tk === 'all' ? '' : $tk, 'page' => 1))) ?>"
           role="tab"
           aria-selected="<?= $tab === $tk ? 'true' : 'false' ?>"
           class="sup-tab <?= $tab === $tk ? 'is-active' : '' ?>">
          <span><?= $sup_esc($meta[0]) ?></span>
          <span class="sup-tab-cnt"><?= (int)$meta[1] ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <div class="sup-tools-right">
        <div class="sup-search-wrap">
          <svg class="sup-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search"
                 form="supFilterForm"
                 name="q"
                 id="supSearchInput"
                 value="<?= $sup_esc($q) ?>"
                 placeholder="Search vendor, TIN, contact, part…"
                 aria-label="Search suppliers by name, tax ID, contact, catalog part, or fleet asset"
                 class="sup-search-input">
          <?php if ($q !== ''): ?>
          <a href="<?= $sup_esc($sup_url('suppliers', array('q' => '', 'page' => 1))) ?>"
             class="sup-search-clear" aria-label="Clear search query" title="Clear search">&times;</a>
          <?php else: ?>
          <kbd class="sup-kbd" aria-hidden="true">/</kbd>
          <?php endif; ?>
        </div>

        <div class="sup-view-seg" role="group" aria-label="Display layout">
          <button type="button" class="sup-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>"
                  aria-pressed="<?= $view_mode === 'table' ? 'true' : 'false' ?>" data-sup-view="table">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>
            Table
          </button>
          <button type="button" class="sup-view-btn <?= $view_mode === 'groups' ? 'is-active' : '' ?>"
                  aria-pressed="<?= $view_mode === 'groups' ? 'true' : 'false' ?>" data-sup-view="groups">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
            By Country
          </button>
          <button type="button" class="sup-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>"
                  aria-pressed="<?= $view_mode === 'cards' ? 'true' : 'false' ?>" data-sup-view="cards">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Cards
          </button>
        </div>
      </div>
    </div>

    <p class="sup-hint no-print">Segment counts cover all suppliers, before search and the filters above.</p>

    <!-- ── Active filter chips ── -->
    <?php if ($has_active_filters): ?>
    <div class="sup-chips-bar no-print" aria-label="Active filters">
      <span class="sup-chip-label">Active filters:</span>
      <?php if ($filter_country !== ''): ?>
      <a class="sup-chip" href="<?= $sup_esc($sup_url('suppliers', array('country' => '', 'page' => 1))) ?>">
        Country: <?= $sup_esc($filter_country) ?> <span class="sup-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <?php if ($filter_currency !== ''): ?>
      <a class="sup-chip" href="<?= $sup_esc($sup_url('suppliers', array('currency' => '', 'page' => 1))) ?>">
        Currency: <?= $sup_esc($filter_currency) ?> <span class="sup-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <?php if ($filter_status !== ''): ?>
      <a class="sup-chip" href="<?= $sup_esc($sup_url('suppliers', array('status' => '', 'page' => 1))) ?>">
        Status: <?= $sup_esc($filter_status) ?> <span class="sup-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <?php if ($tab !== 'all'): ?>
      <a class="sup-chip" href="<?= $sup_esc($sup_url('suppliers', array('tab' => '', 'page' => 1))) ?>">
        Segment: <?= $sup_esc($tab_label) ?> <span class="sup-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <?php if ($q !== ''): ?>
      <a class="sup-chip" href="<?= $sup_esc($sup_url('suppliers', array('q' => '', 'page' => 1))) ?>">
        Search: &ldquo;<?= $sup_esc($q) ?>&rdquo; <span class="sup-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <a href="<?= $sup_esc($clean_reset_url) ?>" class="sup-chip-reset">Reset all</a>
    </div>
    <?php endif; ?>

    <!-- ── Contextual batch action bar ── -->
    <div class="sup-batch-bar no-print" id="supBatchBar" role="region" aria-label="Selected suppliers batch actions">
      <div class="sup-batch-count"><span id="supSelectedCount">0</span> supplier(s) selected</div>
      <div class="sup-batch-actions">
        <a href="<?= $sup_esc(base_url('suppliers/export_csv')) ?>" id="supBatchExportBtn" class="btn btn-primary btn-sm">Export Selected CSV</a>
        <a href="<?= $sup_esc(base_url('suppliers/export_xlsx')) ?>" id="supBatchXlsxBtn" class="btn btn-secondary btn-sm">Export Selected XLSX</a>
        <button type="button" id="supBatchClearBtn" class="btn btn-ghost btn-sm">Clear selection</button>
      </div>
    </div>

    <?php if (empty($suppliers)): ?>
      <!-- ── Empty / zero-match state ── -->
      <div class="sup-empty">
        <div class="sup-empty-ico" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </div>
        <?php if ($has_active_filters): ?>
          <h3>No suppliers match your current filters</h3>
          <p>No vendor matched the selected segment, country, currency, status, or search query.</p>
          <a href="<?= $sup_esc($clean_reset_url) ?>" class="btn btn-secondary">Reset all filters</a>
        <?php else: ?>
          <h3>No suppliers registered yet</h3>
          <p>The supplier directory is empty. Add the first vendor to start tracking quotes and purchase orders.</p>
          <?php if ($can_write): ?>
          <a href="<?= base_url('suppliers/create') ?>" class="btn btn-primary">+ Add Supplier</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php else: ?>

      <!-- ── 1. Enterprise table view ── -->
      <div class="sup-table-wrap">
        <table class="sup-table">
          <thead>
            <tr>
              <th scope="col" class="sup-col-chk">
                <input type="checkbox" id="supSelectAll" aria-label="Select all suppliers on this page">
              </th>
              <?= $sup_th('company_name', 'Vendor', 'left', 'sup-col-name') ?>
              <?= $sup_th('country', 'Corridor', 'left', 'sup-col-loc') ?>
              <th scope="col" class="sup-col-term">Terms &amp; Currency</th>
              <th scope="col" class="sup-col-cnt">Primary Contact</th>
              <?= $sup_th('lead_time', 'Lead', 'left', 'sup-col-lead') ?>
              <?= $sup_th('item_count', 'Catalog', 'left', 'sup-col-cat') ?>
              <?= $sup_th('order_count', 'POs', 'left', 'sup-col-po') ?>
              <?= $sup_th('on_time', 'On-Time', 'left', 'sup-col-ot') ?>
              <?= $sup_th('status', 'Status', 'left', 'sup-col-st') ?>
              <th scope="col" class="sup-col-act" style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($suppliers as $s):
            $assets = !empty($s->fleet_assets) && is_array($s->fleet_assets) ? $s->fleet_assets : array();
          ?>
            <tr data-sup-row="<?= (int)$s->id ?>">
              <td class="sup-col-chk">
                <input type="checkbox" class="sup-row-check" value="<?= (int)$s->id ?>"
                       aria-label="Select <?= $sup_esc($s->company_name) ?>">
              </td>
              <td class="sup-col-name">
                <div class="sup-vendor">
                  <span class="sup-avatar" aria-hidden="true"><?= $sup_esc($sup_initials($s->company_name)) ?></span>
                  <div class="sup-vendor-info">
                    <button type="button" class="sup-name-btn" data-sup-open="<?= (int)$s->id ?>"
                            title="<?= $sup_esc($s->company_name) ?>">
                      <?= $sup_esc($s->company_name) ?>
                    </button>
                    <div class="sup-vendor-meta">
                      <?php if (!empty($s->tax_id)): ?>
                        <span class="sup-tax-id" title="Tax ID"><?= $sup_esc($s->tax_id) ?></span>
                      <?php else: ?>
                        <span class="sup-tax-id" title="Supplier ID">#<?= (int)$s->id ?></span>
                      <?php endif; ?>
                      <?php if (!empty($assets)): ?>
                        <span aria-hidden="true">·</span>
                        <span class="sup-fleet-chip" title="Linked fleet machines"><?= $sup_esc(implode(', ', array_slice($assets, 0, 2))) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </td>
              <td class="sup-col-loc">
                <div class="sup-cell-strong"><?= $sup_esc($s->country) ?></div>
                <div class="sup-cell-sub"><?= $sup_esc($s->city ?: '—') ?></div>
              </td>
              <td class="sup-col-term">
                <div class="sup-cell-strong sup-num-txt" style="font-family:ui-monospace,Menlo,monospace;font-size:11.5px;"><?= $sup_esc($s->currency ?: 'TZS') ?></div>
                <div class="sup-cell-sub" title="<?= $sup_esc($s->payment_terms ?: 'Standard terms') ?>"><?= $sup_esc($s->payment_terms ?: 'Standard terms') ?></div>
              </td>
              <td class="sup-col-cnt">
                <?php if (!empty($s->primary_contact_name)): ?>
                  <div class="sup-cell-strong" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= $sup_esc($s->primary_contact_name) ?></div>
                  <div class="sup-cell-sub"><?= $sup_esc($s->primary_contact_phone ?: ($s->primary_contact_email ?: ($s->primary_contact_role ?? ''))) ?></div>
                <?php else: ?>
                  <span class="sup-muted-zero">No contact</span>
                <?php endif; ?>
              </td>
              <td class="sup-col-lead">
                <span class="sup-num-txt"><?= $s->lead_time_guarantee_days !== NULL ? ((int)$s->lead_time_guarantee_days . 'd') : '—' ?></span>
              </td>
              <td class="sup-col-cat">
                <div class="sup-val-strong"><?= (int)$s->item_count ?></div>
                <?php if (!empty($s->preferred_count)): ?>
                  <div class="sup-cell-sub"><?= (int)$s->preferred_count ?> pref</div>
                <?php endif; ?>
              </td>
              <td class="sup-col-po">
                <div class="sup-val-strong"><?= (int)$s->order_count ?></div>
                <?php if (!empty($s->open_order_count)): ?>
                  <div class="sup-cell-sub"><span class="badge b-warning sup-open-badge"><?= (int)$s->open_order_count ?> open</span></div>
                <?php endif; ?>
              </td>
              <td class="sup-col-ot"><?= $sup_rate($s->on_time_rate) ?></td>
              <td class="sup-col-st"><?= $sup_status_badge($s->status ?? 'Active') ?></td>
              <td class="sup-col-act">
                <div class="sup-row-actions">
                  <button type="button" class="sup-act-btn is-primary" data-sup-open="<?= (int)$s->id ?>" title="Inspect supplier dossier">Inspect</button>
                  <a href="<?= base_url('suppliers/view/' . (int)$s->id) ?>" class="sup-act-btn">Profile</a>
                  <?php if ($can_write): ?>
                  <div class="sup-act-menu">
                    <button type="button" class="sup-menu-btn"
                            aria-label="More actions for <?= $sup_esc($s->company_name) ?>"
                            aria-haspopup="menu"
                            aria-controls="sup-menu-<?= (int)$s->id ?>"
                            aria-expanded="false"
                            data-sup-menu="<?= (int)$s->id ?>">
                      <span aria-hidden="true">&bull;&bull;&bull;</span>
                      <span class="visually-hidden">More actions</span>
                    </button>
                    <div class="sup-menu" id="sup-menu-<?= (int)$s->id ?>" role="menu" aria-label="Supplier actions">
                      <a href="<?= base_url('suppliers/edit/' . (int)$s->id) ?>" class="sup-menu-item" role="menuitem">Edit supplier</a>
                      <div class="sup-menu-sep" role="separator"></div>
                      <form method="post" action="<?= base_url('suppliers/delete/' . (int)$s->id) ?>" class="sup-menu-item-form" onsubmit="return confirm('Delete this supplier? This cannot be undone.')">
                        <?= csrf_field() ?>
                        <button type="submit" class="sup-menu-item sup-menu-item--danger" role="menuitem">Delete supplier</button>
                      </form>
                    </div>
                  </div>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- ── 2. Country corridor grouped view ── -->
      <div class="sup-groups-container">
        <?php if (empty($groups)): ?>
          <div class="sup-empty">
            <div class="sup-empty-ico" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </div>
            <h3>No supplier corridors on this page</h3>
            <p>No suppliers matched the current filters for this page of results.</p>
            <a href="<?= $sup_esc($clean_reset_url) ?>" class="btn btn-secondary">Reset all filters</a>
          </div>
        <?php else: foreach ($groups as $grp): ?>
        <div class="sup-group-card">
          <div class="sup-group-hdr">
            <div class="sup-group-title">
              <?= $sup_esc($grp->country) ?>
              <span class="sup-count-pill" style="margin-left:6px;"><?= (int)$grp->total_count ?> on this page</span>
            </div>
            <div class="sup-group-meta">
              <?= (int)$grp->active_count ?> active · <?= (int)$grp->item_count ?> catalog quotes · <?= (int)$grp->open_orders ?> open POs
            </div>
          </div>
          <div class="sup-group-rows">
            <?php foreach ($grp->suppliers as $gs): ?>
            <div class="sup-group-item">
              <div style="min-width:0;">
                <button type="button" class="sup-name-btn" data-sup-open="<?= (int)$gs->id ?>"><?= $sup_esc($gs->company_name) ?></button>
                <div class="sup-cell-sub">
                  <?= $sup_esc($gs->currency ?: 'TZS') ?>
                  · <?= (int)$gs->item_count ?> items
                  · <?= (int)$gs->order_count ?> POs
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
                <?= $sup_status_badge($gs->status ?? 'Active') ?>
                <a href="<?= base_url('suppliers/view/' . (int)$gs->id) ?>" class="sup-act-btn">Profile</a>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- ── 3. Card view ── -->
      <div class="sup-cards-container">
        <div class="sup-cards-grid">
          <?php foreach ($suppliers as $cs): ?>
          <div class="sup-card">
            <div class="sup-card-top">
              <div class="sup-vendor" style="min-width:0;">
                <span class="sup-avatar" aria-hidden="true"><?= $sup_esc($sup_initials($cs->company_name)) ?></span>
                <div class="sup-vendor-info">
                  <button type="button" class="sup-name-btn" data-sup-open="<?= (int)$cs->id ?>"><?= $sup_esc($cs->company_name) ?></button>
                  <div class="sup-cell-sub"><?= $sup_esc($cs->country) ?><?= $cs->city ? ', ' . $sup_esc($cs->city) : '' ?> · <?= $sup_esc($cs->currency ?: 'TZS') ?></div>
                </div>
              </div>
              <?= $sup_status_badge($cs->status ?? 'Active') ?>
            </div>

            <div class="sup-card-metrics">
              <div>
                <div class="sup-card-metric-k">Lead Time</div>
                <div class="sup-card-metric-v"><?= $cs->lead_time_guarantee_days !== NULL ? ((int)$cs->lead_time_guarantee_days . 'd') : '—' ?></div>
              </div>
              <div>
                <div class="sup-card-metric-k">Catalog</div>
                <div class="sup-card-metric-v"><?= (int)$cs->item_count ?></div>
              </div>
              <div>
                <div class="sup-card-metric-k">Orders</div>
                <div class="sup-card-metric-v"><?= (int)$cs->order_count ?></div>
              </div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
              <span class="sup-card-metric-k" style="text-transform:none;letter-spacing:0;font-size:11px;color:var(--t3);">On-time delivery</span>
              <?= $sup_rate($cs->on_time_rate) ?>
            </div>

            <div class="sup-card-foot">
              <span class="sup-cell-sub" style="margin:0;">
                <?= !empty($cs->primary_contact_name) ? $sup_esc($cs->primary_contact_name) : $sup_esc($cs->payment_terms ?: 'Standard terms') ?>
              </span>
              <div style="display:flex;gap:6px;flex-shrink:0;">
                <button type="button" class="sup-act-btn is-primary" data-sup-open="<?= (int)$cs->id ?>">Inspect</button>
                <a href="<?= base_url('suppliers/view/' . (int)$cs->id) ?>" class="sup-act-btn">Profile</a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php endif; ?>

    <!-- ── Pagination footer ── -->
    <div class="sup-footer no-print">
      <div>
        <?php if ($tot_filt === 0): ?>
          Showing <strong>0</strong> suppliers
        <?php else: ?>
          Showing <?= (int)($offset_val + 1) ?>–<?= (int)min($tot_filt, $offset_val + count($suppliers)) ?>
          of <strong class="mono"><?= (int)$tot_filt ?></strong> suppliers
        <?php endif; ?>
      </div>

      <?php if ($tot_pages > 1): ?>
      <nav class="sup-pages sup-pager" aria-label="Supplier directory pagination">
        <a class="sup-page-link <?= $cur_page <= 1 ? 'is-disabled' : '' ?>"
           href="<?= $sup_esc($sup_url('suppliers', array('page' => max(1, $cur_page - 1)))) ?>">Prev</a>
        <?php
        $win_start = max(1, $cur_page - 2);
        $win_end   = min($tot_pages, $cur_page + 2);
        if ($win_start > 1): ?>
          <a class="sup-page-link" href="<?= $sup_esc($sup_url('suppliers', array('page' => 1))) ?>">1</a>
          <?php if ($win_start > 2): ?><span class="sup-pager-ellipsis">&hellip;</span><?php endif; ?>
        <?php endif;
        for ($p = $win_start; $p <= $win_end; $p++): ?>
          <a class="sup-page-link <?= $p === $cur_page ? 'is-current' : '' ?>"
             <?= $p === $cur_page ? 'aria-current="page"' : '' ?>
             href="<?= $sup_esc($sup_url('suppliers', array('page' => $p))) ?>"><?= $p ?></a>
        <?php endfor;
        if ($win_end < $tot_pages): ?>
          <?php if ($win_end < $tot_pages - 1): ?><span class="sup-pager-ellipsis">&hellip;</span><?php endif; ?>
          <a class="sup-page-link" href="<?= $sup_esc($sup_url('suppliers', array('page' => $tot_pages))) ?>"><?= $tot_pages ?></a>
        <?php endif; ?>
        <a class="sup-page-link <?= $cur_page >= $tot_pages ? 'is-disabled' : '' ?>"
           href="<?= $sup_esc($sup_url('suppliers', array('page' => min($tot_pages, $cur_page + 1)))) ?>">Next</a>
      </nav>
      <?php endif; ?>

      <div class="sup-rows">
        <label for="supPerPage">Rows:</label>
        <select id="supPerPage" class="sup-select" aria-label="Rows per page">
          <?php foreach ($allowed_pp as $pp): ?>
          <option value="<?= $sup_esc($sup_url('suppliers', array('per_page' => $pp, 'page' => 1))) ?>"
                  <?= $per_page === (int)$pp ? 'selected' : '' ?>><?= (int)$pp ?> / page</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

  </div>
</div>

<!-- ── Slide-over vendor inspector drawer ── -->
<div class="sup-drawer-backdrop no-print" id="supDrawerBackdrop"></div>
<aside class="sup-drawer no-print" id="supDrawer" role="dialog" aria-modal="true" aria-labelledby="supDrawerTitle" aria-hidden="true">
  <div class="sup-drawer-hdr">
    <div style="min-width:0;">
      <div class="sup-drawer-eyebrow">Vendor Dossier</div>
      <h2 class="sup-drawer-title" id="supDrawerTitle">Supplier</h2>
    </div>
    <button type="button" class="sup-act-btn" id="supDrawerClose" aria-label="Close vendor inspector" title="Close inspector (Esc)">&times;</button>
  </div>
  <div class="sup-drawer-body" id="supDrawerBody">
    <div style="color:var(--t3);font-size:12.5px;">Select a supplier to inspect commercial terms, contacts, catalog quotes, and recent orders.</div>
  </div>
  <div class="sup-drawer-ftr">
    <div class="sup-drawer-nav">
      <button type="button" class="sup-act-btn" id="supPrevBtn" aria-label="Previous supplier" title="Previous supplier (Left arrow)">&larr;</button>
      <button type="button" class="sup-act-btn" id="supNextBtn" aria-label="Next supplier" title="Next supplier (Right arrow)">&rarr;</button>
    </div>
    <div class="sup-drawer-ctas" id="supDrawerCtas">
      <a href="<?= base_url('suppliers') ?>" id="supDrawerProfileLink" class="btn btn-primary btn-sm">Full Profile</a>
    </div>
  </div>
</aside>

<script>
(function () {
  var shell = document.getElementById('supShell');
  if (!shell) return;

  // At mobile widths the dense table becomes cards unless the reader has
  // explicitly forced the table. Reflect the effective layout in the control.
  var mobileQuery = window.matchMedia('(max-width: 900px)');
  if (mobileQuery.matches && shell.getAttribute('data-view') === 'table') {
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

  // 1. Auto-submit scope filters — changing a filter applies it immediately.
  //    The Apply button remains for keyboard / no-JS users.
  var filterForm = document.getElementById('supFilterForm');
  Array.prototype.forEach.call(document.querySelectorAll('.sup-autosubmit'), function (input) {
    input.addEventListener('change', function () {
      if (filterForm) filterForm.submit();
    });
  });

  // 2. Rows-per-page navigates directly.
  var perPageSel = document.getElementById('supPerPage');
  if (perPageSel) {
    perPageSel.addEventListener('change', function () {
      if (perPageSel.value) window.location.href = perPageSel.value;
    });
  }

  // 3. View switcher (Table / By Country / Cards) — instant, survives navigation.
  Array.prototype.forEach.call(document.querySelectorAll('[data-sup-view]'), function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.getAttribute('data-sup-view') || 'table';
      shell.setAttribute('data-view', mode);
      // A deliberate Table choice must survive the mobile card fallback.
      shell.classList.toggle('sup-user-forced-table', mode === 'table');
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

  // 4. Multi-select + batch export bar.
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
    if (selectAll && rowChecks.length) selectAll.checked = (ids.length === rowChecks.length);
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

  // 5. Row overflow menus (portal pattern: escapes overflow/transform ancestors).
  Array.prototype.forEach.call(document.querySelectorAll('[data-sup-menu]'), function (btn) {
    var menuId = 'sup-menu-' + btn.getAttribute('data-sup-menu');
    var menu   = document.getElementById(menuId);
    if (!menu) return;
    var open = false;

    function position() {
      var rect = btn.getBoundingClientRect();
      var mw = menu.offsetWidth;
      var mh = menu.offsetHeight;
      var left = rect.right - mw;
      var top  = rect.bottom + 6;
      var vpW  = window.innerWidth || document.documentElement.clientWidth;
      var vpH  = window.innerHeight || document.documentElement.clientHeight;
      if (left < 8) left = 8;
      if (left + mw > vpW - 8) left = vpW - mw - 8;
      if (top + mh > vpH - 8) { top = rect.top - mh - 6; if (top < 8) top = 8; }
      menu.style.left = Math.max(8, left) + 'px';
      menu.style.top  = Math.max(8, top)  + 'px';
    }

    function openMenu() {
      open = true;
      document.body.appendChild(menu);
      menu.style.display = 'block';
      position();
      menu.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
      var first = menu.querySelector('[role="menuitem"]');
      if (first) first.focus();
    }

    function closeMenu() {
      open = false;
      menu.classList.remove('is-open');
      btn.setAttribute('aria-expanded', 'false');
      menu.style.display = 'none';
    }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      open ? closeMenu() : openMenu();
    });
    btn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open ? closeMenu() : openMenu(); }
      if (e.key === 'Escape') { closeMenu(); btn.focus(); }
    });
    document.addEventListener('click', function (e) {
      if (open && !btn.contains(e.target) && !menu.contains(e.target)) closeMenu();
    });
    window.addEventListener('scroll', function () { if (open) closeMenu(); }, true);
    window.addEventListener('resize', function () { if (open) position(); });

    var items = Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]'));
    menu.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { closeMenu(); btn.focus(); }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        var idx = items.indexOf(document.activeElement);
        var dir = (e.key === 'ArrowDown') ? 1 : -1;
        items[(idx + dir + items.length) % items.length].focus();
      }
    });
  });

  // 6. Slide-over vendor inspector — JSON payloads are cached per supplier.
  var drawer   = document.getElementById('supDrawer');
  var backdrop = document.getElementById('supDrawerBackdrop');
  var titleEl  = document.getElementById('supDrawerTitle');
  var bodyEl   = document.getElementById('supDrawerBody');
  var ctasEl   = document.getElementById('supDrawerCtas');
  var closeBtn = document.getElementById('supDrawerClose');
  var prevBtn  = document.getElementById('supPrevBtn');
  var nextBtn  = document.getElementById('supNextBtn');
  var lastFocus = null;
  var currentId = null;
  var requestSeq = 0;
  var sheetCache = {};

  function orderedIds() {
    var seen = {};
    var out = [];
    Array.prototype.forEach.call(document.querySelectorAll('[data-sup-open]'), function (b) {
      var id = b.getAttribute('data-sup-open');
      if (id && !seen[id]) { seen[id] = true; out.push(id); }
    });
    return out;
  }

  function syncDrawerNav() {
    if (!prevBtn || !nextBtn) return;
    var list = orderedIds();
    var idx  = list.indexOf(currentId);
    var atStart = (idx <= 0);
    var atEnd   = (idx === -1 || idx >= list.length - 1);
    prevBtn.disabled = atStart;
    nextBtn.disabled = atEnd;
    prevBtn.classList.toggle('sup-drawer-na', atStart);
    nextBtn.classList.toggle('sup-drawer-na', atEnd);
  }

  function closeDrawer() {
    requestSeq++;
    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
  }

  function renderSupplier(res) {
    var s = res.supplier;
    titleEl.textContent = s.company_name + ' (' + s.country + ')';

    var cHtml = '<div style="margin-bottom:14px;"><div class="sup-section-title">Commercial Terms</div>'
      + '<div class="sup-kv-grid">'
      + '<div class="sup-kv"><div class="sup-kv-k">Status &amp; Currency</div><div class="sup-kv-v">' + esc(s.status) + ' · ' + esc(s.currency) + '</div></div>'
      + '<div class="sup-kv"><div class="sup-kv-k">Tax ID</div><div class="sup-kv-v">' + esc(s.tax_id) + '</div></div>'
      + '<div class="sup-kv"><div class="sup-kv-k">Quoted Lead Time</div><div class="sup-kv-v">' + (s.lead_time_guarantee_days !== null ? (s.lead_time_guarantee_days + ' days') : '—') + '</div></div>'
      + '<div class="sup-kv"><div class="sup-kv-k">On-Time Delivery</div><div class="sup-kv-v">' + (s.on_time_rate !== null ? (s.on_time_rate + '% (' + s.on_time_sample + ' closed)') : 'No closed POs yet') + '</div></div>'
      + '<div class="sup-kv" style="grid-column:1 / -1;"><div class="sup-kv-k">Payment Terms</div><div class="sup-kv-v">' + esc(s.payment_terms) + '</div></div>'
      + '</div></div>';

    var contactsHtml = '<div style="margin-bottom:14px;"><div class="sup-section-title">Contacts (' + res.contacts.length + ')</div>';
    if (!res.contacts.length) {
      contactsHtml += '<div style="font-size:12px;color:var(--t3);">No contacts registered yet.</div>';
    } else {
      res.contacts.forEach(function (c) {
        contactsHtml += '<div class="sup-list-row"><div class="sup-list-main">'
          + '<div style="font-weight:600;color:var(--t1);">' + esc(c.name) + ' <span style="color:var(--t3);font-weight:400;">— ' + esc(c.role) + '</span></div>'
          + '<div style="color:var(--t3);font-size:11px;">' + esc(c.phone || '—') + (c.email ? (' · ' + esc(c.email)) : '') + '</div>'
          + '</div></div>';
      });
    }
    contactsHtml += '</div>';

    var catHtml = '<div style="margin-bottom:14px;"><div class="sup-section-title">Catalog Quotes (' + res.catalog.length + ')</div>';
    if (!res.catalog.length) {
      catHtml += '<div style="font-size:12px;color:var(--t3);">No catalog items mapped yet.</div>';
    } else {
      res.catalog.slice(0, 6).forEach(function (m) {
        catHtml += '<div class="sup-list-row"><div class="sup-list-main">'
          + '<div style="font-weight:600;color:var(--t1);">' + esc(m.description) + (m.is_preferred ? ' ★' : '') + '</div>'
          + '<div style="font-size:11px;color:var(--t3);">PN: ' + esc(m.part_number) + (m.item_code ? (' · Store: ' + esc(m.item_code)) : '') + '</div>'
          + '</div><div class="mono" style="white-space:nowrap;font-weight:700;color:var(--t1);">'
          + (m.unit_price !== null ? (Number(m.unit_price).toFixed(2) + ' ' + esc(m.currency)) : '—') + '</div></div>';
      });
    }
    catHtml += '</div>';

    var ordHtml = '<div><div class="sup-section-title">Purchase Orders &amp; Fleet Traceability (' + res.orders.length + ')</div>';
    if (!res.orders.length) {
      ordHtml += '<div style="font-size:12px;color:var(--t3);">No purchase orders placed with this vendor yet.</div>';
    } else {
      res.orders.forEach(function (o) {
        ordHtml += '<div class="sup-list-row"><div class="sup-list-main">'
          + '<a href="' + orderView + encodeURIComponent(o.id) + '" style="color:var(--blue);font-weight:700;text-decoration:none;">' + esc(o.order_no) + '</a>'
          + '<div style="font-size:11px;color:var(--t3);">' + esc(o.order_date) + ' · ' + esc(o.status)
          + (o.registration_no ? (' · Machine: ' + esc(o.registration_no)) : '') + '</div></div>'
          + '<div class="mono" style="white-space:nowrap;font-weight:700;color:var(--t1);">'
          + Number(o.total_cost).toFixed(2) + ' ' + esc(o.currency) + '</div></div>';
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
  }

  function openDrawer(id, triggerEl) {
    if (triggerEl) lastFocus = triggerEl;
    currentId = String(id);
    var thisRequest = ++requestSeq;
    drawer.classList.add('is-open');
    backdrop.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    if (closeBtn) closeBtn.focus();
    syncDrawerNav();

    if (sheetCache[currentId]) {
      renderSupplier(sheetCache[currentId]);
      syncDrawerNav();
      return;
    }

    bodyEl.innerHTML = '<div style="padding:18px 0;color:var(--t3);font-size:12.5px;">Loading vendor dossier…</div>';

    fetch(summaryBase + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (thisRequest !== requestSeq) return;
        if (!res || !res.ok || !res.supplier) {
          bodyEl.innerHTML = '<div style="color:var(--red);">Unable to load supplier details.</div>';
          return;
        }
        sheetCache[currentId] = res;
        renderSupplier(res);
        syncDrawerNav();
      })
      .catch(function () {
        if (thisRequest !== requestSeq) return;
        bodyEl.innerHTML = '<div style="color:var(--red);">Network error loading supplier dossier.</div>';
      });
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-sup-open]'), function (btn) {
    btn.addEventListener('click', function () {
      openDrawer(btn.getAttribute('data-sup-open'), btn);
    });
  });

  function stepDrawer(delta) {
    var list = orderedIds();
    if (!list.length || !currentId) return;
    var idx = list.indexOf(currentId);
    if (idx === -1) return;
    var nextIdx = idx + delta;
    if (nextIdx < 0 || nextIdx >= list.length) return; // boundaries stop, not wrap
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
      if (sInput) { ev.preventDefault(); sInput.focus(); }
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
