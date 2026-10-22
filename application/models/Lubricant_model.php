<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Lubricant_model extends CI_Model {

    public static $CATEGORIES = array(
        'Engine Oil',
        'Hydraulic Oil',
        'Transmission Oil',
        'Differential Oil',
        'Grease',
        'Coolant',
        'Compressor Oil',
        'Filter',
        'Other',
    );

    /** Normalize category strictly to valid `lube_types.category` ENUM values. */
    public static function clean_category($val) {
        $s = trim((string)$val);
        return in_array($s, self::$CATEGORIES, TRUE) ? $s : 'Other';
    }

    /** Validate YYYY-MM month string; fall back to current month on malformed input. */
    public static function clean_month($raw) {
        $s = trim((string)$raw);
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $s, $m)) {
            $y = (int)$m[1];
            $mo = (int)$m[2];
            if ($y >= 2000 && $y <= 2100 && checkdate($mo, 1, $y)) {
                return sprintf('%04d-%02d', $y, $mo);
            }
        }
        return date('Y-m');
    }

    public function get_all_types() {
        return $this->db->where('status', 'Active')->order_by('category')->get('lube_types')->result();
    }

    public function get_type_by_id($id) {
        return $this->db->where('id', (int)$id)->get('lube_types')->row();
    }

    public function get_all_specs() {
        return $this->db->select('ls.*, et.name as type_name, lt.name as lube_name, lt.grade as lube_grade, lt.unit')
            ->from('lube_specifications ls')
            ->join('equipment_types et', 'et.id = ls.equipment_type_id')
            ->join('lube_types lt', 'lt.id = ls.lube_type_id')
            ->order_by('et.name')->get()->result();
    }

    public function get_spec($equipment_type_id, $lube_type_id) {
        return $this->db->where('equipment_type_id', (int)$equipment_type_id)
            ->where('lube_type_id', (int)$lube_type_id)->get('lube_specifications')->row();
    }

    public function get_consumption_by_id($id) {
        $row = $this->db->select('lc.*, lt.name as lube_name, lt.grade as lube_grade, lt.unit, lt.category, lt.unit_cost,
                                  e.registration_no, e.brand, e.model, et.name as type_name,
                                  wo.wo_number, wo.title as wo_title, wo.status as wo_status,
                                  CONCAT(u.first_name," ",u.last_name) as recorded_by_name')
            ->from('lube_consumption lc')
            ->join('lube_types lt', 'lt.id = lc.lube_type_id')
            ->join('equipment e', 'e.id = lc.equipment_id')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->join('work_orders wo', 'wo.id = lc.work_order_id', 'left')
            ->join('users u', 'u.id = lc.recorded_by', 'left')
            ->where('lc.id', (int)$id)
            ->get()
            ->row();
        if ($row) {
            $row->line_cost = round((float)$row->quantity_used * (float)($row->unit_cost ?? 0), 2);
        }
        return $row;
    }

    /**
     * Single-pass O(1) SQL Ledger Overview (<= 5 flat SQL queries regardless of
     * fleet/log size) hydrating the entire `/lubricants` board, KPIs, monthly
     * summary cards, budget burn, and PM compliance matrix.
     */
    public function get_ledger_overview($monthRaw) {
        $month = self::clean_month($monthRaw);
        $parts = explode('-', $month);
        $year  = (int)$parts[0];
        $mNum  = (int)$parts[1];
        $from  = $month . '-01';
        $to    = date('Y-m-t', strtotime($from));

        $rows = $this->db->select('lc.*, lt.name as lube_name, lt.grade as lube_grade, lt.unit, lt.category, lt.unit_cost,
                                   e.registration_no, e.brand, e.model, et.name as type_name,
                                   wo.wo_number, wo.title as wo_title,
                                   CONCAT(u.first_name," ",u.last_name) as recorded_by_name')
            ->from('lube_consumption lc')
            ->join('lube_types lt', 'lt.id = lc.lube_type_id')
            ->join('equipment e', 'e.id = lc.equipment_id')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->join('work_orders wo', 'wo.id = lc.work_order_id', 'left')
            ->join('users u', 'u.id = lc.recorded_by', 'left')
            ->where('lc.consumption_date >=', $from)
            ->where('lc.consumption_date <=', $to)
            ->order_by('lc.consumption_date', 'DESC')
            ->order_by('lc.id', 'DESC')
            ->get()
            ->result();

        $lubeTypes  = $this->get_all_types();
        $budgetRows = $this->db->where('year', $year)->where('month', $mNum)->get('lube_budget')->result();
        $pmReq      = $this->get_pm_driven_requirement($from, $to);

        $budgetByType = array();
        $totalBudgetCost = 0.0;
        foreach ($budgetRows as $b) {
            $lid = (int)$b->lube_type_id;
            $budgetByType[$lid] = $b;
            $totalBudgetCost += (float)($b->budget_cost ?? 0);
        }

        $actualByType = array();
        $totalActualCost = 0.0;
        $totalQtyUsed    = 0.0;
        $overSpecCnt     = 0;
        $linkedWoCnt     = 0;
        $machinesMap     = array();

        foreach ($rows as $r) {
            $lid  = (int)$r->lube_type_id;
            $qty  = (float)$r->quantity_used;
            $uCost = isset($budgetByType[$lid]) && (float)$budgetByType[$lid]->unit_cost > 0
                ? (float)$budgetByType[$lid]->unit_cost
                : (float)($r->unit_cost ?? 0);
            $r->effective_unit_cost = $uCost;
            $r->line_cost           = round($qty * $uCost, 2);

            $totalActualCost += $r->line_cost;
            $totalQtyUsed    += $qty;
            if ($r->variance !== NULL && (float)$r->variance > 0.0001) $overSpecCnt++;
            if (!empty($r->work_order_id)) $linkedWoCnt++;
            if (!empty($r->registration_no)) $machinesMap[(string)$r->registration_no] = TRUE;

            if (!isset($actualByType[$lid])) {
                $actualByType[$lid] = (object)array(
                    'lube_type_id' => $lid,
                    'lube_name'    => (string)$r->lube_name,
                    'lube_grade'   => (string)($r->lube_grade ?? ''),
                    'category'     => (string)$r->category,
                    'unit'         => (string)$r->unit,
                    'total_used'   => 0.0,
                    'total_rec'    => 0.0,
                    'total_cost'   => 0.0,
                    'records'      => 0,
                );
            }
            $actualByType[$lid]->total_used += $qty;
            $actualByType[$lid]->total_rec  += (float)($r->recommended_qty ?? 0);
            $actualByType[$lid]->total_cost += $r->line_cost;
            $actualByType[$lid]->records++;
        }

        foreach ($actualByType as $s) {
            $s->avg_used = $s->records > 0 ? round($s->total_used / $s->records, 2) : 0.0;
        }

        // Build PM Compliance rows identically to get_compliance_report() without extra DB queries
        $compliance = array();
        foreach ($lubeTypes as $t) {
            $lid    = (int)$t->id;
            $reqQty = isset($pmReq[$lid]) ? (float)$pmReq[$lid]['qty'] : 0.0;
            $reqSvc = isset($pmReq[$lid]) ? (int)$pmReq[$lid]['services'] : 0;
            $actQty = isset($actualByType[$lid]) ? (float)$actualByType[$lid]->total_used : 0.0;
            $actRec = isset($actualByType[$lid]) ? (int)$actualByType[$lid]->records : 0;
            $bgtQty = isset($budgetByType[$lid]) ? (float)($budgetByType[$lid]->budget_qty ?? 0) : 0.0;

            if ($reqQty <= 0 && $actQty <= 0 && $bgtQty <= 0) continue;

            $var = round($actQty - $reqQty, 2);
            $compliance[] = array(
                'lube_type_id'      => $lid,
                'lube_name'         => (string)$t->name,
                'grade'             => (string)($t->grade ?? ''),
                'category'          => (string)$t->category,
                'unit'              => (string)$t->unit,
                'required_qty'      => $reqQty,
                'required_services' => $reqSvc,
                'budget_qty'        => $bgtQty,
                'actual_qty'        => $actQty,
                'actual_records'    => $actRec,
                'variance'          => $var,
                'variance_pct'      => $reqQty > 0 ? round(($var / $reqQty) * 100, 1) : NULL,
            );
        }

        return array(
            'month'      => $month,
            'year'       => $year,
            'month_num'  => $mNum,
            'rows'       => $rows,
            'lube_types' => $lubeTypes,
            'summary'    => array_values($actualByType),
            'compliance' => $compliance,
            'kpi'        => array(
                'records'         => count($rows),
                'total_qty'       => round($totalQtyUsed, 1),
                'actual_cost'     => round($totalActualCost, 2),
                'budget_cost'     => round($totalBudgetCost, 2),
                'over_spec_count' => $overSpecCnt,
                'linked_wo_count' => $linkedWoCnt,
                'machines_count'  => count($machinesMap),
            ),
        );
    }

    /**
     * Single-pass O(1) SQL Budget Overview (<= 5 flat SQL queries) hydrating
     * `/lubricants/budget` with Planned Budget vs PM Requirement vs Actual MTD Spend.
     */
    public function get_budget_overview($year, $month) {
        $year  = max(2000, min(2100, (int)$year));
        $month = max(1, min(12, (int)$month));
        $ym    = sprintf('%04d-%02d', $year, $month);
        $from  = $ym . '-01';
        $to    = date('Y-m-t', strtotime($from));

        $lubeTypes  = $this->get_all_types();
        $budgetData = $this->get_budget($year, $month);
        $pmReq      = $this->get_pm_driven_requirement($from, $to);

        $actualRows = $this->db->select('lc.lube_type_id, SUM(lc.quantity_used) as actual_qty, COUNT(*) as records')
            ->from('lube_consumption lc')
            ->where('lc.consumption_date >=', $from)
            ->where('lc.consumption_date <=', $to)
            ->group_by('lc.lube_type_id')
            ->get()
            ->result();
        $actualMap = array();
        foreach ($actualRows as $ar) {
            $actualMap[(int)$ar->lube_type_id] = $ar;
        }

        $lines = array();
        $totals = array(
            'budget_qty'  => 0.0,
            'budget_cost' => 0.0,
            'pm_req_qty'  => 0.0,
            'actual_qty'  => 0.0,
            'actual_cost' => 0.0,
        );

        foreach ($lubeTypes as $lt) {
            $lid   = (int)$lt->id;
            $b     = isset($budgetData[$lid]) ? $budgetData[$lid] : NULL;
            $bQty  = $b ? (float)$b->budget_qty : 0.0;
            $bCost = ($b && (float)$b->unit_cost > 0) ? (float)$b->unit_cost : (float)($lt->unit_cost ?? 0);
            $bTot  = $b ? (float)$b->budget_cost : 0.0;

            $reqQty = isset($pmReq[$lid]) ? (float)$pmReq[$lid]['qty'] : 0.0;
            $reqSvc = isset($pmReq[$lid]) ? (int)$pmReq[$lid]['services'] : 0;

            $actQty  = isset($actualMap[$lid]) ? (float)$actualMap[$lid]->actual_qty : 0.0;
            $actRecs = isset($actualMap[$lid]) ? (int)$actualMap[$lid]->records : 0;
            $actCost = round($actQty * $bCost, 2);

            $totals['budget_qty']  += $bQty;
            $totals['budget_cost'] += $bTot;
            $totals['pm_req_qty']  += $reqQty;
            $totals['actual_qty']  += $actQty;
            $totals['actual_cost'] += $actCost;

            $lines[] = (object)array(
                'id'              => $lid,
                'name'            => (string)$lt->name,
                'grade'           => (string)($lt->grade ?? ''),
                'category'        => (string)$lt->category,
                'unit'            => (string)$lt->unit,
                'default_cost'    => (float)($lt->unit_cost ?? 0),
                'has_saved_row'   => ($b !== NULL),
                'budget_qty'      => $b ? (float)$b->budget_qty : NULL,
                'unit_cost'       => $b ? (float)$b->unit_cost : (float)($lt->unit_cost ?? 0),
                'budget_cost'     => $bTot,
                'pm_req_qty'      => $reqQty,
                'pm_services'     => $reqSvc,
                'actual_qty'      => $actQty,
                'actual_records'  => $actRecs,
                'actual_cost'     => $actCost,
                'remaining_cost'  => round($bTot - $actCost, 2),
                'utilization_pct' => $bTot > 0 ? round(($actCost / $bTot) * 100, 1) : NULL,
            );
        }

        $totals['remaining_cost']  = round($totals['budget_cost'] - $totals['actual_cost'], 2);
        $totals['utilization_pct'] = $totals['budget_cost'] > 0
            ? round(($totals['actual_cost'] / $totals['budget_cost']) * 100, 1)
            : NULL;

        return array(
            'year'        => $year,
            'month'       => $month,
            'lube_types'  => $lubeTypes,
            'budget_data' => $budgetData,
            'lines'       => $lines,
            'totals'      => $totals,
        );
    }

    public function get_consumption($filter = array()) {
        $this->db->select('lc.*, lt.name as lube_name, lt.grade as lube_grade, lt.unit, lt.category, lt.unit_cost,
                           e.registration_no, wo.wo_number,
                           CONCAT(u.first_name," ",u.last_name) as recorded_by_name')
            ->from('lube_consumption lc')
            ->join('lube_types lt', 'lt.id = lc.lube_type_id')
            ->join('equipment e', 'e.id = lc.equipment_id')
            ->join('work_orders wo', 'wo.id = lc.work_order_id', 'left')
            ->join('users u', 'u.id = lc.recorded_by', 'left');

        if (!empty($filter['month'])) {
            $cleanMonth = self::clean_month($filter['month']);
            $from = $cleanMonth . '-01';
            $to   = date('Y-m-t', strtotime($from));
            $this->db->where('lc.consumption_date >=', $from)->where('lc.consumption_date <=', $to);
        }
        if (!empty($filter['equipment_id'])) $this->db->where('lc.equipment_id', (int)$filter['equipment_id']);
        if (!empty($filter['lube_type_id'])) $this->db->where('lc.lube_type_id', (int)$filter['lube_type_id']);

        return $this->db->order_by('lc.consumption_date', 'DESC')->get()->result();
    }

    public function get_by_equipment($equipment_id) {
        return $this->db->select('lc.*, lt.name as lube_name, lt.unit')->from('lube_consumption lc')
            ->join('lube_types lt', 'lt.id = lc.lube_type_id')
            ->where('lc.equipment_id', (int)$equipment_id)->order_by('lc.consumption_date', 'DESC')->get()->result();
    }

    public function get_monthly_summary($month) {
        $cleanMonth = self::clean_month($month);
        $from = $cleanMonth . '-01';
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
            ->where('lb.year', (int)$year)->where('lb.month', (int)$month)->get()->result();
        $indexed = array();
        foreach ($records as $r) { $indexed[$r->lube_type_id] = $r; }
        return $indexed;
    }

    /**
     * Batch O(1) SQL 2-Month PM Lubricant Forecast (<= 2 SQL queries total;
     * eliminates legacy 2 + 2N per-schedule query explosion).
     */
    public function get_monthly_forecast() {
        $m0 = date('Y-m');
        $m1 = date('Y-m', strtotime('+1 month'));
        $globalFrom = $m0 . '-01';
        $globalTo   = date('Y-m-t', strtotime($m1 . '-01'));

        $schedules = $this->db->select('ps.next_due_date, e.equipment_type_id')
            ->from('pm_schedules ps')
            ->join('equipment e', 'e.id = ps.equipment_id')
            ->where('ps.status', 'Active')
            ->where('ps.next_due_date >=', $globalFrom)
            ->where('ps.next_due_date <=', $globalTo)
            ->get()
            ->result();

        $specs = $this->db->get('lube_specifications')->result();
        $specsByEqType = array();
        foreach ($specs as $sp) {
            $et = (int)$sp->equipment_type_id;
            if (!isset($specsByEqType[$et])) $specsByEqType[$et] = array();
            $specsByEqType[$et][] = $sp;
        }

        $result = array($m0 => array(), $m1 => array());
        foreach ($schedules as $pm) {
            $ym = substr((string)$pm->next_due_date, 0, 7);
            if (!isset($result[$ym])) continue;
            $et = (int)$pm->equipment_type_id;
            if (empty($specsByEqType[$et])) continue;
            foreach ($specsByEqType[$et] as $spec) {
                $lid = (int)$spec->lube_type_id;
                if (!isset($result[$ym][$lid])) $result[$ym][$lid] = 0.0;
                $result[$ym][$lid] += (float)$spec->recommended_qty;
            }
        }
        return $result;
    }

    /* ─── PM-DRIVEN LUBE REQUIREMENT (date + hours based) ────────────── */
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

            if (!empty($ps->next_due_date) && $ps->next_due_date >= $from && $ps->next_due_date <= $to) {
                $due_in_period = true;
            }

            if (!$due_in_period && $ps->interval_type === 'Hours'
                && $ps->next_due_hours !== null && $ps->current_hours !== null) {
                $hrs_remaining = (float)$ps->next_due_hours - (float)$ps->current_hours;
                if ($hrs_remaining < 0) $hrs_remaining = 0;
                if ($avg_daily_hrs > 0) {
                    $days_until     = (int)ceil($hrs_remaining / $avg_daily_hrs);
                    $projected_date = date('Y-m-d', strtotime($today . " +{$days_until} days"));
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
                $requirement[$lid]['qty'] += (float)$spec->recommended_qty;
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
            if ($row['required_qty'] <= 0 && $row['actual_qty'] <= 0) continue;
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
        $year   = (int)$year;
        $months = array();
        $totals = array();

        for ($m = 1; $m <= 12; $m++) {
            $month_str = sprintf('%04d-%02d', $year, $m);
            $from = $month_str . '-01';
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
