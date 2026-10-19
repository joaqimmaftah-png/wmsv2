<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once dirname(__FILE__) . '/Pdf_writer.php';

/**
 * Inspection_pdf — builds the print-ready inspection PDF entirely in PHP
 * (no exec(), no python3/reportlab dependency), since many hosts disable
 * exec() for shared-hosting security. This is the generator actually used
 * by Inspections::pdf().
 *
 * Usage:
 *   $builder = new Inspection_pdf($payload); // same $payload shape the
 *                                             // controller already builds
 *   $bytes = $builder->render();
 */
class Inspection_pdf {

    const MM = 2.83464567;
    const MARGIN = 34.0157; // 12mm

    private $payload;
    private $lang;
    private $pdf;
    private $page_w;
    private $page_h;
    private $content_w;
    private $y;

    // palette
    const CLR_GREEN_HEADER = array(0.722, 0.831, 0.659);
    const CLR_GREEN_BAND   = array(0.831, 0.890, 0.800);
    const CLR_GREY_HDR     = array(0.922, 0.922, 0.922);
    const CLR_NA           = array(0.949, 0.949, 0.949);
    const CLR_BORDER       = array(0.533, 0.533, 0.533);
    const CLR_TEXT         = array(0, 0, 0);
    const CLR_TEXT_LABEL   = array(0.267, 0.267, 0.267);
    const CLR_OK           = array(0.102, 0.478, 0.204);
    const CLR_FAIL         = array(0.753, 0.224, 0.169);

    public function __construct($payload) {
        $this->payload = $payload;
        $this->lang = isset($payload['record']['language']) ? $payload['record']['language'] : 'en';
    }

    private function L($en, $fr) {
        return $this->lang === 'fr' ? $fr : $en;
    }

    private function new_page() {
        $this->pdf->add_page($this->page_w, $this->page_h);
        $this->y = $this->page_h - self::MARGIN;
    }

    private function ensure_space($h) {
        if ($this->y - $h < self::MARGIN + 22) {
            $this->new_page();
            return true;
        }
        return false;
    }

    public function render() {
        $template = $this->payload['template'];
        $record   = $this->payload['record'];
        $items    = $this->payload['items'];
        $layout   = isset($template['layout_type']) ? $template['layout_type'] : 'compliance';
        $columns  = isset($template['columns']) ? $template['columns'] : array();
        $meter_label = $this->L(
            isset($template['meter_label_en']) ? $template['meter_label_en'] : 'SMR',
            isset($template['meter_label_fr']) ? $template['meter_label_fr'] : 'SMR'
        );

        $landscape = ($layout === 'matrix');
        $this->page_w = $landscape ? (297 * self::MM) : (210 * self::MM);
        $this->page_h = $landscape ? (210 * self::MM) : (297 * self::MM);
        $this->content_w = $this->page_w - 2 * self::MARGIN;

        $this->pdf = new Pdf_writer();
        $this->new_page();

        $title = $this->L($template['name_en'], $template['name_fr']);
        $tcode = isset($template['code']) ? $template['code'] : '';
        if (in_array($tcode, array('heavy_equipment', 'lv_bus', 'heavy_vehicle'))) {
            $subtitle = $this->L('Quarterly Permit Coding', 'Codification trimestrielle des permis');
        } else {
            $cat_en = !empty($template['category_en']) ? $template['category_en'] : 'Equipment Inspection';
            $cat_fr = !empty($template['category_fr']) ? $template['category_fr'] : 'Inspection d’équipement';
            $subtitle = $this->L($cat_en, $cat_fr);
        }
        $this->_draw_header($title, $subtitle, $record, $meter_label);

        if ($layout === 'matrix') {
            $geo = $this->_draw_table_header_matrix($columns);
            foreach ($items as $it) {
                if ($it['row_type'] === 'section') {
                    $this->_draw_section_row($this->L($it['text_en'], $it['text_fr']));
                } else {
                    $this->_draw_row_matrix($it, $geo, $columns);
                }
            }
        } else {
            $geo = $this->_draw_table_header_compliance();
            foreach ($items as $it) {
                if ($it['row_type'] === 'section') {
                    $this->_draw_section_row($this->L($it['text_en'], $it['text_fr']));
                } else {
                    $this->_draw_row_compliance($it, $geo);
                }
            }
        }

        $this->_draw_general_comments(isset($record['general_comments']) ? $record['general_comments'] : '');
        $this->_draw_signoff($record);

        return $this->pdf->output();
    }

    /* ---------- header ---------- */

    private function _draw_header($title, $subtitle, $record, $meter_label) {
        $x0 = self::MARGIN;
        $w = $this->content_w;

        $this->pdf->rect($x0, $this->y - 20, $w, 20, 'FD', self::CLR_GREEN_HEADER, self::CLR_BORDER, 0.6);
        $this->pdf->text($x0, $this->y - 14, $title, 'F2', 13, self::CLR_TEXT, 'C', $w);
        $this->y -= 20;

        $this->pdf->text($x0, $this->y - 10, $subtitle, 'F1', 8, self::CLR_TEXT_LABEL, 'C', $w);
        $this->y -= 16;

        $fields = array(
            array($this->L('Date', 'Date'), $this->_s($record, 'inspection_date')),
            array($this->L('Fleet Number', 'Numéro de flotte'), $this->_s($record, 'fleet_number')),
            array($this->L('Mobile Equipment Type', 'Type d’équipement'), $this->_s($record, 'equipment_type')),
            array($meter_label, $this->_s($record, 'smr')),
            array('S/N', $this->_s($record, 'serial_number')),
            array($this->L('Model', 'Modèle'), $this->_s($record, 'model')),
        );
        $box_h = 20;
        $box_w = $w / count($fields);
        foreach ($fields as $i => $f) {
            list($label, $value) = $f;
            $bx = $x0 + $i * $box_w;
            $this->pdf->rect($bx, $this->y - $box_h, $box_w, $box_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.5);
            $this->pdf->text($bx + 3, $this->y - 8, mb_strtoupper($label, 'UTF-8'), 'F2', 6, self::CLR_TEXT_LABEL);
            $val = (mb_strlen($value, 'UTF-8') < 26) ? $value : (mb_substr($value, 0, 24, 'UTF-8') . '…');
            $this->pdf->text($bx + 3, $this->y - 17, $val, 'F1', 8, self::CLR_TEXT);
        }
        $this->y -= ($box_h + 6);
    }

    /* ---------- matrix layout ---------- */

    private function _draw_table_header_matrix($columns) {
        $x0 = self::MARGIN;
        $w = $this->content_w;
        $n_cols = count($columns);
        $col_no_w = 16; $col_cat_w = 60; $col_req_w = 150; $col_comment_w = 78;
        $remaining = $w - $col_no_w - $col_cat_w - $col_req_w - $col_comment_w;
        $col_eq_w = $remaining / max(1, $n_cols);
        $row_h = 24;

        $this->pdf->rect($x0, $this->y - $row_h, $w, $row_h, 'F', self::CLR_GREY_HDR, self::CLR_GREY_HDR, 0);

        $labels = array('#', $this->L('Category', 'Catégorie'), $this->L('Check Requirement', 'Exigence'));
        $widths = array($col_no_w, $col_cat_w, $col_req_w);
        $cx = $x0;
        foreach ($labels as $i => $lbl) {
            $wd = $widths[$i];
            $this->pdf->rect($cx, $this->y - $row_h, $wd, $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.5);
            $this->pdf->text_wrapped($cx + 3, $this->y - 9, $lbl, 'F2', 6.5, $wd - 5, 7.2, 2, self::CLR_TEXT);
            $cx += $wd;
        }
        foreach ($columns as $col) {
            $label = $this->L($col['label_en'], $col['label_fr']);
            $this->pdf->rect($cx, $this->y - $row_h, $col_eq_w, $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.5);
            $lines = Pdf_writer::wrap_line($label, 5.6, $col_eq_w - 3, true);
            $lines = array_slice($lines, 0, 3);
            foreach ($lines as $li => $ln) {
                $this->pdf->text($cx, $this->y - 9 - $li * 6.5, $ln, 'F2', 5.6, self::CLR_TEXT, 'C', $col_eq_w);
            }
            $cx += $col_eq_w;
        }
        $this->pdf->rect($cx, $this->y - $row_h, $col_comment_w, $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.5);
        $this->pdf->text($cx + 3, $this->y - 9, $this->L('Comment', 'Commentaire'), 'F2', 6.5, self::CLR_TEXT);

        $this->y -= $row_h;
        return array('col_no_w' => $col_no_w, 'col_cat_w' => $col_cat_w, 'col_req_w' => $col_req_w,
                     'col_eq_w' => $col_eq_w, 'col_comment_w' => $col_comment_w);
    }

    private function _draw_row_matrix($item, $geo, $columns) {
        $x0 = self::MARGIN;
        $req_text = $this->L($item['text_en'], $item['text_fr']);
        $cat_text = $this->L($item['category_en'], $item['category_fr']);

        $req_lines = Pdf_writer::wrap_line($req_text, 6.6, $geo['col_req_w'] - 6);
        $req_h = max(1, min(count($req_lines), 4)) * 7.6;
        $row_h = max(16, $req_h + 6);

        $this->ensure_space($row_h);
        $y_top = $this->y;

        $cx = $x0;
        $this->pdf->rect($cx, $y_top - $row_h, $geo['col_no_w'], $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.4);
        $this->pdf->text($cx, $y_top - $row_h / 2 - 2, (string) (isset($item['item_no']) ? $item['item_no'] : ''), 'F1', 6.5, self::CLR_TEXT, 'C', $geo['col_no_w']);
        $cx += $geo['col_no_w'];

        $this->pdf->rect($cx, $y_top - $row_h, $geo['col_cat_w'], $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.4);
        $this->pdf->text_wrapped($cx + 3, $y_top - 8, $cat_text, 'F1', 6, $geo['col_cat_w'] - 5, 7, 3);
        $cx += $geo['col_cat_w'];

        $this->pdf->rect($cx, $y_top - $row_h, $geo['col_req_w'], $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.4);
        $this->pdf->text_wrapped($cx + 3, $y_top - 8, $req_text, 'F1', 6.6, $geo['col_req_w'] - 6, 7.6, 4);
        $cx += $geo['col_req_w'];

        $na_cols = isset($item['na_columns']) ? $item['na_columns'] : array();
        $result = isset($item['result']) ? $item['result'] : array();
        foreach ($columns as $col) {
            $key = $col['key'];
            $cell_w = $geo['col_eq_w'];
            $is_na = in_array($key, $na_cols);
            if ($is_na) {
                $this->pdf->rect($cx, $y_top - $row_h, $cell_w, $row_h, 'FD', self::CLR_NA, self::CLR_BORDER, 0.4);
                $this->pdf->text($cx, $y_top - $row_h / 2 - 2, $this->L('N/A', 'S/O'), 'F1', 6, array(0.6, 0.6, 0.6), 'C', $cell_w);
            } else {
                $this->pdf->rect($cx, $y_top - $row_h, $cell_w, $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.4);
                $v = isset($result[$key]) ? $result[$key] : null;
                if ($v === 'ok') {
                    $this->pdf->text($cx, $y_top - $row_h / 2 - 2.5, 'OK', 'F2', 7, self::CLR_OK, 'C', $cell_w);
                } elseif ($v === 'fail') {
                    $this->pdf->text($cx, $y_top - $row_h / 2 - 2.5, 'X', 'F2', 8, self::CLR_FAIL, 'C', $cell_w);
                }
            }
            $cx += $cell_w;
        }

        $this->pdf->rect($cx, $y_top - $row_h, $geo['col_comment_w'], $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.4);
        $this->pdf->text_wrapped($cx + 3, $y_top - 8, isset($item['comment']) ? $item['comment'] : '', 'F1', 6, $geo['col_comment_w'] - 5, 7, 3);

        $this->y -= $row_h;
    }

    /* ---------- compliance layout ---------- */

    private function _draw_table_header_compliance() {
        $x0 = self::MARGIN;
        $w = $this->content_w;
        $col_no_w = 18; $col_cat_w = 85; $col_comp_w = 90; $col_comment_w = 130;
        $col_req_w = $w - $col_no_w - $col_cat_w - $col_comp_w - $col_comment_w;
        $row_h = 16;

        $this->pdf->rect($x0, $this->y - $row_h, $w, $row_h, 'F', self::CLR_GREY_HDR, self::CLR_GREY_HDR, 0);
        $labels = array('#', $this->L('Category', 'Catégorie'), $this->L('Check Requirement', 'Exigence de vérification'),
                         $this->L('Compliance', 'Conformité'), $this->L('Comments by Mechanic', 'Commentaires du mécanicien'));
        $widths = array($col_no_w, $col_cat_w, $col_req_w, $col_comp_w, $col_comment_w);
        $cx = $x0;
        foreach ($labels as $i => $lbl) {
            $wd = $widths[$i];
            $this->pdf->rect($cx, $this->y - $row_h, $wd, $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.5);
            $this->pdf->text($cx + 3, $this->y - 11, $lbl, 'F2', 7, self::CLR_TEXT);
            $cx += $wd;
        }
        $this->y -= $row_h;
        return array('col_no_w' => $col_no_w, 'col_cat_w' => $col_cat_w, 'col_req_w' => $col_req_w,
                     'col_comp_w' => $col_comp_w, 'col_comment_w' => $col_comment_w);
    }

    private function _draw_row_compliance($item, $geo) {
        $x0 = self::MARGIN;
        $req_text = $this->L($item['text_en'], $item['text_fr']);
        $cat_text = $this->L($item['category_en'], $item['category_fr']);

        $req_lines = Pdf_writer::wrap_line($req_text, 7, $geo['col_req_w'] - 6);
        $req_h = max(1, min(count($req_lines), 4)) * 8.2;
        $row_h = max(15, $req_h + 5);

        $this->ensure_space($row_h);
        $y_top = $this->y;

        $cx = $x0;
        $this->pdf->rect($cx, $y_top - $row_h, $geo['col_no_w'], $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.4);
        $this->pdf->text($cx, $y_top - $row_h / 2 - 2, (string) (isset($item['item_no']) ? $item['item_no'] : ''), 'F1', 7, self::CLR_TEXT, 'C', $geo['col_no_w']);
        $cx += $geo['col_no_w'];

        $this->pdf->rect($cx, $y_top - $row_h, $geo['col_cat_w'], $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.4);
        $this->pdf->text_wrapped($cx + 3, $y_top - 8, $cat_text, 'F1', 6.3, $geo['col_cat_w'] - 5, 7.2, 3);
        $cx += $geo['col_cat_w'];

        $this->pdf->rect($cx, $y_top - $row_h, $geo['col_req_w'], $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.4);
        $this->pdf->text_wrapped($cx + 3, $y_top - 8, $req_text, 'F1', 7, $geo['col_req_w'] - 6, 8.2, 4);
        $cx += $geo['col_req_w'];

        $this->pdf->rect($cx, $y_top - $row_h, $geo['col_comp_w'], $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.4);
        $result = isset($item['result']['result']) ? $item['result']['result'] : '';
        if ($result !== '') {
            $color = ($result === 'Yes') ? self::CLR_OK : (($result === 'No') ? self::CLR_FAIL : array(0.53, 0.53, 0.53));
            $labelmap = array('Yes' => $this->L('Yes', 'Oui'), 'No' => $this->L('No', 'Non'), 'NA' => $this->L('N/A', 'S/O'));
            $label = isset($labelmap[$result]) ? $labelmap[$result] : $result;
            $this->pdf->text($cx, $y_top - $row_h / 2 - 2.5, $label, 'F2', 7.5, $color, 'C', $geo['col_comp_w']);
        }
        $cx += $geo['col_comp_w'];

        $this->pdf->rect($cx, $y_top - $row_h, $geo['col_comment_w'], $row_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.4);
        $this->pdf->text_wrapped($cx + 3, $y_top - 8, isset($item['comment']) ? $item['comment'] : '', 'F1', 6.5, $geo['col_comment_w'] - 5, 7.6, 3);

        $this->y -= $row_h;
    }

    /* ---------- shared: sections / comments / signatures ---------- */

    private function _draw_section_row($text) {
        $row_h = 13;
        $this->ensure_space($row_h);
        $this->pdf->rect(self::MARGIN, $this->y - $row_h, $this->content_w, $row_h, 'FD', self::CLR_GREEN_BAND, self::CLR_BORDER, 0.5);
        $this->pdf->text(self::MARGIN + 4, $this->y - 9, $text, 'F2', 7.2, array(0.11, 0.23, 0.06));
        $this->y -= $row_h;
    }

    private function _draw_general_comments($text) {
        $label = $this->L('General Comments', 'Commentaires généraux');
        $box_h = 40;
        $this->ensure_space($box_h + 14);
        $this->y -= 6;
        $this->pdf->text(self::MARGIN, $this->y, $label . ':', 'F2', 8, self::CLR_TEXT);
        $this->y -= 12;
        $this->pdf->rect(self::MARGIN, $this->y - $box_h, $this->content_w, $box_h, 'S', array(1, 1, 1), self::CLR_BORDER, 0.5);
        $this->pdf->text_wrapped(self::MARGIN + 5, $this->y - 10, $text, 'F1', 7.5, $this->content_w - 10, 9, 4);
        $this->y -= ($box_h + 10);
    }

    private function _draw_signoff($record) {
        $roles = array(
            array('mechanic', $this->L('Mechanic Conducting Inspection', 'Mécanicien effectuant l’inspection'), $this->L('Inspector Name', "Nom de l'inspecteur")),
            array('supervisor', $this->L('Mechanic Supervisor', 'Superviseur mécanicien'), $this->L('Name', 'Nom')),
            array('operator', $this->L('Operator / Driver', 'Opérateur / Conducteur'), $this->L('Name', 'Nom')),
            array('prod_supervisor', $this->L('Production Supervisor', 'Superviseur de production'), $this->L('Name', 'Nom')),
        );
        // Keep the printed name on a separate line below the signature line;
        // otherwise the signature image obscures the name in printed PDFs.
        $block_h = 36;
        $this->ensure_space($block_h * count($roles) + 10);

        foreach ($roles as $r) {
            list($key, $label, $name_label) = $r;
            $this->ensure_space($block_h);
            $name = $this->_s($record, $key . '_name');
            $date = $this->_s($record, $key . '_date');
            $sig_path = $this->_s($record, $key . '_signature');

            $this->pdf->text(self::MARGIN, $this->y - 8, $label . ':', 'F2', 7.5, self::CLR_TEXT);

            $line_x0 = self::MARGIN + 145;
            $line_x1 = self::MARGIN + $this->content_w * 0.62;
            $this->pdf->line($line_x0, $this->y - 10, $line_x1, $this->y - 10, 0.6, self::CLR_BORDER);
            $this->pdf->text_wrapped($line_x0 + 2, $this->y - 23, $name_label . ': ' . $name,
                'F1', 7, $line_x1 - $line_x0 - 4, 8, 2, self::CLR_TEXT);

            $date_label_x = $line_x1 + 10;
            $this->pdf->text($date_label_x, $this->y - 8, $this->L('Date', 'Date') . ':', 'F2', 7.5, self::CLR_TEXT);
            $date_line_x0 = $date_label_x + 26;
            $date_line_x1 = self::MARGIN + $this->content_w;
            $this->pdf->line($date_line_x0, $this->y - 10, $date_line_x1, $this->y - 10, 0.6, self::CLR_BORDER);
            $this->pdf->text($date_line_x0 + 2, $this->y - 8, $date, 'F1', 7.5, self::CLR_TEXT);

            if ($sig_path && file_exists($sig_path)) {
                $this->_draw_signature_image($sig_path, $line_x0 + 2, $this->y - 9);
            }

            $this->y -= $block_h;
        }
    }

    /** Decode a signature PNG (via GD, if available) and embed it as an RGB image composited on white. */
    private function _draw_signature_image($path, $x, $y) {
        if (!function_exists('imagecreatefromstring')) return; // GD not available — skip gracefully
        $data = @file_get_contents($path);
        if ($data === false) return;
        $src = @imagecreatefromstring($data);
        if (!$src) return;

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0) { imagedestroy($src); return; }

        $bg = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($bg, 255, 255, 255);
        imagefill($bg, 0, 0, $white);
        imagealphablending($bg, true);
        imagecopy($bg, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        $raw = '';
        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $rgb = imagecolorat($bg, $px, $py);
                $raw .= chr(($rgb >> 16) & 0xFF) . chr(($rgb >> 8) & 0xFF) . chr($rgb & 0xFF);
            }
        }
        imagedestroy($bg);

        $target_h = 16;
        $target_w = min(70, $target_h * $w / max(1, $h));
        $this->pdf->image_rgb($raw, $w, $h, $x, $y, $target_w, $target_h);
    }

    private function _s($arr, $key) {
        if (is_array($arr) && isset($arr[$key]) && $arr[$key] !== null) return (string) $arr[$key];
        return '';
    }
}
