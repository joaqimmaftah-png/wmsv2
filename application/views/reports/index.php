<?php
/*
 * Reports — Apple HIG inspired
 * One task per row. Breathing room. No competing buttons.
 * Typography leads. Color is used once, purposefully.
 */
?>

<style>
/* ── Report row list ─────────────────────────────── */
.rpt-group {
  background: rgba(255,255,255,0.033);
  border: 1px solid var(--sep);
  border-radius: var(--r3);
  overflow: hidden;
  margin-bottom: 10px;
  animation: rise .45s cubic-bezier(0.16,1,.3,1) both;
}
.rpt-group-label {
  font-family:inherit, sans-serif;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 1.2px;
  text-transform: uppercase;
  color: var(--t4);
  padding: 18px 22px 6px;
}
.rpt-row {
  display: flex;
  align-items: center;
  gap: 18px;
  padding: 14px 22px;
  border-bottom: 1px solid var(--sep);
  transition: background .15s;
}
.rpt-row:last-child { border-bottom: none; }
.rpt-row:hover { background: rgba(255,255,255,0.028); }

/* Icon */
.rpt-icon {
  width: 38px; height: 38px; min-width: 38px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.rpt-icon svg {
  width: 18px; height: 18px;
  stroke: currentColor; fill: none;
  stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round;
}

/* Text */
.rpt-text { flex: 1; min-width: 0; }
.rpt-title {
  font-size: 13.5px; font-weight: 600;
  color: var(--t1); letter-spacing: -.1px;
  margin-bottom: 2px;
}
.rpt-desc {
  font-size: 11.5px; color: var(--t3); line-height: 1.4;
}

/* Controls */
.rpt-controls {
  display: flex; align-items: center; gap: 8px;
  flex-shrink: 0;
}
.rpt-date {
  background: var(--g1); border: 1px solid var(--sep);
  border-radius: 8px; padding: 6px 10px;
  color: var(--t1); font-family: 'Outfit'; font-size: 12px;
  outline: none; transition: border-color .15s;
}
.rpt-date:focus { border-color: var(--blue); }
.rpt-btn-primary {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--blue); color:var(--t1); border: none;
  padding: 7px 16px; border-radius: 20px;
  font-family: 'Outfit'; font-size: 12.5px; font-weight: 600;
  cursor: pointer; white-space: nowrap; text-decoration: none;
  transition: background .15s, transform .15s;
}
.rpt-btn-primary:hover {
  background: #2b94ff; color:var(--t1); transform: translateY(-1px);
}
.rpt-btn-ghost {
  display: inline-flex; align-items: center; gap: 5px;
  background: transparent; color: var(--t3);
  border: 1px solid var(--sep);
  padding: 6px 13px; border-radius: 20px;
  font-family: 'Outfit'; font-size: 11.5px; font-weight: 500;
  cursor: pointer; white-space: nowrap; text-decoration: none;
  transition: all .15s;
}
.rpt-btn-ghost:hover {
  color: var(--t1); border-color: var(--sep-strong);
  background: var(--g2);
}
.rpt-btn-ghost svg {
  width: 12px; height: 12px; flex-shrink: 0;
  stroke: currentColor; fill: none;
  stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
}
.rpt-btn-primary svg {
  width: 13px; height: 13px; flex-shrink: 0;
  stroke: currentColor; fill: none;
  stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
}

/* Export strip */
.export-strip {
  display: flex; align-items: center; gap: 10px;
  flex-wrap: wrap;
  background: rgba(255,255,255,0.033);
  border: 1px solid var(--sep);
  border-radius: var(--r3);
  padding: 16px 22px;
  animation: rise .55s cubic-bezier(0.16,1,.3,1) both;
  animation-delay: .2s;
}
.export-label {
  font-family:inherit; font-size: 9px; font-weight: 700;
  letter-spacing: 1.1px; text-transform: uppercase;
  color: var(--t4); margin-right: 4px;
}

@media (max-width: 768px) {
  .rpt-controls { flex-wrap: wrap; justify-content: flex-end; }
  .rpt-row { flex-wrap: wrap; gap: 12px; }
}
</style>


<!-- ══════════════════════════════════════════════
     GROUP 1 — DAILY OPERATIONS
══════════════════════════════════════════════ -->
<div class="rpt-group" style="animation-delay:.04s">
  <div class="rpt-group-label">Daily Operations</div>

  <!-- Workshop Daily Report -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(192,57,43,0.12);color:var(--red);">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Workshop Daily Report</div>
      <div class="rpt-desc">Fleet availability, breakdowns, and field interventions — grouped by machine type</div>
    </div>
    <div class="rpt-controls">
      <input type="date" class="rpt-date" id="wdr-date"
             value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
      <a class="rpt-btn-primary" id="wdr-view" href="<?= base_url('reports/workshop_daily?date='.date('Y-m-d')) ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" id="wdr-excel" href="<?= base_url('reports/export_workshop_daily_xlsx?date='.date('Y-m-d')) ?>">
        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Excel
      </a>
    </div>
  </div>

  <!-- Workshop Weekly Report -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(28,140,60,0.12);color:var(--green);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Workshop Weekly Report</div>
      <div class="rpt-desc">7-day fleet performance — availability, interventions, target analysis and KPI charts per machine type</div>
    </div>
    <div class="rpt-controls">
      <?php $wwr_ws = isset($report_week_start) ? $report_week_start : date('Y-m-d');
            $wwr_iso = sprintf('%04d-W%02d', (int)date('o', strtotime($wwr_ws)), (int)date('W', strtotime($wwr_ws))); ?>
      <input type="date" class="rpt-date" id="wwr-date"
             value="<?= $wwr_ws ?>"
             max="<?= date('Y-m-d') ?>">
      <a class="rpt-btn-primary" id="wwr-view" href="<?= base_url('reports/workshop_weekly?week_start='.$wwr_ws) ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" id="wwr-excel" href="<?= base_url('reports/export_workshop_weekly_xlsx?week_start='.$wwr_ws) ?>">
        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Excel
      </a>
      <a class="rpt-btn-ghost" id="wwr-pdf" href="<?= base_url('reports/export_workshop_weekly_pdf?week_start='.$wwr_ws) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        PDF
      </a>
    </div>
  </div>

  <?php $weekly_report_name = isset($weekly_report_name) && $weekly_report_name !== '' ? $weekly_report_name : 'W'.(int)date('W', strtotime(isset($report_week_start) ? $report_week_start : 'today')); ?>
  <!-- Weekly report preview (the name follows the selected week and can be changed on the dashboard) -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(52,199,89,0.14);color:#34c759;">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title"><?= htmlspecialchars((string)($weekly_report_name)) ?> Weekly Report</div>
      <div class="rpt-desc">The approved weekly sheet, generated from the system: pick the ISO week and set its report name, read the fleet grid, the per-type block and what the data still needs — the Excel download follows</div>
    </div>
    <div class="rpt-controls">
      <a class="rpt-btn-primary" href="<?= base_url('reports/weekly_w36?week='.$wwr_iso) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        Preview <?= $wwr_iso ?>
      </a>
    </div>
  </div>

  <!-- Availability Targets -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(255,159,10,0.14);color:#ff9f0a;">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><line x1="12" y1="3" x2="12" y2="8"/><line x1="12" y1="16" x2="12" y2="21"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Availability Targets</div>
      <div class="rpt-desc">Daily target KPI per machine and per group, with single-day overrides — the numbers the weekly report measures against</div>
    </div>
    <div class="rpt-controls">
      <a class="rpt-btn-primary" href="<?= base_url('reports/weekly_targets?week_start='.$wwr_ws) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09a1.65 1.65 0 00-1.08-1.51 1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09a1.65 1.65 0 001.51-1.08 1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
        Set targets
      </a>
    </div>
  </div>

  <!-- Daily Activity -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(0,113,227,0.12);color:var(--blue);">
      <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Daily Activity</div>
      <div class="rpt-desc">Work orders, team attendance, and PM completions for any selected day</div>
    </div>
    <div class="rpt-controls">
      <input type="date" class="rpt-date" id="daily-date"
             value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
      <a class="rpt-btn-primary" id="daily-view" href="<?= base_url('reports/daily?date='.date('Y-m-d')) ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" id="daily-print" href="<?= base_url('reports/pdf_daily?date='.date('Y-m-d')) ?>" target="_blank">
        <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print
      </a>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════
     GROUP 2 — RELIABILITY & PERFORMANCE
══════════════════════════════════════════════ -->
<div class="rpt-group" style="animation-delay:.08s">
  <div class="rpt-group-label">Reliability &amp; Performance</div>

  <!-- MTTR / MTBF -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(180,83,9,0.12);color:var(--amber);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">MTTR / MTBF Report</div>
      <div class="rpt-desc">Mean Time To Repair &amp; Mean Time Between Failures — 12-month trend and per-equipment reliability table</div>
    </div>
    <div class="rpt-controls">
      <select class="rpt-date" id="mttr-months" style="width:130px;">
        <option value="3">3 months</option>
        <option value="6">6 months</option>
        <option value="12" selected>12 months</option>
        <option value="18">18 months</option>
        <option value="24">24 months</option>
      </select>
      <a class="rpt-btn-primary" id="mttr-view" href="<?= base_url('reports/mttr_mtbf?months=12') ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
    </div>
  </div>

  <!-- PM Due Forecast -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(109,63,192,0.12);color:var(--purple);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">PM Due Forecast</div>
      <div class="rpt-desc">Preventive-maintenance schedules falling due — 30 to 180-day horizon, per machine type</div>
    </div>
    <div class="rpt-controls">
      <select class="rpt-date" id="fc-horizon" style="width:130px;">
        <option value="30">30 days</option>
        <option value="60">60 days</option>
        <option value="90" selected>90 days</option>
        <option value="120">120 days</option>
        <option value="180">180 days</option>
      </select>
      <a class="rpt-btn-primary" id="fc-view" href="<?= base_url('reports/availability_forecast?horizon=90') ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
    </div>
  </div>

  <!-- Annual Report -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(28,140,60,0.12);color:var(--green);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><polyline points="4 20 20 20"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Annual Report</div>
      <div class="rpt-desc">Full-year executive summary — monthly KPI trend, equipment reliability ranking, top faults, lubricant usage, and parts spend</div>
    </div>
    <div class="rpt-controls">
      <select class="rpt-date" id="annual-year" style="width:110px;">
        <?php for($y=date('Y');$y>=2020;$y--): ?>
        <option value="<?=$y?>" <?=$y==(int)date('Y')?'selected':''?>><?=$y?></option>
        <?php endfor; ?>
      </select>
      <a class="rpt-btn-primary" id="annual-view" href="<?= base_url('reports/annual_report?year='.date('Y')) ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" id="annual-excel" href="<?= base_url('reports/export_annual_report_xlsx?year='.date('Y')) ?>">
        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Excel
      </a>
    </div>
  </div>

  <!-- Monthly Summary (existing) -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(180,83,9,0.12);color:var(--amber);">
      <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Monthly Summary</div>
      <div class="rpt-desc">KPIs, availability trend, work order breakdown, attendance, and lubricant usage</div>
    </div>
    <div class="rpt-controls">
      <input type="month" class="rpt-date" id="monthly-date" value="<?= date('Y-m') ?>">
      <a class="rpt-btn-primary" id="monthly-view" href="<?= base_url('reports/monthly?month='.date('Y-m')) ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
    </div>
  </div>

</div>


<!-- ══════════════════════════════════════════════
     GROUP 3 — MAINTENANCE PLANNING
══════════════════════════════════════════════ -->
<div class="rpt-group" style="animation-delay:.12s">
  <div class="rpt-group-label">Maintenance Planning</div>

  <!-- Monthly Maintenance Plan -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(28,140,60,0.12);color:var(--green);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Monthly Maintenance Plan</div>
      <div class="rpt-desc">PM jobs scheduled by week + all open corrective work orders — export-ready for team briefings</div>
    </div>
    <div class="rpt-controls">
      <input type="month" class="rpt-date" id="mplan-month" value="<?= date('Y-m') ?>">
      <a class="rpt-btn-primary" id="mplan-view" href="<?= base_url('reports/monthly_plan?month='.date('Y-m')) ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" id="mplan-excel" href="<?= base_url('reports/export_monthly_plan_xlsx?month='.date('Y-m')) ?>">
        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Excel
      </a>
    </div>
  </div>

  <!-- Lubricant Compliance -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(0,113,227,0.12);color:var(--blue);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z"/><polyline points="9 12 11 14 15 10"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Lubricant Compliance</div>
      <div class="rpt-desc">PM-driven lube requirement (date + hours based) vs. actual consumption, by month or full year</div>
    </div>
    <div class="rpt-controls">
      <input type="month" class="rpt-date" id="lube-month" value="<?= date('Y-m') ?>">
      <a class="rpt-btn-primary" id="lube-view" href="<?= base_url('reports/lube_compliance?month='.date('Y-m')) ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" href="<?= base_url('reports/lube_compliance_yearly?year='.date('Y')) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Yearly
      </a>
    </div>
  </div>

  <!-- Store Analytics -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(0,113,227,0.12);color:var(--blue);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Store Analytics</div>
      <div class="rpt-desc">Supplier spend breakdown and learned lead-times by country + shipping method</div>
    </div>
    <div class="rpt-controls">
      <select class="rpt-date" id="store-year" style="width:90px;">
        <?php for ($y = date('Y'); $y >= date('Y')-4; $y--): ?>
        <option value="<?= $y ?>"><?= $y ?></option>
        <?php endfor; ?>
      </select>
      <a class="rpt-btn-primary" id="store-view" href="<?= base_url('reports/store_analytics?year='.date('Y')) ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
    </div>
  </div>

  <!-- Store Issue Register -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(255,159,10,0.12);color:var(--amber);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v4H3z"/><path d="M5 7v14h14V7"/><path d="M9 12h6"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Store Issue Register (Sortie)</div>
      <div class="rpt-desc">Every item issued — date, requisition no, machine or department it was charged to, receiver — with full filtering and Excel export. Replaces the manual Sortie sheet.</div>
    </div>
    <div class="rpt-controls">
      <a class="rpt-btn-primary" href="<?= base_url('reports/issue_register?date_from='.date('Y-m-01').'&date_to='.date('Y-m-d')) ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" href="<?= base_url('reports/export_issue_register_xlsx?date_from='.date('Y-m-01').'&date_to='.date('Y-m-d')) ?>">Excel</a>
    </div>
  </div>

  <!-- Store Receipt Register -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(48,209,88,0.12);color:var(--green);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M12 22V12"/><path d="M3.3 7L12 12l8.7-5"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Store Receipt Register</div>
      <div class="rpt-desc">Goods received by MRN / delivery note — supplier, quantity approved vs delivered, unit price and line value. Replaces the manual Receipts sheet.</div>
    </div>
    <div class="rpt-controls">
      <a class="rpt-btn-primary" href="<?= base_url('reports/receipt_register?date_from='.date('Y-m-01').'&date_to='.date('Y-m-d')) ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" href="<?= base_url('reports/export_receipt_register_xlsx?date_from='.date('Y-m-01').'&date_to='.date('Y-m-d')) ?>">Excel</a>
    </div>
  </div>

  <!-- Re-order Report -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(255,69,58,0.12);color:var(--red);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Re-order Report</div>
      <div class="rpt-desc">Items at or below their minimum stock, with the quantity to order up to the maximum, estimated value and preferred supplier. Replaces the manual "Alerte / Qty to order" columns.</div>
    </div>
    <div class="rpt-controls">
      <a class="rpt-btn-primary" href="<?= base_url('reports/reorder') ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" href="<?= base_url('reports/export_reorder_xlsx') ?>">Excel</a>
    </div>
  </div>

  <!-- Annual Maintenance Plan -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(0,113,227,0.12);color:var(--blue);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h8M8 18h5"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Annual Maintenance Plan</div>
      <div class="rpt-desc">All PM jobs across 12 months — visual heatmap overview + month-by-month breakdown with due dates and hours</div>
    </div>
    <div class="rpt-controls">
      <select class="rpt-date" id="aplan-year" style="width:110px;">
        <?php for($y=date('Y')+1;$y>=2020;$y--): ?>
        <option value="<?=$y?>" <?=$y==(int)date('Y')?'selected':''?>><?=$y?></option>
        <?php endfor; ?>
      </select>
      <a class="rpt-btn-primary" id="aplan-view" href="<?= base_url('reports/annual_plan?year='.date('Y')) ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" id="aplan-excel" href="<?= base_url('reports/export_annual_plan_xlsx?year='.date('Y')) ?>">
        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Excel
      </a>
    </div>
  </div>

  <!-- Service Kit Recommendations -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(192,57,43,0.12);color:var(--red);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Service Kit Recommendations</div>
      <div class="rpt-desc">Per-machine recommended spare parts and lubricants — built automatically from historical WO parts and oil records</div>
    </div>
    <div class="rpt-controls">
      <a class="rpt-btn-primary" href="<?= base_url('reports/service_kits') ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" href="<?= base_url('reports/export_service_kits_xlsx') ?>">
        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Excel
      </a>
    </div>
  </div>

  <!-- Weekly Snapshot (existing) -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(0,113,227,0.12);color:var(--blue);">
      <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Weekly Snapshot</div>
      <div class="rpt-desc">Work order count, team performance, and PM status for a selected week</div>
    </div>
    <div class="rpt-controls">
      <input type="week" class="rpt-date" id="weekly-date">
      <button class="rpt-btn-primary" onclick="gotoWeek()">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </button>
      <a class="rpt-btn-ghost" href="<?= base_url('reports/weekly') ?>">This Week</a>
    </div>
  </div>

  <!-- Daily Activity (existing) -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(0,113,227,0.12);color:var(--blue);">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Daily Activity</div>
      <div class="rpt-desc">Work orders, team attendance, and PM completions for any selected day</div>
    </div>
    <div class="rpt-controls">
      <input type="date" class="rpt-date" id="daily-date"
             value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
      <a class="rpt-btn-primary" id="daily-view" href="<?= base_url('reports/daily?date='.date('Y-m-d')) ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" id="daily-print" href="<?= base_url('reports/pdf_daily?date='.date('Y-m-d')) ?>" target="_blank">
        <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print
      </a>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════
     GROUP 4 — ASSETS & INVENTORY
══════════════════════════════════════════════ -->
<div class="rpt-group" style="animation-delay:.16s">
  <div class="rpt-group-label">Assets &amp; Inventory</div>

  <!-- Asset Register -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(50,173,230,0.12);color:var(--cyan);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8l4 1 3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Asset Register</div>
      <div class="rpt-desc">Full fleet inventory — serial numbers, hours, warranty status, lifetime WOs, availability and next PM</div>
    </div>
    <div class="rpt-controls">
      <a class="rpt-btn-primary" href="<?= base_url('reports/asset_register') ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" href="<?= base_url('reports/export_asset_register_xlsx') ?>">
        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export Excel
      </a>
      <a class="rpt-btn-ghost" href="<?= base_url('reports/asset_template_xlsx') ?>">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Template
      </a>
      <a class="rpt-btn-ghost" href="<?= base_url('reports/asset_register') ?>#assetImportPanel">
        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Import Excel
      </a>
    </div>
  </div>

  <!-- Equipment History (existing) -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(109,63,192,0.12);color:var(--purple);">
      <svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8l4 1 3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Equipment History</div>
      <div class="rpt-desc">Full maintenance record, oil history, and downtime log for any individual machine</div>
    </div>
    <div class="rpt-controls">
      <a class="rpt-btn-primary" href="<?= base_url('equipment') ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        Select Machine
      </a>
    </div>
  </div>

  <!-- Materials Movement (existing) -->
  <div class="rpt-row">
    <div class="rpt-icon" style="background:rgba(50,173,230,0.12);color:var(--cyan);">
      <svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
    </div>
    <div class="rpt-text">
      <div class="rpt-title">Materials Movement</div>
      <div class="rpt-desc">Stock levels, receipts, and issues across all inventory categories</div>
    </div>
    <div class="rpt-controls">
      <a class="rpt-btn-primary" href="<?= base_url('materials/inventory') ?>">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        View
      </a>
      <a class="rpt-btn-ghost" href="<?= base_url('materials/export_xlsx') ?>">
        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Excel
      </a>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════
     EXPORT STRIP
══════════════════════════════════════════════ -->
<div class="export-strip">
  <span class="export-label">Export</span>
  <input type="month" class="rpt-date" id="exportMonth" value="<?= date('Y-m') ?>">
  <button onclick="exportData('workorders')" class="rpt-btn-ghost">
    <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    Work Orders
  </button>
  <button onclick="exportData('attendance')" class="rpt-btn-ghost">
    <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    Attendance
  </button>
  <button onclick="exportData('lubricants')" class="rpt-btn-ghost">
    <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    Lubricants
  </button>
</div>


<script>
var BASE = '<?= base_url() ?>';

// Live date → URL sync for date pickers
function bind(inputId, linkId, baseUrl, param) {
  var inp  = document.getElementById(inputId);
  var link = document.getElementById(linkId);
  if (!inp || !link) return;
  inp.addEventListener('change', function() {
    link.href = BASE + baseUrl + '?' + param + '=' + this.value;
  });
}
bind('wdr-date',    'wdr-view',    'reports/workshop_daily',           'date');
bind('wdr-date',    'wdr-excel',   'reports/export_workshop_daily_xlsx','date');
bind('wwr-date',    'wwr-view',    'reports/workshop_weekly',           'week_start');
bind('wwr-date',    'wwr-excel',   'reports/export_workshop_weekly_xlsx','week_start');
bind('wwr-date',    'wwr-pdf',     'reports/export_workshop_weekly_pdf', 'week_start');
bind('monthly-date','monthly-view','reports/monthly',                   'month');
bind('mplan-month', 'mplan-view',  'reports/monthly_plan',              'month');
bind('mplan-month', 'mplan-excel', 'reports/export_monthly_plan_xlsx',  'month');
bind('lube-month',  'lube-view',   'reports/lube_compliance',           'month');
bind('store-year',  'store-view',  'reports/store_analytics',           'year');
bind('daily-date',  'daily-view',  'reports/daily',                     'date');
bind('daily-date',  'daily-print', 'reports/pdf_daily',                 'date');

// MTTR selector
(function(){
  var sel=document.getElementById('mttr-months'),link=document.getElementById('mttr-view');
  if(sel&&link) sel.addEventListener('change',function(){ link.href=BASE+'reports/mttr_mtbf?months='+this.value; });
})();

// Availability Forecast horizon
(function(){
  var sel=document.getElementById('fc-horizon'),link=document.getElementById('fc-view');
  if(sel&&link) sel.addEventListener('change',function(){ link.href=BASE+'reports/availability_forecast?horizon='+this.value; });
})();

// Annual Report year
(function(){
  var sel=document.getElementById('annual-year');
  var vl=document.getElementById('annual-view');
  var el=document.getElementById('annual-excel');
  if(!sel) return;
  sel.addEventListener('change',function(){
    var y=this.value;
    if(vl) vl.href=BASE+'reports/annual_report?year='+y;
    if(el) el.href=BASE+'reports/export_annual_report_xlsx?year='+y;
  });
})();

// Annual Plan year
(function(){
  var sel=document.getElementById('aplan-year');
  var vl=document.getElementById('aplan-view');
  var el=document.getElementById('aplan-excel');
  if(!sel) return;
  sel.addEventListener('change',function(){
    var y=this.value;
    if(vl) vl.href=BASE+'reports/annual_plan?year='+y;
    if(el) el.href=BASE+'reports/export_annual_plan_xlsx?year='+y;
  });
})();
bind('daily-date',  'daily-view',  'reports/daily',                    'date');
bind('daily-date',  'daily-print', 'reports/pdf_daily',                'date');
bind('monthly-date','monthly-view','reports/monthly',                   'month');

function gotoWeek() {
  var w = document.getElementById('weekly-date').value; // 2024-W08
  if (!w) { location.href = BASE + 'reports/weekly'; return; }
  var parts = w.split('-W');
  var d     = new Date(parts[0], 0, 1 + (parseInt(parts[1])-1)*7);
  var day   = d.getDay();
  var diff  = d.getDate() - day + (day===0 ? -6 : 1);
  d.setDate(diff);
  location.href = BASE + 'reports/weekly?week=' + d.toISOString().split('T')[0];
}

function exportData(type) {
  var month = document.getElementById('exportMonth').value;
  location.href = BASE + 'reports/export_excel/' + type + '?month=' + month;
}
</script>
