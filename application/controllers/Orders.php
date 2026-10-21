<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Orders extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Order_model');
        $this->load->model('Supplier_model');
        $this->load->model('Materials_model');
    }

    /* ── DASHBOARD / LIST ───────────────────────────────── */
    public function index() {
        $filter = array(
            'status'   => $this->input->get('status'),
            'supplier' => $this->input->get('supplier'),
            'country'  => $this->input->get('country'),
            'search'   => $this->input->get('search'),
        );
        $data = array(
            'orders'     => $this->Order_model->get_all($filter),
            'suppliers'  => $this->Supplier_model->get_all(),
            'countries'  => $this->Supplier_model->get_countries(),
            'statuses'   => Order_model::$STATUSES,
            'kpi'        => $this->Order_model->get_kpi(),
            'overdue'    => $this->Order_model->get_overdue(5),
            'due_soon'   => $this->Order_model->get_due_soon(5),
            'filter'     => $filter,
            'page_title' => 'Spare Parts Orders',
        );
        $this->render('orders/index', $data);
    }

    /* ── CREATE ─────────────────────────────────────────── */
    public function create() {
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
        $data['next_statuses'] = Order_model::next_statuses($order->status);
        $data['lead']      = $this->Order_model->get_estimated_lead_time($order->country, $order->shipping_method, $order->supplier_id);
        $data['page_title']= 'Order '.$order->order_no;
        $this->render('orders/view', $data);
    }

    /* ── STATUS CHANGE ──────────────────────────────────── */
    public function update_status($id) {
        if (!$this->require_post()) return;
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
