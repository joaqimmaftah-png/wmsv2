<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Supplier_model extends CI_Model {

    public function get_all($filter = array()) {
        $this->db->select('s.*, (SELECT COUNT(*) FROM supplier_materials sm WHERE sm.supplier_id = s.id) as item_count');
        $this->db->from('suppliers s');
        if (!empty($filter['country']))  $this->db->where('s.country', $filter['country']);
        if (!empty($filter['status']))   $this->db->where('s.status', $filter['status']);
        if (!empty($filter['search'])) {
            $this->db->group_start()
                ->like('s.company_name', $filter['search'])
                ->or_like('s.tax_id', $filter['search'])
                ->group_end();
        }
        $this->db->order_by('s.company_name', 'ASC');
        return $this->db->get()->result();
    }

    public function get_by_id($id) {
        return $this->db->where('id', $id)->get('suppliers')->row();
    }

    public function get_countries() {
        $rows = $this->db->select('country')->distinct()->order_by('country', 'ASC')->get('suppliers')->result();
        return array_map(function($r){ return $r->country; }, $rows);
    }

    public function insert($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->insert('suppliers', $data);
        return $this->db->insert_id();
    }

    public function update($id, $data) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->where('id', $id)->update('suppliers', $data);
    }

    public function delete($id) {
        return $this->db->where('id', $id)->delete('suppliers');
    }

    /* ── Contacts ──────────────────────────────────────── */
    public function get_contacts($supplier_id) {
        return $this->db->where('supplier_id', $supplier_id)->order_by('id', 'ASC')->get('supplier_contacts')->result();
    }

    public function add_contact($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->insert('supplier_contacts', $data);
        return $this->db->insert_id();
    }

    public function delete_contact($id, $supplier_id) {
        $deleted = $this->db->where('id', (int)$id)
            ->where('supplier_id', (int)$supplier_id)
            ->delete('supplier_contacts');
        return $deleted && $this->db->affected_rows() > 0;
    }

    /* ── Catalog (supplier ↔ material mapping) ───────────── */
    public function get_catalog($supplier_id) {
        $this->db->select('sm.*, m.item_code, m.description as material_desc')
            ->from('supplier_materials sm')
            ->join('materials m', 'm.id = sm.material_id', 'left')
            ->where('sm.supplier_id', $supplier_id)
            ->order_by('sm.is_preferred', 'DESC')
            ->order_by('sm.description', 'ASC');
        return $this->db->get()->result();
    }

    public function add_catalog_item($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->insert('supplier_materials', $data);
        return $this->db->insert_id();
    }

    public function update_catalog_item($id, $data) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->where('id', $id)->update('supplier_materials', $data);
    }

    public function delete_catalog_item($id, $supplier_id) {
        $deleted = $this->db->where('id', (int)$id)
            ->where('supplier_id', (int)$supplier_id)
            ->delete('supplier_materials');
        return $deleted && $this->db->affected_rows() > 0;
    }

    /* "Who supplies this part?" — search across all suppliers' catalogs */
    public function find_suppliers_for_part($search) {
        $this->db->select('sm.*, s.company_name, s.country, s.status as supplier_status, m.item_code')
            ->from('supplier_materials sm')
            ->join('suppliers s', 's.id = sm.supplier_id')
            ->join('materials m', 'm.id = sm.material_id', 'left')
            ->group_start()
                ->like('sm.description', $search)
                ->or_like('sm.part_number', $search)
                ->or_like('m.item_code', $search)
            ->group_end()
            ->order_by('sm.is_preferred', 'DESC');
        return $this->db->get()->result();
    }

    public function get_kpi() {
        return array(
            'total'       => $this->db->count_all('suppliers'),
            'active'      => $this->db->where('status', 'Active')->count_all_results('suppliers'),
            'blacklisted' => $this->db->where('status', 'Blacklisted')->count_all_results('suppliers'),
            'countries'   => count($this->get_countries()),
        );
    }
}
