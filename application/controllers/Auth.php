<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends CI_Controller {

    public $db;
    public $lang;
    public $load;
    public $session;
    public $input;
    public $output;
    public $config;
    public $uri;
    public $router;
    public $security;
    public $utf8;
    public $form_validation;

    public function __construct() {
        parent::__construct();

        // cPanel fix: Show friendly error if DB config missing instead of 500
        if (defined('WMS_DB_MISSING') && WMS_DB_MISSING) {
            if (php_sapi_name() !== 'cli') {
                header('HTTP/1.1 500 Internal Server Error');
                echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Setup Required — WMS</title>';
                echo '<style>body{font-family:system-ui;max-width:700px;margin:40px auto;padding:20px;background:#f5f5f7} .box{background:#fff;padding:30px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.1)} h1{color:#c0392b} code{background:#f5f5f7;padding:2px 6px;border-radius:4px} pre{background:#1d1d1f;color:#f5f5f7;padding:15px;border-radius:8px;overflow:auto}</style>';
                echo '</head><body><div class="box">';
                echo '<h1>🗄️ Database Setup Required</h1>';
                echo '<p>This is why you see <strong>HTTP ERROR 500</strong> on cPanel.</p>';
                echo '<p>The file <code>application/config/database.local.php</code> is missing or has empty credentials.</p>';
                echo '<h3>Fix:</h3><ol>';
                echo '<li>Copy <code>database.local.php.example</code> to <code>database.local.php</code></li>';
                echo '<li>Edit with cPanel DB credentials (localhost, cpaneluser_dbuser, cpaneluser_dbname)</li>';
                echo '<li>cPanel > MySQL Databases > Add user to DB with ALL PRIVILEGES</li>';
                echo '<li>Import <code>database/yzzme_43038742_aml.sql</code> via phpMyAdmin</li>';
                echo '</ol>';
                echo '</div></body></html>';
                exit;
            } else {
                echo "Database configuration missing. Create application/config/database.local.php\n";
                exit(1);
            }
        }

        $this->load->library(['session', 'form_validation']);
        $this->load->helper(['url', 'form']);
        $this->load->database();
    }

    public function login() {
        if ($this->session->userdata('user_id')) {
            redirect('dashboard');
        }

        $data = ['error' => ''];

        if ($this->input->post()) {
            $this->form_validation->set_rules('email',    'Email',    'required|valid_email');
            $this->form_validation->set_rules('password', 'Password', 'required|min_length[4]');

            if ($this->form_validation->run()) {
                $email    = $this->input->post('email',    TRUE);
                $password = $this->input->post('password', TRUE);

                $this->load->model('Auth_throttle_model');

                if ($this->Auth_throttle_model->is_locked($email)) {
                    // Deliberately the same wording as a failed sign-in: a locked
                    // account must not be distinguishable from a wrong password.
                    $data['error'] = 'Invalid email or password.';
                } else {
                    // get() answers FALSE when the query itself fails (database
                    // down, `users` not imported, no SELECT grant). Calling
                    // ->row() on FALSE is an Error, so an unusable database used
                    // to produce a blank HTTP 500 here — with the password form
                    // still looking healthy. Say what is actually wrong instead.
                    $query = $this->db->where('email', $email)
                                      ->where('status !=', 'Inactive')
                                      ->get('users');

                    if ($query === FALSE) {
                        log_message('error', 'WMS: the sign-in lookup on `users` failed — the database is '
                            .'unreachable, or the schema has not been imported into it.');

                        $data['error'] = 'The workshop database is not answering. '
                            .'If this installation was just set up, import the database dump shipped '
                            .'with this repository (database/yzzme_43038742_aml.sql) and reload this page.';
                        $this->load->view('auth/login', $data);
                        return;
                    }

                    $user = $query->row();

                    if ($user) {
                        $valid = FALSE;

                        // 1. bcrypt — the only format new hashes use
                        //    (PASSWORD_DEFAULT / PASSWORD_BCRYPT).
                        if (password_verify($password, $user->password)) {
                            $valid = TRUE;
                        }
                        // 2. Legacy MD5, accepted only while legacy_password_login
                        //    is TRUE; the hash is upgraded on this successful sign-in.
                        //    Set that switch to FALSE once every legacy hash has
                        //    been upgraded.
                        elseif ($this->config->item('legacy_password_login')
                                && md5($password) === $user->password) {
                            $valid = TRUE;
                            $this->db->update('users',
                                ['password' => password_hash($password, PASSWORD_DEFAULT)],
                                ['id' => $user->id]
                            );
                        }
                        // 3. Legacy SHA1, same condition as above.
                        elseif ($this->config->item('legacy_password_login')
                                && sha1($password) === $user->password) {
                            $valid = TRUE;
                            $this->db->update('users',
                                ['password' => password_hash($password, PASSWORD_DEFAULT)],
                                ['id' => $user->id]
                            );
                        }

                        if ($valid) {
                            $this->Auth_throttle_model->clear($email);

                            $this->db->update('users',
                                ['last_login' => date('Y-m-d H:i:s')],
                                ['id' => $user->id]
                            );
                            $user->last_login = date('Y-m-d H:i:s');

                            $this->session->set_userdata([
                                'user_id'   => $user->id,
                                'role_id'   => $user->role_id,
                                'user_data' => $user,
                            ]);

                            // Session fixation: keep the session data, change the
                            // id, and destroy the record that existed before this
                            // sign-in (sess_regenerate_destroy = TRUE).
                            $this->session->sess_regenerate(TRUE);

                            redirect('dashboard');
                        } else {
                            $this->Auth_throttle_model->record_failure($email);
                            $data['error'] = 'Invalid email or password.';
                        }
                    } else {
                        $this->Auth_throttle_model->record_failure($email);
                        $data['error'] = 'Invalid email or password.';
                    }
                }
            }
        }

        $this->load->view('auth/login', $data);
    }

    public function logout() {
        if (strtolower($this->input->method()) !== 'post') {
            $this->output->set_status_header(405);
            $this->output->set_header('Allow: POST');
            $this->output->set_content_type('text/plain', 'utf-8');
            $this->output->set_output('Method Not Allowed');
            return;
        }

        $this->session->sess_destroy();
        redirect('login');
    }
}
