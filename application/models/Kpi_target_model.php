<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Availability targets for the weekly report (plan task W0.4 / W1.5).
 *
 * One table, two scopes:
 *
 *   scope = 'Equipment'  →  equipment_id set   ("ADT 010 = 88 %")
 *   scope = 'Group'      →  group_code set     ("ADTs = 72.56 %")
 *
 * `target_date` NULL means the *standing default* of that scope; a date means an
 * override for that one day (business decision D3 + OPEN-1: the sample itself
 * uses 0.88 on six days and 0.54/0.63 on single days).
 *
 * The value the report prints for a machine on a day is resolved by
 * `resolve()` — equipment+day → equipment default → group+day → group default →
 * settings.default_availability_target — and the winner's provenance is
 * returned with it so the data-quality panel can explain every number.
 */
class Kpi_target_model extends CI_Model {

    const TABLE           = 'equipment_kpi_targets';
    const SCOPE_EQUIPMENT = 'Equipment';
    const SCOPE_GROUP     = 'Group';

    /** Resolution order, most specific first (W1.5). */
    const RESOLUTION_CHAIN = array(
        'equipment-day'     => 'Machine, this day',
        'equipment-default' => 'Machine standing default',
        'group-day'         => 'Group, this day',
        'group-default'     => 'Group standing default',
        'settings'          => 'Fallback target (settings)',
    );

    private $last_error = '';

    public function __construct() {
        parent::__construct();
        if (!$this->db) {
            $ci = get_instance();
            $this->db = $ci->db;
        }
    }

    /* ────────────────────────────────────────────────────────────────────
     |  basics
     * ──────────────────────────────────────────────────────────────────── */

    /** The table only exists once the W36 migration has been applied. */
    public function table_ready() {
        return (bool)$this->db->table_exists(self::TABLE);
    }

    public function scopes() {
        return array(self::SCOPE_EQUIPMENT, self::SCOPE_GROUP);
    }

    public function last_error() {
        return $this->last_error;
    }

    public function scope_label($scope) {
        return $scope === self::SCOPE_GROUP ? 'Group' : 'Machine';
    }

    /* ────────────────────────────────────────────────────────────────────
     |  value handling
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * "88", "88 %", "0.88" and "0,88" all mean 88 %.
     *
     * Deliberately the same rule as Settings_model::sanitize() for
     * 'default_availability_target' so the two entry points can never drift
     * apart; tests/unit/58 asserts that both agree.
     *
     * @return float|null null when the input is not a usable percentage
     */
    public function normalize_pct($value) {
        if (is_array($value) || is_object($value)) return null;

        $raw = trim((string)$value);
        if ($raw === '') return null;
        $raw = str_replace(array('%', ' '), '', $raw);
        $raw = str_replace(',', '.', $raw);
        if (!is_numeric($raw)) return null;

        $v = (float)$raw;
        if ($v > 1) $v = $v / 100;                 // 88 → 0.88
        if ($v < 0 || $v > 1) return null;         // nonsense like 150

        return round($v, 4);
    }

    /** Formatted for display: 0.8800 → "88.00 %". */
    public function pct_label($value, $decimals = 2) {
        if ($value === null || $value === '') return '—';
        return number_format(((float)$value) * 100, $decimals, '.', '') . ' %';
    }

    /** A DATE column value, or null (= standing default). */
    public function normalize_date($value) {
        $raw = trim((string)$value);
        if ($raw === '') return null;
        $t = strtotime($raw);
        return $t ? date('Y-m-d', $t) : null;
    }

    /**
     * Validate one target row as posted.
     *
     * @return array|null ['scope','equipment_id','group_code','target_date','target_pct','note'] or null + last_error()
     */
    public function sanitize($post) {
        $this->last_error = '';
        if (!is_array($post)) { $this->last_error = 'Nothing to save.'; return null; }

        $scope = isset($post['scope']) ? trim((string)$post['scope']) : '';
        if (!in_array($scope, $this->scopes(), true)) {
            $this->last_error = 'Choose whether the target applies to a machine or a group.';
            return null;
        }

        $equipment_id = null;
        $group_code   = null;

        if ($scope === self::SCOPE_EQUIPMENT) {
            $equipment_id = isset($post['equipment_id']) ? (int)$post['equipment_id'] : 0;
            if ($equipment_id <= 0) { $this->last_error = 'Select the machine.'; return null; }
            if (!$this->equipment_exists($equipment_id)) {
                $this->last_error = 'That machine does not exist.';
                return null;
            }
        } else {
            $group_code = isset($post['group_code']) ? trim((string)$post['group_code']) : '';
            if ($group_code === '') { $this->last_error = 'Select the group.'; return null; }
            if ($this->known_group_codes() !== array() && !in_array($group_code, $this->known_group_codes(), true)) {
                $this->last_error = 'That group is not in the report list.';
                return null;
            }
        }

        $pct = $this->normalize_pct(isset($post['target_pct']) ? $post['target_pct'] : '');
        if ($pct === null) {
            $this->last_error = 'The target must be a percentage between 0 and 100 (for example 88 or 0.88).';
            return null;
        }

        $date = null;
        if (isset($post['target_date']) && trim((string)$post['target_date']) !== '') {
            $date = $this->normalize_date($post['target_date']);
            if ($date === null) { $this->last_error = 'That date could not be read.'; return null; }
        }

        $note = isset($post['note']) ? trim((string)$post['note']) : '';
        if (strlen($note) > 255) $note = substr($note, 0, 255);

        return array(
            'scope'        => $scope,
            'equipment_id' => $equipment_id,
            'group_code'   => $group_code,
            'target_date'  => $date,
            'target_pct'   => number_format($pct, 4, '.', ''),
            'note'         => $note === '' ? null : $note,
        );
    }

    /* ────────────────────────────────────────────────────────────────────
     |  write
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Insert or update one target. The pilot table has no unique index (and a
     * NULL date cannot be unique in MySQL anyway), so existence is checked
     * first — the same approach Settings_model::save_many() uses.
     *
     * @return int|false the row id
     */
    public function save($post, $user_id = null) {
        if (!$this->table_ready()) { $this->last_error = 'The targets table is not installed.'; return false; }

        $row = $this->sanitize($post);
        if ($row === null) return false;

        $existing = $this->find_exact($row['scope'], $row['equipment_id'], $row['group_code'], $row['target_date']);

        if ($existing) {
            $this->db->where('id', $existing->id)->update(self::TABLE, array(
                'target_pct' => $row['target_pct'],
                'note'       => $row['note'],
            ));
            return (int)$existing->id;
        }

        $row['created_by'] = $user_id;
        $this->db->insert(self::TABLE, $row);
        return (int)$this->db->insert_id();
    }

    /**
     * Update one existing row, even when the edit changes its key (for example
     * turning a standing default into a single-day override). Without this the
     * old row would be left behind next to the new one.
     *
     * @return bool
     */
    public function update_row($id, $post, $user_id = null) {
        if (!$this->table_ready()) { $this->last_error = 'The targets table is not installed.'; return false; }

        $id = (int)$id;
        if ($id <= 0 || $this->get($id) === null) { $this->last_error = 'That target no longer exists.'; return false; }

        $row = $this->sanitize($post);
        if ($row === null) return false;

        // a row for the new key may already exist (the edit closes a gap) — merge instead of duplicating
        $clash = $this->find_exact($row['scope'], $row['equipment_id'], $row['group_code'], $row['target_date']);
        if ($clash && (int)$clash->id !== $id) {
            $this->db->where('id', (int)$clash->id)->update(self::TABLE, array(
                'target_pct' => $row['target_pct'],
                'note'       => $row['note'],
            ));
            $this->db->where('id', $id)->delete(self::TABLE);
            return true;
        }

        $this->db->where('id', $id)->update(self::TABLE, array(
            'scope'        => $row['scope'],
            'equipment_id' => $row['equipment_id'],
            'group_code'   => $row['group_code'],
            'target_date'  => $row['target_date'],
            'target_pct'   => $row['target_pct'],
            'note'         => $row['note'],
        ));
        return true;
    }

    public function delete($id) {
        if (!$this->table_ready()) return false;
        $id = (int)$id;
        if ($id <= 0) return false;
        $this->db->where('id', $id)->delete(self::TABLE);
        return true;
    }

    /**
     * Apply one percentage to many machines in a single action — the "whole
     * fleet editable in under a minute" requirement. Standing default and
     * per-day overrides are both supported (empty date = standing default).
     *
     * @param  float $pct
     * @param  array $opts ['equipment_ids'=>array|null, 'group_code'=>string|null, 'target_date'=>string|null]
     * @return int number of machines written
     */
    public function bulk_apply($pct, array $opts = array(), $user_id = null) {
        if (!$this->table_ready()) { $this->last_error = 'The targets table is not installed.'; return 0; }

        $value = $this->normalize_pct($pct);
        if ($value === null) { $this->last_error = 'The target must be a percentage between 0 and 100.'; return 0; }

        $date = isset($opts['target_date']) ? $this->normalize_date($opts['target_date']) : null;
        $ids  = array();
        if (!empty($opts['equipment_ids'])) {
            foreach ((array)$opts['equipment_ids'] as $id) { $id = (int)$id; if ($id > 0) $ids[] = $id; }
        }

        $group = isset($opts['group_code']) ? trim((string)$opts['group_code']) : '';
        $machines = $this->reportable_machines($group === '' ? null : $group, $ids ?: null);
        if (!$machines) { $this->last_error = 'No machine matches that selection.'; return 0; }

        $written = 0;
        foreach ($machines as $m) {
            $this->db->insert(self::TABLE, array(
                'scope'        => self::SCOPE_EQUIPMENT,
                'equipment_id' => (int)$m->id,
                'group_code'   => null,
                'target_date'  => $date,
                'target_pct'   => number_format($value, 4, '.', ''),
                'note'         => $this->bulk_note($date),
                'created_by'   => $user_id,
            ));
            $written++;
        }
        return $written;
    }

    private function bulk_note($date) {
        return $date === null ? 'Applied to the fleet as the standing default'
                              : 'Applied to the fleet for ' . $date;
    }

    /**
     * Copy a week's per-day overrides onto another week, keeping each override
     * on the same weekday. Rows already present in the target week are updated,
     * never duplicated.
     *
     * @return int rows copied
     */
    public function copy_week($from_start, $to_start, $user_id = null) {
        if (!$this->table_ready()) { $this->last_error = 'The targets table is not installed.'; return 0; }

        $from_start = $this->normalize_date($from_start);
        $to_start   = $this->normalize_date($to_start);
        if ($from_start === null || $to_start === null) { $this->last_error = 'Pick both weeks.'; return 0; }

        $from_end = date('Y-m-d', strtotime($from_start . ' +6 days'));
        $shift    = (int)round((strtotime($to_start) - strtotime($from_start)) / 86400);

        $rows = $this->db->where('target_date >=', $from_start)
                         ->where('target_date <=', $from_end)
                         ->get(self::TABLE)->result();

        $copied = 0;
        foreach ($rows as $r) {
            $new_date = date('Y-m-d', strtotime($r->target_date . ' +' . $shift . ' days'));
            $existing = $this->find_exact($r->scope, $r->equipment_id, $r->group_code, $new_date);
            if ($existing) {
                $this->db->where('id', $existing->id)->update(self::TABLE, array('target_pct' => $r->target_pct));
            } else {
                $this->db->insert(self::TABLE, array(
                    'scope'        => $r->scope,
                    'equipment_id' => $r->equipment_id,
                    'group_code'   => $r->group_code,
                    'target_date'  => $new_date,
                    'target_pct'   => $r->target_pct,
                    'note'         => 'Copied from ' . $from_start . ' week',
                    'created_by'   => $user_id,
                ));
            }
            $copied++;
        }
        return $copied;
    }

    /* ────────────────────────────────────────────────────────────────────
     |  read
     * ──────────────────────────────────────────────────────────────────── */

    public function find_exact($scope, $equipment_id, $group_code, $target_date) {
        if (!$this->table_ready()) return null;

        $this->db->where('scope', $scope);
        if ($equipment_id) $this->db->where('equipment_id', (int)$equipment_id);
        if ($group_code)   $this->db->where('group_code', $group_code);
        $this->db->where('target_date', $target_date === null ? null : $this->normalize_date($target_date));

        $rows = $this->db->limit(1)->get(self::TABLE)->result();
        return $rows ? $rows[0] : null;
    }

    public function get($id) {
        if (!$this->table_ready()) return null;
        $rows = $this->db->where('id', (int)$id)->get(self::TABLE)->result();
        return $rows ? $rows[0] : null;
    }

    /**
     * Every target that matters for a week: the standing defaults plus the
     * per-day overrides that fall inside it.
     */
    public function for_week($week_start, $week_end, $scope = null, $group_code = null) {
        if (!$this->table_ready()) return array();

        $week_start = $this->normalize_date($week_start);
        $week_end   = $this->normalize_date($week_end);
        if ($week_start === null || $week_end === null) return array();

        if ($scope)      $this->db->where('scope', $scope);
        if ($group_code) $this->db->where('group_code', $group_code);

        $rows = $this->db->order_by('scope', 'ASC')
                         ->order_by('group_code', 'ASC')
                         ->order_by('target_date', 'ASC')
                         ->get(self::TABLE)->result();

        // keep the standing defaults and the overrides of this week only
        $out = array();
        foreach ($rows as $r) {
            if ($r->target_date === null || $r->target_date === '') { $out[] = $r; continue; }
            if ($r->target_date >= $week_start && $r->target_date <= $week_end) $out[] = $r;
        }
        return $out;
    }

    /** The machines a bulk action would touch: reportable, optionally grouped. */
    public function reportable_machines($group_code = null, $ids = null) {
        $ids = $ids === null ? null : (array)$ids;
        if (!$this->db->table_exists('equipment')) return array();

        $this->db->select('e.id, e.registration_no, e.report_group, e.status, e.include_in_weekly_report')
                 ->from('equipment e')
                 ->where('e.status !=', 'Retired');
        if ($group_code)          $this->db->where('e.report_group', $group_code);
        if ($ids !== null)        $this->db->where_in('e.id', $ids);
        $rows = $this->db->order_by('e.registration_no', 'ASC')->get()->result();

        $out = array();
        foreach ($rows as $r) {
            if (function_exists('equip_is_reportable') && !equip_is_reportable($r)) continue;
            $out[] = $r;
        }
        return $out;
    }

    /* ────────────────────────────────────────────────────────────────────
     |  resolution (W1.5)
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Pick the value that applies to one date out of the rows of one scope:
     * the exact date wins, otherwise the standing default (target_date NULL).
     *
     * @return object|null the winning row
     */
    public function pick(array $rows, $date) {
        $date    = $this->normalize_date($date);
        $default = null;

        foreach ($rows as $r) {
            $d = isset($r->target_date) ? $r->target_date : null;
            if ($d === null || $d === '') { $default = $r; continue; }
            if ($date !== null && $d === $date) return $r;
        }
        return $default;
    }

    /**
     * The target the weekly report must print for one machine on one day.
     *
     * Chain (first hit wins): equipment+date → equipment standing default →
     * group+date → group standing default → settings fallback.
     *
     * @param  float|null $fallback settings.default_availability_target
     * @return array|null ['pct'=>float,'source'=>'equipment-day',…,'label'=>…,'chain'=>[…]],
     *                    null when nothing applies and there is no fallback
     */
    public function resolve($equipment_id, $group_code, $date, $fallback = null) {
        return $this->resolve_from_rows(
            $this->rows_for(self::SCOPE_EQUIPMENT, $equipment_id, null),
            $this->rows_for(self::SCOPE_GROUP, null, $group_code),
            $date,
            $fallback
        );
    }

    /**
     * The same chain, evaluated purely on rows already loaded in memory.
     *
     * This is the one implementation of the rule: `resolve()` is only a fetch
     * around it, and the targets screen draws its week matrix from it, so the
     * screen can never disagree with what the report will print.
     *
     * @param  array $own_rows   rows of scope Equipment for this machine
     * @param  array $group_rows rows of scope Group for its group
     * @return array|null
     */
    public function resolve_from_rows(array $own_rows, array $group_rows, $date, $fallback = null) {
        $d = $this->normalize_date($date);

        $chain = array(
            'equipment-day'     => $this->value_of($this->pick($own_rows, $d), true),
            'equipment-default' => $this->value_of($this->pick($own_rows, null), false),
            'group-day'         => $this->value_of($this->pick($group_rows, $d), true),
            'group-default'     => $this->value_of($this->pick($group_rows, null), false),
        );

        $hit = $this->pick($own_rows, $d);
        if ($hit !== null) {
            $source = $this->hit_is_dated($hit, $d) ? 'equipment-day' : 'equipment-default';
            return $this->resolution($hit->target_pct, $source, $chain);
        }

        $hit = $this->pick($group_rows, $d);
        if ($hit !== null) {
            $source = $this->hit_is_dated($hit, $d) ? 'group-day' : 'group-default';
            return $this->resolution($hit->target_pct, $source, $chain);
        }

        $chain['settings'] = ($fallback === null || $fallback === '') ? null : (float)$fallback;
        if ($chain['settings'] === null) return null;

        return $this->resolution($fallback, 'settings', $chain);
    }

    /**
     * Resolution for a whole fleet over a whole week, computed from rows in
     * memory (no query per machine-day).
     *
     * @return array machine id => ['standing' => array|null, 'days' => [date => resolution|null]]
     */
    public function resolve_map(array $machines, $days, array $targets, $fallback = null) {
        list($by_id, $by_grp) = $this->partition($targets);

        $out = array();
        foreach ($machines as $m) {
            $id    = is_object($m) ? (int)$m->id : (int)$m['id'];
            $group = is_object($m) ? (isset($m->report_group) ? $m->report_group : null)
                                   : (isset($m['report_group']) ? $m['report_group'] : null);
            if ($group === '') $group = null;

            $own = isset($by_id[$id]) ? $by_id[$id] : array();
            $grp = ($group !== null && isset($by_grp[$group])) ? $by_grp[$group] : array();

            $row = array('standing' => $this->resolve_from_rows($own, $grp, null, $fallback), 'days' => array());
            foreach ((array)$days as $d) {
                $row['days'][$d] = $this->resolve_from_rows($own, $grp, $d, $fallback);
            }
            $out[$id] = $row;
        }
        return $out;
    }

    /** The seven dates of the report week starting at $week_start. */
    public function week_days($week_start) {
        $start = $this->normalize_date($week_start);
        if ($start === null) return array();
        $days = array();
        for ($i = 0; $i < 7; $i++) $days[] = date('Y-m-d', strtotime($start . ' +' . $i . ' days'));
        return $days;
    }

    /* ────────────────────────────────────────────────────────────────────
     |  screen helpers
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Counters for the header line of the targets screen and for the
     * data-quality panel: how much of the fleet is actually covered.
     *
     * Pure function over rows already in memory, so it is cheap to test.
     *
     * @param array $machines reportable machine rows
     * @param array $targets  rows from for_week()
     * @param array $days     the seven dates of the week
     */
    public function coverage(array $machines, array $targets, array $days) {
        $out = array(
            'machines'      => count($machines),
            'with_default'  => 0,
            'with_override' => 0,
            'unresolved'    => 0,   // machine-days with no value anywhere
            'machine_days'  => 0,
            'by_group'      => array(),
            'missing'       => array(),   // machine rows that are not covered at all
        );

        list($by_id, $by_grp) = $this->partition($targets);

        foreach ($machines as $m) {
            $id    = (int)$m->id;
            $group = isset($m->report_group) ? $m->report_group : null;
            $group_code = $group === '' ? null : $group;

            $own      = isset($by_id[$id]) ? $by_id[$id] : array();
            $own_std  = $this->pick($own, null) !== null;
            $own_days = 0;
            foreach ($own as $t) if ($t->target_date !== null && $t->target_date !== '') $own_days++;

            $grp      = ($group_code !== null && isset($by_grp[$group_code])) ? $by_grp[$group_code] : array();
            $grp_std  = $this->pick($grp, null) !== null;
            $grp_days = 0;
            foreach ($grp as $t) if ($t->target_date !== null && $t->target_date !== '') $grp_days++;

            if ($own_std)     $out['with_default']++;
            if ($own_days > 0 || ($group_code !== null && $grp_days > 0)) $out['with_override']++;

            // machine-days that resolve to nothing (no equipment value, no group value)
            $gaps = 0;
            foreach ((array)$days as $d) {
                $v = $this->pick($own, $d);
                if ($v === null && ($group_code === null || $this->pick($grp, $d) === null)) $gaps++;
            }
            $out['machine_days'] += count((array)$days);
            $out['unresolved']   += $gaps;
            if ($gaps === count((array)$days) && $gaps > 0) $out['missing'][] = $m;

            $key = $group_code === null ? '(no group)' : $group_code;
            if (!isset($out['by_group'][$key])) $out['by_group'][$key] = array('machines' => 0, 'with_default' => 0);
            $out['by_group'][$key]['machines']++;
            if ($own_std || $grp_std) $out['by_group'][$key]['with_default']++;
        }

        return $out;
    }

    /** All target rows of a week, ready for the table (labels resolved). */
    public function describe(array $targets, array $equipment_labels, array $group_labels) {
        $out = array();
        foreach ($targets as $t) {
            $out[] = array(
                'row'   => $t,
                'scope' => $t->scope === self::SCOPE_GROUP ? 'Group' : 'Machine',
                'who'   => $t->scope === self::SCOPE_GROUP
                             ? (isset($group_labels[$t->group_code]) ? $group_labels[$t->group_code] : (string)$t->group_code)
                             : (isset($equipment_labels[(int)$t->equipment_id]) ? $equipment_labels[(int)$t->equipment_id] : ('#' . (int)$t->equipment_id)),
                'when'  => ($t->target_date === null || $t->target_date === '')
                             ? 'Standing default'
                             : date('D d M Y', strtotime($t->target_date)),
                'pct'   => $this->pct_label($t->target_pct),
            );
        }
        return $out;
    }

    /* ────────────────────────────────────────────────────────────────────
     |  internals
     * ──────────────────────────────────────────────────────────────────── */

    /** Split target rows into machine => rows and group => rows maps. */
    public function partition(array $targets) {
        $by_id = array();
        $by_grp = array();
        foreach ($targets as $t) {
            if ($t->scope === self::SCOPE_EQUIPMENT && $t->equipment_id) {
                $by_id[(int)$t->equipment_id][] = $t;
            } elseif ($t->scope === self::SCOPE_GROUP && $t->group_code) {
                $by_grp[$t->group_code][] = $t;
            }
        }
        return array($by_id, $by_grp);
    }

    private function rows_for($scope, $equipment_id, $group_code) {
        if (!$this->table_ready()) return array();

        // Validate BEFORE touching the query builder. $this->db is shared by
        // every model in the request, and CI3 only clears pending conditions
        // when a query actually runs — so adding where('scope', …) and then
        // returning early left that condition on the builder, and the next
        // query (e.g. the dashboard's count of active users) inherited it:
        //   SELECT COUNT(*) FROM `users`
        //   WHERE `scope` = 'Equipment' AND `scope` = 'Group' AND `status` = 'Active'
        // → "Unknown column 'scope'" → count_all_results() calling num_rows()
        // on FALSE → fatal Error → blank HTTP 500 (live log, 2026-10-16).
        // resolve(null, null, …) — the fleet-wide target lookup used by the
        // dashboard and the weekly reports — hit both early returns at once.
        if ($scope === self::SCOPE_EQUIPMENT) {
            if (!$equipment_id) return array();
        } else {
            if (!$group_code) return array();
        }

        $this->db->where('scope', $scope);
        if ($scope === self::SCOPE_EQUIPMENT) {
            $this->db->where('equipment_id', (int)$equipment_id);
        } else {
            $this->db->where('group_code', $group_code);
        }
        return $this->db->get(self::TABLE)->result();
    }

    private function hit_is_dated($hit, $date) {
        $d = isset($hit->target_date) ? $hit->target_date : null;
        return $d !== null && $d !== '' && $d === $this->normalize_date($date);
    }

    private function value_of($row, $dated) {
        if ($row === null) return null;
        $d = isset($row->target_date) ? $row->target_date : null;
        $is_dated = ($d !== null && $d !== '');
        if ($dated !== $is_dated) return null;
        return (float)$row->target_pct;
    }

    private function resolution($pct, $source, array $chain) {
        return array(
            'pct'    => (float)$pct,
            'source' => $source,
            'label'  => isset(self::RESOLUTION_CHAIN[$source]) ? self::RESOLUTION_CHAIN[$source] : $source,
            'chain'  => $chain,
        );
    }

    private function equipment_exists($id) {
        if (!$this->db->table_exists('equipment')) return true;   // cannot check → let the FK-less insert decide
        $rows = $this->db->where('id', (int)$id)->get('equipment')->result();
        return !empty($rows);
    }

    private function known_group_codes() {
        if (!$this->db->table_exists('report_groups')) return array();
        $rows = $this->db->get('report_groups')->result();
        $out  = array();
        foreach ($rows as $r) $out[] = $r->code;
        return $out;
    }
}
