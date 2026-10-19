<?php
$status_colors = ['Open'=>'b-open','In Progress'=>'b-progress','Waiting Parts'=>'b-waiting','Completed'=>'b-completed','Closed'=>'b-closed'];
$prio_colors   = ['Critical'=>'b-critical','High'=>'b-high','Medium'=>'b-medium','Low'=>'b-low'];
// W0.1: the breakdown badge follows the is_breakdown flag (see application/helpers/wo_helper.php)
$type_colors   = ['Preventive Maintenance'=>'b-primary','Incident'=>'b-warning'];

$sc = (isset($status_colors[$wo->status]) ? $status_colors[$wo->status] : 'b-secondary');
$pc = (isset($prio_colors[$wo->priority]) ? $prio_colors[$wo->priority] : 'b-secondary');
$tc = (wo_is_breakdown($wo) ? 'b-danger' : (isset($type_colors[$wo->type]) ? $type_colors[$wo->type] : 'b-secondary'));

$status_next = [
  'Open'          => ['In Progress','Waiting Parts','Closed'],
  'In Progress'   => ['Waiting Parts','Completed','Closed'],
  'Waiting Parts' => ['In Progress','Completed','Closed'],
  'Completed'     => ['Closed'],
  'Closed'        => [],
];
$next_statuses = (isset($status_next[$wo->status]) ? $status_next[$wo->status] : []);
?>

<!-- WO Header -->
<div class="flex ai-c jb mb-4" style="flex-wrap:wrap;gap:12px;">
  <div>
    <div class="flex ai-c gap-10" style="flex-wrap:wrap;align-items:center;margin-bottom:6px;">
      <div class="mono" style="font-size:19px;font-weight:600;letter-spacing:-0.5px;color:var(--blue);"><?= $wo->wo_number ?></div>
      <span class="badge <?= $sc ?>"><?= $wo->status ?></span>
      <span class="badge <?= $pc ?>"><?= $wo->priority ?></span>
      <span class="badge <?= $tc ?>"><?= $wo->type ?></span>
    </div>
    <div style="font-size:14px;font-weight:500;color:var(--t1);"><?= htmlspecialchars($wo->title) ?></div>
  </div>
  <div class="flex ai-c gap-10">
    <!-- PRINT JOB CARD BUTTON — primary action -->
    <a href="<?= base_url('workorders/jobcard/'.$wo->id) ?>" target="_blank" class="btn btn-primary btn-icon" style="background:linear-gradient(135deg,#1a6fff,#0a50cc);box-shadow:0 4px 20px rgba(0,113,227,0.35);">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print Job Card
    </a>
    <a href="<?= base_url('workorders/edit/'.$wo->id) ?>" class="btn btn-ghost btn-icon">
      <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Edit
    </a>
    <a href="<?= base_url('workorders') ?>" class="btn btn-ghost">← Back</a>
  </div>
</div>

<!-- Details Grid -->
<div class="grid g-2-1 mb-3">

  <!-- Left: WO Details + Description -->
  <div class="col-2">

    <!-- Info Cards Strip -->
    <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px;">
      <div class="kpi" style="animation-delay:.05s;">
        <div class="kpi-glow" style="background:var(--blue);"></div>
        <span class="kpi-tag">Equipment</span>
        <div style="font-size:15px;font-weight:700;color:var(--t1);margin-top:4px;"><?= (isset($wo->registration_no) ? $wo->registration_no : '—') ?></div>
        <div style="font-size:10px;color:var(--t3);margin-top:2px;"><?= htmlspecialchars((isset($wo->brand) ? $wo->brand : '') . ' ' . (isset($wo->model) ? $wo->model : '')) ?></div>
      </div>
      <div class="kpi" style="animation-delay:.09s;">
        <div class="kpi-glow" style="background:var(--amber);"></div>
        <span class="kpi-tag">SMR / Hours</span>
        <div class="kpi-num" style="font-size:18px;margin-top:4px;"><?= (isset($wo->hours_reading) ? $wo->hours_reading : (isset($wo->hour_meter_reading) ? $wo->hour_meter_reading : '—')) ?><sup style="font-size:11px;">hrs</sup></div>
      </div>
      <div class="kpi" style="animation-delay:.13s;">
        <div class="kpi-glow" style="background:var(--purple);"></div>
        <span class="kpi-tag">Assigned To</span>
        <div style="font-size:13px;font-weight:600;color:var(--t1);margin-top:4px;"><?= (isset($wo->assigned_name) ? $wo->assigned_name : '—') ?></div>
      </div>
      <div class="kpi" style="animation-delay:.17s;">
        <div class="kpi-glow" style="background:var(--red);"></div>
        <span class="kpi-tag">Downtime</span>
        <div class="kpi-num" style="font-size:18px;margin-top:4px;">
          <?php
          if ($wo->start_datetime && $wo->end_datetime) {
            $diff = (strtotime($wo->end_datetime) - strtotime($wo->start_datetime)) / 3600;
            echo number_format($diff,1);
          } else { echo (isset($wo->downtime_hours) ? $wo->downtime_hours : '—'); }
          ?><sup style="font-size:11px;">hrs</sup>
        </div>
      </div>
    </div>

    <!-- Work Sessions (multi-stint time tracking) -->
    <div class="card mb-3" style="animation-delay:.18s;">
      <div class="ch">
        <div><div class="ct">Work Sessions</div><div class="cs"><?= count($sessions) ?> session(s) &middot; <?= number_format($session_hours,2) ?> hrs logged</div></div>
        <?php if (!$open_session): ?>
        <span class="ca" onclick="document.getElementById('start-session-form').style.display='block';this.style.display='none';">+ Start Session</span>
        <?php endif; ?>
      </div>
      <div style="padding:14px 22px;">

        <?php if ($open_session): ?>
        <!-- Active session banner -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--green-fill);border:1px solid rgba(28,140,60,0.18);border-radius:10px;margin-bottom:14px;">
          <div>
            <div style="font-size:12px;font-weight:600;color:var(--green);">● Session <?= (int)$open_session->session_no ?> in progress</div>
            <div style="font-size:10.5px;color:var(--t3);margin-top:2px;"><?= htmlspecialchars($open_session->technician_name ?: 'Unassigned') ?> &middot; started <?= date('d M, H:i', strtotime($open_session->start_datetime)) ?></div>
          </div>
        </div>
        <?= form_open('workorders/close_session/'.$open_session->id.'/'.$wo->id) ?>
        <div style="margin-bottom:10px;">
          <label class="form-label">What was done in this session?</label>
          <textarea name="work_performed" class="form-control" rows="2" placeholder="Brief note on work performed this stint..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-sm w-full">Stop &amp; Log Hours</button>
        <?= form_close() ?>

        <?php else: ?>
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
                <option value="<?= $t->id ?>" <?= $t->id==$default_id?'selected':'' ?>><?= htmlspecialchars($t->first_name.' '.$t->last_name) ?></option>
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
              <div style="font-size:12px;font-weight:600;color:var(--t1);">Session <?= (int)$s->session_no ?> — <?= htmlspecialchars($s->technician_name ?: 'Unassigned') ?></div>
              <div style="font-size:10.5px;color:var(--t3);">
                <?= date('d M, H:i', strtotime($s->start_datetime)) ?>
                <?= $s->end_datetime ? ' → '.date('d M, H:i', strtotime($s->end_datetime)) : ' (in progress)' ?>
                <?php if (!empty($s->switched_to_wo_id) && !empty($s->switched_to_wo_number)): ?>
                &middot; switched to <a href="<?= base_url('workorders/view/'.$s->switched_to_wo_id) ?>" style="color:var(--blue);font-weight:600;"><?= htmlspecialchars($s->switched_to_wo_number) ?></a>
                <?php elseif ($s->interruption_reason): ?>
                &middot; <?= htmlspecialchars($s->interruption_reason) ?>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($s->status === 'Closed'): ?>
            <div class="mono" style="font-size:11.5px;font-weight:600;color:var(--t1);margin-right:10px;"><?= number_format($s->hours_worked,2) ?>h</div>
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
        <div style="font-size:13px;color:var(--t2);line-height:1.6;"><?= nl2br(htmlspecialchars((isset($wo->symptoms) ? $wo->symptoms : (isset($wo->description) ? $wo->description : '')))) ?></div>
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
        <div style="font-size:13px;color:var(--t2);line-height:1.6;"><?= nl2br(htmlspecialchars($wo->root_cause)) ?></div>
        <?php else: ?>
        <div style="color:var(--t3);font-size:13px;">Not yet recorded.</div>
        <?php endif; ?>
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
    $sp_qty_fmt = function ($n) { return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); };
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
            <td style="white-space:nowrap;"><span class="badge <?= $info[2] ?>" style="font-size:10px;"><?= $info[0] ?> <?= htmlspecialchars($st) ?></span></td>
            <td style="font-weight:500;">
              <?= htmlspecialchars(isset($sp->part_name) ? $sp->part_name : '') ?>
              <?php if ($st === 'From Machine' && !empty($sp->source_machine)): ?>
              <div style="font-size:10px;color:var(--t3);">from <?= htmlspecialchars($sp->source_machine) ?></div>
              <?php endif; ?>
              <?php if ($st === 'Unlinked'): ?>
              <div style="font-size:10px;color:var(--t3);">item code missing / not in inventory</div>
              <?php endif; ?>
            </td>
            <td class="mono" style="font-size:11px;"><?= htmlspecialchars(!empty($sp->item_code) ? $sp->item_code : '—') ?></td>
            <td class="mono" style="font-size:11px;color:var(--t3);"><?= htmlspecialchars(isset($sp->part_number) ? $sp->part_number : '') ?></td>
            <td class="mono" style="font-weight:600;"><?= isset($sp->quantity) ? $sp_qty_fmt($sp->quantity) : '' ?> <span style="color:var(--t4);font-size:10px;"><?= htmlspecialchars(isset($sp->unit) ? $sp->unit : 'pcs') ?></span></td>
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
            <td class="mono" style="font-size:11px;"><?= htmlspecialchars((isset($sp->item_code) ? $sp->item_code : '—')); ?></td>
            <td style="font-weight:500;"><?= htmlspecialchars((isset($sp->part_name) ? $sp->part_name : '')); ?></td>
            <td class="mono" style="font-size:11px;color:var(--t3);"><?= htmlspecialchars((isset($sp->part_number) ? $sp->part_number : '')); ?></td>
            <td><?php
              $stype = isset($sp->spare_type) ? $sp->spare_type : 'New';
              if ($stype === 'From Machine') {
                echo '<span class="badge b-warning" style="font-size:10px;">From Machine</span>';
              } else {
                echo '<span class="badge b-success" style="font-size:10px;">New</span>';
              }
            ?></td>
            <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars(isset($sp->source_machine) ? $sp->source_machine : ''); ?></td>
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
      <table class="tbl" style="margin-top:0;">
        <thead><tr><th>System / Location</th><th>Oil / Fluid Type</th><th>Grade</th><th>Qty (L)</th></tr></thead>
        <tbody>
          <?php foreach ($oil_records as $oil): ?>
          <tr>
            <td style="font-weight:500;"><?= htmlspecialchars(isset($oil->system_location) ? $oil->system_location : ''); ?></td>
            <td><?= htmlspecialchars(isset($oil->oil_type) ? $oil->oil_type : ''); ?></td>
            <td style="font-size:12px;color:var(--t3);"><?= htmlspecialchars(isset($oil->grade) ? $oil->grade : ''); ?></td>
            <td class="mono fw-600"><?= isset($oil->quantity) && $oil->quantity ? number_format($oil->quantity,1) : '—'; ?> <span style="color:var(--t4);font-size:10px;">L</span></td>
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
          <div><span style="color:var(--t3);">Site / Dept:</span> <strong><?= htmlspecialchars($incident->site ?: '—') ?><?= $incident->department ? ' / '.htmlspecialchars($incident->department) : '' ?></strong></div>
          <div><span style="color:var(--t3);">Location:</span> <strong><?= htmlspecialchars($incident->location ?: '—') ?></strong></div>
        </div>

        <div class="form-grid cols-3" style="margin-bottom:14px;font-size:12px;">
          <div><span style="color:var(--t3);">Involved:</span> <strong><?= htmlspecialchars($incident->involved_party_type ?: '—') ?></strong><?= $incident->person_name ? ' — '.htmlspecialchars($incident->person_name) : '' ?><?= $incident->person_function ? ' ('.htmlspecialchars($incident->person_function).')' : '' ?></div>
          <div><span style="color:var(--t3);">Staff ID:</span> <strong><?= htmlspecialchars($incident->staff_id ?: '—') ?></strong></div>
          <div><span style="color:var(--t3);">Activity Status:</span>
            <?php if ($incident->activity_status): ?>
            <span style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;
              color:<?= $incident->activity_status==='Uncontrolled'?'var(--red)':($incident->activity_status==='Monitored'?'var(--amber)':'var(--green)') ?>;
              background:<?= $incident->activity_status==='Uncontrolled'?'var(--red-d)':($incident->activity_status==='Monitored'?'var(--amber-d)':'var(--green-d)') ?>;"><?= htmlspecialchars($incident->activity_status) ?></span>
            <?php else: ?>—<?php endif; ?>
          </div>
        </div>

        <?php if ($incident->injured || $incident->equipment_damaged): ?>
        <div style="margin-bottom:10px;padding:8px 12px;background:var(--red-d);border-radius:6px;font-size:12px;color:var(--red);font-weight:600;">
          <?php if ($incident->injured): ?>⚠ Person Injured&nbsp;&nbsp;<?php endif; ?>
          <?php if ($incident->equipment_damaged): ?>⚠ Equipment Damaged<?= $incident->part_affected ? ' — '.htmlspecialchars($incident->part_affected) : '' ?><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($incident->fleet_no): ?>
        <div style="margin-bottom:10px;font-size:12px;"><span style="color:var(--t3);">Fleet No:</span> <strong class="mono"><?= htmlspecialchars($incident->fleet_no) ?></strong></div>
        <?php endif; ?>

        <?php if ($incident->description): ?>
        <div style="margin-bottom:10px;"><span style="font-size:11px;color:var(--t3);font-weight:600;text-transform:uppercase;">Description of Incident</span><div style="font-size:13px;color:var(--t2);margin-top:3px;white-space:pre-wrap;"><?= htmlspecialchars($incident->description) ?></div></div>
        <?php endif; ?>
        <?php if ($incident->injury_damage_details): ?>
        <div style="margin-bottom:10px;"><span style="font-size:11px;color:var(--t3);font-weight:600;text-transform:uppercase;">Details of Injury / Damage / Impact</span><div style="font-size:13px;color:var(--t2);margin-top:3px;white-space:pre-wrap;"><?= htmlspecialchars($incident->injury_damage_details) ?></div></div>
        <?php endif; ?>
        <?php if ($incident->actions_taken): ?>
        <div style="margin-bottom:4px;"><span style="font-size:11px;color:var(--t3);font-weight:600;text-transform:uppercase;">Actions Taken by Line Management</span><div style="font-size:13px;color:var(--t2);line-height:1.6;margin-top:3px;white-space:pre-wrap;"><?= htmlspecialchars($incident->actions_taken) ?></div></div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Evidence gallery -->
        <div style="margin-top:16px;border-top:1px solid var(--sep);padding-top:14px;">
          <span style="font-size:11px;color:var(--t3);font-weight:600;text-transform:uppercase;">Evidence (<?= count($attachments) ?>)</span>
          <?php if (!isset($attachments_ready) || !$attachments_ready): ?>
          <div style="color:#b45309;font-size:12px;margin-top:6px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;">
            Evidence attachments are not available yet on this installation: the uploads table has not been created.
            Apply <code>database/migrations/001_create_wo_attachments.sql</code> to enable this panel.
          </div>
          <?php elseif (empty($attachments)): ?>
          <div style="color:var(--t3);font-size:12px;margin-top:6px;">No photos or documents uploaded yet.</div>
          <?php else: ?>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
            <?php foreach ($attachments as $att):
              $ext = strtolower(pathinfo($att->file_name, PATHINFO_EXTENSION));
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
              <div style="font-size:9px;color:var(--t4);margin-top:3px;width:84px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($att->original_name) ?></div>
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
    <?php if (count($next_statuses) > 0): ?>
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
          <label class="form-label">New Status</label>
          <select name="status" class="form-select">
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
        <?php if ($is_supervisor): ?>
        <div style="margin-bottom:12px;">
          <label class="form-label">Verification Notes <span style="font-weight:400;color:var(--t3);">(recorded when you close the work order)</span></label>
          <input type="text" name="verification_notes" class="form-control" placeholder="What you checked on the machine">
        </div>
        <?php endif; ?>
        <div style="margin-bottom:12px;">
          <label class="form-label">Work Completed Notes</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Describe what was done..."></textarea>
        </div>
        <?php if (wo_is_breakdown($wo)): ?>
        <div style="margin-bottom:12px;">
          <label class="form-label">End Date/Time</label>
          <input type="datetime-local" name="end_datetime" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary w-full">Update Status</button>
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
            <div style="font-size:12px;font-weight:600;color:var(--t1);"><?= htmlspecialchars((isset($h->status_to) ? $h->status_to : (isset($h->new_status) ? $h->new_status : ''))) ?></div>
            <div style="font-size:10.5px;color:var(--t3);"><?= htmlspecialchars((isset($h->notes) ? $h->notes : '')) ?></div>
          </div>
          <div class="mono" style="font-size:10px;color:var(--t4);"><?= (isset($h->changed_at) && $h->changed_at) ? date('d M y', strtotime($h->changed_at)) : (isset($h->created_at) && $h->created_at ? date('d M y', strtotime($h->created_at)) : ''); ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
