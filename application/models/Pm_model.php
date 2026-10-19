<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Pm_model extends CI_Model {

    public function get_all($filter = []) {
        $this->db->select('ps.*, e.registration_no, e.brand, e.model, e.current_hours, et.name as type_name')
            ->from('pm_schedules ps')
            ->join('equipment e', 'e.id = ps.equipment_id')
            ->join('equipment_types et', 'et.id = e.equipment_type_id')
            ->where('ps.status', 'Active');

        if (!empty($filter['equipment_id'])) $this->db->where('ps.equipment_id', $filter['equipment_id']);
        return $this->db->order_by('ps.next_due_date', 'ASC')->get()->result();
    }

    public function get_by_id($id) {
        return $this->db->where('id', $id)->get('pm_schedules')->row();
    }

    public function create($data) {
        $this->db->insert('pm_schedules', $data);
        return $this->db->insert_id();
    }

    public function update($id, $data) {
        $this->db->update('pm_schedules', $data, ['id' => $id]);
    }

    public function get_due_today() {
        $today = date('Y-m-d');
        return $this->db->select('ps.*, e.registration_no, e.brand, e.model')
            ->from('pm_schedules ps')->join('equipment e', 'e.id = ps.equipment_id')
            ->where('ps.next_due_date', $today)->where('ps.status', 'Active')->get()->result();
    }

    public function get_overdue() {
        return $this->db->select('ps.*, e.registration_no, e.brand, e.model')
            ->from('pm_schedules ps')->join('equipment e', 'e.id = ps.equipment_id')
            ->where('ps.next_due_date <', date('Y-m-d'))->where('ps.status', 'Active')
            ->order_by('ps.next_due_date', 'ASC')->get()->result();
    }

    public function get_12month_forecast($months = 12) {
        $schedules = $this->db->select('ps.*, e.registration_no, e.brand, e.model')
            ->from('pm_schedules ps')->join('equipment e', 'e.id = ps.equipment_id')
            ->where('ps.status', 'Active')->get()->result();

        $calendar = [];
        for ($m = 0; $m < $months; $m++) {
            $month_key = date('Y-m', strtotime("+{$m} months"));
            $calendar[$month_key] = [];
        }

        foreach ($schedules as $pm) {
            if (!$pm->next_due_date) continue;
            $next = $pm->next_due_date;
            for ($m = 0; $m < $months; $m++) {
                $month_key  = date('Y-m', strtotime("+{$m} months"));
                $due_month  = date('Y-m', strtotime($next));
                if ($due_month === $month_key) {
                    $calendar[$month_key][] = $pm;
                }
                // Estimate future occurrences
                if ($pm->interval_type === 'Months') {
                    $next = date('Y-m-d', strtotime($next . " +{$pm->interval_value} months"));
                } elseif ($pm->interval_type === 'Days') {
                    $next = date('Y-m-d', strtotime($next . " +{$pm->interval_value} days"));
                } else {
                    break; // hours-based, only show next
                }
            }
        }
        return $calendar;
    }
}
