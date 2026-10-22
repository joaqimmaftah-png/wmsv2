<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Orders extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Order_model');
        $this->load->model('Supplier_model');
        $this->load->model('Materials_model');
    }

    /** Escape leading formula characters in CSV cells. */
    protected function _csv_guard($val) {
        $s = (string)$val;
        if ($s !== '' && in_array($s[0], array('=', '+', '-', '@', "\t", "\r"), TRUE)) {
            return "'" . $s;
        }
        return $s;
    }

    /**
     * Build the filtered, sorted, and optionally paginated Purchase Orders
     * dataset shared across index(), export_csv(), and export_xlsx().
     */
    protected function _build_orders_dataset($paginate = TRUE) {
        $ov  = $this->Order_model->get_register_overview();
        $all = $ov['rows'];

        $allowedTabs = array('all', 'open', 'transit', 'overdue', 'discrepancy', 'closed');
        $tab = strtolower(trim((string)$this->input->get('tab', TRUE)));
        if (!in_array($tab, $allowedTabs, TRUE)) $tab = 'all';

        $qRaw = trim((string)$this->input->get('q', TRUE));
        if ($qRaw === '') {
            $qRaw = trim((string)$this->input->get('search', TRUE));
        }

        $statusF   = trim((string)$this->input->get('status', TRUE));
        $supplierF = (int)$this->input->get('supplier');
        $countryF  = trim((string)$this->input->get('country', TRUE));
        $methodF   = trim((string)$this->input->get('shipping_method', TRUE));

        $idsRaw = trim((string)$this->input->get('ids', TRUE));
        $idSet  = array();
        if ($idsRaw !== '') {
            foreach (explode(',', $idsRaw) as $tok) {
                $iv = (int)trim($tok);
                if ($iv > 0) $idSet[$iv] = TRUE;
            }
        }

        $qLower = strtolower($qRaw);
        $filtered = array();
        foreach ($all as $r) {
            if (!empty($idSet) && !isset($idSet[(int)$r->id])) continue;

            if ($tab === 'open' && in_array($r->status, array('Verified & Closed', 'Cancelled'), TRUE)) continue;
            if ($tab === 'transit' && !in_array($r->status, array('In Transit', 'Customs Clearance'), TRUE)) continue;
            if ($tab === 'overdue' && empty($r->is_overdue)) continue;
            if ($tab === 'discrepancy' && empty($r->has_discrepancy)) continue;
            if ($tab === 'closed' && !in_array($r->status, array('Received at Store', 'Verified & Closed'), TRUE)) continue;

            if ($statusF !== '' && (string)$r->status !== $statusF) continue;
            if ($supplierF > 0 && (int)$r->supplier_id !== $supplierF) continue;
            if ($countryF !== '' && strcasecmp((string)$r->country, $countryF) !== 0) continue;
            if ($methodF !== '' && strcasecmp((string)$r->shipping_method, $methodF) !== 0) continue;

            if ($qLower !== '') {
                $hay = strtolower(
                    (string)$r->order_no . ' '
                    . (string)($r->supplier_name ?? '') . ' '
                    . (string)($r->tracking_number ?? '') . ' '
                    . (string)($r->carrier ?? '') . ' '
                    . (string)($r->country ?? '') . ' '
                    . (string)($r->wo_number ?? '') . ' '
                    . (string)($r->equipment_code ?? '') . ' '
                    . (string)($r->notes ?? '') . ' '
                    . (string)($r->items_search_blob ?? '')
                );
                if (strpos($hay, $qLower) === FALSE) continue;
            }
            $filtered[] = $r;
        }

        $allowedSort = array('order_date', 'order_no', 'supplier', 'expected_delivery_date', 'fulfillment', 'status', 'total_cost');
        $sort = strtolower(trim((string)$this->input->get('sort', TRUE)));
        if (!in_array($sort, $allowedSort, TRUE)) $sort = 'order_date';
        $dir = strtolower(trim((string)$this->input->get('dir', TRUE))) === 'asc' ? 'asc' : 'desc';
        $mul = ($dir === 'asc') ? 1 : -1;

        usort($filtered, function ($a, $b) use ($sort, $mul) {
            switch ($sort) {
                case 'order_no':
                    $cmp = strcmp((string)$a->order_no, (string)$b->order_no);
                    break;
                case 'supplier':
                    $cmp = strcasecmp((string)($a->supplier_name ?? ''), (string)($b->supplier_name ?? ''));
                    break;
                case 'expected_delivery_date':
                    $cmp = strcmp((string)($a->expected_delivery_date ?? ''), (string)($b->expected_delivery_date ?? ''));
                    break;
                case 'fulfillment':
                    $cmp = ($a->fulfillment_pct <=> $b->fulfillment_pct);
                    break;
                case 'status':
                    $cmp = strcmp((string)$a->status, (string)$b->status);
                    break;
                case 'total_cost':
                    $cmp = ((float)$a->total_cost <=> (float)$b->total_cost);
                    break;
                case 'order_date':
                default:
                    $cmp = strcmp((string)$a->order_date, (string)$b->order_date);
                    break;
            }
            if ($cmp === 0) $cmp = ((int)$a->id <=> (int)$b->id);
            return $cmp * $mul;
        });

        $filteredTotals = array(
            'qty_ordered'  => 0.0,
            'qty_received' => 0.0,
            'by_currency'  => array(),
        );
        foreach ($filtered as $fr) {
            $filteredTotals['qty_ordered']  += (float)$fr->qty_ordered_sum;
            $filteredTotals['qty_received'] += (float)$fr->qty_received_sum;
            $ccy = !empty($fr->currency) ? strtoupper((string)$fr->currency) : 'TZS';
            if (!isset($filteredTotals['by_currency'][$ccy])) {
                $filteredTotals['by_currency'][$ccy] = 0.0;
            }
            $filteredTotals['by_currency'][$ccy] += (float)$fr->total_cost;
        }

        $view = strtolower(trim((string)$this->input->get('view', TRUE))) === 'cards' ? 'cards' : 'table';
        $allowedPerPage = array(10, 25, 50, 100, 150);
        $perPage = (int)$this->input->get('per_page');
        if (!in_array($perPage, $allowedPerPage, TRUE)) $perPage = 25;

        $totalFiltered = count($filtered);
        $totalPages    = max(1, (int)ceil($totalFiltered / $perPage));
        $reqPage       = max(1, min($totalPages, (int)$this->input->get('page') ?: 1));
        $offset        = ($reqPage - 1) * $perPage;
        $rowsOut       = $paginate ? array_slice($filtered, $offset, $perPage) : $filtered;

        $kpi = $ov['kpi'];
        $tabCounts = array(
            'all'         => $kpi['total_all'],
            'open'        => $kpi['open_orders'],
            'transit'     => $kpi['in_transit'],
            'overdue'     => $kpi['overdue'],
            'discrepancy' => $kpi['discrepancies'],
            'closed'      => $kpi['closed_orders'],
        );

        return array(
            'orders'          => $rowsOut,
            'suppliers'       => $this->Supplier_model->get_all(),
            'countries'       => $this->Supplier_model->get_countries(),
            'statuses'        => Order_model::$STATUSES,
            'methods'         => Order_model::$SHIPPING_METHODS,
            'kpi'             => $kpi,
            'tab_counts'      => $tabCounts,
            'tab'             => $tab,
            'q'               => $qRaw,
            'overdue'         => $ov['overdue'],
            'due_soon'        => $ov['due_soon'],
            'filter'          => array(
                'status'          => $statusF,
                'supplier'        => $supplierF > 0 ? (string)$supplierF : '',
                'country'         => $countryF,
                'shipping_method' => $methodF,
                'search'          => $qRaw,
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
            'is_supervisor'   => $this->is_supervisor(),
        );
    }

    /* ── DASHBOARD / LIST ───────────────────────────────── */
    public function index() {
        $data = $this->_build_orders_dataset(TRUE);
        $data['page_title'] = 'Spare Parts Orders';
        $this->render('orders/index', $data);
    }

    /* ── JSON SUMMARY FOR SLIDE-OVER INSPECTOR DRAWER ───── */
    public function summary_json($id = NULL) {
        $id = (int)$id;
        if ($id <= 0) {
            $this->json(array('ok' => FALSE, 'error' => 'Invalid purchase order ID.'), 404);
            return;
        }
        $order = $this->Order_model->get_by_id($id);
        if (!$order) {
            $this->json(array('ok' => FALSE, 'error' => 'Purchase order not found.'), 404);
            return;
        }

        $woMeta = NULL;
        if (!empty($order->linked_wo_id) && $this->db->table_exists('work_orders')) {
            $woRow = $this->db
                ->select('wo.id, wo.wo_number, wo.title, e.id AS equipment_id, e.registration_no')
                ->from('work_orders wo')
                ->join('equipment e', 'e.id = wo.equipment_id', 'left')
                ->where('wo.id', (int)$order->linked_wo_id)
                ->get()->row();
            if ($woRow) {
                $woMeta = array(
                    'id'              => (int)$woRow->id,
                    'wo_number'       => (string)$woRow->wo_number,
                    'title'           => (string)($woRow->title ?? ''),
                    'equipment_id'    => $woRow->equipment_id ? (int)$woRow->equipment_id : NULL,
                    'registration_no' => (string)($woRow->registration_no ?? ''),
                );
            }
        }

        $itemsRaw = $this->Order_model->get_receive_state($id);
        $items    = array();
        $outTotal = 0.0;
        foreach ($itemsRaw as $it) {
            $outTotal += (float)$it->qty_outstanding;
            $items[] = array(
                'id'            => (int)$it->id,
                'description'   => (string)$it->description,
                'part_number'   => (string)($it->part_number ?: '—'),
                'unit'          => (string)($it->unit ?: 'pcs'),
                'qty_ordered'   => (float)$it->qty_ordered,
                'qty_received'  => (float)$it->qty_previously_received,
                'qty_outstanding' => (float)$it->qty_outstanding,
                'unit_price'    => $it->unit_price !== NULL ? (float)$it->unit_price : NULL,
            );
        }

        $receiptsRaw = $this->Order_model->get_receipts($id);
        $receipts    = array();
        foreach ($receiptsRaw as $gr) {
            $receipts[] = array(
                'id'               => (int)$gr->id,
                'grn_no'           => (string)$gr->grn_no,
                'receipt_date'     => (string)$gr->receipt_date,
                'received_by'      => trim((string)(($gr->first_name ?? '') . ' ' . ($gr->last_name ?? ''))) ?: 'Store',
                'discrepancy_flag' => (string)$gr->discrepancy_flag,
            );
        }

        $canReceive = $this->can_write()
            && $outTotal > 0.0001
            && !in_array($order->status, array('Cancelled', 'Verified & Closed'), TRUE);

        $this->json(array(
            'ok'       => TRUE,
            'order'    => array(
                'id'                     => (int)$order->id,
                'order_no'               => (string)$order->order_no,
                'status'                 => (string)$order->status,
                'supplier_id'            => (int)$order->supplier_id,
                'supplier_name'          => (string)$order->supplier_name,
                'country'                => (string)$order->country,
                'shipping_method'        => (string)$order->shipping_method,
                'order_date'             => (string)$order->order_date,
                'expected_delivery_date' => (string)($order->expected_delivery_date ?: '—'),
                'actual_delivery_date'   => (string)($order->actual_delivery_date ?: '—'),
                'tracking_number'        => (string)($order->tracking_number ?: '—'),
                'carrier'                => (string)($order->carrier ?: '—'),
                'currency'               => (string)$order->currency,
                'total_cost'             => (float)$order->total_cost,
                'discrepancy_flag'       => (string)$order->discrepancy_flag,
                'discrepancy_notes'      => (string)($order->discrepancy_notes ?: ''),
                'notes'                  => (string)($order->notes ?: '—'),
                'linked_wo'              => $woMeta,
                'can_write'              => $this->can_write(),
                'can_receive'            => $canReceive,
            ),
            'items'    => $items,
            'receipts' => $receipts,
        ));
    }

    /* ── UTF-8 BOM CSV EXPORT ───────────────────────────── */
    public function export_csv() {
        $data = $this->_build_orders_dataset(FALSE);
        $rows = $data['orders'];

        $filename = 'spare-orders-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array(
            'Order No',
            'Order Date',
            'Status',
            'Supplier',
            'Origin Country',
            'Shipping Method',
            'Carrier',
            'Tracking Number',
            'Expected Delivery',
            'Actual Delivery',
            'Linked Work Order',
            'Fleet Asset',
            'Line Count',
            'Qty Ordered',
            'Qty Received',
            'Qty Outstanding',
            'Fulfillment %',
            'Currency',
            'Total Cost',
            'Discrepancy',
            'Notes',
        ));

        foreach ($rows as $r) {
            fputcsv($out, array(
                $this->_csv_guard($r->order_no),
                (string)$r->order_date,
                $this->_csv_guard($r->status),
                $this->_csv_guard($r->supplier_name ?? ''),
                $this->_csv_guard($r->country ?? ''),
                $this->_csv_guard($r->shipping_method ?? ''),
                $this->_csv_guard($r->carrier ?? ''),
                $this->_csv_guard($r->tracking_number ?? ''),
                (string)($r->expected_delivery_date ?? ''),
                (string)($r->actual_delivery_date ?? ''),
                $this->_csv_guard($r->wo_number ?? ''),
                $this->_csv_guard($r->equipment_code ?? ''),
                (int)$r->line_count,
                (float)$r->qty_ordered_sum,
                (float)$r->qty_received_sum,
                (float)$r->qty_outstanding_sum,
                (int)$r->fulfillment_pct,
                $this->_csv_guard($r->currency ?? 'TZS'),
                number_format((float)$r->total_cost, 2, '.', ''),
                $this->_csv_guard($r->discrepancy_flag ?? 'None'),
                $this->_csv_guard($r->notes ?? ''),
            ));
        }
        fclose($out);
        exit;
    }

    /* ── STYLED OPENXML XLSX EXPORT ─────────────────────── */
    public function export_xlsx() {
        $this->load->helper('xlsx');
        $data = $this->_build_orders_dataset(FALSE);
        $rows = $data['orders'];

        $xlsx  = new XlsxWriter();
        $sHdr  = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|bg:1E3A8A|fg:FFFFFF|border:thin|wrap';
        $sCell = 'fontSize:10|fontName:Arial|valign:middle|border:thin|wrap';
        $sC    = 'fontSize:10|fontName:Arial|align:center|valign:middle|border:thin';
        $sLate = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|bg:FEE2E2|fg:991B1B';
        $sDone = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|bg:DCFCE7|fg:166534';
        foreach (array($sHdr, $sCell, $sC, $sLate, $sDone) as $st) {
            $xlsx->registerStyle($st);
        }

        $xlsx->addSheet('Spare Orders');
        $widths = array(1=>16, 2=>13, 3=>18, 4=>26, 5=>15, 6=>16, 7=>18, 8=>13, 9=>15, 10=>15, 11=>10, 12=>12, 13=>12, 14=>12, 15=>10, 16=>15, 17=>14);
        foreach ($widths as $col => $w) {
            $xlsx->setColWidth($col, $w);
        }
        $xlsx->writeRow(array(
            'ORDER NO', 'ORDER DATE', 'STATUS', 'SUPPLIER', 'COUNTRY', 'SHIPPING',
            'TRACKING NO', 'EXPECTED ETA', 'LINKED WO', 'FLEET ASSET',
            'LINES', 'ORDERED', 'RECEIVED', 'OUTSTANDING', 'CCY', 'TOTAL COST', 'DISCREPANCY',
        ), $sHdr);
        $xlsx->freezePane('A2');
        $xlsx->setAutoFilter('A1:Q1');

        foreach ($rows as $r) {
            $stStyle = !empty($r->is_overdue)
                ? $sLate
                : (in_array($r->status, array('Received at Store', 'Verified & Closed'), TRUE) ? $sDone : $sC);
            $xlsx->writeRowStyled(array(
                array('v' => (string)$r->order_no, 's' => $sC),
                array('v' => (string)$r->order_date, 's' => $sC),
                array('v' => (string)$r->status, 's' => $stStyle),
                array('v' => (string)($r->supplier_name ?? ''), 's' => $sCell),
                array('v' => (string)($r->country ?? ''), 's' => $sC),
                array('v' => (string)($r->shipping_method ?? ''), 's' => $sC),
                array('v' => (string)($r->tracking_number ?? ''), 's' => $sCell),
                array('v' => (string)($r->expected_delivery_date ?? ''), 's' => $sC),
                array('v' => (string)($r->wo_number ?? ''), 's' => $sC),
                array('v' => (string)($r->equipment_code ?? ''), 's' => $sC),
                array('v' => (int)$r->line_count, 's' => $sC),
                array('v' => (float)$r->qty_ordered_sum, 's' => $sC),
                array('v' => (float)$r->qty_received_sum, 's' => $sC),
                array('v' => (float)$r->qty_outstanding_sum, 's' => $sC),
                array('v' => (string)($r->currency ?? 'TZS'), 's' => $sC),
                array('v' => (float)$r->total_cost, 's' => $sC),
                array('v' => (string)($r->discrepancy_flag ?? 'None'), 's' => $sC),
            ));
        }

        $fname = 'Spare_Orders_' . date('Ymd_His') . '.xlsx';
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

    /* ── CREATE ─────────────────────────────────────────── */
    public function create() {
        if (!$this->can_write()) {
            show_error('Forbidden.', 403);
            return;
        }
        if ($this->input->method() === 'post') {
            $supplier = $this->Supplier_model->get_by_id((int)$this->input->post('supplier_id', TRUE));
            // A crafted/stale supplier_id used to dereference null two lines
            // later — a 500 instead of a message (K-10).
            if (!$supplier) {
                $this->session->set_flashdata('error', 'The selected supplier was not found. The order was not created.');
                redirect('orders/create');
                return;
            }
            $shipping_method = $this->input->post('shipping_method', TRUE);
            $order_date = $this->input->post('order_date', TRUE) ?: date('Y-m-d');

            $lead = $this->Order_model->get_estimated_lead_time($supplier->country, $shipping_method, $supplier->id);
            $expected = $this->input->post('expected_delivery_date', TRUE)
                ?: date('Y-m-d', strtotime($order_date.' +'.$lead['days'].' days'));

            $raw_wo_id = (int) $this->input->post('linked_wo_id', TRUE);
            $linked_wo_id = NULL;
            if ($raw_wo_id > 0) {
                $this->load->model('Workorder_model');
                if (!$this->Workorder_model->get_by_id($raw_wo_id)) {
                    $this->session->set_flashdata('error', 'The selected work order was not found. The order was not created.');
                    redirect('orders/create');
                    return;
                }
                $linked_wo_id = $raw_wo_id;
            }

            $header = array(
                'supplier_id'             => $supplier->id,
                'country'                 => $supplier->country,
                'shipping_method'         => $shipping_method,
                'order_date'              => $order_date,
                'expected_delivery_date'  => $expected,
                'status'                  => 'Pending Approval',
                'currency'                => wms_currency_code(
                    $this->input->post('currency', TRUE),
                    !empty($supplier->currency) ? $supplier->currency : wms_site_currency()
                ),
                'total_cost'              => $this->input->post('total_cost', TRUE) ?: 0,
                'linked_wo_id'            => $linked_wo_id,
                'requested_by'            => $this->user_id,
                'notes'                   => $this->input->post('notes', TRUE),
            );

            $items = array();
            $descriptions = $this->input->post('item_description');
            if ($descriptions) {
                $part_numbers = $this->input->post('item_part_number');
                $material_ids = $this->input->post('item_material_id');
                $units        = $this->input->post('item_unit');
                $qtys         = $this->input->post('item_qty');
                $prices       = $this->input->post('item_price');
                foreach ($descriptions as $i => $desc) {
                    if (empty($desc)) continue;
                    $mid = !empty($material_ids[$i]) ? (int) $material_ids[$i] : 0;
                    if ($mid > 0 && !$this->Materials_model->get_by_id($mid)) {
                        $mid = 0;
                    }
                    $items[] = array(
                        'material_id' => $mid > 0 ? $mid : NULL,
                        'description' => $desc,
                        'part_number' => isset($part_numbers[$i]) ? $part_numbers[$i] : '',
                        'unit'        => !empty($units[$i]) ? $units[$i] : 'pcs',
                        'qty_ordered' => !empty($qtys[$i]) ? $qtys[$i] : 0,
                        'unit_price'  => !empty($prices[$i]) ? $prices[$i] : NULL,
                    );
                }
            }

            if (empty($items)) {
                $this->session->set_flashdata('error', 'Please add at least one line item before submitting the order.');
                redirect('orders/create');
                return;
            }

            $order_id = $this->Order_model->create($header, $items, $this->user_id);
            if (!$order_id) {
                $this->session->set_flashdata('error', 'The order could not be saved. Please check your entries and try again.');
                redirect('orders/create');
                return;
            }
            $this->session->set_flashdata('success', 'Order created — lead time estimated from '.$lead['source'].' ('.$lead['days'].' days).');
            redirect('orders/view/'.$order_id);
        }

        $data['suppliers']  = $this->Supplier_model->get_all(array('status' => 'Active'));
        $data['materials']  = $this->Materials_model->get_all();
        $data['methods']    = Order_model::$SHIPPING_METHODS;
        $data['page_title'] = 'New Spare Parts Order';
        $this->render('orders/form', $data);
    }

    /* ── VIEW ───────────────────────────────────────────── */
    public function view($id) {
        $order = $this->Order_model->get_by_id($id);
        if (!$order) show_404();

        $data['order']     = $order;
        // The items carry the two numbers the receive flow added —
        // delivered so far, and still outstanding.
        $data['items']     = $this->Order_model->get_receive_state($id);
        $data['receipts']  = $this->Order_model->get_receipts($id);        // one row per delivery
        $data['history']   = $this->Order_model->get_status_history($id);
        $data['documents'] = $this->Order_model->get_documents($id);
        $data['statuses']  = Order_model::$STATUSES;
        $data['next_statuses']     = Order_model::next_statuses($order->status);
        $data['lead']              = $this->Order_model->get_estimated_lead_time($order->country, $order->shipping_method, $order->supplier_id);
        $data['can_write']         = $this->can_write();
        $data['can_update_status'] = ($this->can_write() || $this->is_manager());
        $data['page_title']        = 'Order '.$order->order_no;
        $this->render('orders/view', $data);
    }

    /* ── STATUS CHANGE ──────────────────────────────────── */
    public function update_status($id) {
        if (!$this->require_post()) return;
        // Honour MY_Controller::MANAGER_WRITE_WHITELIST ('orders/update_status'):
        // Supervisor (1), Store (3), and Manager (4) may advance/approve status.
        if (!$this->can_write() && !$this->is_manager()) {
            show_error('Forbidden.', 403);
            return;
        }
        if (!$this->db->trans_begin()) {
            $this->session->set_flashdata('error', 'The order status change could not be started.');
            redirect('orders/view/'.$id);
            return;
        }
        $locked_order = $this->db->query(
            'SELECT id FROM '.$this->db->dbprefix('spare_orders').' WHERE id = ? FOR UPDATE',
            array((int)$id)
        );
        if (!$locked_order || !$locked_order->row()) {
            $this->db->trans_rollback();
            show_404();
            return;
        }
        $order = $this->Order_model->get_by_id($id);
        if (!$order) {
            $this->db->trans_rollback();
            show_404();
            return;
        }

        $status = $this->input->post('status', TRUE);
        $notes  = $this->input->post('notes', TRUE);

        // Guided pipeline (Wave 3): only the next step the process allows.
        // Recording a delivery is not a status change — that happens on the
        // receiving screen, which writes the goods receipt with it.
        if (!Order_model::can_transition($order->status, $status)) {
            $this->db->trans_rollback();
            $allowed = Order_model::next_statuses($order->status);
            $this->session->set_flashdata('error',
                'An order that is "'.$order->status.'" cannot go straight to "'.$status.'". '
                .(empty($allowed)
                    ? 'This order is finished — its status no longer changes.'
                    : 'The next step is: '.implode(', ', $allowed).'. Receiving goods is done on the receive screen.')
                .' Nothing was changed.');
            redirect('orders/view/'.$id);
            return;
        }

        $extra = array();
        if ($this->input->post('tracking_number', TRUE) !== null && $this->input->post('tracking_number', TRUE) !== '') {
            $extra['tracking_number'] = $this->input->post('tracking_number', TRUE);
        }
        if ($this->input->post('carrier', TRUE) !== null && $this->input->post('carrier', TRUE) !== '') {
            $extra['carrier'] = $this->input->post('carrier', TRUE);
        }
        if ($status === 'Received at Store' && empty($order->actual_delivery_date)) {
            $extra['actual_delivery_date'] = date('Y-m-d');
        }
        if (!empty($extra)) $this->Order_model->update_header($id, $extra);

        $this->Order_model->set_status($id, $status, $this->user_id, $notes);
        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'The order status change could not be saved. No changes were retained.');
            redirect('orders/view/'.$id);
            return;
        }
        if (!$this->db->trans_commit()) {
            $this->db->trans_rollback();
            $this->session->set_flashdata('error', 'The order status change could not be committed. No changes were retained.');
            redirect('orders/view/'.$id);
            return;
        }
        $this->session->set_flashdata('success', 'Order status updated to "'.$status.'".');
        redirect('orders/view/'.$id);
    }

    /* ── RECEIVE — one delivery, one GRN ──────────────────
       The screen posts what arrived in *this* delivery. Nothing is
       overwritten: the delivered quantity is added to the item's running
       total, and the stock posting goes through the single stock writer —
       which carries an import_key, so the same GRN line can never be posted
       to the ledger twice (audit finding F-05).

       Rules enforced here
         • only what is still outstanding can be received (no over-receipt);
         • a delivery of nothing is not a delivery;
         • cancelled and closed orders receive nothing;
         • stock is counted in whole units, so a fractional quantity for a
           catalogued item is refused with an explanation instead of being
           silently rounded into the balance.
    */
    public function receive($id) {
        if (!$this->can_write()) {
            show_error('Forbidden.', 403);
            return;
        }
        $order = $this->Order_model->get_by_id($id);
        if (!$order) show_404();

        if (!$this->Order_model->receipts_ready()) {
            $this->session->set_flashdata('error',
                'The goods-receipt tables are not installed yet. Import the database dump '
                .'shipped with this repository (database/schema.sql), then receive the order again. Nothing was saved.');
            redirect('orders/view/'.$id);
            return;
        }

        if (in_array($order->status, array('Cancelled', 'Verified & Closed'), TRUE)) {
            $this->session->set_flashdata('error',
                'Order '.$order->order_no.' is "'.$order->status.'" — it cannot receive more goods.');
            redirect('orders/view/'.$id);
            return;
        }

        if ($this->input->method() === 'post') {
            $this->load->model('Spareparts_model');

            if (!$this->db->trans_begin()) {
                $this->session->set_flashdata('error', 'The receipt transaction could not be started. No changes were saved.');
                redirect('orders/receive/'.$id);
                return;
            }

            // Serialize receipt submissions for this order and keep every write
            // below in the same transaction.
            $locked_order = $this->db->query(
                'SELECT id FROM '.$this->db->dbprefix('spare_orders').' WHERE id = ? FOR UPDATE',
                array((int)$id)
            );
            if (!$locked_order || !$locked_order->row()) {
                $this->db->trans_rollback();
                show_404();
                return;
            }

            // Re-read inside the lock: the status checked above could have
            // changed between the GET and this POST.
            $order = $this->Order_model->get_by_id($id);
            if (!$order || in_array($order->status, array('Cancelled', 'Verified & Closed'), TRUE)) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'This order cannot receive goods. Nothing was saved.');
                redirect('orders/view/'.$id);
                return;
            }

            /*
             * One-time token of the rendered form. A replayed submit (double
             * click, browser re-post) carries the same token and is refused
             * instead of creating a second GRN for the same delivery.
             */
            $token = (string)$this->input->post('submit_token', TRUE);
            if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error',
                    'This receive form has expired — reopen the page and enter the delivery again. Nothing was saved.');
                redirect('orders/receive/'.$id);
                return;
            }

            $posted = $this->input->post('qty_delivery');          // [item_id => qty]
            $posted = is_array($posted) ? $posted : array();

            $flag  = $this->input->post('discrepancy_flag', TRUE) ?: 'None';
            $notes = $this->input->post('discrepancy_notes', TRUE);
            $date  = date('Y-m-d');

            $items = $this->Order_model->get_receive_state($id);
            $lines = array();
            $matched_wos = array();
            $problem = null;

            foreach ($items as $it) {
                $qty = isset($posted[$it->id]) ? $posted[$it->id] : 0;
                if ($qty === '' || $qty === null) $qty = 0;
                if (!is_numeric($qty)) {
                    $problem = 'Line "'.mb_substr($it->description, 0, 60).'": the quantity must be a number.';
                    break;
                }
                $qty = round((float)$qty, 2);

                if ($qty < 0) {
                    $problem = 'Line "'.mb_substr($it->description, 0, 60).'": a delivery cannot be negative.';
                    break;
                }
                if ($qty > (float)$it->qty_outstanding + 0.0001) {
                    $problem = 'Line "'.mb_substr($it->description, 0, 60).'": '.number_format((float)($qty), 2)
                        .' is more than the '.number_format((float)($it->qty_outstanding), 2).' still outstanding.';
                    break;
                }
                if ($qty == 0) continue;

                // Stock is counted in whole units: refuse rather than round.
                if ($it->material_id && abs($qty - round($qty)) > 0.0001) {
                    $problem = 'Line "'.mb_substr($it->description, 0, 60).'": stock is counted in whole units, so '
                        .number_format((float)($qty), 2).' cannot be posted to '.$it->unit.'. Split the delivery or correct the unit.';
                    break;
                }

                $movement_id = null;

                if ($it->material_id) {
                    $context = array(
                        'txn_date'      => $date,
                        'reference_no'  => $order->order_no,
                        'person'        => null,
                        'supplier_id'   => $order->supplier_id,
                        'supplier_name' => isset($order->supplier_name) ? $order->supplier_name : null,
                        'unit_price'    => $it->unit_price,
                        'currency'      => $order->currency,
                        'source'        => 'Order GRN',
                        // One key per submitted line of this delivery: the
                        // stock writer refuses to post the same form twice.
                        'import_key'    => 'grn:'.$order->order_no.':'.$token.':item:'.$it->id,
                    );
                    $move = $this->Materials_model->add_stock_movement(
                        $it->material_id, 'RECEIVE', $qty,
                        'Goods receipt '.$order->order_no.' — '.mb_substr($it->description, 0, 120),
                        $this->user_id, $context
                    );
                    if ($move === false) {
                        $problem = 'Line "'.mb_substr($it->description, 0, 60).'": '
                            .($this->Materials_model->last_db_error() ?: 'the stock posting failed').'.';
                        break;
                    }
                    if (!empty($move['skipped'])) {
                        // Already posted under this token: this is a replayed
                        // submit, not a new delivery.
                        $problem = 'This delivery has already been recorded — the form was submitted twice.';
                        break;
                    }
                    $movement_id = isset($move['movement_id']) ? $move['movement_id'] : null;

                    // Pending Spare Parts Tracking: match this receipt against the
                    // parts pending work orders are waiting for and alert.
                    if (empty($move['skipped'])) {
                        foreach ($this->Spareparts_model->process_receipt(
                                $it->material_id, $qty, $move['before'], $move['after'],
                                $this->user_id, 'Order '.$order->order_no) as $al) {
                            $matched_wos[] = $al->wo_number;
                        }
                    }
                }

                $lines[] = array(
                    'order_item_id'           => (int)$it->id,
                    'material_id'             => $it->material_id,
                    'description'             => $it->description,
                    'unit'                    => $it->unit,
                    'qty_ordered'             => (float)$it->qty_ordered,
                    'qty_previously_received' => (float)$it->qty_previously_received,
                    'qty_delivered'           => $qty,
                    'qty_outstanding_after'   => round((float)$it->qty_outstanding - $qty, 2),
                    'unit_price'              => $it->unit_price,
                    'movement_id'             => $movement_id,
                );
            }

            if ($problem === null && empty($lines)) {
                $problem = 'Nothing was entered: every delivery line is zero.';
            }

            if ($problem !== null) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', $problem.' Nothing was saved.');
                redirect('orders/receive/'.$id);
                return;
            }

            $receipt = $this->Order_model->record_receipt($id, $lines, array(
                'receipt_date'      => $date,
                'discrepancy_flag'  => $flag,
                'discrepancy_notes' => $notes,
                'submit_token'      => $token,
            ), $this->user_id);

            if (empty($receipt['ok'])) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error',
                    'This delivery has already been recorded — the receive form was submitted twice. Nothing was saved.');
                redirect('orders/view/'.$id);
                return;
            }

            // Order header: the first delivery dates the order as delivered.
            if (empty($order->actual_delivery_date)) {
                $this->Order_model->update_header($id, array('actual_delivery_date' => $date));
            }
            $this->Order_model->set_discrepancy($id, $flag, $notes);

            // Fully received now? Then the order moves to "Received at Store"
            // and waits for store verification; a partial delivery leaves the
            // order where it is and is recorded in the status history.
            $complete = true;
            foreach ($this->Order_model->get_receive_state($id) as $after) {
                if ((float)$after->qty_outstanding > 0) { $complete = false; break; }
            }
            $summary = $receipt['grn_no'].': '.count($lines).' line(s), '
                .number_format((float)(array_sum(array_column($lines, 'qty_delivered'))), 2).' unit(s)'
                .($flag !== 'None' ? ' — discrepancy: '.$flag : '');

            if ($complete && $order->status !== 'Received at Store') {
                $this->Order_model->set_status($id, 'Received at Store', $this->user_id,
                    $summary.' — order fully received');
            } else {
                $this->Order_model->log_status($id, $order->status, $this->user_id,
                    $summary.($complete ? '' : ' — partial delivery, still outstanding'));
            }

            if ($this->db->trans_status() === FALSE) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'The receipt could not be completed. No changes were saved.');
                redirect('orders/receive/'.$id);
                return;
            }
            if (!$this->db->trans_commit()) {
                $this->db->trans_rollback();
                $this->session->set_flashdata('error', 'The receipt could not be committed. No changes were saved.');
                redirect('orders/receive/'.$id);
                return;
            }

            $match_note = '';
            if (!empty($matched_wos)) {
                $match_note = ' ⚡ Availability alert sent for pending work order(s): '
                    .implode(', ', array_unique($matched_wos)).'.';
            }
            $this->session->set_flashdata('success',
                ($complete ? 'Order fully received.' : 'Delivery recorded — the order stays open for the balance.')
                .' Stock updated. '.$receipt['grn_no'].' is ready to print.'.$match_note);
            redirect('orders/grn/'.$id.'/'.$receipt['receipt_id']);
            return;
        }

        $data['order']        = $order;
        $data['items']        = $this->Order_model->get_receive_state($id);
        // One-time token for this rendered form. Same guarded idiom as the
        // importer tokens (Materials/Reports): random_bytes where the PHP
        // version has it, a uniqid hash where it does not.
        $data['submit_token'] = function_exists('random_bytes')
            ? bin2hex(random_bytes(16))
            : substr(md5(uniqid('wms_receipt', true)), 0, 32);
        $data['page_title']   = 'Receive Order '.$order->order_no;
        $this->render('orders/receive', $data);
    }

    /* ── GRN — printable, system-generated (not uploaded) ──
       `orders/grn/{order}` prints the latest delivery of that order,
       `orders/grn/{order}/{receipt}` prints one specific delivery. Orders
       received before receipt rows existed have no receipt rows, so the original
       order-level document is still produced for them.
    */
    public function grn($id, $receipt_id = null) {
        $order = $this->Order_model->get_by_id($id);
        if (!$order) show_404();

        $receipt = null;
        if ($this->Order_model->receipts_ready()) {
            if ($receipt_id !== null) {
                $candidate = $this->Order_model->get_receipt((int)$receipt_id);
                // A receipt that belongs to another order must not be printable
                // under this order's URL.
                if (!$candidate || (int)$candidate->order_id !== (int)$order->id) show_404();
                $receipt = $candidate;
            } else {
                $receipt = $this->Order_model->latest_receipt($id);
            }
        }

        $data['order']      = $order;
        $data['receipt']    = $receipt;
        $data['lines']      = $receipt ? $this->Order_model->get_receipt_lines($receipt->id) : array();
        $data['items']      = $this->Order_model->get_items($id);
        $data['page_title'] = 'GRN — '.$order->order_no;
        $this->load->view('orders/grn', $data); // standalone printable page, no header/sidebar
    }

    /* ── DOCUMENT UPLOAD (delivery note / invoice) ──────── */
    public function upload_document($id) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            show_error('Forbidden.', 403);
            return;
        }
        $order = $this->Order_model->get_by_id($id);
        if (!$order) show_404();

        if (!empty($_FILES['document']['name'])) {
            $config = array(
                'upload_path'   => './uploads/order_docs/',
                'allowed_types' => 'gif|jpg|jpeg|png|pdf|doc|docx',
                'max_size'      => 8000,
                'file_name'     => 'ord_'.$order->id.'_'.time(),
            );
            $this->load->library('upload', $config);
            if ($this->upload->do_upload('document')) {
                $up = $this->upload->data();
                $this->Order_model->add_document(
                    $id,
                    $this->input->post('doc_type', TRUE) ?: 'Delivery Note',
                    $up['file_name'],
                    $_FILES['document']['name'],
                    $this->user_id
                );
                $this->session->set_flashdata('success', 'Document uploaded.');
            } else {
                $this->session->set_flashdata('error', $this->upload->display_errors('', ''));
            }
        }
        redirect('orders/view/'.$id);
    }

    public function download_document($doc_id) {
        $doc = $this->db->where('id', $doc_id)->get('order_documents')->row();
        if (!$doc) show_404();
        $path = './uploads/order_docs/'.$doc->file_name;
        if (!file_exists($path)) show_404();
        force_download($path, NULL, TRUE);
    }

    /* ── ANALYTICS (feeds Reports module) ───────────────── */
    public function api_lead_time_estimate() {
        $country = $this->input->get('country');
        $method  = $this->input->get('method');
        $supplier_id = $this->input->get('supplier_id');
        $lead = $this->Order_model->get_estimated_lead_time($country, $method, $supplier_id);
        echo json_encode($lead);
    }
}
