<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once dirname(__FILE__) . '/Pdf_writer.php';

/**
 * Timesheet_pdf — native, zero-dependency A4 Portrait PDF generator reproducing
 * the exact AML Individual Attendance Sheet design (`TIME SHEET AML.pdf`) from
 * live WMS attendance records.
 *
 * Works identically on Windows XAMPP, cPanel shared hosting (with or without
 * ext-gd / exec()), Linux Docker, and CI PHP 8.2–8.4 runtimes.
 */
#[AllowDynamicProperties]
class Timesheet_pdf {

    // Exact A4 page dimensions from TIME SHEET AML.pdf (in PDF points, 72 pt/in)
    const PAGE_W = 595.20;
    const PAGE_H = 841.68;

    // Exact RGB palette extracted from TIME SHEET AML.pdf
    const CLR_TITLE_GREEN = array(0.439, 0.678, 0.278); // #70AD47 title banner fill
    const CLR_HDR_GREEN   = array(0.573, 0.816, 0.314); // #92D050 table green header & total days box
    const CLR_WEEK_BLUE   = array(0.608, 0.761, 0.902); // #9BC2E6 inter-week subtotal & header band
    const CLR_WHITE       = array(1.000, 1.000, 1.000);
    const CLR_BLACK       = array(0.000, 0.000, 0.000);
    const CLR_SUNDAY_RED  = array(1.000, 0.000, 0.000); // #FF0000 Sunday rows

    // Exact column X boundaries from TIME SHEET AML.pdf (total width = 461.74 pt)
    private static $COL_X = array(
        42.24,   // 0: DAYS left
        93.60,   // 1: DATE left
        139.46,  // 2: TIME IN left
        180.86,  // 3: TIME OUT left
        222.29,  // 4: TOTAL H left
        259.61,  // 5: SIGNATURE left
        381.79,  // 6: OBSERVATION left
        503.98,  // 7: OBSERVATION right
    );

    private $sheets;
    private $bannerPath;
    private static $bannerCache = array();

    /**
     * @param array       $sheets     list of sheet payloads from Timesheet_model
     * @param string|null $bannerPath optional path override to AML banner PNG
     */
    public function __construct(array $sheets, $bannerPath = NULL) {
        $this->sheets = $sheets;
        if ($bannerPath !== NULL) {
            $this->bannerPath = $bannerPath;
        } else {
            $root = defined('FCPATH') ? FCPATH : (realpath(dirname(__FILE__) . '/../..') . '/');
            $this->bannerPath = rtrim($root, '/\\') . '/uploads/aml_timesheet_banner.png';
        }
    }

    /**
     * Render all queued sheets into a single PDF binary string (1 A4 page per sheet).
     *
     * @return string raw PDF bytes starting with %PDF-1.4
     */
    public function render() {
        $pdf = new Pdf_writer();
        $banner = $this->_load_banner();

        foreach ($this->sheets as $sheet) {
            $pdf->add_page(self::PAGE_W, self::PAGE_H);
            $this->_draw_sheet($pdf, $sheet, $banner);
        }

        return $pdf->output();
    }

    /* ── Coordinate helpers (top-down origin matching PDF inspection) ── */

    private function _box(Pdf_writer $pdf, $x, $y_top, $w, $h, $mode, $fill, $stroke = array(0, 0, 0), $lw = 0.72) {
        $pdf->rect($x, self::PAGE_H - $y_top - $h, $w, $h, $mode, $fill, $stroke, $lw);
    }

    private function _hline(Pdf_writer $pdf, $x1, $x2, $y_top, $lw = 0.72) {
        $pdf->line($x1, self::PAGE_H - $y_top, $x2, self::PAGE_H - $y_top, $lw, self::CLR_BLACK);
    }

    private function _vline(Pdf_writer $pdf, $x, $y_top1, $y_top2, $lw = 0.72) {
        $pdf->line($x, self::PAGE_H - $y_top1, $x, self::PAGE_H - $y_top2, $lw, self::CLR_BLACK);
    }

    private function _txt(Pdf_writer $pdf, $x, $y_baseline_top, $text, $font = 'F1', $size = 8.64, $color = array(0, 0, 0), $align = 'L', $max_w = NULL) {
        $pdf->text($x, self::PAGE_H - $y_baseline_top, $text, $font, $size, $color, $align, $max_w);
    }

    /* ── Single A4 page renderer matching TIME SHEET AML.pdf ─────────── */

    private function _draw_sheet(Pdf_writer $pdf, array $sheet, $banner) {
        $cx = self::$COL_X;
        $grid = $sheet['grid'];
        $weeks = isset($grid['weeks']) ? $grid['weeks'] : array();

        // 1. Corporate AML top ribbon banner [43.57, 93.43, 494.53, 140.59]
        if ($banner !== NULL) {
            $bw = 450.96;
            $bh = 47.16;
            $bx = 43.57;
            $by = 93.43;
            $pdf->image_rgb($banner['raw'], $banner['w'], $banner['h'], $bx, self::PAGE_H - $by - $bh, $bw, $bh);
        }

        // 2. Green bordered title box [135.20, 146.04, w=276.46, h=34.56]
        $this->_box($pdf, 135.20, 146.04, 276.46, 34.56, 'FD', self::CLR_TITLE_GREEN, self::CLR_BLACK, 0.85);
        $this->_txt($pdf, 135.20, 161.80, 'INDIVIDUAL ATTENDANCE SHEET', 'F4', 12.96, self::CLR_BLACK, 'C', 276.46);
        $this->_txt($pdf, 135.20, 175.10, (string)$sheet['month_heading'], 'F4', 10.10, self::CLR_BLACK, 'C', 276.46);

        // 3. Employee header block (Nom / Position / Site with dotted rules + live values)
        $this->_draw_field_line($pdf, 91.50, 193.20, 'Nom:', (string)$sheet['full_name'], 454.30);
        $this->_draw_field_line($pdf, 91.50, 206.50, 'Position:', (string)$sheet['position'], 454.30);
        $this->_draw_field_line($pdf, 91.50, 219.90, 'Site:', (string)$sheet['site'], 454.30);

        // 4. Compute exact row rhythm so half-month sheets use verbatim 15.12 pt
        //    height while full-month single-page sheets scale cleanly onto 1 A4 page.
        $day_rows  = 0;
        foreach ($weeks as $wk) $day_rows += count($wk['days']);
        $band_rows = max(0, count($weeks) - 1) * 3;
        $tot_units = $day_rows + $band_rows + 2; // +2 for TOTAL DAYS WORKED box

        $hdr_right_top = 235.10;
        $hdr_left_top  = 244.22;
        $body_top      = 257.54;

        $row_h = 15.12;
        if ($tot_units > 26) {
            // Single-page compact full-month mode (~43-45 units): scale cleanly above footer
            $row_h = min(15.12, (735.0 - $body_top) / max(1, $tot_units));
        }
        // Helvetica 7.60pt matches Calibri 8.64pt cap-height & glyph advance exactly
        $font_sz    = ($row_h < 12.5) ? 6.60 : 7.60;
        $tot_lbl_sz = ($row_h < 12.5) ? 6.50 : 7.20;
        $base_off   = $row_h * 0.71;

        // 5. Stepped Top Green Header (#92D050)
        //    Right columns (SIGNATURE, OBSERVATION) rise 9.12 pt higher than left 5 columns
        $this->_box($pdf, $cx[5], $hdr_right_top, $cx[7] - $cx[5], $body_top - $hdr_right_top, 'F', self::CLR_HDR_GREEN, self::CLR_HDR_GREEN, 0);
        $this->_box($pdf, $cx[0], $hdr_left_top,  $cx[5] - $cx[0], $body_top - $hdr_left_top,  'F', self::CLR_HDR_GREEN, self::CLR_HDR_GREEN, 0);

        // Stepped header borders matching TIME SHEET AML.pdf
        $this->_hline($pdf, $cx[5], $cx[7], $hdr_right_top, 1.40);
        $this->_vline($pdf, $cx[5], $hdr_right_top, $body_top, 1.40);
        $this->_vline($pdf, $cx[6], $hdr_right_top, $body_top, 0.72);
        $this->_vline($pdf, $cx[7], $hdr_right_top, $body_top, 1.40);
        $this->_hline($pdf, $cx[5], $cx[7], $body_top, 0.72);

        $this->_hline($pdf, $cx[0], $cx[5], $hdr_left_top, 0.72);
        $this->_hline($pdf, $cx[0], $cx[5], $body_top, 1.40);
        for ($c = 0; $c <= 4; $c++) {
            $this->_vline($pdf, $cx[$c], $hdr_left_top, $body_top, 0.72);
        }

        // Header text labels
        $this->_draw_left_headers($pdf, $hdr_left_top + ($body_top - $hdr_left_top) * 0.75, $font_sz);
        $this->_txt($pdf, $cx[5], $hdr_right_top + 14.00, 'SIGNATURE',   'F2', 7.80, self::CLR_BLACK, 'C', $cx[6] - $cx[5]);
        $this->_txt($pdf, $cx[6], $hdr_right_top + 14.00, 'OBSERVATION', 'F2', 7.80, self::CLR_BLACK, 'C', $cx[7] - $cx[6]);

        // 6. Weekly blocks + inter-week stepped Sky-Blue (#9BC2E6) bands
        $y = $body_top;
        $num_weeks = count($weeks);

        foreach ($weeks as $wi => $wk) {
            $wk_top = $y;
            foreach ($wk['days'] as $day) {
                $is_sun = !empty($day['is_sunday']);
                $clr    = $is_sun ? self::CLR_SUNDAY_RED : self::CLR_BLACK;
                $fnt    = $is_sun ? 'F2' : 'F1';
                $by_txt = $y + $base_off;

                $day_sz = ($day['day_name'] === 'WEDNESDAY') ? min($font_sz, 7.20) : $font_sz;
                $this->_txt($pdf, $cx[0] + 2.2, $by_txt, $day['day_name'],   $fnt, $day_sz,  $clr, 'L');
                $this->_txt($pdf, $cx[1],       $by_txt, $day['date_label'], $fnt, $font_sz, $clr, 'C', $cx[2] - $cx[1]);

                if ($day['time_in'] !== '') {
                    $this->_txt($pdf, $cx[2], $by_txt, $day['time_in'], 'F1', $font_sz, self::CLR_BLACK, 'C', $cx[3] - $cx[2]);
                }
                if ($day['time_out'] !== '') {
                    $this->_txt($pdf, $cx[3], $by_txt, $day['time_out'], 'F1', $font_sz, self::CLR_BLACK, 'C', $cx[4] - $cx[3]);
                }
                if ($day['hours_label'] !== '') {
                    $this->_txt($pdf, $cx[4], $by_txt, $day['hours_label'], 'F1', $font_sz, self::CLR_BLACK, 'C', $cx[5] - $cx[4]);
                }
                if ($day['observation'] !== '') {
                    $obs = $this->_fit_text((string)$day['observation'], $font_sz, ($cx[7] - $cx[6]) - 6.0);
                    $this->_txt($pdf, $cx[6] + 3.0, $by_txt, $obs, 'F1', $font_sz, self::CLR_BLACK, 'L');
                }

                $y += $row_h;
                $this->_hline($pdf, $cx[0], $cx[7], $y, 0.72);
            }

            // Vertical column lines across this week's day rows
            for ($c = 0; $c <= 6; $c++) {
                $this->_vline($pdf, $cx[$c], $wk_top, $y, 0.72);
            }
            $this->_vline($pdf, $cx[7], $wk_top, $y, 1.40);

            // Inter-week 3-row stepped Sky-Blue (#9BC2E6) band (between week $wi and $wi+1)
            if ($wi < $num_weeks - 1) {
                $b0 = $y;
                $b1 = $y + $row_h;       // bottom of TOTAL subtotal row
                $b2 = $y + 2 * $row_h;   // top of repeated left headers
                $b3 = $y + 3 * $row_h;   // bottom of repeated headers

                // Fills: row 1 C..G blue, row 2 F..G blue, row 3 A..G blue (leaving A..B row 1 & A..E row 2 white notch!)
                $this->_box($pdf, $cx[2], $b0, $cx[7] - $cx[2], $row_h,     'F', self::CLR_WEEK_BLUE, self::CLR_WEEK_BLUE, 0);
                $this->_box($pdf, $cx[5], $b1, $cx[7] - $cx[5], $row_h,     'F', self::CLR_WEEK_BLUE, self::CLR_WEEK_BLUE, 0);
                $this->_box($pdf, $cx[0], $b2, $cx[7] - $cx[0], $row_h,     'F', self::CLR_WEEK_BLUE, self::CLR_WEEK_BLUE, 0);

                // Subtotal row borders & values (C..D merged TOTAL, E weekly sum)
                $this->_vline($pdf, $cx[2], $b0, $b1, 0.72);
                $this->_vline($pdf, $cx[4], $b0, $b1, 0.72);
                $this->_vline($pdf, $cx[5], $b0, $b3, 0.72);
                $this->_vline($pdf, $cx[6], $b0, $b3, 0.72);
                $this->_vline($pdf, $cx[7], $b0, $b3, 1.40);
                $this->_hline($pdf, $cx[2], $cx[5], $b1, 0.72);

                $this->_txt($pdf, $cx[2], $b0 + $base_off, 'TOTAL', 'F2', $font_sz, self::CLR_BLACK, 'C', $cx[4] - $cx[2]);
                if ($wk['hours_label'] !== '') {
                    $this->_txt($pdf, $cx[4], $b0 + $base_off, $wk['hours_label'], 'F2', $font_sz, self::CLR_BLACK, 'C', $cx[5] - $cx[4]);
                }

                // Merged right-hand SIGNATURE & OBSERVATION across all 3 band rows
                $mid_bl = $b0 + 1.5 * $row_h + 2.8;
                $this->_txt($pdf, $cx[5], $mid_bl, 'SIGNATURE',   'F2', 7.80, self::CLR_BLACK, 'C', $cx[6] - $cx[5]);
                $this->_txt($pdf, $cx[6], $mid_bl, 'OBSERVATION', 'F2', 7.80, self::CLR_BLACK, 'C', $cx[7] - $cx[6]);

                // Next-week left header row borders & labels (A..E on row 3 of band)
                $this->_hline($pdf, $cx[0], $cx[5], $b2, 0.72);
                $this->_hline($pdf, $cx[0], $cx[7], $b3, 0.72);
                for ($c = 0; $c <= 4; $c++) {
                    $this->_vline($pdf, $cx[$c], $b2, $b3, 0.72);
                }
                $this->_draw_left_headers($pdf, $b2 + $base_off, $font_sz);

                $y = $b3;
            }
        }

        // 7. Bottom Green Summary Box: TOTAL DAYS WORKED [A..B] + boxed cells [C] & [D]
        $sum_h   = 2 * $row_h;
        $sum_bot = $y + $sum_h;

        $this->_box($pdf, $cx[0], $y, $cx[2] - $cx[0], $sum_h, 'F', self::CLR_HDR_GREEN, self::CLR_HDR_GREEN, 0);
        $this->_box($pdf, $cx[2], $y, $cx[4] - $cx[2], $sum_h, 'F', self::CLR_WHITE,     self::CLR_WHITE,     0);

        // Thick outer border on bottom/right of summary box + crisp internal dividers matching TIME SHEET AML.pdf
        $this->_hline($pdf, $cx[0], $cx[4], $y,       0.72);
        $this->_hline($pdf, $cx[0], $cx[4], $sum_bot, 1.40);
        $this->_vline($pdf, $cx[0], $y, $sum_bot, 0.72);
        $this->_vline($pdf, $cx[2], $y, $sum_bot, 0.72);
        $this->_vline($pdf, $cx[3], $y, $sum_bot, 0.72);
        $this->_vline($pdf, $cx[4], $y, $sum_bot, 1.40);

        $sum_bl = $y + $sum_h * 0.56;
        $this->_txt($pdf, $cx[0], $sum_bl, 'TOTAL DAYS WORKED', 'F2', $tot_lbl_sz, self::CLR_BLACK, 'C', $cx[2] - $cx[0]);
        if (!empty($grid['days_worked']) && (int)$grid['days_worked'] > 0) {
            $this->_txt($pdf, $cx[2], $sum_bl, (string)(int)$grid['days_worked'], 'F2', 9.0, self::CLR_BLACK, 'C', $cx[3] - $cx[2]);
        }
        if (!empty($grid['total_hours_label'])) {
            $this->_txt($pdf, $cx[3], $sum_bl, $grid['total_hours_label'] . ' h', 'F2', 8.2, self::CLR_BLACK, 'C', $cx[4] - $cx[3]);
        }

        // 8. 3-Column Sign-Off Footer (exact coordinates y=671 / y=703 when standard, gracefully pushed below tall grids)
        $sig = $sheet['signatories'];
        $sig_y1 = max(671.00, $sum_bot + 44.0);
        $sig_y2 = $sig_y1 + 32.28;

        // Left: Prepared by ... / Assistant Admin HR
        $prep_top = 'Prepared by ' . trim((string)$sig['prepared_by']);
        $this->_draw_underlined_centered($pdf, 50.0, 172.0, $sig_y1, $prep_top, 'F3', 7.80);
        $this->_txt($pdf, 50.0, $sig_y2, trim((string)$sig['prepared_title']), 'F3', 7.80, self::CLR_BLACK, 'C', 122.0);

        // Centre: Checked by Supervisor's / dotted line (or configured subtitle)
        $chk_lbl = trim((string)$sig['checked_by']);
        $chk_top = (stripos($chk_lbl, 'Checked by') === 0) ? $chk_lbl : ('Checked by ' . $chk_lbl);
        $this->_draw_underlined_centered($pdf, 215.0, 348.0, $sig_y1, $chk_top, 'F3', 7.80);
        $chk_sub = trim((string)$sig['checked_title']);
        if ($chk_sub !== '') {
            $this->_txt($pdf, 215.0, $sig_y2, $chk_sub, 'F3', 7.80, self::CLR_BLACK, 'C', 133.0);
        } else {
            $this->_txt($pdf, 215.0, $sig_y2, '..........................................................', 'F1', 7.80, self::CLR_BLACK, 'C', 133.0);
        }

        // Right: Approved by ... / HR Manager
        $app_top = 'Approved by ' . trim((string)$sig['approved_by']);
        $this->_draw_underlined_centered($pdf, 376.0, 514.0, $sig_y1, $app_top, 'F3', 7.80);
        $this->_txt($pdf, 376.0, $sig_y2, trim((string)$sig['approved_title']), 'F3', 7.80, self::CLR_BLACK, 'C', 138.0);
    }

    /** Draw DAYS | DATE | TIME IN | TIME OUT | TOTAL H header texts across columns 0..4. */
    private function _draw_left_headers(Pdf_writer $pdf, $baseline_y, $size) {
        $cx = self::$COL_X;
        $this->_txt($pdf, $cx[0] + 2.2, $baseline_y, 'DAYS',     'F2', $size, self::CLR_BLACK, 'L');
        $this->_txt($pdf, $cx[1],       $baseline_y, 'DATE',     'F2', $size, self::CLR_BLACK, 'C', $cx[2] - $cx[1]);
        $this->_txt($pdf, $cx[2],       $baseline_y, 'TIME IN',  'F2', $size, self::CLR_BLACK, 'C', $cx[3] - $cx[2]);
        $this->_txt($pdf, $cx[3],       $baseline_y, 'TIME OUT', 'F2', $size, self::CLR_BLACK, 'C', $cx[4] - $cx[3]);
        $this->_txt($pdf, $cx[4],       $baseline_y, 'TOTAL H',  'F2', $size, self::CLR_BLACK, 'C', $cx[5] - $cx[4]);
    }

    /** Draw `Label:  Value ....................................` cleanly without dots colliding through letters. */
    private function _draw_field_line(Pdf_writer $pdf, $x_start, $y_baseline, $label, $value, $x_end) {
        $val = trim((string)$value);
        $prefix = ($val !== '') ? ($label . ' ' . $val . ' ') : $label;
        $max_prefix_w = max(80.0, ($x_end - $x_start) - 30.0);
        $prefix = $this->_fit_text($prefix, 11.20, $max_prefix_w, TRUE);
        $this->_txt($pdf, $x_start, $y_baseline, $prefix, 'F4', 11.20, self::CLR_BLACK, 'L');

        // Times-BoldItalic has ~0.91 advance width relative to Helvetica-Bold
        $used_w = Pdf_writer::text_width($prefix, 11.20, TRUE) * 0.91 + 1.5;
        $dot_x  = $x_start + $used_w;
        $avail  = $x_end - $dot_x;
        if ($avail > 8.0) {
            $dot_w  = Pdf_writer::text_width('.', 11.20, TRUE) * 0.91;
            $n_dots = max(3, (int)floor($avail / max(1.0, $dot_w)));
            $this->_txt($pdf, $dot_x, $y_baseline, str_repeat('.', $n_dots), 'F4', 11.20, self::CLR_BLACK, 'L');
        }
    }

    /** Draw centered bold-italic text with a crisp underline matching Excel's single accounting underline. */
    private function _draw_underlined_centered(Pdf_writer $pdf, $x1, $x2, $y_baseline, $text, $font, $size) {
        $box_w = $x2 - $x1;
        $this->_txt($pdf, $x1, $y_baseline, $text, $font, $size, self::CLR_BLACK, 'C', $box_w);
        $tw = min($box_w, Pdf_writer::text_width($text, $size, TRUE));
        $ux = $x1 + ($box_w - $tw) / 2.0;
        $this->_hline($pdf, $ux, $ux + $tw, $y_baseline + 1.35, 0.60);
    }

    private function _fit_text($text, $size, $max_w, $bold = FALSE) {
        if (Pdf_writer::text_width($text, $size, $bold) <= $max_w) return $text;
        $len = mb_strlen($text, 'UTF-8');
        while ($len > 1) {
            $len--;
            $sub = rtrim(mb_substr($text, 0, $len, 'UTF-8')) . '...';
            if (Pdf_writer::text_width($sub, $size, $bold) <= $max_w) return $sub;
        }
        return $text;
    }

    /* ── Pure-PHP + GD fallback PNG loader (zero ext-gd dependency!) ── */

    private function _load_banner() {
        $p = (string)$this->bannerPath;
        if ($p === '' || !is_file($p)) return NULL;
        if (isset(self::$bannerCache[$p])) return self::$bannerCache[$p];

        $decoded = self::decode_png_to_rgb($p);
        if ($decoded === NULL && function_exists('imagecreatefromstring')) {
            $bytes = @file_get_contents($p);
            $im = ($bytes !== FALSE) ? @imagecreatefromstring($bytes) : FALSE;
            if ($im) {
                $w = imagesx($im);
                $h = imagesy($im);
                $bg = imagecreatetruecolor($w, $h);
                $white = imagecolorallocate($bg, 255, 255, 255);
                imagefill($bg, 0, 0, $white);
                imagealphablending($bg, TRUE);
                imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
                imagedestroy($im);
                $raw = '';
                for ($y = 0; $y < $h; $y++) {
                    for ($x = 0; $x < $w; $x++) {
                        $rgb = imagecolorat($bg, $x, $y);
                        $raw .= chr(($rgb >> 16) & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr($rgb & 0xFF);
                    }
                }
                imagedestroy($bg);
                $decoded = array('w' => $w, 'h' => $h, 'raw' => $raw);
            }
        }

        if ($decoded !== NULL) {
            self::$bannerCache[$p] = $decoded;
        }
        return $decoded;
    }

    /**
     * Pure-PHP 8-bit non-interlaced PNG -> 24-bit RGB decoder using zlib.
     * Supports ColorType 2 (RGB), 6 (RGBA composited over white), 3 (Palette),
     * 0 (Grayscale), and 4 (Grayscale+Alpha) without requiring `ext-gd`.
     *
     * @param  string $path
     * @return array{w:int,h:int,raw:string}|null
     */
    public static function decode_png_to_rgb($path) {
        if (!function_exists('gzuncompress') || !is_file($path)) return NULL;
        $bin = @file_get_contents($path);
        if ($bin === FALSE || strlen($bin) < 33 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return NULL;
        }

        $len = strlen($bin);
        $pos = 8;
        $w = 0; $h = 0; $depth = 0; $ctype = 0; $interlace = 0;
        $plte = '';
        $idat = '';

        while ($pos + 8 <= $len) {
            $hdr  = unpack('Nclen/a4ctype', substr($bin, $pos, 8));
            $clen = (int)$hdr['clen'];
            $type = $hdr['ctype'];
            $pos += 8;
            if ($pos + $clen + 4 > $len) break;
            $chunk = substr($bin, $pos, $clen);
            $pos += $clen + 4; // skip CRC

            if ($type === 'IHDR' && $clen >= 13) {
                $ih = unpack('Nw/Nh/Cdepth/Cctype/Ccomp/Cfilt/Cinter', $chunk);
                $w = (int)$ih['w'];
                $h = (int)$ih['h'];
                $depth = (int)$ih['depth'];
                $ctype = (int)$ih['ctype'];
                $interlace = (int)$ih['inter'];
            } elseif ($type === 'PLTE') {
                $plte = $chunk;
            } elseif ($type === 'IDAT') {
                $idat .= $chunk;
            } elseif ($type === 'IEND') {
                break;
            }
        }

        if ($w < 1 || $h < 1 || $depth !== 8 || $interlace !== 0 || $idat === '') {
            return NULL;
        }

        $channels_map = array(0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4);
        if (!isset($channels_map[$ctype])) return NULL;
        $bpp    = $channels_map[$ctype];
        $stride = $w * $bpp;

        $inflated = @gzuncompress($idat);
        if ($inflated === FALSE || strlen($inflated) < ($stride + 1) * $h) {
            return NULL;
        }

        $prev = str_repeat("\x00", $stride);
        $raw  = '';
        $off  = 0;

        for ($y = 0; $y < $h; $y++) {
            $ft   = ord($inflated[$off]);
            $line = substr($inflated, $off + 1, $stride);
            $off += 1 + $stride;

            if ($ft === 1) { // Sub
                for ($i = $bpp; $i < $stride; $i++) {
                    $line[$i] = chr((ord($line[$i]) + ord($line[$i - $bpp])) & 0xFF);
                }
            } elseif ($ft === 2) { // Up
                for ($i = 0; $i < $stride; $i++) {
                    $line[$i] = chr((ord($line[$i]) + ord($prev[$i])) & 0xFF);
                }
            } elseif ($ft === 3) { // Average
                for ($i = 0; $i < $stride; $i++) {
                    $a = ($i >= $bpp) ? ord($line[$i - $bpp]) : 0;
                    $b = ord($prev[$i]);
                    $line[$i] = chr((ord($line[$i]) + (($a + $b) >> 1)) & 0xFF);
                }
            } elseif ($ft === 4) { // Paeth
                for ($i = 0; $i < $stride; $i++) {
                    $a  = ($i >= $bpp) ? ord($line[$i - $bpp]) : 0;
                    $b  = ord($prev[$i]);
                    $c  = ($i >= $bpp) ? ord($prev[$i - $bpp]) : 0;
                    $p  = $a + $b - $c;
                    $pa = abs($p - $a);
                    $pb = abs($p - $b);
                    $pc = abs($p - $c);
                    $pr = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
                    $line[$i] = chr((ord($line[$i]) + $pr) & 0xFF);
                }
            }

            $prev = $line;

            if ($ctype === 2) {
                $raw .= $line;
            } elseif ($ctype === 6) {
                for ($px = 0; $px < $stride; $px += 4) {
                    $a = ord($line[$px + 3]);
                    $r = (int)round((ord($line[$px])     * $a + 255 * (255 - $a)) / 255);
                    $g = (int)round((ord($line[$px + 1]) * $a + 255 * (255 - $a)) / 255);
                    $b = (int)round((ord($line[$px + 2]) * $a + 255 * (255 - $a)) / 255);
                    $raw .= chr($r) . chr($g) . chr($b);
                }
            } elseif ($ctype === 3) {
                for ($px = 0; $px < $stride; $px++) {
                    $idx = ord($line[$px]) * 3;
                    $raw .= ($idx + 3 <= strlen($plte)) ? substr($plte, $idx, 3) : "\xff\xff\xff";
                }
            } elseif ($ctype === 0) {
                for ($px = 0; $px < $stride; $px++) {
                    $c = $line[$px];
                    $raw .= $c . $c . $c;
                }
            } elseif ($ctype === 4) {
                for ($px = 0; $px < $stride; $px += 2) {
                    $a = ord($line[$px + 1]);
                    $v = (int)round((ord($line[$px]) * $a + 255 * (255 - $a)) / 255);
                    $c = chr($v);
                    $raw .= $c . $c . $c;
                }
            }
        }

        return array(
            'w' => $w, 'h' => $h, 'raw' => $raw,
            'width' => $w, 'height' => $h, 'rgb' => $raw,
        );
    }
}
