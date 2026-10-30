<?php
$status_colors = ['Open'=>'b-open','In Progress'=>'b-progress','Waiting Parts'=>'b-waiting','Waiting Tools'=>'b-waiting','Completed'=>'b-completed','Closed'=>'b-closed'];
$prio_colors   = ['Critical'=>'b-critical','High'=>'b-high','Medium'=>'b-medium','Low'=>'b-low'];
// W0.1: the breakdown badge follows the is_breakdown flag (see application/helpers/wo_helper.php)
$type_colors   = ['Preventive Maintenance'=>'b-primary','Incident'=>'b-warning'];

$sc = (isset($status_colors[$wo->status]) ? $status_colors[$wo->status] : 'b-secondary');
$pc = (isset($prio_colors[$wo->priority]) ? $prio_colors[$wo->priority] : 'b-secondary');
$tc = (wo_is_breakdown($wo) ? 'b-danger' : (isset($type_colors[$wo->type]) ? $type_colors[$wo->type] : 'b-secondary'));

// Every status the enum offers must have a way out of itself: `Waiting Tools`
// was missing here, so a work order parked on it rendered no transition
// options and could only be moved through the edit form.
$status_next = [
  'Open'          => ['In Progress','Waiting Parts','Waiting Tools'],
  'In Progress'   => ['Waiting Parts','Waiting Tools','Completed'],
  'Waiting Parts' => ['In Progress','Waiting Tools','Completed'],
  'Waiting Tools' => ['In Progress','Waiting Parts','Completed'],
  'Completed'     => ['Closed'],
  'Closed'        => [],
];
$next_statuses = (isset($status_next[$wo->status]) ? $status_next[$wo->status] : []);
$viewerIsSupervisor = isset($role_id) && (int)$role_id === 1;
if (!$viewerIsSupervisor) $next_statuses = array_values(array_filter($next_statuses, function ($s) { return $s !== 'Closed'; }));
$mayUpdateStatus = isset($role_id) && in_array((int)$role_id, array(1, 2), TRUE);
?>
<style>
.wo-detail-grid > * { min-width: 0; }
.wo-kpi-strip { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 12px; }
@media (max-width: 900px) {
  .wo-detail-grid { grid-template-columns: minmax(0, 1fr); }
  .wo-detail-grid > .col-2 { grid-column: auto; }
  .wo-kpi-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>

<!-- WO Header -->
<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:12px;">
  <div>
    <div class="flex ai-c gap-10" style="flex-wrap:wrap;align-items:center;margin-bottom:6px;">
      <h1 class="mono" style="font-size:19px;font-weight:600;letter-spacing:-0.5px;color:var(--blue);"><?= htmlspecialchars((string)$wo->wo_number, ENT_QUOTES, 'UTF-8') ?></h1>
      <span class="badge <?= $sc ?>"><?= htmlspecialchars((string)$wo->status, ENT_QUOTES, 'UTF-8') ?></span>
      <span class="badge <?= $pc ?>"><?= htmlspecialchars((string)$wo->priority, ENT_QUOTES, 'UTF-8') ?></span>
      <span class="badge <?= $tc ?>"><?= htmlspecialchars((string)$wo->type, ENT_QUOTES, 'UTF-8') ?></span>
      <?php if (!empty($wo->hold_code)): ?>
      <span class="badge b-waiting" title="Hold code — why the work is waiting">
        <?= htmlspecialchars((string)($wo->hold_code), ENT_QUOTES, 'UTF-8') ?><?= wo_hold_label($wo->hold_code) ? ' — '.htmlspecialchars((string)(wo_hold_label($wo->hold_code)), ENT_QUOTES, 'UTF-8') : '' ?>
      </span>
      <?php endif; ?>
    </div>
    <div style="font-size:14px;font-weight:500;color:var(--t1);"><?= htmlspecialchars((string)($wo->title), ENT_QUOTES, 'UTF-8') ?></div>
  </div>
  <div class="flex ai-c gap-10">
    <!-- PRINT JOB CARD BUTTON — primary action -->
    <a href="<?= base_url('workorders/jobcard/'.$wo->id) ?>" target="_blank" class="btn btn-primary btn-icon" style="background:linear-gradient(135deg,#1a6fff,#0a50cc);box-shadow:0 4px 20px rgba(0,113,227,0.35);">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print Job Card
    </a>
    <?php if ($wo->status !== 'Closed' && empty($is_manager) && (!isset($role_id) || (int)$role_id !== 4)): ?>
    <a href="<?= base_url('workorders/edit/'.$wo->id) ?>" class="btn btn-ghost btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Edit
    </a>
    <?php endif; ?>
    <a href="<?= base_url('workorders') ?>" class="btn btn-ghost">← Back</a>
  </div>
</div>

<!-- Details Grid -->
<div class="grid g-2-1 mb-3 wo-detail-grid">

  <!-- Left: WO Details + Description -->
  <div class="col-2">

    <!-- Info Cards Strip -->
    <div class="grid wo-kpi-strip">
      <div class="kpi" style="animation-delay:.05s;">
        <div class="kpi-glow" style="background:var(--blue);"></div>
        <span class="kpi-tag">Equipment</span>
        <div style="font-size:15px;font-weight:700;color:var(--t1);margin-top:4px;"><?= htmlspecialchars((string)(isset($wo->registration_no) ? $wo->registration_no : '—'), ENT_QUOTES, 'UTF-8') ?></div>
        <div style="font-size:10px;color:var(--t3);margin-top:2px;"><?= htmlspecialchars((string)((isset($wo->brand) ? $wo->brand : '') . ' ' . (isset($wo->model) ? $wo->model : '')), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="kpi" style="animation-delay:.09s;">
        <div class="kpi-glow" style="background:var(--amber);"></div>
        <span class="kpi-tag">SMR / Hours</span>
        <div class="kpi-num" style="font-size:18px;margin-top:4px;"><?= htmlspecialchars((string)(isset($wo->hours_reading) ? $wo->hours_reading : (isset($wo->hour_meter_reading) ? $wo->hour_meter_reading : '—')), ENT_QUOTES, 'UTF-8') ?><sup style="font-size:11px;">hrs</sup></div>
      </div>
      <div class="kpi" style="animation-delay:.13s;">
        <div class="kpi-glow" style="background:var(--purple);"></div>
        <span class="kpi-tag">Assigned To</span>
        <div style="font-size:13px;font-weight:600;color:var(--t1);margin-top:4px;"><?= htmlspecialchars((string)((isset($wo->assigned_name) && $wo->assigned_name !== '') ? $wo->assigned_name : '—'), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="kpi" style="animation-delay:.17s;">
        <div class="kpi-glow" style="background:var(--red);"></div>
        <span class="kpi-tag">Downtime</span>
        <div class="kpi-num" style="font-size:18px;margin-top:4px;">
          <?php
          if ($wo->start_datetime && $wo->end_datetime) {
            $diff = (strtotime($wo->end_datetime) - strtotime($wo->start_datetime)) / 3600;
            echo number_format((float)($diff),1);
          } else { echo (isset($wo->downtime_hours) ? $wo->downtime_hours : '—'); }
          ?><sup style="font-size:11px;">hrs</sup>
        </div>
      </div>
    </div>

    <!-- Work Sessions (multi-stint time tracking) -->
    <div class="card mb-3" style="animation-delay:.18s;">
      <div class="ch">
        <div><div class="ct">Work Sessions</div><div class="cs"><?= count($sessions) ?> session(s) &middot; <?= number_format((float)($session_hours),2) ?> hrs logged</div></div>
        <?php if (!$open_session && $mayUpdateStatus && !in_array($wo->status, array('Completed','Closed'), TRUE)): ?>
        <span class="ca" onclick="document.getElementById('start-session-form').style.display='block';this.style.display='none';">+ Start Session</span>
        <?php endif; ?>
      </div>
      <div style="padding:14px 22px;">

        <?php if ($open_session): ?>
        <!-- Active session banner -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--green-fill);border:1px solid rgba(28,140,60,0.18);border-radius:10px;margin-bottom:14px;">
          <div>
            <div style="font-size:12px;font-weight:600;color:var(--green);">● Session <?= (int)$open_session->session_no ?> in progress</div>
            <div style="font-size:10.5px;color:var(--t3);margin-top:2px;"><?= htmlspecialchars((string)($open_session->technician_name ?: 'Unassigned')) ?> &middot; started <?= date('d M, H:i', strtotime($open_session->start_datetime)) ?></div>
          </div>
        </div>
        <?php if ($mayUpdateStatus && $wo->status !== 'Closed'): ?>
        <?= form_open('workorders/close_session/'.$open_session->id.'/'.$wo->id) ?>
        <div style="margin-bottom:10px;">
          <label class="form-label">What was done in this session?</label>
          <textarea name="work_performed" class="form-control" rows="2" placeholder="Brief note on work performed this stint..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-sm w-full">Stop &amp; Log Hours</button>
        <?= form_close() ?>
        <?php endif; ?>

        <?php elseif ($mayUpdateStatus && !in_array($wo->status, array('Completed','Closed'), TRUE)): ?>
        <!-- Start new session form (hidden until "+ Start Session" clicked, or shown if no sessions yet) -->
        <div id="start-session-form" style="<?= count($sessions) > 0 ? 'display:none;' : '' ?>">
          <?php
          // Only offer technicians the supervisor actually assigned to this WO.
          // Falls back to the full technician list only if none were assigned yet.
          $assigned_ids = !empty($wo->assigned_to) ? array_filter(array_map('trim', explode(',', $wo->assigned_to))) : array();
          $tech_choices = $assigned_ids
              ? array_filter($technicians, function($t) use ($assigned_ids) { return in_array((string)$t->id, $assigned_ids); })
              : $technicians;
          $me = $this->session->userdata('user_id');
          ?>
          <?= form_open('workorders/start_session/'.$wo->id) ?>
          <?php if ($assigned_ids && empty($tech_choices)): ?>
          <div style="font-size:11.5px;color:var(--amber);background:var(--amber-fill);border-radius:8px;padding:8px 12px;margin-bottom:10px;">
            The technician(s) assigned to this WO could not be matched — showing all technicians instead.
          </div>
          <?php $tech_choices = $technicians; endif; ?>
          <?php
          $me_in_choices = false;
          foreach ($tech_choices as $t) { if ($t->id == $me) { $me_in_choices = true; break; } }
          $default_id = $me_in_choices ? $me : (reset($tech_choices) ? reset($tech_choices)->id : null);
          ?>
          <div class="form-grid cols-2" style="margin-bottom:10px;">
            <div>
              <label class="form-label">Technician<?= $assigned_ids ? ' (assigned to this WO)' : '' ?></label>
              <select name="technician_id" class="form-select">
                <?php foreach ($tech_choices as $t): ?>
                <option value="<?= $t->id ?>" <?= $t->id==$default_id?'selected':'' ?>><?= htmlspecialchars((string)($t->first_name.' '.$t->last_name)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label">Reason (if resuming after interruption)</label>
              <input type="text" name="interruption_reason" class="form-control" placeholder="e.g. back from WO-118 emergency">
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-sm w-full"><?= count($sessions) > 0 ? 'Resume Work' : 'Start Work' ?></button>
          <?= form_close() ?>
        </div>
        <?php endif; ?>

        <?php if (count($sessions) > 0): ?>
        <div style="margin-top:16px;border-top:1px solid var(--sep);padding-top:12px;">
          <?php foreach ($sessions as $s): ?>
          <div class="row-list-item" style="cursor:default;padding:10px 0;">
            <div style="width:7px;height:7px;border-radius:50%;background:<?= $s->status==='Open'?'var(--green)':'var(--t4)' ?>;flex-shrink:0;margin-top:4px;"></div>
            <div style="flex:1;">
              <div style="font-size:12px;font-weight:600;color:var(--t1);">Session <?= (int)$s->session_no ?> — <?= htmlspecialchars((string)($s->technician_name ?: 'Unassigned')) ?></div>
              <div style="font-size:10.5px;color:var(--t3);">
                <?= date('d M, H:i', strtotime($s->start_datetime)) ?>
                <?= $s->end_datetime ? ' → '.date('d M, H:i', strtotime($s->end_datetime)) : ' (in progress)' ?>
                <?php if (!empty($s->switched_to_wo_id) && !empty($s->switched_to_wo_number)): ?>
                &middot; switched to <a href="<?= base_url('workorders/view/'.$s->switched_to_wo_id) ?>" style="color:var(--blue);font-weight:600;"><?= htmlspecialchars((string)($s->switched_to_wo_number)) ?></a>
                <?php elseif ($s->interruption_reason): ?>
                &middot; <?= htmlspecialchars((string)($s->interruption_reason)) ?>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($s->status === 'Closed'): ?>
            <div class="mono" style="font-size:11.5px;font-weight:600;color:var(--t1);margin-right:10px;"><?= number_format((float)($s->hours_worked),2) ?>h</div>
            <a href="<?= base_url('workorders/session_jobcard/'.$s->id) ?>" target="_blank" class="btn btn-ghost btn-sm">Job Card</a>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Description / Symptoms -->
    <div class="card mb-3" style="animation-delay:.20s;">
      <div class="ch">
        <div><div class="ct">Work Description & Symptoms</div><div class="cs">What was found / reported</div></div>
      </div>
      <div style="padding:16px 22px;">
        <?php if ($wo->symptoms || $wo->description): ?>
        <div style="font-size:13px;color:var(--t2);line-height:1.6;"><?= nl2br((string)(htmlspecialchars((string)((isset($wo->symptoms) ? $wo->symptoms : (isset($wo->description) ? $wo->description : '')))))) ?></div>
        <?php else: ?>
        <div style="color:var(--t3);font-size:13px;">No description recorded.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Root Cause / Work Completed -->
    <div class="card mb-3" style="animation-delay:.24s;">
      <div class="ch">
        <div><div class="ct">Work Completed / Root Cause</div><div class="cs">Actions taken</div></div>
      </div>
      <div style="padding:16px 22px;">
        <?php if ($wo->root_cause): ?>
        <div style="font-size:13px;color:var(--t2);line-height:1.6;"><?= nl2br((string)(htmlspecialchars((string)($wo->root_cause)))) ?></div>
        <?php else: ?>
        <div style="color:var(--t3);font-size:13px;">Not yet recorded.</div>
        <?php endif; ?>
        <?php if (!empty($wo->action_taken)): ?><div style="font-size:13px;color:var(--t2);line-height:1.6;margin-top:8px;"><strong>Work done:</strong> <?= nl2br(htmlspecialchars((string)$wo->action_taken, ENT_QUOTES, 'UTF-8')) ?></div><?php endif; ?>
      </div>
    </div>

    <!-- Spare Parts -->
    <?php
    // Pending Spare Parts Tracking: while the WO is open we show a live
    // availability view (requested vs. in stock); once it is completed the
    // list reads as the classic "parts used" record.
    $wo_pending = in_array($wo->status, array('Open','In Progress','Waiting Parts','Waiting Tools'));
    $pa_lines   = isset($parts_availability) ? $parts_availability : null;
    $pa_sum     = isset($parts_summary) ? $parts_summary : null;
    $pa_dot = array(
      'Allocated'    => array('🟢', 'var(--green)', 'b-success'),
      'Available'    => array('🟢', 'var(--green)', 'b-success'),
      'Partial'      => array('🟡', 'var(--amber)', 'b-warning'),
      'Pending'      => array('🔴', 'var(--red)',   'b-critical'),
      'Unlinked'     => array('⚪', 'var(--t3)',    'b-secondary'),
      'From Machine' => array('🟡', 'var(--amber)', 'b-warning'),
    );
    $sp_qty_fmt = function ($n) { return rtrim((string)(rtrim((string)(number_format((float)$n, 2, '.', '')), '0')), '.'); };
    ?>
    <div class="card mb-3" style="animation-delay:.28s;">
      <div class="ch">
        <div>
          <div class="ct"><?= $wo_pending ? 'Required Spare Parts' : 'Spare Parts Used' ?></div>
          <div class="cs">
            <?php if ($wo_pending && $pa_sum && $pa_sum['tracked'] > 0): ?>
              Spare Parts: <strong style="color:<?= $pa_sum['available'] === $pa_sum['tracked'] ? 'var(--green)' : 'var(--amber)' ?>;">
                <?= (int)$pa_sum['available'] ?> of <?= (int)$pa_sum['tracked'] ?> Available</strong>
              <?php if ($pa_sum['partial'] > 0): ?> · <?= (int)$pa_sum['partial'] ?> partial<?php endif; ?>
              <?php if ($pa_sum['pending'] > 0): ?> · <?= (int)$pa_sum['pending'] ?> pending<?php endif; ?>
              <?php if ($pa_sum['unlinked'] > 0): ?> · <?= (int)$pa_sum['unlinked'] ?> not linked to inventory<?php endif; ?>
            <?php else: ?>
              <?= count($spare_parts) ?> items
            <?php endif; ?>
          </div>
        </div>
        <?php if ($wo_pending): ?><a href="<?= base_url('spareparts') ?>" class="ca">Pending board →</a><?php endif; ?>
      </div>

      <?php if ($wo_pending && $pa_lines !== null && count($pa_lines) > 0): ?>
      <!-- Availability banner -->
      <?php if ($pa_sum['tracked'] > 0 && $pa_sum['available'] === $pa_sum['tracked']): ?>
      <div style="margin:12px 22px 0;padding:9px 14px;background:var(--green-fill);border:1px solid rgba(28,140,60,0.18);border-radius:10px;font-size:12px;font-weight:600;color:var(--green);">
        ✓ All required spare parts are available — the job can proceed.
      </div>
      <?php elseif ($pa_sum['tracked'] > 0): ?>
      <div style="margin:12px 22px 0;padding:9px 14px;background:var(--amber-fill);border:1px solid rgba(180,83,9,0.18);border-radius:10px;font-size:12px;font-weight:600;color:var(--amber);">
        ⏳ Waiting for spare parts<?= $wo->status !== 'Waiting Parts' ? ' — consider setting the status to “Waiting Parts”' : '' ?>.
        You will be notified automatically as soon as the store receives them.
      </div>
      <?php endif; ?>

      <table class="tbl" style="margin-top:12px;">
        <thead>
          <tr><th>Status</th><th>Spare Part</th><th>Item Code</th><th>Part No.</th><th>Required</th><th>In Stock</th><th>Reserved</th></tr>
        </thead>
        <tbody>
          <?php foreach ($pa_lines as $sp):
            $st   = isset($sp->avail_status) ? $sp->avail_status : 'Pending';
            $info = isset($pa_dot[$st]) ? $pa_dot[$st] : array('⚪','var(--t3)','b-secondary');
          ?>
          <tr>
            <td style="white-space:nowrap;"><span class="badge <?= $info[2] ?>" style="font-size:10px;"><?= $info[0] ?> <?= htmlspecialchars((string)($st)) ?></span></td>
            <td style="font-weight:500;">
              <?= htmlspecialchars((string)(isset($sp->part_name) ? $sp->part_name : '')) ?>
              <?php if ($st === 'From Machine' && !empty($sp->source_machine)): ?>
              <div style="font-size:10px;color:var(--t3);">from <?= htmlspecialchars((string)($sp->source_machine)) ?></div>
              <?php endif; ?>
              <?php if ($st === 'Unlinked'): ?>
              <div style="font-size:10px;color:var(--t3);">item code missing / not in inventory</div>
              <?php endif; ?>
            </td>
            <td class="mono" style="font-size:11px;"><?= htmlspecialchars((string)(!empty($sp->item_code) ? $sp->item_code : '—')) ?></td>
            <td class="mono" style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)(isset($sp->part_number) ? $sp->part_number : '')) ?></td>
            <td class="mono" style="font-weight:600;"><?= isset($sp->quantity) ? $sp_qty_fmt($sp->quantity) : '' ?> <span style="color:var(--t4);font-size:10px;"><?= htmlspecialchars((string)(isset($sp->unit) ? $sp->unit : 'pcs')) ?></span></td>
            <td class="mono" style="font-weight:600;color:<?= $st === 'From Machine' ? 'var(--t3)' : ($sp->stock_qty > 0 ? 'var(--t1)' : 'var(--red)') ?>;">
              <?= $st === 'From Machine' ? '—' : (isset($sp->stock_qty) ? $sp_qty_fmt($sp->stock_qty) : '—') ?>
            </td>
            <td class="mono" style="font-weight:600;color:var(--green);">
              <?php
              $alloc = isset($sp->qty_allocated) ? (float)$sp->qty_allocated : 0;
              $issued = isset($sp->qty_issued) ? (float)$sp->qty_issued : 0;
              if ($alloc > 0 || $issued > 0) {
                echo $sp_qty_fmt($alloc + $issued);
                if ($alloc > 0 && $issued > 0) echo ' <span style="color:var(--t4);font-size:10px;">('.$sp_qty_fmt($issued).' issued)</span>';
              } else { echo '—'; }
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php elseif (count($spare_parts) > 0): ?>
      <table class="tbl" style="margin-top:12px;">
        <thead>
          <tr><th>Item Code</th><th>Part Name</th><th>Part No.</th><th>Type</th><th>Source</th><th>Qty</th></tr>
        </thead>
        <tbody>
          <?php foreach ($spare_parts as $sp): ?>
          <tr>
            <td class="mono" style="font-size:11px;"><?= htmlspecialchars((string)((isset($sp->item_code) ? $sp->item_code : '—'))); ?></td>
            <td style="font-weight:500;"><?= htmlspecialchars((string)((isset($sp->part_name) ? $sp->part_name : ''))); ?></td>
            <td class="mono" style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((string)((isset($sp->part_number) ? $sp->part_number : ''))); ?></td>
            <td><?php
              $stype = isset($sp->spare_type) ? $sp->spare_type : 'New';
              if ($stype === 'From Machine') {
                echo '<span class="badge b-warning" style="font-size:10px;">From Machine</span>';
              } else {
                echo '<span class="badge b-success" style="font-size:10px;">New</span>';
              }
            ?></td>
            <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)(isset($sp->source_machine) ? $sp->source_machine : '')); ?></td>
            <td class="mono" style="font-weight:600;"><?= isset($sp->quantity) ? $sp->quantity : ''; ?> <span style="color:var(--t4);font-size:10px;"><?= isset($sp->unit) ? $sp->unit : 'pcs'; ?></span></td>
          </tr>
          <?php endforeach; ?>

        </tbody>
      </table>
      <?php else: ?>
      <div style="padding:24px;text-align:center;color:var(--t3);font-size:13px;">No spare parts recorded</div>
      <?php endif; ?>
    </div>

    <!-- Oil / Coolant Records (from wo_oil_records) -->
    <?php if (isset($oil_records) && count($oil_records) > 0): ?>
    <div class="card mb-3" style="animation-delay:.32s;">
      <div class="ch">
        <div><div class="ct">Oil / Coolant Top Up</div><div class="cs"><?= count($oil_records); ?> entries</div></div>
      </div>
      <div class="cs" style="padding:10px 22px 0;font-size:11.5px;color:var(--t3);">
        Lines marked <strong style="color:var(--green);">in lubricant ledger</strong> were also posted to
        Lubricants → consumption for this work order. Unmatched lines stay on the job card only.
      </div>
      <table class="tbl" style="margin-top:6px;">
        <thead><tr><th>System / Location</th><th>Oil / Fluid Type</th><th>Grade</th><th>Qty (L)</th><th>Lubricant Ledger</th></tr></thead>
        <tbody>
          <?php
          // Match the posted consumption rows back to the lines for display.
          // A queue per quantity: two lines with the same quantity each get
          // their own ledger row instead of the last one hiding the first.
          $lc_by_qty = array();
          if (!empty($lube_consumption)) {
              foreach ($lube_consumption as $lc) {
                  // Only rows this work order posted itself; a top-up recorded
                  // by hand against the same job is a separate fact.
                  if (strpos((string)$lc->notes, 'Auto: oil line') !== 0) continue;
                  $q = (string)round((float)$lc->quantity_used, 2);
                  if (!isset($lc_by_qty[$q])) $lc_by_qty[$q] = array();
                  $lc_by_qty[$q][] = $lc;
              }
          }
          foreach ($oil_records as $oil):
            $q = isset($oil->quantity) ? (string)round((float)$oil->quantity, 2) : '';
            $posted = (!empty($lc_by_qty[$q])) ? array_shift($lc_by_qty[$q]) : null;
          ?>
          <tr>
            <td style="font-weight:500;"><?= htmlspecialchars((string)(isset($oil->system_location) ? $oil->system_location : '')); ?></td>
            <td><?= htmlspecialchars((string)(isset($oil->oil_type) ? $oil->oil_type : '')); ?></td>
            <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars((string)(isset($oil->grade) ? $oil->grade : '')); ?></td>
            <td class="mono fw-600"><?= isset($oil->quantity) && $oil->quantity ? number_format((float)($oil->quantity),1) : '—'; ?> <span style="color:var(--t4);font-size:10px;">L</span></td>
            <td>
              <?php if ($posted): ?>
                <span class="badge b-success" style="font-size:10px;">✓ <?= htmlspecialchars((string)($posted->lube_name)) ?></span>
                <?php if ($posted->recommended_qty !== null): ?>
                <span style="font-size:10px;color:var(--t3);"> var. <?= ($posted->variance > 0 ? '+' : '') . number_format((float)$posted->variance, 1) ?> L</span>
                <?php endif; ?>
              <?php else: ?>
                <span style="font-size:10.5px;color:var(--t4);">not matched</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Breakdown event (one failure, several work orders) -->
    <?php
    $be_group = isset($breakdown_event) ? $breakdown_event : array();
    $be_candidates = isset($breakdown_candidates) ? $breakdown_candidates : array();
    // The event root is the row with no `breakdown_event_id` (it IS the event).
    $be_root = null;
    foreach ($be_group as $g) {
        if (empty($g->breakdown_event_id)) { $be_root = (int)$g->id; break; }
    }
    ?>
    <?php if (function_exists('wo_is_breakdown') && wo_is_breakdown($wo)): ?>
    <div class="card mb-3" style="animation-delay:.33s;border-left:3px solid var(--amber);">
      <div class="ch">
        <div>
          <div class="ct">Breakdown Event</div>
          <div class="cs">
            <?php if (count($be_group) > 1): ?>
              <?= count($be_group) ?> work orders grouped as one failure — the KPIs count the group once.
            <?php else: ?>
              This work order is its own breakdown event.
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div style="padding:14px 22px;">
        <?php if (count($be_group) > 1): ?>
        <div style="margin-bottom:14px;">
          <?php foreach ($be_group as $g): ?>
          <div class="row-list-item" style="padding:8px 0;border-bottom:1px solid var(--sep);">
            <div style="width:7px;height:7px;border-radius:50%;background:<?= ((int)$g->id === (int)$wo->id) ? 'var(--blue)' : 'var(--t4)' ?>;flex-shrink:0;"></div>
            <div style="flex:1;">
              <div style="font-size:12px;font-weight:600;color:var(--t1);">
                <?php if ((int)$g->id === (int)$wo->id): ?>
                  <?= htmlspecialchars((string)($g->wo_number)) ?> <span style="font-weight:400;color:var(--t3);">(this one)</span>
                <?php else: ?>
                  <a href="<?= base_url('workorders/view/'.$g->id) ?>" style="color:var(--blue);text-decoration:none;"><?= htmlspecialchars((string)($g->wo_number)) ?></a>
                <?php endif; ?>
                <?php if ($be_root !== null && (int)$g->id === $be_root): ?>
                  <span class="badge b-secondary" style="font-size:9.5px;margin-left:6px;">event start</span>
                <?php endif; ?>
              </div>
              <div style="font-size:10.5px;color:var(--t3);"><?= htmlspecialchars((string)($g->title)) ?> · <?= htmlspecialchars((string)($g->status)) ?></div>
            </div>
            <div class="mono" style="font-size:10px;color:var(--t4);">
              <?= $g->start_datetime ? date('d M y H:i', strtotime($g->start_datetime)) : '' ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php $can_link = isset($role_id) && in_array((int)$role_id, array(1,2), TRUE); ?>
        <?php if ($can_link): ?>
        <?= form_open('workorders/link_breakdown_event/'.$wo->id, array('class' => 'flex ai-c gap-10', 'style' => 'flex-wrap:wrap;')) ?>
          <label style="font-size:11.5px;color:var(--t3);">Group this work order with:</label>
          <select name="breakdown_event_id" class="form-select" style="width:280px;max-width:100%;min-width:0;font-size:12px;padding:5px 8px;">
            <option value="0">— no other work order (stand alone) —</option>
            <?php foreach ($be_candidates as $cand): ?>
            <option value="<?= (int)$cand->id ?>" <?= ($be_root !== null && (int)$cand->id === $be_root && count($be_group) > 1) ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)($cand->wo_number)) ?> — <?= htmlspecialchars((string)($cand->title)) ?>
              (<?= htmlspecialchars((string)($cand->status)) ?><?= $cand->start_datetime ? ', '.date('d M Y', strtotime($cand->start_datetime)) : '' ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-ghost btn-sm">Apply</button>
          <span style="font-size:10.5px;color:var(--t4);">Only other breakdown work orders on this machine are offered.</span>
        <?= form_close() ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- PM services recorded under this work order -->
    <?php if (!empty($pm_completions)): ?>
    <div class="card mb-3" style="animation-delay:.335s;">
      <div class="ch">
        <div><div class="ct">PM Services Completed</div><div class="cs"><?= count($pm_completions) ?> schedule(s) logged against this work order</div></div>
      </div>
      <table class="tbl" style="margin-top:0;">
        <thead><tr><th>Schedule</th><th>Interval</th><th>Completed</th><th>Meter</th></tr></thead>
        <tbody>
          <?php foreach ($pm_completions as $pmc): ?>
          <tr>
            <td class="fw-500"><?= htmlspecialchars((string)$pmc->schedule_name) ?></td>
            <td style="font-size:12px;color:var(--t3);">
              <?= htmlspecialchars((string)$pmc->interval_type) ?> / <?= (int)$pmc->interval_value ?>
            </td>
            <td class="mono" style="font-size:12px;"><?= $pmc->completed_date ? date('d M Y', strtotime($pmc->completed_date)) : '—' ?></td>
            <td class="mono" style="font-size:12px;"><?= $pmc->completed_hours !== null ? number_format((float)$pmc->completed_hours, 1) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Incident Report & Evidence -->
    <?php if ($wo->type === 'Incident'): ?>
    <div class="card mb-3" style="animation-delay:.34s;border-color:var(--red);">
      <div class="ch">
        <div><div class="ct" style="color:var(--red);">⚠ Incident Report</div><div class="cs">Structured incident details &amp; evidence</div></div>
        <?php if (!empty($incident) && $incident->review_status === 'Submitted'): ?>
        <?= form_open('workorders/review_incident/'.$wo->id, array('style' => 'display:inline;margin:0;', 'onsubmit' => "return confirm('Mark this incident report as reviewed?')")) ?>
          <button type="submit" class="ca" style="border:0;background:none;padding:0;cursor:pointer;">Mark Reviewed</button>
        <?= form_close() ?>
        <?php elseif (!empty($incident) && $incident->review_status === 'Reviewed'): ?>
        <span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;color:var(--green);background:var(--green-d);">✓ Reviewed</span>
        <?php endif; ?>
      </div>
      <div style="padding:16px 22px;">
        <?php if (empty($incident)): ?>
        <div style="color:var(--t3);font-size:13px;">No incident details recorded yet. <a href="<?= base_url('workorders/edit/'.$wo->id) ?>" style="color:var(--blue);">Add them here</a>.</div>
        <?php else: ?>
        <div class="form-grid cols-3" style="margin-bottom:14px;font-size:12px;">
          <div><span style="color:var(--t3);">Date/Time:</span> <strong><?= $incident->incident_datetime ? date('d M Y, H:i', strtotime($incident->incident_datetime)) : '—' ?></strong></div>
          <div><span style="color:var(--t3);">Site / Dept:</span> <strong><?= htmlspecialchars((string)($incident->site ?: '—')) ?><?= $incident->department ? ' / '.htmlspecialchars((string)($incident->department)) : '' ?></strong></div>
          <div><span style="color:var(--t3);">Location:</span> <strong><?= htmlspecialchars((string)($incident->location ?: '—')) ?></strong></div>
        </div>

        <div class="form-grid cols-3" style="margin-bottom:14px;font-size:12px;">
          <div><span style="color:var(--t3);">Involved:</span> <strong><?= htmlspecialchars((string)($incident->involved_party_type ?: '—')) ?></strong><?= $incident->person_name ? ' — '.htmlspecialchars((string)($incident->person_name)) : '' ?><?= $incident->person_function ? ' ('.htmlspecialchars((string)($incident->person_function)).')' : '' ?></div>
          <div><span style="color:var(--t3);">Staff ID:</span> <strong><?= htmlspecialchars((string)($incident->staff_id ?: '—')) ?></strong></div>
          <div><span style="color:var(--t3);">Activity Status:</span>
            <?php if ($incident->activity_status): ?>
            <span style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;
              color:<?= $incident->activity_status==='Uncontrolled'?'var(--red)':($incident->activity_status==='Monitored'?'var(--amber)':'var(--green)') ?>;
              background:<?= $incident->activity_status==='Uncontrolled'?'var(--red-d)':($incident->activity_status==='Monitored'?'var(--amber-d)':'var(--green-d)') ?>;"><?= htmlspecialchars((string)($incident->activity_status)) ?></span>
            <?php else: ?>—<?php endif; ?>
          </div>
        </div>

        <?php if ($incident->injured || $incident->equipment_damaged): ?>
        <div style="margin-bottom:10px;padding:8px 12px;background:var(--red-d);border-radius:6px;font-size:12px;color:var(--red);font-weight:600;">
          <?php if ($incident->injured): ?>⚠ Person Injured&nbsp;&nbsp;<?php endif; ?>
          <?php if ($incident->equipment_damaged): ?>⚠ Equipment Damaged<?= $incident->part_affected ? ' — '.htmlspecialchars((string)($incident->part_affected)) : '' ?><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($incident->fleet_no): ?>
        <div style="margin-bottom:10px;font-size:12px;"><span style="color:var(--t3);">Fleet No:</span> <strong class="mono"><?= htmlspecialchars((string)($incident->fleet_no)) ?></strong></div>
        <?php endif; ?>

        <?php if ($incident->description): ?>
        <div style="margin-bottom:10px;"><span style="font-size:11px;color:var(--t3);font-weight:600;text-transform:uppercase;">Description of Incident</span><div style="font-size:13px;color:var(--t2);margin-top:3px;white-space:pre-wrap;"><?= htmlspecialchars((string)($incident->description)) ?></div></div>
        <?php endif; ?>
        <?php if ($incident->injury_damage_details): ?>
        <div style="margin-bottom:10px;"><span style="font-size:11px;color:var(--t3);font-weight:600;text-transform:uppercase;">Details of Injury / Damage / Impact</span><div style="font-size:13px;color:var(--t2);margin-top:3px;white-space:pre-wrap;"><?= htmlspecialchars((string)($incident->injury_damage_details)) ?></div></div>
        <?php endif; ?>
        <?php if ($incident->actions_taken): ?>
        <div style="margin-bottom:4px;"><span style="font-size:11px;color:var(--t3);font-weight:600;text-transform:uppercase;">Actions Taken by Line Management</span><div style="font-size:13px;color:var(--t2);line-height:1.6;margin-top:3px;white-space:pre-wrap;"><?= htmlspecialchars((string)($incident->actions_taken)) ?></div></div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Evidence gallery -->
        <div style="margin-top:16px;border-top:1px solid var(--sep);padding-top:14px;">
          <span style="font-size:11px;color:var(--t3);font-weight:600;text-transform:uppercase;">Evidence (<?= count($attachments) ?>)</span>
          <?php if (!isset($attachments_ready) || !$attachments_ready): ?>
          <div style="color:#b45309;font-size:12px;margin-top:6px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;">
            Evidence attachments are not available yet on this installation: the uploads table has not been created.
            Import the database dump shipped with this repository to enable this panel.
          </div>
          <?php elseif (empty($attachments)): ?>
          <div style="color:var(--t3);font-size:12px;margin-top:6px;">No photos or documents uploaded yet.</div>
          <?php else: ?>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
            <?php foreach ($attachments as $att):
              $ext = strtolower((string)(pathinfo($att->file_name, PATHINFO_EXTENSION)));
              $isImg = in_array($ext, array('jpg','jpeg','png','gif'));
            ?>
            <div style="text-align:center;">
              <?php if ($isImg): ?>
              <a href="<?= base_url('uploads/wo_attachments/'.$att->file_name) ?>" target="_blank">
                <img src="<?= base_url('uploads/wo_attachments/'.$att->file_name) ?>" style="width:84px;height:84px;object-fit:cover;border-radius:8px;border:1px solid var(--sep);">
              </a>
              <?php else: ?>
              <a href="<?= base_url('workorders/download_attachment/'.$att->id) ?>" style="display:flex;align-items:center;justify-content:center;width:84px;height:84px;border-radius:8px;border:1px solid var(--sep);background:var(--g1);color:var(--t3);font-size:24px;text-decoration:none;">📄</a>
              <?php endif; ?>
              <div style="font-size:9px;color:var(--t4);margin-top:3px;width:84px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars((string)($att->original_name)) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?= form_open_multipart('workorders/upload_evidence/'.$wo->id) ?>
          <div class="flex ai-c gap-10" style="margin-top:12px;">
            <input type="file" name="evidence[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
            <button type="submit" class="btn btn-ghost btn-sm">+ Add Evidence</button>
          </div>
          <?= form_close() ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- Right: Timeline + Status Actions -->
  <div>

    <!-- Key Dates Card -->
    <div class="card mb-3" style="animation-delay:.36s;">
      <div class="ch"><div><div class="ct">Dates & Times</div></div></div>
      <div style="padding:14px 22px;">
        <?php
        $fields = [
          ['Created',  $wo->created_at],
          ['Started',  $wo->start_datetime],
          ['Completed',$wo->end_datetime],
        ];
        foreach ($fields as $f): if (!$f[1]) continue; ?>
        <div class="flex ai-c jb" style="padding:8px 0;border-bottom:1px solid var(--sep);">
          <span style="font-size:11px;color:var(--t3);font-weight:600;text-transform:uppercase;letter-spacing:.5px;"><?= $f[0] ?></span>
          <span class="mono" style="font-size:11.5px;"><?= date('d M Y · H:i', strtotime($f[1])) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Update Status -->
    <?php if ($mayUpdateStatus && count($next_statuses) > 0): ?>
    <div class="card mb-3" style="animation-delay:.40s;">
      <div class="ch"><div><div class="ct">Update Status</div><div class="cs">Advance work order</div></div></div>
      <div style="padding:16px 22px;">
        <?= form_open('workorders/update_status/'.$wo->id) ?>
        <?php
        /*
         * Two-stage closure (Wave 3, Q5): anybody can mark the work Completed —
         * that is the technician's statement that it is done. Only a supervisor
         * sees "Closed", because that is the verification of the repair.
         */
        $is_supervisor = (isset($role_id) && (int)$role_id === 1);
        $offered = array_values(array_filter($next_statuses, function ($s) use ($is_supervisor) {
            return $is_supervisor || $s !== 'Closed';
        }));
        ?>
        <div style="margin-bottom:12px;">
          <label class="form-label" for="wo-next-status">New Status</label>
          <select id="wo-next-status" name="status" class="form-select">
            <?php foreach ($offered as $ns): ?>
            <option value="<?= $ns ?>"><?= $ns ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$is_supervisor): ?>
          <div style="font-size:11px;color:var(--t3);margin-top:6px;">
            A supervisor verifies the repair and closes the work order.
          </div>
          <?php endif; ?>
        </div>
        <div style="margin-bottom:12px;">
          <label class="form-label" for="wo-hold-code">Hold Code <span style="font-weight:400;color:var(--t2);">(why the work waits — cleared when finished)</span></label>
          <select id="wo-hold-code" name="hold_code" class="form-select">
            <option value="">— none —</option>
            <?php foreach (wo_hold_codes() as $code => $label): ?>
            <option value="<?= $code ?>" <?= (isset($wo->hold_code) && (string)$wo->hold_code === $code) ? 'selected' : '' ?>>
              <?= $code ?> — <?= htmlspecialchars((string)($label)) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($is_supervisor && $wo->status === 'Completed' && !empty($linked_backlogs)): ?>
        <div style="margin-bottom:12px;">
          <label class="form-label" for="wo-work-done">Work completed</label>
          <textarea id="wo-work-done" name="action_taken" class="form-control" rows="2" aria-describedby="wo-work-done-hint"><?= htmlspecialchars((string)(isset($wo->action_taken) ? $wo->action_taken : ''), ENT_QUOTES, 'UTF-8') ?></textarea>
          <div id="wo-work-done-hint" class="cs" style="color:#475569;">Required if you select defects to verify.</div>
        </div>
        <fieldset style="border:1px solid var(--sep);border-radius:8px;padding:12px;margin:0 0 12px;">
          <legend style="font-size:12px;font-weight:700;">Defects resolved by this work</legend>
          <p class="cs" style="color:#475569;">Select only defects you have checked. Unselected defects remain open.</p>
          <?php foreach ($linked_backlogs as $linked): ?>
          <label style="display:flex;gap:8px;align-items:flex-start;margin:8px 0;font-size:12px;">
            <input type="checkbox" name="resolved_defect_ids[]" value="<?= (int)$linked->id ?>">
            <span>BL-<?= (int)$linked->id ?> · <?= htmlspecialchars((string)$linked->description, ENT_QUOTES, 'UTF-8') ?></span>
          </label>
          <?php endforeach; ?>
        </fieldset>
        <?php endif; ?>
        <?php if ($is_supervisor && $wo->status === 'Completed'): ?>
        <div style="margin-bottom:12px;">
          <label class="form-label" for="wo-verification-notes">Verification Notes <span style="font-weight:400;color:var(--t2);">(recorded when you close the work order)</span></label>
          <input type="text" id="wo-verification-notes" name="verification_notes" class="form-control" maxlength="300" required aria-describedby="wo-verification-hint" placeholder="What you personally checked on the machine">
          <div id="wo-verification-hint" class="cs" style="color:#475569;">Required when closing; this is recorded as your verification evidence.</div>
        </div>
        <?php endif; ?>
        <div style="margin-bottom:12px;">
          <label class="form-label" for="wo-status-notes">Status history note (optional)</label>
          <textarea id="wo-status-notes" name="notes" class="form-control" rows="2" placeholder="Optional note about the status change"></textarea>
        </div>
        <button type="submit" class="btn btn-primary w-full"><?= $wo->status === 'Completed' && $is_supervisor ? 'Verify &amp; close' : 'Update status' ?></button>
        <?= form_close() ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Status History -->
    <?php if (isset($history) && count($history) > 0): ?>
    <div class="card" style="animation-delay:.44s;">
      <div class="ch"><div><div class="ct">Status History</div><div class="cs">Activity log</div></div></div>
      <div>
        <?php foreach ($history as $h): ?>
        <div class="row-list-item" style="cursor:default;">
          <div style="width:7px;height:7px;border-radius:50%;background:var(--blue);flex-shrink:0;margin-top:3px;"></div>
          <div style="flex:1;">
            <div style="font-size:12px;font-weight:600;color:var(--t1);"><?= htmlspecialchars((string)((isset($h->status_to) ? $h->status_to : (isset($h->new_status) ? $h->new_status : '')))) ?></div>
            <div style="font-size:10.5px;color:var(--t3);"><?= htmlspecialchars((string)((isset($h->notes) ? $h->notes : ''))) ?></div>
          </div>
          <div class="mono" style="font-size:10px;color:var(--t4);"><?= (isset($h->changed_at) && $h->changed_at) ? date('d M y', strtotime($h->changed_at)) : (isset($h->created_at) && $h->created_at ? date('d M y', strtotime($h->created_at)) : ''); ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
