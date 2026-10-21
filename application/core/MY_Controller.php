<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Explicitly declare all properties that CodeIgniter 3 assigns dynamically.
| Required for PHP 8.2+ ("creation of dynamic properties" deprecation).
| Fully backward-compatible with PHP 7.x.
*/
class MY_Controller extends CI_Controller {

    // --- Our own properties ---
    protected $user_id   = null;
    protected $role_id   = null;
    protected $user_data = null;

    // --- CI3 core properties (suppress PHP 8.2 E_DEPRECATED) ---
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
    public $benchmark;
    public $hooks;
    public $cache;

    // --- Model properties (assigned by $this->load->model()) ---
    public $Dashboard_model;
    public $Workorder_model;
    public $Equipment_model;
    public $Team_model;
    public $Lubricant_model;
    public $Pm_model;
    public $Materials_model;
    public $Supplier_model;
    public $Order_model;
    public $Inspection_model;
    public $Asset_model;
    public $Spareparts_model;
    public $Backlog_model;
    public $Kpi_target_model;
    public $W36_report_model;
    public $Settings_model;
    public $Store_model;
    public $Forecast_model;
    public $Maintenance_plan_model;
    public $Kpi_engine_model;
    public $Auth_throttle_model;

    /**
     * PHP 8.x parameter guard: CodeIgniter 3 dispatches URL segments directly
     * via call_user_func_array(). On PHP 7 missing URL segments emitted a
     * warning and hit show_404(); on PHP 8+ they throw an uncaught
     * ArgumentCountError (HTTP 500). Return a clean 404 instead whenever a
     * non-public method or missing required segment is requested.
     */
    public function _remap($method, $params = array()) {
        if (!method_exists($this, $method) || $method === '_remap' || $method[0] === '_') {
            show_404();
            return;
        }
        $ref = new ReflectionMethod($this, $method);
        if (!$ref->isPublic() || count($params) < $ref->getNumberOfRequiredParameters()) {
            show_404();
            return;
        }
        return call_user_func_array(array($this, $method), $params);
    }

    public function __construct() {
        parent::__construct();

        // cPanel fix: If database credentials missing, show friendly error instead of 500
        if (defined('WMS_DB_MISSING') && WMS_DB_MISSING) {
            $this->_show_db_config_error();
            return;
        }

        // Also check if DB connection actually failed (ci_sessions table missing etc)
        if (!$this->db || !$this->db->conn_id) {
            // Let CI's error_db handle it, but log helpful message
            error_log('WMS: Database connection failed - check database.local.php and ensure DB imported');
        }

        $this->_check_auth();
        $this->_enforce_access();
        $this->_load_settings();
    }

    private function _show_db_config_error() {
        // Only show for web requests
        if (php_sapi_name() === 'cli') {
            echo "Database configuration missing. Create application/config/database.local.php\n";
            exit(1);
        }

        // Try to load a view, fallback to plain HTML if view system not ready
        $message = 'Database credentials missing. Create application/config/database.local.php from database.local.php.example';

        // Plain HTML fallback that always works
        header('HTTP/1.1 500 Internal Server Error');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Setup Required — WMS</title>';
        echo '<style>body{font-family:system-ui;max-width:700px;margin:40px auto;padding:20px;background:#f5f5f7} .box{background:#fff;padding:30px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.1)} h1{color:#c0392b} code{background:#f5f5f7;padding:2px 6px;border-radius:4px} pre{background:#1d1d1f;color:#f5f5f7;padding:15px;border-radius:8px;overflow:auto}</style>';
        echo '</head><body><div class="box">';
        echo '<h1>🗄️ Database Setup Required</h1>';
        echo '<p>This is why you see <strong>HTTP ERROR 500</strong> on cPanel.</p>';
        echo '<p>The file <code>application/config/database.local.php</code> is missing or has empty credentials.</p>';
        echo '<h3>Fix in 2 minutes:</h3>';
        echo '<ol>';
        echo '<li>In File Manager, copy <code>application/config/database.local.php.example</code> to <code>database.local.php</code> (same folder)</li>';
        echo '<li>Edit <code>database.local.php</code> with your cPanel DB credentials:<br>';
        echo '<pre>';
        echo htmlspecialchars((string)("<?php\ndefined('BASEPATH') OR exit('No direct script access allowed');\n\n\$db['default']['hostname'] = 'localhost';\n\$db['default']['username'] = 'cpaneluser_dbuser';\n\$db['default']['password'] = 'your_password';\n\$db['default']['database'] = 'cpaneluser_dbname';\n"));
        echo '</pre></li>';
        echo '<li>cPanel > MySQL Databases: Create DB + User + <strong>Add user to DB with ALL PRIVILEGES</strong></li>';
        echo '<li>cPanel > phpMyAdmin: Import <code>database/schema.sql</code></li>';
        echo '</ol>';
        echo '<p><strong>cPanel credentials format:</strong><br>';
        echo 'Username: <code>cpaneluser_dbuser</code> (cPanel prefixes your username)<br>';
        echo 'Database: <code>cpaneluser_dbname</code><br>';
        echo 'Host: <code>localhost</code> (not 127.0.0.1 on most shared hosts)</p>';
        echo '<p style="font-size:12px;color:#86868b">See README.md (Installation / Troubleshooting) for the detailed guide.</p>';
        echo '</div></body></html>';
        exit;
    }

    /*
    | ─────────────────────────────────────────────────────────
    |  ROLE-BASED ACCESS CONTROL
    | ─────────────────────────────────────────────────────────
    |  Role IDs: 1=Supervisor 2=Technician 3=Store 4=Manager 5=CEO
    |
    |  VIEW access is controller-level (a role not listed for a
    |  controller is redirected away before any data loads).
    |  Any controller NOT in this list defaults to Supervisor-only
    |  — a safe default so a new module can never accidentally
    |  ship without access control, the way Suppliers/Orders did.
    |
    |  Manager is otherwise treated as VIEW-ONLY: any action whose
    |  name looks like a mutation (create/edit/delete/update/...)
    |  is blocked for Manager unless explicitly whitelisted below.
    */
    const ACCESS_MATRIX = array(
        'auth'        => array(1, 2, 3, 4, 5),
        'dashboard'   => array(1, 2, 3, 4, 5),
        'workorders'  => array(1, 2, 4),
        'equipment'   => array(1, 2, 4),
        'pm'          => array(1, 2, 4),
        'inspections' => array(1, 2, 4),
        'materials'   => array(1, 2, 3, 4),
        'backlog'     => array(1, 2, 4),
        'spareparts'  => array(1, 2, 3, 4),
        'suppliers'   => array(1, 3, 4),
        'orders'      => array(1, 3, 4),
        'lubricants'  => array(1, 2, 3),
        'reports'     => array(1, 4),
        // Planning is a supervisor + manager activity: the supervisor builds
        // and publishes the plan, the manager reads it and the variance.
        'forecast'    => array(1, 4),
        'team'        => array(1, 2, 3, 4),
        'users'       => array(1),
        'settings'    => array(1),
    );

    const ROLE_MANAGER = 4;
    const ROLE_CEO      = 5;

    /* Own-account pages. Every signed-in staff member (roles 1-4) can open
       their profile and change their own password, whatever the controller
       matrix says about `users`; both actions only ever touch the signed-in
       user's own row. The CEO stays on the Executive Summary only. */
    const SELF_SERVICE_ACTIONS = array(
        'users/profile',
        'users/change_password',
    );

    /* Manager may perform these specific write actions even though
       Manager is otherwise view-only (e.g. approving a spare order). */
    const MANAGER_WRITE_WHITELIST = array(
        'orders/update_status',
        // Notification acknowledgments are permitted but remain POST-only.
        'spareparts/mark_read',
        'spareparts/mark_all_read',
        // Publishing (freezing) a weekly report is the sign-off on a week's
        // figures, which Manager may perform as well as Supervisor — so
        // this POST is allowed through.
        'reports/publish_weekly_w36',
        'reports/weekly_report_name',
    );

    /* Mutating action-name prefixes used by the Manager view-only guard.
       State-changing actions must also enforce POST at their handler; this
       guard is defense in depth and does not replace per-action checks. */
    const MUTATION_PATTERN = '/^(create|edit|delete|add|remove|update|receive|issue|upload|review|mark|change|save|insert|start|stop|close|generate|publish|complete|approve|release|allocate|record|submit|toggle|cancel|sync|assign|import|reconcile)/i';

    protected function _enforce_access() {
        if (!$this->router) return; // safety guard, shouldn't happen in practice

        $class  = strtolower((string)($this->router->fetch_class()));
        $method = strtolower((string)($this->router->fetch_method()));

        if ($class === 'auth') return; // login/logout always reachable

        $allowed = isset(self::ACCESS_MATRIX[$class]) ? self::ACCESS_MATRIX[$class] : array(1);

        // Own-account pages bypass the controller matrix (not the CEO rule,
        // and not the Manager guard's POST/CSRF handling below).
        $self_service = in_array($class . '/' . $method, self::SELF_SERVICE_ACTIONS, true)
                        && $this->role_id != self::ROLE_CEO;

        if (!$self_service && !in_array($this->role_id, $allowed)) {
            $this->session->set_flashdata('error', 'That section is not available for your role.');
            redirect('dashboard');
        }

        if ($this->role_id == self::ROLE_MANAGER) {
            $is_post    = (strtolower((string)($this->input->method())) === 'post');
            $name_match = (bool)preg_match(self::MUTATION_PATTERN, $method);
            $key        = $class . '/' . $method;
            // A manager changes only their OWN password/profile here, which is
            // not a mutation of workshop data.
            $allowed_write = $is_post && (in_array($key, self::MANAGER_WRITE_WHITELIST, true)
                                          || in_array($key, self::SELF_SERVICE_ACTIONS, true));
            if (in_array($key, self::SELF_SERVICE_ACTIONS, true)) $name_match = FALSE;

            // Whitelisted writes are POST-only too. A GET must never become a
            // state-changing shortcut around CSRF or the intended form flow.
            if (($is_post || $name_match) && !$allowed_write) {
                $this->session->set_flashdata('error', 'Your role has view-only access — this action is not permitted.');
                redirect('dashboard');
            }
        }
    }

    protected function _check_auth() {
        if (!$this->session->userdata('user_id')) {
            redirect('login');
        }
        $this->user_id   = $this->session->userdata('user_id');
        $this->role_id   = $this->session->userdata('role_id');
        $this->user_data = $this->session->userdata('user_data');

        /*
         * Sessions written before K-18 carry the entire `users` row, password
         * hash included, in `ci_sessions`. Rewrite one the first time it is
         * seen so existing rows stop holding it; new sign-ins are trimmed at
         * the source (Auth::login). The check is a property test, so it costs
         * nothing once the payload has been rewritten.
         */
        $legacy = (is_object($this->user_data) && isset($this->user_data->password))
               || (is_array($this->user_data)  && isset($this->user_data['password']));
        if ($legacy) {
            $this->user_data = wms_session_user($this->user_data);
            $this->session->set_userdata('user_data', $this->user_data);
        }
    }

    protected function _load_settings() {
        $settings = [];

        // A database that accepts the connection but cannot answer the query
        // (table missing, no SELECT grant, server gone) makes `get()` return
        // FALSE, and `FALSE->result()` is a PHP Error — an Error is not an
        // Exception, so `catch (Exception)` never saw it. The result was a
        // blank HTTP 500 on every page, which is the hardest failure there is
        // to diagnose from a browser: no message, no page, nothing to search.
        // Now it is checked, logged, and the page renders with the documented
        // defaults instead.
        if ($this->db) {
            try {
                $query = $this->db->get('settings');

                if ($query !== FALSE) {
                    foreach ($query->result() as $r) {
                        $settings[$r->setting_key] = $r->setting_value;
                    }
                } else {
                    log_message('error', 'WMS: the `settings` table could not be read — the database is '
                        .'unreachable, or the schema has not been imported into it. Pages will render '
                        .'with the documented default settings.');
                }
            } catch (Throwable $e) {   // Exception and Error alike (PHP 7+)
                log_message('error', 'WMS: settings could not be loaded — '.$e->getMessage());
            }
        }

        $this->load->vars(['site_settings' => $settings]);
    }

    protected function render($view, $data = []) {
        $data['current_user'] = $this->user_data;
        $data['role_id']      = $this->role_id;
        // Pending-spares availability alerts (bell in the top bar).  The query
        // is skipped entirely when the spare-parts tracking tables are missing.
        if (!isset($data['new_spares_alerts'])) {
            $data['new_spares_alerts'] = $this->_new_spares_alert_count();
        }
        // Sign-in security events waiting to be looked at (F-19). Supervisors
        // only, and silent when the security-events table is missing.
        if (!isset($data['security_events_open'])) {
            $data['security_events_open'] = $this->is_supervisor() ? $this->_open_security_event_count() : 0;
        }
        $this->load->view('layouts/header',  $data);
        $this->load->view('layouts/sidebar', $data);
        $this->load->view($view,             $data);
        $this->load->view('layouts/footer',  $data);
    }

    /** Unread spare-part availability alerts for the current user (defensive:
        any problem — missing tables, DB hiccup — simply shows no badge). */
    private function _new_spares_alert_count() {
        try {
            if (!$this->db || !$this->db->table_exists('spare_part_alerts')) return 0;
            if (!$this->db->table_exists('wo_spare_allocations')) return 0;
            $this->load->model('Spareparts_model');
            return (int)$this->Spareparts_model->count_new_alerts($this->user_id, $this->role_id);
        } catch (Throwable $e) {
            // Throwable, not Exception: `table_exists()` runs a query, and a
            // database that cannot answer raises an Error. A badge is not worth
            // an empty page. (Same reasoning as _load_settings().)
            return 0;
        }
    }

    /** Unacknowledged sign-in security events. Any problem => no badge. */
    private function _open_security_event_count() {
        try {
            if (!$this->db || !$this->db->table_exists('security_events')) return 0;
            $this->load->model('Auth_throttle_model');
            return (int)$this->Auth_throttle_model->unacknowledged_count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    protected function is_supervisor() { return $this->role_id == 1; }
    protected function is_manager()    { return $this->role_id == self::ROLE_MANAGER; }
    protected function is_ceo()        { return $this->role_id == self::ROLE_CEO; }
    protected function can_write()     { return in_array($this->role_id, [1, 2, 3]); }

    /** Reject state-changing requests that did not use POST. */
    protected function require_post() {
        if (strtolower((string)($this->input->method())) === 'post') return true;

        $this->output->set_status_header(405);
        $this->output->set_header('Allow: POST');
        $this->output->set_content_type('text/plain', 'utf-8');
        $this->output->set_output('Method Not Allowed');
        return false;
    }

    protected function json($data, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
