<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attendance check-in / check-out (ATTENDANCE_WORKFLOW.md).
 *
 *   punch()       the phone page a scanned site code opens
 *   record()      POST: the only place a punch is written
 *   token()       JSON: the site screen's rotating signed token
 *   board()       the screen at the site entrance (QR + rotating token)
 *   sites()       supervisor: geofence configuration
 *   log()         day view: every punch, who is on site, eligibility
 *   correction()  POST: supervisor correction, reason required
 *
 * Security shape
 * --------------
 * The QR token and the GPS position are BOTH checked here, on the server, on
 * every punch. The phone page only collects a position and reports why a punch
 * cannot be made; it never decides anything. Punch type is derived from the
 * worker's own punch history, so a crafted POST cannot turn a check-out into
 * a check-in (or the reverse).
 */
class Attendance extends MY_Controller {

    /** Model properties (declared for PHP 8.2+ dynamic-property rules). */
    public $Attendance_model;
    public $Settings_model;

    /** A second successful punch sooner than this smells like a double tap. */
    const MIN_PUNCH_GAP_SECONDS = 15;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Attendance_model', 'Settings_model'));
        $this->load->helper(array('attendance', 'form'));
    }

    /* ═════════════════════════════════════════════════════════════════
       Overview
       ═════════════════════════════════════════════════════════════════ */

    public function index() {
        if (!$this->Attendance_model->ready()) {
            $data['ready']      = FALSE;
            $data['page_title'] = 'Attendance';
            $this->render('attendance/index', $data);
            return;
        }

        $date = date('Y-m-d');

        $data['ready']         = TRUE;
        $data['date']          = $date;
        $data['sites']         = $this->Attendance_model->get_sites(TRUE);
        $data['on_site']       = $this->Attendance_model->on_site_map($date);
        $data['my_punches']    = $this->Attendance_model->get_punches($date, $this->user_id);
        $data['my_eligibility']= $this->Attendance_model->eligibility($this->user_id, $date);
        $data['gate_enabled']  = (string)$this->Settings_model->get('attendance_gate_enabled', '0') === '1';
        $data['page_title']    = 'Attendance';
        $this->render('attendance/index', $data);
    }

    /* ═════════════════════════════════════════════════════════════════
       The phone page
       ═════════════════════════════════════════════════════════════════ */

    /**
     * The page a site QR code opens, and the manual fallback for a technician
     * whose camera handed the link to another app.
     *
     * A technician must already be signed in: this page never asks for a
     * password, because the signed-in account IS the identity being punched.
     */
    public function punch() {
        if (!$this->Attendance_model->ready()) {
            $data['ready'] = FALSE;
            $data['page_title'] = 'Check in / out';
            $this->load->view('attendance/punch', $data);
            return;
        }

        $token = $this->input->get('t', TRUE);
        $token = is_scalar($token) ? trim((string)$token) : '';

        $site    = NULL;
        $verdict = NULL;

        if ($token !== '') {
            $site = $this->_site_from_token($token);
            if ($site) {
                $verdict = wms_attendance_qr_verify(
                    $token,
                    $site->qr_secret,
                    NULL,
                    $this->_qr_ttl(),
                    $this->_qr_grace(),
                    $this->_site_allows_static_qr((int)$site->id)
                );
                if (!$site->is_active) {
                    $verdict = array('ok' => FALSE, 'code' => 'inactive', 'message' => 'This site is no longer in use.');
                }
            } else {
                $verdict = array('ok' => FALSE, 'code' => 'bad_signature', 'message' => 'That site code is not valid here. Scan the QR code on the site screen again.');
            }
        }

        $date    = date('Y-m-d');
        $types   = $this->Attendance_model->punch_types($this->user_id, $date);
        $last    = $this->Attendance_model->latest_punch($this->user_id, $date);
        $next    = wms_attendance_next_punch($last ? $last->punch_type : NULL);

        $data['ready']       = TRUE;
        $data['token']       = $token;
        $data['site']        = $site;
        $data['verdict']     = $verdict;
        $data['next_type']   = $next;
        $data['on_site']     = wms_attendance_on_site($types);
        $data['last_punch']  = $last;
        $data['today']       = $this->Attendance_model->get_punches($date, $this->user_id);
        $data['user']        = $this->user_data;
        $data['open_session']= $this->Attendance_model->open_session($this->user_id);
        $data['page_title']  = wms_attendance_punch_label($next);
        $this->load->view('attendance/punch', $data);
    }

    /**
     * POST: validate QR + GPS and write one punch.
     *
     * Nothing is written unless both checks pass. A failed attempt is
     * explained, not recorded (there is no "half punch").
     */
    public function record() {
        if (!$this->require_post()) return;

        if (!$this->Attendance_model->ready()) {
            return $this->_finish(FALSE, 'Attendance punching is not set up on this installation yet.');
        }

        /* ── 0. the signed-in account must still be active & eligible ── */
        $who = $this->Attendance_model->punchable_user($this->user_id);
        if (!$who['ok']) {
            return $this->_finish(FALSE, $who['message']);
        }

        $token = $this->input->post('t', TRUE);
        $token = is_scalar($token) ? trim((string)$token) : '';
        if ($token === '') {
            return $this->_finish(FALSE, 'No site code was sent. Scan the QR code on the site screen, then try again.');
        }

        /* ── 1. the QR token ─────────────────────────────────────────── */
        $site = $this->_site_from_token($token);
        if (!$site || !$site->is_active) {
            return $this->_finish(FALSE, 'That site code is not recognised (or the site is inactive). Scan the QR code on the site screen again.');
        }

        $verdict = wms_attendance_qr_verify(
            $token,
            $site->qr_secret,
            NULL,
            $this->_qr_ttl(),
            $this->_qr_grace(),
            $this->_site_allows_static_qr((int)$site->id)
        );
        if (!$verdict['ok']) {
            return $this->_finish(FALSE, $verdict['message'] . ' Nothing was recorded.');
        }

        /* ── 2. the position ─────────────────────────────────────────── */
        $lat = $this->input->post('lat', TRUE);
        $lng = $this->input->post('lng', TRUE);
        $acc = $this->input->post('acc', TRUE);

        if (!wms_valid_latitude($lat) || !wms_valid_longitude($lng)) {
            return $this->_finish(FALSE, 'Your location could not be read. Allow location access for this page, wait for the phone to get a fix, then try again. Nothing was recorded.');
        }

        $distance = wms_attendance_distance_m($site->latitude, $site->longitude, $lat, $lng);
        $geo      = wms_attendance_geo_verdict($distance, $site->radius_m, $acc, $site->max_accuracy_m);
        if (!$geo['ok']) {
            return $this->_finish(FALSE, $geo['message'] . ' Nothing was recorded.');
        }

        /* ── 3. what this punch means ────────────────────────────────── */
        $date      = date('Y-m-d');
        $last      = $this->Attendance_model->latest_punch($this->user_id, $date);
        $punch     = wms_attendance_next_punch($last ? $last->punch_type : NULL);
        $warning   = '';

        if ($last && (time() - strtotime((string)$last->punched_at)) < self::MIN_PUNCH_GAP_SECONDS) {
            return $this->_finish(FALSE, 'Your last punch was a few seconds ago. Wait a moment before punching again. Nothing was recorded.');
        }

        if ($punch === 'check_out') {
            $session = $this->Attendance_model->open_session($this->user_id);
            if ($session) {
                $ref = !empty($session->wo_number) ? $session->wo_number : ('#' . (int)$session->wo_id);
                $policy = (string)$this->Settings_model->get('attendance_open_session_policy', 'warn');
                if ($policy === 'block') {
                    return $this->_finish(FALSE,
                        'You still have an open work session on ' . $ref . '. Close it (or ask a supervisor to review it) before checking out. Nothing was recorded.');
                }
                $warning = 'Your work session on ' . $ref . ' is still open — close it so the labour hours are recorded.';
            }
        }

        /* ── 4. write the event and refresh the day row ──────────────── */
        $punch_id = $this->Attendance_model->record_punch(array(
            'user_id'    => $this->user_id,
            'site_id'    => $site->id,
            'work_date'  => $date,
            'punch_type' => $punch,
            'punched_at' => date('Y-m-d H:i:s'),
            'origin'     => 'self',
            'latitude'   => round((float)$lat, 7),
            'longitude'  => round((float)$lng, 7),
            'accuracy_m' => round((float)$acc, 1),
            'distance_m' => $distance,
            'radius_m'   => (int)$site->radius_m,
            'ip_address' => $this->input->ip_address(),
            'user_agent' => (string)$this->input->user_agent(),
            'created_by' => $this->user_id,
        ));

        if (!$punch_id) {
            return $this->_finish(FALSE, 'The punch could not be saved. Nothing was recorded — try again.');
        }

        $this->Attendance_model->sync_attendance_row($this->user_id, $date);
        $this->Attendance_model->prune_old_location_evidence($this->_retention_days());

        $e = $this->Attendance_model->eligibility($this->user_id, $date);
        $message = wms_attendance_punch_label($punch) . ' recorded at ' . date('H:i')
                 . ' (' . (int)round($distance) . ' m from ' . $site->name . ').';
        if ($punch === 'check_in' && !empty($e['reasons'])) {
            $message .= ' Note: ' . implode('; ', $e['reasons']) . '.';
        }

        return $this->_finish(TRUE, $message, $warning, $punch, $site);
    }

    /* ═════════════════════════════════════════════════════════════════
       The site screen
       ═════════════════════════════════════════════════════════════════ */

    /**
     * JSON: the current signed token for one site.
     *
     * Supervisor-only: this token is the site key, so it is not issued to a
     * technician's phone (the phone is supposed to SCAN it, on site).
     */
    public function token() {
        if (!$this->is_supervisor()) {
            return $this->json(array('ok' => FALSE, 'error' => 'Supervisors only.'), 403);
        }
        if (!$this->Attendance_model->ready()) {
            return $this->json(array('ok' => FALSE, 'error' => 'Attendance sites are not set up.'), 409);
        }

        $site = $this->Attendance_model->get_site((int)$this->input->get('site_id'));
        if (!$site) $site = $this->Attendance_model->default_site();
        if (!$site || !$site->is_active) {
            return $this->json(array('ok' => FALSE, 'error' => 'No active site is configured.'), 404);
        }

        $ttl     = $this->_qr_ttl();
        $token   = wms_attendance_qr_token($site->id, $site->qr_secret, NULL, $ttl);
        $seconds = $ttl - (time() % $ttl);

        // The URL is absolute: it is read by a phone camera, not by this page.
        $url = rtrim(base_url(), '/') . '/attendance/punch?t=' . rawurlencode((string)$token);

        return $this->json(array(
            'ok'         => TRUE,
            'site'       => array('id' => (int)$site->id, 'name' => $site->name,
                                  'radius_m' => (int)$site->radius_m),
            'token'      => $token,
            'url'        => $url,
            'expires_in' => max(1, $seconds),
            'ttl'        => $ttl,
        ));
    }

    /** The screen at the site entrance: a QR that changes itself. */
    public function board() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'The site screen is for supervisors.');
            redirect('attendance');
            return;
        }

        $sites = $this->Attendance_model->get_sites(TRUE);
        $selected = $this->Attendance_model->get_site((int)$this->input->get('site_id'));
        if (!$selected) $selected = $sites ? $sites[0] : NULL;

        $data['sites']      = $sites;
        $data['site']       = $selected;
        $data['ttl']        = $this->_qr_ttl();
        $data['page_title'] = 'Site check-in screen';
        $this->load->view('attendance/board', $data);
    }

    /**
     * Printable A4 fixed gate QR poster for sites without a permanent screen
     * (ATTENDANCE_WORKFLOW.md → "One-time site setup", step 3).
     *
     * Still validated server-side against live GPS coordinates + radius + accuracy
     * and revocable immediately via "Rotate code".
     */
    public function print_qr() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Printable gate QR sheets are for supervisors.');
            redirect('attendance');
            return;
        }

        $sites = $this->Attendance_model->get_sites(TRUE);
        $selected = $this->Attendance_model->get_site((int)$this->input->get('site_id'));
        if (!$selected) $selected = $sites ? $sites[0] : NULL;

        $token = $selected ? wms_attendance_qr_static_token((int)$selected->id, $selected->qr_secret) : NULL;
        $url   = $token ? (rtrim(base_url(), '/') . '/attendance/punch?t=' . rawurlencode($token)) : '';

        $data['sites']          = $sites;
        $data['site']           = $selected;
        $data['static_enabled'] = $selected ? $this->_site_allows_static_qr((int)$selected->id) : FALSE;
        $data['token']          = $token;
        $data['url']            = $url;
        $data['page_title']     = 'Printable gate QR';
        $this->load->view('attendance/print_qr', $data);
    }

    /* ═════════════════════════════════════════════════════════════════
       Supervisor: site configuration
       ═════════════════════════════════════════════════════════════════ */

    public function sites() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('attendance');
            return;
        }

        if (!$this->Attendance_model->ready()) {
            $data['ready']      = FALSE;
            $data['page_title'] = 'Attendance sites';
            $this->render('attendance/sites', $data);
            return;
        }

        if (strtolower((string)$this->input->method()) === 'post') {
            if (!$this->require_post()) return;

            $action = (string)$this->input->post('action', TRUE);

            if ($action === 'rotate') {
                $site = $this->Attendance_model->get_site((int)$this->input->post('id', TRUE));
                if ($site && $this->Attendance_model->regenerate_secret($site->id)) {
                    $this->session->set_flashdata('success',
                        'A new site code secret was generated for ' . $site->name . '. Any code already printed or photographed stops working.');
                } else {
                    $this->session->set_flashdata('error', 'The site code could not be rotated.');
                }
                redirect('attendance/sites');
                return;
            }

            if ($action === 'enable_static_qr') {
                $site = $this->Attendance_model->get_site((int)$this->input->post('id', TRUE));
                if ($site) {
                    $this->_set_site_static_qr((int)$site->id, TRUE);
                    $this->session->set_flashdata('success', 'Printed QR fallback enabled for ' . $site->name . '.');
                    redirect('attendance/print_qr?site_id=' . (int)$site->id);
                    return;
                }
                redirect('attendance/sites');
                return;
            }

            $id           = (int)$this->input->post('id', TRUE);
            $name         = trim((string)$this->input->post('name', TRUE));
            $lat          = $this->input->post('latitude', TRUE);
            $lng          = $this->input->post('longitude', TRUE);
            $radius       = (int)$this->input->post('radius_m', TRUE);
            $max_acc      = (int)$this->input->post('max_accuracy_m', TRUE);
            $active       = $this->input->post('is_active') ? 1 : 0;
            $allow_static = (bool)$this->input->post('allow_static_qr');

            $errors = array();
            if ($name === '' || strlen($name) > 100) $errors[] = 'Give the site a name (up to 100 characters).';
            if (!wms_valid_latitude($lat))            $errors[] = 'Latitude must be between -90 and 90.';
            if (!wms_valid_longitude($lng))           $errors[] = 'Longitude must be between -180 and 180.';
            if ($radius < 25 || $radius > 5000)       $errors[] = 'The geofence radius must be between 25 and 5 000 m.';
            if ($max_acc < 5 || $max_acc > 2000)      $errors[] = 'Maximum GPS accuracy must be between 5 and 2 000 m.';

            if (!empty($errors)) {
                $this->session->set_flashdata('error', implode(' ', $errors));
                redirect('attendance/sites' . ($id ? '?edit=' . $id : ''));
                return;
            }

            $payload = array(
                'name'           => $name,
                'latitude'       => round((float)$lat, 7),
                'longitude'      => round((float)$lng, 7),
                'radius_m'       => $radius,
                'max_accuracy_m' => $max_acc,
                'is_active'      => $active,
            );

            if ($id > 0) {
                $this->Attendance_model->update_site($id, $payload);
                $this->_set_site_static_qr($id, $allow_static);
                $this->session->set_flashdata('success', 'Site saved.');
            } else {
                $payload['created_by'] = $this->user_id;
                $new_id = $this->Attendance_model->create_site($payload);
                if ($new_id > 0) {
                    $this->_set_site_static_qr($new_id, $allow_static);
                }
                $this->session->set_flashdata('success', 'Site added. Open the site screen to start showing its code.');
            }
            redirect('attendance/sites');
            return;
        }

        $data['ready']               = TRUE;
        $data['sites']               = $this->Attendance_model->get_sites();
        $data['editing']             = $this->Attendance_model->get_site((int)$this->input->get('edit'));
        $data['static_site_map']     = $this->_static_site_map();
        $data['gate_enabled']        = (string)$this->Settings_model->get('attendance_gate_enabled', '0') === '1';
        $data['open_session_policy'] = (string)$this->Settings_model->get('attendance_open_session_policy', 'warn');
        $data['qr_ttl']              = $this->_qr_ttl();
        $data['qr_grace']            = $this->_qr_grace();
        $data['retention_days']      = $this->_retention_days();
        $data['page_title']          = 'Attendance sites';
        $this->render('attendance/sites', $data);
    }

    /** POST: policy settings shown on the sites screen. */
    public function policy() {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('attendance');
            return;
        }

        $this->Settings_model->save_many(array(
            'attendance_gate_enabled'            => $this->input->post('attendance_gate_enabled') ? 1 : 0,
            'attendance_open_session_policy'     => (string)$this->input->post('attendance_open_session_policy', TRUE),
            'attendance_qr_ttl_seconds'          => (int)$this->input->post('attendance_qr_ttl_seconds', TRUE),
            'attendance_qr_grace_seconds'        => (int)$this->input->post('attendance_qr_grace_seconds', TRUE),
            'attendance_location_retention_days' => (int)$this->input->post('attendance_location_retention_days', TRUE),
        ));
        $this->Attendance_model->prune_old_location_evidence($this->_retention_days());

        $this->session->set_flashdata('success', 'Attendance policy saved.');
        redirect('attendance/sites');
    }

    /* ═════════════════════════════════════════════════════════════════
       Supervisor: the day's punches, and corrections
       ═════════════════════════════════════════════════════════════════ */

    public function log() {
        if (!$this->is_supervisor() && !$this->is_manager()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('attendance');
            return;
        }

        $date = (string)$this->input->get('date', TRUE);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
            || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
            $date = date('Y-m-d');
        }

        $data['ready']              = $this->Attendance_model->ready();
        $data['date']               = $date;
        $all_punches                = $this->Attendance_model->get_punches($date);
        $data['punches']            = $all_punches; // full day: worker summary groups these
        $data['on_site']            = $this->Attendance_model->on_site_map($date);
        $all_workers                = $this->Attendance_model->technicians_for_assignment($date);
        $wctx = $this->_build_log_workers_dataset($all_workers);
        $wper_in = (int)$this->input->get('wper_page', TRUE);
        $wper_page = in_array($wper_in, array(10, 25, 50, 100), TRUE) ? $wper_in : 25;
        $wtotal = count($wctx['rows']);
        $wtotal_pages = max(1, (int)ceil($wtotal / $wper_page));
        $wpage_in = (int)$this->input->get('wpage', TRUE);
        $wpage = max(1, min($wtotal_pages, $wpage_in > 0 ? $wpage_in : 1));
        $woffset = ($wpage - 1) * $wper_page;
        $data['workers']            = array_slice($wctx['rows'], $woffset, $wper_page);
        $data['workers_total']      = $wtotal;
        $data['wtab_counts']        = $wctx['tab_counts'];
        $data['wactive_tab']        = $wctx['active_tab'];
        $data['wsearch_query']      = $wctx['search_query'];
        $data['wper_page']          = $wper_page;
        $data['wpage']              = $wpage;
        $data['wtotal_pages']       = $wtotal_pages;
        $data['wshowing_from']      = $wtotal > 0 ? ($woffset + 1) : 0;
        $data['wshowing_to']        = min($wtotal, $woffset + count($data['workers']));
        $data['correction_workers'] = $data['ready'] ? $this->Attendance_model->workers_for_correction() : array();
        $data['sites']              = $this->Attendance_model->get_sites();
        $data['is_supervisor']      = $this->is_supervisor();
        $data['page_title']         = 'Attendance log';

        // Punch-events table: tabs/search/sort/pagination over the same day.
        $ctx = $this->_build_log_dataset($all_punches);
        $per_page_in = (int)$this->input->get('per_page', TRUE);
        $per_page = in_array($per_page_in, array(10, 25, 50, 100), TRUE) ? $per_page_in : 25;
        $total_filtered = count($ctx['rows']);
        $total_pages = max(1, (int)ceil($total_filtered / $per_page));
        $page_in = (int)$this->input->get('page', TRUE);
        $page = max(1, min($total_pages, $page_in > 0 ? $page_in : 1));
        $offset = ($page - 1) * $per_page;
        $view_in = strtolower(trim((string)$this->input->get('view', TRUE)));

        $data['events']         = array_slice($ctx['rows'], $offset, $per_page);
        $data['events_total']   = $total_filtered;
        $data['tab_counts']     = $ctx['tab_counts'];
        $data['log_summary']    = $ctx['summary'];
        $data['log_sites']      = $ctx['sites'];
        $data['active_tab']     = $ctx['active_tab'];
        $data['search_query']   = $ctx['search_query'];
        $data['site_filter']    = $ctx['site_filter'];
        $data['sort_by']        = $ctx['sort_by'];
        $data['sort_dir']       = $ctx['sort_dir'];
        $data['per_page']       = $per_page;
        $data['page']           = $page;
        $data['total_pages']    = $total_pages;
        $data['showing_from']   = $total_filtered > 0 ? ($offset + 1) : 0;
        $data['showing_to']     = min($total_filtered, $offset + count($data['events']));
        $data['view_mode']      = ($view_in === 'cards') ? 'cards' : 'table';
        $data['can_export']     = TRUE; // page itself is supervisor/manager only
        $this->render('attendance/log', $data);
    }

    /**
     * Build the Technicians (worker day-summary) dataset: search and
     * On Site / Away / Eligible / Blocked tabs over the workers the model
     * decorated with on_site / eligible / block_reasons. Counts are after
     * search (house rule). Paginated independently of the Punch-events
     * table (wpage / wper_page) so either panel can grow without the
     * other resetting.
     */
    private function _build_log_workers_dataset($workers) {
        $search_query = trim((string)$this->input->get('wq', TRUE));
        if (strlen($search_query) > 100) $search_query = substr($search_query, 0, 100);

        $tab_in = strtolower(trim((string)$this->input->get('wtab', TRUE)));
        $active_tab = in_array($tab_in, array('all', 'on_site', 'away', 'eligible', 'blocked'), TRUE) ? $tab_in : 'all';

        $scoped = array();
        foreach ((array)$workers as $w) {
            $w->worker_name_log = trim((string)(isset($w->first_name) ? $w->first_name : '') . ' ' . (string)(isset($w->last_name) ? $w->last_name : ''));
            if ($search_query !== '') {
                $hay = strtolower($w->worker_name_log . ' ' .
                    (isset($w->employee_id) ? (string)$w->employee_id : '') . ' ' .
                    (isset($w->position) ? (string)$w->position : '') . ' ' .
                    (!empty($w->block_reasons) ? implode(' ', (array)$w->block_reasons) : ''));
                if (strpos($hay, strtolower($search_query)) === FALSE) continue;
            }
            $scoped[] = $w;
        }

        $tab_counts = array('all' => count($scoped), 'on_site' => 0, 'away' => 0, 'eligible' => 0, 'blocked' => 0);
        foreach ($scoped as $w) {
            if (!empty($w->on_site)) $tab_counts['on_site']++; else $tab_counts['away']++;
            if (!empty($w->eligible)) $tab_counts['eligible']++; else $tab_counts['blocked']++;
        }

        $rows = array();
        foreach ($scoped as $w) {
            if ($active_tab === 'on_site' && empty($w->on_site)) continue;
            if ($active_tab === 'away' && !empty($w->on_site)) continue;
            if ($active_tab === 'eligible' && empty($w->eligible)) continue;
            if ($active_tab === 'blocked' && !empty($w->eligible)) continue;
            $rows[] = $w;
        }

        return array(
            'rows'         => $rows,
            'tab_counts'   => $tab_counts,
            'active_tab'   => $active_tab,
            'search_query' => $search_query,
        );
    }

    /**
     * Build the Punch-events dataset for one day: search (worker, employee
     * ID, site, reason, author), site filter, and tabs. Counts are computed
     * after search + site filter (house rule). Note the Corrections tab is a
     * subset view (origin = supervisor) that overlaps Check-in/Check-out —
     * the type counts always sum to the All count.
     */
    private function _build_log_dataset($punches) {
        $search_query = trim((string)$this->input->get('q', TRUE));
        if (strlen($search_query) > 100) $search_query = substr($search_query, 0, 100);

        $site_names = array();
        foreach ((array)$punches as $p) {
            if (!empty($p->site_name)) $site_names[(string)$p->site_name] = TRUE;
        }
        $site_names = array_keys($site_names);
        sort($site_names, SORT_NATURAL | SORT_FLAG_CASE);

        $site_filter = trim((string)$this->input->get('site', TRUE));
        if ($site_filter !== '' && !in_array($site_filter, $site_names, TRUE)) $site_filter = '';

        $tab_in = strtolower(trim((string)$this->input->get('tab', TRUE)));
        $active_tab = in_array($tab_in, array('all', 'check_in', 'check_out', 'corrections'), TRUE) ? $tab_in : 'all';

        $scoped = array();
        foreach ((array)$punches as $p) {
            $p->worker_name_log = trim((string)(isset($p->first_name) ? $p->first_name : '') . ' ' . (string)(isset($p->last_name) ? $p->last_name : ''));
            if ($search_query !== '') {
                $hay = strtolower($p->worker_name_log . ' ' .
                    (isset($p->employee_id) ? (string)$p->employee_id : '') . ' ' .
                    (isset($p->site_name) ? (string)$p->site_name : '') . ' ' .
                    (isset($p->reason) ? (string)$p->reason : '') . ' ' .
                    trim((string)(isset($p->by_first_name) ? $p->by_first_name : '') . ' ' . (string)(isset($p->by_last_name) ? $p->by_last_name : '')));
                if (strpos($hay, strtolower($search_query)) === FALSE) continue;
            }
            if ($site_filter !== '' && (string)(isset($p->site_name) ? $p->site_name : '') !== $site_filter) continue;
            $scoped[] = $p;
        }

        $tab_counts = array('all' => count($scoped), 'check_in' => 0, 'check_out' => 0, 'corrections' => 0);
        $workers_seen = array();
        foreach ($scoped as $p) {
            if ($p->punch_type === 'check_in') $tab_counts['check_in']++;
            elseif ($p->punch_type === 'check_out') $tab_counts['check_out']++;
            if ((string)(isset($p->origin) ? $p->origin : '') === 'supervisor') $tab_counts['corrections']++;
            $workers_seen[(int)$p->user_id] = TRUE;
        }

        $rows = array();
        foreach ($scoped as $p) {
            if ($active_tab === 'check_in' && $p->punch_type !== 'check_in') continue;
            if ($active_tab === 'check_out' && $p->punch_type !== 'check_out') continue;
            if ($active_tab === 'corrections' && (string)(isset($p->origin) ? $p->origin : '') !== 'supervisor') continue;
            $rows[] = $p;
        }

        $sort_in = strtolower(trim((string)$this->input->get('sort', TRUE)));
        $sort_by = in_array($sort_in, array('time', 'worker', 'type'), TRUE) ? $sort_in : 'time';
        $dir_in = strtolower(trim((string)$this->input->get('dir', TRUE)));
        $sort_dir = ($dir_in === 'desc') ? 'desc' : 'asc';
        $mul = ($sort_dir === 'desc') ? -1 : 1;
        usort($rows, function ($a, $b) use ($sort_by, $mul) {
            switch ($sort_by) {
                case 'worker': $cmp = strcasecmp((string)$a->worker_name_log, (string)$b->worker_name_log); break;
                case 'type':   $cmp = strcasecmp((string)$a->punch_type, (string)$b->punch_type); break;
                default:       $cmp = strcmp((string)$a->punched_at, (string)$b->punched_at);
            }
            if ($cmp === 0) $cmp = strcmp((string)$a->punched_at, (string)$b->punched_at);
            return $cmp * $mul;
        });

        return array(
            'rows'         => $rows,
            'sites'        => $site_names,
            'tab_counts'   => $tab_counts,
            'active_tab'   => $active_tab,
            'search_query' => $search_query,
            'site_filter'  => $site_filter,
            'sort_by'      => $sort_by,
            'sort_dir'     => $sort_dir,
            'summary'      => array(
                'punches'    => $tab_counts['all'],
                'check_in'   => $tab_counts['check_in'],
                'check_out'  => $tab_counts['check_out'],
                'corrections'=> $tab_counts['corrections'],
                'workers'    => count($workers_seen),
            ),
        );
    }

    /** Export the filtered Punch-events day as CSV (same roles as the log page). */
    public function log_csv() {
        if (!$this->is_supervisor() && !$this->is_manager()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('attendance');
            return;
        }
        $date = (string)$this->input->get('date', TRUE);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
            || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
            $date = date('Y-m-d');
        }
        $ctx = $this->_build_log_dataset($this->Attendance_model->get_punches($date));

        $safe_cell = function ($v) {
            $s = (string)$v;
            if ($s !== '' && in_array($s[0], array('=', '+', '-', '@', "\t", "\r"), TRUE)) return "'" . $s;
            return $s;
        };

        $filename = 'WMS_Attendance_Log_' . $date . '.csv';
        if (ob_get_level()) @ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('Date', 'Time', 'Worker', 'Employee ID', 'Punch', 'Site', 'Distance (m)', 'Site Radius (m)', 'Accuracy (m)', 'Origin', 'Recorded By', 'Reason', 'IP Address'), ',', '"', '\\');
        foreach ($ctx['rows'] as $p) {
            fputcsv($out, array(
                $date,
                date('H:i:s', strtotime((string)$p->punched_at)),
                $safe_cell($p->worker_name_log),
                $safe_cell(isset($p->employee_id) ? $p->employee_id : ''),
                $p->punch_type === 'check_in' ? 'Check-in' : 'Check-out',
                $safe_cell(isset($p->site_name) ? $p->site_name : ''),
                isset($p->distance_m) && $p->distance_m !== NULL ? (int)round((float)$p->distance_m) : '',
                isset($p->radius_m) && $p->radius_m !== NULL ? (int)$p->radius_m : '',
                isset($p->accuracy_m) && $p->accuracy_m !== NULL ? (int)round((float)$p->accuracy_m) : '',
                (string)(isset($p->origin) ? $p->origin : '') === 'supervisor' ? 'Correction' : 'Phone',
                $safe_cell(trim((string)(isset($p->by_first_name) ? $p->by_first_name : '') . ' ' . (string)(isset($p->by_last_name) ? $p->by_last_name : ''))),
                $safe_cell(isset($p->reason) ? $p->reason : ''),
                $safe_cell(isset($p->ip_address) ? $p->ip_address : ''),
            ), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /**
     * POST: supervisor correction of a missed or wrong punch.
     *
     * A correction is a NEW event. The original punches are untouched, and the
     * reason, the author and the moment of the correction are all stored.
     * There is no delete path, on purpose.
     */
    public function correction() {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Only a supervisor can correct attendance punches.');
            redirect('attendance/log');
            return;
        }
        if (!$this->Attendance_model->ready()) {
            $this->session->set_flashdata('error', 'Attendance punching is not set up on this installation yet.');
            redirect('attendance');
            return;
        }

        $user_id = (int)$this->input->post('user_id', TRUE);
        $date    = (string)$this->input->post('date', TRUE);
        $time    = (string)$this->input->post('time', TRUE);
        $type    = (string)$this->input->post('punch_type', TRUE);
        $reason  = trim((string)$this->input->post('reason', TRUE));

        $back = 'attendance/log?date=' . rawurlencode($date ?: date('Y-m-d'));

        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
            || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
            $this->session->set_flashdata('error', 'Choose a valid date for the correction.');
            redirect($back);
            return;
        }
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
            $this->session->set_flashdata('error', 'Give the corrected time as HH:MM.');
            redirect($back);
            return;
        }
        if (!in_array($type, array('check_in', 'check_out'), TRUE)) {
            $this->session->set_flashdata('error', 'Choose check-in or check-out.');
            redirect($back);
            return;
        }
        if (strlen($reason) < 5) {
            $this->session->set_flashdata('error', 'A correction needs a reason (at least 5 characters) — it is part of the audit trail.');
            redirect($back);
            return;
        }

        $worker = $this->db->select('id, first_name, last_name, status')
            ->where('id', $user_id)->get('users')->row();
        if (!$worker) {
            $this->session->set_flashdata('error', 'That worker was not found.');
            redirect($back);
            return;
        }

        $this->Attendance_model->record_punch(array(
            'user_id'    => $user_id,
            'site_id'    => (int)$this->input->post('site_id', TRUE) ?: NULL,
            'work_date'  => $date,
            'punch_type' => $type,
            'punched_at' => $date . ' ' . $time . ':00',
            'origin'     => 'supervisor',
            'reason'     => $reason,
            'created_by' => $this->user_id,
        ));
        $this->Attendance_model->sync_attendance_row($user_id, $date);

        $name = trim((string)($worker->first_name . ' ' . $worker->last_name));
        $this->session->set_flashdata('success',
            'Correction saved: ' . wms_attendance_punch_label($type) . ' for ' . $name . ' at ' . $time . '.');
        redirect($back);
    }

    /* ═════════════════════════════════════════════════════════════════
       Internals
       ═════════════════════════════════════════════════════════════════ */

    /** The site a token claims to be for (signature is verified by the caller). */
    private function _site_from_token($token) {
        if (!preg_match('/^v1\.([0-9]{1,10})\./D', (string)$token, $m)) return NULL;
        return $this->Attendance_model->get_site((int)$m[1]);
    }

    private function _qr_ttl() {
        $v = (int)$this->Settings_model->get('attendance_qr_ttl_seconds', 60);
        return ($v >= 10 && $v <= 3600) ? $v : 60;
    }

    private function _qr_grace() {
        $v = (int)$this->Settings_model->get('attendance_qr_grace_seconds', 120);
        return ($v >= 0 && $v <= 3600) ? $v : 120;
    }

    private function _retention_days() {
        $v = (int)$this->Settings_model->get('attendance_location_retention_days', 365);
        if ($v === 0) return 0;
        return ($v >= 30 && $v <= 3650) ? $v : 365;
    }

    /** @return array<int,bool> site_id => TRUE when printed QR codes are enabled */
    private function _static_site_map() {
        $raw = (string)$this->Settings_model->get('attendance_static_qr_sites', '');
        $map = array();
        foreach (preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $id) {
            $sid = (int)$id;
            if ($sid > 0) $map[$sid] = TRUE;
        }
        return $map;
    }

    private function _site_allows_static_qr($site_id) {
        $map = $this->_static_site_map();
        return isset($map[(int)$site_id]);
    }

    private function _set_site_static_qr($site_id, $enabled) {
        $site_id = (int)$site_id;
        if ($site_id <= 0) return;
        $map = $this->_static_site_map();
        if ($enabled) {
            $map[$site_id] = TRUE;
        } else {
            unset($map[$site_id]);
        }
        ksort($map);
        $this->Settings_model->save_many(array(
            'attendance_static_qr_sites' => implode(',', array_keys($map)),
        ));
    }

    /**
     * Answer a punch attempt — HTML result page, or JSON when asked.
     */
    private function _finish($ok, $message, $warning = '', $punch = '', $site = NULL) {
        if ($this->input->is_ajax_request() || strtolower((string)$this->input->post('format')) === 'json') {
            return $this->json(array(
                'ok'      => (bool)$ok,
                'message' => $message,
                'warning' => $warning,
                'punch'   => $punch,
            ), $ok ? 200 : 422);
        }

        $date = date('Y-m-d');
        $data = array(
            'ok'        => (bool)$ok,
            'message'   => $message,
            'warning'   => $warning,
            'punch'     => $punch,
            'site'      => $site,
            'today'     => $this->Attendance_model->get_punches($date, $this->user_id),
            'on_site'   => $this->Attendance_model->is_on_site($this->user_id, $date),
            'page_title'=> $ok ? 'Punch recorded' : 'Punch not recorded',
        );
        $this->load->view('attendance/result', $data);
    }
}
