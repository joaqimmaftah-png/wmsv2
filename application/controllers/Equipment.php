<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Equipment extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Equipment_model');
    }

    /**
     * Shared fleet dataset builder powering `/equipment` (table & card modes) and `/equipment/export_csv`.
     * Executes in constant O(1) SQL queries (<= 6 total regardless of fleet size).
     *
     * @param  bool $paginate slice page rows when TRUE; return all matching rows when FALSE (CSV export)
     * @return array
     */
    private function _build_fleet_dataset($paginate = TRUE) {
        $overview = $this->Equipment_model->get_fleet_overview();
        $all_rows = $overview['rows'];

        $tab = strtolower(trim((string)$this->input->get('tab')));
        $legacy_status = trim((string)$this->input->get('status'));
        if ($tab === '' && $legacy_status !== '') {
            $s_map = array(
                'available'      => 'available',
                'in maintenance' => 'maintenance',
                'breakdown'      => 'breakdown',
                'standby'        => 'standby',
                'retired'        => 'retired',
            );
            $sl = strtolower($legacy_status);
            if (isset($s_map[$sl])) {
                $tab = $s_map[$sl];
                $legacy_status = '';
            }
        }
        $valid_tabs = array('all', 'available', 'maintenance', 'breakdown', 'standby', 'pm_overdue', 'data_alert', 'retired');
        if (!in_array($tab, $valid_tabs, TRUE)) $tab = 'all';

        $q = trim((string)$this->input->get('q'));
        if ($q === '') $q = trim((string)$this->input->get('search'));

        $f_category = trim((string)$this->input->get('category'));
        $f_type     = (int)$this->input->get('type');
        $f_location = trim((string)$this->input->get('location'));
        $f_group    = trim((string)$this->input->get('report_group'));
        $f_rep      = strtolower(trim((string)$this->input->get('reportable')));
        if (!in_array($f_rep, array('', 'yes', 'no'), TRUE)) $f_rep = '';

        $ids_raw = trim((string)$this->input->get('ids'));
        $id_whitelist = array();
        if ($ids_raw !== '') {
            foreach (explode(',', $ids_raw) as $part) {
                $id_int = (int)trim($part);
                if ($id_int > 0) $id_whitelist[$id_int] = TRUE;
            }
        }

        // Single pass for KPI counters, tab badges, and filter-facet collections
        $tab_counts = array(
            'all'         => 0,
            'available'   => 0,
            'maintenance' => 0,
            'breakdown'   => 0,
            'standby'     => 0,
            'pm_overdue'  => 0,
            'data_alert'  => 0,
            'retired'     => 0,
        );
        $kpi = array(
            'total'               => 0,
            'operational'         => 0,
            'down'                => 0,
            'breakdown'           => 0,
            'in_maintenance'      => 0,
            'open_wo_total'       => 0,
            'open_wo_machines'    => 0,
            'pm_overdue_total'    => 0,
            'pm_overdue_machines' => 0,
        );
        $categories_map = array();
        $groups_seen    = array();

        foreach ($all_rows as $eq) {
            $tab_counts['all']++;
            $kpi['total']++;

            $st = (string)$eq->status;
            if ($st === 'Available') {
                $tab_counts['available']++;
                $kpi['operational']++;
            } elseif ($st === 'Standby') {
                $tab_counts['standby']++;
                $kpi['operational']++;
            } elseif ($st === 'In Maintenance') {
                $tab_counts['maintenance']++;
                $kpi['down']++;
                $kpi['in_maintenance']++;
            } elseif ($st === 'Breakdown') {
                $tab_counts['breakdown']++;
                $kpi['down']++;
                $kpi['breakdown']++;
            } elseif ($st === 'Retired') {
                $tab_counts['retired']++;
            }

            if ((int)$eq->open_wo_count > 0) {
                $kpi['open_wo_total'] += (int)$eq->open_wo_count;
                $kpi['open_wo_machines']++;
            }
            if ((int)$eq->pm_overdue_count > 0) {
                $tab_counts['pm_overdue']++;
                $kpi['pm_overdue_total'] += (int)$eq->pm_overdue_count;
                $kpi['pm_overdue_machines']++;
            }
            if (!empty($eq->has_data_alert)) {
                $tab_counts['data_alert']++;
            }

            $cat = trim((string)(isset($eq->category) ? $eq->category : ''));
            if ($cat !== '') $categories_map[$cat] = TRUE;
            $grp = trim((string)(isset($eq->report_group) ? $eq->report_group : ''));
            if ($grp !== '') $groups_seen[$grp] = TRUE;
        }
        $kpi['avail_pct'] = $kpi['total'] > 0
            ? round(($kpi['operational'] / max(1, $kpi['total'] - $tab_counts['retired'])) * 100, 1)
            : 0.0;

        // Filter matching pass
        $q_low = strtolower($q);
        $filtered = array();
        foreach ($all_rows as $eq) {
            if (!empty($id_whitelist) && empty($id_whitelist[(int)$eq->id])) continue;

            if ($tab === 'available' && $eq->status !== 'Available') continue;
            if ($tab === 'maintenance' && $eq->status !== 'In Maintenance') continue;
            if ($tab === 'breakdown' && $eq->status !== 'Breakdown') continue;
            if ($tab === 'standby' && $eq->status !== 'Standby') continue;
            if ($tab === 'pm_overdue' && (int)$eq->pm_overdue_count <= 0) continue;
            if ($tab === 'data_alert' && empty($eq->has_data_alert)) continue;
            if ($tab === 'retired' && $eq->status !== 'Retired') continue;

            if ($legacy_status !== '' && strcasecmp((string)$eq->status, $legacy_status) !== 0) continue;
            if ($f_category !== '' && strcasecmp((string)$eq->category, $f_category) !== 0) continue;
            if ($f_type > 0 && (int)$eq->equipment_type_id !== $f_type) continue;

            if ($f_location !== '') {
                $raw_loc  = (string)(isset($eq->location) ? $eq->location : '');
                $disp_loc = (string)(isset($eq->loc_display) ? $eq->loc_display : $raw_loc);
                $match_loc = (strcasecmp($raw_loc, $f_location) === 0 || strcasecmp($disp_loc, $f_location) === 0);
                if (!$match_loc && function_exists('equip_site_key')) {
                    $match_loc = (equip_site_key($raw_loc) === equip_site_key($f_location));
                }
                if (!$match_loc) continue;
            }

            if ($f_group === '__unclassified') {
                if (!empty($eq->report_group)) continue;
            } elseif ($f_group !== '' && strcasecmp((string)$eq->report_group, $f_group) !== 0) {
                continue;
            }

            if ($f_rep === 'yes' && empty($eq->is_reportable)) continue;
            if ($f_rep === 'no' && !empty($eq->is_reportable)) continue;

            if ($q_low !== '') {
                $hay = strtolower(implode(' ', array(
                    (string)$eq->registration_no,
                    (string)$eq->type_name,
                    (string)$eq->category,
                    (string)$eq->brand,
                    (string)$eq->model,
                    (string)$eq->serial_number,
                    (string)$eq->engine_model,
                    (string)$eq->location,
                    (string)$eq->report_group,
                )));
                if (strpos($hay, $q_low) === FALSE) continue;
            }

            $filtered[] = $eq;
        }

        // Deterministic multi-column sorting (defaults to natural Machine ID ASC)
        $sort = strtolower(trim((string)$this->input->get('sort')));
        $valid_sorts = array('reg', 'type', 'brand', 'year', 'hours', 'km', 'location', 'open_wos', 'status');
        if (!in_array($sort, $valid_sorts, TRUE)) $sort = 'reg';

        $dir = strtolower(trim((string)$this->input->get('dir')));
        $default_dirs = array(
            'reg'      => 'asc',
            'type'     => 'asc',
            'brand'    => 'asc',
            'year'     => 'desc',
            'hours'    => 'desc',
            'km'       => 'desc',
            'location' => 'asc',
            'open_wos' => 'desc',
            'status'   => 'asc',
        );
        if (!in_array($dir, array('asc', 'desc'), TRUE)) {
            $dir = $default_dirs[$sort];
        }
        $mul = ($dir === 'asc') ? 1 : -1;

        usort($filtered, function ($a, $b) use ($sort, $mul) {
            $cmp = 0;
            if ($sort === 'reg') {
                $cmp = strnatcasecmp((string)$a->registration_no, (string)$b->registration_no);
            } elseif ($sort === 'type') {
                $cmp = strnatcasecmp((string)$a->type_name . ' ' . (string)$a->category, (string)$b->type_name . ' ' . (string)$b->category);
            } elseif ($sort === 'brand') {
                $cmp = strnatcasecmp(trim($a->brand . ' ' . $a->model), trim($b->brand . ' ' . $b->model));
            } elseif ($sort === 'year') {
                $cmp = ((int)$a->year_manufacture <=> (int)$b->year_manufacture);
            } elseif ($sort === 'hours') {
                $cmp = ((float)$a->current_hours <=> (float)$b->current_hours);
            } elseif ($sort === 'km') {
                $cmp = ((float)$a->current_km <=> (float)$b->current_km);
            } elseif ($sort === 'location') {
                $cmp = strnatcasecmp((string)$a->loc_display, (string)$b->loc_display);
            } elseif ($sort === 'open_wos') {
                $cmp = ((int)$a->open_wo_count <=> (int)$b->open_wo_count);
                if ($cmp === 0) $cmp = ((int)$a->pm_overdue_count <=> (int)$b->pm_overdue_count);
            } elseif ($sort === 'status') {
                $cmp = ((int)$a->status_rank <=> (int)$b->status_rank);
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

        $categories = array_keys($categories_map);
        sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
        $raw_groups = $this->Equipment_model->report_groups();
        $report_groups = array();
        foreach ($raw_groups as $gk => $gv) {
            $code = is_int($gk) ? (string)$gv : (string)$gk;
            if ($code !== '' && !in_array($code, $report_groups, TRUE)) $report_groups[] = $code;
        }
        foreach (array_keys($groups_seen) as $gs) {
            if (!in_array($gs, $report_groups, TRUE)) $report_groups[] = $gs;
        }
        sort($report_groups, SORT_NATURAL | SORT_FLAG_CASE);

        return array(
            'equipment_list' => $rows,
            'types'          => $this->Equipment_model->get_types(),
            'categories'     => $categories,
            'locations'      => $overview['locations'],
            'report_sites'   => $overview['report_sites'],
            'report_groups'  => $report_groups,
            'reportability'  => $overview['reportability'],
            'site_summary'   => $overview['site_summary'],
            'kpi'            => $kpi,
            'tab_counts'     => $tab_counts,
            'tab'            => $tab,
            'q'              => $q,
            'f_category'     => $f_category,
            'f_type'         => $f_type,
            'f_location'     => $f_location,
            'f_group'        => $f_group,
            'f_rep'          => $f_rep,
            'sort'           => $sort,
            'dir'            => $dir,
            'view_mode'      => $view_mode,
            'per_page'       => $per_page,
            'allowed_pp'     => $allowed_pp,
            'current_page'   => $page,
            'total_pages'    => $total_pages,
            'total_filtered' => $total_filtered,
            'offset'         => $offset,
            'can_create_eq'  => $this->is_supervisor(),
            'can_create_wo'  => ((int)$this->current_user->role_id !== 4),
            'filters'        => array(
                'status'   => $legacy_status,
                'type'     => $f_type ? (string)$f_type : '',
                'category' => $f_category,
                'location' => $f_location,
                'search'   => $q,
            ),
        );
    }

    public function index() {
        $data = $this->_build_fleet_dataset(TRUE);
        $data['page_title'] = 'Equipment Fleet';
        $this->render('maintenance/equipment', $data);
    }

    /**
     * UTF-8 BOM CSV export of the current filtered/sorted fleet register (or selected `?ids=` rows).
     * Accessible to all authenticated roles (Supervisor, Technician, Store Keeper, Manager).
     */
    public function export_csv() {
        $ds   = $this->_build_fleet_dataset(FALSE);
        $rows = $ds['equipment_list'];

        $guard = function ($val) {
            $s = (string)$val;
            if ($s !== '' && preg_match('/^[=+\-@]/', ltrim($s))) {
                return "'" . $s;
            }
            return $s;
        };

        $filename = 'equipment_fleet_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array(
            'Machine ID', 'Type', 'Category', 'Brand', 'Model', 'Year',
            'Serial No.', 'Engine Model', 'Current Hours', 'Current KM',
            'Location', 'Site Approved', 'Status', 'Weekly Report Group',
            'Include in Weekly Report', 'Open WOs', 'Overdue PMs', 'Specs Recorded',
            'Purchase Date', 'Warranty Expiry',
        ));
        foreach ($rows as $eq) {
            fputcsv($out, array(
                $guard($eq->registration_no),
                $guard($eq->type_name),
                $guard($eq->category),
                $guard($eq->brand),
                $guard($eq->model),
                (!empty($eq->year) ? (string)$eq->year : (!empty($eq->year_manufacture) ? (string)$eq->year_manufacture : '')),
                $guard($eq->serial_number),
                $guard($eq->engine_model),
                number_format((float)$eq->current_hours, 1, '.', ''),
                number_format((float)$eq->current_km, 1, '.', ''),
                $guard($eq->location),
                !empty($eq->loc_approved) ? 'Yes' : 'No',
                $guard($eq->status),
                $guard($eq->report_group),
                !empty($eq->is_reportable) ? 'Yes' : 'No',
                (int)$eq->open_wo_count,
                (int)$eq->pm_overdue_count,
                (int)$eq->spec_count,
                $eq->purchase_date ? (string)$eq->purchase_date : '',
                $eq->warranty_expiry ? (string)$eq->warranty_expiry : '',
            ));
        }
        fclose($out);
        exit;
    }

    /**
     * Read-only JSON summary for the right-side Slide-Over Machine Inspector Drawer.
     */
    public function summary_json($id = 0) {
        $id = (int)$id;
        $eq = $this->Equipment_model->get_by_id($id);
        if (!$eq) {
            $this->output
                ->set_status_header(404)
                ->set_content_type('application/json', 'utf-8')
                ->set_output(json_encode(array('ok' => FALSE, 'error' => 'Equipment not found.')));
            return;
        }

        $this->load->model('Workorder_model');
        $this->load->model('Pm_model');
        $specs      = $this->Equipment_model->get_specs($id);
        $kpi        = $this->Equipment_model->get_equipment_kpi($id, 30);
        $pm_list    = $this->Pm_model->get_by_equipment($id);
        $wo_history = $this->Workorder_model->get_by_equipment($id, 8);
        $sites      = $this->Equipment_model->report_sites();

        $smap = equip_status_map($eq->status);
        $today = date('Y-m-d');

        $spec_items = array();
        foreach ($specs as $sp) {
            $spec_items[] = array(
                'key'   => (string)(isset($sp->spec_label) && $sp->spec_label !== '' ? $sp->spec_label : $sp->spec_key),
                'value' => (string)$sp->spec_value,
            );
        }

        $pm_items = array();
        foreach ($pm_list as $pm) {
            $by_date  = (!empty($pm->next_due_date) && (string)$pm->next_due_date < $today);
            $by_hours = ($pm->next_due_hours !== NULL && (float)$pm->next_due_hours > 0 && (float)$eq->current_hours >= (float)$pm->next_due_hours);
            $pm_items[] = array(
                'name'       => (string)$pm->schedule_name,
                'interval'   => trim($pm->interval_value . ' ' . $pm->interval_type),
                'next_due'   => $pm->next_due_date ? date('d M Y', strtotime($pm->next_due_date)) : ($pm->next_due_hours ? number_format((float)$pm->next_due_hours, 0) . ' hrs' : '—'),
                'is_overdue' => ($by_date || $by_hours),
            );
        }

        $wo_items = array();
        $open_wo_cnt = 0;
        foreach ($wo_history as $wo) {
            if (in_array($wo->status, array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools'), TRUE)) {
                $open_wo_cnt++;
            }
            $wo_items[] = array(
                'id'           => (int)$wo->id,
                'wo_number'    => (string)$wo->wo_number,
                'type'         => (string)$wo->type,
                'is_breakdown' => (bool)(function_exists('wo_is_breakdown') && wo_is_breakdown($wo)),
                'priority'     => (string)$wo->priority,
                'status'       => (string)$wo->status,
                'title'        => (string)$wo->title,
                'date_fmt'     => $wo->created_at ? date('d M Y', strtotime($wo->created_at)) : '—',
                'assigned'     => isset($wo->assigned_name) ? (string)$wo->assigned_name : '',
                'url'          => base_url('workorders/view/' . (int)$wo->id),
            );
        }

        $loc_raw = trim((string)(isset($eq->location) ? $eq->location : ''));
        $loc_ok  = ($loc_raw !== '') && (empty($sites) || equip_is_approved_site($loc_raw, $sites));
        $rep_lbl = equip_report_sort_label($eq);

        $payload = array(
            'ok' => TRUE,
            'equipment' => array(
                'id'              => (int)$eq->id,
                'registration_no' => (string)$eq->registration_no,
                'type_name'       => (string)(isset($eq->type_name) ? $eq->type_name : '—'),
                'category'        => (string)(isset($eq->category) ? $eq->category : '—'),
                'brand'           => (string)(isset($eq->brand) ? $eq->brand : '—'),
                'model'           => (string)(isset($eq->model) ? $eq->model : '—'),
                'year'            => (!empty($eq->year) ? (string)$eq->year : (!empty($eq->year_manufacture) ? (string)$eq->year_manufacture : '—')),
                'serial_number'   => $eq->serial_number ? (string)$eq->serial_number : '—',
                'engine_model'    => $eq->engine_model ? (string)$eq->engine_model : '—',
                'current_hours'   => number_format((float)$eq->current_hours, 1) . ' hrs',
                'current_km'      => number_format((float)$eq->current_km, 1) . ' km',
                'location'        => $loc_raw !== '' ? $loc_raw : 'Unassigned',
                'loc_approved'    => (bool)$loc_ok,
                'status'          => (string)$eq->status,
                'status_badge'    => (string)$smap['badge'],
                'report_label'    => $rep_lbl !== '' ? $rep_lbl : '—',
                'reportable'      => (bool)equip_is_reportable($eq),
                'purchase_date'   => $eq->purchase_date ? date('d M Y', strtotime($eq->purchase_date)) : '—',
                'warranty_expiry' => $eq->warranty_expiry ? date('d M Y', strtotime($eq->warranty_expiry)) : '—',
                'availability'    => number_format((float)$kpi['availability_pct'], 1) . '%',
                'mtbf'            => number_format((float)$kpi['mtbf'], 1) . ' h',
                'mttr'            => number_format((float)$kpi['mttr'], 1) . ' h',
                'open_wo_count'   => $open_wo_cnt,
                'specs'           => $spec_items,
                'pm_list'         => $pm_items,
                'wo_list'         => $wo_items,
                'can_edit'        => $this->is_supervisor(),
                'can_create_wo'   => ((int)$this->current_user->role_id !== 4),
                'view_url'        => base_url('equipment/view/' . (int)$eq->id),
                'edit_url'        => base_url('equipment/edit/' . (int)$eq->id),
                'create_wo_url'   => base_url('workorders/create'),
            ),
        );

        $this->output
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($payload));
    }

    public function create() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('equipment');
        }
        $data['page_title']    = 'Add Equipment';
        $data['types']         = $this->Equipment_model->get_types();
        $data['eq_types']      = $data['types'];
        $data['report_groups'] = $this->Equipment_model->report_groups();
        $data['report_sites']  = $this->Equipment_model->report_sites();
        $data['equipment']     = null;

        if ($this->input->post()) {
            $this->form_validation->set_rules('registration_no', 'Registration No', 'required|is_unique[equipment.registration_no]');
            $this->form_validation->set_rules('equipment_type_id', 'Equipment Type', 'required');
            $this->form_validation->set_rules('brand', 'Brand', 'required');
            $this->form_validation->set_rules('model', 'Model', 'required');

            if ($this->form_validation->run()) {
                $yr_in = $this->input->post('year') ?: $this->input->post('year_manufacture');
                $insert = [
                    'registration_no'   => strtoupper($this->input->post('registration_no', TRUE)),
                    'equipment_type_id' => $this->input->post('equipment_type_id'),
                    'brand'             => $this->input->post('brand', TRUE),
                    'model'             => $this->input->post('model', TRUE),
                    'year'              => $yr_in ? (int)$yr_in : null,
                    'serial_number'     => $this->input->post('serial_number', TRUE),
                    'engine_model'      => $this->input->post('engine_model', TRUE),
                    'current_hours'     => (float)$this->input->post('current_hours'),
                    'current_km'        => (float)$this->input->post('current_km'),
                    'status'            => $this->input->post('status') ?: 'Available',
                    'location'          => $this->input->post('location', TRUE),
                    'purchase_date'     => $this->input->post('purchase_date') ?: null,
                    'warranty_expiry'   => $this->input->post('warranty_expiry') ?: null,
                    'report_group'      => $this->input->post('report_group', TRUE) ?: null,
                    'report_sort'       => $this->input->post('report_sort') !== '' ? (int)$this->input->post('report_sort') : null,
                    'include_in_weekly_report' => $this->input->post('include_in_weekly_report') ? 1 : 0,
                ];
                $new_id = $this->Equipment_model->create($insert);
                $this->session->set_flashdata('success', 'Equipment added successfully.');
                redirect('equipment/view/' . $new_id);
            }
        }
        $this->render('maintenance/equipment_form', $data);
    }

    public function view($id) {
        $eq = $this->Equipment_model->get_by_id($id);
        if (!$eq) show_404();
        $this->load->model('Workorder_model');
        $this->load->model('Pm_model');
        $data['page_title']   = $eq->registration_no;
        $data['equipment']    = $eq;
        $data['specs']        = $this->Equipment_model->get_specs($id);
        $data['kpi']          = $this->Equipment_model->get_equipment_kpi($id, 30);
        $data['wo_history']   = $this->Workorder_model->get_by_equipment($id, 20);
        $data['pm_list']      = $this->Pm_model->get_by_equipment($id);
        $data['meter_history']= $this->Equipment_model->get_meter_history($id, 10);
        $data['report_sites'] = $this->Equipment_model->report_sites();
        $this->render('maintenance/equipment_view', $data);
    }

    public function edit($id) {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('equipment');
        }
        $eq = $this->Equipment_model->get_by_id($id);
        if (!$eq) show_404();
        $data['page_title']    = 'Edit: ' . $eq->registration_no;
        $data['equipment']     = $eq;
        $data['types']         = $this->Equipment_model->get_types();
        $data['eq_types']      = $data['types'];
        $data['report_groups'] = $this->Equipment_model->report_groups();
        $data['report_sites']  = $this->Equipment_model->report_sites();

        if ($this->input->post()) {
            $this->form_validation->set_rules('equipment_type_id', 'Equipment Type', 'required');
            $this->form_validation->set_rules('brand', 'Brand', 'required');
            $this->form_validation->set_rules('model', 'Model', 'required');

            if ($this->form_validation->run()) {
                $yr_in = $this->input->post('year') ?: $this->input->post('year_manufacture');
                $update = [
                    'equipment_type_id' => $this->input->post('equipment_type_id'),
                    'brand'             => $this->input->post('brand', TRUE),
                    'model'             => $this->input->post('model', TRUE),
                    'year'              => $yr_in ? (int)$yr_in : null,
                    'serial_number'     => $this->input->post('serial_number', TRUE),
                    'engine_model'      => $this->input->post('engine_model', TRUE),
                    'current_hours'     => (float)$this->input->post('current_hours'),
                    'current_km'        => (float)$this->input->post('current_km'),
                    'status'            => $this->input->post('status'),
                    'location'          => $this->input->post('location', TRUE),
                    'purchase_date'     => $this->input->post('purchase_date') ?: null,
                    'warranty_expiry'   => $this->input->post('warranty_expiry') ?: null,
                    'report_group'      => $this->input->post('report_group', TRUE) ?: null,
                    'report_sort'       => $this->input->post('report_sort') !== '' ? (int)$this->input->post('report_sort') : null,
                    'include_in_weekly_report' => $this->input->post('include_in_weekly_report') ? 1 : 0,
                ];
                $this->Equipment_model->update($id, $update);
                $this->session->set_flashdata('success', 'Equipment updated.');
                redirect('equipment/view/' . $id);
            }
        }
        $this->render('maintenance/equipment_form', $data);
    }

    public function update_hours($id) {
        $eq = $this->Equipment_model->get_by_id($id);
        if (!$eq) show_404();
        $hours = (float)$this->input->post('current_hours');
        $km    = (float)$this->input->post('current_km');
        $notes = $this->input->post('notes', TRUE);
        $this->Equipment_model->record_meter_reading($id, $hours, $km, $this->current_user->id, $notes);
        $this->session->set_flashdata('success', 'Meter reading updated.');
        redirect('equipment/view/' . $id);
    }
}
