<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Lubricants extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Lubricant_model');
        $this->load->model('Equipment_model');
    }

    /** Only Supervisor (1) and Storekeeper (3) may mutate lubricant records, specs & budgets. */
    protected function _can_lube_write() {
        return in_array((int)$this->role_id, array(1, 3), TRUE);
    }

    /** Common RBAC & currency context injected into every Lubricants view. */
    protected function _base_view_flags() {
        return array(
            'can_write'        => $this->_can_lube_write(),
            'can_manage_types' => $this->is_supervisor(),
            'can_view_reports' => in_array((int)$this->role_id, array(1, 4), TRUE),
            'currency_code'    => wms_site_currency(),
        );
    }

    /** Neutralize spreadsheet formula prefixes in both CSV and OpenXML XLSX cells. */
    protected function _csv_guard($val) {
        $s = (string)$val;
        if ($s !== '' && in_array($s[0], array('=', '+', '-', '@', "\t", "\r"), TRUE)) {
            return "'" . $s;
        }
        return $s;
    }

    /**
     * Build the filtered, sorted, machine-grouped, and optionally paginated
     * Lubricant Consumption dataset shared across index(), export_csv(), and export_xlsx().
     */
    protected function _build_ledger_dataset($paginate = TRUE) {
        $month = Lubricant_model::clean_month($this->input->get('month', TRUE));
        $ov    = $this->Lubricant_model->get_ledger_overview($month);
        $all   = $ov['rows'];

        $allowedTabs = array('all', 'over_variance', 'linked_wo', 'engine_hyd', 'unlinked');
        $tab = strtolower(trim((string)$this->input->get('tab', TRUE)));
        if (!in_array($tab, $allowedTabs, TRUE)) $tab = 'all';

        $qRaw = trim((string)$this->input->get('q', TRUE));
        if ($qRaw === '') {
            $qRaw = trim((string)$this->input->get('search', TRUE));
        }

        $eqFilter   = (int)$this->input->get('equipment_id');
        $lubeFilter = (int)$this->input->get('lube_type_id');
        $catFilter  = trim((string)$this->input->get('category', TRUE));

        $idsRaw = trim((string)$this->input->get('ids', TRUE));
        $idSet  = array();
        if ($idsRaw !== '') {
            foreach (explode(',', $idsRaw) as $tok) {
                $iv = (int)trim($tok);
                if ($iv > 0) $idSet[$iv] = TRUE;
            }
        }

        $tabCounts = array(
            'all'           => count($all),
            'over_variance' => 0,
            'linked_wo'     => 0,
            'engine_hyd'    => 0,
            'unlinked'      => 0,
        );
        foreach ($all as $r) {
            if ($r->variance !== NULL && (float)$r->variance > 0.0001) $tabCounts['over_variance']++;
            if (!empty($r->work_order_id)) $tabCounts['linked_wo']++;
            else $tabCounts['unlinked']++;
            if (in_array((string)$r->category, array('Engine Oil', 'Hydraulic Oil'), TRUE)) $tabCounts['engine_hyd']++;
        }

        $qLower = strtolower($qRaw);
        $filtered = array();
        foreach ($all as $c) {
            if (!empty($idSet) && !isset($idSet[(int)$c->id])) continue;

            if ($tab === 'over_variance' && !($c->variance !== NULL && (float)$c->variance > 0.0001)) continue;
            if ($tab === 'linked_wo' && empty($c->work_order_id)) continue;
            if ($tab === 'unlinked' && !empty($c->work_order_id)) continue;
            if ($tab === 'engine_hyd' && !in_array((string)$c->category, array('Engine Oil', 'Hydraulic Oil'), TRUE)) continue;

            if ($eqFilter > 0 && (int)$c->equipment_id !== $eqFilter) continue;
            if ($lubeFilter > 0 && (int)$c->lube_type_id !== $lubeFilter) continue;
            if ($catFilter !== '' && strcasecmp((string)$c->category, $catFilter) !== 0) continue;

            if ($qLower !== '') {
                $hay = strtolower(
                    (string)$c->registration_no . ' '
                    . (string)($c->brand ?? '') . ' '
                    . (string)($c->model ?? '') . ' '
                    . (string)$c->lube_name . ' '
                    . (string)($c->lube_grade ?? '') . ' '
                    . (string)$c->category . ' '
                    . (string)($c->wo_number ?? '') . ' '
                    . (string)($c->recorded_by_name ?? '') . ' '
                    . (string)($c->variance_reason ?? '') . ' '
                    . (string)($c->notes ?? '')
                );
                if (strpos($hay, $qLower) === FALSE) continue;
            }
            $filtered[] = $c;
        }

        $allowedSort = array('date', 'equipment', 'lube', 'qty', 'variance', 'cost');
        $sort = strtolower(trim((string)$this->input->get('sort', TRUE)));
        if (!in_array($sort, $allowedSort, TRUE)) $sort = 'date';
        $defaultDir = in_array($sort, array('date', 'qty', 'variance', 'cost'), TRUE) ? 'desc' : 'asc';
        $dirParam   = strtolower(trim((string)$this->input->get('dir', TRUE)));
        $dir        = in_array($dirParam, array('asc', 'desc'), TRUE) ? $dirParam : $defaultDir;
        $mul        = ($dir === 'asc') ? 1 : -1;

        usort($filtered, function ($a, $b) use ($sort, $mul) {
            switch ($sort) {
                case 'equipment':
                    $cmp = strcasecmp((string)$a->registration_no, (string)$b->registration_no);
                    break;
                case 'lube':
                    $cmp = strcasecmp((string)$a->lube_name, (string)$b->lube_name);
                    break;
                case 'qty':
                    $cmp = ((float)$a->quantity_used <=> (float)$b->quantity_used);
                    break;
                case 'variance':
                    $va = $a->variance !== NULL ? (float)$a->variance : -999999.0;
                    $vb = $b->variance !== NULL ? (float)$b->variance : -999999.0;
                    $cmp = ($va <=> $vb);
                    break;
                case 'cost':
                    $cmp = ((float)$a->line_cost <=> (float)$b->line_cost);
                    break;
                case 'date':
                default:
                    $cmp = strcmp((string)$a->consumption_date, (string)$b->consumption_date);
                    break;
            }
            if ($cmp === 0) {
                $cmp = ((int)$a->id <=> (int)$b->id);
            }
            return $cmp * $mul;
        });

        // Group filtered records by verbatim Fleet Asset (`registration_no`) for "By Machine" view
        $groupsMap = array();
        foreach ($filtered as $c) {
            $reg = (string)($c->registration_no ?: ('Asset #' . (int)$c->equipment_id));
            if (!isset($groupsMap[$reg])) {
                $groupsMap[$reg] = (object)array(
                    'registration_no' => $reg,
                    'model_label'     => trim((string)($c->brand ?? '') . ' ' . (string)($c->model ?? '')),
                    'records'         => array(),
                    'total_qty'       => 0.0,
                    'total_cost'      => 0.0,
                );
            }
            $g = $groupsMap[$reg];
            $g->records[]   = $c;
            $g->total_qty  += (float)$c->quantity_used;
            $g->total_cost += (float)$c->line_cost;
        }

        $viewMode = strtolower(trim((string)$this->input->get('view', TRUE)));
        if (!in_array($viewMode, array('table', 'groups', 'cards'), TRUE)) $viewMode = 'table';

        $allowedPP     = array(10, 25, 50, 100, 150);
        $perPage       = (int)$this->input->get('per_page');
        if (!in_array($perPage, $allowedPP, TRUE)) $perPage = 25;
        $totalFiltered = count($filtered);
        $totalPages    = max(1, (int)ceil($totalFiltered / $perPage));
        $page          = max(1, min((int)$this->input->get('page') ?: 1, $totalPages));
        $offset        = ($page - 1) * $perPage;
        $rows          = $paginate ? array_slice($filtered, $offset, $perPage) : $filtered;

        return array_merge($this->_base_view_flags(), array(
            'consumption'    => $rows,
            'groups'         => array_values($groupsMap),
            'summary'        => $ov['summary'],
            'compliance'     => $ov['compliance'],
            'kpi'            => $ov['kpi'],
            'lube_types'     => $ov['lube_types'],
            'equipment'      => $this->Equipment_model->get_all_active(),
            'tab_counts'     => $tabCounts,
            'tab'            => $tab,
            'q'              => $qRaw,
            'filter'         => array(
                'month'        => $month,
                'equipment_id' => $eqFilter > 0 ? $eqFilter : '',
                'lube_type_id' => $lubeFilter > 0 ? $lubeFilter : '',
                'category'     => $catFilter,
                'search'       => $qRaw,
            ),
            'sort'           => $sort,
            'dir'            => $dir,
            'view_mode'      => $viewMode,
            'per_page'       => $perPage,
            'allowed_pp'     => $allowedPP,
            'current_page'   => $page,
            'total_pages'    => $totalPages,
            'total_filtered' => $totalFiltered,
            'offset'         => $offset,
            'page_title'     => 'Lubricants & Consumables',
        ));
    }

    /* ── LEDGER BOARD ───────────────────────────────────── */
    public function index() {
        $data = $this->_build_ledger_dataset(TRUE);
        $this->render('lubricants/index', $data);
    }

    /* ── SLIDE-OVER CONSUMPTION DOSSIER JSON ────────────── */
    public function summary_json($id) {
        $row = $this->Lubricant_model->get_consumption_by_id((int)$id);
        if (!$row) {
            $this->json(array('ok' => FALSE, 'error' => 'Consumption record not found.'), 404);
            return;
        }
        $this->json(array(
            'ok'     => TRUE,
            'record' => array(
                'id'               => (int)$row->id,
                'consumption_date' => (string)$row->consumption_date,
                'equipment_id'     => (int)$row->equipment_id,
                'registration_no'  => (string)$row->registration_no,
                'machine_meta'     => trim((string)($row->brand ?? '') . ' ' . (string)($row->model ?? '')),
                'type_name'        => (string)($row->type_name ?: '—'),
                'equipment_hours'  => $row->equipment_hours !== NULL ? (float)$row->equipment_hours : NULL,
                'lube_name'        => (string)$row->lube_name,
                'lube_grade'       => (string)($row->lube_grade ?: '—'),
                'category'         => (string)$row->category,
                'unit'             => (string)$row->unit,
                'quantity_used'    => (float)$row->quantity_used,
                'recommended_qty'  => $row->recommended_qty !== NULL ? (float)$row->recommended_qty : NULL,
                'variance'         => $row->variance !== NULL ? (float)$row->variance : NULL,
                'variance_reason'  => (string)($row->variance_reason ?: '—'),
                'notes'            => (string)($row->notes ?: '—'),
                'unit_cost'        => (float)($row->unit_cost ?? 0),
                'line_cost'        => (float)$row->line_cost,
                'currency'         => wms_site_currency(),
                'work_order_id'    => $row->work_order_id ? (int)$row->work_order_id : NULL,
                'wo_number'        => (string)($row->wo_number ?: ''),
                'wo_title'         => (string)($row->wo_title ?: ''),
                'recorded_by_name' => trim((string)($row->recorded_by_name ?: 'System')),
                'can_write'        => $this->_can_lube_write(),
            ),
        ));
    }

    /* ── UTF-8 BOM CSV EXPORT (CONSUMPTION LEDGER) ──────── */
    public function export_csv() {
        $data = $this->_build_ledger_dataset(FALSE);
        $rows = $data['consumption'];
        $ccy  = $data['currency_code'];

        $filename = 'lubricants-' . $data['filter']['month'] . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array(
            'Record ID', 'Date', 'Fleet Asset', 'Machine Brand/Model', 'SMR Hours',
            'Lubricant', 'Grade', 'Category', 'Unit',
            'Qty Used', 'OEM Recommended', 'Variance',
            'Unit Cost (' . $ccy . ')', 'Line Cost (' . $ccy . ')',
            'Work Order', 'Recorded By', 'Variance Reason', 'Notes',
        ));

        foreach ($rows as $c) {
            fputcsv($out, array(
                (int)$c->id,
                (string)$c->consumption_date,
                $this->_csv_guard($c->registration_no ?? ''),
                $this->_csv_guard(trim((string)($c->brand ?? '') . ' ' . (string)($c->model ?? ''))),
                $c->equipment_hours !== NULL ? (float)$c->equipment_hours : '',
                $this->_csv_guard($c->lube_name ?? ''),
                $this->_csv_guard($c->lube_grade ?? ''),
                $this->_csv_guard($c->category ?? ''),
                $this->_csv_guard($c->unit ?? 'L'),
                number_format((float)$c->quantity_used, 2, '.', ''),
                $c->recommended_qty !== NULL ? number_format((float)$c->recommended_qty, 2, '.', '') : '',
                $c->variance !== NULL ? number_format((float)$c->variance, 2, '.', '') : '',
                number_format((float)($c->effective_unit_cost ?? 0), 2, '.', ''),
                number_format((float)($c->line_cost ?? 0), 2, '.', ''),
                $this->_csv_guard($c->wo_number ?? ''),
                $this->_csv_guard(trim((string)($c->recorded_by_name ?? ''))),
                $this->_csv_guard($c->variance_reason ?? ''),
                $this->_csv_guard($c->notes ?? ''),
            ));
        }
        fclose($out);
        exit;
    }

    /* ── STYLED OPENXML XLSX EXPORT (CONSUMPTION LEDGER) ── */
    public function export_xlsx() {
        $this->load->helper('xlsx');
        $data = $this->_build_ledger_dataset(FALSE);
        $rows = $data['consumption'];
        $ccy  = $data['currency_code'];

        $xlsx  = new XlsxWriter();
        $sHdr  = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|bg:1E3A8A|fg:FFFFFF|border:thin|wrap';
        $sCell = 'fontSize:10|fontName:Arial|valign:middle|border:thin|wrap';
        $sC    = 'fontSize:10|fontName:Arial|align:center|valign:middle|border:thin';
        $sOver = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|bg:FEE2E2|fg:991B1B';
        $sGood = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|bg:DCFCE7|fg:166534';
        foreach (array($sHdr, $sCell, $sC, $sOver, $sGood) as $st) {
            $xlsx->registerStyle($st);
        }

        $xlsx->addSheet('Lubricants ' . $data['filter']['month']);
        $widths = array(1=>10, 2=>13, 3=>16, 4=>12, 5=>20, 6=>12, 7=>16, 8=>8, 9=>12, 10=>12, 11=>12, 12=>14, 13=>15, 14=>18, 15=>24);
        foreach ($widths as $col => $w) {
            $xlsx->setColWidth($col, $w);
        }
        $xlsx->writeRow(array(
            'ID', 'DATE', 'FLEET ASSET', 'SMR HRS', 'LUBRICANT', 'GRADE', 'CATEGORY', 'UNIT',
            'QTY USED', 'OEM STD', 'VARIANCE', 'COST (' . $ccy . ')', 'WORK ORDER', 'RECORDED BY', 'VARIANCE REASON',
        ), $sHdr);
        $xlsx->freezePane('A2');
        $xlsx->setAutoFilter('A1:O1');

        foreach ($rows as $c) {
            $vStyle = ($c->variance !== NULL && (float)$c->variance > 0.0001) ? $sOver : ($c->variance !== NULL ? $sGood : $sC);
            $xlsx->writeRowStyled(array(
                array('v' => (int)$c->id, 's' => $sC),
                array('v' => (string)$c->consumption_date, 's' => $sC),
                array('v' => $this->_csv_guard($c->registration_no ?? ''), 's' => $sC),
                array('v' => $c->equipment_hours !== NULL ? (float)$c->equipment_hours : '', 's' => $sC),
                array('v' => $this->_csv_guard($c->lube_name ?? ''), 's' => $sCell),
                array('v' => $this->_csv_guard($c->lube_grade ?? ''), 's' => $sC),
                array('v' => $this->_csv_guard($c->category ?? ''), 's' => $sC),
                array('v' => $this->_csv_guard($c->unit ?? 'L'), 's' => $sC),
                array('v' => round((float)$c->quantity_used, 2), 's' => $sC),
                array('v' => $c->recommended_qty !== NULL ? round((float)$c->recommended_qty, 2) : '', 's' => $sC),
                array('v' => $c->variance !== NULL ? round((float)$c->variance, 2) : '', 's' => $vStyle),
                array('v' => round((float)($c->line_cost ?? 0), 2), 's' => $sC),
                array('v' => $this->_csv_guard($c->wo_number ?? ''), 's' => $sC),
                array('v' => $this->_csv_guard(trim((string)($c->recorded_by_name ?? ''))), 's' => $sCell),
                array('v' => $this->_csv_guard($c->variance_reason ?? ''), 's' => $sCell),
            ));
        }

        $fname = 'Lubricants_' . str_replace('-', '', $data['filter']['month']) . '_' . date('His') . '.xlsx';
        $tmp   = wms_temp_path($fname);
        $xlsx->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: max-age=0');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /* ── RECORD CONSUMPTION ─────────────────────────────── */
    public function record() {
        if (!$this->_can_lube_write()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('lubricants');
            return;
        }

        if ($this->input->method() === 'post') {
            $lube_type_id = (int)$this->input->post('lube_type_id', TRUE);
            $equipment_id = (int)$this->input->post('equipment_id', TRUE);
            $qty_used     = (float)$this->input->post('quantity_used', TRUE);
            $wo_id        = (int)$this->input->post('work_order_id', TRUE);

            $eq = $equipment_id > 0 ? $this->Equipment_model->get_by_id($equipment_id) : NULL;
            if (!$eq) {
                $this->session->set_flashdata('error', 'The selected equipment was not found.');
                redirect('lubricants/record');
                return;
            }

            $lt = $lube_type_id > 0 ? $this->Lubricant_model->get_type_by_id($lube_type_id) : NULL;
            if (!$lt) {
                $this->session->set_flashdata('error', 'The selected lubricant type was not found.');
                redirect('lubricants/record');
                return;
            }

            if ($qty_used <= 0) {
                $this->session->set_flashdata('error', 'Quantity used must be greater than zero.');
                redirect('lubricants/record');
                return;
            }

            if ($wo_id > 0) {
                $wo = $this->db->select('id, equipment_id')->where('id', $wo_id)->get('work_orders')->row();
                if (!$wo || (int)$wo->equipment_id !== $equipment_id) {
                    $this->session->set_flashdata('error', 'The selected work order does not belong to that machine.');
                    redirect('lubricants/record');
                    return;
                }
            }

            $dateRaw = trim((string)$this->input->post('consumption_date', TRUE));
            $cDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) ? $dateRaw : date('Y-m-d');
            $hrsRaw  = trim((string)$this->input->post('equipment_hours', TRUE));
            $eqHours = ($hrsRaw !== '' && is_numeric($hrsRaw)) ? max(0.0, (float)$hrsRaw) : NULL;

            $spec     = $this->Lubricant_model->get_spec($eq->equipment_type_id, $lube_type_id);
            $rec_qty  = $spec ? (float)$spec->recommended_qty : NULL;
            $variance = $rec_qty !== NULL ? round($qty_used - $rec_qty, 2) : NULL;

            $row = array(
                'work_order_id'    => $wo_id > 0 ? $wo_id : NULL,
                'equipment_id'     => $equipment_id,
                'lube_type_id'     => $lube_type_id,
                'quantity_used'    => round($qty_used, 2),
                'recommended_qty'  => $rec_qty,
                'variance'         => $variance,
                'variance_reason'  => trim((string)$this->input->post('variance_reason', TRUE)) ?: NULL,
                'consumption_date' => $cDate,
                'recorded_by'      => $this->user_id,
                'equipment_hours'  => $eqHours,
                'notes'            => trim((string)$this->input->post('notes', TRUE)) ?: NULL,
            );

            $this->db->insert('lube_consumption', $row);
            $this->session->set_flashdata('success', 'Consumption recorded.');
            redirect('lubricants?month=' . substr($cDate, 0, 7));
            return;
        }

        $data = array_merge($this->_base_view_flags(), array(
            'lube_types' => $this->Lubricant_model->get_all_types(),
            'equipment'  => $this->Equipment_model->get_all_active(),
            'open_wos'   => $this->db->select('w.id, w.wo_number, w.title, w.equipment_id, e.registration_no')
                ->from('work_orders w')
                ->join('equipment e', 'e.id = w.equipment_id', 'left')
                ->where_in('w.status', array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools'))
                ->order_by('w.created_at', 'DESC')->limit(300)->get()->result(),
            'page_title' => 'Record Consumption',
        ));
        $this->render('lubricants/record', $data);
    }

    /* ── LUBE TYPES MASTER ──────────────────────────────── */
    public function types() {
        if ($this->input->method() === 'post') {
            if (!$this->is_supervisor()) {
                $this->session->set_flashdata('error', 'Access denied.');
                redirect('lubricants/types');
                return;
            }
            $name = trim((string)$this->input->post('name', TRUE));
            if ($name === '') {
                $this->session->set_flashdata('error', 'Lubricant name is required.');
                redirect('lubricants/types');
                return;
            }
            $cost = trim((string)$this->input->post('unit_cost', TRUE));
            $row  = array(
                'name'        => $name,
                'category'    => Lubricant_model::clean_category($this->input->post('category', TRUE)),
                'grade'       => trim((string)$this->input->post('grade', TRUE)) ?: NULL,
                'unit'        => trim((string)$this->input->post('unit', TRUE)) ?: 'L',
                'unit_cost'   => ($cost !== '' && is_numeric($cost)) ? max(0.0, (float)$cost) : 0.0,
                'description' => trim((string)$this->input->post('description', TRUE)) ?: NULL,
            );
            $this->db->insert('lube_types', $row);
            $this->session->set_flashdata('success', 'Lube type added.');
            redirect('lubricants/types');
            return;
        }

        $data = array_merge($this->_base_view_flags(), array(
            'lube_types' => $this->Lubricant_model->get_all_types(),
            'categories' => Lubricant_model::$CATEGORIES,
            'page_title' => 'Lube Types Master',
        ));
        $this->render('lubricants/types', $data);
    }

    /* ── OEM FLUID SPECIFICATIONS ───────────────────────── */
    public function specifications() {
        if ($this->input->method() === 'post') {
            if (!$this->_can_lube_write()) {
                $this->session->set_flashdata('error', 'Access denied.');
                redirect('lubricants/specifications');
                return;
            }
            $eq_type_id   = (int)$this->input->post('equipment_type_id', TRUE);
            $lube_type_id = (int)$this->input->post('lube_type_id', TRUE);
            $eqType       = $eq_type_id > 0 ? $this->db->where('id', $eq_type_id)->get('equipment_types')->row() : NULL;
            $lubeType     = $lube_type_id > 0 ? $this->Lubricant_model->get_type_by_id($lube_type_id) : NULL;
            if (!$eqType || !$lubeType) {
                $this->session->set_flashdata('error', 'Valid equipment type and lubricant type are required.');
                redirect('lubricants/specifications');
                return;
            }

            $rec_raw = trim((string)$this->input->post('recommended_qty', TRUE));
            $int_raw = trim((string)$this->input->post('service_interval_hours', TRUE));
            $row = array(
                'equipment_type_id'      => $eq_type_id,
                'lube_type_id'           => $lube_type_id,
                'recommended_qty'        => ($rec_raw !== '' && is_numeric($rec_raw)) ? max(0.0, (float)$rec_raw) : 0.0,
                'service_interval_hours' => ($int_raw !== '' && is_numeric($int_raw)) ? max(0, (int)$int_raw) : NULL,
                'notes'                  => trim((string)$this->input->post('notes', TRUE)) ?: NULL,
            );
            $existing = $this->Lubricant_model->get_spec($eq_type_id, $lube_type_id);
            if ($existing) {
                $this->db->where('id', (int)$existing->id)->update('lube_specifications', $row);
            } else {
                $this->db->insert('lube_specifications', $row);
            }
            $this->session->set_flashdata('success', 'Specification saved.');
            redirect('lubricants/specifications');
            return;
        }

        $data = array_merge($this->_base_view_flags(), array(
            'specs'      => $this->Lubricant_model->get_all_specs(),
            'eq_types'   => $this->db->order_by('name', 'ASC')->get('equipment_types')->result(),
            'lube_types' => $this->Lubricant_model->get_all_types(),
            'page_title' => 'Lube Specifications',
        ));
        $this->render('lubricants/specifications', $data);
    }

    /* ── MONTHLY LUBRICANT BUDGET (SUPERVISOR & STORE ONLY) ── */
    public function budget() {
        if (!$this->_can_lube_write()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('lubricants');
            return;
        }

        if ($this->input->method() === 'post') {
            $year  = max(2000, min(2100, (int)$this->input->post('year', TRUE) ?: (int)date('Y')));
            $month = max(1, min(12, (int)$this->input->post('month', TRUE) ?: (int)date('m')));
            $validTypes = array();
            foreach ($this->Lubricant_model->get_all_types() as $lt) {
                $validTypes[(int)$lt->id] = TRUE;
            }

            $items = $this->input->post('budget');
            if (is_array($items)) {
                foreach ($items as $lube_id => $vals) {
                    $lid = (int)$lube_id;
                    if (!isset($validTypes[$lid]) || !is_array($vals)) continue;
                    $qty_raw  = isset($vals['qty']) ? trim((string)$vals['qty']) : '';
                    $cost_raw = isset($vals['cost']) ? trim((string)$vals['cost']) : '';
                    $qty      = ($qty_raw !== '' && is_numeric($qty_raw)) ? max(0.0, (float)$qty_raw) : 0.0;
                    $cost     = ($cost_raw !== '' && is_numeric($cost_raw)) ? max(0.0, (float)$cost_raw) : 0.0;
                    $this->db->replace('lube_budget', array(
                        'lube_type_id' => $lid,
                        'year'         => $year,
                        'month'        => $month,
                        'budget_qty'   => round($qty, 2),
                        'unit_cost'    => round($cost, 2),
                        'budget_cost'  => round($qty * $cost, 2),
                    ));
                }
            }
            $this->session->set_flashdata('success', 'Budget saved.');
            redirect('lubricants/budget?year=' . $year . '&month=' . $month);
            return;
        }

        $year  = max(2000, min(2100, (int)$this->input->get('year', TRUE) ?: (int)date('Y')));
        $month = max(1, min(12, (int)$this->input->get('month', TRUE) ?: (int)date('m')));
        $bOv   = $this->Lubricant_model->get_budget_overview($year, $month);

        $data = array_merge($this->_base_view_flags(), array(
            'lube_types'  => $bOv['lube_types'],
            'budget_data' => $bOv['budget_data'],
            'lines'       => $bOv['lines'],
            'totals'      => $bOv['totals'],
            'year'        => $bOv['year'],
            'month'       => $bOv['month'],
            'page_title'  => 'Lube Budget',
        ));
        $this->render('lubricants/budget', $data);
    }

    /* ── BUDGET CSV & XLSX EXPORTS ──────────────────────── */
    public function budget_csv() {
        if (!$this->_can_lube_write()) {
            show_error('Forbidden.', 403);
            return;
        }
        $year  = max(2000, min(2100, (int)$this->input->get('year', TRUE) ?: (int)date('Y')));
        $month = max(1, min(12, (int)$this->input->get('month', TRUE) ?: (int)date('m')));
        $bOv   = $this->Lubricant_model->get_budget_overview($year, $month);
        $ccy   = wms_site_currency();

        $filename = sprintf('lube-budget-%04d-%02d.csv', $year, $month);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array(
            'Lubricant', 'Grade', 'Category', 'Unit',
            'PM Required Qty', 'Budget Qty', 'Unit Cost (' . $ccy . ')', 'Budget Cost (' . $ccy . ')',
            'Actual MTD Qty', 'Actual MTD Cost (' . $ccy . ')', 'Remaining (' . $ccy . ')', 'Utilization %',
        ));
        foreach ($bOv['lines'] as $ln) {
            fputcsv($out, array(
                $this->_csv_guard($ln->name),
                $this->_csv_guard($ln->grade),
                $this->_csv_guard($ln->category),
                $this->_csv_guard($ln->unit),
                number_format((float)$ln->pm_req_qty, 2, '.', ''),
                number_format((float)($ln->budget_qty ?? 0), 2, '.', ''),
                number_format((float)$ln->unit_cost, 2, '.', ''),
                number_format((float)$ln->budget_cost, 2, '.', ''),
                number_format((float)$ln->actual_qty, 2, '.', ''),
                number_format((float)$ln->actual_cost, 2, '.', ''),
                number_format((float)$ln->remaining_cost, 2, '.', ''),
                $ln->utilization_pct !== NULL ? ($ln->utilization_pct . '%') : '',
            ));
        }
        fclose($out);
        exit;
    }

    public function budget_xlsx() {
        if (!$this->_can_lube_write()) {
            show_error('Forbidden.', 403);
            return;
        }
        $this->load->helper('xlsx');
        $year  = max(2000, min(2100, (int)$this->input->get('year', TRUE) ?: (int)date('Y')));
        $month = max(1, min(12, (int)$this->input->get('month', TRUE) ?: (int)date('m')));
        $bOv   = $this->Lubricant_model->get_budget_overview($year, $month);
        $ccy   = wms_site_currency();

        $xlsx  = new XlsxWriter();
        $sHdr  = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|bg:1E3A8A|fg:FFFFFF|border:thin|wrap';
        $sCell = 'fontSize:10|fontName:Arial|valign:middle|border:thin|wrap';
        $sC    = 'fontSize:10|fontName:Arial|align:center|valign:middle|border:thin';
        foreach (array($sHdr, $sCell, $sC) as $st) {
            $xlsx->registerStyle($st);
        }

        $xlsx->addSheet(sprintf('Budget %04d-%02d', $year, $month));
        foreach (array(1=>22, 2=>12, 3=>18, 4=>8, 5=>13, 6=>13, 7=>14, 8=>16, 9=>13, 10=>16, 11=>16) as $col => $w) {
            $xlsx->setColWidth($col, $w);
        }
        $xlsx->writeRow(array(
            'LUBRICANT', 'GRADE', 'CATEGORY', 'UNIT', 'PM REQ QTY', 'BUDGET QTY',
            'UNIT COST (' . $ccy . ')', 'BUDGET COST (' . $ccy . ')',
            'ACTUAL QTY', 'ACTUAL COST (' . $ccy . ')', 'REMAINING (' . $ccy . ')',
        ), $sHdr);
        $xlsx->freezePane('A2');
        $xlsx->setAutoFilter('A1:K1');

        foreach ($bOv['lines'] as $ln) {
            $xlsx->writeRowStyled(array(
                array('v' => $this->_csv_guard($ln->name), 's' => $sCell),
                array('v' => $this->_csv_guard($ln->grade), 's' => $sC),
                array('v' => $this->_csv_guard($ln->category), 's' => $sC),
                array('v' => $this->_csv_guard($ln->unit), 's' => $sC),
                array('v' => round((float)$ln->pm_req_qty, 2), 's' => $sC),
                array('v' => round((float)($ln->budget_qty ?? 0), 2), 's' => $sC),
                array('v' => round((float)$ln->unit_cost, 2), 's' => $sC),
                array('v' => round((float)$ln->budget_cost, 2), 's' => $sC),
                array('v' => round((float)$ln->actual_qty, 2), 's' => $sC),
                array('v' => round((float)$ln->actual_cost, 2), 's' => $sC),
                array('v' => round((float)$ln->remaining_cost, 2), 's' => $sC),
            ));
        }

        $fname = sprintf('Lube_Budget_%04d_%02d.xlsx', $year, $month);
        $tmp   = wms_temp_path($fname);
        $xlsx->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: max-age=0');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /* ── 2-MONTH PM CONSUMPTION FORECAST ────────────────── */
    public function forecast() {
        $data = array_merge($this->_base_view_flags(), array(
            'forecast'   => $this->Lubricant_model->get_monthly_forecast(),
            'lube_types' => $this->Lubricant_model->get_all_types(),
            'page_title' => 'Consumption Forecast',
        ));
        $this->render('lubricants/forecast', $data);
    }
}
