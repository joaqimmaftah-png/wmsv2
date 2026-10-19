<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Backlog_model — the defect / backlog register.
 *
 * Replaces the two manual sheets in `myexcellreport/asset list.xlsx`:
 *
 *   "Backlog on going"  → open items   (status Open / In Progress)
 *   "Backlog done"      → closed items (status Closed)
 *
 * A backlog item is NOT a work order: it is a defect observed during an
 * inspection, a daily checklist or a job card, which is prioritised (P1/P2/P3),
 * assigned to someone and tracked until it is fixed. A work order may be
 * raised for it — `work_order_id` keeps that link — but most items are closed
 * without one, which is exactly why the workshop kept them in a spreadsheet.
 *
 * `days_down` is deliberately derived, never stored: in Excel it was a formula
 * that was only right on the day the file happened to be opened.
 */
class Backlog_model extends CI_Model {

    const SOURCES    = array('Inspection', 'Checklist', 'Job Card', 'Operator', 'Other');
    const PRIORITIES = array('P1', 'P2', 'P3');
    const STATUSES   = array('Open', 'In Progress', 'Closed', 'Cancelled');
    const MACHINE_STATUSES = array('OPERATIONAL', 'BREAKDOWN', 'STANDBY');

    /** Columns the forms and the importer may write. */
    const FIELDS = array(
        'equipment_id', 'asset_label', 'model', 'serial_no', 'start_odometer',
        'start_date', 'description', 'source', 'reported_by', 'in_charge',
        'priority', 'deadline', 'observation', 'machine_status', 'status',
        'end_odometer', 'end_date', 'closing_remarks', 'mechanic', 'supervisor',
        'work_order_id', 'planned_date', 'estimated_duration', 'source_ref',
        // Wave 3 / migration 014 — closure evidence and inspection links.
        // Listed here because _filter() drops anything not in this list, and
        // silently losing the verifier on a closure would be worse than a
        // failed write. Callers only set them when the columns exist.
        'verified_by', 'verified_at', 'verification_notes', 'closed_by',
        'inspection_item_id', 'recurrence_of',
    );

    protected $_last_error = null;

    public function available() {
        return $this->db->table_exists('backlogs');
    }

    private function _days_expr() {
        return 'DATEDIFF(COALESCE(b.end_date, CURDATE()), b.start_date)';
    }

    // ── READ ────────────────────────────────────────────────
    public function get_all($filter = array(), $limit = 0) {
        if (!$this->available()) return array();
        $days = $this->_days_expr();
        $this->db->select('b.*', FALSE)
                 ->select($days.' AS days_down', FALSE)
                 ->select('e.registration_no AS equipment_code, e.machine_type', FALSE)
                 ->from('backlogs b')
                 ->join('equipment e', 'e.id = b.equipment_id', 'left');

        if (!empty($filter['status'])) {
            if ($filter['status'] === 'OPEN') {
                $this->db->where_in('b.status', array('Open', 'In Progress'));
            } else {
                $this->db->where('b.status', $filter['status']);
            }
        }
        if (!empty($filter['priority']))     $this->db->where('b.priority', $filter['priority']);
        if (!empty($filter['source']))       $this->db->where('b.source', $filter['source']);
        if (!empty($filter['machine_status'])) $this->db->where('b.machine_status', $filter['machine_status']);
        if (!empty($filter['equipment_id'])) $this->db->where('b.equipment_id', (int)$filter['equipment_id']);
        if (!empty($filter['date_from']))    $this->db->where('b.start_date >=', $filter['date_from']);
        if (!empty($filter['date_to']))      $this->db->where('b.start_date <=', $filter['date_to']);
        if (!empty($filter['overdue'])) {
            $this->db->where('b.deadline IS NOT NULL')
                     ->where('b.deadline <', date('Y-m-d'))
                     ->where_in('b.status', array('Open', 'In Progress'));
        }
        if (!empty($filter['search'])) {
            $s = $this->db->escape_like_str($filter['search']);
            $this->db->where("(b.asset_label LIKE '%{$s}%' OR b.description LIKE '%{$s}%'
                               OR b.in_charge LIKE '%{$s}%' OR b.reported_by LIKE '%{$s}%'
                               OR b.observation LIKE '%{$s}%' OR b.model LIKE '%{$s}%')");
        }
        $this->db->order_by("FIELD(b.status,'Open','In Progress','Closed','Cancelled')", '', FALSE)
                 ->order_by("FIELD(b.priority,'P1','P2','P3')", '', FALSE)
                 ->order_by('b.start_date', 'ASC');
        if ($limit > 0) $this->db->limit((int)$limit);
        $r = $this->db->get()->result();
        return $r ? $r : array();
    }

    public function get_by_id($id) {
        if (!$this->available()) return NULL;
        $days = $this->_days_expr();
        return $this->db->select('b.*', FALSE)
                        ->select($days.' AS days_down', FALSE)
                        ->select('e.registration_no AS equipment_code', FALSE)
                        ->from('backlogs b')
                        ->join('equipment e', 'e.id = b.equipment_id', 'left')
                        ->where('b.id', (int)$id)->get()->row();
    }

    /** Headline counters for the list page and the dashboard. */
    public function kpi($filter = array()) {
        $kpi = array('total' => 0, 'open' => 0, 'closed' => 0, 'p1' => 0,
                     'overdue' => 0, 'breakdown' => 0, 'avg_days' => 0, 'oldest' => 0);
        if (!$this->available()) return $kpi;
        $days = $this->_days_expr();
        $row = $this->db->select('COUNT(*) AS total,
                SUM(CASE WHEN b.status IN ("Open","In Progress") THEN 1 ELSE 0 END) AS open_n,
                SUM(CASE WHEN b.status = "Closed" THEN 1 ELSE 0 END) AS closed_n,
                SUM(CASE WHEN b.priority = "P1" AND b.status IN ("Open","In Progress") THEN 1 ELSE 0 END) AS p1_n,
                SUM(CASE WHEN b.deadline IS NOT NULL AND b.deadline < CURDATE()
                          AND b.status IN ("Open","In Progress") THEN 1 ELSE 0 END) AS overdue_n,
                SUM(CASE WHEN b.machine_status = "BREAKDOWN" AND b.status IN ("Open","In Progress") THEN 1 ELSE 0 END) AS bd_n,
                AVG(CASE WHEN b.status IN ("Open","In Progress") THEN '.$days.' END) AS avg_days,
                MAX(CASE WHEN b.status IN ("Open","In Progress") THEN '.$days.' END) AS oldest', FALSE)
            ->from('backlogs b')->get()->row();
        if (!$row) return $kpi;
        return array(
            'total'     => (int)$row->total,
            'open'      => (int)$row->open_n,
            'closed'    => (int)$row->closed_n,
            'p1'        => (int)$row->p1_n,
            'overdue'   => (int)$row->overdue_n,
            'breakdown' => (int)$row->bd_n,
            'avg_days'  => round((float)$row->avg_days, 1),
            'oldest'    => (int)$row->oldest,
        );
    }

    /** Open items per machine — the "which asset is dragging us down" view. */
    public function by_equipment($limit = 20) {
        if (!$this->available()) return array();
        $days = $this->_days_expr();
        $r = $this->db->select('COALESCE(e.registration_no, b.asset_label) AS asset,
                                COUNT(*) AS items,
                                SUM(CASE WHEN b.priority = "P1" THEN 1 ELSE 0 END) AS p1,
                                MAX('.$days.') AS oldest_days', FALSE)
                      ->from('backlogs b')
                      ->join('equipment e', 'e.id = b.equipment_id', 'left')
                      ->where_in('b.status', array('Open', 'In Progress'))
                      ->group_by('asset')
                      ->order_by('items', 'DESC')->limit((int)$limit)->get()->result();
        return $r ? $r : array();
    }

    // ── WRITE ───────────────────────────────────────────────
    public function insert($data) {
        $this->_last_error = null;
        if (!$this->available()) { $this->_last_error = 'The backlog table is missing — run migration 007.'; return false; }
        $row = $this->_filter($data);
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['updated_at'] = date('Y-m-d H:i:s');
        $ok = $this->db->insert('backlogs', $row);
        if (!$ok) { $this->_last_error = $this->_db_error(); return false; }
        return (int)$this->db->insert_id();
    }

    public function update($id, $data) {
        $this->_last_error = null;
        if (!$this->available()) return false;
        $row = $this->_filter($data);
        if (empty($row)) return false;
        $row['updated_at'] = date('Y-m-d H:i:s');
        $ok = $this->db->where('id', (int)$id)->update('backlogs', $row);
        if (!$ok) { $this->_last_error = $this->_db_error(); return false; }
        return true;
    }

    public function delete($id) {
        if (!$this->available()) return false;
        return $this->db->where('id', (int)$id)->delete('backlogs');
    }

    /**
     * Resolve a free-text asset reference ("DZ 003", "ADT015", "C-HE-ADT 015")
     * to an equipment id. The workshop writes the same machine several ways;
     * the key strips punctuation, the site prefix and leading zeros.
     */
    public function resolve_equipment($label) {
        if (!$this->db->table_exists('equipment')) return NULL;
        $key = self::fleet_key($label);
        if (!$key) return NULL;
        $rows = $this->db->select('id, registration_no, legacy_code')
                         ->from('equipment')->get()->result();
        foreach ($rows as $e) {
            if (self::fleet_key($e->registration_no) === $key) return (int)$e->id;
        }
        foreach ($rows as $e) {
            if (!empty($e->legacy_code) && self::fleet_key($e->legacy_code) === $key) return (int)$e->id;
        }
        return NULL;
    }

    /** 'C-HE-ADT 015' / 'adt015' / 'ADT 15' → 'ADT15'. */
    public static function fleet_key($s) {
        if ($s === null || $s === '') return NULL;
        $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$s));
        $s = preg_replace('/^(CHE|CHV|CAML|CCOMP|AML|C)(?=[A-Z])/', '', $s);
        if (!preg_match('/^([A-Z]+)0*([0-9]+)$/', $s, $m)) return NULL;
        return $m[1] . (int)$m[2];
    }

    public function last_error() { return $this->_last_error; }

    private function _filter($data) {
        $out = array();
        foreach (self::FIELDS as $f) {
            if (!array_key_exists($f, $data)) continue;
            $v = $data[$f];
            $out[$f] = ($v === '' || $v === null) ? NULL : $v;
        }
        return $out;
    }

    private function _db_error() {
        if (!method_exists($this->db, 'error')) return 'The record could not be saved.';
        $e = $this->db->error();
        return (is_array($e) && !empty($e['message'])) ? $e['message'] : 'The record could not be saved.';
    }
}
