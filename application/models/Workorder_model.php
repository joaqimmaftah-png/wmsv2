<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Workorder_model extends CI_Model {

    public function get_all($filter = array()) {
        // NOTE: assigned_to is CSV of user IDs — no JOIN on it
        $this->db
            ->select('wo.*, e.registration_no, e.brand, e.model,
                CONCAT(c.first_name," ",c.last_name) as created_by_name')
            ->from('work_orders wo')
            ->join('equipment e', 'e.id = wo.equipment_id')
            ->join('users c', 'c.id = wo.created_by', 'left');

        if (!empty($filter['status']))    $this->db->where('wo.status',   $filter['status']);
        if (!empty($filter['type']))      $this->db->where('wo.type',     $filter['type']);
        if (!empty($filter['priority']))  $this->db->where('wo.priority', $filter['priority']);
        if (!empty($filter['date_from'])) $this->db->where('DATE(wo.start_datetime) >=', $filter['date_from']);
        if (!empty($filter['date_to']))   $this->db->where('DATE(wo.start_datetime) <=', $filter['date_to']);
        if (!empty($filter['search'])) {
            $s = $filter['search'];
            $this->db->group_start()
                ->like('wo.wo_number', $s)
                ->or_like('wo.title', $s)
                ->or_like('e.registration_no', $s)
                ->group_end();
        }
        $results = $this->db->order_by('wo.created_at','DESC')->get()->result();

        // Resolve assigned technician names from CSV
        foreach ($results as &$wo) {
            $wo->assigned_name = $this->_resolve_tech_names($wo->assigned_to);
        }
        return $results;
    }

    public function get_by_id($id) {
        $wo = $this->db
            ->select('wo.*, e.registration_no, e.brand, e.model, e.equipment_type_id, e.current_hours,
                      et.name as equipment_type,
                      CONCAT(c.first_name," ",c.last_name) as created_by_name,
                      CONCAT(comp.first_name," ",comp.last_name) as completed_by_name')
            ->from('work_orders wo')
            ->join('equipment e',       'e.id = wo.equipment_id')
            ->join('equipment_types et','et.id = e.equipment_type_id', 'left')
            ->join('users c',           'c.id = wo.created_by',  'left')
            ->join('users comp',        'comp.id = wo.completed_by', 'left')
            ->where('wo.id', $id)
            ->get()->row();

        if ($wo) {
            $wo->assigned_name = $this->_resolve_tech_names($wo->assigned_to);
        }
        return $wo;
    }

    /** Resolve comma-separated user IDs to "First Last, First Last" */
    private function _resolve_tech_names($csv) {
        if (empty($csv)) return '';
        $ids = array_filter(array_map('intval', explode(',', $csv)));
        if (empty($ids)) return '';
        $users = $this->db->where_in('id', $ids)->get('users')->result();
        $names = array();
        foreach ($users as $u) {
            $names[] = $u->first_name . ' ' . $u->last_name;
        }
        return implode(', ', $names);
    }

    public function get_by_equipment($equipment_id, $limit = 20) {
        $results = $this->db
            ->select('wo.*')
            ->from('work_orders wo')
            ->where('wo.equipment_id', $equipment_id)
            ->order_by('wo.created_at','DESC')
            ->limit($limit)
            ->get()->result();
        foreach ($results as &$wo) {
            $wo->assigned_name = $this->_resolve_tech_names($wo->assigned_to);
        }
        return $results;
    }

    public function get_by_date_range($from, $to) {
        $results = $this->db
            ->select('wo.*, e.registration_no')
            ->from('work_orders wo')
            ->join('equipment e','e.id = wo.equipment_id')
            ->where('DATE(wo.start_datetime) >=', $from)
            ->where('DATE(wo.start_datetime) <=', $to)
            ->order_by('wo.start_datetime','DESC')
            ->get()->result();
        foreach ($results as &$wo) {
            $wo->assigned_name = $this->_resolve_tech_names($wo->assigned_to);
        }
        return $results;
    }

    public function get_by_date($date) {
        $results = $this->db
            ->select('wo.*, e.registration_no')
            ->from('work_orders wo')
            ->join('equipment e','e.id = wo.equipment_id')
            ->where('DATE(wo.start_datetime)', $date)
            ->order_by('wo.start_datetime','DESC')
            ->get()->result();
        foreach ($results as &$wo) {
            $wo->assigned_name = $this->_resolve_tech_names($wo->assigned_to);
        }
        return $results;
    }

    public function get_recent($limit = 5) {
        return $this->db
            ->select('wo.*, e.registration_no')
            ->from('work_orders wo')
            ->join('equipment e','e.id = wo.equipment_id')
            ->order_by('wo.created_at','DESC')
            ->limit($limit)->get()->result();
    }

    /**
     * `is_breakdown` is the single source of truth for every breakdown KPI
     * (dashboard, MTTR/MTBF, W36). The forms never post it, so it is derived
     * here from the work-order type: Corrective Maintenance is the workshop's
     * breakdown work. A caller that sets the flag explicitly keeps its value
     * (e.g. a corrective job deliberately not counted as a breakdown).
     */
    private function _with_breakdown_flag($data) {
        if (!array_key_exists('is_breakdown', $data) && isset($data['type'])) {
            $data['is_breakdown'] = (strcasecmp((string)$data['type'], 'Corrective Maintenance') === 0) ? 1 : 0;
        }
        return $data;
    }

    public function create($data) {
        $this->db->insert('work_orders', $this->_with_breakdown_flag($data));
        return $this->db->insert_id();
    }

    public function update($id, $data) {
        $this->db->update('work_orders', $this->_with_breakdown_flag($data), array('id' => $id));
    }

    // ── Spare Parts ──────────────────────────────────────────
    public function get_spare_parts($wo_id) {
        return $this->db->where('work_order_id', $wo_id)->get('wo_spare_parts')->result();
    }

    /**
     * Save the spare part lines of a work order.
     *
     * Upsert instead of delete+reinsert: existing rows are matched (by item
     * code when present, otherwise by part name) and updated in place, so the
     * row IDs survive an edit.  This matters because the Pending Spare Parts
     * tracking (allocations, arrival alerts) references these rows — a plain
     * delete would orphan active stock reservations.  Rows that disappeared
     * from the form are removed and their reservations released implicitly
     * (the availability engine ignores allocations whose line no longer exists).
     */
    public function save_spare_parts($wo_id, $rows) {
        $existing = $this->db->where('work_order_id', $wo_id)->get('wo_spare_parts')->result();
        $pool = array();
        foreach ($existing as $e) $pool[(int)$e->id] = $e;

        $norm = function ($s) {
            $s = preg_replace('/\s+/u', ' ', trim((string)$s));
            return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        };

        if (empty($rows) || empty($rows['part_name'])) {
            if (!empty($pool)) $this->db->where('work_order_id', $wo_id)->delete('wo_spare_parts');
            return;
        }

        $count = count($rows['part_name']);
        for ($i = 0; $i < $count; $i++) {
            $name = trim(isset($rows['part_name'][$i]) ? $rows['part_name'][$i] : '');
            if (!$name) continue;

            $data = array(
                'work_order_id'  => $wo_id,
                'item_code'      => isset($rows['item_code'][$i])      ? trim($rows['item_code'][$i])      : NULL,
                'part_name'      => $name,
                'part_number'    => isset($rows['part_number'][$i])    ? trim($rows['part_number'][$i])    : NULL,
                'spare_type'     => isset($rows['spare_type'][$i])     ? $rows['spare_type'][$i]           : 'New',
                'source_machine' => isset($rows['source_machine'][$i]) ? trim($rows['source_machine'][$i]) : NULL,
                'quantity'       => isset($rows['quantity'][$i])       ? (float)$rows['quantity'][$i]      : NULL,
                'unit'           => isset($rows['unit'][$i])           ? trim($rows['unit'][$i]) ?: 'pcs'  : 'pcs',
            );
            if (empty($data['item_code']))     $data['item_code'] = NULL;
            if (empty($data['part_number']))   $data['part_number'] = NULL;
            if (empty($data['source_machine'])) $data['source_machine'] = NULL;

            // Find the existing row this input line refers to.
            $match_id = null;
            $code_key = $norm($data['item_code']);
            $name_key = $norm($name);
            foreach ($pool as $pid => $p) {
                $p_code = $norm(isset($p->item_code) ? $p->item_code : '');
                if ($code_key !== '' && $p_code !== '' && $p_code === $code_key) { $match_id = $pid; break; }
            }
            if ($match_id === null) {
                foreach ($pool as $pid => $p) {
                    if ($norm($p->part_name) === $name_key) { $match_id = $pid; break; }
                }
            }

            if ($match_id !== null) {
                unset($data['work_order_id']);
                $this->db->where('id', $match_id)->update('wo_spare_parts', $data);
                unset($pool[$match_id]);
            } else {
                $this->db->insert('wo_spare_parts', $data);
            }
        }

        // Lines removed from the form go away.
        if (!empty($pool)) {
            $this->db->where_in('id', array_keys($pool))->delete('wo_spare_parts');
        }
    }

    // ── Oil / Coolant Records ────────────────────────────────
    public function get_oil_records($wo_id) {
        return $this->db->where('work_order_id', $wo_id)->get('wo_oil_records')->result();
    }

    public function save_oil_records($wo_id, $rows) {
        $this->db->where('work_order_id', $wo_id)->delete('wo_oil_records');
        if (empty($rows) || empty($rows['oil_type'])) return;

        $count = count($rows['oil_type']);
        for ($i = 0; $i < $count; $i++) {
            $type = trim(isset($rows['oil_type'][$i])   ? $rows['oil_type'][$i]   : '');
            $sys  = trim(isset($rows['system'][$i])     ? $rows['system'][$i]     : '');
            $code = trim(isset($rows['item_code'][$i])  ? $rows['item_code'][$i]  : '');
            if (!$type && !$sys && !$code) continue;

            $this->db->insert('wo_oil_records', array(
                'work_order_id'   => $wo_id,
                'system_location' => $sys  ?: NULL,
                'oil_type'        => $type ?: NULL,
                'grade'           => isset($rows['grade'][$i]) ? trim($rows['grade'][$i]) : NULL,
                'quantity'        => isset($rows['quantity'][$i]) ? (float)$rows['quantity'][$i] : NULL,
            ));
        }
    }

    public function get_lube_consumption($wo_id) {
        return $this->db
            ->select('lc.*, lt.name as lube_name, lt.unit')
            ->from('lube_consumption lc')
            ->join('lube_types lt','lt.id = lc.lube_type_id')
            ->where('lc.work_order_id', $wo_id)
            ->get()->result();
    }

    // ── Status History ───────────────────────────────────────
    public function get_status_history($wo_id) {
        return $this->db
            ->select('h.*, CONCAT(u.first_name," ",u.last_name) as changed_by_name')
            ->from('wo_status_history h')
            ->join('users u','u.id = h.changed_by','left')
            ->where('h.work_order_id', $wo_id)
            ->order_by('h.changed_at','ASC')
            ->get()->result();
    }

    public function add_status_history($wo_id, $old, $new, $by, $notes = '') {
        $this->db->insert('wo_status_history', array(
            'work_order_id' => $wo_id,
            'old_status'    => $old,
            'new_status'    => $new,
            'changed_by'    => $by,
            'notes'         => $notes,
        ));
    }

    /* ── Incident details (one row per Incident-type WO) ────── */
    public function get_incident($wo_id) {
        return $this->db->where('wo_id', $wo_id)->get('incident_details')->row();
    }

    public function save_incident($wo_id, $data) {
        $existing = $this->get_incident($wo_id);
        if ($existing) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            return $this->db->where('id', $existing->id)->update('incident_details', $data);
        }
        $data['wo_id']      = $wo_id;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->insert('incident_details', $data);
        return $this->db->insert_id();
    }

    public function mark_incident_reviewed($wo_id, $reviewer_id) {
        $incident = $this->get_incident($wo_id);
        if (!$incident) return false;
        return $this->db->where('id', $incident->id)->update('incident_details', array(
            'review_status' => 'Reviewed',
            'reviewed_by'   => $reviewer_id,
            'reviewed_at'   => date('Y-m-d H:i:s'),
        ));
    }

    /* ── Generic WO attachments (photos / docs) ──────────────── */

    /**
     * Is the attachment table actually present?
     *
     * It is created by migration 001, which every installation is supposed to
     * have — but this one does not: the production log shows
     *   Query error: Table 'your_database.wo_attachments' doesn't exist
     * and the work-order page returned a 500 because of it (audit finding F-07).
     * The table may be missing; the PAGE may not be.
     *
     * Every method below therefore degrades instead of erroring: reads return
     * nothing, writes report failure. Apply `database/migrations/001_create_wo_attachments.sql`
     * to make the feature real.
     */
    public function attachments_ready() {
        return $this->db->table_exists('wo_attachments');
    }

    public function get_attachments($wo_id, $category = null) {
        if (!$this->attachments_ready()) return array();
        $this->db->where('wo_id', $wo_id);
        if ($category) $this->db->where('category', $category);
        return $this->db->order_by('uploaded_at', 'DESC')->get('wo_attachments')->result();
    }

    public function add_attachment($wo_id, $category, $file_name, $original_name, $user_id) {
        if (!$this->attachments_ready()) return false;
        $this->db->insert('wo_attachments', array(
            'wo_id'         => $wo_id,
            'category'      => $category,
            'file_name'     => $file_name,
            'original_name' => $original_name,
            'uploaded_by'   => $user_id,
            'uploaded_at'   => date('Y-m-d H:i:s'),
        ));
        return $this->db->insert_id();
    }

    public function get_attachment($id) {
        if (!$this->attachments_ready()) return null;
        return $this->db->where('id', $id)->get('wo_attachments')->row();
    }

    public function delete_attachment($id, $wo_id) {
        if (!$this->attachments_ready()) return false;
        $deleted = $this->db->where('id', (int)$id)
            ->where('wo_id', (int)$wo_id)
            ->delete('wo_attachments');
        return $deleted && $this->db->affected_rows() > 0;
    }

    /* ── Labor Sessions (multi-stint time tracking) ──────────── */

    public function get_sessions($wo_id) {
        $this->db->select('s.*, CONCAT(u.first_name," ",u.last_name) as technician_name, sw.wo_number as switched_to_wo_number')
            ->from('wo_sessions s')
            ->join('users u', 'u.id = s.technician_id', 'left')
            ->join('work_orders sw', 'sw.id = s.switched_to_wo_id', 'left')
            ->where('s.wo_id', $wo_id)
            ->order_by('s.session_no', 'ASC');
        return $this->db->get()->result();
    }

    public function get_session($session_id) {
        $this->db->select('s.*, CONCAT(u.first_name," ",u.last_name) as technician_name, sw.wo_number as switched_to_wo_number')
            ->from('wo_sessions s')
            ->join('users u', 'u.id = s.technician_id', 'left')
            ->join('work_orders sw', 'sw.id = s.switched_to_wo_id', 'left')
            ->where('s.id', $session_id);
        return $this->db->get()->row();
    }

    public function get_open_session($wo_id) {
        return $this->db->where('wo_id', $wo_id)->where('status', 'Open')->get('wo_sessions')->row();
    }

    /** A technician can only be actively clocked on one WO at a time.
        Finds their open session elsewhere, if any, so it can be auto-closed
        before starting a new one — prevents hours being logged twice at once. */
    public function get_open_session_for_technician($technician_id, $exclude_wo_id = null) {
        $this->db->select('s.*, w.wo_number as other_wo_number')
            ->from('wo_sessions s')
            ->join('work_orders w', 'w.id = s.wo_id')
            ->where('s.technician_id', $technician_id)
            ->where('s.status', 'Open');
        if ($exclude_wo_id) $this->db->where('s.wo_id !=', $exclude_wo_id);
        return $this->db->get()->row();
    }

    /** Start a new session — auto-numbered, sets WO to In Progress if needed */
    public function start_session($wo_id, $technician_id, $created_by, $interruption_reason = null) {
        // Guard: don't open a second session while one is already open
        if ($this->get_open_session($wo_id)) return false;

        $max = $this->db->select_max('session_no')->where('wo_id', $wo_id)->get('wo_sessions')->row();
        $next_no = ($max && $max->session_no) ? $max->session_no + 1 : 1;

        $this->db->insert('wo_sessions', array(
            'wo_id'               => $wo_id,
            'session_no'          => $next_no,
            'technician_id'       => $technician_id,
            'start_datetime'      => date('Y-m-d H:i:s'),
            'status'              => 'Open',
            'interruption_reason' => $interruption_reason,
            'created_by'          => $created_by,
            'created_at'          => date('Y-m-d H:i:s'),
        ));
        return $this->db->insert_id();
    }

    /** Close an open session — hours auto-calculated from start/end timestamps.
        $switched_to_wo_id links this session to the WO the technician moved to,
        so "why did this stop" is a real reference, not just descriptive text. */
    public function close_session($session_id, $work_performed = null, $switched_to_wo_id = null) {
        $session = $this->db->where('id', $session_id)->get('wo_sessions')->row();
        if (!$session || $session->status !== 'Open') return false;

        $end   = date('Y-m-d H:i:s');
        $hours = round((strtotime($end) - strtotime($session->start_datetime)) / 3600, 2);

        $update = array(
            'end_datetime'   => $end,
            'hours_worked'   => $hours,
            'work_performed' => $work_performed,
            'status'         => 'Closed',
            'updated_at'     => date('Y-m-d H:i:s'),
        );
        if ($switched_to_wo_id) $update['switched_to_wo_id'] = $switched_to_wo_id;

        return $this->db->where('id', $session_id)->where('status', 'Open')->update('wo_sessions', $update);
    }

    /** Force-close any open session for a WO (used when WO is marked Completed/Closed) */
    public function close_open_session_for_wo($wo_id) {
        $session = $this->get_open_session($wo_id);
        if ($session) $this->close_session($session->id);
    }

    /** Total worked hours across all closed sessions for a WO */
    public function get_total_session_hours($wo_id) {
        $row = $this->db->select_sum('hours_worked')->where('wo_id', $wo_id)
            ->where('status', 'Closed')->get('wo_sessions')->row();
        return $row && $row->hours_worked ? (float)$row->hours_worked : 0;
    }

    public function count_sessions($wo_id) {
        return $this->db->where('wo_id', $wo_id)->count_all_results('wo_sessions');
    }
}
