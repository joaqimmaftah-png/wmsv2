<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Team extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Team_model');
    }

    public function index() {
        $data['workers']    = $this->Team_model->get_all();
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
        $data['attendance']   = $this->Team_model->get_attendance_history($id, 30);
        $data['certifications'] = $this->Team_model->get_certifications($id);
        $data['leave_history']= $this->Team_model->get_leave_history($id);
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

                $this->db->replace('attendance', array(
                    'user_id'    => (int)$user_id,
                    'date'       => $date,
                    'status'     => $record['status'],
                    'check_in'   => $check_in,
                    'check_out'  => $check_out,
                    'created_by' => $this->user_id,
                ));
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
            $start = $this->input->post('start_date', TRUE);
            $end   = $this->input->post('end_date', TRUE);
            $days  = (strtotime($end) - strtotime($start)) / 86400 + 1;

            $data = [
                'user_id'    => $this->user_id,
                'leave_type' => $this->input->post('leave_type', TRUE),
                'start_date' => $start,
                'end_date'   => $end,
                'days_count' => max(1, $days),
                'reason'     => $this->input->post('reason', TRUE),
                'status'     => 'Pending',
            ];

            $this->db->insert('leave_requests', $data);
            $this->session->set_flashdata('success', 'Leave request submitted.');
            redirect('team');
        }

        $data['page_title'] = 'Leave Request';
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

        $status = $this->input->post('status', TRUE);
        $this->db->update('leave_requests', [
            'status'      => $status,
            'approved_by' => $this->user_id,
            'approved_at' => date('Y-m-d H:i:s'),
            'notes'       => $this->input->post('notes', TRUE),
        ], ['id' => $id]);

        $this->session->set_flashdata('success', "Leave request {$status}.");
        redirect('team');
    }

    public function certifications($user_id = NULL) {
        if ($this->input->post() && $user_id) {
            $exp = $this->input->post('expiry_date', TRUE);
            $status = 'Valid';
            if ($exp && strtotime($exp) < time()) $status = 'Expired';
            elseif ($exp && strtotime($exp) < strtotime('+30 days')) $status = 'Expiring Soon';

            $data = [
                'user_id'      => $user_id,
                'cert_name'    => $this->input->post('cert_name', TRUE),
                'cert_number'  => $this->input->post('cert_number', TRUE),
                'issued_date'  => $this->input->post('issued_date', TRUE) ?: NULL,
                'expiry_date'  => $exp ?: NULL,
                'issuing_body' => $this->input->post('issuing_body', TRUE),
                'status'       => $status,
            ];

            $this->db->insert('certifications', $data);
            $this->session->set_flashdata('success', 'Certification added.');
            redirect("team/view/{$user_id}");
        }

        $data['workers']    = $this->Team_model->get_all_active();
        $data['certs']      = $this->Team_model->get_expiring_certs(60);
        $data['page_title'] = 'Certifications Tracker';
        $this->render('team/certifications', $data);
    }

    public function shift_roster() {
        $week = $this->input->get('week') ?: date('Y-m-d', strtotime('monday this week'));
        $data['workers']    = $this->Team_model->get_all_active();
        $data['shifts']     = $this->db->get('shifts')->result();
        $data['assignments']= $this->Team_model->get_shift_assignments($week);
        $data['week_start'] = $week;
        $data['page_title'] = 'Shift Roster';
        $this->render('team/shift_roster', $data);
    }

    public function report() {
        $month = $this->input->get('month') ?: date('Y-m');
        $data['report']     = $this->Team_model->get_monthly_report($month);
        $data['workers']    = $this->Team_model->get_all_active();
        $data['month']      = $month;
        $data['page_title'] = 'Attendance Report';
        $this->render('team/report', $data);
    }
}
