<?php
/**
 * Operations dashboard — the backbone page for Supervisor / Technician /
 * Manager / Store Keeper. The CEO role is served by dashboard/ceo.php.
 *
 * Reading order follows how an operator actually uses the page:
 *   context → KPI strip → exceptions → trends → records.
 *
 * Every number is the engine's own (Kpi_engine_model / kpi_helper); the
 * 30-day window is stated up front, and every chart degrades to an honest
 * "no data" state rather than a fabricated series. Chart colours are
 * resolved from the design tokens in PHP because Chart.js draws on canvas,
 * which cannot read CSS custom properties.
 */

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

$open_wo        = isset($kpi['open_wo'])        ? (int)$kpi['open_wo']        : 0;
$availability   = isset($kpi['availability'])   ? (float)$kpi['availability'] : 0;
$manpower_data  = isset($kpi['manpower'])       ? $kpi['manpower']            : array('present' => 0, 'total' => 0, 'pct' => 0);
$manpower       = is_array($manpower_data) ? (int)$manpower_data['present'] : (int)$manpower_data;
$total_workers  = is_array($manpower_data) ? (int)$manpower_data['total']   : (isset($kpi['total_workers']) ? (int)$kpi['total_workers'] : 0);
$overdue_pm     = isset($kpi['overdue_pm'])     ? (int)$kpi['overdue_pm']     : 0;
$mttr           = isset($kpi['mttr'])           ? (float)$kpi['mttr']         : 0.0;
$mtbf           = isset($kpi['mtbf'])           ? (float)$kpi['mtbf']         : 0.0;
$breakdowns     = isset($kpi['breakdowns'])     ? (int)$kpi['breakdowns']     : 0;
$total_downtime = isset($kpi['total_downtime']) ? (float)$kpi['total_downtime'] : 0.0;

// The target comes from the engine (BR-W13), never from this file: 88 % default.
$avail_target = isset($kpi['avail_target']) && (float)$kpi['avail_target'] > 0
    ? (float)$kpi['avail_target']
    : (isset($avail_target) ? (float)$avail_target : 88.0);
$avail_watch  = $avail_target - 10;                       // "watch" band, not a second target
$avail_delta  = round($availability - $avail_target, 1);
$avail_delta_txt = ($avail_delta >= 0 ? '+' : '') . $avail_delta . ' pp';

// Availability provenance: the window it was computed over and how many
// machines contributed data. A partial dataset is the one thing that can make
// an availability number misleading, so it is surfaced, not hidden.
$avail_window  = isset($availability_window) ? (array)$availability_window : array();
$avail_with    = isset($avail_window['machines_with']) ? (int)$avail_window['machines_with'] : 0;
$avail_from    = isset($avail_window['from']) ? $avail_window['from'] : null;
$avail_to      = isset($avail_window['to']) ? $avail_window['to'] : null;

$fleet_status  = isset($fleet_status) ? (array)$fleet_status : array('Available' => 0, 'In Maintenance' => 0, 'Breakdown' => 0, 'Standby' => 0);
$machines_down = isset($machines_down) ? (int)$machines_down : 0;
$fleet_total   = isset($fleet_total) ? (int)$fleet_total : 0;
$incidents_month    = isset($incidents_month) ? (int)$incidents_month : 0;
$critical_incidents = isset($critical_incidents) ? (int)$critical_incidents : 0;

// MTTR / MTBF are undefined when the window had no breakdown events; the
// standing rule (mtbf_zero_failure_mode = dash) is to show "—", never a
// fabricated zero.
$has_failures  = $breakdowns > 0;
$mttr_display  = $has_failures ? number_format($mttr, 1) : '—';
$mtbf_display  = $has_failures ? number_format($mtbf, 1) : '—';

// Machines currently out of service, for the fleet-status card.
$down_machines = array();
if (isset($equipment)) {
    foreach ($equipment as $eq) {
        if (equip_status_group($eq) === 'Down') $down_machines[] = $eq;
    }
}
$down_shown = array_slice($down_machines, 0, 5);

// Work-order type → badge class.
$wo_badge = array(
    'Open'          => 'b-open',
    'In Progress'   => 'b-progress',
    'Waiting Parts' => 'b-waiting',
    'Waiting Tools' => 'b-waiting',
    'Completed'     => 'b-completed',
    'Closed'        => 'b-closed',
    'Cancelled'     => 'b-closed',
);

// Chart series, pre-formatted so the canvas only ever sees plain values.
$avail_labels = array(); $avail_vals = array();
if (!empty($availability_trend)) {
    foreach ($availability_trend as $d) {
        $avail_labels[] = date('M d', strtotime($d['date']));
        $avail_vals[]   = $d['pct'];
    }
}
$wo_labels = array(); $wo_vals = array();
if (!empty($wo_monthly)) {
    foreach ($wo_monthly as $m) {
        $wo_labels[] = date('M', strtotime($m['month'] . '-01'));
        $wo_vals[]   = (int)$m['closed'];
    }
}
$att_labels = array(); $att_present = array(); $att_absent = array();
if (!empty($attendance_trend)) {
    foreach ($attendance_trend as $d) {
        $att_labels[]   = date('d M', strtotime($d['date']));
        $att_present[]  = (int)$d['present'];
        $att_absent[]   = (int)$d['absent'];
    }
}

// Inline alert icon (an SVG, not an emoji — it is exposed to AT as aria-hidden).
$ico_alert = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    . '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
    . '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
?>
<style>
/* Dashboard shell — reuses the global tokens; only dashboard-specific
   layout pieces live here so the other ~25 pages using .kpi/.card are
   untouched. */
.dsh-context {
  display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;
  padding:13px 22px;margin-bottom:18px;
  background:var(--surface);border:1px solid var(--sep);border-radius:var(--r-lg);
  box-shadow:var(--shadow-card);
}
.dsh-context .t { font-size:12px;color:var(--t3); }
.dsh-context .t strong { color:var(--t1);font-weight:600; }
.dsh-context .r { font-size:12px;color:var(--t3);font-variant-numeric:tabular-nums; }

.dsh-kpis {
  /* Slate token set (the /materials #invShell palette), scoped to this strip. */
  --sk-text:#0F172A; --sk-muted:#475569; --sk-subtle:#64748B;
  --sk-border:#E2E8F0; --sk-border-strong:#CBD5E1;
  --sk-accent:#1D4ED8; --sk-accent-soft:#EFF6FF;
  --sk-ok:#047857; --sk-warn:#B45309; --sk-crit:#B91C1C;
  display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:18px;
}

.dsh-fleet { display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:6px; }
.dsh-fs { display:flex;flex-direction:column;align-items:center;gap:3px;padding:11px 4px;background:var(--gray-fill);border-radius:10px; }
.dsh-fs-n { font-size:21px;font-weight:700;font-variant-numeric:tabular-nums;line-height:1; }
.dsh-fs-l { font-size:10px;color:var(--t3);text-align:center; }

.dsh-stats { display:grid;grid-template-columns:repeat(2,1fr);gap:10px; }
.dsh-stat { display:flex;flex-direction:column;gap:2px;padding:11px 13px;background:var(--gray-fill);border-radius:10px; }
.dsh-stat .lbl { font-size:11px;font-weight:600;color:var(--t3);letter-spacing:0;text-transform:none; }
.dsh-stat .val { font-size:23px;font-weight:700;color:var(--t1);letter-spacing:-0.5px;font-variant-numeric:tabular-nums;line-height:1.15; }
.dsh-stat .val sup { font-size:11px;color:var(--t2);font-weight:500; }
.dsh-stat .sub { font-size:10.5px;color:var(--t3); }

.dsh-exc-row { display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--sep); }
.dsh-exc-row:last-child { border-bottom:none; }
.dsh-exc-row .ic { width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:var(--red-fill);color:var(--red); }
.dsh-exc-row.warn .ic { background:var(--amber-fill);color:var(--amber); }
.dsh-exc-row .tx { flex:1;min-width:0; }
.dsh-exc-row .tx .t1 { font-size:13px;font-weight:600;color:var(--t1); }
.dsh-exc-row .tx .t2 { font-size:11px;color:var(--t3); }
.dsh-exc-row .n { font-size:18px;font-weight:700;color:var(--t1);font-variant-numeric:tabular-nums; }

.dsh-mix { display:flex;align-items:flex-end;gap:18px;flex-wrap:wrap; }
.dsh-mix .m { display:flex;flex-direction:column;gap:2px; }
.dsh-mix .m .n { font-size:22px;font-weight:700;color:var(--t1);font-variant-numeric:tabular-nums;line-height:1; }
.dsh-mix .m .k { font-size:11px;font-weight:600;color:var(--t3); }
.dsh-mix .sep { width:1px;height:30px;background:var(--sep); }

.dsh-empty {
  display:flex;align-items:center;justify-content:center;gap:9px;
  min-height:180px;font-size:13px;color:var(--t3);
}
.dsh-empty::before { content:'';width:14px;height:14px;border-radius:50%;border:2px solid var(--sep);flex-shrink:0; }

.dsh-type-row { margin-bottom:13px; }
.dsh-type-row:last-child { margin-bottom:0; }
.dsh-type-row .hd { display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px; }
.dsh-type-row .hd .nm { font-weight:600;color:var(--t1); }
.dsh-type-row .hd .ct { color:var(--t3);font-variant-numeric:tabular-nums; }

/* KPI cards — the /materials Slate card language, scoped to this strip:
   uppercase label → value → plain meta. Status lives on the value text
   (is-crit / is-warn / is-accent), never a pill or a dot. */
.dsh-kpi-card {
  background:#FFFFFF;border:1px solid var(--sk-border);border-radius:12px;
  padding:14px 16px;display:flex;flex-direction:column;gap:4px;
  text-decoration:none;color:var(--sk-text);
  box-shadow:0 1px 2px rgba(15,23,42,0.03);
  transition:border-color .14s ease,box-shadow .14s ease,transform .14s ease;
}
.dsh-kpi-card:hover { border-color:var(--sk-border-strong); box-shadow:0 4px 12px rgba(15,23,42,0.06); transform:translateY(-1px); }
.dsh-kpi-card:focus-visible { outline:2px solid var(--sk-accent); outline-offset:2px; }
.dsh-kpi-lbl { font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--sk-subtle); }
.dsh-kpi-val { font-size:25px;font-weight:700;letter-spacing:-0.02em;font-variant-numeric:tabular-nums;line-height:1.15;color:var(--sk-text); }
.dsh-kpi-val sup { font-size:13px;font-weight:600;color:var(--sk-muted);letter-spacing:0; }
.dsh-kpi-meta { font-size:11.5px;color:var(--sk-muted);font-variant-numeric:tabular-nums;line-height:1.35; }
.dsh-kpi-val.is-crit { color:var(--sk-crit); }
.dsh-kpi-val.is-warn { color:var(--sk-warn); }
.dsh-kpi-val.is-accent { color:var(--sk-accent); }

/* Top-priority exception row — the one thing to act on. Accent follows the
   exception's severity; no fill, just a left bar + a tag. */
.dsh-exc-row.is-top { position:relative; border-radius:8px; padding:13px 14px 13px 18px; border-bottom:none; margin:0 0 6px; --accent:var(--red); }
.dsh-exc-row.is-top.warn { --accent:var(--amber); }
.dsh-exc-row.is-top::before { content:''; position:absolute; left:0; top:10px; bottom:10px; width:3px; border-radius:3px; background:var(--accent); }
.dsh-exc-row .top-tag { font-size:9.5px; font-weight:700; letter-spacing:.7px; text-transform:uppercase; color:var(--red); margin-bottom:2px; }
.dsh-exc-row .act { font-size:12px; font-weight:600; color:var(--blue); text-decoration:none; white-space:nowrap; margin-left:auto; }
.dsh-exc-row .act:hover { text-decoration:underline; }
.dsh-exc-row .arr { color:var(--t3); margin-left:auto; font-size:14px; }

/* Chart accessible fallback table — visually hidden, read by assistive tech. */
.dsh-sr { position:absolute !important; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }

/* Collapsible secondary analysis (keeps the landing page effortless). */
.dsh-more { margin-top:18px; }
.dsh-more > summary { font-size:12px; font-weight:600; color:var(--t3); cursor:pointer; padding:6px 2px; list-style:none; }
.dsh-more > summary::-webkit-details-marker { display:none; }
.dsh-more > summary::before { content:'▸'; display:inline-block; margin-right:8px; color:var(--t3); }
.dsh-more[open] > summary::before { content:'▾'; }
.dsh-more[open] > summary { margin-bottom:12px; }

@media (max-width:900px){
  .grid.g-2 { grid-template-columns:1fr; }
  .dsh-kpis { grid-template-columns:repeat(2,1fr); }
}
@media (max-width:760px){ .dsh-kpis{grid-template-columns:1fr;} }
@media (max-width:560px){ .dsh-fleet{grid-template-columns:1fr 1fr;} .dsh-stats{grid-template-columns:1fr;} }
</style>

<!-- ═══ CONTEXT ═══ -->
<div class="dsh-context">
  <div class="t"><strong>Operations Dashboard</strong> · 30-day fleet window — availability, MTTR / MTBF, breakdowns and downtime are computed over this window</div>
  <div class="r"><?= $avail_to ? $h('Availability through ' . date('d M Y', strtotime($avail_to))) . ' · ' : '' ?>Updated <?= $h(date('d M Y, H:i')) ?></div>
</div>

<?php
// Availability status: on/above target stays neutral — the /materials
// convention is that problems are coloured and healthy values keep the
// default text colour. Below the watch band is crit, watch→target is warn.
$avail_status = $availability >= $avail_target ? '' : ($availability >= $avail_watch ? 'is-warn' : 'is-crit');
$avail_meta = $fleet_total > 0
    ? $avail_with . '/' . $fleet_total . ' machines · target ≥' . $avail_target . '% (' . $avail_delta_txt . ')'
    : 'target ≥' . $avail_target . '% (' . $avail_delta_txt . ')';
?>
<!-- ═══ KPI STRIP ═══ -->
<div class="dsh-kpis">

  <a class="dsh-kpi-card" href="<?= $h(base_url('equipment')) ?>">
    <span class="dsh-kpi-lbl">Availability</span>
    <span class="dsh-kpi-val <?= $avail_status ?>"><span class="cu" data-to="<?= $availability ?>" data-decimals="1"><?= $availability ?></span><sup>%</sup></span>
    <span class="dsh-kpi-meta"><?= $avail_meta ?></span>
  </a>

  <a class="dsh-kpi-card" href="<?= $h(base_url('workorders')) ?>">
    <span class="dsh-kpi-lbl">Open Work Orders</span>
    <span class="dsh-kpi-val"><span class="cu" data-to="<?= $open_wo ?>"><?= $open_wo ?></span></span>
    <span class="dsh-kpi-meta">Active work orders</span>
  </a>

  <a class="dsh-kpi-card" href="<?= $h(base_url('pm')) ?>">
    <span class="dsh-kpi-lbl">Overdue PM</span>
    <span class="dsh-kpi-val <?= $overdue_pm > 0 ? 'is-crit' : '' ?>"><span class="cu" data-to="<?= $overdue_pm ?>"><?= $overdue_pm ?></span></span>
    <span class="dsh-kpi-meta"><?= $overdue_pm > 0 ? 'Needs attention' : 'On schedule' ?></span>
  </a>

  <a class="dsh-kpi-card" href="<?= $h(base_url('team')) ?>">
    <span class="dsh-kpi-lbl">Manpower</span>
    <span class="dsh-kpi-val"><span class="cu" data-to="<?= $manpower ?>"><?= $manpower ?></span><sup>/<?= $total_workers ?></sup></span>
    <span class="dsh-kpi-meta">Present today</span>
  </a>

  <a class="dsh-kpi-card" href="<?= $h(base_url('equipment')) ?>">
    <span class="dsh-kpi-lbl">Machines Down</span>
    <span class="dsh-kpi-val <?= $machines_down > 0 ? 'is-warn' : '' ?>"><span class="cu" data-to="<?= $machines_down ?>"><?= $machines_down ?></span></span>
    <span class="dsh-kpi-meta">Out of service now</span>
  </a>

</div>

<!-- ═══ OPERATIONS: availability trend + fleet status ═══ -->
<div class="grid g-2 mb-5">

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Fleet Availability</div>
        <div class="cs">Last 30 days · target <?= $avail_target ?>%</div>
      </div>
      <a class="ca" href="<?= $h(base_url('reports/availability_forecast')) ?>">Report →</a>
    </div>
    <div class="card-body" style="padding-top:14px;">
      <?php if (!empty($avail_vals)): ?>
        <div class="chart-container"><canvas id="cAvail" aria-label="Fleet availability trend over the last 30 days, with the availability target line" role="img"></canvas></div>
        <table class="dsh-sr"><caption>Fleet availability per day over the last 30 days</caption><thead><tr><th>Date</th><th>Availability</th></tr></thead><tbody>
        <?php foreach ($availability_trend as $d): ?>
          <tr><td><?= $h(date('Y-m-d', strtotime($d['date']))) ?></td><td><?= $h(number_format((float)$d['pct'], 1)) ?>%</td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php else: ?>
        <div class="dsh-empty">No availability data for this window.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Fleet Status</div>
        <div class="cs"><?= $fleet_total ?> machines · <?= $machines_down ?> down</div>
      </div>
      <a class="ca" href="<?= $h(base_url('equipment')) ?>">Equipment →</a>
    </div>
    <div class="card-body">
      <div class="dsh-fleet">
        <div class="dsh-fs"><div class="dsh-fs-n" style="color:var(--green);"><?= (int)$fleet_status['Available'] ?></div><div class="dsh-fs-l">Available</div></div>
        <div class="dsh-fs"><div class="dsh-fs-n" style="color:var(--amber);"><?= (int)$fleet_status['In Maintenance'] ?></div><div class="dsh-fs-l">Maintenance</div></div>
        <div class="dsh-fs"><div class="dsh-fs-n" style="color:var(--red);"><?= (int)$fleet_status['Breakdown'] ?></div><div class="dsh-fs-l">Breakdown</div></div>
        <div class="dsh-fs"><div class="dsh-fs-n" style="color:var(--t3);"><?= (int)$fleet_status['Standby'] ?></div><div class="dsh-fs-l">Standby</div></div>
      </div>

      <?php if (!empty($down_shown)): ?>
        <div class="sec-head" style="margin-top:18px;">Out of service</div>
        <?php foreach ($down_shown as $eq): ?>
          <div class="row-list-item" style="padding:10px 0;">
            <span class="led <?= $eq->status === 'Breakdown' ? 'led-crit' : 'led-warn' ?>"></span>
            <div style="flex:1;min-width:0;">
              <div style="font-size:13px;font-weight:600;color:var(--t1);"><?= $h($eq->registration_no) ?></div>
              <div style="font-size:11px;color:var(--t3);"><?= $h($eq->brand ?: '—') ?> <?= $h($eq->model ?: '') ?></div>
            </div>
            <span class="badge <?= $eq->status === 'Breakdown' ? 'b-progress' : 'b-open' ?>"><?= $h($eq->status) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (count($down_machines) > count($down_shown)): ?>
          <div style="padding:8px 0 2px;font-size:11px;color:var(--t3);">+<?= count($down_machines) - count($down_shown) ?> more</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ═══ RELIABILITY + REQUIRES ACTION ═══ -->
<div class="grid g-2 mb-5">

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Reliability</div>
        <div class="cs">Last 30 days</div>
      </div>
      <a class="ca" href="<?= $h(base_url('reports/mttr_mtbf')) ?>">Report →</a>
    </div>
    <div class="card-body">
      <div class="dsh-stats">
        <div class="dsh-stat">
          <div class="lbl">MTTR</div>
          <div class="val"><?= $mttr_display ?><sup>h</sup></div>
          <div class="sub">Mean time to repair</div>
        </div>
        <div class="dsh-stat">
          <div class="lbl">MTBF</div>
          <div class="val"><?= $mtbf_display ?><sup>h</sup></div>
          <div class="sub">Mean time between failures</div>
        </div>
        <div class="dsh-stat">
          <div class="lbl">Downtime</div>
          <div class="val"><?= number_format($total_downtime, 1) ?><sup>h</sup></div>
          <div class="sub">Total over 30 days</div>
        </div>
        <div class="dsh-stat">
          <div class="lbl">Breakdowns</div>
          <div class="val"><?= $breakdowns ?></div>
          <div class="sub">Failure events</div>
        </div>
      </div>
    </div>
  </div>

<?php
// Rank exceptions by severity so the one thing to act on is obvious, then
// pull the top item out as a highlighted priority.
$exceptions = array();
if ($critical_incidents > 0)
    $exceptions[] = array('label' => 'Critical incidents', 'count' => $critical_incidents, 'sub' => $critical_incidents . ' uncontrolled this month', 'href' => base_url('workorders'), 'act' => 'Review', 'sev' => 'crit');
if ($overdue_pm > 0)
    $exceptions[] = array('label' => 'Overdue PM', 'count' => $overdue_pm, 'sub' => $overdue_pm . ' maintenance jobs past due', 'href' => base_url('pm'), 'act' => 'Planner', 'sev' => 'warn');
if (isset($spares_summary['lines']) && (int)$spares_summary['lines'] > 0)
    $exceptions[] = array('label' => 'Pending spare parts', 'count' => (int)$spares_summary['lines'], 'sub' => (int)$spares_summary['lines'] . ' demand lines · ' . (int)($spares_summary['available_lines'] ?? 0) . ' covered', 'href' => base_url('spareparts'), 'act' => 'Board', 'sev' => 'warn');
if (!empty($absent_today))
    $exceptions[] = array('label' => 'Absent today', 'count' => count($absent_today), 'sub' => count($absent_today) . ' staff not on site', 'href' => base_url('team'), 'act' => 'Team', 'sev' => 'info');
$sev_rank = array('crit' => 0, 'warn' => 1, 'info' => 2);
usort($exceptions, function ($a, $b) use ($sev_rank) { return $sev_rank[$a['sev']] - $sev_rank[$b['sev']]; });
$top_exception = array_shift($exceptions);
?>
  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Requires Action</div>
        <div class="cs">Exceptions across the operation</div>
      </div>
    </div>
    <div class="card-body">
      <?php if ($top_exception): ?>
        <div class="dsh-exc-row is-top <?= $top_exception['sev'] !== 'crit' ? 'warn' : '' ?>">
          <div class="ic"><?= $ico_alert ?></div>
          <div class="tx">
            <div class="top-tag">Top priority</div>
            <div class="t1"><?= $h($top_exception['label']) ?></div>
            <div class="t2"><?= $h($top_exception['sub']) ?></div>
          </div>
          <div class="n"><?= (int)$top_exception['count'] ?></div>
          <a class="act" href="<?= $h($top_exception['href']) ?>"><?= $h($top_exception['act']) ?> →</a>
        </div>
      <?php endif; ?>

      <?php foreach ($exceptions as $ex): ?>
        <div class="dsh-exc-row <?= $ex['sev'] !== 'crit' ? 'warn' : '' ?>">
          <div class="ic"><?= $ico_alert ?></div>
          <div class="tx"><div class="t1"><?= $h($ex['label']) ?></div><div class="t2"><?= $h($ex['sub']) ?></div></div>
          <div class="n"><?= (int)$ex['count'] ?></div>
          <a class="ca" href="<?= $h($ex['href']) ?>"><?= $h($ex['act']) ?> →</a>
        </div>
      <?php endforeach; ?>

      <?php if (!$top_exception && empty($exceptions)): ?>
        <div class="dsh-empty" style="min-height:120px;">Nothing outstanding right now.</div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ═══ RECORDS: live work orders + upcoming PM ═══ -->
<div class="grid g-2 mb-5">

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Live Work Orders</div>
        <div class="cs">Newest first</div>
      </div>
      <a class="ca" href="<?= $h(base_url('workorders')) ?>">All →</a>
    </div>
    <div class="card-body p-0">
      <?php if (!empty($recent_wo)): ?>
        <table class="tbl">
          <thead><tr><th scope="col">WO</th><th scope="col">Machine</th><th scope="col">Status</th><th scope="col">Opened</th></tr></thead>
          <tbody>
          <?php foreach ($recent_wo as $wo): ?>
            <tr>
              <td><a href="<?= $h(base_url('workorders/view/' . (int)$wo->id)) ?>" style="color:var(--blue);font-weight:600;text-decoration:none;" class="mono"><?= $h($wo->wo_number) ?></a></td>
              <td><?= $h($wo->registration_no) ?></td>
              <td><span class="badge <?= isset($wo_badge[$wo->status]) ? $wo_badge[$wo->status] : 'b-open' ?>"><?= $h($wo->status) ?></span></td>
              <td class="mono" style="color:var(--t3);"><?= $h(date('d M', strtotime($wo->created_at))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="dsh-empty" style="min-height:120px;">No work orders yet.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Upcoming PM</div>
        <div class="cs">Due within 14 days</div>
      </div>
      <a class="ca" href="<?= $h(base_url('pm')) ?>">Planner →</a>
    </div>
    <div class="card-body p-0">
      <?php if (!empty($due_pm)): ?>
        <table class="tbl">
          <thead><tr><th scope="col">Asset</th><th scope="col">Service</th><th scope="col">Due</th><th scope="col">Status</th></tr></thead>
          <tbody>
          <?php foreach ($due_pm as $p): ?>
            <tr>
              <td><?= $h($p->registration_no) ?></td>
              <td><?= $h($p->schedule_name ?: 'PM') ?></td>
              <td class="mono"><?= $h(date('d M Y', strtotime($p->next_due_date))) ?></td>
              <td><span class="badge <?= strtotime($p->next_due_date) < strtotime('today') ? 'b-progress' : 'b-open' ?>"><?= strtotime($p->next_due_date) < strtotime('today') ? 'Overdue' : 'Due' ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="dsh-empty" style="min-height:120px;">No PM due in the next 14 days.</div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ═══ ANALYSIS: readiness by type + WO volume ═══ -->
<div class="grid g-2 mb-5">

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Fleet Readiness by Type</div>
        <div class="cs">Share of machines up, by equipment group</div>
      </div>
    </div>
    <div class="card-body">
      <?php if (!empty($fleet_kpi_by_type)): ?>
        <?php foreach ($fleet_kpi_by_type as $ft): ?>
          <?php $pct = isset($ft['kpi_pct']) ? (int)$ft['kpi_pct'] : 0; ?>
          <div class="dsh-type-row">
            <div class="hd"><span class="nm"><?= $h($ft['type']) ?></span><span class="ct"><?= (int)($ft['available'] ?? 0) ?>/<?= (int)($ft['total'] ?? 0) ?> · <?= $pct ?>%</span></div>
            <div class="pbar"><div class="pbar-fill" data-w="<?= $pct ?>%" style="width:0;background:<?= $pct >= 90 ? 'var(--green)' : ($pct >= 70 ? 'var(--amber)' : 'var(--red)') ?>;"></div></div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="dsh-empty" style="min-height:120px;">No equipment groups.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Work Orders Closed</div>
        <div class="cs">Last 12 months</div>
      </div>
      <a class="ca" href="<?= $h(base_url('reports/monthly')) ?>">Report →</a>
    </div>
    <div class="card-body" style="padding-top:14px;">
      <?php if (!empty($wo_vals)): ?>
        <div class="chart-container"><canvas id="cMonthly" aria-label="Work orders closed per month over the last 12 months" role="img"></canvas></div>
        <table class="dsh-sr"><caption>Work orders closed per month over the last 12 months</caption><thead><tr><th>Month</th><th>Closed</th></tr></thead><tbody>
        <?php foreach ($wo_monthly as $m): ?>
          <tr><td><?= $h($m['month']) ?></td><td><?= (int)$m['closed'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php else: ?>
        <div class="dsh-empty">No work orders recorded in the last 12 months.</div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ═══ ANALYSIS: secondary (collapsible) ═══ -->
<details class="dsh-more">
<summary>Additional analysis</summary>
<div class="grid g-2 mb-5">

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Attendance</div>
        <div class="cs">Last 14 days · present vs absent</div>
      </div>
      <a class="ca" href="<?= $h(base_url('team')) ?>">Team →</a>
    </div>
    <div class="card-body" style="padding-top:14px;">
      <?php if (!empty($att_present)): ?>
        <div class="chart-container"><canvas id="cAttend" aria-label="Staff attendance over the last 14 days, stacked present and absent" role="img"></canvas></div>
        <table class="dsh-sr"><caption>Staff attendance per day over the last 14 days</caption><thead><tr><th>Date</th><th>Present</th><th>Absent</th></tr></thead><tbody>
        <?php foreach ($attendance_trend as $d): ?>
          <tr><td><?= $h(date('Y-m-d', strtotime($d['date']))) ?></td><td><?= (int)$d['present'] ?></td><td><?= (int)$d['absent'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php else: ?>
        <div class="dsh-empty">No attendance data for the last 14 days.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="ch">
      <div>
        <div class="ct">Work Order Mix</div>
        <div class="cs">This month · <?= $wo_total ?> total</div>
      </div>
    </div>
    <div class="card-body">
      <?php if (!empty($wo_total) && $wo_total > 0): ?>
        <div class="dsh-mix">
          <?php $mix_shown = 0; foreach (array('Breakdown', 'Preventive', 'Incident', 'Other') as $bt): $n = isset($wo_by_type[$bt]) ? (int)$wo_by_type[$bt] : 0; if ($n > 0): $mix_shown++; ?>
            <div class="m"><span class="n"><?= $n ?></span><span class="k"><?= $h($bt) ?></span></div>
            <?php if ($mix_shown < 4): ?><div class="sep"></div><?php endif; ?>
          <?php endif; endforeach; ?>
          <div class="m"><span class="n"><?= (int)$wo_total ?></span><span class="k">Total</span></div>
        </div>
      <?php else: ?>
        <div class="dsh-empty" style="min-height:120px;">No work orders this month.</div>
      <?php endif; ?>
    </div>
  </div>

</div>
</details>

<!-- ═══ CHARTS ═══ -->
<script>
(function () {
  if (typeof Chart === 'undefined') return;

  var RM = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var GRID = { color: 'rgba(15,23,42,0.05)', drawBorder: false };
  var TIP = {
    backgroundColor: '#0F172A',
    borderColor: 'rgba(15,23,42,0)',
    borderWidth: 0,
    padding: 11,
    cornerRadius: 8,
    titleFont: { size: 11, weight: '600', family: 'Inter,-apple-system,sans-serif' },
    bodyFont:  { size: 12, family: 'Inter,-apple-system,sans-serif' }
  };

  // Count-up — respects reduced motion and decimal places.
  document.querySelectorAll('.cu').forEach(function (el) {
    var to  = parseFloat(el.dataset.to) || 0;
    var dec = parseInt(el.dataset.decimals || '0', 10);
    if (RM) { el.textContent = dec > 0 ? to.toFixed(dec) : Math.round(to).toLocaleString(); return; }
    var dur = 1200, start = null;
    function ease(t) { return 1 - Math.pow(1 - t, 4); }
    function frame(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      var v = ease(p) * to;
      el.textContent = dec > 0 ? v.toFixed(dec) : Math.round(v).toLocaleString();
      if (p < 1) requestAnimationFrame(frame);
    }
    setTimeout(function () { requestAnimationFrame(frame); }, 300);
  });

  // Progress bars.
  var pbars = document.querySelectorAll('.pbar-fill[data-w]');
  if (RM) { pbars.forEach(function (b) { b.style.width = b.dataset.w; }); }
  else { setTimeout(function () { pbars.forEach(function (b) { b.style.width = b.dataset.w; }); }, 700); }

  // ── Fleet availability (30-day line + target) ──
  var cAvail = document.getElementById('cAvail');
  if (cAvail) {
    var labels = <?= json_encode($avail_labels) ?>;
    var values = <?= json_encode($avail_vals) ?>;
    var target = <?= json_encode((float)$avail_target) ?>;
    var ctx = cAvail.getContext('2d');
    var g = ctx.createLinearGradient(0, 0, 0, 200);
    g.addColorStop(0, 'rgba(29,78,216,0.16)');
    g.addColorStop(1, 'rgba(29,78,216,0)');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          { label: 'Availability %', data: values, borderColor: '#1D4ED8', backgroundColor: g,
            fill: true, borderWidth: 2, tension: 0.4, pointRadius: 0, pointHoverRadius: 5 },
          { label: 'Target ' + target + '%', data: values.map(function () { return target; }),
            borderColor: 'rgba(15,23,42,0.18)', fill: false, borderWidth: 1.5, borderDash: [5, 5], pointRadius: 0, tension: 0 }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false, animation: !RM,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: Object.assign({}, TIP, { callbacks: {
            label: function (c) { return c.datasetIndex === 0 ? ' ' + c.raw + '% availability' : ' ' + c.raw + '% target'; }
          } })
        },
        scales: {
          x: { grid: GRID, border: { display: false }, ticks: { maxTicksLimit: 6, color: 'rgba(15,23,42,0.45)' } },
          y: { grid: GRID, border: { display: false }, ticks: { color: 'rgba(15,23,42,0.45)', callback: function (v) { return v + '%'; } } }
        }
      }
    });
  }

  // ── Work orders closed (12-month line) ──
  var cMonthly = document.getElementById('cMonthly');
  if (cMonthly) {
    var mL = <?= json_encode($wo_labels) ?>;
    var mV = <?= json_encode($wo_vals) ?>;
    var ctx5 = cMonthly.getContext('2d');
    var g5 = ctx5.createLinearGradient(0, 0, 0, 200);
    g5.addColorStop(0, 'rgba(4,120,87,0.16)');
    g5.addColorStop(1, 'rgba(4,120,87,0)');
    new Chart(ctx5, {
      type: 'line',
      data: { labels: mL, datasets: [{
        label: 'WOs Closed', data: mV, borderColor: '#047857', backgroundColor: g5,
        fill: true, borderWidth: 2, tension: 0.4, pointRadius: 0, pointHoverRadius: 5
      }] },
      options: {
        responsive: true, maintainAspectRatio: false, animation: !RM,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: Object.assign({}, TIP, { callbacks: { label: function (c) { return ' ' + c.raw + ' WOs'; } } })
        },
        scales: {
          x: { grid: GRID, border: { display: false }, ticks: { color: 'rgba(15,23,42,0.45)' } },
          y: { grid: GRID, border: { display: false }, ticks: { color: 'rgba(15,23,42,0.45)' } }
        }
      }
    });
  }

  // ── Attendance (14-day stacked bar) ──
  var cAttend = document.getElementById('cAttend');
  if (cAttend) {
    var aL = <?= json_encode($att_labels) ?>;
    var aP = <?= json_encode($att_present) ?>;
    var aA = <?= json_encode($att_absent) ?>;
    new Chart(cAttend.getContext('2d'), {
      type: 'bar',
      data: {
        labels: aL,
        datasets: [
          { label: 'Present', data: aP, backgroundColor: 'rgba(4,120,87,0.70)', borderRadius: 4, borderSkipped: false },
          { label: 'Absent',  data: aA, backgroundColor: 'rgba(185,28,28,0.45)', borderRadius: 4, borderSkipped: false }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false, animation: !RM,
        plugins: {
          legend: { display: true, position: 'top', labels: { color: 'rgba(15,23,42,0.45)', font: { size: 10.5 }, boxWidth: 9, boxHeight: 9 } },
          tooltip: TIP
        },
        scales: {
          x: { grid: GRID, border: { display: false }, ticks: { color: 'rgba(15,23,42,0.45)', maxRotation: 45, font: { size: 9.5 } } },
          y: { grid: GRID, border: { display: false }, ticks: { color: 'rgba(15,23,42,0.45)' } }
        }
      }
    });
  }
})();
</script>
