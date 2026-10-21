<?php
/**
 * W36 Excel writer — plan tasks W3.1–W3.5.
 *
 * Turns the one array `W36_report_model::build()` produces into the approved
 * workbook: sheet `W36`, rows 1–4, one group block per group in the approved
 * order, the machine rows with their seven day blocks (I:O … AY:BE), the weekly
 * block (BG:BP — `BG` is NUMBER OF DAYS DOWN, added by OPEN-5, D28) and the
 * per-type block (BQ:CB, unchanged).
 *
 * Rule zero of this project: the workbook is a *reader of the database*. No
 * value here is typed, copied from the legacy `weekly report W36.xlsx` workbook or
 * defaulted — every cell comes from the report array (decisions D8/D-W2, BR-W1).
 * The labels and the geometry below are the static part of the contract:
 * they fix the approved sheet structure in one place so the writer and the
 * report can never drift apart.
 *
 * Deliberate differences from the sample workbook:
 *   D-W3    the fleet row covers every reportable machine — the sample's formulas
 *           stopped at row 100 while its data ran to 108
 *   D-W4/11 the roll-ups are capacity-weighted, never an average of percentages
 *   D-W12   conditional formatting is *baked* at generation time (BR-W22) —
 *           the sample's 20 rules all compared a whole column against one row's
 *           target and one of them was invalid OOXML
 *   D-W13   one approved label list for the grid and the per-type block
 *   —       the stray helper cell O1 (=AVERAGE(O5:O10)) and the BV2:CA1 helper
 *           block are dropped: they exist only to feed the `W36 Synthese` sheet's
 *           charts, which Phase A does not generate (CA1 was also wrong, D-W11)
 *   —       groups with no machine are not printed as empty blocks, and machines
 *           with no group are printed under an explicit UNCLASSIFIED block
 *           instead of vanishing from the roll-up
 */
defined('BASEPATH') OR exit('No direct script access allowed');

#[AllowDynamicProperties]
class W36_excel {

    /** Excel serial date 0 — 1899-12-30, the epoch every reader expects. */
    const EXCEL_EPOCH = '1899-12-30';

    /**
     * The approved logo box (W4.1 / decision D7). The sample workbook anchors
     * its logo to D1 with this exact extent; the supplied `uploads/logoam.png`
     * is 250×100, so it is *fitted* inside the box instead of stretched — a
     * logo that keeps its aspect ratio is still inside the approved footprint.
     *   1050925 × 464343 EMU ≈ 11.5 × 5.1 cm
     */
    const LOGO_BOX_CX = 1050925;
    const LOGO_BOX_CY = 464343;
    const LOGO_ANCHOR = 'D1';
    const LOGO_XOFF   = 48766;

    /** The workbook parts the sheet contract fixes. */
    private $spec;

    /** The parts of sheet `W36 Synthese` (Phase B, W5.4). */
    private $spec2;

    /** Optional callable returning a fresh XlsxWriter (tests inject a spy). */
    private $writer_factory;

    /** Optional logo override: a path, or false for a workbook without a logo. */
    private $logo_file = null;

    public function __construct($params = array()) {
        if (isset($params['writer']) && is_callable($params['writer'])) $this->writer_factory = $params['writer'];
        if (isset($params['logo'])) $this->logo_file = $params['logo'];
    }

    /* ────────────────────────────────────────────────────────────────────
     |  the contract (labels + geometry only — no values)
     * ──────────────────────────────────────────────────────────────────── */

    public function spec() {
        if ($this->spec !== null) return $this->spec;

        $this->spec = array(
            'sheet'      => 'W36',
            'freeze'     => 'I50',
            'first_row'  => 1,
            'last_header_row' => 4,

            // row 3, column => label, verbatim (misspellings included, BR-W2)
            'headers' => array(
                'B' => 'TYPE', 'C' => 'EQUIPMENT', 'D' => 'AVAILABLE', 'E' => 'DOWN',
                'F' => 'MACHINE ID', 'G' => 'MODEL', 'H' => 'LOCATION',
            ),
            'day_block_headers' => array(
                'PREVENTIVE TIME', 'TOTAL DOWN TIME', 'NUMBER OF FAILLURE', 'MTBF', 'MTTR',
                'AVAIBILITY', "DAILY TARGET\n KPI",
            ),
            'weekly_block_headers' => array(
                // OPEN-5/D28: the D4 days-down count is the block's first column; the
                // rest of the block keeps the sample's order, one column to the right.
                // The label is the model's own KPI_LABELS['days_down'].
                'NUMBER OF DAYS DOWN',
                'WEEKLY OPEN TIME', 'PREVENTIVE TIME', 'REQUIRED TIME', 'TOTAL DOWN TIME',
                'NUMBER OF FAILLURE', 'MTBF', 'MTTR', 'AVAIBILITY OPERATIONAL', "DAILY TARGET\n KPI",
            ),
            'per_type_headers' => array(
                "Fleet\nAvailible\nNb", "Fleet\nBreakdown\nNb", 'APPLICATION', "WEEKLY OPEN TIME\nh",
                "PREVENTIVE TIME\nh", "REQUIRED TIME\nh", "TOTAL DOWN TIME\nh", "NUMBER OF FAILLURE\nNb",
                "MTBF\nh", "MTTR\nh", "AVAIBILITY OPERATIONAL\n%", "WEEKLY TARGET\n KPI \n%",
            ),

            // the seven day blocks, first column of each
            'day_block_start' => array('I', 'P', 'W', 'AD', 'AK', 'AR', 'AY'),
            'day_columns'     => 7,
            'weekly_start'    => 'BG',
            'per_type_start'  => 'BQ',
            'per_type_header_row' => 3,

            // the table object the sample puts on the per-type block (W4.2):
            // `Tableau2` on BQ3:CB20, filterable, banded rows, no totals row
            'table' => array(
                'name'    => 'Tableau2',
                'style'   => 'TableStyleLight1',
                'stripes' => true,
            ),

            // the labels of rows 1–2
            'title_weekly_open' => 'WEEKLY OPEN TIME',
            'title_daily_open'  => 'DAILY OPEN TIME',
            'title_report'      => 'WORKSHOP DAILY REPORT',

            // column widths, from the sample (A and the two spacers included)
            'widths' => array(
                'A' => 1.54, 'B' => 14.36, 'D' => 9.73, 'E' => 6.91, 'F' => 13.63,
                'G' => 18.27, 'H' => 19.91, 'I' => 11.36, 'J' => 8.09, 'K' => 10.0,
                'L' => 7.45, 'M' => 7.18, 'N' => 9.27, 'O' => 9.82, 'P' => 11.09,
                'Q' => 8.09, 'R' => 10.0, 'S' => 7.45, 'T' => 7.18, 'U' => 9.27,
                'V' => 9.82, 'W' => 11.18, 'X' => 8.09, 'Y' => 10.0, 'Z' => 7.45,
                'AA' => 7.18, 'AB' => 12.27, 'AC' => 9.82, 'AD' => 11.27, 'AE' => 8.09,
                'AF' => 10.0, 'AG' => 7.45, 'AH' => 7.18, 'AI' => 9.27, 'AJ' => 9.82,
                'AK' => 10.82, 'AL' => 8.09, 'AM' => 10.0, 'AN' => 7.45, 'AO' => 7.18,
                'AP' => 9.27, 'AQ' => 9.82, 'AR' => 11.18, 'AS' => 8.09, 'AT' => 10.0,
                'AU' => 7.45, 'AV' => 7.18, 'AW' => 9.27, 'AX' => 9.82, 'AY' => 11.18,
                'AZ' => 8.09, 'BA' => 10.0, 'BB' => 7.45, 'BC' => 7.18, 'BD' => 9.27,
                // OPEN-5/D28: BG is the new column and takes the block's own first
                // width (10.91); the sample's nine weekly widths travel with their
                // content to BH:BO, and BP — the sample's narrow 6.18 spacer — now
                // carries DAILY TARGET KPI, which was 10.27 wide at BO.
                'BE' => 9.82, 'BF' => 5.09, 'BG' => 10.91, 'BH' => 10.91, 'BI' => 10.91,
                'BJ' => 10.45, 'BK' => 9.91, 'BL' => 8.82, 'BM' => 8.91, 'BN' => 7.18,
                'BO' => 12.73, 'BP' => 10.27, 'BQ' => 11.73, 'BR' => 12.63, 'BS' => 18.45,
                'BT' => 10.63, 'BU' => 10.0, 'BV' => 10.0, 'BW' => 10.0, 'BX' => 10.0,
                'BY' => 10.0, 'BZ' => 10.0, 'CA' => 14.82, 'CB' => 13.18,
            ),

            // number formats per column, from the sample ("General" = none)
            'formats' => array(
                'B' => 'General', 'C' => 'General', 'D' => 'General', 'E' => 'General',
                'F' => 'General', 'G' => 'General', 'H' => 'General',
                'I' => '0.0', 'J' => '0.0', 'K' => 'General', 'L' => '0.0', 'M' => '0.0',
                'N' => '0%', 'O' => '0.0%',
                'P' => '0.0', 'Q' => '0.0', 'R' => 'General', 'S' => '0.0', 'T' => '0.0',
                'U' => '0%', 'V' => '0.0%',
                'W' => '0.0', 'X' => '0.0', 'Y' => 'General', 'Z' => '0.0', 'AA' => '0.0',
                'AB' => '0%', 'AC' => '0.0%',
                'AD' => '0.0', 'AE' => '0.0', 'AF' => 'General', 'AG' => '0.0', 'AH' => '0.0',
                'AI' => '0%', 'AJ' => '0.0%',
                'AK' => '0.0', 'AL' => '0.0', 'AM' => 'General', 'AN' => '0.0', 'AO' => '0.0',
                'AP' => '0%', 'AQ' => '0.0%',
                'AR' => '0.0', 'AS' => '0.0', 'AT' => 'General', 'AU' => '0.0', 'AV' => '0.0',
                'AW' => '0%', 'AX' => '0.0%',
                'AY' => '0.0', 'AZ' => '0.0', 'BA' => 'General', 'BB' => '0.0', 'BC' => '0.0',
                'BD' => '0%', 'BE' => '0.0%',
                'BG' => '0', 'BH' => 'General', 'BI' => '0.0', 'BJ' => '0.0', 'BK' => '0.0',
                'BL' => '0', 'BM' => '0.0', 'BN' => '0.0', 'BO' => '0%', 'BP' => '0.0%',
                'BQ' => 'General', 'BR' => 'General', 'BS' => 'General', 'BT' => '0', 'BU' => '0',
                'BV' => '0', 'BW' => '0', 'BX' => '0', 'BY' => '0', 'BZ' => '0',
                'CA' => '0.0%', 'CB' => '0.0%',
            ),

            'row_heights'   => array(1 => 24, 2 => 15, 3 => 43, 4 => 15),
            'data_row_height' => 14.5,
            'date_format'   => 'mm-dd-yy',

            // the day-block metric order, and where each one sits in a block
            'day_metrics' => array(
                'preventive_hours' => 0, 'downtime_hours' => 1, 'failures' => 2,
                'mtbf_hours' => 3, 'mttr_hours' => 4, 'availability' => 5, 'target' => 6,
            ),
            'weekly_metrics' => array(
                'days_down' => 0, 'open' => 1, 'preventive_hours' => 2,
                'required_time_hours' => 3, 'downtime_hours' => 4, 'breakdowns' => 5,
                'mtbf_hours' => 6, 'mttr_hours' => 7, 'availability' => 8,
                'weekly_target' => 9,
            ),

            // the columns whose failure count is highlighted when > 0 (BR-W22)
            'failure_cols' => array(2),   // offset inside a day block
            'weekly_failure_col' => 'BL',   // NUMBER OF FAILLURE, one column right of the sample's BK
        );

        return $this->spec;
    }

    /* ────────────────────────────────────────────────────────────────────
     |  the cell map — everything the sheet says, before it is drawn
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * @param  array $report the array from W36_report_model::build()
     * @return array sheet, freeze, cells (ref => value|role|fmt), merges, …
     */
    public function build(array $report) {
        $spec = $this->spec();

        $week  = isset($report['week']) ? $report['week'] : array();
        $days  = isset($week['days']) ? $week['days'] : array();
        $rows  = isset($report['machines']) ? $report['machines'] : array();
        $order = isset($report['machine_order']) ? $report['machine_order'] : array_keys($rows);
        $groups = isset($report['groups']) ? $report['groups'] : array();
        $per_type = isset($report['per_type']) ? $report['per_type'] : array();
        $fleet = isset($report['fleet']) ? $report['fleet'] : array();
        $report_name = $this->report_name($report);

        $cells  = array();
        $merges = array();
        $put = function ($col, $row, $value, $role = 'data') use (&$cells, $spec) {
            $fmt = isset($spec['formats'][$col]) ? $spec['formats'][$col] : 'General';
            $cells[$col . $row] = array('v' => $value, 'role' => $role, 'fmt' => $fmt);
        };

        /* ── rows 1–2: titles, the open-time constants, the period ─────── */

        $put('B', 1, $spec['title_weekly_open'], 'open_title');
        $put('C', 1, $spec['title_daily_open'],  'open_title_2');
        $put('B', 2, isset($week['open_hours']) ? $week['open_hours'] : null, 'open_title');
        $put('C', 2, isset($week['daily_open_hours']) ? $week['daily_open_hours'] : null, 'open_title_2');
        $put('F', 1, $spec['title_report'], 'report_title');

        foreach ($days as $i => $day) {
            if (!isset($spec['day_block_start'][$i])) break;
            $col = $spec['day_block_start'][$i];
            $cells[$col . 2] = array('v' => $this->excel_date($day), 'role' => 'day_head',
                                     'fmt' => $spec['date_format']);
        }
        $cells[$spec['weekly_start'] . 2] = array(
            'v' => $this->period_label($week, $report_name), 'role' => 'day_head', 'fmt' => 'General',
        );

        /* ── row 3: the headers, at their exact columns ────────────────── */

        foreach ($spec['headers'] as $col => $label) $put($col, 3, $label, 'head');
        foreach ($spec['day_block_start'] as $i => $start) {
            foreach ($spec['day_block_headers'] as $k => $label) {
                $put($this->shift($start, $k), 3, $label, 'head');
            }
        }
        foreach ($spec['weekly_block_headers'] as $k => $label) {
            $put($this->shift($spec['weekly_start'], $k), 3, $label, 'head');
        }
        foreach ($spec['per_type_headers'] as $k => $label) {
            $put($this->shift($spec['per_type_start'], $k), 3, $label, 'head_block');
        }

        /* ── row 4: the fleet roll-up (D-W3: the whole population) ─────── */

        $this->put_daily($put, $this->shift($spec['day_block_start'][0], 0), 4,
                         isset($fleet['daily']) ? $fleet['daily'] : array(), 'fleet');
        $this->put_weekly($put, $spec['weekly_start'], 4,
                          isset($fleet['rollup']) ? $fleet['rollup'] : array(),
                          isset($week['open_hours']) ? $week['open_hours'] : null, 'fleet');

        /* ── rows 5…: the group blocks and the machine rows ─────────────── */

        $row = 5;
        $grid_rows = array();      // machine id => row number (for tests + parity)
        $last_grid_row = 4;

        foreach ($groups as $g) {
            $members = array();
            foreach ($g['machines'] as $id) if (isset($rows[$id])) $members[] = $id;
            if (empty($members)) continue;             // no empty blocks

            $first = $row;
            foreach ($members as $id) {
                $m = $rows[$id];
                $grid_rows[$id] = $row;

                // identity columns (red bold for a machine that was down, BR-W22)
                $was_down = ((float)$m['weekly']['hours_down']) > 0;
                $id_role  = $was_down ? 'down' : 'identity';
                $put('F', $row, $m['machine_id'], $id_role);
                $put('G', $row, $m['model'], $id_role);
                $put('H', $row, isset($m['site']['label']) ? $m['site']['label'] : '', $id_role);

                $this->put_daily($put, $this->shift($spec['day_block_start'][0], 0), $row,
                                 $m['daily'], 'data', $m['weekly']);
                $this->put_weekly($put, $spec['weekly_start'], $row, $m['weekly'],
                                  isset($week['open_hours']) ? $week['open_hours'] : null);
                $row++;
            }
            $last = $row - 1;

            // the group's own counters live in the first row of its block
            $pt = isset($per_type[$g['code']]) ? $per_type[$g['code']] : array();
            $put('B', $first, $g['label'], 'group');
            $put('C', $first, isset($g['row_count']) ? $g['row_count'] : count($members), 'group');
            $put('D', $first, isset($pt['available']) ? $pt['available'] : 0, 'group');
            $put('E', $first, isset($pt['down']) ? $pt['down'] : 0,
                 (isset($pt['down']) && $pt['down'] > 0) ? 'down' : 'group');

            if ($last > $first) foreach (array('B', 'C', 'D', 'E') as $col) {
                $merges[] = $col . $first . ':' . $col . $last;
            }
        }
        $last_grid_row = $row - 1;

        // machines with no group would otherwise be counted in the fleet row and
        // printed nowhere — the sample's rows 101–108 defect. They get a block.
        $unclassified = array();
        foreach ($order as $id) if (isset($rows[$id]) && empty($rows[$id]['group_code'])) $unclassified[] = $id;
        if (!empty($unclassified)) {
            $first = $row;
            foreach ($unclassified as $id) {
                $m = $rows[$id];
                $grid_rows[$id] = $row;
                $was_down = ((float)$m['weekly']['hours_down']) > 0;
                $id_role  = $was_down ? 'down' : 'identity';
                $put('F', $row, $m['machine_id'], $id_role);
                $put('G', $row, $m['model'], $id_role);
                $put('H', $row, isset($m['site']['label']) ? $m['site']['label'] : '', $id_role);
                $this->put_daily($put, $this->shift($spec['day_block_start'][0], 0), $row,
                                 $m['daily'], 'data', $m['weekly']);
                $this->put_weekly($put, $spec['weekly_start'], $row, $m['weekly'],
                                  isset($week['open_hours']) ? $week['open_hours'] : null);
                $row++;
            }
            $last = $row - 1;
            $available = 0; $down = 0;
            foreach ($unclassified as $id) {
                if (((float)$rows[$id]['weekly']['hours_down']) > 0) $down++; else $available++;
            }
            $put('B', $first, 'UNCLASSIFIED', 'group');
            $put('C', $first, count($unclassified), 'group');
            $put('D', $first, $available, 'group');
            $put('E', $first, $down, $down > 0 ? 'down' : 'group');
            if ($last > $first) foreach (array('B', 'C', 'D', 'E') as $col) {
                $merges[] = $col . $first . ':' . $col . $last;
            }
            $last_grid_row = $last;
        }

        /* ── row 2 merges: the day headers and the period label ─────────── */

        foreach ($spec['day_block_start'] as $i => $start) {
            $merges[] = $start . '2:' . $this->shift($start, $spec['day_columns'] - 1) . '2';
        }
        $merges[] = $spec['weekly_start'] . '2:' . $this->shift($spec['weekly_start'], count($spec['weekly_block_headers']) - 1) . '2';

        /* ── the per-type block, BQ4:CB20, one row per group ───────────── */

        $per_type_rows = array();
        $r = $spec['last_header_row'];
        foreach ($groups as $g) {
            $pt = isset($per_type[$g['code']]) ? $per_type[$g['code']] : array();
            $roll = isset($pt['rollup']) ? $pt['rollup'] : array();
            $has   = !empty($pt['row_count']);

            $values = array(
                isset($pt['available']) ? $pt['available'] : null,
                isset($pt['down']) ? $pt['down'] : null,
                $g['label'],
                isset($week['open_hours']) ? $week['open_hours'] : null,
                $has ? $this->num($roll, 'preventive_hours') : null,
                $has ? $this->num($roll, 'required_time_hours') : null,
                $has ? $this->num($roll, 'downtime_hours') : null,
                $has ? $this->num($roll, 'breakdowns') : null,
                $has ? $this->num($roll, 'mtbf_hours') : null,
                $has ? $this->num($roll, 'mttr_hours') : null,
                $has ? $this->num($roll, 'availability') : null,
                $has ? $this->num($roll, 'weekly_target') : null,
            );
            foreach ($values as $k => $v) {
                $col = $this->shift($spec['per_type_start'], $k);
                $put($col, $r, $v, $col === 'BS' ? 'block_label' : 'block_data');
            }
            $per_type_rows[$g['code']] = $r;
            $r++;
        }

        /* ── BR-W23: every availability figure carries its own verdict ─────
         * The sample's 20 conditional-formatting rules all compared a whole
         * column to one row's target and one was invalid OOXML (D-W12), so the
         * colour is baked here instead: green at or above that row's own
         * target, red below it — the same rule the preview screen shows.
         */
        $verdict = function ($availability, $target) {
            if ($availability === null || $target === null) return null;
            return ((float)$availability >= (float)$target) ? 'avail_ok' : 'avail_low';
        };
        foreach ($grid_rows as $id => $r) {
            $w = $rows[$id]['weekly'];
            $role = $verdict(
                array_key_exists('availability', $w) ? $w['availability'] : null,
                array_key_exists('weekly_target', $w) ? $w['weekly_target'] : null
            );
            if ($role !== null) {
                $cells[$this->shift($spec['weekly_start'], $spec['weekly_metrics']['availability']) . $r]['role'] = $role;
            }
        }
        foreach ($groups as $g) {
            if (!isset($per_type_rows[$g['code']])) continue;
            $pt   = isset($per_type[$g['code']]) ? $per_type[$g['code']] : array();
            $roll = isset($pt['rollup']) ? $pt['rollup'] : array();
            $role = $verdict(
                array_key_exists('availability', $roll) ? $roll['availability'] : null,
                array_key_exists('weekly_target', $roll) ? $roll['weekly_target'] : null
            );
            if ($role !== null) $cells['CA' . $per_type_rows[$g['code']]]['role'] = $role;
        }

        $last_cell_row = 0;
        foreach ($cells as $ref => $cell) {
            $n = (int)preg_replace('/^[A-Z]+/', '', (string)$ref);
            if ($n > $last_cell_row) $last_cell_row = $n;
        }

        return array(
            // The contract keeps the sample geometry, but the actual worksheet
            // name follows the selected week's dashboard-controlled name.
            'sheet'      => $report_name,
            'freeze'     => $spec['freeze'],
            'cells'      => $cells,
            'merges'     => $merges,
            'widths'     => $spec['widths'],
            'row_heights'=> $spec['row_heights'],
            'data_row_height' => $spec['data_row_height'],
            'validation' => array(
                'sqref' => 'H5:H' . max(5, $last_grid_row),
                'list'  => isset($report['sites']) ? $report['sites'] : array(),
            ),
            // the last row the sheet actually carries: the deepest cell written —
            // the grid rows and the per-type block (BQ:CB, one row per group) both
            // end inside it.  Measuring a recycled loop variable instead clipped the
            // block whenever a sheet had fewer than ten machine rows.
            'last_row'   => max($last_cell_row, $last_grid_row, 4),
            'grid_rows'  => $grid_rows,
            'per_type_rows' => $per_type_rows,
            'last_grid_row' => $last_grid_row,
        );
    }

    /* ────────────────────────────────────────────────────────────────────
     |  drawing the sheet
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * @param  array  $report the array from W36_report_model::build()
     * @param  string $path   where to write the .xlsx
     * @return string the path written
     */
    public function save(array $report, $path) {
        $xlsx = $this->writer();

        // sheet 1 — `W36` (W3.1–W3.5, W4.1, W4.2)
        $sheet = $this->build($report);
        $this->paint($xlsx, $sheet);
        $this->paint_logo($xlsx);
        $this->paint_table($xlsx, $sheet);

        // sheet 2 — `W36 Synthese` (Phase B, W5.4): in the same workbook, after it
        $this->paint_synthese($xlsx, $this->build_synthese($report));

        return $xlsx->save($path);
    }

    /* ────────────────────────────────────────────────────────────────────
     |  W4.1 — the logo and the page setup (decision D7)
     |
     |  The approved workbook carries the company logo top-left (one-cell
     |  anchor D1) and prints A4 portrait with 0.7" / 0.75" margins. The logo
     |  file is the tracked upload the login screen and job cards already use,
     |  so the workbook and the WMS cannot show different marks. When the file
     |  is missing the report still builds — a report without a logo is better
     |  than no report — and the test suite is what keeps the logo required.
     * ──────────────────────────────────────────────────────────────────── */

    /** The logo the workbook prints, or null when the host has none. */
    public function logo_path() {
        if ($this->logo_file === false) return null;                 // explicitly no logo
        if (is_string($this->logo_file)) return is_file($this->logo_file) ? $this->logo_file : null;

        $candidates = array();
        if (defined('FCPATH')) $candidates[] = FCPATH . 'uploads/logoam.png';
        if (defined('APPPATH')) $candidates[] = APPPATH . '../uploads/logoam.png';
        $candidates[] = dirname(dirname(dirname(__FILE__))) . '/uploads/logoam.png';   // application/libraries → repo root
        foreach ($candidates as $file) {
            if ($file !== '' && is_file($file)) return $file;
        }
        return null;
    }

    /**
     * Paint the table object on the per-type block (W4.2).
     *
     * The approved workbook carries `Tableau2` on `BQ3:CB20` — the per-type
     * block as a real Excel table, with its auto-filter. The range is derived
     * from the rows the writer actually painted, so it grows and shrinks with
     * the group list instead of being a copied constant, and the column names
     * are the same labels the header row carries (the table's header *is* that
     * row; if the two ever disagreed Excel would rewrite one of them).
     */
    public function paint_table($xlsx, array $sheet) {
        $spec = $this->spec();
        $rows = isset($sheet['per_type_rows']) ? $sheet['per_type_rows'] : array();
        if (empty($rows)) return $xlsx;

        $first    = min(array_values($rows));
        $last     = max(array_values($rows));
        $last_col = $this->shift($spec['per_type_start'], count($spec['per_type_headers']) - 1);

        $xlsx->addTable(array(
            'range'   => $spec['per_type_start'] . $spec['per_type_header_row'] . ':' . $last_col . $last,
            'name'    => $spec['table']['name'],
            'columns' => $spec['per_type_headers'],
            'style'   => $spec['table']['style'],
            'stripes' => $spec['table']['stripes'],
        ));
        return $xlsx;
    }

    /**
     * Paint the logo and the print setup onto the current sheet.
     *
     * Anchored D1 like the sample, scaled to fit the sample's box without
     * distorting it (the supplied mark is 2.5:1, the box 2.26:1).
     */
    public function paint_logo($xlsx, $page_setup = true) {
        $file = $this->logo_path();
        if ($file !== null) {
            $xlsx->addImage($file, array(
                'anchor' => self::LOGO_ANCHOR,
                'x'      => self::LOGO_XOFF,
                'fit'    => array(self::LOGO_BOX_CX, self::LOGO_BOX_CY),
                'name'   => 'Logo',
            ));
        }
        // the image and the print setup travel together on sheet `W36` (W4.2),
        // but `W36 Synthese` prints landscape, so it asks for the image alone
        if (!$page_setup) return $xlsx;

        $xlsx->setPageSetup(array(
            'orientation' => 'portrait',
            'paperSize'   => 9,          // A4, the sample's paperSize="9"
            'left' => 0.7, 'right' => 0.7, 'top' => 0.75, 'bottom' => 0.75,
            'header' => 0.3, 'footer' => 0.3,
        ));
        return $xlsx;
    }

    /**
     * Which role an availability cell wears: the sample's own green (006100)
     * when the figure reaches the machine's / group's own target, the sample's
     * red (C00000) when it does not, and no colour at all when either side is
     * unknown — the same rule sheet `W36` bakes in column `BN` (BR-W23).
     */
    private function availability_role($availability, $target, $base) {
        if ($availability === null || $target === null || !is_numeric($availability) || !is_numeric($target)) {
            return $base . ($base === 'syn' ? '_num' : '_pct');
        }
        return $base . (((float)$availability >= (float)$target) ? '_ok' : '_low');
    }

    /**
     * The name used by the generated workbook. Reports produced before the
     * dashboard name field existed still resolve to their ISO week, preserving
     * the sample's W36 output without making W36 a code constant.
     */
    public function report_name(array $report) {
        $week = isset($report['week']) ? $report['week'] : array();
        $name = isset($report['report_name']) ? trim((string)$report['report_name']) : '';
        if ($name === '') {
            $n = isset($week['iso_week']) ? (int)$week['iso_week']
               : (isset($week['start']) ? (int)date('W', strtotime($week['start'])) : 0);
            $name = $n > 0 ? 'W' . $n : 'Weekly Report';
        }
        $name = str_replace(array('[', ']', ':', '*', '?', '/'), ' ', $name);
        $name = str_replace(chr(92), ' ', $name);
        $name = trim((string)preg_replace('/[[:space:]]+/u', ' ', $name));
        return $name === '' ? 'Weekly Report' : $name;
    }

    /** The download name follows the report name and selected ISO week. */
    public function filename(array $report) {
        $week = isset($report['week']) ? $report['week'] : array();
        $label = isset($week['iso_label']) ? $week['iso_label'] : (isset($week['start']) ? $week['start'] : 'week');
        $name = preg_replace('/[^0-9A-Za-z\-]+/', '_', $this->report_name($report));
        $name = trim((string)$name, '_');
        if ($name === '') $name = 'Weekly_Report';
        return $name . '_Weekly_Report_' . preg_replace('/[^0-9A-Za-z\-]/', '_', $label) . '.xlsx';
    }

    /** Paint a built sheet onto a writer (separated so tests can inspect both). */
    public function paint($xlsx, array $sheet) {
        $spec = $this->spec();

        $xlsx->addSheet($sheet['sheet']);
        $xlsx->freezePane($sheet['freeze']);
        foreach ($sheet['widths'] as $col => $w) $xlsx->setColWidth($this->col_index($col), $w);
        foreach ($sheet['row_heights'] as $row => $h) $xlsx->setRowHeight($row, $h);

        // rows 1..last, left to right: XlsxWriter appends rows in order
        for ($row = 1; $row <= $sheet['last_row']; $row++) {
            if ($row > 4) $xlsx->setRowHeight($row, $sheet['data_row_height']);
            $line = array();
            for ($ci = 1; $ci <= 80; $ci++) {                 // A … CB
                $ref = $this->col_letter($ci) . $row;
                if (!isset($sheet['cells'][$ref])) { $line[] = array('v' => null, 's' => ''); continue; }
                $cell = $sheet['cells'][$ref];
                $line[] = array('v' => $cell['v'], 's' => $this->style_key($cell, $this->col_letter($ci)));
            }
            $xlsx->writeRowStyled($line);
        }

        foreach ($sheet['merges'] as $range) {
            $parts = explode(':', $range);
            $xlsx->mergeCells($parts[0], $parts[1]);
        }

        // the site dropdown on every machine row — the sample applied it
        // to 30 rows out of 104; every machine row gets it here
        if (!empty($sheet['validation']['list'])) {
            $xlsx->addDataValidation($sheet['validation']['sqref'], array(
                'type' => 'list',
                'formula1' => '"' . implode(',', array_slice(array_values($sheet['validation']['list']), 0, 30)) . '"',
                'allowBlank' => true,
                'showDropDown' => false,
                'promptTitle' => 'LOCATION',
                'prompt' => 'Report location — the approved site vocabulary',
            ));
        }
        return $xlsx;
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

    /* ────────────────────────────────────────────────────────────────────
     |  styles — the sample's look, in the writer's own vocabulary
     |
     |  Values in the sample: Calibri 10 bold everywhere, titles 18 bold, the
     |  emphasis cells red bold C00000, open-time cells green, headers light
     |  grey, the per-type block a shade lighter. Borders are medium on block
     |  edges and thin inside; the writer supports one weight per cell, so the
     |  inside weight is used (the outer edges of a block still read as a block
     |  because of the fills).
     * ──────────────────────────────────────────────────────────────────── */

    /** Every column that carries a failure count: the 7 day blocks + the weekly one. */
    public function failure_columns() {
        $spec = $this->spec();
        $cols = array();
        foreach ($spec['day_block_start'] as $start) {
            $cols[] = $this->shift($start, $spec['failure_cols'][0]);
        }
        $cols[] = $spec['weekly_failure_col'];
        return $cols;
    }

    private function style_key(array $cell, $col) {
        $fmt  = isset($cell['fmt']) && $cell['fmt'] !== 'General' ? '|numFmt:' . $cell['fmt'] : '';
        $base = 'fontName:Calibri|fontSize:10|bold';
        $syn_bold = 'fontName:Calibri|fontSize:14|bold';      // sheet `W36 Synthese`

        switch ($cell['role']) {
            case 'report_title': return 'fontName:Calibri|fontSize:18|bold';
            case 'open_title':   return $base . '|align:center|valign:center|bg:00FF00|border:medium';
            case 'open_title_2': return $base . '|align:center|valign:center|bg:9AE579|border:medium';
            case 'day_head':     return $base . '|align:center|valign:center|wrap|bg:D9D9D9|border:medium' . $fmt;
            case 'head':         return $base . '|align:center|valign:center|wrap|bg:D9D9D9|border:medium';
            case 'head_block':   return $base . '|align:center|valign:center|wrap|bg:F2F2F2|border:medium';
            case 'group':        return $base . '|align:center|valign:center|border:medium';
            case 'down':         return $base . '|align:center|valign:center|border:medium|wrap|fg:C00000';
            case 'identity':     return $base . '|valign:center|border:medium';
            // BR-W23 — the sample's own green (C006100 was the rule that worked)
            case 'avail_ok':     return $base . '|align:center|valign:center|wrap|border:thin|fg:006100' . $fmt;
            case 'avail_low':    return $base . '|align:center|valign:center|wrap|border:thin|fg:C00000' . $fmt;
            case 'fleet':        return $base . '|align:center|valign:center|wrap|border:medium' . $fmt;
            case 'block_data':   return $base . '|align:center|valign:center|wrap|bg:F2F2F2|border:thin' . $fmt;
            case 'block_label':  return $base . '|align:left|valign:center|bg:F2F2F2|border:thin';

            /* ── sheet `W36 Synthese` (W5.4): the sample's own look ────────
               Calibri 14 on this sheet, titles 18 on a blue band, the KPI
               block 11, the days-down figure the sample's red 16 (N74 is
               C00000 in the file itself). */
            case 'syn_title':      return 'fontName:Calibri|fontSize:18|bold|align:center|valign:center'
                                        . '|bg:0000FF|fg:FFFFFF|border:medium' . $fmt;
            case 'syn_legend':     return 'fontName:Calibri|fontSize:11|bold|valign:top|wrap';
            case 'syn_head':       return $syn_bold . '|align:center|valign:center|wrap|bg:F2F2F2|border:thin';
            case 'syn_group':      return $syn_bold . '|align:center|valign:center|wrap|bg:F2F2F2|border:thin';
            case 'syn_group_pct':  return $syn_bold . '|align:center|valign:center|wrap|bg:F2F2F2|border:thin' . $fmt;
            case 'syn_group_ok':   return $syn_bold . '|align:center|valign:center|wrap|bg:F2F2F2|border:thin|fg:006100' . $fmt;
            case 'syn_group_low':  return $syn_bold . '|align:center|valign:center|wrap|bg:F2F2F2|border:thin|fg:C00000' . $fmt;
            case 'syn_id':         return $syn_bold . '|align:center|valign:center|wrap|border:thin';
            case 'syn_num':        return $syn_bold . '|align:center|valign:center|wrap|border:thin' . $fmt;
            // the sample's `G` rule: `containsText Breakdown` → red (baked here)
            case 'syn_status_bd':  return $syn_bold . '|align:center|valign:center|wrap|border:thin|fg:C00000';
            // the sample's `H` rule compared every row with ONE row's target
            // ($I$72); here each machine is compared with its own (BR-W23)
            case 'syn_ok':         return $syn_bold . '|align:center|valign:center|wrap|border:thin|fg:006100' . $fmt;
            case 'syn_low':        return $syn_bold . '|align:center|valign:center|wrap|border:thin|fg:C00000' . $fmt;
            // the sample's `N` : 16pt bold red wherever there is a figure
            case 'syn_days':       return 'fontName:Calibri|fontSize:16|bold|align:center|valign:center'
                                        . '|border:thin|fg:C00000' . $fmt;
            case 'syn_hold':       return $syn_bold . '|align:center|valign:center|wrap|border:thin|fg:C00000';
            case 'syn_status':     return $syn_bold . '|align:center|valign:center|wrap|border:thin' . $fmt;
            case 'syn_text':       return 'fontName:Calibri|fontSize:14|valign:top|wrap|border:thin';
            case 'syn_data':       return $syn_bold . '|align:center|valign:center|wrap|border:thin' . $fmt;
            // the `M`/`N` block: the counters 14pt, the ratios and the labels 11pt
            case 'syn_kpi_count':  return $syn_bold . '|align:center|valign:center';
            case 'syn_kpi_label':  return 'fontName:Calibri|fontSize:11|bold|align:left|valign:center';
            case 'syn_kpi_sub':    return 'fontName:Calibri|fontSize:11|align:right|valign:center';
            case 'syn_kpi_ratio':  return 'fontName:Calibri|fontSize:11|bold|align:center|valign:center'
                                        . '|numFmt:0%';
            case 'syn_kpi_total':  return 'fontName:Calibri|fontSize:11|bold|align:right|valign:center';
            case 'syn_kpi_total_v':return 'fontName:Calibri|fontSize:11|bold|align:left|valign:center';
            default:
                // failure counts are the other emphasis cells (BR-W22); the columns
                // are derived from the spec, so moving the weekly block cannot leave
                // the emphasis behind on the old letter
                if (in_array($col, $this->failure_columns(), true)
                    && is_numeric($cell['v']) && (float)$cell['v'] > 0) {
                    return $base . '|align:center|valign:center|wrap|border:thin|fg:C00000' . $fmt;
                }
                return $base . '|align:center|valign:center|wrap|border:thin' . $fmt;
        }
    }

    /* ────────────────────────────────────────────────────────────────────
     |  helpers
     * ──────────────────────────────────────────────────────────────────── */

    /** A machine's seven day values, laid into its block. */
    private function put_daily($put, $first_col, $row, array $daily, $role = 'data', array $weekly = array()) {
        $spec = $this->spec();
        foreach (array_values($daily) as $i => $day) {
            if (!isset($spec['day_block_start'][$i])) break;
            $start = $spec['day_block_start'][$i];
            foreach ($spec['day_metrics'] as $key => $offset) {
                $v = array_key_exists($key, $day) ? $day[$key] : null;
                $put($this->shift($start, $offset), $row, $v, $role);
            }
        }
    }

    /** A machine's / the fleet's weekly block, BG:BP (OPEN-5: BG = days down). */
    private function put_weekly($put, $start, $row, array $weekly, $open_hours, $role = 'data') {
        $spec = $this->spec();
        foreach ($spec['weekly_metrics'] as $key => $offset) {
            if ($key === 'open') { $put($this->shift($start, $offset), $row, $open_hours, $role); continue; }
            $v = array_key_exists($key, $weekly) ? $weekly[$key] : null;
            $put($this->shift($start, $offset), $row, $v, $role);
        }
    }

    private function num($arr, $key) { return isset($arr[$key]) ? $arr[$key] : null; }

    /**
     * Excel serial date for a 'Y-m-d' (what a reader shows as a date).
     */
    public function excel_date($date) {
        $ts = strtotime(substr((string)$date, 0, 10) . ' 00:00:00');
        if ($ts === false) return null;
        $epoch = strtotime(self::EXCEL_EPOCH . ' 00:00:00');
        return (int)round(($ts - $epoch) / 86400);
    }

    /**
     * The label the sheet carries in BG2: `W36 from 31/08/26 to 6/09/2026`.
     * The two date formats are the sample's own (d/m/yy and d/m/yyyy).
     */
    public function period_label(array $week, $report_name = null) {
        $start = isset($week['start']) ? $week['start'] : null;
        $end   = isset($week['end']) ? $week['end'] : null;
        $n     = isset($week['iso_week']) ? (int)$week['iso_week'] : (int)date('W', strtotime((string)$start));
        $name  = $report_name !== null && trim((string)$report_name) !== '' ? trim((string)$report_name) : 'W' . $n;
        $a     = $start ? date('j/n/y', strtotime($start)) : '';
        $b     = $end ? date('j/n/Y', strtotime($end)) : '';
        return $name . ' from ' . $a . ' to ' . $b;
    }

    /** Column letters: 0-based shift from a start letter ('I' + 3 → 'L'). */
    public function shift($start, $offset) {
        return $this->col_letter($this->col_index($start) + $offset);
    }

    public function col_index($letters) {
        $n = 0;
        foreach (str_split(strtoupper((string)($letters))) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n;
    }

    public function col_letter($n) {
        $letters = '';
        while ($n > 0) {
            $n--;
            $letters = chr(65 + ($n % 26)) . $letters;
            $n = intdiv($n, 26);
        }
        return $letters;
    }

    /* ────────────────────────────────────────────────────────────────────
     |  the second worksheet — `W36 Synthese` (Phase B, W5.4, D16/D17)
     |
     |  The sample's own sheet, section by section, at the sample's own rows
     |  (title 70, headers 71, machines from 72, registers from 181), so the
     |  download can be compared with the approved workbook line by line. The
     |  top of the sheet stays empty on purpose: in the sample that area holds
     |  the charts and charts are preview-only (D6).
     |
     |  Only the ranges that must follow the data move: the auto-filter covers
     |  the action plan's real rows, the print area the last register row, and
     |  the four dropdowns the rows they belong to.
     * ──────────────────────────────────────────────────────────────────── */

    /** The geometry of sheet `W36 Synthese`, taken from the sample. */
    public function spec_synthese() {
        if ($this->spec2 !== null) return $this->spec2;

        $this->spec2 = array(
            'sheet' => 'W36 Synthese',

            // the action plan
            'action_title_row'  => 70,
            'action_title'      => 'WORKSHOP ACTION PLAN ',
            'action_title_span' => array('C', 'H'),
            'legend_row'        => 69,
            'legend_col'        => 'M',
            'legend_title'      => 'Legende:',
            'week_row'          => 70,
            'header_row'        => 71,
            'first_data_row'    => 72,
            'headers' => array(
                'B' => 'CATEGORIE', 'C' => 'MACHINE ID', 'D' => 'MODEL', 'E' => 'LOCATION',
                'F' => 'CURRENT HOURS/ KM', 'G' => 'EQUIPMENT STATUS', 'H' => 'AVAIBILITY OPERATIONAL',
                'I' => "DAILY TARGET\n KPI", 'J' => 'FLEET AVAIBILITY', 'K' => 'FLEET FORCAST AVAIBILITY',
                'L' => 'COMMENT', 'M' => 'ACTION PLAN', 'N' => 'DAYS DOWN', 'O' => 'STATUS',
            ),

            // the two registers, sharing one row per machine
            'reg_title_row'  => 179,
            'reg_header_row' => 180,
            'reg_first_row'  => 181,
            'reg_title_inspection' => array('col' => 'C', 'span' => array('C', 'I'), 'label' => 'WEEKLY INSPECTION REPORT'),
            'reg_title_pm'         => array('col' => 'J', 'span' => array('J', 'L'), 'label' => 'WEEKLY PLANNED PM SERVICES'),
            'reg_headers' => array(
                'B' => 'CATEGORIE', 'C' => 'MACHINE ID', 'D' => 'COMMENTS', 'I' => 'STATUS',
                'J' => 'TYPE', 'K' => 'STATUS', 'L' => 'COMMENTS', 'M' => 'Pm',
            ),
            'comment_span' => array('D', 'H'),

            // the `M`/`N` counters, at the sample's own rows
            'kpi_col_value' => 'M',
            'kpi_col_label' => 'N',
            'kpi_pm'    => array(181 => 'TOTAL PLANNED', 182 => 'DONE', 183 => 'UNDONE',
                                 184 => 'TOTAL DONE', 185 => 'TOTAL UNDONE'),
            'kpi_pm_sub' => array(190 => 'Insp'),
            'kpi_insp_total_row' => 191,
            'kpi_insp'  => array(192 => 'DONE', 193 => 'UNDONE', 194 => 'BREAKDOWN',
                                 195 => 'DONE', 196 => 'UNDONE', 197 => 'BREAKDOWN'),
            'kpi_total_row'   => 198,
            'kpi_total_label' => 'TOTAL ACTION PLANIFIED',

            // the sample's own widths, formats and row heights
            'widths' => array(
                'A' => 1.7265625,  'B' => 22.54296875, 'C' => 15.6328125, 'D' => 22.1796875,
                'E' => 30.54296875, 'F' => 22.54296875, 'G' => 22.36328125, 'H' => 17.6328125,
                'I' => 17.6328125, 'J' => 17.6328125, 'K' => 8.43, 'L' => 63.54296875,
                'M' => 75.90625,   'N' => 12.08984375, 'O' => 10.90625,
            ),
            'formats' => array('H' => '0.0%', 'I' => '0.0%', 'J' => '0.0%', 'K' => '0.0%', 'N' => '0'),
            'row_heights'     => array(69 => 113, 70 => 35, 71 => 51.5, 179 => 24, 180 => 44.5),
            'data_row_height' => 21,
            'date_format'     => 'mm-dd-yy',

            // the vocabularies the sample's own dropdowns carry. The equipment
            // status list is the database's own (BR-W6, `equip_helper`) — the
            // sample's two-word list was the manual shortcut, and `In Maintenance`
            // is just as much "down" as `Breakdown` is.
            'status_vocabulary'     => function_exists('equip_statuses')
                                       ? equip_statuses() : array('Available', 'Breakdown'),
            'inspection_vocabulary' => array('DONE', 'UNDONE', 'BREAKDOWN'),
            'pm_vocabulary'         => array('DONE', 'UNDONE'),

            // the sample prints the mark on this sheet too (drawing2 → image1)
            'logo' => true,
        );

        return $this->spec2;
    }

    /**
     * The cell map of sheet `W36 Synthese`.
     *
     * @param  array $report the array from W36_report_model::build()
     * @return array sheet, cells, merges, widths, the ranges that follow the data
     */
    public function build_synthese(array $report) {
        $spec = $this->spec_synthese();

        $sy     = isset($report['synthese']) ? $report['synthese'] : array();
        $plan   = isset($sy['action_plan']) ? $sy['action_plan'] : array();
        $reg    = isset($sy['register']) ? $sy['register'] : array();
        $kpi    = isset($sy['kpi']) ? $sy['kpi'] : array();
        $legend = isset($sy['legend']) ? $sy['legend'] : array();
        $week   = isset($report['week']) ? $report['week'] : array();
        $report_name = $this->report_name($report);

        $cells  = array();
        $merges = array();
        $put = function ($col, $row, $value, $role = 'syn_data') use (&$cells, $spec) {
            $fmt = isset($spec['formats'][$col]) ? $spec['formats'][$col] : 'General';
            $cells[$col . $row] = array('v' => $value, 'role' => $role, 'fmt' => $fmt);
        };

        /* ── the title, the legend and the week row ────────────────────── */

        $put($spec['action_title_span'][0], $spec['action_title_row'], $spec['action_title'], 'syn_title');
        $merges[] = $spec['action_title_span'][0] . $spec['action_title_row'] . ':'
                  . $spec['action_title_span'][1] . $spec['action_title_row'];

        $lines = array($spec['legend_title']);
        foreach ($legend as $code => $label) $lines[] = $code . ': ' . $label;
        if (count($lines) > 1) {
            $put($spec['legend_col'], $spec['legend_row'], implode("\n", $lines), 'syn_legend');
        }

        // row 70: `W`, the ISO week number, the first and the last day
        $put('I', $spec['week_row'], 'W', 'syn_title');
        $put('J', $spec['week_row'], isset($week['iso_week']) ? (int)$week['iso_week'] : null, 'syn_title');
        $put('K', $spec['week_row'], isset($week['start']) ? $this->excel_date($week['start']) : null, 'syn_title');
        $put('L', $spec['week_row'], isset($week['end']) ? $this->excel_date($week['end']) : null, 'syn_title');
        foreach (array('K', 'L') as $c) $cells[$c . $spec['week_row']]['fmt'] = $spec['date_format'];

        foreach ($spec['headers'] as $col => $label) $put($col, $spec['header_row'], $label, 'syn_head');

        /* ── the machines, under their group rows (BR-25) ──────────────── */

        $row       = $spec['first_data_row'];
        $group     = null;
        $group_row = null;
        $plan_rows = array();                       // machine id => row

        $close_group = function ($last) use (&$cells, &$merges, &$group, &$group_row, $put) {
            if ($group === null || $group_row === null) return;
            $avail  = isset($group['availability']) ? $group['availability'] : null;
            $target = isset($group['target']) ? $group['target'] : null;
            $put('B', $group_row, isset($group['label']) ? $group['label'] : '', 'syn_group');
            $put('J', $group_row, $avail, $this->availability_role($avail, $target, 'syn_group'));
            $put('K', $group_row, $target, 'syn_group_pct');
            if ($last > $group_row) {
                foreach (array('B', 'J', 'K') as $c) $merges[] = $c . $group_row . ':' . $c . $last;
            }
            $group = null;
        };

        foreach ($plan as $entry) {
            if (!isset($entry['type'])) continue;

            if ($entry['type'] === 'group') {
                $close_group($row - 1);
                $group     = $entry;
                $group_row = $row;
                continue;
            }

            $put('C', $row, isset($entry['machine_id']) ? $entry['machine_id'] : '', 'syn_id');
            $put('D', $row, isset($entry['model']) ? $entry['model'] : '', 'syn_id');
            $put('E', $row, isset($entry['location']) ? $entry['location'] : '', 'syn_id');

            $meter = null;
            if (isset($entry['current_hours']) && $entry['current_hours'] !== null && (float)$entry['current_hours'] > 0) {
                $meter = (float)$entry['current_hours'];
            } elseif (isset($entry['current_km']) && $entry['current_km'] !== null && (float)$entry['current_km'] > 0) {
                $meter = (float)$entry['current_km'];
            }
            $status = isset($entry['status']) ? $entry['status'] : '';
            $avail  = isset($entry['availability']) ? $entry['availability'] : null;
            $target = isset($entry['target']) ? $entry['target'] : null;

            $put('F', $row, $meter, 'syn_num');
            $put('G', $row, $status,
                 (function_exists('equip_is_down') && equip_is_down($status)) ? 'syn_status_bd' : 'syn_id');
            $put('H', $row, $avail, $this->availability_role($avail, $target, 'syn'));
            $put('I', $row, $target, 'syn_num');
            $put('L', $row, isset($entry['comment']) ? $entry['comment'] : null, 'syn_text');
            $put('M', $row, isset($entry['action_plan']) ? $entry['action_plan'] : null, 'syn_text');
            // the sample leaves the cell empty when the machine is not down
            $days = isset($entry['days_down']) ? (int)$entry['days_down'] : 0;
            $put('N', $row, $days > 0 ? $days : null, $days > 0 ? 'syn_days' : 'syn_num');
            $put('O', $row, isset($entry['hold_code']) ? $entry['hold_code'] : null,
                 (isset($entry['hold_code']) && $entry['hold_code'] !== null ? 'syn_hold' : 'syn_id'));

            if (isset($entry['machine_id'])) $plan_rows[$entry['machine_id']] = $row;
            $row++;
        }
        $close_group($row - 1);

        $last_plan_row  = $row - 1;
        $last_plan_row  = max($last_plan_row, $spec['header_row']);   // an empty plan still prints its header

        /* ── the two registers, one row per machine (BR-26) ────────────── */

        $put($spec['reg_title_inspection']['col'], $spec['reg_title_row'],
             $spec['reg_title_inspection']['label'], 'syn_title');
        $merges[] = $spec['reg_title_inspection']['span'][0] . $spec['reg_title_row'] . ':'
                  . $spec['reg_title_inspection']['span'][1] . $spec['reg_title_row'];
        $put($spec['reg_title_pm']['col'], $spec['reg_title_row'], $spec['reg_title_pm']['label'], 'syn_title');
        $merges[] = $spec['reg_title_pm']['span'][0] . $spec['reg_title_row'] . ':'
                  . $spec['reg_title_pm']['span'][1] . $spec['reg_title_row'];

        foreach ($spec['reg_headers'] as $col => $label) {
            $put($col, $spec['reg_header_row'], $label, $col === 'M' ? 'syn_kpi_label' : 'syn_head');
        }
        $merges[] = $spec['comment_span'][0] . $spec['reg_header_row'] . ':'
                  . $spec['comment_span'][1] . $spec['reg_header_row'];

        $row           = $spec['reg_first_row'];
        $reg_rows      = array();
        $group         = null;
        $group_row     = null;
        $close_reg_group = function ($last) use (&$cells, &$merges, &$group, &$group_row, $put) {
            if ($group === null || $group_row === null) return;
            $put('B', $group_row, isset($group['label']) ? $group['label'] : '', 'syn_group');
            if ($last > $group_row) $merges[] = 'B' . $group_row . ':B' . $last;
            $group = null;
        };

        foreach ($reg as $entry) {
            if (!isset($entry['type'])) continue;

            if ($entry['type'] === 'group') {
                $close_reg_group($row - 1);
                $group     = $entry;
                $group_row = $row;
                continue;
            }

            $put('C', $row, isset($entry['machine_id']) ? $entry['machine_id'] : '', 'syn_id');

            $comments = array();
            if (isset($entry['inspection']['comments'])) {
                foreach ((array)$entry['inspection']['comments'] as $c) {
                    $c = trim((string)$c);
                    if ($c !== '') $comments[] = $c;
                }
            }
            $put('D', $row, empty($comments) ? null : implode("\n", $comments), 'syn_text');
            $merges[] = $spec['comment_span'][0] . $row . ':' . $spec['comment_span'][1] . $row;

            $status = isset($entry['inspection']['status']) ? $entry['inspection']['status'] : 'UNDONE';
            $put('I', $row, $status, 'syn_status');

            // one row per machine: several services are stacked inside the cell,
            // which keeps the two registers aligned row for row (BR-26)
            $types = array(); $states = array(); $reasons = array();
            foreach ((array)$entry['pm']['services'] as $sv) {
                if (isset($sv['type']) && $sv['type'] !== '')   $types[]   = $sv['type'];
                if (isset($sv['status']) && $sv['status'] !== '') $states[] = $sv['status'];
                if (isset($sv['comment']) && $sv['comment'] !== null && $sv['comment'] !== '') $reasons[] = $sv['comment'];
            }
            $put('J', $row, empty($types) ? null : implode("\n", $types), 'syn_num');
            $put('K', $row, empty($states) ? null : implode("\n", $states), 'syn_status');
            $put('L', $row, empty($reasons) ? null : implode("\n", $reasons), 'syn_text');

            $reg_rows[] = $row;
            $row++;
        }
        $close_reg_group($row - 1);
        $last_reg_row = max($row - 1, $spec['reg_header_row']);

        /* ── the `M`/`N` counters (BR-27) ──────────────────────────────── */

        $pm  = isset($kpi['pm']) ? $kpi['pm'] : array();
        $ins = isset($kpi['inspection']) ? $kpi['inspection'] : array();

        $pm_values = array(
            181 => isset($pm['planned']) ? (int)$pm['planned'] : 0,
            182 => isset($pm['done']) ? (int)$pm['done'] : 0,
            183 => isset($pm['undone']) ? (int)$pm['undone'] : 0,
            184 => isset($pm['ratio_done']) ? $pm['ratio_done'] : null,
            185 => isset($pm['ratio_undone']) ? $pm['ratio_undone'] : null,
        );
        foreach ($spec['kpi_pm'] as $r => $label) {
            $put($spec['kpi_col_label'], $r, $label, 'syn_kpi_label');
            $put($spec['kpi_col_value'], $r, $pm_values[$r],
                 ($r >= 184) ? 'syn_kpi_ratio' : 'syn_kpi_count');
        }

        foreach ($spec['kpi_pm_sub'] as $r => $label) $put($spec['kpi_col_value'], $r, $label, 'syn_kpi_sub');

        $put($spec['kpi_col_value'], $spec['kpi_insp_total_row'],
            isset($ins['machines']) ? (int)$ins['machines'] : 0, 'syn_kpi_count');

        $ins_values = array(192 => 'done', 193 => 'undone', 194 => 'breakdown',
                            195 => 'ratio_done', 196 => 'ratio_undone', 197 => 'ratio_breakdown');
        foreach ($spec['kpi_insp'] as $r => $label) {
            $put($spec['kpi_col_label'], $r, $label, 'syn_kpi_label');
            $key = $ins_values[$r];
            $put($spec['kpi_col_value'], $r, isset($ins[$key]) ? $ins[$key] : null,
                 ($r >= 195) ? 'syn_kpi_ratio' : 'syn_kpi_count');
        }

        // the sample's total row swaps the columns: label in `M`, figure in `N`
        $put($spec['kpi_col_value'], $spec['kpi_total_row'], $spec['kpi_total_label'], 'syn_kpi_total');
        $put($spec['kpi_col_label'], $spec['kpi_total_row'],
            isset($kpi['total_planned']) ? (int)$kpi['total_planned'] : 0, 'syn_kpi_total_v');

        /* ── everything that has to follow the data ────────────────────── */

        return array(
            'sheet'      => $report_name . ' Synthese',
            'cells'      => $cells,
            'merges'     => $merges,
            'widths'     => $spec['widths'],
            'row_heights'=> $spec['row_heights'],
            'data_row_height' => $spec['data_row_height'],
            'last_row'   => max($last_reg_row, $spec['kpi_total_row']),
            'last_plan_row' => $last_plan_row,
            'first_data_row'=> $spec['first_data_row'],
            'reg_first_row' => $spec['reg_first_row'],
            'last_reg_row'  => $last_reg_row,
            'plan_rows'  => $plan_rows,
            'filter'     => 'J' . $spec['header_row'] . ':O' . $last_plan_row,
            'print_area' => 'A1:O' . $last_reg_row,
            'page'       => array(
                'orientation' => 'landscape',
                'paperSize'   => 9,                        // A4, the sample's own
                'left' => 0.23622047244094491, 'right' => 0.23622047244094491,
                'top'  => 0.15748031496062992, 'bottom' => 0.15748031496062992,
                'header' => 0, 'footer' => 0,
                'fitToPage' => true,                       // sample: fitToPage="1"
                'scale'       => 41,                       // sample: scale="41"
                'fitToWidth'  => 1,                        // sample: fitToWidth="1"
                'fitToHeight' => 0,                        // sample: fitToHeight="0"
            ),
            'validation' => array(
                'hold'       => array('sqref' => 'O' . $spec['first_data_row'] . ':O' . $last_plan_row,
                                      'list'  => array_values(array_keys($legend)), 'title' => 'STATUS'),
                'status'     => array('sqref' => 'G' . $spec['first_data_row'] . ':G' . $last_plan_row,
                                      'list'  => $spec['status_vocabulary'], 'title' => 'EQUIPMENT STATUS'),
                'inspection' => array('sqref' => 'I' . $spec['reg_first_row'] . ':I' . $last_reg_row,
                                      'list'  => $spec['inspection_vocabulary'], 'title' => 'INSPECTION STATUS'),
                'pm'         => array('sqref' => 'K' . $spec['reg_first_row'] . ':K' . $last_reg_row,
                                      'list'  => $spec['pm_vocabulary'], 'title' => 'PM STATUS'),
            ),
        );
    }

    /**
     * Paint sheet `W36 Synthese` onto the writer (a second worksheet).
     *
     * The rows above the report are written empty (the sample's chart area, D6),
     * and every range that must follow the data is passed to the writer instead
     * of being a constant: the auto-filter, the print area and the dropdowns.
     */
    public function paint_synthese($xlsx, array $sheet) {
        $xlsx->addSheet($sheet['sheet']);
        foreach ($sheet['widths'] as $col => $w) $xlsx->setColWidth($this->col_index($col), $w);
        foreach ($sheet['row_heights'] as $row => $h) $xlsx->setRowHeight($row, $h);

        $used = array();
        foreach ($sheet['cells'] as $ref => $cell) {
            $parts = preg_split('/(?<=\D)(?=\d)/', $ref);   // 'AB12' → ['AB', '12']
            $used[(int)$parts[1]] = true;
        }

        for ($row = 1; $row <= $sheet['last_row']; $row++) {
            if ($row > $sheet['first_data_row'] && !isset($sheet['row_heights'][$row])) {
                $xlsx->setRowHeight($row, $sheet['data_row_height']);
            }
            if (!isset($used[$row])) { $xlsx->writeRowStyled(array()); continue; }

            $line = array();
            for ($ci = 1; $ci <= 15; $ci++) {               // A … O
                $ref = $this->col_letter($ci) . $row;
                if (!isset($sheet['cells'][$ref])) { $line[] = array('v' => null, 's' => ''); continue; }
                $cell = $sheet['cells'][$ref];
                $line[] = array('v' => $cell['v'], 's' => $this->style_key($cell, $this->col_letter($ci)));
            }
            $xlsx->writeRowStyled($line);
        }

        foreach ($sheet['merges'] as $range) {
            $parts = explode(':', $range);
            $xlsx->mergeCells($parts[0], $parts[1]);
        }

        foreach ($sheet['validation'] as $v) {
            if (empty($v['list'])) continue;
            $xlsx->addDataValidation($v['sqref'], array(
                'type' => 'list',
                'formula1' => '"' . implode(',', array_values($v['list'])) . '"',
                'allowBlank' => true,
                'showDropDown' => false,
                'promptTitle' => $v['title'],
                'prompt' => 'The approved vocabulary',
            ));
        }

        $xlsx->setAutoFilter($sheet['filter']);
        $xlsx->setPageSetup(array_merge($sheet['page'], array('printArea' => $sheet['print_area'])));

        if ($this->spec_synthese()['logo']) $this->paint_logo($xlsx, false);
        return $xlsx;
    }
}
