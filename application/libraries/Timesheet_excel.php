<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Timesheet_excel — builds native Microsoft Excel `.xlsx` workbooks matching
 * the exact AML Individual Attendance Sheet template (`TIME SHEET AML.pdf`),
 * complete with embedded AML corporate ribbon banner, `#92D050` green & `#9BC2E6`
 * sky-blue headers/subtotals, Monday–Sunday weekly blocks, red bold Sundays,
 * live `=SUM(...)` Excel formulas, `TOTAL DAYS WORKED` box, 3-column approval
 * footer, and A4 Portrait 1-page print configuration.
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

        // Styles matching Excel 2019 template colors & fonts
        $s_blank       = '';
        $s_title_top   = 'bold|italic|fontName:Times New Roman|fontSize:13|align:center|valign:center|bg:70AD47|borderT:thin|borderL:thin|borderR:thin';
        $s_title_sub   = 'bold|italic|fontName:Times New Roman|fontSize:10|align:center|valign:center|bg:70AD47|borderB:thin|borderL:thin|borderR:thin';
        $s_meta        = 'bold|italic|fontName:Times New Roman|fontSize:11.5|align:left|valign:center';

        $s_grn_left_hd = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:92D050|borderL:thin|borderR:thin|borderT:thin|borderB:medium';
        $s_grn_days_hd = 'bold|fontName:Calibri|fontSize:9|align:left|valign:center|bg:92D050|borderL:thin|borderR:thin|borderT:thin|borderB:medium';
        $s_grn_sig_top = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:92D050|borderL:medium|borderR:thin|borderT:medium|borderB:thin';
        $s_grn_obs_top = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:92D050|borderL:thin|borderR:medium|borderT:medium|borderB:thin';

        $s_day_lbl     = 'fontName:Calibri|fontSize:9|align:left|valign:center|border:thin';
        $s_day_ctr     = 'fontName:Calibri|fontSize:9|align:center|valign:center|border:thin';
        $s_day_num     = 'fontName:Calibri|fontSize:9|align:center|valign:center|border:thin|numFmt:0.##';
        $s_day_obs     = 'fontName:Calibri|fontSize:9|align:left|valign:center|borderL:thin|borderR:medium|borderT:thin|borderB:thin';
        $s_sun_lbl     = 'bold|fontName:Calibri|fontSize:9|fg:FF0000|align:left|valign:center|border:thin';
        $s_sun_ctr     = 'bold|fontName:Calibri|fontSize:9|fg:FF0000|align:center|valign:center|border:thin';

        $s_blu_tot_lbl = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|border:thin';
        $s_blu_tot_val = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|border:thin|numFmt:0.##';
        $s_blu_sig     = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|border:thin';
        $s_blu_obs     = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|borderL:thin|borderR:medium|borderT:thin|borderB:thin';
        $s_blu_days_hd = 'bold|fontName:Calibri|fontSize:9|align:left|valign:center|bg:9BC2E6|border:thin';
        $s_blu_left_hd = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|bg:9BC2E6|border:thin';

        $s_sum_lbl     = 'bold|fontName:Calibri|fontSize:8.5|align:center|valign:center|bg:92D050|borderL:thin|borderR:thin|borderT:thin|borderB:medium';
        $s_sum_val_c   = 'bold|fontName:Calibri|fontSize:9.5|align:center|valign:center|borderL:thin|borderR:thin|borderT:thin|borderB:medium';
        $s_sum_val_d   = 'bold|fontName:Calibri|fontSize:9|align:center|valign:center|borderL:thin|borderR:medium|borderT:thin|borderB:medium|numFmt:0.##';

        $s_sig_head    = 'bold|italic|underline|fontName:Calibri|fontSize:9|align:center|valign:center';
        $s_sig_role    = 'bold|italic|fontName:Calibri|fontSize:9|align:center|valign:center';
        $s_sig_dots    = 'fontName:Calibri|fontSize:9|align:center|valign:center';

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

        // Rows 3–4: Green Title Box across B3:F4
        $x->setRowHeight($r, 20);
        $x->writeRowStyled(array(
            array('v' => '', 's' => $s_blank),
            array('v' => 'INDIVIDUAL ATTENDANCE SHEET', 's' => $s_title_top),
            array('v' => '', 's' => $s_title_top),
            array('v' => '', 's' => $s_title_top),
            array('v' => '', 's' => $s_title_top),
            array('v' => '', 's' => $s_title_top),
            array('v' => '', 's' => $s_blank),
        ));
        $x->mergeCells('B' . $r, 'F' . $r);
        $r++;

        $x->setRowHeight($r, 16);
        $x->writeRowStyled(array(
            array('v' => '', 's' => $s_blank),
            array('v' => (string)$sheet['month_heading'], 's' => $s_title_sub),
            array('v' => '', 's' => $s_title_sub),
            array('v' => '', 's' => $s_title_sub),
            array('v' => '', 's' => $s_title_sub),
            array('v' => '', 's' => $s_title_sub),
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
            array('v' => '',         's' => $s_grn_sig_top),
            array('v' => '',         's' => $s_grn_obs_top),
        ));
        $x->mergeCells('F' . $hd_top_r, 'F' . $r);
        $x->mergeCells('G' . $hd_top_r, 'G' . $r);
        $r++;

        // Weekly blocks + inter-week stepped Sky-Blue (#9BC2E6) bands
        $grid = $sheet['grid'];
        $weeks = isset($grid['weeks']) ? $grid['weeks'] : array();
        $num_weeks = count($weeks);
        $all_day_ranges = array();

        foreach ($weeks as $wi => $wk) {
            $wk_first_row = $r;
            foreach ($wk['days'] as $day) {
                $is_sun = !empty($day['is_sunday']);
                $h_val = ($day['hours'] !== NULL && $day['hours'] > 0) ? (float)$day['hours'] : '';

                $x->setRowHeight($r, 15.2);
                $x->writeRowStyled(array(
                    array('v' => $day['day_name'],    's' => $is_sun ? $s_sun_lbl : $s_day_lbl),
                    array('v' => $day['date_label'],  's' => $is_sun ? $s_sun_ctr : $s_day_ctr),
                    array('v' => $day['time_in'],     's' => $s_day_ctr),
                    array('v' => $day['time_out'],    's' => $s_day_ctr),
                    array('v' => $h_val,              's' => $s_day_num),
                    array('v' => '',                  's' => $s_day_ctr),
                    array('v' => $day['observation'], 's' => $s_day_obs),
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
                    array('v' => 'TOTAL',       's' => $s_blu_tot_lbl),
                    array('v' => '',            's' => $s_blu_tot_lbl),
                    array('v' => $sum_expr,     's' => $s_blu_tot_val),
                    array('v' => 'SIGNATURE',   's' => $s_blu_sig),
                    array('v' => 'OBSERVATION', 's' => $s_blu_obs),
                ));
                $x->mergeCells('C' . $r, 'D' . $r);
                $r++;

                // Band row 2: A..E white notch, F..G middle of merge
                $x->setRowHeight($r, 15.2);
                $x->writeRowStyled(array(
                    array('v' => '', 's' => $s_blank),
                    array('v' => '', 's' => $s_blank),
                    array('v' => '', 's' => $s_blank),
                    array('v' => '', 's' => $s_blank),
                    array('v' => '', 's' => $s_blank),
                    array('v' => '', 's' => $s_blu_sig),
                    array('v' => '', 's' => $s_blu_obs),
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
                    array('v' => '',         's' => $s_blu_sig),
                    array('v' => '',         's' => $s_blu_obs),
                ));
                $x->mergeCells('F' . $b1_r, 'F' . $r);
                $x->mergeCells('G' . $b1_r, 'G' . $r);
                $r++;
            }
        }

        // Bottom 2-row summary block: TOTAL DAYS WORKED (A:B merged) + C + D
        $tot_r1 = $r;
        $days_val = !empty($grid['days_worked']) && (int)$grid['days_worked'] > 0 ? (int)$grid['days_worked'] : '';
        $tot_h_expr = !empty($all_day_ranges)
            ? ('=IF(SUM(' . implode(',', $all_day_ranges) . ')>0,SUM(' . implode(',', $all_day_ranges) . '),"")')
            : '';

        $x->setRowHeight($r, 15.2);
        $x->writeRowStyled(array(
            array('v' => 'TOTAL DAYS WORKED', 's' => $s_sum_lbl),
            array('v' => '',                  's' => $s_sum_lbl),
            array('v' => $days_val,           's' => $s_sum_val_c),
            array('v' => $tot_h_expr,         's' => $s_sum_val_d),
            array('v' => '',                  's' => $s_blank),
            array('v' => '',                  's' => $s_blank),
            array('v' => '',                  's' => $s_blank),
        ));
        $r++;

        $x->setRowHeight($r, 15.2);
        $x->writeRowStyled(array(
            array('v' => '', 's' => $s_sum_lbl),
            array('v' => '', 's' => $s_sum_lbl),
            array('v' => '', 's' => $s_sum_val_c),
            array('v' => '', 's' => $s_sum_val_d),
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
