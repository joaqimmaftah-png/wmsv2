<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Lubricants extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Lubricant_model');
        $this->load->model('Equipment_model');
    }

    public function index() {
        $filter = [
            'month'        => $this->input->get('month') ?: date('Y-m'),
            'equipment_id' => $this->input->get('equipment_id'),
            'lube_type_id' => $this->input->get('lube_type_id'),
        ];

        $data['consumption']   = $this->Lubricant_model->get_consumption($filter);
        $data['summary']       = $this->Lubricant_model->get_monthly_summary($filter['month']);
        $data['lube_types']    = $this->Lubricant_model->get_all_types();
        $data['equipment']     = $this->Equipment_model->get_all_active();
        $data['filter']        = $filter;

        // PM-driven requirement vs actual — compliance summary widget
        $from = $filter['month'].'-01';
        $to   = date('Y-m-t', strtotime($from));
        $data['compliance']    = $this->Lubricant_model->get_compliance_report($from, $to);

        $data['page_title']    = 'Lubricants & Consumables';
        $this->render('lubricants/index', $data);
    }

    public function record() {
        if (!in_array($this->role_id, [1, 3])) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('lubricants');
        }

        if ($this->input->post()) {
            $lube_type_id = $this->input->post('lube_type_id', TRUE);
            $equipment_id = $this->input->post('equipment_id', TRUE);
            $qty_used     = (float)$this->input->post('quantity_used', TRUE);

            $eq = $this->Equipment_model->get_by_id($equipment_id);
            $spec = $this->Lubricant_model->get_spec($eq->equipment_type_id, $lube_type_id);
            $rec_qty  = $spec ? $spec->recommended_qty : NULL;
            $variance = $rec_qty ? round($qty_used - $rec_qty, 2) : NULL;

            $data = [
                'equipment_id'    => $equipment_id,
                'lube_type_id'    => $lube_type_id,
                'quantity_used'   => $qty_used,
                'recommended_qty' => $rec_qty,
                'variance'        => $variance,
                'variance_reason' => $this->input->post('variance_reason', TRUE),
                'consumption_date'=> $this->input->post('consumption_date', TRUE) ?: date('Y-m-d'),
                'recorded_by'     => $this->user_id,
                'equipment_hours' => $this->input->post('equipment_hours', TRUE) ?: NULL,
            ];

            $this->db->insert('lube_consumption', $data);
            $this->session->set_flashdata('success', 'Consumption recorded.');
            redirect('lubricants');
        }

        $data['lube_types']  = $this->Lubricant_model->get_all_types();
        $data['equipment']   = $this->Equipment_model->get_all_active();
        $data['page_title']  = 'Record Consumption';
        $this->render('lubricants/record', $data);
    }

    public function types() {
        if ($this->input->post()) {
            if (!$this->is_supervisor()) {
                $this->session->set_flashdata('error', 'Access denied.');
                redirect('lubricants/types');
            }
            $data = [
                'name'        => $this->input->post('name', TRUE),
                'category'    => $this->input->post('category', TRUE),
                'unit'        => $this->input->post('unit', TRUE),
                'description' => $this->input->post('description', TRUE),
            ];
            $this->db->insert('lube_types', $data);
            $this->session->set_flashdata('success', 'Lube type added.');
            redirect('lubricants/types');
        }

        $data['lube_types']  = $this->Lubricant_model->get_all_types();
        $data['page_title']  = 'Lube Types Master';
        $this->render('lubricants/types', $data);
    }

    public function specifications() {
        if ($this->input->post()) {
            $data = [
                'equipment_type_id'       => $this->input->post('equipment_type_id', TRUE),
                'lube_type_id'            => $this->input->post('lube_type_id', TRUE),
                'recommended_qty'         => $this->input->post('recommended_qty', TRUE),
                'service_interval_hours'  => $this->input->post('service_interval_hours', TRUE) ?: NULL,
                'notes'                   => $this->input->post('notes', TRUE),
            ];
            $this->db->insert('lube_specifications', $data);
            $this->session->set_flashdata('success', 'Specification saved.');
            redirect('lubricants/specifications');
        }

        $data['specs']       = $this->Lubricant_model->get_all_specs();
        $data['eq_types']    = $this->db->get('equipment_types')->result();
        $data['lube_types']  = $this->Lubricant_model->get_all_types();
        $data['page_title']  = 'Lube Specifications';
        $this->render('lubricants/specifications', $data);
    }

    public function budget() {
        if ($this->input->post()) {
            $year  = $this->input->post('year', TRUE);
            $month = $this->input->post('month', TRUE);
            $items = $this->input->post('budget');
            foreach ($items as $lube_id => $vals) {
                $this->db->replace('lube_budget', [
                    'lube_type_id' => $lube_id,
                    'year'         => $year,
                    'month'        => $month,
                    'budget_qty'   => $vals['qty'],
                    'unit_cost'    => $vals['cost'],
                    'budget_cost'  => $vals['qty'] * $vals['cost'],
                ]);
            }
            $this->session->set_flashdata('success', 'Budget saved.');
            redirect('lubricants/budget');
        }

        $year  = $this->input->get('year') ?: date('Y');
        $month = $this->input->get('month') ?: date('m');
        $data['lube_types']   = $this->Lubricant_model->get_all_types();
        $data['budget_data']  = $this->Lubricant_model->get_budget($year, $month);
        $data['year']         = $year;
        $data['month']        = $month;
        $data['page_title']   = 'Lube Budget';
        $this->render('lubricants/budget', $data);
    }

    public function forecast() {
        $data['forecast']    = $this->Lubricant_model->get_monthly_forecast();
        $data['page_title']  = 'Consumption Forecast';
        $this->render('lubricants/forecast', $data);
    }
}
