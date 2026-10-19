<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Store_model — the queries behind the store registers that used to be kept
 * by hand in `myexcellreport/Inventory.xlsx`.
 *
 *   Excel sheet "Sortie"    →  issue_register()    (ISSUE movements)
 *   Excel sheet "Receipts"  →  receipt_register()  (RECEIVE movements)
 *   Excel columns Alerte /
 *   Variance / Qty to order →  Materials_model::get_reorder_items()
 *
 * Everything reads `material_transactions` as extended by
 * migrations/007_excel_alignment.sql.  When that migration has not been
 * applied the model reports "not available" instead of failing, so the rest
 * of the application is unaffected.
 */
class Store_model extends CI_Model {

    /** Hard cap so a report can never try to render the whole history. */
    const MAX_ROWS = 5000;

    public function available() {
        return $this->db->table_exists('material_transactions')
            && $this->db->field_exists('txn_date', 'material_transactions');
    }

    /** Business date of a movement: the register date, else the row's timestamp. */
    private function _date_expr() {
        return 'COALESCE(mt.txn_date, DATE(mt.created_at))';
    }

    private function _base($filter) {
        $d = $this->_date_expr();
        $this->db
            ->select('mt.id, mt.type, mt.qty, mt.qty_approved, mt.reference_no,
                      mt.allocation_type, mt.allocation_label, mt.person,
                      mt.supplier_name, mt.unit_price, mt.currency, mt.source,
                      mt.notes, mt.created_at', FALSE)
            ->select($d.' AS txn_date', FALSE)
            ->select('m.item_code, m.description, m.part_number_size, m.unit, m.category', FALSE)
            ->select('e.registration_no AS equipment_code', FALSE)
            ->select('CONCAT(COALESCE(u.first_name,"")," ",COALESCE(u.last_name,"")) AS user_name', FALSE)
            ->from('material_transactions mt')
            ->join('materials m',  'm.id = mt.material_id', 'left')
            ->join('equipment e',  'e.id = mt.equipment_id', 'left')
            ->join('users u',      'u.id = mt.created_by',  'left');

        if (!empty($filter['date_from'])) $this->db->where($d.' >=', $filter['date_from']);
        if (!empty($filter['date_to']))   $this->db->where($d.' <=', $filter['date_to']);
        if (!empty($filter['category']))  $this->db->where('m.category', $filter['category']);
        if (!empty($filter['item_code'])) $this->db->where('m.item_code', $filter['item_code']);
        if (!empty($filter['equipment_id'])) $this->db->where('mt.equipment_id', (int)$filter['equipment_id']);
        if (!empty($filter['allocation'])) {
            $s = $this->db->escape_like_str($filter['allocation']);
            $this->db->where("mt.allocation_label LIKE '%{$s}%'");
        }
        if (!empty($filter['supplier'])) {
            $s = $this->db->escape_like_str($filter['supplier']);
            $this->db->where("mt.supplier_name LIKE '%{$s}%'");
        }
        if (!empty($filter['reference'])) {
            $s = $this->db->escape_like_str($filter['reference']);
            $this->db->where("mt.reference_no LIKE '%{$s}%'");
        }
        if (!empty($filter['person'])) {
            $s = $this->db->escape_like_str($filter['person']);
            $this->db->where("mt.person LIKE '%{$s}%'");
        }
        if (!empty($filter['search'])) {
            $s = $this->db->escape_like_str($filter['search']);
            $this->db->where("(m.item_code LIKE '%{$s}%' OR m.description LIKE '%{$s}%'
                               OR m.part_number_size LIKE '%{$s}%')");
        }
        return $d;
    }

    /** Issues — the "Sortie" register. */
    public function issue_register($filter = array(), $limit = self::MAX_ROWS) {
        if (!$this->available()) return array();
        $d = $this->_base($filter);
        $this->db->where('mt.type', 'ISSUE');
        $this->db->order_by($d, 'DESC', FALSE)->order_by('mt.id', 'DESC');
        if ($limit > 0) $this->db->limit((int)$limit);
        $r = $this->db->get()->result();
        return $r ? $r : array();
    }

    /** Receipts — the "Receipts" register. */
    public function receipt_register($filter = array(), $limit = self::MAX_ROWS) {
        if (!$this->available()) return array();
        $d = $this->_base($filter);
        $this->db->where('mt.type', 'RECEIVE');
        $this->db->order_by($d, 'DESC', FALSE)->order_by('mt.id', 'DESC');
        if ($limit > 0) $this->db->limit((int)$limit);
        $r = $this->db->get()->result();
        return $r ? $r : array();
    }

    /** Headline figures for a register (computed over the WHOLE filter, not the page). */
    public function register_totals($type, $filter = array()) {
        $empty = array('lines' => 0, 'qty' => 0.0, 'value' => 0.0, 'items' => 0,
                       'first' => null, 'last' => null);
        if (!$this->available()) return $empty;
        $d2 = $this->_date_expr();
        $this->db->select('COUNT(*) AS `lines`, COALESCE(SUM(mt.qty),0) AS qty,
                           COALESCE(SUM(mt.qty * COALESCE(mt.unit_price,0)),0) AS value,
                           COUNT(DISTINCT mt.material_id) AS items,
                           MIN('.$d2.') AS first_date, MAX('.$d2.') AS last_date', FALSE)
                 ->from('material_transactions mt')
                 ->join('materials m', 'm.id = mt.material_id', 'left')
                 ->where('mt.type', $type);
        if (!empty($filter['date_from'])) $this->db->where($d2.' >=', $filter['date_from']);
        if (!empty($filter['date_to']))   $this->db->where($d2.' <=', $filter['date_to']);
        if (!empty($filter['category']))  $this->db->where('m.category', $filter['category']);
        if (!empty($filter['item_code'])) $this->db->where('m.item_code', $filter['item_code']);
        if (!empty($filter['equipment_id'])) $this->db->where('mt.equipment_id', (int)$filter['equipment_id']);
        if (!empty($filter['allocation'])) {
            $s = $this->db->escape_like_str($filter['allocation']);
            $this->db->where("mt.allocation_label LIKE '%{$s}%'");
        }
        if (!empty($filter['supplier'])) {
            $s = $this->db->escape_like_str($filter['supplier']);
            $this->db->where("mt.supplier_name LIKE '%{$s}%'");
        }
        if (!empty($filter['search'])) {
            $s = $this->db->escape_like_str($filter['search']);
            $this->db->where("(m.item_code LIKE '%{$s}%' OR m.description LIKE '%{$s}%')");
        }
        $row = $this->db->get()->row();
        if (!$row) return $empty;
        return array(
            'lines' => (int)$row->lines,
            'qty'   => (float)$row->qty,
            'value' => (float)$row->value,
            'items' => (int)$row->items,
            'first' => $row->first_date,
            'last'  => $row->last_date,
        );
    }

    /**
     * Consumption per allocation (machine or department) — the question the
     * "Sortie" sheet was really kept for and that a pivot table had to answer.
     */
    public function consumption_by_allocation($filter = array(), $limit = 40) {
        if (!$this->available()) return array();
        $d = $this->_date_expr();
        $this->db->select('COALESCE(e.registration_no, mt.allocation_label, "(unallocated)") AS allocation,
                           mt.allocation_type,
                           COUNT(*) AS `lines`,
                           COALESCE(SUM(mt.qty),0) AS qty,
                           COALESCE(SUM(mt.qty * COALESCE(mt.unit_price,0)),0) AS value', FALSE)
                 ->from('material_transactions mt')
                 ->join('equipment e', 'e.id = mt.equipment_id', 'left')
                 ->where('mt.type', 'ISSUE');
        if (!empty($filter['date_from'])) $this->db->where($d.' >=', $filter['date_from']);
        if (!empty($filter['date_to']))   $this->db->where($d.' <=', $filter['date_to']);
        $r = $this->db->group_by('allocation, mt.allocation_type')
                      ->order_by('qty', 'DESC')->limit((int)$limit)->get()->result();
        return $r ? $r : array();
    }

    /** Distinct allocation labels, for the filter drop-down. */
    public function allocations() {
        if (!$this->available()) return array();
        $r = $this->db->select('DISTINCT allocation_label', FALSE)
                      ->from('material_transactions')
                      ->where('allocation_label IS NOT NULL')
                      ->where('allocation_label !=', '')
                      ->order_by('allocation_label')->limit(500)->get()->result();
        return $r ? $r : array();
    }

    /** Distinct supplier names seen on receipts, for the filter drop-down. */
    public function receipt_suppliers() {
        if (!$this->available()) return array();
        $r = $this->db->select('DISTINCT supplier_name', FALSE)
                      ->from('material_transactions')
                      ->where('type', 'RECEIVE')
                      ->where('supplier_name IS NOT NULL')
                      ->where('supplier_name !=', '')
                      ->order_by('supplier_name')->limit(500)->get()->result();
        return $r ? $r : array();
    }

    /** Monthly in/out totals — replaces the "Feuil1" pivot table. */
    public function monthly_movement($year = null) {
        if (!$this->available()) return array();
        $year = $year ? (int)$year : (int)date('Y');
        $d = $this->_date_expr();
        $r = $this->db->select('DATE_FORMAT('.$d.', "%Y-%m") AS period,
                                SUM(CASE WHEN mt.type = "RECEIVE" THEN mt.qty ELSE 0 END) AS received,
                                SUM(CASE WHEN mt.type = "ISSUE"   THEN mt.qty ELSE 0 END) AS issued,
                                COUNT(*) AS `lines`', FALSE)
                      ->from('material_transactions mt')
                      ->where('YEAR('.$d.')', $year)
                      ->group_by('period')->order_by('period')->get()->result();
        return $r ? $r : array();
    }

    /** Equipment list used by the allocation filter. */
    public function equipment_options() {
        if (!$this->db->table_exists('equipment')) return array();
        $r = $this->db->select('id, registration_no')->from('equipment')
                      ->order_by('registration_no')->get()->result();
        return $r ? $r : array();
    }
}
