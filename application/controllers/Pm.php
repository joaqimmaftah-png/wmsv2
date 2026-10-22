<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Pm extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Pm_model');
        $this->load->model('Equipment_model');
    }

    /**
     * Shared PM Planner dataset builder powering `/pm` (table & card modes) and `/pm/export_csv`.
     * Executes in constant O(1) SQL queries regardless of schedule count.
     */
    private function _build_planner_dataset($paginate = TRUE) {
        $overview = $this->Pm_model->get_planner_overview();
        $all_rows = $overview['rows'];

        $tab = strtolower(trim((string)$this->input->get('tab')));
        $legacy_status = strtolower(trim((string)$this->input->get('status')));
        if ($tab === '' && $legacy_status !== '') {
            if (in_array($legacy_status, array('overdue', 'due_soon', 'ok', 'inactive'), TRUE)) {
                $tab = $legacy_status;
            } elseif ($legacy_status === 'all') {
                $tab = 'active';
            }
        }
        $valid_tabs = array('active', 'overdue', 'due_soon', 'ok', 'parts_short', 'forecast_alert', 'inactive');
        if (!in_array($tab, $valid_tabs, TRUE)) $tab = 'active';

        $q = trim((string)$this->input->get('q'));
        if ($q === '') $q = trim((string)$this->input->get('search'));

        $f_equip    = (int)$this->input->get('equipment_id');
        $f_interval = trim((string)$this->input->get('interval_type'));
        if (!in_array($f_interval, array('', 'Hours', 'Days', 'Months', 'Km'), TRUE)) $f_interval = '';
        $f_category = trim((string)$this->input->get('category'));
        $f_location = trim((string)$this->input->get('location'));

        $ids_raw = trim((string)$this->input->get('ids'));
        $id_whitelist = array();
        if ($ids_raw !== '') {
            foreach (explode(',', $ids_raw) as $part) {
                $id_int = (int)trim($part);
                if ($id_int > 0) $id_whitelist[$id_int] = TRUE;
            }
        }

        $tab_counts = array(
            'active'         => 0,
            'overdue'        => 0,
            'due_soon'       => 0,
            'ok'             => 0,
            'parts_short'    => 0,
            'forecast_alert' => 0,
            'inactive'       => 0,
        );
        $kpi = array(
            'active_total'    => 0,
            'inactive_total'  => 0,
            'overdue'         => 0,
            'due_soon'        => 0,
            'due_today'       => 0,
            'planned_std_hrs' => 0.0,
            'missing_std_hrs' => 0,
            'parts_ready'     => 0,
            'parts_short'     => 0,
        );

        foreach ($all_rows as $pm) {
            if ($pm->compliance_state === 'inactive') {
                $tab_counts['inactive']++;
                $kpi['inactive_total']++;
                continue;
            }
            $tab_counts['active']++;
            $kpi['active_total']++;

            if ($pm->compliance_state === 'overdue') {
                $tab_counts['overdue']++;
                $kpi['overdue']++;
            } elseif ($pm->compliance_state === 'due_soon') {
                $tab_counts['due_soon']++;
                $kpi['due_soon']++;
                if ($pm->days_left === 0) $kpi['due_today']++;
            } elseif ($pm->compliance_state === 'ok') {
                $tab_counts['ok']++;
            }

            if ($pm->std_hours_val !== NULL) {
                $kpi['planned_std_hrs'] += (float)$pm->std_hours_val;
            } else {
                $kpi['missing_std_hrs']++;
            }

            if ((int)$pm->shortage_count > 0) {
                $tab_counts['parts_short']++;
                $kpi['parts_short']++;
            } elseif ((int)$pm->parts_count > 0) {
                $kpi['parts_ready']++;
            }

            if (!empty($pm->has_forecast_alert)) {
                $tab_counts['forecast_alert']++;
            }
        }

        $q_low = strtolower($q);
        $filtered = array();
        foreach ($all_rows as $pm) {
            if (!empty($id_whitelist) && empty($id_whitelist[(int)$pm->id])) {
                continue;
            } elseif (empty($id_whitelist)) {
                if ($tab === 'inactive') {
                    if ($pm->compliance_state !== 'inactive') continue;
                } else {
                    if ($pm->compliance_state === 'inactive') continue;
                    if ($tab === 'overdue' && $pm->compliance_state !== 'overdue') continue;
                    if ($tab === 'due_soon' && $pm->compliance_state !== 'due_soon') continue;
                    if ($tab === 'ok' && $pm->compliance_state !== 'ok') continue;
                    if ($tab === 'parts_short' && (int)$pm->shortage_count <= 0) continue;
                    if ($tab === 'forecast_alert' && empty($pm->has_forecast_alert)) continue;
                }
            }

            if ($f_equip > 0 && (int)$pm->equipment_id !== $f_equip) continue;
            if ($f_interval !== '' && (string)$pm->interval_type !== $f_interval) continue;
            if ($f_category !== '' && strcasecmp((string)$pm->category, $f_category) !== 0) continue;
            if ($f_location !== '' && strcasecmp((string)$pm->location, $f_location) !== 0) continue;

            if ($q_low !== '') {
                $hay = strtolower(implode(' ', array(
                    (string)$pm->registration_no,
                    (string)$pm->brand,
                    (string)$pm->model,
                    (string)$pm->type_name,
                    (string)$pm->category,
                    (string)$pm->location,
                    (string)$pm->schedule_name,
                    (string)$pm->description,
                    (string)$pm->tasks_checklist,
                    (string)$pm->interval_type,
                )));
                if (strpos($hay, $q_low) === FALSE) continue;
            }

            $filtered[] = $pm;
        }

        $sort = strtolower(trim((string)$this->input->get('sort')));
        $valid_sorts = array('urgency', 'machine', 'schedule', 'interval', 'last_done', 'next_due', 'parts');
        if (!in_array($sort, $valid_sorts, TRUE)) $sort = 'urgency';

        $dir = strtolower(trim((string)$this->input->get('dir')));
        $default_dirs = array(
            'urgency'   => 'asc',
            'machine'   => 'asc',
            'schedule'  => 'asc',
            'interval'  => 'asc',
            'last_done' => 'desc',
            'next_due'  => 'asc',
            'parts'     => 'desc',
        );
        if (!in_array($dir, array('asc', 'desc'), TRUE)) $dir = $default_dirs[$sort];
        $mul = ($dir === 'asc') ? 1 : -1;

        usort($filtered, function ($a, $b) use ($sort, $mul) {
            $cmp = 0;
            if ($sort === 'urgency') {
                $cmp = ((int)$a->urgency_rank <=> (int)$b->urgency_rank);
                if ($cmp === 0) {
                    $da = $a->days_left !== NULL ? (int)$a->days_left : 999999;
                    $db = $b->days_left !== NULL ? (int)$b->days_left : 999999;
                    $cmp = ($da <=> $db);
                }
            } elseif ($sort === 'machine') {
                $cmp = strnatcasecmp((string)$a->registration_no, (string)$b->registration_no);
            } elseif ($sort === 'schedule') {
                $cmp = strnatcasecmp((string)$a->schedule_name, (string)$b->schedule_name);
            } elseif ($sort === 'interval') {
                $cmp = ((int)$a->interval_value <=> (int)$b->interval_value);
            } elseif ($sort === 'last_done') {
                $cmp = strcmp((string)$a->last_done_date, (string)$b->last_done_date);
            } elseif ($sort === 'next_due') {
                $na = !empty($a->next_due_date) ? (string)$a->next_due_date : '9999-12-31';
                $nb = !empty($b->next_due_date) ? (string)$b->next_due_date : '9999-12-31';
                $cmp = strcmp($na, $nb);
            } elseif ($sort === 'parts') {
                $cmp = ((int)$a->shortage_count <=> (int)$b->shortage_count);
                if ($cmp === 0) $cmp = ((int)$a->parts_count <=> (int)$b->parts_count);
            }
            if ($cmp === 0) {
                $cmp = strnatcasecmp((string)$a->registration_no, (string)$b->registration_no);
            }
            return $cmp * $mul;
        });

        $total_filtered = count($filtered);
        $allowed_pp     = array(10, 25, 50, 100, 150);
        $per_page       = (int)$this->input->get('per_page');
        if (!in_array($per_page, $allowed_pp, TRUE)) $per_page = 25;

        $total_pages = max(1, (int)ceil($total_filtered / $per_page));
        $page        = max(1, min((int)$this->input->get('page'), $total_pages));
        $offset      = ($page - 1) * $per_page;
        $rows        = $paginate ? array_slice($filtered, $offset, $per_page) : $filtered;

        $view_mode = strtolower(trim((string)$this->input->get('view')));
        if (!in_array($view_mode, array('table', 'cards'), TRUE)) $view_mode = 'table';

        return array(
            'pm_schedules'   => $rows,
            'equipment'      => $this->Equipment_model->get_all_active(),
            'locations'      => $overview['locations'],
            'categories'     => $overview['categories'],
            'kpi'            => $kpi,
            'tab_counts'     => $tab_counts,
            'tab'            => $tab,
            'q'              => $q,
            'f_equip'        => $f_equip,
            'f_interval'     => $f_interval,
            'f_category'     => $f_category,
            'f_location'     => $f_location,
            'sort'           => $sort,
            'dir'            => $dir,
            'view_mode'      => $view_mode,
            'per_page'       => $per_page,
            'allowed_pp'     => $allowed_pp,
            'current_page'   => $page,
            'total_pages'    => $total_pages,
            'total_filtered' => $total_filtered,
            'offset'         => $offset,
            'can_manage_pm'  => $this->is_supervisor(),
            'can_complete_pm'=> ((int)$this->current_user->role_id !== 4),
            'filter'         => array(
                'equipment_id' => $f_equip ? (string)$f_equip : '',
                'status'       => $tab === 'active' ? 'all' : $tab,
            ),
        );
    }

    public function index() {
        $data = $this->_build_planner_dataset(TRUE);
        $data['open_wos'] = $this->db->select('w.id, w.wo_number, w.title, w.equipment_id, e.registration_no')
            ->from('work_orders w')
            ->join('equipment e', 'e.id = w.equipment_id', 'left')
            ->where_in('w.status', array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools'))
            ->order_by('w.created_at', 'DESC')->limit(300)->get()->result();
        $data['page_title'] = 'PM Planner';
        $this->render('maintenance/pm_planner', $data);
    }

    /**
     * UTF-8 BOM CSV export of filtered/sorted PM schedules (or selected `?ids=` rows).
     * Accessible to all authenticated roles (Supervisor, Technician, Store Keeper, Manager).
     */
    public function export_csv() {
        $ds   = $this->_build_planner_dataset(FALSE);
        $rows = $ds['pm_schedules'];

        $guard = function ($val) {
            $s = (string)$val;
            if ($s !== '' && preg_match('/^[=+\-@]/', ltrim($s))) {
                return "'" . $s;
            }
            return $s;
        };

        $filename = 'pm_schedules_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array(
            'Schedule ID', 'Machine ID', 'Type', 'Category', 'Location',
            'Schedule Name', 'Interval', 'Standard Hours',
            'Last Done Date', 'Last Done Meter', 'Next Due Date', 'Next Due Meter', 'Current Meter',
            'Compliance Status', 'Schedule State', 'BOM Parts', 'BOM Shortages', 'Completions',
        ));
        foreach ($rows as $pm) {
            fputcsv($out, array(
                (int)$pm->id,
                $guard($pm->registration_no),
                $guard($pm->type_name),
                $guard($pm->category),
                $guard($pm->location),
                $guard($pm->schedule_name),
                'Every ' . (int)$pm->interval_value . ' ' . $guard($pm->interval_type),
                $pm->std_hours_val !== NULL ? number_format((float)$pm->std_hours_val, 1, '.', '') : '',
                $pm->last_done_date ? (string)$pm->last_done_date : '',
                $pm->last_done_hours !== NULL ? number_format((float)$pm->last_done_hours, 1, '.', '') : '',
                $pm->next_due_date ? (string)$pm->next_due_date : '',
                $pm->next_due_hours !== NULL ? number_format((float)$pm->next_due_hours, 1, '.', '') : '',
                number_format((float)$pm->cur_meter, 1, '.', ''),
                $guard($pm->compliance_label),
                $guard($pm->status),
                (int)$pm->parts_count,
                (int)$pm->shortage_count,
                (int)$pm->completion_count,
            ));
        }
        fclose($out);
        exit;
    }

    /**
     * Read-only JSON payload for the right-side Slide-Over PM Schedule Inspector Drawer.
     */
    public function summary_json($id = 0) {
        $id = (int)$id;
        $overview = $this->Pm_model->get_planner_overview();
        $found = NULL;
        foreach ($overview['rows'] as $r) {
            if ((int)$r->id === $id) { $found = $r; break; }
        }
        if (!$found) {
            $this->output
                ->set_status_header(404)
                ->set_content_type('application/json', 'utf-8')
                ->set_output(json_encode(array('ok' => FALSE, 'error' => 'PM schedule not found.')));
            return;
        }

        $parts = array();
        if ($this->db->table_exists('pm_part_requirements') && $this->db->table_exists('materials')) {
            $p_rows = $this->db->select('ppr.qty, ppr.is_critical, ppr.note, m.item_code, m.description, m.unit, m.final_qty')
                ->from('pm_part_requirements ppr')
                ->join('materials m', 'm.id = ppr.material_id', 'left')
                ->where('ppr.pm_schedule_id', $id)
                ->get()->result();
            foreach ($p_rows as $pr) {
                $stock = (float)(isset($pr->final_qty) ? $pr->final_qty : 0);
                $need  = (float)$pr->qty;
                $parts[] = array(
                    'code'        => (string)(isset($pr->item_code) ? $pr->item_code : '—'),
                    'description' => (string)(isset($pr->description) ? $pr->description : 'Unlinked material'),
                    'qty'         => number_format($need, 1) . ' ' . (string)(isset($pr->unit) ? $pr->unit : 'pcs'),
                    'stock'       => number_format($stock, 0),
                    'short'       => ($stock < $need),
                    'critical'    => !empty($pr->is_critical),
                );
            }
        }

        $completions = array();
        if ($this->db->table_exists('pm_completions')) {
            $c_rows = $this->db->select('pc.*, w.wo_number, u.first_name, u.last_name')
                ->from('pm_completions pc')
                ->join('work_orders w', 'w.id = pc.work_order_id', 'left')
                ->join('users u', 'u.id = pc.completed_by', 'left')
                ->where('pc.pm_schedule_id', $id)
                ->order_by('pc.completed_date', 'DESC')
                ->order_by('pc.id', 'DESC')
                ->limit(8)
                ->get()->result();
            foreach ($c_rows as $cr) {
                $who = trim((string)(isset($cr->first_name) ? $cr->first_name : '') . ' ' . (string)(isset($cr->last_name) ? $cr->last_name : ''));
                $completions[] = array(
                    'date'      => $cr->completed_date ? date('d M Y', strtotime($cr->completed_date)) : '—',
                    'hours'     => $cr->completed_hours !== NULL ? number_format((float)$cr->completed_hours, 1) : '—',
                    'wo_number' => !empty($cr->wo_number) ? (string)$cr->wo_number : '',
                    'wo_url'    => !empty($cr->work_order_id) ? base_url('workorders/view/' . (int)$cr->work_order_id) : '',
                    'by'        => $who !== '' ? $who : '—',
                    'notes'     => (string)(isset($cr->notes) ? $cr->notes : ''),
                );
            }
        }

        $tasks = array();
        if (!empty($found->tasks_checklist)) {
            foreach (preg_split('/\r\n|\r|\n/', (string)$found->tasks_checklist) as $line) {
                $line = trim($line);
                if ($line !== '') $tasks[] = $line;
            }
        }

        $payload = array(
            'ok' => TRUE,
            'pm' => array(
                'id'               => (int)$found->id,
                'equipment_id'     => (int)$found->equipment_id,
                'registration_no'  => (string)(isset($found->registration_no) ? $found->registration_no : '—'),
                'machine_sub'      => trim((string)$found->type_name . ' · ' . (string)$found->brand . ' ' . (string)$found->model),
                'location'         => !empty($found->location) ? (string)$found->location : 'Unassigned',
                'schedule_name'    => (string)$found->schedule_name,
                'description'      => !empty($found->description) ? (string)$found->description : '—',
                'interval'         => 'Every ' . (int)$found->interval_value . ' ' . (string)$found->interval_type,
                'standard_hours'   => $found->std_hours_val !== NULL ? number_format((float)$found->std_hours_val, 1) . ' h' : 'Not set (excluded from F4)',
                'last_done'        => $found->last_done_date ? date('d M Y', strtotime($found->last_done_date)) : 'Never',
                'last_meter'       => $found->last_done_hours !== NULL ? number_format((float)$found->last_done_hours, 1) . ' ' . $found->meter_unit : '—',
                'next_due'         => $found->next_due_date ? date('d M Y', strtotime($found->next_due_date)) : 'Unscheduled',
                'next_meter'       => $found->next_due_hours !== NULL ? number_format((float)$found->next_due_hours, 1) . ' ' . $found->meter_unit : '—',
                'cur_meter'        => number_format((float)$found->cur_meter, 1) . ' ' . $found->meter_unit,
                'status'           => (string)$found->status,
                'compliance_label' => (string)$found->compliance_label,
                'compliance_badge' => (string)$found->compliance_badge,
                'tasks'            => $tasks,
                'parts'            => $parts,
                'completions'      => $completions,
                'can_manage'       => $this->is_supervisor(),
                'can_complete'     => ((int)$this->current_user->role_id !== 4),
                'equip_url'        => base_url('equipment/view/' . (int)$found->equipment_id),
                'edit_url'         => base_url('pm/edit/' . (int)$found->id),
                'parts_url'        => base_url('forecast/parts/' . (int)$found->id),
            ),
        );

        $this->output
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($payload));
    }

    public function create() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('pm');
        }

        if ($this->input->post()) {
            $last_date  = $this->input->post('last_done_date', TRUE) ?: NULL;
            $interval_t = $this->input->post('interval_type', TRUE);
            $interval_v = (int)$this->input->post('interval_value', TRUE);
            $last_hours = $this->input->post('last_done_hours', TRUE) ?: NULL;

            $next_date  = $this->_calc_next_date($last_date, $interval_t, $interval_v);
            $next_hours = $last_hours
                ? $last_hours + (in_array($interval_t, array('Hours', 'Km'), TRUE) ? $interval_v : 0)
                : NULL;

            $data = [
                'equipment_id'    => $this->input->post('equipment_id', TRUE),
                'schedule_name'   => $this->input->post('schedule_name', TRUE),
                'description'     => $this->input->post('description', TRUE),
                'interval_type'   => $interval_t,
                'interval_value'  => $interval_v,
                'last_done_date'  => $last_date,
                'last_done_hours' => $last_hours,
                'next_due_date'   => $next_date,
                'next_due_hours'  => $next_hours,
                'tasks_checklist' => $this->input->post('tasks_checklist', TRUE),
                'status'          => 'Active',
            ];

            if ($this->db->field_exists('standard_hours', 'pm_schedules')) {
                $std = $this->input->post('standard_hours', TRUE);
                $data['standard_hours'] = ($std === '' || $std === null || !is_numeric($std))
                    ? null
                    : max(0, min(999.99, (float)$std));
            }

            $this->Pm_model->create($data);
            $this->session->set_flashdata('success', 'PM Schedule created.');
            redirect('pm');
        }

        $data['pm']         = null;
        $data['equipment']  = $this->Equipment_model->get_all_active();
        $data['has_standard_hours'] = $this->db->field_exists('standard_hours', 'pm_schedules');
        $data['page_title'] = 'Create PM Schedule';
        $this->render('maintenance/pm_form', $data);
    }

    public function edit($id) {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('pm');
        }

        $pm = $this->Pm_model->get_by_id($id);
        if (!$pm) {
            $this->session->set_flashdata('error', 'PM schedule not found.');
            redirect('pm');
        }

        if ($this->input->post()) {
            $last_date  = $this->input->post('last_done_date', TRUE) ?: NULL;
            $interval_t = $this->input->post('interval_type', TRUE);
            $interval_v = (int)$this->input->post('interval_value', TRUE);
            $last_hours = $this->input->post('last_done_hours', TRUE) ?: NULL;

            $data = [
                'equipment_id'    => $this->input->post('equipment_id', TRUE),
                'schedule_name'   => $this->input->post('schedule_name', TRUE),
                'description'     => $this->input->post('description', TRUE),
                'interval_type'   => $interval_t,
                'interval_value'  => $interval_v,
                'last_done_date'  => $last_date,
                'last_done_hours' => $last_hours,
                'tasks_checklist' => $this->input->post('tasks_checklist', TRUE),
                'status'          => $this->input->post('status', TRUE) ?: $pm->status,
            ];

            $interval_changed  = ($pm->interval_type !== $interval_t) || ((int)$pm->interval_value !== $interval_v);
            $last_done_changed = ((string)$pm->last_done_date !== (string)$last_date);

            if ($interval_changed || $last_done_changed) {
                $data['next_due_date']  = $this->_calc_next_date($last_date, $interval_t, $interval_v);
                $data['next_due_hours'] = $last_hours
                    ? $last_hours + (in_array($interval_t, array('Hours', 'Km'), TRUE) ? $interval_v : 0)
                    : NULL;
            }

            if ($this->db->field_exists('standard_hours', 'pm_schedules')) {
                $std = $this->input->post('standard_hours', TRUE);
                $data['standard_hours'] = ($std === '' || $std === null || !is_numeric($std))
                    ? null
                    : max(0, min(999.99, (float)$std));
            }

            $this->Pm_model->update($id, $data);
            $this->session->set_flashdata('success', 'PM Schedule updated.');
            redirect('pm');
        }

        $data['pm']         = $pm;
        $data['equipment']  = $this->Equipment_model->get_all_active();
        $data['has_standard_hours'] = $this->db->field_exists('standard_hours', 'pm_schedules');
        $data['page_title'] = 'Edit PM Schedule';
        $this->render('maintenance/pm_form', $data);
    }

    public function complete($id = null) {
        if (!$this->require_post()) return;

        if ($this->input->post()) {
            $id = $id ?: $this->input->post('pm_id', TRUE);

            $pm = $id ? $this->Pm_model->get_by_id($id) : null;
            if (!$pm) {
                $this->session->set_flashdata('error', 'PM schedule not found.');
                redirect('pm');
            }

            $completed_date  = $this->input->post('completed_date', TRUE)
                ?: ($this->input->post('completion_date', TRUE) ?: date('Y-m-d'));
            if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', (string)$completed_date)
                || !checkdate((int)substr($completed_date, 5, 2), (int)substr($completed_date, 8, 2), (int)substr($completed_date, 0, 4))) {
                $completed_date = date('Y-m-d');
            }
            $completed_hours = $this->input->post('completed_hours', TRUE);
            if ($completed_hours === NULL) {
                $completed_hours = $this->input->post('current_hours', TRUE);
            }
            $completed_hours = ($completed_hours === NULL || $completed_hours === '') ? NULL : $completed_hours;

            $work_order_id = (int)$this->input->post('work_order_id', TRUE);
            if ($work_order_id > 0) {
                $wo = $this->db->select('id, equipment_id')->where('id', $work_order_id)->get('work_orders')->row();
                if (!$wo || (int)$wo->equipment_id !== (int)$pm->equipment_id) {
                    $work_order_id = 0;
                    $this->session->set_flashdata('error',
                        'The PM was not completed: the selected work order belongs to a different machine.');
                    redirect('pm');
                    return;
                }
            }

            $next_date  = $this->_calc_next_date($completed_date, $pm->interval_type, $pm->interval_value);
            $meter_interval = in_array($pm->interval_type, array('Hours', 'Km'), TRUE);
            $next_hours = ($completed_hours !== NULL && $meter_interval)
                ? (float)$completed_hours + (float)$pm->interval_value
                : NULL;

            $completion = [
                'pm_schedule_id'  => $id,
                'completed_date'  => $completed_date,
                'completed_hours' => $completed_hours,
                'completed_by'    => $this->user_id,
                'notes'           => $this->input->post('notes', TRUE),
            ];
            if ($this->db->field_exists('work_order_id', 'pm_completions')) {
                $completion['work_order_id'] = $work_order_id > 0 ? $work_order_id : NULL;
            }
            $this->db->insert('pm_completions', $completion);

            $meter_note = '';
            if ($completed_hours !== NULL && $meter_interval) {
                $unit = ($pm->interval_type === 'Km') ? 'Kilometres' : $pm->interval_type;
                $reading = $this->Equipment_model->record_meter_reading(
                    $pm->equipment_id, $completed_date, (float)$completed_hours,
                    'WorkOrder', $work_order_id > 0 ? $work_order_id : null, $this->user_id, ['unit' => $unit]
                );
                if (empty($reading['ok'])) {
                    $meter_note = ' Warning: meter reading was not recorded: ' . $reading['message'];
                }
            }

            $schedule_update = array(
                'last_done_date' => $completed_date,
                'next_due_date'  => $next_date,
            );
            if ($completed_hours !== NULL) {
                $schedule_update['last_done_hours'] = $completed_hours;
            }
            if ($next_hours !== NULL) {
                $schedule_update['next_due_hours'] = $next_hours;
            }
            $this->Pm_model->update($id, $schedule_update);

            $this->session->set_flashdata('success', 'PM marked as completed.' . $meter_note);
        }
        redirect('pm');
    }

    public function calendar() {
        $months = 12;
        $data['forecast']   = $this->Pm_model->get_12month_forecast($months);
        $data['page_title'] = '12-Month PM Forecast';
        $this->render('maintenance/pm_calendar', $data);
    }

    private function _calc_next_date($from_date, $interval_type, $interval_value) {
        if (!$from_date) return NULL;
        switch ($interval_type) {
            case 'Days':   return date('Y-m-d', strtotime("{$from_date} +{$interval_value} days"));
            case 'Months': return date('Y-m-d', strtotime("{$from_date} +{$interval_value} months"));
            default:       return date('Y-m-d', strtotime("{$from_date} +30 days"));
        }
    }
}
