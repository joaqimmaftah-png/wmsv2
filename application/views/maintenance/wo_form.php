<?php
if (!isset($wo)) $wo = (object)array();
$is_edit = !empty($wo->id);

$selSys = array();
if ($is_edit && !empty($wo->equipment_systems)) {
    $selSys = array_map('trim', explode(',', isset($wo->equipment_systems) ? $wo->equipment_systems : ''));
}

$sysNames = array(
    1=>'Chassis/Carrosserie', 2=>'Engine', 3=>'Transmission, Gear Box',
    4=>'Hydraulic Circuit', 5=>'Air Conditioning/Ansul', 6=>'Steering',
    7=>'Suspension', 8=>'Brakes', 9=>'Pneumatic Circuit',
    10=>'Final Drives, Diff, Axel', 11=>'Electric', 12=>'Lubricant & Greasing',
    13=>'Tyre, Wheel', 14=>'Lighting & Signing', 15=>'Calibration',
);

$existing_spares = isset($spare_parts) && is_array($spare_parts) ? $spare_parts : array();
$existing_oils   = isset($lubes)       && is_array($lubes)       ? $lubes       : array();
$incident        = isset($incident) ? $incident : (object)array();
$existing_evidence = isset($attachments) && is_array($attachments) ? $attachments : array();

// Multi-technician support: assigned_techs is stored as comma-separated user IDs in assigned_to
$assigned_ids = array();
if ($is_edit && !empty($wo->assigned_to)) {
    $assigned_ids = array_map('trim', explode(',', $wo->assigned_to));
}

function _v($is_edit, $wo, $field, $default = '') {
    if (!$is_edit) return $default;
    return isset($wo->$field) ? $wo->$field : $default;
}
?>
<style>
.sec-head {
    font-family:inherit;font-size:11px;font-weight:700;text-transform:uppercase;
    letter-spacing:.8px;color:var(--t3);padding-bottom:7px;border-bottom:1px solid var(--sep);
    margin:28px 0 14px;display:flex;align-items:center;justify-content:space-between;
}
.sec-head:first-child { margin-top:0; }
.jc-table { width:100%;border-collapse:collapse;font-size:12px; }
.jc-table thead th {
    font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase;
    letter-spacing:.35px;padding:6px 8px;border-bottom:1px solid var(--sep);
    text-align:left;background:transparent;
}
.jc-table tbody tr { border-bottom:1px solid rgba(255,255,255,0.035); }
.jc-table tbody tr:last-child { border-bottom:none; }
.jc-table td { padding:4px 5px;vertical-align:middle; }
.jc-table td input, .jc-table td select {
    background:var(--g1);border:1px solid transparent;border-radius:7px;
    padding:6px 9px;color:var(--t1);font-family:inherit;font-size:12px;
    width:100%;outline:none;transition:border-color .15s,background .15s;
}
.jc-table td input:focus, .jc-table td select:focus {
    border-color:var(--blue);background:rgba(0,113,227,0.06);
}
.jc-table td select option { background:#ffffff; }
.jc-table td input::placeholder { color:var(--t4); }
.jc-table td input.input-error { border-color:var(--red)!important;background:rgba(192,57,43,0.07)!important; }
.jc-table td input.input-ok    { border-color:var(--green)!important; }

.add-row-btn {
    display:inline-flex;align-items:center;gap:6px;background:transparent;
    border:1px dashed var(--sep);color:var(--t3);padding:5px 14px;
    border-radius:8px;font-size:11.5px;cursor:pointer;font-family:inherit;
    transition:all .15s;
}
.add-row-btn:hover { border-color:var(--blue);color:var(--blue);background:var(--blue-d); }
.rm-btn {
    background:none;border:none;color:var(--t4);cursor:pointer;
    padding:4px 7px;border-radius:5px;font-size:14px;line-height:1;
    transition:color .15s,background .15s;
}
.rm-btn:hover { color:var(--red);background:var(--red-d); }

/* DB lookup status badges */
.lookup-badge {
    display:inline-flex;align-items:center;gap:4px;
    font-size:10px;font-weight:600;padding:2px 7px;border-radius:20px;
    margin-left:4px;
}
.lb-ok   { background:var(--green-d);color:var(--green); }
.lb-err  { background:var(--red-d);color:var(--red); }
.lb-spin { background:var(--g2);color:var(--t3); }

/* Stock indicator */
.stock-badge {
    font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;
    display:inline-block;margin-top:2px;
}
.sb-ok   { background:var(--green-d);color:var(--green); }
.sb-warn { background:var(--amber-d);color:var(--amber); }
.sb-err  { background:var(--red-d);color:var(--red); }

.hint { font-size:10.5px;color:var(--t4);margin-top:4px; }

/* Multi-select technicians */
.tech-multi { display:flex;flex-direction:column;gap:4px;max-height:180px;overflow-y:auto;
    background:var(--g1);border:1px solid var(--sep);border-radius:var(--r1);padding:8px 10px; }
.tech-option { display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--t2);
    padding:4px 6px;border-radius:6px;cursor:pointer;transition:background .12s; }
.tech-option:hover { background:var(--g2);color:var(--t1); }
.tech-option input[type=checkbox] { width:14px;height:14px;accent-color:var(--blue);flex-shrink:0; }
</style>

<div style="max-width:900px;margin:0 auto;">
<div class="card">
  <div class="ch">
    <div>
      <div class="ct"><?= $is_edit ? 'Edit Work Order: '.htmlspecialchars((string)($wo->wo_number)) : 'New Work Order'; ?></div>
      <div class="cs">Spare parts, oil top-up, work completed, pending work and remarks all save here and print on the Job Card</div>
    </div>
    <a href="<?= base_url('workorders'); ?>" class="ca">&larr; Back</a>
  </div>

  <div style="padding:24px 28px 32px;">
    <?= form_open_multipart($is_edit ? 'workorders/edit/'.$wo->id : 'workorders/create', array('id'=>'wo-form')); ?>

    <!-- ══ SECTION 1 — IDENTIFICATION ══ -->
    <div class="sec-head" style="margin-top:0;"><span>Work Order Identification</span></div>
    <div class="form-grid cols-3" style="margin-bottom:4px;">
      <div>
        <label class="form-label">Equipment <span class="req">*</span></label>
        <select name="equipment_id" class="form-select" required <?= $is_edit?'disabled':''; ?>>
          <option value="">Select Equipment…</option>
          <?php foreach ($equipment_list as $e): ?>
          <option value="<?= $e->id; ?>" <?= ($is_edit && $wo->equipment_id==$e->id)?'selected':''; ?>>
            <?= htmlspecialchars((string)($e->registration_no.' — '.$e->brand.' '.$e->model)); ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php if ($is_edit): ?>
        <input type="hidden" name="equipment_id" value="<?= $wo->equipment_id; ?>">
        <?php endif; ?>
      </div>
      <div>
        <label class="form-label">WO Type <span class="req">*</span></label>
        <select name="type" id="wo-type" class="form-select" required>
          <?php foreach (array('Corrective Maintenance','Preventive Maintenance','Incident') as $t): ?>
          <option value="<?= $t; ?>" <?= ($is_edit && $wo->type===$t)?'selected':''; ?>><?= $t; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Priority <span class="req">*</span></label>
        <select name="priority" class="form-select" required>
          <?php foreach (array('Critical','High','Medium','Low') as $p): ?>
          <option value="<?= $p; ?>" <?= ($is_edit && $wo->priority===$p)?'selected':''; ?>><?= $p; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Workplace / Location</label>
        <input type="text" name="workplace" class="form-control" value="<?= htmlspecialchars((string)(_v($is_edit,$wo,'workplace'))); ?>" placeholder="e.g. Pit Area A, Workshop Bay 3">
      </div>
      <div>
        <label class="form-label">Purchase Order / DA</label>
        <input type="text" name="purchase_order" class="form-control" value="<?= htmlspecialchars((string)(_v($is_edit,$wo,'purchase_order'))); ?>" placeholder="PO or approval reference">
      </div>
      <div>
        <label class="form-label">Planner</label>
        <input type="text" name="planner" class="form-control" value="<?= htmlspecialchars((string)(_v($is_edit,$wo,'planner'))); ?>" placeholder="Maintenance planner name">
      </div>
      <div>
        <label class="form-label">Supervisor / Responsible</label>
        <?php
        // Auto-filled from the logged-in user
        $sup_name = '';
        $sup_sig  = '';
        if ($is_edit && !empty($wo->supervisor_id)) {
            // Edit mode: show saved supervisor
            $sup_user = isset($current_user) && $current_user->id == $wo->supervisor_id
                ? $current_user : null;
            if (!$sup_user) {
                // Will be fetched server-side; just show stored name
                $sup_name = 'Supervisor (ID '.$wo->supervisor_id.')';
            } else {
                $sup_name = $sup_user->first_name.' '.$sup_user->last_name;
                $sup_sig  = !empty($sup_user->signature) ? $sup_user->signature : '';
            }
        } elseif (isset($current_user)) {
            $sup_name = $current_user->first_name.' '.$current_user->last_name;
            $sup_sig  = !empty($current_user->signature) ? $current_user->signature : '';
        }
        ?>
        <input type="hidden" name="supervisor_id" value="<?= isset($current_user) ? $current_user->id : '' ?>">
        <div style="display:flex;align-items:center;gap:12px;background:var(--g1);border:1px solid var(--sep);border-radius:var(--r1);padding:9px 13px;">
          <?php if ($sup_sig): ?>
          <img src="<?= base_url(htmlspecialchars((string)($sup_sig))) ?>"
               style="height:32px;max-width:100px;object-fit:contain;background:#fff;border-radius:4px;padding:2px 4px;">
          <?php else: ?>
          <div style="width:36px;height:32px;border-radius:6px;background:linear-gradient(145deg,#1870ff,#0047cc);display:flex;align-items:center;justify-content:center;">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          </div>
          <?php endif; ?>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--t1);"><?= htmlspecialchars((string)($sup_name)) ?></div>
            <div style="font-size:10px;color:var(--t3);margin-top:1px;">Auto-assigned — logged-in user</div>
          </div>
        </div>
      </div>
      <div>
        <label class="form-label">Machine Status</label>
        <select name="machine_status" class="form-select">
          <option value="Operational" <?= ($is_edit && _v($is_edit,$wo,'machine_status')==='Operational')?'selected':'selected'; ?>>Operational</option>
          <option value="Breakdown"   <?= ($is_edit && _v($is_edit,$wo,'machine_status')==='Breakdown')?'selected':''; ?>>Breakdown</option>
        </select>
      </div>
      <div>
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php $curStatus = $is_edit ? $wo->status : 'Open';
          foreach (array('Open','In Progress','Waiting Parts','Waiting Tools','Completed','Closed') as $s): ?>
          <option value="<?= $s; ?>" <?= ($curStatus===$s)?'selected':''; ?>><?= $s; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Hold Code <span style="font-weight:400;color:var(--t4);">(why work waits)</span></label>
        <select name="hold_code" class="form-select">
          <option value="">— none —</option>
          <?php $curHold = $is_edit && isset($wo->hold_code) ? (string)$wo->hold_code : '';
          foreach (wo_hold_codes() as $code => $label): ?>
          <option value="<?= $code; ?>" <?= ($curHold === $code) ? 'selected' : ''; ?>>
            <?= $code; ?> — <?= htmlspecialchars((string)($label)); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php $is_sup_form = isset($role_id) && (int)$role_id === 1; ?>
      <?php if ($is_edit && $is_sup_form): ?>
      <div>
        <label class="form-label">Verification Notes <span style="font-weight:400;color:var(--t4);">(recorded when closing)</span></label>
        <input type="text" name="verification_notes" class="form-control"
               value="<?= htmlspecialchars((string)_v($is_edit,$wo,'verification_notes')); ?>"
               placeholder="What you checked on the machine" maxlength="300">
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ INCIDENT DETAILS (shown only when WO Type = Incident) — mirrors HSE Near-Miss form ══ -->
    <div id="incident-section" style="display:none;margin-bottom:4px;">
      <div class="sec-head" style="color:var(--red);">⚠ Incident Details &amp; Evidence — Workshop Incident Report (Near Miss)</div>

      <div class="form-grid cols-3" style="margin-bottom:14px;">
        <div>
          <label class="form-label">Incident Date/Time</label>
          <input type="datetime-local" name="incident_datetime" class="form-control"
            value="<?= !empty($incident->incident_datetime) ? date('Y-m-d\TH:i', strtotime($incident->incident_datetime)) : '' ?>">
        </div>
        <div>
          <label class="form-label">Site</label>
          <input type="text" name="incident_site" class="form-control" value="<?= htmlspecialchars((string)($incident->site ?? '')) ?>" placeholder="e.g. KALIMVA">
        </div>
        <div>
          <label class="form-label">Department</label>
          <input type="text" name="incident_department" class="form-control" value="<?= htmlspecialchars((string)($incident->department ?? '')) ?>" placeholder="e.g. PRODUCTION">
        </div>
        <div class="col-3">
          <label class="form-label">Location</label>
          <input type="text" name="incident_location" class="form-control" value="<?= htmlspecialchars((string)($incident->location ?? '')) ?>" placeholder="e.g. Kalimva Site, Pit Area B">
        </div>
      </div>

      <div class="sec-head" style="margin-top:18px;">Involved Person</div>
      <div class="form-grid cols-3" style="margin-bottom:14px;">
        <div>
          <label class="form-label">Employee / Contractor / Third Party</label>
          <select name="involved_party_type" class="form-select">
            <option value="">— Select —</option>
            <?php $ipt = $incident->involved_party_type ?? ''; foreach (array('Employee','Contractor','Third Party') as $p): ?>
            <option value="<?= $p ?>" <?= $ipt===$p?'selected':'' ?>><?= $p ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Staff ID</label>
          <input type="text" name="staff_id" class="form-control" value="<?= htmlspecialchars((string)($incident->staff_id ?? '')) ?>">
        </div>
        <div>
          <label class="form-label">Name</label>
          <input type="text" name="person_name" class="form-control" value="<?= htmlspecialchars((string)($incident->person_name ?? '')) ?>">
        </div>
        <div>
          <label class="form-label">Function</label>
          <input type="text" name="person_function" class="form-control" value="<?= htmlspecialchars((string)($incident->person_function ?? '')) ?>" placeholder="e.g. Operator">
        </div>
        <div>
          <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--t2);margin-top:24px;">
            <input type="checkbox" name="injured" value="1" <?= !empty($incident->injured)?'checked':'' ?>>
            Injured
          </label>
        </div>
      </div>

      <div class="sec-head" style="margin-top:18px;">Equipment / Damage</div>
      <div class="form-grid cols-3" style="margin-bottom:14px;">
        <div>
          <label class="form-label">Fleet No.</label>
          <input type="text" name="fleet_no" class="form-control" value="<?= htmlspecialchars((string)($incident->fleet_no ?? '')) ?>" placeholder="e.g. C-HE-DZ 004">
        </div>
        <div>
          <label class="form-label">Part</label>
          <input type="text" name="part_affected" class="form-control" value="<?= htmlspecialchars((string)($incident->part_affected ?? '')) ?>">
        </div>
        <div>
          <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--t2);margin-top:24px;">
            <input type="checkbox" name="equipment_damaged" value="1" <?= !empty($incident->equipment_damaged)?'checked':'' ?>>
            Damaged
          </label>
        </div>
      </div>

      <div class="sec-head" style="margin-top:18px;">Narrative</div>
      <div class="form-grid cols-3" style="margin-bottom:14px;">
        <div class="col-3">
          <label class="form-label">Description of Incident</label>
          <textarea name="incident_description" class="form-control" rows="3" placeholder="What happened, sequence of events…"><?= htmlspecialchars((string)($incident->description ?? '')) ?></textarea>
        </div>
        <div class="col-3">
          <label class="form-label">Details of Injury / Damage / Impact</label>
          <textarea name="injury_damage_details" class="form-control" rows="2"><?= htmlspecialchars((string)($incident->injury_damage_details ?? '')) ?></textarea>
        </div>
        <div class="col-3">
          <label class="form-label">Actions Taken by Line Management Following Incident</label>
          <textarea name="actions_taken" class="form-control" rows="2"><?= htmlspecialchars((string)($incident->actions_taken ?? '')) ?></textarea>
        </div>
        <div>
          <label class="form-label">Activity Status</label>
          <select name="activity_status" class="form-select">
            <option value="">— Select —</option>
            <?php $as = $incident->activity_status ?? ''; foreach (array('Controlled','Monitored','Uncontrolled') as $st): ?>
            <option value="<?= $st ?>" <?= $as===$st?'selected':'' ?>><?= $st ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="sec-head" style="margin-top:18px;">Evidence</div>
      <div class="form-grid cols-3">
        <div class="col-3">
          <label class="form-label">Evidence Photos / Scanned Report (PDF or Word)</label>
          <input type="file" name="evidence[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx" class="form-control">
          <div style="font-size:10.5px;color:var(--t4);margin-top:5px;">Select multiple files at once. Accepted: JPG, PNG, GIF, PDF, Word (.doc/.docx) — e.g. a completed HSE incident report form.</div>
          <?php if (!empty($existing_evidence)): ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
            <?php foreach ($existing_evidence as $ev): ?>
            <div style="font-size:10.5px;background:var(--g1);border:1px solid var(--sep);border-radius:6px;padding:5px 9px;">
              📎 <?= htmlspecialchars((string)($ev->original_name)) ?>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="font-size:10.5px;color:var(--t4);margin-top:4px;">Already uploaded — manage attachments from the Work Order view page.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══ OPERATOR / DRIVER ══ -->
    <div style="margin-bottom:4px;">
      <div class="sec-head">Operator / Driver</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start;">

        <!-- Name (free text) -->
        <div>
          <label class="form-label">Name</label>
          <input type="text" name="operator_name" class="form-control"
            value="<?= htmlspecialchars((string)(_v($is_edit,$wo,'operator_name'))); ?>"
            placeholder="Full name — internal or external operator…">
          <div style="font-size:10.5px;color:var(--t4);margin-top:5px;">
            Anyone can be entered here, including contractors not in the system.
          </div>
        </div>

        <!-- Draw-to-sign -->
        <div>
          <label class="form-label">
            Signature
            <span style="font-size:10px;color:var(--blue);font-weight:500;margin-left:6px;letter-spacing:0;text-transform:none;">
              Draw with mouse or finger
            </span>
          </label>

          <!-- Canvas wrapper -->
          <div id="op-sig-wrap" style="position:relative;background:#fff;border:1px solid var(--sep-strong);border-radius:var(--r1);overflow:hidden;cursor:crosshair;user-select:none;">
            <canvas id="op-sig-canvas" style="display:block;width:100%;height:110px;touch-action:none;"></canvas>
            <!-- Show existing signature in edit mode -->
            <?php if ($is_edit && !empty($wo->operator_signature)): ?>
            <img id="op-sig-existing"
                 src="<?= base_url(htmlspecialchars((string)($wo->operator_signature))) ?>"
                 style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain;pointer-events:none;">
            <?php endif; ?>
            <!-- Placeholder hint -->
            <div id="op-sig-hint" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;opacity:.35;">
              <svg viewBox="0 0 120 40" width="120" height="40" xmlns="http://www.w3.org/2000/svg">
                <path d="M10 30 Q25 5 40 25 Q55 45 70 15 Q85 -5 110 20" fill="none" stroke="#1a2a6c" stroke-width="1.5" stroke-linecap="round"/>
              </svg>
            </div>
          </div>

          <!-- Controls -->
          <div style="display:flex;align-items:center;justify-content:space-between;margin-top:7px;">
            <span style="font-size:10px;color:var(--t4);">Sign above</span>
            <div style="display:flex;gap:7px;">
              <button type="button" onclick="opSigUndo()"
                style="font-size:11px;padding:4px 13px;border-radius:20px;background:var(--g2);color:var(--t2);border:1px solid var(--sep);cursor:pointer;font-family:inherit;">
                ↩ Undo
              </button>
              <button type="button" onclick="opSigClear()"
                style="font-size:11px;padding:4px 13px;border-radius:20px;background:var(--red-d);color:var(--red);border:1px solid rgba(192,57,43,.2);cursor:pointer;font-family:inherit;">
                ✕ Clear
              </button>
            </div>
          </div>

          <!-- Hidden field carries base64 PNG to server -->
          <input type="hidden" name="operator_signature_data" id="op-sig-data" value="">
        </div>
      </div>
    </div>

    <!-- ══ ASSIGNED TECHNICIANS (multi-select) ══ -->
    <div style="margin-bottom:4px;">
      <label class="form-label">Assigned Technicians</label>
      <div class="tech-multi" id="tech-multi">
        <?php foreach ($technicians as $tech):
          $checked = in_array((string)$tech->id, $assigned_ids); ?>
        <label class="tech-option">
          <input type="checkbox" name="assigned_techs[]" value="<?= $tech->id; ?>" <?= $checked?'checked':''; ?>>
          <?= htmlspecialchars((string)($tech->first_name.' '.$tech->last_name)); ?>
          <span style="font-size:10px;color:var(--t4);margin-left:auto;"><?= htmlspecialchars((string)($tech->position??'')); ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="assigned_to" id="assigned_to_csv"
        value="<?= htmlspecialchars((string)(_v($is_edit,$wo,'assigned_to'))); ?>">
      <div class="hint">Select one or more technicians to assign to this work order.</div>
    </div>

    <!-- ══ SECTION 2 — TIME & METER ══ -->
    <div class="sec-head">Time &amp; Meter Reading</div>
    <div class="form-grid cols-3" style="margin-bottom:4px;">
      <div>
        <label class="form-label">Start Date &amp; Time</label>
        <input type="datetime-local" name="start_datetime" class="form-control"
          value="<?= ($is_edit && $wo->start_datetime) ? str_replace(' ','T',$wo->start_datetime) : ''; ?>">
      </div>
      <div>
        <label class="form-label">End Date &amp; Time</label>
        <input type="datetime-local" name="end_datetime" class="form-control"
          value="<?= ($is_edit && $wo->end_datetime) ? str_replace(' ','T',$wo->end_datetime) : ''; ?>">
      </div>
      <div>
        <label class="form-label">Hour Meter / SMR</label>
        <input type="number" name="hours_reading" class="form-control" step="0.1"
          value="<?= htmlspecialchars((string)(_v($is_edit,$wo,'hours_reading'))); ?>" placeholder="e.g. 4250.5">
      </div>
    </div>

    <!-- ══ SECTION 3 — WORK REQUESTED ══ -->
    <div class="sec-head">Description of Intervention / Work Requested</div>
    <div style="margin-bottom:12px;">
      <label class="form-label">Title / Short Description <span class="req">*</span></label>
      <input type="text" name="title" class="form-control" required
        value="<?= htmlspecialchars((string)(_v($is_edit,$wo,'title'))); ?>"
        placeholder="Brief description of the problem or maintenance task…">
    </div>
    <div style="margin-bottom:4px;">
      <label class="form-label">Symptoms / Problem Detail</label>
      <textarea name="description" class="form-control" rows="4"
        placeholder="Describe the fault, symptoms, or scope of work in detail…"><?= htmlspecialchars((string)(_v($is_edit,$wo,'description') ?: _v($is_edit,$wo,'symptoms'))); ?></textarea>
    </div>

    <!-- ══ SECTION 4 — EQUIPMENT SYSTEMS ══ -->
    <div class="sec-head">Equipment Systems Affected</div>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-bottom:4px;">
      <?php foreach ($sysNames as $n => $name):
        $checked = in_array((string)$n, $selSys); ?>
      <label style="display:flex;align-items:flex-start;gap:7px;padding:8px 10px;background:var(--g1);border:1px solid var(--sep);border-radius:9px;cursor:pointer;transition:background .15s,border-color .15s;">
        <input type="checkbox" name="equipment_systems[]" value="<?= $n; ?>"
          <?= $checked?'checked':''; ?>
          style="width:14px;height:14px;accent-color:var(--blue);margin-top:1px;flex-shrink:0;"
          onchange="updateSysCSV()">
        <span style="font-size:10.5px;color:var(--t2);line-height:1.3;">
          <span style="font-weight:700;color:var(--t1);"><?= $n; ?>.</span> <?= $name; ?>
        </span>
      </label>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="equipment_systems_csv" id="eq-sys-csv"
      value="<?= htmlspecialchars((string)(_v($is_edit,$wo,'equipment_systems'))); ?>">

    <!-- ══ SECTION 5 — ROOT CAUSE ══ -->
    <div class="sec-head">
      <span>Root Cause</span>
      <span style="font-size:9px;font-weight:500;color:var(--blue);text-transform:none;letter-spacing:0;">Prints on Job Card</span>
    </div>
    <div style="margin-bottom:4px;">
      <label class="form-label">Root Cause of Failure</label>
      <input type="text" name="root_cause" class="form-control"
        value="<?= htmlspecialchars((string)(_v($is_edit,$wo,'root_cause'))); ?>"
        placeholder="What was the root cause of the fault?">
    </div>

    <!-- ══ SECTION 6 — WORK COMPLETED ══ -->
    <div class="sec-head">
      <span>Work Completed</span>
      <span style="font-size:9px;font-weight:500;color:var(--blue);text-transform:none;letter-spacing:0;">Prints on Job Card</span>
    </div>
    <div style="margin-bottom:4px;">
      <label class="form-label">Work Completed / Actions Taken</label>
      <textarea name="action_taken" class="form-control" rows="5"
        placeholder="Describe all work performed: parts replaced, adjustments made, alignments done, tests performed…"><?= htmlspecialchars((string)(_v($is_edit,$wo,'action_taken'))); ?></textarea>
      <div class="hint">This text fills the WORK COMPLETED section of your printed Job Card.</div>
    </div>

    <!-- ══ SECTION 7 — SPARE PARTS USED ══ -->
    <div class="sec-head">
      <span>Spare Parts Used</span>
      <button type="button" class="add-row-btn" onclick="addSpareRow()">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
        Add Part
      </button>
    </div>
    <div style="overflow-x:auto;margin-bottom:4px;">
      <table class="jc-table">
        <thead>
          <tr>
            <th style="width:11%;">Item Code</th>
            <th style="width:24%;">Designation / Part Name</th>
            <th style="width:13%;">Reference / Part No.</th>
            <th style="width:14%;">Type</th>
            <th style="width:14%;">Source Machine</th>
            <th style="width:8%;">Qty</th>
            <th style="width:8%;">Unit</th>
            <th style="width:8%;"></th>
          </tr>
        </thead>
        <tbody id="spare-body">
<?php if (!empty($existing_spares)):
  foreach ($existing_spares as $sp): ?>
          <tr>
            <td>
              <input type="text" name="spares[item_code][]"
                value="<?= htmlspecialchars((string)($sp->item_code??'')); ?>"
                placeholder="ENG-001" class="spare-code"
                oninput="lookupSpare(this)" autocomplete="off">
            </td>
            <td>
              <input type="text" name="spares[part_name][]"
                value="<?= htmlspecialchars((string)($sp->part_name??'')); ?>"
                placeholder="Auto-filled from DB" class="spare-name" readonly
                style="background:var(--g2);">
              <div class="lookup-status"></div>
            </td>
            <td><input type="text" name="spares[part_number][]"   value="<?= htmlspecialchars((string)($sp->part_number??'')); ?>"   placeholder="Ref number"></td>
            <td>
              <select name="spares[spare_type][]" onchange="toggleSource(this)">
                <option value="New"          <?= (!isset($sp->spare_type)||$sp->spare_type==='New')?'selected':''; ?>>&#x1F7E2; New Spare</option>
                <option value="From Machine" <?= (isset($sp->spare_type)&&$sp->spare_type==='From Machine')?'selected':''; ?>>&#x1F7E1; From Machine</option>
              </select>
            </td>
            <td><input type="text" name="spares[source_machine][]" value="<?= htmlspecialchars((string)($sp->source_machine??'')); ?>"
              placeholder="e.g. TRK-002"
              style="<?= (!isset($sp->spare_type)||$sp->spare_type!=='From Machine')?'opacity:.3;':'' ?>"></td>
            <td><input type="number" name="spares[quantity][]" value="<?= $sp->quantity??''; ?>" step="0.01" min="0"></td>
            <td><input type="text"   name="spares[unit][]"     value="<?= htmlspecialchars((string)($sp->unit??'pcs')); ?>"  placeholder="pcs"></td>
            <td><button type="button" class="rm-btn" onclick="removeRow(this)">&#10005;</button></td>
          </tr>
  <?php endforeach; ?>
<?php else: ?>
          <tr>
            <td><input type="text" name="spares[item_code][]" placeholder="ENG-001" class="spare-code" oninput="lookupSpare(this)" autocomplete="off"></td>
            <td>
              <input type="text" name="spares[part_name][]" placeholder="Auto-filled from DB" class="spare-name" readonly style="background:var(--g2);">
              <div class="lookup-status"></div>
            </td>
            <td><input type="text" name="spares[part_number][]" placeholder="Ref number"></td>
            <td>
              <select name="spares[spare_type][]" onchange="toggleSource(this)">
                <option value="New">&#x1F7E2; New Spare</option>
                <option value="From Machine">&#x1F7E1; From Machine</option>
              </select>
            </td>
            <td><input type="text" name="spares[source_machine][]" placeholder="e.g. TRK-002" style="opacity:.3;"></td>
            <td><input type="number" name="spares[quantity][]" step="0.01" min="0" placeholder="1"></td>
            <td><input type="text"   name="spares[unit][]" value="pcs"></td>
            <td><button type="button" class="rm-btn" onclick="removeRow(this)">&#10005;</button></td>
          </tr>
<?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="hint">Enter the Item Code — Part Name will auto-fill from the materials database. 🟢 New = from stock &nbsp;|&nbsp; 🟡 From Machine = cannibalized</div>

    <!-- ══ SECTION 8 — OIL / COOLANT TOP UP ══ -->
    <div class="sec-head">
      <span>Oil / Coolant Top Up</span>
      <button type="button" class="add-row-btn" onclick="addOilRow()">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
        Add Line
      </button>
    </div>
    <div style="overflow-x:auto;margin-bottom:4px;">
      <table class="jc-table">
        <thead>
          <tr>
            <th style="width:18%;">Item Code</th>
            <th style="width:26%;">Oil / Fluid Type</th>
            <th style="width:22%;">Grade / Specification</th>
            <th style="width:18%;">System / Location</th>
            <th style="width:10%;">Qty (L)</th>
            <th style="width:6%;"></th>
          </tr>
        </thead>
        <tbody id="oil-body">
<?php if (!empty($existing_oils)):
  foreach ($existing_oils as $oil): ?>
          <tr>
            <td>
              <input type="text" name="lubes[item_code][]"
                value="<?= htmlspecialchars((string)(isset($oil->item_code)?$oil->item_code:'')); ?>"
                placeholder="LUB001" class="oil-code"
                oninput="lookupOilCode(this)" autocomplete="off">
              <div class="stock-status"></div>
            </td>
            <td><input type="text" name="lubes[oil_type][]" value="<?= htmlspecialchars((string)($oil->oil_type??'')); ?>" placeholder="Auto-filled from DB" class="oil-type" readonly style="background:var(--g2);"></td>
            <td><input type="text" name="lubes[grade][]"    value="<?= htmlspecialchars((string)($oil->grade??'')); ?>"    placeholder="e.g. 15W40, SAE30" class="oil-grade" readonly style="background:var(--g2);"></td>
            <td><input type="text" name="lubes[system][]"   value="<?= htmlspecialchars((string)($oil->system_location??'')); ?>" placeholder="e.g. Engine"></td>
            <td><input type="number" name="lubes[quantity][]" value="<?= $oil->quantity??''; ?>" step="0.1" min="0" class="oil-qty" oninput="checkOilStock(this)"></td>
            <td><button type="button" class="rm-btn" onclick="removeRow(this)">&#10005;</button></td>
          </tr>
  <?php endforeach; ?>
<?php else: ?>
          <tr>
            <td>
              <input type="text" name="lubes[item_code][]" placeholder="LUB001" class="oil-code" oninput="lookupOilCode(this)" autocomplete="off">
              <div class="stock-status"></div>
            </td>
            <td><input type="text" name="lubes[oil_type][]" placeholder="Auto-filled from DB" class="oil-type" readonly style="background:var(--g2);"></td>
            <td><input type="text" name="lubes[grade][]"    placeholder="e.g. 15W40" class="oil-grade" readonly style="background:var(--g2);"></td>
            <td><input type="text" name="lubes[system][]"   placeholder="e.g. Engine, Hydraulic"></td>
            <td><input type="number" name="lubes[quantity][]" step="0.1" min="0" placeholder="0.0" class="oil-qty" oninput="checkOilStock(this)"></td>
            <td><button type="button" class="rm-btn" onclick="removeRow(this)">&#10005;</button></td>
          </tr>
<?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="hint">Enter the Item Code — Oil type and grade auto-fill. Quantity is validated against available stock.</div>

    <!-- ══ SECTION 9 — PENDING WORK ══ -->
    <div class="sec-head">
      <span>Pending Work</span>
      <span style="font-size:9px;font-weight:500;color:var(--blue);text-transform:none;letter-spacing:0;">Prints on Job Card</span>
    </div>
    <div style="margin-bottom:4px;">
      <textarea name="pending_work" class="form-control" rows="3"
        placeholder="Describe work that was deferred and must be carried out in a future intervention…"><?= htmlspecialchars((string)(_v($is_edit,$wo,'pending_work'))); ?></textarea>
    </div>

    <!-- ══ SECTION 10 — REMARKS ══ -->
    <div class="sec-head">
      <span>Remarks / Observations</span>
      <span style="font-size:9px;font-weight:500;color:var(--blue);text-transform:none;letter-spacing:0;">Prints on Job Card</span>
    </div>
    <div style="margin-bottom:28px;">
      <textarea name="remarks" class="form-control" rows="4"
        placeholder="Safety observations, machine condition after repair, recommendations…"><?= htmlspecialchars((string)(_v($is_edit,$wo,'remarks'))); ?></textarea>
    </div>

    <!-- ══ ACTIONS ══ -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding-top:16px;border-top:1px solid var(--sep);">
      <div>
        <?php if ($is_edit): ?>
        <a href="<?= base_url('workorders/jobcard/'.$wo->id); ?>" target="_blank" class="btn btn-ghost btn-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          Print Job Card
        </a>
        <?php endif; ?>
      </div>
      <div style="display:flex;align-items:center;gap:10px;">
        <a href="<?= base_url('workorders'); ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary" id="submit-btn">
          <?= $is_edit ? '&#10003; Update Work Order' : '+ Create Work Order'; ?>
        </button>
        <?php if (!$is_edit): ?>
        <button type="submit" name="save_and_print" value="1" class="btn" style="background:var(--green);color:var(--t1);">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          Save &amp; Print Job Card
        </button>
        <?php endif; ?>
      </div>
    </div>

    <?= form_close(); ?>
  </div>
</div>
</div>

<script>
var BASE_URL = '<?= base_url() ?>';

/* ── Toggle Incident Details section based on WO Type ── */
function toggleIncidentSection() {
  var typeSel = document.getElementById('wo-type');
  var sec = document.getElementById('incident-section');
  if (!typeSel || !sec) return;
  sec.style.display = (typeSel.value === 'Incident') ? '' : 'none';
}
document.getElementById('wo-type').addEventListener('change', toggleIncidentSection);
toggleIncidentSection();

/* ── Tech CSV sync ── */
function updateTechCSV() {
  var ids = [];
  document.querySelectorAll('#tech-multi input:checked').forEach(function(cb){ ids.push(cb.value); });
  document.getElementById('assigned_to_csv').value = ids.join(',');
}
document.querySelectorAll('#tech-multi input').forEach(function(cb){
  cb.addEventListener('change', updateTechCSV);
});
updateTechCSV();

/* ── Equipment systems CSV ── */
function updateSysCSV() {
  var vals = [];
  document.querySelectorAll('input[name="equipment_systems[]"]:checked').forEach(function(cb){ vals.push(cb.value); });
  document.getElementById('eq-sys-csv').value = vals.join(',');
}
updateSysCSV();

/* ── Toggle source machine ── */
function toggleSource(sel) {
  var row = sel.closest('tr');
  var src = row.querySelector('input[name="spares[source_machine][]"]');
  if (src) src.style.opacity = (sel.value === 'From Machine') ? '1' : '0.3';
}

/* ── Remove row ── */
function removeRow(btn) {
  var tbody = btn.closest('tbody');
  var row   = btn.closest('tr');
  if (tbody.rows.length > 1) {
    tbody.removeChild(row);
  } else {
    row.querySelectorAll('input').forEach(function(i){ i.value = ''; });
    var s = row.querySelector('select');
    if (s) s.selectedIndex = 0;
  }
}

/* ══════════════════════════════════════
   SPARE PART LOOKUP
══════════════════════════════════════ */
var spareTimers = {};
function lookupSpare(codeInput) {
  var row      = codeInput.closest('tr');
  var nameInp  = row.querySelector('.spare-name');
  var partNoInp= row.querySelector('input[name="spares[part_number][]"]');
  var unitInp  = row.querySelector('input[name="spares[unit][]"]');
  var statusDiv= row.querySelector('.lookup-status');
  var code     = codeInput.value.trim();

  if (!code) {
    nameInp.value = '';
    nameInp.style.background = 'var(--g2)';
    nameInp.classList.remove('input-ok','input-error');
    statusDiv.innerHTML = '';
    return;
  }

  // Debounce 500ms
  clearTimeout(spareTimers[code]);
  statusDiv.innerHTML = '<span class="lookup-badge lb-spin">Checking…</span>';

  spareTimers[code] = setTimeout(function(){
    fetch(BASE_URL + 'workorders/api_spare_lookup?code=' + encodeURIComponent(code))
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.found) {
          nameInp.value = d.part_name;
          if (partNoInp && !partNoInp.value) partNoInp.value = d.part_number || '';
          if (unitInp   && unitInp.value === 'pcs') unitInp.value = d.unit || 'pcs';
          nameInp.classList.add('input-ok'); nameInp.classList.remove('input-error');
          statusDiv.innerHTML = '<span class="lookup-badge lb-ok">&#10003; Found — Stock: ' + d.stock + '</span>';
        } else {
          nameInp.value = '';
          nameInp.classList.add('input-error'); nameInp.classList.remove('input-ok');
          statusDiv.innerHTML = '<span class="lookup-badge lb-err">&#9888; ' + (d.message || 'Not found') + '</span>';
        }
      })
      .catch(function(){ statusDiv.innerHTML = ''; });
  }, 500);
}

/* ══════════════════════════════════════
   OIL / MATERIAL LOOKUP + STOCK CHECK
══════════════════════════════════════ */
var oilTimers = {};
function lookupOilCode(codeInput) {
  var row       = codeInput.closest('tr');
  var typeInp   = row.querySelector('.oil-type');
  var gradeInp  = row.querySelector('.oil-grade');
  var qtyInp    = row.querySelector('.oil-qty');
  var statusDiv = row.querySelector('.stock-status');
  var code      = codeInput.value.trim();

  if (!code) {
    if (typeInp) typeInp.value = '';
    if (gradeInp) gradeInp.value = '';
    if (statusDiv) statusDiv.innerHTML = '';
    return;
  }

  clearTimeout(oilTimers[code]);
  statusDiv.innerHTML = '<span class="stock-badge" style="background:var(--g2);color:var(--t3);">Checking…</span>';

  oilTimers[code] = setTimeout(function(){
    fetch(BASE_URL + 'workorders/api_spare_lookup?code=' + encodeURIComponent(code))
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.found) {
          if (typeInp)  typeInp.value  = d.part_name;
          if (gradeInp) gradeInp.value = d.part_number || '';
          row.dataset.stock = d.stock;
          statusDiv.innerHTML = '<span class="stock-badge sb-ok">Stock: ' + d.stock + ' ' + (d.unit||'L') + '</span>';
          // Re-validate qty if already filled
          if (qtyInp && qtyInp.value) checkOilStock(qtyInp);
        } else {
          if (typeInp)  typeInp.value  = '';
          if (gradeInp) gradeInp.value = '';
          statusDiv.innerHTML = '<span class="stock-badge sb-err">&#9888; Not found in database</span>';
        }
      })
      .catch(function(){ statusDiv.innerHTML = ''; });
  }, 500);
}

function checkOilStock(qtyInput) {
  var row      = qtyInput.closest('tr');
  var stock    = parseFloat(row.dataset.stock);
  var qty      = parseFloat(qtyInput.value);
  var statusDiv= row.querySelector('.stock-status');

  if (!row.dataset.stock || isNaN(qty) || qty <= 0) return;

  if (qty > stock) {
    qtyInput.classList.add('input-error');
    qtyInput.setCustomValidity('Requested quantity (' + qty + ') exceeds available stock (' + stock + ').');
    if (statusDiv) statusDiv.innerHTML = '<span class="stock-badge sb-err">&#9888; Exceeds stock (' + stock + ' available)</span>';
  } else {
    qtyInput.classList.remove('input-error');
    qtyInput.setCustomValidity('');
    if (statusDiv) statusDiv.innerHTML = '<span class="stock-badge sb-ok">&#10003; OK — ' + (stock - qty) + ' will remain</span>';
  }
}

/* ── Add spare row ── */
function addSpareRow() {
  var tbody = document.getElementById('spare-body');
  var tr = document.createElement('tr');
  tr.innerHTML =
    '<td>' +
      '<input type="text" name="spares[item_code][]" placeholder="ENG-001" class="spare-code" autocomplete="off">' +
    '</td>' +
    '<td>' +
      '<input type="text" name="spares[part_name][]" placeholder="Auto-filled from DB" class="spare-name" readonly style="background:var(--g2);">' +
      '<div class="lookup-status"></div>' +
    '</td>' +
    '<td><input type="text" name="spares[part_number][]" placeholder="Ref number"></td>' +
    '<td><select name="spares[spare_type][]" onchange="toggleSource(this)">' +
      '<option value="New">&#x1F7E2; New Spare</option>' +
      '<option value="From Machine">&#x1F7E1; From Machine</option>' +
    '</select></td>' +
    '<td><input type="text" name="spares[source_machine][]" placeholder="e.g. TRK-002" style="opacity:.3;"></td>' +
    '<td><input type="number" name="spares[quantity][]" step="0.01" min="0" placeholder="1"></td>' +
    '<td><input type="text" name="spares[unit][]" value="pcs"></td>' +
    '<td><button type="button" class="rm-btn" onclick="removeRow(this)">&#10005;</button></td>';
  tbody.appendChild(tr);
  // Attach lookup event
  tr.querySelector('.spare-code').addEventListener('input', function(){ lookupSpare(this); });
  tr.querySelector('.spare-code').focus();
}

/* ── Add oil row ── */
function addOilRow() {
  var tbody = document.getElementById('oil-body');
  var tr = document.createElement('tr');
  tr.innerHTML =
    '<td>' +
      '<input type="text" name="lubes[item_code][]" placeholder="LUB001" class="oil-code" autocomplete="off">' +
      '<div class="stock-status"></div>' +
    '</td>' +
    '<td><input type="text" name="lubes[oil_type][]" placeholder="Auto-filled from DB" class="oil-type" readonly style="background:var(--g2);"></td>' +
    '<td><input type="text" name="lubes[grade][]"    placeholder="e.g. 15W40" class="oil-grade" readonly style="background:var(--g2);"></td>' +
    '<td><input type="text" name="lubes[system][]"   placeholder="e.g. Engine"></td>' +
    '<td><input type="number" name="lubes[quantity][]" step="0.1" min="0" placeholder="0.0" class="oil-qty"></td>' +
    '<td><button type="button" class="rm-btn" onclick="removeRow(this)">&#10005;</button></td>';
  tbody.appendChild(tr);
  var codeInp = tr.querySelector('.oil-code');
  var qtyInp  = tr.querySelector('.oil-qty');
  codeInp.addEventListener('input', function(){ lookupOilCode(this); });
  qtyInp.addEventListener('input',  function(){ checkOilStock(this); });
  codeInp.focus();
}

// Attach events to existing rows on page load
document.querySelectorAll('.spare-code').forEach(function(inp){
  inp.addEventListener('input', function(){ lookupSpare(this); });
});
document.querySelectorAll('.oil-code').forEach(function(inp){
  inp.addEventListener('input', function(){ lookupOilCode(this); });
});
document.querySelectorAll('.oil-qty').forEach(function(inp){
  inp.addEventListener('input', function(){ checkOilStock(this); });
});

/* ── Block submit if stock validation fails ── */
document.getElementById('wo-form').addEventListener('submit', function(e){
  var errors = document.querySelectorAll('.oil-qty.input-error');
  if (errors.length > 0) {
    e.preventDefault();
    alert('Please correct the oil quantity errors before saving. One or more quantities exceed available stock.');
  }
});

/* ══════════════════════════════════════
   DRAW-TO-SIGN CANVAS
══════════════════════════════════════ */
(function() {
  var canvas  = document.getElementById('op-sig-canvas');
  if (!canvas) return;

  var ctx     = canvas.getContext('2d');
  var drawing = false;
  var strokes = [];
  var current = [];
  var hint    = document.getElementById('op-sig-hint');
  var dataInp = document.getElementById('op-sig-data');
  var existImg= document.getElementById('op-sig-existing');

  function setup() {
    var rect = canvas.getBoundingClientRect();
    var dpr  = window.devicePixelRatio || 1;
    canvas.width  = rect.width  * dpr;
    canvas.height = rect.height * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.strokeStyle = '#0a1a5c';
    ctx.lineWidth   = 1.8;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';
    ctx.fillStyle   = '#ffffff';
    ctx.fillRect(0, 0, rect.width, rect.height);
    redraw();
  }

  function pos(e) {
    var r = canvas.getBoundingClientRect();
    var s = e.touches ? e.touches[0] : e;
    return { x: s.clientX - r.left, y: s.clientY - r.top };
  }

  function redraw() {
    var rect = canvas.getBoundingClientRect();
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, rect.width, rect.height);
    strokes.forEach(function(stroke) {
      if (stroke.length < 2) return;
      ctx.beginPath();
      ctx.moveTo(stroke[0].x, stroke[0].y);
      for (var i = 1; i < stroke.length; i++) ctx.lineTo(stroke[i].x, stroke[i].y);
      ctx.stroke();
    });
  }

  function onStart(e) {
    e.preventDefault();
    drawing = true;
    current = [pos(e)];
    if (hint) { hint.style.display = 'none'; }
    if (existImg) { existImg.style.display = 'none'; existImg = null; }
  }

  function onMove(e) {
    e.preventDefault();
    if (!drawing) return;
    var p = pos(e);
    current.push(p);
    if (current.length >= 2) {
      ctx.beginPath();
      ctx.moveTo(current[current.length-2].x, current[current.length-2].y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
    }
  }

  function onEnd(e) {
    if (!drawing) return;
    drawing = false;
    if (current.length) strokes.push(current.slice());
    current = [];
    sync();
  }

  function sync() {
    dataInp.value = strokes.length ? canvas.toDataURL('image/png') : '';
  }

  window.opSigClear = function() {
    strokes = []; current = [];
    redraw();
    dataInp.value = '';
    if (hint) hint.style.display = 'flex';
    var ex = document.getElementById('op-sig-existing');
    if (ex) ex.style.display = 'none';
  };

  window.opSigUndo = function() {
    if (strokes.length) strokes.pop();
    redraw(); sync();
    if (!strokes.length && hint) hint.style.display = 'flex';
  };

  canvas.addEventListener('mousedown',  onStart);
  canvas.addEventListener('mousemove',  onMove);
  canvas.addEventListener('mouseup',    onEnd);
  canvas.addEventListener('mouseleave', onEnd);
  canvas.addEventListener('touchstart', onStart, { passive: false });
  canvas.addEventListener('touchmove',  onMove,  { passive: false });
  canvas.addEventListener('touchend',   onEnd);

  setup();
  window.addEventListener('resize', setup);
})();

</script>