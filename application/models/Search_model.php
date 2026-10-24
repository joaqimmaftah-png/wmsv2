<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Global Find queries.
 *
 * Results are intentionally limited per record type. Exact identifiers are
 * ordered ahead of partial matches so a WO number, registration, item code or
 * part number remains useful even when the same text occurs elsewhere.
 */
class Search_model extends CI_Model {

    const PER_GROUP = 5;

    public function find($q, $user_id, $role_id) {
        $q    = trim((string)$q);
        $role = (int)$role_id;
        if ($q === '' || !in_array($role, array(1, 2, 3, 4), TRUE)) return array();

        $groups = array();

        // Work orders and equipment are available to Supervisor, Technician
        // and Manager. Store keeps inventory search; CEO is excluded above.
        if (in_array($role, array(1, 2, 4), TRUE)) {
            $work_orders = $this->_work_orders($q, (int)$user_id, $role);
            if (!empty($work_orders)) {
                $groups[] = array(
                    'key'     => 'work_orders',
                    'label'   => 'Work orders',
                    'results' => $work_orders,
                );
            }

            $equipment = $this->_equipment($q);
            if (!empty($equipment)) {
                $groups[] = array(
                    'key'     => 'equipment',
                    'label'   => 'Equipment',
                    'results' => $equipment,
                );
            }
        }

        $materials = $this->_materials($q);
        if (!empty($materials)) {
            $groups[] = array(
                'key'     => 'inventory',
                'label'   => 'Inventory',
                'results' => $materials,
            );
        }

        return $groups;
    }

    private function _work_orders($q, $user_id, $role_id) {
        if (!$this->db->table_exists('work_orders') || !$this->db->table_exists('equipment')) return array();

        $this->db
            ->select('wo.id, wo.wo_number, wo.title, wo.type, wo.priority, wo.status,
                e.registration_no, e.brand, e.model')
            ->from('work_orders wo')
            ->join('equipment e', 'e.id = wo.equipment_id', 'left');

        if ($role_id === 2) $this->_scope_technician_work_orders($user_id);

        $this->db->group_start()
            ->like('wo.wo_number', $q, 'both')
            ->or_like('wo.title', $q, 'both')
            ->or_like('wo.description', $q, 'both')
            ->or_like('e.registration_no', $q, 'both')
            ->or_like('e.brand', $q, 'both')
            ->or_like('e.model', $q, 'both')
            ->group_end();

        $exact = $this->db->escape($q);
        $rows = $this->db
            ->order_by('CASE WHEN wo.wo_number = '.$exact.' THEN 0 WHEN e.registration_no = '.$exact.' THEN 1 ELSE 2 END', '', FALSE)
            ->order_by('wo.updated_at', 'DESC')
            ->order_by('wo.id', 'DESC')
            ->limit(self::PER_GROUP)
            ->get()->result();

        $results = array();
        foreach ($rows as $row) {
            $context = array_filter(array(
                trim((string)$row->registration_no),
                trim((string)$row->type),
                trim((string)$row->priority) !== '' ? $row->priority.' priority' : '',
            ));
            $results[] = array(
                'type'       => 'work_order',
                'identifier' => (string)$row->wo_number,
                'title'      => (string)$row->title,
                'context'    => implode(' · ', $context),
                'status'     => (string)$row->status,
                'tone'       => $this->_work_order_tone($row),
                'url'        => base_url('workorders/view/'.(int)$row->id),
            );
        }
        return $results;
    }

    private function _equipment($q) {
        if (!$this->db->table_exists('equipment')) return array();

        $this->db
            ->select('id, registration_no, legacy_code, machine_type, brand, model, serial_number, status, location')
            ->from('equipment')
            ->group_start()
            ->like('registration_no', $q, 'both')
            ->or_like('legacy_code', $q, 'both')
            ->or_like('serial_number', $q, 'both')
            ->or_like('frame_no', $q, 'both')
            ->or_like('brand', $q, 'both')
            ->or_like('model', $q, 'both')
            ->or_like('machine_type', $q, 'both')
            ->group_end();

        $exact = $this->db->escape($q);
        $rows = $this->db
            ->order_by('CASE WHEN registration_no = '.$exact.' THEN 0 WHEN legacy_code = '.$exact.' THEN 1 WHEN serial_number = '.$exact.' THEN 2 ELSE 3 END', '', FALSE)
            ->order_by('registration_no', 'ASC')
            ->limit(self::PER_GROUP)
            ->get()->result();

        $results = array();
        foreach ($rows as $row) {
            $title = trim((string)($row->brand.' '.$row->model));
            if ($title === '') $title = trim((string)$row->machine_type) !== '' ? (string)$row->machine_type : 'Equipment';
            $context = array_filter(array(
                trim((string)$row->machine_type),
                trim((string)$row->location),
            ));
            $results[] = array(
                'type'       => 'equipment',
                'identifier' => (string)$row->registration_no,
                'title'      => $title,
                'context'    => implode(' · ', $context),
                'status'     => (string)$row->status,
                'tone'       => $this->_equipment_tone($row->status),
                'url'        => base_url('equipment/view/'.(int)$row->id),
            );
        }
        return $results;
    }

    private function _materials($q) {
        if (!$this->db->table_exists('materials')) return array();

        $this->db
            ->select('id, item_code, description, part_number_size, unit, initial_stock,
                qty_received, qty_sortie, min_stock, application, location, is_active')
            ->from('materials')
            ->group_start()
            ->like('item_code', $q, 'both')
            ->or_like('part_number_size', $q, 'both')
            ->or_like('description', $q, 'both')
            ->or_like('application', $q, 'both')
            ->group_end();

        $exact = $this->db->escape($q);
        $rows = $this->db
            ->order_by('CASE WHEN item_code = '.$exact.' THEN 0 WHEN part_number_size = '.$exact.' THEN 1 ELSE 2 END', '', FALSE)
            ->order_by('item_code', 'ASC')
            ->limit(self::PER_GROUP)
            ->get()->result();

        $results = array();
        foreach ($rows as $row) {
            $balance = (int)$row->initial_stock + (int)$row->qty_received - (int)$row->qty_sortie;
            $minimum = ($row->min_stock !== NULL && $row->min_stock !== '') ? (int)$row->min_stock : 5;
            if (empty($row->is_active)) {
                $stock_label = 'Inactive';
                $tone        = 'neutral';
            } elseif ($balance <= 0) {
                $stock_label = 'Out of stock';
                $tone        = 'critical';
            } elseif ($balance <= $minimum) {
                $stock_label = 'Low stock';
                $tone        = 'warning';
            } else {
                $stock_label = 'In stock';
                $tone        = 'success';
            }

            $context = array_filter(array(
                trim((string)$row->part_number_size) !== '' ? 'Part '.trim((string)$row->part_number_size) : '',
                'Balance '.$balance.' '.trim((string)($row->unit ?: 'pcs')),
                trim((string)$row->location),
            ));
            $results[] = array(
                'type'       => 'inventory',
                'identifier' => (string)$row->item_code,
                'title'      => (string)$row->description,
                'context'    => implode(' · ', $context),
                'status'     => $stock_label,
                'tone'       => $tone,
                'url'        => base_url('materials/inventory?q='.rawurlencode((string)$row->item_code).'&item='.(int)$row->id),
            );
        }
        return $results;
    }

    /** Exact CSV membership, matching the existing spare-alert WO scope. */
    private function _scope_technician_work_orders($user_id) {
        $uid = (int)$user_id;
        $this->db->group_start()
            ->where('wo.created_by', $uid)
            ->or_where('wo.supervisor_id', $uid)
            ->or_where("(wo.assigned_to = '{$uid}' OR wo.assigned_to LIKE '{$uid},%'"
                ." OR wo.assigned_to LIKE '%,{$uid},%' OR wo.assigned_to LIKE '%,{$uid}')", null, false)
            ->group_end();
    }

    private function _work_order_tone($row) {
        if ((string)$row->priority === 'Critical') return 'critical';
        if (in_array((string)$row->status, array('Waiting Parts', 'Waiting Tools'), TRUE)) return 'warning';
        if (in_array((string)$row->status, array('Completed', 'Closed'), TRUE)) return 'success';
        return 'neutral';
    }

    private function _equipment_tone($status) {
        if ((string)$status === 'Breakdown') return 'critical';
        if ((string)$status === 'In Maintenance') return 'warning';
        if ((string)$status === 'Available') return 'success';
        return 'neutral';
    }
}
