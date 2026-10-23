<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Lubricants suite — shared presentation layer.
 *
 * One design system for all six surfaces (ledger, budget, specifications,
 * types, record, forecast), mirroring the /team/report enterprise table
 * design: page header, KPI strip, scope filters, tabs, search, view toggle,
 * filter chips, batch bar, sticky table, empty states, pagination, drawer
 * and a clean print layout.
 *
 * View layer only: no data is sourced or shaped here. Every value a page
 * renders still comes from the controller.
 */

if (!isset($lub_esc)) {
    $lub_esc = function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };
}

$lub_company = (isset($site_settings['company_name']) && $site_settings['company_name'] !== '')
    ? (string)$site_settings['company_name']
    : 'Workshop WMS';

/* ── Suite navigation ────────────────────────────────────────────────────── */
$lub_suite_links = array(
    array('lubricants',                  'Consumption Ledger', 'ledger'),
    array('lubricants/budget',           'Lube Budget',        'budget',        TRUE),
    array('lubricants/specifications',   'OEM Specifications', 'specifications'),
    array('lubricants/types',            'Lube Types',         'types'),
    array('lubricants/forecast',         'PM Forecast',        'forecast'),
);

$lub_can_write = !empty($can_write);

/** Render the suite nav; $active is one of the keys above. */
$lub_nav = function ($active) use ($lub_suite_links, $lub_esc, $lub_can_write) {
    $out = '<nav class="lub-subnav no-print" aria-label="Lubricants suite">'
         . '<span class="lub-nav-label">Lubricants</span>';
    foreach ($lub_suite_links as $link) {
        if (!empty($link[3]) && !$lub_can_write) continue;
        $is = ($link[2] === $active);
        $out .= '<a href="' . base_url($link[0]) . '"'
              . ($is ? ' class="is-active" aria-current="page"' : '')
              . '>' . $lub_esc($link[1]) . '</a>';
    }
    return $out . '</nav>';
};

/* ── Page header ─────────────────────────────────────────────────────────── */
/**
 * $opts: crumb (array of [label, url|null]), title, pill, subtitle, actions (html)
 */
$lub_page_head = function (array $opts) use ($lub_esc) {
    $crumb = '';
    foreach ($opts['crumb'] as $c) {
        if ($crumb !== '') $crumb .= '<span aria-hidden="true">/</span>';
        $crumb .= empty($c[1])
            ? '<span>' . $lub_esc($c[0]) . '</span>'
            : '<a href="' . base_url($c[1]) . '">' . $lub_esc($c[0]) . '</a>';
    }
    $html = '<div class="lub-head no-print">'
          . '<nav class="lub-crumb" aria-label="Breadcrumb">' . $crumb . '</nav>'
          . '<div class="lub-head-row"><div>';
    $html .= '<div class="lub-head-title"><h1>' . $lub_esc($opts['title']) . '</h1>';
    if (!empty($opts['pill'])) {
        $html .= '<span class="lub-pill">' . $lub_esc($opts['pill']) . '</span>';
    }
    $html .= '</div>';
    if (!empty($opts['subtitle'])) {
        $html .= '<p class="lub-sub">' . $lub_esc($opts['subtitle']) . '</p>';
    }
    $html .= '</div>';
    if (!empty($opts['actions'])) {
        $html .= '<div class="lub-actions">' . $opts['actions'] . '</div>';
    }
    return $html . '</div></div>';
};

/* ── Print banner (only visible in print media) ──────────────────────────── */
$lub_print_banner = function ($title, $meta) use ($lub_esc, $lub_company) {
    return '<div class="lub-print-banner">'
         . '<h2>' . $lub_esc(strtoupper($lub_company)) . ' — ' . $lub_esc(strtoupper($title)) . '</h2>'
         . '<p>' . $meta . ' &middot; Printed ' . date('d M Y H:i') . '</p>'
         . '</div>';
};

/* ── KPI card ────────────────────────────────────────────────────────────── */
/** $tone: '' | 'good' | 'warn' | 'bad' */
$lub_kpi = function ($label, $value, $sub = '', $tone = '') use ($lub_esc) {
    $style = '';
    if ($tone === 'good') $style = ' style="color:var(--green);"';
    elseif ($tone === 'warn') $style = ' style="color:var(--amber);"';
    elseif ($tone === 'bad')  $style = ' style="color:var(--red);"';
    return '<div class="lub-kpi">'
         . '<div class="lub-kpi-k">' . $lub_esc($label) . '</div>'
         . '<div class="lub-kpi-v"' . $style . '>' . $value . '</div>'
         . ($sub !== '' ? '<div class="lub-kpi-s">' . $sub . '</div>' : '')
         . '</div>';
};

/* ── Quantitative bar (rate / utilisation / compliance) ──────────────────── */
/** $tone: 'good' | 'warn' | 'bad'. $text overrides the percentage label. */
$lub_bar = function ($pct, $tone = 'good', $text = NULL, $width = 44) use ($lub_esc) {
    $p = max(0, min(100, (int)round($pct)));
    $cls = in_array($tone, array('good', 'warn', 'bad'), TRUE) ? $tone : 'good';
    $label = ($text === NULL) ? ($p . '%') : $text;
    return '<span class="lub-rate-wrap lub-rate-' . $cls . '">'
         . '<span class="lub-rate-pct">' . $lub_esc($label) . '</span>'
         . '<span class="lub-rate-bar" style="width:' . (int)$width . 'px" aria-hidden="true">'
         . '<span class="lub-rate-fill" style="width:' . max(4, $p) . '%"></span></span></span>';
};

/* ── Semantic badge ──────────────────────────────────────────────────────── */
/** $kind: 'success' | 'danger' | 'warning' | 'secondary' | 'primary' */
$lub_badge = function ($label, $kind = 'secondary') use ($lub_esc) {
    return '<span class="badge b-' . $kind . '">' . $lub_esc($label) . '</span>';
};

/* ── Empty state ─────────────────────────────────────────────────────────── */
$lub_empty = function ($title, $body, $cta = '') use ($lub_esc) {
    return '<div class="lub-empty">'
         . '<div class="lub-empty-ico" aria-hidden="true">'
         . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
         . '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>'
         . '</div>'
         . '<h3>' . $lub_esc($title) . '</h3>'
         . '<p>' . $lub_esc($body) . '</p>'
         . ($cta !== '' ? $cta : '')
         . '</div>';
};

/* ── Shared stylesheet ───────────────────────────────────────────────────── */
?>
<style>
/* ─────────────────────────────────────────────────────────────────────────────
   LUBRICANTS SUITE — shared design system.
   Mirrors the /team/report table surface: hairline separators, one surface
   colour, restrained accents. Base tokens (--surface, --sep, --t1…--t3,
   --blue/--green/--amber/--red, radii, shadows) come from the layout.
   ───────────────────────────────────────────────────────────────────────────── */
#lubShell, #lubBudgetShell, .lub-page, .lub-drawer, .lub-menu, .lub-print-banner {
  --lub-fill: rgba(0,0,0,0.035);
  --lub-fill-2: rgba(0,0,0,0.06);
  --lub-sep-strong: rgba(0,0,0,0.14);
  --lub-blue-lt: rgba(0, 113, 227, 0.08);
  --lub-ease: cubic-bezier(.4, 0, .2, 1);
}

.lub-page { max-width: 100%; }
.lub-print-banner { display: none; }

/* ── Suite navigation ── */
.lub-subnav {
  display: flex; align-items: center; gap: 3px; flex-wrap: wrap;
  padding: 6px 8px; margin-bottom: 14px;
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-md, 14px); box-shadow: var(--shadow-card);
}
.lub-nav-label {
  padding: 0 9px 0 3px; margin-right: 3px;
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.045em; color: var(--t3);
  border-right: 1px solid var(--sep);
}
.lub-subnav a {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 11px; border-radius: var(--r-sm, 10px);
  font-size: 12.5px; font-weight: 500; color: var(--t2);
  text-decoration: none; border: 1px solid transparent;
  transition: background var(--lub-ease), color var(--lub-ease), border-color var(--lub-ease);
}
.lub-subnav a:hover { background: var(--lub-fill); color: var(--t1); }
.lub-subnav a.is-active {
  background: var(--lub-blue-lt); color: var(--blue); font-weight: 600;
  border-color: rgba(0, 113, 227, 0.22);
}

/* ── Page header ── */
.lub-head { margin-bottom: 14px; }
.lub-crumb {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; color: var(--t3); margin-bottom: 6px;
}
.lub-crumb a { color: var(--t3); text-decoration: none; }
.lub-crumb a:hover { color: var(--blue); }
.lub-head-row {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 14px; flex-wrap: wrap;
}
.lub-head h1 {
  margin: 0; font-size: 21px; font-weight: 700;
  letter-spacing: -0.02em; color: var(--t1);
}
.lub-head-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.lub-pill {
  display: inline-flex; align-items: center; padding: 3px 10px;
  border-radius: 100px; font-size: 11.5px; font-weight: 600;
  background: var(--surface); border: 1px solid var(--sep); color: var(--t2);
  font-variant-numeric: tabular-nums;
}
.lub-sub { margin: 5px 0 0; font-size: 12.5px; color: var(--t2); }
.lub-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* ── KPI strip ── */
.lub-kpi-grid {
  display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px; margin-bottom: 14px;
}
.lub-kpi-grid.is-5 { grid-template-columns: repeat(5, minmax(0, 1fr)); }
.lub-kpi {
  padding: 12px 14px; border-radius: var(--r-md, 14px);
  background: var(--surface); border: 1px solid var(--sep);
  box-shadow: var(--shadow-card); min-width: 0;
}
.lub-kpi-k {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.045em; color: var(--t3);
}
.lub-kpi-v {
  font-size: 21px; font-weight: 700; color: var(--t1);
  margin-top: 4px; line-height: 1.15; font-variant-numeric: tabular-nums;
}
.lub-kpi-s {
  font-size: 11.5px; color: var(--t3); margin-top: 3px;
  overflow: hidden; text-overflow: ellipsis;
}

/* ── Main surface ── */
.lub-surface {
  background: var(--surface); border: 1px solid var(--sep);
  border-radius: var(--r-lg, 18px); box-shadow: var(--shadow-card);
  overflow: hidden;
}
.lub-surface + .lub-surface, .lub-surface + .lub-card-panel { margin-top: 14px; }
.lub-panel-pad { padding: 16px; }
.lub-panel-head {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; padding: 14px 16px;
  border-bottom: 1px solid var(--sep);
}
.lub-panel-title { font-size: 13.5px; font-weight: 600; color: var(--t1); }
.lub-panel-sub { margin: 3px 0 0; font-size: 12px; color: var(--t2); }

/* ── Scope filter bar ── */
.lub-scope-bar {
  padding: 12px 16px; background: var(--surface-2);
  border-bottom: 1px solid var(--sep);
  display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap;
}
.lub-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.lub-field label {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--t3); margin: 0;
}
.lub-input, .lub-select {
  height: 32px; padding: 0 10px; max-width: 200px;
  font-family: inherit; font-size: 12.5px; color: var(--t1);
  background: var(--surface); border: 1px solid var(--sep-strong);
  border-radius: var(--r-sm, 10px); outline: none;
  transition: border-color var(--lub-ease), box-shadow var(--lub-ease);
}
.lub-select {
  padding-right: 28px; cursor: pointer;
  appearance: none; -webkit-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2386868b' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 8px center;
}
.lub-input:focus, .lub-select:focus {
  border-color: var(--blue); box-shadow: 0 0 0 3px var(--lub-blue-lt);
}
.lub-scope-actions { display: inline-flex; align-items: center; gap: 6px; margin-left: auto; }

/* ── Toolbar: tabs + search + view toggle ── */
.lub-toolbar {
  padding: 10px 16px; border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; background: var(--surface);
}
.lub-tabs { display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.lub-tab {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 11px; border-radius: var(--r-sm, 10px);
  font-size: 12.5px; font-weight: 500; color: var(--t2);
  text-decoration: none; border: 1px solid transparent;
  transition: background var(--lub-ease), color var(--lub-ease), border-color var(--lub-ease);
}
.lub-tab:hover { background: var(--lub-fill); color: var(--t1); }
.lub-tab.is-active {
  background: var(--lub-blue-lt); color: var(--blue); font-weight: 600;
  border-color: rgba(0, 113, 227, 0.22);
}
.lub-tab-cnt {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 18px; padding: 0 6px;
  border-radius: 100px; font-size: 11px; font-weight: 600;
  background: var(--lub-fill); color: var(--t2); font-variant-numeric: tabular-nums;
}
.lub-tab.is-active .lub-tab-cnt { background: var(--blue); color: #ffffff; }

.lub-tools-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto; }
.lub-search-wrap { position: relative; display: inline-flex; align-items: center; }
.lub-search-ico {
  position: absolute; left: 9px; width: 14px; height: 14px;
  color: var(--t3); pointer-events: none;
}
.lub-search-input {
  height: 32px; width: 250px; padding: 0 54px 0 29px;
  font-family: inherit; font-size: 12.5px; color: var(--t1);
  background: var(--surface); border: 1px solid var(--sep-strong);
  border-radius: var(--r-sm, 10px); outline: none;
  transition: border-color var(--lub-ease), box-shadow var(--lub-ease);
}
.lub-search-input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px var(--lub-blue-lt); }
.lub-kbd {
  position: absolute; right: 8px; padding: 1px 5px;
  font-family: ui-monospace, Menlo, monospace; font-size: 10.5px; color: var(--t3);
  background: var(--surface); border: 1px solid var(--sep-strong);
  border-radius: 4px; pointer-events: none; line-height: 1.3;
}
.lub-search-input:focus ~ .lub-kbd { display: none; }
.lub-search-clear {
  position: absolute; right: 6px; width: 20px; height: 20px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 50%; background: var(--lub-fill-2); color: var(--t2);
  font-size: 13px; text-decoration: none; line-height: 1;
}
.lub-search-clear:hover { background: var(--lub-sep-strong); color: var(--t1); }

.lub-view-seg {
  display: inline-flex; padding: 2px; background: var(--lub-fill);
  border-radius: var(--r-sm, 10px); border: 1px solid var(--sep);
}
.lub-view-btn {
  display: inline-flex; align-items: center; gap: 5px;
  height: 26px; padding: 0 9px; border-radius: 6px; border: none;
  background: transparent; color: var(--t2); font-family: inherit;
  font-size: 12px; font-weight: 500; cursor: pointer; text-decoration: none;
  transition: background var(--lub-ease), color var(--lub-ease);
}
.lub-view-btn:hover { color: var(--t1); }
.lub-view-btn.is-active {
  background: var(--surface); color: var(--t1); font-weight: 600;
  box-shadow: 0 1px 2px rgba(0,0,0,0.08);
}

.lub-hint {
  margin: 0; padding: 7px 16px; font-size: 11.5px; color: var(--t3);
  border-bottom: 1px solid var(--sep);
}

/* ── Active filter chips ── */
.lub-chips-bar {
  padding: 8px 16px; background: var(--surface-2); border-bottom: 1px solid var(--sep);
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 12px;
}
.lub-chip-label { color: var(--t3); font-weight: 500; margin-right: 2px; }
.lub-chip {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 2px 8px; border-radius: 100px;
  background: var(--surface); border: 1px solid var(--sep-strong);
  color: var(--t1); font-size: 11.5px; font-weight: 500; text-decoration: none;
  transition: border-color var(--lub-ease), color var(--lub-ease);
}
.lub-chip:hover { border-color: var(--red); color: var(--red); }
.lub-chip-x { color: var(--t3); font-weight: 700; }
.lub-chip:hover .lub-chip-x { color: var(--red); }
.lub-chip-reset { margin-left: auto; font-size: 12px; font-weight: 500; color: var(--blue); text-decoration: none; }
.lub-chip-reset:hover { text-decoration: underline; }

/* ── Contextual batch bar ── */
.lub-batch-bar {
  padding: 9px 16px; background: rgba(0, 113, 227, 0.06);
  border-bottom: 1px solid rgba(0, 113, 227, 0.24);
  display: none; align-items: center; justify-content: space-between;
  gap: 10px; flex-wrap: wrap;
}
.lub-batch-bar.is-open { display: flex; }
.lub-batch-count { font-size: 12.5px; font-weight: 600; color: var(--t1); }
.lub-batch-actions { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }

/* ── Enterprise table ── */
.lub-table-wrap { width: 100%; overflow: auto; max-height: calc(100vh - 205px); }
.lub-table {
  width: 100%; table-layout: fixed;
  border-collapse: separate; border-spacing: 0;
}
.lub-table thead th {
  position: sticky; top: 0; z-index: 2;
  background: var(--surface-2);
  padding: 9px 8px; text-align: left; white-space: nowrap;
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.035em; color: var(--t3);
  border-bottom: 1px solid var(--sep);
}
.lub-table thead th.lub-num { text-align: right; }
.lub-th-sort {
  display: inline-flex; align-items: center; gap: 4px;
  color: var(--t3); text-decoration: none; cursor: pointer; user-select: none;
  transition: color var(--lub-ease);
}
.lub-th-sort.is-right { flex-direction: row-reverse; }
.lub-th-sort:hover { color: var(--t1); }
.lub-th-sort.is-sorted { color: var(--blue); }
.lub-sort-ico { flex-shrink: 0; }

.lub-table tbody td {
  padding: 10px 8px; vertical-align: middle;
  border-bottom: 1px solid var(--sep);
  overflow: hidden; text-overflow: ellipsis;
}
.lub-table tbody td.lub-num { text-align: right; }
.lub-table tbody tr { transition: background var(--lub-ease); }
.lub-table tbody tr:last-child td { border-bottom: none; }
.lub-table tbody tr:hover { background: #f8f9fb; }
.lub-table tfoot td {
  padding: 11px 10px; border-top: 1px solid var(--sep-strong);
  background: var(--surface-2);
}

/* Identity cell */
.lub-entity { display: flex; align-items: center; gap: 9px; min-width: 0; }
.lub-avatar {
  flex-shrink: 0; width: 30px; height: 30px; border-radius: 9px;
  display: inline-flex; align-items: center; justify-content: center;
  background: var(--lub-blue-lt); color: var(--blue);
  font-size: 10.5px; font-weight: 700; letter-spacing: 0.02em;
}
.lub-entity-info { min-width: 0; flex: 1 1 auto; overflow: hidden; }
.lub-entity-name {
  display: block; max-width: 100%; padding: 0; border: 0; background: none;
  text-align: left; cursor: pointer; color: var(--t1);
  font-family: inherit; font-size: 12.5px; font-weight: 600;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.lub-entity-name:hover { color: var(--blue); text-decoration: underline; }
.lub-entity-name.is-plain { cursor: default; }
.lub-entity-name.is-plain:hover { color: var(--t1); text-decoration: none; }
.lub-entity-meta {
  display: flex; align-items: center; gap: 5px; flex-wrap: wrap;
  margin-top: 2px; font-size: 11px; color: var(--t3);
  overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
}
.lub-code {
  font-family: ui-monospace, Menlo, monospace; font-size: 10.5px;
  color: var(--t3); letter-spacing: 0.01em;
}

/* Text cells */
.lub-cell-strong { font-size: 12px; font-weight: 600; color: var(--t1); }
.lub-cell-sub {
  font-size: 11px; color: var(--t3); margin-top: 2px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.lub-muted-zero { color: var(--t3); font-variant-numeric: tabular-nums; }
.lub-val-strong { font-weight: 700; color: var(--t1); font-variant-numeric: tabular-nums; }
.lub-num-txt { font-variant-numeric: tabular-nums; }
.lub-mono { font-family: ui-monospace, Menlo, monospace; }

/* Quantitative bar */
.lub-rate-wrap { display: inline-flex; align-items: center; justify-content: flex-end; gap: 7px; }
.lub-rate-pct {
  font-family: ui-monospace, Menlo, monospace; font-size: 12px; font-weight: 700;
  min-width: 38px; text-align: right; font-variant-numeric: tabular-nums;
}
.lub-rate-bar { height: 5px; border-radius: 99px; background: var(--lub-fill-2); overflow: hidden; flex-shrink: 0; }
.lub-rate-fill { display: block; height: 100%; border-radius: 99px; }
.lub-rate-good .lub-rate-pct { color: var(--green); }
.lub-rate-good .lub-rate-fill { background: var(--green); }
.lub-rate-warn .lub-rate-pct { color: var(--amber); }
.lub-rate-warn .lub-rate-fill { background: var(--amber); }
.lub-rate-bad  .lub-rate-pct { color: var(--red); }
.lub-rate-bad  .lub-rate-fill { background: var(--red); }
.lub-note-pill {
  display: inline-flex; align-items: center; padding: 2px 7px;
  border-radius: 100px; background: var(--lub-fill);
  color: var(--t3); font-size: 10.5px; font-weight: 500; white-space: nowrap;
}

/* Actions */
.lub-row-actions { display: inline-flex; align-items: center; justify-content: flex-end; gap: 4px; }
.lub-act-btn {
  display: inline-flex; align-items: center; gap: 4px;
  height: 27px; padding: 0 8px; border-radius: 6px;
  border: 1px solid var(--sep-strong); background: var(--surface);
  color: var(--t1); font-family: inherit; font-size: 11.5px; font-weight: 500;
  cursor: pointer; text-decoration: none; white-space: nowrap;
  transition: background var(--lub-ease), border-color var(--lub-ease), color var(--lub-ease);
}
.lub-act-btn:hover { background: var(--lub-fill); border-color: #b8b8bf; color: var(--t1); }
.lub-act-btn.is-primary {
  background: var(--lub-blue-lt); border-color: rgba(0, 113, 227, 0.25);
  color: var(--blue); font-weight: 600;
}
.lub-act-btn.is-primary:hover { background: rgba(0, 113, 227, 0.16); }

/* ── Empty state ── */
.lub-empty { padding: 48px 24px; text-align: center; }
.lub-empty-ico {
  width: 44px; height: 44px; border-radius: 12px;
  background: var(--lub-fill); color: var(--t3);
  display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px;
}
.lub-empty h3 { font-size: 15px; font-weight: 600; color: var(--t1); margin-bottom: 4px; }
.lub-empty p { font-size: 13px; color: var(--t2); max-width: 440px; margin: 0 auto 14px; }

/* ── Grouped view ── */
.lub-groups-container { display: none; padding: 14px; }
.lub-group-card {
  margin-bottom: 12px; border-radius: var(--r-md, 14px); overflow: hidden;
  background: var(--surface); border: 1px solid var(--sep);
}
.lub-group-hdr {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; flex-wrap: wrap; padding: 11px 16px;
  background: var(--surface-2); border-bottom: 1px solid var(--sep);
}
.lub-group-title { font-weight: 600; color: var(--t1); font-size: 13.5px; }
.lub-group-meta { font-size: 11.5px; color: var(--t3); }
.lub-group-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; padding: 12px 16px; }
.lub-group-item {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  padding: 10px 12px; border-radius: 9px; border: 1px solid var(--sep); min-width: 0;
}
.lub-group-item:hover { background: var(--lub-fill); }

/* ── Card view ── */
.lub-cards-container { display: none; padding: 14px; }
.lub-cards-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.lub-card {
  display: flex; flex-direction: column; gap: 10px;
  padding: 14px; border-radius: var(--r-md, 14px);
  background: var(--surface); border: 1px solid var(--sep); min-width: 0;
}
.lub-card:hover { border-color: var(--sep-strong); }
.lub-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.lub-card-metrics {
  display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px;
  padding: 8px; border-radius: 8px; background: var(--surface-2); font-size: 11px;
}
.lub-card-metric-k { color: var(--t3); font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em; }
.lub-card-metric-v { font-weight: 700; color: var(--t1); margin-top: 2px; font-variant-numeric: tabular-nums; }
.lub-card-foot {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding-top: 8px; border-top: 1px solid var(--sep); margin-top: auto;
}

/* ── Pagination footer ── */
.lub-footer {
  padding: 11px 16px; border-top: 1px solid var(--sep);
  background: var(--surface-2);
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap; font-size: 12.5px; color: var(--t2);
}
.lub-pages { display: inline-flex; align-items: center; gap: 4px; }
.lub-page-link {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 30px; height: 28px; padding: 0 8px; border-radius: 6px;
  border: 1px solid var(--sep-strong); background: var(--surface);
  color: var(--t1); font-size: 12px; text-decoration: none;
  transition: background var(--lub-ease), border-color var(--lub-ease);
}
.lub-page-link:hover { background: var(--lub-fill); border-color: #b8b8bf; }
.lub-page-link.is-current {
  background: var(--blue); border-color: var(--blue); color: #ffffff; font-weight: 600;
}
.lub-page-link.is-disabled { opacity: 0.4; pointer-events: none; }
.lub-pager-ellipsis { padding: 0 4px; color: var(--t3); }
.lub-rows { display: inline-flex; align-items: center; gap: 6px; }
.lub-rows label { font-size: 12px; color: var(--t3); margin: 0; }
.lub-rows .lub-select { height: 28px; font-size: 12px; padding-right: 24px; }

/* ── Slide-over dossier drawer ── */
.lub-drawer-backdrop {
  position: fixed; inset: 0; background: rgba(2,6,23,0.36); z-index: 1040;
  opacity: 0; pointer-events: none; transition: opacity .18s ease;
}
.lub-drawer-backdrop.is-open { opacity: 1; pointer-events: auto; }
.lub-drawer {
  position: fixed; top: 0; right: 0; bottom: 0; width: min(520px, 100vw); z-index: 1050;
  background: var(--surface); border-left: 1px solid var(--sep-strong);
  box-shadow: -16px 0 40px rgba(0,0,0,0.18);
  display: flex; flex-direction: column;
  transform: translateX(100%); visibility: hidden;
  transition: transform .2s ease, visibility .2s;
}
.lub-drawer.is-open { transform: translateX(0); visibility: visible; }
.lub-drawer :focus-visible, .lub-page :focus-visible { outline: 2px solid var(--blue); outline-offset: 2px; }
.lub-drawer-hdr {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 10px;
  padding: 16px 18px; border-bottom: 1px solid var(--sep);
}
.lub-drawer-eyebrow {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.05em; color: var(--t3);
}
.lub-drawer-title { margin: 3px 0 0; font-size: 16px; font-weight: 700; color: var(--t1); word-break: break-word; }
.lub-drawer-body { padding: 16px 18px; overflow-y: auto; flex: 1 1 auto; }
.lub-drawer-ftr {
  display: flex; align-items: center; justify-content: space-between;
  gap: 8px; flex-wrap: wrap; padding: 12px 18px; border-top: 1px solid var(--sep);
}
.lub-drawer-nav { display: inline-flex; gap: 6px; }
.lub-drawer-ctas { display: inline-flex; gap: 6px; flex-wrap: wrap; }
.lub-drawer-na { opacity: 0.4; pointer-events: none; }
.lub-kv-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-bottom: 14px; }
.lub-kv { padding: 9px 11px; border-radius: 8px; background: var(--surface-2); min-width: 0; }
.lub-kv-k { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--t3); }
.lub-kv-v { font-size: 12.5px; font-weight: 600; color: var(--t1); margin-top: 2px; word-break: break-word; }
.lub-section-title {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.045em; color: var(--t3); margin-bottom: 6px;
}

/* ── Forms ── */
.lub-form-grid { display: grid; gap: 14px; }
.lub-form-grid.cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.lub-form-grid.cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.lub-form-actions {
  display: flex; align-items: center; justify-content: flex-end; gap: 10px;
  flex-wrap: wrap; margin-top: 18px; padding-top: 14px;
  border-top: 1px solid var(--sep);
}
.lub-form-actions .lub-form-note { margin-right: auto; font-size: 11.5px; color: var(--t3); }

/* ── Responsive ── */
@media (max-width: 1100px) {
  .lub-kpi-grid, .lub-kpi-grid.is-5 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .lub-cards-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 900px) {
  .lub-kpi-grid, .lub-kpi-grid.is-5 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .lub-cards-grid { grid-template-columns: minmax(0, 1fr); }
  .lub-group-grid { grid-template-columns: minmax(0, 1fr); }
  .lub-search-wrap { flex: 1 1 100%; }
  .lub-search-input { width: 100%; }
  .lub-tools-right { width: 100%; justify-content: space-between; }
  .lub-kv-grid { grid-template-columns: minmax(0, 1fr); }
  .lub-form-grid.cols-2, .lub-form-grid.cols-3 { grid-template-columns: minmax(0, 1fr); }
  .lub-scope-actions { margin-left: 0; width: 100%; }
  .lub-table-wrap { max-height: none; }
  .lub-input, .lub-select { max-width: 100%; }
}

/* ── Clean print layout ── */
@media print {
  .lub-print-banner {
    display: block !important; margin-bottom: 14px;
    padding-bottom: 10px; border-bottom: 2px solid #1d1d1f;
  }
  .lub-print-banner h2 { font-size: 16px; margin: 0 0 3px; }
  .lub-print-banner p { font-size: 11px; color: #444; margin: 0; }
  .lub-head, .lub-subnav, .lub-scope-bar, .lub-toolbar, .lub-hint,
  .lub-chips-bar, .lub-batch-bar, .lub-footer, .lub-cards-container,
  .lub-groups-container, .lub-drawer, .lub-drawer-backdrop,
  .lub-row-actions, .lub-form-actions, .no-print { display: none !important; }
  .lub-surface { border: none !important; box-shadow: none !important; }
  .lub-table-wrap { display: block !important; overflow: visible !important; max-height: none !important; }
  .lub-table thead th { position: static !important; background: #ffffff !important; }
  .lub-table th, .lub-table td { padding: 6px 8px !important; font-size: 11px !important; }
  .lub-table tbody tr:hover { background: transparent !important; }
  .lub-kpi-grid, .lub-kpi-grid.is-5 { grid-template-columns: repeat(5, minmax(0, 1fr)) !important; gap: 6px !important; margin-bottom: 10px !important; }
  .lub-kpi { box-shadow: none !important; border: 1px solid #cccccc !important; padding: 7px 9px !important; }
  .lub-kpi-v { font-size: 15px !important; }
}
</style>
<?php
unset($lub_suite_links);
