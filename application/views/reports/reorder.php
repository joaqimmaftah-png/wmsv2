<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Re-order report — enterprise data-table surface.
 *
 * Mirrors the /team/report design system: scoped local tokens over the shared
 * Apple-HIG base tokens, one restrained surface, a compact KPI strip, a
 * labelled scope bar, URL-driven state tabs / sorting / view switching, a
 * sticky table with a card fallback, an honest empty state and print styles.
 *
 * All figures come from Materials_model::get_reorder_items(). Nothing here
 * changes the query, the min/max calculation, the filter contract, RBAC, the
 * CSV/XLSX export or the routes. The extra `state`, `sort`, `dir` and `view`
 * query keys are display-only; the controller validates and paginates them before
 * passing the bounded page plus full-scope metadata into this view.
 */

/* ── Scope and escaping helpers ─────────────────────────────────────────── */
$h = function ($v) {
    if ($v === null || !is_scalar($v)) return '';
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$f         = isset($filter) && is_array($filter) ? $filter : array();
$g         = function ($k) use ($f) {
    return isset($f[$k]) && is_scalar($f[$k]) ? (string)$f[$k] : '';
};
$rows      = isset($rows) && is_array($rows) ? $rows : array();
$categories= isset($categories) && is_array($categories) ? $categories : array();
$locations = isset($locations) && is_array($locations) ? $locations : array();
$extended  = !empty($extended);

/* The export contract is unchanged: category / location / search only. */
$filterQs  = array_filter(array(
    'category' => $g('category'),
    'location' => $g('location'),
    'search'   => $g('search'),
), function ($v) { return $v !== ''; });
$exportQs  = http_build_query($filterQs);
$exportUrl = base_url('reports/export_reorder_xlsx') . ($exportQs !== '' ? '?' . $exportQs : '');

/* Display-only URL state, validated against allowlists. Scalars only: an
   array query value (e.g. ?state[]=x) must never reach a string cast. The
   controller supplies the same validated state on a real request; the GET
   fallback keeps this view safe and renderable in isolation. */
$qsParam = function ($key) { return (isset($_GET[$key]) && is_scalar($_GET[$key])) ? (string)$_GET[$key] : ''; };
$state = $qsParam('state');
if (isset($reorder_state) && is_scalar($reorder_state)) $state = (string)$reorder_state;
if (!in_array($state, array('all', 'out', 'in'), true)) $state = 'all';

$sortCols = array(
    'item_code'      => 'string',
    'category'       => 'string',
    'description'    => 'string',
    'final_qty'      => 'number',
    'min_stock'      => 'number',
    'max_stock'      => 'number',
    'qty_to_order'   => 'number',
    'unit_price_ugx' => 'number',
    'location'       => 'string',
    'supplier_name'  => 'string',
);
$sortKey = $qsParam('sort');
if (isset($reorder_sort) && is_scalar($reorder_sort)) $sortKey = (string)$reorder_sort;
if (!isset($sortCols[$sortKey])) $sortKey = 'final_qty';
$sortDir = (strtolower($qsParam('dir')) === 'desc') ? 'desc' : 'asc';
if (isset($reorder_dir) && is_scalar($reorder_dir)) $sortDir = strtolower((string)$reorder_dir) === 'desc' ? 'desc' : 'asc';

$viewRaw = $qsParam('view');
if (isset($reorder_view) && is_scalar($reorder_view)) $viewRaw = (string)$reorder_view;
$viewMode = ($viewRaw === 'cards') ? 'cards' : 'table';
/* Keep responsive defaults honest: table is forced on small screens only
   when the user actually supplied view=table, not merely because the
   controller normalised an omitted parameter to its table default. */
$viewExplicit = in_array($qsParam('view'), array('table', 'cards'), true);

$serverPaged = isset($reorder_pagination) && is_array($reorder_pagination)
    && !empty($reorder_pagination['server_paged']);
$pagination = array(
    'total_filtered' => 0, 'total_pages' => 1, 'per_page' => 25,
    'page' => 1, 'showing_from' => 0, 'showing_to' => 0,
);
if ($serverPaged) {
    $pagination = array_merge($pagination, $reorder_pagination);
} else {
    $perPageParam = (int)$qsParam('per_page');
    $pagination['per_page'] = in_array($perPageParam, array(10, 25, 50, 100), true) ? $perPageParam : 25;
    $pageParam = (int)$qsParam('page');
    $pagination['page'] = $pageParam > 0 ? $pageParam : 1;
}
$pagination['per_page'] = in_array((int)$pagination['per_page'], array(10, 25, 50, 100), true)
    ? (int)$pagination['per_page'] : 25;
$pagination['page'] = max(1, (int)$pagination['page']);
$perPage = $pagination['per_page'];
$page = $pagination['page'];

$roParams = array(
    'category' => $g('category'),
    'location' => $g('location'),
    'search'   => $g('search'),
    'state'    => $state,
    'sort'     => $sortKey,
    'dir'      => $sortDir,
    'view'     => $viewMode,
    'per_page' => $perPage,
    'page'     => $page,
);
$roDefaults = array(
    'state' => 'all', 'sort' => 'final_qty', 'dir' => 'asc', 'view' => 'table',
    'per_page' => 25, 'page' => 1,
);
$roUrl = function (array $over = array()) use ($roParams, $roDefaults, $viewExplicit) {
    $p = array_merge($roParams, $over);
    $viewWasExplicit = $viewExplicit || array_key_exists('view', $over);
    foreach ($p as $k => $v) {
        if ($v === null || $v === '') unset($p[$k]);
        elseif ($k === 'view' && $v === 'table' && !$viewWasExplicit) unset($p[$k]);
        elseif ($k !== 'view' && isset($roDefaults[$k]) && $v === $roDefaults[$k]) unset($p[$k]);
    }
    $qs = http_build_query($p);
    return site_url('reports/reorder') . ($qs !== '' ? '?' . $qs : '');
};

/* ── Row-level derived state (no new business metrics) ──────────────────── */
$levelVal = function ($m, $key) {
    if (!isset($m->{$key}) || $m->{$key} === null || $m->{$key} === '') return null;
    return (int)$m->{$key};
};
/* A quantity to order only exists when at least one stock level is on file. */
$canCalc = function ($m) use ($extended, $levelVal) {
    return $extended && ($levelVal($m, 'min_stock') !== null || $levelVal($m, 'max_stock') !== null);
};
$isOut = function ($m) { return (int)$m->final_qty <= 0; };

/* The controller calculates these across the complete filtered scope before
   slicing the page. The fallback calculation keeps direct view renders useful
   in tests and does not change the production path. */
if ($serverPaged && isset($reorder_summary) && is_array($reorder_summary)) {
    $summary = array_merge(array(
        'total_scope' => count($rows), 'out_count' => 0, 'in_count' => 0,
        'qty_total' => 0, 'qty_missing' => 0,
    ), $reorder_summary);
    $out = (int)$summary['out_count'];
    $inStock = (int)$summary['in_count'];
    $qtyTotal = (int)$summary['qty_total'];
    $qtyMissing = (int)$summary['qty_missing'];
    $total = (int)$summary['total_scope'];
} else {
    $out = 0; $inStock = 0; $qtyTotal = 0; $qtyMissing = 0;
    foreach ($rows as $m) {
        if ($isOut($m)) $out++; else $inStock++;
        if ($canCalc($m)) $qtyTotal += (int)$m->qty_to_order;
        else $qtyMissing++;
    }
    $total = count($rows);
}

/* Status tabs partition the complete scope, not just the visible page. */
$tabDefs = array(
    'all' => array('label' => 'All items',    'count' => $total),
    'out' => array('label' => 'Out of stock', 'count' => $out),
    'in'  => array('label' => 'Low, in stock','count' => $inStock),
);
$tabHelp = array(
    'all' => 'All items matching the category, location and search filters',
    'out' => 'Items whose recorded balance is zero or below',
    'in'  => 'Items still above zero but at or below the reorder level',
);

/* On a real request Reports::reorder() has already status-filtered, sorted
   and sliced this page. The fallback retains safe standalone rendering for
   fixtures and keeps the old view contract useful outside the controller. */
$displayRows = array();
if ($serverPaged) {
    $displayRows = $rows;
} else {
    foreach ($rows as $m) {
        if ($state === 'out' && !$isOut($m)) continue;
        if ($state === 'in'  &&  $isOut($m)) continue;
        $displayRows[] = $m;
    }
}
$sortVal = function ($m, $k) use ($sortCols, $levelVal, $canCalc) {
    if ($k === 'qty_to_order') return $canCalc($m) ? (int)$m->qty_to_order : null;
    if ($k === 'min_stock' || $k === 'max_stock') return $levelVal($m, $k);
    if (!isset($m->{$k}) || $m->{$k} === null || $m->{$k} === '') return null;
    return $m->{$k};
};
if (!$serverPaged) {
    usort($displayRows, function ($a, $b) use ($sortKey, $sortCols, $sortDir, $sortVal) {
        $va = $sortVal($a, $sortKey);
        $vb = $sortVal($b, $sortKey);
        /* Missing values always sort last, in either direction. */
        if ($va === null && $vb === null) $c = 0;
        elseif ($va === null) return 1;
        elseif ($vb === null) return -1;
        elseif ($sortCols[$sortKey] === 'number') $c = ((float)$va <=> (float)$vb);
        else $c = strcasecmp((string)$va, (string)$vb);
        if ($sortDir === 'desc') $c = -$c;
        if ($c !== 0) return $c;
        return strcasecmp((string)$a->item_code, (string)$b->item_code);
    });

    $pagination['total_filtered'] = count($displayRows);
    $pagination['total_pages'] = max(1, (int)ceil($pagination['total_filtered'] / $perPage));
    $pagination['page'] = min($pagination['page'], $pagination['total_pages']);
    $page = $pagination['page'];
    $offset = ($page - 1) * $perPage;
    $pagination['showing_from'] = $pagination['total_filtered'] > 0 ? $offset + 1 : 0;
    $pagination['showing_to'] = min($offset + $perPage, $pagination['total_filtered']);
    $displayRows = array_slice($displayRows, $offset, $perPage);
} else {
    $pagination['total_filtered'] = max(0, (int)$pagination['total_filtered']);
    $pagination['total_pages'] = max(1, (int)$pagination['total_pages']);
    $pagination['page'] = min($pagination['page'], $pagination['total_pages']);
    $page = $pagination['page'];
}

$hasActiveFilters = ($g('category') !== '' || $g('location') !== '' || $g('search') !== '' || $state !== 'all');
$noStockData      = ($total === 0 && empty($categories));
$lowLabel         = $extended ? 'at or below the reorder level' : 'at or below the low-stock threshold';
$sortLabels       = array(
    'item_code'=>'Item code','category'=>'Category','description'=>'Description',
    'final_qty'=>'In stock','min_stock'=>'Min','max_stock'=>'Max',
    'qty_to_order'=>'Qty to order','unit_price_ugx'=>'Price UGX',
    'location'=>'Location','supplier_name'=>'Supplier',
);

/* Sortable column header with ARIA sort state, mirroring /team/report. */
$roTh = function ($key, $label, $align = 'left', $sortable = true) use ($sortCols, $sortKey, $sortDir, $roUrl, $h) {
    $cls = 'ro-th' . ($align === 'right' ? ' ro-num' : '');
    if (!$sortable || !isset($sortCols[$key])) {
        return '<th scope="col" class="' . $cls . '">' . $h($label) . '</th>';
    }
    $active = ($sortKey === $key);
    $next   = ($active && $sortDir === 'asc') ? 'desc' : 'asc';
    $aria   = $active ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none';
    $href   = $roUrl(array('sort' => $key, 'dir' => $next));
    $icon   = '<svg class="ro-sort-ico" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">';
    if ($active && $sortDir === 'asc') {
        $icon .= '<path d="M6 2.5L9.2 6.2H2.8L6 2.5Z" fill="currentColor"/><path d="M6 9.5L2.8 7.2H9.2L6 9.5Z" fill="currentColor" opacity="0.22"/>';
    } elseif ($active && $sortDir === 'desc') {
        $icon .= '<path d="M6 2.5L9.2 4.8H2.8L6 2.5Z" fill="currentColor" opacity="0.22"/><path d="M6 9.5L2.8 5.8H9.2L6 9.5Z" fill="currentColor"/>';
    } else {
        $icon .= '<path d="M6 2.5L8.8 5.3H3.2L6 2.5ZM6 9.5L3.2 6.7H8.8L6 9.5Z" fill="currentColor" opacity="0.35"/>';
    }
    $icon .= '</svg>';
    $linkCls = 'ro-th-sort' . ($align === 'right' ? ' is-right' : '') . ($active ? ' is-sorted' : '');
    $sortTxt = $active ? (', sorted ' . ($sortDir === 'asc' ? 'ascending' : 'descending')) : ', not sorted';
    return '<th scope="col" class="' . $cls . '" aria-sort="' . $aria . '">'
         . '<a href="' . $h($href) . '" class="' . $linkCls . '" aria-label="' . $h($label . $sortTxt . '. Activate to sort.') . '">'
         . '<span>' . $h($label) . '</span>' . $icon . '</a></th>';
};

/* Shared formatting for optional numeric cells. */
$roNum = function ($raw, $decimals = 0) use ($h) {
    if ($raw === null || $raw === '') return '<span class="ro-na" title="Not on file">—</span>';
    return $h(number_format((float)$raw, $decimals));
};
$roText = function ($raw) use ($h) {
    $s = isset($raw) && $raw !== null ? trim((string)$raw) : '';
    return $s === '' ? '<span class="ro-na" title="Not on file">—</span>' : $h($s);
};
?>

<style>
/* ═══════════════════════════════════════════════════════════════════════════
   RE-ORDER REPORT — /team/report enterprise data-table design system.
   Local tokens are scoped to .ro-page. Base tokens (--surface, --sep,
   --t1..--t3, --blue/--green/--amber/--red, --r-*, --shadow-card) come from
   layouts/header.php. The reference view references global aliases such as
   --blue-lt / --sep-opaque / --r-pill that are NOT defined anywhere; the
   sibling supplier/user/lubricant surfaces solve this with scoped tokens,
   and this view follows that same pattern so every declaration resolves.
   ═══════════════════════════════════════════════════════════════════════════ */
.ro-page {
  --ro-fill:        rgba(0,0,0,0.035);
  --ro-fill-2:      rgba(0,0,0,0.065);
  --ro-sep-opaque:  rgba(0,0,0,0.13);
  --ro-blue-lt:     rgba(0,113,227,0.08);
  --ro-r-pill:      100px;
  --ro-ease:        cubic-bezier(.4,0,.2,1);
  --ro-font:        'Inter',-apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",sans-serif;
  --ro-mono:        ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;
  --ro-shadow-md:   0 4px 16px rgba(0,0,0,0.10), 0 1px 3px rgba(0,0,0,0.06);
  --ro-ink-red:     #a5271b;
  --ro-ink-amber:   #8a3f06;
  --ro-ink-green:   #12692c;
  --ro-ink-blue:    #0058b0;
  max-width:100%;
}
.ro-page *, .ro-page *::before, .ro-page *::after { box-sizing:border-box; }
.ro-sr-only {
  position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;
  overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;
}

/* ── Print-only official header ── */
.ro-print-banner { display:none; }

/* ── Page header ── */
.ro-head {
  display:flex;align-items:flex-start;justify-content:space-between;
  gap:16px;flex-wrap:wrap;margin-bottom:18px;
}
.ro-crumb {
  display:inline-flex;align-items:center;gap:6px;
  font-size:12px;font-weight:500;color:var(--t2);margin-bottom:5px;
}
.ro-crumb a { color:var(--t2);text-decoration:none;min-height:24px;display:inline-flex;align-items:center; }
.ro-crumb a:hover { color:var(--blue);text-decoration:underline; }
.ro-head-title { display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
.ro-head-title h1 {
  margin:0;font-size:23px;font-weight:700;letter-spacing:-0.025em;
  line-height:1.2;color:var(--t1);
}
.ro-mode-pill {
  display:inline-flex;align-items:center;gap:6px;
  padding:3px 10px;border:1px solid var(--sep);background:var(--surface);
  border-radius:var(--ro-r-pill);font-size:11.5px;font-weight:600;color:var(--t2);
}
.ro-mode-pill.is-fallback { background:var(--amber-fill);border-color:rgba(180,83,9,0.22);color:var(--ro-ink-amber); }
.ro-sub { margin-top:4px;font-size:13px;color:var(--t2);max-width:72ch;line-height:1.5; }
.ro-head-actions { display:flex;align-items:center;gap:8px;flex-wrap:wrap; }
/* Keep the 24 px minimum target even if a layout stylesheet is unavailable. */
.ro-page .ro-head-actions .btn { min-height:36px; }
.ro-page .ro-empty .btn { min-height:36px; }
.ro-page .ro-scope-bar .btn { min-height:34px; }
.ro-btn-ico { width:15px;height:15px;flex-shrink:0; }

/* ── KPI strip ── */
.ro-kpis {
  display:grid;grid-template-columns:repeat(3,minmax(0,1fr));
  gap:12px;margin-bottom:16px;
}
.ro-kpi {
  background:var(--surface);border:1px solid var(--sep);
  border-radius:var(--r-md);padding:14px 16px;box-shadow:var(--shadow-card);
  display:flex;flex-direction:column;justify-content:space-between;min-height:98px;
}
.ro-kpi-label {
  font-size:11px;font-weight:600;text-transform:uppercase;
  letter-spacing:0.04em;color:var(--t2);margin-bottom:6px;
}
.ro-kpi-val {
  font-size:25px;font-weight:700;letter-spacing:-0.03em;line-height:1.05;
  color:var(--t1);font-variant-numeric:tabular-nums;
}
.ro-kpi-val.is-critical { color:var(--ro-ink-red); }
.ro-kpi-val.is-na { color:var(--t2);font-size:20px;letter-spacing:-0.01em; }
.ro-kpi-meta { margin-top:7px;font-size:11.5px;color:var(--t2);line-height:1.35; }
.ro-kpi-meta.is-warning { color:var(--ro-ink-amber);font-weight:600; }

/* ── Data availability notice (honest partial state) ── */
.ro-notice {
  display:flex;align-items:flex-start;gap:10px;
  padding:12px 14px;margin-bottom:16px;
  background:var(--amber-fill);border:1px solid rgba(180,83,9,0.20);
  border-left:3px solid var(--amber);border-radius:var(--r-sm);
  font-size:12.5px;line-height:1.5;color:var(--t1);
}
.ro-notice svg { width:16px;height:16px;flex-shrink:0;margin-top:1px;color:var(--ro-ink-amber); }
.ro-notice strong { font-weight:600;color:var(--ro-ink-amber); }

/* ── Main surface ── */
.ro-shell {
  background:var(--surface);border:1px solid var(--sep);
  border-radius:var(--r-lg);box-shadow:var(--shadow-card);overflow:visible;
}
.ro-scope-bar {
  padding:12px 16px;background:var(--surface-2);
  border-bottom:1px solid var(--sep);border-radius:var(--r-lg) var(--r-lg) 0 0;
  display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;
}
.ro-field { display:flex;flex-direction:column;gap:4px; }
.ro-field label {
  font-size:10.5px;font-weight:600;text-transform:uppercase;
  letter-spacing:0.04em;color:var(--t2);margin:0;
}
.ro-input, .ro-select {
  height:34px;padding:0 10px;font-family:var(--ro-font);font-size:12.5px;
  color:var(--t1);background:var(--surface);
  border:1px solid var(--ro-sep-opaque);border-radius:var(--r-sm);
  outline:none;transition:border-color var(--ro-ease),box-shadow var(--ro-ease);
}
.ro-select {
  padding-right:28px;cursor:pointer;appearance:none;-webkit-appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236e6e73' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 9px center;
}
.ro-input::placeholder { color:var(--t2);opacity:1; }
.ro-input:focus, .ro-select:focus {
  border-color:var(--blue);box-shadow:0 0 0 3px var(--ro-blue-lt);
}
.ro-scope-bar .btn { height:34px;padding:0 16px; }

/* ── Toolbar: status tabs + search + view switch ── */
.ro-toolbar {
  padding:10px 16px;border-bottom:1px solid var(--sep);
  display:flex;align-items:center;justify-content:space-between;
  gap:12px;flex-wrap:wrap;background:var(--surface);
}
.ro-tabs { display:inline-flex;align-items:center;gap:4px;flex-wrap:wrap; }
.ro-tab {
  display:inline-flex;align-items:center;gap:6px;
  min-height:30px;padding:5px 11px;border-radius:var(--r-sm);
  font-size:12.5px;font-weight:500;color:var(--t2);
  text-decoration:none;border:1px solid transparent;
  transition:background var(--ro-ease),color var(--ro-ease),border-color var(--ro-ease);
}
.ro-tab:hover { background:var(--ro-fill);color:var(--t1); }
.ro-tab.is-active {
  background:var(--ro-blue-lt);color:var(--ro-ink-blue);font-weight:600;
  border-color:rgba(0,113,227,0.24);
}
.ro-tab-cnt {
  display:inline-flex;align-items:center;justify-content:center;
  min-width:20px;height:18px;padding:0 6px;border-radius:var(--ro-r-pill);
  font-size:11px;font-weight:600;background:var(--ro-fill-2);color:var(--t2);
  font-variant-numeric:tabular-nums;
}
.ro-tab.is-active .ro-tab-cnt { background:var(--blue);color:#fff; }
.ro-tools-right { display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-left:auto; }
.ro-search-wrap { position:relative;display:inline-flex;align-items:center; }
.ro-search-ico {
  position:absolute;left:9px;width:14px;height:14px;color:var(--t2);pointer-events:none;
}
.ro-search-input {
  height:34px;width:236px;padding:0 52px 0 29px;
  font-family:var(--ro-font);font-size:12.5px;color:var(--t1);
  background:var(--surface-2);border:1px solid var(--ro-sep-opaque);
  border-radius:var(--r-sm);outline:none;
  transition:border-color var(--ro-ease),background var(--ro-ease),box-shadow var(--ro-ease);
}
.ro-search-input:focus {
  background:var(--surface);border-color:var(--blue);
  box-shadow:0 0 0 3px var(--ro-blue-lt);
}
.ro-search-input:focus ~ .ro-kbd { display:none; }
.ro-kbd {
  position:absolute;right:8px;padding:1px 5px;font-family:var(--ro-mono);
  font-size:10.5px;color:var(--t2);background:var(--surface);
  border:1px solid var(--ro-sep-opaque);border-radius:4px;
  pointer-events:none;line-height:1.3;
}
.ro-search-clear {
  position:absolute;right:6px;min-width:24px;height:24px;
  display:inline-flex;align-items:center;justify-content:center;
  border-radius:50%;background:var(--ro-fill-2);color:var(--t2);
  font-size:14px;text-decoration:none;line-height:1;
}
.ro-search-clear:hover { background:var(--ro-sep-opaque);color:var(--t1); }
.ro-view-seg {
  display:inline-flex;padding:2px;gap:2px;background:var(--ro-fill);
  border-radius:var(--r-sm);border:1px solid var(--sep);
}
.ro-view-btn {
  display:inline-flex;align-items:center;gap:5px;
  min-height:28px;padding:0 10px;border-radius:6px;border:none;
  background:transparent;color:var(--t2);font-family:var(--ro-font);
  font-size:12px;font-weight:500;cursor:pointer;text-decoration:none;
  transition:background var(--ro-ease),color var(--ro-ease),box-shadow var(--ro-ease);
}
.ro-view-btn:hover { color:var(--t1); }
.ro-view-btn.is-active {
  background:var(--surface);color:var(--t1);font-weight:600;
  box-shadow:0 1px 2px rgba(0,0,0,0.10);
}

/* ── Active filter chips ── */
.ro-chips-bar {
  padding:8px 16px;background:#fbfbfd;border-bottom:1px solid var(--sep);
  display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:12px;
}
.ro-chip-label { color:var(--t2);font-weight:500;margin-right:2px; }
.ro-chip {
  display:inline-flex;align-items:center;gap:5px;min-height:24px;
  padding:2px 9px;border-radius:var(--ro-r-pill);
  background:var(--surface);border:1px solid var(--ro-sep-opaque);
  color:var(--t1);font-size:11.5px;font-weight:500;text-decoration:none;
  max-width:32ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.ro-chip:hover { border-color:var(--red);color:var(--ro-ink-red); }
.ro-chip-x { color:var(--t2);font-weight:700; }
.ro-chip-reset { margin-left:auto;min-height:24px;display:inline-flex;align-items:center;font-size:12px;font-weight:500;color:var(--ro-ink-blue); }
.ro-chip-reset:hover { text-decoration:underline; }

/* ── Enterprise table ── */
.ro-table-wrap { width:100%;overflow-x:auto; }
.ro-table { width:100%;border-collapse:collapse;font-size:12.5px; }
.ro-table thead th {
  position:sticky;top:0;z-index:2;background:var(--surface-2);
  border-bottom:1px solid var(--ro-sep-opaque);
  padding:9px 11px;text-align:left;white-space:nowrap;
  font-size:11px;font-weight:600;text-transform:uppercase;
  letter-spacing:0.035em;color:var(--t2);
}
.ro-table thead th.ro-num { text-align:right; }
.ro-th-sort {
  display:inline-flex;align-items:center;gap:4px;min-height:24px;
  color:var(--t2);text-decoration:none;cursor:pointer;user-select:none;
  transition:color var(--ro-ease);
}
.ro-th-sort.is-right { flex-direction:row-reverse; }
.ro-th-sort:hover { color:var(--t1); }
.ro-th-sort.is-sorted { color:var(--ro-ink-blue); }
.ro-sort-ico { flex-shrink:0; }
.ro-table tbody tr { border-bottom:1px solid var(--sep);transition:background var(--ro-ease); }
.ro-table tbody tr:last-child { border-bottom:none; }
.ro-table tbody tr:hover { background:#f8f9fb; }
.ro-table tbody td { padding:10px 11px;vertical-align:middle;color:var(--t1); }
.ro-table tbody tr.is-out { background:rgba(192,57,43,0.022); }
.ro-table tbody tr.is-out:hover { background:rgba(192,57,43,0.055); }
.ro-num { text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap; }
.ro-code {
  font-family:var(--ro-mono);font-size:11.5px;font-weight:600;
  color:var(--ro-ink-blue);white-space:nowrap;
}
.ro-desc { font-size:12.5px;font-weight:600;color:var(--t1);max-width:30ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.ro-part { font-size:11px;color:var(--t2);font-family:var(--ro-mono);margin-top:2px; }
.ro-cat {
  display:inline-flex;align-items:center;max-width:18ch;padding:2px 8px;
  border-radius:6px;background:var(--ro-fill);color:var(--t1);
  font-size:11px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.ro-val { font-family:var(--ro-mono);font-size:12.5px;font-weight:600;color:var(--t1); }
.ro-val.is-out { color:var(--ro-ink-red);font-weight:700; }
.ro-val.is-low { color:var(--ro-ink-amber); }
.ro-order { font-family:var(--ro-mono);font-size:13px;font-weight:700;color:var(--ro-ink-blue); }
.ro-na { color:var(--t2);font-family:var(--ro-mono);font-size:12px; }
.ro-status {
  display:inline-flex;align-items:center;gap:5px;padding:2px 9px;
  border-radius:var(--ro-r-pill);font-size:10.5px;font-weight:700;
  letter-spacing:0.02em;white-space:nowrap;
}
.ro-status .ro-dot { width:6px;height:6px;border-radius:50%;flex-shrink:0; }
.ro-status.is-out { background:var(--red-fill);color:var(--ro-ink-red); }
.ro-status.is-out .ro-dot { background:var(--red); }
.ro-status.is-low { background:var(--amber-fill);color:var(--ro-ink-amber); }
.ro-status.is-low .ro-dot { background:var(--amber); }
.ro-table tfoot td {
  padding:10px 11px;background:var(--surface-2);
  border-top:1px solid var(--ro-sep-opaque);font-size:11.5px;color:var(--t2);
}
.ro-table tfoot .ro-total-label { text-align:right;font-weight:600;text-transform:uppercase;letter-spacing:0.035em; }
.ro-table tfoot .ro-total-val { font-family:var(--ro-mono);font-size:13px;font-weight:700;color:var(--t1); }

/* ── Card fallback ── */
.ro-mode-table .ro-cards-container { display:none; }
.ro-mode-cards .ro-table-wrap { display:none; }
.ro-cards-bar {
  padding:8px 16px;border-bottom:1px solid var(--sep);background:var(--surface-2);
  display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;
  font-size:12px;color:var(--t2);
}
.ro-cards-bar a { color:var(--ro-ink-blue);min-height:24px;display:inline-flex;align-items:center; }
.ro-cards-grid {
  padding:16px;display:grid;
  grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:14px;
}
.ro-card-item {
  background:var(--surface);border:1px solid var(--ro-sep-opaque);
  border-radius:var(--r-md);padding:14px 16px;display:flex;flex-direction:column;gap:12px;
  transition:box-shadow var(--ro-ease),border-color var(--ro-ease);
}
.ro-card-item:hover { border-color:rgba(0,113,227,0.4);box-shadow:var(--ro-shadow-md); }
.ro-card-top { display:flex;align-items:flex-start;justify-content:space-between;gap:10px; }
.ro-card-id { min-width:0; }
.ro-card-code { font-family:var(--ro-mono);font-size:12px;font-weight:700;color:var(--ro-ink-blue); }
.ro-card-desc { font-size:13px;font-weight:600;color:var(--t1);margin-top:3px;line-height:1.35;word-break:break-word; }
.ro-card-part { font-size:11px;color:var(--t2);font-family:var(--ro-mono);margin-top:3px; }
.ro-card-metrics {
  display:grid;grid-template-columns:repeat(4,minmax(0,1fr));
  gap:6px;padding:9px 10px;border-radius:var(--r-sm);
  background:var(--surface-2);border:1px solid var(--sep);
}
.ro-cm-box { display:flex;flex-direction:column;gap:2px;min-width:0; }
.ro-cm-lbl { font-size:10px;font-weight:600;text-transform:uppercase;color:var(--t2); }
.ro-cm-val { font-family:var(--ro-mono);font-size:13px;font-weight:600;color:var(--t1); }
.ro-cm-val.is-out { color:var(--ro-ink-red); }
.ro-cm-val.is-order { color:var(--ro-ink-blue); }
.ro-card-foot {
  display:flex;align-items:center;justify-content:space-between;gap:8px;
  padding-top:9px;border-top:1px solid var(--sep);flex-wrap:wrap;font-size:11.5px;color:var(--t2);
}
.ro-card-foot > span { min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.ro-card-foot .ro-cat { font-size:10.5px; }

/* ── Empty state ── */
.ro-empty { padding:48px 24px;text-align:center; }
.ro-empty-ico {
  width:44px;height:44px;border-radius:12px;background:var(--ro-fill);
  color:var(--t2);display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;
}
.ro-empty h3 { font-size:15px;font-weight:600;color:var(--t1);margin-bottom:4px; }
.ro-empty p { font-size:13px;color:var(--t2);max-width:48ch;margin:0 auto 14px;line-height:1.5; }

/* ── Pagination footer ── */
.ro-pagination {
  padding:11px 16px;border-top:1px solid var(--sep);background:var(--surface-2);
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  flex-wrap:wrap;font-size:12.5px;color:var(--t2);
}
.ro-pagination strong { color:var(--t1);font-variant-numeric:tabular-nums; }
.ro-pages { display:inline-flex;align-items:center;gap:4px; }
.ro-page-link {
  display:inline-flex;align-items:center;justify-content:center;
  min-width:30px;height:28px;padding:0 8px;border-radius:6px;
  border:1px solid var(--ro-sep-opaque);background:var(--surface);
  color:var(--t1);font-size:12px;font-weight:500;text-decoration:none;
  font-variant-numeric:tabular-nums;
}
.ro-page-link:hover { background:var(--ro-fill); }
.ro-page-link.is-current { background:var(--blue);border-color:var(--blue);color:#fff;font-weight:600; }
.ro-page-link.is-disabled { opacity:.42;pointer-events:none; }
.ro-page-size { display:inline-flex;align-items:center;gap:6px; }
.ro-page-size label { font-size:12px;color:var(--t2);margin:0; }
.ro-page-size select { height:28px;font-size:12px; }

/* ── Footer ── */
.ro-footer {
  padding:11px 16px;border-top:1px solid var(--sep);background:var(--surface-2);
  border-radius:0 0 var(--r-lg) var(--r-lg);
  display:flex;align-items:flex-start;justify-content:space-between;gap:14px;
  flex-wrap:wrap;font-size:12px;color:var(--t2);line-height:1.5;
}
.ro-footer strong { color:var(--t1);font-variant-numeric:tabular-nums; }
.ro-footer-notes { max-width:78ch; }
.ro-footer-notes p + p { margin-top:3px; }
.ro-footer-legend { display:flex;align-items:center;gap:6px;flex-wrap:wrap; }

/* ── Focus visibility (WCAG 2.2 AA) ── */
.ro-page a:focus-visible,
.ro-page button:focus-visible,
.ro-page input:focus-visible,
.ro-page select:focus-visible,
.ro-page summary:focus-visible {
  outline:2px solid var(--blue);outline-offset:2px;border-radius:4px;
}
.ro-page .ro-input:focus-visible,
.ro-page .ro-select:focus-visible,
.ro-page .ro-search-input:focus-visible { outline:none; }

/* ── Responsive ── */
@media (max-width:1100px){
  .ro-search-input { width:200px; }
  .ro-head-actions { gap:6px; }
}
@media (max-width:780px){
  .ro-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .ro-kpi:last-child { grid-column:1 / -1; }
  .ro-search-input { width:100%; }
  .ro-tools-right { width:100%;justify-content:space-between; }
  .ro-search-wrap { flex:1; }
  .ro-page .ro-head-actions .btn { padding:7px 12px;font-size:12px; }
  /* Cards are the default on small screens unless the user explicitly chose Table. */
  .ro-shell:not(.ro-user-forced-table) .ro-table-wrap { display:none; }
  .ro-shell:not(.ro-user-forced-table) .ro-cards-container { display:block; }
}
@media (max-width:480px){
  .ro-kpis { grid-template-columns:1fr; }
  .ro-kpi:last-child { grid-column:auto; }
  .ro-scope-bar .ro-field, .ro-scope-bar .ro-select, .ro-scope-bar .ro-input { width:100%; }
  .ro-cards-grid { grid-template-columns:1fr;padding:12px; }
  .ro-card-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); }
}

/* ── Reduced motion ── */
@media (prefers-reduced-motion:reduce){
  .ro-page *, .ro-page *::before, .ro-page *::after {
    transition:none!important;animation:none!important;scroll-behavior:auto!important;
  }
}

/* ── Print ── */
@media print {
  .ro-print-banner {
    display:block!important;margin-bottom:14px;padding-bottom:10px;
    border-bottom:2px solid #1d1d1f;
  }
  .ro-print-banner h2 { font-size:16px;margin:0 0 3px;letter-spacing:0.01em; }
  .ro-print-banner p { font-size:11px;color:#333;margin:0;line-height:1.5; }
  .ro-head,.ro-scope-bar,.ro-toolbar,.ro-chips-bar,.ro-pagination,.ro-footer,.ro-cards-container,.ro-notice { display:none!important; }
  .ro-kpis { grid-template-columns:repeat(3,1fr);margin-bottom:10px; }
  .ro-kpi { box-shadow:none!important;border:1px solid #ddd!important;min-height:0;padding:8px 10px; }
  .ro-kpi-val { font-size:17px; }
  .ro-kpi-meta { font-size:9.5px; }
  .ro-shell { border:none!important;box-shadow:none!important;border-radius:0!important; }
  .ro-page .ro-table-wrap { display:block!important;overflow:visible!important; }
  .ro-table thead th { position:static!important; }
  .ro-table th,.ro-table td { padding:5px 6px!important;font-size:9.5px!important; }
  .ro-table tbody tr:hover { background:transparent!important; }
}
</style>

<div class="ro-page">

  <!-- Print-only official report header -->
  <div class="ro-print-banner">
    <h2>RE-ORDER REPORT — ITEMS AT OR BELOW MINIMUM STOCK</h2>
    <p>
      <?php if ($g('category') !== ''): ?>Category: <strong><?= $h($g('category')) ?></strong> · <?php endif; ?>
      <?php if ($g('location') !== ''): ?>Location: <strong><?= $h($g('location')) ?></strong> · <?php endif; ?>
      <?php if ($g('search') !== ''): ?>Search: <strong><?= $h($g('search')) ?></strong> · <?php endif; ?>
      Items shown: <strong><?= (int)count($displayRows) ?></strong> of <strong><?= (int)$total ?></strong>
      · Calculation: <strong><?= $extended ? 'min / max levels' : 'low-stock threshold (levels not installed)' ?></strong>
      · Printed <?= $h(date('d M Y H:i')) ?>
    </p>
  </div>

  <!-- Page header -->
  <header class="ro-head no-print">
    <div>
      <nav class="ro-crumb" aria-label="Breadcrumb">
        <a href="<?= $h(site_url('reports')) ?>">Reports</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Re-order report</span>
      </nav>
      <div class="ro-head-title">
        <h1>Re-order Report</h1>
        <span class="ro-mode-pill <?= $extended ? '' : 'is-fallback' ?>">
          <?= $extended ? 'Min / max levels' : 'Low-stock threshold' ?>
        </span>
      </div>
      <p class="ro-sub">
        Items <?= $h($lowLabel) ?>. The quantity to order is calculated from stock levels &mdash; never typed.
        Use the filters to narrow the list, then export it for purchasing.
      </p>
    </div>
    <div class="ro-head-actions">
      <a href="<?= $h($exportUrl) ?>" class="btn btn-primary"
         title="Download an Excel workbook of all items matching the category, location and search filters (not the current status tab)">
        <svg class="ro-btn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Download Excel
      </a>
      <a href="<?= $h(base_url('orders')) ?>" class="btn btn-secondary">Spare orders</a>
      <button type="button" class="btn btn-secondary" onclick="window.print()">
        <svg class="ro-btn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print
      </button>
    </div>
  </header>

  <!-- Executive summary strip -->
  <section class="ro-kpis" aria-label="Re-order summary for the current scope">
    <div class="ro-kpi">
      <div class="ro-kpi-label">Items to Re-order</div>
      <div class="ro-kpi-val"><?= $h(number_format($total)) ?></div>
      <div class="ro-kpi-meta">Matching the category, location and search filters</div>
    </div>
    <div class="ro-kpi">
      <div class="ro-kpi-label">Out of Stock</div>
      <div class="ro-kpi-val <?= $out > 0 ? 'is-critical' : '' ?>"><?= $h(number_format($out)) ?></div>
      <div class="ro-kpi-meta">Recorded balance at or below zero</div>
    </div>
    <div class="ro-kpi">
      <div class="ro-kpi-label">Total Qty to Order</div>
      <?php if ($total === 0): ?>
        <div class="ro-kpi-val">0</div>
        <div class="ro-kpi-meta">No items in the current scope</div>
      <?php elseif ($qtyMissing === $total): ?>
        <div class="ro-kpi-val is-na">Not available</div>
        <div class="ro-kpi-meta is-warning">Requires min / max stock levels on file</div>
      <?php else: ?>
        <div class="ro-kpi-val"><?= $h(number_format($qtyTotal)) ?></div>
        <div class="ro-kpi-meta <?= $qtyMissing > 0 ? 'is-warning' : '' ?>">
          <?= $qtyMissing > 0
              ? 'Units across '.number_format($total - $qtyMissing).' items; '.number_format($qtyMissing).' item(s) excluded — no levels on file'
              : 'Units to order, calculated across all '.number_format($total).' items' ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Honest partial state: the min/max columns are not installed -->
  <?php if (!$extended): ?>
  <aside class="ro-notice" aria-label="Data availability notice">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/></svg>
    <div>
      <strong>Minimum / maximum levels are not installed.</strong>
      The report falls back to the generic low-stock threshold, so quantities to order cannot be
      calculated and are shown as &ldquo;&mdash;&rdquo; rather than zero. The level columns are part of the
      database dump shipped with this repository; the levels set in the Stock List come in with the
      inventory Excel import.
    </div>
  </aside>
  <?php endif; ?>

  <!-- Main enterprise surface -->
  <section class="ro-shell ro-mode-<?= $h($viewMode) ?> <?= ($viewExplicit && $viewMode === 'table') ? 'ro-user-forced-table' : '' ?>"
           id="roShell" aria-label="Re-order report items">

    <!-- Scope filters (unchanged GET contract) -->
    <form method="get" action="<?= $h(site_url('reports/reorder')) ?>" class="ro-scope-bar no-print" id="roFilterForm">
      <input type="hidden" name="state" value="<?= $h($state) ?>">
      <input type="hidden" name="sort" value="<?= $h($sortKey) ?>">
      <input type="hidden" name="dir" value="<?= $h($sortDir) ?>">
      <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
      <?php if ($viewExplicit): ?><input type="hidden" name="view" value="<?= $h($viewMode) ?>">
      <?php endif; ?>

      <div class="ro-field">
        <label for="roCategory">Category</label>
        <select name="category" id="roCategory" class="ro-select ro-autosubmit" style="width:180px;">
          <option value="">All categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $h($c->category) ?>" <?= $g('category') === (string)$c->category ? 'selected' : '' ?>><?= $h($c->category) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="ro-field">
        <label for="roLocation">Location</label>
        <select name="location" id="roLocation" class="ro-select ro-autosubmit" style="width:170px;">
          <option value="">All locations</option>
          <?php foreach ($locations as $l): ?>
          <option value="<?= $h($l->location) ?>" <?= $g('location') === (string)$l->location ? 'selected' : '' ?>><?= $h($l->location) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    </form>

    <!-- Status tabs + search + view switch (all real links / form controls) -->
    <div class="ro-toolbar no-print">
      <nav class="ro-tabs" aria-label="Filter by stock state">
        <?php foreach ($tabDefs as $tKey => $tInfo): $on = ($state === $tKey); ?>
        <a href="<?= $h($roUrl(array('state' => $tKey))) ?>"
           class="ro-tab <?= $on ? 'is-active' : '' ?>"
           <?= $on ? 'aria-current="page"' : '' ?>
           title="<?= $h($tabHelp[$tKey]) ?>">
          <span><?= $h($tInfo['label']) ?></span>
          <span class="ro-tab-cnt"><?= $h(number_format((int)$tInfo['count'])) ?></span>
        </a>
        <?php endforeach; ?>
      </nav>

      <div class="ro-tools-right">
        <div class="ro-search-wrap">
          <svg class="ro-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search" form="roFilterForm" name="search" id="roSearchInput"
                 value="<?= $h($g('search')) ?>"
                 placeholder="Item code or description…"
                 aria-label="Search by item code or description"
                 class="ro-search-input">
          <?php if ($g('search') !== ''): ?>
          <a href="<?= $h($roUrl(array('search' => ''))) ?>" class="ro-search-clear" aria-label="Clear search query" title="Clear search">&times;</a>
          <?php else: ?>
          <kbd class="ro-kbd" aria-hidden="true">/</kbd>
          <?php endif; ?>
        </div>

        <div class="ro-view-seg" role="group" aria-label="Display layout">
          <a href="<?= $h($roUrl(array('view' => 'table'))) ?>"
             aria-pressed="<?= $viewMode === 'table' ? 'true' : 'false' ?>"
             class="ro-view-btn <?= $viewMode === 'table' ? 'is-active' : '' ?>">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>
            Table
          </a>
          <a href="<?= $h($roUrl(array('view' => 'cards'))) ?>"
             aria-pressed="<?= $viewMode === 'cards' ? 'true' : 'false' ?>"
             class="ro-view-btn <?= $viewMode === 'cards' ? 'is-active' : '' ?>">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Cards
          </a>
        </div>
      </div>
    </div>

    <!-- Active filter chips -->
    <?php if ($hasActiveFilters): ?>
    <div class="ro-chips-bar no-print" aria-label="Active filters">
      <span class="ro-chip-label">Active filters:</span>
      <?php if ($state !== 'all'): ?>
      <a class="ro-chip" href="<?= $h($roUrl(array('state' => 'all'))) ?>" title="Remove the status filter">
        Status: <?= $h($tabDefs[$state]['label']) ?> <span class="ro-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <?php if ($g('category') !== ''): ?>
      <a class="ro-chip" href="<?= $h($roUrl(array('category' => ''))) ?>" title="Remove the category filter">
        Category: <?= $h($g('category')) ?> <span class="ro-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <?php if ($g('location') !== ''): ?>
      <a class="ro-chip" href="<?= $h($roUrl(array('location' => ''))) ?>" title="Remove the location filter">
        Location: <?= $h($g('location')) ?> <span class="ro-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <?php if ($g('search') !== ''): ?>
      <a class="ro-chip" href="<?= $h($roUrl(array('search' => ''))) ?>" title="Remove the search filter">
        Search: &ldquo;<?= $h($g('search')) ?>&rdquo; <span class="ro-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <a href="<?= $h($roUrl(array('category'=>'','location'=>'','search'=>'','state'=>'all','sort'=>'final_qty','dir'=>'asc'))) ?>" class="ro-chip-reset">Reset all</a>
    </div>
    <?php endif; ?>

    <?php if (empty($displayRows)): ?>
    <!-- Honest empty / partial states -->
    <div class="ro-empty">
      <div class="ro-empty-ico" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </div>
      <?php if ($noStockData): ?>
        <h3>No stock data available</h3>
        <p>No material records were found. Import the inventory Excel file to populate stock levels, then this report will list items at or below their reorder level.</p>
      <?php elseif ($hasActiveFilters): ?>
        <h3>No items match your current filters</h3>
        <p>No re-order item matched the selected category, location, search or status for this scope. Clear the filters to see the full list.</p>
        <a href="<?= $h($roUrl(array('category'=>'','location'=>'','search'=>'','state'=>'all','sort'=>'final_qty','dir'=>'asc'))) ?>" class="btn btn-secondary">Reset all filters</a>
      <?php else: ?>
        <h3>Nothing needs re-ordering</h3>
        <p>Every item is currently <?= $extended ? 'above its minimum level' : 'above the low-stock threshold' ?>. This report only lists items <?= $h($lowLabel) ?>.</p>
      <?php endif; ?>
    </div>
    <?php else: ?>

    <!-- 1. Enterprise table view -->
    <div class="ro-table-wrap">
      <table class="ro-table">
        <caption class="ro-sr-only">Items at or below the reorder level, filtered by category, location, search and stock state. Sorted by <?= $h($sortLabels[$sortKey]) ?> <?= $sortDir === 'asc' ? 'ascending' : 'descending' ?>.</caption>
        <thead>
          <tr>
            <?= $roTh('item_code', 'Item Code') ?>
            <?= $roTh('category', 'Category') ?>
            <?= $roTh('description', 'Description') ?>
            <th scope="col" class="ro-th">Part No / Size</th>
            <th scope="col" class="ro-th">Unit</th>
            <?= $roTh('final_qty', 'In Stock', 'right') ?>
            <?= $roTh('min_stock', 'Min', 'right') ?>
            <?= $roTh('max_stock', 'Max', 'right') ?>
            <?= $roTh('qty_to_order', 'Qty to Order', 'right') ?>
            <th scope="col" class="ro-th">Status</th>
            <?= $roTh('unit_price_ugx', 'Price UGX', 'right') ?>
            <?= $roTh('location', 'Location') ?>
            <?= $roTh('supplier_name', 'Supplier') ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($displayRows as $m):
            $outRow  = $isOut($m);
            $min     = $levelVal($m, 'min_stock');
            $max     = $levelVal($m, 'max_stock');
            $hasQty  = $canCalc($m);
            $price   = (isset($m->unit_price_ugx) && $m->unit_price_ugx !== null && $m->unit_price_ugx !== '') ? $m->unit_price_ugx : null;
            $part    = isset($m->part_number_size) ? trim((string)$m->part_number_size) : '';
            $sup     = isset($m->supplier_name) ? trim((string)$m->supplier_name) : '';
          ?>
          <tr class="<?= $outRow ? 'is-out' : '' ?>">
            <td class="ro-code"><?= $h($m->item_code) ?></td>
            <td><span class="ro-cat" title="<?= $h($m->category) ?>"><?= $roText($m->category) ?></span></td>
            <td><div class="ro-desc" title="<?= $h($m->description) ?>"><?= $roText($m->description) ?></div></td>
            <td class="ro-part"><?= $roText($part) ?></td>
            <td><?= $roText(isset($m->unit) ? $m->unit : null) ?></td>
            <td class="ro-num"><span class="ro-val <?= $outRow ? 'is-out' : 'is-low' ?>"><?= $roNum((int)$m->final_qty) ?></span></td>
            <td class="ro-num"><?= $roNum($min) ?></td>
            <td class="ro-num"><?= $roNum($max) ?></td>
            <td class="ro-num"><?= $hasQty ? '<span class="ro-order">'.$roNum((int)$m->qty_to_order).'</span>' : '<span class="ro-na" title="Not calculable without min / max levels">—</span>' ?></td>
            <td>
              <span class="ro-status <?= $outRow ? 'is-out' : 'is-low' ?>"
                    title="<?= $outRow ? 'Balance at or below zero' : 'Balance above zero but at or below the reorder level' ?>">
                <span class="ro-dot" aria-hidden="true"></span><?= $outRow ? 'OUT OF STOCK' : 'ORDER' ?>
              </span>
            </td>
            <td class="ro-num"><?= $roNum($price, 2) ?></td>
            <td><?= $roText(isset($m->location) ? $m->location : null) ?></td>
            <td><?= $roText($sup) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="8" class="ro-total-label">Total qty to order &middot; <?= $h(number_format($total)) ?> item(s) in full scope</td>
            <td class="ro-num">
              <?php if ($qtyMissing === $total): ?>
                <span class="ro-na" title="Not calculable without min / max levels">—</span>
              <?php else: ?>
                <span class="ro-total-val"><?= $h(number_format($qtyTotal)) ?></span>
                <?php if ($qtyMissing > 0): ?>
                <span class="ro-na" title="<?= $h(number_format($qtyMissing)) ?> item(s) have no min / max levels and are excluded">partial</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td colspan="4"></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- 2. Responsive card view -->
    <div class="ro-cards-container">
      <div class="ro-cards-bar no-print">
        <span>Card layout &middot; Sorted by <strong><?= $h($sortLabels[$sortKey]) ?></strong> (<?= strtoupper($sortDir) ?>)</span>
        <a href="<?= $h($roUrl(array('view' => 'table'))) ?>">View as table</a>
      </div>
      <div class="ro-cards-grid">
        <?php foreach ($displayRows as $m):
          $outRow = $isOut($m);
          $min    = $levelVal($m, 'min_stock');
          $max    = $levelVal($m, 'max_stock');
          $hasQty = $canCalc($m);
          $price  = (isset($m->unit_price_ugx) && $m->unit_price_ugx !== null && $m->unit_price_ugx !== '') ? $m->unit_price_ugx : null;
          $part   = isset($m->part_number_size) ? trim((string)$m->part_number_size) : '';
          $sup    = isset($m->supplier_name) ? trim((string)$m->supplier_name) : '';
        ?>
        <article class="ro-card-item" aria-label="<?= $h($m->item_code . ' — ' . $m->description) ?>">
          <div class="ro-card-top">
            <div class="ro-card-id">
              <div class="ro-card-code"><?= $h($m->item_code) ?></div>
              <div class="ro-card-desc"><?= $roText($m->description) ?></div>
              <div class="ro-card-part"><?= $roText($part) ?></div>
            </div>
            <span class="ro-status <?= $outRow ? 'is-out' : 'is-low' ?>">
              <span class="ro-dot" aria-hidden="true"></span><?= $outRow ? 'OUT OF STOCK' : 'ORDER' ?>
            </span>
          </div>

          <div class="ro-card-metrics">
            <div class="ro-cm-box"><span class="ro-cm-lbl">In stock</span>
              <span class="ro-cm-val <?= $outRow ? 'is-out' : '' ?>"><?= $roNum((int)$m->final_qty) ?></span></div>
            <div class="ro-cm-box"><span class="ro-cm-lbl">Min</span>
              <span class="ro-cm-val"><?= $roNum($min) ?></span></div>
            <div class="ro-cm-box"><span class="ro-cm-lbl">Max</span>
              <span class="ro-cm-val"><?= $roNum($max) ?></span></div>
            <div class="ro-cm-box"><span class="ro-cm-lbl">To order</span>
              <span class="ro-cm-val <?= $hasQty ? 'is-order' : '' ?>"><?= $hasQty ? $roNum((int)$m->qty_to_order) : '<span class="ro-na" title="Not calculable without min / max levels">—</span>' ?></span></div>
          </div>

          <div class="ro-card-foot">
            <span class="ro-cat" title="<?= $h($m->category) ?>"><?= $roText($m->category) ?></span>
            <span><?= $roText(isset($m->location) ? $m->location : null) ?></span>
            <span><?= $roText($sup) ?></span>
            <span><?= $price !== null ? 'UGX ' . $roNum($price, 2) : '<span class="ro-na" title="Price not on file">—</span>' ?></span>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </div>

    <?php endif; ?>

    <!-- Pagination & page-size footer: the rows selector and links preserve
         every active filter, status tab, sort and display-mode query value. -->
    <div class="ro-pagination no-print">
      <div>
        <?php if ((int)$pagination['total_filtered'] > 0): ?>
          Showing <strong><?= $h(number_format((int)$pagination['showing_from'])) ?>&ndash;<?= $h(number_format((int)$pagination['showing_to'])) ?></strong>
          of <strong><?= $h(number_format((int)$pagination['total_filtered'])) ?></strong> item(s)
          <?php if ((int)$pagination['total_filtered'] < $total): ?>
            <span>(filtered from <?= $h(number_format($total)) ?>)</span>
          <?php endif; ?>
        <?php else: ?>
          0 matching item(s)
        <?php endif; ?>
      </div>

      <?php if ((int)$pagination['total_pages'] > 1): ?>
      <nav class="ro-pages" aria-label="Re-order report pagination">
        <a class="ro-page-link <?= $page <= 1 ? 'is-disabled' : '' ?>"
           href="<?= $h($roUrl(array('page' => max(1, $page - 1)))) ?>"
           <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Prev</a>
        <?php
        $pageWindowStart = max(1, $page - 2);
        $pageWindowEnd   = min((int)$pagination['total_pages'], $page + 2);
        if ($pageWindowStart > 1): ?>
          <a class="ro-page-link" href="<?= $h($roUrl(array('page' => 1))) ?>">1</a>
          <?php if ($pageWindowStart > 2): ?><span aria-hidden="true" style="padding:0 4px;color:var(--t2);">&hellip;</span><?php endif; ?>
        <?php endif;
        for ($p = $pageWindowStart; $p <= $pageWindowEnd; $p++): ?>
          <a class="ro-page-link <?= $p === $page ? 'is-current' : '' ?>"
             <?= $p === $page ? 'aria-current="page"' : '' ?>
             href="<?= $h($roUrl(array('page' => $p))) ?>"><?= $p ?></a>
        <?php endfor;
        if ($pageWindowEnd < (int)$pagination['total_pages']): ?>
          <?php if ($pageWindowEnd < (int)$pagination['total_pages'] - 1): ?><span aria-hidden="true" style="padding:0 4px;color:var(--t2);">&hellip;</span><?php endif; ?>
          <a class="ro-page-link" href="<?= $h($roUrl(array('page' => (int)$pagination['total_pages']))) ?>"><?= (int)$pagination['total_pages'] ?></a>
        <?php endif; ?>
        <a class="ro-page-link <?= $page >= (int)$pagination['total_pages'] ? 'is-disabled' : '' ?>"
           href="<?= $h($roUrl(array('page' => min((int)$pagination['total_pages'], $page + 1)))) ?>"
           <?= $page >= (int)$pagination['total_pages'] ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next</a>
      </nav>
      <?php endif; ?>

      <div class="ro-page-size">
        <label for="roPerPage">Rows:</label>
        <select id="roPerPage" class="ro-select">
          <?php foreach (array(10, 25, 50, 100) as $pp): ?>
            <option value="<?= $h($roUrl(array('per_page' => $pp, 'page' => 1))) ?>"
                    <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?> / page</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Footer: honest scope, calculation and export provenance -->
    <div class="ro-footer no-print">
      <div>
        Full filtered scope: <strong><?= $h(number_format($total)) ?></strong> item(s).
        KPIs and quantity totals cover this scope; the table and cards above show the current page.
      </div>
      <div class="ro-footer-notes">
        <p>
          Rule: <strong>stock &le; min &rarr; order up to max</strong>. Quantities are computed by
          <code>get_reorder_items()</code>; when a max is set it is the target, otherwise the min is used,
          otherwise no quantity is shown (never zero).
        </p>
        <p>
          Source: live materials balances and stock levels. The Excel download includes all items
          matching the category, location and search filters &mdash; not the current status tab.
        </p>
      </div>
    </div>
  </section>
</div>

<script>
(function () {
  var form = document.getElementById('roFilterForm');
  var search = document.getElementById('roSearchInput');
  var perPage = document.getElementById('roPerPage');

  /* Page-size changes are GET links so the URL remains shareable and the
     control degrades cleanly to the numbered links when JavaScript is off. */
  if (perPage) {
    perPage.addEventListener('change', function () {
      if (perPage.value) window.location.href = perPage.value;
    });
  }

  /* Selects apply immediately; the Apply button remains the explicit fallback. */
  var autos = document.querySelectorAll('.ro-autosubmit');
  for (var i = 0; i < autos.length; i++) {
    autos[i].addEventListener('change', function () { if (form) form.submit(); });
  }

  /* "/" focuses search, matching the global find convention. */
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== '/' || !search) return;
    var t = ev.target;
    var tag = (t && t.tagName) ? t.tagName.toLowerCase() : '';
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) return;
    ev.preventDefault();
    search.focus();
    search.select();
  });
})();
</script>
