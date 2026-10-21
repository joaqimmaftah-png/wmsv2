<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Team extends MY_Controller {

    public $Attendance_model;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Team_model', 'Attendance_model'));
    }

    public function index() {
        $data['workers']    = $this->Team_model->get_all();
        // Leave requests waiting for a decision (supervisors only — the
        // approve/reject action is guarded as well, this just hides the panel).
        $data['pending_leave'] = $this->is_supervisor()
            ? $this->Team_model->get_pending_leave_requests()
            : array();
        // "Add Worker" is supervisor-only (Team::create enforces it too); hide
        // the button from the roles that would only be redirected.
        $data['is_supervisor'] = $this->is_supervisor();
        $data['page_title'] = 'Team Management';
        $this->render('team/index', $data);
    }

    public function create() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('team');
        }

        if ($this->input->post()) {
            $post   = $this->input->post();
            $errors = $this->_worker_errors($post, NULL);

            if (empty($errors)) {
                $field = function ($key) use ($post) {
                    return isset($post[$key]) && is_scalar($post[$key]) ? trim((string)$post[$key]) : '';
                };
                $data = array(
                    'role_id'     => (int)$field('role_id'),
                    'employee_id' => strtoupper((string)($field('employee_id'))),
                    'first_name'  => $field('first_name'),
                    'last_name'   => $field('last_name'),
                    'email'       => strtolower((string)($field('email'))),
                    'password'    => password_hash($field('password'), PASSWORD_DEFAULT),
                    'phone'       => $field('phone') !== '' ? $field('phone') : NULL,
                    'position'    => $field('position') !== '' ? $field('position') : NULL,
                    'skill_level' => in_array($field('skill_level'), array('Junior','Senior','Expert','Specialist'), TRUE)
                                        ? $field('skill_level') : 'Junior',
                    'join_date'   => $field('join_date') !== '' ? $field('join_date') : NULL,
                    'status'      => 'Active',
                );

                $photo = $this->_upload_photo();
                if ($photo) $data['photo'] = $photo;
                if (!empty($_FILES['signature']['name'])) $this->_save_signature($data);

                // `users.email` and `users.employee_id` are UNIQUE, so an insert
                // can fail even after the checks above (a race, or a value the
                // checks could not see). The old code flashed success anyway and
                // left the supervisor on a worker that did not exist.
                $id = $this->Team_model->create($data);
                if ($id) {
                    $this->session->set_flashdata('success',
                        'Worker ' . $data['first_name'] . ' ' . $data['last_name'] . ' added.');
                    redirect('team/view/' . (int)$id);
                    return;
                }
                $errors[] = 'The worker could not be saved. Nothing was written — check the values and try again.';
            }

            $posted = (object)$post;
            $data = array(
                'errors'     => $errors,
                'worker'     => $posted,
                'roles'      => $this->db->get('roles')->result(),
                'is_edit'    => FALSE,
                'page_title' => 'Add Worker',
            );
            $this->render('team/form', $data);
            return;
        }

        $data['roles']      = $this->db->get('roles')->result();
        $data['is_edit']    = FALSE;
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
        // The view tests this flag for the edit / certification controls.
        // (`role_id` is injected by render() as well; an explicit flag keeps
        // the intent local instead of comparing the layout's global.)
        $data['is_supervisor'] = $this->is_supervisor();
        $data['page_title']   = $data['worker']->first_name . ' ' . $data['worker']->last_name;
        $this->render('team/view', $data);
    }

    public function attendance() {
        if (strtolower((string)($this->input->method())) === 'post') {
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

            // The `attendance.status` enum also has Holiday; the form did not
            // offer it and the whitelist rejected it, so a public holiday
            // could never be recorded (and would arrive as an error if it was).
            $allowed_statuses = array('Present', 'Absent', 'Leave', 'Rest', 'Holiday');
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
        // Read-only cross-check: the punch-derived on-site state. The times in
        // the table below are the day row, which a punch keeps in sync, so the
        // two can never tell different stories.
        $data['on_site']    = $this->Attendance_model->on_site_map($date);
        $data['punching']   = $this->Attendance_model->ready();
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
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('team');
        }

        $worker = $this->Team_model->get_by_id($id);
        if (!$worker) show_404();

        if ($this->input->post()) {
            $post   = $this->input->post();
            $errors = $this->_worker_errors($post, $id);

            if (empty($errors)) {
                $field = function ($key) use ($post) {
                    return isset($post[$key]) && is_scalar($post[$key]) ? trim((string)$post[$key]) : '';
                };
                $update = array(
                    'role_id'     => (int)$field('role_id'),
                    'first_name'  => $field('first_name'),
                    'last_name'   => $field('last_name'),
                    'email'       => strtolower((string)($field('email'))),
                    'phone'       => $field('phone') !== '' ? $field('phone') : NULL,
                    'position'    => $field('position') !== '' ? $field('position') : NULL,
                    'skill_level' => in_array($field('skill_level'), array('Junior','Senior','Expert','Specialist'), TRUE)
                                        ? $field('skill_level') : $worker->skill_level,
                    'status'      => in_array($field('status'), array('Active','Inactive','On Leave'), TRUE)
                                        ? $field('status') : $worker->status,
                    'join_date'   => $field('join_date') !== '' ? $field('join_date') : NULL,
                );

                // A blank password box means "keep the current password" (the
                // form says so); only a typed value is hashed.
                if ($field('password') !== '') {
                    $update['password'] = password_hash($field('password'), PASSWORD_DEFAULT);
                }

                $photo = $this->_upload_photo();
                if ($photo) $update['photo'] = $photo;
                if (!empty($_FILES['signature']['name'])) $this->_save_signature($update, $id);

                if ($this->db->where('id', (int)$id)->update('users', $update) === FALSE) {
                    $errors[] = 'The changes could not be saved. Nothing was written.';
                } else {
                    $this->session->set_flashdata('success', 'Profile updated.');
                    redirect('team/view/' . (int)$id);
                    return;
                }
            }

            // Keep what was typed; fall back to the stored row for anything the
            // browser did not send (a disabled field, an unchecked control), so
            // re-rendering the form cannot silently reset a value.
            $posted = (object)array_merge(array(
                'id'          => (int)$worker->id,
                'employee_id' => $worker->employee_id,
                'photo'       => $worker->photo,
                'role_id'     => $worker->role_id,
                'status'      => $worker->status,
                'skill_level' => $worker->skill_level,
                'join_date'   => $worker->join_date,
                'phone'       => $worker->phone,
                'position'    => $worker->position,
            ), $post);

            $data = array(
                'errors'     => $errors,
                'worker'     => $posted,
                'roles'      => $this->db->get('roles')->result(),
                'is_edit'    => TRUE,
                'page_title' => 'Edit — ' . $worker->first_name . ' ' . $worker->last_name,
            );
            $this->render('team/form', $data);
            return;
        }

        $data['worker']     = $worker;
        $data['roles']      = $this->db->get('roles')->result();
        $data['is_edit']    = TRUE;
        $data['page_title'] = 'Edit — ' . $worker->first_name . ' ' . $worker->last_name;
        $this->render('team/form', $data);
    }

    /**
     * Validate a worker create/edit POST.
     *
     * The Team screens had no rules at all: a blank name or a duplicate email
     * reached the INSERT, which failed, and the page still said "Worker added"
     * (the Team half of K-09). These are the checks Users already applied —
     * required names, a valid unique email, a real role, a unique employee ID
     * on create and an 8-character minimum password.
     *
     * @param  array    $post    the posted fields
     * @param  int|null $user_id the worker being edited (NULL on create)
     * @return string[] human-readable problems, empty when the form is valid
     */
    private function _worker_errors($post, $user_id = NULL) {
        $errors = array();

        $first = trim((string)(isset($post['first_name']) ? (string)$post['first_name'] : ''));
        $last  = trim((string)(isset($post['last_name'])  ? (string)$post['last_name']  : ''));
        if ($first === '' || $last === '') {
            $errors[] = 'First name and last name are required.';
        }

        $email = strtolower((string)(trim((string)(isset($post['email']) ? (string)$post['email'] : ''))));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        } else {
            $this->db->where('email', $email);
            if ($user_id) $this->db->where('id !=', (int)$user_id);
            if ($this->db->count_all_results('users') > 0) {
                $errors[] = 'Another worker already uses ' . $email . '.';
            }
        }

        $role = isset($post['role_id']) ? (int)$post['role_id'] : 0;
        if ($role <= 0 || $this->db->where('id', $role)->count_all_results('roles') === 0) {
            $errors[] = 'Choose a valid role.';
        }

        if (!empty($post['join_date']) && !$this->_valid_date($post['join_date'])) {
            $errors[] = 'The join date is not a valid date.';
        }

        $pw = isset($post['password']) ? (string)$post['password'] : '';

        if (!$user_id) {
            $emp = strtoupper((string)(trim((string)(isset($post['employee_id']) ? (string)$post['employee_id'] : ''))));
            if ($emp === '') {
                $errors[] = 'An employee ID is required.';
            } elseif ($this->db->where('employee_id', $emp)->count_all_results('users') > 0) {
                $errors[] = 'Employee ID ' . $emp . ' is already in use.';
            }
            if (strlen((string)($pw)) < 8) {
                $errors[] = 'The password must be at least 8 characters.';
            }
        } else {
            if ($pw !== '' && strlen((string)($pw)) < 8) {
                $errors[] = 'A new password must be at least 8 characters, or left blank to keep the current one.';
            }
            if (isset($post['status']) && !in_array($post['status'], array('Active', 'Inactive', 'On Leave'), TRUE)) {
                $errors[] = 'Choose a valid status.';
            }
        }

        return $errors;
    }

    /** Store an optional worker photo; NULL when none was posted or it failed. */
    private function _upload_photo() {
        if (empty($_FILES['photo']['name'])) return NULL;

        if (!is_dir('./uploads/photos/')) {
            @mkdir('./uploads/photos/', 0755, true);
        }
        // initialize(), not load->library($config): CI ignores the config when
        // the Upload class is already loaded in this request (it is loaded for
        // the signature as well), which silently applied the wrong rules.
        $this->load->library('upload');
        $this->upload->initialize(array(
            'upload_path'   => './uploads/photos/',
            'allowed_types' => 'gif|jpg|jpeg|png',
            'max_size'      => 2000,
            'file_name'     => 'user_' . time(),
        ));

        if (!$this->upload->do_upload('photo')) {
            $this->session->set_flashdata('error',
                'The worker was saved without a photo: ' . $this->upload->display_errors('', ''));
            return NULL;
        }
        return $this->upload->data('file_name');
    }

    /** Upload signature, process to transparent PNG, save path in $data['signature'] */
    private function _save_signature(&$data, $user_id = null) {
        if (!is_dir('./uploads/signatures/')) mkdir('./uploads/signatures/', 0755, true);
        // The timestamp alone collided: two anonymous uploads in the same second
        // got the same name and the second thumbnail overwrote the first, so both
        // workers ended up pointing at one signature.
        try {
            $token = bin2hex(random_bytes(4));
        } catch (Exception $e) {
            $token = substr(md5(uniqid('', true)), 0, 8);
        }
        $fname = 'sig_'.($user_id ?: 'new').'_'.time().'_'.$token.'.png';
        $config = [
            'upload_path'   => './uploads/signatures/',
            'allowed_types' => 'jpg|jpeg|png|gif',
            'max_size'      => 3000,
            'file_name'     => pathinfo($fname, PATHINFO_FILENAME),
        ];
        // initialize(): the Upload class is shared with the photo/webcam paths,
        // and load->library($config) is ignored once it is loaded.
        $this->load->library('upload');
        $this->upload->initialize($config);
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
        // The Add control on the worker page is supervisor-only (like delete and
        // like team create/edit). The endpoint had no guard, so a crafted POST
        // could still add a certificate to anyone's record.
        if ($this->input->post() && !$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('team');
        }

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

            if (!$this->Team_model->add_certification($data)) {
                // The certificate document was already written to uploads/;
                // drop it rather than leaving an orphan behind a failed insert.
                if ($document) {
                    $path = FCPATH . ltrim((string)($document), '/');
                    if (is_file($path)) @unlink($path);
                }
                $this->session->set_flashdata('error',
                    'The certification could not be saved. Nothing was written — check the values and try again.');
                redirect("team/view/{$user_id}");
                return;
            }
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

        // Delete the row first: the file was removed before the DELETE used to
        // run, so a failed delete left a record pointing at a missing document.
        if (!$this->Team_model->delete_certification($id)) {
            $this->session->set_flashdata('error',
                'The certification could not be deleted. Nothing was removed.');
            redirect('team/view/' . (int)$cert->user_id);
            return;
        }

        // The stored document lives under uploads/; remove it with the record
        // rather than leaving an orphan file behind.
        if (!empty($cert->document)) {
            $path = FCPATH . ltrim((string)($cert->document), '/');
            if (is_file($path)) @unlink($path);
        }

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
        $saved = $this->Team_model->save_shift(array(
            'name'        => substr($name, 0, 50),
            'start_time'  => substr($start, 0, 5) . ':00',
            'end_time'    => substr($end, 0, 5) . ':00',
            'description' => $desc !== '' ? substr($desc, 0, 200) : NULL,
        ), $id ?: NULL);

        if (!$saved) {
            $this->session->set_flashdata('error', 'The shift could not be saved. Nothing was written.');
            redirect('team/shift_roster?week=' . $week);
            return;
        }

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
        $month = $this->input->get('month');
        // A malformed month reached strtotime(), which returned false and turned
        // the report into January 1970 while the header still showed the input.
        if (!is_string($month) || !preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/D', $month)) {
            $month = date('Y-m');
        }
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
        $value = is_string($value) ? trim((string)($value)) : '';
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
                    rtrim((string)(rtrim((string)(number_format((float)$row['remaining'], 2, '.', '')), '0')), '.'),
                    rtrim((string)(rtrim((string)(number_format((float)$row['entitled'], 2, '.', '')), '0')), '.')
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
