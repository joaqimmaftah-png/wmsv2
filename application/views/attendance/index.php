<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Attendance home: where a worker stands today, and the supervisor's way into
 * the site screen, the geofence configuration and the punch log.
 *
 * Rendered inside the normal layout. `$on_site` is derived from punches —
 * never typed — so this page and the punch log can never disagree.
 */
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$is_supervisor = (isset($role_id) && (int)$role_id === 1);
$is_manager    = (isset($role_id) && (int)$role_id === 4);
$can_punch     = !$is_manager;   // Manager is view-only (MY_Controller guard)
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="ct" style="font-size:16px;">Attendance</div>
    <div class="cs">Site check-in / check-out by QR code + GPS</div>
  </div>
  <div class="flex ai-c gap-10" style="flex-wrap:wrap;">
    <?php if ($can_punch): ?>
      <a href="<?= base_url('attendance/punch') ?>" class="btn btn-primary btn-sm">Enter a check-in code</a>
    <?php endif; ?>
    <?php if ($is_supervisor): ?>
      <a href="<?= base_url('attendance/board') ?>" class="btn btn-ghost btn-sm">Site screen</a>
      <a href="<?= base_url('attendance/sites') ?>" class="btn btn-ghost btn-sm">Sites &amp; policy</a>
    <?php endif; ?>
    <?php if ($is_supervisor || $is_manager): ?>
      <a href="<?= base_url('attendance/log') ?>" class="btn btn-ghost btn-sm">Punch log</a>
    <?php endif; ?>
    <a href="<?= base_url('team/attendance') ?>" class="btn btn-ghost btn-sm">Daily attendance →</a>
  </div>
</div>

<?php if (empty($ready)): ?>
  <div class="card" style="border-left:3px solid var(--amber);">
    <div style="padding:16px 18px;font-size:12.5px;line-height:1.7;color:var(--t2);">
      <strong style="color:var(--t1);">Attendance punching is not set up on this installation.</strong><br>
      The tables <code>attendance_sites</code> and <code>attendance_punches</code> are missing. Apply the
      database migrations (<code>php scripts/migrate.php</code>), then a supervisor configures the site's
      coordinates and geofence radius. Until then the manual daily attendance screen keeps working as before.
    </div>
  </div>
<?php else: ?>

  <div class="grid g-2 mb-4" style="gap:16px;">
    <!-- my day -->
    <div class="card">
      <div class="ch" style="padding:14px 18px 8px;">
        <div><div class="ct">Today — <?= date('l, d M Y') ?></div>
          <div class="cs"><?= isset($my_punches) ? count($my_punches) : 0 ?> punch(es) recorded</div></div>
      </div>
      <div style="padding:6px 18px 16px;">
        <div class="flex ai-c gap-10" style="padding:10px 0 12px;font-size:13px;">
          <span style="width:8px;height:8px;border-radius:50%;background:<?= !empty($my_eligibility['on_site']) ? 'var(--green)' : 'var(--t4)' ?>;"></span>
          <span style="color:var(--t2);"><?= !empty($my_eligibility['on_site']) ? 'On site now' : 'Not checked in' ?></span>
        </div>

        <?php if (!empty($my_punches)): ?>
          <table class="tbl">
            <thead><tr><th>Punch</th><th>Time</th><th style="text-align:right;">Source</th></tr></thead>
            <tbody>
            <?php foreach ($my_punches as $p): ?>
              <tr>
                <td><span class="badge" style="background:<?= $p->punch_type === 'check_in' ? 'rgba(28,140,60,0.1);color:var(--green)' : 'rgba(180,83,9,0.1);color:var(--amber)' ?>">
                  <?= $p->punch_type === 'check_in' ? 'CHECK-IN' : 'CHECK-OUT' ?></span></td>
                <td class="mono" style="font-size:12px;"><?= date('H:i', strtotime((string)$p->punched_at)) ?></td>
                <td style="text-align:right;font-size:11.5px;color:var(--t3);">
                  <?= $p->origin === 'supervisor' ? 'Supervisor' : 'Phone' ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p style="font-size:12.5px;color:var(--t3);line-height:1.6;">
            <?php if ($can_punch): ?>
              No punches yet. Scan the QR code on the site screen, or
              <a href="<?= base_url('attendance/punch') ?>">enter the code by hand</a>.
            <?php else: ?>
              No punches recorded for your account today.
            <?php endif; ?>
          </p>
        <?php endif; ?>

        <?php if (!empty($my_eligibility['reasons'])): ?>
          <div style="margin-top:14px;padding:10px 12px;border-radius:8px;background:rgba(180,83,9,0.07);
                      color:var(--amber);font-size:11.5px;line-height:1.6;">
            <strong>Assignment:</strong> <?= $h(implode('; ', $my_eligibility['reasons'])) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- on site now -->
    <div class="card">
      <div class="ch" style="padding:14px 18px 8px;">
        <div><div class="ct">On site now</div>
          <div class="cs">Latest punch of the day is a check-in</div></div>
      </div>
      <div style="padding:6px 18px 16px;">
        <?php if (empty($on_site)): ?>
          <p style="font-size:12.5px;color:var(--t3);padding:10px 0;">
            Nobody is checked in at the moment. A technician must check in before being assigned a new work order<?= !empty($gate_enabled) ? ' (the assignment gate is on)' : '' ?>.
          </p>
        <?php else: ?>
          <table class="tbl">
            <thead><tr><th>Worker</th><th>Site</th><th style="text-align:right;">Since</th></tr></thead>
            <tbody>
            <?php foreach ($on_site as $uid => $p): ?>
              <tr>
                <td style="font-size:12.5px;"><?= $h(trim((string)($p->first_name ?? '') . ' ' . (string)($p->last_name ?? ''))) ?></td>
                <td style="font-size:11.5px;color:var(--t3);"><?= $h($p->site_name ?? '—') ?></td>
                <td style="text-align:right;font-size:11.5px;color:var(--t3);" class="mono">
                  <?= date('H:i', strtotime((string)$p->punched_at)) ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($is_supervisor): ?>
  <div class="card">
    <div style="padding:16px 18px;font-size:12.5px;line-height:1.7;color:var(--t2);">
      <strong style="color:var(--t1);">How the site screen works.</strong>
      Open <a href="<?= base_url('attendance/board') ?>">the site screen</a> on the phone or tablet at the
      entrance and leave it open: it shows a signed QR code that changes every few seconds, and it keeps
      changing by itself. Technicians scan it with the camera app, which opens the check-in page on their own
      signed-in session. Expired or forged codes are rejected on the server, and the position is checked
      against the site radius every time.
      <?php if (!empty($gate_enabled)): ?>
        <br><br><strong style="color:var(--t1);">The assignment gate is on:</strong>
        the work-order picker only offers technicians who are checked in, have not checked out, and are not
        holding an unfinished work order.
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>
