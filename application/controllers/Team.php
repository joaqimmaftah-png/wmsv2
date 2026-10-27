<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Team extends MY_Controller {

    public $Attendance_model;
    public $Timesheet_model;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Team_model', 'Attendance_model', 'Timesheet_model'));
        $this->load->helper('attendance');
    }

    public function index() {
        // Horizontal PII/HR privacy: only Supervisors & Managers view the full workforce directory;
        // Technicians & Store Keepers go cleanly to their own profile record.
        if (!$this->is_supervisor() && !$this->is_manager()) {
            redirect('team/view/' . (int)$this->user_id);
            return;
        }

        $ctx = $this->_build_roster_dataset();

        $per_page_in = (int)$this->input->get('per_page', TRUE);
        $per_page    = in_array($per_page_in, array(10, 25, 50, 100), TRUE) ? $per_page_in : 25;

        $total_filtered = count($ctx['rows']);
        $total_pages    = max(1, (int)ceil($total_filtered / $per_page));
        $page_in        = (int)$this->input->get('page', TRUE);
        $page           = max(1, min($total_pages, $page_in > 0 ? $page_in : 1));
        $offset         = ($page - 1) * $per_page;
        $paged_rows     = array_slice($ctx['rows'], $offset, $per_page);

        $view_in   = strtolower(trim((string)$this->input->get('view', TRUE)));
        $view_mode = ($view_in === 'cards') ? 'cards' : 'table';

        $pending_leave = $this->is_supervisor()
            ? $this->Team_model->get_pending_leave_requests()
            : array();

        // Map worker ID -> roster row so Pending Leave rows surface live open WO workload (0 extra queries)
        $roster_by_id = array();
        foreach ($ctx['all_rows'] as $rw) {
            $roster_by_id[(int)$rw->id] = $rw;
        }

        $data = array(
            'workers'        => $paged_rows,
            'roster_by_id'   => $roster_by_id,
            'pending_leave'  => $pending_leave,
            'total_filtered' => $total_filtered,
            'total_scope'    => $ctx['total_scope'],
            'tab_counts'     => $ctx['tab_counts'],
            'summary'        => array_merge($ctx['summary'], array('pending_leave' => count($pending_leave))),
            'site_filter'    => $ctx['site_filter'],
            'role_filter'    => $ctx['role_filter'],
            'skill_filter'   => $ctx['skill_filter'],
            'search_query'   => $ctx['search_query'],
            'active_tab'     => $ctx['active_tab'],
            'sort_by'        => $ctx['sort_by'],
            'sort_dir'       => $ctx['sort_dir'],
            'per_page'       => $per_page,
            'page'           => $page,
            'total_pages'    => $total_pages,
            'showing_from'   => $total_filtered > 0 ? ($offset + 1) : 0,
            'showing_to'     => min($total_filtered, $offset + count($paged_rows)),
            'view_mode'      => $view_mode,
            'sites'          => $this->Timesheet_model->site_options(),
            'roles_list'     => $this->db->order_by('id', 'ASC')->get('roles')->result(),
            'skills_list'    => array('Junior', 'Senior', 'Expert', 'Specialist'),
            'is_supervisor'  => $this->is_supervisor(),
            'is_manager'     => $this->is_manager(),
            'page_title'     => 'Team Management',
        );
        $this->render('team/index', $data);
    }

    /**
     * Export filtered/sorted Workforce Directory as UTF-8 BOM CSV with formula-injection
     * sanitization (accessible to Supervisors & Managers).
     */
    public function roster_csv() {
        if (!$this->is_supervisor() && !$this->is_manager()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('team/view/' . (int)$this->user_id);
            return;
        }

        $ctx     = $this->_build_roster_dataset();
        $rows    = $ctx['rows'];
        $sel_ids = $this->_parse_selected_ids();
        if (!empty($sel_ids)) {
            $allow = array_fill_keys($sel_ids, TRUE);
            $rows  = array_values(array_filter($rows, function ($r) use ($allow) {
                return isset($allow[(int)$r->id]);
            }));
        }

        $safe_cell = function ($v) {
            $s = (string)$v;
            if ($s !== '' && in_array($s[0], array('=', '+', '-', '@', "\t", "\r"), TRUE)) {
                return "'" . $s;
            }
            return $s;
        };

        $filename = 'WMS_Workforce_Directory_' . date('Y-m-d') . '.csv';
        if (ob_get_level()) @ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array(
            'Employee ID', 'Full Name', 'Role', 'Position', 'Skill Level', 'Site',
            'Status', 'On Site Today', 'Current Shift', 'Active Work Orders',
            'Valid Certs', 'Expiring Certs', 'Expired Certs',
            'Email', 'Phone', 'Join Date',
        ), ',', '"', '\\');

        foreach ($rows as $r) {
            fputcsv($out, array(
                $safe_cell($r->employee_id),
                $safe_cell($r->full_name),
                $safe_cell(ucfirst($r->role_name)),
                $safe_cell($r->position),
                $safe_cell($r->skill_level),
                $safe_cell($r->site),
                $safe_cell($r->effective_status),
                $r->on_site_today ? 'Yes' : 'No',
                $safe_cell($r->current_shift),
                (int)$r->active_wo_count,
                (int)$r->cert_valid,
                (int)$r->cert_expiring,
                (int)$r->cert_expired,
                $safe_cell($r->email),
                $safe_cell($r->phone),
                $safe_cell($r->join_date),
            ), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /**
     * JSON endpoint backing the right-side Slide-Over Staff Inspector Drawer on `/team`.
     */
    public function member_json($user_id) {
        $uid = (int)$user_id;
        $send_json = function ($status_code, array $payload) {
            if (ob_get_level()) @ob_end_clean();
            http_response_code($status_code);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
            echo json_encode($payload);
            exit;
        };

        if ($uid <= 0) {
            $send_json(404, array('ok' => FALSE, 'error' => 'Invalid staff identifier.'));
        }
        if (!$this->is_supervisor() && !$this->is_manager() && $uid !== (int)$this->user_id) {
            $send_json(403, array('ok' => FALSE, 'error' => 'You can only inspect your own staff profile.'));
        }

        $worker = $this->Team_model->get_by_id($uid);
        if (!$worker) {
            $send_json(404, array('ok' => FALSE, 'error' => 'Staff member not found.'));
        }

        $this->Team_model->refresh_cert_status($uid);

        $today   = date('Y-m-d');
        $s_map   = $this->Timesheet_model->resolve_sites_for_workers(array($worker), date('Y-m-01'), date('Y-m-t'), NULL);
        $s_info  = isset($s_map[$uid]) ? $s_map[$uid] : array('site' => '', 'source' => '');
        $on_site = $this->Attendance_model->ready() ? $this->Attendance_model->on_site_map($today) : array();

        $att_rows = $this->Team_model->get_attendance_history($uid, 30);
        $p_cnt = 0; $a_cnt = 0; $l_cnt = 0;
        foreach ($att_rows as $ar) {
            if ($ar->status === 'Present') $p_cnt++;
            elseif ($ar->status === 'Absent') $a_cnt++;
            elseif ($ar->status === 'Leave') $l_cnt++;
        }
        $judged_30 = $p_cnt + $a_cnt + $l_cnt;
        $att_pct   = $judged_30 > 0 ? (int)round($p_cnt / $judged_30 * 100, 0) : NULL;

        $certs_raw = $this->Team_model->get_certifications($uid);
        $certs_out = array();
        foreach ($certs_raw as $c) {
            $days_left = !empty($c->expiry_date)
                ? (int)floor((strtotime($c->expiry_date) - strtotime($today)) / 86400)
                : NULL;
            $certs_out[] = array(
                'cert_name'    => (string)$c->cert_name,
                'cert_number'  => isset($c->cert_number) ? (string)$c->cert_number : '',
                'issuing_body' => isset($c->issuing_body) ? (string)$c->issuing_body : '',
                'expiry_date'  => !empty($c->expiry_date) ? date('d M Y', strtotime($c->expiry_date)) : '',
                'status'       => (string)$c->status,
                'days_left'    => $days_left,
                'document_url' => !empty($c->document) ? base_url($c->document) : '',
            );
        }

        $leaves_raw = array_slice($this->Team_model->get_leave_history($uid), 0, 5);
        $leaves_out = array();
        $eff_status = (string)$worker->status;
        foreach ($leaves_raw as $lr) {
            if ($eff_status === 'Active' && $lr->status === 'Approved'
                && $lr->start_date <= $today && $lr->end_date >= $today) {
                $eff_status = 'On Leave';
            }
            $leaves_out[] = array(
                'leave_type' => (string)$lr->leave_type,
                'start_date' => date('d M Y', strtotime($lr->start_date)),
                'end_date'   => date('d M Y', strtotime($lr->end_date)),
                'days_count' => (int)$lr->days_count,
                'status'     => (string)$lr->status,
            );
        }

        $balances_raw = $this->Team_model->get_leave_balance($uid);
        $annual_rem   = NULL;
        foreach ($balances_raw as $b) {
            if ($b['type'] === 'Annual Leave' && $b['remaining'] !== NULL) {
                $annual_rem = round((float)$b['remaining'], 1);
                break;
            }
        }

        $open_wos = $this->Team_model->get_worker_open_workorders($uid);

        $send_json(200, array(
            'ok'               => TRUE,
            'id'               => $uid,
            'employee_id'      => (string)$worker->employee_id,
            'full_name'        => trim((string)$worker->first_name . ' ' . (string)$worker->last_name),
            'role_name'        => ucfirst((string)$worker->role_name),
            'position'         => isset($worker->position) ? (string)$worker->position : '',
            'skill_level'      => isset($worker->skill_level) ? (string)$worker->skill_level : 'Junior',
            'status'           => $eff_status,
            'site'             => $s_info['site'],
            'site_source'      => $s_info['source'],
            'on_site_today'    => isset($on_site[$uid]),
            'email'            => isset($worker->email) ? (string)$worker->email : '',
            'phone'            => isset($worker->phone) ? (string)$worker->phone : '',
            'join_date'        => !empty($worker->join_date) ? date('d M Y', strtotime($worker->join_date)) : '',
            'last_login'       => !empty($worker->last_login) ? date('d M Y H:i', strtotime($worker->last_login)) : '',
            'photo_url'        => !empty($worker->photo) ? base_url('uploads/photos/' . rawurlencode(basename((string)$worker->photo))) : '',
            'att_30d'          => array(
                'pct'     => $att_pct,
                'present' => $p_cnt,
                'absent'  => $a_cnt,
                'leave'   => $l_cnt,
            ),
            'annual_remaining' => $annual_rem,
            'open_workorders'  => $open_wos,
            'certifications'   => $certs_out,
            'recent_leaves'    => $leaves_out,
        ));
    }

    /**
     * Shared server-side filter, status-tab counting, KPI rollup & deterministic
     * multi-column sort pipeline for `/team` and `/team/roster_csv`.
     */
    private function _build_roster_dataset() {
        $site_filter  = trim((string)$this->input->get('site', TRUE));
        $role_in      = (int)$this->input->get('role', TRUE);
        $role_filter  = ($role_in > 0) ? $role_in : 0;
        $skill_in     = trim((string)$this->input->get('skill', TRUE));
        $valid_skills = array('Junior', 'Senior', 'Expert', 'Specialist');
        $skill_filter = in_array($skill_in, $valid_skills, TRUE) ? $skill_in : '';
        $search_query = trim((string)$this->input->get('q', TRUE));
        if (strlen($search_query) > 100) $search_query = substr($search_query, 0, 100);

        $tab_in     = strtolower(trim((string)$this->input->get('tab', TRUE)));
        $valid_tabs = array('all', 'active', 'on_site', 'on_leave', 'cert_alert', 'inactive');
        $active_tab = in_array($tab_in, $valid_tabs, TRUE) ? $tab_in : 'all';

        $sort_in    = strtolower(trim((string)$this->input->get('sort', TRUE)));
        $valid_sort = array('name', 'employee_id', 'role', 'site', 'skill', 'active_wos', 'certs', 'join_date', 'status');
        $sort_by    = in_array($sort_in, $valid_sort, TRUE) ? $sort_in : 'name';

        $dir_in   = strtolower(trim((string)$this->input->get('dir', TRUE)));
        $sort_dir = ($dir_in === 'desc') ? 'desc' : 'asc';

        $all_rows = $this->Team_model->get_roster_overview();

        // Scope by Role + Skill + Site + Search BEFORE status tab counts so counts match filter scope
        $scoped = array();
        $q_low  = function_exists('mb_strtolower') ? mb_strtolower($search_query, 'UTF-8') : strtolower($search_query);
        foreach ($all_rows as $r) {
            if ($role_filter > 0 && (int)$r->role_id !== $role_filter) continue;
            if ($skill_filter !== '' && $r->skill_level !== $skill_filter) continue;
            if ($site_filter !== '' && strcasecmp($r->site, $site_filter) !== 0) continue;
            if ($q_low !== '') {
                $hay = strtolower(
                    $r->employee_id . ' ' . $r->full_name . ' ' . $r->email . ' ' . $r->phone . ' '
                    . $r->position . ' ' . $r->site . ' ' . $r->role_name . ' ' . $r->skill_level . ' ' . $r->current_shift
                );
                if (strpos($hay, $q_low) === FALSE) continue;
            }
            $scoped[] = $r;
        }

        $tab_counts = array(
            'all'        => count($scoped),
            'active'     => 0,
            'on_site'    => 0,
            'on_leave'   => 0,
            'cert_alert' => 0,
            'inactive'   => 0,
        );
        $sum_wos = 0; $sum_crit_wos = 0; $sum_exp_certs = 0; $sum_soon_certs = 0;

        foreach ($scoped as $r) {
            if ($r->effective_status === 'Active')   $tab_counts['active']++;
            if ($r->effective_status === 'On Leave') $tab_counts['on_leave']++;
            if ($r->effective_status === 'Inactive') $tab_counts['inactive']++;
            if ($r->on_site_today)                   $tab_counts['on_site']++;
            if ($r->has_cert_alert)                  $tab_counts['cert_alert']++;

            $sum_wos        += (int)$r->active_wo_count;
            $sum_crit_wos   += (int)$r->critical_wo_count;
            $sum_exp_certs  += (int)$r->cert_expired;
            $sum_soon_certs += (int)$r->cert_expiring;
        }

        $rows = array();
        foreach ($scoped as $r) {
            if ($active_tab === 'active'     && $r->effective_status !== 'Active')   continue;
            if ($active_tab === 'on_site'    && !$r->on_site_today)                  continue;
            if ($active_tab === 'on_leave'   && $r->effective_status !== 'On Leave') continue;
            if ($active_tab === 'cert_alert' && !$r->has_cert_alert)                 continue;
            if ($active_tab === 'inactive'   && $r->effective_status !== 'Inactive') continue;
            $rows[] = $r;
        }

        $mul = ($sort_dir === 'desc') ? -1 : 1;
        usort($rows, function ($a, $b) use ($sort_by, $mul) {
            $cmp = 0;
            switch ($sort_by) {
                case 'employee_id': $cmp = strnatcasecmp($a->employee_id, $b->employee_id); break;
                case 'role':        $cmp = strnatcasecmp($a->role_name, $b->role_name); break;
                case 'site':        $cmp = strnatcasecmp($a->site, $b->site); break;
                case 'skill':       $cmp = ((int)$a->skill_rank <=> (int)$b->skill_rank); break;
                case 'active_wos':  $cmp = ((int)$a->active_wo_count <=> (int)$b->active_wo_count); break;
                case 'certs':
                    $sa = ((int)$a->cert_expired * 100) + ((int)$a->cert_expiring * 10) + (int)$a->cert_total;
                    $sb = ((int)$b->cert_expired * 100) + ((int)$b->cert_expiring * 10) + (int)$b->cert_total;
                    $cmp = ($sa <=> $sb);
                    break;
                case 'join_date':   $cmp = strcmp($a->join_date !== '' ? $a->join_date : '9999-99-99', $b->join_date !== '' ? $b->join_date : '9999-99-99'); break;
                case 'status':      $cmp = strnatcasecmp($a->effective_status, $b->effective_status); break;
                case 'name':
                default:            $cmp = strnatcasecmp($a->full_name, $b->full_name); break;
            }
            if ($cmp !== 0) return $cmp * $mul;
            $tie = strnatcasecmp($a->full_name, $b->full_name);
            return $tie !== 0 ? $tie : ((int)$a->id <=> (int)$b->id);
        });

        return array(
            'site_filter'  => $site_filter,
            'role_filter'  => $role_filter,
            'skill_filter' => $skill_filter,
            'search_query' => $search_query,
            'active_tab'   => $active_tab,
            'sort_by'      => $sort_by,
            'sort_dir'     => $sort_dir,
            'total_scope'  => count($scoped),
            'tab_counts'   => $tab_counts,
            'summary'      => array(
                'total_staff'    => count($scoped),
                'active_staff'   => $tab_counts['active'],
                'inactive_staff' => $tab_counts['inactive'],
                'on_site_today'  => $tab_counts['on_site'],
                'on_leave_today' => $tab_counts['on_leave'],
                'open_wos'       => $sum_wos,
                'critical_wos'   => $sum_crit_wos,
                'cert_expired'   => $sum_exp_certs,
                'cert_expiring'  => $sum_soon_certs,
            ),
            'all_rows'     => $all_rows,
            'rows'         => $rows,
        );
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
                if ($this->db->field_exists('site', 'users')) {
                    $data['site'] = $field('site') !== '' ? substr($field('site'), 0, 100) : NULL;
                }

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
                'sites'      => $this->Timesheet_model->site_options(),
                'is_edit'    => FALSE,
                'page_title' => 'Add Worker',
            );
            $this->render('team/form', $data);
            return;
        }

        $data['roles']      = $this->db->get('roles')->result();
        $data['sites']      = $this->Timesheet_model->site_options();
        $data['is_edit']    = FALSE;
        $data['page_title'] = 'Add Worker';
        $this->render('team/form', $data);
    }

    public function view($id) {
        if (!$this->is_supervisor() && !$this->is_manager() && (int)$id !== (int)$this->user_id) {
            $this->session->set_flashdata('error', 'You can only view your own staff profile.');
            redirect('team/view/' . (int)$this->user_id);
            return;
        }

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
        $data['worker_site']  = $this->Timesheet_model->resolve_worker_site(
            $data['worker'], date('Y-m-01'), date('Y-m-t')
        );
        $data['can_download_timesheet'] = (
            $this->is_supervisor() || $this->is_manager() || (int)$id === (int)$this->user_id
        );
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
                    'status'       => $record['status'],
                    'check_in'     => $check_in,
                    'check_out'    => $check_out,
                    'hours_worked' => wms_hours_between($check_in, $check_out),
                    'created_by'   => $this->user_id,
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
            redirect(($this->is_supervisor() || $this->is_manager()) ? 'team' : ('team/view/' . (int)$this->user_id));
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
                if ($this->db->field_exists('site', 'users')) {
                    $update['site'] = $field('site') !== '' ? substr($field('site'), 0, 100) : NULL;
                }

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
                'site'        => isset($worker->site) ? $worker->site : NULL,
            ), $post);

            $data = array(
                'errors'     => $errors,
                'worker'     => $posted,
                'roles'      => $this->db->get('roles')->result(),
                'sites'      => $this->Timesheet_model->site_options(),
                'is_edit'    => TRUE,
                'page_title' => 'Edit — ' . $worker->first_name . ' ' . $worker->last_name,
            );
            $this->render('team/form', $data);
            return;
        }

        $data['worker']     = $worker;
        $data['roles']      = $this->db->get('roles')->result();
        $data['sites']      = $this->Timesheet_model->site_options();
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

        $ctx = $this->_build_certifications_dataset();

        $per_page = $ctx['per_page'];
        $total_filtered = ($ctx['active_tab'] === 'none')
            ? count($ctx['worker_rows']) : count($ctx['rows']);
        $total_pages = max(1, (int)ceil($total_filtered / $per_page));
        $page = max(1, min($total_pages, $ctx['page']));
        $offset = ($page - 1) * $per_page;

        $data = array(
            'records'         => ($ctx['active_tab'] === 'none') ? array() : array_slice($ctx['rows'], $offset, $per_page),
            'worker_records'  => ($ctx['active_tab'] === 'none') ? array_slice($ctx['worker_rows'], $offset, $per_page) : array(),
            // Backward-compatible keys (attention/alert consumers of this view's old vars)
            'certs'           => $ctx['rows'],
            'workers'         => $ctx['workers_scope'],
            'tab_counts'      => $ctx['tab_counts'],
            'summary'         => $ctx['summary'],
            'cert_types'      => $ctx['cert_types'],
            'search_query'    => $ctx['search_query'],
            'cert_filter'     => $ctx['cert_filter'],
            'doc_filter'      => $ctx['doc_filter'],
            'active_tab'      => $ctx['active_tab'],
            'sort_by'         => $ctx['sort_by'],
            'sort_dir'        => $ctx['sort_dir'],
            'per_page'        => $per_page,
            'page'            => $page,
            'total_pages'     => $total_pages,
            'total_filtered'  => $total_filtered,
            'showing_from'    => $total_filtered > 0 ? ($offset + 1) : 0,
            'showing_to'      => min($total_filtered, $offset + $per_page),
            'view_mode'       => $ctx['view_mode'],
            'can_view_all'    => $ctx['can_view_all'],
            'is_supervisor'   => $this->is_supervisor(),
            'cert_alert_days' => $ctx['cert_alert_days'],
            'page_title'      => $ctx['can_view_all'] ? 'Certifications Tracker' : 'My Certifications',
        );
        $this->render('team/certifications', $data);
    }

    /**
     * Export the currently filtered Certifications Tracker dataset as UTF-8
     * BOM CSV (formula-injection sanitised, like roster_csv). Role scoping
     * matches the tracker: Supervisors/Managers export the workforce set,
     * Technicians/Store Keepers only their own records.
     */
    public function certifications_csv() {
        $ctx = $this->_build_certifications_dataset();

        $safe_cell = function ($v) {
            $s = (string)$v;
            if ($s !== '' && in_array($s[0], array('=', '+', '-', '@', "\t", "\r"), TRUE)) {
                return "'" . $s;
            }
            return $s;
        };

        $filename = 'WMS_Certifications_' . date('Y-m-d') . '.csv';
        if (ob_get_level()) @ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        if ($ctx['active_tab'] === 'none') {
            fputcsv($out, array('Employee ID', 'Worker', 'Position', 'Certificates Recorded'), ',', '"', '\\');
            foreach ($ctx['worker_rows'] as $w) {
                fputcsv($out, array(
                    $safe_cell($w->employee_id), $safe_cell($w->full_name),
                    $safe_cell($w->position), 0,
                ), ',', '"', '\\');
            }
            fclose($out);
            exit;
        }

        fputcsv($out, array(
            'Employee ID', 'Worker', 'Position', 'Certificate', 'Certificate Number',
            'Issuing Body', 'Issued Date', 'Expiry Date', 'Days Left', 'Status', 'Document',
        ), ',', '"', '\\');

        $today = strtotime(date('Y-m-d'));
        foreach ($ctx['rows'] as $c) {
            $days = !empty($c->expiry_date)
                ? (int)floor((strtotime($c->expiry_date) - $today) / 86400) : NULL;
            fputcsv($out, array(
                $safe_cell($c->employee_id),
                $safe_cell($c->worker_name),
                $safe_cell(isset($c->position) ? $c->position : ''),
                $safe_cell($c->cert_name),
                $safe_cell(isset($c->cert_number) ? $c->cert_number : ''),
                $safe_cell(isset($c->issuing_body) ? $c->issuing_body : ''),
                $safe_cell(isset($c->issued_date) ? $c->issued_date : ''),
                $safe_cell(isset($c->expiry_date) ? $c->expiry_date : ''),
                $days === NULL ? '' : $days,
                $safe_cell(!empty($c->expiry_date) ? $c->status : 'No expiry date'),
                $safe_cell(isset($c->document) ? $c->document : ''),
            ), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /**
     * Shared filter / tab-count / summary / sort pipeline for
     * `team/certifications` and `team/certifications_csv`, so the screen and
     * the export always describe the same dataset. Role scoping is applied
     * first (CERT-01): Supervisors & Managers see the workforce; other roles
     * only their own certificates and their own worker row.
     */
    private function _build_certifications_dataset() {
        $this->Team_model->refresh_cert_status();
        $alert_days = $this->_cert_alert_days();

        $can_view_all = ($this->is_supervisor() || $this->is_manager());
        $only_uid     = $can_view_all ? NULL : (int)$this->user_id;

        $search_query = trim((string)$this->input->get('q', TRUE));
        if (strlen($search_query) > 100) $search_query = substr($search_query, 0, 100);
        $cert_filter  = trim((string)$this->input->get('cert', TRUE));
        $doc_in       = strtolower(trim((string)$this->input->get('doc', TRUE)));
        $doc_filter   = in_array($doc_in, array('with', 'without'), TRUE) ? $doc_in : '';

        $tab_in     = strtolower(trim((string)$this->input->get('tab', TRUE)));
        $valid_tabs = array('all', 'expired', 'expiring', 'valid', 'no_expiry', 'none');
        $active_tab = in_array($tab_in, $valid_tabs, TRUE) ? $tab_in : 'all';

        $sort_in    = strtolower(trim((string)$this->input->get('sort', TRUE)));
        $valid_sort = array('expiry', 'worker', 'certificate', 'issued', 'status');
        $sort_by    = in_array($sort_in, $valid_sort, TRUE) ? $sort_in : 'expiry';
        $dir_in     = strtolower(trim((string)$this->input->get('dir', TRUE)));
        $sort_dir   = ($dir_in === 'desc') ? 'desc' : 'asc';

        $per_page_in = (int)$this->input->get('per_page', TRUE);
        $per_page    = in_array($per_page_in, array(10, 25, 50, 100), TRUE) ? $per_page_in : 25;
        $page        = max(1, (int)$this->input->get('page', TRUE));
        $view_in     = strtolower(trim((string)$this->input->get('view', TRUE)));
        $view_mode   = ($view_in === 'cards') ? 'cards' : 'table';

        $all_records = $this->Team_model->get_certifications_overview($only_uid);

        if ($can_view_all) {
            $workers_scope = $this->Team_model->get_all_active();
        } else {
            $me = $this->Team_model->get_by_id((int)$this->user_id);
            $workers_scope = $me ? array($me) : array();
        }

        // Certificate types present in the role-scoped data (filter options
        // come from real records only — no invented catalogue).
        $cert_types = array();
        $cert_user_ids = array();
        foreach ($all_records as $r) {
            $cert_types[(string)$r->cert_name] = TRUE;
            $cert_user_ids[(int)$r->user_id] = TRUE;
        }
        $cert_types = array_keys($cert_types);
        sort($cert_types, SORT_NATURAL | SORT_FLAG_CASE);
        if ($cert_filter !== '' && !in_array($cert_filter, $cert_types, TRUE)) $cert_filter = '';

        // Search + certificate/document filters BEFORE tab counts so counts
        // describe the filtered scope (house counting rule).
        $q_low = function_exists('mb_strtolower') ? mb_strtolower($search_query, 'UTF-8') : strtolower($search_query);
        $scoped = array();
        foreach ($all_records as $r) {
            if ($cert_filter !== '' && (string)$r->cert_name !== $cert_filter) continue;
            if ($doc_filter === 'with' && empty($r->document)) continue;
            if ($doc_filter === 'without' && !empty($r->document)) continue;
            if ($q_low !== '') {
                $hay = strtolower(
                    $r->worker_name . ' ' . $r->employee_id . ' ' . $r->cert_name . ' '
                    . (isset($r->cert_number) ? $r->cert_number : '') . ' '
                    . (isset($r->issuing_body) ? $r->issuing_body : '') . ' '
                    . (isset($r->position) ? $r->position : '')
                );
                if (strpos($hay, $q_low) === FALSE) continue;
            }
            $scoped[] = $r;
        }

        $today = strtotime(date('Y-m-d'));
        $days_left = function ($r) use ($today) {
            return !empty($r->expiry_date)
                ? (int)floor((strtotime($r->expiry_date) - $today) / 86400) : NULL;
        };

        $tab_counts = array('all' => count($scoped), 'expired' => 0, 'expiring' => 0, 'valid' => 0, 'no_expiry' => 0, 'none' => 0);
        foreach ($scoped as $r) {
            $d = $days_left($r);
            if ($d === NULL) $tab_counts['no_expiry']++;
            elseif ($d < 0) $tab_counts['expired']++;
            elseif ($d <= $alert_days) $tab_counts['expiring']++;
            else $tab_counts['valid']++;
        }

        // Workers in role scope with zero certificates recorded. Search
        // applies to worker identity fields; cert/doc filters do not apply
        // (these workers have no certificates to filter).
        $worker_rows = array();
        foreach ($workers_scope as $w) {
            if (isset($cert_user_ids[(int)$w->id])) continue;
            if ($q_low !== '') {
                $hay = strtolower($w->first_name . ' ' . $w->last_name . ' ' . $w->employee_id . ' ' . (isset($w->position) ? $w->position : ''));
                if (strpos($hay, $q_low) === FALSE) continue;
            }
            $w = clone $w;
            $w->full_name = trim($w->first_name . ' ' . $w->last_name);
            $worker_rows[] = $w;
        }
        $tab_counts['none'] = count($worker_rows);
        usort($worker_rows, function ($a, $b) {
            $t = strnatcasecmp($a->full_name, $b->full_name);
            return $t !== 0 ? $t : ((int)$a->id <=> (int)$b->id);
        });

        $rows = array();
        foreach ($scoped as $r) {
            $d = $days_left($r);
            if ($active_tab === 'expired'   && !($d !== NULL && $d < 0)) continue;
            if ($active_tab === 'expiring'  && !($d !== NULL && $d >= 0 && $d <= $alert_days)) continue;
            if ($active_tab === 'valid'     && !($d !== NULL && $d > $alert_days)) continue;
            if ($active_tab === 'no_expiry' && $d !== NULL) continue;
            if ($active_tab === 'none') continue;
            $rows[] = $r;
        }

        $status_rank = function ($r) use ($days_left, $alert_days) {
            $d = $days_left($r);
            if ($d === NULL) return 3;
            if ($d < 0) return 0;
            return ($d <= $alert_days) ? 1 : 2;
        };
        $mul = ($sort_dir === 'desc') ? -1 : 1;
        usort($rows, function ($a, $b) use ($sort_by, $mul, $status_rank) {
            // Records without a date always sort last, in both directions.
            $cmp = 0;
            switch ($sort_by) {
                case 'worker':      $cmp = strnatcasecmp($a->worker_name, $b->worker_name); break;
                case 'certificate': $cmp = strnatcasecmp($a->cert_name, $b->cert_name); break;
                case 'issued':
                    if (empty($a->issued_date) XOR empty($b->issued_date)) return empty($a->issued_date) ? 1 : -1;
                    $cmp = strcmp((string)$a->issued_date, (string)$b->issued_date); break;
                case 'status':      $cmp = ($status_rank($a) <=> $status_rank($b)); break;
                case 'expiry':
                default:
                    if (empty($a->expiry_date) XOR empty($b->expiry_date)) return empty($a->expiry_date) ? 1 : -1;
                    $cmp = strcmp((string)$a->expiry_date, (string)$b->expiry_date); break;
            }
            if ($cmp !== 0) return $cmp * $mul;
            $tie = strnatcasecmp($a->worker_name, $b->worker_name);
            if ($tie !== 0) return $tie;
            $tie = strnatcasecmp($a->cert_name, $b->cert_name);
            return $tie !== 0 ? $tie : ((int)$a->id <=> (int)$b->id);
        });

        return array(
            'can_view_all'   => $can_view_all,
            'cert_alert_days'=> $alert_days,
            'search_query'   => $search_query,
            'cert_filter'    => $cert_filter,
            'doc_filter'     => $doc_filter,
            'active_tab'     => $active_tab,
            'sort_by'        => $sort_by,
            'sort_dir'       => $sort_dir,
            'per_page'       => $per_page,
            'page'           => $page,
            'view_mode'      => $view_mode,
            'cert_types'     => $cert_types,
            'workers_scope'  => $workers_scope,
            'tab_counts'     => $tab_counts,
            'summary'        => array(
                'total'    => $tab_counts['all'],
                'valid'    => $tab_counts['valid'],
                'expiring' => $tab_counts['expiring'],
                'expired'  => $tab_counts['expired'],
                'no_expiry'=> $tab_counts['no_expiry'],
                'none'     => $tab_counts['none'],
                'workers'  => count($workers_scope),
            ),
            'rows'           => $rows,
            'worker_rows'    => $worker_rows,
        );
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
        $ctx = $this->_build_report_dataset();

        $per_page_in = (int)$this->input->get('per_page', TRUE);
        $per_page = in_array($per_page_in, array(10, 25, 50, 100), TRUE) ? $per_page_in : 25;

        $total_filtered = count($ctx['rows']);
        $total_pages    = max(1, (int)ceil($total_filtered / $per_page));
        $page_in        = (int)$this->input->get('page', TRUE);
        $page           = max(1, min($total_pages, $page_in > 0 ? $page_in : 1));
        $offset         = ($page - 1) * $per_page;
        $paged_rows     = array_slice($ctx['rows'], $offset, $per_page);

        $view_in   = strtolower(trim((string)$this->input->get('view', TRUE)));
        $view_mode = ($view_in === 'cards') ? 'cards' : 'table';

        $data = array(
            'report'           => $paged_rows,
            'total_filtered'   => $total_filtered,
            'total_scope'      => $ctx['total_scope'],
            'tab_counts'       => $ctx['tab_counts'],
            'summary'          => $ctx['summary'],
            'month'            => $ctx['period']['month'],
            'period'           => $ctx['period'],
            'site_filter'      => $ctx['site_filter'],
            'role_filter'      => $ctx['role_filter'],
            'search_query'     => $ctx['search_query'],
            'active_tab'       => $ctx['active_tab'],
            'sort_by'          => $ctx['sort_by'],
            'sort_dir'         => $ctx['sort_dir'],
            'per_page'         => $per_page,
            'page'             => $page,
            'total_pages'      => $total_pages,
            'showing_from'     => $total_filtered > 0 ? ($offset + 1) : 0,
            'showing_to'       => min($total_filtered, $offset + count($paged_rows)),
            'view_mode'        => $view_mode,
            'sites'            => $this->Timesheet_model->site_options(),
            'roles_list'       => $this->db->order_by('id', 'ASC')->get('roles')->result(),
            'can_batch'        => $ctx['can_view_all'],
            'is_supervisor'    => $this->is_supervisor(),
            'current_user_id'  => (int)$this->user_id,
            'page_title'       => $ctx['can_view_all']
                ? 'Attendance Report & Individual Timesheets'
                : 'My Attendance & Timesheet',
        );
        $this->render('team/report', $data);
    }

    /**
     * Export the currently filtered/sorted Attendance Summary table as UTF-8 CSV
     * (respecting RBAC scoping, period, site, role, status tab, search & selection).
     */
    public function report_csv() {
        $ctx  = $this->_build_report_dataset();
        $rows = $ctx['rows'];

        $sel_ids = $this->_parse_selected_ids();
        if (!empty($sel_ids)) {
            $allow = array_fill_keys($sel_ids, TRUE);
            $rows  = array_values(array_filter($rows, function ($r) use ($allow) {
                return isset($allow[(int)$r->id]);
            }));
        }

        $safe_cell = function ($v) {
            $s = (string)$v;
            if ($s !== '' && in_array($s[0], array('=', '+', '-', '@', "\t", "\r"), TRUE)) {
                return "'" . $s;
            }
            return $s;
        };

        $filename = 'AML_Attendance_Summary_' . $ctx['period']['file_slug'] . '.csv';
        if (ob_get_level()) @ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array(
            'Employee ID', 'Name', 'Role', 'Position', 'Site',
            'Present', 'Absent', 'Leave', 'Rest', 'Holiday',
            'Hours Worked', 'Working Days', 'Attendance Rate',
        ), ',', '"', '\\');

        foreach ($rows as $r) {
            $rate = ($r->pct === NULL) ? 'No records' : ($r->pct . '%');
            fputcsv($out, array(
                $safe_cell($r->employee_id),
                $safe_cell($r->full_name),
                $safe_cell($r->role_name),
                $safe_cell($r->position),
                $safe_cell($r->site),
                (int)$r->present,
                (int)$r->absent,
                (int)$r->leave,
                (int)$r->rest,
                (int)$r->holiday,
                (float)$r->hours_worked,
                (int)$r->total,
                $rate,
            ), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    /**
     * JSON endpoint backing the right-side Slide-Over AML Attendance Sheet
     * Inspector on `/team/report` (avoids losing table state or downloading
     * files just to verify daily check-ins, check-outs & observations).
     */
    public function sheet_json($user_id) {
        $uid = (int)$user_id;
        $send_json = function ($status_code, array $payload) {
            if (ob_get_level()) @ob_end_clean();
            http_response_code($status_code);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
            echo json_encode($payload);
            exit;
        };

        if ($uid <= 0) {
            $send_json(404, array('ok' => FALSE, 'error' => 'Invalid worker identifier.'));
        }
        if (!$this->is_supervisor() && !$this->is_manager() && $uid !== (int)$this->user_id) {
            $send_json(403, array('ok' => FALSE, 'error' => 'You may only inspect your own attendance sheet.'));
        }

        $period = wms_timesheet_resolve_period(
            $this->input->get('month', TRUE),
            $this->input->get('period', TRUE),
            $this->input->get('from', TRUE),
            $this->input->get('to', TRUE)
        );
        $site_override = trim((string)$this->input->get('site', TRUE));

        $sheets = $this->Timesheet_model->build_for_worker($uid, $period, $site_override);
        if (empty($sheets)) {
            $send_json(404, array('ok' => FALSE, 'error' => 'Worker not found.'));
        }

        $first = $sheets[0];
        $segments_out = array();
        $tot_days_worked = 0;
        $tot_hours = 0.0;
        $missing_out = 0;

        foreach ($sheets as $sh) {
            $g = $sh['grid'];
            $tot_days_worked += (int)$g['days_worked'];
            $tot_hours += (float)$g['total_hours'];
            foreach ($g['weeks'] as $wk) {
                foreach ($wk['days'] as $d) {
                    if ($d['time_in'] !== '' && $d['time_out'] === '' && $d['status'] === 'Present') {
                        $missing_out++;
                    }
                }
            }
            $segments_out[] = array(
                'index'         => (int)$sh['segment_index'],
                'count'         => (int)$sh['segment_count'],
                'month_heading' => (string)$sh['month_heading'],
                'start_date'    => (string)$g['start_date'],
                'end_date'      => (string)$g['end_date'],
                'days_worked'   => (int)$g['days_worked'],
                'total_hours'   => (string)$g['total_hours_label'],
                'weeks'         => $g['weeks'],
            );
        }

        $send_json(200, array(
            'ok'               => TRUE,
            'user_id'          => $uid,
            'employee_id'      => (string)$first['employee_id'],
            'full_name'        => (string)$first['full_name'],
            'position'         => (string)$first['position'],
            'role_name'        => isset($first['role_name']) ? (string)$first['role_name'] : '',
            'site'             => (string)$first['site'],
            'site_source'      => isset($first['site_source']) ? (string)$first['site_source'] : '',
            'period_label'     => (string)$period['period_label'],
            'total_days'       => $tot_days_worked,
            'total_hours'      => wms_timesheet_format_hours($tot_hours),
            'missing_checkout' => $missing_out,
            'signatories'      => $first['signatories'],
            'segments'         => $segments_out,
        ));
    }

    /**
     * Download or preview a single worker's AML Individual Attendance Sheet
     * (`PDF` by default, or native `.xlsx` when `?format=xlsx`).
     *
     * Supervisors & Managers may export any worker; Technicians & Store Keepers
     * may download their own sheet.
     */
    public function timesheet($user_id) {
        $uid = (int)$user_id;
        if ($uid <= 0) show_404();

        if (!$this->is_supervisor() && !$this->is_manager() && $uid !== (int)$this->user_id) {
            $this->session->set_flashdata('error', 'You can only download your own attendance sheet.');
            redirect('team/view/' . (int)$this->user_id);
            return;
        }

        $period = wms_timesheet_resolve_period(
            $this->input->get('month', TRUE),
            $this->input->get('period', TRUE),
            $this->input->get('from', TRUE),
            $this->input->get('to', TRUE)
        );
        $site_override = trim((string)$this->input->get('site', TRUE));

        $sheets = $this->Timesheet_model->build_for_worker($uid, $period, $site_override);
        if (empty($sheets)) show_404();

        $emp_slug  = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$sheets[0]['employee_id']);
        $name_slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$sheets[0]['full_name']);
        $base_name = trim('AML_Timesheet_' . $emp_slug . '_' . $name_slug . '_' . $period['file_slug'], '_');

        $this->_stream_timesheet_export($sheets, $base_name);
    }

    /**
     * One-click batch download of AML Individual Attendance Sheets across all
     * matching or explicitly selected active staff (multi-page PDF or `.xlsx`).
     */
    public function timesheets_batch() {
        if (!$this->is_supervisor() && !$this->is_manager()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('dashboard');
            return;
        }

        $ctx = $this->_build_report_dataset();
        $sel_ids = $this->_parse_selected_ids();

        // Filter matched IDs from active table criteria (tab/role/search/site) and optional checkbox selection
        $matched_ids = array();
        $allow_map   = !empty($sel_ids) ? array_fill_keys($sel_ids, TRUE) : NULL;
        foreach ($ctx['rows'] as $r) {
            $id = (int)$r->id;
            if ($allow_map !== NULL && !isset($allow_map[$id])) continue;
            $matched_ids[] = $id;
        }

        if (empty($matched_ids)) {
            $this->session->set_flashdata('error', 'No active workers matched the selected filter.');
            redirect('team/report?month=' . rawurlencode($ctx['period']['month']));
            return;
        }

        $sheets = $this->Timesheet_model->build_for_all_active(
            $ctx['period'],
            $ctx['site_filter'],
            $matched_ids
        );
        if (empty($sheets)) {
            $this->session->set_flashdata('error', 'No active workers matched the selected filter.');
            redirect('team/report?month=' . rawurlencode($ctx['period']['month']));
            return;
        }

        $site_slug = $ctx['site_filter'] !== '' ? ('_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $ctx['site_filter'])) : '';
        $sel_slug  = !empty($sel_ids) ? ('_Selected_' . count($matched_ids)) : '_All_Workers';
        $base_name = 'AML_Timesheets' . $sel_slug . $site_slug . '_' . $ctx['period']['file_slug'];

        $this->_stream_timesheet_export($sheets, $base_name);
    }

    /** Parse optional comma-separated positive integer IDs (`?ids=2,5,9`). */
    private function _parse_selected_ids() {
        $raw = trim((string)$this->input->get('ids', TRUE));
        if ($raw === '') return array();
        $out = array();
        foreach (explode(',', $raw) as $part) {
            $n = (int)trim($part);
            if ($n > 0) $out[$n] = $n;
        }
        return array_values($out);
    }

    /**
     * Shared server-side filter, status-tab counting, KPI aggregation & sort
     * pipeline so HTML table view, card view, CSV export, and batch PDF/XLSX
     * export always operate on the exact same dataset & RBAC rules.
     */
    private function _build_report_dataset() {
        $period = wms_timesheet_resolve_period(
            $this->input->get('month', TRUE),
            $this->input->get('period', TRUE),
            $this->input->get('from', TRUE),
            $this->input->get('to', TRUE)
        );
        $site_filter  = trim((string)$this->input->get('site', TRUE));
        $role_in      = (int)$this->input->get('role', TRUE);
        $role_filter  = ($role_in > 0) ? $role_in : 0;
        $search_query = trim((string)$this->input->get('q', TRUE));
        if (strlen($search_query) > 100) $search_query = substr($search_query, 0, 100);

        $tab_in     = strtolower(trim((string)$this->input->get('tab', TRUE)));
        $valid_tabs = array('all', 'recorded', 'unrecorded', 'absent', 'leave');
        $active_tab = in_array($tab_in, $valid_tabs, TRUE) ? $tab_in : 'all';

        $sort_in    = strtolower(trim((string)$this->input->get('sort', TRUE)));
        $valid_sort = array('name', 'employee_id', 'site', 'present', 'absent', 'leave', 'hours', 'total', 'pct');
        $sort_by    = in_array($sort_in, $valid_sort, TRUE) ? $sort_in : 'name';

        $dir_in   = strtolower(trim((string)$this->input->get('dir', TRUE)));
        $sort_dir = ($dir_in === 'desc') ? 'desc' : 'asc';

        // Strict RBAC horizontal privacy: only Supervisor (1) and Manager (4) view all staff
        $can_view_all = ($this->is_supervisor() || $this->is_manager());
        $only_uid     = $can_view_all ? NULL : (int)$this->user_id;

        $base_rows = $this->Team_model->get_monthly_report(
            $period['month'],
            $period['start_date'],
            $period['end_date'],
            $site_filter,
            $only_uid
        );

        // Apply Role + Search filter BEFORE computing Status Tab counts so counts match search/filter scope
        $scoped = array();
        $q_low  = function_exists('mb_strtolower') ? mb_strtolower($search_query, 'UTF-8') : strtolower($search_query);
        foreach ($base_rows as $r) {
            if ($role_filter > 0 && (int)$r->role_id !== $role_filter) {
                continue;
            }
            if ($q_low !== '') {
                $hay = strtolower(
                    $r->employee_id . ' ' . $r->full_name . ' ' . $r->position . ' ' . $r->site . ' ' . $r->role_name
                );
                if (strpos($hay, $q_low) === FALSE) {
                    continue;
                }
            }
            $scoped[] = $r;
        }

        // Compute transparent Status Tab counts across current search/site/role dataset
        $tab_counts = array(
            'all'        => count($scoped),
            'recorded'   => 0,
            'unrecorded' => 0,
            'absent'     => 0,
            'leave'      => 0,
        );
        $sum_present = 0; $sum_absent = 0; $sum_leave = 0;
        $sum_hours = 0.0; $sum_judged = 0; $sum_missing_out = 0;

        foreach ($scoped as $r) {
            if ($r->has_records) $tab_counts['recorded']++;
            else $tab_counts['unrecorded']++;
            if ((int)$r->absent > 0) $tab_counts['absent']++;
            if ((int)$r->leave > 0)  $tab_counts['leave']++;

            $sum_present     += (int)$r->present;
            $sum_absent      += (int)$r->absent;
            $sum_leave       += (int)$r->leave;
            $sum_hours       += (float)$r->hours_worked;
            $sum_judged      += (int)$r->total;
            $sum_missing_out += (int)$r->missing_checkout;
        }

        // Apply active Status Tab filter
        $rows = array();
        foreach ($scoped as $r) {
            if ($active_tab === 'recorded'   && !$r->has_records)      continue;
            if ($active_tab === 'unrecorded' && $r->has_records)       continue;
            if ($active_tab === 'absent'     && (int)$r->absent <= 0)  continue;
            if ($active_tab === 'leave'      && (int)$r->leave <= 0)   continue;
            $rows[] = $r;
        }

        // Deterministic multi-column sorting (tie-broken by full_name ASC, id ASC)
        $mul = ($sort_dir === 'desc') ? -1 : 1;
        usort($rows, function ($a, $b) use ($sort_by, $mul) {
            $cmp = 0;
            switch ($sort_by) {
                case 'employee_id': $cmp = strnatcasecmp($a->employee_id, $b->employee_id); break;
                case 'site':        $cmp = strnatcasecmp($a->site, $b->site); break;
                case 'present':     $cmp = ((int)$a->present <=> (int)$b->present); break;
                case 'absent':      $cmp = ((int)$a->absent  <=> (int)$b->absent); break;
                case 'leave':       $cmp = ((int)$a->leave   <=> (int)$b->leave); break;
                case 'hours':       $cmp = ((float)$a->hours_worked <=> (float)$b->hours_worked); break;
                case 'total':       $cmp = ((int)$a->total   <=> (int)$b->total); break;
                case 'pct':
                    $pa = ($a->pct === NULL) ? -1 : (int)$a->pct;
                    $pb = ($b->pct === NULL) ? -1 : (int)$b->pct;
                    $cmp = ($pa <=> $pb);
                    break;
                case 'name':
                default:
                    $cmp = strnatcasecmp($a->full_name, $b->full_name);
                    break;
            }
            if ($cmp !== 0) return $cmp * $mul;
            $tie = strnatcasecmp($a->full_name, $b->full_name);
            return $tie !== 0 ? $tie : ((int)$a->id <=> (int)$b->id);
        });

        return array(
            'period'       => $period,
            'site_filter'  => $site_filter,
            'role_filter'  => $role_filter,
            'search_query' => $search_query,
            'active_tab'   => $active_tab,
            'sort_by'      => $sort_by,
            'sort_dir'     => $sort_dir,
            'can_view_all' => $can_view_all,
            'total_scope'  => count($scoped),
            'tab_counts'   => $tab_counts,
            'summary'      => array(
                'staff_count'      => count($scoped),
                'recorded_staff'   => $tab_counts['recorded'],
                'unrecorded_staff' => $tab_counts['unrecorded'],
                'present_days'     => $sum_present,
                'absent_days'      => $sum_absent,
                'leave_days'       => $sum_leave,
                'hours_worked'     => round($sum_hours, 2),
                'missing_checkout' => $sum_missing_out,
                'overall_pct'      => $sum_judged > 0 ? (int)round($sum_present / $sum_judged * 100, 0) : NULL,
            ),
            'rows'         => $rows,
        );
    }

    /** Stream either PDF or XLSX binary cleanly with safe HTTP headers. */
    private function _stream_timesheet_export(array $sheets, $base_name) {
        $format  = strtolower(trim((string)$this->input->get('format', TRUE)));
        $preview = ((string)$this->input->get('preview', TRUE) === '1');

        if ($format === 'xlsx' || $format === 'excel') {
            require_once APPPATH . 'libraries/Timesheet_excel.php';
            $bytes = (new Timesheet_excel())->render($sheets);
            $filename = $base_name . '.xlsx';
            if (ob_get_level()) @ob_end_clean();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: private, max-age=0, must-revalidate');
            echo $bytes;
            exit;
        }

        require_once APPPATH . 'libraries/Timesheet_pdf.php';
        $bytes = (new Timesheet_pdf($sheets))->render();
        $filename = $base_name . '.pdf';
        $disp = $preview ? 'inline' : 'attachment';
        if (ob_get_level()) @ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disp . '; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $bytes;
        exit;
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
