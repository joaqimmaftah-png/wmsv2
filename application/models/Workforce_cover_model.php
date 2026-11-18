<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * One supervisor-approved cover assignment for each published, dated sick
 * Shift. The finder makes suggestions; it never assigns an Off day or changes
 * attendance. On-duty cover redistributes duties, not an extra headcount.
 * An Off candidate must explicitly be called in with the existing Pay/TimeOff
 * exception, and the original Off remains the base plan.
 */
class Workforce_cover_model extends CI_Model {
    private $tables_ready = NULL;

    public function ready() {
        if ($this->tables_ready !== NULL) return $this->tables_ready;
        return $this->tables_ready = $this->Workforce_plan_model->ready()
            && $this->db->table_exists('workforce_plan_cover');
    }

    /** No hidden mutation: an uninstalled feature cannot affect legacy leave. */
    public function conflict($user_id, $from, $to, $side = 'either') {
        if (!$this->ready() || $from > $to) return FALSE;
        $this->db->where('status', 'Assigned')->where('work_date >=', $from)->where('work_date <=', $to);
        if ($side === 'absent') $this->db->where('absent_user_id', (int)$user_id);
        elseif ($side === 'cover') $this->db->where('cover_user_id', (int)$user_id);
        else $this->db->group_start()->where('absent_user_id', (int)$user_id)
            ->or_where('cover_user_id', (int)$user_id)->group_end();
        return $this->db->count_all_results('workforce_plan_cover') > 0;
    }

    public function vacancy($absent_id, $date) {
        if (!$this->ready() || $date < date('Y-m-d') || !$this->Workforce_plan_model->is_published($date)) return NULL;
        $absent = $this->db->where('id', (int)$absent_id)->get('users')->row();
        if (!$absent || !in_array($absent->status, array('Active', 'On Leave'), TRUE)
            || ($absent->join_date && $absent->join_date > $date)) return NULL;
        $day = $this->db->where('user_id', (int)$absent_id)->where('work_date', $date)
            ->where('day_type', 'Shift')->get('workforce_plan_days')->row();
        if (!$day || !$day->shift_id) return NULL;
        $sick = $this->db->where('user_id', (int)$absent_id)->where('leave_type', 'Sick Leave')
            ->where('status', 'Scheduled')->where('start_date <=', $date)->where('end_date >=', $date)
            ->get('workforce_plan_leaves')->row();
        $source = 'Planned';
        if (!$sick) {
            $sick = $this->db->where('user_id', (int)$absent_id)->where('leave_type', 'Sick Leave')
                ->where('status', 'Approved')->where('start_date <=', $date)->where('end_date >=', $date)
                ->get('leave_requests')->row();
            $source = 'Approved request';
        }
        if (!$sick) return NULL;
        $shift = $this->db->where('id', (int)$day->shift_id)->get('shifts')->row();
        if (!$shift) return NULL;
        $cover = $this->db->where('absent_user_id', (int)$absent_id)->where('work_date', $date)
            ->get('workforce_plan_cover')->row();
        if ($cover && $cover->status !== 'Assigned') $cover = NULL;
        return array('absent' => $absent, 'date' => $date, 'shift' => $shift,
            'leave' => $sick, 'source' => $source, 'cover' => $cover);
    }

    /**
     * A deterministic, deliberately conservative policy. Certification names
     * currently held by the sick worker are a safety proxy, NOT a declaration
     * that these are every qualification required for the job. A supervisor
     * must verify actual capacity and competency before approval.
     *
     * @return array{state:string,mode:string,reason:string,score:int}
     */
    public static function assess($absent, $candidate, $cell, $target_shift_id,
                                  array $required_certs = array(), array $held_certs = array(),
                                  $already_covering = FALSE, $actual_status = '', $work_orders = 0,
                                  $recent_covers = 0, $open_session = FALSE, $date = '', $pending_leave = FALSE) {
        $issues = array();
        $norm = function ($v) { return strtolower(trim((string)$v)); };
        if ((int)$absent->id === (int)$candidate->id) $issues[] = 'The sick worker cannot cover their own shift.';
        if ((string)$candidate->status !== 'Active' || (!empty($candidate->join_date) && $candidate->join_date > $date))
            $issues[] = 'Not an active worker on this date.';
        if ((int)$candidate->role_id !== (int)$absent->role_id) $issues[] = 'Different job role.';
        if ($norm($absent->position) === '' || $norm($candidate->position) === ''
            || $norm($absent->position) !== $norm($candidate->position)) $issues[] = 'Position is missing or does not match.';
        if ($norm($absent->site) === '' || $norm($candidate->site) === ''
            || $norm($absent->site) !== $norm($candidate->site)) $issues[] = 'Work site is missing or does not match.';
        $rank = array('Junior' => 1, 'Senior' => 2, 'Expert' => 3, 'Specialist' => 4);
        $a = isset($rank[$absent->skill_level]) ? $rank[$absent->skill_level] : 0;
        $b = isset($rank[$candidate->skill_level]) ? $rank[$candidate->skill_level] : 0;
        if (!$a || $b < $a) $issues[] = 'Skill level is below the absent worker or not recorded.';
        $held = array(); foreach ($held_certs as $name) $held[$norm($name)] = TRUE;
        foreach ($required_certs as $name) if (!isset($held[$norm($name)])) {
            $issues[] = 'Missing current certificate: ' . $name . '.';
        }
        if ($already_covering) $issues[] = 'Already covering another shift on this date.';
        if ($pending_leave) $issues[] = 'A leave request is pending for this date.';
        if ($actual_status !== '' && $actual_status !== 'Present') $issues[] = 'Actual attendance is already ' . $actual_status . '.';
        if ($open_session) $issues[] = 'An active work session needs to be closed or reassigned.';
        if (!is_array($cell) || !isset($cell['plan_status']) || $cell['plan_status'] !== 'Published')
            $issues[] = 'Candidate has no published plan for this date.';
        $mode = ''; $state = 'Blocked';
        if (is_array($cell) && isset($cell['kind'], $cell['plan_status']) && $cell['plan_status'] === 'Published') {
            if ($cell['kind'] === 'Shift' && (int)$cell['shift_id'] === (int)$target_shift_id) {
                $mode = 'OnShift'; $state = 'Ready';
            } elseif ($cell['kind'] === 'Off' && empty($cell['replacement'])) {
                if (!empty($cell['extra'])) {
                    if (in_array($cell['extra']->status, array('Planned', 'Confirmed'), TRUE)
                        && (int)$cell['extra']->shift_id === (int)$target_shift_id) {
                        $mode = 'OffCallIn'; $state = 'Ready';
                    } else $issues[] = 'Already called in for a different shift.';
                } else { $mode = 'OffCallIn'; $state = 'CallIn'; }
            } else $issues[] = 'Leave, Holiday, a replacement Off, or another shift prevents cover.';
        }
        // A Present attendance row without an authorised Off exception is not
        // evidence of consent to a call-in; correct that case manually first.
        if ($state === 'CallIn' && $actual_status === 'Present') $issues[] = 'Work was already recorded without an authorised call-in.';
        if ($issues) $state = 'Blocked';
        $score = ($mode === 'OnShift' ? 100 : ($state === 'CallIn' ? 35 : 75))
            + max(0, $b - $a) * 3 - min(5, (int)$work_orders) * 8 - min(5, (int)$recent_covers) * 9;
        return array('state' => $state, 'mode' => $mode, 'reason' => implode(' ', $issues),
            'score' => $score);
    }

    /** Bulk reads; no candidate-by-candidate SQL. Ranking is recalculated on
     * the approval POST while holding the same year lock as leave and day edits. */
    public function shortlist(array $vacancy) {
        $absent = $vacancy['absent']; $date = $vacancy['date']; $shift_id = (int)$vacancy['shift']->id;
        $people = $this->db->where('status', 'Active')->where('role_id', (int)$absent->role_id)
            ->where('id !=', (int)$absent->id)->order_by('first_name')->get('users')->result();
        $out = array('ready' => array(), 'call_in' => array(), 'blocked' => array());
        if (!$people) return $out;
        $ids = array((int)$absent->id); foreach ($people as $p) $ids[] = (int)$p->id;
        $days = $this->Workforce_plan_model->days($date, $date, array_slice($ids, 1), FALSE);
        $certs = array();
        foreach ($this->db->select('user_id, cert_name')->where_in('user_id', $ids)
                     ->group_start()->where('issued_date IS NULL', NULL, FALSE)->or_where('issued_date <=', $date)->group_end()
                     ->group_start()->where('expiry_date IS NULL', NULL, FALSE)->or_where('expiry_date >=', $date)->group_end()
                     ->get('certifications')->result() as $cert) {
            $certs[(int)$cert->user_id][] = (string)$cert->cert_name;
        }
        $reserved = array();
        foreach ($this->db->select('cover_user_id')->where('work_date', $date)->where('status', 'Assigned')
                     ->get('workforce_plan_cover')->result() as $row) $reserved[(int)$row->cover_user_id] = TRUE;
        $pending = array();
        foreach ($this->db->select('user_id')->where_in('user_id', array_slice($ids, 1))
                     ->where('status', 'Pending')->where('start_date <=', $date)
                     ->where('end_date >=', $date)->get('leave_requests')->result() as $row)
            $pending[(int)$row->user_id] = TRUE;
        $actual = array();
        if ($date <= date('Y-m-d')) {
            foreach ($this->db->select('user_id, status')->where_in('user_id', array_slice($ids, 1))
                         ->where('date', $date)->get('attendance')->result() as $row)
                $actual[(int)$row->user_id] = $row->status;
        }
        $loads = array();
        foreach ($this->db->select('assigned_to')->where_in('status', array('Open','In Progress','Waiting Parts','Waiting Tools'))
                     ->get('work_orders')->result() as $job) {
            foreach (array_unique(array_map('intval', explode(',', (string)$job->assigned_to))) as $id)
                if ($id > 0) $loads[$id] = isset($loads[$id]) ? $loads[$id] + 1 : 1;
        }
        $recent = array();
        foreach ($this->db->select('cover_user_id, COUNT(*) AS total')->where('status', 'Assigned')
                     ->where('work_date >=', date('Y-m-d', strtotime($date . ' -30 days')))
                     ->where('work_date <=', $date)->group_by('cover_user_id')
                     ->get('workforce_plan_cover')->result() as $row) $recent[(int)$row->cover_user_id] = (int)$row->total;
        $sessions = array();
        if ($date === date('Y-m-d') && $this->db->table_exists('wo_sessions')) {
            foreach ($this->db->select('technician_id')->where_in('technician_id', array_slice($ids, 1))
                         ->where('status', 'Open')->get('wo_sessions')->result() as $row)
                $sessions[(int)$row->technician_id] = TRUE;
        }
        foreach ($people as $person) {
            $id = (int)$person->id;
            $cell = isset($days[$id][$date]) ? $days[$id][$date] : NULL;
            $result = self::assess($absent, $person, $cell, $shift_id,
                isset($certs[(int)$absent->id]) ? $certs[(int)$absent->id] : array(),
                isset($certs[$id]) ? $certs[$id] : array(), isset($reserved[$id]),
                isset($actual[$id]) ? $actual[$id] : '', isset($loads[$id]) ? $loads[$id] : 0,
                isset($recent[$id]) ? $recent[$id] : 0, isset($sessions[$id]), $date, isset($pending[$id]));
            $result['worker'] = $person;
            $result['work_orders'] = isset($loads[$id]) ? $loads[$id] : 0;
            $result['recent_covers'] = isset($recent[$id]) ? $recent[$id] : 0;
            $result['extra'] = $cell && isset($cell['extra']) ? $cell['extra'] : NULL;
            if ($result['state'] === 'Ready') $out['ready'][] = $result;
            elseif ($result['state'] === 'CallIn') $out['call_in'][] = $result;
            else $out['blocked'][] = $result;
        }
        foreach ($out as &$group) usort($group, function ($a, $b) {
            return $b['score'] <=> $a['score'] ?: strcmp($a['worker']->first_name . $a['worker']->last_name,
                $b['worker']->first_name . $b['worker']->last_name);
        });
        unset($group);
        return $out;
    }

    /** One month at a time. Every row is a *published* sick Shift, even if no
     * matching worker exists: uncovered dates are the supervisor's action list. */
    private function queue_query($from, $to, $uncovered_only = FALSE) {
        $sick = "(EXISTS (SELECT 1 FROM workforce_plan_leaves l WHERE l.user_id = d.user_id AND l.leave_type = 'Sick Leave' AND l.status = 'Scheduled' AND l.start_date <= d.work_date AND l.end_date >= d.work_date) OR EXISTS (SELECT 1 FROM leave_requests lr WHERE lr.user_id = d.user_id AND lr.leave_type = 'Sick Leave' AND lr.status = 'Approved' AND lr.start_date <= d.work_date AND lr.end_date >= d.work_date))";
        $query = $this->db->from('workforce_plan_days d')
            ->join('users u', 'u.id = d.user_id')
            ->join('shifts s', 's.id = d.shift_id')
            ->join('workforce_plan_years y', 'y.plan_year = YEAR(d.work_date)')
            ->join('workforce_plan_cover c', 'c.absent_user_id = d.user_id AND c.work_date = d.work_date AND c.status = \'Assigned\'', 'left')
            ->join('users cu', 'cu.id = c.cover_user_id', 'left')
            ->where('d.work_date >=', $from)->where('d.work_date <=', $to)
            ->where('d.day_type', 'Shift')->where_in('u.status', array('Active','On Leave'))
            ->where('y.status', 'Published')->where('y.published_at <=', date('Y-m-d H:i:s'))
            ->where($sick, NULL, FALSE);
        if ($uncovered_only) $query->where('c.id IS NULL', NULL, FALSE);
        return $query;
    }

    public function queue($from, $to, $page = 1, $per_page = 25, $uncovered_only = FALSE) {
        if (!$this->ready()) return array('rows' => array(), 'total' => 0);
        $page = max(1, (int)$page); $per_page = max(1, min(100, (int)$per_page));
        $total = $this->queue_query($from, $to, $uncovered_only)->count_all_results();
        $rows = $this->queue_query($from, $to, $uncovered_only)
            ->select('d.user_id AS absent_user_id, d.work_date, d.shift_id, u.first_name, u.last_name, u.employee_id, u.site, u.position, s.name AS shift_name, s.start_time, s.end_time, c.id AS cover_id, c.cover_user_id, c.cover_mode, cu.first_name AS cover_first_name, cu.last_name AS cover_last_name')
            ->order_by('d.work_date')->order_by('u.last_name')->limit($per_page, ($page - 1) * $per_page)
            ->get()->result();
        return array('rows' => $rows, 'total' => (int)$total);
    }

    /** All cover assignments touching any of these workers, in bulk. */
    public function dates($from, $to, array $ids) {
        if (!$this->ready() || !$ids) return array();
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
        if (!$ids) return array();
        return $this->db->where('status', 'Assigned')->where('work_date >=', $from)->where('work_date <=', $to)
            ->group_start()->where_in('absent_user_id', $ids)->or_where_in('cover_user_id', $ids)->group_end()
            ->get('workforce_plan_cover')->result();
    }

    /** All annual writes serialize on this exact year row. Call within the
     * transaction when approving a legacy Sick Leave request as well. */
    public function begin_years(array $years, $actor) {
        if (!$this->ready() || !$this->db->trans_begin()) return FALSE;
        $years = array_unique(array_map('intval', $years)); sort($years, SORT_NUMERIC);
        foreach ($years as $year) {
            if ($year < 2020 || $year > 2100) { $this->db->trans_rollback(); return FALSE; }
            $this->db->query('INSERT IGNORE INTO workforce_plan_years (plan_year,created_by) VALUES (?,?)', array($year, (int)$actor));
            $lock = $this->db->query('SELECT plan_year FROM workforce_plan_years WHERE plan_year = ? FOR UPDATE', array($year));
            if (!$lock || !$lock->row()) { $this->db->trans_rollback(); return FALSE; }
        }
        return TRUE;
    }

    private function fail($message) { $this->db->trans_rollback(); return array('ok' => FALSE, 'message' => $message); }
    private function done($message) {
        if ($this->db->trans_status() === FALSE || !$this->db->trans_commit()) {
            $this->db->trans_rollback();
            return array('ok' => FALSE, 'message' => 'Cover was not saved; a database error occurred.');
        }
        return array('ok' => TRUE, 'message' => $message);
    }
    private function event($year, $uid, $date, $type, $details, $actor) {
        $this->db->insert('workforce_plan_events', array('plan_year' => (int)$year, 'user_id' => (int)$uid,
            'work_date' => $date, 'event_type' => $type, 'details' => json_encode($details), 'actor_id' => (int)$actor));
    }

    /** Require a fresh match *under the year lock*. Caller posts only IDs and
     * a reason, never a trustworthy eligibility score or candidate state. */
    public function approve($absent_id, $date, $candidate_id, $reason, $capacity_checked,
                            $availability_confirmed, $compensation, $actor) {
        if (!$this->ready() || $date < date('Y-m-d') || strlen(trim($reason)) < 5)
            return array('ok' => FALSE, 'message' => 'Choose a current or future sick Shift and give an approval reason (at least 5 characters).');
        if (!$capacity_checked) return array('ok' => FALSE, 'message' => 'Confirm the candidate has capacity and the required qualifications.');
        if (!$this->begin_years(array((int)substr($date, 0, 4)), $actor))
            return array('ok' => FALSE, 'message' => 'Cannot lock the cover year. Check migration 0019.');
        $vacancy = $this->vacancy($absent_id, $date);
        if (!$vacancy) return $this->fail('This is no longer a published sick Shift. Refresh the cover finder.');
        if ($vacancy['cover']) return $this->fail('This sick Shift has already been covered. Refresh before editing.');
        $candidate = NULL;
        $options = $this->shortlist($vacancy);
        foreach (array('ready', 'call_in') as $group) foreach ($options[$group] as $row)
            if ((int)$row['worker']->id === (int)$candidate_id) $candidate = $row;
        if (!$candidate) return $this->fail('Candidate is no longer eligible. Refresh for a new shortlist.');
        $mode = $candidate['mode']; $extra = $candidate['extra']; $created = 0;
        if ($candidate['state'] === 'CallIn') {
            if (!$availability_confirmed || !in_array($compensation, array('Pay','TimeOff'), TRUE))
                return $this->fail('An Off day requires confirmed worker availability and a Pay or replacement-Off route.');
            // Extra work and cover are authorised atomically, with independent
            // evidence and compensation. Never turn the base Off into a Shift.
            $this->db->insert('workforce_plan_extra_days', array(
                'user_id' => (int)$candidate_id, 'work_date' => $date,
                'shift_id' => (int)$vacancy['shift']->id, 'status' => 'Planned',
                'compensation' => $compensation, 'settlement' => 'Open',
                'reason' => trim($reason), 'approved_by' => (int)$actor,
                'approved_at' => date('Y-m-d H:i:s')));
            $extra_id = (int)$this->db->insert_id(); $created = 1;
            $this->event((int)substr($date, 0, 4), $candidate_id, $date, 'Off-day work planned',
                array('shift_id' => (int)$vacancy['shift']->id, 'compensation' => $compensation,
                    'purpose' => 'Sick shift cover', 'reason' => trim($reason)), $actor);
        } else {
            // A prior authorised exception is reused, never silently edited.
            if ($compensation !== '' || $availability_confirmed) return $this->fail('The proposed terms changed. Refresh the candidate list.');
            $extra_id = $mode === 'OffCallIn' && $extra ? (int)$extra->id : NULL;
        }
        $values = array('shift_id' => (int)$vacancy['shift']->id, 'cover_user_id' => (int)$candidate_id,
            'cover_mode' => $mode, 'extra_day_id' => $extra_id, 'extra_created_for_cover' => $created,
            'status' => 'Assigned', 'reason' => trim($reason),
            'availability_confirmed_at' => $candidate['state'] === 'CallIn' ? date('Y-m-d H:i:s') : NULL,
            'approved_by' => (int)$actor,
            'approved_at' => date('Y-m-d H:i:s'), 'cancel_reason' => NULL,
            'cancelled_by' => NULL, 'cancelled_at' => NULL);
        $prior = $this->db->where('absent_user_id', (int)$absent_id)->where('work_date', $date)
            ->get('workforce_plan_cover')->row();
        if ($prior) $this->db->where('id', (int)$prior->id)->update('workforce_plan_cover', $values);
        else {
            $values['absent_user_id'] = (int)$absent_id; $values['work_date'] = $date;
            $this->db->insert('workforce_plan_cover', $values);
        }
        $year = (int)substr($date, 0, 4);
        $details = array('absent_user_id' => (int)$absent_id, 'cover_user_id' => (int)$candidate_id,
            'shift_id' => (int)$vacancy['shift']->id, 'mode' => $mode,
            'extra_day_id' => $extra_id, 'reason' => trim($reason));
        $this->event($year, $absent_id, $date, 'Sick cover assigned', $details, $actor);
        $this->event($year, $candidate_id, $date, 'Cover duty assigned', $details, $actor);
        return $this->done($mode === 'OffCallIn'
            ? 'Cover approved. The candidate remains Off on the base plan, with a separate compensated call-in.'
            : 'On-shift cover approved. This redistributes duties; it does not add a staffed shift.');
    }

    /** Cancellation is explicit and auditable. Only an untouched call-in made
     * by this approval is revoked; a pre-existing or worked extra is preserved. */
    public function cancel($id, $reason, $actor) {
        if (!$this->ready() || strlen(trim($reason)) < 5)
            return array('ok' => FALSE, 'message' => 'Give a reason (at least 5 characters) to cancel cover.');
        $row = $this->db->where('id', (int)$id)->get('workforce_plan_cover')->row();
        if (!$row || $row->work_date < date('Y-m-d'))
            return array('ok' => FALSE, 'message' => 'Past cover is historical and cannot be cancelled.');
        if (!$this->begin_years(array((int)substr($row->work_date, 0, 4)), $actor))
            return array('ok' => FALSE, 'message' => 'Cannot lock the cover year.');
        $row = $this->db->where('id', (int)$id)->get('workforce_plan_cover')->row();
        if (!$row || $row->status !== 'Assigned') return $this->fail('Cover has already changed; refresh the page.');
        if ($row->cover_mode === 'OffCallIn' && (int)$row->extra_created_for_cover) {
            $extra = $this->db->where('id', (int)$row->extra_day_id)->get('workforce_plan_extra_days')->row();
            if (!$extra || $extra->status !== 'Planned' || $extra->settlement !== 'Open')
                return $this->fail('The call-in has work or compensation attached; it cannot be cancelled as unused cover.');
            $att = $this->db->where('user_id', (int)$row->cover_user_id)->where('date', $row->work_date)
                ->get('attendance')->row();
            if ($att && $att->status === 'Present')
                return $this->fail('Actual work was recorded. Confirm and settle the call-in instead of cancelling its cover.');
            $this->db->where('id', (int)$extra->id)->update('workforce_plan_extra_days', array('status' => 'Cancelled'));
            $this->event((int)substr($row->work_date, 0, 4), $row->cover_user_id, $row->work_date,
                'Extra work cancelled', array('reason' => 'Cover cancelled: ' . trim($reason)), $actor);
        }
        $this->db->where('id', (int)$row->id)->update('workforce_plan_cover', array(
            'status' => 'Cancelled', 'cancel_reason' => trim($reason),
            'cancelled_by' => (int)$actor, 'cancelled_at' => date('Y-m-d H:i:s')));
        $details = array('absent_user_id' => (int)$row->absent_user_id,
            'cover_user_id' => (int)$row->cover_user_id, 'reason' => trim($reason));
        $year = (int)substr($row->work_date, 0, 4);
        $this->event($year, $row->absent_user_id, $row->work_date, 'Sick cover cancelled', $details, $actor);
        $this->event($year, $row->cover_user_id, $row->work_date, 'Cover duty cancelled', $details, $actor);
        return $this->done('Cover cancelled. The sick Shift needs a new replacement.'
            . ($row->cover_mode === 'OffCallIn' && !(int)$row->extra_created_for_cover
                ? ' The pre-existing Off-day call-in remains authorised; manage it separately.' : ''));
    }
}
