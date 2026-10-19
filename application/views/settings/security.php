<?php
/**
 * Settings → Sign-in security (finding F-19, second half).
 *
 * Wave 1 gave the sign-in screen a throttle. Nothing told anybody it fired.
 * This screen is the missing half: the policy in force, what has actually
 * happened, and a button that means "a person has seen this".
 *
 * Everything printed here is escaped: `subject` is an e-mail address or an IP
 * the attacker chose, and `details` is generated text. Neither is trusted.
 */
$p = $policy;
$fmt = function ($n) { return number_format((int)$n); };
$badge = array(
    'account_lockout' => array('Account lockout', 'var(--red)',   'var(--red-d)'),
    'ip_spray'        => array('Password spray',  'var(--amber)', 'var(--amber-d)'),
);
?>

<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:10px;">
  <div>
    <div class="ct" style="font-size:16px;">Sign-in Security</div>
    <div class="cs">What the sign-in throttle does, and what it has actually stopped</div>
  </div>
  <a href="<?= base_url('settings') ?>" class="btn btn-ghost btn-sm">← System settings</a>
</div>

<?php if (empty($events_ready)): ?>
<!-- ── the visibility table is not there yet ─────────────────────────── -->
<div class="card mb-4" style="border-left:3px solid var(--amber);">
  <div style="padding:16px 18px;font-size:12.5px;color:var(--t2);line-height:1.6;">
    <strong style="color:var(--t1);">Event history is not available yet.</strong><br>
    Throttling itself is <?= empty($attempts_ready) ? '<strong>also off</strong> — the counters table is missing too' : 'running' ?>.
    Both tables are part of the database dump shipped with this repository —
    restore the missing one to record what fires.
  </div>
</div>
<?php endif; ?>

<!-- ═══ POLICY IN FORCE ═══ -->
<div class="grid g-2 mb-4" style="gap:16px;">
  <div class="card" style="padding:0;">
    <div class="ch" style="padding:14px 18px 10px;">
      <div><div class="ct">Policy in force</div>
      <div class="cs">Counters are time-based: a lock releases by itself after <?= (int)$p['window_minutes'] ?> minutes</div></div>
    </div>
    <div style="padding:6px 18px 16px;">
      <table class="tbl">
        <tbody>
          <tr><td>Failures for one account from one source</td>
              <td class="mono" style="text-align:right;font-weight:700;"><?= (int)$p['pair_attempts'] ?></td>
              <td style="color:var(--t3);font-size:11px;">locks that pair for <?= (int)$p['window_minutes'] ?> min</td></tr>
          <tr><td>Failures for one account from any source</td>
              <td class="mono" style="text-align:right;font-weight:700;"><?= (int)$p['account_attempts'] ?></td>
              <td style="color:var(--t3);font-size:11px;">stops one mailbox being hammered</td></tr>
          <tr><td>Failures from one source across <em>different</em> accounts</td>
              <td class="mono" style="text-align:right;font-weight:700;">
                <?= $p['ip_cap_enabled'] ? (int)$p['source_attempts'] : '—' ?></td>
              <td style="color:var(--t3);font-size:11px;">
                <?= $p['ip_cap_enabled']
                    ? 'only when it touches '.((int)$p['spray_accounts']).'+ accounts (password spraying)'
                    : '<strong>off</strong> on this installation' ?></td></tr>
        </tbody>
      </table>

      <?= form_open('settings/security', array('style' => 'margin-top:14px;')) ?>
        <input type="hidden" name="action" value="policy">
        <label class="form-label" style="display:flex;gap:8px;align-items:flex-start;font-weight:500;">
          <input type="checkbox" name="login_ip_cap_enabled" value="1" <?= $p['ip_cap_enabled'] ? 'checked' : '' ?> style="margin-top:2px;">
          <span style="font-size:11.5px;color:var(--t2);line-height:1.5;">
            Limit failures from one source across many accounts.
            <br><span style="color:var(--t3);">Turn this off only if every visitor appears to come from the same address
            (a proxy or CDN without <code>proxy_ips</code> configured) — there the rule would lock out the whole site.
            The two account limits keep working either way.</span>
          </span>
        </label>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:10px;">Save policy</button>
      <?= form_close() ?>
    </div>
  </div>

  <!-- ═══ WHAT HAS ACTUALLY HAPPENED ═══ -->
  <div class="card" style="padding:0;">
    <div class="ch" style="padding:14px 18px 10px;">
      <div><div class="ct">Last <?= $hours >= 720 ? '30 days' : ($hours >= 168 ? '7 days' : '24 hours') ?></div>
      <div class="cs">Failed sign-ins, and the events worth acting on</div></div>
      <div>
        <?php foreach (array(24 => '24 h', 168 => '7 d', 720 => '30 d') as $h => $lbl): ?>
        <a href="<?= base_url('settings/security?hours='.$h) ?>" class="ca"
           style="<?= $hours === $h ? 'font-weight:700;color:var(--blue);' : '' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="padding:6px 18px 16px;">
      <?php if ($recent === NULL): ?>
      <div style="color:var(--t3);font-size:12px;padding:8px 0;">
        Failed-attempt counters are not being kept: <code>login_attempts</code> is missing.
      </div>
      <?php else: ?>
      <div class="grid g-4" style="gap:10px;margin:8px 0 14px;">
        <?php
        $tiles = array(
            array('Failures, last hour',  $fmt($recent['last_hour']), 'var(--blue)'),
            array('Failures, last 24 h',  $fmt($recent['last_day']),  'var(--blue)'),
            array('Distinct accounts',    $fmt($recent['accounts']),  'var(--amber)'),
            array('Distinct sources',     $fmt($recent['sources']),   'var(--amber)'),
        );
        foreach ($tiles as $t): ?>
        <div style="background:var(--g1);border-radius:10px;padding:10px 12px;">
          <div style="font-size:9.5px;color:var(--t3);text-transform:uppercase;font-weight:700;"><?= $t[0] ?></div>
          <div class="mono" style="font-size:20px;font-weight:800;color:<?= $t[2] ?>;"><?= $t[1] ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div style="display:flex;gap:16px;font-size:12px;">
        <span>Lockouts: <strong><?= (int)$counts['account_lockout'] ?></strong></span>
        <span>Spray detections: <strong><?= (int)$counts['ip_spray'] ?></strong></span>
      </div>

      <?php if ($p['ip_cap_enabled'] && $recent !== NULL && $recent['sources'] === 1 && $recent['last_day'] > (int)$p['source_attempts']): ?>
      <div style="margin-top:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:9px 11px;font-size:11.5px;color:#92400e;line-height:1.55;">
        <strong>Every failed sign-in came from one address.</strong> If this installation sits behind a proxy or CDN,
        set <code>proxy_ips</code> in <code>application/config/config.php</code> (or turn the source limit off above) —
        otherwise legitimate users share one address and the spray rule is measuring the wrong thing.
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══ THE EVENTS ═══ -->
<div class="card" style="padding:0;">
  <div class="ch" style="padding:14px 18px 10px;">
    <div><div class="ct">Security events</div>
    <div class="cs">Recorded when a lockout or spray detection fires · kept 90 days · newest first</div></div>
  </div>

  <?php if (empty($events)): ?>
  <div style="padding:26px 18px;text-align:center;color:var(--t3);font-size:12.5px;">
    Nothing has fired. That is the normal state — this list is here for the day it is not.
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="tbl">
      <thead>
        <tr>
          <th>WHEN</th><th>WHAT</th><th>SUBJECT</th><th>SOURCE</th>
          <th style="text-align:center;">FAILURES</th><th>DETAIL</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($events as $e):
        $b = isset($badge[$e->event_type]) ? $badge[$e->event_type] : array($e->event_type, 'var(--t2)', 'var(--g1)');
      ?>
        <tr>
          <td style="font-size:11.5px;color:var(--t2);white-space:nowrap;">
            <?= date('d M Y, H:i', strtotime($e->created_at)) ?>
          </td>
          <td>
            <span style="font-size:10px;font-weight:700;color:<?= $b[1] ?>;background:<?= $b[2] ?>;padding:2px 7px;border-radius:6px;white-space:nowrap;">
              <?= $b[0] ?>
            </span>
          </td>
          <td class="mono" style="font-size:11.5px;"><?= htmlspecialchars($e->subject) ?></td>
          <td class="mono" style="font-size:11.5px;color:var(--t3);"><?= htmlspecialchars($e->ip_address) ?></td>
          <td class="mono" style="text-align:center;"><?= (int)$e->attempts ?>
            <?php if ($e->distinct_accounts !== NULL): ?>
            <div style="font-size:9.5px;color:var(--t3);"><?= (int)$e->distinct_accounts ?> accounts</div>
            <?php endif; ?>
          </td>
          <td style="font-size:11.5px;color:var(--t2);"><?= htmlspecialchars((string)$e->details) ?></td>
          <td style="text-align:right;">
            <?php if ($e->acknowledged_at === NULL): ?>
            <?= form_open('settings/security', array('style' => 'display:inline;margin:0;')) ?>
              <input type="hidden" name="action" value="acknowledge">
              <input type="hidden" name="id" value="<?= (int)$e->id ?>">
              <input type="text" name="note" class="form-control" placeholder="what was done"
                     style="width:130px;display:inline-block;font-size:11px;padding:3px 7px;vertical-align:middle;">
              <button type="submit" class="btn btn-ghost btn-sm">Acknowledge</button>
            <?= form_close() ?>
            <?php else: ?>
            <span style="font-size:11px;color:var(--green);font-weight:600;">✓ acknowledged</span>
            <div style="font-size:10px;color:var(--t3);"><?= date('d M, H:i', strtotime($e->acknowledged_at)) ?>
              <?php if (!empty($e->acknowledged_note)): ?> · <?= htmlspecialchars($e->acknowledged_note) ?><?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div style="padding:12px 18px;border-top:1px solid var(--g2);font-size:11px;color:var(--t3);line-height:1.6;">
    An event is recorded once per subject per <?= (int)$p['window_minutes'] ?>-minute window: an attacker who keeps
    trying after being locked does not bury the first event under forty identical ones.
    Addresses here are the account names that were attempted and the source they came from —
    the same information the throttling counters already hold.
  </div>
</div>
