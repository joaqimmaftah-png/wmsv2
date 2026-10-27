<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Certifications Tracker — enterprise data table (docs/team-certifications-table-ux-audit.md).
 * Dataset, counting, sorting and pagination are server-side in
 * Team::_build_certifications_dataset(); this view only presents them.
 * Record = one row of `certifications`. The `none` tab lists workers with
 * zero certificates recorded (worker rows, not certificate rows).
 */
$alert_days = isset($cert_alert_days) ? (int)$cert_alert_days : 30;
$base_params = array(
    'q' => $search_query, 'cert' => $cert_filter, 'doc' => $doc_filter,
    'tab' => $active_tab, 'sort' => $sort_by, 'dir' => $sort_dir,
    'per_page' => (string)$per_page, 'view' => $view_mode,
);
$ct_url = function ($overrides = array()) use ($base_params) {
    $merged = array_merge($base_params, $overrides);
    $defaults = array('tab' => 'all', 'sort' => 'expiry', 'dir' => 'asc', 'per_page' => '25', 'view' => 'table', 'page' => '1');
    $clean = array();
    foreach ($merged as $k => $v) {
        $sv = trim((string)$v);
        if ($sv === '') continue;
        if (isset($defaults[$k]) && $sv === $defaults[$k]) continue;
        $clean[$k] = $sv;
    }
    $qs = http_build_query($clean);
    return site_url('team/certifications') . ($qs !== '' ? ('?' . $qs) : '');
};
$export_qs = http_build_query(array_filter(array(
    'q' => $search_query, 'cert' => $cert_filter, 'doc' => $doc_filter,
    'tab' => ($active_tab !== 'all' ? $active_tab : ''),
    'sort' => ($sort_by !== 'expiry' ? $sort_by : ''), 'dir' => ($sort_dir !== 'asc' ? $sort_dir : ''),
)));
$export_url = site_url('team/certifications_csv') . ($export_qs !== '' ? ('?' . $export_qs) : '');

$sort_th = function ($col, $label, $align = 'left', $default_dir = 'asc') use ($sort_by, $sort_dir, $ct_url) {
    $active = ($sort_by === $col);
    $next = $active ? (($sort_dir === 'asc') ? 'desc' : 'asc') : $default_dir;
    $aria = $active ? (($sort_dir === 'asc') ? 'ascending' : 'descending') : 'none';
    $icon = '<svg class="ct-sort-ico" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M6 2.5L8.8 5.3H3.2L6 2.5ZM6 9.5L3.2 6.7H8.8L6 9.5Z" fill="currentColor" opacity="' . ($active ? '1' : '0.35') . '"/></svg>';
    return '<th scope="col" aria-sort="' . $aria . '"' . ($align === 'right' ? ' class="ct-num"' : '') . '>'
        . '<a class="ct-th-sort' . ($active ? ' is-sorted' : '') . '" href="' . htmlspecialchars($ct_url(array('sort' => $col, 'dir' => $next, 'page' => 1)), ENT_QUOTES, 'UTF-8') . '">'
        . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>' . $icon . '</a></th>';
};

$today_ts = strtotime(date('Y-m-d'));
$days_of = function ($r) use ($today_ts) {
    return !empty($r->expiry_date) ? (int)floor((strtotime($r->expiry_date) - $today_ts) / 86400) : NULL;
};
$badge_of = function ($r) use ($days_of, $alert_days) {
    $d = $days_of($r);
    if ($d === NULL) return array('b-secondary', 'No expiry date');
    if ($d < 0) return array('b-danger', 'Expired');
    if ($d <= $alert_days) return array('b-warning', 'Expiring Soon');
    return array('b-available', 'Valid');
};
$days_text = function ($r) use ($days_of) {
    $d = $days_of($r);
    if ($d === NULL) return '—';
    if ($d < 0) return abs($d) . 'd overdue';
    if ($d === 0) return 'Today';
    return 'in ' . $d . 'd';
};
$avatar_palette = array('#0066cc', '#1d3557', '#5856d6', '#1c7c36', '#0c7b7b');
$initials_of = function ($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $a = isset($parts[0][0]) ? $parts[0][0] : '';
    $b = isset($parts[1][0]) ? $parts[1][0] : '';
    return strtoupper($a . $b);
};
$has_upcoming = ($alert_days < 90);
$tab_defs = array(
    'all'       => array('label' => 'All Certificates', 'count' => (int)$tab_counts['all']),
    'expired'   => array('label' => 'Expired',          'count' => (int)$tab_counts['expired']),
    'expiring'  => array('label' => 'Expiring Soon',    'count' => (int)$tab_counts['expiring']),
    'valid'     => array('label' => $has_upcoming ? 'Valid (>90d)' : 'Valid', 'count' => (int)$tab_counts['valid']),
    'no_expiry' => array('label' => 'No Expiry Date',   'count' => (int)$tab_counts['no_expiry']),
    'none'      => array('label' => 'None Recorded',    'count' => (int)$tab_counts['none']),
);
if ($has_upcoming) {
    // Insert the early-warning band after Expiring Soon, keeping tab order
    // by urgency: expired -> soon -> 31-90d -> valid -> no expiry -> none.
    $rebuilt = array();
    foreach ($tab_defs as $k => $def) {
        $rebuilt[$k] = $def;
        if ($k === 'expiring') {
            $rebuilt['upcoming'] = array('label' => 'Expiring in ' . ($alert_days + 1) . '–90d', 'count' => (int)$tab_counts['upcoming']);
        }
    }
    $tab_defs = $rebuilt;
}
$has_filters = ($search_query !== '' || $cert_filter !== '' || $doc_filter !== '' || $active_tab !== 'all');
$is_none_mode = ($active_tab === 'none');
$valid_pct = ((int)$summary['total'] > 0) ? (int)round((int)$summary['valid'] / (int)$summary['total'] * 100) : NULL;
$tracker_qs = http_build_query(array_filter($base_params));
?>

<style>
.ct-print-banner{display:none}
.ct-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.ct-crumb{font-size:12px;font-weight:500;color:var(--t3);margin-bottom:4px}
.ct-crumb a{color:var(--t2);text-decoration:none}.ct-crumb a:hover{color:var(--blue)}
.ct-head h1{font-size:24px;font-weight:700;letter-spacing:-.025em;color:var(--t1);line-height:1.2;margin:0}
.ct-sub{font-size:13px;color:var(--t2);margin-top:3px}
.ct-head-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ct-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:18px}
.ct-kpi{background:var(--surface);border:1px solid var(--sep);border-radius:var(--r-md);padding:14px 16px;box-shadow:var(--shadow-card)}
.ct-kpi-lbl{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--t3);margin-bottom:6px}
.ct-kpi-val{font-size:24px;font-weight:700;letter-spacing:-.03em;color:var(--t1);line-height:1.05;font-variant-numeric:tabular-nums}
.ct-kpi-meta{margin-top:6px;font-size:11.5px;color:var(--t3)}
.ct-shell{background:var(--surface);border:1px solid var(--sep);border-radius:var(--r-lg);box-shadow:var(--shadow-card);overflow:visible}
.ct-scope{padding:12px 16px;background:var(--surface-2);border-bottom:1px solid var(--sep);border-radius:var(--r-lg) var(--r-lg) 0 0;display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap}
.ct-field{display:flex;flex-direction:column;gap:4px}
.ct-field label{font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--t3);margin:0}
.ct-input,.ct-select{height:32px;padding:0 10px;font:inherit;font-size:12.5px;color:var(--t1);background:var(--surface);border:1px solid var(--sep-strong);border-radius:var(--r-xs);outline:none}
.ct-select{padding-right:26px;cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236e6e73' stroke-width='2.4'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center}
.ct-input:focus,.ct-select:focus{border-color:var(--blue);box-shadow:0 0 0 3px var(--blue-fill)}
.ct-toolbar{padding:10px 16px;border-bottom:1px solid var(--sep);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.ct-tabs{display:inline-flex;align-items:center;gap:4px;flex-wrap:wrap}
.ct-tab{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border-radius:var(--r-xs);font-size:12.5px;font-weight:500;color:var(--t2);text-decoration:none;border:1px solid transparent}
.ct-tab:hover{background:var(--g1);color:var(--t1)}
.ct-tab.is-active{background:var(--blue-fill);color:var(--blue);font-weight:600;border-color:rgba(0,113,227,.22)}
.ct-tab-cnt{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:18px;padding:0 6px;border-radius:100px;font-size:11px;font-weight:600;background:var(--g2);color:var(--t2);font-variant-numeric:tabular-nums}
.ct-tab.is-active .ct-tab-cnt{background:var(--blue);color:#fff}
.ct-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-left:auto}
.ct-search-wrap{position:relative;display:inline-flex;align-items:center}
.ct-search-ico{position:absolute;left:9px;width:14px;height:14px;color:var(--t3);pointer-events:none}
.ct-search-input{height:32px;width:230px;padding:0 30px 0 29px;font:inherit;font-size:12.5px;color:var(--t1);background:var(--surface-2);border:1px solid var(--sep-strong);border-radius:var(--r-xs);outline:none}
.ct-search-input:focus{background:var(--surface);border-color:var(--blue);box-shadow:0 0 0 3px var(--blue-fill)}
.ct-kbd{position:absolute;right:8px;padding:1px 5px;font-family:monospace;font-size:10.5px;color:var(--t3);background:var(--surface);border:1px solid var(--sep-strong);border-radius:4px;pointer-events:none}
.ct-search-input:focus~.ct-kbd{display:none}
.ct-view-seg{display:inline-flex;padding:2px;background:var(--g2);border-radius:var(--r-xs);border:1px solid var(--sep)}
.ct-view-btn{display:inline-flex;align-items:center;gap:5px;height:26px;padding:0 9px;border-radius:6px;font-size:12px;font-weight:500;color:var(--t2);text-decoration:none}
.ct-view-btn.is-active{background:var(--surface);color:var(--t1);font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.08)}
.ct-chips{padding:8px 16px;background:var(--surface-2);border-bottom:1px solid var(--sep);display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:12px}
.ct-chip{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:100px;background:var(--surface);border:1px solid var(--sep-strong);color:var(--t1);font-size:11.5px;font-weight:500;text-decoration:none}
.ct-chip:hover{border-color:var(--red);color:var(--red)}
.ct-chip-reset{font-size:11.5px;font-weight:600;color:var(--blue);text-decoration:none;margin-left:4px}
.ct-table-wrap{overflow-x:auto}
.ct-table{width:100%;border-collapse:collapse;min-width:920px}
.ct-table caption{text-align:left}
.ct-table thead th{position:sticky;top:0;z-index:2;background:var(--surface-2);padding:10px 14px;font-size:11px;font-weight:600;color:var(--t3);text-align:left;border-bottom:1px solid var(--sep);white-space:nowrap}
.ct-table tbody td{padding:11px 14px;font-size:12.5px;color:var(--t2);border-bottom:1px solid var(--sep);vertical-align:middle}
.ct-table tbody tr:hover td{background:#f7f7f8;color:var(--t1)}
.ct-table tbody tr:last-child td{border-bottom:none}
.ct-num{text-align:right!important;font-variant-numeric:tabular-nums}
.ct-num .ct-th-sort{justify-content:flex-end}
.ct-th-sort{display:inline-flex;align-items:center;gap:4px;color:inherit;text-decoration:none;font:inherit}
.ct-th-sort:hover{color:var(--t1)}.ct-th-sort.is-sorted{color:var(--blue)}
.ct-worker{display:flex;align-items:center;gap:10px;min-width:0}
.ct-avatar{width:32px;height:32px;min-width:32px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}
.ct-worker-name{font-size:12.5px;font-weight:600;color:var(--t1);text-decoration:none;white-space:nowrap}
.ct-worker-name:hover{color:var(--blue);text-decoration:underline}
.ct-worker-meta{font-size:11px;color:var(--t3);white-space:nowrap}
.ct-cert-name{font-size:12.5px;font-weight:600;color:var(--t1)}
.ct-sub2{font-size:11px;color:var(--t3)}
.mono{font-variant-numeric:tabular-nums}
.ct-doc-link{font-size:11.5px;font-weight:600;color:var(--blue);text-decoration:none;white-space:nowrap}
.ct-doc-link:hover{text-decoration:underline}
.ct-actions{display:flex;align-items:center;gap:6px;justify-content:flex-end;white-space:nowrap}
.ct-empty{padding:44px 20px;text-align:center}
.ct-empty-ico{width:46px;height:46px;margin:0 auto 10px;border-radius:50%;background:var(--g1);display:flex;align-items:center;justify-content:center;color:var(--t3)}
.ct-empty h2{font-size:14px;font-weight:600;color:var(--t1);margin:0 0 4px}
.ct-empty p{font-size:12.5px;color:var(--t3);margin:0 auto 12px;max-width:420px}
.ct-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:12px;padding:16px}
.ct-card{border:1px solid var(--sep);border-radius:var(--r-md);padding:14px;background:var(--surface)}
.ct-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:8px}
.ct-card-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;font-size:11.5px;margin-bottom:12px}
.ct-card-grid dt{color:var(--t3)}.ct-card-grid dd{color:var(--t1);font-weight:500;margin:0;text-align:right}
.ct-footer{padding:10px 16px;border-top:1px solid var(--sep);display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--t2)}
.ct-pages{display:inline-flex;gap:4px;margin-left:auto;flex-wrap:wrap}
.ct-page{min-width:28px;height:28px;padding:0 7px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid var(--sep);color:var(--t2);text-decoration:none;font-size:12px}
.ct-page.is-active{background:var(--blue);border-color:var(--blue);color:#fff;font-weight:600}
.ct-page.is-disabled{opacity:.4;pointer-events:none}
.sr-only{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden;clip:rect(0,0,0,0)!important;white-space:nowrap}
.ct-drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.34);z-index:600;opacity:0;pointer-events:none;transition:opacity .16s}
.ct-drawer{position:fixed;top:0;right:0;bottom:0;width:min(420px,100%);background:var(--surface);z-index:601;box-shadow:var(--shadow-float);transform:translateX(102%);transition:transform .18s ease;display:flex;flex-direction:column}
.ct-drawer.is-open{transform:none}.ct-drawer-overlay.is-open{opacity:1;pointer-events:auto}
.ct-drawer-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px;border-bottom:1px solid var(--sep)}
.ct-drawer-body{padding:16px;overflow-y:auto;flex:1}
.ct-drawer-sec{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--t3);margin:16px 0 8px}
.ct-drawer-sec:first-child{margin-top:0}
.ct-kv{display:grid;grid-template-columns:118px 1fr;gap:6px 12px;font-size:12.5px}
.ct-kv dt{color:var(--t3)}.ct-kv dd{margin:0;color:var(--t1);font-weight:500;overflow-wrap:anywhere}
.ct-drawer-foot{padding:12px 16px;border-top:1px solid var(--sep);display:flex;gap:8px;flex-wrap:wrap}
.ct-x{border:0;background:transparent;font-size:22px;line-height:1;color:var(--t3);cursor:pointer;padding:2px 6px}
.wms-dialog{border:1px solid var(--sep);border-radius:14px;padding:0;max-width:560px;width:calc(100% - 32px);background:var(--surface);color:var(--t1);box-shadow:0 24px 60px rgba(0,0,0,.28)}
.wms-dialog::backdrop{background:rgba(0,0,0,.42)}
.wms-dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid var(--sep)}
.wms-dialog-title{font-size:14px;font-weight:600}
.wms-dialog form .modal-body{padding:18px 20px}
.wms-dialog-foot{display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid var(--sep)}
@media (prefers-reduced-motion:reduce){.ct-drawer,.ct-drawer-overlay{transition:none}}
@media(max-width:1100px){.ct-kpis{grid-template-columns:repeat(3,1fr)}}
@media(max-width:760px){
 .ct-kpis{grid-template-columns:repeat(2,1fr)}
 .ct-search-input{width:100%}.ct-search-wrap{width:100%}
 .ct-table{min-width:0}
 .ct-table .ct-hide-sm{display:none}
}
@media print{
 .ct-print-banner{display:block;margin-bottom:14px;border-bottom:2px solid #1d1d1f;padding-bottom:8px}
 .ct-print-banner h1{font-size:18px;margin:0}.ct-print-banner p{font-size:11px;color:#444;margin:2px 0 0}
 .no-print{display:none!important}
 .ct-shell,.ct-kpi{box-shadow:none!important}
}
</style>

<div class="ct-print-banner">
  <h2><?= $can_view_all ? 'Certifications Tracker' : 'My Certifications' ?> — Certificate Register</h2>
  <p><?= (int)$summary['total'] ?> certificate(s) in scope · <?= (int)$summary['expired'] ?> expired · <?= (int)$summary['expiring'] ?> expiring within <?= $alert_days ?> days · Printed <?= date('d M Y H:i') ?></p>
</div>

<!-- Page Header -->
<div class="ct-head no-print">
  <div>
    <div class="ct-crumb"><a href="<?= site_url('team') ?>">Team</a> <span aria-hidden="true">/</span> Certifications</div>
    <h1><?= $can_view_all ? 'Certifications Tracker' : 'My Certifications' ?></h1>
    <p class="ct-sub">
      <?= $can_view_all
        ? 'Certificate compliance for ' . (int)$summary['workers'] . ' active worker(s)'
        : 'Your recorded certificates and renewal dates' ?>
      · Alert window <?= $alert_days ?> days · Statuses refreshed from expiry dates
    </p>
  </div>
  <div class="ct-head-actions">
    <a href="<?= htmlspecialchars($export_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost btn-sm" title="Export the filtered certificate register as CSV">
      <svg class="ct-btn-ico" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-2px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export CSV
    </a>
    <a href="<?= site_url($can_view_all ? 'team' : ('team/view/' . (int)$this->session->userdata('user_id'))) ?>" class="btn btn-ghost btn-sm">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-2px;"><polyline points="15 18 9 12 15 6"/></svg>
      <?= $can_view_all ? 'Back to Team' : 'My Profile' ?>
    </a>
  </div>
</div>

<!-- Executive KPI Strip -->
<section class="ct-kpis no-print" aria-label="Certification summary">
  <div class="ct-kpi"><div class="ct-kpi-lbl">Certificates in Scope</div><div class="ct-kpi-val"><?= (int)$summary['total'] ?></div><div class="ct-kpi-meta"><?= (int)$summary['workers'] ?> worker(s) in scope</div></div>
  <div class="ct-kpi"><div class="ct-kpi-lbl">Valid</div><div class="ct-kpi-val" style="color:var(--green);"><?= (int)$summary['valid'] ?></div><div class="ct-kpi-meta"><?= $valid_pct === NULL ? 'No dated certificates' : ($valid_pct . '% of dated register beyond alert window') ?></div></div>
  <div class="ct-kpi"><div class="ct-kpi-lbl">Expiring Soon</div><div class="ct-kpi-val" style="color:var(--amber);"><?= (int)$summary['expiring'] ?></div><div class="ct-kpi-meta">Within <?= $alert_days ?> days</div></div>
  <div class="ct-kpi"><div class="ct-kpi-lbl">Expired</div><div class="ct-kpi-val" style="color:<?= (int)$summary['expired'] > 0 ? 'var(--red)' : 'var(--t1)' ?>;"><?= (int)$summary['expired'] ?></div><div class="ct-kpi-meta"><?= (int)$summary['expired'] > 0 ? 'Immediate renewal action required' : 'No lapsed certificates' ?></div></div>
  <div class="ct-kpi"><div class="ct-kpi-lbl">No Certificates</div><div class="ct-kpi-val" style="color:<?= (int)$summary['none'] > 0 ? 'var(--amber)' : 'var(--t1)' ?>;"><?= (int)$summary['none'] ?></div><div class="ct-kpi-meta">Worker(s) with nothing recorded</div></div>
</section>

<div class="ct-shell">
  <!-- Scope Filters -->
  <form method="get" action="<?= site_url('team/certifications') ?>" id="ctFilterForm" class="no-print">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="dir" value="<?= htmlspecialchars($sort_dir, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode, ENT_QUOTES, 'UTF-8') ?>">
    <div class="ct-scope">
      <div class="ct-field">
        <label for="ctCertFilter">Certificate</label>
        <select name="cert" id="ctCertFilter" class="ct-select ct-autosubmit" aria-label="Filter by certificate name">
          <option value="">All certificates</option>
          <?php foreach ($cert_types as $ct): ?>
          <option value="<?= htmlspecialchars($ct, ENT_QUOTES, 'UTF-8') ?>" <?= $cert_filter === $ct ? 'selected' : '' ?>><?= htmlspecialchars($ct, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ct-field">
        <label for="ctDocFilter">Document</label>
        <select name="doc" id="ctDocFilter" class="ct-select ct-autosubmit" aria-label="Filter by document">
          <option value="" <?= $doc_filter === '' ? 'selected' : '' ?>>Any document state</option>
          <option value="with" <?= $doc_filter === 'with' ? 'selected' : '' ?>>With document</option>
          <option value="without" <?= $doc_filter === 'without' ? 'selected' : '' ?>>Without document</option>
        </select>
      </div>
      <div class="ct-field">
        <label for="ctSearch">Search</label>
        <span class="ct-search-wrap">
          <svg class="ct-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search" name="q" id="ctSearch" value="<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>"
                 placeholder="Worker, ID, certificate, number…"
                 aria-label="Search by worker, employee ID, certificate name, number, or issuing body"
                 class="ct-search-input" autocomplete="off">
          <kbd class="ct-kbd" aria-hidden="true">/</kbd>
        </span>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <?php if ($has_filters): ?>
      <a href="<?= site_url('team/certifications') ?>" class="btn btn-ghost btn-sm">Reset all</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Status Tabs + View Toggle -->
  <div class="ct-toolbar no-print">
    <nav class="ct-tabs" role="tablist" aria-label="Certification status categories">
      <?php foreach ($tab_defs as $key => $def): $is_on = ($active_tab === $key); ?>
      <a href="<?= htmlspecialchars($ct_url(array('tab' => $key, 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>"
         class="ct-tab <?= $is_on ? 'is-active' : '' ?>" role="tab" id="ct-tab-<?= $key ?>"
         aria-selected="<?= $is_on ? 'true' : 'false' ?>"><?= htmlspecialchars($def['label'], ENT_QUOTES, 'UTF-8') ?> <span class="ct-tab-cnt"><?= (int)$def['count'] ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="ct-tools">
      <div class="ct-view-seg" role="group" aria-label="Display layout">
        <a href="<?= htmlspecialchars($ct_url(array('view' => 'table')), ENT_QUOTES, 'UTF-8') ?>" class="ct-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>" aria-pressed="<?= $view_mode === 'table' ? 'true' : 'false' ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg> Table
        </a>
        <a href="<?= htmlspecialchars($ct_url(array('view' => 'cards')), ENT_QUOTES, 'UTF-8') ?>" class="ct-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>" aria-pressed="<?= $view_mode === 'cards' ? 'true' : 'false' ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg> Cards
        </a>
      </div>
    </div>
  </div>

  <!-- Active Filter Chips -->
  <?php if ($has_filters): ?>
  <div class="ct-chips no-print" aria-label="Active filters">
    <span style="color:var(--t3);font-weight:500;">Filters:</span>
    <?php if ($search_query !== ''): ?><a class="ct-chip" href="<?= htmlspecialchars($ct_url(array('q' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>" aria-label="Remove search filter">Search: <?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?> <span aria-hidden="true">×</span></a><?php endif; ?>
    <?php if ($cert_filter !== ''): ?><a class="ct-chip" href="<?= htmlspecialchars($ct_url(array('cert' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>" aria-label="Remove certificate filter">Certificate: <?= htmlspecialchars($cert_filter, ENT_QUOTES, 'UTF-8') ?> <span aria-hidden="true">×</span></a><?php endif; ?>
    <?php if ($doc_filter !== ''): ?><a class="ct-chip" href="<?= htmlspecialchars($ct_url(array('doc' => '', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>" aria-label="Remove document filter">Document: <?= $doc_filter === 'with' ? 'With document' : 'Without document' ?> <span aria-hidden="true">×</span></a><?php endif; ?>
    <?php if ($active_tab !== 'all'): ?><a class="ct-chip" href="<?= htmlspecialchars($ct_url(array('tab' => 'all', 'page' => 1)), ENT_QUOTES, 'UTF-8') ?>" aria-label="Remove status tab filter">Status: <?= htmlspecialchars($tab_defs[$active_tab]['label'], ENT_QUOTES, 'UTF-8') ?> <span aria-hidden="true">×</span></a><?php endif; ?>
    <a class="ct-chip-reset" href="<?= site_url('team/certifications') ?>">Reset all</a>
  </div>
  <?php endif; ?>

  <?php if ($total_filtered === 0): ?>
  <div class="ct-empty">
    <div class="ct-empty-ico" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg></div>
    <?php if ($is_none_mode): ?>
      <h2>Every worker in scope has a certificate recorded</h2>
      <p>No worker matches “None Recorded”<?= $search_query !== '' ? ' for this search' : '' ?>. This does not confirm each worker holds the certificates their role requires — only that at least one record exists.</p>
    <?php elseif (!$has_filters && (int)$summary['total'] === 0): ?>
      <h2>No certificates recorded yet</h2>
      <p><?= !empty($is_supervisor) ? 'Open a worker profile to add their first certificate.' : 'Your supervisor adds certificates to your profile. Ask them if a record is missing.' ?></p>
    <?php else: ?>
      <h2>No certificates match the current filters</h2>
      <p>Try a different search or reset the filters to see the full register.</p>
      <a href="<?= site_url('team/certifications') ?>" class="btn btn-ghost btn-sm">Reset all filters</a>
    <?php endif; ?>
  </div>

  <?php elseif ($is_none_mode): ?>
  <!-- Workers with no certificates recorded -->
  <?php if ($view_mode === 'cards'): ?>
  <div class="ct-cards">
    <?php foreach ($worker_records as $w): ?>
    <article class="ct-card">
      <div class="ct-card-top">
        <div class="ct-worker">
          <span class="ct-avatar" style="background:<?= $avatar_palette[(int)$w->id % count($avatar_palette)] ?>;" aria-hidden="true"><?= htmlspecialchars($initials_of($w->full_name), ENT_QUOTES, 'UTF-8') ?></span>
          <div><div class="ct-cert-name"><?= htmlspecialchars($w->full_name, ENT_QUOTES, 'UTF-8') ?></div><div class="ct-sub2"><?= htmlspecialchars((string)$w->employee_id, ENT_QUOTES, 'UTF-8') ?><?= !empty($w->position) ? ' · ' . htmlspecialchars((string)$w->position, ENT_QUOTES, 'UTF-8') : '' ?></div></div>
        </div>
        <span class="badge b-warning">None recorded</span>
      </div>
      <div class="ct-actions" style="justify-content:flex-start;">
        <a href="<?= site_url('team/view/' . (int)$w->id) ?>" class="btn btn-primary btn-sm"><?= !empty($is_supervisor) ? 'Add Certificates' : 'View Profile' ?></a>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="ct-table-wrap">
    <table class="ct-table">
      <caption class="sr-only">Workers with no certificates recorded</caption>
      <thead><tr><th scope="col">Worker</th><th scope="col">Employee ID</th><th scope="col" class="ct-hide-sm">Position</th><th scope="col">Certificates</th><th scope="col" style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($worker_records as $w): ?>
        <tr>
          <td><div class="ct-worker"><span class="ct-avatar" style="background:<?= $avatar_palette[(int)$w->id % count($avatar_palette)] ?>;" aria-hidden="true"><?= htmlspecialchars($initials_of($w->full_name), ENT_QUOTES, 'UTF-8') ?></span><a class="ct-worker-name" href="<?= site_url('team/view/' . (int)$w->id) ?>"><?= htmlspecialchars($w->full_name, ENT_QUOTES, 'UTF-8') ?></a></div></td>
          <td class="mono"><?= htmlspecialchars((string)$w->employee_id, ENT_QUOTES, 'UTF-8') ?></td>
          <td class="ct-hide-sm"><?= htmlspecialchars((string)(isset($w->position) ? $w->position : ''), ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
          <td><span class="badge b-warning">None recorded</span></td>
          <td><div class="ct-actions"><a href="<?= site_url('team/view/' . (int)$w->id) ?>" class="btn btn-primary btn-sm"><?= !empty($is_supervisor) ? 'Add Certificates' : 'View Profile' ?></a></div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php elseif ($view_mode === 'cards'): ?>
  <div class="ct-cards">
    <?php foreach ($records as $c): list($bcls, $blabel) = $badge_of($c); $ext = !empty($c->document) ? strtoupper(pathinfo((string)$c->document, PATHINFO_EXTENSION)) : ''; ?>
    <article class="ct-card">
      <div class="ct-card-top">
        <div class="ct-worker">
          <span class="ct-avatar" style="background:<?= $avatar_palette[(int)$c->user_id % count($avatar_palette)] ?>;" aria-hidden="true"><?= htmlspecialchars($initials_of($c->worker_name), ENT_QUOTES, 'UTF-8') ?></span>
          <div><a class="ct-worker-name" href="<?= site_url('team/view/' . (int)$c->user_id) ?>"><?= htmlspecialchars((string)$c->worker_name, ENT_QUOTES, 'UTF-8') ?></a><div class="ct-sub2"><?= htmlspecialchars((string)$c->employee_id, ENT_QUOTES, 'UTF-8') ?></div></div>
        </div>
        <span class="badge <?= $bcls ?>"><?= $blabel ?></span>
      </div>
      <div class="ct-cert-name" style="margin-bottom:8px;"><?= htmlspecialchars((string)$c->cert_name, ENT_QUOTES, 'UTF-8') ?></div>
      <dl class="ct-card-grid">
        <dt>Expiry</dt><dd><?= !empty($c->expiry_date) ? date('d M Y', strtotime($c->expiry_date)) : '—' ?> (<?= $days_text($c) ?>)</dd>
        <dt>Issued</dt><dd><?= !empty($c->issued_date) ? date('d M Y', strtotime($c->issued_date)) : '—' ?></dd>
        <dt>Number</dt><dd class="mono"><?= htmlspecialchars((string)(!empty($c->cert_number) ? $c->cert_number : '—'), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>Issuing body</dt><dd><?= htmlspecialchars((string)(!empty($c->issuing_body) ? $c->issuing_body : '—'), ENT_QUOTES, 'UTF-8') ?></dd>
      </dl>
      <div class="ct-actions" style="justify-content:flex-start;">
        <button type="button" class="btn btn-ghost btn-sm" data-cert-inspect="<?= (int)$c->id ?>" aria-label="Inspect <?= htmlspecialchars((string)$c->cert_name, ENT_QUOTES, 'UTF-8') ?> for <?= htmlspecialchars((string)$c->worker_name, ENT_QUOTES, 'UTF-8') ?>">Inspect</button>
        <a href="<?= site_url('team/view/' . (int)$c->user_id) ?>" class="btn btn-ghost btn-sm">Profile</a>
        <?php if (!empty($c->document)): ?><a class="ct-doc-link" href="<?= base_url(htmlspecialchars((string)$c->document, ENT_QUOTES, 'UTF-8')) ?>" target="_blank" rel="noopener" aria-label="Open <?= htmlspecialchars((string)$c->cert_name, ENT_QUOTES, 'UTF-8') ?> document<?= $ext !== '' ? ' (' . $ext . ')' : '' ?>">Open<?= $ext !== '' ? ' ' . $ext : ' document' ?></a><?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>

  <?php else: ?>
  <div class="ct-table-wrap">
    <table class="ct-table">
      <caption class="sr-only">Certificate register — <?= htmlspecialchars($tab_defs[$active_tab]['label'], ENT_QUOTES, 'UTF-8') ?></caption>
      <thead>
        <tr>
          <?= $sort_th('worker', 'Worker') ?>
          <?= $sort_th('certificate', 'Certificate') ?>
          <th scope="col" class="ct-hide-sm">Issuing Body</th>
          <?= str_replace('<th scope="col"', '<th scope="col" class="ct-hide-sm"', $sort_th('issued', 'Issued')) ?>
          <?= $sort_th('expiry', 'Expiry') ?>
          <th scope="col" class="ct-num">Days Left</th>
          <?= $sort_th('status', 'Status', 'left', 'asc') ?>
          <th scope="col">Document</th>
          <th scope="col" style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $c): list($bcls, $blabel) = $badge_of($c); $d = $days_of($c); $ext = !empty($c->document) ? strtoupper(pathinfo((string)$c->document, PATHINFO_EXTENSION)) : ''; ?>
        <tr>
          <td>
            <div class="ct-worker">
              <span class="ct-avatar" style="background:<?= $avatar_palette[(int)$c->user_id % count($avatar_palette)] ?>;" aria-hidden="true"><?= htmlspecialchars($initials_of($c->worker_name), ENT_QUOTES, 'UTF-8') ?></span>
              <div>
                <a class="ct-worker-name" href="<?= site_url('team/view/' . (int)$c->user_id) ?>"><?= htmlspecialchars((string)$c->worker_name, ENT_QUOTES, 'UTF-8') ?></a>
                <div class="ct-worker-meta"><?= htmlspecialchars((string)$c->employee_id, ENT_QUOTES, 'UTF-8') ?><?= !empty($c->position) ? ' · ' . htmlspecialchars((string)$c->position, ENT_QUOTES, 'UTF-8') : '' ?></div>
              </div>
            </div>
          </td>
          <td><div class="ct-cert-name"><?= htmlspecialchars((string)$c->cert_name, ENT_QUOTES, 'UTF-8') ?></div><?php if (!empty($c->cert_number)): ?><div class="ct-sub2 mono"><?= htmlspecialchars((string)$c->cert_number, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?></td>
          <td class="ct-hide-sm"><?= htmlspecialchars((string)(!empty($c->issuing_body) ? $c->issuing_body : '—'), ENT_QUOTES, 'UTF-8') ?></td>
          <td class="ct-hide-sm" style="white-space:nowrap;"><?= !empty($c->issued_date) ? date('d M Y', strtotime($c->issued_date)) : '—' ?></td>
          <td style="white-space:nowrap;"><?= !empty($c->expiry_date) ? date('d M Y', strtotime($c->expiry_date)) : '—' ?></td>
          <td class="ct-num" style="font-weight:600;color:<?= $d === NULL ? 'var(--t3)' : ($d < 0 ? 'var(--red)' : ($d <= $alert_days ? 'var(--amber)' : 'var(--t2)')) ?>;"><?= $days_text($c) ?></td>
          <td><span class="badge <?= $bcls ?>"><?= $blabel ?></span></td>
          <td>
            <?php if (!empty($c->document)): ?>
              <a class="ct-doc-link" href="<?= base_url(htmlspecialchars((string)$c->document, ENT_QUOTES, 'UTF-8')) ?>" target="_blank" rel="noopener" aria-label="Open <?= htmlspecialchars((string)$c->cert_name, ENT_QUOTES, 'UTF-8') ?> document for <?= htmlspecialchars((string)$c->worker_name, ENT_QUOTES, 'UTF-8') ?><?= $ext !== '' ? ' (' . $ext . ')' : '' ?>">Open<?= $ext !== '' ? ' ' . $ext : '' ?></a>
            <?php else: ?>
              <span style="color:var(--t3);font-size:11.5px;">No document</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="ct-actions">
              <button type="button" class="btn btn-ghost btn-sm" data-cert-inspect="<?= (int)$c->id ?>" aria-label="Inspect <?= htmlspecialchars((string)$c->cert_name, ENT_QUOTES, 'UTF-8') ?> for <?= htmlspecialchars((string)$c->worker_name, ENT_QUOTES, 'UTF-8') ?>">Inspect</button>
              <a href="<?= site_url('team/view/' . (int)$c->user_id) ?>" class="btn btn-ghost btn-sm" aria-label="Open profile of <?= htmlspecialchars((string)$c->worker_name, ENT_QUOTES, 'UTF-8') ?>">Profile</a>
              <?php if (!empty($is_supervisor)): ?>
              <button type="button" class="btn btn-ghost btn-sm" data-cert-edit="<?= (int)$c->id ?>" aria-label="Renew or edit <?= htmlspecialchars((string)$c->cert_name, ENT_QUOTES, 'UTF-8') ?> for <?= htmlspecialchars((string)$c->worker_name, ENT_QUOTES, 'UTF-8') ?>">Renew</button>
              <?= form_open('team/delete_certification/' . (int)$c->id, array('style' => 'display:inline;')) ?>
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red);" aria-label="Delete <?= htmlspecialchars((string)$c->cert_name, ENT_QUOTES, 'UTF-8') ?> for <?= htmlspecialchars((string)$c->worker_name, ENT_QUOTES, 'UTF-8') ?>"
                        onclick="return confirm('Delete this certification? The attached document is removed too.');">Delete</button>
              <?= form_close() ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Pagination Footer -->
  <?php if ($total_filtered > 0): ?>
  <div class="ct-footer no-print">
    <span>Showing <strong class="mono"><?= (int)$showing_from ?>–<?= (int)$showing_to ?></strong> of <strong class="mono"><?= (int)$total_filtered ?></strong> <?= $is_none_mode ? 'worker(s)' : 'record(s)' ?></span>
    <form method="get" action="<?= site_url('team/certifications') ?>" style="display:inline-flex;align-items:center;gap:6px;">
      <input type="hidden" name="q" value="<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="cert" value="<?= htmlspecialchars($cert_filter, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="doc" value="<?= htmlspecialchars($doc_filter, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="dir" value="<?= htmlspecialchars($sort_dir, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode, ENT_QUOTES, 'UTF-8') ?>">
      <label for="ctPerPage" style="font-size:11.5px;color:var(--t3);">Show</label>
      <select name="per_page" id="ctPerPage" class="ct-select ct-autosubmit" style="height:28px;" aria-label="Records per page" onchange="this.form.submit()">
        <?php foreach (array(10, 25, 50, 100) as $pp): ?><option value="<?= $pp ?>" <?= $per_page === $pp ? 'selected' : '' ?>><?= $pp ?></option><?php endforeach; ?>
      </select>
    </form>
    <?php if ($total_pages > 1): ?>
    <nav class="ct-pages" aria-label="Pagination">
      <a class="ct-page <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars($ct_url(array('page' => max(1, $page - 1))), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">‹</a>
      <?php
      $win = array();
      for ($p = 1; $p <= $total_pages; $p++) {
          if ($p === 1 || $p === $total_pages || abs($p - $page) <= 1) $win[] = $p;
      }
      $win = array_unique($win); sort($win); $prev = 0;
      foreach ($win as $p):
          if ($prev && $p - $prev > 1) echo '<span class="ct-page is-disabled" aria-hidden="true">…</span>';
          $prev = $p;
      ?>
      <a class="ct-page <?= $p === $page ? 'is-active' : '' ?>" href="<?= htmlspecialchars($ct_url(array('page' => $p)), ENT_QUOTES, 'UTF-8') ?>" <?= $p === $page ? 'aria-current="page"' : '' ?> aria-label="Page <?= $p ?>"><?= $p ?></a>
      <?php endforeach; ?>
      <a class="ct-page <?= $page >= $total_pages ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars($ct_url(array('page' => min($total_pages, $page + 1))), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">›</a>
    </nav>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Certificate Inspector Drawer -->
<div class="ct-drawer-overlay no-print" id="ctDrawerOverlay" hidden></div>
<aside class="ct-drawer no-print" id="ctDrawer" role="dialog" aria-modal="false" aria-labelledby="ctDrawerTitle" aria-hidden="true">
  <div class="ct-drawer-head">
    <div>
      <div class="ct-sub2" id="ctDrawerWorker">Certificate</div>
      <div id="ctDrawerTitle" style="font-size:16px;font-weight:700;color:var(--t1);">Loading…</div>
    </div>
    <button type="button" class="ct-x" id="ctDrawerClose" aria-label="Close inspector">×</button>
  </div>
  <div class="ct-drawer-body" id="ctDrawerBody">
    <p style="font-size:12.5px;color:var(--t3);">Loading certificate…</p>
  </div>
  <div class="ct-drawer-foot">
    <button type="button" class="btn btn-ghost btn-sm" id="ctDrawerPrev">‹ Prev</button>
    <button type="button" class="btn btn-ghost btn-sm" id="ctDrawerNext">Next ›</button>
    <span style="flex:1;"></span>
    <a href="#" class="btn btn-ghost btn-sm" id="ctDrawerDoc" target="_blank" rel="noopener" hidden>Open Document</a>
    <?php if (!empty($is_supervisor)): ?>
    <button type="button" class="btn btn-primary btn-sm" id="ctDrawerRenew">Renew / Edit</button>
    <?php endif; ?>
    <a href="#" class="btn btn-primary btn-sm" id="ctDrawerProfile">Open Profile</a>
  </div>
</aside>

<?php if (!empty($is_supervisor)): ?>
<!-- Renew / Edit Certification dialog (posts to Team::edit_certification) -->
<dialog id="ctEditDialog" class="wms-dialog" aria-labelledby="ctEditTitle">
  <div class="wms-dialog-head">
    <span class="wms-dialog-title" id="ctEditTitle">Renew / Edit Certification</span>
    <button type="button" class="ct-x" id="ctEditClose" aria-label="Close">×</button>
  </div>
  <?= form_open_multipart('', array('id' => 'ctEditForm')) ?>
    <input type="hidden" name="return_to" value="tracker">
    <input type="hidden" name="tracker_qs" value="<?= htmlspecialchars($tracker_qs, ENT_QUOTES, 'UTF-8') ?>">
    <div class="modal-body">
      <div style="font-size:12px;color:var(--t2);margin-bottom:12px;" id="ctEditWorker"></div>
      <div class="row g-2" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div style="grid-column:1/-1;"><label class="form-label" for="ctEditName">Certificate Name *</label><input type="text" id="ctEditName" name="cert_name" class="form-control" required list="ctCertNames" placeholder="e.g. Forklift Operator License"><datalist id="ctCertNames"><?php foreach ($cert_types as $ctn): ?><option value="<?= htmlspecialchars($ctn, ENT_QUOTES, 'UTF-8') ?>"></option><?php endforeach; ?></datalist></div>
        <div><label class="form-label" for="ctEditNumber">Certificate Number</label><input type="text" id="ctEditNumber" name="cert_number" class="form-control"></div>
        <div><label class="form-label" for="ctEditBody">Issuing Body</label><input type="text" id="ctEditBody" name="issuing_body" class="form-control"></div>
        <div><label class="form-label" for="ctEditIssued">Issued Date</label><input type="date" id="ctEditIssued" name="issued_date" class="form-control"></div>
        <div><label class="form-label" for="ctEditExpiry">Expiry Date</label><input type="date" id="ctEditExpiry" name="expiry_date" class="form-control"></div>
        <div style="grid-column:1/-1;">
          <label class="form-label" for="ctEditDoc">Replacement Document <span style="font-weight:400;color:var(--t3);">(PDF / JPG / PNG, max 8 MB — leave empty to keep current)</span></label>
          <input type="file" id="ctEditDoc" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
          <div style="font-size:11.5px;color:var(--t3);margin-top:4px;" id="ctEditCurrentDoc"></div>
          <label style="display:flex;gap:6px;align-items:center;font-size:12px;margin-top:6px;"><input type="checkbox" name="remove_document" value="1" id="ctEditRemoveDoc"> Remove current document without replacement</label>
        </div>
      </div>
    </div>
    <div class="wms-dialog-foot">
      <button type="button" class="btn btn-ghost btn-sm" id="ctEditCancel">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
    </div>
  <?= form_close() ?>
</dialog>
<?php endif; ?>


<script>
(function () {
  var form = document.getElementById('ctFilterForm');
  if (!form) return;
  form.querySelectorAll('.ct-autosubmit').forEach(function (el) {
    el.addEventListener('change', function () { form.submit(); });
  });

  /* ── Certificate inspector drawer + renew/edit ─────────────────── */
  var drawer = document.getElementById('ctDrawer');
  if (drawer) {
    var overlay = document.getElementById('ctDrawerOverlay');
    var bodyEl = document.getElementById('ctDrawerBody');
    var titleEl = document.getElementById('ctDrawerTitle');
    var workerEl = document.getElementById('ctDrawerWorker');
    var profileBtn = document.getElementById('ctDrawerProfile');
    var docBtn = document.getElementById('ctDrawerDoc');
    var renewBtn = document.getElementById('ctDrawerRenew');
    var prevBtn = document.getElementById('ctDrawerPrev');
    var nextBtn = document.getElementById('ctDrawerNext');
    var cache = {};
    var currentId = null;
    var ids = Array.prototype.map.call(document.querySelectorAll('[data-cert-inspect]'), function (b) { return parseInt(b.getAttribute('data-cert-inspect'), 10); });

    function esc(t) { var d = document.createElement('div'); d.textContent = String(t == null ? '' : t); return d.innerHTML; }
    function daysText(c) {
      if (c.days_left === null) return 'No expiry date recorded';
      if (c.days_left < 0) return Math.abs(c.days_left) + ' day(s) overdue';
      if (c.days_left === 0) return 'Expires today';
      return 'Expires in ' + c.days_left + ' day(s)';
    }
    function openDrawer(id) {
      currentId = id;
      drawer.classList.add('is-open'); drawer.setAttribute('aria-hidden', 'false');
      overlay.hidden = false; overlay.classList.add('is-open');
      titleEl.textContent = 'Loading…'; bodyEl.innerHTML = '<p style="font-size:12.5px;color:var(--t3);">Loading certificate…</p>';
      prevBtn.disabled = ids.indexOf(id) <= 0;
      nextBtn.disabled = ids.indexOf(id) === -1 || ids.indexOf(id) >= ids.length - 1;
      var url = <?= json_encode(site_url('team/certifications_json/')) ?> + id;
      var render = function (c) {
        titleEl.textContent = c.cert_name;
        workerEl.textContent = c.worker_name + (c.employee_id ? ' · ' + c.employee_id : '');
        profileBtn.href = c.profile_url;
        if (c.document_url) { docBtn.href = c.document_url; docBtn.hidden = false; } else { docBtn.hidden = true; }
        if (renewBtn) { renewBtn.setAttribute('data-cert-edit', String(c.id)); renewBtn.disabled = !c.can_edit; }
        bodyEl.innerHTML =
          '<div class="ct-drawer-sec">Status</div>' +
          '<dl class="ct-kv"><dt>Status</dt><dd>' + esc(c.status) + '</dd><dt>Expiry</dt><dd>' + esc(c.expiry_date || '—') + '</dd><dt>Remaining</dt><dd>' + esc(daysText(c)) + '</dd></dl>' +
          '<div class="ct-drawer-sec">Certificate</div>' +
          '<dl class="ct-kv"><dt>Number</dt><dd>' + esc(c.cert_number || '—') + '</dd><dt>Issuing body</dt><dd>' + esc(c.issuing_body || '—') + '</dd><dt>Issued</dt><dd>' + esc(c.issued_date || '—') + '</dd><dt>Document</dt><dd>' + (c.document_url ? '<a href="' + esc(c.document_url) + '" target="_blank" rel="noopener">Open document</a>' : 'No document on file') + '</dd></dl>' +
          '<div class="ct-drawer-sec">Worker</div>' +
          '<dl class="ct-kv"><dt>Name</dt><dd>' + esc(c.worker_name) + '</dd><dt>Employee ID</dt><dd>' + esc(c.employee_id || '—') + '</dd><dt>Position</dt><dd>' + esc(c.position || '—') + '</dd></dl>';
      };
      if (cache[id]) { render(cache[id]); return; }
      fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (c) { if (!c.ok) throw new Error(c.error || 'Not available'); cache[id] = c; if (currentId === id) render(c); })
        .catch(function (e) { if (currentId === id) bodyEl.innerHTML = '<p style="font-size:12.5px;color:var(--red);">' + esc(e.message) + '</p>'; });
    }
    function closeDrawer() {
      drawer.classList.remove('is-open'); drawer.setAttribute('aria-hidden', 'true');
      overlay.classList.remove('is-open'); overlay.hidden = true; currentId = null;
    }
    document.querySelectorAll('[data-cert-inspect]').forEach(function (b) {
      b.addEventListener('click', function () { openDrawer(parseInt(b.getAttribute('data-cert-inspect'), 10)); });
    });
    document.getElementById('ctDrawerClose').addEventListener('click', closeDrawer);
    overlay.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && currentId !== null) closeDrawer(); });
    prevBtn.addEventListener('click', function () { var i = ids.indexOf(currentId); if (i > 0) openDrawer(ids[i - 1]); });
    nextBtn.addEventListener('click', function () { var i = ids.indexOf(currentId); if (i >= 0 && i < ids.length - 1) openDrawer(ids[i + 1]); });

    /* Renew / Edit dialog */
    var editDialog = document.getElementById('ctEditDialog');
    if (editDialog) {
      var editForm = document.getElementById('ctEditForm');
      var fill = function (c) {
        editForm.action = <?= json_encode(site_url('team/edit_certification/')) ?> + c.id;
        document.getElementById('ctEditWorker').textContent = c.worker_name + (c.employee_id ? ' · ' + c.employee_id : '');
        document.getElementById('ctEditName').value = c.cert_name || '';
        document.getElementById('ctEditNumber').value = c.cert_number || '';
        document.getElementById('ctEditBody').value = c.issuing_body || '';
        document.getElementById('ctEditIssued').value = c.issued_raw || '';
        document.getElementById('ctEditExpiry').value = c.expiry_raw || '';
        document.getElementById('ctEditDoc').value = '';
        document.getElementById('ctEditRemoveDoc').checked = false;
        document.getElementById('ctEditCurrentDoc').textContent = c.document_name ? ('Current document: ' + c.document_name + ' — uploading a replacement removes the old file after saving.') : 'No document currently on file.';
        if (typeof editDialog.showModal === 'function') editDialog.showModal();
      };
      var openEdit = function (id) {
        if (cache[id]) { fill(cache[id]); return; }
        fetch(<?= json_encode(site_url('team/certifications_json/')) ?> + id, { headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (c) { if (c.ok) { cache[id] = c; fill(c); } });
      };
      document.querySelectorAll('[data-cert-edit]').forEach(function (b) {
        b.addEventListener('click', function () { openEdit(parseInt(b.getAttribute('data-cert-edit'), 10)); });
      });
      if (renewBtn) renewBtn.addEventListener('click', function () { if (currentId !== null) { closeDrawer(); openEdit(currentId); } });
      document.getElementById('ctEditClose').addEventListener('click', function () { editDialog.close(); });
      document.getElementById('ctEditCancel').addEventListener('click', function () { editDialog.close(); });
    }
  }
  var search = document.getElementById('ctSearch');
  if (search) {
    var timer = null;
    search.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { form.submit(); }, 450);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
        e.preventDefault(); search.focus();
      }
    });
  }
})();
</script>
