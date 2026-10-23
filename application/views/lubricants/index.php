<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Lubricants — Consumption Ledger.
 *
 * Presentation follows the /team/report enterprise table design system via
 * the shared lubricants partial. All figures come from
 * Lubricants::_build_ledger_dataset(); nothing is derived beyond presentation.
 */

$lub_esc = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$f               = isset($filter) && is_array($filter) ? $filter : array();
$month_str       = Lubricant_model::clean_month($f['month'] ?? date('Y-m'));
$month_name      = date('F Y', strtotime($month_str . '-01'));
$current_month   = date('Y-m');
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

include __DIR__ . '/_ui.php';

/* ── Active filter state ─────────────────────────────────────────────────── */
$filter_equipment = !empty($f['equipment_id']) ? (int)$f['equipment_id'] : 0;
$filter_lube      = !empty($f['lube_type_id']) ? (int)$f['lube_type_id'] : 0;
$filter_category  = isset($f['category']) ? (string)$f['category'] : '';

$equipment_label = '';
foreach ($equipment as $eq_row) {
    if ((int)$eq_row->id === $filter_equipment) { $equipment_label = (string)$eq_row->registration_no; break; }
}
$lube_label = '';
foreach ($lube_types as $lt_row) {
    if ((int)$lt_row->id === $filter_lube) { $lube_label = (string)$lt_row->name; break; }
}

$tab_labels = array(
    'all'           => 'All Records',
    'over_variance' => 'Over-Spec Variance',
    'linked_wo'     => 'Linked to WO',
    'engine_hyd'    => 'Engine & Hydraulic',
    'unlinked'      => 'No WO Link',
);
$tab_label = isset($tab_labels[$tab]) ? $tab_labels[$tab] : $tab;

$has_active_filters = ($tab !== 'all' || $q !== '' || $filter_equipment > 0
    || $filter_lube > 0 || $filter_category !== '' || $month_str !== $current_month);

$clean_reset_url = base_url('lubricants');

/* ── Column header helper: shared SVG sort indicator + ARIA sort state ──── */
$lub_th = function ($col, $label, $align = 'left', $col_class = '') use ($sort, $dir, $lub_url) {
    $is_active = ($sort === $col);
    $next_dir  = $is_active ? (($dir === 'asc') ? 'desc' : 'asc') : 'asc';
    $aria_sort = $is_active ? (($dir === 'asc') ? 'ascending' : 'descending') : 'none';
    $href      = $lub_url('lubricants', array('sort' => $col, 'dir' => $next_dir, 'page' => 1));
    $cls       = 'lub-th-sort' . ($align === 'right' ? ' is-right' : '') . ($is_active ? ' is-sorted' : '');

    $icon = '<svg class="lub-sort-ico" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">';
    if ($is_active && $dir === 'asc') {
        $icon .= '<path d="M6 2.5L9.2 6.2H2.8L6 2.5Z" fill="currentColor"/><path d="M6 9.5L2.8 7.2H9.2L6 9.5Z" fill="currentColor" opacity="0.22"/>';
    } elseif ($is_active && $dir === 'desc') {
        $icon .= '<path d="M6 2.5L9.2 4.8H2.8L6 2.5Z" fill="currentColor" opacity="0.22"/><path d="M6 9.5L2.8 5.8H9.2L6 9.5Z" fill="currentColor"/>';
    } else {
        $icon .= '<path d="M6 2.5L8.8 5.3H3.2L6 2.5ZM6 9.5L3.2 6.7H8.8L6 9.5Z" fill="currentColor" opacity="0.35"/>';
    }
    $icon .= '</svg>';

    $th_class = trim(($col_class !== '' ? $col_class . ' ' : '') . ($align === 'right' ? 'lub-num' : ''));

    return '<th scope="col" aria-sort="' . $aria_sort . '" class="' . $th_class . '">'
         . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . $cls . '">'
         . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>' . $icon
         . '</a></th>';
};

/**
 * OEM variance → badge tone.
 * Over-spec is a cost/compliance risk (danger); under-spec means the machine
 * may be under-lubricated (warning); within 0.1 of the standard is on target.
 */
$lub_var_badge = function ($variance, $unit) use ($lub_esc) {
    if ($variance === NULL) {
        return '<span class="lub-note-pill">No OEM std</span>';
    }
    $v = (float)$variance;
    if ($v > 0.0001) {
        return '<span class="badge b-danger">+' . $lub_esc(number_format($v, 1) . ' ' . $unit) . '</span>';
    }
    if ($v < -0.0001) {
        return '<span class="badge b-warning">' . $lub_esc(number_format($v, 1) . ' ' . $unit) . '</span>';
    }
    return '<span class="badge b-success">On std</span>';
};

/** Dispensed vs OEM standard, as a capped bar with the true percentage. */
$lub_oem_bar = function ($used, $recommended) use ($lub_esc) {
    if ($recommended === NULL || (float)$recommended <= 0) {
        return '<span class="lub-note-pill">—</span>';
    }
    $pct = ((float)$used / (float)$recommended) * 100;
    $tone = ($pct > 110) ? 'bad' : (($pct >= 90) ? 'good' : 'warn');
    return '<span class="lub-rate-wrap lub-rate-' . $tone . '" title="' . $lub_esc(number_format($pct, 0) . '% of OEM standard') . '">'
         . '<span class="lub-rate-pct">' . $lub_esc(number_format($pct, 0) . '%') . '</span>'
         . '<span class="lub-rate-bar" style="width:34px" aria-hidden="true">'
         . '<span class="lub-rate-fill" style="width:' . max(4, min(100, (int)round($pct))) . '%"></span></span></span>';
};

/** Machine identity mark — two leading characters of the registration. */
$lub_mark = function ($reg) {
    $clean = trim((string)$reg);
    if ($clean === '') return '—';
    return strtoupper(function_exists('mb_substr') ? mb_substr($clean, 0, 2, 'UTF-8') : substr($clean, 0, 2));
};

/* ── Print banner meta ───────────────────────────────────────────────────── */
$print_bits = array('Period: <strong>' . $lub_esc($month_name) . '</strong>');
$print_bits[] = 'Records: <strong>' . (int)$tot_filt . '</strong>';
if ($filter_equipment > 0 && $equipment_label !== '') $print_bits[] = 'Machine: ' . $lub_esc($equipment_label);
if ($filter_lube > 0 && $lube_label !== '')           $print_bits[] = 'Fluid: ' . $lub_esc($lube_label);
if ($filter_category !== '')                          $print_bits[] = 'Category: ' . $lub_esc($filter_category);
if ($tab !== 'all')                                   $print_bits[] = 'Segment: ' . $lub_esc($tab_label);
if ($q !== '')                                        $print_bits[] = 'Search: &ldquo;' . $lub_esc($q) . '&rdquo;';
$print_meta = implode(' &middot; ', $print_bits);

$header_actions = '<a href="' . $lub_esc($lub_url('lubricants/export_csv', array(), array('page', 'per_page'))) . '" id="lubExportCsvBtn" class="btn btn-ghost btn-sm">Export CSV</a>'
    . '<a href="' . $lub_esc($lub_url('lubricants/export_xlsx', array(), array('page', 'per_page'))) . '" id="lubExportXlsxBtn" class="btn btn-ghost btn-sm">Export Excel</a>';
if ($can_view_rpt) {
    $header_actions .= '<a href="' . $lub_esc(base_url('reports/lube_compliance?month=' . $month_str)) . '" class="btn btn-ghost btn-sm">Compliance Report</a>';
}
if ($can_write) {
    $header_actions .= '<a href="' . base_url('lubricants/record') . '" id="lubRecordBtn" class="btn btn-primary btn-sm">+ Record Consumption</a>';
}
?>
<style>
/* Page-specific layout: view switching + responsive table/card behaviour. */
.lub-compliance-card {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-lg, 18px); box-shadow: var(--shadow-card);
  overflow: hidden; margin-bottom: 14px;
}
.lub-compliance-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 10px; padding: 12px 16px 16px;
}
.lub-compliance-item {
  padding: 10px 12px; border-radius: 10px;
  border: 1px solid var(--sep); background: var(--surface-2); min-width: 0;
}
.lub-compliance-head {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.lub-compliance-name { font-weight: 600; color: var(--t1); font-size: 12.5px; min-width: 0; }
.lub-compliance-figures {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  margin-top: 7px; font-size: 11.5px; color: var(--t3);
}

.lub-col-chk  { width: 34px; text-align: center !important; }
.lub-col-date { width: 8%; }
.lub-col-asset{ width: 15%; }
.lub-col-lube { width: 15%; }
.lub-col-qty  { width: 9%; }
.lub-col-std  { width: 12%; }
.lub-col-var  { width: 10%; }
.lub-col-cost { width: 9%; }
.lub-col-wo   { width: 10%; }
.lub-col-act  { width: 140px; }

/* View mode switching (table | groups | cards) */
#lubShell[data-view="groups"] .lub-table-wrap,
#lubShell[data-view="groups"] .lub-cards-container { display: none; }
#lubShell[data-view="groups"] .lub-groups-container { display: block; }

#lubShell[data-view="cards"] .lub-table-wrap,
#lubShell[data-view="cards"] .lub-groups-container { display: none; }
#lubShell[data-view="cards"] .lub-cards-container { display: block; }

@media (max-width: 900px) {
  /* Cards replace the dense table unless the reader explicitly chose Table. */
  #lubShell:not([data-view="groups"]) .lub-cards-container { display: block !important; }
  #lubShell:not([data-view="groups"]).lub-user-forced-table .lub-cards-container { display: none !important; }
  #lubShell[data-view="groups"] .lub-cards-container { display: none !important; }
  #lubShell:not(.lub-user-forced-table) .lub-table-wrap { display: none !important; }
  #lubShell .lub-view-btn[data-lub-view="table"] { display: none; }
  .lub-col-chk { display: none; }
}
</style>

<?= $lub_print_banner('Lubricant Consumption Ledger', $print_meta) ?>

<div id="lubShell" class="lub-page" data-view="<?= $lub_esc($view_mode) ?>">

  <?= $lub_nav('ledger') ?>

  <?= $lub_page_head(array(
      'crumb'    => array(array('Lubricants', 'lubricants'), array('Consumption Ledger', NULL)),
      'title'    => 'Lubricants & Consumables',
      'pill'     => (int)$tot_filt . ' issue(s) · ' . $month_name,
      'subtitle' => 'Fluid and grease dispensed to fleet machines, measured against OEM standards and linked work orders.',
      'actions'  => $header_actions,
  )) ?>

  <!-- ── KPI strip ── -->
  <div class="lub-kpi-grid is-5 no-print">
    <?= $lub_kpi('Period Issues', (int)$kpi['records'], (int)$kpi['linked_wo_count'] . ' linked to work orders') ?>
    <?= $lub_kpi('Volume Dispensed', number_format((float)$kpi['total_qty'], 1), 'Across ' . count($summary) . ' active fluid grade(s)') ?>
    <?= $lub_kpi('Estimated Issue Value', $lub_esc($ccy) . ' ' . number_format((float)$kpi['actual_cost'], 0), 'Budget ' . $lub_esc($ccy) . ' ' . number_format((float)$kpi['budget_cost'], 0)) ?>
    <?= $lub_kpi('Machines Serviced', (int)$kpi['machines_count'], 'Unique fleet assets in ' . $lub_esc($month_str), 'good') ?>
    <?= $lub_kpi('Over-Spec Variances', (int)$kpi['over_spec_count'], 'Issues exceeding OEM standard', (int)$kpi['over_spec_count'] > 0 ? 'bad' : '') ?>
  </div>

  <?php if (!empty($compliance)): ?>
  <!-- ── PM requirement vs actual dispensed ── -->
  <div class="lub-compliance-card no-print">
    <div class="lub-panel-head">
      <div>
        <div class="lub-panel-title">PM Requirement vs Actual Dispensed</div>
        <p class="lub-panel-sub">Scheduled PM fluid demand compared with recorded store issues for <?= $lub_esc($month_name) ?>.</p>
      </div>
    </div>
    <div class="lub-compliance-grid">
      <?php foreach ($compliance as $cp):
        $cp_req = (float)$cp['required_qty'];
        $cp_act = (float)$cp['actual_qty'];
        $cp_pct = $cp['variance_pct'];
        if ($cp_pct === NULL)  { $cp_kind = 'secondary'; $cp_lbl = 'Unscheduled'; }
        elseif ($cp_pct > 10)  { $cp_kind = 'danger';    $cp_lbl = 'Over-using +' . $cp_pct . '%'; }
        elseif ($cp_pct < -10) { $cp_kind = 'warning';   $cp_lbl = 'Under-using ' . $cp_pct . '%'; }
        else                   { $cp_kind = 'success';   $cp_lbl = 'On target'; }
        $cp_ratio = $cp_req > 0 ? ($cp_act / $cp_req) * 100 : 0;
        $cp_tone  = ($cp_pct === NULL) ? 'good' : (($cp_pct > 10) ? 'bad' : (($cp_pct < -10) ? 'warn' : 'good'));
      ?>
      <div class="lub-compliance-item">
        <div class="lub-compliance-head">
          <span class="lub-compliance-name"><?= $lub_esc($cp['lube_name']) ?></span>
          <?= $lub_badge($cp_lbl, $cp_kind) ?>
        </div>
        <div class="lub-compliance-figures">
          <span>
            PM std <strong style="color:var(--t1);"><?= number_format($cp_req, 1) ?> <?= $lub_esc($cp['unit']) ?></strong>
            · issued <strong style="color:var(--t1);"><?= number_format($cp_act, 1) ?> <?= $lub_esc($cp['unit']) ?></strong>
          </span>
        </div>
        <div style="margin-top:7px;">
          <?= $lub_bar($cp_ratio, $cp_tone, number_format($cp_ratio, 0) . '%', 64) ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Ledger surface ── -->
  <div class="lub-surface">

    <!-- Scope filters -->
    <form method="get" action="<?= base_url('lubricants') ?>" id="lubFilterForm" class="lub-scope-bar no-print">
      <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= $lub_esc($tab) ?>"><?php endif; ?>
      <?php if ($sort !== 'date'): ?><input type="hidden" name="sort" value="<?= $lub_esc($sort) ?>"><?php endif; ?>
      <?php if ($dir !== 'desc'): ?><input type="hidden" name="dir" value="<?= $lub_esc($dir) ?>"><?php endif; ?>
      <?php if ($filter_category !== ''): ?><input type="hidden" name="category" value="<?= $lub_esc($filter_category) ?>"><?php endif; ?>
      <input type="hidden" name="view" id="lubViewInput" value="<?= $lub_esc($view_mode) ?>">

      <div class="lub-field">
        <label for="lubMonth">Month</label>
        <input type="month" id="lubMonth" name="month" class="lub-input lub-autosubmit"
               value="<?= $lub_esc($month_str) ?>" aria-label="Filter month">
      </div>

      <div class="lub-field">
        <label for="lubEquipment">Fleet Asset</label>
        <select name="equipment_id" id="lubEquipment" class="lub-select lub-autosubmit" style="max-width:180px;" aria-label="Filter by fleet asset">
          <option value="">All Fleet Assets</option>
          <?php foreach ($equipment as $eq): ?>
          <option value="<?= (int)$eq->id ?>" <?= ($filter_equipment === (int)$eq->id) ? 'selected' : '' ?>>
            <?= $lub_esc($eq->registration_no) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="lub-field">
        <label for="lubLubeType">Fluid Type</label>
        <select name="lube_type_id" id="lubLubeType" class="lub-select lub-autosubmit" style="max-width:170px;" aria-label="Filter by lubricant type">
          <option value="">All Lube Types</option>
          <?php foreach ($lube_types as $lt): ?>
          <option value="<?= (int)$lt->id ?>" <?= ($filter_lube === (int)$lt->id) ? 'selected' : '' ?>>
            <?= $lub_esc($lt->name) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="lub-scope-actions">
        <button type="submit" class="btn btn-ghost btn-sm">Apply</button>
        <a href="<?= $lub_esc($clean_reset_url) ?>" class="btn btn-ghost btn-sm">Reset</a>
      </div>
    </form>

    <!-- Tabs · search · view toggle -->
    <div class="lub-toolbar no-print">
      <div class="lub-tabs" role="tablist" aria-label="Consumption segments">
        <?php
        $tabDefs = array(
            'all'           => array('All Records', $tab_counts['all']),
            'over_variance' => array('Over-Spec', $tab_counts['over_variance']),
            'linked_wo'     => array('Linked to WO', $tab_counts['linked_wo']),
            'engine_hyd'    => array('Engine & Hydraulic', $tab_counts['engine_hyd']),
            'unlinked'      => array('No WO Link', $tab_counts['unlinked']),
        );
        foreach ($tabDefs as $tk => $meta):
        ?>
        <a href="<?= $lub_esc($lub_url('lubricants', array('tab' => $tk === 'all' ? '' : $tk, 'page' => 1))) ?>"
           role="tab"
           aria-selected="<?= $tab === $tk ? 'true' : 'false' ?>"
           class="lub-tab <?= $tab === $tk ? 'is-active' : '' ?>">
          <span><?= $lub_esc($meta[0]) ?></span>
          <span class="lub-tab-cnt"><?= (int)$meta[1] ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <div class="lub-tools-right">
        <div class="lub-search-wrap">
          <svg class="lub-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search"
                 form="lubFilterForm"
                 name="q"
                 id="lubSearchInput"
                 value="<?= $lub_esc($q) ?>"
                 placeholder="Search asset, fluid, WO#, mechanic…"
                 aria-label="Search lubricant consumption by asset, fluid, work order, mechanic or notes"
                 class="lub-search-input">
          <?php if ($q !== ''): ?>
          <a href="<?= $lub_esc($lub_url('lubricants', array('q' => '', 'page' => 1))) ?>"
             class="lub-search-clear" aria-label="Clear search query" title="Clear search">&times;</a>
          <?php else: ?>
          <kbd class="lub-kbd" aria-hidden="true">/</kbd>
          <?php endif; ?>
        </div>

        <div class="lub-view-seg" role="group" aria-label="Ledger layout">
          <button type="button" class="lub-view-btn <?= $view_mode === 'table' ? 'is-active' : '' ?>"
                  aria-pressed="<?= $view_mode === 'table' ? 'true' : 'false' ?>" data-lub-view="table">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>
            Table
          </button>
          <button type="button" class="lub-view-btn <?= $view_mode === 'groups' ? 'is-active' : '' ?>"
                  aria-pressed="<?= $view_mode === 'groups' ? 'true' : 'false' ?>" data-lub-view="groups">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
            By Machine
          </button>
          <button type="button" class="lub-view-btn <?= $view_mode === 'cards' ? 'is-active' : '' ?>"
                  aria-pressed="<?= $view_mode === 'cards' ? 'true' : 'false' ?>" data-lub-view="cards">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Cards
          </button>
        </div>
      </div>
    </div>

    <p class="lub-hint no-print">Segment counts cover <?= $lub_esc($month_name) ?>, before search and the other filters.</p>

    <!-- Active filter chips -->
    <?php if ($has_active_filters): ?>
    <div class="lub-chips-bar no-print" aria-label="Active filters">
      <span class="lub-chip-label">Active filters:</span>
      <?php if ($month_str !== $current_month): ?>
      <a class="lub-chip" href="<?= $lub_esc($lub_url('lubricants', array('month' => $current_month, 'page' => 1))) ?>">
        Month: <?= $lub_esc($month_name) ?> <span class="lub-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <?php if ($filter_equipment > 0 && $equipment_label !== ''): ?>
      <a class="lub-chip" href="<?= $lub_esc($lub_url('lubricants', array('equipment_id' => '', 'page' => 1))) ?>">
        Asset: <?= $lub_esc($equipment_label) ?> <span class="lub-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <?php if ($filter_lube > 0 && $lube_label !== ''): ?>
      <a class="lub-chip" href="<?= $lub_esc($lub_url('lubricants', array('lube_type_id' => '', 'page' => 1))) ?>">
        Fluid: <?= $lub_esc($lube_label) ?> <span class="lub-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <?php if ($filter_category !== ''): ?>
      <a class="lub-chip" href="<?= $lub_esc($lub_url('lubricants', array('category' => '', 'page' => 1))) ?>">
        Category: <?= $lub_esc($filter_category) ?> <span class="lub-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <?php if ($tab !== 'all'): ?>
      <a class="lub-chip" href="<?= $lub_esc($lub_url('lubricants', array('tab' => '', 'page' => 1))) ?>">
        Segment: <?= $lub_esc($tab_label) ?> <span class="lub-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <?php if ($q !== ''): ?>
      <a class="lub-chip" href="<?= $lub_esc($lub_url('lubricants', array('q' => '', 'page' => 1))) ?>">
        Search: &ldquo;<?= $lub_esc($q) ?>&rdquo; <span class="lub-chip-x" aria-hidden="true">&times;</span>
      </a>
      <?php endif; ?>
      <a href="<?= $lub_esc($clean_reset_url) ?>" class="lub-chip-reset">Reset all</a>
    </div>
    <?php endif; ?>

    <!-- Contextual batch bar -->
    <div class="lub-batch-bar no-print" id="lubBatchBar" role="region" aria-label="Selected consumption records actions">
      <div class="lub-batch-count"><span id="lubSelectedCount">0</span> record(s) selected</div>
      <div class="lub-batch-actions">
        <a href="<?= $lub_esc(base_url('lubricants/export_csv')) ?>" id="lubBatchExportBtn" class="btn btn-primary btn-sm">Export Selected CSV</a>
        <a href="<?= $lub_esc(base_url('lubricants/export_xlsx')) ?>" id="lubBatchXlsxBtn" class="btn btn-secondary btn-sm">Export Selected XLSX</a>
        <button type="button" id="lubBatchClearBtn" class="btn btn-ghost btn-sm">Clear selection</button>
      </div>
    </div>

    <?php if (empty($consumption)): ?>
      <!-- Empty / zero-match state -->
      <?php if ($has_active_filters): ?>
        <?= $lub_empty(
            'No lubricant issues match your filters',
            'No consumption record matched the selected month, asset, fluid, segment, or search query.',
            '<a href="' . $lub_esc($clean_reset_url) . '" class="btn btn-secondary">Reset all filters</a>'
        ) ?>
      <?php else: ?>
        <?= $lub_empty(
            'No lubricant issues recorded for ' . $month_name,
            'Nothing has been issued from the store in this period. Record a consumption entry to start the ledger.',
            $can_write ? '<a href="' . base_url('lubricants/record') . '" class="btn btn-primary">+ Record Consumption</a>' : ''
        ) ?>
      <?php endif; ?>
    <?php else: ?>

      <!-- ── 1. Ledger table ── -->
      <div class="lub-table-wrap">
        <table class="lub-table">
          <thead>
            <tr>
              <th scope="col" class="lub-col-chk">
                <input type="checkbox" id="lubSelectAll" aria-label="Select all records on this page">
              </th>
              <?= $lub_th('date', 'Date', 'left', 'lub-col-date') ?>
              <?= $lub_th('equipment', 'Fleet Asset', 'left', 'lub-col-asset') ?>
              <?= $lub_th('lube', 'Fluid & Grade', 'left', 'lub-col-lube') ?>
              <?= $lub_th('qty', 'Dispensed', 'right', 'lub-col-qty') ?>
              <th scope="col" class="lub-col-std lub-num">OEM Std</th>
              <?= $lub_th('variance', 'Variance', 'left', 'lub-col-var') ?>
              <?= $lub_th('cost', 'Cost (' . $ccy . ')', 'right', 'lub-col-cost') ?>
              <th scope="col" class="lub-col-wo">WO / Staff</th>
              <th scope="col" class="lub-col-act" style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($consumption as $c):
            $var_val = $c->variance !== NULL ? (float)$c->variance : NULL;
          ?>
            <tr data-lub-row="<?= (int)$c->id ?>">
              <td class="lub-col-chk">
                <input type="checkbox" class="lub-row-check" value="<?= (int)$c->id ?>"
                       aria-label="Select issue #<?= (int)$c->id ?>">
              </td>
              <td class="lub-col-date">
                <div class="lub-cell-strong lub-num-txt" style="font-size:11.5px;font-weight:500;color:var(--t2);">
                  <?= !empty($c->consumption_date) ? date('d M Y', strtotime($c->consumption_date)) : '—' ?>
                </div>
                <div class="lub-cell-sub lub-mono">#<?= (int)$c->id ?></div>
              </td>
              <td class="lub-col-asset">
                <div class="lub-entity">
                  <span class="lub-avatar" aria-hidden="true"><?= $lub_esc($lub_mark($c->registration_no)) ?></span>
                  <div class="lub-entity-info">
                    <button type="button" class="lub-entity-name lub-mono" data-lub-open="<?= (int)$c->id ?>"
                            title="<?= $lub_esc($c->registration_no) ?>">
                      <?= $lub_esc($c->registration_no) ?>
                    </button>
                    <div class="lub-entity-meta">
                      <?php if ($c->equipment_hours !== NULL): ?>
                        <span><?= number_format((float)$c->equipment_hours, 0) ?> hrs</span>
                      <?php else: ?>
                        <span><?= $lub_esc(trim(($c->brand ?? '') . ' ' . ($c->model ?? '')) ?: '—') ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </td>
              <td class="lub-col-lube">
                <div class="lub-cell-strong" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= $lub_esc($c->lube_name) ?></div>
                <div class="lub-cell-sub"><?= $lub_esc($c->category) ?><?= !empty($c->lube_grade) ? ' · ' . $lub_esc($c->lube_grade) : '' ?></div>
              </td>
              <td class="lub-col-qty lub-num">
                <span class="lub-val-strong"><?= number_format((float)$c->quantity_used, 1) ?></span>
                <span class="lub-code"><?= $lub_esc($c->unit) ?></span>
              </td>
              <td class="lub-col-std lub-num">
                <?= $lub_oem_bar($c->quantity_used, $c->recommended_qty) ?>
                <?php if ($c->recommended_qty !== NULL): ?>
                <div class="lub-cell-sub lub-num-txt">std <?= number_format((float)$c->recommended_qty, 1) ?> <?= $lub_esc($c->unit) ?></div>
                <?php endif; ?>
              </td>
              <td class="lub-col-var"><?= $lub_var_badge($var_val, (string)$c->unit) ?></td>
              <td class="lub-col-cost lub-num">
                <span class="lub-num-txt" style="font-size:12px;color:var(--t1);"><?= number_format((float)($c->line_cost ?? 0), 2) ?></span>
              </td>
              <td class="lub-col-wo">
                <?php if (!empty($c->wo_number)): ?>
                <a href="<?= base_url('workorders/view/' . (int)$c->work_order_id) ?>"
                   class="lub-mono" style="font-size:11.5px;color:var(--blue);font-weight:600;text-decoration:none;">
                  <?= $lub_esc($c->wo_number) ?>
                </a>
                <?php else: ?>
                <span class="lub-muted-zero" style="font-size:11.5px;">—</span>
                <?php endif; ?>
                <div class="lub-cell-sub"><?= $lub_esc(trim((string)($c->recorded_by_name ?: 'System'))) ?></div>
              </td>
              <td class="lub-col-act">
                <div class="lub-row-actions">
                  <button type="button" class="lub-act-btn is-primary" data-lub-open="<?= (int)$c->id ?>" title="Inspect fluid issue dossier">Inspect</button>
                  <a href="<?= base_url('equipment/view/' . (int)$c->equipment_id) ?>" class="lub-act-btn" title="Open machine history">Machine</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- ── 2. By machine ── -->
      <div class="lub-groups-container">
        <?php foreach ($groups as $grp): ?>
        <div class="lub-group-card">
          <div class="lub-group-hdr">
            <div class="lub-group-title">
              <span class="lub-mono"><?= $lub_esc($grp->registration_no) ?></span>
              <?php if (!empty($grp->model_label)): ?>
              <span style="font-weight:400;color:var(--t3);font-size:12px;">— <?= $lub_esc($grp->model_label) ?></span>
              <?php endif; ?>
            </div>
            <div class="lub-group-meta">
              <?= count($grp->records) ?> issue(s) on this page · estimated value <?= $lub_esc($ccy) ?> <?= number_format((float)$grp->total_cost, 2) ?>
            </div>
          </div>
          <div class="lub-group-grid">
            <?php foreach ($grp->records as $gr): ?>
            <div class="lub-group-item">
              <div style="min-width:0;">
                <button type="button" class="lub-entity-name" data-lub-open="<?= (int)$gr->id ?>"><?= $lub_esc($gr->lube_name) ?></button>
                <div class="lub-cell-sub">
                  <?= date('d M Y', strtotime($gr->consumption_date)) ?><?= !empty($gr->wo_number) ? ' · ' . $lub_esc($gr->wo_number) : '' ?>
                </div>
              </div>
              <div class="lub-num-txt" style="font-weight:700;color:var(--t1);flex-shrink:0;">
                <?= number_format((float)$gr->quantity_used, 1) ?> <?= $lub_esc($gr->unit) ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- ── 3. Cards ── -->
      <div class="lub-cards-container">
        <div class="lub-cards-grid">
          <?php foreach ($consumption as $cc):
            $cc_var = $cc->variance !== NULL ? (float)$cc->variance : NULL;
          ?>
          <div class="lub-card">
            <div class="lub-card-top">
              <div class="lub-entity" style="min-width:0;">
                <span class="lub-avatar" aria-hidden="true"><?= $lub_esc($lub_mark($cc->registration_no)) ?></span>
                <div class="lub-entity-info">
                  <button type="button" class="lub-entity-name lub-mono" data-lub-open="<?= (int)$cc->id ?>"><?= $lub_esc($cc->registration_no) ?></button>
                  <div class="lub-cell-sub"><?= $lub_esc($cc->lube_name) ?> · <?= date('d M Y', strtotime($cc->consumption_date)) ?></div>
                </div>
              </div>
              <?= $lub_var_badge($cc_var, (string)$cc->unit) ?>
            </div>

            <div class="lub-card-metrics">
              <div>
                <div class="lub-card-metric-k">Dispensed</div>
                <div class="lub-card-metric-v"><?= number_format((float)$cc->quantity_used, 1) ?> <?= $lub_esc($cc->unit) ?></div>
              </div>
              <div>
                <div class="lub-card-metric-k">OEM Std</div>
                <div class="lub-card-metric-v"><?= $cc->recommended_qty !== NULL ? number_format((float)$cc->recommended_qty, 1) : '—' ?></div>
              </div>
              <div>
                <div class="lub-card-metric-k">Cost (<?= $lub_esc($ccy) ?>)</div>
                <div class="lub-card-metric-v"><?= number_format((float)($cc->line_cost ?? 0), 0) ?></div>
              </div>
            </div>

            <div class="lub-card-foot">
              <span class="lub-cell-sub" style="margin:0;">
                <?= !empty($cc->wo_number) ? $lub_esc($cc->wo_number) : $lub_esc(trim((string)($cc->recorded_by_name ?: 'System'))) ?>
              </span>
              <div style="display:flex;gap:6px;flex-shrink:0;">
                <button type="button" class="lub-act-btn is-primary" data-lub-open="<?= (int)$cc->id ?>">Inspect</button>
                <a href="<?= base_url('equipment/view/' . (int)$cc->equipment_id) ?>" class="lub-act-btn">Machine</a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php endif; ?>

    <!-- Pagination footer -->
    <div class="lub-footer no-print">
      <div>
        <?php if ($tot_filt === 0): ?>
          Showing <strong>0</strong> issues
        <?php else: ?>
          Showing <?= (int)($offset_val + 1) ?>–<?= (int)min($tot_filt, $offset_val + count($consumption)) ?>
          of <strong><?= (int)$tot_filt ?></strong> issue(s) · <?= $lub_esc($month_name) ?>
        <?php endif; ?>
      </div>

      <?php if ($tot_pages > 1): ?>
      <nav class="lub-pages lub-pager" aria-label="Consumption ledger pagination">
        <a class="lub-page-link <?= $cur_page <= 1 ? 'is-disabled' : '' ?>"
           href="<?= $lub_esc($lub_url('lubricants', array('page' => max(1, $cur_page - 1)))) ?>">Prev</a>
        <?php
        $win_start = max(1, $cur_page - 2);
        $win_end   = min($tot_pages, $cur_page + 2);
        if ($win_start > 1): ?>
          <a class="lub-page-link" href="<?= $lub_esc($lub_url('lubricants', array('page' => 1))) ?>">1</a>
          <?php if ($win_start > 2): ?><span class="lub-pager-ellipsis">&hellip;</span><?php endif; ?>
        <?php endif;
        for ($p = $win_start; $p <= $win_end; $p++): ?>
          <a class="lub-page-link <?= $p === $cur_page ? 'is-current' : '' ?>"
             <?= $p === $cur_page ? 'aria-current="page"' : '' ?>
             href="<?= $lub_esc($lub_url('lubricants', array('page' => $p))) ?>"><?= $p ?></a>
        <?php endfor;
        if ($win_end < $tot_pages): ?>
          <?php if ($win_end < $tot_pages - 1): ?><span class="lub-pager-ellipsis">&hellip;</span><?php endif; ?>
          <a class="lub-page-link" href="<?= $lub_esc($lub_url('lubricants', array('page' => $tot_pages))) ?>"><?= $tot_pages ?></a>
        <?php endif; ?>
        <a class="lub-page-link <?= $cur_page >= $tot_pages ? 'is-disabled' : '' ?>"
           href="<?= $lub_esc($lub_url('lubricants', array('page' => min($tot_pages, $cur_page + 1)))) ?>">Next</a>
      </nav>
      <?php endif; ?>

      <div class="lub-rows">
        <label for="lubPerPage">Rows:</label>
        <select id="lubPerPage" class="lub-select" aria-label="Rows per page">
          <?php foreach ($allowed_pp as $pp): ?>
          <option value="<?= $lub_esc($lub_url('lubricants', array('per_page' => $pp, 'page' => 1))) ?>"
                  <?= $per_page === (int)$pp ? 'selected' : '' ?>><?= (int)$pp ?> / page</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

  </div>
</div>

<!-- ── Slide-over fluid issue dossier ── -->
<div class="lub-drawer-backdrop no-print" id="lubDrawerBackdrop"></div>
<aside class="lub-drawer no-print" id="lubDrawer" role="dialog" aria-modal="true" aria-labelledby="lubDrawerTitle" aria-hidden="true">
  <div class="lub-drawer-hdr">
    <div style="min-width:0;">
      <div class="lub-drawer-eyebrow">Fluid Issue Dossier</div>
      <h2 class="lub-drawer-title" id="lubDrawerTitle">Consumption Record</h2>
    </div>
    <button type="button" class="lub-act-btn" id="lubDrawerClose" aria-label="Close consumption inspector" title="Close inspector (Esc)">&times;</button>
  </div>
  <div class="lub-drawer-body" id="lubDrawerBody">
    <div style="color:var(--t3);font-size:12.5px;">Select a fluid issue record to inspect OEM variance, meter reading, and work order traceability.</div>
  </div>
  <div class="lub-drawer-ftr">
    <div class="lub-drawer-nav">
      <button type="button" class="lub-act-btn" id="lubPrevBtn" aria-label="Previous record" title="Previous record (Left arrow)">&larr;</button>
      <button type="button" class="lub-act-btn" id="lubNextBtn" aria-label="Next record" title="Next record (Right arrow)">&rarr;</button>
    </div>
    <div class="lub-drawer-ctas" id="lubDrawerCtas"></div>
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

  // 1. Scope filters apply immediately on change; Apply stays for keyboard/no-JS.
  var filterForm = document.getElementById('lubFilterForm');
  Array.prototype.forEach.call(document.querySelectorAll('.lub-autosubmit'), function (input) {
    input.addEventListener('change', function () { if (filterForm) filterForm.submit(); });
  });

  // 2. Rows-per-page navigates directly.
  var perPageSel = document.getElementById('lubPerPage');
  if (perPageSel) {
    perPageSel.addEventListener('change', function () {
      if (perPageSel.value) window.location.href = perPageSel.value;
    });
  }

  // 3. View switcher — instant, and survives navigation.
  Array.prototype.forEach.call(document.querySelectorAll('[data-lub-view]'), function (btn) {
    btn.addEventListener('click', function () {
      var mode = btn.getAttribute('data-lub-view') || 'table';
      shell.setAttribute('data-view', mode);
      // A deliberate Table choice must survive the mobile card fallback.
      shell.classList.toggle('lub-user-forced-table', mode === 'table');
      var viewInput = document.getElementById('lubViewInput');
      if (viewInput) viewInput.value = mode;
      Array.prototype.forEach.call(document.querySelectorAll('[data-lub-view]'), function (b) {
        var active = b === btn;
        b.classList.toggle('is-active', active);
        b.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      var url = new URL(window.location.href);
      if (mode === 'table') url.searchParams.delete('view');
      else url.searchParams.set('view', mode);
      window.history.replaceState(null, '', url.toString());
      Array.prototype.forEach.call(document.querySelectorAll('.lub-pager a[href]'), function (link) {
        var dest = new URL(link.href, window.location.href);
        if (mode === 'table') dest.searchParams.delete('view');
        else dest.searchParams.set('view', mode);
        link.href = dest.toString();
      });
    });
  });

  // Mobile reflects the effective layout in the control.
  if (window.matchMedia('(max-width: 900px)').matches && shell.getAttribute('data-view') === 'table') {
    shell.setAttribute('data-view', 'cards');
    var tableButton = document.querySelector('[data-lub-view="table"]');
    var cardsButton = document.querySelector('[data-lub-view="cards"]');
    if (tableButton) { tableButton.classList.remove('is-active'); tableButton.setAttribute('aria-pressed', 'false'); }
    if (cardsButton) { cardsButton.classList.add('is-active'); cardsButton.setAttribute('aria-pressed', 'true'); }
  }

  // 4. Multi-select + batch export.
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

  // 5. Slide-over dossier — JSON cached per record, arrows stop at the edges.
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
  var requestSeq = 0;
  var sheetCache = {};

  function orderedIds() {
    var seen = {};
    var out = [];
    Array.prototype.forEach.call(document.querySelectorAll('[data-lub-open]'), function (b) {
      var id = b.getAttribute('data-lub-open');
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
    prevBtn.classList.toggle('lub-drawer-na', atStart);
    nextBtn.classList.toggle('lub-drawer-na', atEnd);
  }

  function closeDrawer() {
    requestSeq++;
    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
  }

  function renderRecord(rec) {
    titleEl.textContent = rec.registration_no + ' · ' + rec.lube_name + ' (#' + rec.id + ')';
    var varLabel = rec.variance !== null
      ? ((rec.variance > 0 ? '+' : '') + rec.variance.toFixed(2) + ' ' + rec.unit)
      : 'No OEM baseline';

    bodyEl.innerHTML =
      '<div class="lub-section-title">OEM Specification &amp; Variance</div>'
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
      renderRecord(sheetCache[currentId]);
      syncDrawerNav();
      return;
    }

    bodyEl.innerHTML = '<div style="padding:18px 0;color:var(--t3);font-size:12.5px;">Loading fluid issue dossier…</div>';

    fetch(summaryBase + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (thisRequest !== requestSeq) return;
        if (!res || !res.ok || !res.record) {
          bodyEl.innerHTML = '<div style="color:var(--red);">Unable to load consumption record.</div>';
          return;
        }
        sheetCache[currentId] = res.record;
        renderRecord(res.record);
        syncDrawerNav();
      })
      .catch(function () {
        if (thisRequest !== requestSeq) return;
        bodyEl.innerHTML = '<div style="color:var(--red);">Network error loading consumption dossier.</div>';
      });
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-lub-open]'), function (btn) {
    btn.addEventListener('click', function () {
      openDrawer(btn.getAttribute('data-lub-open'), btn);
    });
  });

  function stepDrawer(delta) {
    var list = orderedIds();
    if (!list.length || !currentId) return;
    var idx = list.indexOf(currentId);
    if (idx === -1) return;
    var nextIdx = idx + delta;
    if (nextIdx < 0 || nextIdx >= list.length) return; // stop at the boundaries
    openDrawer(list[nextIdx], null);
  }

  if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
  if (backdrop) backdrop.addEventListener('click', closeDrawer);
  if (prevBtn) prevBtn.addEventListener('click', function () { stepDrawer(-1); });
  if (nextBtn) nextBtn.addEventListener('click', function () { stepDrawer(1); });

  // 6. Keyboard: "/" search, Esc close, arrows browse the dossier.
  document.addEventListener('keydown', function (ev) {
    var tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
    var inField = (tag === 'input' || tag === 'textarea' || tag === 'select' || (ev.target && ev.target.isContentEditable));
    if (ev.key === '/' && !inField && !drawer.classList.contains('is-open')) {
      var sInput = document.getElementById('lubSearchInput');
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
    if (ev.key === 'Escape') { ev.preventDefault(); closeDrawer(); }
    else if (ev.key === 'ArrowRight') { ev.preventDefault(); stepDrawer(1); }
    else if (ev.key === 'ArrowLeft') { ev.preventDefault(); stepDrawer(-1); }
  });
})();
</script>
