<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Backlog — the defect / backlog register.
 *
 * Replaces the manual "Backlog on going" / "Backlog done" sheets. Items are
 * raised, prioritised, assigned and closed in the system; the Excel export
 * reproduces the two sheets for anyone who still wants the file.
 */
class Backlog extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Backlog_model');
        $this->load->model('Equipment_model');
    }

    /* ── SHARED ENTERPRISE DATASET ──────────────────────── */
    private function _csv_guard($val) {
        $s = (string)$val;
        if ($s !== '' && in_array($s[0], array('=', '+', '-', '@', "\t", "\r"), TRUE)) {
            return "'" . $s;
        }
        return $s;
    }

    private function _build_register_dataset($paginate = TRUE) {
        $overview = $this->Backlog_model->get_register_overview();
        $allRows  = $overview['rows'];

        $tab = strtolower((string)trim((string)$this->input->get('tab', TRUE)));
        $validTabs = array('all', 'active', 'p1', 'breakdown', 'overdue', 'scheduled', 'closed');
        if (!in_array($tab, $validTabs, TRUE)) {
            $tab = 'all';
        }

        $q = trim((string)$this->input->get('q', TRUE));
        if ($q === '') {
            $q = trim((string)$this->input->get('search', TRUE));
        }

        $fStatus   = trim((string)$this->input->get('status', TRUE));
        $fPriority = trim((string)$this->input->get('priority', TRUE));
        $fSource   = trim((string)$this->input->get('source', TRUE));
        $fMachSt   = trim((string)$this->input->get('machine_status', TRUE));
        $fEquip    = (int)$this->input->get('equipment_id', TRUE);
        $fFrom     = trim((string)$this->input->get('date_from', TRUE));
        $fTo       = trim((string)$this->input->get('date_to', TRUE));
        $fOverdue  = (string)$this->input->get('overdue', TRUE) === '1';

        $sort = strtolower((string)trim((string)$this->input->get('sort', TRUE)));
        $allowedSort = array('default', 'start_date', 'asset', 'source', 'priority', 'days_down', 'deadline', 'in_charge', 'status');
        if (!in_array($sort, $allowedSort, TRUE)) {
            $sort = 'default';
        }
        $dir = strtolower((string)trim((string)$this->input->get('dir', TRUE)));
        if (!in_array($dir, array('asc', 'desc'), TRUE)) {
            $dir = ($sort === 'days_down' || $sort === 'start_date') ? 'desc' : 'asc';
        }

        $viewMode = strtolower((string)trim((string)$this->input->get('view', TRUE)));
        if (!in_array($viewMode, array('table', 'cards'), TRUE)) {
            $viewMode = 'table';
        }

        // Global KPI telemetry computed across entire register before slicing tabs
        $openAges = array();
        $kpi = array(
            'total'     => count($allRows),
            'open'      => 0,
            'closed'    => 0,
            'p1'        => 0,
            'overdue'   => 0,
            'breakdown' => 0,
            'scheduled' => 0,
            'avg_days'  => NULL,
            'oldest'    => NULL,
        );
        foreach ($allRows as $b) {
            if (!empty($b->is_open)) {
                $kpi['open']++;
                if ($b->days_down !== NULL) {
                    $openAges[] = (int)$b->days_down;
                    if ($kpi['oldest'] === NULL || (int)$b->days_down > $kpi['oldest']) $kpi['oldest'] = (int)$b->days_down;
                }
                if ($b->priority === 'P1') $kpi['p1']++;
                if (!empty($b->is_overdue)) $kpi['overdue']++;
                if ($b->machine_status === 'BREAKDOWN') $kpi['breakdown']++;
                if (!empty($b->has_plan)) $kpi['scheduled']++;
            } elseif ($b->status === 'Closed') {
                $kpi['closed']++;
            }
        }
        if (!empty($openAges)) {
            $kpi['avg_days'] = round(array_sum($openAges) / count($openAges), 1);
        }

        // Stage 1: Apply facet filters
        $faceted = array();
        $qLower  = strtolower($q);
        $exactId = preg_match('/^bl-([0-9]+)$/iD', $q, $idMatch) ? (int)$idMatch[1] : NULL;
        foreach ($allRows as $b) {
            if ($fStatus !== '') {
                if ($fStatus === 'OPEN') {
                    if (empty($b->is_open)) continue;
                } elseif ((string)$b->status !== $fStatus) {
                    continue;
                }
            }
            if ($fPriority !== '' && (string)$b->priority !== $fPriority) continue;
            if ($fSource !== '' && (string)$b->source !== $fSource) continue;
            if ($fMachSt !== '' && (string)$b->machine_status !== $fMachSt) continue;
            if ($fEquip > 0 && (int)$b->equipment_id !== $fEquip) continue;
            if ($fFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom) && (string)$b->start_date < $fFrom) continue;
            if ($fTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo) && (string)$b->start_date > $fTo) continue;
            if ($fOverdue && empty($b->is_overdue)) continue;

            if ($exactId !== NULL && (int)$b->id !== $exactId) continue;
            if ($qLower !== '' && $exactId === NULL) {
                $hay = strtolower(implode(' ', array(
                    'bl-' . (int)$b->id,
                    '#' . (int)$b->id,
                    (string)$b->machine_display,
                    (string)$b->asset_label,
                    (string)$b->model,
                    (string)$b->serial_no,
                    (string)$b->description,
                    (string)$b->observation,
                    (string)$b->closing_remarks,
                    (string)$b->reported_by,
                    (string)$b->in_charge,
                    (string)$b->mechanic,
                    (string)(isset($b->wo_number) ? $b->wo_number : ''),
                )));
                if (strpos($hay, $qLower) === FALSE) continue;
            }
            $faceted[] = $b;
        }

        // Stage 2: Tab badge counters
        $tabCounts = array(
            'all'       => count($faceted),
            'active'    => 0,
            'p1'        => 0,
            'breakdown' => 0,
            'overdue'   => 0,
            'scheduled' => 0,
            'closed'    => 0,
        );
        foreach ($faceted as $b) {
            if (!empty($b->is_open)) {
                $tabCounts['active']++;
                if ($b->priority === 'P1') $tabCounts['p1']++;
                if ($b->machine_status === 'BREAKDOWN') $tabCounts['breakdown']++;
                if (!empty($b->is_overdue)) $tabCounts['overdue']++;
                if (!empty($b->has_plan)) $tabCounts['scheduled']++;
            } elseif ($b->status === 'Closed') {
                $tabCounts['closed']++;
            }
        }

        // Stage 3: Slice active tab
        $filtered = array();
        foreach ($faceted as $b) {
            if ($tab === 'active' && empty($b->is_open)) continue;
            if ($tab === 'p1' && (empty($b->is_open) || $b->priority !== 'P1')) continue;
            if ($tab === 'breakdown' && (empty($b->is_open) || $b->machine_status !== 'BREAKDOWN')) continue;
            if ($tab === 'overdue' && empty($b->is_overdue)) continue;
            if ($tab === 'scheduled' && (empty($b->is_open) || empty($b->has_plan))) continue;
            if ($tab === 'closed' && $b->status !== 'Closed') continue;
            $filtered[] = $b;
        }

        // Stage 4: Optional ?ids=1,2,3 subset for multi-select batch CSV export
        $idsRaw = trim((string)$this->input->get('ids', TRUE));
        if (!$paginate && $idsRaw !== '') {
            $idMap = array();
            foreach (explode(',', $idsRaw) as $tok) {
                $v = (int)trim($tok);
                if ($v > 0) $idMap[$v] = TRUE;
            }
            if (!empty($idMap)) {
                $sub = array();
                foreach ($filtered as $b) {
                    if (isset($idMap[(int)$b->id])) $sub[] = $b;
                }
                $filtered = $sub;
            }
        }

        // Stage 5: Deterministic multi-column sorting
        $mult = ($dir === 'asc') ? 1 : -1;
        usort($filtered, function ($a, $b) use ($sort, $mult) {
            if ($sort === 'start_date') {
                $c = strcmp((string)$a->start_date, (string)$b->start_date);
                return $c !== 0 ? ($c * $mult) : (((int)$b->id) <=> ((int)$a->id));
            }
            if ($sort === 'asset') {
                $c = strcasecmp((string)$a->machine_display, (string)$b->machine_display);
                return $c !== 0 ? ($c * $mult) : (((int)$b->id) <=> ((int)$a->id));
            }
            if ($sort === 'source') {
                $c = strcasecmp((string)$a->source, (string)$b->source);
                return $c !== 0 ? ($c * $mult) : (((int)$b->id) <=> ((int)$a->id));
            }
            if ($sort === 'priority') {
                $c = ((int)$a->priority_rank) <=> ((int)$b->priority_rank);
                return $c !== 0 ? ($c * $mult) : (((int)$b->days_down) <=> ((int)$a->days_down));
            }
            if ($sort === 'days_down') {
                if ($a->days_down === NULL && $b->days_down !== NULL) return 1;
                if ($b->days_down === NULL && $a->days_down !== NULL) return -1;
                $c = ((int)$a->days_down) <=> ((int)$b->days_down);
                return $c !== 0 ? ($c * $mult) : (((int)$b->id) <=> ((int)$a->id));
            }
            if ($sort === 'deadline') {
                $da = !empty($a->deadline) ? (string)$a->deadline : '9999-12-31';
                $db = !empty($b->deadline) ? (string)$b->deadline : '9999-12-31';
                $c  = strcmp($da, $db);
                return $c !== 0 ? ($c * $mult) : (((int)$b->id) <=> ((int)$a->id));
            }
            if ($sort === 'in_charge') {
                $c = strcasecmp((string)$a->owner_display, (string)$b->owner_display);
                return $c !== 0 ? ($c * $mult) : (((int)$b->id) <=> ((int)$a->id));
            }
            if ($sort === 'status') {
                $c = ((int)$a->status_rank) <=> ((int)$b->status_rank);
                return $c !== 0 ? ($c * $mult) : (((int)$a->priority_rank) <=> ((int)$b->priority_rank));
            }
            // Default triage order: Open -> In Progress -> Closed -> Cancelled, then P1 -> P2 -> P3, then oldest
            if ($a->status_rank !== $b->status_rank) return $a->status_rank <=> $b->status_rank;
            if ($a->priority_rank !== $b->priority_rank) return $a->priority_rank <=> $b->priority_rank;
            $c = strcmp((string)$a->start_date, (string)$b->start_date);
            return $c !== 0 ? $c : (((int)$b->id) <=> ((int)$a->id));
        });

        // Stage 6: Server-side pagination
        $allowedPp     = array(10, 25, 50, 100, 150);
        $perPage       = (int)$this->input->get('per_page', TRUE);
        if (!in_array($perPage, $allowedPp, TRUE)) $perPage = 25;
        $totalFiltered = count($filtered);
        $totalPages    = max(1, (int)ceil($totalFiltered / $perPage));
        $currentPage   = max(1, min($totalPages, (int)$this->input->get('page', TRUE) ?: 1));
        $offset        = ($currentPage - 1) * $perPage;
        $pagedRows     = $paginate ? array_slice($filtered, $offset, $perPage) : $filtered;

        return array(
            'items'          => $pagedRows,
            'kpi'            => $kpi,
            'by_asset'       => $overview['by_asset'],
            'equipment'      => $overview['equipment'],
            'available'      => $overview['available'],
            'tab_counts'     => $tabCounts,
            'tab'            => $tab,
            'q'              => $q,
            'filter'         => array(
                'status'         => $fStatus,
                'priority'       => $fPriority,
                'source'         => $fSource,
                'machine_status' => $fMachSt,
                'equipment_id'   => $fEquip ?: '',
                'date_from'      => $fFrom,
                'date_to'        => $fTo,
                'overdue'        => $fOverdue ? '1' : '',
                'search'         => $q,
            ),
            'sort'           => $sort,
            'dir'            => $dir,
            'view_mode'      => $viewMode,
            'per_page'       => $perPage,
            'allowed_pp'     => $allowedPp,
            'current_page'   => $currentPage,
            'total_pages'    => $totalPages,
            'total_filtered' => $totalFiltered,
            'offset'         => $offset,
            'can_write'      => $this->can_write(),
            'is_supervisor'  => $this->is_supervisor(),
        );
    }

    /* ── LIST ──────────────────────────────────────────── */
    public function index() {
        $data = $this->_build_register_dataset(TRUE);
        $data['page_title'] = 'Backlog Register';
        $this->render('backlog/index', $data);
    }

    /* ── UTF-8 BOM CSV EXPORT ──────────────────────────── */
    public function export_csv() {
        $data = $this->_build_register_dataset(FALSE);
        $rows = $data['items'];

        $filename = 'backlog-register-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array(
            'Backlog ID',
            'Machine / Asset ID',
            'Model',
            'Serial No',
            'Start Odometer',
            'Start Date',
            'Days Open',
            'Deadline',
            'Priority',
            'Machine Condition',
            'Status',
            'Source',
            'Description',
            'Observation / Action',
            'Reported By',
            'In Charge',
            'Linked WO',
            'Planned Date',
            'Est. Duration (min)',
            'Planning Outcome',
            'Planning Note',
            'Proposed Date',
            'Proposed Duration (min)',
            'End Date',
            'End Odometer',
            'Verification Status',
            'Verified By',
            'Completion Source',
            'Closing Remarks',
            'Reviewed By',
            'Reviewed At',
            'Cancellation Reason',
        ));

        foreach ($rows as $r) {
            fputcsv($out, array(
                'BL-' . (int)$r->id,
                $this->_csv_guard($r->machine_display),
                $this->_csv_guard($r->model),
                $this->_csv_guard($r->serial_no),
                $this->_csv_guard($r->start_odometer),
                $this->_csv_guard($r->start_date),
                $r->days_down === NULL ? '' : (int)$r->days_down,
                $this->_csv_guard($r->deadline),
                $this->_csv_guard($r->priority),
                $this->_csv_guard($r->machine_status),
                $this->_csv_guard($r->status),
                $this->_csv_guard($r->source),
                $this->_csv_guard($r->description),
                $this->_csv_guard($r->observation),
                $this->_csv_guard($r->reported_by),
                $this->_csv_guard($r->in_charge),
                $this->_csv_guard(isset($r->wo_number) ? $r->wo_number : ''),
                $this->_csv_guard($r->plan_date_display),
                $r->plan_minutes !== NULL ? (int)$r->plan_minutes : '',
                $this->_csv_guard($r->schedule_label),
                $this->_csv_guard($r->schedule_detail),
                $this->_csv_guard(isset($r->requested_date) ? $r->requested_date : ''),
                isset($r->requested_duration) && $r->requested_duration !== NULL ? (int)$r->requested_duration : '',
                $this->_csv_guard($r->end_date),
                $this->_csv_guard($r->end_odometer),
                $this->_csv_guard($r->verification_label),
                $this->_csv_guard(isset($r->closure_kind) && in_array($r->closure_kind, array('verified', 'historical_verified'), TRUE) ? $r->verifier_name : ''),
                $this->_csv_guard(isset($r->completion_source) ? $r->completion_source : ''),
                $this->_csv_guard($r->closing_remarks),
                $this->_csv_guard(isset($r->reviewer_name) ? $r->reviewer_name : ''),
                $this->_csv_guard(isset($r->reviewed_at) ? $r->reviewed_at : ''),
                $this->_csv_guard(isset($r->cancellation_reason) ? $r->cancellation_reason : ''),
            ));
        }

        fclose($out);
        exit;
    }

    /* ── JSON SUMMARY FOR SLIDE-OVER DEFECT INSPECTOR ──── */
    public function summary_json($id = NULL) {
        $bid = (int)$id;
        if ($bid <= 0) {
            return $this->json(array('ok' => FALSE, 'error' => 'Invalid backlog item ID.'), 404);
        }
        $overview = $this->Backlog_model->get_register_overview();
        $found    = NULL;
        foreach ($overview['rows'] as $row) {
            if ((int)$row->id === $bid) {
                $found = $row;
                break;
            }
        }
        if (!$found) {
            return $this->json(array('ok' => FALSE, 'error' => 'Backlog item not found.'), 404);
        }

        return $this->json(array(
            'ok'   => TRUE,
            'item' => array(
                'id'                   => (int)$found->id,
                'machine_display'      => (string)$found->machine_display,
                'equipment_id'         => !empty($found->equipment_id) ? (int)$found->equipment_id : 0,
                'asset_label'          => (string)$found->asset_label,
                'model'                => (string)$found->model ?: '—',
                'serial_no'            => (string)$found->serial_no ?: '—',
                'start_odometer'       => (string)$found->start_odometer ?: '—',
                'start_date'           => (string)$found->start_date ?: '—',
                'days_down'            => $found->days_down === NULL ? NULL : (int)$found->days_down,
                'deadline'             => (string)$found->deadline ?: '—',
                'is_overdue'           => (bool)$found->is_overdue,
                'priority'             => (string)$found->priority ?: '—',
                'machine_status'       => (string)$found->machine_status ?: '—',
                'status'               => (string)$found->status,
                'is_open'              => (bool)$found->is_open,
                'source'               => (string)$found->source ?: 'Other',
                'description'          => (string)$found->description,
                'observation'          => (string)$found->observation,
                'reported_by'          => (string)$found->reported_by ?: '—',
                'in_charge'            => (string)$found->in_charge ?: '—',
                'mechanic'             => (string)$found->mechanic ?: '—',
                'supervisor'           => (string)$found->supervisor ?: '—',
                'planned_date'         => (string)$found->plan_date_display ?: '—',
                'schedule_label'       => (string)$found->schedule_label,
                'schedule_detail'      => (string)$found->schedule_detail,
                'requested_date'       => isset($found->requested_date) && $found->requested_date ? (string)$found->requested_date : '—',
                'requested_duration'   => isset($found->requested_duration) && $found->requested_duration !== NULL ? ((int)$found->requested_duration.' min') : '—',
                'estimated_duration'   => $found->plan_minutes !== NULL ? ((int)$found->plan_minutes . ' min/day') : '—',
                'work_order_id'        => !empty($found->work_order_id) ? (int)$found->work_order_id : 0,
                'wo_number'            => isset($found->wo_number) && $found->wo_number ? (string)$found->wo_number : '',
                'wo_status'            => isset($found->wo_status) && $found->wo_status ? (string)$found->wo_status : '',
                'inspection_record_id' => (int)$found->inspection_record_id,
                'recurrence_of'        => !empty($found->recurrence_of) ? (int)$found->recurrence_of : 0,
                'end_date'             => (string)$found->end_date ?: '—',
                'end_odometer'         => (string)$found->end_odometer ?: '—',
                'closing_remarks'      => (string)$found->closing_remarks,
                'verifier_name'        => isset($found->closure_kind) && in_array($found->closure_kind, array('verified', 'historical_verified'), TRUE) ? ((string)$found->verifier_name ?: '—') : '—',
                'verification_label'   => (string)$found->verification_label,
                'completion_source'    => isset($found->completion_source) ? (string)$found->completion_source : '',
                'cancellation_reason'  => isset($found->cancellation_reason) ? (string)$found->cancellation_reason : '',
                'events'               => $this->Backlog_model->events($bid),
                'reviewer_name'        => !empty($found->reviewer_name) ? (string)$found->reviewer_name : (!empty($found->reviewed_by) ? 'User '.(int)$found->reviewed_by : '—'),
                'reviewed_at'          => isset($found->reviewed_at) && $found->reviewed_at ? (string)$found->reviewed_at : '—',
                'verified_at'          => isset($found->closure_kind) && in_array($found->closure_kind, array('verified', 'historical_verified'), TRUE) && !empty($found->verified_at) ? (string)$found->verified_at : '—',
                'verification_notes'   => isset($found->closure_kind) && in_array($found->closure_kind, array('verified', 'historical_verified'), TRUE) && !empty($found->verification_notes) ? (string)$found->verification_notes : '',
                'legacy_verification_notes' => (!isset($found->closure_kind) || !in_array($found->closure_kind, array('verified', 'historical_verified'), TRUE)) && !empty($found->verification_notes) ? (string)$found->verification_notes : '',
                'can_edit'             => (bool)($this->can_write() && ($found->is_open || $this->is_supervisor())),
                'can_close'            => (bool)($found->is_open && $this->is_supervisor()),
            ),
        ));
    }

    /** Advisory only: do not prevent a legitimate repeat report. */
    public function similar() {
        $equipmentId = (int)$this->input->get('equipment_id', TRUE);
        $term = trim((string)$this->input->get('q', TRUE));
        if ($equipmentId <= 0 || mb_strlen($term) < 4 || !$this->db->table_exists('backlogs')) {
            return $this->json(array('matches' => array()));
        }
        $this->db->select('id, description, priority, status')->where('equipment_id', $equipmentId)
            ->where_in('status', array('Open', 'In Progress'))->like('description', mb_substr($term, 0, 80));
        $exclude = (int)$this->input->get('exclude_id', TRUE);
        if ($exclude > 0) $this->db->where('id !=', $exclude);
        $rows = $this->db->order_by('id', 'DESC')->limit(5)->get('backlogs')->result();
        return $this->json(array('matches' => $rows ? $rows : array()));
    }

    /* ── CREATE ────────────────────────────────────────── */
    public function create() {
        if (!$this->can_write()) redirect('backlog');
        if ($this->input->post()) return $this->_save(null);
        $this->_form(null, array());
    }

    /* ── EDIT ──────────────────────────────────────────── */
    public function edit($id) {
        if (!$this->can_write()) redirect('backlog');
        $item = $this->Backlog_model->get_by_id($id);
        if (!$item) show_404();
        if (!$this->is_supervisor() && in_array($item->status, array('Closed', 'Cancelled'), TRUE)) {
            $this->session->set_flashdata('error', 'Only a supervisor may change a closed or cancelled defect.');
            redirect('backlog'); return;
        }
        if ($this->input->post()) return $this->_save($item);
        $closeInput = $this->session->flashdata('backlog_close_input');
        if (is_array($closeInput)) {
            $post = array_merge((array)$item, $closeInput, array('status' => 'Closed', 'verified_now' => '1'));
            return $this->_form($item, array(), (object)$post);
        }
        $this->_form($item, array());
    }

    /* ── CLOSE (quick action from the list) ────────────── */
    /**
     * Close a defect (Wave 3, Q5 / F-08).
     *
     * Closing a defect is the statement "the repair is done and somebody
     * checked it". It used to be available to anyone who could edit the board,
     * which made it useless as evidence; it is now the supervisor's call, the
     * closure records who verified it and when, and — when the defect has a
     * work order — the work order must be finished first, because that is
     * where the repair is written down. A work-order closure verifies only
     * the defects the supervisor explicitly selects.
     */
    public function close($id) {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Closing a defect requires supervisor verification.');
            redirect('backlog'); return;
        }
        if (!$this->Backlog_model->workflow_ready()) {
            $this->session->set_flashdata('error', 'Apply database migration 0015 before verifying defects.');
            redirect('backlog'); return;
        }
        $end = trim((string)$this->input->post('end_date', TRUE));
        $repair = trim((string)$this->input->post('closing_remarks', TRUE));
        $checked = trim((string)$this->input->post('verification_notes', TRUE));
        if (!$this->_is_date($end) || $end > date('Y-m-d') || $repair === '' || $checked === '') {
            return $this->_close_form_error($id, 'Enter a valid completion date, what was done and what you personally checked.');
        }
        if (!$this->db->trans_begin()) {
            return $this->_close_form_error($id, 'Could not start verification. Nothing was changed.');
        }
        $locked = $this->db->query('SELECT id FROM '.$this->db->dbprefix('backlogs').' WHERE id = ? FOR UPDATE', array((int)$id));
        $item = $locked && $locked->row() ? $this->Backlog_model->get_by_id($id) : NULL;
        $error = '';
        if (!$item || !in_array($item->status, array('Open', 'In Progress'), TRUE)) $error = 'This defect is no longer open. Refresh and review it.';
        elseif ($end < $item->start_date) $error = 'Completion cannot be before the starting date.';
        elseif (!empty($item->work_order_id)) {
            $wo = $this->db->select('id, wo_number, equipment_id, status')->where('id', (int)$item->work_order_id)->get('work_orders')->row();
            if (!$wo || empty($wo->equipment_id) || empty($item->equipment_id)
                || (int)$wo->equipment_id !== (int)$item->equipment_id || !in_array($wo->status, array('Completed', 'Closed'), TRUE)) {
                $error = 'Finish the linked work order for this machine before closing the defect.';
            }
        }
        if ($error !== '') {
            $this->db->trans_rollback(); return $this->_close_form_error($id, $error);
        }
        $now = date('Y-m-d H:i:s');
        $data = array('status' => 'Closed', 'end_date' => $end,
            'end_odometer' => $this->input->post('end_odometer', TRUE), 'closing_remarks' => $repair,
            'mechanic' => $this->input->post('mechanic', TRUE) ?: $item->in_charge,
            'verified_by' => $this->user_id, 'verified_at' => $now,
            'verification_notes' => mb_substr($checked, 0, 300), 'closed_by' => $this->user_id,
            'closure_kind' => 'verified', 'reviewed_by' => $this->user_id, 'reviewed_at' => $now);
        $jobs = $this->Backlog_model->active_plans_locked($id);
        if ($jobs === FALSE) {
            $this->db->trans_rollback();
            return $this->_close_form_error($id, 'The defect plan could not be checked. Nothing was changed.');
        }
        $job = $jobs ? $jobs[0] : NULL;
        if ($job && (string)$job->start_date >= date('Y-m-d')) {
            $data['schedule_state'] = 'review_required';
            $data['schedule_message'] = 'A future plan job remains. A supervisor must review it; published baselines stay frozen.';
        }
        $ok = $this->Backlog_model->update($id, $data)
            && $this->Backlog_model->record_event($id, $this->user_id, 'verified', array('completion_date' => $end, 'repair' => $repair, 'checked' => $checked));
        if (!$ok || $this->db->trans_status() === FALSE || !$this->db->trans_commit()) {
            $this->db->trans_rollback();
            return $this->_close_form_error($id, 'The defect could not be verified. Nothing was changed.');
        } else {
            $this->session->set_flashdata('success', $job && (string)$job->start_date >= date('Y-m-d')
                ? 'Defect verified. A future plan job still needs supervisor review.' : 'Defect closed and verified by you.');
        }
        redirect('backlog');
    }

    private function _close_form_error($id, $message) {
        $this->session->set_flashdata('error', $message);
        $this->session->set_flashdata('backlog_close_input', $this->input->post(NULL, TRUE));
        redirect('backlog/edit/'.(int)$id);
    }

    /** Preserve traceability: a supervisor can cancel with a reason, not erase history. */
    public function delete($id) {
        if (!$this->require_post()) return;
        $this->session->set_flashdata('error', 'Defects cannot be deleted. A supervisor may cancel an incorrect record with a reason.');
        redirect('backlog');
    }

    /* ── EXCEL EXPORT (mirrors the two manual sheets) ──── */
    public function export_xlsx() {
        $this->load->helper('xlsx');
        $filter = $this->_filter();
        $rows   = $this->Backlog_model->get_all($filter);
        $overview = $this->Backlog_model->get_register_overview();
        $enriched = array();
        foreach ($overview['rows'] as $entry) $enriched[(int)$entry->id] = $entry;
        foreach ($rows as $key => $entry) if (isset($enriched[(int)$entry->id])) $rows[$key] = $enriched[(int)$entry->id];

        $open = array(); $done = array();
        foreach ($rows as $r) {
            if ($r->status === 'Closed' || $r->status === 'Cancelled') $done[] = $r;
            else $open[] = $r;
        }

        $xlsx = new XlsxWriter();
        $sHdr  = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap';
        $sCell = 'fontSize:10|fontName:Arial|valign:middle|border:thin|wrap';
        $sC    = 'fontSize:10|fontName:Arial|align:center|valign:middle|border:thin';
        $sP1   = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|bg:FFE0E0|fg:C00000';
        foreach (array($sHdr, $sCell, $sC, $sP1) as $s) $xlsx->registerStyle($s);

        // ── Sheet 1: open items ──
        $xlsx->addSheet('Backlog on going');
        foreach (array(1=>12, 2=>18, 3=>20, 4=>14, 5=>12, 6=>45, 7=>12, 8=>16,
                       9=>16, 10=>8, 11=>10, 12=>12, 13=>35, 14=>14) as $c => $w) {
            $xlsx->setColWidth($c, $w);
        }
        $xlsx->writeRow(array('ASSET','MODEL','S/N','ODOMETER','STARTING DATE','DESCRIPTION',
                              'SOURCE','REPORTED BY','IN CHARGE','PRIORITY','DAYS DOWN',
                              'DEADLINE','OBSERVATION','STATUS'), $sHdr);
        $xlsx->freezePane('A2');
        $xlsx->setAutoFilter('A1:N1');
        foreach ($open as $r) {
            $xlsx->writeRowStyled(array(
                array('v' => $r->equipment_code ?: $r->asset_label, 's' => $sC),
                array('v' => (string)$r->model,          's' => $sCell),
                array('v' => (string)$r->serial_no,      's' => $sCell),
                array('v' => (string)$r->start_odometer, 's' => $sC),
                array('v' => (string)$r->start_date,     's' => $sC),
                array('v' => (string)$r->description,    's' => $sCell),
                array('v' => (string)$r->source,         's' => $sC),
                array('v' => (string)$r->reported_by,    's' => $sCell),
                array('v' => (string)$r->in_charge,      's' => $sCell),
                array('v' => (string)$r->priority,       's' => ($r->priority === 'P1' ? $sP1 : $sC)),
                array('v' => $r->days_down === NULL ? '' : (int)$r->days_down, 's' => $sC),
                array('v' => (string)$r->deadline,       's' => $sC),
                array('v' => (string)$r->observation,    's' => $sCell),
                array('v' => (string)$r->machine_status, 's' => $sC),
            ));
        }

        // ── Sheet 2: closed items ──
        $xlsx->addSheet('Backlog done');
        foreach (array(1=>12, 2=>18, 3=>20, 4=>14, 5=>12, 6=>45, 7=>18, 8=>14,
                       9=>14, 10=>12, 11=>40, 12=>14, 13=>38, 14=>22, 15=>38, 16=>24, 17=>24, 18=>40) as $c => $w) {
            $xlsx->setColWidth($c, $w);
        }
        $xlsx->writeRow(array('ASSET','MODEL','S/N','START ODO','STARTING DATE','DESCRIPTION',
                              'MECHANIC IN CHARGE','SUPERVISOR','END ODO','END DATE',
                              'OBSERVATION', 'STATUS', 'VERIFICATION STATUS', 'VERIFIED BY', 'COMPLETION SOURCE', 'REVIEWED BY', 'REVIEWED AT', 'CANCELLATION REASON'), $sHdr);
        $xlsx->freezePane('A2');
        foreach ($done as $r) {
            $xlsx->writeRowStyled(array(
                array('v' => $r->equipment_code ?: $r->asset_label, 's' => $sC),
                array('v' => (string)$r->model,           's' => $sCell),
                array('v' => (string)$r->serial_no,       's' => $sCell),
                array('v' => (string)$r->start_odometer,  's' => $sC),
                array('v' => (string)$r->start_date,      's' => $sC),
                array('v' => (string)$r->description,     's' => $sCell),
                array('v' => (string)($r->mechanic ?: $r->in_charge), 's' => $sCell),
                array('v' => (string)$r->supervisor,      's' => $sCell),
                array('v' => (string)$r->end_odometer,    's' => $sC),
                array('v' => (string)$r->end_date,        's' => $sC),
                array('v' => (string)$r->closing_remarks, 's' => $sCell),
                array('v' => (string)$r->status, 's' => $sCell),
                array('v' => (string)(isset($r->verification_label) ? $r->verification_label : 'Verification not confirmed'), 's' => $sCell),
                array('v' => isset($r->closure_kind) && in_array($r->closure_kind, array('verified', 'historical_verified'), TRUE) ? (string)$r->verifier_name : '', 's' => $sCell),
                array('v' => isset($r->completion_source) ? (string)$r->completion_source : '', 's' => $sCell),
                array('v' => isset($r->reviewer_name) ? (string)$r->reviewer_name : '', 's' => $sCell),
                array('v' => isset($r->reviewed_at) ? (string)$r->reviewed_at : '', 's' => $sCell),
                array('v' => isset($r->cancellation_reason) ? (string)$r->cancellation_reason : '', 's' => $sCell),
            ));
        }

        $name = 'Backlog_'.date('Ymd_His').'.xlsx';
        $tmp  = wms_temp_path($name);
        $xlsx->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    /* ── internals ─────────────────────────────────────── */

    private function _filter() {
        $search = $this->input->get('search');
        if ($search === null || $search === '') {
            $search = $this->input->get('q');
        }
        return array(
            'status'         => $this->input->get('status'),
            'priority'       => $this->input->get('priority'),
            'source'         => $this->input->get('source'),
            'machine_status' => $this->input->get('machine_status'),
            'equipment_id'   => $this->input->get('equipment_id'),
            'date_from'      => $this->input->get('date_from'),
            'date_to'        => $this->input->get('date_to'),
            'overdue'        => $this->input->get('overdue'),
            'search'         => $search,
        );
    }

    private function _equipment_list() {
        if (!$this->db->table_exists('equipment')) return array();
        return $this->db->select('id, registration_no')->from('equipment')
                        ->order_by('registration_no')->get()->result();
    }

    private function _work_order_list() {
        if (!$this->db->table_exists('work_orders')) return array();
        return $this->db->select('id, wo_number, title, equipment_id, status')
            ->order_by('id', 'DESC')->get('work_orders')->result();
    }

    private function _form($item, $errors, $post = NULL) {
        $statuses = Backlog_model::STATUSES;
        if (!$this->is_supervisor()) {
            $statuses = array_values(array_diff($statuses, array('Closed', 'Cancelled')));
        }
        if (!$item && !$post) {
            $post = (object)array(
                'equipment_id' => (int)$this->input->get('equipment_id', TRUE),
                'asset_label' => trim((string)$this->input->get('asset_label', TRUE)),
            );
        }
        $token = $post && !empty($post->submit_token) ? (string)$post->submit_token : bin2hex(random_bytes(16));
        $version = $item ? ($post && isset($post->record_version) ? (string)$post->record_version : Backlog_model::edit_version($item)) : '';
        $this->render('backlog/form', array(
            'item' => $item, 'post' => $post, 'errors' => $errors, 'record_version' => $version,
            'equipment' => $this->_equipment_list(), 'work_orders' => $this->_work_order_list(),
            'sources' => Backlog_model::SOURCES, 'priorities' => Backlog_model::PRIORITIES,
            'statuses' => $statuses, 'machine_statuses' => Backlog_model::MACHINE_STATUSES,
            'is_supervisor' => $this->is_supervisor(), 'workflow_ready' => $this->Backlog_model->workflow_ready(),
            'active_plan' => $item ? $this->Backlog_model->active_plan($item->id) : NULL,
            'submit_token' => $token,
            'page_title' => $item ? 'Edit Backlog Item' : 'New Backlog Item',
        ));
    }

    private function _save($item) {
        $in = $this->input->post(NULL, TRUE);
        $errors = array();
        $ready = $this->Backlog_model->workflow_ready();
        $asset = trim((string)$this->_v($in, 'asset_label'));
        $equip = (int)$this->_v($in, 'equipment_id');
        if ($equip > 0) {
            $machine = $this->db->select('id, registration_no, legacy_code')->from('equipment')
                ->where('id', $equip)->get()->row();
            if (!$machine) {
                $errors['equipment_id'] = 'The selected machine no longer exists.';
            } elseif ($asset === '') {
                $asset = (string)$machine->registration_no;
            } else {
                $assetKey = Backlog_model::fleet_key($asset);
                $registeredKey = Backlog_model::fleet_key($machine->registration_no);
                $legacyKey = Backlog_model::fleet_key(isset($machine->legacy_code) ? $machine->legacy_code : NULL);
                $same = strcasecmp($asset, (string)$machine->registration_no) === 0
                    || ($assetKey && ($assetKey === $registeredKey || $assetKey === $legacyKey));
                if (!$same) $errors['asset_label'] = 'The typed reference does not match the selected machine. Correct it or leave it blank.';
            }
        } elseif ($asset !== '') {
            $equip = (int)$this->Backlog_model->resolve_equipment($asset);
        }
        if ($asset === '') $errors['asset_label'] = 'Choose a machine or type its asset reference.';

        $start = trim((string)$this->_v($in, 'start_date'));
        $desc = trim((string)$this->_v($in, 'description'));
        $status = in_array($this->_v($in, 'status'), Backlog_model::STATUSES, TRUE)
            ? $this->_v($in, 'status') : 'Open';
        // Hidden closure inputs are not evidence on an open/cancelled defect.
        // Preserve legacy values already on a non-closed row without accepting
        // new completion evidence outside the supervisor's closure flow.
        $legacyCompletion = $item && $item->status !== 'Closed';
        $end = trim((string)($status === 'Closed'
            ? (array_key_exists('end_date', $in) ? $in['end_date'] : ($item && isset($item->end_date) ? $item->end_date : ''))
            : ($legacyCompletion && isset($item->end_date) ? $item->end_date : '')));
        if (!$this->_is_date($start)) $errors['start_date'] = 'A valid starting date is required.';
        if ($desc === '') $errors['description'] = 'Describe the defect.';
        if (mb_strlen($asset) > 80) $errors['asset_label'] = 'Use 80 characters or fewer for the asset reference.';
        $deadline = trim((string)$this->_v($in, 'deadline'));
        if ($deadline !== '' && !$this->_is_date($deadline)) $errors['deadline'] = 'Enter a valid deadline.';
        if ($status === 'Closed') {
            if ($end !== '' && !$this->_is_date($end)) $errors['end_date'] = 'Enter a valid completion date.';
            elseif ($end !== '' && $this->_is_date($start) && $end < $start) $errors['end_date'] = 'Completion cannot be before the starting date.';
            elseif ($end !== '' && $end > date('Y-m-d')) $errors['end_date'] = 'A completed defect cannot have a future completion date.';
        }

        $closedBefore = $item && in_array($item->status, array('Closed', 'Cancelled'), TRUE);
        $newlyClosing = $status === 'Closed' && (!$item || $item->status !== 'Closed');
        $newlyCancelling = $status === 'Cancelled' && (!$item || $item->status !== 'Cancelled');
        if ($closedBefore && !$this->is_supervisor()) $errors['status'] = 'Only a supervisor may change a closed or cancelled defect.';
        if ($closedBefore && $this->is_supervisor() && trim((string)$this->_v($in, 'change_reason')) === '') {
            $errors['change_reason'] = 'Give a reason for correcting a closed or cancelled record.';
        }
        if ($newlyClosing && !$this->is_supervisor()) $errors['status'] = 'Closing a defect requires supervisor verification.';
        if ($newlyCancelling && !$this->is_supervisor()) $errors['status'] = 'Cancelling a defect requires supervisor review.';
        if (($newlyClosing || $newlyCancelling || $closedBefore) && !$ready) {
            $errors['status'] = 'Apply database migration 0015 before closing, cancelling or correcting defects.';
        }
        $cancelReason = trim((string)(array_key_exists('cancellation_reason', $in) ? $in['cancellation_reason']
            : ($item && isset($item->cancellation_reason) ? $item->cancellation_reason : '')));
        if ($status === 'Cancelled' && $cancelReason === '') {
            $errors['cancellation_reason'] = 'Explain why this defect is being cancelled.';
        } elseif ($status === 'Cancelled' && mb_strlen($cancelReason) > 500) {
            $errors['cancellation_reason'] = 'Use 500 characters or fewer for the cancellation reason.';
        }

        $oldWoId = $item && !empty($item->work_order_id) ? (int)$item->work_order_id : 0;
        $woId = array_key_exists('work_order_id', $in) ? (int)$in['work_order_id'] : $oldWoId;
        $wo = NULL;
        if ($item && $oldWoId && $woId !== $oldWoId) {
            if (!$this->is_supervisor()) $errors['work_order_id'] = 'Only a supervisor may change an existing work-order link.';
            elseif (trim((string)$this->_v($in, 'link_change_reason')) === '') $errors['link_change_reason'] = 'Explain why the existing work-order link is changing.';
            if ($newlyClosing) $errors['work_order_id'] = 'Correct the existing work-order link first, then verify the defect separately.';
        }
        $existingJob = $item ? $this->Backlog_model->active_plan($item->id) : NULL;
        if ($item && $existingJob && (int)$item->equipment_id !== $equip) {
            $errors['equipment_id'] = 'Review or remove the existing plan before changing this defect’s machine.';
        }
        if ($woId > 0) {
            $wo = $this->db->table_exists('work_orders')
                ? $this->db->select('id, wo_number, equipment_id, status')->where('id', $woId)->get('work_orders')->row()
                : NULL;
            if (!$wo || $equip <= 0 || (int)$wo->equipment_id !== $equip) {
                $errors['work_order_id'] = 'Choose a work order for the same registered machine.';
            } elseif ($newlyClosing && !in_array($wo->status, array('Completed', 'Closed'), TRUE)) {
                $errors['status'] = 'Finish work order '.$wo->wo_number.' before closing this defect.';
            }
        }

        $historical = $newlyClosing && (string)$this->_v($in, 'historical') === '1';
        $verifiedNow = (string)$this->_v($in, 'verified_now') === '1';
        $repair = trim((string)($status === 'Closed'
            ? (array_key_exists('closing_remarks', $in) ? $in['closing_remarks'] : ($item && isset($item->closing_remarks) ? $item->closing_remarks : ''))
            : ($legacyCompletion && isset($item->closing_remarks) ? $item->closing_remarks : '')));
        $source = trim((string)$this->_v($in, 'completion_source'));
        $verification = trim((string)$this->_v($in, 'verification_notes'));
        if ($newlyClosing && $this->is_supervisor()) {
            if ($this->_is_date($start) && $start > date('Y-m-d')) $errors['start_date'] = 'A closed defect cannot start in the future.';
            if ($repair === '') $errors['closing_remarks'] = 'Describe the work completed.';
            if ($historical && $source === '') $errors['completion_source'] = 'Give a traceable completion source, such as a job card or named firsthand reporter.';
            if (!$historical && $end === '') $errors['end_date'] = 'Enter the completion date.';
            if (!$historical && !$verifiedNow) $errors['verified_now'] = 'Confirm that you personally verified this repair.';
            if (($verifiedNow || !$historical) && $verification === '') $errors['verification_notes'] = 'Describe what you checked.';
            if (mb_strlen($verification) > 300) $errors['verification_notes'] = 'Use 300 characters or fewer for your verification.';
            if (mb_strlen($source) > 500) $errors['completion_source'] = 'Use 500 characters or fewer for the completion source.';
        }
        if ($errors) {
            $this->session->set_flashdata('error', 'Please correct the highlighted fields.');
            return $this->_form($item, $errors, (object)$in);
        }

        $token = trim((string)$this->_v($in, 'submit_token'));
        if (!$item && $ready) {
            if (!preg_match('/^[a-f0-9]{32}$/D', $token)) $token = bin2hex(random_bytes(16));
            $duplicate = $this->Backlog_model->by_submit_token($token);
            if ($duplicate) {
                $this->session->set_flashdata('success', 'This defect was already saved as BL-'.(int)$duplicate->id.'.');
                redirect('backlog');
                return;
            }
        }

        $requestedDate = trim((string)$this->_v($in, 'requested_date'));
        $requestedMins = trim((string)$this->_v($in, 'requested_duration'));
        // Older clients can submit these names, but they are proposals, never
        // a technician's direct write to the authoritative plan.
        if (!array_key_exists('requested_date', $in)) $requestedDate = trim((string)$this->_v($in, 'planned_date'));
        if (!array_key_exists('requested_duration', $in)) $requestedMins = trim((string)$this->_v($in, 'estimated_duration'));
        $hasRequest = $requestedDate !== '' || $requestedMins !== '';
        $validRequest = $equip > 0 && $this->_is_date($requestedDate)
            && ctype_digit($requestedMins) && (int)$requestedMins > 0 && (int)$requestedMins <= 1440;
        $planAction = (string)$this->_v($in, 'plan_action');
        if (!in_array($planAction, array('schedule', 'remove'), TRUE)) $planAction = 'save';
        if (!$this->is_supervisor()) $planAction = 'save';
        $openStatus = in_array($status, array('Open', 'In Progress'), TRUE);

        $data = array(
            'equipment_id' => $equip > 0 ? $equip : NULL, 'asset_label' => $asset,
            'model' => $this->_v($in, 'model'), 'serial_no' => $this->_v($in, 'serial_no'),
            'start_odometer' => $this->_v($in, 'start_odometer'), 'start_date' => $start,
            'description' => $desc,
            'source' => in_array($this->_v($in, 'source'), Backlog_model::SOURCES, TRUE) ? $this->_v($in, 'source') : 'Other',
            'reported_by' => $this->_v($in, 'reported_by'), 'in_charge' => $this->_v($in, 'in_charge'),
            'priority' => in_array($this->_v($in, 'priority'), Backlog_model::PRIORITIES, TRUE) ? $this->_v($in, 'priority') : NULL,
            'deadline' => $deadline !== '' ? $deadline : NULL,
            'observation' => $this->_v($in, 'observation'),
            'machine_status' => in_array($this->_v($in, 'machine_status'), Backlog_model::MACHINE_STATUSES, TRUE) ? $this->_v($in, 'machine_status') : NULL,
            'status' => $status,
            'end_odometer' => $status === 'Closed' && array_key_exists('end_odometer', $in) ? $in['end_odometer'] : ($item && isset($item->end_odometer) ? $item->end_odometer : NULL),
            'end_date' => $end !== '' ? $end : NULL, 'closing_remarks' => $repair,
            'mechanic' => $status === 'Closed' && array_key_exists('mechanic', $in) ? $in['mechanic'] : ($item && isset($item->mechanic) ? $item->mechanic : NULL),
            'supervisor' => $status === 'Closed' && array_key_exists('supervisor', $in) ? $in['supervisor'] : ($item && isset($item->supervisor) ? $item->supervisor : NULL),
            'work_order_id' => $woId > 0 ? $woId : NULL,
        );
        if (!$item && $this->db->field_exists('created_by', 'backlogs')) $data['created_by'] = $this->user_id;
        if ($ready) {
            if (!$item) $data['submit_token'] = $token;
            $data['requested_date'] = $this->_is_date($requestedDate) ? $requestedDate : NULL;
            $data['requested_duration'] = ctype_digit($requestedMins) && (int)$requestedMins > 0 && (int)$requestedMins <= 1440 ? (int)$requestedMins : NULL;
            if ($hasRequest && !$validRequest) {
                $data['schedule_state'] = 'needs_info';
                $data['schedule_message'] = 'Choose a registered machine, a valid date, and a duration from 1 to 1440 minutes. No requested change has been scheduled. Entered date: '.mb_substr($requestedDate, 0, 30).'; minutes: '.mb_substr($requestedMins, 0, 30).'.';
            } elseif ($hasRequest) {
                $data['schedule_state'] = 'pending_approval';
                $data['schedule_message'] = 'The proposed date is not scheduled until a supervisor commits the plan.';
            } else {
                $data['schedule_state'] = $existingJob ? 'planned' : 'not_planned';
                $data['schedule_message'] = NULL;
                if ($existingJob && $item && isset($item->schedule_state) && $item->schedule_state === 'warning') {
                    $data['schedule_state'] = 'warning';
                    $data['schedule_message'] = isset($item->schedule_message) ? $item->schedule_message : NULL;
                }
            }
            if ($item && $planAction === 'save' && isset($item->schedule_state)
                && in_array($item->schedule_state, array('failed', 'needs_info'), TRUE)
                && (string)$data['requested_date'] === (string)(isset($item->requested_date) ? $item->requested_date : '')
                && (string)$data['requested_duration'] === (string)(isset($item->requested_duration) ? $item->requested_duration : '')) {
                $data['schedule_state'] = $item->schedule_state;
                $data['schedule_message'] = isset($item->schedule_message) ? $item->schedule_message : NULL;
            }
            if (!$openStatus) {
                $data['requested_date'] = NULL; $data['requested_duration'] = NULL;
                $data['schedule_state'] = $existingJob ? 'planned' : 'not_planned';
                $data['schedule_message'] = NULL;
            }
            if (!$openStatus && $existingJob && (string)$existingJob->start_date >= date('Y-m-d')) {
                $data['schedule_state'] = 'review_required';
                $data['schedule_message'] = 'A future plan job remains. A supervisor must review it; published baselines stay frozen.';
            }
            if ($newlyClosing) {
                $data['closed_by'] = $this->user_id;
                $data['reviewed_by'] = $this->user_id;
                $data['reviewed_at'] = date('Y-m-d H:i:s');
                $data['completion_source'] = $historical ? $source : NULL;
                $data['closure_kind'] = $historical ? ($verifiedNow ? 'historical_verified' : 'historical') : 'verified';
                $data['verified_by'] = $verifiedNow ? $this->user_id : NULL;
                $data['verified_at'] = $verifiedNow ? date('Y-m-d H:i:s') : NULL;
                $data['verification_notes'] = $verifiedNow ? $verification : NULL;
            } elseif ($item && $item->status === 'Closed' && $status === 'Closed') {
                if (array_key_exists('completion_source', $in)) $data['completion_source'] = $source !== '' ? $source : NULL;
            } elseif ($item && $item->status === 'Closed' && $status !== 'Closed') {
                $data['verified_by'] = NULL; $data['verified_at'] = NULL;
                $data['verification_notes'] = NULL; $data['closure_kind'] = NULL;
                $data['end_date'] = NULL; $data['end_odometer'] = NULL; $data['closing_remarks'] = NULL;
                $data['completion_source'] = NULL; $data['closed_by'] = NULL;
                $data['reviewed_by'] = NULL; $data['reviewed_at'] = NULL;
            }
            if ($item && $item->status === 'Cancelled' && $status !== 'Cancelled') {
                $data['cancellation_reason'] = NULL;
                if (!$newlyClosing) { $data['reviewed_by'] = NULL; $data['reviewed_at'] = NULL; }
            }
            if ($status === 'Cancelled') {
                $data['cancellation_reason'] = $cancelReason;
                if ($newlyCancelling) { $data['reviewed_by'] = $this->user_id; $data['reviewed_at'] = date('Y-m-d H:i:s'); }
            }
        } elseif ($hasRequest) {
            // Do not lose an urgent report when an older database has not yet
            // been migrated; preserve the request as a note, never as a plan.
            $data['observation'] = trim((string)$data['observation']."\nPlanning request not scheduled (migration 0015 required): date ".$requestedDate.'; minutes '.$requestedMins);
        }

        // Handle database failures as recoverable form errors, including in
        // development where CodeIgniter would otherwise exit on a query error.
        $previousDebug = $this->db->db_debug;
        $this->db->db_debug = FALSE;
        try {
            if (!$this->db->trans_begin()) {
                $this->session->set_flashdata('error', 'Could not start saving the defect. Nothing was saved.');
                return $this->_form($item, array(), (object)$in);
            }
            if ($item) {
                $q = $this->db->query('SELECT * FROM '.$this->db->dbprefix('backlogs').' WHERE id = ? FOR UPDATE', array((int)$item->id));
                $latest = $q ? $q->row() : NULL;
                $submittedVersion = trim((string)$this->_v($in, 'record_version'));
                $stale = !$latest;
                // Forms rendered by this version detect edits made since the
                // form was opened. Older integrations without a version retain
                // the transaction-time check below for backward compatibility.
                if ($latest && $submittedVersion !== '' && (!preg_match('/^[a-f0-9]{64}$/D', $submittedVersion)
                    || !hash_equals(Backlog_model::edit_version($latest), $submittedVersion))) $stale = TRUE;
                if ($latest) foreach (Backlog_model::FIELDS as $field) {
                    if (!property_exists($latest, $field) || !property_exists($item, $field)) continue;
                    if ((string)$latest->$field !== (string)$item->$field) { $stale = TRUE; break; }
                }
                if ($stale) {
                    $this->db->trans_rollback();
                    $message = 'This defect changed while you were editing. Copy your entries, refresh and review the latest record before saving.';
                    $this->session->set_flashdata('error', $message);
                    return $this->_form($item, array('record_version' => $message), (object)$in);
                }
                if ((int)$latest->equipment_id !== $equip || !$openStatus) {
                    // The earlier form validation was advisory. A forecast job
                    // may have been activated since then without modifying the
                    // defect. Read the current jobs while holding its row lock.
                    $lockedJobs = $this->Backlog_model->active_plans_locked($item->id);
                    if ($lockedJobs === FALSE) {
                        $this->db->trans_rollback();
                        $message = 'The current plan could not be checked. Your entries are still on the form; try again.';
                        $this->session->set_flashdata('error', $message);
                        return $this->_form($item, array('equipment_id' => $message), (object)$in);
                    }
                    $lockedJob = $lockedJobs ? $lockedJobs[0] : NULL;
                    if ($lockedJob && (int)$latest->equipment_id !== $equip) {
                        $this->db->trans_rollback();
                        $message = 'A plan now exists. Review or remove it before changing this defect’s machine.';
                        $this->session->set_flashdata('error', $message);
                        return $this->_form($item, array('equipment_id' => $message), (object)$in);
                    }
                    if (!$openStatus && $ready) {
                        $data['schedule_state'] = $lockedJob ? 'planned' : 'not_planned';
                        $data['schedule_message'] = NULL;
                        if ($lockedJob && (string)$lockedJob->start_date >= date('Y-m-d')) {
                            $data['schedule_state'] = 'review_required';
                            $data['schedule_message'] = 'A future plan job remains. A supervisor must review it; published baselines stay frozen.';
                        }
                    }
                }
            }
            $savedId = $item ? (int)$item->id : $this->Backlog_model->insert($data);
            $ok = $item ? $this->Backlog_model->update($savedId, $data) : ($savedId !== FALSE && $savedId > 0);
            if ($ok && $ready) {
                $changes = array();
                if ($item) foreach ($data as $key => $value) {
                    $before = isset($item->$key) ? $item->$key : NULL;
                    if ((string)$before !== (string)$value) $changes[$key] = array('before' => $before, 'after' => $value);
                }
                $ok = $this->Backlog_model->record_event($savedId, $this->user_id, $item ? 'updated' : 'created', array(
                    'reason' => trim((string)$this->_v($in, 'change_reason')),
                    'link_change_reason' => trim((string)$this->_v($in, 'link_change_reason')), 'changes' => $changes,
                    'status' => $status, 'historical' => $historical,
                ));
            }
            if (!$ok || $this->db->trans_status() === FALSE || !$this->db->trans_commit()) {
                $this->db->trans_rollback();
                if (!$item && $ready && ($duplicate = $this->Backlog_model->by_submit_token($token))) {
                    $this->session->set_flashdata('success', 'This defect was already saved as BL-'.(int)$duplicate->id.'.');
                    redirect('backlog'); return;
                }
                $this->session->set_flashdata('error', 'The defect could not be confirmed. Your entries are still on the form; check the register before retrying.');
                return $this->_form($item, array(), (object)$in);
            }
        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Defect save failed: '.$e->getMessage());
            $this->session->set_flashdata('error', 'The defect could not be confirmed. Your entries are still on the form; check the register before retrying.');
            return $this->_form($item, array(), (object)$in);
        } finally {
            $this->db->db_debug = $previousDebug;
        }

        $message = $item ? 'Defect updated.' : 'Defect BL-'.$savedId.' saved.';
        $warning = '';
        if (!$ready && ($hasRequest || $planAction !== 'save')) {
            $warning = 'The defect was saved, but its planning request was not scheduled. Ask a supervisor to apply migration 0015.';
        } elseif ($ready && $planAction === 'schedule' && $openStatus) {
            if (!$validRequest) $warning = 'The defect was saved, but the request needs a registered machine, a date and 1–1440 minutes.';
            else {
                $warning = $this->_apply_plan($savedId, $equip, $desc, $requestedDate, (int)$requestedMins, FALSE);
                if ($warning === '') $message .= ' Maintenance-plan job saved.';
            }
        } elseif ($ready && $planAction === 'schedule' && !$openStatus) {
            $warning = 'The defect was saved as closed or cancelled; no new plan job was created.';
        } elseif ($ready && $planAction === 'remove') {
            $warning = $this->_apply_plan($savedId, $equip, $desc, '', 0, TRUE);
        } elseif ($hasRequest && $openStatus) {
            $warning = $validRequest ? 'The defect was saved. The proposed date is awaiting supervisor scheduling.'
                : 'The defect was saved, but the planning request needs more information.';
        }
        if ($warning !== '') $this->session->set_flashdata('error', htmlspecialchars($warning, ENT_QUOTES, 'UTF-8'));
        else $this->session->set_flashdata('success', $message);
        if ((string)$this->_v($in, 'after_save') === 'add_another' && !$item) {
            redirect('backlog/create?'.http_build_query(array('equipment_id' => $equip, 'asset_label' => $asset)));
        } else redirect('backlog');
    }

    /** Apply a supervisor's requested plan change after the defect is committed.
     * The per-defect row lock makes retries idempotent and keeps the old job if
     * validation, a frozen week or a write fails. */
    private function _apply_plan($id, $equipmentId, $description, $date, $minutes, $remove) {
        if (!$this->is_supervisor()) return 'The defect was saved; only a supervisor can change the plan.';
        $this->load->model('Maintenance_plan_model');
        // The defect is already committed. A ledger/job failure must not turn
        // the request into an uncaught database error or erase the defect.
        $previousDebug = $this->db->db_debug;
        $this->db->db_debug = FALSE;
        try {
            $job = NULL;
            // This starts a fresh transaction and takes the shared plan lock
            // before the defect lock or any consistent plan read. The database
            // holds it until the whole job/ledger/defect update commits.
            $outcome = $this->Maintenance_plan_model->plan_transaction(function () use ($id, $equipmentId, $description, $date, $minutes, $remove, &$job) {
                $q = $this->db->query('SELECT id, status, equipment_id, description, requested_date, requested_duration FROM '.$this->db->dbprefix('backlogs').' WHERE id = ? FOR UPDATE', array((int)$id));
                $locked = $q ? $q->row() : NULL;
                if (!$locked) return array('error' => 'The saved defect could not be reloaded.');
                if (!$remove && (!in_array($locked->status, array('Open', 'In Progress'), TRUE)
                    || (int)$locked->equipment_id !== (int)$equipmentId
                    || (string)$locked->description !== (string)$description
                    || (string)$locked->requested_date !== (string)$date
                    || (int)$locked->requested_duration !== (int)$minutes)) {
                    return array('error' => 'The defect or planning request changed while planning. Refresh and review it.');
                }
                $jobs = $this->Backlog_model->active_plans($id);
                $job = $jobs ? $jobs[0] : NULL;
                if (count($jobs) > 1) {
                    return array('error' => 'This defect has multiple active plan jobs. Review them in the maintenance plan before changing them here.');
                }
                if ($job && ($job->status === 'Issued' || (isset($job->source) && $job->source !== 'FromBacklog'))) {
                    return array('error' => 'This job was issued or created elsewhere. Review it in the maintenance plan before changing it here.');
                }
                if ($remove) {
                    $result = $job ? $this->Maintenance_plan_model->delete_job($job->id, $this->user_id) : array('deleted' => 0, 'synced' => array());
                } else {
                    $jobData = array('equipment_id' => (int)$equipmentId, 'start_date' => $date, 'end_date' => $date,
                        'hours_per_day' => round($minutes / 60, 2), 'job_type' => 'Repair', 'category' => 'Corrective',
                        'title' => 'Backlog: '.mb_strcut($description, 0, 190, 'UTF-8'), 'status' => 'Planned',
                        'source' => 'FromBacklog', 'backlog_id' => (int)$id);
                    $result = $this->Maintenance_plan_model->save_job($jobData, $this->user_id, $job ? $job->id : NULL);
                }
                if (!empty($result['errors'])) return array('error' => implode(' ', $result['errors']));
                if (!$remove && empty($result['id'])) return array('error' => 'The plan job was not saved.');
                if ($this->db->trans_status() === FALSE) return array('error' => 'The plan or forecast ledger could not be saved.');

                $warnings = array();
                foreach ((array)(isset($result['synced']) ? $result['synced'] : array()) as $sync) {
                    if (!empty($sync['frozen'])) return array('error' => 'The week is published and frozen.');
                    if (!empty($sync['manual_kept'])) $warnings[] = 'A manual forecast entry still takes precedence.';
                    if (!empty($sync['overbooked'])) $warnings[] = 'The machine is overbooked; review the forecast.';
                }
                $update = array('planned_date' => $remove ? NULL : $date,
                    'estimated_duration' => $remove ? NULL : $minutes,
                    'requested_date' => NULL, 'requested_duration' => NULL,
                    'schedule_state' => $remove ? 'not_planned' : ($warnings ? 'warning' : 'planned'),
                    'schedule_message' => $warnings ? implode(' ', array_unique($warnings)) : NULL);
                $ok = $this->Backlog_model->update($id, $update)
                    && $this->Backlog_model->record_event($id, $this->user_id, $remove ? 'plan_removed' : 'plan_scheduled', $update);
                return $ok ? array('warnings' => $warnings) : array('error' => 'The plan change could not be committed.');
            });
            $error = !empty($outcome['error']) ? $outcome['error'] : '';
            $warnings = isset($outcome['warnings']) ? $outcome['warnings'] : array();
            if ($error !== '') {
                $suffix = ' The existing plan, if any, remains unchanged.';
                // Record the failure separately. Another supervisor may already
                // have retried, so never overwrite a newer successful proposal.
                if ($this->db->trans_begin()) {
                    $q = $this->db->query('SELECT requested_date, requested_duration FROM '.$this->db->dbprefix('backlogs').' WHERE id = ? FOR UPDATE', array((int)$id));
                    $current = $q ? $q->row() : NULL;
                    $nowJob = $remove && isset($job) && $job ? $this->Backlog_model->active_plan($id) : NULL;
                    $sameJob = $nowJob && (int)$nowJob->id === (int)$job->id
                        && (string)$nowJob->start_date === (string)$job->start_date
                        && (string)$nowJob->end_date === (string)$job->end_date
                        && (string)$nowJob->hours_per_day === (string)$job->hours_per_day
                        && (string)$nowJob->status === (string)$job->status;
                    $sameRequest = $current && ($remove ? $sameJob : ((string)$current->requested_date === (string)$date
                        && (int)$current->requested_duration === (int)$minutes));
                    $logged = $current && $this->Backlog_model->record_event($id, $this->user_id, 'plan_failed', array('reason' => $error, 'remove' => $remove));
                    if ($logged && $sameRequest) $logged = $this->Backlog_model->update($id, array('schedule_state' => 'failed', 'schedule_message' => mb_substr($error.$suffix, 0, 700)));
                    if (!$logged || $this->db->trans_status() === FALSE || !$this->db->trans_commit()) $this->db->trans_rollback();
                }
                return 'Defect saved, but the plan was not changed: '.$error.$suffix;
            }
            return $warnings ? 'Defect saved and job planned. '.implode(' ', array_unique($warnings)) : '';
        } catch (Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'Defect planning failed: '.$e->getMessage());
            return 'Defect saved, but the plan could not be confirmed. Check the maintenance plan before retrying.';
        } finally {
            $this->db->db_debug = $previousDebug;
        }
    }

    private function _v($in, $k) {
        return isset($in[$k]) ? $in[$k] : '';
    }

    private function _is_date($s) {
        if (!$s || !is_string($s)) return FALSE;
        $d = DateTime::createFromFormat('!Y-m-d', $s);
        return $d && $d->format('Y-m-d') === $s;
    }
}
