<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Spareparts_model — Pending Spare Parts Tracking & Availability Alerts.
 *
 * Closes the loop between the workshop and the store:
 *
 *   Work Order → Equipment → Requested Spare Part → Stock Receipt
 *
 * The request itself lives in `wo_spare_parts` (one row per part requested on
 * a work order).  This model adds the three things that were missing:
 *
 *   1. AVAILABILITY (computed, never stored)
 *      Every pending work-order part line is matched against the inventory by
 *      item code (case/space-insensitive — see _key()), with the part number
 *      as a fallback.  A line's status derives from the live material balance
 *      minus active allocations, so the display can never drift away from the
 *      real stock:
 *          free stock = material balance − Σ 'Allocated' reservations
 *          Allocated  — reserved stock fully covers the line
 *          Available  — free stock fully covers the line
 *          Partial    — free stock covers part of the line
 *          Pending    — nothing available yet
 *          Unlinked   — no matching inventory item (item code missing/wrong)
 *
 *   2. RECEIPT MATCHING + ALERTS  (process_receipt())
 *      Every stock receipt (manual Receive, GRN receive) is checked against
 *      the pending demand.  When a receipt makes a waited-for part available,
 *      a `spare_part_alerts` row is written — the maintenance team no longer
 *      has to ask the store whether "their" part has arrived.
 *
 *   3. ALLOCATION  (allocate / release / issue)
 *      When several work orders wait for the same part, the store allocates
 *      the received quantity against them; allocating reserves stock so two
 *      work orders can never spend the same piece twice.  Issuing books the
 *      physical issue movement; completing a work order auto-issues whatever
 *      was still reserved for it.
 *
 * The model degrades gracefully: when migration 006 has not been applied,
 * tables_ready() returns FALSE and every method returns an empty/no-op result
 * instead of failing.
 */
class Spareparts_model extends CI_Model {

    /** Work-order statuses that still need their spare parts. */
    public static $PENDING_WO_STATUSES = array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools');

    /** Line statuses (computed). */
    const STATUS_ALLOCATED = 'Allocated';
    const STATUS_AVAILABLE = 'Available';
    const STATUS_PARTIAL   = 'Partial';
    const STATUS_PENDING   = 'Pending';
    const STATUS_UNLINKED  = 'Unlinked';
    const STATUS_FROM_MACHINE = 'From Machine';

    /**
     * Materials model used for issuing stock.  Assigned by the controller
     * (`$this->Spareparts_model->materials = $this->Materials_model;`) or
     * resolved lazily from the CI instance; kept injectable so unit tests can
     * supply their own.
     */
    public $materials = null;

    /* ── Infrastructure ─────────────────────────────────── */

    /** TRUE once migration 006 has been applied. */
    public function tables_ready() {
        return $this->db->table_exists('wo_spare_allocations')
            && $this->db->table_exists('spare_part_alerts')
            && $this->db->table_exists('wo_spare_parts')
            && $this->db->table_exists('materials');
    }

    /** Case-insensitive, whitespace-collapsed comparison key (same rule as Materials_model::key()). */
    public function _key($s) {
        $s = preg_replace('/\s+/u', ' ', trim((string)$s));
        if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
        return strtolower($s);
    }

    private function _materials_model() {
        if ($this->materials !== null) return $this->materials;
        $ci = get_instance();
        if (isset($ci->Materials_model)) return $ci->Materials_model;
        return null;
    }

    /** Current balance of a material row (initial + received − issued). */
    public function _balance($m) {
        if (!$m) return 0;
        return (int)$m->initial_stock + (int)$m->qty_received - (int)$m->qty_sortie;
    }

    /* ── Inventory index ──────────────────────────────────
       Loaded once per request and matched in PHP, because item codes are
       compared with the normalising key() and legacy data contains mixed
       case / spacing. */

    public function get_material_index() {
        $index = array('by_id' => array(), 'by_code' => array(), 'by_part' => array());
        if (!$this->db->table_exists('materials')) return $index;

        $rows = $this->db->get('materials')->result();
        foreach ($rows as $m) {
            $index['by_id'][(int)$m->id] = $m;
            $code = $this->_key($m->item_code);
            if ($code !== '' && !isset($index['by_code'][$code])) $index['by_code'][$code] = $m;
            $part = $this->_key(isset($m->part_number_size) ? $m->part_number_size : '');
            if ($part !== '' && !isset($index['by_part'][$part])) $index['by_part'][$part] = $m;
        }
        return $index;
    }

    /**
     * Match one requested part against the inventory.
     * Primary key is the ITEM CODE (the store may receive hundreds of items —
     * matching on names alone is unreliable); the part number is the fallback
     * for lines recorded with only the OEM reference.
     *
     * @return object|null the materials row
     */
    public function resolve_material($item_code, $part_number, $index = null) {
        if ($index === null) $index = $this->get_material_index();
        $code = $this->_key($item_code);
        if ($code !== '' && isset($index['by_code'][$code])) return $index['by_code'][$code];
        $part = $this->_key($part_number);
        if ($part !== '' && isset($index['by_part'][$part])) return $index['by_part'][$part];
        return null;
    }

    /* ── Demand queries ─────────────────────────────────── */

    /**
     * All spare-part lines still waiting on pending work orders
     * ("From Machine" parts are sourced on the shop floor, not from the
     * store, so they are not tracked here).
     */
    public function get_pending_lines($filter = array()) {
        if (!$this->tables_ready()) return array();

        $this->db->select('sp.*, wo.wo_number, wo.status AS wo_status, wo.priority,
                wo.title AS wo_title, wo.created_by AS wo_created_by,
                wo.assigned_to AS wo_assigned_to, wo.supervisor_id,
                e.id AS equipment_db_id, e.registration_no, e.brand, e.model')
            ->from('wo_spare_parts sp')
            ->join('work_orders wo', 'wo.id = sp.work_order_id')
            ->join('equipment e', 'e.id = wo.equipment_id', 'left')
            ->where_in('wo.status', self::$PENDING_WO_STATUSES)
            ->where('(sp.spare_type IS NULL OR sp.spare_type <> \'From Machine\')', null, false);

        if (!empty($filter['wo_id'])) $this->db->where('sp.work_order_id', (int)$filter['wo_id']);
        if (!empty($filter['search'])) {
            $s = $this->db->escape_like_str($filter['search']);
            $this->db->where("(wo.wo_number LIKE '%{$s}%' OR sp.part_name LIKE '%{$s}%'"
                ." OR sp.item_code LIKE '%{$s}%' OR sp.part_number LIKE '%{$s}%'"
                ." OR e.registration_no LIKE '%{$s}%')", null, false);
        }
        $lines = $this->db->order_by('wo.priority', 'ASC')->order_by('wo.wo_number', 'ASC')->get()->result();
        return $this->enrich_lines($lines);
    }

    /** Reserved ('Allocated') quantity per material across all pending work orders. */
    public function get_reserved_by_material() {
        $out = array();
        if (!$this->tables_ready()) return $out;
        $rows = $this->db->select('a.material_id, SUM(a.qty_allocated) AS reserved')
            ->from('wo_spare_allocations a')
            ->join('wo_spare_parts sp', 'sp.id = a.wo_spare_part_id')
            ->join('work_orders wo', 'wo.id = a.work_order_id')
            ->where('a.status', 'Allocated')
            ->where_in('wo.status', self::$PENDING_WO_STATUSES)
            ->group_by('a.material_id')
            ->get()->result();
        foreach ($rows as $r) $out[(int)$r->material_id] = (float)$r->reserved;
        return $out;
    }

    /** Allocated + issued quantities per WO part line (only lines that still exist). */
    public function get_line_allocations($wo_id = null) {
        $out = array();
        if (!$this->tables_ready()) return $out;
        $this->db->select('a.*, wo.wo_number, wo.status AS wo_status')
            ->from('wo_spare_allocations a')
            ->join('wo_spare_parts sp', 'sp.id = a.wo_spare_part_id')
            ->join('work_orders wo', 'wo.id = a.work_order_id')
            ->where_in('a.status', array('Allocated', 'Issued'));
        if ($wo_id) $this->db->where('a.work_order_id', (int)$wo_id);
        $rows = $this->db->order_by('a.allocated_at', 'ASC')->get()->result();
        foreach ($rows as $r) {
            $out[(int)$r->wo_spare_part_id][] = $r;
        }
        return $out;
    }

    /**
     * Attach live availability information to part lines.
     *
     * Each returned line gets:
     *   material          matched materials row (or null)
     *   stock_qty         current inventory balance
     *   free_qty          balance minus reservations made for other lines
     *   qty_required      required quantity (0 when not specified)
     *   qty_allocated     reserved for this line
     *   qty_issued        already issued against this line
     *   qty_available     how much of the requirement is coverable right now
     *   avail_status      Allocated | Available | Partial | Pending | Unlinked
     */
    public function enrich_lines($lines) {
        if (empty($lines)) return array();
        $index    = $this->get_material_index();
        $reserved = $this->get_reserved_by_material();
        $allocs   = $this->get_line_allocations();

        foreach ($lines as $line) {
            $mat = $this->resolve_material(
                isset($line->item_code) ? $line->item_code : '',
                isset($line->part_number) ? $line->part_number : '',
                $index
            );
            $line->material    = $mat;
            $line->material_id = $mat ? (int)$mat->id : null;
            $line->stock_qty   = $this->_balance($mat);
            $line->item_unit   = $mat ? $mat->unit : (isset($line->unit) ? $line->unit : 'pcs');

            $alloc = 0.0; $issued = 0.0;
            if (isset($allocs[(int)$line->id])) {
                foreach ($allocs[(int)$line->id] as $a) {
                    if ($a->status === 'Issued') $issued += (float)$a->qty_allocated;
                    else                          $alloc  += (float)$a->qty_allocated;
                }
            }
            $line->qty_allocated = $alloc;
            $line->qty_issued    = $issued;
            $line->qty_required  = isset($line->quantity) ? (float)$line->quantity : 0;

            if ($mat) {
                $reserved_total = isset($reserved[(int)$mat->id]) ? $reserved[(int)$mat->id] : 0;
                $line->free_qty = max(0, $line->stock_qty - $reserved_total);
                $satisfied  = $alloc + $issued;
                $needed     = max(0, $line->qty_required - $satisfied);
                $coverable  = min($line->free_qty, $needed);
                $line->qty_available = $satisfied + $coverable;

                if ($needed <= 0) {
                    $line->avail_status = $alloc > 0 ? self::STATUS_ALLOCATED : self::STATUS_AVAILABLE;
                } elseif ($coverable >= $needed) {
                    $line->avail_status = self::STATUS_AVAILABLE;
                } elseif ($coverable > 0) {
                    $line->avail_status = self::STATUS_PARTIAL;
                } else {
                    $line->avail_status = self::STATUS_PENDING;
                }
            } else {
                $line->free_qty      = 0;
                $line->qty_available = $alloc + $issued;
                $line->avail_status  = self::STATUS_UNLINKED;
            }
        }
        return $lines;
    }

    /* ── Work-order view helpers ────────────────────────── */

    /**
     * Availability of ALL part lines of one work order (the card shown inside
     * the WO), including "From Machine" parts which are reported but not
     * tracked against the store.
     *
     * @return array ['lines' => [...], 'summary' => [...]]
     */
    public function get_wo_parts_availability($wo_id) {
        $summary = array(
            'total' => 0, 'available' => 0, 'partial' => 0, 'pending' => 0,
            'unlinked' => 0, 'from_machine' => 0, 'tracked' => 0,
        );
        if (!$this->tables_ready()) return array('lines' => array(), 'summary' => $summary);

        $rows = $this->db->where('work_order_id', (int)$wo_id)->get('wo_spare_parts')->result();
        if (empty($rows)) return array('lines' => array(), 'summary' => $summary);

        $tracked = array();
        foreach ($rows as $r) {
            if ($r->spare_type !== 'From Machine') $tracked[] = $r;
        }
        $tracked = $this->enrich_lines($tracked);
        $enriched = array();
        foreach ($tracked as $r) $enriched[(int)$r->id] = $r;

        // Walk the original row order so the card reads exactly like the form.
        $lines = array();
        foreach ($rows as $r) {
            if ($r->spare_type === 'From Machine') {
                $r->avail_status = self::STATUS_FROM_MACHINE;
                $lines[] = $r;
                $summary['from_machine']++;
                $summary['total']++;
                continue;
            }
            $line = $enriched[(int)$r->id];
            $lines[] = $line;
            $summary['tracked']++;
            $summary['total']++;
            switch ($line->avail_status) {
                case self::STATUS_ALLOCATED:
                case self::STATUS_AVAILABLE: $summary['available']++; break;
                case self::STATUS_PARTIAL:   $summary['partial']++;   break;
                case self::STATUS_UNLINKED:  $summary['unlinked']++;  break;
                default:                     $summary['pending']++;   break;
            }
        }
        return array('lines' => $lines, 'summary' => $summary);
    }

    /** Quick board KPIs. */
    public function get_board_summary() {
        $out = array('lines' => 0, 'available_lines' => 0, 'pending_lines' => 0,
                      'wos' => 0, 'ready_wos' => 0, 'new_alerts' => 0);
        if (!$this->tables_ready()) return $out;

        $lines = $this->get_pending_lines();
        $by_wo = array();
        foreach ($lines as $l) {
            $out['lines']++;
            $ok = ($l->avail_status === self::STATUS_AVAILABLE || $l->avail_status === self::STATUS_ALLOCATED);
            if ($ok) $out['available_lines']++;
            else     $out['pending_lines']++;
            $by_wo[(int)$l->work_order_id][] = $ok;
        }
        $out['wos'] = count($by_wo);
        foreach ($by_wo as $flags) {
            if (!in_array(false, $flags, true)) $out['ready_wos']++;
        }
        $out['new_alerts'] = (int)$this->db->where('status', 'New')
            ->count_all_results('spare_part_alerts');
        return $out;
    }

    /** Work orders whose required parts are ALL available (the jobs that can proceed now). */
    public function get_ready_work_orders() {
        if (!$this->tables_ready()) return array();
        $lines = $this->get_pending_lines();
        $by_wo = array();
        foreach ($lines as $l) {
            $wid = (int)$l->work_order_id;
            if (!isset($by_wo[$wid])) {
                $by_wo[$wid] = array('wo_number' => $l->wo_number, 'registration_no' => isset($l->registration_no) ? $l->registration_no : '',
                                     'title' => isset($l->wo_title) ? $l->wo_title : '', 'work_order_id' => $wid,
                                     'ok' => true, 'parts' => 0);
            }
            $by_wo[$wid]['parts']++;
            if ($l->avail_status !== self::STATUS_AVAILABLE && $l->avail_status !== self::STATUS_ALLOCATED) {
                $by_wo[$wid]['ok'] = false;
            }
        }
        $ready = array();
        foreach ($by_wo as $w) {
            if ($w['ok']) $ready[] = $w;
        }
        return $ready;
    }

    /* ── Receipt matching + alerts ──────────────────────── */

    /**
     * Called after stock was RECEIVED (manual receive, GRN, ...).  Checks
     * whether the received item is required by any pending work order and
     * writes the availability alerts.
     *
     * @param int   $material_id the inventory item that was received
     * @param float $qty         quantity received
     * @param int   $stock_before balance before the receipt
     * @param int   $stock_after  balance after the receipt
     * @param int   $user_id     who booked the receipt
     * @param string $source     e.g. 'Manual receive' or 'Order SPO-0004'
     * @return array the alerts created (each as an object with ->message)
     */
    public function process_receipt($material_id, $qty, $stock_before, $stock_after, $user_id = null, $source = '') {
        $created = array();
        if (!$this->tables_ready()) return $created;

        $material = $this->db->where('id', (int)$material_id)->get('materials')->row();
        if (!$material) return $created;

        // Pending demand for this item (matched on the same rules as the board).
        $index = array('by_id' => array((int)$material->id => $material), 'by_code' => array(), 'by_part' => array());
        $lines = $this->get_pending_lines();
        $waiting = array();
        foreach ($lines as $l) {
            if ($l->material_id === (int)$material->id && $l->qty_required > ($l->qty_allocated + $l->qty_issued)) {
                $waiting[] = $l;
            }
        }
        if (empty($waiting)) return $created;

        $reserved = $this->get_reserved_by_material();
        $reserved = isset($reserved[(int)$material->id]) ? $reserved[(int)$material->id] : 0;
        $free_before = max(0, (float)$stock_before - $reserved);
        $free_after  = max(0, (float)$stock_after  - $reserved);

        foreach ($waiting as $l) {
            $needed = $l->qty_required - $l->qty_allocated - $l->qty_issued;
            if ($needed <= 0) continue;
            if ($free_before >= $needed) continue;      // was already coverable — no new information

            if ($free_after >= $needed) {
                $type = 'Available';
                $message = sprintf('%s %s has been received into stock and is required for Work Order %s – %s.',
                    $l->part_name, $material->item_code, $l->wo_number,
                    isset($l->registration_no) && $l->registration_no !== '' ? $l->registration_no : 'equipment');
            } elseif ($free_after > $free_before) {
                $type = 'Partial';
                $covered = min($free_after, $needed);
                $message = sprintf('Partial stock received for %s %s — %s of %s needed for Work Order %s – %s is now coverable; %s still pending.',
                    $l->part_name, $material->item_code,
                    rtrim(rtrim(number_format($covered, 2, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($needed, 2, '.', ''), '0'), '.'),
                    $l->wo_number,
                    isset($l->registration_no) && $l->registration_no !== '' ? $l->registration_no : 'equipment',
                    rtrim(rtrim(number_format($needed - $covered, 2, '.', ''), '0'), '.'));
            } else {
                continue;                                  // receipt did not improve this line
            }

            $this->db->insert('spare_part_alerts', array(
                'wo_spare_part_id' => (int)$l->id,
                'work_order_id'    => (int)$l->work_order_id,
                'material_id'      => (int)$material->id,
                'item_code'        => $material->item_code,
                'part_name'        => $l->part_name,
                'qty_received'     => $qty,
                'stock_after'      => $stock_after,
                'alert_type'       => $type,
                'message'          => $message,
                'received_by'      => $user_id,
                'status'           => 'New',
                'created_at'       => date('Y-m-d H:i:s'),
            ));
            $alert = $this->db->where('id', $this->db->insert_id())->get('spare_part_alerts')->row();
            if ($alert) {
                $alert->wo_number      = $l->wo_number;       // convenience for callers (not a table column)
                $alert->registration_no = isset($l->registration_no) ? $l->registration_no : '';
                $created[] = $alert;
            }
        }
        return $created;
    }

    /* ── Alerts ─────────────────────────────────────────── */

    /**
     * Which work orders a user receives alerts for: supervisors, store and
     * managers see everything; technicians see the work orders they created
     * or are assigned to.
     */
    private function _scope_alert_query($user_id, $role_id) {
        // (first/last name are combined in PHP — keeps the query portable)
        $this->db->select('a.*, wo.wo_number, wo.status AS wo_status, e.registration_no,
                rc.first_name AS rb_first, rc.last_name AS rb_last')
            ->from('spare_part_alerts a')
            ->join('work_orders wo', 'wo.id = a.work_order_id', 'left')
            ->join('equipment e', 'e.id = wo.equipment_id', 'left')
            ->join('users rc', 'rc.id = a.received_by', 'left');

        if ((int)$role_id === 2) {
            // Technicians see the WOs they created, supervise or are assigned to.
            // assigned_to is a CSV of user ids; the LIKE variants below match a
            // csv entry exactly and work on every supported DB driver.
            $uid = (int)$user_id;
            $this->db->group_start()
                ->where('wo.created_by', $uid)
                ->or_where('wo.supervisor_id', $uid)
                ->or_where("(wo.assigned_to = '{$uid}' OR wo.assigned_to LIKE '{$uid},%'"
                    ." OR wo.assigned_to LIKE '%,{$uid},%' OR wo.assigned_to LIKE '%,{$uid}')", null, false)
                ->group_end();
        }
    }

    /** Combine the receiver name columns after a fetch. */
    private function _hydrate_alerts($rows) {
        foreach ($rows as $r) {
            $first = isset($r->rb_first) ? trim((string)$r->rb_first) : '';
            $last  = isset($r->rb_last)  ? trim((string)$r->rb_last)  : '';
            $r->received_by_name = trim($first.' '.$last);
        }
        return $rows;
    }

    public function count_new_alerts($user_id, $role_id) {
        if (!$this->tables_ready()) return 0;
        if ((int)$role_id === 5) return 0;                    // CEO is out of scope
        $this->_scope_alert_query($user_id, $role_id);
        return (int)$this->db->where('a.status', 'New')->count_all_results();
    }

    public function get_alerts($filter = array(), $user_id = null, $role_id = null) {
        if (!$this->tables_ready()) return array();
        $this->_scope_alert_query($user_id, $role_id);
        if (isset($filter['status']) && $filter['status'] !== '') $this->db->where('a.status', $filter['status']);
        if (!empty($filter['wo_id'])) $this->db->where('a.work_order_id', (int)$filter['wo_id']);
        return $this->_hydrate_alerts($this->db->order_by('a.created_at', 'DESC')->limit(200)->get()->result());
    }

    public function get_recent_alerts($user_id, $role_id, $limit = 6) {
        if (!$this->tables_ready()) return array();
        $this->_scope_alert_query($user_id, $role_id);
        return $this->_hydrate_alerts($this->db->order_by('a.created_at', 'DESC')->limit((int)$limit)->get()->result());
    }

    public function mark_alert_read($id, $user_id = null) {
        if (!$this->tables_ready()) return false;
        return $this->db->where('id', (int)$id)->update('spare_part_alerts', array(
            'status'  => 'Read',
            'read_at' => date('Y-m-d H:i:s'),
            'read_by' => $user_id,
        ));
    }

    public function mark_all_alerts_read($user_id, $role_id) {
        if (!$this->tables_ready()) return 0;
        // Resolve the visible "New" alerts first, then update by id — an
        // UPDATE with joins is not portable across the supported drivers.
        $this->_scope_alert_query($user_id, $role_id);
        $rows = $this->db->where('a.status', 'New')->get()->result();
        $ids  = array();
        foreach ($rows as $r) $ids[] = (int)$r->id;
        if (empty($ids)) return 0;

        $this->db->where_in('id', $ids)->update('spare_part_alerts', array(
            'status'  => 'Read',
            'read_at' => date('Y-m-d H:i:s'),
            'read_by' => $user_id,
        ));
        return count($ids);
    }

    /* ── Allocation ─────────────────────────────────────── */

    public function get_line($line_id) {
        if (!$this->db->table_exists('wo_spare_parts')) return null;
        return $this->db->where('id', (int)$line_id)->get('wo_spare_parts')->row();
    }

    public function get_allocation($id) {
        if (!$this->tables_ready()) return null;
        return $this->db->where('id', (int)$id)->get('wo_spare_allocations')->row();
    }

    /**
     * Reserve stock against one requested part line.
     *
     * @return array ['ok' => bool, 'error' => string|null, 'allocation_id' => int|null]
     */
    public function allocate($line_id, $qty, $user_id = null) {
        if (!$this->tables_ready()) return array('ok' => false, 'error' => 'Spare parts tracking tables are not set up yet (run migration 006).');

        $qty = (float)$qty;
        if ($qty <= 0) return array('ok' => false, 'error' => 'Quantity must be greater than zero.');
        if (!$this->db->trans_begin()) return array('ok' => false, 'error' => 'A stock allocation transaction could not be started.');

        // Lock the demand line and its WO before recalculating outstanding demand.
        $line_query = $this->db->query(
            'SELECT * FROM '.$this->db->dbprefix('wo_spare_parts').' WHERE id = ? FOR UPDATE',
            array((int)$line_id)
        );
        $base_line = $line_query ? $line_query->row() : null;
        if (!$base_line) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'This part line is not on a pending work order (it may have been completed or removed).');
        }

        $locked_wo = $this->db->query(
            'SELECT id FROM '.$this->db->dbprefix('work_orders').' WHERE id = ? FOR UPDATE',
            array((int)$base_line->work_order_id)
        );
        if (!$locked_wo || !$locked_wo->row()) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'This part line is not on a pending work order (it may have been completed or removed).');
        }

        $material = $this->resolve_material(
            isset($base_line->item_code) ? $base_line->item_code : '',
            isset($base_line->part_number) ? $base_line->part_number : ''
        );
        if (!$material) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'This part is not linked to an inventory item — set the correct item code first.');
        }
        $locked_material = $this->db->query(
            'SELECT id FROM '.$this->db->dbprefix('materials').' WHERE id = ? FOR UPDATE',
            array((int)$material->id)
        );
        if (!$locked_material || !$locked_material->row()) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'The linked inventory item could not be locked.');
        }

        // Re-read after acquiring the locks: another allocation may have
        // committed while this request was waiting for the row locks.
        $lines = $this->get_pending_lines(array('wo_id' => (int)$base_line->work_order_id));
        $line  = null;
        foreach ($lines as $candidate) {
            if ((int)$candidate->id === (int)$line_id) { $line = $candidate; break; }
        }
        if (!$line) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'This part line is not on a pending work order (it may have been completed or removed).');
        }
        if (!$line->material || (int)$line->material->id !== (int)$material->id) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'This part is not linked to the expected inventory item.');
        }

        $needed = $line->qty_required - $line->qty_allocated - $line->qty_issued;
        if ($qty > $needed + 0.0000001) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'Only '.rtrim(rtrim(number_format($needed, 2, '.', ''), '0'), '.').' more are required for this work order.');
        }
        if ($qty > $line->free_qty + 0.0000001) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'Only '.rtrim(rtrim(number_format($line->free_qty, 2, '.', ''), '0'), '.').' '.$line->item_unit.' of '.$line->material->item_code.' are free in stock (rest is reserved for other work orders).');
        }

        $inserted = $this->db->insert('wo_spare_allocations', array(
            'wo_spare_part_id' => (int)$line->id,
            'work_order_id'    => (int)$line->work_order_id,
            'material_id'      => (int)$line->material->id,
            'qty_allocated'    => $qty,
            'status'           => 'Allocated',
            'allocated_by'     => $user_id,
            'allocated_at'     => date('Y-m-d H:i:s'),
        ));
        $allocation_id = (int)$this->db->insert_id();
        if (!$inserted || $this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'The stock allocation could not be saved.');
        }
        if (!$this->db->trans_commit()) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'The stock allocation could not be committed.');
        }

        return array('ok' => true, 'error' => null, 'allocation_id' => $allocation_id);
    }

    /** Cancel a reservation — the stock becomes free for other work orders again. */
    public function release_allocation($allocation_id, $user_id = null) {
        if (!$this->tables_ready()) return false;
        $initial = $this->get_allocation($allocation_id);
        if (!$initial || $initial->status !== 'Allocated') return false;
        if (!$this->db->trans_begin()) return false;

        // Use the WO as the common lock root for release, issue and WO close.
        $locked_wo = $this->db->query(
            'SELECT id FROM '.$this->db->dbprefix('work_orders').' WHERE id = ? FOR UPDATE',
            array((int)$initial->work_order_id)
        );
        $locked_alloc = $this->db->query(
            'SELECT * FROM '.$this->db->dbprefix('wo_spare_allocations').' WHERE id = ? FOR UPDATE',
            array((int)$allocation_id)
        );
        $alloc = $locked_alloc ? $locked_alloc->row() : null;
        if (!$locked_wo || !$locked_wo->row() || !$alloc
            || (int)$alloc->work_order_id !== (int)$initial->work_order_id
            || $alloc->status !== 'Allocated') {
            $this->db->trans_rollback();
            return false;
        }

        $ok = $this->db->where('id', (int)$allocation_id)->where('status', 'Allocated')
            ->update('wo_spare_allocations', array(
                'status' => 'Released',
                'notes'  => 'Released by user '.$user_id.' on '.date('Y-m-d H:i'),
            ));
        if (!$ok || $this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            return false;
        }
        if (!$this->db->trans_commit()) {
            $this->db->trans_rollback();
            return false;
        }
        return true;
    }

    /**
     * Hand the allocated stock out physically: books an ISSUE movement on the
     * inventory and marks the allocation as issued.
     *
     * @return array ['ok' => bool, 'error' => string|null]
     */
    public function issue_allocation($allocation_id, $user_id = null) {
        if (!$this->tables_ready()) return array('ok' => false, 'error' => 'Spare parts tracking tables are not set up yet (run migration 006).');
        $initial = $this->get_allocation($allocation_id);
        if (!$initial) return array('ok' => false, 'error' => 'Allocation not found.');
        if ($initial->status === 'Issued') return array('ok' => false, 'error' => 'This allocation was already issued.');
        if ($initial->status !== 'Allocated') return array('ok' => false, 'error' => 'Only active allocations can be issued.');
        if (!$this->db->trans_begin()) return array('ok' => false, 'error' => 'A stock issue transaction could not be started.');

        // Lock in the same order as WO completion (work order, allocation,
        // material) so a manual issue cannot race the automatic close-out.
        $locked_wo = $this->db->query(
            'SELECT id FROM '.$this->db->dbprefix('work_orders').' WHERE id = ? FOR UPDATE',
            array((int)$initial->work_order_id)
        );
        if (!$locked_wo || !$locked_wo->row()) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'The work order behind this allocation no longer exists.');
        }
        $locked_alloc = $this->db->query(
            'SELECT * FROM '.$this->db->dbprefix('wo_spare_allocations').' WHERE id = ? FOR UPDATE',
            array((int)$allocation_id)
        );
        $alloc = $locked_alloc ? $locked_alloc->row() : null;
        if (!$alloc || (int)$alloc->work_order_id !== (int)$initial->work_order_id) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'Allocation not found.');
        }
        if ($alloc->status === 'Issued') {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'This allocation was already issued.');
        }
        if ($alloc->status !== 'Allocated') {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'Only active allocations can be issued.');
        }

        $locked_material = $this->db->query(
            'SELECT * FROM '.$this->db->dbprefix('materials').' WHERE id = ? FOR UPDATE',
            array((int)$alloc->material_id)
        );
        $mat = $locked_material ? $locked_material->row() : null;
        $wo  = $this->db->where('id', (int)$alloc->work_order_id)->get('work_orders')->row();
        if (!$wo || !$mat) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'The work order or inventory item behind this allocation no longer exists.');
        }

        $mm = $this->_materials_model();
        if (!$mm) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'The inventory module is not available.');
        }

        // Preserve the existing quantity conversion and GRN/unit semantics.
        $qty = (int)round((float)$alloc->qty_allocated);
        if ($qty <= 0) $qty = 1;
        $move = $mm->add_stock_movement((int)$mat->id, 'ISSUE', $qty,
            'Issued against '.$wo->wo_number.' (spare part allocation #'.$alloc->id.')', $user_id);
        if ($move === false) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'The stock issue could not be booked. Check that enough stock is available.');
        }

        $updated = $this->db->where('id', (int)$allocation_id)->where('status', 'Allocated')
            ->update('wo_spare_allocations', array(
                'status'    => 'Issued',
                'issued_at' => date('Y-m-d H:i:s'),
            ));
        if (!$updated || $this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'The stock issue could not be booked.');
        }
        if (!$this->db->trans_commit()) {
            $this->db->trans_rollback();
            return array('ok' => false, 'error' => 'The stock issue could not be committed.');
        }
        return array('ok' => true, 'error' => null);
    }

    /**
     * When a work order is completed/closed, everything still reserved for it
     * is treated as consumed: the ISSUE movements are booked automatically so
     * the stock balance stays truthful.
     *
     * @return int number of allocations auto-issued
     */
    public function auto_issue_for_wo($wo_id, $user_id = null) {
        if (!$this->tables_ready()) return 0;
        $rows = $this->db->where('work_order_id', (int)$wo_id)
            ->where('status', 'Allocated')
            ->get('wo_spare_allocations')->result();
        $count = 0;
        foreach ($rows as $alloc) {
            $res = $this->issue_allocation($alloc->id, $user_id);
            if (!empty($res['ok'])) $count++;
        }
        return $count;
    }

    /* ── Purchasing visibility ──────────────────────────── */

    /** Quantities still on open supplier orders, per material (id => qty outstanding). */
    public function get_on_order_by_material() {
        $out = array();
        if (!$this->db->table_exists('spare_order_items') || !$this->db->table_exists('spare_orders')) return $out;
        $rows = $this->db->select('i.material_id, SUM(COALESCE(i.qty_ordered,0) - COALESCE(i.qty_received,0)) AS outstanding')
            ->from('spare_order_items i')
            ->join('spare_orders o', 'o.id = i.order_id')
            ->where('i.material_id IS NOT NULL', null, false)
            ->where_not_in('o.status', array('Received at Store', 'Verified & Closed', 'Cancelled'))
            ->group_by('i.material_id')
            ->get()->result();
        foreach ($rows as $r) {
            $qty = (float)$r->outstanding;
            if ($qty > 0) $out[(int)$r->material_id] = $qty;
        }
        return $out;
    }
}
