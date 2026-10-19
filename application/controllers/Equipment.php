<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Equipment extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Equipment_model');
        $this->load->model('Workorder_model');
    }

    public function index() {
        $filter = [
            'status'       => $this->input->get('status'),
            'type'         => $this->input->get('type'),
            'search'       => $this->input->get('search'),
            'report_group' => $this->input->get('report_group'),   // W0.3
            'reportable'   => $this->input->get('reportable'),
        ];

        $data['equipment']     = $this->Equipment_model->get_all($filter);
        $data['eq_types']      = $this->db->get('equipment_types')->result();
        $data['report_groups'] = $this->Equipment_model->report_groups();
        $data['reportability'] = $this->Equipment_model->reportability_summary();
        $data['report_sites']  = $this->Equipment_model->report_sites();      // W0.5
        $data['site_summary']  = $this->Equipment_model->site_summary();      // W0.5
        $data['filter']        = $filter;
        $data['page_title'] = 'Equipment Register';
        $this->render('maintenance/equipment', $data);
    }

    public function create() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('equipment');
        }

        if ($this->input->post()) {
            $data = [
                'equipment_type_id' => $this->input->post('equipment_type_id', TRUE),
                'registration_no'   => strtoupper($this->input->post('registration_no', TRUE)),
                'brand'             => $this->input->post('brand', TRUE),
                'model'             => $this->input->post('model', TRUE),
                'year'              => $this->input->post('year', TRUE),
                'serial_number'     => $this->input->post('serial_number', TRUE),
                'engine_model'      => $this->input->post('engine_model', TRUE),
                'current_hours'     => $this->input->post('current_hours', TRUE) ?: 0,
                'current_km'        => $this->input->post('current_km', TRUE) ?: 0,
                'location'          => $this->Equipment_model->sanitize_location($this->input->post('location', TRUE))['value'],   // W0.5
                'purchase_date'     => $this->input->post('purchase_date', TRUE) ?: NULL,
                'warranty_expiry'   => $this->input->post('warranty_expiry', TRUE) ?: NULL,
                'notes'             => $this->input->post('notes', TRUE),
                'status'            => 'Available',
            ];
            // W0.3 — weekly-report classification (group / order / included)
            $data = array_merge($data, $this->Equipment_model->sanitize_reportability([
                'report_group'             => $this->input->post('report_group', TRUE),
                'report_sort'              => $this->input->post('report_sort', TRUE),
                'include_in_weekly_report' => $this->input->post('include_in_weekly_report', TRUE),
            ]));

            // Handle photo upload
            if ($_FILES['photo']['name']) {
                $config = [
                    'upload_path'   => './uploads/photos/',
                    'allowed_types' => 'gif|jpg|jpeg|png',
                    'max_size'      => 5000,
                    'file_name'     => 'eq_' . time(),
                ];
                $this->load->library('upload', $config);
                if ($this->upload->do_upload('photo')) {
                    $data['photo'] = $this->upload->data('file_name');
                }
            }

            $this->Equipment_model->create($data);
            $this->session->set_flashdata('success', 'Equipment added successfully.');
            redirect('equipment');
        }

        $data['eq_types']      = $this->db->get('equipment_types')->result();
        $data['report_groups'] = $this->Equipment_model->report_groups();
        $data['report_sites']  = $this->Equipment_model->report_sites();      // W0.5
        $data['page_title']    = 'Add Equipment';
        $this->render('maintenance/equipment_form', $data);
    }

    public function view($id) {
        $data['equipment']   = $this->Equipment_model->get_by_id($id);
        if (!$data['equipment']) show_404();
        $data['wo_history']  = $this->Workorder_model->get_by_equipment($id, 20);
        $data['pm_list']     = $this->Equipment_model->get_pm_schedules($id);
        $data['kpi']         = $this->Equipment_model->get_equipment_kpi($id);
        // Manufacturer specification sheet imported from the asset list
        // (migration 007). Absent on a database that has not been migrated.
        $data['specs']       = $this->_equipment_specs($id);
        $data['report_sites'] = $this->Equipment_model->report_sites();       // W0.5
        $data['page_title']  = 'Equipment: ' . $data['equipment']->registration_no;
        $this->render('maintenance/equipment_view', $data);
    }

    /**
     * Specification sheet rows for one machine, in the Excel column order.
     * Returns an empty array when migration 007 has not been applied.
     */
    private function _equipment_specs($id) {
        if (!$this->db->table_exists('equipment_specs')) return array();
        return $this->db->select('spec_key, spec_label, spec_value')
                        ->from('equipment_specs')
                        ->where('equipment_id', (int)$id)
                        ->where('spec_value !=', '')
                        ->order_by('sort_order, spec_label')
                        ->get()->result();
    }

    public function edit($id) {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('equipment');
        }

        $equipment = $this->Equipment_model->get_by_id($id);
        if (!$equipment) show_404();

        if ($this->input->post()) {
            $data = [
                'equipment_type_id' => $this->input->post('equipment_type_id', TRUE),
                'registration_no'   => strtoupper($this->input->post('registration_no', TRUE)),
                'brand'             => $this->input->post('brand', TRUE),
                'model'             => $this->input->post('model', TRUE),
                'year'              => $this->input->post('year', TRUE),
                'serial_number'     => $this->input->post('serial_number', TRUE),
                'engine_model'      => $this->input->post('engine_model', TRUE),
                'current_hours'     => $this->input->post('current_hours', TRUE) ?: 0,
                'current_km'        => $this->input->post('current_km', TRUE) ?: 0,
                'location'          => $this->Equipment_model->sanitize_location($this->input->post('location', TRUE))['value'],   // W0.5
                'status'            => $this->input->post('status', TRUE),
                'purchase_date'     => $this->input->post('purchase_date', TRUE) ?: NULL,
                'warranty_expiry'   => $this->input->post('warranty_expiry', TRUE) ?: NULL,
                'notes'             => $this->input->post('notes', TRUE),
            ];
            // W0.3 — weekly-report classification (group / order / included)
            $data = array_merge($data, $this->Equipment_model->sanitize_reportability([
                'report_group'             => $this->input->post('report_group', TRUE),
                'report_sort'              => $this->input->post('report_sort', TRUE),
                'include_in_weekly_report' => $this->input->post('include_in_weekly_report', TRUE),
            ]));

            $this->Equipment_model->update($id, $data);
            $this->session->set_flashdata('success', 'Equipment updated.');
            redirect("equipment/view/{$id}");
        }

        $data['equipment']     = $equipment;
        $data['eq_types']      = $this->db->get('equipment_types')->result();
        $data['report_groups'] = $this->Equipment_model->report_groups();
        $data['report_sites']  = $this->Equipment_model->report_sites();      // W0.5
        $data['page_title']    = 'Edit Equipment: ' . $equipment->registration_no;
        $this->render('maintenance/equipment_form', $data);
    }

    /*
     * Meter update (audit finding F-06).
     *
     * This used to write `equipment.current_hours` / `current_km` directly,
     * with no history row and no validation — the meter could jump thousands
     * of hours, or go backwards, and nothing recorded who changed it. The
     * values now go through Equipment_model::record_meter_reading(), which is
     * the single writer for every meter value in the system: it keeps the
     * reading history, refuses an impossible jump or a backwards reading, and
     * follows the machine's meter_type. "This is a correction" is the explicit
     * way past those refusals, and it is recorded on the reading.
     */
    public function update_hours($id) {
        if (!$this->require_post()) return;

        $equipment = $this->Equipment_model->get_by_id($id);
        if (!$equipment) show_404();

        $override = (bool)$this->input->post('meter_override');
        $messages = array();
        $errors   = array();

        foreach (array('current_hours' => 'Hours', 'current_km' => 'Kilometres') as $field => $unit) {
            $raw = $this->input->post($field, TRUE);
            if ($raw === null || $raw === '') continue;

            if (!is_numeric($raw)) {
                $errors[] = ($unit === 'Kilometres' ? 'Kilometres' : 'Hours').' must be a number.';
                continue;
            }
            // Submitting the unchanged value is not a new reading.
            if (!$override && abs((float)$raw - (float)$equipment->{$field}) < 0.0001) continue;

            $result = $this->Equipment_model->record_meter_reading(
                $id, date('Y-m-d'), (float)$raw, 'Manual', null, $this->user_id,
                array('unit' => $unit, 'override' => $override)
            );
            if (empty($result['ok'])) $errors[] = $result['message'];
            else                      $messages[] = $result['message'];
        }

        if (!empty($errors)) {
            $this->session->set_flashdata('error', implode(' ', $errors));
        } elseif (!empty($messages)) {
            $this->session->set_flashdata('success', implode(' ', $messages));
        } else {
            $this->session->set_flashdata('success', 'Nothing changed — the readings are the same as the machine already shows.');
        }
        redirect("equipment/view/{$id}");
    }
}
