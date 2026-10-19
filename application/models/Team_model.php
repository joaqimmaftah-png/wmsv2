<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Team_model extends CI_Model {

    public function get_all() {
        return $this->db->select('u.*, r.name as role_name')->from('users u')
            ->join('roles r', 'r.id = u.role_id')->order_by('u.first_name')->get()->result();
    }

    public function get_all_active() {
        return $this->db->select('u.*, r.name as role_name')->from('users u')
            ->join('roles r', 'r.id = u.role_id')->where('u.status', 'Active')
            ->order_by('u.first_name')->get()->result();
    }

    public function get_technicians() {
        return $this->db->select('u.*')->from('users u')
            ->where_in('u.role_id', [1, 2])->where('u.status', 'Active')
            ->order_by('u.first_name')->get()->result();
    }

    public function get_by_id($id) {
        return $this->db->select('u.*, r.name as role_name')->from('users u')
            ->join('roles r', 'r.id = u.role_id')->where('u.id', $id)->get()->row();
    }

    public function create($data) {
        $this->db->insert('users', $data);
        return $this->db->insert_id();
    }

    public function get_attendance_by_date($date) {
        $records = $this->db->where('date', $date)->get('attendance')->result_array();
        $indexed = [];
        foreach ($records as $r) { $indexed[$r['user_id']] = $r; }
        return $indexed;
    }

    public function get_attendance_history($user_id, $days = 30) {
        return $this->db->where('user_id', $user_id)
            ->where('date >=', date('Y-m-d', strtotime("-{$days} days")))
            ->order_by('date', 'DESC')->get('attendance')->result();
    }

    public function get_attendance_range($from, $to) {
        return $this->db->select('a.*, CONCAT(u.first_name," ",u.last_name) as full_name')
            ->from('attendance a')->join('users u', 'u.id = a.user_id')
            ->where('a.date >=', $from)->where('a.date <=', $to)
            ->order_by('a.date', 'ASC')->get()->result();
    }

    public function get_monthly_report($month) {
        list($year, $mon) = explode('-', $month);
        $from = "{$month}-01";
        $to   = date('Y-m-t', strtotime($from));
        $days = date('t', strtotime($from));

        $workers = $this->get_all_active();
        $result  = [];

        foreach ($workers as $w) {
            $counts = $this->db->select('status, COUNT(*) as cnt')
                ->where('user_id', $w->id)->where('date >=', $from)->where('date <=', $to)
                ->group_by('status')->get('attendance')->result_array();

            $stats = ['Present' => 0, 'Absent' => 0, 'Leave' => 0, 'Rest' => 0, 'Holiday' => 0];
            foreach ($counts as $c) { $stats[$c['status']] = $c['cnt']; }

            $total = $days;
            $pct   = $total > 0 ? round($stats['Present'] / $total * 100, 0) : 0;
            $result[] = (object)[
                'employee_id' => $w->employee_id,
                'full_name'   => $w->first_name . ' ' . $w->last_name,
                'position'    => $w->position,
                'present'     => $stats['Present'],
                'absent'      => $stats['Absent'],
                'leave'       => $stats['Leave'],
                'rest'        => $stats['Rest'],
                'total'       => $total,
                'pct'         => $pct,
            ];
        }
        return $result;
    }

    public function get_certifications($user_id) {
        return $this->db->where('user_id', $user_id)->order_by('expiry_date', 'ASC')->get('certifications')->result();
    }

    public function get_expiring_certs($days = 60) {
        return $this->db
            ->select('c.*, CONCAT(u.first_name," ",u.last_name) as worker_name, u.employee_id')
            ->from('certifications c')->join('users u', 'u.id = c.user_id')
            ->where('c.expiry_date <=', date('Y-m-d', strtotime("+{$days} days")))
            ->where('c.expiry_date >=', date('Y-m-d'))
            ->order_by('c.expiry_date', 'ASC')->get()->result();
    }

    public function get_leave_history($user_id) {
        return $this->db->where('user_id', $user_id)->order_by('created_at', 'DESC')->get('leave_requests')->result();
    }

    public function get_shift_assignments($week_start) {
        $records = $this->db->where('week_start', $week_start)->get('shift_assignments')->result();
        $indexed = [];
        foreach ($records as $r) { $indexed[$r->user_id] = $r->shift_id; }
        return $indexed;
    }
}
