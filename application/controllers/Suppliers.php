<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Suppliers extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Supplier_model');
        $this->load->model('Materials_model');
    }

    /* ── LIST ───────────────────────────────────────────── */
    public function index() {
        $filter = array(
            'country' => $this->input->get('country'),
            'status'  => $this->input->get('status'),
            'search'  => $this->input->get('search'),
        );
        $data = array(
            'suppliers'  => $this->Supplier_model->get_all($filter),
            'countries'  => $this->Supplier_model->get_countries(),
            'kpi'        => $this->Supplier_model->get_kpi(),
            'filter'     => $filter,
            'page_title' => 'Suppliers',
        );
        $this->render('suppliers/index', $data);
    }

    /* ── CREATE ─────────────────────────────────────────── */
    public function create() {
        if ($this->input->method() === 'post') {
            $name = trim((string)$this->input->post('company_name', TRUE));
            if ($name === '') {
                $this->session->set_flashdata('error', 'Company name is required.');
                redirect('suppliers/create');
                return;
            }
            $data = array(
                'company_name'             => $name,
                'tax_id'                   => $this->input->post('tax_id', TRUE) ?: NULL,
                'country'                  => $this->input->post('country', TRUE) ?: 'Tanzania',
                'city'                     => $this->input->post('city', TRUE) ?: NULL,
                'address'                  => $this->input->post('address', TRUE) ?: NULL,
                'currency'                 => wms_currency_code($this->input->post('currency', TRUE)),
                'payment_terms'            => $this->input->post('payment_terms', TRUE) ?: NULL,
                'lead_time_guarantee_days' => $this->input->post('lead_time_guarantee_days', TRUE) ?: NULL,
                'status'                   => $this->input->post('status', TRUE) ?: 'Active',
                'notes'                    => $this->input->post('notes', TRUE) ?: NULL,
            );
            $id = $this->Supplier_model->insert($data);
            $this->session->set_flashdata('success', 'Supplier added successfully.');
            redirect('suppliers/view/'.$id);
        }
        $data['page_title'] = 'Add Supplier';
        $this->render('suppliers/form', $data);
    }

    /* ── EDIT ───────────────────────────────────────────── */
    public function edit($id) {
        $supplier = $this->Supplier_model->get_by_id($id);
        if (!$supplier) show_404();

        if ($this->input->method() === 'post') {
            $data = array(
                'company_name'             => $this->input->post('company_name', TRUE),
                'tax_id'                   => $this->input->post('tax_id', TRUE),
                'country'                  => $this->input->post('country', TRUE),
                'city'                     => $this->input->post('city', TRUE),
                'address'                  => $this->input->post('address', TRUE),
                'currency'                 => wms_currency_code($this->input->post('currency', TRUE)),
                'payment_terms'            => $this->input->post('payment_terms', TRUE),
                'lead_time_guarantee_days' => $this->input->post('lead_time_guarantee_days', TRUE) ?: NULL,
                'status'                   => $this->input->post('status', TRUE) ?: 'Active',
                'notes'                    => $this->input->post('notes', TRUE),
            );
            $this->Supplier_model->update($id, $data);
            $this->session->set_flashdata('success', 'Supplier updated successfully.');
            redirect('suppliers/view/'.$id);
        }
        $data['supplier']   = $supplier;
        $data['page_title'] = 'Edit Supplier';
        $this->render('suppliers/form', $data);
    }

    /* ── VIEW (profile + contacts + catalog) ───────────── */
    public function view($id) {
        $supplier = $this->Supplier_model->get_by_id($id);
        if (!$supplier) show_404();

        $this->load->model('Order_model');
        $data['supplier']      = $supplier;
        $data['contacts']      = $this->Supplier_model->get_contacts($id);
        $data['catalog']       = $this->Supplier_model->get_catalog($id);
        $data['materials']     = $this->Materials_model->get_all();
        $data['on_time']       = $this->Order_model->get_supplier_on_time_rate($id);
        $data['recent_orders'] = $this->Order_model->get_all(array('supplier' => $id));
        $data['page_title']    = $supplier->company_name;
        $this->render('suppliers/view', $data);
    }

    /* ── CONTACTS ───────────────────────────────────────── */
    public function add_contact($supplier_id) {
        if (!$this->require_post()) return;
        $name = trim((string) $this->input->post('name', TRUE));
        if ($name === '') {
            $this->session->set_flashdata('error', 'Contact name is required.');
            redirect('suppliers/view/'.$supplier_id);
            return;
        }
        $this->Supplier_model->add_contact(array(
            'supplier_id' => (int) $supplier_id,
            'name'        => $name,
            'role'        => trim((string) $this->input->post('role', TRUE)),
            'phone'       => trim((string) $this->input->post('phone', TRUE)),
            'email'       => trim((string) $this->input->post('email', TRUE)),
        ));
        $this->session->set_flashdata('success', 'Contact added.');
        redirect('suppliers/view/'.$supplier_id);
    }

    public function delete_contact($id, $supplier_id = null) {
        if (!$this->require_post()) return;
        if ($supplier_id === null) show_404();
        if (!$this->Supplier_model->delete_contact($id, $supplier_id)) show_404();
        $this->session->set_flashdata('success', 'Contact removed.');
        redirect('suppliers/view/'.$supplier_id);
    }

    /* ── CATALOG (supplier ↔ material mapping) ──────────── */
    public function add_catalog_item($supplier_id) {
        if (!$this->require_post()) return;
        $part_number = trim((string) $this->input->post('part_number', TRUE));
        $description = trim((string) $this->input->post('description', TRUE));
        if ($part_number === '' && $description === '') {
            $this->session->set_flashdata('error', 'Part number or description is required.');
            redirect('suppliers/view/'.$supplier_id);
            return;
        }
        $this->Supplier_model->add_catalog_item(array(
            'supplier_id'    => (int) $supplier_id,
            'material_id'    => $this->input->post('material_id', TRUE) ?: NULL,
            'part_number'    => $part_number,
            'description'    => $description,
            'unit_price'     => $this->input->post('unit_price', TRUE) ?: NULL,
            'currency'       => wms_currency_code($this->input->post('currency', TRUE)),
            'moq'            => $this->input->post('moq', TRUE) ?: NULL,
            'lead_time_days' => $this->input->post('lead_time_days', TRUE) ?: NULL,
            'is_preferred'   => $this->input->post('is_preferred', TRUE) ? 1 : 0,
        ));
        $this->session->set_flashdata('success', 'Catalog item added.');
        redirect('suppliers/view/'.$supplier_id);
    }

    public function delete_catalog_item($id, $supplier_id = null) {
        if (!$this->require_post()) return;
        if ($supplier_id === null) show_404();
        if (!$this->Supplier_model->delete_catalog_item($id, $supplier_id)) show_404();
        $this->session->set_flashdata('success', 'Catalog item removed.');
        redirect('suppliers/view/'.$supplier_id);
    }

    /* ── "Who supplies this part?" lookup ───────────────── */
    public function find_part() {
        $q = $this->input->get('q');
        $data['q']          = $q;
        $data['results']    = $q ? $this->Supplier_model->find_suppliers_for_part($q) : array();
        $data['page_title'] = 'Find Supplier for Part';
        $this->render('suppliers/find_part', $data);
    }

    public function delete($id) {
        if (!$this->require_post()) return;
        $this->Supplier_model->delete($id);
        $this->session->set_flashdata('success', 'Supplier deleted.');
        redirect('suppliers');
    }
}
