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
        $rows = $this->db
            ->select('wo.*, e.registration_no')
            ->from('work_orders wo')
            ->join('equipment e','e.id = wo.equipment_id')
            ->order_by('wo.created_at','DESC')
            ->limit($limit)->get()->result();

        // `assigned_to` is a CSV; resolve every technician through the same
        // helper the list and detail screens use, never a JOIN on the column.
        foreach ($rows as $wo) {
            $wo->assigned_name = $this->_resolve_tech_names($wo->assigned_to);
        }
        return $rows;
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

    /**
     * Apply a partial update and report whether it worked.
     *
     * This used to swallow the result, so a rejected UPDATE (an enum value the
     * column does not accept, a value too long, a dead connection) still let the
     * caller flash "Work Order updated." and redirect to a page showing the old
     * values.
     *
     * @return bool TRUE on success, FALSE when the statement failed
     */
    public function update($id, $data) {
        return $this->db->update('work_orders', $this->_with_breakdown_flag($data), array('id' => $id));
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
        if (!$this->db->table_exists('lube_consumption')) return array();
        return $this->db
            ->select('lc.*, lt.name as lube_name, lt.unit')
            ->from('lube_consumption lc')
            ->join('lube_types lt','lt.id = lc.lube_type_id')
            ->where('lc.work_order_id', $wo_id)
            ->get()->result();
    }

    /**
     * Post the work order's oil / coolant lines to the lubricant-consumption
     * ledger (`lube_consumption`), where the lubricant reports and compliance
     * figures read them.
     *
     * The job card's oil lines were only ever written to `wo_oil_records`:
     * `lube_consumption.work_order_id` was never populated, so oil topped up
     * on a repair was invisible to Lubricants → consumption and to the
     * PM-driven compliance report. This is idempotent: rows previously posted
     * for this work order are replaced, never stacked.
     *
     * A line whose `oil_type` (auto-filled from the inventory item's
     * description) does not resolve to a `lube_types` row is counted as
     * unmatched and left out — the raw line still exists on the job card.
     *
     * @param  int      $wo_id
     * @param  int|null $user_id recorded_by
     * @return array{posted:int,unmatched:int,removed:int}
     */
    public function sync_lube_consumption($wo_id, $user_id = NULL) {
        $out = array('posted' => 0, 'unmatched' => 0, 'removed' => 0);

        if (!$this->db->table_exists('lube_consumption') || !$this->db->table_exists('lube_types')) {
            return $out;
        }

        $wo = $this->db->select('id, wo_number, equipment_id, start_datetime, end_datetime, created_at')
            ->where('id', (int)$wo_id)->get('work_orders')->row();
        if (!$wo) return $out;

        // Replace only what THIS model posted before. A consumption record
        // entered by hand on the Lubricants screen and attached to this work
        // order must survive an edit of the job card.
        $auto_note = 'Auto: oil line on work order ' . $wo->wo_number;
        $this->db->where('work_order_id', (int)$wo_id)
            ->where('notes', $auto_note)
            ->delete('lube_consumption');
        $out['removed'] = (int)$this->db->affected_rows();

        $oils = $this->get_oil_records($wo_id);
        if (empty($oils)) return $out;

        // `lube_consumption.equipment_id` is NOT NULL; an oil line without a
        // machine cannot be posted (and cannot be reported either).
        if (empty($wo->equipment_id)) {
            $out['unmatched'] = count($oils);
            return $out;
        }

        $equipment_type_id = NULL;
        if (!empty($wo->equipment_id)) {
            $eq = $this->db->select('id, equipment_type_id')
                ->where('id', (int)$wo->equipment_id)->get('equipment')->row();
            if ($eq) $equipment_type_id = $eq->equipment_type_id;
        }

        $types = $this->db->select('id, name, grade, unit')->get('lube_types')->result();

        $when = $wo->end_datetime ?: ($wo->start_datetime ?: $wo->created_at);
        $date = $when ? date('Y-m-d', strtotime($when)) : date('Y-m-d');

        foreach ($oils as $oil) {
            $qty = (float)$oil->quantity;
            if ($qty <= 0) continue;

            $type = $this->_resolve_lube_type($types, $oil->oil_type, $oil->grade);
            if (!$type) {
                $out['unmatched']++;
                continue;
            }

            $recommended = NULL;
            if ($equipment_type_id && $this->db->table_exists('lube_specifications')) {
                $spec = $this->db->where('equipment_type_id', (int)$equipment_type_id)
                    ->where('lube_type_id', (int)$type->id)
                    ->get('lube_specifications')->row();
                if ($spec) $recommended = (float)$spec->recommended_qty;
            }

            $this->db->insert('lube_consumption', array(
                'work_order_id'    => (int)$wo_id,
                'equipment_id'     => (int)$wo->equipment_id,
                'lube_type_id'     => (int)$type->id,
                'quantity_used'    => $qty,
                'recommended_qty'  => $recommended,
                'variance'         => $recommended === NULL ? NULL : round($qty - $recommended, 2),
                'consumption_date' => $date,
                'recorded_by'      => $user_id ? (int)$user_id : NULL,
                'equipment_hours'  => NULL,
                'notes'            => $auto_note,
            ));
            $out['posted']++;
        }

        return $out;
    }

    /**
     * Match a job-card oil line to a `lube_types` row.
     *
     * Exact name first (case-insensitive), then a conservative contains match
     * because the job card's oil type is the inventory item's description
     * ("Engine Oil 15W-40") while `lube_types.name` is the short name ("Engine
     * Oil"). A grade match is used when the names do not overlap.
     */
    private function _resolve_lube_type($types, $oil_type, $grade) {
        $name    = strtolower(trim((string)$oil_type));
        $grade_v = strtolower(trim((string)$grade));
        if ($name === '' && $grade_v === '') return NULL;

        // 1. Exact name (case-insensitive) — the only unambiguous match.
        foreach ($types as $t) {
            if ($name !== '' && strtolower(trim($t->name)) === $name) return $t;
        }

        // 2. Whole-word containment, longest lube name first, so "Engine Oil"
        //    matches "Engine Oil 15W-40" but a two-letter name can never match
        //    half a word. Names shorter than four characters are skipped.
        $best = NULL;
        $best_len = 0;
        foreach ($types as $t) {
            $tn = strtolower(trim($t->name));
            if ($tn === '' || strlen($tn) < 4 || $name === '') continue;
            if (strlen($tn) <= $best_len) continue;
            if (preg_match('/(^|[^a-z0-9])' . preg_quote($tn, '/') . '([^a-z0-9]|$)/', $name)) {
                $best = $t;
                $best_len = strlen($tn);
            }
        }
        if ($best) return $best;

        // 3. The grade as a tie-breaker ("15W-40"); same whole-token rule.
        if ($grade_v !== '') {
            foreach ($types as $t) {
                $tg = strtolower(trim(isset($t->grade) ? $t->grade : ''));
                if ($tg === '' || strlen($tg) < 2) continue;
                if (preg_match('/(^|[^a-z0-9])' . preg_quote($tg, '/') . '([^a-z0-9]|$)/', $grade_v)) {
                    return $t;
                }
            }
        }
        return NULL;
    }

    /* ── Breakdown events ─────────────────────────────────────────────
       One failure can need several repair work orders. They are grouped by
       `breakdown_event_id` (the id of the first work order; that first row
       keeps NULL) and the KPI engine counts the group as ONE breakdown
       (D1, kpi_helper.php::kpi_breakdown_events). Until now nothing in the UI
       could write the column. */

    /** The root work-order id of the event this WO belongs to (its own id when it is the root). */
    public function get_breakdown_event_root($wo_id) {
        $wo = $this->db->select('id, breakdown_event_id')->where('id', (int)$wo_id)->get('work_orders')->row();
        if (!$wo) return NULL;
        return $wo->breakdown_event_id ? (int)$wo->breakdown_event_id : (int)$wo->id;
    }

    /** Every work order in the same breakdown event, root first. */
    public function get_breakdown_event_group($wo_id) {
        if (!$this->db->field_exists('breakdown_event_id', 'work_orders')) return array();
        $root = $this->get_breakdown_event_root($wo_id);
        if (!$root) return array();

        return $this->db
            ->select('w.id, w.wo_number, w.title, w.status, w.type, w.start_datetime, w.end_datetime, w.is_breakdown, w.breakdown_event_id')
            ->from('work_orders w')
            ->where('(w.id = ' . (int)$root . ' OR w.breakdown_event_id = ' . (int)$root . ')', NULL, FALSE)
            ->order_by('w.start_datetime', 'ASC')
            ->get()->result();
    }

    /**
     * Root work orders of OTHER breakdown events on the same machine that this
     * work order could join.
     */
    public function get_breakdown_event_candidates($equipment_id, $exclude_id = NULL) {
        if (!$this->db->field_exists('breakdown_event_id', 'work_orders')) return array();

        $this->db->select('w.id, w.wo_number, w.title, w.status, w.start_datetime')
            ->from('work_orders w')
            ->where('w.equipment_id', (int)$equipment_id)
            ->where('w.is_breakdown', 1)
            ->where('w.breakdown_event_id IS NULL', NULL, FALSE);
        if ($exclude_id) {
            $this->db->where('w.id !=', (int)$exclude_id);
        }
        return $this->db->order_by('w.start_datetime', 'DESC')->limit(50)->get()->result();
    }

    /**
     * Link this work order into another breakdown event, or detach it.
     *
     * Guards: both work orders must be breakdowns on the same machine, and an
     * event can never point at itself or form a two-step cycle. When the
     * linked row was itself the root of an event, its followers are moved to
     * the new root so the group stays one event.
     *
     * @param  int $wo_id       work order being edited
     * @param  int $event_wo_id root work order to join; 0/NULL/own id = detach
     * @return array{ok:bool,message:string}
     */
    public function link_breakdown_event($wo_id, $event_wo_id) {
        $wo = $this->get_by_id($wo_id);
        if (!$wo) return array('ok' => FALSE, 'message' => 'Work order not found.');
        if (function_exists('wo_is_breakdown') ? !wo_is_breakdown($wo) : !(int)$wo->is_breakdown) {
            return array('ok' => FALSE, 'message' => 'Only a breakdown work order can be part of a breakdown event.');
        }
        if (!$this->db->field_exists('breakdown_event_id', 'work_orders')) {
            return array('ok' => FALSE, 'message' => 'This database does not have the breakdown-event column.');
        }

        $event_wo_id = (int)$event_wo_id;

        if ($event_wo_id <= 0 || $event_wo_id === (int)$wo->id) {
            $this->db->where('id', (int)$wo->id)->update('work_orders', array('breakdown_event_id' => NULL));
            return array('ok' => TRUE, 'message' => 'This work order now stands alone as its own breakdown event.');
        }

        $target = $this->get_by_id($event_wo_id);
        if (!$target) return array('ok' => FALSE, 'message' => 'The selected work order does not exist.');
        if (function_exists('wo_is_breakdown') ? !wo_is_breakdown($target) : !(int)$target->is_breakdown) {
            return array('ok' => FALSE, 'message' => 'The selected work order is not a breakdown.');
        }
        if ((int)$target->equipment_id !== (int)$wo->equipment_id) {
            return array('ok' => FALSE, 'message' => 'A breakdown event can only group work orders on the same machine.');
        }

        $root = $target->breakdown_event_id ? (int)$target->breakdown_event_id : (int)$target->id;
        if ($root === (int)$wo->id) {
            return array('ok' => FALSE, 'message' => 'A breakdown event cannot be linked to itself.');
        }

        $this->db->trans_begin();

        // If this row was the root of its own group, its followers move with it.
        if (empty($wo->breakdown_event_id)) {
            $this->db->where('breakdown_event_id', (int)$wo->id)
                ->update('work_orders', array('breakdown_event_id' => $root));
        }

        $this->db->where('id', (int)$wo->id)->update('work_orders', array('breakdown_event_id' => $root));

        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            return array('ok' => FALSE, 'message' => 'The link could not be saved.');
        }
        $this->db->trans_commit();

        return array('ok' => TRUE, 'message' => 'Linked to breakdown event ' . $target->wo_number . '.');
    }

    /* ── PM completions recorded against this work order ─────────────── */

    public function get_pm_completions($wo_id) {
        if (!$this->db->table_exists('pm_completions')) return array();
        return $this->db
            ->select('pc.*, p.schedule_name, p.interval_type, p.interval_value, e.registration_no')
            ->from('pm_completions pc')
            ->join('pm_schedules p', 'p.id = pc.pm_schedule_id', 'left')
            ->join('equipment e', 'e.id = p.equipment_id', 'left')
            ->where('pc.work_order_id', (int)$wo_id)
            ->order_by('pc.completed_date', 'DESC')
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
     * Every installation is supposed to have it — but one did not: its
     * production log shows
     *   Query error: Table 'your_database.wo_attachments' doesn't exist
     * and the work-order page returned a 500 because of it (audit finding F-07).
     * The table may be missing; the PAGE may not be.
     *
     * Every method below therefore degrades instead of erroring: reads return
     * nothing, writes report failure. Restoring the table (it is in the shipped
     * database dump) makes the feature real.
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
