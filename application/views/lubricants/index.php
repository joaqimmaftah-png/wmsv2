<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$lub_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$f               = isset($filter) && is_array($filter) ? $filter : array();
$month_str       = Lubricant_model::clean_month($f['month'] ?? date('Y-m'));
$month_name      = date('F Y', strtotime($month_str . '-01'));
$tab             = isset($tab) ? (string)$tab : 'all';
$q               = isset($q) ? (string)$q : (string)($f['search'] ?? '');
$sort            = isset($sort) ? (string)$sort : 'date';
$dir             = isset($dir) ? (string)$dir : 'desc';
$view_mode       = isset($view_mode) ? (string)$view_mode : 'table';
$per_page        = isset($per_page) ? (int)$per_page : 25;
$allowed_pp      = isset($allowed_pp) && is_array($allowed_pp) ? $allowed_pp : array(10, 25, 50, 100, 150);
$cur_page        = isset($current_page) ? (int)$current_page : 1;
$tot_pages       = isset($total_pages) ? (int)$total_pages : 1;
$tot_filt        = isset($total_filtered) ? (int)$total_filtered : count($consumption ?? array());
$offset_val      = isset($offset) ? (int)$offset : 0;
$can_write       = !empty($can_write);
$can_view_rpt    = !empty($can_view_reports);
$ccy             = isset($currency_code) && $currency_code !== '' ? (string)$currency_code : 'TZS';
$groups          = isset($groups) && is_array($groups) ? $groups : array();
$summary         = isset($summary) && is_array($summary) ? $summary : array();
$compliance      = isset($compliance) && is_array($compliance) ? $compliance : array();
$lube_types      = isset($lube_types) && is_array($lube_types) ? $lube_types : array();
$equipment       = isset($equipment) && is_array($equipment) ? $equipment : array();
$kpi             = isset($kpi) && is_array($kpi) ? $kpi : array(
    'records' => count($consumption ?? array()), 'total_qty' => 0, 'actual_cost' => 0,
    'budget_cost' => 0, 'over_spec_count' => 0, 'linked_wo_count' => 0, 'machines_count' => 0,
);
$tab_counts      = isset($tab_counts) && is_array($tab_counts) ? $tab_counts : array(
    'all' => count($consumption ?? array()), 'over_variance' => 0, 'linked_wo' => 0, 'engine_hyd' => 0, 'unlinked' => 0,
);

$base_params = array('month' => $month_str);
if ($tab !== 'all') $base_params['tab'] = $tab;
if ($q !== '') $base_params['q'] = $q;
if (!empty($f['equipment_id'])) $base_params['equipment_id'] = (int)$f['equipment_id'];
if (!empty($f['lube_type_id'])) $base_params['lube_type_id'] = (int)$f['lube_type_id'];
if (!empty($f['category'])) $base_params['category'] = $f['category'];
if ($sort !== 'date') $base_params['sort'] = $sort;
if ($dir !== 'desc') $base_params['dir'] = $dir;
if ($view_mode !== 'table') $base_params['view'] = $view_mode;
if ($per_page !== 25) $base_params['per_page'] = $per_page;

$lub_url = function ($path = 'lubricants', array $override = array(), array $drop = array()) use ($base_params) {
    $p = array_merge($base_params, $override);
    foreach ($drop as $k) unset($p[$k]);
    foreach ($p as $k => $v) {
        if ($v === '' || $v === NULL) unset($p[$k]);
    }
    $qs = http_build_query($p);
    return base_url($path) . ($qs !== '' ? '?' . $qs : '');
};

$lub_sort_url = function ($col) use ($sort, $dir, $lub_url) {
    $nextDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return $lub_url('lubricants', array('sort' => $col, 'dir' => $nextDir, 'page' => 1));
};

$lub_sort_icon = function ($col) use ($sort, $dir) {
    if ($sort !== $col) return '<span class="lub-sort-ind" aria-hidden="true">&#8597;</span>';
    return $dir === 'asc'
        ? '<span class="lub-sort-ind is-active" aria-hidden="true">&#9650;</span>'
        : '<span class="lub-sort-ind is-active" aria-hidden="true">&#9660;</span>';
};
?>
<style>
#lubShell { max-width: 100%; overflow-x: clip; }
.lub-subnav {
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
  margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--sep, rgba(148,163,184,0.16));
}
.lub-subnav a {
  padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;
  text-decoration: none; color: var(--t2); background: var(--card, #111827);
  border: 1px solid var(--sep, rgba(148,163,184,0.16));
}
.lub-subnav a.is-active { background: var(--blue); color: #ffffff; border-color: var(--blue); }

.lub-hero {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  flex-wrap: wrap; margin-bottom: 14px;
}
.lub-hero-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.lub-hero-title h1 { margin: 0; font-size: 18px; font-weight: 700; color: var(--t1); }
.lub-pill {
  padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700;
  background: var(--g2); color: var(--t2); border: 1px solid var(--sep, rgba(148,163,184,0.18));
}
.lub-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.lub-kpi-grid {
  display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px;
}
.lub-kpi {
  padding: 12px 14px; border-radius: 12px; background: var(--card, #111827);
  border: 1px solid var(--sep, rgba(148,163,184,0.16)); min-width: 0;
}
.lub-kpi-k { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--t3); }
.lub-kpi-v { font-size: 20px; font-weight: 800; color: var(--t1); margin-top: 4px; line-height: 1.15; }
.lub-kpi-s { font-size: 11px; color: var(--t3); margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.lub-tabs { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
.lub-tab {
  display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 8px;
  font-size: 12px; font-weight: 600; color: var(--t2); text-decoration: none;
  background: var(--card, #111827); border: 1px solid var(--sep, rgba(148,163,184,0.16));
}
.lub-tab.is-active { background: var(--blue); color: #ffffff; border-color: var(--blue); }
.lub-tab-cnt {
  padding: 1px 6px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
  background: rgba(148,163,184,0.2); color: inherit;
}
.lub-tab.is-active .lub-tab-cnt { background: rgba(255,255,255,0.22); color: #ffffff; }

.lub-toolbar {
  display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
  padding: 10px 12px; margin-bottom: 12px; border-radius: 12px;
  background: var(--card, #111827); border: 1px solid var(--sep, rgba(148,163,184,0.16));
}
.lub-filter-form { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; flex: 1 1 auto; min-width: 0; }
.lub-search-wrap { position: relative; flex: 1 1 200px; min-width: 165px; max-width: 280px; }
.lub-search-wrap input { width: 100%; padding-right: 28px; }
.lub-slash-kbd {
  position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
  padding: 1px 5px; font-size: 10px; font-family: monospace; border-radius: 4px;
  color: var(--t3); border: 1px solid var(--sep, rgba(148,163,184,0.25)); pointer-events: none;
}
.lub-view-switch {
  display: inline-flex; align-items: center; border-radius: 8px; overflow: hidden;
  border: 1px solid var(--sep, rgba(148,163,184,0.2)); background: var(--g1, rgba(15,23,42,0.5));
}
.lub-view-btn {
  border: 0; background: transparent; color: var(--t2); padding: 6px 10px;
  font-size: 11.5px; font-weight: 600; cursor: pointer;
}
.lub-view-btn.is-active { background: var(--blue); color: #ffffff; }

.lub-batch-bar {
  display: none; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
  padding: 9px 14px; margin-bottom: 12px; border-radius: 10px;
  background: rgba(37,99,235,0.14); border: 1px solid rgba(59,130,246,0.45);
}
.lub-batch-bar.is-open { display: flex; }

.lub-table-wrap { width: 100%; overflow-x: hidden; }
.lub-table { width: 100%; table-layout: fixed; border-collapse: collapse; }
.lub-table th, .lub-table td {
  padding: 10px 8px; vertical-align: middle; overflow: hidden; text-overflow: ellipsis;
  border-bottom: 1px solid var(--g2, rgba(148,163,184,0.12));
}
.lub-table th {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
  color: var(--t3); text-align: left; white-space: nowrap;
}
.lub-table th a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
.lub-sort-ind { font-size: 10px; opacity: 0.45; }
.lub-sort-ind.is-active { opacity: 1; color: var(--blue); }

.lub-c-chk  { width: 34px; text-align: center !important; }
.lub-c-date { width: 10%; }
.lub-c-asset{ width: 15%; }
.lub-c-lube { width: 18%; }
.lub-c-qty  { width: 10%; text-align: right !important; }
.lub-c-std  { width: 10%; text-align: right !important; }
.lub-c-var  { width: 11%; text-align: center !important; }
.lub-c-cost { width: 10%; text-align: right !important; }
.lub-c-wo   { width: 11%; }
.lub-c-act  { width: 82px; text-align: right !important; }

.lub-open-btn {
  background: none; border: 0; padding: 0; text-align: left; cursor: pointer;
  color: var(--t1); font-weight: 700; font-size: 12.5px; max-width: 100%;
  display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.lub-open-btn:hover { color: var(--blue); text-decoration: underline; }
.lub-sub { font-size: 11px; color: var(--t3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px; }

.lub-badge {
  display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
}
.lub-b-ok   { background: rgba(22,163,74,0.16); color: #4ade80; border: 1px solid rgba(74,222,128,0.32); }
.lub-b-over { background: rgba(220,38,38,0.18); color: #fca5a5; border: 1px solid rgba(252,165,165,0.35); }
.lub-b-warn { background: rgba(245,158,11,0.16); color: #fcd34d; border: 1px solid rgba(252,211,77,0.32); }
.lub-b-info { background: rgba(56,189,248,0.14); color: #7dd3fc; border: 1px solid rgba(125,211,252,0.3); }

.lub-groups-container { display: none; }
.lub-group-card {
  margin-bottom: 12px; border-radius: 12px; overflow: hidden;
  background: var(--card, #111827); border: 1px solid var(--sep, rgba(148,163,184,0.16));
}
.lub-group-hdr {
  display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
  padding: 11px 16px; background: rgba(148,163,184,0.06); border-bottom: 1px solid var(--g2);
}
.lub-group-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; padding: 12px 16px; }
.lub-group-item {
  padding: 10px 12px; border-radius: 9px; border: 1px solid var(--sep, rgba(148,163,184,0.15));
  display: flex; align-items: center; justify-content: space-between; gap: 10px; min-width: 0;
}

.lub-cards-container { display: none; }
.lub-cards-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.lub-card {
  padding: 14px; border-radius: 12px; background: var(--card, #111827);
  border: 1px solid var(--sep, rgba(148,163,184,0.16)); display: flex; flex-direction: column; gap: 10px; min-width: 0;
}
.lub-card-metrics {
  display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px;
  padding: 8px; border-radius: 8px; background: rgba(148,163,184,0.06); font-size: 11px;
}

#lubShell[data-view="groups"] .lub-table-wrap,
#lubShell[data-view="groups"] .lub-cards-container { display: none; }
#lubShell[data-view="groups"] .lub-groups-container { display: block; }

#lubShell[data-view="cards"] .lub-table-wrap,
#lubShell[data-view="cards"] .lub-groups-container { display: none; }
#lubShell[data-view="cards"] .lub-cards-container { display: block; }

.lub-pager {
  display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
  padding: 12px 16px; border-top: 1px solid var(--g2); font-size: 12px; color: var(--t3);
}

/* Slide-Over Fluid Consumption Dossier Drawer */
.lub-drawer-backdrop {
  position: fixed; inset: 0; background: rgba(2,6,23,0.62); z-index: 1040;
  opacity: 0; pointer-events: none; transition: opacity .18s ease;
}
.lub-drawer-backdrop.is-open { opacity: 1; pointer-events: auto; }
.lub-drawer {
  position: fixed; top: 0; right: 0; bottom: 0; width: min(500px, 100vw); z-index: 1050;
  background: var(--card, #0f172a); border-left: 1px solid var(--sep, rgba(148,163,184,0.22));
  box-shadow: -16px 0 40px rgba(0,0,0,0.45); display: flex; flex-direction: column;
  transform: translateX(100%); visibility: hidden; transition: transform .2s ease, visibility .2s;
}
.lub-drawer.is-open { transform: translateX(0); visibility: visible; }
.lub-drawer-hdr {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 10px;
  padding: 16px 18px; border-bottom: 1px solid var(--g2);
}
.lub-drawer-body { padding: 16px 18px; overflow-y: auto; flex: 1 1 auto; }
.lub-drawer-ftr {
  display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
  padding: 12px 18px; border-top: 1px solid var(--g2);
}
.lub-kv-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-bottom: 14px; }
.lub-kv { padding: 9px 11px; border-radius: 8px; background: rgba(148,163,184,0.06); min-width: 0; }
.lub-kv-k { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--t3); }
.lub-kv-v { font-size: 12.5px; font-weight: 600; color: var(--t1); margin-top: 2px; word-break: break-word; }

@media (max-width: 1100px) {
  .lub-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .lub-cards-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 900px) {
  .lub-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .lub-table-wrap { display: none !important; }
  .lub-cards-container { display: block !important; }
  .lub-cards-grid { grid-template-columns: minmax(0, 1fr); }
  .lub-group-grid { grid-template-columns: minmax(0, 1fr); }
  .lub-search-wrap { max-width: 100%; flex: 1 1 100%; }
  .lub-kv-grid { grid-template-columns: minmax(0, 1fr); }
}
</style>

<div id="lubShell" data-view="<?= $lub_esc($view_mode) ?>">

  <!-- Sub-Navigation Across Lubricants Suite -->
  <nav class="lub-subnav" aria-label="Lubricants navigation">
    <a href="<?= base_url('lubricants') ?>" class="is-active">Consumption Ledger</a>
    <?php if ($can_write): ?>
    <a href="<?= base_url('lubricants/budget') ?>">Lube Budget</a>
    <?php endif; ?>
    <a href="<?= base_url('lubricants/specifications') ?>">OEM Specifications</a>
    <a href="<?= base_url('lubricants/types') ?>">Lube Types</a>
    <a href="<?= base_url('lubricants/forecast') ?>">PM Forecast</a>
  </nav>

  <!-- Hero Header -->
  <div class="lub-hero">
    <div class="lub-hero-title">
      <h1>Lubricants &amp; Consumables — <?= $lub_esc($month_name) ?></h1>
      <span class="lub-pill"><?= (int)$tot_filt ?> issue(s)</span>
    </div>
    <div class="lub-actions">
      <a href="<?= $lub_esc($lub_url('lubricants/export_csv', array(), array('page', 'per_page'))) ?>" id="lubExportCsvBtn" class="btn btn-ghost btn-sm">Export CSV</a>
      <a href="<?= $lub_esc($lub_url('lubricants/export_xlsx', array(), array('page', 'per_page'))) ?>" id="lubExportXlsxBtn" class="btn btn-ghost btn-sm">Export Excel</a>
      <?php if ($can_view_rpt): ?>
      <a href="<?= $lub_esc(base_url('reports/lube_compliance?month=' . $month_str)) ?>" class="btn btn-ghost btn-sm">Compliance Report</a>
      <?php endif; ?>
      <?php if ($can_write): ?>
      <a href="<?= base_url('lubricants/record') ?>" id="lubRecordBtn" class="btn btn-primary btn-sm">+ Record Consumption</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- 5-Card KPI Strip -->
  <div class="lub-kpi-grid">
    <div class="lub-kpi">
      <div class="lub-kpi-k">MTD Issues</div>
      <div class="lub-kpi-v"><?= (int)$kpi['records'] ?></div>
      <div class="lub-kpi-s"><?= (int)$kpi['linked_wo_count'] ?> linked to Work Orders</div>
    </div>
    <div class="lub-kpi">
      <div class="lub-kpi-k">Volume Dispensed</div>
      <div class="lub-kpi-v"><?= number_format((float)$kpi['total_qty'], 1) ?></div>
      <div class="lub-kpi-s">Across <?= count($summary) ?> active fluid grade(s)</div>
    </div>
    <div class="lub-kpi">
      <div class="lub-kpi-k">MTD Actual Spend</div>
      <div class="lub-kpi-v"><?= $lub_esc($ccy) ?> <?= number_format((float)$kpi['actual_cost'], 0) ?></div>
      <div class="lub-kpi-s">Budget: <?= $lub_esc($ccy) ?> <?= number_format((float)$kpi['budget_cost'], 0) ?></div>
    </div>
    <div class="lub-kpi">
      <div class="lub-kpi-k">Machines Serviced</div>
      <div class="lub-kpi-v" style="color:var(--green);"><?= (int)$kpi['machines_count'] ?></div>
      <div class="lub-kpi-s">Unique fleet assets in <?= $lub_esc($month_str) ?></div>
    </div>
    <div class="lub-kpi">
      <div class="lub-kpi-k">Over-Spec Variances</div>
      <div class="lub-kpi-v" style="color:<?= (int)$kpi['over_spec_count'] > 0 ? 'var(--red)' : 'var(--t1)' ?>;"><?= (int)$kpi['over_spec_count'] ?></div>
      <div class="lub-kpi-s">Issues exceeding OEM standard</div>
    </div>
  </div>

  <?php if (!empty($compliance)): ?>
  <!-- Compact PM Compliance Matrix -->
  <div class="card" style="margin-bottom:14px;">
    <div class="ch">
      <div>
        <div class="ct">PM Requirement vs Actual Dispensed — <?= $lub_esc($month_name) ?></div>
        <div class="cs">Scheduled PM fluid demand compared against recorded store issues</div>
      </div>
    </div>
    <div style="padding:10px 14px 14px;display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;">
      <?php foreach ($compliance as $cp):
        $pct = $cp['variance_pct'];
        if ($pct === NULL)     { $cpLbl = 'Unscheduled'; $cpCls = 'lub-b-info'; }
        elseif ($pct > 10)     { $cpLbl = 'Over-Using (+' . $pct . '%)'; $cpCls = 'lub-b-over'; }
        elseif ($pct < -10)    { $cpLbl = 'Under-Using (' . $pct . '%)'; $cpCls = 'lub-b-warn'; }
        else                   { $cpLbl = 'On Target'; $cpCls = 'lub-b-ok'; }
      ?>
      <div style="padding:10px 12px;border-radius:10px;border:1px solid var(--sep,rgba(148,163,184,0.16));background:rgba(148,163,184,0.05);">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
          <span style="font-weight:700;color:var(--t1);font-size:12.5px;"><?= $lub_esc($cp['lube_name']) ?></span>
          <span class="lub-badge <?= $cpCls ?>"><?= $lub_esc($cpLbl) ?></span>
        </div>
        <div style="font-size:11.5px;color:var(--t3);margin-top:5px;" class="mono">
          PM Std: <?= number_format((float)$cp['required_qty'], 1) ?> <?= $lub_esc($cp['unit']) ?>
          &middot; Actual: <strong style="color:var(--t1);"><?= number_format((float)$cp['actual_qty'], 1) ?> <?= $lub_esc($cp['unit']) ?></strong>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Segment Filter Tabs -->
  <div class="lub-tabs" role="tablist" aria-label="Consumption segments">
    <?php
    $tabDefs = array(
        'all'           => array('All Records', $tab_counts['all']),
        'over_variance' => array('Over-Spec Variance', $tab_counts['over_variance']),
        'linked_wo'     => array('Linked to WO', $tab_counts['linked_wo']),
        'engine_hyd'    => array('Engine & Hydraulic', $tab_counts['engine_hyd']),
        'unlinked'      => array('Top-Ups (No WO)', $tab_counts['unlinked']),
    );
    foreach ($tabDefs as $tk => $meta):
    ?>
    <a href="<?= $lub_esc($lub_url('lubricants', array('tab' => $tk === 'all' ? '' : $tk, 'page' => 1))) ?>"
       class="lub-tab <?= $tab === $tk ? 'is-active' : '' ?>"
       role="tab" aria-selected="<?= $tab === $tk ? 'true' : 'false' ?>">
      <span><?= $lub_esc($meta[0]) ?></span>
      <span class="lub-tab-cnt"><?= (int)$meta[1] ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Search, Filters & 3-Way Layout Switcher -->
  <div class="lub-toolbar">
    <form method="get" action="<?= base_url('lubricants') ?>" class="lub-filter-form" id="lubFilterForm">
      <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= $lub_esc($tab) ?>"><?php endif; ?>
      <?php if ($sort !== 'date'): ?><input type="hidden" name="sort" value="<?= $lub_esc($sort) ?>"><?php endif; ?>
      <?php if ($dir !== 'desc'): ?><input type="hidden" name="dir" value="<?= $lub_esc($dir) ?>"><?php endif; ?>
      <input type="hidden" name="view" id="lubViewInput" value="<?= $lub_esc($view_mode) ?>">

      <div class="lub-search-wrap">
        <input type="search" id="lubSearchInput" name="q" class="form-control"
               placeholder="Search EX 006, fluid, WO#, mechanic…"
               aria-label="Search lubricant consumption"
               value="<?= $lub_esc($q) ?>">
        <span class="lub-slash-kbd" aria-hidden="true">/</span>
      </div>

      <input type="month" name="month" class="form-control" style="width:142px;"
             aria-label="Filter month" value="<?= $lub_esc($month_str) ?>">

      <select name="equipment_id" class="form-select" style="width:155px;" aria-label="Filter by equipment">
        <option value="">All Fleet Assets</option>
        <?php foreach ($equipment as $eq): ?>
        <option value="<?= (int)$eq->id ?>" <?= (!empty($f['equipment_id']) && (int)$f['equipment_id'] === (int)$eq->id) ? 'selected' : '' ?>>
          <?= $lub_esc($eq->registration_no) ?>
        </option>
        <?php endforeach; ?>
      </select>

      <select name="lube_type_id" class="form-select" style="width:148px;" aria-label="Filter by lubricant">
        <option value="">All Lube Types</option>
        <?php foreach ($lube_types as $lt): ?>
        <option value="<?= (int)$lt->id ?>" <?= (!empty($f['lube_type_id']) && (int)$f['lube_type_id'] === (int)$lt->id) ? 'selected' : '' ?>>
          <?= $lub_esc($lt->name) ?>
        </option>
        <?php endforeach; ?>
      </select>

      <select name="per_page" class="form-select" style="width:92px;" aria-label="Rows per page" onchange="this.form.submit()">
        <?php foreach ($allowed_pp as $pp): ?>
        <option value="<?= (int)$pp ?>" <?= $per_page === (int)$pp ? 'selected' : '' ?>><?= (int)$pp ?> / pg</option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <a href="<?= base_url('lubricants') ?>" class="btn btn-ghost btn-sm">Reset</a>
    </form>

    <div class="lub-view-switch" role="group" aria-label="Ledger layout">
      <button type="button" class="lub-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>" data-lub-view="table">Table</button>
      <button type="button" class="lub-view-btn <?= $view_mode === 'groups' ? 'is-active' : '' ?>" data-lub-view="groups">By Machine</button>
      <button type="button" class="lub-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>" data-lub-view="cards">Cards</button>
    </div>
  </div>

  <!-- Sticky Multi-Select Batch Action Bar -->
  <div class="lub-batch-bar" id="lubBatchBar" role="region" aria-label="Selected consumption actions">
    <div style="font-size:12.5px;font-weight:600;color:var(--t1);">
      <span id="lubSelectedCount">0</span> record(s) selected
    </div>
    <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
      <a href="<?= $lub_esc(base_url('lubricants/export_csv')) ?>" id="lubBatchExportBtn" class="btn btn-ghost btn-sm">Export Selected CSV</a>
      <a href="<?= $lub_esc(base_url('lubricants/export_xlsx')) ?>" id="lubBatchXlsxBtn" class="btn btn-ghost btn-sm">Export Selected Excel</a>
      <button type="button" id="lubBatchClearBtn" class="btn btn-ghost btn-sm">Clear</button>
    </div>
  </div>

  <!-- Main Ledger Card -->
  <div class="card">
    <!-- 1. Zero-Overflow Desktop DataTable -->
    <div class="lub-table-wrap">
      <table class="lub-table">
        <thead>
          <tr>
            <th class="lub-c-chk">
              <input type="checkbox" id="lubSelectAll" aria-label="Select all visible lubricant records">
            </th>
            <th class="lub-c-date">
              <a href="<?= $lub_esc($lub_sort_url('date')) ?>">Date <?= $lub_sort_icon('date') ?></a>
            </th>
            <th class="lub-c-asset">
              <a href="<?= $lub_esc($lub_sort_url('equipment')) ?>">Fleet Asset <?= $lub_sort_icon('equipment') ?></a>
            </th>
            <th class="lub-c-lube">
              <a href="<?= $lub_esc($lub_sort_url('lube')) ?>">Fluid &amp; Grade <?= $lub_sort_icon('lube') ?></a>
            </th>
            <th class="lub-c-qty">
              <a href="<?= $lub_esc($lub_sort_url('qty')) ?>">Dispensed <?= $lub_sort_icon('qty') ?></a>
            </th>
            <th class="lub-c-std">OEM Std</th>
            <th class="lub-c-var">
              <a href="<?= $lub_esc($lub_sort_url('variance')) ?>">Variance <?= $lub_sort_icon('variance') ?></a>
            </th>
            <th class="lub-c-cost">
              <a href="<?= $lub_esc($lub_sort_url('cost')) ?>">Cost (<?= $lub_esc($ccy) ?>) <?= $lub_sort_icon('cost') ?></a>
            </th>
            <th class="lub-c-wo">WO / Staff</th>
            <th class="lub-c-act">Inspect</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($consumption)): ?>
          <tr>
            <td colspan="10" style="text-align:center;padding:48px 16px;color:var(--t3);">
              No lubricant issues recorded for <?= $lub_esc($month_name) ?> matching current filters.
            </td>
          </tr>
        <?php else: foreach ($consumption as $c):
          $varVal = $c->variance !== NULL ? (float)$c->variance : NULL;
          $varCls = $varVal === NULL ? 'lub-b-info' : ($varVal > 0.0001 ? 'lub-b-over' : ($varVal < -0.0001 ? 'lub-b-warn' : 'lub-b-ok'));
          $varTxt = $varVal === NULL ? 'Top-Up' : (($varVal > 0 ? '+' : '') . number_format($varVal, 1) . ' ' . $c->unit);
        ?>
          <tr data-lub-row="<?= (int)$c->id ?>">
            <td class="lub-c-chk">
              <input type="checkbox" class="lub-row-check" value="<?= (int)$c->id ?>"
                     aria-label="Select issue #<?= (int)$c->id ?>">
            </td>
            <td class="lub-c-date mono" style="font-size:11.5px;color:var(--t2);">
              <?= !empty($c->consumption_date) ? date('d M Y', strtotime($c->consumption_date)) : '—' ?>
            </td>
            <td class="lub-c-asset">
              <button type="button" class="lub-open-btn mono" data-lub-open="<?= (int)$c->id ?>">
                <?= $lub_esc($c->registration_no) ?>
              </button>
              <div class="lub-sub">
                <?= $c->equipment_hours !== NULL ? (number_format((float)$c->equipment_hours, 0) . ' hrs') : $lub_esc(trim(($c->brand ?? '') . ' ' . ($c->model ?? '')) ?: '—') ?>
              </div>
            </td>
            <td class="lub-c-lube">
              <div style="font-weight:600;color:var(--t1);font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= $lub_esc($c->lube_name) ?>
              </div>
              <div class="lub-sub"><?= $lub_esc($c->category) ?><?= !empty($c->lube_grade) ? ' · ' . $lub_esc($c->lube_grade) : '' ?></div>
            </td>
            <td class="lub-c-qty mono" style="font-weight:700;color:var(--t1);">
              <?= number_format((float)$c->quantity_used, 1) ?> <?= $lub_esc($c->unit) ?>
            </td>
            <td class="lub-c-std mono" style="font-size:11.5px;color:var(--t3);">
              <?= $c->recommended_qty !== NULL ? (number_format((float)$c->recommended_qty, 1) . ' ' . $lub_esc($c->unit)) : '—' ?>
            </td>
            <td class="lub-c-var">
              <span class="lub-badge <?= $varCls ?>"><?= $lub_esc($varTxt) ?></span>
            </td>
            <td class="lub-c-cost mono" style="font-size:12px;color:var(--t1);">
              <?= number_format((float)($c->line_cost ?? 0), 2) ?>
            </td>
            <td class="lub-c-wo">
              <?php if (!empty($c->wo_number)): ?>
              <a href="<?= base_url('workorders/view/' . (int)$c->work_order_id) ?>" class="mono" style="font-size:11.5px;color:var(--blue);font-weight:600;">
                <?= $lub_esc($c->wo_number) ?>
              </a>
              <?php else: ?>
              <span style="font-size:11.5px;color:var(--t3);">—</span>
              <?php endif; ?>
              <div class="lub-sub"><?= $lub_esc(trim((string)($c->recorded_by_name ?: 'System'))) ?></div>
            </td>
            <td class="lub-c-act">
              <button type="button" class="btn btn-ghost btn-sm" data-lub-open="<?= (int)$c->id ?>">Inspect</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- 2. By Machine Grouped View -->
    <div class="lub-groups-container" style="padding:14px;">
      <?php if (empty($groups)): ?>
      <div style="text-align:center;padding:36px;color:var(--t3);">No machine consumption groups in this period.</div>
      <?php else: foreach ($groups as $grp): ?>
      <div class="lub-group-card">
        <div class="lub-group-hdr">
          <div style="font-weight:700;color:var(--t1);font-size:13.5px;" class="mono">
            <?= $lub_esc($grp->registration_no) ?>
            <?php if (!empty($grp->model_label)): ?>
            <span style="font-family:sans-serif;font-weight:400;color:var(--t3);font-size:12px;">— <?= $lub_esc($grp->model_label) ?></span>
            <?php endif; ?>
          </div>
          <div style="font-size:11.5px;color:var(--t3);" class="mono">
            <?= count($grp->records) ?> issue(s) &middot; <?= number_format((float)$grp->total_qty, 1) ?> units &middot; <?= $lub_esc($ccy) ?> <?= number_format((float)$grp->total_cost, 2) ?>
          </div>
        </div>
        <div class="lub-group-grid">
          <?php foreach ($grp->records as $gr): ?>
          <div class="lub-group-item">
            <div style="min-width:0;">
              <button type="button" class="lub-open-btn" data-lub-open="<?= (int)$gr->id ?>"><?= $lub_esc($gr->lube_name) ?></button>
              <div class="lub-sub"><?= date('d M Y', strtotime($gr->consumption_date)) ?><?= !empty($gr->wo_number) ? ' · ' . $lub_esc($gr->wo_number) : '' ?></div>
            </div>
            <div class="mono" style="font-weight:700;color:var(--t1);flex-shrink:0;">
              <?= number_format((float)$gr->quantity_used, 1) ?> <?= $lub_esc($gr->unit) ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- 3. Responsive Mobile / Desktop Cards View -->
    <div class="lub-cards-container" style="padding:14px;">
      <?php if (empty($consumption)): ?>
      <div style="text-align:center;padding:36px;color:var(--t3);">No consumption records found.</div>
      <?php else: ?>
      <div class="lub-cards-grid">
        <?php foreach ($consumption as $cc):
          $cVar = $cc->variance !== NULL ? (float)$cc->variance : NULL;
          $cCls = $cVar === NULL ? 'lub-b-info' : ($cVar > 0.0001 ? 'lub-b-over' : 'lub-b-ok');
          $cTxt = $cVar === NULL ? 'Top-Up' : (($cVar > 0 ? '+' : '') . number_format($cVar, 1) . ' ' . $cc->unit);
        ?>
        <div class="lub-card">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
            <div style="min-width:0;">
              <button type="button" class="lub-open-btn mono" data-lub-open="<?= (int)$cc->id ?>"><?= $lub_esc($cc->registration_no) ?></button>
              <div class="lub-sub"><?= $lub_esc($cc->lube_name) ?> &middot; <?= date('d M Y', strtotime($cc->consumption_date)) ?></div>
            </div>
            <span class="lub-badge <?= $cCls ?>"><?= $lub_esc($cTxt) ?></span>
          </div>
          <div class="lub-card-metrics">
            <div>
              <div style="color:var(--t3);font-size:10px;">Dispensed</div>
              <div class="mono" style="font-weight:700;color:var(--t1);"><?= number_format((float)$cc->quantity_used, 1) ?> <?= $lub_esc($cc->unit) ?></div>
            </div>
            <div>
              <div style="color:var(--t3);font-size:10px;">OEM Std</div>
              <div class="mono" style="font-weight:700;color:var(--t1);"><?= $cc->recommended_qty !== NULL ? number_format((float)$cc->recommended_qty, 1) : '—' ?></div>
            </div>
            <div>
              <div style="color:var(--t3);font-size:10px;">Cost (<?= $lub_esc($ccy) ?>)</div>
              <div class="mono" style="font-weight:700;color:var(--t1);"><?= number_format((float)($cc->line_cost ?? 0), 0) ?></div>
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:auto;">
            <span class="lub-sub" style="margin:0;"><?= !empty($cc->wo_number) ? $lub_esc($cc->wo_number) : $lub_esc(trim((string)($cc->recorded_by_name ?: 'System'))) ?></span>
            <button type="button" class="btn btn-ghost btn-sm" data-lub-open="<?= (int)$cc->id ?>">Inspect</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Pagination Bar -->
    <div class="lub-pager">
      <div>
        <?php if ($tot_filt === 0): ?>
          Showing 0 records
        <?php else: ?>
          Showing <?= (int)($offset_val + 1) ?>–<?= (int)min($tot_filt, $offset_val + count($consumption)) ?> of <?= (int)$tot_filt ?> issue(s)
        <?php endif; ?>
      </div>
      <?php if ($tot_pages > 1): ?>
      <div style="display:flex;gap:6px;align-items:center;">
        <?php if ($cur_page > 1): ?>
        <a href="<?= $lub_esc($lub_url('lubricants', array('page' => $cur_page - 1))) ?>" class="btn btn-ghost btn-sm">&larr; Prev</a>
        <?php endif; ?>
        <span>Page <?= (int)$cur_page ?> / <?= (int)$tot_pages ?></span>
        <?php if ($cur_page < $tot_pages): ?>
        <a href="<?= $lub_esc($lub_url('lubricants', array('page' => $cur_page + 1))) ?>" class="btn btn-ghost btn-sm">Next &rarr;</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Accessible Slide-Over Fluid Consumption Dossier Drawer -->
<div class="lub-drawer-backdrop" id="lubDrawerBackdrop"></div>
<aside class="lub-drawer" id="lubDrawer" role="dialog" aria-modal="true" aria-labelledby="lubDrawerTitle" aria-hidden="true">
  <div class="lub-drawer-hdr">
    <div style="min-width:0;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--t3);">Fluid Issue Dossier</div>
      <h2 id="lubDrawerTitle" style="margin:3px 0 0;font-size:16px;font-weight:700;color:var(--t1);word-break:break-word;">Consumption Record</h2>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" id="lubDrawerClose" aria-label="Close consumption inspector">&times;</button>
  </div>
  <div class="lub-drawer-body" id="lubDrawerBody">
    <div style="color:var(--t3);font-size:12.5px;">Select a fluid issue record to inspect OEM variance, meter reading, and Work Order traceability.</div>
  </div>
  <div class="lub-drawer-ftr">
    <div style="display:flex;gap:6px;">
      <button type="button" class="btn btn-ghost btn-sm" id="lubPrevBtn" title="Previous record (ArrowLeft)">&larr; Prev</button>
      <button type="button" class="btn btn-ghost btn-sm" id="lubNextBtn" title="Next record (ArrowRight)">Next &rarr;</button>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;" id="lubDrawerCtas"></div>
  </div>
</aside>

<script>
(function () {
  var shell = document.getElementById('lubShell');
  if (!shell) return;

  var summaryBase    = <?= json_encode(rtrim(base_url('lubricants/summary_json'), '/') . '/') ?>;
  var woViewBase     = <?= json_encode(rtrim(base_url('workorders/view'), '/') . '/') ?>;
  var eqViewBase     = <?= json_encode(rtrim(base_url('equipment/view'), '/') . '/') ?>;
  var exportCsvBase  = <?= json_encode(base_url('lubricants/export_csv')) ?>;
  var exportXlsxBase = <?= json_encode(base_url('lubricants/export_xlsx')) ?>;
  var currentMonth   = <?= json_encode($month_str) ?>;
<?php if ($can_write): ?>
  var recordUrl      = <?= json_encode(base_url('lubricants/record')) ?>;
<?php endif; ?>

  function esc(str) {
    var d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
  }

  // 3-Way View Switcher
  var viewInput = document.getElementById('lubViewInput');
  Array.prototype.forEach.call(document.querySelectorAll('[data-lub-view]'), function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.getAttribute('data-lub-view') || 'table';
      shell.setAttribute('data-view', mode);
      if (viewInput) viewInput.value = mode;
      Array.prototype.forEach.call(document.querySelectorAll('[data-lub-view]'), function (b) {
        b.classList.toggle('is-active', b === btn);
      });
    });
  });

  // Multi-select checkboxes + Batch CSV / Excel export bar
  var selectAll = document.getElementById('lubSelectAll');
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.lub-row-check'));
  var batchBar  = document.getElementById('lubBatchBar');
  var cntEl     = document.getElementById('lubSelectedCount');
  var batchCsv  = document.getElementById('lubBatchExportBtn');
  var batchXlsx = document.getElementById('lubBatchXlsxBtn');
  var clearBtn  = document.getElementById('lubBatchClearBtn');

  function syncSelection() {
    var ids = rowChecks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    if (cntEl) cntEl.textContent = String(ids.length);
    if (batchBar) batchBar.classList.toggle('is-open', ids.length > 0);
    var qs = ids.length ? ('?month=' + encodeURIComponent(currentMonth) + '&ids=' + encodeURIComponent(ids.join(','))) : '';
    if (batchCsv) batchCsv.setAttribute('href', exportCsvBase + qs);
    if (batchXlsx) batchXlsx.setAttribute('href', exportXlsxBase + qs);
    if (selectAll && rowChecks.length) selectAll.checked = ids.length === rowChecks.length;
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

  // Slide-Over Fluid Consumption Dossier Drawer
  var drawer    = document.getElementById('lubDrawer');
  var backdrop  = document.getElementById('lubDrawerBackdrop');
  var titleEl   = document.getElementById('lubDrawerTitle');
  var bodyEl    = document.getElementById('lubDrawerBody');
  var ctasEl    = document.getElementById('lubDrawerCtas');
  var closeBtn  = document.getElementById('lubDrawerClose');
  var prevBtn   = document.getElementById('lubPrevBtn');
  var nextBtn   = document.getElementById('lubNextBtn');
  var lastFocus = null;
  var currentId = null;

  function orderedIds() {
    var seen = {};
    var out = [];
    Array.prototype.forEach.call(document.querySelectorAll('button[data-lub-open]'), function (b) {
      var id = b.getAttribute('data-lub-open');
      if (id && !seen[id]) { seen[id] = true; out.push(id); }
    });
    return out;
  }

  function closeDrawer() {
    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
  }

  function openDrawer(id, triggerEl) {
    if (triggerEl) lastFocus = triggerEl;
    currentId = String(id);
    drawer.classList.add('is-open');
    backdrop.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    bodyEl.innerHTML = '<div style="padding:18px 0;color:var(--t3);font-size:12.5px;">Loading fluid issue dossier…</div>';

    fetch(summaryBase + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok || !res.record) {
          bodyEl.innerHTML = '<div style="color:var(--red);">Unable to load consumption record.</div>';
          return;
        }
        var rec = res.record;
        titleEl.textContent = rec.registration_no + ' · ' + rec.lube_name + ' (#' + rec.id + ')';

        var varLabel = rec.variance !== null ? ((rec.variance > 0 ? '+' : '') + rec.variance.toFixed(2) + ' ' + rec.unit) : 'No OEM baseline';
        bodyEl.innerHTML =
          '<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:6px;">OEM Specification &amp; Variance</div>'
          + '<div class="lub-kv-grid">'
          + '<div class="lub-kv"><div class="lub-kv-k">Fleet Asset</div><div class="lub-kv-v">' + esc(rec.registration_no) + (rec.machine_meta ? (' · ' + esc(rec.machine_meta)) : '') + '</div></div>'
          + '<div class="lub-kv"><div class="lub-kv-k">Issue Date &amp; SMR</div><div class="lub-kv-v">' + esc(rec.consumption_date) + (rec.equipment_hours !== null ? (' · ' + rec.equipment_hours + ' hrs') : '') + '</div></div>'
          + '<div class="lub-kv"><div class="lub-kv-k">Fluid &amp; Grade</div><div class="lub-kv-v">' + esc(rec.lube_name) + ' (' + esc(rec.lube_grade) + ')</div></div>'
          + '<div class="lub-kv"><div class="lub-kv-k">Dispensed vs OEM Std</div><div class="lub-kv-v">' + rec.quantity_used.toFixed(2) + ' ' + esc(rec.unit) + ' / ' + (rec.recommended_qty !== null ? (rec.recommended_qty.toFixed(2) + ' ' + esc(rec.unit)) : '—') + '</div></div>'
          + '<div class="lub-kv"><div class="lub-kv-k">Variance</div><div class="lub-kv-v">' + esc(varLabel) + '</div></div>'
          + '<div class="lub-kv"><div class="lub-kv-k">Line Cost</div><div class="lub-kv-v">' + esc(rec.currency) + ' ' + rec.line_cost.toFixed(2) + '</div></div>'
          + '<div class="lub-kv" style="grid-column:1 / -1;"><div class="lub-kv-k">Variance Reason</div><div class="lub-kv-v">' + esc(rec.variance_reason) + '</div></div>'
          + '<div class="lub-kv" style="grid-column:1 / -1;"><div class="lub-kv-k">Work Order &amp; Recorded By</div><div class="lub-kv-v">'
          + (rec.wo_number ? ('<a href="' + woViewBase + encodeURIComponent(rec.work_order_id) + '" style="color:var(--blue);">' + esc(rec.wo_number) + '</a> · ') : '')
          + esc(rec.recorded_by_name) + '</div></div>'
          + '</div>';

        var ctas = '<a href="' + eqViewBase + encodeURIComponent(rec.equipment_id) + '" class="btn btn-ghost btn-sm">Machine History</a>';
<?php if ($can_write): ?>
        ctas = '<a href="' + recordUrl + '" class="btn btn-primary btn-sm">+ Record Consumption</a>' + ctas;
<?php endif; ?>
        ctasEl.innerHTML = ctas;
      })
      .catch(function () {
        bodyEl.innerHTML = '<div style="color:var(--red);">Network error loading consumption dossier.</div>';
      });
  }

  Array.prototype.forEach.call(document.querySelectorAll('button[data-lub-open]'), function (btn) {
    btn.addEventListener('click', function () {
      openDrawer(btn.getAttribute('data-lub-open'), btn);
    });
  });

  function stepDrawer(delta) {
    var list = orderedIds();
    if (!list.length || !currentId) return;
    var idx = list.indexOf(currentId);
    openDrawer(list[(idx + delta + list.length) % list.length], null);
  }

  if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
  if (backdrop) backdrop.addEventListener('click', closeDrawer);
  if (prevBtn) prevBtn.addEventListener('click', function () { stepDrawer(-1); });
  if (nextBtn) nextBtn.addEventListener('click', function () { stepDrawer(1); });

  document.addEventListener('keydown', function (ev) {
    var tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
    var inField = (tag === 'input' || tag === 'textarea' || tag === 'select' || (ev.target && ev.target.isContentEditable));
    if (ev.key === '/' && !inField && !drawer.classList.contains('is-open')) {
      var sInput = document.getElementById('lubSearchInput');
      if (sInput) { ev.preventDefault(); sInput.focus(); }
      return;
    }
    if (!drawer.classList.contains('is-open')) return;
    if (ev.key === 'Escape') { ev.preventDefault(); closeDrawer(); }
    else if (ev.key === 'ArrowRight') { ev.preventDefault(); stepDrawer(1); }
    else if (ev.key === 'ArrowLeft') { ev.preventDefault(); stepDrawer(-1); }
  });
})();
</script>
