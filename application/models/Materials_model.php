<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Materials_model — data access for the Inventory module (`materials`).
 *
 * The stock model of this application
 * ───────────────────────────────────
 *   materials.final_qty = initial_stock + qty_received − qty_sortie
 *
 * `initial_stock` is the opening balance of the item, `qty_received` /
 * `qty_sortie` are the accumulated in/out totals and `final_qty` is the stored
 * balance the whole application reads (list filters, KPIs, alerts).
 * Movements are audited in `material_transactions` (RECEIVE / ISSUE, with
 * stock_before / stock_after).  Nothing in this model writes a quantity without
 * keeping the three columns and the movement log consistent:
 *
 *   • add_stock_movement()  — THE single writer. One atomic UPDATE + one
 *                             transaction row, refusing an issue that would
 *                             drive the balance below zero and refusing to
 *                             post the same import_key twice. Used by
 *                             Receive / Issue, the goods receipt, the Excel
 *                             import, the work-order issue and the quantity
 *                             adjustments made from the item form.
 *   • set_opening_stock()   — replaces the opening balance of an item that has
 *                             no recorded movements yet, and logs the change.
 *
 * There is no other way to move stock: the legacy receive_stock() /
 * issue_stock() pair (which updated the totals without a movement row, and
 * is what allowed the goods receipt to double-post) was removed in Wave 2.
 *
 * The importer needs to match spreadsheet values against the database; the
 * comparison helpers (key(), signature(), get_*_lookup()) therefore live here
 * so that the manual form, the Excel import and the export all agree on what
 * "the same value" means.
 */
class Materials_model extends CI_Model {

    /** Columns the UI / importer are allowed to write directly. */
    const MASTER_FIELDS = array(
        'item_code', 'category', 'description', 'part_number_size',
        'unit', 'application', 'location',
    );

    /**
     * Re-order control and valuation columns added by
     * migrations/007_excel_alignment.sql to match the manual "Stock List"
     * sheet.  They are only written when the column really exists, so the
     * module keeps working on a database where 007 has not been applied yet.
     */
    const EXTENDED_FIELDS = array(
        'min_stock', 'max_stock', 'unit_price_ugx', 'unit_price_usd',
        'supplier_id', 'supplier_name',
    );

    /** Movement columns added by 007 (Sortie / Receipts registers). */
    const MOVEMENT_FIELDS = array(
        'txn_date', 'reference_no', 'allocation_type', 'equipment_id',
        'allocation_label', 'person', 'supplier_id', 'supplier_name',
        'qty_approved', 'unit_price', 'currency', 'source',
    );

    /** Cached results of the two schema probes. */
    protected $_has_extended = null;
    protected $_has_movement = null;

    /** Stock columns (never written without going through the stock helpers). */
    const STOCK_FIELDS = array('initial_stock', 'qty_received', 'qty_sortie', 'final_qty');

    /** Categories / units offered when the table is empty (the UI list). */
    const DEFAULT_CATEGORIES = array(
        'CONSUMABLE', 'SPARE PART', 'LUBRICANT', 'ELECTRICAL', 'HYDRAULIC', 'TOOL', 'OTHER',
    );
    const DEFAULT_UNITS = array('pcs', 'L', 'Kg', 'm', 'Box', 'Cyl', 'Roll', 'Pair', 'Set', 'Tube');

    /** Low-stock threshold used by the dashboard and the list filters. */
    const LOW_STOCK = 5;

    /** Last database error message (for user-facing messages). */
    protected $_last_error = null;

    private function _table_ok() {
        return $this->db->table_exists('materials');
    }

    // ── READ ────────────────────────────────────────────────
    public function get_all($filter = array()) {
        if (!$this->_table_ok()) return array();
        try {
            $this->db->select('*')->from('materials');
            if (!empty($filter['category'])) {
                $this->db->where('category', $filter['category']);
            }
            if (!empty($filter['location'])) {
                $this->db->where('location', $filter['location']);
            }
            if (!empty($filter['stock'])) {
                if ($filter['stock'] === 'low') {
                    $this->db->where('final_qty >', 0);
                    $this->db->where('final_qty <=', 5);
                } elseif ($filter['stock'] === 'out') {
                    $this->db->where('final_qty <=', 0);
                } elseif ($filter['stock'] === 'ok') {
                    $this->db->where('final_qty >', 5);
                }
            }
            if (!empty($filter['search'])) {
                $s = $this->db->escape_like_str($filter['search']);
                $this->db->where("(item_code LIKE '%{$s}%' OR description LIKE '%{$s}%' OR category LIKE '%{$s}%')");
            }
            $result = $this->db->order_by('category')->order_by('item_code')->get()->result();
            return $result ? $result : array();
        } catch (Exception $e) {
            $this->_last_error = $e->getMessage();
            log_message('error', 'Materials_model::get_all() - '.$e->getMessage());
            return array();
        }
    }

    /** Number of rows in the catalogue (used to verify an export is complete). */
    public function count_items() {
        if (!$this->_table_ok()) return 0;
        return (int)$this->db->count_all('materials');
    }

    public function get_by_id($id) {
        if (!$this->_table_ok()) return NULL;
        return $this->db->where('id', $id)->get('materials')->row();
    }

    public function get_by_code($code) {
        if (!$this->_table_ok()) return NULL;
        return $this->db->where('item_code', strtoupper($code))->get('materials')->row();
    }

    /**
     * Is the item code already used?  The database column is UNIQUE with a
     * case-insensitive collation, so "con001" collides with "CON001" and the
     * comparison here is case-insensitive in the same way.  Repeated spaces are
     * collapsed first ("con   001" is the same code as "con 001", exactly what
     * the trimmed/unique column would do), while a code that differs by real
     * spacing ("CON 001" vs "CON001") remains a different code — as the index
     * treats it.
     *
     * @param string   $code
     * @param int|null $excludeId  ignore this row (edit / import update)
     * @return object|null the conflicting row (id, item_code, description)
     */
    public function find_by_code_key($code, $excludeId = null) {
        if (!$this->_table_ok()) return null;
        $key = $this->key($code);
        if ($key === '') return null;
        $rows = $this->db->select('id, item_code, description, unit, final_qty')
                         ->get('materials')->result();
        foreach ($rows as $r) {
            if ($excludeId !== null && (int)$r->id === (int)$excludeId) continue;
            if ($this->key($r->item_code) === $key) return $r;
        }
        return null;
    }

    public function code_exists($code, $excludeId = null) {
        return $this->find_by_code_key($code, $excludeId) !== null;
    }

    public function get_categories() {
        if (!$this->_table_ok()) return array();
        $this->db->distinct();
        $this->db->select('category');
        $this->db->order_by('category');
        return $this->db->get('materials')->result();
    }

    public function get_locations() {
        if (!$this->_table_ok()) return array();
        $this->db->distinct();
        $this->db->select('location');
        $this->db->where('location !=', '');
        $this->db->order_by('location');
        return $this->db->get('materials')->result();
    }

    public function get_low_stock($limit = 10) {
        if (!$this->_table_ok()) return array();
        return $this->db->where('final_qty >', -999)->where('final_qty <=', 5)
            ->order_by('final_qty', 'ASC')->limit($limit)->get('materials')->result();
    }

    public function get_recent_movements($limit = 10) {
        if (!$this->db->table_exists('material_transactions')) return array();
        return $this->db
            ->select('mt.*, m.description, m.unit, CONCAT(u.first_name," ",u.last_name) as user_name')
            ->from('material_transactions mt')
            ->join('materials m',   'm.id = mt.material_id', 'left')
            ->join('users u',       'u.id = mt.created_by',  'left')
            ->order_by('mt.created_at', 'DESC')
            ->limit($limit)->get()->result();
    }

    // ── COMPARISON / LOOKUP HELPERS (shared by UI + importer) ──

    /** Case-insensitive, whitespace-collapsed comparison key. */
    public function key($s) {
        $s = preg_replace('/\s+/u', ' ', trim((string)$s));
        if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
        return strtolower($s);
    }

    /**
     * Signature of an inventory record: description + part number + unit.
     * Two records with the same signature are "the same inventory record"
     * (same item, different code) and the import reports them as duplicates.
     * Returns null when there is nothing to compare (no description).
     */
    public function signature($row) {
        $get = function($k) use ($row) {
            if (is_array($row))  return isset($row[$k]) ? $row[$k] : '';
            if (is_object($row)) return isset($row->$k) ? $row->$k : '';
            return '';
        };
        $desc = $this->key($get('description'));
        if ($desc === '') return null;
        return $desc.'|'.$this->key($get('part_number_size')).'|'.$this->key($get('unit'));
    }

    /**
     * Category spellings that actually exist in the database plus the default
     * list.  Because `category` is free text and the legacy data contains
     * FILTER / Filter / filter, the most frequently used spelling wins so that
     * an import can never fragment the data further.
     *
     * @return array ['list' => [display, ...], 'map' => [key => display], 'counts' => [display => n]]
     */
    public function get_category_lookup() {
        return $this->_lookup('category', self::DEFAULT_CATEGORIES);
    }

    /** Unit spellings (same rules as categories: pc / PC / Pc → "pc"). */
    public function get_unit_lookup() {
        return $this->_lookup('unit', self::DEFAULT_UNITS);
    }

    private function _lookup($column, $defaults) {
        $list = array(); $map = array(); $counts = array();
        if ($this->_table_ok()) {
            $rows = $this->db->select($column.' AS v, COUNT(*) AS n')
                             ->group_by($column)
                             ->order_by('n', 'DESC')->order_by($column, 'ASC')
                             ->get('materials')->result();
            foreach ($rows as $r) {
                $display = trim((string)$r->v);
                if ($display === '') continue;
                $k = $this->key($display);
                if (isset($map[$k])) continue;          // most frequent spelling wins
                $map[$k]  = $display;
                $list[]   = $display;
                $counts[$display] = (int)$r->n;
            }
        }
        foreach ($defaults as $d) {
            $k = $this->key($d);
            if (isset($map[$k])) continue;
            $map[$k] = $d;
            $list[]  = $d;
        }
        return array('list' => $list, 'map' => $map, 'counts' => $counts);
    }

    /**
     * Index used for duplicate detection during import:
     *   'by_code'      normalised code      => row summary
     *   'by_signature' description|part|unit => [row summary, ...]
     * All lookups are normalised with key() so matching is case/space insensitive.
     */
    public function get_existing_index() {
        $byCode = array(); $bySig = array();
        if (!$this->_table_ok()) return array('by_code' => $byCode, 'by_signature' => $bySig);

        $rows = $this->db
            ->select('id, item_code, description, part_number_size, unit, category,
                      initial_stock, qty_received, qty_sortie, final_qty, location')
            ->get('materials')->result();

        foreach ($rows as $r) {
            $summary = array(
                'id'            => (int)$r->id,
                'item_code'     => $r->item_code,
                'description'   => $r->description,
                'unit'          => $r->unit,
                'category'      => $r->category,
                'part_number_size' => $r->part_number_size,
                'initial_stock' => (int)$r->initial_stock,
                'qty_received'  => (int)$r->qty_received,
                'qty_sortie'    => (int)$r->qty_sortie,
                'final_qty'     => (int)$r->final_qty,
                'location'      => $r->location,
            );
            $byCode[$this->key($r->item_code)] = $summary;
            $sig = $this->signature($r);
            if ($sig !== null) $bySig[$sig][] = $summary;
        }
        return array('by_code' => $byCode, 'by_signature' => $bySig);
    }

    /**
     * Supplier information per material (empty when the table has no rows).
     *
     * The supplier company is stored in `suppliers.company_name` (the column
     * every other module — Supplier_model, Order_model, the views — reads).
     * The lookup is also defensive on purpose: it only feeds the cosmetic
     * SUPPLIER(S) column of the export, so a schema problem here must never
     * turn an export into an HTTP 500.  A failed query is logged and treated
     * as "no supplier information".
     */
    public function get_supplier_map() {
        if (!$this->db->table_exists('supplier_materials')
            || !$this->db->table_exists('suppliers')) return array();

        try {
            $query = $this->db
                ->select('sm.material_id, s.company_name AS supplier_name, sm.part_number,
                          sm.unit_price, sm.currency, sm.is_preferred, sm.lead_time_days')
                ->from('supplier_materials sm')
                ->join('suppliers s', 's.id = sm.supplier_id', 'left')
                ->order_by('sm.is_preferred', 'DESC')
                ->order_by('s.company_name', 'ASC')
                ->get();
        } catch (Exception $e) {
            log_message('error', 'Materials_model::get_supplier_map() - '.$e->getMessage());
            return array();
        }

        // With db_debug disabled a failed query returns FALSE instead of
        // throwing; calling ->result() on it used to be a fatal error.
        if (!$query) {
            log_message('error', 'Materials_model::get_supplier_map() - supplier lookup failed'
                .($this->last_db_error() ? ': '.$this->last_db_error() : ''));
            return array();
        }

        $out = array();
        foreach ($query->result() as $r) {
            $id = (int)$r->material_id;
            if (!isset($out[$id])) $out[$id] = array('suppliers' => array(), 'parts' => array());
            if (!empty($r->supplier_name)) {
                $out[$id]['suppliers'][] = $r->supplier_name
                    .(empty($r->is_preferred) ? '' : ' (preferred)');
            }
            if (!empty($r->part_number)) $out[$id]['parts'][] = $r->part_number;
        }
        return $out;
    }

    // ── STOCK HELPERS ───────────────────────────────────────

    /**
     * Balance derived from the three stock columns.  The stored `final_qty`
     * stays authoritative for reports (see the module documentation) but the
     * user interface has always displayed this value.
     */
    public function derived_qty($row) {
        if (is_array($row)) {
            return (int)(isset($row['initial_stock']) ? $row['initial_stock'] : 0)
                 + (int)(isset($row['qty_received'])  ? $row['qty_received']  : 0)
                 - (int)(isset($row['qty_sortie'])    ? $row['qty_sortie']    : 0);
        }
        return (int)$row->initial_stock + (int)$row->qty_received - (int)$row->qty_sortie;
    }

    /** 'out' (≤0), 'low' (≤5) or 'ok' — the three levels of the list filter. */
    public function stock_level($qty) {
        $qty = (int)$qty;
        if ($qty <= 0) return 'out';
        if ($qty <= self::LOW_STOCK) return 'low';
        return 'ok';
    }

    // ── WRITE ───────────────────────────────────────────────
    public function insert($data) {
        $this->_reset_error();
        $data['final_qty']  = (int)$data['initial_stock'] + (int)$data['qty_received'] - (int)$data['qty_sortie'];
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->_insert_row($data);
    }

    /**
     * INSERT helper with a reliable outcome: the return value of the query, the
     * driver error AND the insert id are all checked, because a driver may
     * report a stale insert id (or no message at all) after a rejected insert.
     *
     * @return int|false new id
     */
    protected function _insert_row($data) {
        $ok  = $this->db->insert('materials', $data);
        $err = $this->last_db_error();
        if ($ok === false || $err !== null) {
            $this->_last_error = $err !== null ? $err : 'The record could not be saved (the database rejected the insert).';
            return false;
        }
        $id = (int)$this->db->insert_id();
        if ($id <= 0) {
            $this->_last_error = 'The record was not created (no row id was returned).';
            return false;
        }
        return $id;
    }

    public function update($id, $data) {
        $this->_reset_error();
        $data['final_qty']  = (int)$data['initial_stock'] + (int)$data['qty_received'] - (int)$data['qty_sortie'];
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->where('id', $id)->update('materials', $data);
    }

    /**
     * Insert an item from validated input (manual form or Excel import).
     * Only whitelisted columns are written and the balance is derived.
     *
     * @return int|false new id
     */
    public function insert_item($data) {
        $this->_reset_error();
        $row = $this->_filter_fields($data);
        if (!isset($row['item_code']) || $row['item_code'] === '') {
            $this->_last_error = 'item_code is required';
            return false;
        }
        // initial_stock is a stock column and therefore NOT part of
        // MASTER_FIELDS — take it from the raw input explicitly.
        $row['initial_stock'] = isset($data['initial_stock']) ? (int)$data['initial_stock'] : 0;
        if ($row['initial_stock'] < 0) {
            $this->_last_error = 'Opening stock cannot be negative';
            return false;
        }
        $row['qty_received'] = 0;
        $row['qty_sortie']   = 0;
        $row['final_qty']    = (int)$row['initial_stock'];
        $row['created_at']   = date('Y-m-d H:i:s');
        $row['updated_at']   = date('Y-m-d H:i:s');
        return $this->_insert_row($row);
    }

    /**
     * Update master data of an item.  The stock columns are only touched when
     * $withStock is true (the item form) — an import can therefore never
     * overwrite a balance by accident.
     */
    public function update_item($id, $data, $withStock = false) {
        $this->_reset_error();
        $row = $this->_filter_fields($data);
        if ($withStock) {
            // Stock columns are only written when a quantity really changed.
            // This keeps the stored balance (and any legacy inconsistency the
            // Stock Check column reports) untouched when the user only edits
            // master data such as the description or the location.
            $current = $this->get_by_id($id);
            if ($current) {
                $changed = false;
                foreach (array('initial_stock', 'qty_received', 'qty_sortie') as $f) {
                    if (array_key_exists($f, $data) && (int)$data[$f] !== (int)$current->$f) $changed = true;
                }
                if ($changed) {
                    foreach (array('initial_stock', 'qty_received', 'qty_sortie') as $f) {
                        if (array_key_exists($f, $data)) $row[$f] = (int)$data[$f];
                    }
                    if (isset($row['initial_stock'], $row['qty_received'], $row['qty_sortie'])) {
                        $row['final_qty'] = $row['initial_stock'] + $row['qty_received'] - $row['qty_sortie'];
                    }
                }
            }
        }
        if (empty($row)) return false;
        $row['updated_at'] = date('Y-m-d H:i:s');
        $ok  = $this->db->where('id', (int)$id)->update('materials', $row);
        $err = $this->last_db_error();
        if ($ok === false || $err !== null) {
            $this->_last_error = $err !== null ? $err : 'The change could not be saved (the database rejected the update).';
            return false;
        }
        return true;
    }

    public function delete($id) {
        $this->_reset_error();
        return $this->db->where('id', $id)->delete('materials');
    }

    /*
     * receive_stock() / issue_stock() lived here until Wave 2. They updated the
     * stock columns without writing a movement row, and the goods receipt used
     * them alongside a total-overwriting qty_received update — which is how the
     * same delivery could be counted twice (audit finding F-05). Everything now
     * goes through add_stock_movement() above.
     */

    /**
     * Move stock in or out and record the movement — the single entry point
     * used by Receive / Issue, the Excel import and the item-form adjustments.
     *
     * The UPDATE is expression based, so it is atomic and cannot lose a
     * concurrent movement:
     *   RECEIVE: qty_received += q ; final_qty = initial + (qty_received + q) − qty_sortie
     *   ISSUE  : qty_sortie   += q ; final_qty = initial + qty_received − (qty_sortie + q)
     *
     * @return array|false ['before' => int, 'after' => int]
     */
    public function add_stock_movement($id, $type, $qty, $notes = null, $user_id = null, $context = array()) {
        $this->_reset_error();
        $id     = (int)$id;
        $amount = (float)$qty;
        $qty    = (int)round($amount);

        /*
         * Whole units only. The stock counters are integers, so a fractional
         * movement cannot be represented: silently rounding 2.5 to 3 would put
         * a wrong number in both the balance and the movement log, so it is
         * refused instead. (The historical Excel rows do contain fractional
         * quantities — they are migrated history and are flagged
         * affects_balance = 0; see the stock reconciliation.)
         */
        if ($id <= 0 || $amount <= 0 || abs($amount - $qty) > 0.0001) {
            $this->_last_error = 'Quantity must be a positive whole number';
            return false;
        }

        $type = ($type === 'ISSUE') ? 'ISSUE' : 'RECEIVE';

        /*
         * Idempotency. A caller that supplies an import_key is saying "this
         * movement is this document line". If that key is already in the
         * ledger, the movement was posted before (a double-clicked GRN, a
         * re-run import) and is reported as skipped instead of posted again.
         * Migration 013 adds the unique index that enforces this at the
         * database level as well.
         */
        $import_key = isset($context['import_key']) ? trim((string)$context['import_key']) : '';
        // has_movement_fields() is the same probe that gates the context
        // columns below: it is false on a database where migration 007 has not
        // run, so there is no import_key column to look in either.
        if ($import_key !== '' && $this->has_movement_fields()) {
            $existing = $this->db->select('id, stock_before, stock_after')
                ->where('import_key', $import_key)->limit(1)
                ->get('material_transactions')->row();
            if ($existing) {
                return array(
                    'before'      => $existing->stock_before,
                    'after'       => $existing->stock_after,
                    'movement_id' => (int)$existing->id,
                    'skipped'     => TRUE,
                );
            }
        }

        if (!$this->db->trans_begin()) {
            $this->_last_error = 'A stock movement transaction could not be started';
            return false;
        }

        // Serialize movements for this item so the logged before/after values
        // agree with the balance update even when two receipts/issues overlap.
        $locked = $this->db->query(
            'SELECT * FROM '.$this->db->dbprefix('materials').' WHERE id = ? FOR UPDATE',
            array($id)
        );
        $row = $locked ? $locked->row() : null;
        if (!$row) {
            $this->db->trans_rollback();
            $this->_last_error = 'Item not found or could not be locked';
            return false;
        }

        $before = $this->derived_qty($row);

        /*
         * A shelf cannot go negative. An issue larger than the recorded
         * balance is a signal that the recorded balance is wrong (that is
         * exactly how FAS038 was found: −4 nuts) — so it needs an explicit
         * exception from the caller, and the caller records who authorised it.
         */
        if ($type === 'ISSUE' && ($before - $qty) < 0 && empty($context['allow_negative'])) {
            $this->db->trans_rollback();
            $this->_last_error = 'Issuing '.$qty.' would take the balance below zero (recorded balance: '.$before.')';
            return false;
        }

        /*
         * Write literal values computed from the row that was just locked.
         * MySQL/MariaDB evaluate the assignments of one UPDATE left to right
         * and later ones see the EARLIER results, so a final_qty expression
         * that re-adds the quantity ("initial + (qty_received + N) - sortie"
         * after "qty_received = qty_received + N") counted every movement
         * twice. The row is locked FOR UPDATE, so these numbers cannot be
         * stale.
         */
        $new_received = (int)$row->qty_received + ($type === 'RECEIVE' ? $qty : 0);
        $new_sortie   = (int)$row->qty_sortie   + ($type === 'ISSUE'   ? $qty : 0);
        $this->db->set('qty_received', $new_received);
        $this->db->set('qty_sortie',   $new_sortie);
        $this->db->set('final_qty',    (int)$row->initial_stock + $new_received - $new_sortie);
        $this->db->set('updated_at', date('Y-m-d H:i:s'));
        $ok  = $this->db->where('id', $id)->update('materials');
        $err = $this->last_db_error();
        if (!$ok || $err !== null) {
            $this->db->trans_rollback();
            $this->_last_error = $err !== null ? $err : 'The stock update failed';
            return false;
        }

        $after       = $before + ($type === 'RECEIVE' ? $qty : -$qty);
        $movement_id = (int)$this->log_transaction($id, $type, $qty, $before, $notes, $user_id, $context);
        if ($this->db->trans_status() === FALSE) {
            $err = $this->last_db_error();
            $this->db->trans_rollback();
            $this->_last_error = $err !== null ? $err : 'The movement log could not be saved';
            return false;
        }
        if (!$this->db->trans_commit()) {
            $this->db->trans_rollback();
            $this->_last_error = 'The stock movement transaction could not be committed';
            return false;
        }

        return array('before' => $before, 'after' => $after,
                     'movement_id' => $movement_id, 'skipped' => FALSE);
    }

    /**
     * Record the opening balance of a newly created item as a movement WITHOUT
     * touching the stock columns: `initial_stock` already holds the balance, so
     * adding it to qty_received would double count it.  The log entry makes the
     * opening balance traceable (stock_before 0 → stock_after = opening).
     */
    public function log_opening_stock($id, $qty, $user_id = null, $notes = null) {
        $this->_reset_error();
        $id  = (int)$id;
        $qty = (int)round((float)$qty);
        if ($id <= 0 || $qty <= 0) return false;
        $this->log_transaction($id, 'RECEIVE', $qty, 0,
            $notes !== null ? $notes : 'Opening stock', $user_id);
        return true;
    }

    /**
     * Replace the opening balance of an item.  Only allowed while the item has
     * no recorded movements (qty_received = qty_sortie = 0), so existing stock
     * can never be overwritten or double counted by an import.  The difference
     * is logged as a movement.
     */
    public function set_opening_stock($id, $qty, $user_id = null, $notes = null) {
        $this->_reset_error();
        $id  = (int)$id;
        $qty = (int)round((float)$qty);
        if ($id <= 0 || $qty < 0) return false;

        $row = $this->get_by_id($id);
        if (!$row) return false;
        if ((int)$row->qty_received !== 0 || (int)$row->qty_sortie !== 0) {
            $this->_last_error = 'The item already has recorded movements';
            return false;
        }

        $old   = (int)$row->initial_stock;
        $this->db->where('id', $id)->update('materials', array(
            'initial_stock' => $qty,
            'final_qty'     => $qty + (int)$row->qty_received - (int)$row->qty_sortie,
            'updated_at'    => date('Y-m-d H:i:s'),
        ));
        $err = $this->last_db_error();
        if ($err !== null) { $this->_last_error = $err; return false; }

        $diff = $qty - $old;
        if ($diff !== 0) {
            $this->log_transaction($id, $diff > 0 ? 'RECEIVE' : 'ISSUE', abs($diff), $old,
                $notes !== null ? $notes : 'Opening stock adjusted', $user_id);
        }
        return true;
    }

    // ── TRANSACTIONS ────────────────────────────────────────
    /**
     * Write one movement row.
     *
     * $context carries the register fields the manual Excel sheets kept and
     * the old table could not hold — the business date, the requisition /
     * MRN number, what the issue was charged to (a machine or a department),
     * who collected it and, for a receipt, the supplier and price.  They are
     * written only when migration 007 has added the columns.
     */
    public function log_transaction($material_id, $type, $qty, $stock_before, $notes, $user_id, $context = array()) {
        if (!$this->db->table_exists('material_transactions')) return 0;
        $row = array(
            'material_id'   => $material_id,
            'type'          => $type,
            'qty'           => $qty,
            'stock_before'  => $stock_before,
            'stock_after'   => $stock_before + ($type === 'RECEIVE' ? $qty : -$qty),
            'notes'         => $notes,
            'created_by'    => $user_id,
            'created_at'    => date('Y-m-d H:i:s'),
        );
        if ($this->has_movement_fields()) {
            foreach (self::MOVEMENT_FIELDS as $f) {
                if (!array_key_exists($f, $context)) continue;
                $v = $context[$f];
                $row[$f] = ($v === '' || $v === null) ? NULL : $v;
            }
            if (empty($row['txn_date'])) $row['txn_date'] = date('Y-m-d');
            if (empty($row['source']))   $row['source']   = 'Manual';
            // A movement entered in the system always moves the balance;
            // only the migrated Excel history is flagged 0.
            $row['affects_balance'] = 1;
        }
        $this->db->insert('material_transactions', $row);
        return (int)$this->db->insert_id();
    }

    public function get_transactions($filter = array()) {
        if (!$this->db->table_exists('material_transactions')) return array();
        $dateCol = $this->has_movement_fields()
            ? 'COALESCE(mt.txn_date, DATE(mt.created_at))'
            : 'DATE(mt.created_at)';
        $this->db
            ->select('mt.*, m.item_code, m.description, m.unit, m.category,
                      CONCAT(u.first_name," ",u.last_name) as user_name', FALSE)
            ->select($dateCol.' AS movement_date', FALSE)
            ->from('material_transactions mt')
            ->join('materials m', 'm.id = mt.material_id', 'left')
            ->join('users u',     'u.id = mt.created_by',  'left');
        if ($this->has_movement_fields()) {
            $this->db->select('e.registration_no AS equipment_code', FALSE)
                     ->join('equipment e', 'e.id = mt.equipment_id', 'left');
        }
        if (!empty($filter['type']))      $this->db->where('mt.type',            $filter['type']);
        if (!empty($filter['item_code'])) $this->db->like('m.item_code',         $filter['item_code']);
        if (!empty($filter['date_from'])) $this->db->where($dateCol.' >=', $filter['date_from']);
        if (!empty($filter['date_to']))   $this->db->where($dateCol.' <=', $filter['date_to']);
        $limit = isset($filter['limit']) ? (int)$filter['limit'] : 200;
        $this->db->order_by($dateCol, 'DESC', FALSE)->order_by('mt.id', 'DESC');
        if ($limit > 0) $this->db->limit($limit);
        return $this->db->get()->result();
    }

    // ── DASHBOARD KPIs ──────────────────────────────────────
    public function get_kpi() {
        if (!$this->_table_ok()) return array();
        $rows = $this->db->get('materials')->result();
        $kpi  = array('items'=>0,'out'=>0,'low'=>0,'ok'=>0,
                      'total_received'=>0,'total_issued'=>0,'total_stock'=>0);
        foreach ($rows as $r) {
            $f = (int)$r->initial_stock + (int)$r->qty_received - (int)$r->qty_sortie;
            $kpi['items']++;
            $kpi['total_received'] += $r->qty_received;
            $kpi['total_issued']   += $r->qty_sortie;
            $kpi['total_stock']    += max(0, $f);
            if ($f <= 0)       $kpi['out']++;
            elseif ($f <= 5)   $kpi['low']++;
            else               $kpi['ok']++;
        }
        return $kpi;
    }

    // ── TRANSACTION CONTROL (import safety) ─────────────────

    /** True when the driver exposes transactions (mysqli / pdo in CI3). */
    public function can_transact() {
        return method_exists($this->db, 'trans_begin');
    }

    public function begin_transaction() {
        if ($this->can_transact()) { $this->db->trans_begin(); return true; }
        return false;
    }

    public function commit_transaction() {
        if (!$this->can_transact()) return true;
        $this->db->trans_commit();
        return $this->transaction_status();
    }

    public function rollback_transaction() {
        if (!$this->can_transact()) return true;
        $this->db->trans_rollback();
        return true;
    }

    /** FALSE when a query inside the transaction failed. */
    public function transaction_status() {
        if (!$this->can_transact()) return true;
        $status = $this->db->trans_status();
        return ($status === false) ? false : true;
    }

    public function in_transaction() {
        return method_exists($this->db, 'trans_status') && $this->db->trans_depth() > 0;
    }

    // ── HELPERS ─────────────────────────────────────────────

    /** Keep only the columns this module owns. */
    protected function _filter_fields($data) {
        $out = array();
        foreach (self::MASTER_FIELDS as $f) {
            if (array_key_exists($f, $data)) $out[$f] = $data[$f];
        }
        if ($this->has_extended_fields()) {
            foreach (self::EXTENDED_FIELDS as $f) {
                if (!array_key_exists($f, $data)) continue;
                $v = $data[$f];
                $out[$f] = ($v === '' || $v === null) ? NULL : $v;
            }
        }
        return $out;
    }

    /**
     * Does the catalogue carry the re-order / pricing columns?
     * (migrations/007_excel_alignment.sql).  Probed once per request.
     */
    public function has_extended_fields() {
        if ($this->_has_extended === null) {
            $this->_has_extended = $this->_table_ok()
                && $this->db->field_exists('min_stock', 'materials');
        }
        return $this->_has_extended;
    }

    /** Does `material_transactions` carry the register columns from 007? */
    public function has_movement_fields() {
        if ($this->_has_movement === null) {
            $this->_has_movement = $this->db->table_exists('material_transactions')
                && $this->db->field_exists('txn_date', 'material_transactions');
        }
        return $this->_has_movement;
    }

    /**
     * Items that have reached their re-order level.
     *
     * The spreadsheet stored "Alerte", "Variance" and "Qty to order" as three
     * more columns that had to be dragged down by hand after every change.
     * They are arithmetic on min/max/balance, so the system derives them:
     *
     *     alert        : final_qty <= min_stock
     *     qty_to_order : GREATEST(max_stock - final_qty, 0)
     *
     * Items without a min_stock fall back to the generic LOW_STOCK threshold,
     * so the report is still useful before every item has been given a level.
     */
    public function get_reorder_items($filter = array()) {
        if (!$this->_table_ok()) return array();
        $low = (int)self::LOW_STOCK;
        $this->db->select('*', FALSE)->from('materials');
        if ($this->has_extended_fields()) {
            $this->db->select(
                'CASE WHEN max_stock IS NOT NULL AND max_stock > final_qty
                      THEN max_stock - final_qty
                      WHEN min_stock IS NOT NULL AND min_stock > final_qty
                      THEN min_stock - final_qty
                      ELSE 0 END AS qty_to_order', FALSE);
            $this->db->where('(
                (min_stock IS NOT NULL AND final_qty <= min_stock)
                OR (min_stock IS NULL AND final_qty <= '.$low.')
            )', NULL, FALSE);
        } else {
            $this->db->select('0 AS qty_to_order', FALSE);
            $this->db->where('final_qty <=', $low);
        }
        if (!empty($filter['category'])) $this->db->where('category', $filter['category']);
        if (!empty($filter['location'])) $this->db->where('location', $filter['location']);
        if (!empty($filter['search'])) {
            $s = $this->db->escape_like_str($filter['search']);
            $this->db->where("(item_code LIKE '%{$s}%' OR description LIKE '%{$s}%')");
        }
        if ($this->has_extended_fields()) $this->db->where('is_active', 1);
        try {
            $r = $this->db->order_by('final_qty', 'ASC')->order_by('item_code')->get()->result();
            return $r ? $r : array();
        } catch (Exception $e) {
            log_message('error', 'Materials_model::get_reorder_items() - '.$e->getMessage());
            return array();
        }
    }

    /**
     * Last error of a write attempt: the model's own validation message when
     * it has one, otherwise the message reported by the database driver.
     *
     * Conservative on purpose: a driver may report a message even when the
     * query succeeded (SQLite answers "not an error" with code 0), so a write
     * must only be treated as failed when there really is an error.
     */
    public function last_db_error() {
        if ($this->_last_error !== null && $this->_last_error !== '') return $this->_last_error;
        if (!method_exists($this->db, 'error')) return null;
        $e = $this->db->error();
        if (!is_array($e)) return null;
        $code = isset($e['code']) ? (int)$e['code'] : null;
        $msg  = isset($e['message']) ? trim((string)$e['message']) : '';
        if ($msg === '') return null;
        if ($code !== null && $code === 0 && strcasecmp($msg, 'not an error') === 0) return null;
        return $msg;
    }

    /** Forget the previous error before a new write attempt. */
    private function _reset_error() { $this->_last_error = null; }

    // ── Import audit trail (optional — skipped when migration 005 has not
    //    been applied; the code detects the table) ──────────────────────────

    public function has_import_log() {
        return $this->db->table_exists('inventory_import_logs');
    }

    public function log_import($data) {
        if (!$this->has_import_log()) return false;
        $this->_reset_error();
        $row = array(
            'user_id'          => isset($data['user_id']) ? (int)$data['user_id'] : null,
            'file_name'        => isset($data['file_name']) ? substr($data['file_name'], 0, 255) : null,
            'mode'             => isset($data['mode']) ? $data['mode'] : null,
            'duplicate_action' => isset($data['duplicate_action']) ? $data['duplicate_action'] : null,
            'stock_action'     => isset($data['stock_action']) ? $data['stock_action'] : null,
            'total_rows'       => isset($data['total_rows']) ? (int)$data['total_rows'] : 0,
            'inserted'         => isset($data['inserted']) ? (int)$data['inserted'] : 0,
            'updated'          => isset($data['updated']) ? (int)$data['updated'] : 0,
            'skipped'          => isset($data['skipped']) ? (int)$data['skipped'] : 0,
            'failed'           => isset($data['failed']) ? (int)$data['failed'] : 0,
            'duplicates'       => isset($data['duplicates']) ? (int)$data['duplicates'] : 0,
            'created_at'       => date('Y-m-d H:i:s'),
        );
        $this->db->insert('inventory_import_logs', $row);
        return $this->db->insert_id();
    }

    public function get_import_history($limit = 5) {
        if (!$this->has_import_log()) return array();
        return $this->db->order_by('id', 'DESC')->limit((int)$limit)->get('inventory_import_logs')->result();
    }
}
