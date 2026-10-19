<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Users extends MY_Controller {

    public function __construct() {
        parent::__construct();
        // profile() and change_password() are available to ALL logged-in users
        $allowed_for_all = ['profile', 'change_password'];
        $method = $this->router->fetch_method();
        if (!in_array($method, $allowed_for_all) && $this->role_id != 1) {
            $this->session->set_flashdata('error', 'Access denied. Supervisor only.');
            redirect('dashboard');
        }
        $this->load->model('Team_model');
    }

    // ── List all users ──────────────────────────────────────────
    public function index() {
        $filter = array(
            'role_id' => $this->input->get('role'),
            'status'  => $this->input->get('status'),
            'search'  => $this->input->get('q'),
        );

        $q = $this->db->select('u.*, r.name as role_name')
            ->from('users u')
            ->join('roles r', 'r.id = u.role_id');

        if (!empty($filter['role_id'])) $q->where('u.role_id', $filter['role_id']);
        if (!empty($filter['status']))  $q->where('u.status', $filter['status']);
        if (!empty($filter['search'])) {
            $s = $filter['search'];
            $q->group_start()
              ->like('u.first_name', $s)->or_like('u.last_name', $s)
              ->or_like('u.email', $s)->or_like('u.employee_id', $s)
              ->group_end();
        }

        $data['users']      = $q->order_by('u.first_name', 'ASC')->get()->result();
        $data['roles']      = $this->db->get('roles')->result();
        $data['filter']     = $filter;
        $data['stats']      = $this->_get_stats();
        $data['page_title'] = 'User Access Management';
        $this->render('users/index', $data);
    }

    // ── Create user ─────────────────────────────────────────────
    public function create() {
        if ($this->input->post()) {
            $this->form_validation->set_rules('first_name',  'First Name',  'required|trim');
            $this->form_validation->set_rules('last_name',   'Last Name',   'required|trim');
            $this->form_validation->set_rules('email',       'Email',       'required|valid_email|is_unique[users.email]');
            $this->form_validation->set_rules('employee_id', 'Employee ID', 'required|trim|is_unique[users.employee_id]');
            $this->form_validation->set_rules('password',    'Password',    'required|min_length[8]');
            $this->form_validation->set_rules('role_id',     'Role',        'required|integer');

            if ($this->form_validation->run()) {
                $data = array(
                    'role_id'     => $this->input->post('role_id', TRUE),
                    'employee_id' => strtoupper($this->input->post('employee_id', TRUE)),
                    'first_name'  => $this->input->post('first_name', TRUE),
                    'last_name'   => $this->input->post('last_name', TRUE),
                    'email'       => strtolower($this->input->post('email', TRUE)),
                    'password'    => password_hash($this->input->post('password', TRUE), PASSWORD_DEFAULT),
                    'phone'       => $this->input->post('phone', TRUE),
                    'position'    => $this->input->post('position', TRUE),
                    'skill_level' => $this->input->post('skill_level', TRUE) ?: 'Junior',
                    'join_date'   => $this->input->post('join_date', TRUE) ?: NULL,
                    'status'      => 'Active',
                );
                $this->db->insert('users', $data);
                $this->session->set_flashdata('success', 'User ' . $data['first_name'] . ' ' . $data['last_name'] . ' created successfully.');
                redirect('users');
            }
            $data['form_data'] = $this->input->post();
        }

        $data['roles']      = $this->db->get('roles')->result();
        $data['edit_mode']  = FALSE;
        $data['page_title'] = 'Create New User';
        $this->render('users/form', $data);
    }

    // ── Edit user ───────────────────────────────────────────────
    public function edit($id) {
        $user = $this->db->select('u.*, r.name as role_name')
            ->from('users u')->join('roles r', 'r.id = u.role_id')
            ->where('u.id', $id)->get()->row();
        if (!$user) show_404();

        if ($this->input->post()) {
            $this->form_validation->set_rules('first_name', 'First Name', 'required|trim');
            $this->form_validation->set_rules('last_name',  'Last Name',  'required|trim');
            $this->form_validation->set_rules('email',      'Email',      'required|valid_email');
            $this->form_validation->set_rules('role_id',    'Role',       'required|integer');

            if ($this->form_validation->run()) {
                // Check email unique (excluding self)
                $dup = $this->db->where('email', $this->input->post('email', TRUE))->where('id !=', $id)->count_all_results('users');
                if ($dup > 0) {
                    $this->session->set_flashdata('error', 'Email is already in use by another account.');
                    redirect("users/edit/{$id}");
                }

                $update = array(
                    'role_id'     => $this->input->post('role_id', TRUE),
                    'first_name'  => $this->input->post('first_name', TRUE),
                    'last_name'   => $this->input->post('last_name', TRUE),
                    'email'       => strtolower($this->input->post('email', TRUE)),
                    'phone'       => $this->input->post('phone', TRUE),
                    'position'    => $this->input->post('position', TRUE),
                    'skill_level' => $this->input->post('skill_level', TRUE) ?: 'Junior',
                    'status'      => $this->input->post('status', TRUE),
                );

                // Optional password change
                $new_pw = $this->input->post('new_password', TRUE);
                if ($new_pw && strlen($new_pw) >= 8) {
                    $update['password'] = password_hash($new_pw, PASSWORD_DEFAULT);
                } elseif ($new_pw && strlen($new_pw) < 8) {
                    $this->session->set_flashdata('error', 'New password must be at least 8 characters.');
                    redirect("users/edit/{$id}");
                }

                $this->db->update('users', $update, array('id' => $id));
                $this->session->set_flashdata('success', 'User updated successfully.');
                redirect('users');
            }
            $data['form_data'] = $this->input->post();
        }

        $data['user']       = $user;
        $data['roles']      = $this->db->get('roles')->result();
        $data['edit_mode']  = TRUE;
        $data['page_title'] = 'Edit User: ' . $user->first_name . ' ' . $user->last_name;
        $this->render('users/form', $data);
    }

    // ── Toggle active / inactive ────────────────────────────────
    public function toggle_status($id) {
        // F-04: this changes an account's status, so it is a POST — and with
        // global CSRF protection on, a POST also has to carry the token.
        // As a GET it could be fired by an <img> tag on any page a supervisor
        // happened to open, which is a state change nobody asked for.
        if (!$this->require_post()) return;

        // Prevent deactivating yourself
        if ($id == $this->user_id) {
            $this->session->set_flashdata('error', 'You cannot deactivate your own account.');
            redirect('users');
        }
        $user = $this->db->where('id', $id)->get('users')->row();
        if (!$user) show_404();

        $new_status = ($user->status === 'Active') ? 'Inactive' : 'Active';
        $this->db->update('users', array('status' => $new_status), array('id' => $id));
        $verb = ($new_status === 'Active') ? 'activated' : 'deactivated';
        $this->session->set_flashdata('success', $user->first_name . ' ' . $user->last_name . ' has been ' . $verb . '.');
        redirect('users');
    }

    // ── Change role instantly ───────────────────────────────────
    public function change_role($id) {
        if ($id == $this->user_id) {
            $this->session->set_flashdata('error', 'You cannot change your own role.');
            redirect('users');
        }
        $role_id = (int)$this->input->post('role_id', TRUE);
        if ($role_id < 1 || $role_id > 5) {
            $this->session->set_flashdata('error', 'Invalid role.');
            redirect('users');
        }
        $this->db->update('users', array('role_id' => $role_id), array('id' => $id));
        $this->session->set_flashdata('success', 'Role updated successfully.');
        redirect('users');
    }

    // ── Reset password (admin force-reset) ──────────────────────
    public function reset_password($id) {
        $user = $this->db->where('id', $id)->get('users')->row();
        if (!$user) show_404();

        if ($this->input->post()) {
            $pw = $this->input->post('new_password', TRUE);
            if (strlen($pw) < 8) {
                $this->session->set_flashdata('error', 'Password must be at least 8 characters.');
                redirect("users/reset_password/{$id}");
            }
            $this->db->update('users', array('password' => password_hash($pw, PASSWORD_DEFAULT)), array('id' => $id));
            $this->session->set_flashdata('success', 'Password reset for ' . $user->first_name . '.');
            redirect('users');
        }

        $data['target_user'] = $user;
        $data['page_title']  = 'Reset Password: ' . $user->first_name . ' ' . $user->last_name;
        $this->render('users/reset_password', $data);
    }

    // ── My Profile (any logged-in user) ────────────────────────
    public function profile() {
        $me = $this->db->where('id', $this->user_id)->get('users')->row();
        if (!$me) show_404();

        if ($this->input->post()) {
            $update = array(
                'phone'    => $this->input->post('phone',    TRUE),
                'position' => $this->input->post('position', TRUE),
            );

            // Handle drawn signature (base64 PNG)
            $sig_data = $this->input->post('signature_data', FALSE);
            if ($sig_data && strpos($sig_data, 'data:image/png;base64,') === 0) {
                if (!is_dir('./uploads/signatures/')) {
                    @mkdir('./uploads/signatures/', 0755, true);
                }
                $base64 = substr($sig_data, strlen('data:image/png;base64,'));
                $bytes  = base64_decode($base64);
                if ($bytes && strlen($bytes) > 100) {
                    $fname  = 'sig_user_' . $this->user_id . '.png';
                    $path   = './uploads/signatures/' . $fname;
                    file_put_contents($path, $bytes);
                    $update['signature'] = 'uploads/signatures/' . $fname;
                }
            }

            // Handle profile photo upload
            if (!empty($_FILES['photo']['name'])) {
                $cfg = array(
                    'upload_path'   => './uploads/photos/',
                    'allowed_types' => 'jpg|jpeg|png|gif',
                    'max_size'      => 2000,
                    'file_name'     => 'user_' . $this->user_id . '_' . time(),
                );
                $this->load->library('upload', $cfg);
                if ($this->upload->do_upload('photo')) {
                    $update['photo'] = $this->upload->data('file_name');
                }
            }

            $this->db->update('users', $update, array('id' => $this->user_id));

            // Refresh session user_data
            $refreshed = $this->db->where('id', $this->user_id)->get('users')->row();
            $this->session->set_userdata('user_data', $refreshed);

            $this->session->set_flashdata('success', 'Profile updated successfully.');
            redirect('users/profile');
        }

        $data['me']         = $me;
        $data['page_title'] = 'My Profile';
        $this->render('users/profile', $data);
    }

    // ── Self password change ────────────────────────────────────
    public function change_password() {
        // Any logged-in user can change their own password
        if ($this->input->post()) {
            $current  = $this->input->post('current_password', TRUE);
            $new      = $this->input->post('new_password', TRUE);
            $confirm  = $this->input->post('confirm_password', TRUE);

            $me = $this->db->where('id', $this->user_id)->get('users')->row();

            if (!password_verify($current, $me->password)) {
                $this->session->set_flashdata('error', 'Current password is incorrect.');
                redirect('users/change_password');
            }
            if ($new !== $confirm) {
                $this->session->set_flashdata('error', 'New passwords do not match.');
                redirect('users/change_password');
            }
            if (strlen($new) < 8) {
                $this->session->set_flashdata('error', 'New password must be at least 8 characters.');
                redirect('users/change_password');
            }

            $this->db->update('users', array('password' => password_hash($new, PASSWORD_DEFAULT)), array('id' => $this->user_id));
            $this->session->set_flashdata('success', 'Password changed successfully.');
            redirect('dashboard');
        }

        $data['page_title'] = 'Change Password';
        $this->render('users/change_password', $data);
    }

    // ── Delete user ─────────────────────────────────────────────
    public function delete($id) {
        // F-04: same class of endpoint as toggle_status — a write, so POST only.
        // (Nothing in the interface links here; it survives as an endpoint and
        // now refuses a GET.)
        if (!$this->require_post()) return;

        if ($id == $this->user_id) {
            $this->session->set_flashdata('error', 'You cannot delete your own account.');
            redirect('users');
        }

        $user = $this->db->where('id', $id)->get('users')->row();
        if (!$user) show_404();

        // Safety: deactivate instead of hard delete (preserves WO history)
        $this->db->update('users', array('status' => 'Inactive'), array('id' => $id));
        $this->session->set_flashdata('success', $user->first_name . ' ' . $user->last_name . ' has been deactivated (records preserved).');
        redirect('users');
    }

    // ── Stats helper ────────────────────────────────────────────
    private function _get_stats() {
        $total    = $this->db->count_all('users');
        $active   = $this->db->where('status', 'Active')->count_all_results('users');
        $inactive = $this->db->where('status', 'Inactive')->count_all_results('users');
        $by_role  = $this->db->select('r.name, COUNT(u.id) as cnt')
            ->from('users u')->join('roles r', 'r.id = u.role_id')
            ->group_by('u.role_id')->get()->result();
        return array('total' => $total, 'active' => $active, 'inactive' => $inactive, 'by_role' => $by_role);
    }
}
