<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Work-order classification helpers — the single source of truth for
 * "what is a breakdown work order".
 *
 * Why this file exists (review blocker B2, plan task W0.1)
 * ------------------------------------------------------
 * `work_orders.type` is an enum holding only three values:
 *
 *     'Corrective Maintenance' | 'Preventive Maintenance' | 'Incident'
 *
 * Code written as `->where('type', 'Breakdown')` (8 places in Dashboard_model,
 * plus Asset_model, Equipment_model and Reports) therefore matched **nothing**:
 * breakdown counts, MTTR, MTBF, the equipment reliability KPIs and the
 * dashboard donut silently reported zero.
 *
 * The explicit flag `work_orders.is_breakdown` is now the source of truth
 * (the several repair work orders of one failure are linked through
 * `breakdown_event_id`). Rows created before the flag existed fall back to
 * the corrective type, so the helpers below are safe on legacy data too.
 *
 * Rules BR-W7 / BR-W7b / BR-W7c; decisions D1 and D5.
 */

if (!function_exists('wo_breakdown_sql')) {
    /**
     * SQL predicate for "this work order is a breakdown".
     *
     * Use it in raw SQL fragments, e.g.
     *   COUNT(CASE WHEN is_breakdown = 1 THEN 1 END)
     *
     * @param  string $column column name (optionally table-qualified)
     * @return string
     */
    function wo_breakdown_sql($column = 'is_breakdown')
    {
        return $column . ' = 1';
    }
}

if (!function_exists('wo_is_breakdown')) {
    /**
     * True when a work-order row (object or array) represents a breakdown.
     *
     * The flag wins when present; otherwise the historical corrective type is
     * used, because corrective maintenance is the workshop's breakdown work.
     *
     * @param  object|array $wo
     * @return bool
     */
    function wo_is_breakdown($wo)
    {
        if (!is_object($wo) && !is_array($wo)) {
            return false;
        }

        $flag = is_object($wo)
            ? (isset($wo->is_breakdown) ? $wo->is_breakdown : null)
            : (array_key_exists('is_breakdown', (array)$wo) ? $wo['is_breakdown'] : null);

        if ($flag !== null) {
            return ((int)$flag) === 1;
        }

        $type = is_object($wo) ? (isset($wo->type) ? $wo->type : null) : (isset($wo['type']) ? $wo['type'] : null);

        return is_string($type) && strcasecmp($type, 'Corrective Maintenance') === 0;
    }
}

if (!function_exists('wo_bucket')) {
    /**
     * Reporting bucket of a work order, used by the dashboards and charts.
     *
     * @param  object|array $wo
     * @return string 'Breakdown' | 'Preventive' | 'Incident' | 'Other'
     */
    function wo_bucket($wo)
    {
        if (wo_is_breakdown($wo)) {
            return 'Breakdown';
        }

        $type = is_object($wo) ? (isset($wo->type) ? $wo->type : null) : (isset($wo['type']) ? $wo['type'] : null);
        $type = is_string($type) ? $type : '';

        if (strcasecmp($type, 'Preventive Maintenance') === 0) {
            return 'Preventive';
        }
        if (strcasecmp($type, 'Incident') === 0) {
            return 'Incident';
        }

        return 'Other';
    }
}

if (!function_exists('wo_buckets')) {
    /**
     * The buckets in display order (keys are always present, value 0).
     *
     * @return array<string,int>
     */
    function wo_buckets()
    {
        return array('Breakdown' => 0, 'Preventive' => 0, 'Incident' => 0, 'Other' => 0);
    }
}

/**
 * The five pending-work codes confirmed by the business (decision D5).
 *
 * ONE source for the sheet legend, the STATUS column of the action plan and
 * any screen that prints why work is waiting (rule BR-28): a code can never
 * mean two things in two places.
 *
 * @return array code => the wording the business approved
 */
function wo_hold_codes()
{
    return array(
        'WSP' => 'Waiting for Spare Part',
        'WQT' => 'Waiting for Quotation',
        'WCF' => 'Waiting for Confirmation',
        'UD'  => 'Under Diagnosis',
        'UR'  => 'Under Repair',
    );
}

/**
 * The label of one hold code. Unknown codes are returned as they are and an
 * empty code stays null — the report prints an em dash, never a guess.
 */
function wo_hold_label($code)
{
    $code = strtoupper(trim((string)$code));
    if ($code === '') return null;
    $codes = wo_hold_codes();
    return isset($codes[$code]) ? $codes[$code] : $code;
}
