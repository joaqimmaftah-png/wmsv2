<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Supervisor: the day's punch log, eligibility, and corrections.
 *
 * This is the audit view. Punches are listed exactly as recorded — origin,
 * distance, accuracy, author and reason — and a correction is added as a new
 * line, never by changing an old one. That is the acceptance rule
 * "Supervisor corrections require a reason and are auditable".
 *
 * @var bool   $ready
 * @var string $date
 * @var array  $punches
 * @var array  $on_site
 * @var array  $workers
 * @var array  $sites
 * @var bool   $is_supervisor
 */
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$by_user = array();
foreach ($punches as $p) $by_user[(int)$p->user_id][] = $p;

$events       = isset($events) ? $events : $punches;
$tab_counts   = isset($tab_counts) ? $tab_counts : array('all' => count($punches), 'check_in' => 0, 'check_out' => 0, 'corrections' => 0);
$log_summary  = isset($log_summary) ? $log_summary : array('punches' => count($punches), 'check_in' => 0, 'check_out' => 0, 'corrections' => 0, 'workers' => 0);
$log_sites    = isset($log_sites) ? $log_sites : array();
$active_tab   = isset($active_tab) ? $active_tab : 'all';
$search_query = isset($search_query) ? $search_query : '';
$site_filter  = isset($site_filter) ? $site_filter : '';
$sort_by      = isset($sort_by) ? $sort_by : 'time';
$sort_dir     = isset($sort_dir) ? $sort_dir : 'asc';
$per_page     = isset($per_page) ? (int)$per_page : 25;
$page         = isset($page) ? (int)$page : 1;
$total_pages  = isset($total_pages) ? (int)$total_pages : 1;
$events_total = isset($events_total) ? (int)$events_total : count($events);
$showing_from = isset($showing_from) ? (int)$showing_from : ($events_total ? 1 : 0);
$showing_to   = isset($showing_to) ? (int)$showing_to : $events_total;
$view_mode    = isset($view_mode) ? $view_mode : 'table';
$is_cards     = ($view_mode === 'cards');

$base_params = array_filter(array(
    'date'  => $date,
    'q'     => $search_query,
    'site'  => $site_filter,
    'tab'   => ($active_tab !== 'all') ? $active_tab : null,
    'sort'  => ($sort_by !== 'time') ? $sort_by : null,
    'dir'   => ($sort_dir !== 'asc') ? $sort_dir : null,
    'view'  => $is_cards ? 'cards' : null,
));
$mk_url = function ($overrides = array()) use ($base_params) {
    return site_url('attendance/log') . '?' . http_build_query(array_merge($base_params, $overrides));
};
$sort_url = function ($key) use ($mk_url, $sort_by, $sort_dir) {
    if ($sort_by === $key) return $mk_url(array('sort' => $key, 'dir' => ($sort_dir === 'asc' ? 'desc' : 'asc'), 'page' => null));
    return $mk_url(array('sort' => $key, 'dir' => 'asc', 'page' => null));
};
$aria_sort = function ($key) use ($sort_by, $sort_dir) {
    return $sort_by === $key ? ' aria-sort="' . ($sort_dir === 'asc' ? 'ascending' : 'descending') . '"' : '';
};
$sort_mark = function ($key) use ($sort_by, $sort_dir) {
    return $sort_by === $key ? ($sort_dir === 'asc' ? ' ↑' : ' ↓') : '';
};
$tab_defs = array(
    'all'         => array('label' => 'All Punches', 'count' => (int)$tab_counts['all']),
    'check_in'    => array('label' => 'Check-ins',   'count' => (int)$tab_counts['check_in']),
    'check_out'   => array('label' => 'Check-outs',  'count' => (int)$tab_counts['check_out']),
    'corrections' => array('label' => 'Corrections', 'count' => (int)$tab_counts['corrections']),
);
$has_filters = ($search_query !== '' || $site_filter !== '');

$workers_total  = isset($workers_total) ? (int)$workers_total : count($workers);
$wtab_counts    = isset($wtab_counts) ? $wtab_counts : array('all' => $workers_total, 'on_site' => 0, 'away' => 0, 'eligible' => 0, 'blocked' => 0);
$wactive_tab    = isset($wactive_tab) ? $wactive_tab : 'all';
$wsearch_query  = isset($wsearch_query) ? $wsearch_query : '';
$wper_page      = isset($wper_page) ? (int)$wper_page : 25;
$wpage          = isset($wpage) ? (int)$wpage : 1;
$wtotal_pages   = isset($wtotal_pages) ? (int)$wtotal_pages : 1;
$wshowing_from  = isset($wshowing_from) ? (int)$wshowing_from : ($workers_total ? 1 : 0);
$wshowing_to    = isset($wshowing_to) ? (int)$wshowing_to : $workers_total;
$wtab_defs = array(
    'all'      => array('label' => 'All',      'count' => (int)$wtab_counts['all']),
    'on_site'  => array('label' => 'On Site',  'count' => (int)$wtab_counts['on_site']),
    'away'     => array('label' => 'Away',     'count' => (int)$wtab_counts['away']),
    'eligible' => array('label' => 'Eligible', 'count' => (int)$wtab_counts['eligible']),
    'blocked'  => array('label' => 'Blocked',  'count' => (int)$wtab_counts['blocked']),
);
$w_base = array_merge($base_params, array_filter(array(
    'page'     => ($page > 1) ? $page : null,
    'per_page' => ((int)$per_page !== 25) ? (int)$per_page : null,
    'wq'       => $wsearch_query,
    'wtab'     => ($wactive_tab !== 'all') ? $wactive_tab : null,
)));
$w_url = function ($overrides = array()) use ($w_base) {
    return site_url('attendance/log') . '?' . http_build_query(array_merge($w_base, $overrides));
};
?>
<style>
.al-kpis{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:10px;margin-bottom:14px}
.al-kpi{background:var(--surface);border:1px solid var(--sep);border-radius:12px;padding:12px 14px}
.al-kpi .k-l{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--t3)}
.al-kpi .k-v{font-size:20px;font-weight:700;color:var(--t1);margin-top:2px}
.al-kpi .k-m{font-size:11px;color:var(--t3);margin-top:1px}
.al-tabs{display:flex;gap:2px;flex-wrap:wrap;border-bottom:1px solid var(--sep)}
.al-tab{padding:8px 12px;font-size:12.5px;font-weight:500;color:var(--t2);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}
.al-tab:hover{color:var(--t1)}
.al-tab[aria-selected="true"]{color:var(--blue);border-bottom-color:var(--blue);font-weight:600}
.al-tab .cnt{font-size:11px;background:var(--gray-fill);border-radius:999px;padding:0 6px;margin-left:4px}
.al-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:12px 0 4px}
.al-chips{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:6px 0 2px}
.al-chip{font-size:11.5px;background:var(--blue-fill);color:var(--blue);border-radius:999px;padding:2px 6px 2px 10px;display:inline-flex;gap:4px;align-items:center}
.al-chip a{color:inherit;text-decoration:none;font-weight:700;padding:0 4px}
.al-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:12px;padding:14px 0 4px}
.al-card{border:1px solid var(--sep);border-radius:12px;padding:14px;background:var(--surface)}
.al-pager{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;padding:12px 0 4px}
.al-pg{display:inline-flex;gap:4px;align-items:center}
.al-pg a,.al-pg span.cur{font-size:12px;padding:4px 9px;border:1px solid var(--sep);border-radius:8px;color:var(--t2);text-decoration:none}
.al-pg span.cur{background:var(--blue);color:#fff;border-color:var(--blue);font-weight:600}
.al-pg span.dis{font-size:12px;padding:4px 9px;color:var(--t4)}
.sortable-th a{color:inherit;text-decoration:none}
.sortable-th a:hover{color:var(--blue)}
.al-print-banner{display:none}
.al-empty{padding:40px 20px;text-align:center;color:var(--t3)}
@media(max-width:900px){.al-kpis{grid-template-columns:repeat(2,1fr)}}
@media print{.al-toolbar,.al-tabs,.al-chips,.al-pager,.no-print{display:none!important}.al-print-banner{display:block;margin-bottom:10px}}
</style>
<div class="al-print-banner"><strong>Attendance punch log — <?= date('l, d F Y', strtotime($date)) ?></strong> (printed <?= date('d M Y H:i') ?>)</div>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <h1 class="ct" style="font-size:16px;">Punch log — <?= date('l, d F Y', strtotime($date)) ?></h1>
    <div class="cs"><?= count($punches) ?> punch(es) · <?= count($on_site) ?> on site</div>
  </div>
  <form method="get" action="<?= base_url('attendance/log') ?>" class="flex ai-c gap-10">
    <input type="date" name="date" class="form-control" style="width:auto;" max="<?= date('Y-m-d') ?>"
           value="<?= $h($date) ?>">
    <button type="submit" class="btn btn-primary btn-sm">Load</button>
    <a href="<?= base_url('attendance/log?date=' . date('Y-m-d')) ?>" class="btn btn-ghost btn-sm">Today</a>
    <a href="<?= base_url('team/report?month=' . rawurlencode(substr((string)$date, 0, 7))) ?>" class="btn btn-ghost btn-sm">AML Timesheets →</a>
    <a href="<?= base_url('attendance') ?>" class="btn btn-ghost btn-sm">← Attendance</a>
  </form>
</div>

<?php if (empty($ready)): ?>
  <div class="card" style="border-left:3px solid var(--amber);">
    <div style="padding:16px 18px;font-size:12.5px;color:var(--t2);line-height:1.7;">
      The attendance punch tables are not installed yet — run <code>php scripts/migrate.php</code>.
    </div>
  </div>
<?php else: ?>

<!-- KPI strip: the whole day (before tab narrowing) -->
<div class="al-kpis" role="region" aria-label="Day summary">
  <div class="al-kpi"><div class="k-l">Punches</div><div class="k-v"><?= (int)$log_summary['punches'] ?></div><div class="k-m">events on this date</div></div>
  <div class="al-kpi"><div class="k-l">Check-ins</div><div class="k-v"><?= (int)$log_summary['check_in'] ?></div><div class="k-m">arrivals recorded</div></div>
  <div class="al-kpi"><div class="k-l">Check-outs</div><div class="k-v"><?= (int)$log_summary['check_out'] ?></div><div class="k-m">departures recorded</div></div>
  <div class="al-kpi"><div class="k-l">Corrections</div><div class="k-v"><?= (int)$log_summary['corrections'] ?></div><div class="k-m">supervisor-added events</div></div>
  <div class="al-kpi"><div class="k-l">On Site Now*</div><div class="k-v"><?= count($on_site) ?></div><div class="k-m">*state for the selected date · <?= (int)$log_summary['workers'] ?> worker(s) punched</div></div>
</div>

<div class="grid g-2 mb-4" style="gap:16px;align-items:start;">
  <!-- ── per worker ─────────────────────────────────────────────── -->
  <div class="card">
    <div class="ch" style="padding:14px 18px 8px;">
      <div><div class="ct">Technicians</div><div class="cs">On-site state and assignment eligibility</div></div>
      <span class="cs"><?= (int)$workers_total ?> shown · search & pages below</span>
    </div>
    <div style="padding:0 18px;">
      <div class="al-tabs" role="tablist" aria-label="Technician state">
        <?php foreach ($wtab_defs as $key => $def): ?>
        <a role="tab" aria-selected="<?= $wactive_tab === $key ? 'true' : 'false' ?>" class="al-tab"
           href="<?= $w_url(array('wtab' => ($key === 'all' ? null : $key), 'wpage' => null)) ?>">
          <?= $def['label'] ?><span class="cnt"><?= (int)$def['count'] ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <form method="get" action="<?= site_url('attendance/log') ?>" class="al-toolbar no-print" role="search" style="padding-bottom:0;">
        <?php foreach ($w_base as $k => $v): if ($k === 'wq' || $k === 'wtab') continue; ?><input type="hidden" name="<?= $h($k) ?>" value="<?= $h((string)$v) ?>"><?php endforeach; ?>
        <?php if ($wactive_tab !== 'all'): ?><input type="hidden" name="wtab" value="<?= $wactive_tab ?>"><?php endif; ?>
        <div style="position:relative;flex:1;min-width:170px;">
          <label class="sr-only" for="alWorkerSearch">Search technicians</label>
          <input type="search" id="alWorkerSearch" name="wq" class="form-control" style="width:100%;padding-left:30px;"
                 placeholder="Search name, ID, position, block reason…" value="<?= $h($wsearch_query) ?>">
          <span aria-hidden="true" style="position:absolute;left:9px;top:7px;color:var(--t3);">&#128269;</span>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($wsearch_query !== '' || $wactive_tab !== 'all'): ?>
        <a href="<?= $w_url(array('wq' => null, 'wtab' => null, 'wpage' => null)) ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
      </form>
    </div>
    <div style="overflow-x:auto;">
      <table class="tbl" style="margin-top:10px;">
        <thead><tr><th>Worker</th><th>Punches</th><th>State</th><th>Eligible</th></tr></thead>
        <tbody>
        <?php if (empty($workers)): ?>
          <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--t3);">
            <?php if ((int)$wtab_counts['all'] === 0 && $wsearch_query === ''): ?>
              No active technicians.
            <?php elseif ($wactive_tab === 'blocked' && $wsearch_query === ''): ?>
              Nobody is blocked — every technician is eligible.
            <?php elseif ($wactive_tab === 'on_site' && $wsearch_query === ''): ?>
              Nobody is on site for this date yet.
            <?php else: ?>
              No technicians match the current search or tab. <a href="<?= $w_url(array('wq' => null, 'wtab' => null, 'wpage' => null)) ?>">Clear</a>
            <?php endif; ?>
          </td></tr>
        <?php else: foreach ($workers as $w):
          $list = isset($by_user[(int)$w->id]) ? $by_user[(int)$w->id] : array(); ?>
          <tr>
            <td style="font-size:12.5px;">
              <a href="<?= site_url('team/view/' . (int)$w->id) ?>" style="color:var(--t1);text-decoration:none;font-weight:600;"><?= $h(trim($w->first_name . ' ' . $w->last_name)) ?></a>
              <div style="font-size:10.5px;color:var(--t4);"><?= $h($w->employee_id) ?></div>
            </td>
            <td style="font-size:11.5px;color:var(--t2);white-space:nowrap;">
              <?php if (empty($list)): ?>
                <span style="color:var(--t4);">—</span>
              <?php else: foreach ($list as $p): ?>
                <?= date('H:i', strtotime((string)$p->punched_at)) ?>
                <span style="font-weight:700;color:<?= $p->punch_type === 'check_in' ? 'var(--green)' : 'var(--amber)' ?>;">
                  <?= $p->punch_type === 'check_in' ? 'IN' : 'OUT' ?>
                </span><?= $p->origin === 'supervisor' ? '<sup title="Supervisor correction">✎</sup>' : '' ?>
              <?php endforeach; endif; ?>
            </td>
            <td>
              <span class="badge" style="background:<?= $w->on_site ? 'rgba(28,140,60,0.1);color:var(--green)' : 'rgba(0,0,0,0.05);color:var(--t3)' ?>">
                <?= $w->on_site ? 'ON SITE' : 'AWAY' ?>
              </span>
            </td>
            <td style="font-size:11px;line-height:1.5;color:<?= $w->eligible ? 'var(--green)' : 'var(--amber)' ?>;">
              <?= $w->eligible ? 'Yes' : $h(implode('; ', $w->block_reasons)) ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($workers_total > 0): ?>
    <div style="padding:0 18px 14px;">
      <div class="al-pager no-print">
        <form method="get" action="<?= site_url('attendance/log') ?>" class="flex ai-c gap-10" style="font-size:12px;color:var(--t2);">
          <?php foreach ($w_base as $k => $v): ?><input type="hidden" name="<?= $h($k) ?>" value="<?= $h((string)$v) ?>"><?php endforeach; ?>
          <label for="alWPerPage">Show</label>
          <select id="alWPerPage" name="wper_page" class="form-select al-autosubmit" style="width:auto;font-size:12px;">
            <?php foreach (array(10, 25, 50, 100) as $pp): ?><option value="<?= $pp ?>" <?= (int)$wper_page === $pp ? 'selected' : '' ?>><?= $pp ?></option><?php endforeach; ?>
          </select>
          <span>Showing <?= (int)$wshowing_from ?>–<?= (int)$wshowing_to ?> of <?= (int)$workers_total ?> technicians</span>
        </form>
        <nav class="al-pg" aria-label="Technician pages">
          <?php if ($wpage > 1): ?><a href="<?= $w_url(array('wpage' => $wpage - 1, 'wper_page' => (int)$wper_page)) ?>">‹ Prev</a><?php else: ?><span class="dis">‹ Prev</span><?php endif; ?>
          <?php
            $wwin = array();
            for ($i = 1; $i <= (int)$wtotal_pages; $i++) { if ($i === 1 || $i === (int)$wtotal_pages || abs($i - $wpage) <= 1) $wwin[] = $i; }
            $wwin = array_values(array_unique($wwin)); $wprev = 0;
            foreach ($wwin as $i): if ($wprev && $i > $wprev + 1): ?><span class="dis">…</span><?php endif; $wprev = $i; ?>
            <?php if ($i === $wpage): ?><span class="cur" aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= $w_url(array('wpage' => $i, 'wper_page' => (int)$wper_page)) ?>"><?= $i ?></a><?php endif; ?>
          <?php endforeach; ?>
          <?php if ($wpage < (int)$wtotal_pages): ?><a href="<?= $w_url(array('wpage' => $wpage + 1, 'wper_page' => (int)$wper_page)) ?>">Next ›</a><?php else: ?><span class="dis">Next ›</span><?php endif; ?>
        </nav>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── correction form ────────────────────────────────────────── -->
  <?php if ($is_supervisor): ?>
  <div class="card">
    <div class="ch" style="padding:14px 18px 8px;">
      <div><div class="ct">Add a correction</div>
        <div class="cs">A missed or wrong punch — recorded on top of the history, never instead of it</div></div>
    </div>
    <?= form_open('attendance/correction') ?>
      <div style="padding:6px 18px 18px;">
        <div class="form-grid cols-2">
          <div>
            <label class="form-label" for="alCorrWorker">Worker</label>
            <select id="alCorrWorker" name="user_id" class="form-select" required>
              <option value="">Choose…</option>
              <?php foreach ((!empty($correction_workers) ? $correction_workers : $workers) as $w): ?>
                <option value="<?= (int)$w->id ?>"><?= $h(trim($w->first_name . ' ' . $w->last_name)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label" for="alCorrDate">Date</label>
            <input type="date" id="alCorrDate" name="date" class="form-control" required max="<?= date('Y-m-d') ?>"
                   value="<?= $h($date) ?>">
          </div>
          <div>
            <label class="form-label" for="alCorrTime">Time</label>
            <input type="time" id="alCorrTime" name="time" class="form-control" required value="<?= date('H:i') ?>">
          </div>
          <div>
            <label class="form-label" for="alCorrType">Punch</label>
            <select id="alCorrType" name="punch_type" class="form-select">
              <option value="check_in">Check-in</option>
              <option value="check_out">Check-out</option>
            </select>
          </div>
        </div>

        <div style="margin-top:12px;">
          <label class="form-label" for="alCorrSite">Site (optional)</label>
          <select id="alCorrSite" name="site_id" class="form-select">
            <option value="">—</option>
            <?php foreach ($sites as $s): ?>
              <option value="<?= (int)$s->id ?>"><?= $h($s->name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="margin-top:12px;">
          <label class="form-label" for="alCorrReason">Reason (required)</label>
          <input type="text" id="alCorrReason" name="reason" class="form-control" maxlength="300" required
                 placeholder="Forgot to punch out — left site at 17:40 with the crew">
          <div class="hint">Stored with your name and the time of the correction. It cannot be edited away later.</div>
        </div>

        <div class="flex ai-c gap-10" style="justify-content:flex-end;margin-top:16px;">
          <button type="submit" class="btn btn-primary">Record correction</button>
        </div>
      </div>
    <?= form_close() ?>
  </div>
<?php endif; ?>
</div>

<!-- ── raw punch events ─────────────────────────────────────────── -->
<div class="card">
  <div class="ch" style="padding:14px 18px 8px;">
    <div><div class="ct">Punch events</div><div class="cs">Server timestamps · corrections are new events, never edits</div></div>
    <?php if (!empty($can_export)): ?>
    <a href="<?= site_url('attendance/log_csv') . '?' . http_build_query($base_params) ?>" class="btn btn-ghost btn-sm no-print">Export CSV</a>
    <?php endif; ?>
  </div>
  <div style="padding:0 18px;">
    <div class="al-tabs" role="tablist" aria-label="Punch type">
      <?php foreach ($tab_defs as $key => $def): ?>
      <a role="tab" aria-selected="<?= $active_tab === $key ? 'true' : 'false' ?>" class="al-tab"
         href="<?= $mk_url(array('tab' => ($key === 'all' ? null : $key), 'page' => null)) ?>">
        <?= $def['label'] ?><span class="cnt"><?= (int)$def['count'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <form method="get" action="<?= site_url('attendance/log') ?>" class="al-toolbar no-print" role="search">
      <input type="hidden" name="date" value="<?= $h($date) ?>">
      <?php if ($active_tab !== 'all'): ?><input type="hidden" name="tab" value="<?= $active_tab ?>"><?php endif; ?>
      <?php if ($sort_by !== 'time'): ?><input type="hidden" name="sort" value="<?= $sort_by ?>"><?php endif; ?>
      <?php if ($sort_dir !== 'asc'): ?><input type="hidden" name="dir" value="<?= $sort_dir ?>"><?php endif; ?>
      <?php if ($is_cards): ?><input type="hidden" name="view" value="cards"><?php endif; ?>
      <div style="position:relative;">
        <label class="sr-only" for="alSearch">Search punches</label>
        <input type="search" id="alSearch" name="q" class="form-control" style="width:250px;padding-left:30px;"
               placeholder="Search worker, ID, site, reason…  ( / )" value="<?= $h($search_query) ?>">
        <span aria-hidden="true" style="position:absolute;left:9px;top:7px;color:var(--t3);">&#128269;</span>
      </div>
      <label class="sr-only" for="alSiteFilter">Filter by site</label>
      <select id="alSiteFilter" name="site" class="form-select al-autosubmit" style="width:auto;">
        <option value="">All sites</option>
        <?php foreach ($log_sites as $sn): ?>
        <option value="<?= $h($sn) ?>" <?= $site_filter === $sn ? 'selected' : '' ?>><?= $h($sn) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
      <?php if ($has_filters || $active_tab !== 'all'): ?>
      <a href="<?= site_url('attendance/log?date=' . rawurlencode($date)) ?>" class="btn btn-ghost btn-sm">Clear</a>
      <?php endif; ?>
      <span style="flex:1;"></span>
      <div class="btn-group no-print" role="group" aria-label="View mode">
        <a href="<?= $mk_url(array('view' => null)) ?>" class="btn btn-ghost btn-sm" aria-pressed="<?= $is_cards ? 'false' : 'true' ?>">Table</a>
        <a href="<?= $mk_url(array('view' => 'cards')) ?>" class="btn btn-ghost btn-sm" aria-pressed="<?= $is_cards ? 'true' : 'false' ?>">Cards</a>
      </div>
    </form>

    <?php if ($has_filters): ?>
    <div class="al-chips" aria-label="Active filters">
      <span style="font-size:11.5px;color:var(--t3);">Active filters:</span>
      <?php if ($search_query !== ''): ?><span class="al-chip">Search: <?= $h($search_query) ?><a href="<?= $mk_url(array('q' => null, 'page' => null)) ?>" aria-label="Clear search">×</a></span><?php endif; ?>
      <?php if ($site_filter !== ''): ?><span class="al-chip">Site: <?= $h($site_filter) ?><a href="<?= $mk_url(array('site' => null, 'page' => null)) ?>" aria-label="Clear site filter">×</a></span><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if (empty($events)): ?>
    <div class="al-empty">
      <?php if ((int)$tab_counts['all'] === 0 && !$has_filters): ?>
        <div style="font-size:15px;font-weight:600;color:var(--t1);">No punches on this date.</div>
        <div style="font-size:12.5px;margin-top:4px;">Phone punches and supervisor corrections for <?= date('d M Y', strtotime($date)) ?> will appear here.</div>
      <?php elseif ($active_tab === 'corrections'): ?>
        <div style="font-size:15px;font-weight:600;color:var(--t1);">No corrections on this date.</div>
        <div style="font-size:12.5px;margin-top:4px;">Every punch shown was recorded from a phone.</div>
      <?php else: ?>
        <div style="font-size:15px;font-weight:600;color:var(--t1);">No punches match the current filters.</div>
        <div style="font-size:12.5px;margin-top:4px;">Try a different search term or <a href="<?= site_url('attendance/log?date=' . rawurlencode($date)) ?>">clear the filters</a>.</div>
      <?php endif; ?>
    </div>
  <?php elseif ($is_cards): ?>
    <div style="padding:0 18px;">
      <div class="al-cards">
        <?php foreach ($events as $p): ?>
        <div class="al-card">
          <div class="flex ai-c jb" style="gap:8px;">
            <div>
              <a href="<?= site_url('team/view/' . (int)$p->user_id) ?>" style="font-weight:600;font-size:13.5px;color:var(--t1);text-decoration:none;"><?= $h($p->worker_name_log) ?></a>
              <div style="font-size:11px;color:var(--t3);"><?= $h(isset($p->employee_id) ? $p->employee_id : '') ?> · <?= $h($p->site_name ?? 'No site') ?></div>
            </div>
            <span class="badge" style="background:<?= $p->punch_type === 'check_in' ? 'var(--green-fill);color:var(--green)' : 'var(--amber-fill);color:var(--amber)' ?>"><?= $p->punch_type === 'check_in' ? 'CHECK-IN' : 'CHECK-OUT' ?></span>
          </div>
          <div class="mono" style="font-size:16px;font-weight:700;margin-top:8px;"><?= date('H:i:s', strtotime((string)$p->punched_at)) ?></div>
          <div style="font-size:11.5px;color:var(--t3);line-height:1.6;margin-top:4px;">
            <?php if (isset($p->distance_m) && $p->distance_m !== NULL): ?>Distance <?= (int)round((float)$p->distance_m) ?> m<?= (isset($p->radius_m) && $p->radius_m !== NULL) ? ' / ' . (int)$p->radius_m . ' m radius' : '' ?><br><?php endif; ?>
            <?php if (isset($p->accuracy_m) && $p->accuracy_m !== NULL): ?>Accuracy ±<?= (int)round((float)$p->accuracy_m) ?> m<br><?php endif; ?>
            <?php if ($p->origin === 'supervisor'): ?>Correction by <?= $h(trim((string)($p->by_first_name ?? '') . ' ' . (string)($p->by_last_name ?? ''))) ?><?php if (!empty($p->reason)): ?> — “<?= $h($p->reason) ?>”<?php endif; ?>
            <?php else: ?>Phone punch · <?= $h($p->ip_address ?? '—') ?><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:10px;">
      <thead>
        <tr>
          <th scope="col" class="sortable-th"<?= $aria_sort('time') ?>><a href="<?= $sort_url('time') ?>">Time<?= $sort_mark('time') ?></a></th>
          <th scope="col" class="sortable-th"<?= $aria_sort('worker') ?>><a href="<?= $sort_url('worker') ?>">Worker<?= $sort_mark('worker') ?></a></th>
          <th scope="col" class="sortable-th"<?= $aria_sort('type') ?>><a href="<?= $sort_url('type') ?>">Punch<?= $sort_mark('type') ?></a></th>
          <th scope="col">Site</th><th scope="col" style="text-align:right;">Distance</th><th scope="col" style="text-align:right;">Accuracy</th>
          <th scope="col">Origin</th><th scope="col">Recorded by / reason</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($events as $p): ?>
        <tr>
          <td class="mono" style="font-size:12px;"><?= date('H:i:s', strtotime((string)$p->punched_at)) ?></td>
          <td style="font-size:12.5px;">
            <a href="<?= site_url('team/view/' . (int)$p->user_id) ?>" style="color:var(--t1);text-decoration:none;font-weight:600;"><?= $h($p->worker_name_log) ?></a>
            <div style="font-size:10.5px;color:var(--t4);"><?= $h(isset($p->employee_id) ? $p->employee_id : '') ?></div>
          </td>
          <td><span class="badge" style="background:<?= $p->punch_type === 'check_in' ? 'var(--green-fill);color:var(--green)' : 'var(--amber-fill);color:var(--amber)' ?>"><?= $p->punch_type === 'check_in' ? 'CHECK-IN' : 'CHECK-OUT' ?></span></td>
          <td style="font-size:11.5px;color:var(--t3);"><?= $h($p->site_name ?? '—') ?></td>
          <td style="font-size:11.5px;color:var(--t3);text-align:right;">
            <?= (isset($p->distance_m) && $p->distance_m !== NULL) ? (int)round((float)$p->distance_m) . ' m' : '—' ?>
            <?php if (isset($p->radius_m) && $p->radius_m !== NULL): ?>
              <span style="color:var(--t4);">/ <?= (int)$p->radius_m ?> m</span>
            <?php endif; ?>
          </td>
          <td style="font-size:11.5px;color:var(--t3);text-align:right;">
            <?= (isset($p->accuracy_m) && $p->accuracy_m !== NULL) ? '±' . (int)round((float)$p->accuracy_m) . ' m' : '—' ?>
          </td>
          <td style="font-size:11.5px;">
            <?php if ($p->origin === 'supervisor'): ?>
              <span class="badge" style="background:var(--purple-fill);color:var(--purple)">CORRECTION</span>
            <?php else: ?>
              <span style="color:var(--t3);">Phone</span>
            <?php endif; ?>
          </td>
          <td style="font-size:11.5px;color:var(--t3);line-height:1.5;max-width:260px;">
            <?php if ($p->origin === 'supervisor'): ?>
              <?= $h(trim((string)($p->by_first_name ?? '') . ' ' . (string)($p->by_last_name ?? ''))) ?>
              <?php if (!empty($p->reason)): ?> — “<?= $h($p->reason) ?>”<?php endif; ?>
            <?php else: ?>
              <?= $h($p->ip_address ?? '—') ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($events_total > 0): ?>
  <div style="padding:0 18px;">
    <div class="al-pager no-print">
      <form method="get" action="<?= site_url('attendance/log') ?>" class="flex ai-c gap-10" style="font-size:12px;color:var(--t2);">
        <?php foreach ($base_params as $k => $v): ?><input type="hidden" name="<?= $h($k) ?>" value="<?= $h((string)$v) ?>"><?php endforeach; ?>
        <label for="alPerPage">Show</label>
        <select id="alPerPage" name="per_page" class="form-select al-autosubmit" style="width:auto;font-size:12px;">
          <?php foreach (array(10, 25, 50, 100) as $pp): ?><option value="<?= $pp ?>" <?= (int)$per_page === $pp ? 'selected' : '' ?>><?= $pp ?></option><?php endforeach; ?>
        </select>
        <span>Showing <?= (int)$showing_from ?>–<?= (int)$showing_to ?> of <?= (int)$events_total ?> punches</span>
      </form>
      <nav class="al-pg" aria-label="Punch pages">
        <?php if ($page > 1): ?><a href="<?= $mk_url(array('page' => $page - 1, 'per_page' => (int)$per_page)) ?>">‹ Prev</a><?php else: ?><span class="dis">‹ Prev</span><?php endif; ?>
        <?php
          $win = array();
          for ($i = 1; $i <= (int)$total_pages; $i++) { if ($i === 1 || $i === (int)$total_pages || abs($i - $page) <= 1) $win[] = $i; }
          $win = array_values(array_unique($win)); $prev_w = 0;
          foreach ($win as $i): if ($prev_w && $i > $prev_w + 1): ?><span class="dis">…</span><?php endif; $prev_w = $i; ?>
          <?php if ($i === $page): ?><span class="cur" aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= $mk_url(array('page' => $i, 'per_page' => (int)$per_page)) ?>"><?= $i ?></a><?php endif; ?>
        <?php endforeach; ?>
        <?php if ($page < (int)$total_pages): ?><a href="<?= $mk_url(array('page' => $page + 1, 'per_page' => (int)$per_page)) ?>">Next ›</a><?php else: ?><span class="dis">Next ›</span><?php endif; ?>
      </nav>
    </div>
  </div>
  <?php endif; ?>
  <div style="padding:12px 20px 18px;font-size:11.5px;color:var(--t3);line-height:1.7;">
    No punch can be edited or deleted from this screen. A correction is a new event with a reason and an
    author; the original stays visible, so the day can always be reconstructed.
  </div>
</div>

<script>
(function () {
  document.querySelectorAll('.al-autosubmit').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });
  var search = document.getElementById('alSearch');
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && search && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
      e.preventDefault(); search.focus(); search.select();
    }
  });
  var wsearch = document.getElementById('alWorkerSearch');
  var wtimer = null;
  if (wsearch) {
    wsearch.addEventListener('input', function () {
      clearTimeout(wtimer);
      wtimer = setTimeout(function () { wsearch.form.submit(); }, 450);
    });
  }
  var timer = null;
  if (search) {
    search.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { search.form.submit(); }, 450);
    });
  }
})();
</script>

<?php endif; ?>
