<?php
/**
 * Equipment Fleet Register — Apple-HIG Enterprise Data Table + Dual Card View + Slide-Over Inspector Drawer.
 * Preserves verbatim Asset Register `registration_no`, `type_name`, `category`, `brand`, `model`,
 * `year_manufacture`, `serial_number`, `engine_model`, `current_hours`, `current_km`, and `location`.
 */
$eq_h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$base_params = array();
if (!empty($tab) && $tab !== 'all') $base_params['tab'] = $tab;
if (!empty($q))          $base_params['q']            = $q;
if (!empty($f_category)) $base_params['category']     = $f_category;
if (!empty($f_type))     $base_params['type']         = (int)$f_type;
if (!empty($f_location)) $base_params['location']     = $f_location;
if (!empty($f_group))    $base_params['report_group'] = $f_group;
if (!empty($f_rep))      $base_params['reportable']   = $f_rep;
if (!empty($view_mode) && $view_mode !== 'table') $base_params['view'] = $view_mode;
if (!empty($sort) && !($sort === 'reg' && $dir === 'asc')) {
    $base_params['sort'] = $sort;
    $base_params['dir']  = $dir;
}
if (!empty($per_page) && (int)$per_page !== 25) $base_params['per_page'] = (int)$per_page;

$eq_url = function (array $override = array(), array $remove = array()) use ($base_params) {
    $p = array_merge($base_params, $override);
    foreach ($remove as $rk) unset($p[$rk]);
    foreach ($p as $pk => $pv) {
        if ($pv === '' || $pv === NULL || ($pk === 'tab' && $pv === 'all') || ($pk === 'view' && $pv === 'table')) {
            unset($p[$pk]);
        }
    }
    $qs = http_build_query($p);
    return base_url('equipment') . ($qs !== '' ? '?' . $qs : '');
};

$sort_link = function ($col, $default_dir = 'asc') use ($sort, $dir, $eq_url) {
    $next = ($sort === $col) ? ($dir === 'asc' ? 'desc' : 'asc') : $default_dir;
    return $eq_url(array('sort' => $col, 'dir' => $next), array('page'));
};
$aria_sort = function ($col) use ($sort, $dir) {
    if ($sort !== $col) return 'none';
    return $dir === 'asc' ? 'ascending' : 'descending';
};
$sort_glyph = function ($col) use ($sort, $dir) {
    if ($sort !== $col) return '<span class="eq-sort-ind" aria-hidden="true">⇅</span>';
    return $dir === 'asc'
        ? '<span class="eq-sort-ind is-on" aria-hidden="true">↑</span>'
        : '<span class="eq-sort-ind is-on" aria-hidden="true">↓</span>';
};

$csv_qs  = http_build_query($base_params);
$csv_url = base_url('equipment/export_csv') . ($csv_qs !== '' ? '?' . $csv_qs : '');

$has_active_filters = (!empty($q) || !empty($f_category) || !empty($f_type) || !empty($f_location) || !empty($f_group) || !empty($f_rep) || (!empty($tab) && $tab !== 'all'));

$type_label_map = array();
foreach ($types as $t) $type_label_map[(int)$t->id] = $t->name;

$rep_summary = isset($reportability) ? $reportability : array('total' => 0, 'reportable' => 0, 'not_reportable' => 0, 'unclassified' => 0);
$st_summary  = isset($site_summary) ? $site_summary : array('machines' => 0, 'per_site' => array(), 'unapproved' => array(), 'blank' => 0);
$sites_vocab = isset($report_sites) ? $report_sites : array();
$unapp_cnt   = array_sum(isset($st_summary['unapproved']) ? $st_summary['unapproved'] : array());
$dq_total    = (int)$rep_summary['unclassified'] + $unapp_cnt + (int)$st_summary['blank'];

$show_from = $total_filtered > 0 ? ($offset + 1) : 0;
$show_to   = min($offset + $per_page, $total_filtered);
?>
<style>
  .eq-wrap{display:flex;flex-direction:column;gap:16px}
  .eq-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}
  .eq-title-grp{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
  .eq-title{font-size:21px;font-weight:700;letter-spacing:-.02em;color:var(--t1);margin:0}
  .eq-sub{font-size:12.5px;color:var(--t3);font-weight:500}
  .eq-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .eq-view-seg{display:inline-flex;background:var(--g1);border:1px solid var(--line2);border-radius:var(--r-sm);padding:2px}
  .eq-view-seg a{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;font-size:12px;font-weight:600;color:var(--t2);border-radius:6px;text-decoration:none;transition:background .15s,color .15s}
  .eq-view-seg a.active{background:var(--surface);color:var(--blue);box-shadow:0 1px 2px rgba(15,23,42,.08)}

  /* 5-Card Executive KPI Strip */
  .eq-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}
  @media(max-width:1199px){.eq-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
  @media(max-width:767px){.eq-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
  .eq-kpi{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:14px 16px;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:4px;box-shadow:var(--sh-sm);transition:border-color .15s,transform .15s}
  .eq-kpi:hover{border-color:var(--blue);transform:translateY(-1px);color:inherit}
  .eq-kpi.is-active{border-color:var(--blue);box-shadow:0 0 0 2px rgba(37,99,235,.12)}
  .eq-kpi-lbl{font-size:11px;font-weight:600;letter-spacing:.045em;text-transform:uppercase;color:var(--t3)}
  .eq-kpi-val{font-size:24px;font-weight:700;letter-spacing:-.02em;color:var(--t1);font-variant-numeric:tabular-nums;line-height:1.15}
  .eq-kpi-meta{font-size:11.5px;color:var(--t2);display:flex;align-items:center;gap:6px;flex-wrap:wrap}

  /* Segmented Status & Operational Categorization Tabs */
  .eq-tabs-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
  .eq-tabs{display:inline-flex;align-items:center;gap:3px;background:var(--g1);padding:4px;border-radius:10px;border:1px solid var(--line);overflow-x:auto;max-width:100%}
  .eq-tab{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border-radius:7px;font-size:12.5px;font-weight:600;color:var(--t2);text-decoration:none;white-space:nowrap;transition:background .15s,color .15s}
  .eq-tab:hover{color:var(--t1);background:rgba(255,255,255,.55)}
  .eq-tab.active{background:var(--surface);color:var(--t1);box-shadow:0 1px 3px rgba(15,23,42,.1)}
  .eq-tab-cnt{font-size:11px;font-weight:700;padding:1px 6px;border-radius:999px;background:var(--g2);color:var(--t2);font-variant-numeric:tabular-nums}
  .eq-tab.active .eq-tab-cnt{background:var(--blue-d);color:var(--blue)}
  .eq-tab-cnt.is-crit{background:var(--red-d);color:var(--red)}
  .eq-tab-cnt.is-warn{background:var(--amber-d);color:var(--amber)}

  /* Filter Toolbar + Active Chips + Governance Bar */
  .eq-filter-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:12px 14px;box-shadow:var(--sh-sm);display:flex;flex-direction:column;gap:10px}
  .eq-filter-row{display:grid;grid-template-columns:minmax(220px,1.9fr) repeat(5,minmax(125px,1fr)) auto;gap:8px;align-items:center}
  @media(max-width:1280px){.eq-filter-row{grid-template-columns:repeat(3,minmax(0,1fr))}}
  @media(max-width:767px){.eq-filter-row{grid-template-columns:1fr}}
  .eq-search-box{position:relative;display:flex;align-items:center}
  .eq-search-box svg{position:absolute;left:10px;width:15px;height:15px;color:var(--t3);pointer-events:none}
  .eq-search-box input{padding-left:32px;padding-right:28px;font-size:12.5px;height:36px}
  .eq-kbd{position:absolute;right:8px;font-size:10px;font-family:var(--mono);color:var(--t3);border:1px solid var(--line2);border-radius:4px;padding:1px 5px;pointer-events:none}
  .eq-filter-row select{height:36px;font-size:12.5px}
  .eq-chips{display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding-top:4px;border-top:1px dashed var(--line)}
  .eq-chip{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:3px 9px;border-radius:999px;background:var(--blue-d);color:var(--blue);text-decoration:none}
  .eq-chip:hover{opacity:.85;color:var(--blue)}
  .eq-gov-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;padding:8px 12px;border-radius:8px;background:var(--g1);border:1px solid var(--line);font-size:11.5px;color:var(--t2)}

  /* Enterprise Fleet DataTable */
  .eq-table-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh-sm);overflow:hidden}
  .eq-table-wrap{overflow-x:auto}
  table.eq-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px}
  .eq-table thead th{position:sticky;top:0;z-index:2;background:var(--g1);border-bottom:1px solid var(--line2);padding:9px 8px;font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--t2);white-space:nowrap;vertical-align:middle}
  .eq-table thead th a{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:3px}
  .eq-table thead th a:hover{color:var(--blue)}
  .eq-sort-ind{font-size:10.5px;color:var(--t3);opacity:.65}
  .eq-sort-ind.is-on{color:var(--blue);opacity:1;font-weight:800}
  .eq-table tbody td{padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:middle;color:var(--t1)}
  .eq-table tbody tr:last-child td{border-bottom:none}
  .eq-table tbody tr{transition:background .12s;cursor:pointer}
  .eq-table tbody tr:hover{background:var(--g1)}
  .eq-table tbody tr.is-down-crit{box-shadow:inset 3px 0 0 var(--red)}
  .eq-table tbody tr.is-down-maint{box-shadow:inset 3px 0 0 var(--amber)}
  .eq-table tbody tr.is-selected{background:var(--blue-d)}
  .eq-num{text-align:right;font-variant-numeric:tabular-nums;font-family:var(--mono);font-size:12px;white-space:nowrap}
  .eq-machine-id{font-family:var(--mono);font-size:13px;font-weight:700;color:var(--blue);text-decoration:none;letter-spacing:.01em;white-space:nowrap}
  .eq-machine-id:hover{text-decoration:underline}
  .eq-meta-sub{font-size:11px;color:var(--t3);margin-top:2px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
  .eq-cat-pill{display:inline-block;font-size:10.5px;font-weight:600;padding:1px 7px;border-radius:999px;background:var(--g2);color:var(--t2);white-space:nowrap}
  .eq-ops-stack{display:flex;align-items:center;gap:5px;flex-wrap:wrap}
  .eq-ops-pill{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:2px 7px;border-radius:6px;text-decoration:none;white-space:nowrap;font-variant-numeric:tabular-nums}
  .eq-ops-wo{background:var(--blue-d);color:var(--blue)}
  .eq-ops-wo.crit{background:var(--red-d);color:var(--red)}
  .eq-ops-pm{background:var(--amber-d);color:var(--amber)}
  .eq-row-acts{display:inline-flex;align-items:center;gap:4px}
  .eq-act-btn{display:inline-flex;align-items:center;justify-content:center;height:28px;padding:0 8px;border-radius:6px;border:1px solid var(--line2);background:var(--surface);color:var(--t2);font-size:11.5px;font-weight:600;text-decoration:none;cursor:pointer;transition:border-color .14s,color .14s}
  .eq-act-btn:hover{border-color:var(--blue);color:var(--blue)}

  /* Dual Card Grid View (`?view=cards` & automatic `< 768px` mobile layout) */
  .eq-cards-container{display:none}
  .eq-wrap[data-view="cards"] .eq-table-only{display:none}
  .eq-wrap[data-view="cards"] .eq-cards-container{display:block}
  @media(max-width:767px){
    .eq-table-only{display:none !important}
    .eq-cards-container{display:block !important}
  }
  .eq-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(290px,100%),1fr));gap:14px}
  .eq-card-item{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px;box-shadow:var(--sh-sm);display:flex;flex-direction:column;gap:10px;transition:border-color .15s,transform .15s;cursor:pointer}
  .eq-card-item:hover{border-color:var(--blue);transform:translateY(-2px)}

  /* Sticky Multi-Select Batch Bar */
  .eq-batch-bar{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:1040;background:#0f172a;color:#f8fafc;padding:9px 16px;border-radius:999px;box-shadow:0 12px 30px rgba(15,23,42,.3);display:none;align-items:center;gap:12px;font-size:12.5px}
  .eq-batch-bar.is-open{display:inline-flex}
  .eq-batch-bar .btn-batch{background:#2563eb;color:#fff;border:none;border-radius:999px;padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none}
  .eq-batch-bar .btn-batch-clear{background:transparent;color:#cbd5e1;border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:4px 10px;font-size:11.5px;cursor:pointer}

  /* Pagination Footer */
  .eq-foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:11px 14px;border-top:1px solid var(--line);background:var(--surface);font-size:12.5px;color:var(--t2)}
  .eq-pages{display:inline-flex;align-items:center;gap:4px;flex-wrap:wrap}
  .eq-page-link{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;padding:0 8px;border-radius:6px;border:1px solid var(--line);background:var(--surface);color:var(--t1);font-size:12px;font-weight:600;text-decoration:none}
  .eq-page-link:hover{border-color:var(--blue);color:var(--blue)}
  .eq-page-link.active{background:var(--blue);border-color:var(--blue);color:#fff}
  .eq-page-link.is-disabled{opacity:.42;pointer-events:none}

  /* Right-Side Slide-Over Machine Inspector Drawer */
  .eq-drawer-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.42);z-index:1050;opacity:0;pointer-events:none;transition:opacity .2s}
  .eq-drawer-backdrop.is-open{opacity:1;pointer-events:auto}
  .eq-drawer{position:fixed;top:0;right:0;bottom:0;width:min(500px,100vw);background:var(--surface);border-left:1px solid var(--line);z-index:1055;transform:translateX(100%);transition:transform .22s cubic-bezier(.16,1,.3,1);display:flex;flex-direction:column;box-shadow:-12px 0 36px rgba(15,23,42,.18)}
  .eq-drawer.is-open{transform:translateX(0)}
  .eq-drawer-head{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
  .eq-drawer-body{padding:16px 18px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:16px}
  .eq-drawer-foot{padding:12px 18px;border-top:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;background:var(--g1)}
  .eq-dl-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 14px;font-size:12.5px}
  .eq-dl-item dt{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--t3);margin:0}
  .eq-dl-item dd{margin:2px 0 0;font-weight:600;color:var(--t1);word-break:break-word}
  .eq-mini-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
  .eq-mini-kpi{background:var(--g1);border:1px solid var(--line);border-radius:8px;padding:8px 10px}
  .eq-mini-kpi span{display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--t3)}
  .eq-mini-kpi strong{font-size:13.5px;font-weight:700;color:var(--t1);font-variant-numeric:tabular-nums}

  @media print {
    .wms-sidebar, .wms-topbar, .eq-actions, .eq-tabs-bar, .eq-filter-card, .eq-batch-bar,
    .eq-row-acts, .eq-col-check, .eq-col-act, .eq-foot-controls, .eq-drawer, .eq-drawer-backdrop { display:none !important; }
    .eq-kpis { grid-template-columns:repeat(5,1fr) !important; }
    .eq-table-card, .eq-kpi { box-shadow:none !important; border:1px solid #cbd5e1 !important; }
    .eq-table th, .eq-table td { padding:6px 8px !important; font-size:10.5px !important; }
  }
</style>

<div class="eq-wrap" id="eqShell" data-view="<?= $eq_h($view_mode) ?>">
  <!-- 1. Page Header & Primary Toolbar -->
  <div class="eq-head">
    <div class="eq-title-grp">
      <h1 class="eq-title">Equipment Fleet</h1>
      <span class="eq-sub"><?= number_format($total_filtered) ?> of <?= number_format($kpi['total']) ?> Asset Register machines</span>
    </div>
    <div class="eq-actions">
      <div class="eq-view-seg" role="group" aria-label="View mode">
        <a href="<?= $eq_h($eq_url(array('view' => 'table'), array('page'))) ?>" data-eq-view="table" class="<?= $view_mode === 'table' ? 'active' : '' ?>">Table</a>
        <a href="<?= $eq_h($eq_url(array('view' => 'cards'), array('page'))) ?>" data-eq-view="cards" class="<?= $view_mode === 'cards' ? 'active' : '' ?>">Cards</a>
      </div>
      <a href="<?= $eq_h($csv_url) ?>" class="btn btn-outline-secondary btn-sm">Export CSV</a>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">Print</button>
      <?php if (!empty($can_create_eq)): ?>
      <a href="<?= base_url('equipment/create') ?>" class="btn btn-primary btn-sm">+ Add Equipment</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- 2. Executive Fleet Telemetry KPI Strip -->
  <div class="eq-kpis">
    <a href="<?= $eq_h($eq_url(array('tab' => 'all'), array('page'))) ?>" class="eq-kpi <?= $tab === 'all' ? 'is-active' : '' ?>">
      <span class="eq-kpi-lbl">Total Fleet Assets</span>
      <span class="eq-kpi-val"><?= number_format($kpi['total']) ?></span>
      <span class="eq-kpi-meta"><?= number_format($rep_summary['reportable']) ?> weekly-reportable assets</span>
    </a>
    <a href="<?= $eq_h($eq_url(array('tab' => 'available'), array('page'))) ?>" class="eq-kpi <?= $tab === 'available' ? 'is-active' : '' ?>">
      <span class="eq-kpi-lbl">Operational Fleet</span>
      <span class="eq-kpi-val" style="color:var(--green);"><?= number_format($kpi['operational']) ?></span>
      <span class="eq-kpi-meta"><?= number_format($kpi['avail_pct'], 1) ?>% fleet availability · <?= number_format($tab_counts['standby']) ?> standby</span>
    </a>
    <a href="<?= $eq_h($eq_url(array('tab' => 'breakdown'), array('page'))) ?>" class="eq-kpi <?= in_array($tab, array('breakdown', 'maintenance'), TRUE) ? 'is-active' : '' ?>">
      <span class="eq-kpi-lbl">Down / Out of Service</span>
      <span class="eq-kpi-val" style="color:<?= $kpi['down'] > 0 ? 'var(--red)' : 'var(--t1)' ?>;"><?= number_format($kpi['down']) ?></span>
      <span class="eq-kpi-meta"><?= number_format($kpi['breakdown']) ?> breakdown · <?= number_format($kpi['in_maintenance']) ?> in maint.</span>
    </a>
    <a href="<?= base_url('workorders?tab=open') ?>" class="eq-kpi">
      <span class="eq-kpi-lbl">Active Work Orders</span>
      <span class="eq-kpi-val" style="color:var(--blue);"><?= number_format($kpi['open_wo_total']) ?></span>
      <span class="eq-kpi-meta">Across <?= number_format($kpi['open_wo_machines']) ?> fleet machines</span>
    </a>
    <a href="<?= $eq_h($eq_url(array('tab' => 'pm_overdue'), array('page'))) ?>" class="eq-kpi <?= $tab === 'pm_overdue' ? 'is-active' : '' ?>">
      <span class="eq-kpi-lbl">PM Overdue Alerts</span>
      <span class="eq-kpi-val" style="color:<?= $kpi['pm_overdue_total'] > 0 ? 'var(--amber)' : 'var(--t1)' ?>;"><?= number_format($kpi['pm_overdue_total']) ?></span>
      <span class="eq-kpi-meta">Across <?= number_format($kpi['pm_overdue_machines']) ?> scheduled machines</span>
    </a>
  </div>

  <!-- 3. Segmented Fleet Categorization Tabs -->
  <div class="eq-tabs-bar">
    <nav class="eq-tabs" aria-label="Equipment status tabs">
      <?php
      $tabs_def = array(
          'all'         => array('label' => 'All Fleet',      'tone' => ''),
          'available'   => array('label' => 'Available',      'tone' => ''),
          'maintenance' => array('label' => 'In Maintenance', 'tone' => $tab_counts['maintenance'] > 0 ? 'is-warn' : ''),
          'breakdown'   => array('label' => 'Breakdown',      'tone' => $tab_counts['breakdown'] > 0 ? 'is-crit' : ''),
          'standby'     => array('label' => 'Standby',        'tone' => ''),
          'pm_overdue'  => array('label' => 'PM Overdue',     'tone' => $tab_counts['pm_overdue'] > 0 ? 'is-warn' : ''),
          'data_alert'  => array('label' => 'Data Alerts',    'tone' => $tab_counts['data_alert'] > 0 ? 'is-warn' : ''),
          'retired'     => array('label' => 'Retired',        'tone' => ''),
      );
      foreach ($tabs_def as $tk => $tmeta):
      ?>
      <a href="<?= $eq_h($eq_url(array('tab' => $tk), array('page'))) ?>"
         class="eq-tab <?= $tab === $tk ? 'active' : '' ?>"
         aria-current="<?= $tab === $tk ? 'page' : 'false' ?>">
        <span><?= $eq_h($tmeta['label']) ?></span>
        <span class="eq-tab-cnt <?= $eq_h($tmeta['tone']) ?>"><?= number_format($tab_counts[$tk]) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <!-- 4. Multi-Facet Filter Bar, Dismissible Filter Chips & W0.3/W0.5 Governance Strip -->
  <div class="eq-filter-card">
    <form method="get" action="<?= base_url('equipment') ?>" class="eq-filter-row" id="eqFilterForm">
      <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= $eq_h($tab) ?>"><?php endif; ?>
      <?php if ($view_mode !== 'table'): ?><input type="hidden" name="view" value="<?= $eq_h($view_mode) ?>"><?php endif; ?>
      <?php if (!($sort === 'reg' && $dir === 'asc')): ?>
        <input type="hidden" name="sort" value="<?= $eq_h($sort) ?>">
        <input type="hidden" name="dir" value="<?= $eq_h($dir) ?>">
      <?php endif; ?>
      <?php if ((int)$per_page !== 25): ?><input type="hidden" name="per_page" value="<?= (int)$per_page ?>"><?php endif; ?>

      <div class="eq-search-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="search" name="q" id="eqSearchInput" class="form-control"
               placeholder="Search Machine ID, brand, model, serial, engine..."
               value="<?= $eq_h($q) ?>" aria-label="Search fleet equipment">
        <span class="eq-kbd">/</span>
      </div>

      <select name="category" class="form-select" aria-label="Filter by category" onchange="this.form.submit()">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= $eq_h($cat) ?>" <?= strcasecmp($f_category, $cat) === 0 ? 'selected' : '' ?>><?= $eq_h($cat) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="type" class="form-select" aria-label="Filter by equipment type" onchange="this.form.submit()">
        <option value="">All Types</option>
        <?php foreach ($types as $t): ?>
        <option value="<?= (int)$t->id ?>" <?= (int)$f_type === (int)$t->id ? 'selected' : '' ?>><?= $eq_h($t->name) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="location" class="form-select" aria-label="Filter by site or location" onchange="this.form.submit()">
        <option value="">All Locations</option>
        <?php foreach ($locations as $loc): ?>
        <option value="<?= $eq_h($loc) ?>" <?= strcasecmp($f_location, $loc) === 0 ? 'selected' : '' ?>><?= $eq_h($loc) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="report_group" class="form-select" aria-label="Filter by W36 weekly report group" onchange="this.form.submit()">
        <option value="">All W36 Groups</option>
        <option value="__unclassified" <?= $f_group === '__unclassified' ? 'selected' : '' ?>>Unclassified (No Group)</option>
        <?php foreach ($report_groups as $grp): ?>
        <option value="<?= $eq_h($grp) ?>" <?= strcasecmp($f_group, $grp) === 0 ? 'selected' : '' ?>><?= $eq_h($grp) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="reportable" class="form-select" aria-label="Filter by weekly report inclusion" onchange="this.form.submit()">
        <option value="">Weekly Report: Any</option>
        <option value="yes" <?= $f_rep === 'yes' ? 'selected' : '' ?>>Included in W36</option>
        <option value="no" <?= $f_rep === 'no' ? 'selected' : '' ?>>Excluded / Retired</option>
      </select>

      <div class="d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm" style="height:36px;padding:0 14px;">Apply</button>
        <?php if ($has_active_filters): ?>
        <a href="<?= base_url('equipment' . ($view_mode === 'cards' ? '?view=cards' : '')) ?>" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center" style="height:36px;">Reset</a>
        <?php endif; ?>
      </div>
    </form>

    <?php if ($has_active_filters): ?>
    <div class="eq-chips">
      <span style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;">Active Filters:</span>
      <?php if ($tab !== 'all'): ?>
        <a href="<?= $eq_h($eq_url(array(), array('tab', 'page'))) ?>" class="eq-chip">Tab: <?= $eq_h($tabs_def[$tab]['label']) ?> ×</a>
      <?php endif; ?>
      <?php if ($q !== ''): ?>
        <a href="<?= $eq_h($eq_url(array(), array('q', 'page'))) ?>" class="eq-chip">Query: "<?= $eq_h($q) ?>" ×</a>
      <?php endif; ?>
      <?php if ($f_category !== ''): ?>
        <a href="<?= $eq_h($eq_url(array(), array('category', 'page'))) ?>" class="eq-chip">Category: <?= $eq_h($f_category) ?> ×</a>
      <?php endif; ?>
      <?php if ($f_type > 0 && isset($type_label_map[$f_type])): ?>
        <a href="<?= $eq_h($eq_url(array(), array('type', 'page'))) ?>" class="eq-chip">Type: <?= $eq_h($type_label_map[$f_type]) ?> ×</a>
      <?php endif; ?>
      <?php if ($f_location !== ''): ?>
        <a href="<?= $eq_h($eq_url(array(), array('location', 'page'))) ?>" class="eq-chip">Location: <?= $eq_h($f_location) ?> ×</a>
      <?php endif; ?>
      <?php if ($f_group !== ''): ?>
        <a href="<?= $eq_h($eq_url(array(), array('report_group', 'page'))) ?>" class="eq-chip">Group: <?= $eq_h($f_group === '__unclassified' ? 'Unclassified' : $f_group) ?> ×</a>
      <?php endif; ?>
      <?php if ($f_rep !== ''): ?>
        <a href="<?= $eq_h($eq_url(array(), array('reportable', 'page'))) ?>" class="eq-chip">Weekly Report: <?= $eq_h(strtoupper($f_rep)) ?> ×</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="eq-gov-bar">
      <div>
        <strong>Weekly Report Governance:</strong>
        <?= number_format($rep_summary['reportable']) ?> reportable of <?= number_format($rep_summary['total']) ?> assets
        <?php if ((int)$rep_summary['unclassified'] > 0): ?>
          · <span style="color:var(--amber);font-weight:600;"><?= (int)$rep_summary['unclassified'] ?> without W36 group</span>
        <?php endif; ?>
        <?php if ($unapp_cnt > 0): ?>
          · <span style="color:var(--red);font-weight:600;"><?= (int)$unapp_cnt ?> unapproved site value(s)</span>
        <?php endif; ?>
        <?php if ((int)$st_summary['blank'] > 0): ?>
          · <span style="color:var(--t3);"><?= (int)$st_summary['blank'] ?> blank location(s)</span>
        <?php endif; ?>
      </div>
      <?php if ($dq_total > 0 && $tab !== 'data_alert'): ?>
      <a href="<?= $eq_h($eq_url(array('tab' => 'data_alert'), array('page'))) ?>" style="font-weight:600;color:var(--blue);text-decoration:none;">
        Inspect <?= number_format($tab_counts['data_alert']) ?> data alert machine(s) &rarr;
      </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($equipment_list)): ?>
  <!-- Empty State -->
  <div class="eq-table-card" style="padding:48px 20px;text-align:center;">
    <div style="font-size:15px;font-weight:700;color:var(--t1);margin-bottom:4px;">No fleet machines match your current filters</div>
    <div style="font-size:13px;color:var(--t3);margin-bottom:14px;">Try broadening your search terms or clearing active status/location filters.</div>
    <a href="<?= base_url('equipment') ?>" class="btn btn-outline-primary btn-sm">Reset All Filters</a>
  </div>

  <?php else: ?>
  <!-- 5B. Secondary Interactive Card Grid & Mobile Responsive View -->
  <div class="eq-cards-container">
    <div class="eq-cards-grid">
      <?php foreach ($equipment_list as $eq):
          $smap    = equip_status_map($eq->status);
          $rep_lbl = equip_report_sort_label($eq);
          $loc_ok  = !empty($eq->loc_approved);
      ?>
      <div class="eq-card-item" data-drawer-id="<?= (int)$eq->id ?>" onclick="window.openEqDrawer(<?= (int)$eq->id ?>, event)">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div>
            <a href="<?= base_url('equipment/view/' . (int)$eq->id) ?>" class="eq-machine-id" onclick="event.stopPropagation()">
              <?= $eq_h($eq->registration_no) ?>
            </a>
            <div style="font-size:12px;color:var(--t2);font-weight:600;margin-top:2px;">
              <?= $eq_h(trim($eq->brand . ' ' . $eq->model)) ?>
              <?= $eq->year_manufacture ? ' · ' . $eq_h($eq->year_manufacture) : '' ?>
            </div>
          </div>
          <span class="badge <?= $eq_h($smap['badge']) ?>">
            <span class="led <?= $eq_h($smap['led']) ?>" style="margin-right:4px;"></span><?= $eq_h($eq->status) ?>
          </span>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span style="font-size:12px;font-weight:600;color:var(--t1);"><?= $eq_h($eq->type_name ?: '—') ?></span>
          <?php if (!empty($eq->category)): ?>
            <span class="eq-cat-pill"><?= $eq_h($eq->category) ?></span>
          <?php endif; ?>
          <?php if ($rep_lbl !== ''): ?>
            <span class="eq-cat-pill" style="font-family:var(--mono);"><?= $eq_h($rep_lbl) ?></span>
          <?php endif; ?>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding-top:8px;border-top:1px solid var(--line);font-size:11.5px;">
          <div>
            <div style="color:var(--t3);font-size:10.5px;">Current Hours</div>
            <div style="font-family:var(--mono);font-weight:700;"><?= number_format((float)$eq->current_hours, 1) ?></div>
          </div>
          <div>
            <div style="color:var(--t3);font-size:10.5px;">Current KM</div>
            <div style="font-family:var(--mono);font-weight:700;"><?= number_format((float)$eq->current_km, 1) ?></div>
          </div>
          <div>
            <div style="color:var(--t3);font-size:10.5px;">Location</div>
            <div style="font-weight:600;<?= !$loc_ok && $eq->location !== '' ? 'color:var(--red);' : '' ?>">
              <?= $eq_h($eq->location ?: '—') ?>
            </div>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-center pt-1">
          <div class="eq-ops-stack">
            <?php if ((int)$eq->open_wo_count > 0): ?>
              <span class="eq-ops-pill eq-ops-wo <?= (int)$eq->crit_wo_count > 0 ? 'crit' : '' ?>"><?= (int)$eq->open_wo_count ?> Open WO</span>
            <?php endif; ?>
            <?php if ((int)$eq->pm_overdue_count > 0): ?>
              <span class="eq-ops-pill eq-ops-pm"><?= (int)$eq->pm_overdue_count ?> PM Overdue</span>
            <?php endif; ?>
          </div>
          <div class="eq-row-acts" onclick="event.stopPropagation()">
            <button type="button" class="eq-act-btn" onclick="window.openEqDrawer(<?= (int)$eq->id ?>, event)">Inspect</button>
            <a href="<?= base_url('equipment/view/' . (int)$eq->id) ?>" class="eq-act-btn">Open</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- 5A. Primary Enterprise DataTable View -->
  <div class="eq-table-card">
    <div class="eq-table-wrap eq-table-only">
      <table class="eq-table" aria-label="Equipment Fleet Register">
        <thead>
          <tr>
            <th class="eq-col-check" style="width:32px;">
              <input type="checkbox" id="eqCheckAll" class="form-check-input" aria-label="Select all visible machines on this page">
            </th>
            <th aria-sort="<?= $aria_sort('reg') ?>">
              <a href="<?= $eq_h($sort_link('reg', 'asc')) ?>">Machine ID <?= $sort_glyph('reg') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('type') ?>">
              <a href="<?= $eq_h($sort_link('type', 'asc')) ?>">Type &amp; Category <?= $sort_glyph('type') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('brand') ?>">
              <a href="<?= $eq_h($sort_link('brand', 'asc')) ?>">Brand / Model <?= $sort_glyph('brand') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('hours') ?>" style="text-align:right;">
              <a href="<?= $eq_h($sort_link('hours', 'desc')) ?>">Current Hours <?= $sort_glyph('hours') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('km') ?>" style="text-align:right;">
              <a href="<?= $eq_h($sort_link('km', 'desc')) ?>">Current KM <?= $sort_glyph('km') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('location') ?>">
              <a href="<?= $eq_h($sort_link('location', 'asc')) ?>">Site / W36 <?= $sort_glyph('location') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('open_wos') ?>">
              <a href="<?= $eq_h($sort_link('open_wos', 'desc')) ?>">WOs / PM <?= $sort_glyph('open_wos') ?></a>
            </th>
            <th aria-sort="<?= $aria_sort('status') ?>">
              <a href="<?= $eq_h($sort_link('status', 'asc')) ?>">Status <?= $sort_glyph('status') ?></a>
            </th>
            <th class="eq-col-act" style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($equipment_list as $eq):
              $smap    = equip_status_map($eq->status);
              $rep_lbl = equip_report_sort_label($eq);
              $loc_ok  = !empty($eq->loc_approved);
              $row_cls = '';
              if ($eq->status === 'Breakdown') $row_cls = 'is-down-crit';
              elseif ($eq->status === 'In Maintenance') $row_cls = 'is-down-maint';
          ?>
          <tr class="<?= $row_cls ?>" data-eq-id="<?= (int)$eq->id ?>" onclick="window.openEqDrawer(<?= (int)$eq->id ?>, event)">
            <td class="eq-col-check" onclick="event.stopPropagation()">
              <input type="checkbox" class="form-check-input eq-row-check" value="<?= (int)$eq->id ?>"
                     aria-label="Select machine <?= $eq_h($eq->registration_no) ?>">
            </td>
            <td>
              <a href="<?= base_url('equipment/view/' . (int)$eq->id) ?>" class="eq-machine-id" onclick="event.stopPropagation()">
                <?= $eq_h($eq->registration_no) ?>
              </a>
              <div class="eq-meta-sub">
                <?php if ($eq->year_manufacture): ?><span><?= $eq_h($eq->year_manufacture) ?></span><?php endif; ?>
                <?php if ((int)$eq->spec_count > 0): ?><span><?= (int)$eq->spec_count ?> specs</span><?php endif; ?>
              </div>
            </td>
            <td>
              <div style="font-weight:600;color:var(--t1);"><?= $eq_h($eq->type_name ?: '—') ?></div>
              <?php if (!empty($eq->category)): ?>
              <div class="eq-meta-sub"><span class="eq-cat-pill"><?= $eq_h($eq->category) ?></span></div>
              <?php endif; ?>
            </td>
            <td style="max-width:210px;">
              <div style="font-weight:600;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= $eq_h(trim($eq->brand . ' ' . $eq->model) ?: '—') ?></div>
              <div class="eq-meta-sub" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;">
                <?php if (!empty($eq->serial_number)): ?>
                  <span style="font-family:var(--mono);">S/N: <?= $eq_h($eq->serial_number) ?></span>
                <?php endif; ?>
                <?php if (!empty($eq->engine_model)): ?>
                  <span> · <?= $eq_h($eq->engine_model) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td class="eq-num"><?= number_format((float)$eq->current_hours, 1) ?></td>
            <td class="eq-num"><?= number_format((float)$eq->current_km, 1) ?></td>
            <td style="max-width:155px;">
              <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;<?= !$loc_ok && $eq->location !== '' ? 'color:var(--red);' : 'color:var(--t1);' ?>">
                <?= $eq_h($eq->location !== '' && $eq->location !== NULL ? $eq->location : '—') ?>
              </div>
              <div class="eq-meta-sub" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;">
                <?php if ($rep_lbl !== ''): ?>
                  <span style="font-family:var(--mono);"><?= $eq_h($rep_lbl) ?></span>
                <?php elseif (!empty($eq->is_reportable)): ?>
                  <span style="color:var(--amber);">W36 unclassified</span>
                <?php else: ?>
                  <span>Excluded W36</span>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <div class="eq-ops-stack">
                <?php if ((int)$eq->open_wo_count > 0): ?>
                  <span class="eq-ops-pill eq-ops-wo <?= (int)$eq->crit_wo_count > 0 ? 'crit' : '' ?>">
                    <?= (int)$eq->open_wo_count ?> WO
                  </span>
                <?php endif; ?>
                <?php if ((int)$eq->pm_overdue_count > 0): ?>
                  <span class="eq-ops-pill eq-ops-pm"><?= (int)$eq->pm_overdue_count ?> PM</span>
                <?php elseif ((int)$eq->open_wo_count === 0): ?>
                  <span style="font-size:11.5px;color:var(--t3);">Clear</span>
                <?php endif; ?>
              </div>
            </td>
            <td style="white-space:nowrap;">
              <span class="badge <?= $eq_h($smap['badge']) ?>">
                <span class="led <?= $eq_h($smap['led']) ?>" style="margin-right:4px;"></span><?= $eq_h($eq->status) ?>
              </span>
            </td>
            <td class="eq-col-act" style="text-align:right;white-space:nowrap;" onclick="event.stopPropagation()">
              <div class="eq-row-acts">
                <button type="button" class="eq-act-btn eq-inspect-trigger" onclick="window.openEqDrawer(<?= (int)$eq->id ?>, event)"
                        aria-label="Inspect <?= $eq_h($eq->registration_no) ?>">Inspect</button>
                <a href="<?= base_url('equipment/view/' . (int)$eq->id) ?>" class="eq-act-btn">View</a>
                <?php if (!empty($can_create_eq)): ?>
                <a href="<?= base_url('equipment/edit/' . (int)$eq->id) ?>" class="eq-act-btn">Edit</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- 6. Pagination & Page-Size Footer -->
    <div class="eq-foot">
      <div>
        Showing <strong><?= number_format($show_from) ?>–<?= number_format($show_to) ?></strong>
        of <strong><?= number_format($total_filtered) ?></strong> machines
      </div>
      <div class="d-flex align-items-center gap-3 flex-wrap eq-foot-controls">
        <form method="get" action="<?= base_url('equipment') ?>" class="d-inline-flex align-items-center gap-2">
          <?php foreach ($base_params as $bk => $bv): if ($bk === 'per_page' || $bk === 'page') continue; ?>
            <input type="hidden" name="<?= $eq_h($bk) ?>" value="<?= $eq_h($bv) ?>">
          <?php endforeach; ?>
          <label for="eqPerPage" style="font-size:12px;color:var(--t3);">Rows:</label>
          <select id="eqPerPage" name="per_page" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
            <?php foreach ($allowed_pp as $pp): ?>
            <option value="<?= (int)$pp ?>" <?= (int)$per_page === (int)$pp ? 'selected' : '' ?>><?= (int)$pp === 150 ? '150 (All)' : (int)$pp ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <?php if ($total_pages > 1): ?>
        <nav class="eq-pages" aria-label="Equipment pagination">
          <a class="eq-page-link <?= $current_page <= 1 ? 'is-disabled' : '' ?>"
             href="<?= $eq_h($eq_url(array('page' => max(1, $current_page - 1)))) ?>">Prev</a>
          <?php
          $start_p = max(1, $current_page - 2);
          $end_p   = min($total_pages, $current_page + 2);
          for ($p_i = $start_p; $p_i <= $end_p; $p_i++):
          ?>
            <a class="eq-page-link <?= $p_i === $current_page ? 'active' : '' ?>"
               href="<?= $eq_h($eq_url(array('page' => $p_i))) ?>"><?= $p_i ?></a>
          <?php endfor; ?>
          <a class="eq-page-link <?= $current_page >= $total_pages ? 'is-disabled' : '' ?>"
             href="<?= $eq_h($eq_url(array('page' => min($total_pages, $current_page + 1)))) ?>">Next</a>
        </nav>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Sticky Multi-Select Batch CSV Export Bar -->
<div class="eq-batch-bar" id="eqBatchBar" role="region" aria-label="Selected machines batch actions">
  <span><strong id="eqSelectedCount">0</strong> machine(s) selected</span>
  <a href="#" id="eqBatchExportBtn" class="btn-batch">Export Selected CSV</a>
  <button type="button" id="eqBatchClearBtn" class="btn-batch-clear">Clear</button>
</div>

<!-- Right-Side Slide-Over Machine Inspector Drawer -->
<div class="eq-drawer-backdrop" id="eqDrawerBackdrop" onclick="window.closeEqDrawer()"></div>
<aside class="eq-drawer" id="eqDrawer" role="dialog" aria-modal="true" aria-labelledby="eqDrawerTitle" aria-hidden="true">
  <div class="eq-drawer-head">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h2 id="eqDrawerTitle" style="font-size:18px;font-weight:700;font-family:var(--mono);margin:0;">—</h2>
        <span id="eqDrawerStatus" class="badge b-secondary">—</span>
      </div>
      <div id="eqDrawerSub" style="font-size:12.5px;color:var(--t2);margin-top:3px;">Loading machine profile…</div>
    </div>
    <button type="button" class="eq-act-btn" onclick="window.closeEqDrawer()" aria-label="Close inspector drawer">✕</button>
  </div>
  <div class="eq-drawer-body" id="eqDrawerBody">
    <div style="color:var(--t3);font-size:13px;">Loading Asset Register telemetry…</div>
  </div>
  <div class="eq-drawer-foot" id="eqDrawerFoot">
    <a href="#" id="eqDrawerFullBtn" class="btn btn-primary btn-sm">Open Full Profile &rarr;</a>
  </div>
</aside>

<script>
(function () {
  var shell       = document.getElementById('eqShell');
  var searchInput = document.getElementById('eqSearchInput');
  var pageIds     = Array.prototype.slice.call(document.querySelectorAll('tr[data-eq-id]')).map(function (r) {
    return parseInt(r.getAttribute('data-eq-id'), 10);
  });
  var activeId    = null;

  Array.prototype.slice.call(document.querySelectorAll('[data-eq-view]')).forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      var mode = btn.getAttribute('data-eq-view');
      if (shell) shell.setAttribute('data-view', mode);
      Array.prototype.slice.call(document.querySelectorAll('[data-eq-view]')).forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-eq-view') === mode);
      });
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', btn.getAttribute('href'));
      }
    });
  });

  document.addEventListener('keydown', function (e) {
    var drawerOpen = drawer && drawer.classList.contains('is-open');
    if (e.key === '/' && searchInput && !/^(INPUT|TEXTAREA|SELECT)$/.test((e.target && e.target.tagName) || '')) {
      e.preventDefault();
      searchInput.focus();
      searchInput.select();
    } else if (e.key === 'Escape' && drawerOpen) {
      window.closeEqDrawer();
    } else if (drawerOpen && (e.key === 'ArrowRight' || e.key === 'ArrowLeft') && pageIds.length > 1) {
      var idx = pageIds.indexOf(activeId);
      if (idx !== -1) {
        var nextIdx = (idx + (e.key === 'ArrowRight' ? 1 : -1) + pageIds.length) % pageIds.length;
        window.openEqDrawer(pageIds[nextIdx], null, true);
      }
    }
  });

  // Multi-select batch CSV bar
  var checkAll   = document.getElementById('eqCheckAll');
  var rowChecks  = Array.prototype.slice.call(document.querySelectorAll('.eq-row-check'));
  var batchBar   = document.getElementById('eqBatchBar');
  var cntEl      = document.getElementById('eqSelectedCount');
  var exportBtn  = document.getElementById('eqBatchExportBtn');
  var clearBtn   = document.getElementById('eqBatchClearBtn');
  var baseCsvUrl = <?= json_encode(base_url('equipment/export_csv')) ?>;

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

  // Slide-Over Machine Inspector Drawer
  var drawer     = document.getElementById('eqDrawer');
  var backdrop   = document.getElementById('eqDrawerBackdrop');
  var titleEl    = document.getElementById('eqDrawerTitle');
  var statusEl   = document.getElementById('eqDrawerStatus');
  var subEl      = document.getElementById('eqDrawerSub');
  var bodyEl     = document.getElementById('eqDrawerBody');
  var footEl     = document.getElementById('eqDrawerFoot');
  var summaryBase = <?= json_encode(base_url('equipment/summary_json/')) ?>;
  var lastTrigger = null;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  window.openEqDrawer = function (id, ev, keepTrigger) {
    if (ev && ev.target && ev.target.closest && ev.target.closest('a,button,input,select,label')) {
      if (!ev.target.closest('[onclick*="openEqDrawer"]')) return;
    }
    activeId = parseInt(id, 10);
    if (!keepTrigger) lastTrigger = document.activeElement;
    drawer.classList.add('is-open');
    backdrop.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    titleEl.textContent = 'Machine #' + id;
    subEl.textContent   = 'Loading Asset Register record…';
    bodyEl.innerHTML    = '<div style="color:var(--t3);font-size:13px;">Loading Asset Register telemetry…</div>';

    fetch(summaryBase + encodeURIComponent(id), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok || !res.equipment) {
          bodyEl.innerHTML = '<div style="color:var(--red);font-size:13px;">Unable to load machine details.</div>';
          return;
        }
        var m = res.equipment;
        titleEl.textContent  = m.registration_no;
        statusEl.className   = 'badge ' + esc(m.status_badge || 'b-secondary');
        statusEl.textContent = m.status;
        subEl.textContent    = m.type_name + (m.category ? ' · ' + m.category : '') + ' · ' + m.brand + ' ' + m.model;

        var fields = [
          ['Machine ID', m.registration_no],
          ['Type / Category', m.type_name + ' / ' + m.category],
          ['Brand & Model', m.brand + ' ' + m.model],
          ['Year of Manufacture', m.year],
          ['Serial No.', m.serial_number],
          ['Engine Model', m.engine_model],
          ['Location', m.location + (m.loc_approved ? '' : ' (Unapproved Site)')],
          ['Weekly Report Group', m.report_label],
          ['Purchase Date', m.purchase_date],
          ['Warranty Expiry', m.warranty_expiry]
        ];
        var html = '<div class="eq-mini-kpis">' +
          '<div class="eq-mini-kpi"><span>Current Hours</span><strong>' + esc(m.current_hours) + '</strong></div>' +
          '<div class="eq-mini-kpi"><span>Current KM</span><strong>' + esc(m.current_km) + '</strong></div>' +
          '<div class="eq-mini-kpi"><span>30d Avail.</span><strong>' + esc(m.availability) + '</strong></div>' +
          '<div class="eq-mini-kpi"><span>Open WOs</span><strong>' + esc(m.open_wo_count) + '</strong></div>' +
          '</div>';

        html += '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:8px;">Asset Register Details</div><dl class="eq-dl-grid">';
        fields.forEach(function (f) {
          html += '<div class="eq-dl-item"><dt>' + esc(f[0]) + '</dt><dd>' + esc(f[1]) + '</dd></div>';
        });
        html += '</dl></div>';

        if (m.specs && m.specs.length) {
          html += '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:6px;">Manufacturer Specifications (' + m.specs.length + ')</div><dl class="eq-dl-grid">';
          m.specs.forEach(function (sp) {
            html += '<div class="eq-dl-item"><dt>' + esc(sp.key) + '</dt><dd>' + esc(sp.value) + '</dd></div>';
          });
          html += '</dl></div>';
        }

        if (m.pm_list && m.pm_list.length) {
          html += '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:6px;">Preventive Maintenance (' + m.pm_list.length + ')</div>';
          m.pm_list.forEach(function (pm) {
            html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--line);font-size:12px;">' +
              '<div><strong>' + esc(pm.name) + '</strong> <span style="color:var(--t3);">(' + esc(pm.interval) + ')</span></div>' +
              '<span class="badge ' + (pm.is_overdue ? 'b-danger' : 'b-primary') + '">' + esc(pm.next_due) + (pm.is_overdue ? ' · OVERDUE' : '') + '</span></div>';
          });
          html += '</div>';
        }

        html += '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--t3);margin-bottom:6px;">Recent Work Orders (' + (m.wo_list ? m.wo_list.length : 0) + ')</div>';
        if (m.wo_list && m.wo_list.length) {
          m.wo_list.forEach(function (wo) {
            html += '<a href="' + esc(wo.url) + '" style="display:flex;justify-content:space-between;align-items:center;padding:7px 8px;border-radius:6px;border:1px solid var(--line);margin-bottom:6px;text-decoration:none;color:inherit;font-size:12px;">' +
              '<div><strong style="font-family:var(--mono);color:var(--blue);">' + esc(wo.wo_number) + '</strong> · ' + esc(wo.title) +
              '<div style="font-size:11px;color:var(--t3);">' + esc(wo.type) + ' · ' + esc(wo.date_fmt) + (wo.assigned ? ' · ' + esc(wo.assigned) : '') + '</div></div>' +
              '<span class="badge ' + (wo.is_breakdown ? 'b-danger' : 'b-secondary') + '">' + esc(wo.status) + '</span></a>';
          });
        } else {
          html += '<div style="font-size:12px;color:var(--t3);">No work orders recorded for this machine yet.</div>';
        }
        html += '</div>';

        bodyEl.innerHTML = html;

        var footHtml = '<div class="d-flex gap-2 flex-wrap">';
        if (m.can_create_wo) {
          footHtml += '<a href="' + esc(m.create_wo_url) + '" class="btn btn-success btn-sm">+ New Work Order</a>';
        }
        if (m.can_edit) {
          footHtml += '<a href="' + esc(m.edit_url) + '" class="btn btn-outline-secondary btn-sm">Edit Machine</a>';
        }
        footHtml += '</div><a href="' + esc(m.view_url) + '" class="btn btn-primary btn-sm">Open Full Profile &rarr;</a>';
        footEl.innerHTML = footHtml;
      })
      .catch(function () {
        bodyEl.innerHTML = '<div style="color:var(--red);font-size:13px;">Network error loading machine profile.</div>';
      });
  };

  window.closeEqDrawer = function () {
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
