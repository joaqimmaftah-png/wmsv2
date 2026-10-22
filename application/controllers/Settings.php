<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Settings extends MY_Controller {

    public function index() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('dashboard');
        }

        $this->load->model('Settings_model');

        if ($this->input->post()) {
            $settings = $this->input->post('settings');
            if (is_array($settings)) {
                // W0.7: upsert through the model so a key that has not been seeded
                // yet (fresh installation) is created with its documented
                // description, and unknown/invalid values are ignored or coerced.
                $this->Settings_model->save_many($settings);
            }
            $this->session->set_flashdata('success', 'Settings saved.');
            redirect('settings');
        }

        $rows = $this->db->get('settings')->result();
        $data['settings_data'] = [];
        foreach ($rows as $r) $data['settings_data'][$r->setting_key] = $r;

        // Defaults + wording for the weekly-report fields shown on the screen
        $data['settings_defaults']     = Settings_model::defaults();
        $data['settings_descriptions'] = Settings_model::descriptions();
        $data['report_keys']           = Settings_model::report_keys();
        $data['forecast_keys']         = Settings_model::forecast_keys();
        $data['timesheet_keys']        = Settings_model::timesheet_keys();
        $data['page_title'] = 'System Settings';
        $this->render('settings/index', $data);
    }

    /* ─────────────────────────────────────────────────────────────────
       SIGN-IN SECURITY (finding F-19, second half)

       Wave 1 stopped online password guessing; nothing showed anybody that
       it was happening. This screen is that missing half: what the policy
       is, what has actually fired, and a place to say "seen it".
    ───────────────────────────────────────────────────────────────── */

    public function security() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('dashboard');
        }

        $this->load->model('Auth_throttle_model');
        $this->load->model('Settings_model');

        if ($this->input->post()) {
            return $this->security_action();
        }

        $hours = (int)($this->input->get('hours') ?: 24);
        if (!in_array($hours, array(24, 168, 720), true)) $hours = 24;

        $data['policy']        = $this->Auth_throttle_model->policy();
        $data['events']        = $this->Auth_throttle_model->recent_events(100);
        $data['counts']        = $this->Auth_throttle_model->event_counts($hours);
        $data['hours']         = $hours;
        $data['events_ready']  = $this->db->table_exists('security_events');
        $data['attempts_ready']= $this->db->table_exists('login_attempts');

        // How much guessing has this installation actually seen? Read-only,
        // and only ever counts — never the addresses themselves.
        $data['recent'] = $data['attempts_ready'] ? array(
            'last_hour'  => (int)$this->db->where('attempted_at >=', date('Y-m-d H:i:s', time() - 3600))
                                    ->count_all_results('login_attempts'),
            'last_day'   => (int)$this->db->where('attempted_at >=', date('Y-m-d H:i:s', time() - 86400))
                                    ->count_all_results('login_attempts'),
            'sources'    => (int)$this->db->select('COUNT(DISTINCT ip_address) AS n')
                                    ->where('attempted_at >=', date('Y-m-d H:i:s', time() - 86400))
                                    ->get('login_attempts')->row()->n,
            'accounts'   => (int)$this->db->select('COUNT(DISTINCT email) AS n')
                                    ->where('attempted_at >=', date('Y-m-d H:i:s', time() - 86400))
                                    ->get('login_attempts')->row()->n,
        ) : NULL;

        $data['setting_ip_cap'] = $this->Settings_model->get('login_ip_cap_enabled', 1);
        $data['page_title']     = 'Sign-in Security';
        $this->render('settings/security', $data);
    }

    /** The screen's two write actions. POST only, supervisor only. */
    private function security_action() {
        if (!$this->require_post()) return;
        $this->load->model('Auth_throttle_model');

        $action = (string)$this->input->post('action', TRUE);

        if ($action === 'acknowledge') {
            $ok = $this->Auth_throttle_model->acknowledge(
                $this->input->post('id', TRUE),
                $this->user_id,
                $this->input->post('note', TRUE)
            );
            $this->session->set_flashdata($ok ? 'success' : 'error',
                $ok ? 'Event acknowledged.' : 'That event could not be acknowledged.');

        } elseif ($action === 'policy') {
            // Only the documented key is accepted: the settings model whitelists
            // it, and sanitize() coerces it to 0/1.
            $this->Settings_model->save_many(array(
                'login_ip_cap_enabled' => (int)$this->input->post('login_ip_cap_enabled', TRUE),
            ));
            $this->session->set_flashdata('success', 'Sign-in policy saved.');

        } else {
            $this->session->set_flashdata('error', 'Unknown action.');
        }

        redirect('settings/security');
    }
}
