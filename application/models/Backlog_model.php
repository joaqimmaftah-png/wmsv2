<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Backlog_model — the defect / backlog register.
 *
 * Replaces the two manual backlog sheets of the old asset-list workbook:
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
        // Closure-evidence and inspection-link columns.
        // Listed here because _filter() drops anything not in this list, and
        // silently losing the verifier on a closure would be worse than a
        // failed write. Callers only set them when the columns exist.
        'verified_by', 'verified_at', 'verification_notes', 'closed_by',
        'inspection_item_id', 'recurrence_of', 'created_by',
        'requested_date', 'requested_duration', 'schedule_state', 'schedule_message',
        'closure_kind', 'completion_source', 'reviewed_by', 'reviewed_at',
        'cancellation_reason', 'submit_token',
    );

    protected $_last_error = null;

    public function available() {
        return $this->db->table_exists('backlogs');
    }

    /** An edit-form version is a conflict detector, not an authorization token. */
    public static function edit_version($item) {
        $values = array();
        foreach (self::FIELDS as $field) {
            if (property_exists($item, $field)) $values[$field] = $item->$field === NULL ? NULL : (string)$item->$field;
        }
        return hash('sha256', serialize($values));
    }

    private function _days_expr() {
        return "CASE WHEN b.status IN ('Open','In Progress') THEN DATEDIFF(CURDATE(), b.start_date) WHEN b.end_date IS NOT NULL THEN DATEDIFF(b.end_date, b.start_date) ELSE NULL END";
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
        $expr = 'COALESCE(e.registration_no, b.asset_label)';
        $r = $this->db->select($expr . ' AS asset,
                                MAX(b.equipment_id) AS equipment_id,
                                COUNT(*) AS items,
                                SUM(CASE WHEN b.priority = "P1" THEN 1 ELSE 0 END) AS p1,
                                MAX('.$days.') AS oldest_days', FALSE)
                      ->from('backlogs b')
                      ->join('equipment e', 'e.id = b.equipment_id', 'left')
                      ->where_in('b.status', array('Open', 'In Progress'))
                      ->group_by($expr, FALSE)
                      ->order_by('items', 'DESC')->limit((int)$limit)->get()->result();
        return $r ? $r : array();
    }

    /**
     * Hydrate the complete Backlog Defect Register in constant O(1) SQL queries
     * (`<= 3` queries regardless of defect count):
     *   1. `backlogs` LEFT JOIN `equipment`, `work_orders`, and `users` (`verified_by`)
     *   2. `equipment` active asset list (`id, registration_no, location`)
     *
     * Strictly compliant with MySQL 8.0 `ONLY_FULL_GROUP_BY` (zero ambiguous grouping).
     *
     * @param string|null $today Optional YYYY-MM-DD anchor for deterministic unit tests.
     * @return array{available:bool,rows:object[],equipment:object[],by_asset:object[],today:string}
     */
    public function get_register_overview($today = NULL) {
        $todayStr = ($today && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$today))
            ? (string)$today
            : date('Y-m-d');

        if (!$this->available()) {
            return array(
                'available' => FALSE,
                'rows'      => array(),
                'equipment' => array(),
                'by_asset'  => array(),
                'today'     => $todayStr,
            );
        }

        $hasWo    = $this->db->table_exists('work_orders');
        $hasUsers = $this->db->table_exists('users');
        $hasReviewer = $hasUsers && $this->db->field_exists('reviewed_by', 'backlogs');

        $sel = 'b.*, e.registration_no AS equipment_code, e.machine_type, e.location AS equip_location';
        if ($hasWo) {
            $sel .= ', wo.wo_number, wo.status AS wo_status';
        }
        if ($hasUsers) {
            $sel .= ', uv.first_name AS verifier_first, uv.last_name AS verifier_last';
        }
        if ($hasReviewer) $sel .= ', ur.first_name AS reviewer_first, ur.last_name AS reviewer_last';

        $qb = $this->db->select($sel)->from('backlogs b')
            ->join('equipment e', 'e.id = b.equipment_id', 'left');
        if ($hasWo) {
            $qb->join('work_orders wo', 'wo.id = b.work_order_id', 'left');
        }
        if ($hasUsers) {
            $qb->join('users uv', 'uv.id = b.verified_by', 'left');
        }
        if ($hasReviewer) $qb->join('users ur', 'ur.id = b.reviewed_by', 'left');
        $rows = $qb->order_by('b.id', 'DESC')->get()->result();
        if (!$rows) $rows = array();

        // A date typed on a defect is not proof that a plan job exists. Read
        // actual active jobs in one query, including legacy data predating 0015.
        $jobs = array(); $jobCounts = array();
        if ($this->db->table_exists('maintenance_plan_jobs')) {
            $planRows = $this->db->select('id, backlog_id, equipment_id, start_date, end_date, hours_per_day, status')
                ->where('backlog_id IS NOT NULL', NULL, FALSE)
                ->where_in('status', array('Planned', 'Issued'))
                ->order_by('id', 'DESC')->get('maintenance_plan_jobs')->result();
            foreach ((array)$planRows as $job) {
                $bid = (int)$job->backlog_id;
                $jobCounts[$bid] = isset($jobCounts[$bid]) ? $jobCounts[$bid] + 1 : 1;
                if (!isset($jobs[$bid])) $jobs[$bid] = $job;
            }
        }
        $todayTs   = strtotime($todayStr . ' 00:00:00');
        $assetRoll = array();

        foreach ($rows as $b) {
            $code = trim((string)(isset($b->equipment_code) ? $b->equipment_code : ''));
            $lbl  = trim((string)(isset($b->asset_label) ? $b->asset_label : ''));
            $b->machine_display = $code !== '' ? $code : ($lbl !== '' ? $lbl : '—');

            $isOpen = in_array((string)$b->status, array('Open', 'In Progress'), TRUE);
            $b->is_open = $isOpen;

            // Exact calendar day age anchored at local midnight
            $endAnchor = $isOpen ? $todayStr : (!empty($b->end_date) ? (string)$b->end_date : NULL);
            if ($endAnchor && !empty($b->start_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$b->start_date)) {
                $dStart = strtotime((string)$b->start_date . ' 00:00:00');
                $dEnd   = strtotime($endAnchor . ' 00:00:00');
                $b->days_down = max(0, (int)round(($dEnd - $dStart) / 86400));
            } else {
                $b->days_down = NULL; // unknown historical completion is not today
            }

            $job = isset($jobs[(int)$b->id]) ? $jobs[(int)$b->id] : NULL;
            $planMismatch = $job && (empty($job->equipment_id) || empty($b->equipment_id)
                || (int)$job->equipment_id !== (int)$b->equipment_id);
            $b->has_plan = (bool)($job && !$planMismatch);
            $b->plan_job_id = $job ? (int)$job->id : 0;
            $b->plan_date_display = $job ? (string)$job->start_date : '';
            $b->plan_minutes = $job ? (int)round((float)$job->hours_per_day * 60) : NULL;
            $state = isset($b->schedule_state) ? (string)$b->schedule_state : '';
            $labels = array('not_planned' => 'Not planned', 'needs_info' => 'Planning needs information',
                'pending_approval' => 'Proposal awaiting supervisor', 'planned' => 'Planned',
                'warning' => 'Planned with warning', 'failed' => 'Planning failed',
                'review_required' => 'Plan needs supervisor review');
            $b->schedule_label = isset($labels[$state]) ? $labels[$state]
                : ($job ? 'Planned' : (!empty($b->planned_date) ? 'Legacy date; plan not confirmed' : 'Not planned'));
            if (!$job && in_array($state, array('planned', 'warning'), TRUE)) {
                $b->schedule_label = 'Plan job not found — review required';
            } elseif ($job && $state === 'not_planned') {
                $b->schedule_label = 'Plan job found — review required';
            }
            if ($job && in_array($state, array('pending_approval', 'needs_info', 'failed'), TRUE)) {
                $b->schedule_label .= ' — existing plan remains';
            }
            if ($planMismatch) $b->schedule_label = 'Plan job belongs to another machine — review required';
            if (!empty($jobCounts[(int)$b->id]) && $jobCounts[(int)$b->id] > 1) {
                $b->schedule_label = 'Multiple active plan jobs — review required';
            }
            if (!$isOpen && $job && (string)$job->start_date >= $todayStr) {
                $b->schedule_label = 'Closed defect — future plan needs review';
            }
            $b->schedule_detail = isset($b->schedule_message) ? (string)$b->schedule_message : '';

            $b->is_overdue = ($isOpen && !empty($b->deadline) && (string)$b->deadline < $todayStr);
            $b->deadline_delta = NULL;
            if (!empty($b->deadline) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$b->deadline)) {
                $b->deadline_delta = (int)round((strtotime((string)$b->deadline . ' 00:00:00') - $todayTs) / 86400);
            }

            // Parse originating inspection ID when source_ref matches `insp_{recordId}_item_{itemId}`
            $b->inspection_record_id = 0;
            if (!empty($b->source_ref) && preg_match('/^insp_(\d+)_item_/', (string)$b->source_ref, $mInsp)) {
                $b->inspection_record_id = (int)$mInsp[1];
            }

            $vfFirst = trim((string)(isset($b->verifier_first) ? $b->verifier_first : ''));
            $vfLast  = trim((string)(isset($b->verifier_last) ? $b->verifier_last : ''));
            $vfFull  = trim($vfFirst . ' ' . $vfLast);
            // Free-text supervisor names and legacy automated stamps are not
            // proof that the person actually verified the repair.
            $b->verifier_name = $vfFull;
            $b->reviewer_name = trim((string)(isset($b->reviewer_first) ? $b->reviewer_first : '').' '.(string)(isset($b->reviewer_last) ? $b->reviewer_last : ''));
            $kind = isset($b->closure_kind) ? (string)$b->closure_kind : '';
            $b->verification_label = $b->status !== 'Closed' ? ''
                : (in_array($kind, array('verified', 'historical_verified'), TRUE) && !empty($b->verified_by) && !empty($b->verified_at)
                    ? ($kind === 'historical_verified' ? 'Historical completion; supervisor verified now' : 'Supervisor verified')
                    : (in_array($kind, array('historical', 'historical_verified'), TRUE)
                        ? 'Historical completion; verification not recorded'
                        : 'Legacy closure; verification not confirmed'));

            $owner = trim((string)$b->in_charge);
            if ($owner === '') $owner = trim((string)$b->mechanic);
            if ($owner === '') $owner = trim((string)$b->reported_by);
            $b->owner_display = $owner !== '' ? $owner : '—';

            $statusOrder = array('Open' => 1, 'In Progress' => 2, 'Closed' => 3, 'Cancelled' => 4);
            $prioOrder   = array('P1' => 1, 'P2' => 2, 'P3' => 3);
            $b->status_rank   = isset($statusOrder[$b->status]) ? $statusOrder[$b->status] : 5;
            $b->priority_rank = isset($prioOrder[$b->priority]) ? $prioOrder[$b->priority] : 4;

            if ($isOpen) {
                $key = $b->machine_display;
                if (!isset($assetRoll[$key])) {
                    $assetRoll[$key] = (object)array(
                        'asset'        => $key,
                        'equipment_id' => !empty($b->equipment_id) ? (int)$b->equipment_id : 0,
                        'items'        => 0,
                        'p1'           => 0,
                        'oldest_days'  => 0,
                    );
                }
                $assetRoll[$key]->items++;
                if ($b->priority === 'P1') $assetRoll[$key]->p1++;
                if ($b->days_down > $assetRoll[$key]->oldest_days) {
                    $assetRoll[$key]->oldest_days = $b->days_down;
                }
            }
        }

        $byAsset = array_values($assetRoll);
        usort($byAsset, function ($a, $b) {
            if ($b->items !== $a->items) return $b->items <=> $a->items;
            return $b->p1 <=> $a->p1;
        });
        $byAsset = array_slice($byAsset, 0, 10);

        $equipList = array();
        if ($this->db->table_exists('equipment')) {
            $equipList = $this->db->select('id, registration_no, location')
                ->from('equipment')
                ->order_by('registration_no', 'ASC')
                ->get()->result();
        }

        return array(
            'available' => TRUE,
            'rows'      => $rows,
            'equipment' => $equipList ? $equipList : array(),
            'by_asset'  => $byAsset,
            'today'     => $todayStr,
        );
    }

    // ── WRITE ───────────────────────────────────────────────
    public function insert($data) {
        $this->_last_error = null;
        if (!$this->available()) { $this->_last_error = 'The backlog table is missing — import the database dump shipped with this repository.'; return false; }
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

    /** 0015 is required before writing planning proposals or closure evidence. */
    public function workflow_ready() {
        foreach (array('requested_date', 'requested_duration', 'schedule_state', 'schedule_message',
            'closure_kind', 'completion_source', 'reviewed_by', 'reviewed_at', 'cancellation_reason',
            'submit_token', 'verified_by', 'verified_at', 'verification_notes', 'closed_by') as $column) {
            if (!$this->db->field_exists($column, 'backlogs')) return FALSE;
        }
        if (!$this->db->table_exists('backlog_events')) return FALSE;
        // A column alone is not enough: concurrent retries rely on the unique
        // database index, including during a partially applied migration.
        $index = $this->db->query('SHOW INDEX FROM `'.$this->db->dbprefix('backlogs').'` WHERE `Key_name` = ?', array('uq_backlog_submit_token'));
        $parts = $index ? $index->result() : array();
        return count($parts) === 1 && (int)$parts[0]->Non_unique === 0
            && (string)$parts[0]->Column_name === 'submit_token' && (int)$parts[0]->Seq_in_index === 1
            && (!isset($parts[0]->Sub_part) || $parts[0]->Sub_part === NULL);
    }

    public function by_submit_token($token) {
        if (!$token || !$this->db->field_exists('submit_token', 'backlogs')) return NULL;
        return $this->db->where('submit_token', $token)->get('backlogs')->row();
    }

    public function record_event($id, $actor_id, $type, $details) {
        if (!$this->db->table_exists('backlog_events')) return FALSE;
        if (is_array($details) || is_object($details)) {
            $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $this->db->insert('backlog_events', array(
            'backlog_id' => (int)$id, 'actor_id' => $actor_id ? (int)$actor_id : NULL,
            'event_type' => (string)$type, 'details' => (string)$details,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    public function events($id) {
        if (!$this->db->table_exists('backlog_events')) return array();
        $rows = $this->db->where('backlog_id', (int)$id)->order_by('id', 'DESC')
            ->limit(30)->get('backlog_events')->result();
        return $rows ? $rows : array();
    }

    public function active_plans($id) {
        if (!$this->db->table_exists('maintenance_plan_jobs')) return array();
        $rows = $this->db->where('backlog_id', (int)$id)
            ->where_in('status', array('Planned', 'Issued'))
            ->order_by('id', 'DESC')->get('maintenance_plan_jobs')->result();
        return $rows ? $rows : array();
    }

    public function active_plan($id) {
        $rows = $this->active_plans($id);
        return $rows ? $rows[0] : NULL;
    }

    /** Current read after locking the defect row; FALSE means the check failed. */
    public function active_plans_locked($id) {
        if (!$this->db->table_exists('maintenance_plan_jobs')) return array();
        $query = $this->db->query('SELECT * FROM '.$this->db->dbprefix('maintenance_plan_jobs')
            ." WHERE backlog_id = ? AND status IN ('Planned','Issued') ORDER BY id DESC FOR UPDATE", array((int)$id));
        return $query ? $query->result() : FALSE;
    }

    public function open_for_work_order($work_order_id) {
        if (!$this->available()) return array();
        $rows = $this->db->where('work_order_id', (int)$work_order_id)
            ->where_in('status', array('Open', 'In Progress'))
            ->order_by('id', 'ASC')->get('backlogs')->result();
        return $rows ? $rows : array();
    }

    /** Called inside the work-order status transaction; only explicitly checked
     * defects are closed. Errors abort the whole status change. */
    public function close_selected_for_work_order($wo, array $ids, $actor_id, $notes) {
        if (!$ids) return array('closed' => 0);
        if (!$this->workflow_ready()) return array('error' => 'Apply backlog workflow migration 0015 before verifying defects.');
        $notes = trim((string)$notes);
        if ($notes === '') return array('error' => 'Describe what you checked before verifying defects.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) { return $id > 0; })));
        if (!$ids) return array('error' => 'The selected defects could not be read. Refresh and review.');
        sort($ids, SORT_NUMERIC); // lock in a consistent order across verification requests
        $workDone = trim((string)(isset($wo->action_taken) ? $wo->action_taken : ''));
        if ($workDone === '') return array('error' => 'Record the work completed on the work order before verifying its selected defects.');
        $completionDate = !empty($wo->end_datetime) ? substr((string)$wo->end_datetime, 0, 10) : NULL;
        $parsedCompletion = $completionDate ? DateTime::createFromFormat('!Y-m-d', $completionDate) : FALSE;
        if (!$parsedCompletion || $parsedCompletion->format('Y-m-d') !== $completionDate) $completionDate = NULL;
        if ($completionDate && $completionDate > date('Y-m-d')) return array('error' => 'The work order completion date is in the future.');
        $closed = 0;
        foreach ($ids as $id) {
            $query = $this->db->query('SELECT * FROM '.$this->db->dbprefix('backlogs').' WHERE id = ? FOR UPDATE', array((int)$id));
            $item = $query ? $query->row() : NULL;
            if (!$item || (int)$item->work_order_id !== (int)$wo->id
                || !in_array($item->status, array('Open', 'In Progress'), TRUE)
                || empty($item->equipment_id) || empty($wo->equipment_id)
                || (int)$item->equipment_id !== (int)$wo->equipment_id) {
                return array('error' => 'A selected defect is no longer open on this machine and work order. Refresh and review.');
            }
            if (!empty($item->start_date) && ($item->start_date > date('Y-m-d') || ($completionDate && $item->start_date > $completionDate))) {
                return array('error' => 'A selected defect starts after the work was completed; review its dates before closing it.');
            }
            $now = date('Y-m-d H:i:s');
            $data = array('status' => 'Closed', 'end_date' => $completionDate,
                'closing_remarks' => 'Work order '.$wo->wo_number.': '.$workDone,
                'closed_by' => (int)$actor_id, 'verified_by' => (int)$actor_id,
                'verified_at' => $now, 'verification_notes' => mb_substr($notes, 0, 300),
                'closure_kind' => 'verified', 'reviewed_by' => (int)$actor_id, 'reviewed_at' => $now);
            // The work-order transaction may have taken an earlier consistent
            // snapshot. This locking read observes a plan committed just before
            // the defect row was locked rather than reporting a stale state.
            $jobs = $this->active_plans_locked($id);
            if ($jobs === FALSE) return array('error' => 'The defect plan could not be checked; no status change was saved.');
            $job = $jobs ? $jobs[0] : NULL;
            if ($job && (string)$job->start_date >= date('Y-m-d')) {
                $data['schedule_state'] = 'review_required';
                $data['schedule_message'] = 'A future plan job remains. A supervisor must review it; published baselines stay frozen.';
            }
            $ok = $this->db->where('id', $id)->where('work_order_id', (int)$wo->id)
                ->where_in('status', array('Open', 'In Progress'))->update('backlogs', $data);
            if (!$ok || $this->db->affected_rows() !== 1
                || !$this->record_event($id, $actor_id, 'verified_with_work_order', array('work_order_id' => (int)$wo->id, 'notes' => $notes))) {
                return array('error' => 'The selected defect could not be verified; no status change was saved.');
            }
            $closed++;
        }
        return array('closed' => $closed);
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
        $rows = $this->db->select('id, registration_no, legacy_code')
                         ->from('equipment')->get()->result();
        $exact = array(); $normalized = array();
        foreach ($rows as $e) {
            if (strcasecmp(trim((string)$e->registration_no), trim((string)$label)) === 0
                || (!empty($e->legacy_code) && strcasecmp(trim((string)$e->legacy_code), trim((string)$label)) === 0)) $exact[(int)$e->id] = TRUE;
            if ($key && (self::fleet_key($e->registration_no) === $key
                || (!empty($e->legacy_code) && self::fleet_key($e->legacy_code) === $key))) $normalized[(int)$e->id] = TRUE;
        }
        if (count($exact) === 1) return (int)key($exact);
        if (count($exact) > 1 || count($normalized) !== 1) return NULL;
        return (int)key($normalized);
    }

    /** 'C-HE-ADT 015' / 'adt015' / 'ADT 15' → 'ADT15'. */
    public static function fleet_key($s) {
        if ($s === null || $s === '') return NULL;
        $s = strtoupper((string)(preg_replace('/[^A-Za-z0-9]/', '', (string)$s)));
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
