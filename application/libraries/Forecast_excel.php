<?php
/**
 * Forecast Excel writer — task F8.
 *
 * The workbook the workshop is used to, generated from the database.
 *
 * Deliberate departures from the legacy `WEEK 40 FORECAST 2026.xlsx`
 * workbook, each one a defect found in that workbook itself:
 *
 *   1.    a day's availability was typed `0.04166` rather than computed, so a
 *         machine planned out 24 h/day printed 0.02 % instead of 0 %.  Every
 *         figure here comes from `kpi_availability()` — nothing is typed.
 *   2.    the daily divisor was 30 in 309 cells and 24 in one, under a header
 *         that said 24 HRS.  One divisor, from `settings`, is used throughout.
 *   3.    `EX 006` was missing from its own group roll-up.  Rows are emitted
 *         from the model's own list, so a machine cannot be dropped.
 *   4.    group figures were an unweighted mean of percentages.  Subtotals are
 *         capacity-weighted (D30); the unweighted mean appears only in the
 *         labelled reference column, and only when the setting allows it.
 *   5.    a machine out of service all week was scored as if it could be
 *         available.  It leaves the availability population and is reported in
 *         the unit view instead (D32).
 *   6.    the rolling schedule sheet carried `#REF!` in two of its three week
 *         blocks.  Every cell here is a value read from the database, so a
 *         stale reference cannot exist.
 *
 * The shape is split the way `W36_excel` splits it: `build_*()` returns a plain
 * cell map with no writer in sight, so the exact figures can be asserted
 * without a workbook, and `paint()` is the only thing that touches
 * XlsxWriter.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

#[AllowDynamicProperties]
class Forecast_excel {

    /** 1899-12-30 — the serial-date epoch every Excel reader expects. */
    const EXCEL_EPOCH = '1899-12-30';

    /* ── styles, in the writer's own vocabulary ─────────────────────── */
    const S_TITLE  = 'bold|fontSize:14|align:left|valign:middle|fg:1F4E79';
    const S_SUB    = 'fontSize:9|align:left|valign:middle|fg:808080';
    const S_HDR    = 'bold|fontSize:9|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap';
    const S_GRP    = 'bold|fontSize:10|align:left|valign:middle|bg:BDD7EE|fg:1F4E79|border:thin';
    const S_TXT    = 'fontSize:9|valign:middle|border:thin';
    const S_TXT_C  = 'fontSize:9|align:center|valign:middle|border:thin';
    const S_ID     = 'bold|fontSize:9|align:center|valign:middle|border:thin|fg:1F4E79';
    const S_NUM    = 'fontSize:9|align:center|valign:middle|border:thin|numFmt:0.##';
    const S_HRS    = 'fontSize:9|align:center|valign:middle|border:thin|numFmt:0.00';
    const S_PCT    = 'bold|fontSize:10|align:center|valign:middle|border:thin|numFmt:0.00%';
    const S_PCT_REF= 'fontSize:9|align:center|valign:middle|border:thin|numFmt:0.00%|fg:808080';
    const S_OUT    = 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:FFF2CC|fg:7F6000';
    const S_SUBTOT = 'bold|fontSize:9|valign:middle|border:thin|bg:F2F2F2';
    const S_SUB_N  = 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:F2F2F2|numFmt:0.00';
    const S_SUB_P  = 'bold|fontSize:10|align:center|valign:middle|border:thin|bg:F2F2F2|numFmt:0.00%';
    const S_FLEET  = 'bold|fontSize:10|valign:middle|border:thin|bg:1F4E79|fg:FFFFFF';
    const S_FLEET_N= 'bold|fontSize:10|align:center|valign:middle|border:thin|bg:1F4E79|fg:FFFFFF|numFmt:0.00';
    const S_FLEET_P= 'bold|fontSize:11|align:center|valign:middle|border:thin|bg:1F4E79|fg:FFFFFF|numFmt:0.00%';
    const S_GOOD   = 'bold|fontSize:10|align:center|valign:middle|border:thin|numFmt:+0.00;-0.00|fg:2E7D32';
    const S_BAD    = 'bold|fontSize:10|align:center|valign:middle|border:thin|numFmt:+0.00;-0.00|fg:C00000';
    const S_NOTE   = 'fontSize:9|align:left|valign:top|wrap|fg:808080';

    /** Optional callable returning a fresh XlsxWriter (tests inject a spy). */
    private $writer_factory;

    public function __construct($params = array()) {
        if (isset($params['writer_factory']) && is_callable($params['writer_factory'])) {
            $this->writer_factory = $params['writer_factory'];
        }
    }

    /* ════════════════════════════════════════════════════════════════════
     |  SHEET 1 — PLANNED AVAILABILITY
     |
     |  One row per machine, its seven planned days, its weekly hours and the
     |  availability they produce.  A group subtotal under each group and a
     |  fleet row at the foot — the workbook's own shape, with the arithmetic
     |  replaced by the KPI engine.
     * ════════════════════════════════════════════════════════════════════ */

    /**
     * @param  array $plan  Forecast_model::planned_availability() output
     * @param  array $opts  show_reference (bool)
     * @return array        a cell map for paint()
     */
    public function build_planned(array $plan, array $opts = array()) {
        $show_ref = !isset($opts['show_reference']) || $opts['show_reference'];

        $days   = isset($plan['days']) ? $plan['days'] : array();
        $rows   = isset($plan['rows']) ? $plan['rows'] : array();
        $groups = isset($plan['groups']) ? $plan['groups'] : array();
        $fleet  = isset($plan['fleet']) ? $plan['fleet'] : array();
        $unit   = isset($plan['unit']) ? $plan['unit'] : array();

        // A … D identity, one column per day, then the weekly block
        $c_day   = 5;                       // E
        $c_pm    = $c_day + count($days);   // after the last day
        $c_stand = $c_pm + 1;
        $c_unpl  = $c_stand + 1;
        $c_req   = $c_unpl + 1;
        $c_down  = $c_req + 1;
        $c_avail = $c_down + 1;
        $c_ref   = $c_avail + 1;
        $c_stat  = $show_ref ? $c_ref + 1 : $c_avail + 1;
        $last_col = $c_stat;

        $cells = array();
        $merges = array();
        $put = function ($col, $row, $value, $style, $fmt = null) use (&$cells) {
            $cells[$col . $row] = array('v' => $value, 's' => $style, 'fmt' => $fmt);
        };
        $blank_to = function ($row, $style, $from = 1) use (&$cells, $last_col, $put) {
            for ($c = $from; $c <= $last_col; $c++) {
                $ref = self::col_letter($c) . $row;
                if (!isset($cells[$ref])) $put(self::col_letter($c), $row, '', $style);
            }
        };

        /* ── header ─────────────────────────────────────────────────── */
        $put('A', 1, 'PLANNED PLANT AVAILABILITY', self::S_TITLE);
        $put('A', 2, sprintf(
            '%s  ·  %s to %s  ·  required time = open hours − preventive maintenance  ·  generated %s',
            isset($plan['iso_label']) ? $plan['iso_label'] : '',
            isset($plan['week_start']) ? date('d M Y', strtotime($plan['week_start'])) : '',
            isset($plan['week_end']) ? date('d M Y', strtotime($plan['week_end'])) : '',
            date('d M Y H:i')
        ), self::S_SUB);
        $put('A', 3, sprintf(
            'Fleet %d machines  ·  %d planned out of service  ·  %d available to production  ·  open hours %s/day',
            isset($unit['total']) ? $unit['total'] : 0,
            isset($unit['out_of_service']) ? $unit['out_of_service'] : 0,
            isset($unit['available']) ? $unit['available'] : 0,
            isset($plan['daily_open_hours']) ? $plan['daily_open_hours'] : 24
        ), self::S_SUB);

        $hdr = 5;
        $put('A', $hdr, 'GROUP',    self::S_HDR);
        $put('B', $hdr, 'MACHINE',  self::S_HDR);
        $put('C', $hdr, 'TYPE',     self::S_HDR);
        $put('D', $hdr, 'LOCATION', self::S_HDR);
        foreach ($days as $i => $d) {
            $put(self::col_letter($c_day + $i), $hdr, date('D d/m', strtotime($d)), self::S_HDR);
        }
        $put(self::col_letter($c_pm),    $hdr, 'PM h',           self::S_HDR);
        $put(self::col_letter($c_stand), $hdr, 'STANDING h',     self::S_HDR);
        $put(self::col_letter($c_unpl),  $hdr, 'UNPLANNED h',    self::S_HDR);
        $put(self::col_letter($c_req),   $hdr, 'REQUIRED h',     self::S_HDR);
        $put(self::col_letter($c_down),  $hdr, 'DOWNTIME h',     self::S_HDR);
        $put(self::col_letter($c_avail), $hdr, 'PLANNED AVAILABILITY', self::S_HDR);
        if ($show_ref) $put(self::col_letter($c_ref), $hdr, 'UNWEIGHTED (ref)', self::S_HDR);
        $put(self::col_letter($c_stat),  $hdr, 'STATUS',         self::S_HDR);

        /* ── machine rows, grouped, worst group first ───────────────── */
        $by_group = array();
        foreach ($rows as $r) $by_group[$r['group']][] = $r;
        $group_by_name = array();
        foreach ($groups as $g) $group_by_name[$g['group']] = $g;

        $row = $hdr + 1;
        $group_rows = array();
        foreach ($groups as $g) {
            $name = $g['group'];
            if (empty($by_group[$name])) continue;

            // group banner
            $put('A', $row, strtoupper((string)($name)), self::S_GRP);
            $blank_to($row, self::S_GRP);
            $merges[] = 'A' . $row . ':' . self::col_letter($last_col) . $row;
            $row++;

            foreach ($by_group[$name] as $r) {
                $put('A', $row, $name, self::S_TXT);
                $put('B', $row, $r['registration_no'], self::S_ID);
                $put('C', $row, $r['type'], self::S_TXT);
                $put('D', $row, $r['location'], self::S_TXT);

                foreach ($days as $i => $d) {
                    $cell = isset($r['daily'][$d]) ? $r['daily'][$d] : null;
                    $h = $cell ? round($cell['standing_hours'] + $cell['unplanned_hours'], 2) : null;
                    $put(self::col_letter($c_day + $i), $row, $h, self::S_NUM);
                }

                $put(self::col_letter($c_pm),    $row, $r['pm_hours'],         self::S_HRS);
                $put(self::col_letter($c_stand), $row, $r['standing_hours'],   self::S_HRS);
                $put(self::col_letter($c_unpl),  $row, $r['unplanned_hours'],  self::S_HRS);
                $put(self::col_letter($c_req),   $row, $r['required_hours'],   self::S_HRS);
                $put(self::col_letter($c_down),  $row, $r['downtime_hours'],   self::S_HRS);

                if ($r['out_of_service']) {
                    // defect 5 above / D32 — not scored, and said so
                    $put(self::col_letter($c_avail), $row, 'OUT OF SERVICE', self::S_OUT);
                    if ($show_ref) $put(self::col_letter($c_ref), $row, '', self::S_PCT_REF);
                    $put(self::col_letter($c_stat), $row, 'OUT OF SERVICE', self::S_OUT);
                } else {
                    $put(self::col_letter($c_avail), $row, $r['availability'], self::S_PCT);
                    if ($show_ref) {
                        $put(self::col_letter($c_ref), $row,
                            kpi_availability($r['required_hours'], $r['downtime_hours']), self::S_PCT_REF);
                    }
                    $put(self::col_letter($c_stat), $row, $r['has_plan'] ? 'PLANNED' : 'NO PLAN', self::S_TXT_C);
                }
                $row++;
            }

            // capacity-weighted subtotal (D30) — the model's own figure
            $put('A', $row, strtoupper((string)($name)) . ' — GROUP', self::S_SUBTOT);
            $put('B', $row, $g['machines'] . ' machines', self::S_SUBTOT);
            $blank_to($row, self::S_SUBTOT, 3);
            $put(self::col_letter($c_pm),    $row, $g['pm_hours'],       self::S_SUB_N);
            $put(self::col_letter($c_req),   $row, $g['required_hours'], self::S_SUB_N);
            $put(self::col_letter($c_down),  $row, $g['downtime_hours'], self::S_SUB_N);
            $put(self::col_letter($c_avail), $row, $g['availability'],   self::S_SUB_P);
            if ($show_ref) $put(self::col_letter($c_ref), $row, $g['availability_unweighted'], self::S_PCT_REF);
            $group_rows[$name] = $row;
            $row++;
            $row++;                                     // a space between groups
        }

        /* ── fleet row ──────────────────────────────────────────────── */
        $put('A', $row, 'FLEET', self::S_FLEET);
        $put('B', $row, (isset($unit['total']) ? $unit['total'] : 0) . ' machines', self::S_FLEET);
        $blank_to($row, self::S_FLEET, 3);
        $put(self::col_letter($c_req),   $row, isset($fleet['required_hours']) ? $fleet['required_hours'] : null, self::S_FLEET_N);
        $put(self::col_letter($c_down),  $row, isset($fleet['downtime_hours']) ? $fleet['downtime_hours'] : null, self::S_FLEET_N);
        $put(self::col_letter($c_avail), $row, isset($fleet['availability']) ? $fleet['availability'] : null, self::S_FLEET_P);
        if ($show_ref) {
            $put(self::col_letter($c_ref), $row,
                isset($fleet['availability_unweighted']) ? $fleet['availability_unweighted'] : null, self::S_PCT_REF);
        }
        $put(self::col_letter($c_stat), $row,
            (isset($unit['available']) ? $unit['available'] : 0) . ' AVAIL', self::S_FLEET);
        $fleet_row = $row;

        /* ── the unit view (F7), below the percentages ──────────────── */
        $row += 2;
        $put('A', $row, 'MACHINES AVAILABLE TO PRODUCTION, PER DAY', self::S_TITLE);
        $row++;
        $put('A', $row, 'A headcount, not a percentage — a machine at 60 % is still a machine an operator can be put on.', self::S_SUB);
        $row += 2;

        $u_hdr = $row;
        $put('A', $u_hdr, 'GROUP', self::S_HDR);
        foreach ($days as $i => $d) $put(self::col_letter($c_day + $i), $u_hdr, date('D d/m', strtotime($d)), self::S_HDR);
        $row++;

        if (!empty($plan['units_by_day'])) {
            foreach ($groups as $g) {
                $name = $g['group'];
                if (empty($plan['units_by_day'][$name])) continue;
                $put('A', $row, $name, self::S_TXT);
                foreach ($days as $i => $d) {
                    $c = isset($plan['units_by_day'][$name][$d]) ? $plan['units_by_day'][$name][$d] : null;
                    $put(self::col_letter($c_day + $i), $row,
                        $c ? $c['available'] . ' / ' . $c['total'] : '', self::S_TXT_C);
                }
                $row++;
            }
            if (!empty($plan['units_by_day']['__fleet__'])) {
                $put('A', $row, 'FLEET', self::S_FLEET);
                foreach ($days as $i => $d) {
                    $c = $plan['units_by_day']['__fleet__'][$d];
                    $put(self::col_letter($c_day + $i), $row,
                        $c['available'] . ' / ' . $c['total'], self::S_FLEET);
                }
                $row++;
            }
        }

        $row += 2;
        $put('A', $row, 'Availability = (required time − planned downtime) / required time, where required time = open hours − preventive maintenance. '
            . 'The same kpi_availability() the weekly actual report uses, so plan and actual are comparable. '
            . 'A machine planned out of service for the week leaves the availability population and is counted in the unit view above.', self::S_NOTE);

        return array(
            'sheet'     => 'PLANNED',
            'cells'     => $cells,
            'merges'    => $merges,
            'last_row'  => $row,
            'last_col'  => $last_col,
            'freeze'    => 'E6',
            'widths'    => $this->_widths($last_col, $c_day, count($days)),
            'fleet_row' => $fleet_row,
            'group_rows'=> $group_rows,
        );
    }

    /* ════════════════════════════════════════════════════════════════════
     |  SHEET 2 — PLAN vs ACTUAL
     * ════════════════════════════════════════════════════════════════════ */

    /**
     * @param  array $pva Forecast_model::plan_vs_actual() output
     * @return array
     */
    public function build_plan_vs_actual(array $pva) {
        $cells = array();
        $merges = array();
        $last_col = 9;
        $put = function ($col, $row, $value, $style) use (&$cells) {
            $cells[$col . $row] = array('v' => $value, 's' => $style, 'fmt' => null);
        };

        $put('A', 1, 'PLAN vs ACTUAL', self::S_TITLE);
        $put('A', 2, sprintf(
            '%s  ·  measured against the frozen baseline, revision %s, published %s',
            isset($pva['iso_label']) ? $pva['iso_label'] : '',
            isset($pva['revision']) ? $pva['revision'] : '—',
            !empty($pva['published_at']) ? date('d M Y H:i', strtotime($pva['published_at'])) : 'unknown'
        ), self::S_SUB);
        $put('A', 3, 'Variance is in percentage points. A negative figure is a miss. '
            . 'PM variance and unplanned variance separate a planning error from a reliability one.', self::S_SUB);

        $hdr = 5;
        $heads = array('GROUP','MACHINES','PLANNED','ACTUAL','VARIANCE (pp)',
                       'PM h PLAN','PM h ACTUAL','DOWNTIME h PLAN','DOWNTIME h ACTUAL');
        foreach ($heads as $i => $h) $put(self::col_letter($i + 1), $hdr, $h, self::S_HDR);

        $row = $hdr + 1;
        $groups = isset($pva['groups']) ? $pva['groups'] : array();
        foreach ($groups as $g) {
            $put('A', $row, $g['group'], self::S_TXT);
            $put('B', $row, $g['machines'], self::S_TXT_C);
            $put('C', $row, $g['planned_availability'], self::S_PCT);
            $put('D', $row, $g['actual_availability'],  self::S_PCT);
            $put('E', $row, $g['variance_pp'],
                ($g['variance_pp'] !== null && $g['variance_pp'] < 0) ? self::S_BAD : self::S_GOOD);
            $put('F', $row, $g['planned_pm_hours'], self::S_HRS);
            $put('G', $row, $g['actual_pm_hours'],  self::S_HRS);
            $put('H', $row, $g['planned_downtime_hours'], self::S_HRS);
            $put('I', $row, $g['actual_downtime_hours'],  self::S_HRS);
            $row++;
        }

        $f = isset($pva['fleet']) ? $pva['fleet'] : array();
        $put('A', $row, 'FLEET', self::S_FLEET);
        $put('B', $row, '', self::S_FLEET);
        $put('C', $row, isset($f['planned']) ? $f['planned'] : null, self::S_FLEET_P);
        $put('D', $row, isset($f['actual']) ? $f['actual'] : null, self::S_FLEET_P);
        $put('E', $row, isset($f['variance_pp']) ? $f['variance_pp'] : null, self::S_FLEET_N);
        for ($c = 6; $c <= $last_col; $c++) $put(self::col_letter($c), $row, '', self::S_FLEET);

        return array(
            'sheet'    => 'PLAN vs ACTUAL',
            'cells'    => $cells,
            'merges'   => $merges,
            'last_row' => $row,
            'last_col' => $last_col,
            'freeze'   => 'A6',
            'widths'   => array('A' => 24, 'B' => 11, 'C' => 13, 'D' => 13, 'E' => 15,
                                'F' => 12, 'G' => 13, 'H' => 15, 'I' => 16),
        );
    }

    /* ════════════════════════════════════════════════════════════════════
     |  SHEET 3 — ROLLING THREE-WEEK PLAN
     * ════════════════════════════════════════════════════════════════════ */

    /**
     * @param  array $weeks    each ['offset','week_start','iso_label','plan']
     * @param  array $machines each ['registration_no','type','location','weeks'=>[offset=>row]]
     * @return array
     */
    public function build_rolling(array $weeks, array $machines) {
        $cells = array();
        $put = function ($col, $row, $value, $style) use (&$cells) {
            $cells[$col . $row] = array('v' => $value, 's' => $style, 'fmt' => null);
        };

        $put('A', 1, 'ROLLING THREE-WEEK MAINTENANCE PLAN', self::S_TITLE);
        $labels = array();
        foreach ($weeks as $w) $labels[] = $w['iso_label'];
        $put('A', 2, implode('  ·  ', $labels) . '  ·  every cell read from the database — no formula references to go stale', self::S_SUB);

        $hdr = 4;
        $put('A', $hdr, 'MACHINE', self::S_HDR);
        $put('B', $hdr, 'TYPE',    self::S_HDR);
        $put('C', $hdr, 'LOCATION',self::S_HDR);
        $col = 4;
        foreach ($weeks as $w) {
            $put(self::col_letter($col),     $hdr, $w['iso_label'] . ' DT h', self::S_HDR);
            $put(self::col_letter($col + 1), $hdr, $w['iso_label'] . ' AVAIL', self::S_HDR);
            $col += 2;
        }
        $last_col = $col - 1;

        $row = $hdr + 1;
        foreach ($machines as $m) {
            $put('A', $row, $m['registration_no'], self::S_ID);
            $put('B', $row, $m['type'], self::S_TXT);
            $put('C', $row, $m['location'], self::S_TXT);
            $col = 4;
            foreach ($weeks as $w) {
                $r = isset($m['weeks'][$w['offset']]) ? $m['weeks'][$w['offset']] : null;
                if (!$r) {
                    $put(self::col_letter($col),     $row, '', self::S_TXT_C);
                    $put(self::col_letter($col + 1), $row, '', self::S_TXT_C);
                } elseif ($r['out_of_service']) {
                    $put(self::col_letter($col),     $row, 'OUT', self::S_OUT);
                    $put(self::col_letter($col + 1), $row, 'OUT OF SERVICE', self::S_OUT);
                } else {
                    $put(self::col_letter($col),     $row,
                        round($r['standing_hours'] + $r['unplanned_hours'], 2), self::S_NUM);
                    $put(self::col_letter($col + 1), $row, $r['availability'], self::S_PCT);
                }
                $col += 2;
            }
            $row++;
        }

        // the three fleet headlines under the grid
        $row++;
        $put('A', $row, 'FLEET AVAILABILITY BY WEEK', self::S_TITLE);
        $row++;
        $col = 1;
        foreach ($weeks as $w) {
            $f = $w['plan']['fleet'];
            $put(self::col_letter($col), $row, $w['iso_label'], self::S_TXT_C);
            $put(self::col_letter($col + 1), $row, $f['availability'], self::S_PCT);
            $col += 2;
        }

        $w = array('A' => 14, 'B' => 18, 'C' => 16);
        for ($c = 4; $c <= $last_col; $c++) $w[self::col_letter($c)] = 13;

        return array(
            'sheet'    => 'ROLLING 3-WEEK',
            'cells'    => $cells,
            'merges'   => array(),
            'last_row' => $row,
            'last_col' => $last_col,
            'freeze'   => 'D5',
            'widths'   => $w,
        );
    }

    /* ════════════════════════════════════════════════════════════════════
     |  painting and saving
     * ════════════════════════════════════════════════════════════════════ */

    /** Paint one built sheet onto a writer. */
    public function paint($xlsx, array $sheet) {
        $xlsx->addSheet($sheet['sheet']);
        if (!empty($sheet['freeze'])) $xlsx->freezePane($sheet['freeze']);
        foreach ($sheet['widths'] as $col => $width) {
            $xlsx->setColWidth(self::col_index($col), $width);
        }

        for ($row = 1; $row <= $sheet['last_row']; $row++) {
            $line = array();
            for ($c = 1; $c <= $sheet['last_col']; $c++) {
                $ref  = self::col_letter($c) . $row;
                $cell = isset($sheet['cells'][$ref]) ? $sheet['cells'][$ref] : null;
                if (!$cell) { $line[] = array('v' => null, 's' => ''); continue; }
                $line[] = array('v' => $cell['v'], 's' => $cell['s']);
            }
            $xlsx->writeRowStyled($line);
        }

        foreach ($sheet['merges'] as $range) {
            $parts = explode(':', $range);
            $xlsx->mergeCells($parts[0], $parts[1]);
        }
        return $xlsx;
    }

    /**
     * Write the workbook.
     *
     * @param  array $sheets one or more built sheets, in order
     * @param  string $path
     * @return string|false  what the writer returned
     */
    public function save(array $sheets, $path) {
        $xlsx = $this->writer();
        foreach ($sheets as $sheet) $this->paint($xlsx, $sheet);
        return $xlsx->save($path);
    }

    /** The download name, from the week it covers. */
    public function filename($iso_label, $kind = 'forecast') {
        return $kind . '_' . str_replace('-', '', (string)$iso_label) . '.xlsx';
    }

    /** Column widths: identity columns wide, day columns narrow. */
    private function _widths($last_col, $first_day, $n_days) {
        $w = array('A' => 22, 'B' => 14, 'C' => 18, 'D' => 16);
        for ($i = 0; $i < $n_days; $i++) $w[self::col_letter($first_day + $i)] = 10;
        for ($c = $first_day + $n_days; $c <= $last_col; $c++) {
            $w[self::col_letter($c)] = ($c === $last_col) ? 16 : 13;
        }
        return $w;
    }

    /* ── column helpers ─────────────────────────────────────────────── */

    /** 'C' => 3 (1-based). */
    public static function col_index($letters) {
        $letters = strtoupper((string)$letters);
        $n = 0;
        for ($i = 0, $len = strlen((string)($letters)); $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n;
    }

    /** 3 => 'C' (1-based). */
    public static function col_letter($n) {
        $s = '';
        $n = (int)$n;
        while ($n > 0) {
            $m = ($n - 1) % 26;                 // 0..25
            $s = chr(65 + $m) . $s;
            $n = (int)(($n - $m - 1) / 26);     // bijective base-26: no zero digit
        }
        return $s;
    }

    /** A Y-m-d date as an Excel serial. */
    public static function excel_date($date) {
        $t = strtotime($date);
        if ($t === false) return null;
        return (int)floor(($t - strtotime(self::EXCEL_EPOCH)) / 86400);
    }

    /** A fresh writer (the xlsx helper defines XlsxWriter). */
    private function writer() {
        if ($this->writer_factory) {
            $w = call_user_func($this->writer_factory);
            if ($w) return $w;
        }
        if (!class_exists('XlsxWriter')) {
            $file = APPPATH . 'helpers/xlsx_helper.php';
            if (file_exists($file)) require_once $file;
        }
        return new XlsxWriter();
    }
}
