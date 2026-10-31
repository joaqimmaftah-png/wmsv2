-- Fleet Availability WMS live-data profile
-- Purpose: read-only diagnostics for Phase 1 feasibility/reconciliation.
-- This script contains SELECT statements only; SET below creates session-scoped
-- values and does not write application data. Run using a read-only DB account.
--
-- Change @week_start and @week_end_exclusive to match the exact reporting week
-- being reconciled (Monday start, next Monday exclusive in this example).
-- No row-level person names or free-text narratives are selected.

SET @week_start = '2026-10-19 00:00:00';
SET @week_end_exclusive = '2026-10-26 00:00:00';

-- 1. Active report settings: especially open time, week rules and KPI policy.
SELECT setting_key, setting_value, description
FROM settings
WHERE setting_key IN (
  'weekly_open_hours', 'daily_open_hours', 'report_week_start_day',
  'default_availability_target', 'breakdown_count_mode',
  'mtbf_zero_failure_mode', 'pm_meter_stale_days'
)
ORDER BY `key`;

-- 2. Equipment population/status/reportability completeness.
SELECT
  status,
  include_in_weekly_report,
  COUNT(*) AS equipment_count,
  SUM(CASE WHEN report_group IS NULL OR TRIM(report_group) = '' THEN 1 ELSE 0 END) AS missing_report_group,
  SUM(CASE WHEN location IS NULL OR TRIM(location) = '' THEN 1 ELSE 0 END) AS missing_location
FROM equipment
GROUP BY status, include_in_weekly_report
ORDER BY status, include_in_weekly_report;

-- 3. Duplicate canonical equipment registrations.
SELECT registration_no, COUNT(*) AS row_count
FROM equipment
GROUP BY registration_no
HAVING COUNT(*) > 1
ORDER BY row_count DESC, registration_no;

-- 4. Work orders overlapping the selected week (by actual interval), grouped
-- by classification/status. This is not yet the KPI event-union calculation.
SELECT
  type, is_breakdown, status,
  COUNT(*) AS work_orders,
  SUM(CASE WHEN start_datetime IS NULL THEN 1 ELSE 0 END) AS missing_start,
  SUM(CASE WHEN end_datetime IS NULL THEN 1 ELSE 0 END) AS missing_end,
  SUM(CASE WHEN breakdown_event_id IS NULL AND is_breakdown = 1 THEN 1 ELSE 0 END) AS breakdown_without_event_id
FROM work_orders
WHERE start_datetime < @week_end_exclusive
  AND (end_datetime IS NULL OR end_datetime > @week_start)
GROUP BY type, is_breakdown, status
ORDER BY type, is_breakdown, status;

-- 5. Work-order interval and stored-duration anomalies. A null end on an active
-- WO is not inherently invalid; a completed/closed WO without end needs review.
SELECT
  COUNT(*) AS total_work_orders,
  SUM(CASE WHEN is_breakdown = 1 AND start_datetime IS NULL THEN 1 ELSE 0 END) AS breakdown_missing_start,
  SUM(CASE WHEN start_datetime IS NOT NULL AND end_datetime IS NOT NULL AND end_datetime <= start_datetime THEN 1 ELSE 0 END) AS invalid_interval,
  SUM(CASE WHEN status IN ('Completed','Closed') AND end_datetime IS NULL THEN 1 ELSE 0 END) AS closed_without_end,
  SUM(CASE WHEN downtime_hours < 0 THEN 1 ELSE 0 END) AS negative_stored_downtime,
  SUM(CASE WHEN start_datetime IS NOT NULL AND end_datetime IS NOT NULL
                AND end_datetime > start_datetime AND downtime_hours IS NOT NULL
                AND ABS(downtime_hours - TIMESTAMPDIFF(SECOND, start_datetime, end_datetime) / 3600.0) > 0.05
           THEN 1 ELSE 0 END) AS stored_duration_differs_by_over_003h
FROM work_orders;

-- 6. Breakdown event IDs reused across multiple assets (should be reviewed).
SELECT breakdown_event_id,
       COUNT(*) AS work_order_count,
       COUNT(DISTINCT equipment_id) AS equipment_count,
       MIN(start_datetime) AS first_start,
       MAX(end_datetime) AS latest_end
FROM work_orders
WHERE is_breakdown = 1 AND breakdown_event_id IS NOT NULL
GROUP BY breakdown_event_id
HAVING COUNT(DISTINCT equipment_id) > 1
ORDER BY equipment_count DESC, work_order_count DESC;

-- 7. Breakdown WOs with strictly overlapping intervals on the same equipment.
-- Some overlaps are expected when multiple repair WOs belong to one event;
-- overlaps across event IDs or duplicate intervals need review. Open intervals
-- are capped at the selected week's end for this diagnostic.
SELECT
  a.equipment_id,
  a.id AS work_order_a,
  b.id AS work_order_b,
  a.breakdown_event_id AS event_a,
  b.breakdown_event_id AS event_b,
  a.start_datetime AS start_a,
  a.end_datetime AS end_a,
  b.start_datetime AS start_b,
  b.end_datetime AS end_b
FROM work_orders a
JOIN work_orders b
  ON b.equipment_id = a.equipment_id
 AND b.id > a.id
 AND b.is_breakdown = 1
 AND a.is_breakdown = 1
 AND a.start_datetime IS NOT NULL
 AND b.start_datetime IS NOT NULL
 AND a.start_datetime < COALESCE(b.end_datetime, @week_end_exclusive)
 AND b.start_datetime < COALESCE(a.end_datetime, @week_end_exclusive)
WHERE a.start_datetime < @week_end_exclusive
  AND b.start_datetime < @week_end_exclusive
  AND (a.end_datetime IS NULL OR a.end_datetime > @week_start)
  AND (b.end_datetime IS NULL OR b.end_datetime > @week_start)
ORDER BY a.equipment_id, a.start_datetime, b.start_datetime;

-- 8. Work-order to equipment referential anomalies and current equipment status
-- disagreement. The latter is a review queue, not an automatic correction.
SELECT
  SUM(CASE WHEN e.id IS NULL THEN 1 ELSE 0 END) AS work_orders_without_equipment,
  SUM(CASE WHEN w.is_breakdown = 1 AND w.status IN ('Open','In Progress','Waiting Parts','Waiting Tools')
                AND e.id IS NOT NULL AND e.status NOT IN ('Breakdown','In Maintenance')
           THEN 1 ELSE 0 END) AS active_breakdown_wo_equipment_not_marked_down,
  SUM(CASE WHEN e.status = 'Breakdown'
                AND NOT EXISTS (
                  SELECT 1 FROM work_orders x
                  WHERE x.equipment_id = e.id AND x.is_breakdown = 1
                    AND x.status IN ('Open','In Progress','Waiting Parts','Waiting Tools')
                )
           THEN 1 ELSE 0 END) AS equipment_breakdown_without_active_breakdown_wo
FROM work_orders w
LEFT JOIN equipment e ON e.id = w.equipment_id;

-- 9. Labor-session data quality and linkage. Session hours are labor, not
-- automatically machine downtime. Rows with null hours may be open sessions.
SELECT
  COUNT(*) AS session_count,
  SUM(CASE WHEN w.id IS NULL THEN 1 ELSE 0 END) AS orphan_work_order,
  SUM(CASE WHEN s.start_datetime IS NULL THEN 1 ELSE 0 END) AS missing_session_start,
  SUM(CASE WHEN s.end_datetime IS NOT NULL AND s.end_datetime <= s.start_datetime THEN 1 ELSE 0 END) AS invalid_session_interval,
  SUM(CASE WHEN s.status = 'Closed' AND (s.hours_worked IS NULL OR s.hours_worked <= 0) THEN 1 ELSE 0 END) AS closed_session_missing_positive_hours,
  SUM(CASE WHEN s.start_datetime >= @week_start AND s.start_datetime < @week_end_exclusive THEN 1 ELSE 0 END) AS sessions_started_in_selected_week
FROM wo_sessions s
LEFT JOIN work_orders w ON w.id = s.wo_id;

-- 10. Preventive labor/activity by WO classification in the selected week.
SELECT
  w.type,
  w.is_breakdown,
  COUNT(DISTINCT w.id) AS work_orders,
  COUNT(s.id) AS session_rows,
  ROUND(COALESCE(SUM(s.hours_worked), 0), 2) AS logged_labor_hours,
  SUM(CASE WHEN s.hours_worked IS NULL OR s.hours_worked <= 0 THEN 1 ELSE 0 END) AS session_rows_without_positive_hours
FROM work_orders w
LEFT JOIN wo_sessions s
  ON s.wo_id = w.id
 AND s.start_datetime >= @week_start
 AND s.start_datetime < @week_end_exclusive
WHERE w.start_datetime < @week_end_exclusive
  AND (w.end_datetime IS NULL OR w.end_datetime > @week_start)
GROUP BY w.type, w.is_breakdown
ORDER BY w.type, w.is_breakdown;

-- 11. Backlog status/condition/link completeness. A BREAKDOWN condition here is
-- defect context, not proof of a timed breakdown event.
SELECT
  status,
  machine_status,
  COUNT(*) AS backlog_items,
  SUM(CASE WHEN equipment_id IS NULL THEN 1 ELSE 0 END) AS missing_equipment_id,
  SUM(CASE WHEN work_order_id IS NULL THEN 1 ELSE 0 END) AS without_work_order,
  SUM(CASE WHEN end_date IS NOT NULL AND end_date < start_date THEN 1 ELSE 0 END) AS end_date_before_start_date
FROM backlogs
GROUP BY status, machine_status
ORDER BY status, machine_status;

-- 12. Backlog links to nonexistent/different-equipment work orders.
SELECT
  COUNT(*) AS linked_backlog_rows,
  SUM(CASE WHEN w.id IS NULL THEN 1 ELSE 0 END) AS missing_linked_work_order,
  SUM(CASE WHEN w.id IS NOT NULL AND b.equipment_id IS NOT NULL
                AND w.equipment_id <> b.equipment_id THEN 1 ELSE 0 END) AS equipment_mismatch
FROM backlogs b
LEFT JOIN work_orders w ON w.id = b.work_order_id
WHERE b.work_order_id IS NOT NULL;

-- 13. Target coverage and scope configuration (validate duplicate/overlapping
-- policy rows separately against Kpi_target_model's resolution order).
SELECT scope, target_date, COUNT(*) AS target_rows,
       MIN(target_pct) AS min_target, MAX(target_pct) AS max_target
FROM equipment_kpi_targets
GROUP BY scope, target_date
ORDER BY scope, target_date;

SELECT
  SUM(CASE WHEN scope = 'Equipment' AND equipment_id IS NULL THEN 1 ELSE 0 END) AS equipment_scope_missing_equipment,
  SUM(CASE WHEN scope = 'Group' AND (group_code IS NULL OR TRIM(group_code) = '') THEN 1 ELSE 0 END) AS group_scope_missing_code,
  SUM(CASE WHEN target_pct < 0 OR target_pct > 1 THEN 1 ELSE 0 END) AS target_out_of_range
FROM equipment_kpi_targets;

-- 14. Planned downtime is a plan/forecast ledger; report its categories/sources
-- separately from realized work-order and session totals.
SELECT category, source, COUNT(*) AS rows,
       SUM(hours) AS planned_hours,
       SUM(CASE WHEN hours < 0 OR hours > 24 THEN 1 ELSE 0 END) AS invalid_machine_day_hours
FROM equipment_planned_downtime
GROUP BY category, source
ORDER BY category, source;

-- 15. Weekly snapshot coverage and integrity. A mismatch may require review;
-- do not rewrite snapshots as part of this audit.
SELECT
  COUNT(*) AS snapshot_count,
  SUM(CASE WHEN payload_checksum <> SHA2(payload, 256) THEN 1 ELSE 0 END) AS checksum_mismatch_count,
  MIN(week_start) AS earliest_published_week,
  MAX(week_start) AS latest_published_week
FROM weekly_report_snapshots;

SELECT week_start, week_end, iso_label, published_at,
       CASE WHEN payload_checksum = SHA2(payload, 256) THEN 'ok' ELSE 'mismatch' END AS checksum_status
FROM weekly_report_snapshots
WHERE week_start >= DATE(@week_start) - INTERVAL 35 DAY
  AND week_start <= DATE(@week_start)
ORDER BY week_start;

-- 16. One selected-week fleet footprint. For actual KPI totals, use the WMS
-- reports/engine (Kpi_engine_model + kpi_helper.php); a simple SUM of stored
-- downtime_hours does NOT merge overlaps and is not a valid availability total.
SELECT
  COUNT(DISTINCT e.id) AS non_retired_equipment,
  COUNT(DISTINCT CASE WHEN e.include_in_weekly_report = 1 THEN e.id END) AS marked_reportable,
  COUNT(DISTINCT CASE WHEN w.id IS NOT NULL THEN e.id END) AS equipment_with_overlapping_week_work_order,
  COUNT(DISTINCT CASE WHEN w.is_breakdown = 1 THEN w.id END) AS breakdown_work_orders,
  COUNT(DISTINCT CASE WHEN w.is_breakdown = 1 AND w.breakdown_event_id IS NOT NULL THEN w.breakdown_event_id END) AS linked_breakdown_event_ids
FROM equipment e
LEFT JOIN work_orders w
  ON w.equipment_id = e.id
 AND w.start_datetime < @week_end_exclusive
 AND (w.end_datetime IS NULL OR w.end_datetime > @week_start)
WHERE e.status <> 'Retired';
