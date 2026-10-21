<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Workorders extends MY_Controller {

    public $Materials_model;
    public $Workorder_model;
    public $Equipment_model;
    public $Team_model;
    public $Lubricant_model;
    public $Spareparts_model;
    public $Attendance_model;
    public $Settings_model;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Workorder_model','Equipment_model','Team_model','Lubricant_model','Materials_model','Spareparts_model','Attendance_model','Settings_model'));
        $this->Spareparts_model->materials = $this->Materials_model;
    }

    /* ─────────────────────────────────────────────────────
       INDEX
    ───────────────────────────────────────────────────── */
    public function index() {
        $filter = array(
            'status'   => $this->input->get('status'),
            'type'     => $this->input->get('type'),
            'priority' => $this->input->get('priority'),
            'search'   => $this->input->get('search'),
        );
        $data['work_orders']    = $this->Workorder_model->get_all($filter);
        $data['equipment_list'] = $this->Equipment_model->get_all_active();
        $data['technicians']    = $this->_assignment_picker();
        $data['attendance_gate']= $this->_attendance_gate();
        $data['filter']         = $filter;
        $data['page_title']     = 'Work Orders';
        $this->render('maintenance/workorders', $data);
    }

    /* ─────────────────────────────────────────────────────
       CREATE
    ───────────────────────────────────────────────────── */
    public function create() {
        if ($this->input->post()) {
            $wo_number = $this->_generate_wo_number();
            $sys_csv   = $this->_get_systems_csv();
            $techs_csv = $this->input->post('assigned_to', TRUE) ?: NULL;

            $problems = $this->_assignment_problems((string)$techs_csv);
            if (!empty($problems)) {
                $this->session->set_flashdata('error',
                    'Only technicians who are checked in on site and free of unfinished work orders can be '
                    .'assigned while the attendance gate is on. ' . implode(' ', $problems));
                redirect('workorders/create');
                return;
            }

            $op_sig = $this->_save_operator_signature();

            $insert = array(
                'wo_number'           => $wo_number,
                'equipment_id'        => (int)$this->input->post('equipment_id', TRUE),
                'type'                => $this->input->post('type',            TRUE),
                'priority'            => $this->input->post('priority',        TRUE),
                'status'              => $this->input->post('status',          TRUE) ?: 'Open',
                'title'               => $this->input->post('title',           TRUE),
                'description'         => $this->input->post('description',     TRUE),
                'root_cause'          => $this->input->post('root_cause',      TRUE) ?: NULL,
                'action_taken'        => $this->input->post('action_taken',    TRUE) ?: NULL,
                'pending_work'        => $this->input->post('pending_work',    TRUE) ?: NULL,
                'remarks'             => $this->input->post('remarks',         TRUE) ?: NULL,
                'assigned_to'         => $techs_csv,
                'supervisor_id'       => $this->user_id,
                'operator_name'       => $this->input->post('operator_name',   TRUE) ?: NULL,
                'operator_signature'  => $op_sig,
                'start_datetime'      => $this->input->post('start_datetime',  TRUE) ?: NULL,
                'end_datetime'        => $this->input->post('end_datetime',    TRUE) ?: NULL,
                'hours_reading'       => $this->input->post('hours_reading',   TRUE) ?: NULL,
                'workplace'           => $this->input->post('workplace',       TRUE) ?: NULL,
                'purchase_order'      => $this->input->post('purchase_order',  TRUE) ?: NULL,
                'machine_status'      => $this->input->post('machine_status',  TRUE) ?: 'Operational',
                'hold_code'           => $this->_valid_hold_code($this->input->post('hold_code', TRUE)),
                'planner'             => $this->input->post('planner',         TRUE) ?: NULL,
                'equipment_systems'   => $sys_csv,
                'created_by'          => $this->user_id,
            );

            $start_ts = $insert['start_datetime'] ? strtotime($insert['start_datetime']) : FALSE;
            $end_ts   = $insert['end_datetime']   ? strtotime($insert['end_datetime'])   : FALSE;
            if ($start_ts !== FALSE && $end_ts !== FALSE && $end_ts >= $start_ts) {
                $insert['downtime_hours'] = round(($end_ts - $start_ts) / 3600, 2);
            }

            /*
             * The form posts without server-side rules, so a required field
             * missing (or an enum value outside the column) used to reach the
             * INSERT, whose failure was ignored: the page flashed success,
             * every child row was written against a non-existent work order,
             * and the user landed on a 404. Validate here and stop before
             * anything is written (K-09).
             */
            $required_ok = $insert['equipment_id'] > 0
                && trim((string)$insert['title']) !== ''
                && in_array($insert['type'], array('Corrective Maintenance', 'Preventive Maintenance', 'Incident'), TRUE)
                && in_array($insert['status'], array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools', 'Completed', 'Closed'), TRUE);
            if (!$required_ok) {
                $this->session->set_flashdata('error',
                    'A work order needs a machine, a type, a status and a title. Nothing was saved.');
                redirect('workorders/create');
                return;
            }

            $wo_id = $this->Workorder_model->create($insert);
            if (!$wo_id) {
                $this->session->set_flashdata('error',
                    'The work order could not be saved. Nothing was written — please check the form and try again.');
                redirect('workorders/create');
                return;
            }

            $this->Workorder_model->save_spare_parts($wo_id, $this->input->post('spares'));
            $this->Workorder_model->save_oil_records($wo_id,  $this->input->post('lubes'));

            // Oil lines are also consumption: post them to the lubricant
            // ledger so the Lubricants screens see them (idempotent).
            $lube = $this->Workorder_model->sync_lube_consumption($wo_id, $this->user_id);

            if ($insert['type'] === 'Incident') {
                $this->_save_incident_details($wo_id);
                $this->_save_evidence_uploads($wo_id);
            }

            // Equipment status automation: a corrective WO takes the machine
            // down (Breakdown); a planned job takes it into maintenance only
            // when the work actually starts. A retired machine is never moved.
            $this->_sync_equipment_status($insert['equipment_id'], $insert['type'], $insert['status'], $wo_id);

            $this->session->set_flashdata('success',
                "Work Order {$wo_number} created." . $this->_lube_sync_note($lube));
            if ($this->input->post('save_and_print')) {
                redirect("workorders/jobcard/{$wo_id}?autoprint=1");
            }
            redirect('workorders');
        }

        $data['equipment_list'] = $this->Equipment_model->get_all_active();
        $data['technicians']    = $this->_assignment_picker();
        $data['attendance_gate']= $this->_attendance_gate();
        $data['spare_parts']    = array();
        $data['lubes']          = array();
        $data['page_title']     = 'Create New Work Order';
        $this->render('maintenance/wo_form', $data);
    }

    /* ─────────────────────────────────────────────────────
       VIEW
    ───────────────────────────────────────────────────── */
    public function view($id) {
        $wo = $this->Workorder_model->get_by_id($id);
        if (!$wo) show_404();

        $data['wo']          = $wo;
        $data['spare_parts'] = $this->Workorder_model->get_spare_parts($id);
        // Pending Spare Parts Tracking — live availability of every requested part
        $avail = $this->Spareparts_model->get_wo_parts_availability($id);
        $data['parts_availability'] = $avail['lines'];
        $data['parts_summary']      = $avail['summary'];
        $data['oil_records'] = $this->Workorder_model->get_oil_records($id);
        $data['lubes']       = $this->Workorder_model->get_oil_records($id);
        // The same oil lines after they were posted to the lubricant ledger.
        $data['lube_consumption'] = $this->Workorder_model->get_lube_consumption($id);
        $data['pm_completions']   = $this->Workorder_model->get_pm_completions($id);
        // Breakdown events (one failure → several work orders): show the group
        // and let a technician attach this work order to another event on the
        // same machine, or detach it.
        if (function_exists('wo_is_breakdown') ? wo_is_breakdown($wo) : !empty($wo->is_breakdown)) {
            $data['breakdown_event'] = $this->Workorder_model->get_breakdown_event_group($id);
            $data['breakdown_candidates'] = $this->Workorder_model->get_breakdown_event_candidates($wo->equipment_id, $id);
        }
        $data['history']     = $this->Workorder_model->get_status_history($id);
        $data['technicians'] = !empty($wo->assigned_to) ? $this->Team_model->get_technicians() : $this->_assignment_picker($wo);
        $data['attendance_gate'] = $this->_attendance_gate();
        $data['sessions']     = $this->Workorder_model->get_sessions($id);
        $data['open_session'] = $this->Workorder_model->get_open_session($id);
        $data['session_hours']= $this->Workorder_model->get_total_session_hours($id);
        if ($wo->type === 'Incident') {
            $data['incident']    = $this->Workorder_model->get_incident($id);
            $data['attachments'] = $this->Workorder_model->get_attachments($id, 'Incident Evidence');
        }
        // F-07: the attachment table is missing on this installation, which is
        // what made this page a 500. Say so on the screen instead of failing.
        $data['attachments_ready'] = $this->Workorder_model->attachments_ready();
        $data['page_title']  = 'Work Order: ' . $wo->wo_number;
        $this->render('maintenance/wo_view', $data);
    }

    /* ─────────────────────────────────────────────────────
       EDIT
    ───────────────────────────────────────────────────── */
    public function edit($id) {
        $wo = $this->Workorder_model->get_by_id($id);
        if (!$wo) show_404();

        if ($this->input->post()) {
            $sys_csv   = $this->_get_systems_csv();
            $techs_csv = $this->input->post('assigned_to', TRUE) ?: NULL;

            $problems = $this->_assignment_problems((string)$techs_csv, (string)$wo->assigned_to);
            if (!empty($problems)) {
                $this->session->set_flashdata('error',
                    'Only technicians who are checked in on site and free of unfinished work orders can be '
                    .'added while the attendance gate is on. ' . implode(' ', $problems));
                redirect("workorders/edit/{$id}");
                return;
            }

            $op_sig = $this->_save_operator_signature();

            $update = array(
                'type'               => $this->input->post('type',           TRUE),
                'priority'           => $this->input->post('priority',       TRUE),
                'status'             => $this->input->post('status',         TRUE) ?: $wo->status,
                'title'              => $this->input->post('title',          TRUE),
                'description'        => $this->input->post('description',    TRUE),
                'root_cause'         => $this->input->post('root_cause',     TRUE) ?: NULL,
                'action_taken'       => $this->input->post('action_taken',   TRUE) ?: NULL,
                'pending_work'       => $this->input->post('pending_work',   TRUE) ?: NULL,
                'remarks'            => $this->input->post('remarks',        TRUE) ?: NULL,
                'assigned_to'        => $techs_csv,
                'supervisor_id'      => $this->user_id,
                'operator_name'      => $this->input->post('operator_name',  TRUE) ?: NULL,
                'start_datetime'     => $this->input->post('start_datetime', TRUE) ?: NULL,
                'end_datetime'       => $this->input->post('end_datetime',   TRUE) ?: NULL,
                'hours_reading'      => $this->input->post('hours_reading',  TRUE) ?: NULL,
                'workplace'          => $this->input->post('workplace',      TRUE) ?: NULL,
                'purchase_order'     => $this->input->post('purchase_order', TRUE) ?: NULL,
                'machine_status'     => $this->input->post('machine_status', TRUE) ?: NULL,
                'hold_code'          => $this->_valid_hold_code($this->input->post('hold_code', TRUE)),
                'planner'            => $this->input->post('planner',        TRUE) ?: NULL,
                'equipment_systems'  => $sys_csv,
            );
            if ($op_sig) $update['operator_signature'] = $op_sig;

            /*
             * Closing is a supervisor's verification, whichever screen is used
             * (SYSTEM.md §7.1 / §17). The quick status form already enforced
             * this; the edit form could silently set "Closed" without a
             * verifier, so the same rule is applied here.
             */
            if ($update['status'] === 'Closed' && $wo->status !== 'Closed' && !$this->is_supervisor()) {
                $this->session->set_flashdata('error',
                    'A work order is closed by a supervisor after checking the repair. '
                    .'Set it to Completed — the supervisor then verifies and closes it.');
                redirect("workorders/view/{$id}");
                return;
            }
            if ($update['status'] === 'Closed' && $wo->status !== 'Closed') {
                $update['verified_by']        = $this->user_id;
                $update['verified_at']        = date('Y-m-d H:i:s');
                $update['verification_notes'] = $this->input->post('verification_notes', TRUE) ?: NULL;
            }

            /*
             * Same gap as create(): the edit form posted straight into the
             * UPDATE. A blank title, or an enum value the column does not
             * accept (type/priority/status/machine_status), made MySQL reject
             * the row — nothing was saved, yet the page said "Work Order
             * updated." and the child rows below were still rewritten (K-09,
             * edit half). Validate first, then write.
             */
            $core_ok = trim((string)$update['title']) !== ''
                && in_array($update['type'], array('Corrective Maintenance', 'Preventive Maintenance', 'Incident'), TRUE)
                && in_array($update['priority'], array('Critical', 'High', 'Medium', 'Low'), TRUE)
                && in_array($update['status'], array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools', 'Completed', 'Closed'), TRUE)
                && ($update['machine_status'] === NULL || in_array($update['machine_status'], array('Operational', 'Breakdown'), TRUE));
            if (!$core_ok) {
                $this->session->set_flashdata('error',
                    'A work order needs a title, a valid type, priority and status. Nothing was saved.');
                redirect("workorders/edit/{$id}");
                return;
            }

            if (in_array($update['status'], array('Completed','Closed'))) {
                if (!$wo->completed_by) {
                    $update['completed_by'] = $this->user_id;
                }
                // The quick form stamps the end when work is completed; do the
                // same here so a WO completed through the edit form has one.
                if ($update['status'] === 'Completed' && empty($update['end_datetime'])) {
                    $update['end_datetime'] = date('Y-m-d H:i:s');
                }
            }

            /*
             * Downtime is derived, so recompute it last — after the Completed
             * stamp above may have filled end_datetime. strtotime() returns
             * FALSE for a malformed timestamp, which used to be arithmetic'd
             * into a large negative figure; with no usable pair the value is
             * cleared instead of keeping a stale duration.
             */
            $update['downtime_hours'] = NULL;
            $start_ts = $update['start_datetime'] ? strtotime($update['start_datetime']) : FALSE;
            $end_ts   = $update['end_datetime']   ? strtotime($update['end_datetime'])   : FALSE;
            if ($start_ts !== FALSE && $end_ts !== FALSE && $end_ts >= $start_ts) {
                $update['downtime_hours'] = round(($end_ts - $start_ts) / 3600, 2);
            }

            if ($this->Workorder_model->update($id, $update) === FALSE) {
                $this->session->set_flashdata('error',
                    'The work order could not be saved. Nothing was written — check the values and try again.');
                redirect("workorders/edit/{$id}");
                return;
            }
            $this->Workorder_model->save_spare_parts($id, $this->input->post('spares'));
            $this->Workorder_model->save_oil_records($id,  $this->input->post('lubes'));
            $lube = $this->Workorder_model->sync_lube_consumption($id, $this->user_id);

            // Transitioning to Completed/Closed consumes whatever stock was
            // still reserved for this WO (Pending Spare Parts Tracking).
            if (in_array($update['status'], array('Completed','Closed'))
                && !in_array($wo->status, array('Completed','Closed'))) {
                $this->Spareparts_model->auto_issue_for_wo($id, $this->user_id);
            }

            if ($update['type'] === 'Incident') {
                $this->_save_incident_details($id);
                $this->_save_evidence_uploads($id);
            }

            // Keep the machine's status in step with the work order's new
            // status (this path used to change the WO only).
            $this->_sync_equipment_status($wo->equipment_id, $update['type'], $update['status'], $id);

            $this->session->set_flashdata('success', 'Work Order updated.' . $this->_lube_sync_note($lube));
            redirect("workorders/view/{$id}");
        }

        $data['wo']             = $wo;
        $data['spare_parts']    = $this->Workorder_model->get_spare_parts($id);
        $data['lubes']          = $this->Workorder_model->get_oil_records($id);
        $data['equipment_list'] = $this->Equipment_model->get_all_active();
        $data['technicians']    = $this->_assignment_picker($wo);
        $data['attendance_gate']= $this->_attendance_gate();
        $data['incident']       = $this->Workorder_model->get_incident($id);
        $data['attachments']    = $this->Workorder_model->get_attachments($id, 'Incident Evidence');
        $data['page_title']     = 'Edit WO: ' . $wo->wo_number;
        $this->render('maintenance/wo_form', $data);
    }

    /* ─────────────────────────────────────────────────────
       UPDATE STATUS (quick)
    ───────────────────────────────────────────────────── */
    public function update_status($id) {
        if (!$this->require_post()) return;
        if (!$this->db->trans_begin()) {
            $this->session->set_flashdata('error', 'The status change could not be started. No changes were saved.');
            redirect('workorders');
            return;
        }
        $locked_wo = $this->db->query(
            'SELECT id FROM '.$this->db->dbprefix('work_orders').' WHERE id = ? FOR UPDATE',
            array((int)$id)
        );
        if (!$locked_wo || !$locked_wo->row()) {
            $this->db->trans_rollback();
            show_404();
            return;
        }
        $wo = $this->Workorder_model->get_by_id($id);
        if (!$wo) {
            $this->db->trans_rollback();
            show_404();
            return;
        }
        $status = $this->input->post('status', TRUE);
        $notes  = $this->input->post('notes',  TRUE);
        $update = array('status' => $status);

        /*
         * Hold code: why work is waiting (WSP/WQT/WCF/UD/UR, rule BR-28).
         * The column existed and the weekly report printed it, but no screen
         * ever wrote it. The quick form now offers it for waiting statuses;
         * a finished work order can never keep one.
         */
        if (in_array($status, array('Completed','Closed'), TRUE)) {
            $update['hold_code'] = NULL;
        } elseif ($this->input->post('hold_code') !== NULL) {
            $update['hold_code'] = $this->_valid_hold_code($this->input->post('hold_code', TRUE));
        }

        /*
         * Two-stage closure (Wave 3, Q5 / F-08).
         *
         * "Completed" is the technician's/workshop's statement that the work is
         * done. "Closed" is the supervisor's verification of it. Until now both
         * meant the same thing, both were allowed to the same person, and the
         * only record was `completed_by` — so a repair nobody checked was
         * indistinguishable from a verified one.
         *
         * `verified_by` / `verified_at` are only written by this branch, and
         * only by a supervisor (role 1 — the Manager is read-only by the access
         * matrix, so the verification authority is the supervisor).
         */
        if ($status === 'Closed' && !$this->is_supervisor()) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error',
                'A work order is closed by a supervisor after checking the repair. '
                .'Set it to Completed with your findings — the supervisor then verifies and closes it.');
            redirect("workorders/view/{$id}");
            return;
        }

        if ($status === 'In Progress' && !$wo->start_datetime) {
            $update['start_datetime'] = date('Y-m-d H:i:s');
        }
        if (in_array($status, array('Completed','Closed'))) {
            if ($status === 'Completed') {
                $update['end_datetime']  = date('Y-m-d H:i:s');
                $update['completed_by']  = $this->user_id;
            } else {
                $update['verified_by']          = $this->user_id;
                $update['verified_at']          = date('Y-m-d H:i:s');
                $update['verification_notes']   = $this->input->post('verification_notes', TRUE) ?: $notes;
                if (!$wo->end_datetime) {
                    $update['end_datetime'] = date('Y-m-d H:i:s');
                }
                if (!$wo->completed_by) {
                    $update['completed_by'] = $this->user_id;
                }
            }
            $posted_rc = $this->input->post('root_cause', TRUE);
            $posted_at = $this->input->post('action_taken', TRUE);
            if ($posted_rc !== null && trim((string)$posted_rc) !== '') {
                $update['root_cause'] = $posted_rc;
            }
            if ($posted_at !== null && trim((string)$posted_at) !== '') {
                $update['action_taken'] = $posted_at;
            }

            // Close any still-open labor session before finalizing the WO
            $this->Workorder_model->close_open_session_for_wo($id);
            $session_total = $this->Workorder_model->get_total_session_hours($id);

            // Preserve existing actual_hours if verifying/closing without new session/manual hours
            $manual_actual = $this->input->post('actual_hours', TRUE);
            if ($session_total > 0) {
                $update['actual_hours'] = $session_total;
            } elseif ($manual_actual !== null && $manual_actual !== '' && is_numeric($manual_actual)) {
                $update['actual_hours'] = (float)$manual_actual;
            }

            $effective_end = isset($update['end_datetime']) ? $update['end_datetime'] : $wo->end_datetime;
            if ($wo->start_datetime && $effective_end && ($status === 'Completed' || $wo->downtime_hours === null)) {
                $update['downtime_hours'] = max(0, round((strtotime($effective_end) - strtotime($wo->start_datetime)) / 3600, 2));
            }
            // -> Available (the helper also refuses to revive a Retired machine)
            $this->_sync_equipment_status($wo->equipment_id, $wo->type, $status, $id);

            // Pending Spare Parts Tracking: anything still reserved for this WO
            // is treated as consumed — book the ISSUE movements automatically.
            $issued_count = $this->Spareparts_model->auto_issue_for_wo($id, $this->user_id);
            if ($issued_count > 0) {
                $notes = trim((string)(trim((string)$notes).' [Reserved spare parts auto-issued to this WO: '
                    .$issued_count.' allocation(s).]'));
            }
        }

        $this->Workorder_model->update($id, $update);
        $this->Workorder_model->add_status_history($id, $wo->status, $status, $this->user_id, $notes);

        /*
         * One closure path for a defect (F-08). When a backlog item points at
         * this work order, verifying the repair here closes the defect too —
         * with the verifier recorded — instead of leaving the work order closed
         * and the defect open (or the reverse, which is what two independent
         * closure buttons produced).
         */
        if ($status === 'Closed' && $this->db->table_exists('backlogs')) {
            $update_cols = array(
                'status'          => 'Closed',
                'end_date'        => date('Y-m-d'),
                'closing_remarks' => 'Closed with work order '.$wo->wo_number." (verified by supervisor) — see the work order for what was done.",
                'closed_by'       => $this->user_id,
            );
            if ($this->db->field_exists('verified_by', 'backlogs')) {
                $update_cols['verified_by']         = $this->user_id;
                $update_cols['verified_at']         = date('Y-m-d H:i:s');
                $update_cols['verification_notes']  = 'Verified with work order '.$wo->wo_number.'.';
            }
            $this->db->where('work_order_id', (int)$id)
                ->where_in('status', array('Open', 'In Progress'))
                ->update('backlogs', $update_cols);

            if ($this->db->affected_rows() > 0) {
                $notes = trim((string)$notes.' [defect(s) on this work order closed]');
            }
        }

        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'The status change could not be completed. No changes were saved.');
            redirect("workorders/view/{$id}");
            return;
        }
        if (!$this->db->trans_commit()) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'The status change could not be committed. No changes were saved.');
            redirect("workorders/view/{$id}");
            return;
        }
        $this->session->set_flashdata('success', 'Status updated.');
        redirect("workorders/view/{$id}");
    }

    /* ─────────────────────────────────────────────────────
       JOB CARD
    ───────────────────────────────────────────────────── */
    public function jobcard($id) {
        $wo = $this->Workorder_model->get_by_id($id);
        if (!$wo) show_404();

        $spare_parts = $this->Workorder_model->get_spare_parts($id);
        $oil_records = $this->Workorder_model->get_oil_records($id);
        $equipment   = $this->db->get_where('equipment', array('id' => $wo->equipment_id))->row();

        // Multi-tech support
        $assigned_techs = array();
        $assigned_tech  = NULL;
        if (!empty($wo->assigned_to)) {
            $ids = array_filter(array_map('trim', explode(',', $wo->assigned_to)));
            if (!empty($ids)) {
                $techs = $this->db->where_in('id', $ids)->get('users')->result();
                foreach ($techs as $t) {
                    $assigned_techs[] = $t->first_name . ' ' . $t->last_name
                        . (!empty($t->position) ? ' (' . $t->position . ')' : '');
                }
                $assigned_tech = implode(', ', $assigned_techs);
            }
        }
        $wo->assigned_name  = $assigned_tech;
        $wo->assigned_techs = $assigned_techs;

        // Creator
        $creator    = $this->db->get_where('users', array('id' => $wo->created_by))->row();
        $created_by = $creator ? $creator->first_name . ' ' . $creator->last_name : '';

        // Site settings
        $site_settings = array();
        $rows = $this->db->get('settings')->result();
        foreach ($rows as $r) {
            $site_settings[$r->setting_key] = $r->setting_value;
        }

        if (empty($wo->registration_no) && $equipment) {
            $wo->registration_no = $equipment->registration_no;
        }

        // Signatures - check columns exist first
        $supervisor        = NULL;
        $operator          = NULL;
        $intervention_resp = NULL;

        $cols = $this->db->list_fields('work_orders');
        if (in_array('supervisor_id', $cols) && !empty($wo->supervisor_id)) {
            $supervisor = $this->db->get_where('users', array('id' => $wo->supervisor_id))->row();
        }
        // Operator is now free-text + drawn signature (not a DB user)
        $operator = NULL;
        if (!empty($wo->operator_name) || !empty($wo->operator_signature)) {
            $operator = (object) array(
                'first_name'  => isset($wo->operator_name) ? $wo->operator_name : '',
                'last_name'   => '',
                'position'    => 'Operator / Driver',
                'signature'   => isset($wo->operator_signature) ? $wo->operator_signature : '',
            );
        }
        if (!empty($assigned_techs)) {
            $ids_arr = array_filter(array_map('trim', explode(',', $wo->assigned_to)));
            $first   = reset($ids_arr);
            if ($first) {
                $intervention_resp = $this->db->get_where('users', array('id' => $first))->row();
            }
        }

        $data = array(
            'wo'               => $wo,
            'equipment'        => $equipment,
            'spare_parts'      => is_array($spare_parts) ? $spare_parts : array(),
            'oil_records'      => is_array($oil_records)  ? $oil_records  : array(),
            'assigned_tech'    => $assigned_tech,
            'assigned_techs'   => $assigned_techs,
            'created_by'       => $created_by,
            'site_settings'    => $site_settings,
            'supervisor'       => $supervisor,
            'operator'         => $operator,
            'intervention_resp'=> $intervention_resp,
            'incident'         => $wo->type === 'Incident' ? $this->Workorder_model->get_incident($id) : null,
            'attachments'      => $wo->type === 'Incident' ? $this->Workorder_model->get_attachments($id, 'Incident Evidence') : array(),
        );

        $this->load->view('maintenance/jobcard', $data);
    }

    /* ─────────────────────────────────────────────────────
       API — Spare part lookup
    ───────────────────────────────────────────────────── */
    public function api_spare_lookup() {
        $code = $this->input->get('code', TRUE);
        if (!$code) { $this->json(array('found'=>false)); return; }

        if (!$this->db->table_exists('materials')) {
            $this->json(array('found'=>false,'message'=>'Materials database not set up yet.'));
            return;
        }
        $item = $this->db->where('item_code', $code)->get('materials')->row();
        if ($item) {
            $pn    = isset($item->part_number_size) ? $item->part_number_size : '';
            $stock = (int)$item->initial_stock + (int)$item->qty_received - (int)$item->qty_sortie;
            $this->json(array(
                'found'       => true,
                'part_name'   => $item->description,
                'part_number' => $pn,
                'unit'        => $item->unit,
                'stock'       => $stock,
            ));
        } else {
            $this->json(array('found'=>false,'message'=>'Spare part not found in the database.'));
        }
    }

    /* ─────────────────────────────────────────────────────
       API — Stock check
    ───────────────────────────────────────────────────── */
    public function api_stock_check() {
        $code = $this->input->get('code', TRUE);
        $qty  = (float)$this->input->get('qty', TRUE);
        if (!$code) { $this->json(array('ok'=>true)); return; }

        if (!$this->db->table_exists('materials')) {
            $this->json(array('ok'=>true,'stock'=>null)); return;
        }
        $item = $this->db->where('item_code', $code)->get('materials')->row();
        if (!$item) { $this->json(array('ok'=>true,'stock'=>null)); return; }

        $stock = (int)$item->initial_stock + (int)$item->qty_received - (int)$item->qty_sortie;
        if ($qty > $stock) {
            $this->json(array('ok'=>false,'stock'=>$stock,
                'message'=>"Requested quantity ({$qty}) exceeds available stock ({$stock})."));
        } else {
            $this->json(array('ok'=>true,'stock'=>$stock));
        }
    }

    /* ─────────────────────────────────────────────────────
       PRIVATE HELPERS
    ───────────────────────────────────────────────────── */
    /* ─────────────────────────────────────────────────────
       LABOR SESSIONS — multi-stint time tracking
    ───────────────────────────────────────────────────── */

    /** Start a new work session (initial start, or resuming after an interruption) */
    public function start_session($id) {
        if (!$this->require_post()) return;
        if (!$this->db->trans_begin()) {
            $this->session->set_flashdata('error', 'The work session could not be started. No changes were saved.');
            redirect('workorders/view/'.$id);
            return;
        }

        $locked_wo = $this->db->query(
            'SELECT id FROM '.$this->db->dbprefix('work_orders').' WHERE id = ? FOR UPDATE',
            array((int)$id)
        );
        if (!$locked_wo || !$locked_wo->row()) {
            $this->db->trans_rollback();
            show_404();
            return;
        }
        $wo = $this->Workorder_model->get_by_id($id);
        if (!$wo) {
            $this->db->trans_rollback();
            show_404();
            return;
        }

        $requested_tech = $this->input->post('technician_id', TRUE);
        $technician_id = is_scalar($requested_tech) && $requested_tech !== ''
            ? (int)$requested_tech : (int)$this->user_id;
        $reason_input = $this->input->post('interruption_reason', TRUE);
        $reason = is_scalar($reason_input) && $reason_input !== '' ? (string)$reason_input : NULL;
        if ($technician_id <= 0) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'A valid technician is required to start a session.');
            redirect('workorders/view/'.$id);
            return;
        }

        // Serialize starts for one technician as well as starts on one WO.
        $locked_tech = $this->db->query(
            'SELECT id FROM '.$this->db->dbprefix('users').' WHERE id = ? FOR UPDATE',
            array($technician_id)
        );
        if (!$locked_tech || !$locked_tech->row()) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'The selected technician was not found.');
            redirect('workorders/view/'.$id);
            return;
        }

        // Enforce attendance gate when clocking on to a work session (ATTENDANCE_WORKFLOW.md):
        // 1. Any technician clocking on must currently be checked in on site (not absent / checked out).
        // 2. If starting work on an unassigned work order (not already named in `assigned_to`),
        //    the technician must also pass full work-order assignment eligibility.
        if ($this->_attendance_gate() && $this->Attendance_model->ready()) {
            $already_assigned = in_array(
                $technician_id,
                array_map('intval', preg_split('/[^0-9]+/', (string)$wo->assigned_to, -1, PREG_SPLIT_NO_EMPTY)),
                TRUE
            );
            if (!$already_assigned) {
                $gate_problems = $this->Attendance_model->assignment_problems((string)$technician_id);
                if (!empty($gate_problems)) {
                    $this->db->trans_rollback();
                    $this->session->set_flashdata('error',
                        'Cannot start work while the attendance gate is on: ' . implode(' ', $gate_problems));
                    redirect('workorders/view/'.$id);
                    return;
                }
            } elseif (!$this->Attendance_model->is_on_site($technician_id)) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error',
                    'Cannot start work while the attendance gate is on: technician is not currently checked in on site.');
                redirect('workorders/view/'.$id);
                return;
            }
        }

        // A technician can only be actively clocked on one WO at a time.
        // If they have an open session elsewhere, close it with this new one.
        $switch_note = '';
        $other_open  = $this->Workorder_model->get_open_session_for_technician($technician_id, $id);
        if ($other_open) {
            $this->Workorder_model->close_session($other_open->id, 'Switched to WO '.$wo->wo_number, $id);
            $closed = $this->Workorder_model->get_session($other_open->id);
            if (!$closed || $closed->status !== 'Closed') {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'The previous work session could not be closed; no new session was started.');
                redirect('workorders/view/'.$id);
                return;
            }
            $switch_note = ' Their open session on WO '.$other_open->other_wo_number.' was closed automatically ('
                .number_format((float)($closed->hours_worked), 2).'h logged).';
        }

        $session_id = $this->Workorder_model->start_session($id, $technician_id, $this->user_id, $reason);
        if (!$session_id || $session_id === false) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'A session is already open for this Work Order; no changes were saved.');
            redirect('workorders/view/'.$id);
            return;
        }

        // First session on a WO — mark it In Progress and stamp start_datetime, same as before.
        if (!$wo->start_datetime) {
            $this->Workorder_model->update($id, array(
                'status'         => 'In Progress',
                'start_datetime' => date('Y-m-d H:i:s'),
            ));
            $this->Workorder_model->add_status_history($id, $wo->status, 'In Progress', $this->user_id, 'Session started');
        }

        if ($this->db->trans_status() === FALSE || !$this->db->trans_commit()) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'The work session could not be saved; no changes were retained.');
            redirect('workorders/view/'.$id);
            return;
        }
        $this->session->set_flashdata('success', 'Work session started.'.$switch_note);
        redirect('workorders/view/'.$id);
    }

    /** Close the currently open session — hours auto-calculated from timestamps */
    public function close_session($session_id, $wo_id = null) {
        if (!$this->require_post()) return;
        if ($wo_id === null) show_404();
        if (!$this->db->trans_begin()) {
            $this->session->set_flashdata('error', 'The work session could not be closed. No changes were saved.');
            redirect('workorders/view/'.$wo_id);
            return;
        }

        $locked_wo = $this->db->query(
            'SELECT id FROM '.$this->db->dbprefix('work_orders').' WHERE id = ? FOR UPDATE',
            array((int)$wo_id)
        );
        $locked_session = $this->db->query(
            'SELECT * FROM '.$this->db->dbprefix('wo_sessions').' WHERE id = ? AND wo_id = ? FOR UPDATE',
            array((int)$session_id, (int)$wo_id)
        );
        $session = $locked_session ? $locked_session->row() : null;
        if (!$locked_wo || !$locked_wo->row() || !$session) {
            $this->db->trans_rollback();
            show_404();
            return;
        }
        if ($session->status !== 'Open') {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'This work session is no longer open.');
            redirect('workorders/view/'.$wo_id);
            return;
        }

        $work_performed = $this->input->post('work_performed', TRUE);
        if (!$this->Workorder_model->close_session($session_id, $work_performed)
            || $this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'The work session could not be closed. No changes were saved.');
            redirect('workorders/view/'.$wo_id);
            return;
        }
        if (!$this->db->trans_commit()) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'The work session could not be committed. No changes were saved.');
            redirect('workorders/view/'.$wo_id);
            return;
        }
        $this->session->set_flashdata('success', 'Session closed and hours recorded.');
        redirect('workorders/view/'.$wo_id);
    }

    /** Printable job card for a single session — same document, scoped to one stint */
    public function session_jobcard($session_id) {
        $session = $this->Workorder_model->get_session($session_id);
        if (!$session) show_404();
        $wo = $this->Workorder_model->get_by_id($session->wo_id);
        if (!$wo) show_404();

        $equipment   = $this->Equipment_model->get_by_id($wo->equipment_id);
        $spare_parts = $this->Workorder_model->get_spare_parts($wo->id);
        $oil_records = $this->Workorder_model->get_oil_records($wo->id);

        // This session's technician only — job card is scoped to who worked this stint
        $assigned_tech  = $session->technician_name;
        $assigned_techs = $session->technician_name ? array($session->technician_name) : array();

        $creator    = $this->db->get_where('users', array('id' => $wo->created_by))->row();
        $created_by = $creator ? $creator->first_name . ' ' . $creator->last_name : '';

        $site_settings = array();
        foreach ($this->db->get('settings')->result() as $r) {
            $site_settings[$r->setting_key] = $r->setting_value;
        }
        if (empty($wo->registration_no) && $equipment) {
            $wo->registration_no = $equipment->registration_no;
        }

        $supervisor = NULL;
        $cols = $this->db->list_fields('work_orders');
        if (in_array('supervisor_id', $cols) && !empty($wo->supervisor_id)) {
            $supervisor = $this->db->get_where('users', array('id' => $wo->supervisor_id))->row();
        }
        $operator = NULL;
        if (!empty($wo->operator_name) || !empty($wo->operator_signature)) {
            $operator = (object) array(
                'first_name' => isset($wo->operator_name) ? $wo->operator_name : '',
                'last_name'  => '',
                'position'   => 'Operator / Driver',
                'signature'  => isset($wo->operator_signature) ? $wo->operator_signature : '',
            );
        }
        $intervention_resp = $session->technician_id
            ? $this->db->get_where('users', array('id' => $session->technician_id))->row()
            : NULL;

        $sessions_all = $this->Workorder_model->get_sessions($wo->id);

        $data = array(
            'wo'                => $wo,
            'equipment'         => $equipment,
            'spare_parts'       => is_array($spare_parts) ? $spare_parts : array(),
            'oil_records'       => is_array($oil_records)  ? $oil_records  : array(),
            'assigned_tech'     => $assigned_tech,
            'assigned_techs'    => $assigned_techs,
            'created_by'        => $created_by,
            'site_settings'     => $site_settings,
            'supervisor'        => $supervisor,
            'operator'          => $operator,
            'intervention_resp' => $intervention_resp,
            'incident'          => $wo->type === 'Incident' ? $this->Workorder_model->get_incident($wo->id) : null,
            'attachments'       => $wo->type === 'Incident' ? $this->Workorder_model->get_attachments($wo->id, 'Incident Evidence') : array(),
            'session'           => $session,
            'session_count'     => count($sessions_all),
        );

        $this->load->view('maintenance/jobcard', $data);
    }

    /* ─────────────────────────────────────────────────────
       INCIDENT — review (supervisor sign-off)
    ───────────────────────────────────────────────────── */
    public function review_incident($id) {
        if (!$this->require_post()) return;
        $this->Workorder_model->mark_incident_reviewed($id, $this->user_id);
        $this->session->set_flashdata('success', 'Incident report marked as reviewed.');
        redirect('workorders/view/'.$id);
    }

    /* ─────────────────────────────────────────────────────
       INCIDENT — add evidence after creation
    ───────────────────────────────────────────────────── */
    public function upload_evidence($id) {
        if (!$this->require_post()) return;
        $wo = $this->Workorder_model->get_by_id($id);
        if (!$wo) show_404();
        if ($this->_save_evidence_uploads($id)) {
            $this->session->set_flashdata('success', 'Evidence uploaded.');
        } else {
            $this->session->set_flashdata('error',
                'Evidence was not saved: the attachment table is missing on this installation. '
                .'Import the database dump shipped with this repository and try again.');
        }
        redirect('workorders/view/'.$id);
    }

    /* ─────────────────────────────────────────────────────
       BREAKDOWN EVENT — group repair work orders of one failure
    ───────────────────────────────────────────────────── */
    public function link_breakdown_event($id) {
        if (!$this->require_post()) return;

        $wo = $this->Workorder_model->get_by_id($id);
        if (!$wo) show_404();

        $result = $this->Workorder_model->link_breakdown_event(
            $id, $this->input->post('breakdown_event_id', TRUE)
        );

        $this->session->set_flashdata($result['ok'] ? 'success' : 'error', $result['message']);
        redirect("workorders/view/{$id}");
    }

    public function download_attachment($attachment_id) {
        $att = $this->Workorder_model->get_attachment($attachment_id);
        if (!$att) show_404();
        $path = './uploads/wo_attachments/'.$att->file_name;
        if (!file_exists($path)) show_404();
        force_download($path, NULL, TRUE);
    }

    public function delete_attachment($attachment_id, $wo_id = null) {
        if (!$this->require_post()) return;
        if ($wo_id === null) show_404();
        if (!$this->Workorder_model->get_by_id($wo_id)) show_404();
        if (!$this->Workorder_model->delete_attachment($attachment_id, $wo_id)) show_404();
        $this->session->set_flashdata('success', 'Attachment removed.');
        redirect('workorders/view/'.$wo_id);
    }

    /* ─────────────────────────────────────────────────────
       PRIVATE HELPERS
    ───────────────────────────────────────────────────── */
    /** Save structured incident fields posted from wo_form (HSE Near-Miss form layout) */
    private function _save_incident_details($wo_id) {
        if ($this->input->post('incident_datetime') === null) return; // section wasn't shown/submitted
        $this->Workorder_model->save_incident($wo_id, array(
            'incident_datetime'    => $this->input->post('incident_datetime', TRUE) ?: NULL,
            'site'                 => $this->input->post('incident_site', TRUE) ?: NULL,
            'department'           => $this->input->post('incident_department', TRUE) ?: NULL,
            'location'             => $this->input->post('incident_location', TRUE) ?: NULL,
            'involved_party_type'  => $this->input->post('involved_party_type', TRUE) ?: NULL,
            'staff_id'             => $this->input->post('staff_id', TRUE) ?: NULL,
            'person_name'          => $this->input->post('person_name', TRUE) ?: NULL,
            'person_function'      => $this->input->post('person_function', TRUE) ?: NULL,
            'injured'              => $this->input->post('injured', TRUE) ? 1 : 0,
            'fleet_no'             => $this->input->post('fleet_no', TRUE) ?: NULL,
            'part_affected'        => $this->input->post('part_affected', TRUE) ?: NULL,
            'equipment_damaged'    => $this->input->post('equipment_damaged', TRUE) ? 1 : 0,
            'description'          => $this->input->post('incident_description', TRUE) ?: NULL,
            'injury_damage_details'=> $this->input->post('injury_damage_details', TRUE) ?: NULL,
            'actions_taken'        => $this->input->post('actions_taken', TRUE) ?: NULL,
            'activity_status'      => $this->input->post('activity_status', TRUE) ?: NULL,
        ));
    }

    /** Handle multiple evidence photo/PDF uploads (field name: evidence[]) */
    private function _save_evidence_uploads($wo_id) {
        if (empty($_FILES['evidence']['name'][0])) return TRUE;

        if (!is_dir('./uploads/wo_attachments/')) mkdir('./uploads/wo_attachments/', 0755, true);

        $count = count($_FILES['evidence']['name']);
        for ($i = 0; $i < $count; $i++) {
            if (empty($_FILES['evidence']['name'][$i])) continue;

            $_FILES['evidence_single'] = array(
                'name'     => $_FILES['evidence']['name'][$i],
                'type'     => $_FILES['evidence']['type'][$i],
                'tmp_name' => $_FILES['evidence']['tmp_name'][$i],
                'error'    => $_FILES['evidence']['error'][$i],
                'size'     => $_FILES['evidence']['size'][$i],
            );

            $config = array(
                'upload_path'   => './uploads/wo_attachments/',
                'allowed_types' => 'gif|jpg|jpeg|png|pdf|doc|docx',
                'max_size'      => 8000,
                'file_name'     => 'evd_'.$wo_id.'_'.time().'_'.$i,
            );
            $this->load->library('upload', $config);
            $this->upload->initialize($config);
            if ($this->upload->do_upload('evidence_single')) {
                $up  = $this->upload->data();
                $ok  = $this->Workorder_model->add_attachment(
                    $wo_id, 'Incident Evidence', $up['file_name'], $_FILES['evidence_single']['name'], $this->user_id
                );
                if (!$ok) {
                    // The file landed on disk but could not be recorded, so it
                    // would be invisible and unreachable — remove it rather than
                    // leave an orphan that shows up in no list (F-07).
                    @unlink('./uploads/wo_attachments/'.$up['file_name']);
                    unset($_FILES['evidence_single']);
                    return FALSE;
                }
            }
        }
        unset($_FILES['evidence_single']);
        return TRUE;
    }

    /** Save operator drawn signature (base64 PNG) to file, return path */
    private function _save_operator_signature() {
        $data = $this->input->post('operator_signature_data', FALSE);
        if (!$data || strlen((string)($data)) < 30) return NULL;

        // base64 PNG from canvas.toDataURL()
        if (strpos($data, 'data:image/png;base64,') === 0) {
            $base64 = substr($data, strlen('data:image/png;base64,'));
            $bytes  = base64_decode($base64);
            if (!$bytes) return NULL;

            if (!is_dir('./uploads/signatures/')) mkdir('./uploads/signatures/', 0755, true);
            $fname = 'opsig_' . $this->user_id . '_' . time() . '.png';
            $path  = './uploads/signatures/' . $fname;
            file_put_contents($path, $bytes);
            return 'uploads/signatures/' . $fname;
        }
        return NULL;
    }

    private function _get_systems_csv() {
        $csv = $this->input->post('equipment_systems_csv', TRUE);
        if (!$csv) {
            $arr = $this->input->post('equipment_systems', TRUE);
            $csv = $arr ? implode(',', (array)$arr) : NULL;
        }
        return $csv;
    }

    /* ─────────────────────────────────────────────────────
       ATTENDANCE GATE (ATTENDANCE_WORKFLOW.md)

       When `attendance_gate_enabled` is on, only technicians who are on site
       and free of unfinished work orders may be OFFERED for assignment, and
       the same rule is re-checked here when the form is saved — a hidden name
       in the picker is not a rule. The gate is off by default, so an
       installation that has not configured an attendance site behaves exactly
       as before.
    ───────────────────────────────────────────────────── */
    private function _attendance_gate() {
        try {
            return (string)$this->Settings_model->get('attendance_gate_enabled', '0') === '1';
        } catch (Throwable $e) {
            return FALSE;
        }
    }

    /**
     * The technicians a supervisor may pick in this form.
     *
     * On an edit, the technicians already named on the order are included even
     * when they are no longer eligible: otherwise an unrelated edit would hide
     * them, and the supervisor could not see whom to clear. They are flagged
     * (`->eligible = FALSE`, `->block_reasons`) for the view.
     *
     * @param  object|null $wo the order being edited
     * @return array
     */
    private function _assignment_picker($wo = NULL) {
        if (!$this->_attendance_gate()) {
            return $this->Team_model->get_technicians();
        }

        try {
            if (!$this->Attendance_model->ready()) {
                return $this->Team_model->get_technicians();
            }

            // Offer ONLY eligible technicians in the picker (ATTENDANCE_WORKFLOW.md
            // → "Work-order assignment eligibility"), then re-attach anyone already
            // named on this order when editing so a supervisor can still clear them.
            $list    = $this->Attendance_model->technicians_for_assignment(NULL, TRUE);
            $have    = array();
            $current = array();
            foreach ($list as $t) $have[(int)$t->id] = TRUE;

            if ($wo && !empty($wo->assigned_to)) {
                $current = preg_split('/[^0-9]+/', (string)$wo->assigned_to, -1, PREG_SPLIT_NO_EMPTY);
            }

            foreach (array_unique($current) as $id) {
                $id = (int)$id;
                if ($id <= 0 || isset($have[$id])) continue;

                $row = $this->db->select('id, first_name, last_name, position, employee_id, role_id')
                    ->where('id', $id)->get('users')->row();
                if (!$row) continue;

                $e = $this->Attendance_model->eligibility($id);
                $row->on_site       = $e['on_site'];
                $row->eligible      = $e['eligible'];
                $row->block_reasons = $e['reasons'];
                $list[] = $row;
                $have[$id] = TRUE;
            }

            return $list;
        } catch (Throwable $e) {
            // The gate must never break the work-order form.
            log_message('error', 'WMS: attendance gate could not be evaluated — ' . $e->getMessage());
            return $this->Team_model->get_technicians();
        }
    }

    /**
     * Server-side re-check of what actually arrived in `assigned_to`.
     *
     * Only the ids being ADDED are checked: clearing an ineligible technician
     * has to stay possible, and an unrelated edit must not be blocked by an
     * assignment that already existed when the gate was switched on.
     *
     * @return array human-readable problems (empty = accept)
     */
    private function _assignment_problems($new_csv, $old_csv = '') {
        if (!$this->_attendance_gate()) return array();

        try {
            if (!$this->Attendance_model->ready()) return array();
        } catch (Throwable $e) {
            return array();
        }

        $old = array();
        foreach (preg_split('/[^0-9]+/', (string)$old_csv, -1, PREG_SPLIT_NO_EMPTY) as $id) $old[(int)$id] = TRUE;

        $added = array();
        foreach (preg_split('/[^0-9]+/', (string)$new_csv, -1, PREG_SPLIT_NO_EMPTY) as $id) {
            $id = (int)$id;
            if ($id > 0 && !isset($old[$id])) $added[] = $id;
        }
        if (empty($added)) return array();

        return $this->Attendance_model->assignment_problems(implode(',', $added));
    }

    private function _generate_wo_number() {
        $prefix = 'WO-AML-';
        $last   = $this->db->like('wo_number', $prefix, 'after')
                            ->order_by('id', 'DESC')
                            ->limit(1)
                            ->get('work_orders')->row();
        $seq = 1;
        if ($last) {
            $num = preg_replace('/[^0-9]/', '', substr($last->wo_number, strlen((string)($prefix))));
            $seq = (int)$num + 1;
        }
        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

    /* ─────────────────────────────────────────────────────
       STATUS / LEDGER helpers
    ───────────────────────────────────────────────────── */

    /** A posted hold code, or NULL. Only the five approved codes are stored. */
    private function _valid_hold_code($value) {
        $value = strtoupper((string)(trim((string)$value)));
        if ($value === '') return NULL;
        $codes = wo_hold_codes();
        return isset($codes[$value]) ? $value : NULL;
    }

    /**
     * Keep `equipment.status` in step with the work order.
     *
     * Rules (SYSTEM.md §7.1):
     *   corrective WO open / in progress  → Breakdown
     *   planned (PM / incident) in progress → In Maintenance
     *   Completed / Closed                → Available
     *   planned WO still Open             → unchanged (not down yet)
     *
     * `Equipment_model::update_status()` refuses to revive a Retired machine.
     */
    private function _sync_equipment_status($equipment_id, $type, $status, $exclude_wo_id = NULL) {
        $equipment_id = (int)$equipment_id;
        if ($equipment_id <= 0) return;

        $is_breakdown = (strcasecmp((string)$type, 'Corrective Maintenance') === 0);

        if (in_array($status, array('Completed','Closed'), TRUE)) {
            $target = 'Available';
        } elseif ($status === 'In Progress') {
            $target = $is_breakdown ? 'Breakdown' : 'In Maintenance';
        } elseif (in_array($status, array('Open','Waiting Parts','Waiting Tools'), TRUE) && $is_breakdown) {
            $target = 'Breakdown';
        } else {
            $target = NULL;
        }

        if ($target === NULL) return;

        /*
         * One machine, several work orders: finishing this job does not make
         * the machine Available while another open job still has it down.
         * The machine's status therefore follows the strongest open claim:
         * an open breakdown wins over an open planned job, and only when
         * nothing is open does it become Available.
         */
        if ($target === 'Available' || $target === 'In Maintenance') {
            $this->db->select('id, is_breakdown, type')
                ->where('equipment_id', $equipment_id)
                ->where_in('status', array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools'));
            // This work order may not have been written yet (quick status
            // change writes after this call), so exclude it explicitly.
            if ($exclude_wo_id) $this->db->where('id !=', (int)$exclude_wo_id);
            $open = $this->db->get('work_orders')->result();

            $has_open_breakdown = FALSE;
            $has_open_planned   = FALSE;
            foreach ($open as $o) {
                if (function_exists('wo_is_breakdown') ? wo_is_breakdown($o) : !empty($o->is_breakdown)) {
                    $has_open_breakdown = TRUE;
                } else {
                    $has_open_planned = TRUE;
                }
            }
            if ($has_open_breakdown) {
                $target = 'Breakdown';
            } elseif ($target === 'Available' && $has_open_planned) {
                $target = 'In Maintenance';
            }
        }

        $this->Equipment_model->update_status($equipment_id, $target);
    }

    /** Flash-message suffix describing what the lubricant sync did. */
    private function _lube_sync_note($result) {
        if (!is_array($result)) return '';
        $note = '';
        if (!empty($result['posted'])) {
            $note .= ' '.$result['posted'].' oil line(s) posted to lubricant consumption.';
        }
        if (!empty($result['unmatched'])) {
            $note .= ' '.$result['unmatched'].' oil line(s) not matched to a lubricant type — '
                   . 'record them under Lubricants if they should count.';
        }
        return $note;
    }
}
