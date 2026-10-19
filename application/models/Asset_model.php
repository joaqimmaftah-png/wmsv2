<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Asset_model — data access for the Asset Register (fleet master data).
 *
 * Extracted from Dashboard_model::get_asset_register() so the register, the
 * Excel export and the Excel import all read/write the same shape of data.
 *
 * Performance: the previous implementation ran two extra queries per asset
 * (lifetime work-order stats + next PM).  Everything is now aggregated with
 * three queries in total, which keeps the register page and the export fast
 * even on a large fleet.
 */
class Asset_model extends CI_Model {

    /** column_lifecycle is the canonical list of editable equipment fields */
    const FIELDS = array(
        'registration_no', 'equipment_type_id', 'brand', 'model', 'year',
        'serial_number', 'engine_model', 'current_hours', 'current_km',
        'location', 'status', 'purchase_date', 'warranty_expiry', 'notes',
    );

    const STATUSES = array('Available', 'In Maintenance', 'Breakdown', 'Standby', 'Retired');

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       READ — asset register
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * Full register, one row per asset, including every equipment column plus
     * lifetime maintenance statistics and the next PM due.
     *
     * @param array $opts ['status'=>..., 'type_id'=>..., 'search'=>...]
     */
    public function get_register($opts = array()) {
        $this->db->select('e.*, et.name AS equip_type, et.category AS equip_category')
                 ->from('equipment e')
                 ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left');

        if (!empty($opts['status']))  $this->db->where('e.status', $opts['status']);
        if (!empty($opts['type_id'])) $this->db->where('e.equipment_type_id', $opts['type_id']);
        if (!empty($opts['search'])) {
            $s = $opts['search'];
            $this->db->group_start()
                     ->like('e.registration_no', $s)->or_like('e.brand', $s)
                     ->or_like('e.model', $s)->or_like('e.serial_number', $s)
                     ->group_end();
        }
        $equipment = $this->db->order_by('et.name, e.registration_no')->get()->result();

        $stats = $this->_wo_stats();
        $pms   = $this->_next_pm();

        $assets = array();
        foreach ($equipment as $eq) {
            $s = isset($stats[$eq->id]) ? $stats[$eq->id] : array();
            $totalWo   = (int)  (isset($s['total_wo'])   ? $s['total_wo']   : 0);
            $breakdown = (int)  (isset($s['breakdowns']) ? $s['breakdowns'] : 0);
            $corrective= (int)  (isset($s['corrective']) ? $s['corrective'] : 0);
            $completed = (int)  (isset($s['completed'])  ? $s['completed']  : 0);
            $openWo    = (int)  (isset($s['open_wo'])    ? $s['open_wo']    : 0);
            $totalDown = (float)(isset($s['total_down']) ? $s['total_down'] : 0);

            $opHrs = (float)$eq->current_hours;
            $avail = $opHrs > 0 ? round((($opHrs - $totalDown) / $opHrs) * 100, 1) : 100.0;

            $warrantyStatus = 'N/A';
            $warrantyDays   = null;
            if (!empty($eq->warranty_expiry)) {
                $days = (int)ceil((strtotime($eq->warranty_expiry) - time()) / 86400);
                $warrantyDays   = $days;
                $warrantyStatus = $days > 0 ? 'Valid' : 'Expired';
            }

            $pm = isset($pms[$eq->id]) ? $pms[$eq->id] : null;

            $assets[] = array(
                'id'              => (int)$eq->id,
                'equipment_type_id'=> (int)$eq->equipment_type_id,
                'type'            => strtoupper($eq->equip_type ? $eq->equip_type : 'OTHER'),
                'type_name'       => $eq->equip_type,
                'category'        => $eq->equip_category,
                'registration_no' => $eq->registration_no,
                'brand'           => $eq->brand,
                'model'           => $eq->model,
                'year'            => $eq->year,
                'serial_number'   => $eq->serial_number,
                'engine_model'    => $eq->engine_model,
                'current_hours'   => $opHrs,
                'current_km'      => (float)$eq->current_km,
                'location'        => $eq->location,
                'status'          => $eq->status,
                'purchase_date'   => $eq->purchase_date,
                'warranty_expiry' => $eq->warranty_expiry,
                'warranty_status' => $warrantyStatus,
                'warranty_days'   => $warrantyDays,
                'notes'           => $eq->notes,
                'created_at'      => $eq->created_at,
                'updated_at'      => $eq->updated_at,
                // lifetime maintenance statistics
                'total_wo'        => $totalWo,
                'breakdowns'      => $breakdown,
                'corrective_wo'   => $corrective,
                'completed_wo'    => $completed,
                'open_wo'         => $openWo,
                'total_down'      => $totalDown,
                'availability'    => $avail,
                // next preventive maintenance due
                'next_pm_name'    => $pm ? $pm->schedule_name : null,
                'next_pm_date'    => $pm ? $pm->next_due_date : null,
                'next_pm_hours'   => $pm ? $pm->next_due_hours : null,
                'last_pm_date'    => $pm ? $pm->last_done_date : null,
                'pm_interval'     => $pm ? ($pm->interval_value.' '.$pm->interval_type) : null,
            );
        }

        $grouped = array();
        foreach ($assets as $a) $grouped[$a['type']][] = $a;

        return array(
            'assets'      => $assets,
            'grouped'     => $grouped,
            'total'       => count($assets),
            'active'      => count(array_filter($assets, function($a){ return $a['status'] !== 'Retired'; })),
            'breakdown'   => count(array_filter($assets, function($a){ return $a['status'] === 'Breakdown'; })),
            'warranty_exp'=> count(array_filter($assets, function($a){ return $a['warranty_days'] !== null && $a['warranty_days'] < 0; })),
        );
    }

    /** Lifetime work-order aggregates, keyed by equipment id. */
    private function _wo_stats() {
        $rows = $this->db
            ->select("equipment_id,
                      COUNT(*) AS total_wo,
                      COUNT(CASE WHEN is_breakdown = 1 THEN 1 END) AS breakdowns,
                      COUNT(CASE WHEN type = 'Corrective Maintenance' THEN 1 END) AS corrective,
                      COUNT(CASE WHEN status IN ('Completed','Closed') THEN 1 END) AS completed,
                      COUNT(CASE WHEN status NOT IN ('Completed','Closed') THEN 1 END) AS open_wo,
                      COALESCE(SUM(downtime_hours),0) AS total_down")
            ->from('work_orders')
            ->group_by('equipment_id')
            ->get()->result();

        $out = array();
        foreach ($rows as $r) $out[(int)$r->equipment_id] = (array)$r;
        return $out;
    }

    /** Earliest active PM schedule per equipment (name, due date/hours, last done). */
    private function _next_pm() {
        $rows = $this->db
            ->select('equipment_id, schedule_name, next_due_date, next_due_hours, last_done_date, interval_type, interval_value')
            ->where('status', 'Active')
            ->order_by('next_due_date', 'ASC')
            ->get('pm_schedules')->result();

        // MySQL sorts NULL dates first on ASC; scheduled dates take precedence.
        usort($rows, function($a, $b) {
            if ($a->next_due_date === null && $b->next_due_date === null) return 0;
            if ($a->next_due_date === null) return 1;
            if ($b->next_due_date === null) return -1;
            return strcmp($a->next_due_date, $b->next_due_date);
        });

        $out = array();
        foreach ($rows as $r) {
            $id = (int)$r->equipment_id;
            if (!isset($out[$id])) $out[$id] = $r;   // first = soonest
        }
        return $out;
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       READ — lookups used by the importer
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function get_types() {
        return $this->db->order_by('name')->get('equipment_types')->result();
    }

    /** [lowercased, whitespace-collapsed type name => id] */
    public function get_type_map() {
        $out = array();
        foreach ($this->get_types() as $t) {
            $out[$this->_key($t->name)] = (int)$t->id;
        }
        return $out;
    }

    /** [normalised type name => display name] — used for import error hints */
    public function get_type_names() {
        $out = array();
        foreach ($this->get_types() as $t) {
            $out[$this->_key($t->name)] = $t->name;
        }
        return $out;
    }

    /**
     * Index of the existing fleet for duplicate detection during import:
     *   ['by_reg'    => [normalised registration => ['id'=>..,'registration_no'=>..]],
     *    'by_serial' => [normalised serial       => [['id'=>..,'registration_no'=>..], ...]]]
     */
    public function get_existing_index() {
        $rows = $this->db->select('id, registration_no, serial_number')->get('equipment')->result();
        $byReg = array(); $bySerial = array();
        foreach ($rows as $r) {
            $byReg[$this->_key($r->registration_no)] = array('id' => (int)$r->id, 'registration_no' => $r->registration_no);
            if (trim((string)$r->serial_number) !== '') {
                $bySerial[$this->_key($r->serial_number)][] = array('id' => (int)$r->id, 'registration_no' => $r->registration_no);
            }
        }
        return array('by_reg' => $byReg, 'by_serial' => $bySerial);
    }

    /** Case-insensitive, whitespace-collapsed comparison key. */
    private function _key($s) {
        $s = preg_replace('/\s+/', ' ', trim((string)$s));
        if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
        return strtolower($s);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       WRITE — used by the importer (and reusable by the UI)
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function insert_asset($data) {
        $data = $this->_filter_fields($data);
        if (empty($data['status'])) $data['status'] = 'Available';
        $this->db->insert('equipment', $data);
        return $this->db->insert_id();
    }

    public function update_asset($id, $data) {
        $data = $this->_filter_fields($data);
        if (empty($data)) return false;
        $this->db->update('equipment', $data, array('id' => (int)$id));
        return $this->db->affected_rows() >= 0;
    }

    /** Never let a caller write a column that does not exist on `equipment`. */
    private function _filter_fields($data) {
        $out = array();
        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $data)) $out[$f] = $data[$f];
        }
        return $out;
    }

    public function create_type($name) {
        $this->db->insert('equipment_types', array('name' => trim($name), 'category' => null, 'description' => null));
        return $this->db->insert_id();
    }

    public function last_db_error() {
        $e = $this->db->error();
        return isset($e['message']) && $e['message'] !== '' ? $e['message'] : null;
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       Import audit trail (optional — skipped while the log table
       does not exist)
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function has_import_log() {
        return $this->db->table_exists('asset_import_logs');
    }

    public function log_import($data) {
        if (!$this->has_import_log()) return false;
        $row = array(
            'user_id'          => isset($data['user_id']) ? (int)$data['user_id'] : null,
            'file_name'        => isset($data['file_name']) ? substr($data['file_name'], 0, 255) : null,
            'mode'             => isset($data['mode']) ? $data['mode'] : null,
            'total_rows'       => isset($data['total_rows']) ? (int)$data['total_rows'] : 0,
            'imported'         => isset($data['imported']) ? (int)$data['imported'] : 0,
            'updated'          => isset($data['updated']) ? (int)$data['updated'] : 0,
            'skipped'          => isset($data['skipped']) ? (int)$data['skipped'] : 0,
            'failed'           => isset($data['failed']) ? (int)$data['failed'] : 0,
            'duplicate_action' => isset($data['duplicate_action']) ? $data['duplicate_action'] : null,
            'created_at'       => date('Y-m-d H:i:s'),
        );
        $this->db->insert('asset_import_logs', $row);
        return $this->db->insert_id();
    }

    public function get_import_history($limit = 5) {
        if (!$this->has_import_log()) return array();
        return $this->db->order_by('id', 'DESC')->limit((int)$limit)->get('asset_import_logs')->result();
    }
}
