<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Spareparts — Pending Spare Parts Tracking & Availability Alerts.
 *
 * The board that answers:
 *   "Which pending maintenance jobs can now proceed because the required
 *    spare parts have arrived?"
 *
 * Read actions (index / alerts / summary_json / export_csv / export_xlsx) are
 * available to supervisor, technician, store and manager. Reserving or issuing
 * stock is limited to roles that can write (supervisor / technician / store) —
 * the Manager role stays view-only.
 */
class Spareparts extends MY_Controller {

    public $Spareparts_model;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Spareparts_model', 'Materials_model'));
        $this->Spareparts_model->materials = $this->Materials_model;
    }

    /** Neutralize spreadsheet formula prefixes in CSV cells. */
    protected function _csv_guard($val) {
        $s = (string)$val;
        if ($s !== '' && in_array($s[0], array('=', '+', '-', '@', "\t", "\r"), TRUE)) {
            return "'" . $s;
        }
        return $s;
    }

    /**
     * Build the filtered, sorted, grouped, and optionally paginated dataset
     * shared across index(), export_csv(), and export_xlsx().
     */
    protected function _build_board_dataset($paginate = TRUE) {
        $ov       = $this->Spareparts_model->get_board_overview($this->user_id, $this->role_id);
        $allLines = $ov['lines'];
        $readyMap = $ov['ready_wo_ids'];

        $allowedTabs = array('all', 'pending', 'available', 'allocated', 'unlinked', 'ready_wos');
        $tab = strtolower(trim((string)$this->input->get('tab', TRUE)));
        if (!in_array($tab, $allowedTabs, TRUE)) $tab = 'all';

        $qRaw = trim((string)$this->input->get('q', TRUE));
        if ($qRaw === '') {
            $qRaw = trim((string)$this->input->get('search', TRUE));
        }
        $statusF   = trim((string)$this->input->get('status', TRUE));
        if ($statusF === 'all') $statusF = '';
        $priorityF = trim((string)$this->input->get('priority', TRUE));
        $woStatusF = trim((string)$this->input->get('wo_status', TRUE));

        $idsRaw = trim((string)$this->input->get('ids', TRUE));
        $idSet  = array();
        if ($idsRaw !== '') {
            foreach (explode(',', $idsRaw) as $tok) {
                $iv = (int)trim($tok);
                if ($iv > 0) $idSet[$iv] = TRUE;
            }
        }

        $readyLineCnt = 0;
        foreach ($allLines as $al) {
            if (isset($readyMap[(int)$al->work_order_id])) $readyLineCnt++;
        }

        $qLower = strtolower($qRaw);
        $filtered = array();
        foreach ($allLines as $l) {
            if (!empty($idSet) && !isset($idSet[(int)$l->id])) continue;

            if ($tab === 'pending' && !in_array($l->avail_status, array('Pending', 'Partial'), TRUE)) continue;
            if ($tab === 'available' && $l->avail_status !== 'Available') continue;
            if ($tab === 'allocated' && $l->avail_status !== 'Allocated') continue;
            if ($tab === 'unlinked' && $l->avail_status !== 'Unlinked') continue;
            if ($tab === 'ready_wos' && !isset($readyMap[(int)$l->work_order_id])) continue;

            if ($statusF !== '' && strcasecmp((string)$l->avail_status, $statusF) !== 0) continue;
            if ($priorityF !== '' && strcasecmp((string)$l->priority, $priorityF) !== 0) continue;
            if ($woStatusF !== '' && strcasecmp((string)$l->wo_status, $woStatusF) !== 0) continue;

            if ($qLower !== '') {
                $matCode = $l->material ? (string)$l->material->item_code : (string)($l->item_code ?? '');
                $matDesc = $l->material ? (string)$l->material->description : '';
                $matPart = $l->material ? (string)($l->material->part_number_size ?? '') : '';
                $hay = strtolower(
                    (string)$l->wo_number . ' '
                    . (string)($l->wo_title ?? '') . ' '
                    . (string)($l->registration_no ?? '') . ' '
                    . (string)($l->part_name ?? '') . ' '
                    . (string)($l->part_number ?? '') . ' '
                    . $matCode . ' ' . $matDesc . ' ' . $matPart
                );
                if (strpos($hay, $qLower) === FALSE) continue;
            }
            $filtered[] = $l;
        }

        $prioRank = array('Critical' => 1, 'High' => 2, 'Medium' => 3, 'Low' => 4);
        $allowedSort = array('priority', 'wo_number', 'equipment', 'part_name', 'qty_required', 'qty_available', 'status');
        $sort = strtolower(trim((string)$this->input->get('sort', TRUE)));
        if (!in_array($sort, $allowedSort, TRUE)) $sort = 'priority';
        $dir = strtolower(trim((string)$this->input->get('dir', TRUE))) === 'desc' ? 'desc' : 'asc';
        $mul = ($dir === 'asc') ? 1 : -1;

        usort($filtered, function ($a, $b) use ($sort, $mul, $prioRank) {
            switch ($sort) {
                case 'wo_number':
                    $cmp = strcmp((string)$a->wo_number, (string)$b->wo_number);
                    break;
                case 'equipment':
                    $cmp = strcasecmp((string)($a->registration_no ?? ''), (string)($b->registration_no ?? ''));
                    break;
                case 'part_name':
                    $cmp = strcasecmp((string)$a->part_name, (string)$b->part_name);
                    break;
                case 'qty_required':
                    $cmp = ((float)$a->qty_required <=> (float)$b->qty_required);
                    break;
                case 'qty_available':
                    $cmp = ((float)$a->qty_available <=> (float)$b->qty_available);
                    break;
                case 'status':
                    $cmp = strcmp((string)$a->avail_status, (string)$b->avail_status);
                    break;
                case 'priority':
                default:
                    $ra = isset($prioRank[$a->priority]) ? $prioRank[$a->priority] : 9;
                    $rb = isset($prioRank[$b->priority]) ? $prioRank[$b->priority] : 9;
                    $cmp = ($ra <=> $rb);
                    break;
            }
            if ($cmp === 0) $cmp = strcmp((string)$a->wo_number, (string)$b->wo_number);
            if ($cmp === 0) $cmp = ((int)$a->id <=> (int)$b->id);
            return $cmp * $mul;
        });

        $filteredTotals = array(
            'qty_required'  => 0.0,
            'qty_available' => 0.0,
            'qty_allocated' => 0.0,
            'qty_shortfall' => 0.0,
        );
        foreach ($filtered as $fl) {
            $filteredTotals['qty_required']  += (float)$fl->qty_required;
            $filteredTotals['qty_available'] += (float)$fl->qty_available;
            $filteredTotals['qty_allocated'] += (float)$fl->qty_allocated;
            $filteredTotals['qty_shortfall'] += (float)$fl->qty_shortfall;
        }

        $viewRaw = strtolower(trim((string)$this->input->get('view', TRUE)));
        $view = in_array($viewRaw, array('table', 'groups', 'cards'), TRUE) ? $viewRaw : 'table';

        $allowedPerPage = array(10, 25, 50, 100, 150);
        $perPage = (int)$this->input->get('per_page');
        if (!in_array($perPage, $allowedPerPage, TRUE)) $perPage = 25;

        $totalFiltered = count($filtered);
        $totalPages    = max(1, (int)ceil($totalFiltered / $perPage));
        $reqPage       = max(1, min($totalPages, (int)$this->input->get('page') ?: 1));
        $offset        = ($reqPage - 1) * $perPage;
        $rowsOut       = $paginate ? array_slice($filtered, $offset, $perPage) : $filtered;

        // Build grouped-by-material view over the filtered lines for Store workflow
        $groups   = array();
        $unlinked = array();
        foreach ($filtered as $gl) {
            if (!$gl->material) {
                $unlinked[] = $gl;
                continue;
            }
            $key = (int)$gl->material->id;
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'material' => $gl->material,
                    'stock'    => $gl->stock_qty,
                    'free'     => $gl->free_qty,
                    'lines'    => array(),
                    'demand'   => 0.0,
                );
            }
            $groups[$key]['lines'][] = $gl;
            $groups[$key]['demand'] += max(0, $gl->qty_required - $gl->qty_allocated - $gl->qty_issued);
        }

        $sum = $ov['summary'];
        $tabCounts = array(
            'all'       => $sum['lines'],
            'pending'   => $sum['pending_lines'] - $sum['unlinked_lines'],
            'available' => $sum['available_lines'] - $sum['allocated_lines'],
            'allocated' => $sum['allocated_lines'],
            'unlinked'  => $sum['unlinked_lines'],
            'ready_wos' => $readyLineCnt,
        );

        return array(
            'lines'           => $rowsOut,
            'groups'          => $groups,
            'unlinked'        => $unlinked,
            'line_allocs'     => $ov['line_allocs'],
            'on_order'        => $ov['on_order'],
            'summary'         => $sum,
            'ready_wos'       => $ov['ready_wos'],
            'ready_wo_ids'    => $readyMap,
            'new_alerts'      => $ov['new_alerts'],
            'tab_counts'      => $tabCounts,
            'tab'             => $tab,
            'q'               => $qRaw,
            'filter'          => array(
                'status'    => $statusF,
                'priority'  => $priorityF,
                'wo_status' => $woStatusF,
                'search'    => $qRaw,
            ),
            'sort'            => $sort,
            'dir'             => $dir,
            'view_mode'       => $view,
            'per_page'        => $perPage,
            'allowed_pp'      => $allowedPerPage,
            'current_page'    => $reqPage,
            'total_pages'     => $totalPages,
            'total_filtered'  => $totalFiltered,
            'offset'          => $offset,
            'filtered_totals' => $filteredTotals,
            'can_write'       => $this->can_write(),
            'can_order'       => in_array((int)$this->role_id, array(1, 3), TRUE),
            'can_view_orders' => in_array((int)$this->role_id, array(1, 3, 4), TRUE),
            'statuses'        => array('Pending', 'Partial', 'Available', 'Allocated', 'Unlinked'),
            'priorities'      => array('Critical', 'High', 'Medium', 'Low'),
            'wo_statuses'     => Spareparts_model::$PENDING_WO_STATUSES,
        );
    }

    /* ── PENDING SPARE PARTS BOARD ─────────────────────── */
    public function index() {
        $data = $this->_build_board_dataset(TRUE);
        $data['page_title'] = 'Pending Spare Parts';
        $this->render('spareparts/index', $data);
    }

    /* ── JSON SUMMARY FOR SLIDE-OVER DEMAND DRAWER ──────── */
    public function summary_json($line_id = NULL) {
        $line_id = (int)$line_id;
        if ($line_id <= 0) {
            $this->json(array('ok' => FALSE, 'error' => 'Invalid spare-part demand ID.'), 404);
            return;
        }
        $ov = $this->Spareparts_model->get_board_overview($this->user_id, $this->role_id);
        $target = NULL;
        foreach ($ov['lines'] as $l) {
            if ((int)$l->id === $line_id) {
                $target = $l;
                break;
            }
        }
        if (!$target) {
            $this->json(array('ok' => FALSE, 'error' => 'Pending spare-part demand line not found.'), 404);
            return;
        }

        $allocList = array();
        if (isset($ov['line_allocs'][$line_id])) {
            foreach ($ov['line_allocs'][$line_id] as $ar) {
                $allocList[] = array(
                    'id'            => (int)$ar->id,
                    'qty_allocated' => (float)$ar->qty_allocated,
                    'status'        => (string)$ar->status,
                    'allocated_at'  => (string)($ar->allocated_at ?? ''),
                );
            }
        }

        $needed = max(0.0, round($target->qty_required - $target->qty_allocated - $target->qty_issued, 2));
        $maxAlloc = min($needed, (float)$target->free_qty);

        $this->json(array(
            'ok'          => TRUE,
            'line'        => array(
                'id'              => (int)$target->id,
                'work_order_id'   => (int)$target->work_order_id,
                'wo_number'       => (string)$target->wo_number,
                'wo_title'        => (string)($target->wo_title ?? ''),
                'wo_status'       => (string)$target->wo_status,
                'priority'        => (string)$target->priority,
                'registration_no' => (string)($target->registration_no ?: '—'),
                'part_name'       => (string)$target->part_name,
                'item_code'       => $target->material ? (string)$target->material->item_code : (string)($target->item_code ?: '—'),
                'part_number'     => (string)($target->part_number ?: ($target->material ? ($target->material->part_number_size ?: '—') : '—')),
                'bin_location'    => $target->material ? (string)($target->material->location ?: '—') : '—',
                'unit'            => (string)$target->item_unit,
                'avail_status'    => (string)$target->avail_status,
                'qty_required'    => (float)$target->qty_required,
                'qty_available'   => (float)$target->qty_available,
                'qty_allocated'   => (float)$target->qty_allocated,
                'qty_issued'      => (float)$target->qty_issued,
                'qty_shortfall'   => (float)$target->qty_shortfall,
                'stock_qty'       => (float)$target->stock_qty,
                'free_qty'        => (float)$target->free_qty,
                'on_order_qty'    => (float)$target->on_order_qty,
                'max_alloc_qty'   => round($maxAlloc, 2),
                'can_write'       => $this->can_write(),
                'can_order'       => in_array((int)$this->role_id, array(1, 3), TRUE),
            ),
            'allocations' => $allocList,
        ));
    }

    /* ── UTF-8 BOM CSV EXPORT ───────────────────────────── */
    public function export_csv() {
        $data = $this->_build_board_dataset(FALSE);
        $rows = $data['lines'];

        $filename = 'pending-spares-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array(
            'Work Order',
            'WO Status',
            'Priority',
            'Fleet Asset',
            'Item Code',
            'Part Description',
            'Part Number',
            'Unit',
            'Qty Required',
            'Stock On Hand',
            'Free Stock',
            'Qty Reserved',
            'Qty Coverable',
            'Shortfall',
            'On Open PO',
            'Availability Status',
        ));

        foreach ($rows as $l) {
            fputcsv($out, array(
                $this->_csv_guard($l->wo_number),
                $this->_csv_guard($l->wo_status),
                $this->_csv_guard($l->priority),
                $this->_csv_guard($l->registration_no ?? ''),
                $this->_csv_guard($l->material ? $l->material->item_code : ($l->item_code ?? '')),
                $this->_csv_guard($l->part_name),
                $this->_csv_guard($l->part_number ?? ''),
                $this->_csv_guard($l->item_unit),
                (float)$l->qty_required,
                (float)$l->stock_qty,
                (float)$l->free_qty,
                (float)$l->qty_allocated,
                (float)$l->qty_available,
                (float)$l->qty_shortfall,
                (float)$l->on_order_qty,
                $this->_csv_guard($l->avail_status),
            ));
        }
        fclose($out);
        exit;
    }

    /* ── STYLED OPENXML XLSX EXPORT ─────────────────────── */
    public function export_xlsx() {
        $this->load->helper('xlsx');
        $data = $this->_build_board_dataset(FALSE);
        $rows = $data['lines'];

        $xlsx  = new XlsxWriter();
        $sHdr  = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|bg:1E3A8A|fg:FFFFFF|border:thin|wrap';
        $sCell = 'fontSize:10|fontName:Arial|valign:middle|border:thin|wrap';
        $sC    = 'fontSize:10|fontName:Arial|align:center|valign:middle|border:thin';
        $sRed  = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|bg:FEE2E2|fg:991B1B';
        $sGrn  = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|bg:DCFCE7|fg:166534';
        foreach (array($sHdr, $sCell, $sC, $sRed, $sGrn) as $st) {
            $xlsx->registerStyle($st);
        }

        $xlsx->addSheet('Pending Spares');
        $widths = array(1=>16, 2=>15, 3=>12, 4=>15, 5=>16, 6=>28, 7=>16, 8=>10, 9=>12, 10=>12, 11=>12, 12=>12, 13=>12, 14=>14);
        foreach ($widths as $col => $w) {
            $xlsx->setColWidth($col, $w);
        }
        $xlsx->writeRow(array(
            'WORK ORDER', 'WO STATUS', 'PRIORITY', 'FLEET ASSET', 'ITEM CODE',
            'PART DESCRIPTION', 'PART NO', 'UNIT', 'REQUIRED', 'ON HAND',
            'RESERVED', 'SHORTFALL', 'ON PO', 'STATUS',
        ), $sHdr);
        $xlsx->freezePane('A2');
        $xlsx->setAutoFilter('A1:N1');

        foreach ($rows as $l) {
            $stStyle = in_array($l->avail_status, array('Available', 'Allocated'), TRUE) ? $sGrn : $sRed;
            $xlsx->writeRowStyled(array(
                array('v' => (string)$l->wo_number, 's' => $sC),
                array('v' => (string)$l->wo_status, 's' => $sC),
                array('v' => (string)$l->priority, 's' => $sC),
                array('v' => (string)($l->registration_no ?? ''), 's' => $sC),
                array('v' => (string)($l->material ? $l->material->item_code : ($l->item_code ?? '')), 's' => $sC),
                array('v' => (string)$l->part_name, 's' => $sCell),
                array('v' => (string)($l->part_number ?? ''), 's' => $sC),
                array('v' => (string)$l->item_unit, 's' => $sC),
                array('v' => (float)$l->qty_required, 's' => $sC),
                array('v' => (float)$l->stock_qty, 's' => $sC),
                array('v' => (float)$l->qty_allocated, 's' => $sC),
                array('v' => (float)$l->qty_shortfall, 's' => $sC),
                array('v' => (float)$l->on_order_qty, 's' => $sC),
                array('v' => (string)$l->avail_status, 's' => $stStyle),
            ));
        }

        $fname = 'Pending_Spares_' . date('Ymd_His') . '.xlsx';
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

    /* ── ALERTS (availability notifications) ───────────── */
    public function alerts() {
        $filter = array('status' => $this->input->get('status'));
        $data = array(
            'alerts'     => $this->Spareparts_model->get_alerts($filter, $this->user_id, $this->role_id),
            'filter'     => $filter,
            'new_count'  => $this->Spareparts_model->count_new_alerts($this->user_id, $this->role_id),
            'page_title' => 'Spare Part Availability Alerts',
        );
        $this->render('spareparts/alerts', $data);
    }

    public function mark_read($id) {
        if (!$this->require_post()) return;
        $this->Spareparts_model->mark_alert_read($id, $this->user_id, $this->role_id);
        redirect('spareparts/alerts');
    }

    public function mark_all_read() {
        if (!$this->require_post()) return;
        $n = $this->Spareparts_model->mark_all_alerts_read($this->user_id, $this->role_id);
        $this->session->set_flashdata('success', $n > 0 ? $n.' alert(s) marked as read.' : 'No new alerts.');
        redirect('spareparts/alerts');
    }

    /* ── ALLOCATE stock against a requested part ───────── */
    public function allocate($line_id) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'Only supervisor, technician or store users can allocate stock.');
            redirect('spareparts');
        }
        $res = $this->Spareparts_model->allocate($line_id, $this->input->post('qty'), $this->user_id);
        if (!empty($res['ok'])) {
            $this->session->set_flashdata('success', 'Stock allocated — the quantity is now reserved for this work order.');
        } else {
            $this->session->set_flashdata('error', isset($res['error']) ? $res['error'] : 'Allocation failed.');
        }
        redirect('spareparts');
    }

    /* ── RELEASE a reservation ─────────────────────────── */
    public function release($allocation_id) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'Only supervisor, technician or store users can release allocations.');
            redirect('spareparts');
        }
        if ($this->Spareparts_model->release_allocation($allocation_id, $this->user_id)) {
            $this->session->set_flashdata('success', 'Allocation released — the stock is available for other work orders again.');
        } else {
            $this->session->set_flashdata('error', 'The allocation could not be released (it may already have been issued).');
        }
        redirect('spareparts');
    }

    /* ── ISSUE allocated stock (physical hand-out) ─────── */
    public function issue($allocation_id) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'Only supervisor, technician or store users can issue stock.');
            redirect('spareparts');
        }
        $res = $this->Spareparts_model->issue_allocation($allocation_id, $this->user_id);
        if (!empty($res['ok'])) {
            $this->session->set_flashdata('success', 'Stock issued and inventory updated.');
        } else {
            $this->session->set_flashdata('error', isset($res['error']) ? $res['error'] : 'The issue could not be booked.');
        }
        redirect('spareparts');
    }
}
