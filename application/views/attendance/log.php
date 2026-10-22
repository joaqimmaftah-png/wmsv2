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
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="ct" style="font-size:16px;">Punch log — <?= date('l, d F Y', strtotime($date)) ?></div>
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

<div class="grid g-2 mb-4" style="gap:16px;align-items:start;">
  <!-- ── per worker ─────────────────────────────────────────────── -->
  <div class="card">
    <div class="ch" style="padding:14px 18px 8px;">
      <div><div class="ct">Technicians</div><div class="cs">On-site state and assignment eligibility</div></div>
    </div>
    <div style="overflow-x:auto;">
      <table class="tbl" style="margin-top:10px;">
        <thead><tr><th>Worker</th><th>Punches</th><th>State</th><th>Eligible</th></tr></thead>
        <tbody>
        <?php if (empty($workers)): ?>
          <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--t3);">No active technicians.</td></tr>
        <?php else: foreach ($workers as $w):
          $list = isset($by_user[(int)$w->id]) ? $by_user[(int)$w->id] : array(); ?>
          <tr>
            <td style="font-size:12.5px;">
              <?= $h(trim($w->first_name . ' ' . $w->last_name)) ?>
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
            <label class="form-label">Worker</label>
            <select name="user_id" class="form-select" required>
              <option value="">Choose…</option>
              <?php foreach ((!empty($correction_workers) ? $correction_workers : $workers) as $w): ?>
                <option value="<?= (int)$w->id ?>"><?= $h(trim($w->first_name . ' ' . $w->last_name)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label">Date</label>
            <input type="date" name="date" class="form-control" required max="<?= date('Y-m-d') ?>"
                   value="<?= $h($date) ?>">
          </div>
          <div>
            <label class="form-label">Time</label>
            <input type="time" name="time" class="form-control" required value="<?= date('H:i') ?>">
          </div>
          <div>
            <label class="form-label">Punch</label>
            <select name="punch_type" class="form-select">
              <option value="check_in">Check-in</option>
              <option value="check_out">Check-out</option>
            </select>
          </div>
        </div>

        <div style="margin-top:12px;">
          <label class="form-label">Site (optional)</label>
          <select name="site_id" class="form-select">
            <option value="">—</option>
            <?php foreach ($sites as $s): ?>
              <option value="<?= (int)$s->id ?>"><?= $h($s->name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="margin-top:12px;">
          <label class="form-label">Reason (required)</label>
          <input type="text" name="reason" class="form-control" maxlength="300" required
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
    <div><div class="ct">Punch events</div><div class="cs">Newest last · server timestamps</div></div>
  </div>
  <div style="overflow-x:auto;">
    <table class="tbl" style="margin-top:10px;">
      <thead>
        <tr><th>Time</th><th>Worker</th><th>Punch</th><th>Site</th><th>Distance</th><th>Accuracy</th>
            <th>Origin</th><th>Recorded by / reason</th></tr>
      </thead>
      <tbody>
      <?php if (empty($punches)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--t3);">No punches on this date.</td></tr>
      <?php else: foreach ($punches as $p): ?>
        <tr>
          <td class="mono" style="font-size:12px;"><?= date('H:i:s', strtotime((string)$p->punched_at)) ?></td>
          <td style="font-size:12.5px;"><?= $h(trim((string)($p->first_name ?? '') . ' ' . (string)($p->last_name ?? ''))) ?></td>
          <td><span class="badge" style="background:<?= $p->punch_type === 'check_in' ? 'rgba(28,140,60,0.1);color:var(--green)' : 'rgba(180,83,9,0.1);color:var(--amber)' ?>">
            <?= $p->punch_type === 'check_in' ? 'CHECK-IN' : 'CHECK-OUT' ?></span></td>
          <td style="font-size:11.5px;color:var(--t3);"><?= $h($p->site_name ?? '—') ?></td>
          <td style="font-size:11.5px;color:var(--t3);">
            <?= $p->distance_m !== NULL ? (int)round((float)$p->distance_m) . ' m' : '—' ?>
            <?php if ($p->radius_m !== NULL): ?>
              <span style="color:var(--t4);">/ <?= (int)$p->radius_m ?> m</span>
            <?php endif; ?>
          </td>
          <td style="font-size:11.5px;color:var(--t3);">
            <?= $p->accuracy_m !== NULL ? '±' . (int)round((float)$p->accuracy_m) . ' m' : '—' ?>
          </td>
          <td style="font-size:11.5px;">
            <?php if ($p->origin === 'supervisor'): ?>
              <span class="badge" style="background:rgba(109,63,192,0.1);color:#6d3fc0;">CORRECTION</span>
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
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div style="padding:12px 20px 18px;font-size:11.5px;color:var(--t3);line-height:1.7;">
    No punch can be edited or deleted from this screen. A correction is a new event with a reason and an
    author; the original stays visible, so the day can always be reconstructed.
  </div>
</div>

<?php endif; ?>
