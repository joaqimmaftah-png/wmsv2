<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Timesheet_excel — builds native Microsoft Excel `.xlsx` workbooks matching
 * the exact AML Individual Attendance Sheet template (`TIME SHEET AML.pdf`),
 * complete with embedded AML corporate ribbon banner, `#92D050` green & `#9BC2E6`
 * sky-blue headers/subtotals, exact stepped `medium` outer frame borders,
 * Monday–Sunday weekly blocks, red bold Sundays, live `=SUM(...)` / `=COUNT(...)`
 * Excel formulas, `TOTAL DAYS WORKED` box, 3-column approval footer, and A4
 * Portrait 1-page print configuration.
 */
#[AllowDynamicProperties]
class Timesheet_excel {

    private $bannerPath;

    public function __construct($params = array()) {
        if (isset($params['banner'])) {
            $this->bannerPath = $params['banner'];
        } else {
            $root = defined('FCPATH') ? FCPATH : (realpath(dirname(__FILE__) . '/../..') . '/');
            $this->bannerPath = rtrim($root, '/\\') . '/uploads/aml_timesheet_banner.png';
        }
    }

    /** Load project XlsxWriter cleanly in both CI and CLI/test contexts. */
    private function _writer() {
        if (!class_exists('XlsxWriter')) {
            if (function_exists('get_instance') && isset(get_instance()->load)) {
                get_instance()->load->helper('xlsx');
            }
            if (!class_exists('XlsxWriter')) {
                require_once dirname(__FILE__) . '/../helpers/xlsx_helper.php';
            }
        }
        return new XlsxWriter();
    }

    /**
     * Save one or many printable AML sheets into a single `.xlsx` workbook
     * (one Excel worksheet tab per printable sheet, ready for 1-page A4 print).
     *
     * @param  array  $sheets   list of sheet payloads from Timesheet_model
     * @param  string $filePath destination `.xlsx` path
     * @return string           saved `$filePath`
     */
    public function save(array $sheets, $filePath) {
        $xlsx = $this->_writer();
        $used_names = array();

        foreach ($sheets as $idx => $sheet) {
            $tab = $this->_unique_tab_name($sheet, $idx + 1, $used_names);
            $this->_paint_sheet($xlsx, $tab, $sheet);
        }

        return $xlsx->save($filePath);
    }

    /**
     * Render workbook directly to raw `.xlsx` binary bytes (via temp file cleanup).
     *
     * @param  array  $sheets
     * @return string binary `.xlsx` bytes starting with PK\x03\x04
     */
    public function render(array $sheets) {
        $tmp = tempnam(sys_get_temp_dir(), 'aml_ts_');
        $xlsx_path = $tmp . '.xlsx';
        @unlink($tmp);
        try {
            $this->save($sheets, $xlsx_path);
            $bytes = file_get_contents($xlsx_path);
            @unlink($xlsx_path);
            return $bytes !== FALSE ? $bytes : '';
        } catch (Throwable $e) {
            @unlink($xlsx_path);
            throw $e;
        }
    }

    private function _unique_tab_name(array $sheet, $index, array &$used) {
        $base = trim((string)$sheet['full_name']);
        if ($base === '') $base = (string)$sheet['employee_id'];
        if (!empty($sheet['segment_count']) && (int)$sheet['segment_count'] > 1) {
            $base .= ' P' . (int)$sheet['segment_index'];
        }
        $clean = str_replace(array('[', ']', ':', '*', '?', '/', '\\'), ' ', $base);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));
        if ($clean === '') $clean = 'Sheet' . $index;

        $cand = substr($clean, 0, 31);
        if (isset($used[strtolower($cand)])) {
            $suffix = ' ' . $index;
            $cand = substr($clean, 0, 31 - strlen($suffix)) . $suffix;
        }
        $used[strtolower($cand)] = TRUE;
        return $cand;
    }

    private function _paint_sheet(XlsxWriter $x, $tabName, array $sheet) {
        $x->addSheet($tabName);

        // Exact 7 columns matching TIME SHEET AML.pdf proportions
        // A: DAYS | B: DATE | C: TIME IN | D: TIME OUT | E: TOTAL H | F: SIGNATURE | G: OBSERVATION
        $widths = array(
            1 => 13.0,  // DAYS
            2 => 11.2,  // DATE
            3 => 9.8,   // TIME IN
            4 => 10.2,  // TIME OUT
            5 => 9.2,   // TOTAL H
            6 => 25.0,  // SIGNATURE
            7 => 25.0,  // OBSERVATION
        );
        foreach ($widths as $col => $w) $x->setColWidth($col, $w);

        // Styles matching Excel 2019 template colors, fonts & exact outer medium perimeter borders
        $s_blank         = '';
        $s_title_tl      = 'bold|italic|fontName:Times New Roman|fontSize:13|align:center|valign:center|bg:70AD47|borderT:thin|borderL:thin';
        $s_title_tm      = 'bold|italic|fontName:Times New Roman|fontSize:13|align:center|valign:center|bg:70AD47|borderT:thin';
        $s_title_tr      = 'bold|italic|fontName:Times New Roman|fontSize:13|align:center|valign:center|bg:70AD47|borderT:thin|borderR:thin';
        $s_title_bl      = 'bold|italic|fontName:Times New Roman|fontSize:10|align:center|valign:center|bg:70AD47|borderB:thin|borderL:thin';
        $s_title_bm      = 'bold|italic|fontName:Times New Roman|fontSize:10|align:center|valign:center|bg:70AD47|borderB:thin';
        $s_title_br      = 'bold|italic|fontName:Times New Roman|fontSize:10|align:center|valign:center|bg:70AD47|borderB:thin|borderR:thin';
        $s_meta          = 'bold|italic|fontName:Times New Roman|fontSize:11.5|align:left|valign:center';

        // Stepped top green header (#92D050): outer perimeter medium, inner dividers thin
        $s_grn_days_hd   = 'bold|fontName:Calibri|fontSize:9|align:left|valign:center|bg:92D050|borderL:medium|borderR:thin|borderT:medium|borderB:medium';
        $s_grn_left_hd   = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:92D050|borderL:thin|borderR:thin|borderT:medium|borderB:medium';
        $s_grn_sig_top   = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:92D050|borderL:medium|borderR:thin|borderT:medium';
        $s_grn_obs_top   = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:92D050|borderL:thin|borderR:medium|borderT:medium';
        $s_grn_sig_bot   = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:92D050|borderL:thin|borderR:thin|borderB:medium';
        $s_grn_obs_bot   = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:92D050|borderL:thin|borderR:medium|borderB:medium';

        // Standard day rows (left outer border medium on A, right outer border medium on G)
        $s_day_lbl       = 'fontName:Calibri|fontSize:9|align:left|valign:center|borderL:medium|borderR:thin|borderT:thin|borderB:thin';
        $s_day_lbl_notch = 'fontName:Calibri|fontSize:9|align:left|valign:center|borderL:medium|borderR:thin|borderT:thin|borderB:medium';
        $s_day_ctr       = 'fontName:Calibri|fontSize:9|align:center|valign:center|border:thin';
        $s_day_ctr_notch = 'fontName:Calibri|fontSize:9|align:center|valign:center|borderL:thin|borderR:thin|borderT:thin|borderB:medium';
        $s_day_num       = 'fontName:Calibri|fontSize:9|align:center|valign:center|border:thin|numFmt:0.##';
        $s_day_num_end   = 'fontName:Calibri|fontSize:9|align:center|valign:center|borderL:thin|borderR:thin|borderT:thin|borderB:medium|numFmt:0.##';
        $s_day_sig_end   = 'fontName:Calibri|fontSize:9|align:center|valign:center|borderL:thin|borderR:thin|borderT:thin|borderB:medium';
        $s_day_obs       = 'fontName:Calibri|fontSize:9|align:left|valign:center|borderL:thin|borderR:medium|borderT:thin|borderB:thin';
        $s_day_obs_end   = 'fontName:Calibri|fontSize:9|align:left|valign:center|borderL:thin|borderR:medium|borderT:thin|borderB:medium';

        $s_sun_lbl       = 'bold|fontName:Calibri|fontSize:9|fg:FF0000|align:left|valign:center|borderL:medium|borderR:thin|borderT:thin|borderB:thin';
        $s_sun_lbl_notch = 'bold|fontName:Calibri|fontSize:9|fg:FF0000|align:left|valign:center|borderL:medium|borderR:thin|borderT:thin|borderB:medium';
        $s_sun_ctr       = 'bold|fontName:Calibri|fontSize:9|fg:FF0000|align:center|valign:center|border:thin';
        $s_sun_ctr_notch = 'bold|fontName:Calibri|fontSize:9|fg:FF0000|align:center|valign:center|borderL:thin|borderR:thin|borderT:thin|borderB:medium';

        // Sky-blue (#9BC2E6) 3-row inter-week band
        $s_blu_tot_c     = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|borderL:medium|borderT:thin|borderB:medium';
        $s_blu_tot_d     = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|borderR:thin|borderT:thin|borderB:medium';
        $s_blu_tot_val   = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|borderL:thin|borderR:thin|borderT:thin|borderB:medium|numFmt:0.##';
        $s_blu_sig_t     = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|borderL:thin|borderR:thin|borderT:thin';
        $s_blu_sig_m     = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|borderL:medium|borderR:thin';
        $s_blu_sig_b     = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|borderL:thin|borderR:thin|borderB:thin';
        $s_blu_obs_t     = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|borderL:thin|borderR:medium|borderT:thin';
        $s_blu_obs_m     = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|borderL:thin|borderR:medium';
        $s_blu_obs_b     = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|borderL:thin|borderR:medium|borderB:thin';
        $s_blu_days_hd   = 'bold|fontName:Calibri|fontSize:9|align:left|valign:center|bg:9BC2E6|borderL:medium|borderR:thin|borderT:medium|borderB:thin';
        $s_blu_left_hd   = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|borderL:thin|borderR:thin|borderT:medium|borderB:thin';

        // Bottom 2-row TOTAL DAYS WORKED summary block
        $s_sum_lbl_tl    = 'bold|fontName:Calibri|fontSize:8.5|align:center|valign:center|bg:92D050|borderL:medium|borderT:thin';
        $s_sum_lbl_tr    = 'bold|fontName:Calibri|fontSize:8.5|align:center|valign:center|bg:92D050|borderR:thin|borderT:thin';
        $s_sum_lbl_bl    = 'bold|fontName:Calibri|fontSize:8.5|align:center|valign:center|bg:92D050|borderL:medium|borderB:medium';
        $s_sum_lbl_br    = 'bold|fontName:Calibri|fontSize:8.5|align:center|valign:center|bg:92D050|borderR:thin|borderB:medium';
        $s_sum_val_ct    = 'bold|fontName:Calibri|fontSize:9.5|align:center|valign:center|borderL:thin|borderR:thin|borderT:thin|numFmt:0';
        $s_sum_val_cb    = 'bold|fontName:Calibri|fontSize:9.5|align:center|valign:center|borderL:thin|borderR:thin|borderB:medium|numFmt:0';
        $s_sum_val_dt    = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|borderL:thin|borderR:medium|borderT:thin|numFmt:0.##';
        $s_sum_val_db    = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|borderL:thin|borderR:medium|borderB:medium|numFmt:0.##';

        $s_sig_head      = 'bold|italic|underline|fontName:Calibri|fontSize:9|align:center|valign:center';
        $s_sig_role      = 'bold|italic|fontName:Calibri|fontSize:9|align:center|valign:center';
        $s_sig_dots      = 'fontName:Calibri|fontSize:9|align:center|valign:center';

        $r = 1;

        // Rows 1–2: Corporate AML Ribbon Banner area (48 pt tall)
        $x->setRowHeight($r, 26);
        $x->writeRowStyled(array_fill(0, 7, array('v' => '', 's' => $s_blank)));
        $r++;
        $x->setRowHeight($r, 24);
        $x->writeRowStyled(array_fill(0, 7, array('v' => '', 's' => $s_blank)));
        $r++;

        if (is_string($this->bannerPath) && is_file($this->bannerPath)) {
            $x->addImage($this->bannerPath, array(
                'anchor' => 'A1',
                'x'      => 19050,
                'y'      => 19050,
                'width'  => (int)round(451 * 12700), // exact pt -> EMU (1 pt = 12700 EMU)
                'height' => (int)round(47.2 * 12700),
                'name'   => 'AML Banner',
            ));
        }

        // Rows 3–4: Green Title Box across B3:F4 (clean outer border, no inner dividers)
        $x->setRowHeight($r, 20);
        $x->writeRowStyled(array(
            array('v' => '', 's' => $s_blank),
            array('v' => 'INDIVIDUAL ATTENDANCE SHEET', 's' => $s_title_tl),
            array('v' => '', 's' => $s_title_tm),
            array('v' => '', 's' => $s_title_tm),
            array('v' => '', 's' => $s_title_tm),
            array('v' => '', 's' => $s_title_tr),
            array('v' => '', 's' => $s_blank),
        ));
        $x->mergeCells('B' . $r, 'F' . $r);
        $r++;

        $x->setRowHeight($r, 16);
        $x->writeRowStyled(array(
            array('v' => '', 's' => $s_blank),
            array('v' => (string)$sheet['month_heading'], 's' => $s_title_bl),
            array('v' => '', 's' => $s_title_bm),
            array('v' => '', 's' => $s_title_bm),
            array('v' => '', 's' => $s_title_bm),
            array('v' => '', 's' => $s_title_br),
            array('v' => '', 's' => $s_blank),
        ));
        $x->mergeCells('B' . $r, 'F' . $r);
        $r++;

        // Spacer row 5
        $x->setRowHeight($r, 6);
        $x->writeRowStyled(array_fill(0, 7, array('v' => '', 's' => $s_blank)));
        $r++;

        // Rows 6–8: Nom / Position / Site lines across B..F
        $meta_lines = array(
            $this->_format_dotted_field('Nom:', (string)$sheet['full_name']),
            $this->_format_dotted_field('Position:', (string)$sheet['position']),
            $this->_format_dotted_field('Site:', (string)$sheet['site']),
        );
        foreach ($meta_lines as $ml) {
            $x->setRowHeight($r, 15.5);
            $x->writeRowStyled(array(
                array('v' => '', 's' => $s_blank),
                array('v' => $ml, 's' => $s_meta),
                array('v' => '', 's' => $s_meta),
                array('v' => '', 's' => $s_meta),
                array('v' => '', 's' => $s_meta),
                array('v' => '', 's' => $s_meta),
                array('v' => '', 's' => $s_blank),
            ));
            $x->mergeCells('B' . $r, 'F' . $r);
            $r++;
        }

        // Spacer row 9
        $x->setRowHeight($r, 8);
        $x->writeRowStyled(array_fill(0, 7, array('v' => '', 's' => $s_blank)));
        $r++;

        // Rows 10–11: Stepped Top Green Header (#92D050)
        // Row 10: A..E blank, F=SIGNATURE, G=OBSERVATION (merged F10:F11, G10:G11)
        $hd_top_r = $r;
        $x->setRowHeight($r, 9.5);
        $x->writeRowStyled(array(
            array('v' => '', 's' => $s_blank),
            array('v' => '', 's' => $s_blank),
            array('v' => '', 's' => $s_blank),
            array('v' => '', 's' => $s_blank),
            array('v' => '', 's' => $s_blank),
            array('v' => 'SIGNATURE',   's' => $s_grn_sig_top),
            array('v' => 'OBSERVATION', 's' => $s_grn_obs_top),
        ));
        $r++;

        $x->setRowHeight($r, 14);
        $x->writeRowStyled(array(
            array('v' => 'DAYS',     's' => $s_grn_days_hd),
            array('v' => 'DATE',     's' => $s_grn_left_hd),
            array('v' => 'TIME IN',  's' => $s_grn_left_hd),
            array('v' => 'TIME OUT', 's' => $s_grn_left_hd),
            array('v' => 'TOTAL H',  's' => $s_grn_left_hd),
            array('v' => '',         's' => $s_grn_sig_bot),
            array('v' => '',         's' => $s_grn_obs_bot),
        ));
        $x->mergeCells('F' . $hd_top_r, 'F' . $r);
        $x->mergeCells('G' . $hd_top_r, 'G' . $r);
        $r++;

        // Weekly blocks + inter-week stepped Sky-Blue (#9BC2E6) bands
        $grid = $sheet['grid'];
        $weeks = isset($grid['weeks']) ? $grid['weeks'] : array();
        $num_weeks = count($weeks);
        $all_day_ranges = array();
        $days_without_hours = 0;

        foreach ($weeks as $wi => $wk) {
            $wk_first_row = $r;
            $wk_cnt = count($wk['days']);
            foreach ($wk['days'] as $di => $day) {
                $is_sun       = !empty($day['is_sunday']);
                $is_wk_last   = ($di === $wk_cnt - 1);
                $has_inter    = ($wi < $num_weeks - 1);
                $is_table_end = ($wi === $num_weeks - 1) && $is_wk_last;

                if ($day['hours'] !== NULL && $day['hours'] > 0) {
                    $h_val = (float)$day['hours'];
                } else {
                    // Auto-computes decimal hours in Excel if HR enters TIME IN / TIME OUT later; stays "" when blank
                    $h_val = '=IF(AND(C' . $r . '<>"",D' . $r . '<>""),ROUND(MOD(D' . $r . '-C' . $r . ',1)*24,2),"")';
                    if (!empty($day['worked'])) {
                        $days_without_hours++;
                    }
                }

                $notch_ab = $has_inter && $is_wk_last;
                $st_a = $is_sun ? ($notch_ab ? $s_sun_lbl_notch : $s_sun_lbl) : ($notch_ab ? $s_day_lbl_notch : $s_day_lbl);
                $st_b = $is_sun ? ($notch_ab ? $s_sun_ctr_notch : $s_sun_ctr) : ($notch_ab ? $s_day_ctr_notch : $s_day_ctr);
                $st_e = $is_table_end ? $s_day_num_end : $s_day_num;
                $st_f = $is_table_end ? $s_day_sig_end : $s_day_ctr;
                $st_g = $is_table_end ? $s_day_obs_end : $s_day_obs;

                $x->setRowHeight($r, 15.2);
                $x->writeRowStyled(array(
                    array('v' => $day['day_name'],    's' => $st_a),
                    array('v' => $day['date_label'],  's' => $st_b),
                    array('v' => $day['time_in'],     's' => $s_day_ctr),
                    array('v' => $day['time_out'],    's' => $s_day_ctr),
                    array('v' => $h_val,              's' => $st_e),
                    array('v' => '',                  's' => $st_f),
                    array('v' => $day['observation'], 's' => $st_g),
                ));
                $r++;
            }
            $wk_last_row = $r - 1;
            if ($wk_last_row >= $wk_first_row) {
                $all_day_ranges[] = 'E' . $wk_first_row . ':E' . $wk_last_row;
            }

            // Inter-week 3-row Sky-Blue band with live Excel =SUM(...) formula
            if ($wi < $num_weeks - 1) {
                $b1_r = $r;
                $sum_expr = ($wk_last_row >= $wk_first_row)
                    ? ('=IF(SUM(E' . $wk_first_row . ':E' . $wk_last_row . ')>0,SUM(E' . $wk_first_row . ':E' . $wk_last_row . '),"")')
                    : '';

                // Band row 1: A..B white notch, C..D TOTAL, E weekly SUM, F..G merged top
                $x->setRowHeight($r, 15.2);
                $x->writeRowStyled(array(
                    array('v' => '',            's' => $s_blank),
                    array('v' => '',            's' => $s_blank),
                    array('v' => 'TOTAL',       's' => $s_blu_tot_c),
                    array('v' => '',            's' => $s_blu_tot_d),
                    array('v' => $sum_expr,     's' => $s_blu_tot_val),
                    array('v' => 'SIGNATURE',   's' => $s_blu_sig_t),
                    array('v' => 'OBSERVATION', 's' => $s_blu_obs_t),
                ));
                $x->mergeCells('C' . $r, 'D' . $r);
                $r++;

                // Band row 2: A..E white notch, F..G middle of merge (medium borderL on F!)
                $x->setRowHeight($r, 15.2);
                $x->writeRowStyled(array(
                    array('v' => '', 's' => $s_blank),
                    array('v' => '', 's' => $s_blank),
                    array('v' => '', 's' => $s_blank),
                    array('v' => '', 's' => $s_blank),
                    array('v' => '', 's' => $s_blank),
                    array('v' => '', 's' => $s_blu_sig_m),
                    array('v' => '', 's' => $s_blu_obs_m),
                ));
                $r++;

                // Band row 3: A..E next-week headers, F..G bottom of 3-row merge
                $x->setRowHeight($r, 15.2);
                $x->writeRowStyled(array(
                    array('v' => 'DAYS',     's' => $s_blu_days_hd),
                    array('v' => 'DATE',     's' => $s_blu_left_hd),
                    array('v' => 'TIME IN',  's' => $s_blu_left_hd),
                    array('v' => 'TIME OUT', 's' => $s_blu_left_hd),
                    array('v' => 'TOTAL H',  's' => $s_blu_left_hd),
                    array('v' => '',         's' => $s_blu_sig_b),
                    array('v' => '',         's' => $s_blu_obs_b),
                ));
                $x->mergeCells('F' . $b1_r, 'F' . $r);
                $x->mergeCells('G' . $b1_r, 'G' . $r);
                $r++;
            }
        }

        // Bottom 2-row summary block: TOTAL DAYS WORKED (A:B merged) + live Days Count (C) + live Total Hours (D)
        $tot_r1 = $r;
        $rng_csv = implode(',', $all_day_ranges);
        if ($rng_csv !== '') {
            $cnt_expr = ($days_without_hours > 0)
                ? ('(COUNT(' . $rng_csv . ')+' . (int)$days_without_hours . ')')
                : ('COUNT(' . $rng_csv . ')');
            $days_val   = '=IF(' . $cnt_expr . '>0,' . $cnt_expr . ',"")';
            $tot_h_expr = '=IF(SUM(' . $rng_csv . ')>0,SUM(' . $rng_csv . '),"")';
        } else {
            $days_val   = !empty($grid['days_worked']) && (int)$grid['days_worked'] > 0 ? (int)$grid['days_worked'] : '';
            $tot_h_expr = '';
        }

        $x->setRowHeight($r, 15.2);
        $x->writeRowStyled(array(
            array('v' => 'TOTAL DAYS WORKED', 's' => $s_sum_lbl_tl),
            array('v' => '',                  's' => $s_sum_lbl_tr),
            array('v' => $days_val,           's' => $s_sum_val_ct),
            array('v' => $tot_h_expr,         's' => $s_sum_val_dt),
            array('v' => '',                  's' => $s_blank),
            array('v' => '',                  's' => $s_blank),
            array('v' => '',                  's' => $s_blank),
        ));
        $r++;

        $x->setRowHeight($r, 15.2);
        $x->writeRowStyled(array(
            array('v' => '', 's' => $s_sum_lbl_bl),
            array('v' => '', 's' => $s_sum_lbl_br),
            array('v' => '', 's' => $s_sum_val_cb),
            array('v' => '', 's' => $s_sum_val_db),
            array('v' => '', 's' => $s_blank),
            array('v' => '', 's' => $s_blank),
            array('v' => '', 's' => $s_blank),
        ));
        $x->mergeCells('A' . $tot_r1, 'B' . $r);
        $x->mergeCells('C' . $tot_r1, 'C' . $r);
        $x->mergeCells('D' . $tot_r1, 'D' . $r);
        $r++;

        // Two spacer rows above 3-column approval footer
        for ($sp = 0; $sp < 2; $sp++) {
            $x->setRowHeight($r, 18);
            $x->writeRowStyled(array_fill(0, 7, array('v' => '', 's' => $s_blank)));
            $r++;
        }

        // Signatory Top Row: Prepared by | Checked by | Approved by
        $sig = $sheet['signatories'];
        $chk_lbl = trim((string)$sig['checked_by']);
        $chk_top = (stripos($chk_lbl, 'Checked by') === 0) ? $chk_lbl : ('Checked by ' . $chk_lbl);

        $x->setRowHeight($r, 16);
        $x->writeRowStyled(array(
            array('v' => 'Prepared by ' . trim((string)$sig['prepared_by']), 's' => $s_sig_head),
            array('v' => '',                                                 's' => $s_sig_head),
            array('v' => $chk_top,                                           's' => $s_sig_head),
            array('v' => '',                                                 's' => $s_sig_head),
            array('v' => '',                                                 's' => $s_sig_head),
            array('v' => '',                                                 's' => $s_blank),
            array('v' => 'Approved by ' . trim((string)$sig['approved_by']), 's' => $s_sig_head),
        ));
        $x->mergeCells('A' . $r, 'B' . $r);
        $x->mergeCells('C' . $r, 'F' . $r);
        $r++;

        // Signature space row
        $x->setRowHeight($r, 22);
        $x->writeRowStyled(array_fill(0, 7, array('v' => '', 's' => $s_blank)));
        $r++;

        // Signatory Bottom Title Row: Assistant Admin HR | ........ | HR Manager
        $chk_sub = trim((string)$sig['checked_title']);
        $x->setRowHeight($r, 16);
        $x->writeRowStyled(array(
            array('v' => trim((string)$sig['prepared_title']), 's' => $s_sig_role),
            array('v' => '',                                   's' => $s_sig_role),
            array('v' => $chk_sub !== '' ? $chk_sub : '........................................................', 's' => $chk_sub !== '' ? $s_sig_role : $s_sig_dots),
            array('v' => '',                                   's' => $chk_sub !== '' ? $s_sig_role : $s_sig_dots),
            array('v' => '',                                   's' => $chk_sub !== '' ? $s_sig_role : $s_sig_dots),
            array('v' => '',                                   's' => $s_blank),
            array('v' => trim((string)$sig['approved_title']), 's' => $s_sig_role),
        ));
        $x->mergeCells('A' . $r, 'B' . $r);
        $x->mergeCells('C' . $r, 'F' . $r);

        // Configure 1-page A4 Portrait printing out of the box
        $x->setPageSetup(array(
            'orientation' => 'portrait',
            'paperSize'   => 9, // A4
            'fitToPage'   => TRUE,
            'fitToWidth'  => 1,
            'fitToHeight' => 1,
            'left'        => 0.45,
            'right'       => 0.45,
            'top'         => 0.50,
            'bottom'      => 0.50,
            'header'      => 0.20,
            'footer'      => 0.20,
            'printArea'   => 'A1:G' . $r,
        ));
    }

    private function _format_dotted_field($label, $value) {
        $val = trim((string)$value);
        if ($val === '') {
            return $label . str_repeat('.', 92);
        }
        $dots = max(12, 86 - strlen($label) - strlen($val));
        return $label . ' ' . $val . ' ' . str_repeat('.', $dots);
    }
}
