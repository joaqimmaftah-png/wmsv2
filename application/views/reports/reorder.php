<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Re-order report — enterprise data-table surface.
 *
 * A quiet, single-surface inventory report: one editorial title, one summary
 * band, one command deck, URL-driven state tabs / sorting / view switching,
 * a spacious sticky table with a card fallback, an honest empty state and
 * print styles. Visual hierarchy is carried by scale, spacing and restraint.
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
   RE-ORDER REPORT — quiet, single-surface inventory intelligence.

   Everything is scoped to .ro-page. The shared layout supplies the base ink,
   surface, separator, radius and semantic color tokens; the local tokens below
   resolve the few aliases this report needs. No visual rule changes the query,
   filter, sort, pagination, export or print contracts.
   ═══════════════════════════════════════════════════════════════════════════ */
.ro-page {
  --ro-fill:        rgba(29,29,31,0.035);
  --ro-fill-2:      rgba(29,29,31,0.065);
  --ro-sep-opaque:  rgba(29,29,31,0.14);
  --ro-blue-lt:     rgba(0,113,227,0.075);
  --ro-r-pill:      100px;
  --ro-ease:        cubic-bezier(.22,.61,.36,1);
  --ro-font:        'Inter',-apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",sans-serif;
  --ro-mono:        ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;
  --ro-line:        rgba(29,29,31,0.075);
  --ro-line-strong: rgba(29,29,31,0.14);
  --ro-radius-lg:   20px;
  --ro-radius-md:   15px;
  --ro-radius-sm:   9px;
  --ro-shadow-md:   0 1px 2px rgba(29,29,31,0.03), 0 12px 32px rgba(29,29,31,0.055);
  --ro-shadow-lift: 0 2px 5px rgba(29,29,31,0.05), 0 20px 44px rgba(29,29,31,0.075);
  --ro-ink-red:     #a5271b;
  --ro-ink-amber:   #8a3f06;
  --ro-ink-green:   #12692c;
  --ro-ink-blue:    #0058b0;
  max-width:1600px;
  margin:0 auto;
  color:var(--t1);
  font-family:var(--ro-font);
  color-scheme:light;
  -webkit-font-smoothing:antialiased;
}
.ro-page *, .ro-page *::before, .ro-page *::after { box-sizing:border-box; }
.ro-sr-only {
  position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;
  overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;
}

/* One restrained entrance. The page settles; it never performs. */
@keyframes ro-arrive {
  from { opacity:0; transform:translate3d(0,8px,0); }
  to   { opacity:1; transform:translate3d(0,0,0); }
}
.ro-head, .ro-kpis, .ro-notice, .ro-shell { animation:ro-arrive .42s var(--ro-ease) both; }
.ro-head { animation-delay:.01s; }
.ro-kpis { animation-delay:.05s; }
.ro-notice { animation-delay:.08s; }
.ro-shell { animation-delay:.11s; }

/* ── Print-only official header ── */
.ro-print-banner { display:none; }

/* ── Page header: one unmistakable focal point ── */
.ro-head {
  display:grid;grid-template-columns:minmax(0,1fr) auto;
  align-items:end;gap:32px;padding:6px 2px 0;margin-bottom:28px;
}
.ro-crumb {
  display:inline-flex;align-items:center;gap:7px;
  margin-bottom:14px;font-size:10px;font-weight:600;
  letter-spacing:.11em;text-transform:uppercase;color:var(--t3);
}
.ro-crumb a {
  color:var(--t3);text-decoration:none;min-height:24px;
  display:inline-flex;align-items:center;transition:color var(--ro-ease);
}
.ro-crumb a:hover { color:var(--blue); }
.ro-head-title { display:flex;align-items:center;gap:12px;flex-wrap:wrap; }
.ro-head-title h1 {
  margin:0;font-size:clamp(30px,3.3vw,42px);font-weight:700;
  letter-spacing:-0.055em;line-height:1.04;color:var(--t1);
}
.ro-mode-pill {
  display:inline-flex;align-items:center;gap:6px;
  padding:4px 10px;border:1px solid var(--ro-line);background:var(--ro-fill);
  border-radius:var(--ro-r-pill);font-size:10.5px;font-weight:600;
  letter-spacing:.02em;color:var(--t2);white-space:nowrap;
}
.ro-mode-pill.is-fallback {
  background:var(--amber-fill);border-color:rgba(180,83,9,0.18);color:var(--ro-ink-amber);
}
.ro-sub {
  margin-top:11px;font-size:13.5px;color:var(--t2);
  max-width:64ch;line-height:1.6;
}
.ro-head-actions { display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap; }
.ro-page .ro-head-actions .btn {
  min-height:38px;border-radius:var(--ro-r-pill);font-size:12.5px;
  letter-spacing:-.01em;gap:7px;transition:background .18s var(--ro-ease),
  color .18s var(--ro-ease),border-color .18s var(--ro-ease),
  box-shadow .18s var(--ro-ease),transform .18s var(--ro-ease);
}
.ro-page .ro-head-actions .btn-primary {
  padding:0 17px;background:var(--blue);border:1px solid var(--blue);
  box-shadow:0 1px 2px rgba(0,113,227,.18);
}
.ro-page .ro-head-actions .btn-primary:hover {
  background:#006edb;transform:translateY(-1px);
  box-shadow:0 4px 12px rgba(0,113,227,.17);
}
.ro-page .ro-head-actions .btn-secondary {
  background:transparent;color:var(--t2);border-color:var(--ro-line-strong);
}
.ro-page .ro-head-actions .btn-secondary:hover {
  background:var(--ro-fill);color:var(--t1);border-color:var(--ro-sep-opaque);
  transform:none;
}
.ro-page .btn svg { fill:none;stroke:currentColor; }
.ro-btn-ico { width:15px;height:15px;flex-shrink:0; }

/* ── Summary: three facts, one surface ── */
.ro-kpis {
  display:grid;grid-template-columns:1.18fr .9fr 1.25fr;
  gap:0;margin-bottom:20px;background:var(--surface);
  border:1px solid var(--ro-line);border-radius:var(--ro-radius-lg);
  box-shadow:var(--ro-shadow-md);overflow:hidden;
}
.ro-kpi {
  display:flex;flex-direction:column;align-items:flex-start;
  justify-content:space-between;min-height:126px;
  padding:22px 26px;background:transparent;border:0;
  border-right:1px solid var(--ro-line);box-shadow:none;
}
.ro-kpi:last-child { border-right:0; }
.ro-kpi-label {
  font-size:10px;font-weight:600;text-transform:uppercase;
  letter-spacing:.11em;color:var(--t3);margin:0;
}
.ro-kpi-val {
  font-size:clamp(28px,3vw,36px);font-weight:700;
  letter-spacing:-.055em;line-height:1;color:var(--t1);
  font-variant-numeric:tabular-nums;
}
.ro-kpi.is-lead .ro-kpi-val { font-size:clamp(34px,3.8vw,46px); }
.ro-kpi-val.is-critical { color:var(--ro-ink-red); }
.ro-kpi-val.is-na { color:var(--t2);font-size:22px;letter-spacing:-.025em; }
.ro-kpi-meta {
  margin-top:11px;font-size:11.5px;color:var(--t3);
  line-height:1.45;max-width:38ch;
}
.ro-kpi-meta.is-warning { color:var(--ro-ink-amber);font-weight:600; }

/* ── Data availability note: present, but out of the way ── */
.ro-notice {
  display:flex;align-items:flex-start;gap:11px;
  padding:14px 17px;margin-bottom:20px;
  background:#fffaf4;border:1px solid rgba(180,83,9,0.15);
  border-left:3px solid var(--amber);border-radius:var(--ro-radius-md);
  font-size:12.5px;line-height:1.55;color:var(--t2);
}
.ro-notice svg { width:16px;height:16px;flex-shrink:0;margin-top:2px;color:var(--ro-ink-amber); }
.ro-notice strong { font-weight:600;color:var(--ro-ink-amber); }

/* ── Main surface ── */
.ro-shell {
  background:var(--surface);border:1px solid var(--ro-line);
  border-radius:var(--ro-radius-lg);box-shadow:var(--ro-shadow-md);
  overflow:visible;
}

/* Scope selectors read as quiet settings, not a second toolbar. */
.ro-scope-bar {
  padding:18px 22px 16px;background:var(--surface);
  border-bottom:1px solid var(--ro-line);
  border-radius:var(--ro-radius-lg) var(--ro-radius-lg) 0 0;
  display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;
}
.ro-field { display:flex;flex-direction:column;gap:6px; }
.ro-field label {
  font-size:9.5px;font-weight:600;text-transform:uppercase;
  letter-spacing:.1em;color:var(--t3);margin:0 0 0 2px;
}
.ro-input, .ro-select {
  height:38px;padding:0 12px;font-family:var(--ro-font);
  font-size:12.5px;color:var(--t1);background:var(--surface);
  border:1px solid var(--ro-line-strong);border-radius:var(--ro-radius-sm);
  outline:none;transition:border-color .18s var(--ro-ease),box-shadow .18s var(--ro-ease);
}
.ro-select {
  padding-right:30px;cursor:pointer;appearance:none;-webkit-appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236e6e73' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 10px center;
}
.ro-scope-category { width:190px; }
.ro-scope-location { width:180px; }
.ro-input::placeholder { color:var(--t3);opacity:1; }
.ro-input:focus, .ro-select:focus {
  border-color:var(--blue);box-shadow:0 0 0 3px var(--ro-blue-lt);
}
.ro-page .ro-scope-bar .btn {
  height:38px;min-height:38px;padding:0 15px;border-radius:var(--ro-radius-sm);
  background:var(--ro-fill);color:var(--t1);border:1px solid var(--ro-line);
  font-size:12px;font-weight:500;
}
.ro-page .ro-scope-bar .btn:hover { background:var(--ro-fill-2);transform:none; }

/* State, search and layout are one calm command row. */
.ro-toolbar {
  padding:0 22px;border-bottom:1px solid var(--ro-line);
  display:flex;align-items:center;justify-content:space-between;
  min-height:60px;gap:20px;flex-wrap:wrap;background:var(--surface);
}
.ro-tabs { display:inline-flex;align-items:stretch;gap:22px; }
.ro-tab {
  position:relative;display:inline-flex;align-items:center;gap:7px;
  min-height:60px;padding:0 1px;border:0;border-radius:0;
  font-size:12.5px;font-weight:500;color:var(--t3);
  text-decoration:none;white-space:nowrap;
  transition:color .18s var(--ro-ease);
}
.ro-tab::after {
  content:"";position:absolute;left:0;right:0;bottom:-1px;height:2px;
  border-radius:2px 2px 0 0;background:var(--blue);
  transform:scaleX(0);transform-origin:center;
  transition:transform .22s var(--ro-ease);
}
.ro-tab:hover { background:transparent;color:var(--t1); }
.ro-tab.is-active {
  background:transparent;color:var(--t1);font-weight:600;border:0;
}
.ro-tab.is-active::after { transform:scaleX(1); }
.ro-tab-cnt {
  display:inline-flex;align-items:center;justify-content:center;
  min-width:21px;height:19px;padding:0 6px;border-radius:var(--ro-r-pill);
  font-size:10.5px;font-weight:600;background:var(--ro-fill);color:var(--t3);
  font-variant-numeric:tabular-nums;
}
.ro-tab.is-active .ro-tab-cnt { background:var(--ro-blue-lt);color:var(--ro-ink-blue); }
.ro-tools-right { display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-left:auto; }
.ro-search-wrap { position:relative;display:inline-flex;align-items:center; }
.ro-search-ico {
  position:absolute;left:10px;width:14px;height:14px;color:var(--t3);pointer-events:none;
}
.ro-search-input {
  height:34px;width:220px;padding:0 44px 0 30px;
  font-family:var(--ro-font);font-size:12.5px;color:var(--t1);
  background:transparent;border:1px solid var(--ro-line-strong);
  border-radius:8px;outline:none;appearance:none;-webkit-appearance:none;
  transition:border-color .18s var(--ro-ease),background .18s var(--ro-ease),box-shadow .18s var(--ro-ease);
}
.ro-search-input::-webkit-search-cancel-button { display:none; }
.ro-search-input::placeholder { color:var(--t3);opacity:1; }
.ro-search-input:focus {
  background:var(--surface);border-color:var(--blue);
  box-shadow:0 0 0 3px var(--ro-blue-lt);
}
.ro-search-input:focus ~ .ro-kbd { display:none; }
.ro-kbd {
  position:absolute;right:8px;padding:1px 5px;font-family:var(--ro-mono);
  font-size:10px;color:var(--t3);background:var(--surface);
  border:1px solid var(--ro-line);border-radius:5px;
  pointer-events:none;line-height:1.3;
}
.ro-search-clear {
  position:absolute;right:6px;min-width:24px;height:24px;
  display:inline-flex;align-items:center;justify-content:center;
  border-radius:50%;background:var(--ro-fill-2);color:var(--t2);
  font-size:14px;text-decoration:none;line-height:1;
  transition:background .15s var(--ro-ease),color .15s var(--ro-ease);
}
.ro-search-clear:hover { background:var(--ro-sep-opaque);color:var(--t1); }
.ro-view-seg {
  display:inline-flex;padding:2px;gap:2px;background:var(--ro-fill);
  border-radius:8px;border:1px solid var(--ro-line);
}
.ro-view-btn {
  display:inline-flex;align-items:center;gap:5px;
  min-height:28px;padding:0 10px;border-radius:6px;border:none;
  background:transparent;color:var(--t3);font-family:var(--ro-font);
  font-size:11.5px;font-weight:500;cursor:pointer;text-decoration:none;
  transition:background .18s var(--ro-ease),color .18s var(--ro-ease),box-shadow .18s var(--ro-ease);
}
.ro-view-btn:hover { color:var(--t1); }
.ro-view-btn.is-active {
  background:var(--surface);color:var(--t1);font-weight:600;
  box-shadow:0 1px 3px rgba(29,29,31,.10);
}

/* ── Active filters ── */
.ro-chips-bar {
  padding:11px 22px;background:var(--surface-2);border-bottom:1px solid var(--ro-line);
  display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:12px;
}
.ro-chip-label { color:var(--t3);font-size:11px;font-weight:500;margin-right:3px; }
.ro-chip {
  display:inline-flex;align-items:center;gap:5px;min-height:25px;
  padding:2px 10px;border-radius:var(--ro-r-pill);
  background:var(--surface);border:1px solid var(--ro-line-strong);
  color:var(--t2);font-size:11px;font-weight:500;text-decoration:none;
  max-width:34ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  transition:color .15s var(--ro-ease),border-color .15s var(--ro-ease);
}
.ro-chip:hover { border-color:rgba(192,57,43,.32);color:var(--ro-ink-red); }
.ro-chip-x { color:var(--t3);font-weight:700; }
.ro-chip-reset {
  margin-left:auto;min-height:24px;display:inline-flex;align-items:center;
  font-size:11.5px;font-weight:600;color:var(--ro-ink-blue);text-decoration:none;
}
.ro-chip-reset:hover { text-decoration:underline; }

/* ── Enterprise table: generous rhythm, one accent ── */
.ro-table-wrap { width:100%;overflow-x:auto; }
.ro-table {
  width:100%;min-width:1260px;border-collapse:collapse;
  table-layout:fixed;font-size:12.5px;
}
.ro-table thead th {
  position:sticky;top:0;z-index:2;background:rgba(255,255,255,.96);
  -webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);
  border-bottom:1px solid var(--ro-line-strong);
  padding:12px 10px;text-align:left;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis;
  font-size:9.5px;font-weight:700;text-transform:uppercase;
  letter-spacing:.065em;color:var(--t3);
}
/* Fixed column intent keeps all thirteen fields visible on a laptop without
   letting long descriptions or supplier names dictate the whole report. */
.ro-table th:nth-child(1) { width:98px; }
.ro-table th:nth-child(2) { width:98px; }
.ro-table th:nth-child(3) { width:170px; }
.ro-table th:nth-child(4) { width:104px; }
.ro-table th:nth-child(5) { width:48px; }
.ro-table th:nth-child(6) { width:88px; }
.ro-table th:nth-child(7) { width:58px; }
.ro-table th:nth-child(8) { width:58px; }
.ro-table th:nth-child(9) { width:124px; }
.ro-table th:nth-child(10) { width:118px; }
.ro-table th:nth-child(11) { width:100px; }
.ro-table th:nth-child(12) { width:100px; }
.ro-table th:nth-child(13) { width:106px; }
.ro-table thead th.ro-num { text-align:right; }
.ro-th-sort {
  position:relative;display:inline-flex;align-items:center;
  min-height:24px;padding-right:12px;
  color:inherit;text-decoration:none;cursor:pointer;user-select:none;
  transition:color .18s var(--ro-ease);
}
.ro-th-sort.is-right { padding-right:0;padding-left:12px; }
.ro-th-sort:hover { color:var(--t1); }
.ro-th-sort.is-sorted { color:var(--ro-ink-blue); }
.ro-sort-ico {
  position:absolute;right:0;top:50%;width:10px;height:10px;
  transform:translateY(-50%);opacity:0;
  transition:opacity .15s var(--ro-ease);
}
.ro-th-sort.is-right .ro-sort-ico { left:0;right:auto; }
.ro-th-sort.is-sorted .ro-sort-ico,
.ro-th-sort:hover .ro-sort-ico,
.ro-th-sort:focus-visible .ro-sort-ico { opacity:1; }
.ro-table tbody tr {
  transition:background .16s var(--ro-ease);
}
.ro-table tbody td {
  padding:14px 10px;border-bottom:1px solid var(--ro-line);
  vertical-align:middle;color:var(--t1);line-height:1.45;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.ro-table tbody tr:last-child td { border-bottom:none; }
.ro-table tbody tr:hover { background:rgba(0,113,227,.026); }
.ro-table tbody tr.is-out { background:rgba(192,57,43,.018); }
.ro-table tbody tr.is-out:hover { background:rgba(192,57,43,.042); }
.ro-table tbody tr.is-out td:first-child { box-shadow:inset 2px 0 0 var(--red); }
.ro-num { text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap; }
.ro-cell-code { width:1%;white-space:nowrap; }
.ro-cell-desc { width:180px; }
.ro-cell-order { background:rgba(0,113,227,.018); }
.ro-cell-status { white-space:nowrap; }
.ro-code {
  font-family:var(--ro-mono);font-size:11px;font-weight:600;
  color:var(--t1);white-space:nowrap;letter-spacing:-.01em;
}
.ro-desc {
  font-size:12.5px;font-weight:600;color:var(--t1);
  max-width:30ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.ro-part, .ro-cell-secondary { color:var(--t2); }
.ro-part { font-size:10.5px;color:var(--t3);font-family:var(--ro-mono); }
.ro-cat {
  display:inline-flex;align-items:center;max-width:18ch;padding:2px 9px;
  border-radius:var(--ro-r-pill);background:transparent;
  border:1px solid var(--ro-line-strong);color:var(--t2);
  font-size:10.5px;font-weight:500;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis;
}
.ro-val { font-family:var(--ro-mono);font-size:12.5px;font-weight:600;color:var(--t1); }
.ro-val.is-out { color:var(--ro-ink-red);font-weight:700; }
.ro-val.is-low { color:var(--ro-ink-amber); }
.ro-order { font-family:var(--ro-mono);font-size:14px;font-weight:700;color:var(--ro-ink-blue); }
.ro-na { color:var(--t3);font-family:var(--ro-mono);font-size:12px; }
.ro-status {
  display:inline-flex;align-items:center;gap:6px;padding:0;
  border-radius:0;background:transparent;
  font-size:10px;font-weight:700;letter-spacing:.07em;white-space:nowrap;
}
.ro-status .ro-dot { width:6px;height:6px;border-radius:50%;flex-shrink:0; }
.ro-status.is-out { color:var(--ro-ink-red); }
.ro-status.is-out .ro-dot { background:var(--red); }
.ro-status.is-low { color:var(--ro-ink-amber); }
.ro-status.is-low .ro-dot { background:var(--amber); }
.ro-table tfoot td {
  padding:14px 10px;background:var(--surface-2);
  border-top:1px solid var(--ro-line-strong);font-size:11px;color:var(--t3);
}
.ro-table tfoot .ro-total-label {
  text-align:right;font-weight:600;text-transform:uppercase;letter-spacing:.075em;
}
.ro-table tfoot .ro-total-val {
  font-family:var(--ro-mono);font-size:15px;font-weight:700;color:var(--ro-ink-blue);
}

/* ── Card fallback: the answer first ── */
.ro-mode-table .ro-cards-container { display:none; }
.ro-mode-cards .ro-table-wrap { display:none; }
.ro-cards-bar {
  padding:11px 22px;border-bottom:1px solid var(--ro-line);background:transparent;
  display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;
  font-size:11.5px;color:var(--t3);
}
.ro-cards-bar strong { color:var(--t2); }
.ro-cards-bar a {
  color:var(--ro-ink-blue);min-height:24px;display:inline-flex;align-items:center;
  font-weight:600;text-decoration:none;
}
.ro-cards-bar a:hover { text-decoration:underline; }
.ro-cards-grid {
  padding:22px;display:grid;
  grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:14px;
}
.ro-card-item {
  background:var(--surface);border:1px solid var(--ro-line);
  border-radius:var(--ro-radius-md);padding:20px;
  display:flex;flex-direction:column;gap:20px;
  box-shadow:0 1px 2px rgba(29,29,31,.025);
  transition:border-color .2s var(--ro-ease),box-shadow .2s var(--ro-ease),transform .2s var(--ro-ease);
}
.ro-card-item:hover {
  border-color:rgba(0,113,227,.28);box-shadow:var(--ro-shadow-lift);transform:translateY(-2px);
}
.ro-card-top { display:flex;align-items:flex-start;justify-content:space-between;gap:14px; }
.ro-card-id { min-width:0; }
.ro-card-code {
  font-family:var(--ro-mono);font-size:10.5px;font-weight:600;
  color:var(--t3);letter-spacing:.02em;
}
.ro-card-desc {
  font-size:15px;font-weight:600;color:var(--t1);
  margin-top:6px;line-height:1.35;letter-spacing:-.02em;word-break:break-word;
}
.ro-card-part { font-size:10.5px;color:var(--t3);font-family:var(--ro-mono);margin-top:5px; }
.ro-card-metrics {
  display:grid;grid-template-columns:repeat(4,minmax(0,1fr));
  gap:0;padding:0;border:0;border-radius:0;background:transparent;
}
.ro-cm-box {
  display:flex;flex-direction:column;gap:6px;min-width:0;
  padding:0 12px;border-left:1px solid var(--ro-line);
}
.ro-cm-box:first-child { padding-left:0;border-left:0; }
.ro-cm-lbl {
  font-size:9.5px;font-weight:600;text-transform:uppercase;
  letter-spacing:.085em;color:var(--t3);
}
.ro-cm-val {
  font-family:var(--ro-mono);font-size:16px;font-weight:700;
  letter-spacing:-.025em;color:var(--t1);
}
.ro-cm-val.is-out { color:var(--ro-ink-red); }
.ro-cm-val.is-order, .ro-cm-box.is-primary .ro-cm-val { color:var(--ro-ink-blue); }
.ro-cm-box.is-primary .ro-cm-val { font-size:21px; }
.ro-card-foot {
  display:flex;align-items:center;justify-content:space-between;gap:10px;
  padding-top:14px;border-top:1px solid var(--ro-line);
  flex-wrap:wrap;font-size:11px;color:var(--t2);
}
.ro-card-foot > span { min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.ro-card-foot .ro-cat { font-size:10px; }

/* ── Empty state ── */
.ro-empty { padding:82px 24px 88px;text-align:center; }
.ro-empty-ico {
  width:52px;height:52px;border-radius:50%;background:var(--ro-fill);
  color:var(--t3);display:inline-flex;align-items:center;justify-content:center;margin-bottom:18px;
}
.ro-empty h3 {
  font-size:18px;font-weight:600;color:var(--t1);
  letter-spacing:-.035em;margin-bottom:7px;
}
.ro-empty p {
  font-size:13px;color:var(--t2);max-width:46ch;
  margin:0 auto 18px;line-height:1.6;
}
.ro-page .ro-empty .btn {
  min-height:36px;border-radius:var(--ro-r-pill);
  background:transparent;border:1px solid var(--ro-line-strong);color:var(--t1);
}
.ro-page .ro-empty .btn:hover { background:var(--ro-fill);transform:none; }

/* ── Pagination ── */
.ro-pagination {
  padding:13px 22px;border-top:1px solid var(--ro-line);background:transparent;
  display:flex;align-items:center;justify-content:space-between;gap:14px;
  flex-wrap:wrap;font-size:12px;color:var(--t3);
}
.ro-pagination strong { color:var(--t1);font-variant-numeric:tabular-nums; }
.ro-pages { display:inline-flex;align-items:center;gap:3px; }
.ro-page-link {
  display:inline-flex;align-items:center;justify-content:center;
  min-width:29px;height:29px;padding:0 8px;border-radius:7px;
  border:0;background:transparent;
  color:var(--t2);font-size:11.5px;font-weight:500;text-decoration:none;
  font-variant-numeric:tabular-nums;
  transition:background .15s var(--ro-ease),color .15s var(--ro-ease);
}
.ro-page-link:hover { background:var(--ro-fill);color:var(--t1); }
.ro-page-link.is-current {
  background:var(--ro-blue-lt);color:var(--ro-ink-blue);font-weight:700;
}
.ro-page-link.is-disabled { opacity:.35;pointer-events:none; }
.ro-page-ellipsis { padding:0 4px;color:var(--t3); }
.ro-page-size { display:inline-flex;align-items:center;gap:7px; }
.ro-page-size label { font-size:11.5px;color:var(--t3);margin:0; }
.ro-page-size select { height:32px;font-size:11.5px;padding:0 28px 0 10px; }

/* ── Provenance: readable, but visually last ── */
.ro-footer {
  padding:18px 22px;border-top:1px solid var(--ro-line);background:var(--surface-2);
  border-radius:0 0 var(--ro-radius-lg) var(--ro-radius-lg);
  display:grid;grid-template-columns:minmax(0,.78fr) minmax(0,1.22fr);
  gap:28px;font-size:11.5px;color:var(--t3);line-height:1.6;
}
.ro-footer strong { color:var(--t2);font-variant-numeric:tabular-nums; }
.ro-footer-label {
  display:block;margin-bottom:5px;font-size:9px;font-weight:700;
  letter-spacing:.12em;text-transform:uppercase;color:var(--t3);
}
.ro-footer-scope { color:var(--t2); }
.ro-footer-notes { max-width:82ch; }
.ro-footer-notes p + p { margin-top:5px; }
.ro-footer-notes code {
  font-family:var(--ro-mono);font-size:10.5px;color:var(--t2);
}
.ro-footer-legend { display:flex;align-items:center;gap:6px;flex-wrap:wrap; }

/* ── Focus visibility (WCAG 2.2 AA) ── */
.ro-page a:focus-visible,
.ro-page button:focus-visible,
.ro-page input:focus-visible,
.ro-page select:focus-visible,
.ro-page summary:focus-visible {
  outline:2px solid var(--blue);outline-offset:3px;border-radius:6px;
}
.ro-page .ro-input:focus-visible,
.ro-page .ro-select:focus-visible,
.ro-page .ro-search-input:focus-visible { outline:none; }

/* ── Responsive: hierarchy survives the width ── */
@media (max-width:1100px){
  .ro-search-input { width:190px; }
  .ro-head-actions { gap:6px; }
  .ro-kpi { padding:20px; }
}
@media (max-width:940px){
  .ro-head { grid-template-columns:1fr;align-items:start;gap:18px; }
  .ro-head-actions { justify-content:flex-start; }
  .ro-kpis { grid-template-columns:1fr 1fr; }
  .ro-kpi:nth-child(2) { border-right:0; }
  .ro-kpi:last-child { grid-column:1 / -1;border-top:1px solid var(--ro-line); }
  .ro-toolbar { align-items:stretch;gap:0; }
  .ro-tabs { width:100%;border-bottom:1px solid var(--ro-line);gap:18px; }
  .ro-tab { min-height:50px; }
  .ro-tools-right { width:100%;margin-left:0;padding:10px 0 12px; }
  .ro-footer { grid-template-columns:1fr;gap:14px; }
}
@media (max-width:780px){
  /* Reclaim the shared page gutter without changing the application layout. */
  .ro-page { margin-left:-16px;margin-right:-16px; }
  .ro-head { padding-top:0;margin-bottom:22px; }
  .ro-head-title h1 { font-size:32px; }
  .ro-scope-bar { padding:16px 18px; }
  .ro-toolbar { padding:0 18px; }
  .ro-chips-bar, .ro-pagination, .ro-footer { padding-left:18px;padding-right:18px; }
  .ro-cards-grid { padding:18px; }
  .ro-page .ro-head-actions .btn { padding:0 13px;font-size:12px; }
  /* Cards are the default on small screens unless the user explicitly chose Table. */
  .ro-shell:not(.ro-user-forced-table) .ro-table-wrap { display:none; }
  .ro-shell:not(.ro-user-forced-table) .ro-cards-container { display:block; }
}
@media (max-width:640px){
  .ro-page { margin-left:-24px;margin-right:-24px; }
  .ro-head-title h1 { font-size:30px; }
  .ro-head-actions { width:100%; }
  .ro-page .ro-head-actions .btn-primary { flex:1 1 100%;justify-content:center; }
  .ro-page .ro-head-actions .btn-secondary { flex:1 1 auto;justify-content:center; }
  .ro-kpis { grid-template-columns:1fr; }
  .ro-kpi, .ro-kpi:nth-child(2), .ro-kpi:last-child {
    grid-column:auto;min-height:0;padding:18px 20px;
    border-right:0;border-top:1px solid var(--ro-line);
  }
  .ro-kpi:first-child { border-top:0; }
  .ro-kpi.is-lead .ro-kpi-val { font-size:38px; }
  .ro-scope-bar .ro-field,
  .ro-scope-bar .ro-select,
  .ro-scope-bar .ro-input,
  .ro-page .ro-scope-bar .btn { width:100%; }
  .ro-tabs { gap:16px;overflow-x:auto;flex-wrap:nowrap;scrollbar-width:none; }
  .ro-tabs::-webkit-scrollbar { display:none; }
  .ro-tools-right { flex-direction:column;align-items:stretch;gap:8px; }
  .ro-search-wrap { width:100%;flex:none; }
  .ro-search-input { width:100%; }
  .ro-view-seg { align-self:flex-end;margin-left:0; }
  .ro-cards-grid { grid-template-columns:1fr;padding:14px; }
  .ro-card-item { padding:18px; }
  .ro-card-metrics { grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 0; }
  .ro-cm-box { padding:0 8px; }
  .ro-cm-box:nth-child(3) { padding-left:0;border-left:0; }
  .ro-card-foot { display:grid;grid-template-columns:1fr 1fr;gap:7px 12px; }
  .ro-card-foot > span:last-child { text-align:right; }
  .ro-pagination { align-items:flex-start;flex-direction:column; }
  .ro-page-size { margin-left:0; }
}
@media (max-width:400px){
  .ro-head-title { gap:8px; }
  .ro-mode-pill { font-size:10px; }
  .ro-view-btn { padding:0 8px; }
}

/* ── Reduced motion ── */
@media (prefers-reduced-motion:reduce){
  .ro-page *, .ro-page *::before, .ro-page *::after {
    transition:none!important;animation:none!important;scroll-behavior:auto!important;
  }
}

/* ── Print ── */
@media print {
  .ro-head,.ro-scope-bar,.ro-toolbar,.ro-chips-bar,.ro-pagination,.ro-footer,.ro-cards-container,.ro-notice { display:none!important; }
  .ro-head,.ro-kpis,.ro-notice,.ro-shell { animation:none!important; }
  .ro-print-banner {
    display:block!important;margin-bottom:14px;padding-bottom:10px;
    border-bottom:2px solid #1d1d1f;
  }
  .ro-print-banner h2 { font-size:16px;margin:0 0 3px;letter-spacing:.01em; }
  .ro-print-banner p { font-size:11px;color:#333;margin:0;line-height:1.5; }
  .ro-kpis {
    grid-template-columns:repeat(3,1fr);margin-bottom:10px;
    border:1px solid #ddd;border-radius:0;box-shadow:none;
  }
  .ro-kpi {
    min-height:0;padding:8px 10px;border-right:1px solid #ddd!important;
    border-top:0!important;box-shadow:none;
  }
  .ro-kpi:last-child { border-right:0!important; }
  .ro-kpi-val, .ro-kpi.is-lead .ro-kpi-val { font-size:17px; }
  .ro-kpi-meta { font-size:9.5px;margin-top:4px; }
  .ro-shell { border:none!important;box-shadow:none!important;border-radius:0!important; }
  .ro-page .ro-table-wrap { display:block!important;overflow:visible!important; }
  .ro-table { min-width:0!important; }
  .ro-table thead th {
    position:static!important;background:#f2f2f2!important;backdrop-filter:none!important;
  }
  .ro-table th,.ro-table td { padding:5px 6px!important;font-size:9.5px!important; }
  .ro-table tbody tr:hover, .ro-table tbody tr.is-out { background:transparent!important; }
  .ro-table tbody tr.is-out td:first-child { box-shadow:none!important; }
  .ro-status { background:transparent!important; }
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
      <a href="<?= $h($exportUrl) ?>" class="btn btn-primary ro-action-export"
         title="Download an Excel workbook of all items matching the category, location and search filters (not the current status tab)">
        <svg class="ro-btn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export Excel
      </a>
      <a href="<?= $h(base_url('orders')) ?>" class="btn btn-secondary ro-action-secondary">Spare orders</a>
      <button type="button" class="btn btn-secondary ro-action-secondary" onclick="window.print()">
        <svg class="ro-btn-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print
      </button>
    </div>
  </header>

  <!-- Executive summary strip -->
  <section class="ro-kpis" aria-label="Re-order summary for the current scope">
    <div class="ro-kpi is-lead">
      <div class="ro-kpi-label">Items to Re-order</div>
      <div class="ro-kpi-val"><?= $h(number_format($total)) ?></div>
      <div class="ro-kpi-meta">Matching the category, location and search filters</div>
    </div>
    <div class="ro-kpi">
      <div class="ro-kpi-label">Out of Stock</div>
      <div class="ro-kpi-val <?= $out > 0 ? 'is-critical' : '' ?>"><?= $h(number_format($out)) ?></div>
      <div class="ro-kpi-meta">Recorded balance at or below zero</div>
    </div>
    <div class="ro-kpi is-total">
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
      <strong>Min / max levels are not installed.</strong>
      Quantities to order stay blank until stock levels are imported; this view is using the generic
      low-stock threshold.
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
        <select name="category" id="roCategory" class="ro-select ro-autosubmit ro-scope-category">
          <option value="">All categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $h($c->category) ?>" <?= $g('category') === (string)$c->category ? 'selected' : '' ?>><?= $h($c->category) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="ro-field">
        <label for="roLocation">Location</label>
        <select name="location" id="roLocation" class="ro-select ro-autosubmit ro-scope-location">
          <option value="">All locations</option>
          <?php foreach ($locations as $l): ?>
          <option value="<?= $h($l->location) ?>" <?= $g('location') === (string)$l->location ? 'selected' : '' ?>><?= $h($l->location) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit" class="btn btn-primary btn-sm ro-apply">Apply</button>
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
            <th scope="col" class="ro-th">Part / Size</th>
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
            <td class="ro-cell-code ro-code"><?= $h($m->item_code) ?></td>
            <td><span class="ro-cat" title="<?= $h($m->category) ?>"><?= $roText($m->category) ?></span></td>
            <td class="ro-cell-desc"><div class="ro-desc" title="<?= $h($m->description) ?>"><?= $roText($m->description) ?></div></td>
            <td class="ro-cell-secondary ro-part" title="<?= $h($part) ?>"><?= $roText($part) ?></td>
            <td class="ro-cell-secondary"><?= $roText(isset($m->unit) ? $m->unit : null) ?></td>
            <td class="ro-cell-stock ro-num"><span class="ro-val <?= $outRow ? 'is-out' : 'is-low' ?>"><?= $roNum((int)$m->final_qty) ?></span></td>
            <td class="ro-cell-secondary ro-num"><?= $roNum($min) ?></td>
            <td class="ro-cell-secondary ro-num"><?= $roNum($max) ?></td>
            <td class="ro-cell-order ro-num"><?= $hasQty ? '<span class="ro-order">'.$roNum((int)$m->qty_to_order).'</span>' : '<span class="ro-na" title="Not calculable without min / max levels">—</span>' ?></td>
            <td class="ro-cell-status">
              <span class="ro-status <?= $outRow ? 'is-out' : 'is-low' ?>"
                    title="<?= $outRow ? 'Balance at or below zero' : 'Balance above zero but at or below the reorder level' ?>">
                <span class="ro-dot" aria-hidden="true"></span><?= $outRow ? 'OUT OF STOCK' : 'ORDER' ?>
              </span>
            </td>
            <td class="ro-cell-secondary ro-num"><?= $roNum($price, 2) ?></td>
            <td class="ro-cell-secondary" title="<?= $h(isset($m->location) ? (string)$m->location : '') ?>"><?= $roText(isset($m->location) ? $m->location : null) ?></td>
            <td class="ro-cell-secondary" title="<?= $h($sup) ?>"><?= $roText($sup) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="ro-total-row">
            <td colspan="8" class="ro-total-label">Total qty to order &middot; <?= $h(number_format($total)) ?> item(s) in full scope</td>
            <td class="ro-cell-order ro-num">
              <?php if ($qtyMissing === $total): ?>
                <span class="ro-na" title="Not calculable without min / max levels">—</span>
              <?php else: ?>
                <span class="ro-total-val"><?= $h(number_format($qtyTotal)) ?></span>
                <?php if ($qtyMissing > 0): ?>
                <span class="ro-na" title="<?= $h(number_format($qtyMissing)) ?> item(s) have no min / max levels and are excluded">partial</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td colspan="4" class="ro-cell-secondary"></td>
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
            <div class="ro-cm-box is-primary"><span class="ro-cm-lbl">To order</span>
              <span class="ro-cm-val <?= $hasQty ? 'is-order' : '' ?>"><?= $hasQty ? $roNum((int)$m->qty_to_order) : '<span class="ro-na" title="Not calculable without min / max levels">—</span>' ?></span></div>
          </div>

          <div class="ro-card-foot">
            <span class="ro-cat" title="<?= $h($m->category) ?>"><?= $roText($m->category) ?></span>
            <span title="<?= $h(isset($m->location) ? (string)$m->location : '') ?>"><?= $roText(isset($m->location) ? $m->location : null) ?></span>
            <span title="<?= $h($sup) ?>"><?= $roText($sup) ?></span>
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
          <?php if ($pageWindowStart > 2): ?><span aria-hidden="true" class="ro-page-ellipsis">&hellip;</span><?php endif; ?>
        <?php endif;
        for ($p = $pageWindowStart; $p <= $pageWindowEnd; $p++): ?>
          <a class="ro-page-link <?= $p === $page ? 'is-current' : '' ?>"
             <?= $p === $page ? 'aria-current="page"' : '' ?>
             href="<?= $h($roUrl(array('page' => $p))) ?>"><?= $p ?></a>
        <?php endfor;
        if ($pageWindowEnd < (int)$pagination['total_pages']): ?>
          <?php if ($pageWindowEnd < (int)$pagination['total_pages'] - 1): ?><span aria-hidden="true" class="ro-page-ellipsis">&hellip;</span><?php endif; ?>
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
      <div class="ro-footer-scope">
        <span class="ro-footer-label">Full filtered scope</span>
        <strong><?= $h(number_format($total)) ?></strong> item(s). KPIs and quantity totals cover
        this scope; the table and cards above show the current page.
      </div>
      <div class="ro-footer-notes">
        <p>
          <strong>Rule</strong> &middot; stock &le; min &rarr; order up to max.
          <code>get_reorder_items()</code> computes quantities; without stock levels,
          none is shown (never zero).
        </p>
        <p>
          <strong>Export</strong> &middot; The Excel download includes all items matching the
          category, location and search filters &mdash; not the current status tab.
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
