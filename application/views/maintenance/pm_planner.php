<?php
/**
 * Preventive Maintenance Planner — Apple-HIG Enterprise Data Table + Dual Card View + Slide-Over Inspector Drawer.
 * Preserves verbatim Asset Register `registration_no` and integrates date + meter compliance, F4 standard hours,
 * BOM spare-part readiness (`pm_part_requirements` vs `materials.final_qty`), and completion history.
 */
$pm_h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$base_params = array();
if (!empty($tab) && $tab !== 'active') $base_params['tab'] = $tab;
if (!empty($q))          $base_params['q']             = $q;
if (!empty($f_equip))    $base_params['equipment_id']  = (int)$f_equip;
if (!empty($f_interval)) $base_params['interval_type'] = $f_interval;
if (!empty($f_category)) $base_params['category']      = $f_category;
if (!empty($f_location)) $base_params['location']      = $f_location;
if (!empty($view_mode) && $view_mode !== 'table') $base_params['view'] = $view_mode;
if (!empty($sort) && !($sort === 'urgency' && $dir === 'asc')) {
    $base_params['sort'] = $sort;
    $base_params['dir']  = $dir;
}
if (!empty($per_page) && (int)$per_page !== 25) $base_params['per_page'] = (int)$per_page;

$pm_url = function (array $override = array(), array $remove = array()) use ($base_params) {
    $p = array_merge($base_params, $override);
    foreach ($remove as $rk) unset($p[$rk]);
    foreach ($p as $pk => $pv) {
        if ($pv === '' || $pv === NULL || ($pk === 'tab' && $pv === 'active') || ($pk === 'view' && $pv === 'table')) {
            unset($p[$pk]);
        }
    }
    $qs = http_build_query($p);
    return base_url('pm') . ($qs !== '' ? '?' . $qs : '');
};

$sort_link = function ($col, $default_dir = 'asc') use ($sort, $dir, $pm_url) {
    $next = ($sort === $col) ? ($dir === 'asc' ? 'desc' : 'asc') : $default_dir;
    return $pm_url(array('sort' => $col, 'dir' => $next), array('page'));
};
$aria_sort = function ($col) use ($sort, $dir) {
    if ($sort !== $col) return 'none';
    return $dir === 'asc' ? 'ascending' : 'descending';
};
$sort_glyph = function ($col) use ($sort, $dir) {
    if ($sort !== $col) return '<span class="pm-sort-ind" aria-hidden="true">⇅</span>';
    return $dir === 'asc'
        ? '<span class="pm-sort-ind is-on" aria-hidden="true">↑</span>'
        : '<span class="pm-sort-ind is-on" aria-hidden="true">↓</span>';
};

$csv_qs  = http_build_query($base_params);
$csv_url = base_url('pm/export_csv') . ($csv_qs !== '' ? '?' . $csv_qs : '');

$has_active_filters = (!empty($q) || !empty($f_equip) || !empty($f_interval) || !empty($f_category) || !empty($f_location) || (!empty($tab) && $tab !== 'active'));

$eq_label_map = array();
if (isset($equipment)) {
    foreach ($equipment as $eq_opt) $eq_label_map[(int)$eq_opt->id] = (string)$eq_opt->registration_no;
}

$show_from = $total_filtered > 0 ? ($offset + 1) : 0;
$show_to   = min($offset + $per_page, $total_filtered);
?>
<style>
  .pm-wrap{display:flex;flex-direction:column;gap:16px}
  .pm-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}
  .pm-title-grp{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
  .pm-title{font-size:21px;font-weight:700;letter-spacing:-.02em;color:var(--t1);margin:0}
  .pm-sub{font-size:12.5px;color:var(--t3);font-weight:500}
  .pm-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .pm-view-seg{display:inline-flex;background:var(--g1);border:1px solid var(--line2);border-radius:var(--r-sm);padding:2px}
  .pm-view-seg a{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;font-size:12px;font-weight:600;color:var(--t2);border-radius:6px;text-decoration:none}
  .pm-view-seg a.active{background:var(--surface);color:var(--blue);box-shadow:0 1px 2px rgba(15,23,42,.08)}

  /* 5-Card Executive PM KPI Strip */
  .pm-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}
  @media(max-width:1199px){.pm-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
  @media(max-width:767px){.pm-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
  .pm-kpi{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:14px 16px;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:4px;box-shadow:var(--sh-sm);transition:border-color .15s,transform .15s}
  .pm-kpi:hover{border-color:var(--blue);transform:translateY(-1px);color:inherit}
  .pm-kpi.is-active{border-color:var(--blue);box-shadow:0 0 0 2px rgba(37,99,235,.12)}
  .pm-kpi-lbl{font-size:11px;font-weight:600;letter-spacing:.045em;text-transform:uppercase;color:var(--t3)}
  .pm-kpi-val{font-size:24px;font-weight:700;letter-spacing:-.02em;color:var(--t1);font-variant-numeric:tabular-nums;line-height:1.15}
  .pm-kpi-meta{font-size:11.5px;color:var(--t2);display:flex;align-items:center;gap:6px;flex-wrap:wrap}

  /* Segmented Compliance & Operational Tabs */
  .pm-tabs-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
  .pm-tabs{display:inline-flex;align-items:center;gap:3px;background:var(--g1);padding:4px;border-radius:10px;border:1px solid var(--line);overflow-x:auto;max-width:100%}
  .pm-tab{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border-radius:7px;font-size:12.5px;font-weight:600;color:var(--t2);text-decoration:none;white-space:nowrap}
  .pm-tab:hover{color:var(--t1);background:rgba(255,255,255,.55)}
  .pm-tab.active{background:var(--surface);color:var(--t1);box-shadow:0 1px 3px rgba(15,23,42,.1)}
  .pm-tab-cnt{font-size:11px;font-weight:700;padding:1px 6px;border-radius:999px;background:var(--g2);color:var(--t2);font-variant-numeric:tabular-nums}
  .pm-tab.active .pm-tab-cnt{background:var(--blue-d);color:var(--blue)}
  .pm-tab-cnt.is-crit{background:var(--red-d);color:var(--red)}
  .pm-tab-cnt.is-warn{background:var(--amber-d);color:var(--amber)}

  /* Multi-Facet Filter Toolbar */
  .pm-filter-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:12px 14px;box-shadow:var(--sh-sm);display:flex;flex-direction:column;gap:10px}
  .pm-filter-row{display:grid;grid-template-columns:minmax(220px,1.9fr) repeat(4,minmax(130px,1fr)) auto;gap:8px;align-items:center}
  @media(max-width:1280px){.pm-filter-row{grid-template-columns:repeat(3,minmax(0,1fr))}}
  @media(max-width:767px){.pm-filter-row{grid-template-columns:1fr}}
  .pm-search-box{position:relative;display:flex;align-items:center}
  .pm-search-box svg{position:absolute;left:10px;width:15px;height:15px;color:var(--t3);pointer-events:none}
  .pm-search-box input{padding-left:32px;padding-right:28px;font-size:12.5px;height:36px}
  .pm-kbd{position:absolute;right:8px;font-size:10px;font-family:var(--mono);color:var(--t3);border:1px solid var(--line2);border-radius:4px;padding:1px 5px;pointer-events:none}
  .pm-filter-row select{height:36px;font-size:12.5px}
  .pm-chips{display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding-top:4px;border-top:1px dashed var(--line)}
  .pm-chip{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:3px 9px;border-radius:999px;background:var(--blue-d);color:var(--blue);text-decoration:none}

  /* Enterprise PM DataTable */
  .pm-table-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh-sm);overflow:hidden}
  .pm-table-wrap{overflow-x:auto}
  table.pm-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px}
  .pm-table thead th{position:sticky;top:0;z-index:2;background:var(--g1);border-bottom:1px solid var(--line2);padding:9px 8px;font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--t2);white-space:nowrap;vertical-align:middle}
  .pm-table thead th a{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:3px}
  .pm-table thead th a:hover{color:var(--blue)}
  .pm-sort-ind{font-size:10.5px;color:var(--t3);opacity:.65}
  .pm-sort-ind.is-on{color:var(--blue);opacity:1;font-weight:800}
  .pm-table tbody td{padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:middle;color:var(--t1)}
  .pm-table tbody tr:last-child td{border-bottom:none}
  .pm-table tbody tr{transition:background .12s;cursor:pointer}
  .pm-table tbody tr:hover{background:var(--g1)}
  .pm-table tbody tr.is-overdue{box-shadow:inset 3px 0 0 var(--red)}
  .pm-table tbody tr.is-soon{box-shadow:inset 3px 0 0 var(--amber)}
  .pm-table tbody tr.is-selected{background:var(--blue-d)}
  .pm-machine-id{font-family:var(--mono);font-size:13px;font-weight:700;color:var(--blue);text-decoration:none;white-space:nowrap}
  .pm-machine-id:hover{text-decoration:underline}
  .pm-meta-sub{font-size:11px;color:var(--t3);margin-top:2px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
  .pm-pill{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:2px 7px;border-radius:6px;text-decoration:none;white-space:nowrap;font-variant-numeric:tabular-nums}
  .pm-pill-ok{background:var(--green-d);color:var(--green)}
  .pm-pill-short{background:var(--red-d);color:var(--red)}
  .pm-pill-muted{background:var(--g2);color:var(--t2)}
  .pm-row-acts{display:inline-flex;align-items:center;gap:4px}
  .pm-act-btn{display:inline-flex;align-items:center;justify-content:center;height:28px;padding:0 8px;border-radius:6px;border:1px solid var(--line2);background:var(--surface);color:var(--t2);font-size:11.5px;font-weight:600;text-decoration:none;cursor:pointer}
  .pm-act-btn:hover{border-color:var(--blue);color:var(--blue)}

  /* Dual Card Grid View & Mobile Auto-Fallback */
  .pm-cards-container{display:none}
  .pm-wrap[data-view="cards"] .pm-table-only{display:none}
  .pm-wrap[data-view="cards"] .pm-cards-container{display:block}
  @media(max-width:767px){
    .pm-table-only{display:none !important}
    .pm-cards-container{display:block !important}
  }
  .pm-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(295px,100%),1fr));gap:14px}
  .pm-card-item{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px;box-shadow:var(--sh-sm);display:flex;flex-direction:column;gap:10px;cursor:pointer;transition:border-color .15s,transform .15s}
  .pm-card-item:hover{border-color:var(--blue);transform:translateY(-2px)}

  /* Batch Bar, Pagination & Drawer */
  .pm-batch-bar{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:1040;background:#0f172a;color:#f8fafc;padding:9px 16px;border-radius:999px;box-shadow:0 12px 30px rgba(15,23,42,.3);display:none;align-items:center;gap:12px;font-size:12.5px}
  .pm-batch-bar.is-open{display:inline-flex}
  .pm-batch-bar .btn-batch{background:#2563eb;color:#fff;border:none;border-radius:999px;padding:5px 12px;font-size:12px;font-weight:600;text-decoration:none}
  .pm-batch-bar .btn-batch-clear{background:transparent;color:#cbd5e1;border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:4px 10px;font-size:11.5px;cursor:pointer}
  .pm-foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:11px 14px;border-top:1px solid var(--line);background:var(--surface);font-size:12.5px;color:var(--t2)}
  .pm-pages{display:inline-flex;align-items:center;gap:4px;flex-wrap:wrap}
  .pm-page-link{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;padding:0 8px;border-radius:6px;border:1px solid var(--line);background:var(--surface);color:var(--t1);font-size:12px;font-weight:600;text-decoration:none}
  .pm-page-link.active{background:var(--blue);border-color:var(--blue);color:#fff}
  .pm-page-link.is-disabled{opacity:.42;pointer-events:none}

  .pm-drawer-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.42);z-index:1050;opacity:0;pointer-events:none;transition:opacity .2s}
  .pm-drawer-backdrop.is-open{opacity:1;pointer-events:auto}
  .pm-drawer{position:fixed;top:0;right:0;bottom:0;width:min(500px,100vw);background:var(--surface);border-left:1px solid var(--line);z-index:1055;transform:translateX(100%);transition:transform .22s cubic-bezier(.16,1,.3,1);display:flex;flex-direction:column;box-shadow:-12px 0 36px rgba(15,23,42,.18)}
  .pm-drawer.is-open{transform:translateX(0)}
  .pm-drawer-head{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
  .pm-drawer-body{padding:16px 18px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:16px}
  .pm-drawer-foot{padding:12px 18px;border-top:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;background:var(--g1)}
  .pm-dl-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 14px;font-size:12.5px}
  .pm-dl-item dt{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--t3);margin:0}
  .pm-dl-item dd{margin:2px 0 0;font-weight:600;color:var(--t1);word-break:break-word}
  .pm-mini-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
  .pm-mini-kpi{background:var(--g1);border:1px solid var(--line);border-radius:8px;padding:8px 10px}
  .pm-mini-kpi span{display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--t3)}
  .pm-mini-kpi strong{font-size:13px;font-weight:700;color:var(--t1);font-variant-numeric:tabular-nums}

  @media print {
    .wms-sidebar, .wms-topbar, .pm-actions, .pm-tabs-bar, .pm-filter-card, .pm-batch-bar,
    .pm-row-acts, .pm-col-check, .pm-col-act, .pm-foot-controls, .pm-drawer, .pm-drawer-backdrop, #doneModal { display:none !important; }
    .pm-kpis { grid-template-columns:repeat(5,1fr) !important; }
    .pm-table-card, .pm-kpi { box-shadow:none !important; border:1px solid #cbd5e1 !important; }
    .pm-table th, .pm-table td { padding:6px 8px !important; font-size:10.5px !important; }
  }
</style>

<div class="pm-wrap" id="pmShell" data-view="<?= $pm_h($view_mode) ?>">
  <!-- 1. Header & Primary Toolbar -->
  <div class="pm-head">
    <div class="pm-title-grp">
      <h1 class="pm-title">PM Planner</h1>
      <span class="pm-sub"><?= number_format($total_filtered) ?> matching of <?= number_format($kpi['active_total']) ?> active schedules</span>
    </div>
    <div class="pm-actions">
      <div class="pm-view-seg" role="group" aria-label="View mode">
        <a href="<?= $pm_h($pm_url(array('view' => 'table'), array('page'))) ?>" data-pm-view="table" class="<?= $view_mode === 'table' ? 'active' : '' ?>">Table</a>
        <a href="<?= $pm_h($pm_url(array('view' => 'cards'), array('page'))) ?>" data-pm-view="cards" class="<?= $view_mode === 'cards' ? 'active' : '' ?>">Cards</a>
      </div>
      <a href="<?= base_url('pm/calendar') ?>" class="btn btn-outline-secondary btn-sm">12-Month Calendar</a>
      <a href="<?= $pm_h($csv_url) ?>" class="btn btn-outline-secondary btn-sm">Export CSV</a>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">Print</button>
      <?php if (!empty($can_manage_pm)): ?>
      <a href="<?= base_url('pm/create') ?>" class="btn btn-primary btn-sm">+ New PM Schedule</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- 2. Executive 5-Card PM Telemetry KPI Strip -->
  <div class="pm-kpis">
    <a href="<?= $pm_h($pm_url(array('tab' => 'active'), array('page'))) ?>" class="pm-kpi <?= $tab === 'active' ? 'is-active' : '' ?>">
      <span class="pm-kpi-lbl">Active PM Schedules</span>
      <span class="pm-kpi-val"><?= number_format($kpi['active_total']) ?></span>
      <span class="pm-kpi-meta"><?= number_format($kpi['inactive_total']) ?> inactive / paused</span>
    </a>
    <a href="<?= $pm_h($pm_url(array('tab' => 'overdue'), array('page'))) ?>" class="pm-kpi <?= $tab === 'overdue' ? 'is-active' : '' ?>">
      <span class="pm-kpi-lbl">Overdue Services</span>
      <span class="pm-kpi-val" style="color:<?= $kpi['overdue'] > 0 ? 'var(--red)' : 'var(--t1)' ?>;"><?= number_format($kpi['overdue']) ?></span>
      <span class="pm-kpi-meta">Date or meter target exceeded</span>
    </a>
    <a href="<?= $pm_h($pm_url(array('tab' => 'due_soon'), array('page'))) ?>" class="pm-kpi <?= $tab === 'due_soon' ? 'is-active' : '' ?>">
      <span class="pm-kpi-lbl">Due Soon Window</span>
      <span class="pm-kpi-val" style="color:<?= $kpi['due_soon'] > 0 ? 'var(--amber)' : 'var(--t1)' ?>;"><?= number_format($kpi['due_soon']) ?></span>
      <span class="pm-kpi-meta"><?= number_format($kpi['due_today']) ?> due today · &le;7d / &le;50h</span>
    </a>
    <a href="<?= $pm_h($pm_url(array('tab' => 'forecast_alert'), array('page'))) ?>" class="pm-kpi <?= $tab === 'forecast_alert' ? 'is-active' : '' ?>">
      <span class="pm-kpi-lbl">Planned PM Duration</span>
      <span class="pm-kpi-val" style="color:var(--blue);"><?= number_format($kpi['planned_std_hrs'], 1) ?>h</span>
      <span class="pm-kpi-meta"><?= number_format($kpi['missing_std_hrs']) ?> missing F4 std. hours</span>
    </a>
    <a href="<?= $pm_h($pm_url(array('tab' => 'parts_short'), array('page'))) ?>" class="pm-kpi <?= $tab === 'parts_short' ? 'is-active' : '' ?>">
      <span class="pm-kpi-lbl">BOM Parts Readiness</span>
      <span class="pm-kpi-val" style="color:<?= $kpi['parts_short'] > 0 ? 'var(--red)' : 'var(--green)' ?>;"><?= number_format($kpi['parts_ready']) ?></span>
      <span class="pm-kpi-meta"><?= number_format($kpi['parts_short']) ?> schedule(s) short in store</span>
    </a>
  </div>

  <!-- 3. Segmented Compliance & Governance Tabs -->
  <div class="pm-tabs-bar">
    <nav class="pm-tabs" aria-label="PM compliance filter tabs">
      <?php
      $tabs_def = array(
          'active'         => array('label' => 'All Active',     'tone' => ''),
          'overdue'        => array('label' => 'Overdue',        'tone' => $tab_counts['overdue'] > 0 ? 'is-crit' : ''),
          'due_soon'       => array('label' => 'Due Soon',       'tone' => $tab_counts['due_soon'] > 0 ? 'is-warn' : ''),
          'ok'             => array('label' => 'On Schedule',    'tone' => ''),
          'parts_short'    => array('label' => 'Parts Short',    'tone' => $tab_counts['parts_short'] > 0 ? 'is-crit' : ''),
          'forecast_alert' => array('label' => 'Forecast Alert', 'tone' => $tab_counts['forecast_alert'] > 0 ? 'is-warn' : ''),
          'inactive'       => array('label' => 'Inactive',       'tone' => ''),
      );
      foreach ($tabs_def as $tk => $tmeta):
      ?>
      <a href="<?= $pm_h($pm_url(array('tab' => $tk), array('page'))) ?>"
         class="pm-tab <?= $tab === $tk ? 'active' : '' ?>"
         aria-current="<?= $tab === $tk ? 'page' : 'false' ?>">
        <span><?= $pm_h($tmeta['label']) ?></span>
        <span class="pm-tab-cnt <?= $pm_h($tmeta['tone']) ?>"><?= number_format($tab_counts[$tk]) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <!-- 4. Multi-Facet Filter Bar & Active Filter Chips -->
  <div class="pm-filter-card">
    <form method="get" action="<?= base_url('pm') ?>" class="pm-filter-row" id="pmFilterForm">
      <?php if ($tab !== 'active'): ?><input type="hidden" name="tab" value="<?= $pm_h($tab) ?>"><?php endif; ?>
      <?php if ($view_mode !== 'table'): ?><input type="hidden" name="view" value="<?= $pm_h($view_mode) ?>"><?php endif; ?>
      <?php if (!($sort === 'urgency' && $dir === 'asc')): ?>
        <input type="hidden" name="sort" value="<?= $pm_h($sort) ?>">
        <input type="hidden" name="dir" value="<?= $pm_h($dir) ?>">
      <?php endif; ?>
      <?php if ((int)$per_page !== 25): ?><input type="hidden" name="per_page" value="<?= (int)$per_page ?>"><?php endif; ?>

      <div class="pm-search-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="search" name="q" id="pmSearchInput" class="form-control"
               placeholder="Search Machine ID, schedule, checklist, site..."
               value="<?= $pm_h($q) ?>" aria-label="Search PM schedules">
        <span class="pm-kbd">/</span>
      </div>

      <select name="equipment_id" class="form-select" aria-label="Filter by equipment" onchange="this.form.submit()">
        <option value="">All Equipment</option>
        <?php if (isset($equipment)) foreach ($equipment as $eq): ?>
        <option value="<?= (int)$eq->id ?>" <?= (int)$f_equip === (int)$eq->id ? 'selected' : '' ?>><?= $pm_h($eq->registration_no) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="interval_type" class="form-select" aria-label="Filter by interval type" onchange="this.form.submit()">
        <option value="">All Intervals</option>
        <?php foreach (array('Hours', 'Days', 'Months', 'Km') as $it): ?>
        <option value="<?= $pm_h($it) ?>" <?= $f_interval === $it ? 'selected' : '' ?>><?= $pm_h($it) ?>-Based</option>
        <?php endforeach; ?>
      </select>

      <select name="category" class="form-select" aria-label="Filter by asset category" onchange="this.form.submit()">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= $pm_h($cat) ?>" <?= strcasecmp($f_category, $cat) === 0 ? 'selected' : '' ?>><?= $pm_h($cat) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="location" class="form-select" aria-label="Filter by site or location" onchange="this.form.submit()">
        <option value="">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $pm_h($loc) ?>" <?= strcasecmp($f_location, $loc) === 0 ? 'selected' : '' ?>><?= $pm_h($loc) ?></option>
        <?php endforeach; ?>
      </select>

      <div class="d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm" style="height:36px;padding:0 14px;">Apply</button>
        <?php if ($has_active_filters): ?>
        <a href="<?= base_url('pm' . ($view_mode === 'cards' ? '?view=cards' : '')) ?>" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center" style="height:36px;">Reset</a>
        <?php endif; ?>
      </div>
    </form>

    <?php if ($has_active_filters): ?>
    <div class="pm-chips">
      <span style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;">Active Filters:</span>
      <?php if ($tab !== 'active'): ?>
        <a href="<?= $pm_h($pm_url(array(), array('tab', 'page'))) ?>" class="pm-chip">Tab: <?= $pm_h($tabs_def[$tab]['label']) ?> ×</a>
      <?php endif; ?>
      <?php if ($q !== ''): ?>
        <a href="<?= $pm_h($pm_url(array(), array('q', 'page'))) ?>" class="pm-chip">Query: "<?= $pm_h($q) ?>" ×</a>
      <?php endif; ?>
      <?php if ($f_equip > 0 && isset($eq_label_map[$f_equip])): ?>
        <a href="<?= $pm_h($pm_url(array(), array('equipment_id', 'page'))) ?>" class="pm-chip">Machine: <?= $pm_h($eq_label_map[$f_equip]) ?> ×</a>
      <?php endif; ?>
      <?php if ($f_interval !== ''): ?>
        <a href="<?= $pm_h($pm_url(array(), array('interval_type', 'page'))) ?>" class="pm-chip">Interval: <?= $pm_h($f_interval) ?> ×</a>
      <?php endif; ?>
      <?php if ($f_category !== ''): ?>
        <a href="<?= $pm_h($pm_url(array(), array('category', 'page'))) ?>" class="pm-chip">Category: <?= $pm_h($f_category) ?> ×</a>
      <?php endif; ?>
      <?php if ($f_location !== ''): ?>
        <a href="<?= $pm_h($pm_url(array(), array('location', 'page'))) ?>" class="pm-chip">Location: <?= $pm_h($f_location) ?> ×</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if (empty($pm_schedules)): ?>
  <!-- Empty State -->
  <div class="pm-table-card" style="padding:48px 20px;text-align:center;">
    <div style="font-size:15px;font-weight:700;color:var(--t1);margin-bottom:4px;">No PM schedules match your current view</div>
    <div style="font-size:13px;color:var(--t3);margin-bottom:14px;">Adjust active compliance tabs/filters or create a preventive maintenance schedule for a machine.</div>
    <div class="d-inline-flex gap-2">
      <?php if ($has_active_filters): ?>
      <a href="<?= base_url('pm') ?>" class="btn btn-outline-secondary btn-sm">Reset Filters</a>
      <?php endif; ?>
      <?php if (!empty($can_manage_pm)): ?>
      <a href="<?= base_url('pm/create') ?>" class="btn btn-primary btn-sm">+ Create PM Schedule</a>
      <?php endif; ?>
    </div>
  </div>

  <?php else: ?>
  <!-- 5B. Secondary Card Grid & Mobile Responsive View -->
  <div class="pm-cards-container">
    <div class="pm-cards-grid">
      <?php foreach ($pm_schedules as $pm): ?>
      <div class="pm-card-item" onclick="window.openPmDrawer(<?= (int)$pm->id ?>, event)">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div>
            <a href="<?= base_url('equipment/view/' . (int)$pm->equipment_id) ?>" class="pm-machine-id" onclick="event.stopPropagation()">
              <?= $pm_h($pm->registration_no ?: '—') ?>
            </a>
            <div style="font-size:13px;font-weight:700;color:var(--t1);margin-top:2px;"><?= $pm_h($pm->schedule_name) ?></div>
          </div>
          <span class="badge <?= $pm_h($pm->compliance_badge) ?>">
            <span class="led <?= $pm_h($pm->compliance_led) ?>" style="margin-right:4px;"></span><?= $pm_h($pm->compliance_label) ?>
          </span>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding-top:8px;border-top:1px solid var(--line);font-size:11.5px;">
          <div>
            <div style="color:var(--t3);font-size:10.5px;">Interval</div>
            <div style="font-family:var(--mono);font-weight:700;"><?= (int)$pm->interval_value . ' ' . $pm_h($pm->interval_type) ?></div>
          </div>
          <div>
            <div style="color:var(--t3);font-size:10.5px;">Next Due</div>
            <div style="font-family:var(--mono);font-weight:700;"><?= $pm->next_due_date ? date('d M Y', strtotime($pm->next_due_date)) : '—' ?></div>
          </div>
          <div>
            <div style="color:var(--t3);font-size:10.5px;">Std. Hours</div>
            <div style="font-family:var(--mono);font-weight:700;"><?= $pm->std_hours_val !== NULL ? number_format((float)$pm->std_hours_val, 1) . 'h' : '—' ?></div>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-center pt-1" onclick="event.stopPropagation()">
          <div>
            <?php if ((int)$pm->shortage_count > 0): ?>
              <a href="<?= base_url('forecast/parts/' . (int)$pm->id) ?>" class="pm-pill pm-pill-short"><?= (int)$pm->shortage_count ?> Short</a>
            <?php elseif ((int)$pm->parts_count > 0): ?>
              <a href="<?= base_url('forecast/parts/' . (int)$pm->id) ?>" class="pm-pill pm-pill-ok"><?= (int)$pm->parts_count ?> Parts Ready</a>
            <?php else: ?>
              <a href="<?= base_url('forecast/parts/' . (int)$pm->id) ?>" class="pm-pill pm-pill-muted">No BOM</a>
            <?php endif; ?>
          </div>
          <div class="pm-row-acts">
            <button type="button" class="pm-act-btn" onclick="window.openPmDrawer(<?= (int)$pm->id ?>, event)">Inspect</button>
            <?php if (!empty($can_complete_pm)): ?>
            <button type="button" class="pm-act-btn" onclick="markDone(<?= (int)$pm->id ?>, <?= (int)$pm->equipment_id ?>)">Done</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- 5A. Primary Enterprise PM DataTable -->
  <div class="pm-table-card">
    <div class="pm-table-wrap pm-table-only">
      <table class="pm-table" aria-label="Preventive Maintenance Schedules">
        <thead>
          <tr>
            <th class="pm-col-check" style="width:32px;">
              <input type="checkbox" id="pmCheckAll" class="form-check-input" aria-label="Select all schedules on this page">
            </th>
            <th aria-sort="<?= $aria_sort('machine') ?>">
              <a href="<?= $pm_h($sort_link('machine', 'asc')) ?>">Machine &amp; Site <?= $sort_glyph('machine') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('schedule') ?>">
              <a href="<?= $pm_h($sort_link('schedule', 'asc')) ?>">PM Schedule <?= $sort_glyph('schedule') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('interval') ?>">
              <a href="<?= $pm_h($sort_link('interval', 'asc')) ?>">Interval / Std <?= $sort_glyph('interval') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('last_done') ?>">
              <a href="<?= $pm_h($sort_link('last_done', 'desc')) ?>">Last Done <?= $sort_glyph('last_done') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('next_due') ?>">
              <a href="<?= $pm_h($sort_link('next_due', 'asc')) ?>">Next Due / Meter <?= $sort_glyph('next_due') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('parts') ?>">
              <a href="<?= $pm_h($sort_link('parts', 'desc')) ?>">BOM Parts <?= $sort_glyph('parts') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('urgency') ?>">
              <a href="<?= $pm_h($sort_link('urgency', 'asc')) ?>">Status <?= $sort_glyph('urgency') ?></a>
            </th>
            <th class="pm-col-act" style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pm_schedules as $pm):
              $row_cls = '';
              if ($pm->compliance_state === 'overdue') $row_cls = 'is-overdue';
              elseif ($pm->compliance_state === 'due_soon') $row_cls = 'is-soon';
          ?>
          <tr class="<?= $row_cls ?>" data-pm-id="<?= (int)$pm->id ?>" onclick="window.openPmDrawer(<?= (int)$pm->id ?>, event)">
            <td class="pm-col-check" onclick="event.stopPropagation()">
              <input type="checkbox" class="form-check-input pm-row-check" value="<?= (int)$pm->id ?>"
                     aria-label="Select schedule <?= $pm_h($pm->schedule_name) ?>">
            </td>
            <td style="max-width:155px;">
              <a href="<?= base_url('equipment/view/' . (int)$pm->equipment_id) ?>" class="pm-machine-id" onclick="event.stopPropagation()">
                <?= $pm_h($pm->registration_no ?: '—') ?>
              </a>
              <div class="pm-meta-sub" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;">
                <?= $pm_h($pm->type_name ?: 'Asset') ?><?= !empty($pm->location) ? ' · ' . $pm_h($pm->location) : '' ?>
              </div>
            </td>
            <td style="max-width:215px;">
              <div style="font-weight:600;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= $pm_h($pm->schedule_name) ?></div>
              <?php if (!empty($pm->description)): ?>
              <div class="pm-meta-sub" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;"><?= $pm_h($pm->description) ?></div>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <div style="font-family:var(--mono);font-weight:600;">Every <?= (int)$pm->interval_value ?> <?= $pm_h($pm->interval_type) ?></div>
              <div class="pm-meta-sub">
                <?php if ($pm->std_hours_val !== NULL): ?>
                  <span>Std: <?= number_format((float)$pm->std_hours_val, 1) ?>h</span>
                <?php else: ?>
                  <span style="color:var(--amber);">No F4 std hrs</span>
                <?php endif; ?>
              </div>
            </td>
            <td style="white-space:nowrap;">
              <div style="font-family:var(--mono);font-size:12px;"><?= $pm->last_done_date ? date('d M Y', strtotime($pm->last_done_date)) : '—' ?></div>
              <div class="pm-meta-sub">
                <?php if ($pm->last_done_hours !== NULL): ?>
                  <span style="font-family:var(--mono);">@ <?= number_format((float)$pm->last_done_hours, 0) ?><?= $pm_h($pm->meter_unit) ?></span>
                <?php endif; ?>
                <?php if ((int)$pm->completion_count > 0): ?>
                  <span><?= (int)$pm->completion_count ?>x done</span>
                <?php endif; ?>
              </div>
            </td>
            <td style="white-space:nowrap;">
              <div style="font-family:var(--mono);font-weight:600;color:<?= $pm->compliance_state === 'overdue' ? 'var(--red)' : ($pm->compliance_state === 'due_soon' ? 'var(--amber)' : 'var(--t1)') ?>;">
                <?= $pm->next_due_date ? date('d M Y', strtotime($pm->next_due_date)) : '—' ?>
              </div>
              <?php if ($pm->next_due_hours !== NULL): ?>
              <div class="pm-meta-sub" style="font-family:var(--mono);">
                Due <?= number_format((float)$pm->next_due_hours, 0) ?><?= $pm_h($pm->meter_unit) ?> · Cur <?= number_format((float)$pm->cur_meter, 0) ?><?= $pm_h($pm->meter_unit) ?>
              </div>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;" onclick="event.stopPropagation()">
              <?php if ((int)$pm->shortage_count > 0): ?>
                <a href="<?= base_url('forecast/parts/' . (int)$pm->id) ?>" class="pm-pill pm-pill-short"><?= (int)$pm->shortage_count ?> Short</a>
              <?php elseif ((int)$pm->parts_count > 0): ?>
                <a href="<?= base_url('forecast/parts/' . (int)$pm->id) ?>" class="pm-pill pm-pill-ok"><?= (int)$pm->parts_count ?> Ready</a>
              <?php else: ?>
                <a href="<?= base_url('forecast/parts/' . (int)$pm->id) ?>" class="pm-pill pm-pill-muted">Set BOM</a>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <span class="badge <?= $pm_h($pm->compliance_badge) ?>">
                <span class="led <?= $pm_h($pm->compliance_led) ?>" style="margin-right:4px;"></span><?= $pm_h($pm->compliance_label) ?>
              </span>
            </td>
            <td class="pm-col-act" style="text-align:right;white-space:nowrap;" onclick="event.stopPropagation()">
              <div class="pm-row-acts">
                <button type="button" class="pm-act-btn pm-inspect-trigger" onclick="window.openPmDrawer(<?= (int)$pm->id ?>, event)"
                        aria-label="Inspect <?= $pm_h($pm->schedule_name) ?>">Inspect</button>
                <?php if (!empty($can_complete_pm)): ?>
                <button type="button" class="pm-act-btn" onclick="markDone(<?= (int)$pm->id ?>, <?= (int)$pm->equipment_id ?>)">Done</button>
                <?php endif; ?>
                <?php if (!empty($can_manage_pm)): ?>
                <a href="<?= base_url('pm/edit/' . (int)$pm->id) ?>" class="pm-act-btn">Edit</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- 6. Pagination Footer -->
    <div class="pm-foot">
      <div>
        Showing <strong><?= number_format($show_from) ?>–<?= number_format($show_to) ?></strong>
        of <strong><?= number_format($total_filtered) ?></strong> schedules
      </div>
      <div class="d-flex align-items-center gap-3 flex-wrap pm-foot-controls">
        <form method="get" action="<?= base_url('pm') ?>" class="d-inline-flex align-items-center gap-2">
          <?php foreach ($base_params as $bk => $bv): if ($bk === 'per_page' || $bk === 'page') continue; ?>
            <input type="hidden" name="<?= $pm_h($bk) ?>" value="<?= $pm_h($bv) ?>">
          <?php endforeach; ?>
          <label for="pmPerPage" style="font-size:12px;color:var(--t3);">Rows:</label>
          <select id="pmPerPage" name="per_page" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
            <?php foreach ($allowed_pp as $pp): ?>
            <option value="<?= (int)$pp ?>" <?= (int)$per_page === (int)$pp ? 'selected' : '' ?>><?= (int)$pp === 150 ? '150 (All)' : (int)$pp ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <?php if ($total_pages > 1): ?>
        <nav class="pm-pages" aria-label="PM schedule pagination">
          <a class="pm-page-link <?= $current_page <= 1 ? 'is-disabled' : '' ?>"
             href="<?= $pm_h($pm_url(array('page' => max(1, $current_page - 1)))) ?>">Prev</a>
          <?php
          $start_p = max(1, $current_page - 2);
          $end_p   = min($total_pages, $current_page + 2);
          for ($p_i = $start_p; $p_i <= $end_p; $p_i++):
          ?>
            <a class="pm-page-link <?= $p_i === $current_page ? 'active' : '' ?>"
               href="<?= $pm_h($pm_url(array('page' => $p_i))) ?>"><?= $p_i ?></a>
          <?php endfor; ?>
          <a class="pm-page-link <?= $current_page >= $total_pages ? 'is-disabled' : '' ?>"
             href="<?= $pm_h($pm_url(array('page' => min($total_pages, $current_page + 1)))) ?>">Next</a>
        </nav>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Sticky Multi-Select Batch CSV Export Bar -->
<div class="pm-batch-bar" id="pmBatchBar" role="region" aria-label="Selected PM schedules batch actions">
  <span><strong id="pmSelectedCount">0</strong> schedule(s) selected</span>
  <a href="#" id="pmBatchExportBtn" class="btn-batch">Export Selected CSV</a>
  <button type="button" id="pmBatchClearBtn" class="btn-batch-clear">Clear</button>
</div>

<!-- Right-Side Slide-Over PM Schedule Inspector Drawer -->
<div class="pm-drawer-backdrop" id="pmDrawerBackdrop" onclick="window.closePmDrawer()"></div>
<aside class="pm-drawer" id="pmDrawer" role="dialog" aria-modal="true" aria-labelledby="pmDrawerTitle" aria-hidden="true">
  <div class="pm-drawer-head">
    <div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <h2 id="pmDrawerTitle" style="font-size:17px;font-weight:700;margin:0;">—</h2>
        <span id="pmDrawerStatus" class="badge b-secondary">—</span>
      </div>
      <div id="pmDrawerSub" style="font-size:12.5px;color:var(--t2);margin-top:3px;">Loading PM schedule…</div>
    </div>
    <button type="button" class="pm-act-btn" onclick="window.closePmDrawer()" aria-label="Close PM inspector drawer">✕</button>
  </div>
  <div class="pm-drawer-body" id="pmDrawerBody">
    <div style="color:var(--t3);font-size:13px;">Loading PM compliance, BOM parts &amp; history…</div>
  </div>
  <div class="pm-drawer-foot" id="pmDrawerFoot"></div>
</aside>

<!-- Mark Done Modal -->
<div id="doneModal" role="dialog" aria-modal="true" aria-labelledby="doneModalTitle" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(6px);z-index:1060;align-items:center;justify-content:center;">
  <div style="background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:26px;width:420px;max-width:92vw;box-shadow:0 20px 50px rgba(15,23,42,.25);">
    <div id="doneModalTitle" style="font-size:17px;font-weight:700;margin-bottom:16px;">Mark PM Schedule as Complete</div>
    <?= form_open('pm/complete') ?>
    <input type="hidden" name="pm_id" id="doneId">
    <div style="margin-bottom:14px;">
      <label class="form-label">Completion Date</label>
      <input type="date" name="completed_date" class="form-control" value="<?= date('Y-m-d') ?>">
    </div>
    <div style="margin-bottom:14px;">
      <label class="form-label">Meter Reading <span style="font-weight:400;color:var(--t3);">(hours or km per schedule)</span></label>
      <input type="number" name="completed_hours" class="form-control" step="0.1" placeholder="Hour / odometer reading">
    </div>
    <div style="margin-bottom:16px;">
      <label class="form-label">Work Order <span style="font-weight:400;color:var(--t3);">(optional — job that performed service)</span></label>
      <select name="work_order_id" class="form-select">
        <option value="">— not linked to a work order —</option>
        <?php if (isset($open_wos)): foreach ($open_wos as $w): ?>
        <option value="<?= (int)$w->id ?>" data-equipment="<?= (int)$w->equipment_id ?>">
          <?= $pm_h($w->wo_number) ?> — <?= $pm_h($w->registration_no) ?> — <?= $pm_h($w->title) ?>
        </option>
        <?php endforeach; endif; ?>
      </select>
      <div style="font-size:11px;color:var(--t3);margin-top:4px;">Only a work order belonging to this schedule's machine is accepted.</div>
    </div>
    <div style="margin-bottom:18px;">
      <label class="form-label">Notes</label>
      <textarea name="notes" class="form-control" rows="2" placeholder="Service completion notes..."></textarea>
    </div>
    <div class="d-flex justify-content-end gap-2">
      <button type="button" onclick="document.getElementById('doneModal').style.display='none'" class="btn btn-outline-secondary btn-sm">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm">Mark Complete</button>
    </div>
    <?= form_close() ?>
  </div>
</div>

<script>
function markDone(id, equipmentId) {
  window.closePmDrawer();
  document.getElementById('doneId').value = id;
  var sel = document.querySelector('#doneModal select[name="work_order_id"]');
  if (sel) {
    for (var i = 0; i < sel.options.length; i++) {
      var opt = sel.options[i];
      var eq  = opt.getAttribute('data-equipment');
      var off = (eq !== null && eq !== '' && String(eq) !== String(equipmentId));
      opt.hidden = off;
      opt.disabled = off;
    }
    sel.value = '';
  }
  document.getElementById('doneModal').style.display = 'flex';
}

(function () {
  var shell       = document.getElementById('pmShell');
  var searchInput = document.getElementById('pmSearchInput');
  var drawer      = document.getElementById('pmDrawer');
  var backdrop    = document.getElementById('pmDrawerBackdrop');
  var titleEl     = document.getElementById('pmDrawerTitle');
  var statusEl    = document.getElementById('pmDrawerStatus');
  var subEl       = document.getElementById('pmDrawerSub');
  var bodyEl      = document.getElementById('pmDrawerBody');
  var footEl      = document.getElementById('pmDrawerFoot');
  var summaryBase = <?= json_encode(base_url('pm/summary_json/'), JSON_UNESCAPED_SLASHES) ?>;
  var baseCsvUrl  = <?= json_encode(base_url('pm/export_csv'), JSON_UNESCAPED_SLASHES) ?>;
  var pageIds     = Array.prototype.slice.call(document.querySelectorAll('tr[data-pm-id]')).map(function (r) {
    return parseInt(r.getAttribute('data-pm-id'), 10);
  });
  var activeId    = null;
  var lastTrigger = null;

  Array.prototype.slice.call(document.querySelectorAll('[data-pm-view]')).forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      var mode = btn.getAttribute('data-pm-view');
      if (shell) shell.setAttribute('data-view', mode);
      Array.prototype.slice.call(document.querySelectorAll('[data-pm-view]')).forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-pm-view') === mode);
      });
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', btn.getAttribute('href'));
      }
    });
  });

  document.addEventListener('keydown', function (e) {
    var drawerOpen = drawer && drawer.classList.contains('is-open');
    var modalEl    = document.getElementById('doneModal');
    if (e.key === '/' && searchInput && !/^(INPUT|TEXTAREA|SELECT)$/.test((e.target && e.target.tagName) || '')) {
      e.preventDefault();
      searchInput.focus();
      searchInput.select();
    } else if (e.key === 'Escape') {
      if (modalEl && modalEl.style.display === 'flex') modalEl.style.display = 'none';
      if (drawerOpen) window.closePmDrawer();
    } else if (drawerOpen && (e.key === 'ArrowRight' || e.key === 'ArrowLeft') && pageIds.length > 1) {
      var idx = pageIds.indexOf(activeId);
      if (idx !== -1) {
        var nextIdx = (idx + (e.key === 'ArrowRight' ? 1 : -1) + pageIds.length) % pageIds.length;
        window.openPmDrawer(pageIds[nextIdx], null, true);
      }
    }
  });

  var checkAll  = document.getElementById('pmCheckAll');
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.pm-row-check'));
  var batchBar  = document.getElementById('pmBatchBar');
  var cntEl     = document.getElementById('pmSelectedCount');
  var exportBtn = document.getElementById('pmBatchExportBtn');
  var clearBtn  = document.getElementById('pmBatchClearBtn');

  function syncSelection() {
    var ids = [];
    rowChecks.forEach(function (cb) {
      var tr = cb.closest('tr');
      if (cb.checked) {
        ids.push(cb.value);
        if (tr) tr.classList.add('is-selected');
      } else if (tr) {
        tr.classList.remove('is-selected');
      }
    });
    if (cntEl) cntEl.textContent = String(ids.length);
    if (exportBtn) exportBtn.href = baseCsvUrl + '?ids=' + encodeURIComponent(ids.join(','));
    if (batchBar) batchBar.classList.toggle('is-open', ids.length > 0);
    if (checkAll && rowChecks.length > 0) {
      checkAll.checked = ids.length === rowChecks.length;
      checkAll.indeterminate = ids.length > 0 && ids.length < rowChecks.length;
    }
  }
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      rowChecks.forEach(function (cb) { cb.checked = checkAll.checked; });
      syncSelection();
    });
  }
  rowChecks.forEach(function (cb) { cb.addEventListener('change', syncSelection); });
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      rowChecks.forEach(function (cb) { cb.checked = false; });
      syncSelection();
    });
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  window.openPmDrawer = function (id, ev, keepTrigger) {
    if (ev && ev.target && ev.target.closest && ev.target.closest('a,button,input,select,label')) {
      if (!ev.target.closest('[onclick*="openPmDrawer"]')) return;
    }
    activeId = parseInt(id, 10);
    if (!keepTrigger) lastTrigger = document.activeElement;
    drawer.classList.add('is-open');
    backdrop.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    titleEl.textContent = 'PM Schedule #' + id;
    subEl.textContent   = 'Loading schedule details…';
    bodyEl.innerHTML    = '<div style="color:var(--t3);font-size:13px;">Loading PM compliance, BOM parts &amp; history…</div>';

    fetch(summaryBase + encodeURIComponent(id), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok || !res.pm) {
          bodyEl.innerHTML = '<div style="color:var(--red);font-size:13px;">Unable to load PM schedule details.</div>';
          return;
        }
        var p = res.pm;
        titleEl.textContent  = p.registration_no + ' · ' + p.schedule_name;
        statusEl.className   = 'badge ' + esc(p.compliance_badge || 'b-secondary');
        statusEl.textContent = p.compliance_label;
        subEl.textContent    = p.machine_sub + ' · ' + p.location;

        var html = '<div class="pm-mini-kpis">' +
          '<div class="pm-mini-kpi"><span>Interval</span><strong>' + esc(p.interval) + '</strong></div>' +
          '<div class="pm-mini-kpi"><span>Next Due</span><strong>' + esc(p.next_due) + '</strong></div>' +
          '<div class="pm-mini-kpi"><span>Target Meter</span><strong>' + esc(p.next_meter) + '</strong></div>' +
          '<div class="pm-mini-kpi"><span>Current Meter</span><strong>' + esc(p.cur_meter) + '</strong></div>' +
          '</div>';

        var fields = [
          ['Machine ID', p.registration_no],
          ['Schedule State', p.status],
          ['Standard Hours (F4)', p.standard_hours],
          ['Site / Location', p.location],
          ['Last Completed', p.last_done + ' (' + p.last_meter + ')'],
          ['Description', p.description]
        ];
        html += '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:8px;">Schedule Parameters</div><dl class="pm-dl-grid">';
        fields.forEach(function (f) {
          html += '<div class="pm-dl-item"><dt>' + esc(f[0]) + '</dt><dd>' + esc(f[1]) + '</dd></div>';
        });
        html += '</dl></div>';

        if (p.tasks && p.tasks.length) {
          html += '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:6px;">Checklist Tasks (' + p.tasks.length + ')</div><ul style="margin:0;padding-left:18px;font-size:12.5px;">';
          p.tasks.forEach(function (t) { html += '<li>' + esc(t) + '</li>'; });
          html += '</ul></div>';
        }

        html += '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:6px;">BOM Spare Parts (' + (p.parts ? p.parts.length : 0) + ')</div>';
        if (p.parts && p.parts.length) {
          p.parts.forEach(function (sp) {
            html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--line);font-size:12px;">' +
              '<div><strong>' + esc(sp.code) + '</strong> · ' + esc(sp.description) + ' <span style="color:var(--t3);">(' + esc(sp.qty) + ')</span></div>' +
              '<span class="badge ' + (sp.short ? 'b-danger' : 'b-available') + '">Stock: ' + esc(sp.stock) + (sp.short ? ' · SHORT' : '') + '</span></div>';
          });
        } else {
          html += '<div style="font-size:12px;color:var(--t3);">No spare parts linked to this PM schedule yet.</div>';
        }
        html += '</div>';

        html += '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:6px;">Recent Completions (' + (p.completions ? p.completions.length : 0) + ')</div>';
        if (p.completions && p.completions.length) {
          p.completions.forEach(function (c) {
            html += '<div style="padding:7px 8px;border-radius:6px;border:1px solid var(--line);margin-bottom:6px;font-size:12px;">' +
              '<div style="display:flex;justify-content:space-between;"><strong>' + esc(c.date) + '</strong><span>Meter: ' + esc(c.hours) + '</span></div>' +
              '<div style="font-size:11px;color:var(--t3);">By ' + esc(c.by) + (c.wo_number ? ' · <a href="' + esc(c.wo_url) + '">' + esc(c.wo_number) + '</a>' : '') + '</div></div>';
          });
        } else {
          html += '<div style="font-size:12px;color:var(--t3);">No completion records recorded yet.</div>';
        }
        html += '</div>';

        bodyEl.innerHTML = html;

        var footHtml = '<div class="d-flex gap-2 flex-wrap">';
        if (p.can_complete) {
          footHtml += '<button type="button" class="btn btn-success btn-sm" onclick="markDone(' + Number(p.id) + ',' + Number(p.equipment_id) + ')">Mark Complete</button>';
        }
        footHtml += '<a href="' + esc(p.parts_url) + '" class="btn btn-outline-secondary btn-sm">BOM Parts</a>';
        if (p.can_manage) {
          footHtml += '<a href="' + esc(p.edit_url) + '" class="btn btn-outline-secondary btn-sm">Edit</a>';
        }
        footHtml += '</div><a href="' + esc(p.equip_url) + '" class="btn btn-primary btn-sm">Machine Profile &rarr;</a>';
        footEl.innerHTML = footHtml;
      })
      .catch(function () {
        bodyEl.innerHTML = '<div style="color:var(--red);font-size:13px;">Network error loading PM schedule details.</div>';
      });
  };

  window.closePmDrawer = function () {
    if (!drawer) return;
    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    if (lastTrigger && lastTrigger.focus) {
      try { lastTrigger.focus(); } catch (e) {}
      lastTrigger = null;
    }
  };
})();
</script>
