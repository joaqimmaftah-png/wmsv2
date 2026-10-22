<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Pm_model extends CI_Model {

    public function get_all($filter = []) {
        $this->db->select('ps.*, e.registration_no, e.brand, e.model, e.current_hours, e.current_km, e.location, et.name as type_name, et.category')
            ->from('pm_schedules ps')
            ->join('equipment e', 'e.id = ps.equipment_id')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left');

        if (!empty($filter['include_inactive'])) {
            // Include both Active and Inactive schedules
        } else {
            $this->db->where('ps.status', 'Active');
        }

        if (!empty($filter['equipment_id'])) $this->db->where('ps.equipment_id', $filter['equipment_id']);
        return $this->db->order_by('ps.next_due_date', 'ASC')->get()->result();
    }

    /**
     * Hydrate all PM schedules (`Active` + `Inactive`), equipment meters, BOM part readiness,
     * and completion counts in <= 4 constant O(1) ANSI-SQL queries (zero N+1 loops).
     *
     * @param  string|null $today optional YYYY-MM-DD date override for deterministic tests
     * @return array{rows:object[],locations:string[],categories:string[]}
     */
    public function get_planner_overview($today = NULL) {
        $today = ($today && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$today))
            ? (string)$today
            : date('Y-m-d');
        $today_ts = strtotime($today . ' 00:00:00');

        $rows = $this->db->select('ps.*, e.registration_no, e.brand, e.model, e.current_hours, e.current_km, e.location, e.status AS equip_status, et.name AS type_name, et.category')
            ->from('pm_schedules ps')
            ->join('equipment e', 'e.id = ps.equipment_id', 'left')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->order_by('ps.next_due_date', 'ASC')
            ->order_by('ps.id', 'ASC')
            ->get()->result();

        // Grouped BOM spare-part readiness per PM schedule (1 SQL query)
        $parts_map = array();
        if ($this->db->table_exists('pm_part_requirements') && $this->db->table_exists('materials')) {
            $p_rows = $this->db->select('ppr.pm_schedule_id, COUNT(*) AS parts_cnt, SUM(CASE WHEN COALESCE(m.final_qty, 0) < ppr.qty THEN 1 ELSE 0 END) AS short_cnt', FALSE)
                ->from('pm_part_requirements ppr')
                ->join('materials m', 'm.id = ppr.material_id', 'left')
                ->group_by('ppr.pm_schedule_id')
                ->get()->result();
            foreach ($p_rows as $pr) {
                $parts_map[(int)$pr->pm_schedule_id] = array(
                    'parts' => (int)$pr->parts_cnt,
                    'short' => (int)$pr->short_cnt,
                );
            }
        }

        // Grouped completion counts per PM schedule (1 SQL query)
        $comp_map = array();
        if ($this->db->table_exists('pm_completions')) {
            $c_rows = $this->db->select('pm_schedule_id, COUNT(*) AS comp_cnt', FALSE)
                ->from('pm_completions')
                ->group_by('pm_schedule_id')
                ->get()->result();
            foreach ($c_rows as $cr) {
                $comp_map[(int)$cr->pm_schedule_id] = (int)$cr->comp_cnt;
            }
        }

        $loc_set = array();
        $cat_set = array();

        foreach ($rows as $pm) {
            $pid = (int)$pm->id;
            $loc = trim((string)(isset($pm->location) ? $pm->location : ''));
            $cat = trim((string)(isset($pm->category) ? $pm->category : ''));
            if ($loc !== '') $loc_set[$loc] = TRUE;
            if ($cat !== '') $cat_set[$cat] = TRUE;

            $is_active = ((string)$pm->status === 'Active');
            $days_left = NULL;
            if (!empty($pm->next_due_date)) {
                $due_ts = strtotime(substr((string)$pm->next_due_date, 0, 10) . ' 00:00:00');
                if ($due_ts !== FALSE) {
                    $days_left = (int)round(($due_ts - $today_ts) / 86400);
                }
            }

            $is_meter = in_array((string)$pm->interval_type, array('Hours', 'Km'), TRUE);
            $meter_unit = ((string)$pm->interval_type === 'Km') ? 'km' : 'h';
            $cur_meter  = ((string)$pm->interval_type === 'Km')
                ? (float)(isset($pm->current_km) ? $pm->current_km : 0)
                : (float)(isset($pm->current_hours) ? $pm->current_hours : 0);
            $meter_left = NULL;
            if ($is_meter && $pm->next_due_hours !== NULL && (float)$pm->next_due_hours > 0) {
                $meter_left = round((float)$pm->next_due_hours - $cur_meter, 1);
            }
            $soon_meter_threshold = ((string)$pm->interval_type === 'Km') ? 500.0 : 50.0;

            if (!$is_active) {
                $state = 'inactive';
                $label = 'Inactive';
                $badge = 'b-secondary';
                $led   = 'led-off';
                $rank  = 50;
            } elseif (($days_left !== NULL && $days_left < 0) || ($meter_left !== NULL && $meter_left <= 0)) {
                $state = 'overdue';
                if ($days_left !== NULL && $days_left < 0) {
                    $label = 'Overdue (' . abs($days_left) . 'd)';
                } else {
                    $label = 'Overdue (' . number_format(abs((float)$meter_left), 0) . $meter_unit . ')';
                }
                $badge = 'b-breakdown';
                $led   = 'led-crit';
                $rank  = 1;
            } elseif (($days_left !== NULL && $days_left <= 7) || ($meter_left !== NULL && $meter_left <= $soon_meter_threshold)) {
                $state = 'due_soon';
                if ($days_left !== NULL && $days_left <= 7) {
                    $label = $days_left === 0 ? 'Due Today' : ('Due in ' . $days_left . 'd');
                } else {
                    $label = 'Due in ' . number_format((float)$meter_left, 0) . $meter_unit;
                }
                $badge = 'b-warning';
                $led   = 'led-warn';
                $rank  = 2;
            } elseif ($days_left === NULL && $meter_left === NULL) {
                $state = 'unscheduled';
                $label = 'Unscheduled';
                $badge = 'b-secondary';
                $led   = 'led-off';
                $rank  = 3;
            } else {
                $state = 'ok';
                $label = 'On Schedule';
                $badge = 'b-available';
                $led   = 'led-ok';
                $rank  = 4;
            }

            $std_hrs = isset($pm->standard_hours) && $pm->standard_hours !== NULL && $pm->standard_hours !== ''
                ? (float)$pm->standard_hours
                : NULL;
            $p_info = isset($parts_map[$pid]) ? $parts_map[$pid] : array('parts' => 0, 'short' => 0);

            $pm->days_left          = $days_left;
            $pm->cur_meter          = $cur_meter;
            $pm->meter_unit         = $meter_unit;
            $pm->meter_left         = $meter_left;
            $pm->compliance_state   = $state;
            $pm->compliance_label   = $label;
            $pm->compliance_badge   = $badge;
            $pm->compliance_led     = $led;
            $pm->urgency_rank       = $rank;
            $pm->std_hours_val      = $std_hrs;
            $pm->parts_count        = (int)$p_info['parts'];
            $pm->shortage_count     = (int)$p_info['short'];
            $pm->completion_count   = isset($comp_map[$pid]) ? (int)$comp_map[$pid] : 0;
            $pm->has_forecast_alert = ($is_active && ($std_hrs === NULL || empty($pm->next_due_date)));
        }

        $locations  = array_keys($loc_set);
        $categories = array_keys($cat_set);
        sort($locations, SORT_NATURAL | SORT_FLAG_CASE);
        sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

        return array(
            'rows'       => $rows,
            'locations'  => $locations,
            'categories' => $categories,
        );
    }

    public function get_by_id($id) {
        return $this->db->where('id', $id)->get('pm_schedules')->row();
    }

    public function get_by_equipment($equipment_id) {
        return $this->db->where('equipment_id', (int)$equipment_id)
            ->order_by('next_due_date', 'ASC')
            ->get('pm_schedules')->result();
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
                if ($pm->interval_type === 'Months') {
                    $next = date('Y-m-d', strtotime($next . " +{$pm->interval_value} months"));
                } elseif ($pm->interval_type === 'Days') {
                    $next = date('Y-m-d', strtotime($next . " +{$pm->interval_value} days"));
                } else {
                    break;
                }
            }
        }
        return $calendar;
    }
}
