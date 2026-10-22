<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enterprise Fleet Inspection Register & Bilingual Checklist Hub (`/inspections`)
 * Apple-HIG clean enterprise surface with zero horizontal overflow at 1440x900,
 * automatic responsive Mobile Card View (< 768px), Slide-Over Checklist & Defect
 * Inspector Drawer (`#inDrawer`), quick `+ New Inspection` template launcher modal,
 * full Bilingual EN/FR localization, RBAC action gating, and UTF-8 BOM CSV export.
 */
$lang            = (isset($lang) && $lang === 'fr') ? 'fr' : 'en';
$L               = function ($en, $fr) use ($lang) { return $lang === 'fr' ? $fr : $en; };
$h               = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$records         = isset($records) && is_array($records) ? $records : array();
$templates       = isset($templates) && is_array($templates) ? $templates : array();
$record_counts   = isset($record_counts) && is_array($record_counts) ? $record_counts : array();
$equipment_list  = isset($equipment_list) && is_array($equipment_list) ? $equipment_list : array();
$focusedTpl      = isset($focused_template) ? $focused_template : NULL;
$forcedCode      = isset($forced_code) && $forced_code !== NULL && $forced_code !== '' ? (string)$forced_code : NULL;

$kpi             = isset($kpi) && is_array($kpi) ? $kpi : array(
    'total_records' => 0, 'today_records' => 0, 'p1_nogo' => 0, 'failed_records' => 0,
    'total_failed_items' => 0, 'clean_pass' => 0, 'pass_rate' => 100, 'unsigned' => 0,
    'drafts' => 0, 'active_templates' => count($templates), 'total_items' => 0,
);
$tab_counts      = isset($tab_counts) && is_array($tab_counts) ? $tab_counts : array(
    'all' => count($records), 'p1_nogo' => 0, 'failed' => 0, 'passed' => 0,
    'unsigned' => 0, 'draft' => 0, 'forms' => count($templates),
);
$tab             = isset($tab) ? (string)$tab : 'all';
$q               = isset($q) ? (string)$q : '';
$f_template      = isset($f_template) ? (string)$f_template : '';
$f_equip         = isset($f_equip) ? (int)$f_equip : 0;
$f_rec_lang      = isset($f_rec_lang) ? (string)$f_rec_lang : '';
$f_from          = isset($f_from) ? (string)$f_from : '';
$f_to            = isset($f_to) ? (string)$f_to : '';
$sort            = isset($sort) ? (string)$sort : 'inspection_date';
$dir             = isset($dir) && $dir === 'asc' ? 'asc' : 'desc';
$view_mode       = isset($view_mode) && $view_mode === 'cards' ? 'cards' : 'table';
$per_page        = isset($per_page) ? (int)$per_page : 25;
$allowed_pp      = isset($allowed_pp) && is_array($allowed_pp) ? $allowed_pp : array(10, 25, 50, 100, 150);
$current_page    = isset($current_page) ? max(1, (int)$current_page) : 1;
$total_pages     = isset($total_pages) ? max(1, (int)$total_pages) : 1;
$total_filtered  = isset($total_filtered) ? (int)$total_filtered : count($records);
$offset          = isset($offset) ? (int)$offset : 0;
$can_manage      = !empty($can_manage);
$can_submit      = isset($can_submit) ? !empty($can_submit) : TRUE;

$baseRoute = $forcedCode ? ('inspections/records/' . rawurlencode($forcedCode)) : 'inspections';

$baseParams = array('lang' => $lang);
if ($tab !== 'all')            $baseParams['tab']          = $tab;
if ($q !== '')                 $baseParams['q']            = $q;
if (!$forcedCode && $f_template !== '') $baseParams['template'] = $f_template;
if ($f_equip > 0)              $baseParams['equipment_id'] = $f_equip;
if ($f_rec_lang !== '')        $baseParams['rec_lang']     = $f_rec_lang;
if ($f_from !== '')            $baseParams['date_from']    = $f_from;
if ($f_to !== '')              $baseParams['date_to']      = $f_to;
if ($sort !== 'inspection_date' || $dir !== 'desc') {
    $baseParams['sort'] = $sort;
    $baseParams['dir']  = $dir;
}
if ($view_mode !== 'table')    $baseParams['view']         = $view_mode;
if ($per_page !== 25)          $baseParams['per_page']     = $per_page;

$buildUrl = function (array $overrides = array(), $customRoute = NULL) use ($baseParams, $baseRoute) {
    $route  = $customRoute !== NULL ? $customRoute : $baseRoute;
    $params = array_merge($baseParams, $overrides);
    foreach ($params as $k => $v) {
        if ($v === NULL || $v === '' || $v === 0 || ($k === 'tab' && $v === 'all') || ($k === 'page' && (int)$v <= 1)) {
            unset($params[$k]);
        }
    }
    $qs = http_build_query($params);
    return base_url($route . ($qs !== '' ? '?' . $qs : ''));
};

$exportParams = $baseParams;
unset($exportParams['tab']);
if ($tab !== 'all' && $tab !== 'forms') $exportParams['tab'] = $tab;
if ($forcedCode) $exportParams['template'] = $forcedCode;
$exportQs  = http_build_query($exportParams);
$exportUrl = base_url('inspections/export_csv' . ($exportQs !== '' ? '?' . $exportQs : ''));

$sortTh = function ($colKey, $label, $alignRight = FALSE) use ($sort, $dir, $buildUrl, $h) {
    $isCurrent = ($sort === $colKey);
    $nextDir   = ($isCurrent && $dir === 'asc') ? 'desc' : 'asc';
    $ariaSort  = $isCurrent ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $arrow     = $isCurrent ? ($dir === 'asc' ? '↑' : '↓') : '↕';
    $cls       = ($isCurrent ? 'is-sorted ' : '') . ($alignRight ? 'num' : '');
    return '<th class="' . trim($cls) . '" aria-sort="' . $ariaSort . '">'
        . '<a class="in-sort-link" href="' . $h($buildUrl(array('sort' => $colKey, 'dir' => $nextDir, 'page' => 1))) . '">'
        . '<span>' . $h($label) . '</span><span class="in-sort-arrow" aria-hidden="true">' . $arrow . '</span>'
        . '</a></th>';
};

// Group active templates by category for Form Library tab & Quick Launcher modal
$groupedTemplates = array();
foreach ($templates as $t) {
    $cat = ($lang === 'fr')
        ? (isset($t->category_fr) && $t->category_fr !== '' ? $t->category_fr : 'Général')
        : (isset($t->category_en) && $t->category_en !== '' ? $t->category_en : 'General');
    if (!isset($groupedTemplates[$cat])) $groupedTemplates[$cat] = array();
    $groupedTemplates[$cat][] = $t;
}

// Flash messages safe in both CI HTTP context and standalone view tests
$flashSuccess = (isset($this) && isset($this->session) && method_exists($this->session, 'flashdata'))
    ? (string)$this->session->flashdata('success') : '';
$flashError   = (isset($this) && isset($this->session) && method_exists($this->session, 'flashdata'))
    ? (string)$this->session->flashdata('error') : '';

$equipMap = array();
foreach ($equipment_list as $eqObj) {
    $equipMap[(int)$eqObj->id] = (string)$eqObj->registration_no;
}

$activeChips = array();
if ($forcedCode || $f_template !== '') {
    $codeLbl = strtoupper($forcedCode ? $forcedCode : $f_template);
    $activeChips[] = array(
        'label' => $L('Checklist', 'Formulaire') . ': ' . $codeLbl,
        'url'   => $forcedCode ? base_url('inspections?lang=' . $lang) : $buildUrl(array('template' => NULL, 'page' => 1)),
    );
}
if ($f_equip > 0) {
    $eqLbl = isset($equipMap[$f_equip]) ? $equipMap[$f_equip] : ('#' . $f_equip);
    $activeChips[] = array(
        'label' => $L('Machine', 'Machine') . ': ' . $eqLbl,
        'url'   => $buildUrl(array('equipment_id' => NULL, 'page' => 1)),
    );
}
if ($f_rec_lang !== '') {
    $activeChips[] = array(
        'label' => $L('Record Lang', 'Langue') . ': ' . strtoupper($f_rec_lang),
        'url'   => $buildUrl(array('rec_lang' => NULL, 'page' => 1)),
    );
}
if ($f_from !== '') {
    $activeChips[] = array(
        'label' => $L('From', 'Du') . ': ' . $f_from,
        'url'   => $buildUrl(array('date_from' => NULL, 'page' => 1)),
    );
}
if ($f_to !== '') {
    $activeChips[] = array(
        'label' => $L('To', 'Au') . ': ' . $f_to,
        'url'   => $buildUrl(array('date_to' => NULL, 'page' => 1)),
    );
}
if ($q !== '') {
    $activeChips[] = array(
        'label' => $L('Search', 'Recherche') . ': “' . $q . '”',
        'url'   => $buildUrl(array('q' => NULL, 'page' => 1)),
    );
}
?>

<style>
.in-shell {
  --in-bg: #F8FAFC;
  --in-surface: #FFFFFF;
  --in-border: #E2E8F0;
  --in-border-strong: #CBD5E1;
  --in-text: #0F172A;
  --in-muted: #475569;
  --in-subtle: #64748B;
  --in-accent: #1D4ED8;
  --in-accent-soft: #EFF6FF;
  --in-ok-bg: #ECFDF5;
  --in-ok-fg: #047857;
  --in-ok-bd: #A7F3D0;
  --in-warn-bg: #FFFBEB;
  --in-warn-fg: #B45309;
  --in-warn-bd: #FDE68A;
  --in-crit-bg: #FEF2F2;
  --in-crit-fg: #B91C1C;
  --in-crit-bd: #FECACA;
  max-width: 100%;
  overflow-x: clip;
  color: var(--in-text);
  font-variant-numeric: tabular-nums;
}
.in-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.in-title { font-size: 21px; font-weight: 700; letter-spacing: -0.02em; color: var(--in-text); margin: 0; }
.in-sub { font-size: 13px; color: var(--in-muted); margin-top: 3px; }
.in-head-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.in-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  border-radius: 8px;
  font-size: 12.5px;
  font-weight: 600;
  text-decoration: none;
  border: 1px solid var(--in-border);
  background: var(--in-surface);
  color: var(--in-text);
  cursor: pointer;
  line-height: 1.25;
  transition: background .14s, border-color .14s;
}
.in-btn:hover { background: #F1F5F9; border-color: var(--in-border-strong); color: var(--in-text); }
.in-btn-primary { background: var(--in-accent); border-color: var(--in-accent); color: #FFFFFF; }
.in-btn-primary:hover { background: #1E40AF; border-color: #1E40AF; color: #FFFFFF; }
.in-btn-sm { padding: 4px 8px; font-size: 11.5px; border-radius: 6px; }

.in-lang-seg {
  display: inline-flex;
  border: 1px solid var(--in-border);
  border-radius: 8px;
  background: var(--in-surface);
  padding: 2px;
  gap: 2px;
}
.in-lang-link {
  padding: 5px 10px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 6px;
  text-decoration: none;
  color: var(--in-muted);
}
.in-lang-link.is-active { background: var(--in-accent-soft); color: var(--in-accent); }

/* 5-Card KPI Telemetry Strip */
.in-kpi-strip {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.in-kpi {
  background: var(--in-surface);
  border: 1px solid var(--in-border);
  border-radius: 11px;
  padding: 13px 15px;
  text-decoration: none;
  color: inherit;
  display: flex;
  flex-direction: column;
  gap: 4px;
  transition: border-color .14s, box-shadow .14s;
}
.in-kpi:hover { border-color: #93C5FD; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05); }
.in-kpi-lbl { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--in-subtle); }
.in-kpi-val { font-size: 22px; font-weight: 700; color: var(--in-text); line-height: 1.15; }
.in-kpi-meta { font-size: 11.5px; color: var(--in-muted); }
.in-kpi.is-crit .in-kpi-val { color: var(--in-crit-fg); }
.in-kpi.is-warn .in-kpi-val { color: var(--in-warn-fg); }
.in-kpi.is-ok .in-kpi-val { color: var(--in-ok-fg); }

/* Tabs Row + View Switcher */
.in-tabs-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.in-tabs { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.in-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 11px;
  border-radius: 8px;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--in-muted);
  background: var(--in-surface);
  border: 1px solid var(--in-border);
  text-decoration: none;
}
.in-tab:hover { color: var(--in-text); border-color: var(--in-border-strong); }
.in-tab.is-active { background: var(--in-accent-soft); color: var(--in-accent); border-color: #93C5FD; }
.in-tab-cnt {
  padding: 1px 6px;
  border-radius: 999px;
  font-size: 11px;
  background: #F1F5F9;
  color: var(--in-muted);
}
.in-tab.is-active .in-tab-cnt { background: #DBEAFE; color: var(--in-accent); }

.in-view-seg {
  display: inline-flex;
  border: 1px solid var(--in-border);
  border-radius: 8px;
  background: var(--in-surface);
  padding: 2px;
}
.in-view-btn {
  border: 0;
  background: transparent;
  padding: 5px 10px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 6px;
  color: var(--in-muted);
  cursor: pointer;
}
.in-view-btn.is-active { background: var(--in-accent-soft); color: var(--in-accent); }

/* Filter Bar */
.in-filter-panel {
  background: var(--in-surface);
  border: 1px solid var(--in-border);
  border-radius: 11px;
  padding: 11px 13px;
  margin-bottom: 12px;
}
.in-filter-form {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.in-search-wrap { position: relative; flex: 1 1 210px; min-width: 180px; }
.in-input, .in-select {
  height: 34px;
  padding: 0 10px;
  border-radius: 8px;
  border: 1px solid var(--in-border);
  background: var(--in-surface);
  color: var(--in-text);
  font-size: 12.5px;
}
.in-search-wrap .in-input { width: 100%; padding-right: 28px; }
.in-kbd {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 10.5px;
  font-weight: 600;
  color: var(--in-subtle);
  border: 1px solid var(--in-border);
  border-radius: 4px;
  padding: 0 5px;
  background: #F8FAFC;
  pointer-events: none;
}
.in-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 9px; }
.in-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
  background: var(--in-accent-soft);
  color: var(--in-accent);
  text-decoration: none;
  border: 1px solid #BFDBFE;
}

/* Sticky Selection Batch Bar */
.in-batch-bar {
  display: none;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 9px 14px;
  margin-bottom: 10px;
  border-radius: 10px;
  background: #0F172A;
  color: #FFFFFF;
  font-size: 12.5px;
}
.in-batch-bar.is-open { display: flex; }
.in-batch-bar .in-btn { background: #1E293B; border-color: #334155; color: #FFFFFF; }
.in-batch-bar .in-btn-primary { background: #2563EB; border-color: #2563EB; }

/* Zero-Overflow 1440px DataTable */
.in-table-wrap {
  background: var(--in-surface);
  border: 1px solid var(--in-border);
  border-radius: 12px;
  overflow: hidden;
  width: 100%;
}
.in-table {
  width: 100%;
  table-layout: fixed;
  border-collapse: collapse;
  font-size: 12.5px;
}
.in-table thead th {
  background: #F8FAFC;
  border-bottom: 1px solid var(--in-border);
  padding: 10px 9px;
  text-align: left;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--in-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.in-table thead th.num, .in-table tbody td.num { text-align: right; }
.in-sort-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: inherit;
  text-decoration: none;
  max-width: 100%;
}
.in-sort-arrow { font-size: 11px; color: var(--in-subtle); }
.in-table thead th.is-sorted .in-sort-link,
.in-table thead th.is-sorted .in-sort-arrow { color: var(--in-accent); }

.in-table tbody tr { border-bottom: 1px solid var(--in-border); transition: background .1s; }
.in-table tbody tr:last-child { border-bottom: 0; }
.in-table tbody tr:hover { background: #F8FAFC; }
.in-table tbody td {
  padding: 10px 9px;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
}
.in-primary-btn {
  border: 0;
  background: transparent;
  padding: 0;
  margin: 0;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--in-accent);
  cursor: pointer;
  text-align: left;
  text-decoration: none;
}
.in-primary-btn:hover { text-decoration: underline; }
.in-cell-main { font-weight: 600; color: var(--in-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.in-cell-sub { font-size: 11.5px; color: var(--in-subtle); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.in-equip-link { font-weight: 700; color: var(--in-text); text-decoration: none; }
.in-equip-link:hover { color: var(--in-accent); text-decoration: underline; }

/* Semantic Badges (WCAG AA >= 4.5:1) */
.in-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  border: 1px solid transparent;
  white-space: nowrap;
}
.in-badge-crit { background: var(--in-crit-bg); color: var(--in-crit-fg); border-color: var(--in-crit-bd); }
.in-badge-warn { background: var(--in-warn-bg); color: var(--in-warn-fg); border-color: var(--in-warn-bd); }
.in-badge-ok   { background: var(--in-ok-bg);   color: var(--in-ok-fg);   border-color: var(--in-ok-bd); }
.in-badge-neutral { background: #F1F5F9; color: #334155; border-color: #CBD5E1; }

.in-row-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 5px;
  flex-wrap: nowrap;
}

/* Dual Card View & < 768px Mobile Fallback */
.in-cards-container { display: none; }
.in-cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 12px;
}
.in-record-card {
  background: var(--in-surface);
  border: 1px solid var(--in-border);
  border-radius: 12px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.in-shell[data-view="cards"] .in-table-wrap { display: none; }
.in-shell[data-view="cards"] .in-cards-container { display: block; }

/* Pagination Footer */
.in-pager {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 12px;
  font-size: 12.5px;
  color: var(--in-muted);
}
.in-page-links { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.in-page-link {
  padding: 5px 10px;
  border-radius: 7px;
  border: 1px solid var(--in-border);
  background: var(--in-surface);
  color: var(--in-text);
  text-decoration: none;
  font-weight: 600;
  font-size: 12px;
}
.in-page-link.is-active { background: var(--in-accent); border-color: var(--in-accent); color: #FFFFFF; }

/* Bilingual Form Library Section */
.in-forms-section { margin-top: 8px; }
.in-cat-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 700;
  color: var(--in-text);
  margin: 18px 0 10px;
  padding-bottom: 6px;
  border-bottom: 1px solid var(--in-border);
}
.in-tpl-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 12px;
}
.in-tpl-card {
  background: var(--in-surface);
  border: 1px solid var(--in-border);
  border-radius: 12px;
  padding: 15px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 12px;
}
.in-tpl-actions { display: flex; flex-wrap: wrap; gap: 6px; padding-top: 10px; border-top: 1px solid var(--in-border); }

/* Slide-Over Checklist & Defect Inspector Drawer */
.in-drawer {
  position: fixed;
  inset: 0;
  z-index: 1050;
  display: none;
}
.in-drawer.is-open { display: block; }
.in-drawer-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.42);
}
.in-drawer-panel {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  width: min(520px, 100vw);
  background: var(--in-surface);
  border-left: 1px solid var(--in-border);
  box-shadow: -12px 0 32px rgba(15, 23, 42, 0.14);
  display: flex;
  flex-direction: column;
  z-index: 1;
}
.in-drawer-head {
  padding: 15px 18px;
  border-bottom: 1px solid var(--in-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.in-drawer-body {
  padding: 16px 18px;
  overflow-y: auto;
  flex: 1 1 auto;
  font-size: 12.5px;
}
.in-drawer-foot {
  padding: 12px 18px;
  border-top: 1px solid var(--in-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
}
.in-kv-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  background: #F8FAFC;
  border: 1px solid var(--in-border);
  border-radius: 10px;
  padding: 12px;
  margin-bottom: 14px;
}
.in-kv-lbl { font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: var(--in-subtle); }
.in-kv-val { font-size: 12.5px; font-weight: 600; color: var(--in-text); margin-top: 2px; }

/* Quick New Inspection Launcher Modal */
.in-modal {
  position: fixed;
  inset: 0;
  z-index: 1060;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.in-modal.is-open { display: flex; }
.in-modal-dialog {
  position: relative;
  z-index: 1;
  width: min(680px, 100%);
  max-height: 82vh;
  background: var(--in-surface);
  border: 1px solid var(--in-border);
  border-radius: 14px;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.18);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

@media (max-width: 1100px) {
  .in-kpi-strip { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 767px) {
  .in-kpi-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .in-table-wrap { display: none !important; }
  .in-cards-container { display: block !important; }
  .in-cards-grid { grid-template-columns: 1fr; }
  .in-view-seg { display: none; }
}
@media print {
  .in-head-actions, .in-filter-panel, .in-tabs-bar, .in-batch-bar, .in-pager, .in-row-actions, .in-col-chk { display: none !important; }
  .in-table-wrap { border: 0; }
}
</style>

<div class="in-shell" id="inShell" data-view="<?= $h($view_mode) ?>">

  <!-- Header Command Bar -->
  <div class="in-header">
    <div>
      <h1 class="in-title">
        <?php if ($focusedTpl): ?>
          <?= $h($lang === 'fr' ? $focusedTpl->name_fr : $focusedTpl->name_en) ?> — <?= $L('Records', 'Enregistrements') ?>
        <?php else: ?>
          <?= $L('Inspections & Safety Checklists', 'Inspections & Listes de contrôle') ?>
        <?php endif; ?>
      </h1>
      <div class="in-sub">
        <?= $L(
            'Fleet-wide safety & mechanical inspection register across all 14 bilingual checklists.',
            'Registre unifié des inspections de sécurité et mécaniques sur les 14 formulaires bilingues.'
        ) ?>
      </div>
    </div>

    <div class="in-head-actions">
      <?php if ($can_submit): ?>
        <?php if ($focusedTpl): ?>
          <a class="in-btn in-btn-primary" href="<?= $h(base_url('inspections/fill/' . $focusedTpl->code . '/en')) ?>">
            + <?= $L('New (EN)', 'Nouveau (EN)') ?>
          </a>
          <a class="in-btn in-btn-primary" href="<?= $h(base_url('inspections/fill/' . $focusedTpl->code . '/fr')) ?>">
            + <?= $L('New (FR)', 'Nouveau (FR)') ?>
          </a>
        <?php else: ?>
          <button type="button" class="in-btn in-btn-primary" id="inOpenLauncherBtn">
            + <?= $L('New Inspection', 'Nouvelle inspection') ?>
          </button>
        <?php endif; ?>
      <?php endif; ?>

      <a class="in-btn" href="<?= $h($exportUrl) ?>">
        ↓ <?= $L('Export CSV', 'Exporter CSV') ?>
      </a>

      <?php if ($can_manage): ?>
        <a class="in-btn" href="<?= $h(base_url('inspections/templates')) ?>">
          <?= $L('Manage Templates', 'Gérer les modèles') ?>
        </a>
      <?php endif; ?>

      <?php if ($forcedCode): ?>
        <a class="in-btn" href="<?= $h(base_url('inspections?lang=' . $lang)) ?>">
          ← <?= $L('All Checklists', 'Tous les formulaires') ?>
        </a>
      <?php endif; ?>

      <div class="in-lang-seg" role="group" aria-label="Language">
        <a href="<?= $h($buildUrl(array('lang' => 'en'))) ?>" class="in-lang-link <?= $lang === 'en' ? 'is-active' : '' ?>">EN</a>
        <a href="<?= $h($buildUrl(array('lang' => 'fr'))) ?>" class="in-lang-link <?= $lang === 'fr' ? 'is-active' : '' ?>">FR</a>
      </div>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?>
    <div style="padding:10px 14px;border-radius:9px;background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;margin-bottom:14px;font-size:13px;">
      <?= $h($flashSuccess) ?>
    </div>
  <?php endif; ?>
  <?php if ($flashError !== ''): ?>
    <div style="padding:10px 14px;border-radius:9px;background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;margin-bottom:14px;font-size:13px;">
      <?= $h($flashError) ?>
    </div>
  <?php endif; ?>

  <!-- 5-Card Executive Telemetry Strip -->
  <div class="in-kpi-strip">
    <a class="in-kpi" href="<?= $h($buildUrl(array('tab' => 'all', 'page' => 1))) ?>">
      <span class="in-kpi-lbl"><?= $L('Submitted Records', 'Inspections soumises') ?></span>
      <span class="in-kpi-val"><?= (int)$kpi['total_records'] ?></span>
      <span class="in-kpi-meta"><?= (int)$kpi['today_records'] ?> <?= $L('recorded today', 'aujourd’hui') ?></span>
    </a>

    <a class="in-kpi <?= (int)$kpi['p1_nogo'] > 0 ? 'is-crit' : '' ?>" href="<?= $h($buildUrl(array('tab' => 'p1_nogo', 'page' => 1))) ?>">
      <span class="in-kpi-lbl"><?= $L('No-Go Breakdowns (P1)', 'Arrêts immédiats (P1)') ?></span>
      <span class="in-kpi-val"><?= (int)$kpi['p1_nogo'] ?></span>
      <span class="in-kpi-meta"><?= $L('Critical safety stop items', 'Défauts critiques bloquants') ?></span>
    </a>

    <a class="in-kpi <?= (int)$kpi['failed_records'] > 0 ? 'is-warn' : '' ?>" href="<?= $h($buildUrl(array('tab' => 'failed', 'page' => 1))) ?>">
      <span class="in-kpi-lbl"><?= $L('Inspections w/ Defects', 'Avec anomalies') ?></span>
      <span class="in-kpi-val"><?= (int)$kpi['failed_records'] ?></span>
      <span class="in-kpi-meta"><?= (int)$kpi['total_failed_items'] ?> <?= $L('failed checks logged', 'points non conformes') ?></span>
    </a>

    <a class="in-kpi is-ok" href="<?= $h($buildUrl(array('tab' => 'passed', 'page' => 1))) ?>">
      <span class="in-kpi-lbl"><?= $L('Clean Pass Rate', 'Taux de conformité') ?></span>
      <span class="in-kpi-val"><?= (int)$kpi['pass_rate'] ?>%</span>
      <span class="in-kpi-meta"><?= (int)$kpi['clean_pass'] ?> <?= $L('zero-defect inspections', 'inspections sans défaut') ?></span>
    </a>

    <a class="in-kpi" href="<?= $h($buildUrl(array('tab' => 'forms', 'page' => 1))) ?>">
      <span class="in-kpi-lbl"><?= $L('Bilingual Checklists', 'Modèles bilingues') ?></span>
      <span class="in-kpi-val"><?= (int)$kpi['active_templates'] ?></span>
      <span class="in-kpi-meta"><?= (int)$kpi['unsigned'] ?> <?= $L('awaiting supervisor sign-off', 'sans visa superviseur') ?></span>
    </a>
  </div>

  <!-- Governance Tabs & Table/Cards Switcher -->
  <div class="in-tabs-bar">
    <nav class="in-tabs" aria-label="Inspection views">
      <?php
      $tabDefs = array(
          'all'      => $L('All Records', 'Tous'),
          'p1_nogo'  => $L('No-Go (P1)', 'Arrêt P1'),
          'failed'   => $L('Defects Found', 'Anomalies'),
          'passed'   => $L('Clean Pass', 'Conformes'),
          'unsigned' => $L('Unsigned', 'Non signés'),
          'draft'    => $L('Drafts', 'Brouillons'),
          'forms'    => $L('Form Library', 'Formulaires'),
      );
      foreach ($tabDefs as $tKey => $tLabel):
          $cnt = isset($tab_counts[$tKey]) ? (int)$tab_counts[$tKey] : 0;
      ?>
        <a class="in-tab <?= $tab === $tKey ? 'is-active' : '' ?>"
           href="<?= $h($buildUrl(array('tab' => $tKey, 'page' => 1))) ?>">
          <span><?= $h($tLabel) ?></span>
          <span class="in-tab-cnt"><?= $cnt ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if ($tab !== 'forms'): ?>
      <div class="in-view-seg" role="group" aria-label="Layout view">
        <button type="button" class="in-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>" data-in-view="table">
          <?= $L('Table', 'Tableau') ?>
        </button>
        <button type="button" class="in-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>" data-in-view="cards">
          <?= $L('Cards', 'Cartes') ?>
        </button>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($tab === 'forms'): ?>
    <!-- BILINGUAL FORM LIBRARY VIEW (`?tab=forms`) -->
    <div class="in-filter-panel">
      <div class="in-filter-form">
        <div class="in-search-wrap">
          <input type="search" id="inFormSearchInput" class="in-input"
                 placeholder="<?= $h($L('Filter 14 bilingual checklists by name or code…', 'Filtrer les 14 formulaires…')) ?>">
          <span class="in-kbd">/</span>
        </div>
      </div>
    </div>

    <div class="in-forms-section" id="inFormsLibrary">
      <?php foreach ($groupedTemplates as $catName => $catList): ?>
        <div class="in-tpl-group">
          <div class="in-cat-title">
            <span><?= $h($catName) ?></span>
            <span class="in-badge in-badge-neutral"><?= count($catList) ?></span>
          </div>
          <div class="in-tpl-grid">
            <?php foreach ($catList as $t):
              $rc        = isset($record_counts[$t->id]) ? (int)$record_counts[$t->id] : 0;
              $mainTitle = ($lang === 'fr') ? $t->name_fr : $t->name_en;
              $subTitle  = ($lang === 'fr') ? $t->name_en : $t->name_fr;
              $itemCnt   = isset($t->item_count) ? (int)$t->item_count : 0;
            ?>
              <div class="in-tpl-card" data-tpl-search="<?= $h(strtolower($t->code . ' ' . $t->name_en . ' ' . $t->name_fr . ' ' . $catName)) ?>">
                <div>
                  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
                    <span class="in-badge in-badge-neutral"><?= $h(strtoupper((string)$t->code)) ?></span>
                    <span style="font-size:11.5px;color:var(--in-subtle);">
                      <?= $itemCnt ?> <?= $L('checks', 'points') ?> · <?= $rc ?> <?= $L('submitted', 'soumis') ?>
                    </span>
                  </div>
                  <div style="font-size:14.5px;font-weight:700;color:var(--in-text);margin-bottom:3px;"><?= $h($mainTitle) ?></div>
                  <div style="font-size:12px;color:var(--in-subtle);"><?= $h($subTitle) ?></div>
                </div>
                <div class="in-tpl-actions">
                  <?php if ($can_submit): ?>
                    <a class="in-btn in-btn-primary in-btn-sm" href="<?= $h(base_url('inspections/fill/' . $t->code . '/en')) ?>">
                      + <?= $L('New (EN)', 'Nouveau (EN)') ?>
                    </a>
                    <a class="in-btn in-btn-primary in-btn-sm" href="<?= $h(base_url('inspections/fill/' . $t->code . '/fr')) ?>">
                      + <?= $L('New (FR)', 'Nouveau (FR)') ?>
                    </a>
                  <?php endif; ?>
                  <a class="in-btn in-btn-sm" href="<?= $h(base_url('inspections?tab=all&template=' . rawurlencode($t->code) . '&lang=' . $lang)) ?>">
                    <?= $L('Records', 'Enregistrements') ?> (<?= $rc ?>)
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php else: ?>
    <!-- UNIFIED ENTERPRISE FLEET INSPECTION REGISTER DATATABLE -->
    <div class="in-filter-panel">
      <form method="get" action="<?= $h(base_url($baseRoute)) ?>" class="in-filter-form">
        <input type="hidden" name="lang" value="<?= $h($lang) ?>">
        <input type="hidden" name="tab" value="<?= $h($tab) ?>">
        <input type="hidden" name="view" value="<?= $h($view_mode) ?>" id="inViewHiddenInput">

        <div class="in-search-wrap">
          <input type="search" name="q" id="inSearchInput" class="in-input"
                 value="<?= $h($q) ?>"
                 placeholder="<?= $h($L('Search machine ID, INSP #, checklist, mechanic, comments…', 'Rechercher machine, INSP #, formulaire, mécanicien…')) ?>">
          <span class="in-kbd">/</span>
        </div>

        <?php if (!$forcedCode): ?>
          <select name="template" class="in-select" aria-label="Checklist template" onchange="this.form.submit()">
            <option value=""><?= $h($L('All Checklists', 'Tous les formulaires')) ?></option>
            <?php foreach ($templates as $t): ?>
              <option value="<?= $h($t->code) ?>" <?= strtolower($f_template) === strtolower((string)$t->code) ? 'selected' : '' ?>>
                <?= $h(strtoupper((string)$t->code) . ' — ' . ($lang === 'fr' ? $t->name_fr : $t->name_en)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>

        <select name="equipment_id" class="in-select" aria-label="Machine" onchange="this.form.submit()">
          <option value="0"><?= $h($L('All Machines', 'Toutes les machines')) ?></option>
          <?php foreach ($equipment_list as $eq): ?>
            <option value="<?= (int)$eq->id ?>" <?= $f_equip === (int)$eq->id ? 'selected' : '' ?>>
              <?= $h($eq->registration_no) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select name="rec_lang" class="in-select" aria-label="Submission language" onchange="this.form.submit()">
          <option value=""><?= $h($L('EN & FR', 'EN & FR')) ?></option>
          <option value="en" <?= $f_rec_lang === 'en' ? 'selected' : '' ?>>English (EN)</option>
          <option value="fr" <?= $f_rec_lang === 'fr' ? 'selected' : '' ?>>Français (FR)</option>
        </select>

        <input type="date" name="date_from" class="in-input" value="<?= $h($f_from) ?>" title="From date" onchange="this.form.submit()">
        <input type="date" name="date_to" class="in-input" value="<?= $h($f_to) ?>" title="To date" onchange="this.form.submit()">

        <button type="submit" class="in-btn"><?= $L('Filter', 'Filtrer') ?></button>
        <?php if (!empty($activeChips)): ?>
          <a class="in-btn" href="<?= $h(base_url('inspections?lang=' . $lang)) ?>"><?= $L('Reset', 'Réinitialiser') ?></a>
        <?php endif; ?>
      </form>

      <?php if (!empty($activeChips)): ?>
        <div class="in-chips">
          <?php foreach ($activeChips as $chip): ?>
            <a class="in-chip" href="<?= $h($chip['url']) ?>">
              <span><?= $h($chip['label']) ?></span>
              <span aria-hidden="true">×</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Sticky Multi-Select Batch Action Bar -->
    <div class="in-batch-bar" id="inBatchBar" role="region" aria-label="Selected inspections">
      <div>
        <strong id="inSelectedCount">0</strong>
        <?= $L('inspection(s) selected', 'inspection(s) sélectionnée(s)') ?>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <a class="in-btn in-btn-primary in-btn-sm" id="inBatchExportBtn" href="<?= $h($exportUrl) ?>">
          ↓ <?= $L('Export Selected CSV', 'Exporter la sélection CSV') ?>
        </a>
        <button type="button" class="in-btn in-btn-sm" id="inBatchClearBtn">
          <?= $L('Clear', 'Effacer') ?>
        </button>
      </div>
    </div>

    <!-- Desktop 1440px Zero-Overflow DataTable -->
    <div class="in-table-wrap">
      <table class="in-table">
        <colgroup>
          <col style="width:38px;">
          <col style="width:12%;">
          <col style="width:15%;">
          <col style="width:19%;">
          <col style="width:9%;">
          <col style="width:14%;">
          <col style="width:15%;">
          <col style="width:auto;">
        </colgroup>
        <thead>
          <tr>
            <th class="in-col-chk" style="text-align:center;">
              <input type="checkbox" id="inCheckAll" aria-label="Select all visible inspections">
            </th>
            <?= $sortTh('inspection_date', $L('Date & ID', 'Date & N°')) ?>
            <?= $sortTh('machine', $L('Machine ID', 'Machine')) ?>
            <?= $sortTh('template', $L('Checklist Form', 'Formulaire')) ?>
            <?= $sortTh('smr', $L('Meter', 'Compteur'), TRUE) ?>
            <?= $sortTh('verdict', $L('Verdict', 'Verdict')) ?>
            <?= $sortTh('inspector', $L('Inspector & Sign-Off', 'Inspecteur & Visa')) ?>
            <th style="text-align:right;"><?= $h($L('Actions', 'Actions')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($records)): ?>
            <tr>
              <td colspan="8" style="padding:40px 18px;text-align:center;color:var(--in-muted);">
                <div style="font-size:14.5px;font-weight:700;color:var(--in-text);margin-bottom:4px;">
                  <?= $L('No matching inspection records found', 'Aucune inspection correspondante') ?>
                </div>
                <div style="font-size:12.5px;margin-bottom:14px;">
                  <?= $L(
                      'Adjust your search or filters, or run one of the 14 bilingual safety checklists.',
                      'Modifiez vos filtres ou lancez l’un des 14 formulaires bilingues.'
                  ) ?>
                </div>
                <div style="display:inline-flex;gap:8px;flex-wrap:wrap;justify-content:center;">
                  <a class="in-btn in-btn-sm" href="<?= $h($buildUrl(array('tab' => 'forms', 'page' => 1))) ?>">
                    <?= $L('Browse 14 Checklists', 'Voir les 14 formulaires') ?>
                  </a>
                  <?php if (!empty($activeChips) || $tab !== 'all'): ?>
                    <a class="in-btn in-btn-sm" href="<?= $h(base_url('inspections?lang=' . $lang)) ?>">
                      <?= $L('Clear Filters', 'Effacer les filtres') ?>
                    </a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($records as $r):
              $rid     = (int)$r->id;
              $tplName = ($lang === 'fr') ? $r->template_name_fr : $r->template_name_en;
              if ($r->verdict_state === 'p1_nogo') {
                  $vCls = 'in-badge-crit';
                  $vTxt = $L('No-Go (P1)', 'Arrêt (P1)');
              } elseif ($r->verdict_state === 'failed') {
                  $vCls = 'in-badge-warn';
                  $vTxt = sprintf($L('Defects (%d)', 'Anomalies (%d)'), (int)$r->fail_count);
              } elseif ($r->verdict_state === 'draft') {
                  $vCls = 'in-badge-neutral';
                  $vTxt = $L('Draft', 'Brouillon');
              } else {
                  $vCls = 'in-badge-ok';
                  $vTxt = $L('Clean Pass', 'Conforme');
              }
            ?>
              <tr data-in-id="<?= $rid ?>">
                <td class="in-col-chk" style="text-align:center;">
                  <input type="checkbox" class="in-row-check" value="<?= $rid ?>" aria-label="Select inspection INSP-<?= $rid ?>">
                </td>
                <td>
                  <button type="button" class="in-primary-btn in-inspect-trigger" data-in-open="<?= $rid ?>">
                    INSP-<?= str_pad((string)$rid, 4, '0', STR_PAD_LEFT) ?>
                  </button>
                  <div class="in-cell-sub">
                    <?= $h($r->inspection_date ?: '—') ?> · <?= $h(strtoupper((string)$r->language)) ?>
                  </div>
                </td>
                <td>
                  <div class="in-cell-main">
                    <?php if (!empty($r->equipment_id)): ?>
                      <a class="in-equip-link" href="<?= $h(base_url('equipment/view/' . (int)$r->equipment_id)) ?>">
                        <?= $h($r->machine_label) ?>
                      </a>
                    <?php else: ?>
                      <?= $h($r->machine_label) ?>
                    <?php endif; ?>
                  </div>
                  <div class="in-cell-sub">
                    <?= $h(trim((string)$r->equipment_type . ($r->model ? ' · ' . $r->model : '')) ?: '—') ?>
                  </div>
                </td>
                <td>
                  <div class="in-cell-main" title="<?= $h($tplName) ?>"><?= $h($tplName) ?></div>
                  <div class="in-cell-sub"><?= $h(strtoupper((string)$r->template_code)) ?> · <?= (int)$r->checked_count ?>/<?= (int)$r->total_items ?> <?= $L('items', 'pts') ?></div>
                </td>
                <td class="num">
                  <div class="in-cell-main"><?= $h($r->smr !== NULL && $r->smr !== '' ? $r->smr : '—') ?></div>
                  <div class="in-cell-sub"><?= $h($r->meter_label_en) ?></div>
                </td>
                <td>
                  <span class="in-badge <?= $vCls ?>"><?= $h($vTxt) ?></span>
                  <?php if ((int)$r->fail_count > 0): ?>
                    <div class="in-cell-sub"><?= (int)$r->fail_count ?> <?= $L('failed', 'échec(s)') ?><?= (int)$r->p1_count > 0 ? ' · ' . (int)$r->p1_count . ' P1' : '' ?></div>
                  <?php else: ?>
                    <div class="in-cell-sub"><?= $L('0 defects', '0 anomalie') ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="in-cell-main"><?= $h($r->inspector_label) ?></div>
                  <div class="in-cell-sub">
                    <?php if (!empty($r->is_signed)): ?>
                      <span style="color:var(--in-ok-fg);font-weight:600;">✓ <?= $L('Sup. signed', 'Visé sup.') ?></span>
                    <?php else: ?>
                      <span style="color:var(--in-warn-fg);font-weight:600;"><?= $L('Unsigned', 'Non signé') ?></span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="in-row-actions">
                    <button type="button" class="in-btn in-btn-sm in-inspect-btn" data-in-open="<?= $rid ?>">
                      <?= $L('Inspect', 'Inspecter') ?>
                    </button>
                    <a class="in-btn in-btn-sm" href="<?= $h(base_url('inspections/view/' . $rid)) ?>">
                      <?= $L('View', 'Voir') ?>
                    </a>
                    <a class="in-btn in-btn-sm" href="<?= $h(base_url('inspections/pdf/' . $rid)) ?>" target="_blank" rel="noopener">
                      PDF
                    </a>
                    <?php if ($can_submit): ?>
                      <a class="in-btn in-btn-sm" href="<?= $h(base_url('inspections/edit/' . $rid)) ?>">
                        <?= $L('Edit', 'Modifier') ?>
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Dual Card Grid & Automatic < 768px Mobile Card View -->
    <div class="in-cards-container">
      <?php if (empty($records)): ?>
        <div style="padding:34px 16px;text-align:center;background:var(--in-surface);border:1px solid var(--in-border);border-radius:12px;color:var(--in-muted);">
          <?= $L('No matching inspection records found.', 'Aucune inspection correspondante.') ?>
        </div>
      <?php else: ?>
        <div class="in-cards-grid">
          <?php foreach ($records as $r):
            $rid     = (int)$r->id;
            $tplName = ($lang === 'fr') ? $r->template_name_fr : $r->template_name_en;
            if ($r->verdict_state === 'p1_nogo') {
                $vCls = 'in-badge-crit';
                $vTxt = $L('No-Go (P1)', 'Arrêt (P1)');
            } elseif ($r->verdict_state === 'failed') {
                $vCls = 'in-badge-warn';
                $vTxt = sprintf($L('Defects (%d)', 'Anomalies (%d)'), (int)$r->fail_count);
            } elseif ($r->verdict_state === 'draft') {
                $vCls = 'in-badge-neutral';
                $vTxt = $L('Draft', 'Brouillon');
            } else {
                $vCls = 'in-badge-ok';
                $vTxt = $L('Clean Pass', 'Conforme');
            }
          ?>
            <div class="in-record-card">
              <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
                <div>
                  <button type="button" class="in-primary-btn" data-in-open="<?= $rid ?>">
                    INSP-<?= str_pad((string)$rid, 4, '0', STR_PAD_LEFT) ?> · <?= $h($r->machine_label) ?>
                  </button>
                  <div class="in-cell-sub"><?= $h($tplName) ?></div>
                </div>
                <span class="in-badge <?= $vCls ?>"><?= $h($vTxt) ?></span>
              </div>

              <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;font-size:12px;color:var(--in-muted);">
                <div><strong><?= $L('Date', 'Date') ?>:</strong> <?= $h($r->inspection_date ?: '—') ?></div>
                <div><strong><?= $h($r->meter_label_en) ?>:</strong> <?= $h($r->smr ?: '—') ?></div>
                <div><strong><?= $L('Inspector', 'Inspecteur') ?>:</strong> <?= $h($r->inspector_label) ?></div>
                <div><strong><?= $L('Sign-off', 'Visa') ?>:</strong> <?= !empty($r->is_signed) ? $L('Signed', 'Signé') : $L('Unsigned', 'Non signé') ?></div>
              </div>

              <div style="display:flex;gap:6px;flex-wrap:wrap;padding-top:8px;border-top:1px solid var(--in-border);">
                <button type="button" class="in-btn in-btn-sm" data-in-open="<?= $rid ?>"><?= $L('Inspect', 'Inspecter') ?></button>
                <a class="in-btn in-btn-sm" href="<?= $h(base_url('inspections/view/' . $rid)) ?>"><?= $L('View', 'Voir') ?></a>
                <a class="in-btn in-btn-sm" href="<?= $h(base_url('inspections/pdf/' . $rid)) ?>" target="_blank" rel="noopener">PDF</a>
                <?php if ($can_submit): ?>
                  <a class="in-btn in-btn-sm" href="<?= $h(base_url('inspections/edit/' . $rid)) ?>"><?= $L('Edit', 'Modifier') ?></a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Pagination Bar -->
    <div class="in-pager">
      <div>
        <?php
        $showFrom = $total_filtered > 0 ? ($offset + 1) : 0;
        $showTo   = min($total_filtered, $offset + count($records));
        ?>
        <?= sprintf($L('Showing %d–%d of %d inspections', 'Affichage de %d–%d sur %d inspections'), $showFrom, $showTo, $total_filtered) ?>
      </div>

      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <form method="get" action="<?= $h(base_url($baseRoute)) ?>" style="display:inline-flex;align-items:center;gap:6px;">
          <?php foreach ($baseParams as $pk => $pv): if ($pk === 'per_page' || $pk === 'page') continue; ?>
            <input type="hidden" name="<?= $h($pk) ?>" value="<?= $h($pv) ?>">
          <?php endforeach; ?>
          <label for="inPerPage" style="font-size:12px;"><?= $L('Rows:', 'Lignes :') ?></label>
          <select id="inPerPage" name="per_page" class="in-select" style="height:30px;" onchange="this.form.submit()">
            <?php foreach ($allowed_pp as $pp): ?>
              <option value="<?= (int)$pp ?>" <?= $per_page === (int)$pp ? 'selected' : '' ?>><?= (int)$pp ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <div class="in-page-links">
          <?php if ($current_page > 1): ?>
            <a class="in-page-link" href="<?= $h($buildUrl(array('page' => $current_page - 1))) ?>">‹ <?= $L('Prev', 'Préc.') ?></a>
          <?php endif; ?>
          <?php
          $pStart = max(1, $current_page - 2);
          $pEnd   = min($total_pages, $current_page + 2);
          for ($p = $pStart; $p <= $pEnd; $p++):
          ?>
            <a class="in-page-link <?= $p === $current_page ? 'is-active' : '' ?>"
               href="<?= $h($buildUrl(array('page' => $p))) ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($current_page < $total_pages): ?>
            <a class="in-page-link" href="<?= $h($buildUrl(array('page' => $current_page + 1))) ?>"><?= $L('Next', 'Suiv.') ?> ›</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Accessible Slide-Over Checklist & Defect Inspector Drawer (`#inDrawer`) -->
<div class="in-drawer" id="inDrawer" role="dialog" aria-modal="true" aria-labelledby="inDrawerTitle">
  <div class="in-drawer-backdrop" data-in-close></div>
  <div class="in-drawer-panel">
    <div class="in-drawer-head">
      <div>
        <div id="inDrawerTitle" style="font-size:15px;font-weight:700;color:var(--in-text);">
          <?= $L('Inspection Summary', 'Résumé de l’inspection') ?>
        </div>
        <div id="inDrawerSub" style="font-size:11.5px;color:var(--in-subtle);margin-top:2px;">
          <?= $L('Use ← / → to step through records · Esc to close', 'Utilisez ← / → pour naviguer · Échap pour fermer') ?>
        </div>
      </div>
      <button type="button" class="in-btn in-btn-sm" data-in-close aria-label="Close inspector">✕</button>
    </div>
    <div class="in-drawer-body" id="inDrawerBody"></div>
    <div class="in-drawer-foot" id="inDrawerFoot"></div>
  </div>
</div>

<?php if ($can_submit): ?>
<!-- Quick `+ New Inspection` Bilingual Form Launcher Modal (`#inNewModal`) -->
<div class="in-modal" id="inNewModal" role="dialog" aria-modal="true" aria-labelledby="inNewModalTitle">
  <div class="in-drawer-backdrop" data-in-modal-close></div>
  <div class="in-modal-dialog">
    <div class="in-drawer-head">
      <div>
        <div id="inNewModalTitle" style="font-size:15.5px;font-weight:700;color:var(--in-text);">
          <?= $L('Start a New Equipment Inspection', 'Démarrer une nouvelle inspection') ?>
        </div>
        <div style="font-size:12px;color:var(--in-subtle);">
          <?= $L('Select any of the 14 bilingual checklists in English or French.', 'Choisissez un formulaire en anglais ou en français.') ?>
        </div>
      </div>
      <button type="button" class="in-btn in-btn-sm" data-in-modal-close aria-label="Close modal">✕</button>
    </div>
    <div style="padding:12px 18px;border-bottom:1px solid var(--in-border);">
      <input type="search" id="inModalFilterInput" class="in-input" style="width:100%;"
             placeholder="<?= $h($L('Type equipment category or form code (e.g. Excavator, ADT, Bus)…', 'Filtrer par équipement ou code…')) ?>">
    </div>
    <div style="padding:14px 18px;overflow-y:auto;display:flex;flex-direction:column;gap:8px;" id="inModalList">
      <?php foreach ($templates as $t):
        $mTitle = ($lang === 'fr') ? $t->name_fr : $t->name_en;
      ?>
        <div class="in-modal-item" data-modal-search="<?= $h(strtolower($t->code . ' ' . $t->name_en . ' ' . $t->name_fr)) ?>"
             style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--in-border);border-radius:10px;">
          <div>
            <div style="font-size:13px;font-weight:700;color:var(--in-text);">
              <span class="in-badge in-badge-neutral" style="margin-right:6px;"><?= $h(strtoupper((string)$t->code)) ?></span>
              <?= $h($mTitle) ?>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-shrink:0;">
            <a class="in-btn in-btn-primary in-btn-sm" href="<?= $h(base_url('inspections/fill/' . $t->code . '/en')) ?>">+ EN</a>
            <a class="in-btn in-btn-primary in-btn-sm" href="<?= $h(base_url('inspections/fill/' . $t->code . '/fr')) ?>">+ FR</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var shell       = document.getElementById('inShell');
  var summaryBase = <?= json_encode(base_url('inspections/summary_json/'), JSON_UNESCAPED_SLASHES) ?>;
  var viewBase    = <?= json_encode(base_url('inspections/view/'), JSON_UNESCAPED_SLASHES) ?>;
  var editBase    = <?= json_encode(base_url('inspections/edit/'), JSON_UNESCAPED_SLASHES) ?>;
  var pdfBase     = <?= json_encode(base_url('inspections/pdf/'), JSON_UNESCAPED_SLASHES) ?>;
  var equipBase   = <?= json_encode(base_url('equipment/view/'), JSON_UNESCAPED_SLASHES) ?>;
  var baseExport  = <?= json_encode($exportUrl, JSON_UNESCAPED_SLASHES) ?>;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // Table <-> Cards view mode switcher
  var viewBtns  = document.querySelectorAll('[data-in-view]');
  var viewInput = document.getElementById('inViewHiddenInput');
  viewBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.getAttribute('data-in-view') === 'cards' ? 'cards' : 'table';
      shell.setAttribute('data-view', mode);
      viewBtns.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      if (viewInput) viewInput.value = mode;
      try { localStorage.setItem('wms_inspections_view', mode); } catch (e) {}
    });
  });

  // Multi-select checkboxes & sticky batch CSV bar
  var checkAll    = document.getElementById('inCheckAll');
  var rowChecks   = Array.prototype.slice.call(document.querySelectorAll('.in-row-check'));
  var batchBar    = document.getElementById('inBatchBar');
  var countEl     = document.getElementById('inSelectedCount');
  var exportBtn   = document.getElementById('inBatchExportBtn');
  var clearBtn    = document.getElementById('inBatchClearBtn');

  function syncBatch() {
    if (!batchBar) return;
    var ids = rowChecks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    countEl.textContent = String(ids.length);
    batchBar.classList.toggle('is-open', ids.length > 0);
    if (exportBtn) {
      var sep = baseExport.indexOf('?') === -1 ? '?' : '&';
      exportBtn.setAttribute('href', ids.length ? (baseExport + sep + 'ids=' + encodeURIComponent(ids.join(','))) : baseExport);
    }
    if (checkAll && rowChecks.length) {
      checkAll.checked = (ids.length === rowChecks.length);
    }
  }
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      rowChecks.forEach(function (c) { c.checked = checkAll.checked; });
      syncBatch();
    });
  }
  rowChecks.forEach(function (c) { c.addEventListener('change', syncBatch); });
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      rowChecks.forEach(function (c) { c.checked = false; });
      if (checkAll) checkAll.checked = false;
      syncBatch();
    });
  }

  // Form library live filter (`?tab=forms`)
  var formSearch = document.getElementById('inFormSearchInput');
  if (formSearch) {
    formSearch.addEventListener('input', function () {
      var needle = (formSearch.value || '').trim().toLowerCase();
      document.querySelectorAll('.in-tpl-group').forEach(function (grp) {
        var shown = 0;
        grp.querySelectorAll('.in-tpl-card').forEach(function (card) {
          var hay = card.getAttribute('data-tpl-search') || '';
          var ok  = !needle || hay.indexOf(needle) !== -1;
          card.style.display = ok ? 'flex' : 'none';
          if (ok) shown++;
        });
        grp.style.display = shown > 0 ? 'block' : 'none';
      });
    });
  }

  // Quick `+ New Inspection` Launcher Modal
  var newModal    = document.getElementById('inNewModal');
  var launcherBtn = document.getElementById('inOpenLauncherBtn');
  var modalFilter = document.getElementById('inModalFilterInput');
  var modalCaller = null;

  function closeModal() {
    if (!newModal) return;
    newModal.classList.remove('is-open');
    if (modalCaller && typeof modalCaller.focus === 'function') modalCaller.focus();
  }
  if (launcherBtn && newModal) {
    launcherBtn.addEventListener('click', function () {
      modalCaller = launcherBtn;
      newModal.classList.add('is-open');
      if (modalFilter) { modalFilter.value = ''; modalFilter.focus(); }
    });
    newModal.querySelectorAll('[data-in-modal-close]').forEach(function (el) {
      el.addEventListener('click', closeModal);
    });
  }
  if (modalFilter) {
    modalFilter.addEventListener('input', function () {
      var q = (modalFilter.value || '').trim().toLowerCase();
      document.querySelectorAll('.in-modal-item').forEach(function (row) {
        var hay = row.getAttribute('data-modal-search') || '';
        row.style.display = (!q || hay.indexOf(q) !== -1) ? 'flex' : 'none';
      });
    });
  }

  // Accessible Slide-Over Checklist & Defect Inspector Drawer (`#inDrawer`)
  var drawer     = document.getElementById('inDrawer');
  var drawerBody = document.getElementById('inDrawerBody');
  var drawerFoot = document.getElementById('inDrawerFoot');
  var drawerTit  = document.getElementById('inDrawerTitle');
  var orderedIds = Array.prototype.slice.call(document.querySelectorAll('tr[data-in-id]')).map(function (tr) {
    return parseInt(tr.getAttribute('data-in-id'), 10);
  });
  var activeId   = 0;
  var lastOpener = null;

  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('is-open');
    activeId = 0;
    if (lastOpener && typeof lastOpener.focus === 'function') {
      lastOpener.focus();
    }
  }

  function openDrawer(id, opener) {
    if (!drawer) return;
    if (opener) lastOpener = opener;
    activeId = parseInt(id, 10);
    drawer.classList.add('is-open');
    drawerTit.textContent = 'INSP-' + String(activeId).padStart(4, '0');
    drawerBody.innerHTML = '<div style="padding:24px;text-align:center;color:#64748B;">Loading inspection telemetry…</div>';
    drawerFoot.innerHTML = '';

    fetch(summaryBase + activeId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || !payload.ok) {
          drawerBody.innerHTML = '<div style="padding:18px;color:#B91C1C;">Could not load inspection details.</div>';
          return;
        }
        var rec = payload.record;
        drawerTit.textContent = 'INSP-' + String(rec.id).padStart(4, '0') + ' · ' + rec.machine_label;

        var eqHtml = rec.equipment_id
          ? '<a class="in-equip-link" href="' + esc(equipBase + rec.equipment_id) + '">' + esc(rec.machine_label) + '</a>'
          : esc(rec.machine_label);

        var html = '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin-bottom:6px;">Inspection Parameters</div>';
        html += '<div class="in-kv-grid">'
          + '<div><div class="in-kv-lbl">Machine ID</div><div class="in-kv-val">' + eqHtml + '</div></div>'
          + '<div><div class="in-kv-lbl">Date & Lang</div><div class="in-kv-val">' + esc(rec.inspection_date) + ' · ' + esc(rec.language) + '</div></div>'
          + '<div><div class="in-kv-lbl">Checklist</div><div class="in-kv-val">' + esc(rec.template_code) + ' — ' + esc(rec.template_name_en) + '</div></div>'
          + '<div><div class="in-kv-lbl">Meter (' + esc(rec.meter_label) + ')</div><div class="in-kv-val">' + esc(rec.smr) + '</div></div>'
          + '<div><div class="in-kv-lbl">Checks Evaluated</div><div class="in-kv-val">' + esc(rec.checked_count) + ' checked · ' + esc(rec.fail_count) + ' failed</div></div>'
          + '<div><div class="in-kv-lbl">Model / Serial</div><div class="in-kv-val">' + esc(rec.model) + ' · ' + esc(rec.serial_number) + '</div></div>'
          + '</div>';

        // Failed items section
        html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:14px 0 6px;">Failed Checklist Items (' + payload.failed_items.length + ')</div>';
        if (!payload.failed_items.length) {
          html += '<div style="padding:11px 12px;border-radius:9px;background:#ECFDF5;border:1px solid #A7F3D0;color:#047857;font-weight:600;margin-bottom:14px;">All evaluated checklist items conformed — zero defects raised.</div>';
        } else {
          html += '<div style="display:flex;flex-direction:column;gap:7px;margin-bottom:14px;">';
          payload.failed_items.forEach(function (fi) {
            var bCls = fi.priority === 'P1' ? 'in-badge-crit' : 'in-badge-warn';
            html += '<div style="padding:9px 11px;border:1px solid #E2E8F0;border-radius:9px;">'
              + '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:3px;">'
              + '<strong>#' + esc(fi.item_no) + ' — ' + esc(fi.text_en) + '</strong>'
              + '<span class="in-badge ' + bCls + '">' + esc(fi.priority) + ' · ' + esc(fi.label) + '</span>'
              + '</div>'
              + (fi.comment ? '<div style="font-size:11.5px;color:#475569;">Note: ' + esc(fi.comment) + '</div>' : '')
              + '</div>';
          });
          html += '</div>';
        }

        // Sign-off matrix
        html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:14px 0 6px;">Sign-Off & Attribution</div>';
        html += '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:14px;">';
        payload.signatures.forEach(function (sg) {
          html += '<div style="padding:8px 10px;border:1px solid #E2E8F0;border-radius:8px;">'
            + '<div class="in-kv-lbl">' + esc(sg.role) + '</div>'
            + '<div style="font-weight:600;margin-top:2px;">' + esc(sg.name) + '</div>'
            + '<div style="font-size:11px;color:' + (sg.signed ? '#047857' : '#B45309') + ';">'
            + (sg.signed ? ('Signed · ' + esc(sg.date)) : 'Unsigned')
            + '</div></div>';
        });
        html += '</div>';

        // Linked defect backlogs
        if (payload.backlogs && payload.backlogs.length) {
          html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:14px 0 6px;">Linked Defect Backlogs (' + payload.backlogs.length + ')</div>';
          payload.backlogs.forEach(function (bl) {
            html += '<div style="padding:8px 10px;border:1px solid #E2E8F0;border-radius:8px;margin-bottom:6px;">'
              + '<span class="in-badge ' + (bl.priority === 'P1' ? 'in-badge-crit' : 'in-badge-warn') + '">' + esc(bl.priority) + ' · ' + esc(bl.status) + '</span> '
              + '<span style="font-weight:600;">' + esc(bl.description) + '</span>'
              + '</div>';
          });
        }

        if (rec.general_comments) {
          html += '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#64748B;margin:14px 0 6px;">General Comments</div>'
            + '<div style="padding:9px 11px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;">' + esc(rec.general_comments) + '</div>';
        }

        drawerBody.innerHTML = html;

        var foot = '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
          + '<a class="in-btn in-btn-primary in-btn-sm" href="' + esc(viewBase + rec.id) + '">Open Full Form</a>'
          + '<a class="in-btn in-btn-sm" target="_blank" rel="noopener" href="' + esc(pdfBase + rec.id) + '">Download PDF</a>'
          + (rec.can_edit ? '<a class="in-btn in-btn-sm" href="' + esc(editBase + rec.id) + '">Edit Record</a>' : '')
          + '</div>'
          + '<button type="button" class="in-btn in-btn-sm" id="inDrawerDoneBtn">Close</button>';
        drawerFoot.innerHTML = foot;
        var doneBtn = document.getElementById('inDrawerDoneBtn');
        if (doneBtn) doneBtn.addEventListener('click', closeDrawer);
      })
      .catch(function () {
        drawerBody.innerHTML = '<div style="padding:18px;color:#B91C1C;">Network error while loading inspection summary.</div>';
      });
  }

  document.addEventListener('click', function (ev) {
    var opener = ev.target.closest('[data-in-open]');
    if (opener) {
      ev.preventDefault();
      openDrawer(opener.getAttribute('data-in-open'), opener);
      return;
    }
    if (ev.target.closest('[data-in-close]')) {
      ev.preventDefault();
      closeDrawer();
    }
  });

  // Global keyboard shortcuts: `/`, `Escape`, `ArrowLeft` / `ArrowRight`
  document.addEventListener('keydown', function (ev) {
    var tag = ev.target && ev.target.tagName ? ev.target.tagName.toLowerCase() : '';
    var inField = (tag === 'input' || tag === 'textarea' || tag === 'select' || (ev.target && ev.target.isContentEditable));

    if (ev.key === '/' && !inField && (!drawer || !drawer.classList.contains('is-open'))) {
      var targetInput = document.getElementById('inSearchInput') || document.getElementById('inFormSearchInput');
      if (targetInput) {
        ev.preventDefault();
        targetInput.focus();
      }
      return;
    }

    if (ev.key === 'Escape') {
      if (newModal && newModal.classList.contains('is-open')) {
        ev.preventDefault();
        closeModal();
        return;
      }
      if (drawer && drawer.classList.contains('is-open')) {
        ev.preventDefault();
        closeDrawer();
      }
      return;
    }

    if (drawer && drawer.classList.contains('is-open') && orderedIds.length > 1) {
      var idx = orderedIds.indexOf(activeId);
      if (ev.key === 'ArrowRight' && idx !== -1) {
        ev.preventDefault();
        openDrawer(orderedIds[(idx + 1) % orderedIds.length], null);
      } else if (ev.key === 'ArrowLeft' && idx !== -1) {
        ev.preventDefault();
        openDrawer(orderedIds[(idx - 1 + orderedIds.length) % orderedIds.length], null);
      }
    }
  });
})();
</script>
