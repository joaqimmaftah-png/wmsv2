<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Dashboard extends MY_Controller {

    public function index() {
        $this->load->model('Dashboard_model');

        if ($this->is_ceo()) {
            $this->_ceo_dashboard();
            return;
        }

        // --- KPIs ---
        $data['kpi'] = [
            'open_wo'       => $this->Dashboard_model->count_open_wo(),
            'availability'  => $this->Dashboard_model->get_availability_pct(),
            'avail_target'  => $this->Dashboard_model->availability_target_pct(),
            'manpower'      => $this->Dashboard_model->get_manpower_today(),
            'total_workers' => $this->db->where('status','Active')->count_all_results('users'),
            'overdue_pm'    => $this->Dashboard_model->count_overdue_pm(),
            'mttr'          => $this->Dashboard_model->get_mttr_30d(),
            'mtbf'          => $this->Dashboard_model->get_mtbf_30d(),
            'lube_month'    => $this->Dashboard_model->get_lube_month_total(),
            'breakdowns'    => $this->Dashboard_model->count_breakdowns_30d(),
            'total_downtime'=> $this->Dashboard_model->get_total_downtime_30d(),
        ];

        // Availability provenance — the window it was computed over and how many
        // machines actually reported, so the card can show "14/16 machines"
        // instead of a bare percentage that hides a partial dataset.
        $data['availability_window'] = $this->Dashboard_model->get_availability_window();

        // --- Charts ---
        $data['avail_target']       = $this->Dashboard_model->availability_target_pct();
        $data['availability_trend'] = $this->Dashboard_model->get_availability_trend();
        $data['attendance_trend']   = $this->Dashboard_model->get_attendance_trend();
        $data['wo_monthly']         = $this->Dashboard_model->get_wo_monthly_12();
        $data['lube_summary']       = $this->Dashboard_model->get_lube_by_equipment_type();

        // --- WO by type for donut ---
        $wo_raw = $this->db
            ->select('type, is_breakdown, COUNT(*) as cnt')
            ->where('MONTH(created_at)', date('m'))
            ->where('YEAR(created_at)', date('Y'))
            ->group_by(['type', 'is_breakdown'])
            ->get('work_orders')->result();

        // W0.1: bucket by is_breakdown / real enum values so the donut's
        // 'Breakdown' slice is no longer hard-wired to zero.
        $data['wo_by_type'] = wo_buckets();
        $data['wo_total']   = 0;
        foreach ($wo_raw as $r) {
            $data['wo_by_type'][wo_bucket($r)] += (int)$r->cnt;
            $data['wo_total'] += (int)$r->cnt;
        }

        // --- Fleet status ---
        $data['equipment'] = $this->db
            ->select('id,registration_no,brand,model,status,current_hours,current_km')
            ->where('status !=', 'Retired')
            ->get('equipment')->result();

        // One status definition (application/helpers/equip_helper.php): roll the
        // fleet into the four machine states and count what is down right now
        // (Breakdown or In Maintenance). Same helper the equipment screens use,
        // so the dashboard never invents a second status taxonomy.
        $fleet_status  = array('Available' => 0, 'In Maintenance' => 0, 'Breakdown' => 0, 'Standby' => 0);
        $machines_down = 0;
        foreach ($data['equipment'] as $eq) {
            if (isset($fleet_status[$eq->status])) $fleet_status[$eq->status]++;
            if (equip_status_group($eq) === 'Down') $machines_down++;
        }
        $data['fleet_status']  = $fleet_status;
        $data['machines_down'] = $machines_down;
        $data['fleet_total']   = count($data['equipment']);

        // --- Fleet KPI by type (for dashboard strip + charts) ---
        $data['fleet_kpi_by_type'] = $this->Dashboard_model->get_fleet_kpi_by_type();

        // --- Recent WOs ---
        // `assigned_to` is a CSV of user IDs, so joining `users` on it only ever
        // matched the first technician (MySQL coerces '3,5' to 3) and could
        // print the wrong name. The model resolves the whole list after the
        // query; the card itself shows machine + status, not the assignee.
        $this->load->model('Workorder_model');
        $data['recent_wo'] = $this->Workorder_model->get_recent(5);

        // --- Due PM ---
        $data['due_pm'] = $this->db
            ->select('p.*,e.registration_no')
            ->from('pm_schedules p')
            ->join('equipment e','e.id=p.equipment_id','left')
            ->where('p.next_due_date <=', date('Y-m-d', strtotime('+14 days')))
            ->where('p.status', 'Active')
            ->order_by('p.next_due_date','ASC')
            ->limit(8)
            ->get()->result();

        // --- Overdue PM list ---
        $data['overdue_pm_list'] = $this->db
            ->select('p.*,e.registration_no')
            ->from('pm_schedules p')
            ->join('equipment e','e.id=p.equipment_id','left')
            ->where('p.next_due_date <', date('Y-m-d'))
            ->where('p.status', 'Active')
            ->get()->result();

        // --- Absent today ---
        $data['absent_today'] = $this->db
            ->select('u.first_name,u.last_name,CONCAT(u.first_name," ",u.last_name) AS full_name')
            ->from('attendance a')
            ->join('users u','u.id=a.user_id')
            ->where('a.date', date('Y-m-d'))
            ->where('a.status', 'Absent')
            ->get()->result();

        // --- Incidents (safety exceptions surfaced on the operational board) ---
        // Count only; the CEO view carries the record lists.
        $data['incidents_month']    = $this->db->where('type','Incident')
            ->where('MONTH(created_at)', date('m'))->where('YEAR(created_at)', date('Y'))
            ->count_all_results('work_orders');
        $data['critical_incidents'] = $this->db->select('id')->from('incident_details')
            ->where('activity_status','Uncontrolled')
            ->where('MONTH(created_at)', date('m'))->where('YEAR(created_at)', date('Y'))
            ->count_all_results();

        // --- Pending Spare Parts Tracking & Availability Alerts ---
        // Answers: "which pending jobs can now proceed because their parts arrived?"
        // (no-op until the spare-parts tracking tables exist)
        $this->load->model('Spareparts_model');
        $spare_lines = $this->Spareparts_model->get_pending_lines();
        $spare_rank  = array('Pending' => 0, 'Unlinked' => 1, 'Partial' => 2, 'Available' => 3, 'Allocated' => 4);
        usort($spare_lines, function ($a, $b) use ($spare_rank) {
            $ra = isset($spare_rank[$a->avail_status]) ? $spare_rank[$a->avail_status] : 9;
            $rb = isset($spare_rank[$b->avail_status]) ? $spare_rank[$b->avail_status] : 9;
            return $ra === $rb ? 0 : ($ra < $rb ? -1 : 1);
        });
        $data['spares_summary'] = $this->Spareparts_model->get_board_summary();
        $data['spares_lines']   = array_slice($spare_lines, 0, 8);
        $data['spares_ready']   = $this->Spareparts_model->get_ready_work_orders();

        $data['page_title'] = 'Dashboard';
        $this->render('dashboard/index', $data);
    }

    /* ─────────────────────────────────────────────────────
       CEO EXECUTIVE SUMMARY — high-level KPIs only.
       No editable records, no operational drill-down lists —
       intentionally a different, simpler view, not a cut-down
       version of the operational dashboard.
    ───────────────────────────────────────────────────── */
    private function _ceo_dashboard() {
        $this->load->model(['Order_model', 'Lubricant_model', 'Materials_model']);

        $year  = date('Y');
        $month = date('Y-m');

        // ── Spend & Orders ──
        $spend = $this->Order_model->get_spend_by_supplier($year.'-01-01', $year.'-12-31');
        // K-11: orders carry their own currency, so a single grand total would
        // add TZS, UGX and USD together. Keep one total per currency.
        $spend_by_currency = array();
        foreach ($spend as $s) {
            $cur = wms_currency_code($s->currency);
            $spend_by_currency[$cur] = (isset($spend_by_currency[$cur]) ? $spend_by_currency[$cur] : 0)
                + (float)$s->total_spend;
        }
        arsort($spend_by_currency);
        $order_kpi = $this->Order_model->get_kpi();

        // ── Incidents ──
        $incident_count = $this->db
            ->where('type', 'Incident')
            ->where('MONTH(created_at)', date('m'))
            ->where('YEAR(created_at)', date('Y'))
            ->count_all_results('work_orders');
        $critical_incidents = $this->db
            ->select('id')->from('incident_details')
            ->where('activity_status', 'Uncontrolled')
            ->where('MONTH(created_at)', date('m'))
            ->where('YEAR(created_at)', date('Y'))
            ->count_all_results();
        $recent_incidents = $this->db
            ->select('w.id, w.wo_number, w.created_at, e.registration_no, i.activity_status, i.review_status')
            ->from('work_orders w')
            ->join('equipment e', 'e.id = w.equipment_id', 'left')
            ->join('incident_details i', 'i.wo_id = w.id', 'left')
            ->where('w.type', 'Incident')
            ->order_by('w.created_at', 'DESC')->limit(6)->get()->result();

        // ── Equipment status breakdown ──
        $eq_status_raw = $this->db->select('status, COUNT(*) as cnt')
            ->where('status !=', 'Retired')->group_by('status')->get('equipment')->result();
        $equipment_status = array();
        foreach ($eq_status_raw as $r) $equipment_status[$r->status] = (int)$r->cnt;

        // ── Active Work Orders ──
        $active_wo = $this->db
            ->select('w.id, w.wo_number, w.type, w.status, w.created_at, e.registration_no')
            ->from('work_orders w')
            ->join('equipment e', 'e.id = w.equipment_id', 'left')
            ->where_not_in('w.status', array('Completed','Closed','Cancelled'))
            ->order_by('w.created_at', 'DESC')->limit(8)->get()->result();

        // ── PM / Service Alerts ──
        $overdue_pm_list = $this->Dashboard_model->get_due_pm_list(); // upcoming/overdue combined per existing method
        $overdue_pm_count = $this->Dashboard_model->count_overdue_pm();

        // ── Store Inventory ──
        $mat_kpi       = $this->Materials_model->get_kpi();
        $low_stock     = $this->Materials_model->get_low_stock(6);

        // ── Lubricants: consumption vs budget "remaining" this month ──
        $lube_summary  = $this->Lubricant_model->get_monthly_summary($month);
        $lube_budget   = $this->Lubricant_model->get_budget($year, (int)date('m'));
        $lube_rows     = array();
        foreach ($lube_summary as $l) {
            $budget = isset($lube_budget[$l->lube_type_id]) ? (float)$lube_budget[$l->lube_type_id]->budget_qty : null;
            $lube_rows[] = array(
                'name'      => $l->lube_name,
                'category'  => $l->category,
                'unit'      => $l->unit,
                'used'      => (float)$l->total_used,
                'budget'    => $budget,
                'remaining' => $budget !== null ? round($budget - $l->total_used, 1) : null,
            );
        }

        // ── Attendance ──
        $manpower     = $this->Dashboard_model->get_manpower_today();
        $absent_today = $this->Dashboard_model->get_absent_today();

        // ── Spare orders needing attention ──
        $orders_due_soon = $this->Order_model->get_due_soon(5);
        $orders_overdue  = $this->Order_model->get_overdue(5);

        $data = array(
            'kpi' => array(
                'availability'    => $this->Dashboard_model->get_availability_pct(),
                'avail_target'    => $this->Dashboard_model->availability_target_pct(),
                'open_wo'         => $this->Dashboard_model->count_open_wo(),
                'overdue_pm'      => $overdue_pm_count,
                'mttr'            => $this->Dashboard_model->get_mttr_30d(),
                'mtbf'            => $this->Dashboard_model->get_mtbf_30d(),
                'manpower'        => $manpower,
                'breakdowns_30d'  => $this->Dashboard_model->count_breakdowns_30d(),
                'spend_ytd'       => $spend_by_currency,
                'order_kpi'       => $order_kpi,
                'incidents_month' => $incident_count,
                'critical_incidents_month' => $critical_incidents,
            ),
            'availability_trend' => $this->Dashboard_model->get_availability_trend(90),
            'fleet_kpi_by_type'  => $this->Dashboard_model->get_fleet_kpi_by_type(),
            'equipment_status'   => $equipment_status,
            'active_wo'          => $active_wo,
            'overdue_pm_list'    => $overdue_pm_list,
            'mat_kpi'            => $mat_kpi,
            'low_stock'          => $low_stock,
            'lube_rows'          => $lube_rows,
            'absent_today'       => $absent_today,
            'recent_incidents'   => $recent_incidents,
            'orders_due_soon'    => $orders_due_soon,
            'orders_overdue'     => $orders_overdue,
            'page_title'         => 'Executive Summary',
        );

        $this->render('dashboard/ceo', $data);
    }
}
