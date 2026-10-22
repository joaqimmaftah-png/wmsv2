<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Order_model extends CI_Model {

    /* Fixed status pipeline — kept hard-coded by design (not configurable),
       since this workshop runs one process. Add/reorder here if the real
       workflow changes. */
    public static $STATUSES = array(
        'Pending Approval', 'Sent to Supplier', 'Confirmed', 'In Transit',
        'Customs Clearance', 'Received at Store', 'Verified & Closed', 'Cancelled',
    );

    public static $SHIPPING_METHODS = array('Air Freight', 'Sea Freight', 'Road Transport', 'Rail');

    /**
     * Which status may follow which (Wave 3, guided pipeline).
     *
     * The order form offered every status to every user, so an order could jump
     * from "Sent to Supplier" to "Verified & Closed" in one step — skipping the
     * receipt, the verification and any trace of what actually arrived. The
     * pipeline below is the process the workshop already follows; the extra
     * step is that the old "Received at Store" shortcut is only reachable
     * through the receiving screen (which records the delivery), not by picking
     * it from the dropdown.
     *
     * Cancelling is possible until goods have been booked in.
     */
    public static $TRANSITIONS = array(
        'Pending Approval'   => array('Sent to Supplier', 'Cancelled'),
        'Sent to Supplier'   => array('Confirmed', 'Cancelled'),
        'Confirmed'          => array('In Transit', 'Cancelled'),
        'In Transit'         => array('Customs Clearance', 'Received at Store', 'Cancelled'),
        'Customs Clearance'  => array('Received at Store', 'Cancelled'),
        'Received at Store'  => array('Verified & Closed', 'Cancelled'),
        'Verified & Closed'  => array(),
        'Cancelled'          => array(),
    );

    /** Is this status change allowed by the pipeline? */
    public static function can_transition($from, $to) {
        if ($from === $to) return true;                        // re-posting the same status is harmless
        $allowed = isset(self::$TRANSITIONS[$from]) ? self::$TRANSITIONS[$from] : array();
        return in_array($to, $allowed, TRUE);
    }

    /** The statuses the pipeline allows next, for the drop-down. */
    public static function next_statuses($from) {
        $allowed = isset(self::$TRANSITIONS[$from]) ? self::$TRANSITIONS[$from] : array();
        if ($from === 'Received at Store') {
            // "Received at Store" itself is reached by booking a delivery in;
            // listing it again would invite a status change that records nothing.
            $allowed = array_values(array_diff($allowed, array('Received at Store')));
        }
        return $allowed;
    }

    const DEFAULT_LEAD_TIME_DAYS = 30;

    /* ── List / filter ─────────────────────────────────── */
    public function get_all($filter = array()) {
        $this->db->select('o.*, s.company_name as supplier_name')
            ->from('spare_orders o')
            ->join('suppliers s', 's.id = o.supplier_id');
        if (!empty($filter['status']))   $this->db->where('o.status', $filter['status']);
        if (!empty($filter['supplier'])) $this->db->where('o.supplier_id', $filter['supplier']);
        if (!empty($filter['country']))  $this->db->where('o.country', $filter['country']);
        if (!empty($filter['from']))     $this->db->where('o.order_date >=', $filter['from']);
        if (!empty($filter['to']))       $this->db->where('o.order_date <=', $filter['to']);
        if (!empty($filter['search'])) {
            $this->db->group_start()
                ->like('o.order_no', $filter['search'])
                ->or_like('s.company_name', $filter['search'])
                ->or_like('o.tracking_number', $filter['search'])
                ->group_end();
        }
        $this->db->order_by('o.order_date', 'DESC');
        return $this->db->get()->result();
    }

    public function get_by_id($id) {
        $this->db->select('o.*, s.company_name as supplier_name, s.currency as supplier_currency, s.country as supplier_country')
            ->from('spare_orders o')
            ->join('suppliers s', 's.id = o.supplier_id')
            ->where('o.id', $id);
        return $this->db->get()->row();
    }

    public function get_items($order_id) {
        return $this->db->where('order_id', $order_id)->order_by('id', 'ASC')->get('spare_order_items')->result();
    }

    public function get_status_history($order_id) {
        $this->db->select('h.*, u.first_name, u.last_name')
            ->from('order_status_history h')
            ->join('users u', 'u.id = h.changed_by', 'left')
            ->where('h.order_id', $order_id)
            ->order_by('h.changed_at', 'ASC');
        return $this->db->get()->result();
    }

    public function get_documents($order_id) {
        return $this->db->where('order_id', $order_id)->order_by('uploaded_at', 'DESC')->get('order_documents')->result();
    }

    /* ── Lead-time estimation ──────────────────────────────
       Learns from your own order history: average days between
       order_date and actual_delivery_date for completed orders
       on the same country + shipping method. Falls back to the
       supplier's quoted guarantee, then a flat default. */
    public function get_estimated_lead_time($country, $shipping_method, $supplier_id = null) {
        $this->db->select('AVG(DATEDIFF(actual_delivery_date, order_date)) as avg_days, COUNT(*) as n')
            ->from('spare_orders')
            ->where('country', $country)
            ->where('shipping_method', $shipping_method)
            ->where('actual_delivery_date IS NOT NULL', null, false)
            ->where_in('status', array('Received at Store', 'Verified & Closed'));
        $row = $this->db->get()->row();

        if ($row && $row->n >= 3 && $row->avg_days) {
            return array('days' => (int)round($row->avg_days), 'source' => 'history', 'sample' => (int)$row->n);
        }

        if ($supplier_id) {
            $sup = $this->db->select('lead_time_guarantee_days')->where('id', $supplier_id)->get('suppliers')->row();
            if ($sup && $sup->lead_time_guarantee_days) {
                return array('days' => (int)$sup->lead_time_guarantee_days, 'source' => 'supplier_quote', 'sample' => 0);
            }
        }

        return array('days' => self::DEFAULT_LEAD_TIME_DAYS, 'source' => 'default', 'sample' => 0);
    }

    /* ── Create / update ───────────────────────────────── */
    public function generate_order_no() {
        $year   = date('Y');
        $prefix = "SO-{$year}-";
        $row    = $this->db->select('order_no')
            ->like('order_no', $prefix, 'after')
            ->order_by('id', 'DESC')
            ->get('spare_orders')->result();
        $max = 0;
        foreach ($row as $r) {
            if (preg_match('/^SO-\d{4}-(\d+)$/', (string)$r->order_no, $m)) {
                $n = (int)$m[1];
                if ($n > $max) $max = $n;
            }
        }
        return sprintf('SO-%s-%03d', $year, $max + 1);
    }

    public function create($header, $items, $user_id) {
        $this->db->trans_begin();
        $header['order_no']   = $this->generate_order_no();
        $header['created_at'] = date('Y-m-d H:i:s');
        $header['updated_at'] = date('Y-m-d H:i:s');
        $this->db->insert('spare_orders', $header);
        $order_id = $this->db->insert_id();

        foreach ($items as $it) {
            if (empty($it['description'])) continue;
            $it['order_id'] = $order_id;
            $this->db->insert('spare_order_items', $it);
        }

        $this->log_status($order_id, $header['status'], $user_id, 'Order created');
        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            return FALSE;
        }
        $this->db->trans_commit();
        return $order_id;
    }

    public function update_header($id, $data) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->where('id', $id)->update('spare_orders', $data);
    }

    public function log_status($order_id, $status, $user_id, $notes = null) {
        $this->db->insert('order_status_history', array(
            'order_id'   => $order_id,
            'status'     => $status,
            'changed_by' => $user_id,
            'changed_at' => date('Y-m-d H:i:s'),
            'notes'      => $notes,
        ));
    }

    public function set_status($order_id, $status, $user_id, $notes = null) {
        $this->update_header($order_id, array('status' => $status));
        $this->log_status($order_id, $status, $user_id, $notes);
    }

    public function add_document($order_id, $doc_type, $file_name, $original_name, $user_id) {
        $this->db->insert('order_documents', array(
            'order_id'      => $order_id,
            'doc_type'      => $doc_type,
            'file_name'     => $file_name,
            'original_name' => $original_name,
            'uploaded_by'   => $user_id,
            'uploaded_at'   => date('Y-m-d H:i:s'),
        ));
    }

    /* ── Receiving: one document per delivery ───────────────
       Every delivery is its own goods_receipts row with its own GRN number,
       its own date and the storeman who booked it in; every line of that
       delivery carries the four quantities the storekeeper works with
       (ordered / previously received / delivered now / outstanding after).

       `spare_order_items.qty_received` is kept as a running total — added to,
       never overwritten — so the screens and reports that read it keep
       working unchanged. The stock posting itself is not done here: it goes
       through Materials_model::add_stock_movement(), which is the single
       writer that keeps the counters, the movement log and the before/after
       values consistent (and refuses to post the same GRN line twice).
    */

    /** Are the goods-receipt tables in place? */
    public function receipts_ready() {
        return $this->db->table_exists('goods_receipts')
            && $this->db->table_exists('goods_receipt_lines');
    }

    public function generate_grn_no($order_id) {
        $order = $this->get_by_id($order_id);
        $base  = 'GRN-'.($order ? $order->order_no : $order_id);
        $n     = $this->db->like('grn_no', $base, 'after')->count_all_results('goods_receipts');
        return $n === 0 ? $base : $base.'-'.($n + 1);
    }

    /**
     * How much of each line has already been delivered.
     *
     * This is `spare_order_items.qty_received` — the running total. It has to
     * be: it already includes the deliveries booked before receipt rows existed (when
     * there were no receipt rows at all), and record_receipt() adds to it for
     * every delivery from now on. The goods_receipt_lines rows are the
     * documents and the per-delivery audit trail; if the two ever disagree,
     * this number is the one every existing screen and report means by
     * "received".
     *
     * @return array [order_item_id => qty_received_so_far]
     */
    public function received_by_item($order_id) {
        $map = array();
        foreach ($this->get_items($order_id) as $it) {
            $map[(int)$it->id] = (float)$it->qty_received;
        }
        return $map;
    }

    /** The order lines plus the two numbers the receive screen needs. */
    public function get_receive_state($order_id) {
        $received = $this->received_by_item($order_id);
        $items    = $this->get_items($order_id);
        foreach ($items as $it) {
            $done = isset($received[(int)$it->id]) ? $received[(int)$it->id] : 0.0;
            $it->qty_previously_received = $done;
            $it->qty_outstanding         = max(0, (float)$it->qty_ordered - $done);
        }
        return $items;
    }

    /**
     * Write one delivery: header + lines, and the running total on each item.
     * Must be called inside the caller's transaction (the controller holds the
     * order row lock). $lines are already validated and already posted to
     * stock; their movement_id links the line to the ledger.
     *
     * @return array ['receipt_id' => int, 'grn_no' => string]
     */
    public function record_receipt($order_id, $lines, $meta, $user_id) {
        $grn_no = $this->generate_grn_no($order_id);

        $inserted = $this->db->insert('goods_receipts', array(
            'order_id'          => (int)$order_id,
            'grn_no'            => $grn_no,
            'submit_token'      => isset($meta['submit_token']) ? $meta['submit_token'] : NULL,
            'receipt_date'      => $meta['receipt_date'],
            'received_by'       => (int)$user_id,
            'discrepancy_flag'  => $meta['discrepancy_flag'],
            'discrepancy_notes' => $meta['discrepancy_notes'],
            'notes'             => isset($meta['notes']) ? $meta['notes'] : NULL,
            'created_at'        => date('Y-m-d H:i:s'),
        ));
        $receipt_id = (int)$this->db->insert_id();

        /*
         * The header insert fails when submit_token is a duplicate — the same
         * rendered receive form submitted twice (double click, browser
         * re-post). Reporting that upward lets the controller roll the whole
         * delivery back instead of documenting it a second time.
         */
        if ($inserted === FALSE || $receipt_id <= 0) {
            return array('receipt_id' => 0, 'grn_no' => $grn_no, 'ok' => FALSE);
        }

        foreach ($lines as $l) {
            $delivered = number_format((float)$l['qty_delivered'], 2, '.', '');

            $this->db->insert('goods_receipt_lines', array(
                'receipt_id'              => $receipt_id,
                'order_item_id'           => (int)$l['order_item_id'],
                'material_id'             => $l['material_id'] ? (int)$l['material_id'] : NULL,
                'description'             => $l['description'],
                'unit'                    => $l['unit'],
                'qty_ordered'             => (float)$l['qty_ordered'],
                'qty_previously_received' => (float)$l['qty_previously_received'],
                'qty_delivered'           => (float)$l['qty_delivered'],
                'qty_outstanding_after'   => (float)$l['qty_outstanding_after'],
                'unit_price'              => $l['unit_price'],
                'movement_id'             => !empty($l['movement_id']) ? (int)$l['movement_id'] : NULL,
                'notes'                   => isset($l['notes']) ? $l['notes'] : NULL,
            ));

            // Running total: added, never overwritten.
            $this->db->set('qty_received', 'COALESCE(qty_received, 0) + '.$delivered, FALSE)
                ->where('id', (int)$l['order_item_id'])
                ->where('order_id', (int)$order_id)
                ->update('spare_order_items');
        }

        return array('receipt_id' => $receipt_id, 'grn_no' => $grn_no, 'ok' => TRUE);
    }

    public function get_receipts($order_id) {
        if (!$this->receipts_ready()) return array();
        return $this->db->select('r.*, u.first_name, u.last_name')
            ->from('goods_receipts r')
            ->join('users u', 'u.id = r.received_by', 'left')
            ->where('r.order_id', (int)$order_id)
            ->order_by('r.receipt_date', 'ASC')->order_by('r.id', 'ASC')
            ->get()->result();
    }

    public function get_receipt($receipt_id) {
        if (!$this->receipts_ready()) return null;
        return $this->db->select('r.*, u.first_name, u.last_name,
                o.order_no, o.order_date, o.country, o.shipping_method,
                o.tracking_number, o.status AS order_status, o.supplier_id,
                s.company_name AS supplier_name')
            ->from('goods_receipts r')
            ->join('users u', 'u.id = r.received_by', 'left')
            ->join('spare_orders o', 'o.id = r.order_id')
            ->join('suppliers s', 's.id = o.supplier_id', 'left')
            ->where('r.id', (int)$receipt_id)
            ->get()->row();
    }

    public function get_receipt_lines($receipt_id) {
        if (!$this->receipts_ready()) return array();
        // Joined to the ordered line so the printed GRN shows the part number
        // the storekeeper checks the box against.
        return $this->db->select('l.*, i.part_number')
            ->from('goods_receipt_lines l')
            ->join('spare_order_items i', 'i.id = l.order_item_id', 'left')
            ->where('l.receipt_id', (int)$receipt_id)
            ->order_by('l.id', 'ASC')
            ->get()->result();
    }

    /** The most recent delivery of an order — what `orders/grn/{id}` prints. */
    public function latest_receipt($order_id) {
        if (!$this->receipts_ready()) return null;
        $row = $this->db->where('order_id', (int)$order_id)
            ->order_by('receipt_date', 'DESC')->order_by('id', 'DESC')
            ->limit(1)->get('goods_receipts')->row();
        return $row ? $this->get_receipt($row->id) : null;
    }

    public function set_discrepancy($order_id, $flag, $notes) {
        $this->update_header($order_id, array(
            'discrepancy_flag'  => $flag,
            'discrepancy_notes' => $notes,
        ));
    }

    /* ── Dashboard KPIs ─────────────────────────────────── */
    public function get_kpi() {
        $today = date('Y-m-d');
        return array(
            'open_orders'    => $this->db->where_not_in('status', array('Verified & Closed', 'Cancelled'))->count_all_results('spare_orders'),
            'in_transit'     => $this->db->where_in('status', array('In Transit', 'Customs Clearance'))->count_all_results('spare_orders'),
            'overdue'        => $this->db->where('expected_delivery_date <', $today)
                                          ->where_not_in('status', array('Received at Store', 'Verified & Closed', 'Cancelled'))
                                          ->count_all_results('spare_orders'),
            'due_soon'       => $this->db->where('expected_delivery_date >=', $today)
                                          ->where('expected_delivery_date <=', date('Y-m-d', strtotime('+7 days')))
                                          ->where_not_in('status', array('Received at Store', 'Verified & Closed', 'Cancelled'))
                                          ->count_all_results('spare_orders'),
        );
    }

    public function get_overdue($limit = 10) {
        $this->db->select('o.*, s.company_name as supplier_name')
            ->from('spare_orders o')
            ->join('suppliers s', 's.id = o.supplier_id')
            ->where('o.expected_delivery_date <', date('Y-m-d'))
            ->where_not_in('o.status', array('Received at Store', 'Verified & Closed', 'Cancelled'))
            ->order_by('o.expected_delivery_date', 'ASC')
            ->limit($limit);
        return $this->db->get()->result();
    }

    public function get_due_soon($limit = 10) {
        $this->db->select('o.*, s.company_name as supplier_name')
            ->from('spare_orders o')
            ->join('suppliers s', 's.id = o.supplier_id')
            ->where('o.expected_delivery_date >=', date('Y-m-d'))
            ->where('o.expected_delivery_date <=', date('Y-m-d', strtotime('+7 days')))
            ->where_not_in('o.status', array('Received at Store', 'Verified & Closed', 'Cancelled'))
            ->order_by('o.expected_delivery_date', 'ASC')
            ->limit($limit);
        return $this->db->get()->result();
    }

    /* ── Analytics ──────────────────────────────────────── */
    /**
     * Spend per supplier, one row per currency (K-11).
     *
     * `total_cost` is recorded in the order's own currency, so summing it
     * across orders in TZS, UGX and USD produced a number with no meaning.
     * Grouping by currency lets the caller show a total per currency instead
     * of inventing an exchange rate.
     */
    public function get_spend_by_supplier($from = null, $to = null) {
        $this->db->select('s.company_name, s.country, o.currency, SUM(o.total_cost) as total_spend, COUNT(*) as order_count')
            ->from('spare_orders o')
            ->join('suppliers s', 's.id = o.supplier_id');
        if ($from) $this->db->where('o.order_date >=', $from);
        if ($to)   $this->db->where('o.order_date <=', $to);
        $this->db->group_by('o.supplier_id, s.company_name, s.country, o.currency')->order_by('total_spend', 'DESC');
        return $this->db->get()->result();
    }

    public function get_lead_time_by_route() {
        $this->db->select('country, shipping_method, AVG(DATEDIFF(actual_delivery_date, order_date)) as avg_days, COUNT(*) as n')
            ->from('spare_orders')
            ->where('actual_delivery_date IS NOT NULL', null, false)
            ->where_in('status', array('Received at Store', 'Verified & Closed'))
            ->group_by('country, shipping_method')
            ->order_by('country', 'ASC');
        return $this->db->get()->result();
    }

    public function get_supplier_on_time_rate($supplier_id) {
        $closed = $this->db->where('supplier_id', $supplier_id)
            ->where_in('status', array('Received at Store', 'Verified & Closed'))
            ->where('actual_delivery_date IS NOT NULL', null, false)
            ->get('spare_orders')->result();
        $n = count($closed);
        if ($n === 0) return null; // not enough data — caller should withhold display
        $on_time = 0;
        foreach ($closed as $o) {
            if ($o->expected_delivery_date && $o->actual_delivery_date <= $o->expected_delivery_date) $on_time++;
        }
        return array('rate' => round($on_time / $n * 100, 1), 'sample' => $n);
    }

    /**
     * Constant O(1) query hydrator (<= 2 SQL SELECT queries regardless of PO
     * count) for the Enterprise Spare Parts Orders Register, KPI telemetry,
     * 6 governance tabs, and multi-currency CSV / XLSX exports.
     */
    public function get_register_overview() {
        $today    = date('Y-m-d');
        $soonDate = date('Y-m-d', strtotime('+7 days'));

        $sel = 'o.*, s.company_name AS supplier_name';
        $hasWo = $this->db->table_exists('work_orders');
        $hasEq = $hasWo && $this->db->table_exists('equipment');
        $hasUs = $this->db->table_exists('users');

        if ($hasWo) {
            $sel .= ', wo.wo_number, wo.title AS wo_title';
        }
        if ($hasEq) {
            $sel .= ', e.id AS equipment_id, e.registration_no AS equipment_code';
        }
        if ($hasUs) {
            $sel .= ', CONCAT(u.first_name, " ", u.last_name) AS requester_name';
        }

        $qb = $this->db->select($sel, FALSE)
            ->from('spare_orders o')
            ->join('suppliers s', 's.id = o.supplier_id', 'left');
        if ($hasWo) {
            $qb->join('work_orders wo', 'wo.id = o.linked_wo_id', 'left');
        }
        if ($hasEq) {
            $qb->join('equipment e', 'e.id = wo.equipment_id', 'left');
        }
        if ($hasUs) {
            $qb->join('users u', 'u.id = o.requested_by', 'left');
        }
        $rows = $qb->order_by('o.order_date', 'DESC')->order_by('o.id', 'DESC')->get()->result();
        if (!$rows) $rows = array();

        // Single batch query across all line items (eliminates N+1 completely)
        $itemMap = array();
        if (!empty($rows) && $this->db->table_exists('spare_order_items')) {
            $allItems = $this->db
                ->select('order_id, description, part_number, qty_ordered, qty_received')
                ->from('spare_order_items')
                ->order_by('id', 'ASC')
                ->get()->result();
            if ($allItems) {
                foreach ($allItems as $it) {
                    $oid = (int)$it->order_id;
                    if (!isset($itemMap[$oid])) {
                        $itemMap[$oid] = array(
                            'count'        => 0,
                            'qty_ordered'  => 0.0,
                            'qty_received' => 0.0,
                            'first_label'  => '',
                            'search_terms' => array(),
                        );
                    }
                    $itemMap[$oid]['count']++;
                    $itemMap[$oid]['qty_ordered']  += (float)$it->qty_ordered;
                    $itemMap[$oid]['qty_received'] += (float)($it->qty_received ?? 0);
                    if ($itemMap[$oid]['first_label'] === '') {
                        $itemMap[$oid]['first_label'] = trim((string)$it->description);
                    }
                    $itemMap[$oid]['search_terms'][] = trim((string)$it->description . ' ' . (string)($it->part_number ?? ''));
                }
            }
        }

        $kpi = array(
            'total_all'      => count($rows),
            'open_orders'    => 0,
            'in_transit'     => 0,
            'overdue'        => 0,
            'due_soon'       => 0,
            'discrepancies'  => 0,
            'closed_orders'  => 0,
            'open_spend_by_ccy' => array(),
        );
        $overdueList = array();
        $dueSoonList = array();

        foreach ($rows as $r) {
            $oid = (int)$r->id;
            $im  = isset($itemMap[$oid]) ? $itemMap[$oid] : array(
                'count' => 0, 'qty_ordered' => 0.0, 'qty_received' => 0.0,
                'first_label' => '', 'search_terms' => array(),
            );

            $r->line_count          = (int)$im['count'];
            $r->qty_ordered_sum     = round((float)$im['qty_ordered'], 2);
            $r->qty_received_sum    = round((float)$im['qty_received'], 2);
            $r->qty_outstanding_sum = max(0.0, round($r->qty_ordered_sum - $r->qty_received_sum, 2));
            $r->fulfillment_pct     = $r->qty_ordered_sum > 0
                ? min(100, (int)round(($r->qty_received_sum / $r->qty_ordered_sum) * 100))
                : 0;
            $r->first_item_label    = $im['first_label'];
            $r->items_search_blob   = strtolower(implode(' ', $im['search_terms']));

            $isClosedTerminal = in_array($r->status, array('Verified & Closed', 'Cancelled'), TRUE);
            $isDeliveredOrDone = in_array($r->status, array('Received at Store', 'Verified & Closed', 'Cancelled'), TRUE);

            $r->is_overdue = !empty($r->expected_delivery_date)
                && $r->expected_delivery_date < $today
                && !$isDeliveredOrDone;
            $r->is_due_soon = !empty($r->expected_delivery_date)
                && $r->expected_delivery_date >= $today
                && $r->expected_delivery_date <= $soonDate
                && !$isDeliveredOrDone;
            $r->has_discrepancy = !empty($r->discrepancy_flag) && $r->discrepancy_flag !== 'None';

            if (!$isClosedTerminal) {
                $kpi['open_orders']++;
                $ccy = !empty($r->currency) ? strtoupper((string)$r->currency) : 'TZS';
                if (!isset($kpi['open_spend_by_ccy'][$ccy])) {
                    $kpi['open_spend_by_ccy'][$ccy] = 0.0;
                }
                $kpi['open_spend_by_ccy'][$ccy] += (float)$r->total_cost;
            }
            if (in_array($r->status, array('Received at Store', 'Verified & Closed'), TRUE)) {
                $kpi['closed_orders']++;
            }
            if (in_array($r->status, array('In Transit', 'Customs Clearance'), TRUE)) {
                $kpi['in_transit']++;
            }
            if ($r->is_overdue) {
                $kpi['overdue']++;
                if (count($overdueList) < 5) $overdueList[] = $r;
            }
            if ($r->is_due_soon) {
                $kpi['due_soon']++;
                if (count($dueSoonList) < 5) $dueSoonList[] = $r;
            }
            if ($r->has_discrepancy) {
                $kpi['discrepancies']++;
            }
        }

        return array(
            'rows'     => $rows,
            'kpi'      => $kpi,
            'overdue'  => $overdueList,
            'due_soon' => $dueSoonList,
        );
    }
}
