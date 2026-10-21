<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Lubricant_model extends CI_Model {

    public function get_all_types() {
        return $this->db->where('status', 'Active')->order_by('category')->get('lube_types')->result();
    }

    public function get_all_specs() {
        return $this->db->select('ls.*, et.name as type_name, lt.name as lube_name, lt.unit')
            ->from('lube_specifications ls')
            ->join('equipment_types et', 'et.id = ls.equipment_type_id')
            ->join('lube_types lt', 'lt.id = ls.lube_type_id')
            ->order_by('et.name')->get()->result();
    }

    public function get_spec($equipment_type_id, $lube_type_id) {
        return $this->db->where('equipment_type_id', $equipment_type_id)
            ->where('lube_type_id', $lube_type_id)->get('lube_specifications')->row();
    }

    public function get_consumption($filter = array()) {
        $this->db->select('lc.*, lt.name as lube_name, lt.unit, lt.category,
                           e.registration_no, wo.wo_number,
                           CONCAT(u.first_name," ",u.last_name) as recorded_by_name')
            ->from('lube_consumption lc')
            ->join('lube_types lt', 'lt.id = lc.lube_type_id')
            ->join('equipment e', 'e.id = lc.equipment_id')
            ->join('work_orders wo', 'wo.id = lc.work_order_id', 'left')
            ->join('users u', 'u.id = lc.recorded_by', 'left');

        if (!empty($filter['month'])) {
            // Avoid DATE_FORMAT in WHERE — use BETWEEN instead (CI3 safe, index-friendly)
            $from = $filter['month'] . '-01';
            $to   = date('Y-m-t', strtotime($from));
            $this->db->where('lc.consumption_date >=', $from)->where('lc.consumption_date <=', $to);
        }
        if (!empty($filter['equipment_id'])) $this->db->where('lc.equipment_id', $filter['equipment_id']);
        if (!empty($filter['lube_type_id'])) $this->db->where('lc.lube_type_id', $filter['lube_type_id']);

        return $this->db->order_by('lc.consumption_date', 'DESC')->get()->result();
    }

    public function get_by_equipment($equipment_id) {
        return $this->db->select('lc.*, lt.name as lube_name, lt.unit')->from('lube_consumption lc')
            ->join('lube_types lt', 'lt.id = lc.lube_type_id')
            ->where('lc.equipment_id', $equipment_id)->order_by('lc.consumption_date', 'DESC')->get()->result();
    }

    public function get_monthly_summary($month) {
        // Use BETWEEN instead of DATE_FORMAT to avoid CI3 missing-= bug
        $from = $month . '-01';
        $to   = date('Y-m-t', strtotime($from));
        return $this->db->select('lc.lube_type_id, lt.name as lube_name, lt.category, lt.unit,
                                  SUM(lc.quantity_used) as total_used, AVG(lc.quantity_used) as avg_used,
                                  SUM(lc.recommended_qty) as total_rec, COUNT(*) as records')
            ->from('lube_consumption lc')
            ->join('lube_types lt', 'lt.id = lc.lube_type_id')
            ->where('lc.consumption_date >=', $from)
            ->where('lc.consumption_date <=', $to)
            ->group_by('lc.lube_type_id, lt.name, lt.category, lt.unit')
            ->order_by('lt.category')->get()->result();
    }

    public function get_budget($year, $month) {
        $records = $this->db->select('lb.*, lt.name as lube_name, lt.unit')
            ->from('lube_budget lb')->join('lube_types lt', 'lt.id = lb.lube_type_id')
            ->where('lb.year', $year)->where('lb.month', $month)->get()->result();
        $indexed = array();
        foreach ($records as $r) { $indexed[$r->lube_type_id] = $r; }
        return $indexed;
    }

    public function get_monthly_forecast() {
        $result = array();
        for ($m = 0; $m <= 1; $m++) {
            $month = date('Y-m', strtotime("+{$m} months"));
            $from  = $month . '-01';
            $to    = date('Y-m-t', strtotime($from));

            $pm_due = $this->db->where('next_due_date >=', $from)->where('next_due_date <=', $to)
                ->where('status', 'Active')->get('pm_schedules')->result();

            $lube_forecast = array();
            foreach ($pm_due as $pm) {
                $eq = $this->db->where('id', $pm->equipment_id)->get('equipment')->row();
                if (!$eq) continue;
                $specs = $this->db->where('equipment_type_id', $eq->equipment_type_id)->get('lube_specifications')->result();
                foreach ($specs as $spec) {
                    $lid = $spec->lube_type_id;
                    if (!isset($lube_forecast[$lid])) $lube_forecast[$lid] = 0;
                    $lube_forecast[$lid] += $spec->recommended_qty;
                }
            }
            $result[$month] = $lube_forecast;
        }
        return $result;
    }

    /* ─── PM-DRIVEN LUBE REQUIREMENT (date + hours based) ──────────────
       Returns, per lube_type_id, the qty + service-count that the PM
       schedule says SHOULD be consumed in [$from, $to], based on:
       - date-based PM: next_due_date falls within the window
       - hours-based PM: hrs remaining ÷ avg daily hours projects a
         due-date that falls within the window
    ──────────────────────────────────────────────────────────────────── */
    public function get_pm_driven_requirement($from, $to, $avg_daily_hrs = 20) {
        $schedules = $this->db->select('ps.*, e.equipment_type_id, e.current_hours')
            ->from('pm_schedules ps')
            ->join('equipment e', 'e.id = ps.equipment_id')
            ->where('ps.status', 'Active')
            ->get()->result();

        $specs = $this->db->get('lube_specifications')->result();
        $specsByType = array();
        foreach ($specs as $s) {
            if (!isset($specsByType[$s->equipment_type_id])) $specsByType[$s->equipment_type_id] = array();
            $specsByType[$s->equipment_type_id][$s->lube_type_id] = $s;
        }

        $today = date('Y-m-d');
        $requirement = array();

        foreach ($schedules as $ps) {
            $due_in_period = false;

            // 1) Date-based check
            if (!empty($ps->next_due_date) && $ps->next_due_date >= $from && $ps->next_due_date <= $to) {
                $due_in_period = true;
            }

            // 2) Hours-based check (projects a due date from remaining hours)
            if (!$due_in_period && $ps->interval_type === 'Hours'
                && $ps->next_due_hours !== null && $ps->current_hours !== null) {
                $hrs_remaining = (float)$ps->next_due_hours - (float)$ps->current_hours;
                if ($hrs_remaining < 0) $hrs_remaining = 0;
                if ($avg_daily_hrs > 0) {
                    $days_until      = (int)ceil($hrs_remaining / $avg_daily_hrs);
                    $projected_date  = date('Y-m-d', strtotime($today." +{$days_until} days"));
                    if ($projected_date >= $from && $projected_date <= $to) {
                        $due_in_period = true;
                    }
                }
            }

            if (!$due_in_period) continue;
            if (!isset($specsByType[$ps->equipment_type_id])) continue;

            foreach ($specsByType[$ps->equipment_type_id] as $lid => $spec) {
                if (!isset($requirement[$lid])) {
                    $requirement[$lid] = array('lube_type_id' => $lid, 'qty' => 0.0, 'services' => 0);
                }
                $requirement[$lid]['qty']      += (float)$spec->recommended_qty;
                $requirement[$lid]['services']++;
            }
        }

        return $requirement;
    }

    /* ─── COMPLIANCE REPORT: required (PM-driven) vs actual consumption ── */
    public function get_compliance_report($from, $to, $avg_daily_hrs = 20) {
        $required = $this->get_pm_driven_requirement($from, $to, $avg_daily_hrs);

        $actual = $this->db->select('lc.lube_type_id, SUM(lc.quantity_used) as total_used, COUNT(*) as records')
            ->from('lube_consumption lc')
            ->where('lc.consumption_date >=', $from)
            ->where('lc.consumption_date <=', $to)
            ->group_by('lc.lube_type_id')
            ->get()->result();

        $types = $this->db->order_by('category')->get('lube_types')->result();
        $byId = array();
        foreach ($types as $t) {
            $byId[$t->id] = array(
                'lube_type_id'      => $t->id,
                'lube_name'         => $t->name,
                'category'          => $t->category,
                'unit'              => $t->unit,
                'required_qty'      => 0.0,
                'required_services' => 0,
                'actual_qty'        => 0.0,
                'actual_records'    => 0,
            );
        }
        foreach ($required as $lid => $r) {
            if (isset($byId[$lid])) {
                $byId[$lid]['required_qty']      = $r['qty'];
                $byId[$lid]['required_services'] = $r['services'];
            }
        }
        foreach ($actual as $a) {
            if (isset($byId[$a->lube_type_id])) {
                $byId[$a->lube_type_id]['actual_qty']     = (float)$a->total_used;
                $byId[$a->lube_type_id]['actual_records'] = (int)$a->records;
            }
        }

        $rows = array();
        foreach ($byId as $row) {
            if ($row['required_qty'] <= 0 && $row['actual_qty'] <= 0) continue; // not relevant this window
            $row['variance']     = round($row['actual_qty'] - $row['required_qty'], 2);
            $row['variance_pct'] = $row['required_qty'] > 0
                ? round($row['variance'] / $row['required_qty'] * 100, 1)
                : null;
            $rows[] = $row;
        }
        return $rows;
    }

    /* ─── YEARLY ROLLUP: 12 months of compliance + annual totals ───────── */
    public function get_yearly_compliance($year, $avg_daily_hrs = 20) {
        $months = array();
        $totals = array();

        for ($m = 1; $m <= 12; $m++) {
            $month_str = sprintf('%04d-%02d', $year, $m);
            $from = $month_str.'-01';
            $to   = date('Y-m-t', strtotime($from));

            $rows = $this->get_compliance_report($from, $to, $avg_daily_hrs);
            $months[$month_str] = $rows;

            foreach ($rows as $r) {
                $lid = $r['lube_type_id'];
                if (!isset($totals[$lid])) {
                    $totals[$lid] = array(
                        'lube_type_id'      => $r['lube_type_id'],
                        'lube_name'         => $r['lube_name'],
                        'category'          => $r['category'],
                        'unit'              => $r['unit'],
                        'required_qty'      => 0.0,
                        'required_services' => 0,
                        'actual_qty'        => 0.0,
                        'actual_records'    => 0,
                    );
                }
                $totals[$lid]['required_qty']      += $r['required_qty'];
                $totals[$lid]['required_services'] += $r['required_services'];
                $totals[$lid]['actual_qty']        += $r['actual_qty'];
                $totals[$lid]['actual_records']    += $r['actual_records'];
            }
        }

        $totalsOut = array();
        foreach ($totals as $t) {
            $t['variance']     = round($t['actual_qty'] - $t['required_qty'], 2);
            $t['variance_pct'] = $t['required_qty'] > 0
                ? round($t['variance'] / $t['required_qty'] * 100, 1)
                : null;
            $totalsOut[] = $t;
        }

        return array('year' => $year, 'months' => $months, 'totals' => $totalsOut);
    }
}
