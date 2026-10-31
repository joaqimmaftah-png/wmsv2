<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Equipment_model extends CI_Model {

    public function get_all($filter = []) {
        $this->db->select('e.*, et.name as type_name, et.category')
            ->from('equipment e')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left');

        if (!empty($filter['status']))   $this->db->where('e.status', $filter['status']);
        if (!empty($filter['type']))     $this->db->where('e.equipment_type_id', $filter['type']);
        if (!empty($filter['category'])) $this->db->where('et.category', $filter['category']);
        // W0.3 — weekly-report filters
        if (!empty($filter['location']))     $this->db->where('e.location', $filter['location']);
        if (!empty($filter['report_group'])) $this->db->where('e.report_group', $filter['report_group']);
        if (!empty($filter['reportable']))   $this->db->where('e.include_in_weekly_report', 1)
                                                     ->where('e.status !=', 'Retired');
        if (!empty($filter['search'])) {
            $s = $filter['search'];
            $this->db->group_start()
                ->like('e.registration_no', $s)
                ->or_like('e.legacy_code', $s)
                ->or_like('e.brand', $s)
                ->or_like('e.model', $s)
                ->or_like('e.serial_number', $s)
                ->or_like('e.engine_model', $s)
                ->or_like('e.location', $s)
                ->or_like('et.name', $s)
                ->or_like('et.category', $s)
                ->group_end();
        }
        return $this->db->order_by('e.registration_no', 'ASC')->get()->result();
    }

    public function get_all_active() {
        return $this->db->select('e.*, et.name as type_name, et.category')
            ->from('equipment e')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->where('e.status !=', 'Retired')
            ->order_by('e.registration_no', 'ASC')
            ->get()->result();
    }

    /**
     * Hydrate the entire fleet directory in constant O(1) SQL queries (<= 5 total):
     *   1. `equipment` + `equipment_types` (`type_name`, `category`)
     *   2. `report_sites` approved site vocabulary map
     *   3. `work_orders` grouped open & critical WO counts per `equipment_id`
     *   4. `pm_schedules` active & overdue PM counts per `equipment_id`
     *   5. `equipment_specs` manufacturer spec row counts per `equipment_id`
     *
     * Never modifies or reformats `registration_no` or any Asset Register field.
     *
     * @param  string|null $today optional YYYY-MM-DD override for deterministic testing
     * @return array{rows:object[],report_sites:array,reportability:array,site_summary:array,locations:string[]}
     */
    public function get_fleet_overview($today = NULL) {
        $today = ($today && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$today))
            ? (string)$today
            : date('Y-m-d');

        $rows = $this->db->select('e.*, et.name as type_name, et.category')
            ->from('equipment e')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->order_by('e.registration_no', 'ASC')
            ->get()->result();

        $sites = $this->report_sites();

        // Grouped open Work Orders per machine (1 SQL query)
        $wo_map = array();
        if ($this->db->table_exists('work_orders')) {
            $w_rows = $this->db->select("equipment_id, COUNT(*) AS open_cnt, SUM(CASE WHEN priority = 'Critical' THEN 1 ELSE 0 END) AS crit_cnt", FALSE)
                ->from('work_orders')
                ->where_in('status', array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools'))
                ->group_by('equipment_id')
                ->get()->result();
            foreach ($w_rows as $wr) {
                $wo_map[(int)$wr->equipment_id] = array(
                    'open' => (int)$wr->open_cnt,
                    'crit' => (int)$wr->crit_cnt,
                );
            }
        }

        // Active PM schedules per machine to flag active & overdue PMs (1 SQL query)
        $pm_raw_by_eq = array();
        if ($this->db->table_exists('pm_schedules')) {
            $p_rows = $this->db->select('equipment_id, interval_type, next_due_date, next_due_hours')
                ->from('pm_schedules')
                ->where('status', 'Active')
                ->get()->result();
            foreach ($p_rows as $pr) {
                $eid = (int)$pr->equipment_id;
                if (!isset($pm_raw_by_eq[$eid])) $pm_raw_by_eq[$eid] = array();
                $pm_raw_by_eq[$eid][] = $pr;
            }
        }

        // Recorded manufacturer specs per machine (1 SQL query)
        $spec_map = array();
        if ($this->db->table_exists('equipment_specs')) {
            $sp_rows = $this->db->select('equipment_id, COUNT(*) AS cnt', FALSE)
                ->from('equipment_specs')
                ->where('spec_value !=', '')
                ->group_by('equipment_id')
                ->get()->result();
            foreach ($sp_rows as $sr) {
                $spec_map[(int)$sr->equipment_id] = (int)$sr->cnt;
            }
        }

        $reportability = array('total' => 0, 'reportable' => 0, 'not_reportable' => 0, 'unclassified' => 0);
        $site_summary  = array('machines' => 0, 'per_site' => array(), 'unapproved' => array(), 'blank' => 0);
        foreach (array_keys($sites) as $s_lbl) $site_summary['per_site'][$s_lbl] = 0;

        $loc_set = array();
        foreach (array_keys($sites) as $s_lbl) $loc_set[$s_lbl] = TRUE;

        $stat_rank = array(
            'Breakdown'      => 1,
            'In Maintenance' => 2,
            'Available'      => 3,
            'Standby'        => 4,
            'Retired'        => 5,
        );

        foreach ($rows as $eq) {
            $eid = (int)$eq->id;
            $reportability['total']++;

            $is_rep = function_exists('equip_is_reportable')
                ? equip_is_reportable($eq)
                : (!empty($eq->include_in_weekly_report) && (string)$eq->status !== 'Retired');

            $loc_raw = trim((string)(isset($eq->location) ? $eq->location : ''));
            if ($loc_raw !== '') $loc_set[$loc_raw] = TRUE;

            $loc_approved = TRUE;
            $loc_display  = $loc_raw;
            if ($loc_raw === '') {
                $loc_approved = FALSE;
            } elseif (!empty($sites) && function_exists('equip_is_approved_site')) {
                $loc_approved = equip_is_approved_site($loc_raw, $sites);
                if ($loc_approved && function_exists('equip_site_label')) {
                    $loc_display = equip_site_label($loc_raw, $sites);
                    $loc_set[$loc_display] = TRUE;
                }
            }

            if ($is_rep) {
                $reportability['reportable']++;
                if (empty($eq->report_group)) $reportability['unclassified']++;

                if ((string)$eq->status !== 'Retired') {
                    $site_summary['machines']++;
                    if ($loc_raw === '') {
                        $site_summary['blank']++;
                    } elseif ($loc_approved) {
                        if (!isset($site_summary['per_site'][$loc_display])) $site_summary['per_site'][$loc_display] = 0;
                        $site_summary['per_site'][$loc_display]++;
                    } else {
                        if (!isset($site_summary['unapproved'][$loc_raw])) $site_summary['unapproved'][$loc_raw] = 0;
                        $site_summary['unapproved'][$loc_raw]++;
                    }
                }
            } else {
                $reportability['not_reportable']++;
            }

            $pm_list   = isset($pm_raw_by_eq[$eid]) ? $pm_raw_by_eq[$eid] : array();
            $pm_over   = 0;
            $cur_hours = (float)$eq->current_hours;
            foreach ($pm_list as $pm_item) {
                $by_date  = (!empty($pm_item->next_due_date) && (string)$pm_item->next_due_date < $today);
                $by_hours = ($pm_item->next_due_hours !== NULL && (float)$pm_item->next_due_hours > 0 && $cur_hours >= (float)$pm_item->next_due_hours);
                if ($by_date || $by_hours) $pm_over++;
            }

            $wo_info = isset($wo_map[$eid]) ? $wo_map[$eid] : array('open' => 0, 'crit' => 0);

            if (!isset($eq->year_manufacture) || !$eq->year_manufacture) {
                $eq->year_manufacture = isset($eq->year) ? $eq->year : NULL;
            }

            $eq->open_wo_count    = (int)$wo_info['open'];
            $eq->crit_wo_count    = (int)$wo_info['crit'];
            $eq->pm_active_count  = count($pm_list);
            $eq->pm_overdue_count = $pm_over;
            $eq->spec_count       = isset($spec_map[$eid]) ? (int)$spec_map[$eid] : 0;
            $eq->is_reportable    = (bool)$is_rep;
            $eq->loc_display      = $loc_display;
            $eq->loc_approved     = (bool)$loc_approved;
            $eq->has_data_alert   = ($is_rep && (empty($eq->report_group) || $loc_raw === '' || !$loc_approved));
            $eq->status_rank      = isset($stat_rank[$eq->status]) ? $stat_rank[$eq->status] : 99;
        }

        ksort($site_summary['per_site']);
        arsort($site_summary['unapproved']);
        $locations = array_keys($loc_set);
        sort($locations, SORT_NATURAL | SORT_FLAG_CASE);

        return array(
            'rows'          => $rows,
            'report_sites'  => $sites,
            'reportability' => $reportability,
            'site_summary'  => $site_summary,
            'locations'     => $locations,
        );
    }

    public function get_by_id($id) {
        return $this->db->select('e.*, et.name as type_name, et.category')
            ->from('equipment e')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->where('e.id', $id)
            ->get()->row();
    }


    /* ── W0.5  site vocabulary ─────────────────────────────────────────── */

    /**
     * The approved sites (`report_sites`, seeded master data, task W0.6),
     * in printing order: label => code.
     *
     * Empty array on a database where the table does not exist yet, so every
     * screen degrades to plain text instead of breaking.
     */
    public function report_sites() {
        if (!$this->db->table_exists('report_sites')) return array();

        $rows = $this->db->order_by('sort_order', 'ASC')->order_by('label', 'ASC')
                         ->get('report_sites')->result();

        $out = array();
        foreach ($rows as $r) $out[$r->label] = $r->code;
        return $out;
    }

    /**
     * Validate a location as posted by the equipment form.
     *
     * The value is stored as typed (the column is free text and older rows hold
     * values the business has not mapped yet), but callers are told whether it is
     * an approved site so they can flag it instead of blocking the edit.
     *
     * @return array{value:string,approved:bool,label:string}
     */
    public function sanitize_location($value) {
        $value = trim((string)$value);
        if (strlen((string)($value)) > 200) $value = substr($value, 0, 200);   // column is VARCHAR(200)

        $sites = $this->report_sites();
        $approved = !empty($sites) && function_exists('equip_is_approved_site')
            ? equip_is_approved_site($value, $sites)
            : ($value !== '');

        $label = ($approved && function_exists('equip_site_label'))
            ? equip_site_label($value, $sites)
            : $value;

        return array('value' => $label, 'approved' => $approved, 'label' => $label);
    }

    /**
     * Counters for the equipment screen and for the data-quality panel:
     * how many reportable machines sit at an approved site, how many hold a
     * value the business has not mapped yet, and how many are blank.
     *
     * @return array{machines:int,per_site:array,unapproved:array,blank:int}
     */
    public function site_summary() {
        $sites = $this->report_sites();
        $out = array('machines' => 0, 'per_site' => array(), 'unapproved' => array(), 'blank' => 0);
        foreach (array_keys($sites) as $label) $out['per_site'][$label] = 0;

        $rows = $this->db->select('e.registration_no, e.location, e.status, e.include_in_weekly_report')
                         ->from('equipment e')
                         ->where('e.status !=', 'Retired')
                         ->order_by('e.registration_no', 'ASC')
                         ->get()->result();

        foreach ($rows as $r) {
            if (function_exists('equip_is_reportable') && !equip_is_reportable($r)) continue;
            $out['machines']++;

            $loc = trim((string)(isset($r->location) ? $r->location : ''));
            if ($loc === '') { $out['blank']++; continue; }

            $approved = equip_is_approved_site($loc, $sites);
            if ($approved) {
                $label = equip_site_label($loc, $sites);
                if (!isset($out['per_site'][$label])) $out['per_site'][$label] = 0;
                $out['per_site'][$label]++;
            } else {
                if (!isset($out['unapproved'][$loc])) $out['unapproved'][$loc] = 0;
                $out['unapproved'][$loc]++;
            }
        }

        ksort($out['per_site']);
        arsort($out['unapproved']);
        return $out;
    }

    /* ── W0.3  weekly-report grouping ─────────────────────────────────── */

    /**
     * The 17 report groups in sheet order (`report_groups`, seeded master
     * data).  Returns an empty array on a database where the table
     * has not been created yet, so the screens never crash.
     *
     * @return array code => label
     */
    public function report_groups() {
        if (!$this->db->table_exists('report_groups')) return array();
        $rows = $this->db->select('code, label, sort_order')
                         ->order_by('sort_order', 'ASC')
                         ->get('report_groups')->result();
        $out = array();
        foreach ((array)$rows as $r) $out[$r->code] = $r->label;
        return $out;
    }

    /**
     * Validate the three weekly-report fields of an equipment form post.
     *
     * - report_group must be one of the configured codes (or empty = not classified)
     * - report_sort must be an integer position >= 0 (default = 0, unpositioned)
     * - include_in_weekly_report is a checkbox: present = 1, absent = 0
     *
     * @param  array $post  the request values (already trimmed)
     * @return array ready for the equipment insert/update
     */
    public function sanitize_reportability($post) {
        $groups = $this->report_groups();

        $group = isset($post['report_group']) ? trim((string)$post['report_group']) : '';
        if ($group !== '' && !empty($groups) && !array_key_exists($group, $groups)) {
            $group = '';                        // unknown code is dropped, never stored
        }
        if ($group !== '' && empty($groups)) {
            $group = '';                        // no reference table yet: do not invent one
        }

        $sort = isset($post['report_sort']) ? (int)$post['report_sort'] : 0;
        if ($sort < 0) $sort = 0;

        $flag = (isset($post['include_in_weekly_report'])
                 && in_array((string)$post['include_in_weekly_report'], array('1', 'on', 'true', 'yes'), true)) ? 1 : 0;

        return array(
            'report_group'            => $group === '' ? null : $group,
            'report_sort'             => $sort,
            'include_in_weekly_report'=> $flag,
        );
    }

    /**
     * Reportability counters for the equipment list header.
     *
     * @return array{total:int,reportable:int,not_reportable:int,unclassified:int}
     */
    public function reportability_summary() {
        $out = array('total' => 0, 'reportable' => 0, 'not_reportable' => 0, 'unclassified' => 0);
        foreach ((array)$this->db->select('status, report_group, include_in_weekly_report')
                                 ->get('equipment')->result() as $r) {
            $out['total']++;
            if (equip_is_reportable($r)) {
                $out['reportable']++;
                if (empty($r->report_group)) $out['unclassified']++;
            } else {
                $out['not_reportable']++;
            }
        }
        return $out;
    }

    public function create($data) {
        $this->db->insert('equipment', $data);
        return $this->db->insert_id();
    }

    public function update($id, $data) {
        $this->db->update('equipment', $data, ['id' => $id]);
    }

    /**
     * Change a machine's status.
     *
     * Two guards the old one-liner did not have:
     *  - the value must be one of the `equipment.status` enum values (a typo or
     *    a crafted POST could otherwise be written and, under a non-strict
     *    sql_mode, silently coerced);
     *  - a Retired machine is never moved back into service automatically by
     *    work-order activity — retiring is a deliberate act.
     *
     * @return bool whether a row was written
     */
    public function update_status($id, $status) {
        $allowed = function_exists('equip_statuses')
            ? equip_statuses()
            : array('Available', 'In Maintenance', 'Breakdown', 'Standby', 'Retired');

        if (!in_array($status, $allowed, TRUE)) return FALSE;

        $eq = $this->db->select('id, status')->where('id', (int)$id)->get('equipment')->row();
        if (!$eq) return FALSE;
        if ($eq->status === 'Retired' && $status !== 'Retired') return FALSE;

        $this->db->where('id', (int)$id)->update('equipment', array('status' => $status));
        return $this->db->affected_rows() > 0;
    }

    public function get_pm_schedules($equipment_id) {
        return $this->db->where('equipment_id', $equipment_id)->where('status', 'Active')
            ->order_by('next_due_date', 'ASC')->get('pm_schedules')->result();
    }

    /**
     * Availability / MTTR / MTBF for one machine.
     *
     * This used to divide lifetime downtime by the machine's odometer
     * (`current_hours`) — a number that is not an operating-time basis at all,
     * and which disagreed with the dashboard and the weekly report. It now
     * comes from Kpi_engine_model, i.e. the approved workbook definition, over
     * a stated window (the dashboard's 30-day window by default).
     *
     * @param int|null $days look-back window; null = the dashboard window
     */
    public function get_equipment_kpi($equipment_id, $days = null) {
        $ci = get_instance();
        if (!isset($ci->Kpi_engine_model)) {
            $ci->load->model('Kpi_engine_model');
        }
        $days = $days === null ? Kpi_engine_model::DASHBOARD_WINDOW_DAYS : max(1, (int)$days);
        $to   = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-'.($days - 1).' days'));

        $k = $ci->Kpi_engine_model->machine($equipment_id, $from, $to);
        $target = $ci->Kpi_engine_model->target_for($equipment_id, $to);

        return [
            'availability'  => $k['availability_pct'],
            'mttr'          => $k['mttr_hours'] === null ? 0 : round($k['mttr_hours'], 2),
            'mtbf'          => $k['mtbf_hours'] === null ? 0 : round($k['mtbf_hours'], 1),
            'total_down'    => round((float)$k['downtime_hours'], 2),
            'breakdowns'    => (int)$k['breakdowns'],
            // extra context for the page
            'required_hours'=> round((float)$k['required_time_hours'], 2),
            'open_hours'    => $ci->Kpi_engine_model->open_hours_between($from, $to),
            'period'        => ['from' => $from, 'to' => $to, 'days' => $days],
            'target'        => round($target * 100, 1),
            'variance_pp'   => $ci->Kpi_engine_model->variance_pp($k['availability'], $target),
        ];
    }

    /*
     * Minimum days the interval covers — used to decide what counts as an
     * impossible jump (a machine cannot run more hours than the clock, nor
     * cover more kilometres than a day allows).
     */
    const METER_MAX_PER_DAY = array('Hours' => 24, 'Kilometres' => 1500);

    /**
     * The single meter writer (audit finding F-06).
     *
     * Every meter value in the system — the equipment screen, PM completion
     * and the inspections form — arrives here, so that one set of rules
     * decides what is a valid reading:
     *
     *   • the unit comes from the machine (`meter_type`) unless the caller
     *     says otherwise, so a kilometre reading can never be booked as hours;
     *   • a reading must be greater than zero;
     *   • the meter may not run backwards (it may repeat, e.g. a re-entry);
     *   • a jump larger than physically possible is refused;
     *   • a value for a date that already has a reading replaces it only if it
     *     is larger — a same-day correction downwards needs the override;
     *   • `equipment.current_hours` / `current_km` follow the latest reading
     *     and only ever move up, unless the override is used to correct them.
     *
     * `$options` = ['unit' => 'Hours'|'Kilometres', 'override' => bool]
     * An override is the "I know this is a correction" switch: it is the only
     * way past the three refusals, and the person using it is recorded on the
     * reading row.
     *
     * @return array ['ok' => bool, 'message' => string, 'unit' => string,
     *                'value' => float, 'previous' => float]
     */
    public function record_meter_reading($equipment_id, $date, $value, $source = 'Manual', $work_order_id = null, $user_id = null, $options = array()) {
        $equipment_id = (int)$equipment_id;
        $value        = round((float)$value, 1);
        $override     = !empty($options['override']);
        $date         = $date ?: date('Y-m-d');

        $eq = $this->get_by_id($equipment_id);
        if (!$eq) return array('ok' => false, 'message' => 'Equipment not found.', 'unit' => null, 'value' => $value, 'previous' => null);

        $unit = (isset($options['unit']) && in_array($options['unit'], array('Hours', 'Kilometres'), TRUE))
            ? $options['unit']
            : $eq->meter_type;
        $column        = ($unit === 'Kilometres') ? 'km' : 'hours';
        $current_field = ($unit === 'Kilometres') ? 'current_km' : 'current_hours';

        if (!$this->db->table_exists('equipment_meter_readings')) {
            return array('ok' => false, 'message' => 'The meter-reading history table is not installed yet — import the database dump shipped with this repository before recording readings.', 'unit' => $unit, 'value' => $value, 'previous' => null);
        }
        if ($value <= 0) {
            return array('ok' => false, 'message' => 'A meter reading must be greater than zero.', 'unit' => $unit, 'value' => $value, 'previous' => null);
        }

        // The reading on this date, and the last reading before it.
        $same = $this->db->where('equipment_id', $equipment_id)->where('reading_date', $date)
            ->limit(1)->get('equipment_meter_readings')->row();
        $previous_row = $this->db->where('equipment_id', $equipment_id)
            ->where('reading_date <', $date)->where($column.' IS NOT NULL', NULL, FALSE)
            ->order_by('reading_date', 'DESC')->order_by('id', 'DESC')
            ->limit(1)->get('equipment_meter_readings')->row();

        $previous = $same && $same->{$column} !== NULL
            ? (float)$same->{$column}
            : ($previous_row ? (float)$previous_row->{$column} : (float)$eq->{$current_field});

        $unit_word = ($unit === 'Kilometres') ? 'km' : 'hours';

        if (!$override) {
            if ($value < $previous) {
                return array('ok' => false, 'unit' => $unit, 'value' => $value, 'previous' => $previous,
                    'message' => 'Refused: '.number_format((float)($value), 1).' '.$unit_word.' is lower than the last recorded reading of '
                        .number_format((float)($previous), 1).'. A meter does not run backwards — if this is a correction, tick "This is a correction" and try again.');
            }
            if ($previous_row) {
                $days = max(1, (int)floor((strtotime($date) - strtotime($previous_row->reading_date)) / 86400));
                $max  = self::METER_MAX_PER_DAY[$unit] * $days;
                if (($value - (float)$previous_row->{$column}) > $max) {
                    return array('ok' => false, 'unit' => $unit, 'value' => $value, 'previous' => $previous,
                        'message' => 'Refused: '.number_format((float)($value), 1).' '.$unit_word.' is '.number_format((float)($value - (float)$previous_row->{$column}), 1)
                            .' more than the reading of '.date('d M Y', strtotime($previous_row->reading_date)).' — more than '
                            .number_format((float)($max), 0).' '.$unit_word.' in '.$days.' day(s) is not possible. Check the figure, or tick "This is a correction".');
                }
            }
        }

        if ($same) {
            // Same day, same value: nothing to record.
            if (!$override && abs($value - (float)$same->{$column}) < 0.0001) {
                return array('ok' => true, 'unit' => $unit, 'value' => $value, 'previous' => $previous,
                    'message' => 'Reading for '.date('d M Y', strtotime($date)).' is already '.number_format((float)($value), 1).' '.$unit_word.' — nothing to change.');
            }
            $this->db->where('id', $same->id)->update('equipment_meter_readings', array(
                $column        => $value,
                'source'       => $source,
                'work_order_id'=> $work_order_id ? (int)$work_order_id : $same->work_order_id,
                'recorded_by'  => $user_id ? (int)$user_id : $same->recorded_by,
            ));
        } else {
            $this->db->insert('equipment_meter_readings', array(
                'equipment_id'  => $equipment_id,
                'reading_date'  => $date,
                $column         => $value,
                'source'        => $source,
                'work_order_id' => $work_order_id ? (int)$work_order_id : NULL,
                'recorded_by'   => $user_id ? (int)$user_id : NULL,
            ));
        }

        // The machine's current value follows the latest reading, and only ever
        // moves up — unless this is an explicit correction.
        $newer = $this->db->where('equipment_id', $equipment_id)->where('reading_date >', $date)
            ->count_all_results('equipment_meter_readings');
        $changed = false;
        if ($newer === 0) {
            $current = (float)$eq->{$current_field};
            if ($value > $current || $override) {
                $this->db->where('id', $equipment_id)->update('equipment', array(
                    $current_field => $value,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ));
                $changed = true;
            }
        }

        return array('ok' => true, 'unit' => $unit, 'value' => $value, 'previous' => $previous,
            'changed_current' => $changed,
            'message' => 'Recorded '.number_format((float)($value), 1).' '.$unit_word.' for '.date('d M Y', strtotime($date))
                .($changed ? ' — the machine now reads '.number_format((float)($value), 1).' '.$unit_word.'.' : ' (a later reading already exists, so the machine\'s current value was left alone).'));
    }
}
