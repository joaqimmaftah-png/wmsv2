<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Equipment-status helpers — the single source of truth for
 * "what does this machine status mean".
 *
 * Why this file exists (plan task W0.2)
 * -------------------------------------
 * `equipment.status` is an enum: Available | In Maintenance | Breakdown |
 * Standby | Retired.  The codebase nevertheless compared it against a status
 * the enum does not contain — the word Maintenance without the "In " prefix —
 * so those comparisons never matched:
 *
 *     in_array($eq->equip_status, ['Breakdown', Maintenance_literal, ...])
 *     $eq->equip_status === 'Breakdown' || $eq->equip_status === Maintenance_literal
 *     $statusColor = [... Maintenance_literal => ...]        // dead key
 *
 * and every view re-implemented the same status → colour/led/badge mapping
 * inline.  This helper centralises the vocabulary, the grouping and the
 * presentation map so a status is defined exactly once.
 *
 * Groups (used for the DOWN / AVAILABLE columns and the fleet KPI strip):
 *   Operational : Available, Standby
 *   Down        : Breakdown, In Maintenance
 *   Retired     : Retired  (excluded from weekly reporting)
 *
 * Rules BR-W18 / BR-W20; decision OPEN-7 (period figures come from work
 * orders, current status from
 * `equipment.status`, disagreements are surfaced in the data-quality panel).
 */

if (!function_exists('equip_statuses')) {
    /**
     * The canonical status list, in `equipment.status` enum order.
     * Use it for dropdowns, filters and validation instead of copying literals.
     *
     * @return string[]
     */
    function equip_statuses()
    {
        return array('Available', 'In Maintenance', 'Breakdown', 'Standby', 'Retired');
    }
}

if (!function_exists('equip_status_of')) {
    /**
     * Read the status from a row (object or array), a plain status string, or nothing.
     *
     * @param  mixed $equipment row with ->status / ->equip_status, or a status string
     * @return string  '' when there is no usable status
     */
    function equip_status_of($equipment)
    {
        if (is_string($equipment) || $equipment === null) {
            return (string)$equipment;
        }
        foreach (array('status', 'equip_status', 'machine_status') as $key) {
            if (is_object($equipment) && isset($equipment->$key)) return (string)$equipment->$key;
            if (is_array($equipment) && isset($equipment[$key]))  return (string)$equipment[$key];
        }
        return '';
    }
}

if (!function_exists('equip_status_map')) {
    /**
     * The canonical meaning and presentation of a status.
     *
     * @param  string $status
     * @return array{status:string,known:bool,group:string,label:string,
     *               badge:string,led:string,color:string,bg:string}
     */
    function equip_status_map($status)
    {
        $status = (string)$status;

        $map = array(
            'Available' => array(
                'group' => 'Operational', 'badge' => 'b-available', 'led' => 'led-ok',
                'color' => 'var(--green)', 'bg' => 'var(--green-d)',
            ),
            'Standby' => array(
                'group' => 'Operational', 'badge' => 'b-standby', 'led' => 'led-off',
                'color' => 'var(--t3)', 'bg' => 'var(--g2)',
            ),
            'In Maintenance' => array(
                'group' => 'Down', 'badge' => 'b-maintenance', 'led' => 'led-warn',
                'color' => 'var(--amber)', 'bg' => 'var(--amber-d)',
            ),
            'Breakdown' => array(
                'group' => 'Down', 'badge' => 'b-breakdown', 'led' => 'led-crit',
                'color' => 'var(--red)', 'bg' => 'var(--red-d)',
            ),
            'Retired' => array(
                'group' => 'Retired', 'badge' => 'b-secondary', 'led' => 'led-off',
                'color' => 'var(--t3)', 'bg' => 'var(--g2)',
            ),
        );

        $known = isset($map[$status]);
        $base  = $known ? $map[$status] : array(
            'group' => 'Unknown', 'badge' => 'b-secondary', 'led' => 'led-off',
            'color' => 'var(--t3)', 'bg' => 'var(--g2)',
        );

        return array_merge(array(
            'status' => $status,
            'known'  => $known,
            'label'  => $status === '' ? '—' : $status,
        ), $base);
    }
}

if (!function_exists('equip_status_group')) {
    /**
     * @param  mixed $equipment row or status string
     * @return string 'Operational' | 'Down' | 'Retired' | 'Unknown'
     */
    function equip_status_group($equipment)
    {
        $map = equip_status_map(equip_status_of($equipment));
        return $map['group'];
    }
}

if (!function_exists('equip_status_is_known')) {
    /**
     * False for anything outside the enum — a data-quality signal, never a crash.
     *
     * @param  mixed $equipment row or status string
     * @return bool
     */
    function equip_status_is_known($equipment)
    {
        return equip_status_of($equipment) === ''
            ? false
            : equip_status_map(equip_status_of($equipment))['known'];
    }
}

if (!function_exists('equip_is_down')) {
    /**
     * True when the machine is out of service: Breakdown or In Maintenance.
     * This replaces every `in_array($status, ['Breakdown','In Maintenance'])`
     * comparison in the application.
     *
     * @param  mixed $equipment row or status string
     * @return bool
     */
    function equip_is_down($equipment)
    {
        return equip_status_group($equipment) === 'Down';
    }
}

if (!function_exists('equip_is_reportable')) {
    /**
     * Is this machine part of the weekly report? (BR-W20)
     *
     * A machine is reportable when it is not retired AND `include_in_weekly_report`
     * is not switched off (W0.3 - the flag is maintained on the equipment form).
     * Rows that do not carry the column (older selects, partial fixtures) are
     * treated as reportable, so the default is never "silently missing".
     *
     * @param  object|array $equipment
     * @return bool
     */
    function equip_is_reportable($equipment)
    {
        if (!is_object($equipment) && !is_array($equipment)) {
            return false;
        }

        if (strcasecmp(equip_status_of($equipment), 'Retired') === 0) {
            return false;
        }

        if (is_object($equipment)) {
            $flag = isset($equipment->include_in_weekly_report) ? $equipment->include_in_weekly_report : null;
        } else {
            $flag = array_key_exists('include_in_weekly_report', (array)$equipment)
                ? $equipment['include_in_weekly_report'] : null;
        }

        return $flag === null ? true : ((int)$flag) !== 0;
    }
}

if (!function_exists('equip_report_sort_label')) {
    /**
     * Human label for the report position of a machine, shown on the equipment card.
     *
     * @param  object|array $equipment
     * @return string e.g. 'ADT / 30002'
     */
    function equip_report_sort_label($equipment)
    {
        $group = is_object($equipment)
            ? (isset($equipment->report_group) ? $equipment->report_group : '')
            : (isset($equipment['report_group']) ? $equipment['report_group'] : '');
        $sort = is_object($equipment)
            ? (isset($equipment->report_sort) ? (int)$equipment->report_sort : 0)
            : (isset($equipment['report_sort']) ? (int)$equipment['report_sort'] : 0);

        if ($group === '' && !$sort) return '';
        $out = $group !== '' ? $group : '-';
        if ($sort) $out .= ' / ' . $sort;
        return $out;
    }
}

if (!function_exists('equip_status_sql')) {
    /**
     * SQL predicate for "this machine is down", for raw query fragments.
     * The statuses are written literally because the column is an enum.
     *
     * @param  string $column
     * @return string e.g.  e.status IN ('Breakdown','In Maintenance')
     */
    function equip_status_sql($column = 'status')
    {
        return $column . " IN ('Breakdown','In Maintenance')";
    }
}

if (!function_exists('equip_machine_id')) {
    /**
     * The short code the weekly report prints in the MACHINE ID column.
     *
     * BR-W5: COALESCE(legacy_code, registration_no) — a machine that has no
     * short code still resolves to something, it is never blank and it is never
     * invented by the report.
     *
     * @param  object|array|null $equipment
     * @return string
     */
    function equip_machine_id($equipment)
    {
        if (!is_object($equipment) && !is_array($equipment)) {
            return '';
        }

        $get = function ($key) use ($equipment) {
            if (is_object($equipment)) return isset($equipment->$key) ? trim((string)$equipment->$key) : '';
            return isset($equipment[$key]) ? trim((string)$equipment[$key]) : '';
        };

        $legacy = $get('legacy_code');
        return $legacy !== '' ? $legacy : $get('registration_no');
    }
}

if (!function_exists('equip_site_key')) {
    /**
     * Compare a stored location with a site of the approved vocabulary without
     * caring about case, runs of spaces or the underscore used in site codes:
     * "MET PLANT" ≡ "met  plant" ≡ "MET_PLANT".
     */
    function equip_site_key($value)
    {
        $value = strtoupper(trim((string)$value));
        return preg_replace('/[\s_]+/', ' ', $value);
    }
}

if (!function_exists('equip_is_approved_site')) {
    /**
     * Is this value one of the approved sites?
     *
     * @param  string $value  equipment.location as stored
     * @param  array  $sites  label => (anything) or a plain list of labels
     * @return bool
     */
    function equip_is_approved_site($value, $sites)
    {
        if (equip_site_key($value) === '') {
            return false;
        }

        foreach ((array)$sites as $key => $label) {
            $candidate = is_int($key) ? $label : $key;                 // plain list or label => x
            if (equip_site_key($candidate) === equip_site_key($value)) return true;
            if (is_string($label) && equip_site_key($label) === equip_site_key($value)) return true;
        }
        return false;
    }
}

if (!function_exists('equip_site_label')) {
    /**
     * The approved spelling of a location value, or the value itself when it is
     * not approved (the report still prints it, but flags it).
     */
    function equip_site_label($value, $sites)
    {
        $key = equip_site_key($value);

        foreach ((array)$sites as $k => $v) {
            // report_sites() hands over label => code, but a plain list of labels
            // (or of codes) is accepted too
            $label = is_int($k) ? $v : $k;
            $code  = is_int($k) ? null : $v;

            if (!is_string($label)) continue;
            if (equip_site_key($label) === $key) return $label;
            if (is_string($code) && equip_site_key($code) === $key) return $label;
        }
        return trim((string)$value);
    }
}

