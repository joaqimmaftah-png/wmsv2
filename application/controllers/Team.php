<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Team extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Team_model');
    }

    public function index() {
        $data['workers']    = $this->Team_model->get_all();
        // Leave requests waiting for a decision (supervisors only — the
        // approve/reject action is guarded as well, this just hides the panel).
        $data['pending_leave'] = $this->is_supervisor()
            ? $this->Team_model->get_pending_leave_requests()
            : array();
        $data['page_title'] = 'Team Management';
        $this->render('team/index', $data);
    }

    public function create() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('team');
        }

        if ($this->input->post()) {
            $password = $this->input->post('password', TRUE);
            $data = [
                'role_id'     => $this->input->post('role_id', TRUE),
                'employee_id' => strtoupper($this->input->post('employee_id', TRUE)),
                'first_name'  => $this->input->post('first_name', TRUE),
                'last_name'   => $this->input->post('last_name', TRUE),
                'email'       => $this->input->post('email', TRUE),
                'password'    => password_hash($password, PASSWORD_DEFAULT),
                'phone'       => $this->input->post('phone', TRUE),
                'position'    => $this->input->post('position', TRUE),
                'skill_level' => $this->input->post('skill_level', TRUE),
                'join_date'   => $this->input->post('join_date', TRUE) ?: NULL,
                'status'      => 'Active',
            ];

            if (!empty($_FILES['photo']['name'])) {
                $config = ['upload_path' => './uploads/photos/', 'allowed_types' => 'gif|jpg|jpeg|png', 'max_size' => 2000, 'file_name' => 'user_' . time()];
                $this->load->library('upload', $config);
                if ($this->upload->do_upload('photo')) {
                    $data['photo'] = $this->upload->data('file_name');
                }
            }

            if (!empty($_FILES['signature']['name'])) {
                $this->_save_signature($data);
            }

            $this->Team_model->create($data);
            $this->session->set_flashdata('success', 'Worker added.');
            redirect('team');
        }

        $data['roles']      = $this->db->get('roles')->result();
        $data['page_title'] = 'Add Worker';
        $this->render('team/form', $data);
    }

    public function view($id) {
        $data['worker']       = $this->Team_model->get_by_id($id);
        if (!$data['worker']) show_404();

        // Stored cert status is refreshed from the expiry date on every view,
        // so "Valid" can never outlive the certificate.
        $this->Team_model->refresh_cert_status($id);

        $data['attendance']   = $this->Team_model->get_attendance_history($id, 30);
        $data['certifications'] = $this->Team_model->get_certifications($id);
        $data['leave_history']= $this->Team_model->get_leave_history($id);
        $data['leave_balance']= $this->Team_model->get_leave_balance($id);
        $data['leave_year']   = (int)date('Y');
        $data['cert_alert_days'] = $this->_cert_alert_days();
        $data['page_title']   = $data['worker']->first_name . ' ' . $data['worker']->last_name;
        $this->render('team/view', $data);
    }

    public function attendance() {
        if (strtolower($this->input->method()) === 'post') {
            $posted_date = $this->input->post('date', TRUE);
            $date = is_string($posted_date) && $posted_date !== '' ? $posted_date : date('Y-m-d');
            if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
                || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
                $this->session->set_flashdata('error', 'Attendance date is invalid.');
                redirect('team/attendance');
                return;
            }

            $records = $this->input->post('attendance', TRUE);
            if (!is_array($records) || empty($records)) {
                $this->session->set_flashdata('error', 'No attendance records were submitted.');
                redirect('team/attendance?date=' . rawurlencode($date));
                return;
            }

            $allowed_statuses = array('Present', 'Absent', 'Leave', 'Rest');
            if (!$this->db->trans_begin()) {
                $this->session->set_flashdata('error', 'Attendance could not be saved because the database transaction could not be started.');
                redirect('team/attendance?date=' . rawurlencode($date));
                return;
            }
            foreach ($records as $user_id => $record) {
                $user_id = (string)$user_id;
                if (!preg_match('/^[1-9][0-9]*$/D', $user_id) || !is_array($record)
                    || !isset($record['status']) || !in_array($record['status'], $allowed_statuses, true)) {
                    $this->db->trans_rollback();
                    $this->session->set_flashdata('error', 'An attendance record was invalid; no records were saved.');
                    redirect('team/attendance?date=' . rawurlencode($date));
                    return;
                }

                $check_in = isset($record['check_in']) && $record['check_in'] !== '' ? $record['check_in'] : NULL;
                $check_out = isset($record['check_out']) && $record['check_out'] !== '' ? $record['check_out'] : NULL;
                if (($check_in !== NULL && (!is_scalar($check_in) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', (string)$check_in)))
                    || ($check_out !== NULL && (!is_scalar($check_out) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', (string)$check_out)))) {
                    $this->db->trans_rollback();
                    $this->session->set_flashdata('error', 'A check-in or check-out time was invalid; no records were saved.');
                    redirect('team/attendance?date=' . rawurlencode($date));
                    return;
                }

                /*
                 * REPLACE deletes the row and inserts a new one: the id changes,
                 * `hours_worked` is reset, and any note is lost — including the
                 * marker an approved leave wrote, which would then survive its
                 * own rejection. Update the existing day instead (the table has
                 * UNIQUE(user_id, date)).
                 */
                $existing = $this->db->select('id, notes')
                    ->where('user_id', (int)$user_id)->where('date', $date)
                    ->get('attendance')->row();

                $row = array(
                    'status'     => $record['status'],
                    'check_in'   => $check_in,
                    'check_out'  => $check_out,
                    'created_by' => $this->user_id,
                );

                if ($existing) {
                    // The day is no longer Leave, so it must no longer be
                    // claimed by the leave request: clear only its marker.
                    if ($record['status'] !== 'Leave'
                        && strpos((string)$existing->notes, Team_model::LEAVE_ATTENDANCE_PREFIX) === 0) {
                        $row['notes'] = NULL;
                    }
                    $this->db->where('id', $existing->id)->update('attendance', $row);
                } else {
                    $row['user_id'] = (int)$user_id;
                    $row['date']    = $date;
                    $this->db->insert('attendance', $row);
                }
            }

            if ($this->db->trans_status() === FALSE) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'Attendance could not be saved; no records were changed.');
            } elseif (!$this->db->trans_commit()) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'Attendance could not be committed; no records were changed.');
            } else {
                $this->session->set_flashdata('success', 'Attendance recorded.');
            }
            redirect('team/attendance?date=' . rawurlencode($date));
            return;
        }

        $requested_date = $this->input->get('date', TRUE);
        $date = is_string($requested_date) ? $requested_date : date('Y-m-d');
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
            || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
            $date = date('Y-m-d');
        }

        $data['workers']    = $this->Team_model->get_all_active();
        $data['attendance'] = $this->Team_model->get_attendance_by_date($date);
        $data['date']       = $date;
        $data['page_title'] = 'Daily Attendance';
        $this->render('team/attendance', $data);
    }

    public function leave_request() {
        if ($this->input->post()) {
            $start = $this->_valid_date($this->input->post('start_date', TRUE));
            $end   = $this->_valid_date($this->input->post('end_date', TRUE));

            if (!$start || !$end) {
                $this->session->set_flashdata('error', 'Please give both a valid start date and end date.');
                redirect('team/leave_request');
                return;
            }
            if (strtotime($end) < strtotime($start)) {
                $this->session->set_flashdata('error', 'The end date cannot be before the start date.');
                redirect('team/leave_request');
                return;
            }

            $leave_type = $this->input->post('leave_type', TRUE);
            if (!in_array($leave_type, Team_model::LEAVE_TYPES, TRUE)) {
                $this->session->set_flashdata('error', 'Please choose a valid leave type.');
                redirect('team/leave_request');
                return;
            }

            if ($this->Team_model->has_overlapping_leave($this->user_id, $start, $end)) {
                $this->session->set_flashdata('error',
                    'You already have a pending or approved leave request covering part of '
                    .date('d M Y', strtotime($start)).' – '.date('d M Y', strtotime($end)).'.');
                redirect('team/leave_request');
                return;
            }

            $days = (int)floor((strtotime($end) - strtotime($start)) / 86400) + 1;

            // Check the balance BEFORE inserting: once this request is a row,
            // its own days are already subtracted and comparing it with itself
            // would warn about requests that actually fit.
            // A request is never blocked on the balance (a supervisor may still
            // approve an exception), so the warning rides on the success flash.
            $warning = $this->_leave_balance_warning($this->user_id, $leave_type, $days);

            $this->db->insert('leave_requests', [
                'user_id'    => $this->user_id,
                'leave_type' => $leave_type,
                'start_date' => $start,
                'end_date'   => $end,
                'days_count' => max(1, $days),
                'reason'     => $this->input->post('reason', TRUE),
                'status'     => 'Pending',
            ]);

            $this->session->set_flashdata('success',
                'Leave request submitted.' . ($warning ? ' ' . $warning : ''));
            redirect('team');
        }

        $data['balances']    = $this->Team_model->get_leave_balance($this->user_id);
        $data['leave_year']  = (int)date('Y');
        $data['page_title']  = 'Leave Request';
        $this->render('team/leave_form', $data);
    }

    public function edit($id) {
        if (!$this->is_supervisor()) { redirect('team'); }

        $worker = $this->Team_model->get_by_id($id);
        if (!$worker) show_404();

        if ($this->input->post()) {
            $data = [
                'role_id'     => $this->input->post('role_id', TRUE),
                'first_name'  => $this->input->post('first_name', TRUE),
                'last_name'   => $this->input->post('last_name', TRUE),
                'email'       => $this->input->post('email', TRUE),
                'phone'       => $this->input->post('phone', TRUE),
                'position'    => $this->input->post('position', TRUE),
                'skill_level' => $this->input->post('skill_level', TRUE),
                'status'      => $this->input->post('status', TRUE),
                'join_date'   => $this->input->post('join_date', TRUE) ?: NULL,
            ];
            $pw = $this->input->post('password', TRUE);
            if ($pw) $data['password'] = password_hash($pw, PASSWORD_DEFAULT);

            if (!empty($_FILES['photo']['name'])) {
                $config = ['upload_path' => './uploads/photos/', 'allowed_types' => 'gif|jpg|jpeg|png', 'max_size' => 2000, 'file_name' => 'user_'.time()];
                $this->load->library('upload', $config);
                if ($this->upload->do_upload('photo')) { $data['photo'] = $this->upload->data('file_name'); }
            }

            if (!empty($_FILES['signature']['name'])) {
                $this->_save_signature($data, $id);
            }

            $this->db->update('users', $data, ['id' => $id]);
            $this->session->set_flashdata('success', 'Profile updated.');
            redirect('team/view/'.$id);
        }

        $data['worker']     = $worker;
        $data['roles']      = $this->db->get('roles')->result();
        $data['page_title'] = 'Edit — '.$worker->first_name.' '.$worker->last_name;
        $this->render('team/form', $data);
    }

    /** Upload signature, process to transparent PNG, save path in $data['signature'] */
    private function _save_signature(&$data, $user_id = null) {
        if (!is_dir('./uploads/signatures/')) mkdir('./uploads/signatures/', 0755, true);
        $fname = 'sig_'.($user_id ?: time()).'_'.time().'.png';
        $config = [
            'upload_path'   => './uploads/signatures/',
            'allowed_types' => 'jpg|jpeg|png|gif',
            'max_size'      => 3000,
            'file_name'     => pathinfo($fname, PATHINFO_FILENAME),
        ];
        $this->load->library('upload', $config);
        if ($this->upload->do_upload('signature')) {
            $uploaded = $this->upload->data('file_name');
            // Remove black background → transparent PNG
            $src_path = './uploads/signatures/' . $uploaded;
            $out_path = './uploads/signatures/' . $fname;
            $this->_make_transparent($src_path, $out_path);
            if ($src_path !== $out_path) @unlink($src_path);
            $data['signature'] = 'uploads/signatures/' . $fname;
        }
    }

    private function _make_transparent($src, $dst) {
        $info = @getimagesize($src);
        if (!$info) { copy($src, $dst); return; }

        // GD is optional on shared hosting. Without this guard the upload path
        // fatal-errored on a host without it (K-15); the signature is then
        // simply stored as uploaded, without the transparent conversion.
        if (!function_exists('imagecreatefromjpeg')) { copy($src, $dst); return; }

        if ($info['mime'] === 'image/jpeg' || $info['mime'] === 'image/jpg') {
            $img = imagecreatefromjpeg($src);
        } elseif ($info['mime'] === 'image/png') {
            $img = imagecreatefrompng($src);
        } elseif ($info['mime'] === 'image/gif') {
            $img = imagecreatefromgif($src);
        } else { copy($src, $dst); return; }

        $w = imagesx($img); $h = imagesy($img);
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefilledrectangle($out, 0, 0, $w, $h, $transparent);
        imagealphablending($out, true);

        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >>  8) & 0xFF;
                $b =  $rgb        & 0xFF;
                $brightness = $r + $g + $b;
                if ($brightness < 80) continue; // black background → transparent
                // Darken signature pixel for visibility on white
                $nr = min(50,  (int)($r * 0.3));
                $ng = min(40,  (int)($g * 0.3));
                $nb = min(120, (int)($b * 0.5));
                $alpha = max(0, (int)(127 - $brightness / 6));
                $col = imagecolorallocatealpha($out, $nr, $ng, $nb, $alpha);
                imagesetpixel($out, $x, $y, $col);
            }
        }
        imagepng($out, $dst);
        imagedestroy($img); imagedestroy($out);
    }

    public function approve_leave($id) {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('team');
        }

        $leave = $this->Team_model->get_leave_request($id);
        if (!$leave) {
            $this->session->set_flashdata('error', 'Leave request not found.');
            redirect('team');
        }

        $status = $this->input->post('status', TRUE);
        if (!in_array($status, array('Pending', 'Approved', 'Rejected'), TRUE)) {
            $this->session->set_flashdata('error', 'Invalid leave decision.');
            redirect('team');
        }

        // Deciding leave and writing the attendance days are one action: an
        // approval that fails to reach attendance would leave the person
        // looking absent for the whole period.
        $this->db->trans_begin();

        $this->db->update('leave_requests', [
            'status'      => $status,
            'approved_by' => $this->user_id,
            'approved_at' => date('Y-m-d H:i:s'),
            'notes'       => $this->input->post('notes', TRUE),
        ], ['id' => (int)$id]);

        $days_written = 0;
        $days_removed = 0;

        if ($status === 'Approved') {
            $leave->status = 'Approved';
            $days_written  = $this->Team_model->apply_leave_to_attendance($leave, $this->user_id);
        } else {
            // A request that was approved before and is now rejected/pending
            // takes its auto-written attendance rows back out.
            $days_removed = $this->Team_model->revert_leave_attendance($leave);
        }

        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'The leave decision could not be saved. No changes were made.');
            redirect('team');
            return;
        }
        $this->db->trans_commit();

        $note = '';
        if ($days_written > 0) $note = " Attendance marked as Leave for {$days_written} day(s).";
        elseif ($days_removed > 0) $note = " {$days_removed} automatic Leave attendance row(s) removed.";

        $this->session->set_flashdata('success', "Leave request {$status}." . $note);
        redirect('team');
    }

    public function certifications($user_id = NULL) {
        if ($this->input->post() && $user_id) {
            $worker = $this->Team_model->get_by_id($user_id);
            if (!$worker) show_404();

            $name = trim((string)$this->input->post('cert_name', TRUE));
            if ($name === '') {
                $this->session->set_flashdata('error', 'A certificate name is required.');
                redirect("team/view/{$user_id}");
                return;
            }

            $issued = $this->_valid_date($this->input->post('issued_date', TRUE));
            $expiry = $this->_valid_date($this->input->post('expiry_date', TRUE));
            if ($issued && $expiry && strtotime($expiry) < strtotime($issued)) {
                $this->session->set_flashdata('error', 'The expiry date cannot be before the issue date.');
                redirect("team/view/{$user_id}");
                return;
            }

            $document = $this->_save_cert_document($user_id);

            $data = [
                'user_id'      => (int)$user_id,
                'cert_name'    => $name,
                'cert_number'  => $this->input->post('cert_number', TRUE) ?: NULL,
                'issued_date'  => $issued,
                'expiry_date'  => $expiry,
                'issuing_body' => $this->input->post('issuing_body', TRUE) ?: NULL,
                'document'     => $document,
                'status'       => 'Valid',      // refreshed from the date below
            ];

            $this->Team_model->add_certification($data);
            $this->Team_model->refresh_cert_status($user_id);

            $note = $document ? ' The document was attached.' : '';
            $this->session->set_flashdata('success', 'Certification added.' . $note);
            redirect("team/view/{$user_id}");
        }

        // The tracker recomputes every stored status before listing, so it and
        // the 60-day window below always agree.
        $this->Team_model->refresh_cert_status();
        $days = $this->_cert_alert_days();

        $data['workers']    = $this->Team_model->get_all_active();
        $data['certs']      = $this->Team_model->get_expiring_certs($days);
        $data['cert_alert_days'] = $days;
        $data['page_title'] = 'Certifications Tracker';
        $this->render('team/certifications', $data);
    }

    /** Remove a certification record (supervisor only). */
    public function delete_certification($id = NULL) {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('team');
        }

        $cert = $id ? $this->Team_model->get_certification($id) : NULL;
        if (!$cert) {
            $this->session->set_flashdata('error', 'Certification not found.');
            redirect('team/certifications');
        }

        // The stored document lives under uploads/; remove it with the record
        // rather than leaving an orphan file behind.
        if (!empty($cert->document)) {
            $path = FCPATH . ltrim($cert->document, '/');
            if (is_file($path)) @unlink($path);
        }

        $this->Team_model->delete_certification($id);
        $this->session->set_flashdata('success', 'Certification deleted.');
        redirect('team/view/' . (int)$cert->user_id);
    }

    /* ─────────────────────────────────────────────────────
       SHIFT ROSTER — week view + shift types + assignments
    ───────────────────────────────────────────────────── */

    public function shift_roster() {
        $week = $this->_roster_week($this->input->get('week'));

        $data['workers']     = $this->Team_model->get_all_active();
        $data['shifts']      = $this->Team_model->get_shifts();
        $data['assignment_counts'] = $this->Team_model->get_shift_assignment_counts();
        $data['assignments'] = $this->Team_model->get_shift_assignments($week);
        $data['week_start']  = $week;
        $data['can_edit']    = $this->is_supervisor();
        $data['page_title']  = 'Shift Roster';
        $this->render('team/shift_roster', $data);
    }

    /** Create or update a shift type. */
    public function shift_save() {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('team/shift_roster');
        }

        $id    = (int)$this->input->post('shift_id');
        $name  = trim((string)$this->input->post('name', TRUE));
        $start = trim((string)$this->input->post('start_time', TRUE));
        $end   = trim((string)$this->input->post('end_time', TRUE));
        $desc  = trim((string)$this->input->post('description', TRUE));
        $week  = $this->_roster_week($this->input->post('week'));

        $err = '';
        if ($name === '') {
            $err = 'The shift needs a name.';
        } elseif (!$this->_valid_time($start) || !$this->_valid_time($end)) {
            $err = 'Start and end time must be HH:MM.';
        } elseif ($start === $end) {
            $err = 'A shift cannot start and end at the same time.';
        }

        if ($err !== '') {
            $this->session->set_flashdata('error', $err);
            redirect('team/shift_roster?week=' . $week);
            return;
        }

        // times are stored as HH:MM(:SS) — the time column accepts either
        $this->Team_model->save_shift(array(
            'name'        => substr($name, 0, 50),
            'start_time'  => substr($start, 0, 5) . ':00',
            'end_time'    => substr($end, 0, 5) . ':00',
            'description' => $desc !== '' ? substr($desc, 0, 200) : NULL,
        ), $id ?: NULL);

        $this->session->set_flashdata('success', $id ? 'Shift updated.' : 'Shift created.');
        redirect('team/shift_roster?week=' . $week);
    }

    /** Delete a shift type that is not assigned to anybody. */
    public function shift_delete($id = NULL) {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('team/shift_roster');
        }

        $week = $this->_roster_week($this->input->post('week'));
        $shift = $id ? $this->Team_model->get_shift($id) : NULL;
        if (!$shift) {
            $this->session->set_flashdata('error', 'Shift not found.');
            redirect('team/shift_roster?week=' . $week);
        }

        if (!$this->Team_model->delete_shift($id)) {
            $this->session->set_flashdata('error',
                'This shift is still assigned on the roster. Clear the assignments first.');
        } else {
            $this->session->set_flashdata('success', 'Shift deleted.');
        }
        redirect('team/shift_roster?week=' . $week);
    }

    /** Assign one worker to a shift for the displayed week (empty = clear). */
    public function assign_shift() {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('team/shift_roster');
        }

        $week     = $this->_roster_week($this->input->post('week'));
        $user_id  = (int)$this->input->post('user_id');
        $shift_id = (int)$this->input->post('shift_id');

        $worker = $user_id ? $this->Team_model->get_by_id($user_id) : NULL;
        if (!$worker) {
            $this->session->set_flashdata('error', 'Worker not found.');
            redirect('team/shift_roster?week=' . $week);
        }

        if ($shift_id > 0 && !$this->Team_model->get_shift($shift_id)) {
            $this->session->set_flashdata('error', 'Shift not found.');
            redirect('team/shift_roster?week=' . $week);
        }

        $this->Team_model->set_shift_assignment($user_id, $shift_id ?: NULL, $week);
        $this->session->set_flashdata('success',
            $shift_id
                ? 'Roster updated for ' . $worker->first_name . ' ' . $worker->last_name . '.'
                : 'Shift cleared for ' . $worker->first_name . ' ' . $worker->last_name . '.');
        redirect('team/shift_roster?week=' . $week);
    }

    public function report() {
        $month = $this->input->get('month') ?: date('Y-m');
        $data['report']     = $this->Team_model->get_monthly_report($month);
        $data['workers']    = $this->Team_model->get_all_active();
        $data['month']      = $month;
        $data['page_title'] = 'Attendance Report';
        $this->render('team/report', $data);
    }

    /* ─────────────────────────────────────────────────────
       PRIVATE helpers
    ───────────────────────────────────────────────────── */

    /** 'Y-m-d' or NULL; rejects impossible dates such as 2026-02-30. */
    private function _valid_date($value) {
        if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $m)) {
            return NULL;
        }
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return NULL;
        return $value;
    }

    /** HH:MM (optionally HH:MM:SS) — the time inputs post the first form. */
    private function _valid_time($value) {
        return is_string($value)
            && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $value);
    }

    /**
     * The roster's week start: accepts the date form ('2026-10-19') and the
     * HTML week-input form ('2026-W42'), which is what the week picker posts.
     * Always normalised to the ISO Monday the assignments are keyed by.
     */
    private function _roster_week($value) {
        $value = is_string($value) ? trim($value) : '';
        if ($value !== '' && preg_match('/^([0-9]{4})-?[Ww]([0-9]{1,2})$/D', $value, $m)) {
            $week = (int)$m[2];
            if ($week >= 1 && $week <= 53) {
                try {
                    $d = new DateTime();
                    $d->setISODate((int)$m[1], $week, 1);
                    if ($d->format('o-\WW') === sprintf('%04d-W%02d', (int)$m[1], $week)) {
                        return $this->Team_model->week_start_date($d->format('Y-m-d'));
                    }
                } catch (Exception $e) {
                    // fall through to "this week"
                }
            }
        }
        if ($value !== '' && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            return $this->Team_model->week_start_date($value);
        }
        return $this->Team_model->week_start_date();
    }

    /** `cert_alert_days` setting (seeded at 30 days, previously unread). */
    private function _cert_alert_days() {
        $this->load->model('Settings_model');
        $v = (int)$this->Settings_model->get('cert_alert_days', 30);
        return ($v > 0 && $v <= 365) ? $v : 30;
    }

    /**
     * "This request exceeds your balance" text, or '' when it fits or the
     * type is not tracked. Never blocks the request.
     */
    private function _leave_balance_warning($user_id, $leave_type, $days) {
        foreach ($this->Team_model->get_leave_balance($user_id) as $row) {
            if ($row['type'] !== $leave_type) continue;
            if ($row['entitled'] === NULL) return '';
            if ($row['remaining'] < $days) {
                return sprintf(
                    'Note: this request (%d day(s)) exceeds the remaining %s balance (%s day(s) of %s). '
                    . 'The supervisor can still approve it.',
                    $days, $leave_type,
                    rtrim(rtrim(number_format((float)$row['remaining'], 2, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format((float)$row['entitled'], 2, '.', ''), '0'), '.')
                );
            }
            return '';
        }
        return '';
    }

    /**
     * Store an optional certificate document under uploads/documents/ and
     * return its repository-relative path, or NULL when none was posted.
     * Mirrors the upload rules used for equipment photos and user signatures.
     */
    private function _save_cert_document($user_id) {
        if (empty($_FILES['document']['name'])) return NULL;

        if (!is_dir('./uploads/documents/')) {
            @mkdir('./uploads/documents/', 0755, true);
        }

        $config = array(
            'upload_path'   => './uploads/documents/',
            'allowed_types' => 'pdf|jpg|jpeg|png',
            'max_size'      => 8000,                       // 8 MB, as other attachments
            'file_name'     => 'cert_' . (int)$user_id . '_' . time(),
        );
        // initialize() rather than load->library(): CI ignores the config when
        // the Upload class was already loaded earlier in the same request.
        $this->load->library('upload');
        $this->upload->initialize($config);

        if (!$this->upload->do_upload('document')) {
            $this->session->set_flashdata('error',
                'The certification was saved without its document: ' . $this->upload->display_errors('', ''));
            return NULL;
        }

        return 'uploads/documents/' . $this->upload->data('file_name');
    }
}
