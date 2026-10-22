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
            'avg_days'  => 0.0,
            'oldest'    => 0,
        );
        foreach ($allRows as $b) {
            if (!empty($b->is_open)) {
                $kpi['open']++;
                $openAges[] = (int)$b->days_down;
                if ((int)$b->days_down > $kpi['oldest']) $kpi['oldest'] = (int)$b->days_down;
                if ($b->priority === 'P1') $kpi['p1']++;
                if (!empty($b->is_overdue)) $kpi['overdue']++;
                if ($b->machine_status === 'BREAKDOWN') $kpi['breakdown']++;
                if (!empty($b->planned_date)) $kpi['scheduled']++;
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

            if ($qLower !== '') {
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
                if (!empty($b->planned_date)) $tabCounts['scheduled']++;
            } else {
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
            if ($tab === 'scheduled' && (empty($b->is_open) || empty($b->planned_date))) continue;
            if ($tab === 'closed' && !empty($b->is_open)) continue;
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
            'End Date',
            'End Odometer',
            'Verified / Closed By',
            'Closing Remarks',
        ));

        foreach ($rows as $r) {
            fputcsv($out, array(
                'BL-' . (int)$r->id,
                $this->_csv_guard($r->machine_display),
                $this->_csv_guard($r->model),
                $this->_csv_guard($r->serial_no),
                $this->_csv_guard($r->start_odometer),
                $this->_csv_guard($r->start_date),
                (int)$r->days_down,
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
                $this->_csv_guard($r->planned_date),
                $r->estimated_duration !== NULL ? (int)$r->estimated_duration : '',
                $this->_csv_guard($r->end_date),
                $this->_csv_guard($r->end_odometer),
                $this->_csv_guard($r->verifier_name),
                $this->_csv_guard($r->closing_remarks),
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
                'days_down'            => (int)$found->days_down,
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
                'planned_date'         => (string)$found->planned_date ?: '—',
                'estimated_duration'   => $found->estimated_duration !== NULL ? ((int)$found->estimated_duration . ' min') : '—',
                'work_order_id'        => !empty($found->work_order_id) ? (int)$found->work_order_id : 0,
                'wo_number'            => isset($found->wo_number) && $found->wo_number ? (string)$found->wo_number : '',
                'wo_status'            => isset($found->wo_status) && $found->wo_status ? (string)$found->wo_status : '',
                'inspection_record_id' => (int)$found->inspection_record_id,
                'recurrence_of'        => !empty($found->recurrence_of) ? (int)$found->recurrence_of : 0,
                'end_date'             => (string)$found->end_date ?: '—',
                'end_odometer'         => (string)$found->end_odometer ?: '—',
                'closing_remarks'      => (string)$found->closing_remarks,
                'verifier_name'        => (string)$found->verifier_name ?: '—',
                'verified_at'          => isset($found->verified_at) && $found->verified_at ? (string)$found->verified_at : '—',
                'verification_notes'   => isset($found->verification_notes) && $found->verification_notes ? (string)$found->verification_notes : '',
                'can_edit'             => (bool)$this->can_write(),
                'can_close'            => (bool)($found->is_open && $this->is_supervisor()),
            ),
        ));
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
        if ($this->input->post()) return $this->_save($item);
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
     * where the repair is written down. Closing the work order closes the
     * defect automatically, so there is exactly one closure path.
     */
    public function close($id) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) redirect('backlog');
        $item = $this->Backlog_model->get_by_id($id);
        if (!$item) show_404();

        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error',
                'Closing a defect is the supervisor\'s verification. Record what was done and let a supervisor close it.');
            redirect('backlog');
            return;
        }

        // A defect with a work order is closed through that work order.
        if (!empty($item->work_order_id) && $this->db->table_exists('work_orders')) {
            $wo = $this->db->select('id, wo_number, status')->where('id', (int)$item->work_order_id)
                ->get('work_orders')->row();
            if ($wo && !in_array($wo->status, array('Completed', 'Closed'), TRUE)) {
                $this->session->set_flashdata('error',
                    'Work order '.$wo->wo_number.' is still "'.$wo->status.'" — finish it there, then close it as the supervisor: '
                    .'that closes this defect with the repair record attached.');
                redirect('backlog');
                return;
            }
        }

        $data = array(
            'status'          => 'Closed',
            'end_date'        => $this->input->post('end_date') ?: date('Y-m-d'),
            'end_odometer'    => $this->input->post('end_odometer'),
            'closing_remarks' => $this->input->post('closing_remarks'),
            'mechanic'        => $this->input->post('mechanic') ?: $item->in_charge,
            'supervisor'      => $this->input->post('supervisor') ?: $item->supervisor,
        );

        // Closure-evidence columns — written only on databases that have them.
        if ($this->db->field_exists('verified_by', 'backlogs')) {
            $data['verified_by']         = $this->user_id;
            $data['verified_at']         = date('Y-m-d H:i:s');
            $data['verification_notes']  = $this->input->post('verification_notes', TRUE)
                ?: 'Verified on site by the supervisor.';
        }
        if ($this->db->field_exists('closed_by', 'backlogs')) {
            $data['closed_by'] = $this->user_id;
        }

        $ok = $this->Backlog_model->update($id, $data);
        $this->session->set_flashdata($ok ? 'success' : 'error',
            $ok ? 'Defect closed and verified by you.' : 'The item could not be closed.');
        redirect('backlog');
    }

    public function delete($id) {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) redirect('backlog');
        $this->Backlog_model->delete($id);
        $this->session->set_flashdata('success', 'Backlog item deleted.');
        redirect('backlog');
    }

    /* ── EXCEL EXPORT (mirrors the two manual sheets) ──── */
    public function export_xlsx() {
        $this->load->helper('xlsx');
        $filter = $this->_filter();
        $rows   = $this->Backlog_model->get_all($filter);

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
                array('v' => (int)$r->days_down,         's' => $sC),
                array('v' => (string)$r->deadline,       's' => $sC),
                array('v' => (string)$r->observation,    's' => $sCell),
                array('v' => (string)$r->machine_status, 's' => $sC),
            ));
        }

        // ── Sheet 2: closed items ──
        $xlsx->addSheet('Backlog done');
        foreach (array(1=>12, 2=>18, 3=>20, 4=>14, 5=>12, 6=>45, 7=>18, 8=>14,
                       9=>14, 10=>12, 11=>40) as $c => $w) {
            $xlsx->setColWidth($c, $w);
        }
        $xlsx->writeRow(array('ASSET','MODEL','S/N','START ODO','STARTING DATE','DESCRIPTION',
                              'MECHANIC IN CHARGE','SUPERVISOR','END ODO','END DATE',
                              'OBSERVATION'), $sHdr);
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

    private function _form($item, $errors, $post = null) {
        $statuses = Backlog_model::STATUSES;
        if (!$this->is_supervisor() && (!$item || $item->status !== 'Closed')) {
            $statuses = array_values(array_diff($statuses, array('Closed')));
        }
        $this->render('backlog/form', array(
            'item'       => $item,
            'post'       => $post,
            'errors'     => $errors,
            'equipment'  => $this->_equipment_list(),
            'sources'    => Backlog_model::SOURCES,
            'priorities' => Backlog_model::PRIORITIES,
            'statuses'   => $statuses,
            'machine_statuses' => Backlog_model::MACHINE_STATUSES,
            'page_title' => $item ? 'Edit Backlog Item' : 'New Backlog Item',
        ));
    }

    private function _save($item) {
        $in = $this->input->post(NULL, TRUE);
        $errors = array();

        $asset = trim((string)$this->_v($in, 'asset_label'));
        $equip = (int)$this->_v($in, 'equipment_id');
        if ($equip > 0) {
            $row = $this->db->select('registration_no')->from('equipment')
                            ->where('id', $equip)->get()->row();
            if ($row && $asset === '') $asset = $row->registration_no;
        } elseif ($asset !== '') {
            // free-text asset: try to attach it to a real machine
            $equip = (int)$this->Backlog_model->resolve_equipment($asset);
        }

        if ($asset === '')  $errors['asset_label'] = 'Choose the machine, or type the asset reference.';
        $start = $this->_v($in, 'start_date');
        if (!$this->_is_date($start)) $errors['start_date'] = 'A valid starting date is required.';
        $desc = trim((string)$this->_v($in, 'description'));
        if ($desc === '') $errors['description'] = 'Describe the defect.';
        $end = $this->_v($in, 'end_date');
        if ($end !== '' && $end !== null && !$this->_is_date($end)) {
            $errors['end_date'] = 'The end date is not a valid date.';
        }
        if (!$errors && $end && $start && $end < $start) {
            $errors['end_date'] = 'The end date cannot be before the starting date.';
        }
        $status = in_array($this->_v($in, 'status'), Backlog_model::STATUSES, true)
            ? $this->_v($in, 'status') : 'Open';
        $newlyClosing = ($status === 'Closed' && (!$item || $item->status !== 'Closed'));
        if ($newlyClosing) {
            if (!$this->is_supervisor()) {
                $errors['status'] = 'Closing a defect requires supervisor verification.';
            } elseif ($item && !empty($item->work_order_id) && $this->db->table_exists('work_orders')) {
                $wo = $this->db->select('id, wo_number, status')
                    ->where('id', (int)$item->work_order_id)->get('work_orders')->row();
                if ($wo && !in_array($wo->status, array('Completed', 'Closed'), TRUE)) {
                    $errors['status'] = 'Work order ' . $wo->wo_number . ' is still "' . $wo->status . '" — finish it first.';
                }
            }
        }
        if ($status === 'Closed' && !$end) {
            $end = date('Y-m-d');
        }

        if ($errors) {
            $this->session->set_flashdata('error', 'Please correct the highlighted fields.');
            return $this->_form($item, $errors, (object)$in);
        }

        $planned_date = $this->_v($in, 'planned_date');
        $estimated_duration = $this->_v($in, 'estimated_duration');

        $data = array(
            'equipment_id'    => $equip > 0 ? $equip : NULL,
            'asset_label'     => $asset,
            'model'           => $this->_v($in, 'model'),
            'serial_no'       => $this->_v($in, 'serial_no'),
            'start_odometer'  => $this->_v($in, 'start_odometer'),
            'start_date'      => $start,
            'description'     => $desc,
            'source'          => in_array($this->_v($in, 'source'), Backlog_model::SOURCES, true)
                                    ? $this->_v($in, 'source') : 'Other',
            'reported_by'     => $this->_v($in, 'reported_by'),
            'in_charge'       => $this->_v($in, 'in_charge'),
            'priority'        => in_array($this->_v($in, 'priority'), Backlog_model::PRIORITIES, true)
                                    ? $this->_v($in, 'priority') : NULL,
            'deadline'        => $this->_is_date($this->_v($in, 'deadline')) ? $this->_v($in, 'deadline') : NULL,
            'observation'     => $this->_v($in, 'observation'),
            'machine_status'  => in_array($this->_v($in, 'machine_status'), Backlog_model::MACHINE_STATUSES, true)
                                    ? $this->_v($in, 'machine_status') : NULL,
            'status'          => $status,
            'end_odometer'    => $this->_v($in, 'end_odometer'),
            'end_date'        => $end ?: NULL,
            'closing_remarks' => $this->_v($in, 'closing_remarks'),
            'mechanic'        => $this->_v($in, 'mechanic'),
            'supervisor'      => $this->_v($in, 'supervisor'),
            'planned_date'    => $this->_is_date($planned_date) ? $planned_date : NULL,
            'estimated_duration' => is_numeric($estimated_duration) ? (int)$estimated_duration : NULL,
        );
        if ($newlyClosing) {
            if ($this->db->field_exists('verified_by', 'backlogs')) {
                $data['verified_by']        = $this->user_id;
                $data['verified_at']        = date('Y-m-d H:i:s');
                $data['verification_notes'] = 'Verified on edit by supervisor.';
            }
            if ($this->db->field_exists('closed_by', 'backlogs')) {
                $data['closed_by'] = $this->user_id;
            }
        }

        if ($item) {
            $ok = $this->Backlog_model->update($item->id, $data);
            $msg = $ok ? 'Backlog item updated.' : 'The item could not be updated.';
            $saved_id = $item->id;
        } else {
            $saved_id = $this->Backlog_model->insert($data);
            $ok = $saved_id !== false;
            $msg = $ok ? 'Backlog item created.' : 'The item could not be created.';
        }
        
        if ($ok && $equip > 0 && $this->_is_date($planned_date) && is_numeric($estimated_duration)) {
            // Push to Maintenance Plan (AC-07, AC-08)
            $this->load->model('Maintenance_plan_model');
            $hours_per_day = round((int)$estimated_duration / 60, 2);
            $job_data = [
                'equipment_id'  => $equip,
                'start_date'    => $planned_date,
                'end_date'      => $planned_date,
                'hours_per_day' => $hours_per_day,
                'job_type'      => 'Repair',
                'category'      => 'Corrective',
                'title'         => 'Backlog: ' . mb_substr($desc, 0, 100),
                'status'        => 'Planned',
                'source'        => 'FromBacklog',
                'backlog_id'    => $saved_id
            ];
            
            // Check if job exists
            $existing_job = $this->db->where('backlog_id', $saved_id)->get('maintenance_plan_jobs')->row();
            if ($existing_job) {
                $this->Maintenance_plan_model->save_job($job_data, $this->user_id, $existing_job->id);
            } else {
                $this->Maintenance_plan_model->save_job($job_data, $this->user_id);
            }
        } elseif ($ok && $item) {
            // If it was removed or unset
            $existing_job = $this->db->where('backlog_id', $saved_id)->get('maintenance_plan_jobs')->row();
            if ($existing_job && (!$this->_is_date($planned_date) || !is_numeric($estimated_duration))) {
                $this->load->model('Maintenance_plan_model');
                $this->Maintenance_plan_model->delete_job($existing_job->id, $this->user_id);
            }
        }

        if (!$ok && $this->Backlog_model->last_error()) {
            $msg .= ' ' . $this->Backlog_model->last_error();
        }
        $this->session->set_flashdata($ok ? 'success' : 'error', $msg);
        if (!$ok) return $this->_form($item, array(), (object)$in);
        redirect('backlog');
    }

    private function _v($in, $k) {
        return isset($in[$k]) ? $in[$k] : '';
    }

    private function _is_date($s) {
        if (!$s) return false;
        $d = DateTime::createFromFormat('Y-m-d', $s);
        return $d && $d->format('Y-m-d') === $s;
    }
}
