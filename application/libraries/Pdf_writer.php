<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pdf_writer — minimal, dependency-free PDF generator.
 *
 * Written from scratch against the PDF 1.4 spec (no exec()/shell_exec(),
 * no Composer, no third-party library) so it works on hosts that disable
 * exec() (very common on shared hosting) and don't have python3/reportlab.
 *
 * Supports: multi-page documents, core Helvetica/Helvetica-Bold text,
 * filled/stroked rectangles, lines, and RGB image embedding (from a
 * GD image resource, e.g. a decoded signature PNG). Text is encoded as
 * CP1252/WinAnsi, which covers standard French accented characters.
 */
#[AllowDynamicProperties]
class Pdf_writer {

    private $pages = array();   // each: array('w'=>, 'h'=>, 'content'=>string)
    private $images = array();  // each: array('raw'=>rgb bytes, 'w'=>, 'h'=>)
    private $curContent = '';
    private $curW = 595.28;
    private $curH = 841.89;
    private $hasOpenPage = false;

    public function add_page($w, $h) {
        if ($this->hasOpenPage) {
            $this->pages[] = array('w' => $this->curW, 'h' => $this->curH, 'content' => $this->curContent);
        }
        $this->curW = $w;
        $this->curH = $h;
        $this->curContent = '';
        $this->hasOpenPage = true;
    }

    private function _finish_current_page() {
        if (!$this->hasOpenPage) return;
        $this->pages[] = array('w' => $this->curW, 'h' => $this->curH, 'content' => $this->curContent);
        $this->hasOpenPage = false;
    }

    /* ---------- drawing primitives ---------- */

    public function rect($x, $y, $w, $h, $mode = 'S', $fill = array(0, 0, 0), $stroke = array(0, 0, 0), $line_width = 0.5) {
        list($fr, $fg, $fb) = $fill;
        list($sr, $sg, $sb) = $stroke;
        $op = ($mode === 'F') ? 'f' : (($mode === 'B' || $mode === 'FD') ? 'B' : 'S');
        $this->curContent .= sprintf(
            "q\n%.2F w\n%.3F %.3F %.3F RG\n%.3F %.3F %.3F rg\n%.2F %.2F %.2F %.2F re\n%s\nQ\n",
            $line_width, $sr, $sg, $sb, $fr, $fg, $fb, $x, $y, $w, $h, $op
        );
    }

    public function line($x1, $y1, $x2, $y2, $width = 0.6, $color = array(0, 0, 0)) {
        list($r, $g, $b) = $color;
        $this->curContent .= sprintf(
            "q\n%.2F w\n%.3F %.3F %.3F RG\n%.2F %.2F m\n%.2F %.2F l\nS\nQ\n",
            $width, $r, $g, $b, $x1, $y1, $x2, $y2
        );
    }

    /** Draw one line of text with the baseline at (x, y). */
    public function text($x, $y, $text, $font = 'F1', $size = 8, $color = array(0, 0, 0), $align = 'L', $max_w = null) {
        if ($text === null || $text === '') return;
        list($r, $g, $b) = $color;
        $tx = $x;
        if ($align !== 'L' && $max_w !== null) {
            $tw = self::text_width($text, $size, ($font === 'F2'));
            if ($align === 'C') $tx = $x + ($max_w - $tw) / 2;
            elseif ($align === 'R') $tx = $x + $max_w - $tw;
        }
        $escaped = $this->_encode_text($text);
        $this->curContent .= sprintf(
            "BT\n/%s %.2F Tf\n%.3F %.3F %.3F rg\n%.2F %.2F Td\n(%s) Tj\nET\n",
            $font, $size, $r, $g, $b, $tx, $y, $escaped
        );
    }

    /**
     * Word-wrap $text to fit $max_w points and draw it starting with the
     * baseline of the first line at $y_top. Returns the height used.
     */
    public function text_wrapped($x, $y_top, $text, $font, $size, $max_w, $line_h, $max_lines, $color = array(0, 0, 0)) {
        $lines = array();
        foreach (preg_split("/\r\n|\n|\r/", (string) $text) as $raw) {
            $wrapped = self::wrap_line(trim((string)($raw)), $size, $max_w, ($font === 'F2'));
            if (empty($wrapped)) $wrapped = array('');
            foreach ($wrapped as $w) $lines[] = $w;
        }
        $lines = array_slice($lines, 0, $max_lines);
        $n = 0;
        foreach ($lines as $i => $line) {
            $this->text($x, $y_top - $i * $line_h, $line, $font, $size, $color);
            $n++;
        }
        return max(1, $n) * $line_h;
    }

    /** Embed an RGB image (raw bytes, top-to-bottom rows, 3 bytes/px) at ($x,$y) sized ($draw_w x $draw_h). */
    public function image_rgb($raw_rgb, $px_w, $px_h, $x, $y, $draw_w, $draw_h) {
        $idx = count($this->images);
        $this->images[] = array('raw' => $raw_rgb, 'w' => $px_w, 'h' => $px_h);
        $this->curContent .= sprintf("q\n%.2F 0 0 %.2F %.2F %.2F cm\n/Im%d Do\nQ\n", $draw_w, $draw_h, $x, $y, $idx);
    }

    /* ---------- text measurement (approximate core-font metrics) ---------- */

    private static function char_factor($ch, $bold) {
        if ($ch === ' ') return 0.278;
        if (ctype_digit($ch)) return 0.556;
        if (in_array($ch, array('i', 'l', 'j', 'I', '.', ',', "'", ':', ';', '!', '|', 'f', 't'))) return 0.28;
        if (in_array($ch, array('m', 'w', 'M', 'W'))) return $bold ? 0.95 : 0.833;
        if (ctype_upper($ch)) return $bold ? 0.72 : 0.667;
        return $bold ? 0.60 : 0.5;
    }

    public static function text_width($text, $size, $bold = false) {
        $w = 0.0;
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $w += self::char_factor(mb_substr($text, $i, 1, 'UTF-8'), $bold) * $size;
        }
        return $w;
    }

    public static function wrap_line($text, $size, $max_w, $bold = false) {
        if ($text === '') return array('');
        $words = preg_split('/\s+/', $text);
        $lines = array();
        $cur = '';
        foreach ($words as $word) {
            $candidate = ($cur === '') ? $word : ($cur . ' ' . $word);
            if (self::text_width($candidate, $size, $bold) <= $max_w || $cur === '') {
                $cur = $candidate;
            } else {
                $lines[] = $cur;
                $cur = $word;
            }
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines;
    }

    /* ---------- encoding / escaping ---------- */

    private function _encode_text($text) {
        $cp1252 = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);
        if ($cp1252 === FALSE) $cp1252 = $text;
        $cp1252 = str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $cp1252);
        return $cp1252;
    }

    /* ---------- output ---------- */

    public function output() {
        $this->_finish_current_page();

        $n_images = count($this->images);
        $img_base = 6; // 1 catalog, 2 pages, 3 font1, 4 font2, 5 resources
        $content_base = $img_base + $n_images;

        $xobj_entries = '';
        for ($i = 0; $i < $n_images; $i++) $xobj_entries .= "/Im{$i} " . ($img_base + $i) . " 0 R ";
        $resources = "<< /Font << /F1 3 0 R /F2 4 0 R >> /XObject << {$xobj_entries}>> >>";

        $body = array();
        $body[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $body[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $body[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        $body[5] = $resources;

        for ($i = 0; $i < $n_images; $i++) {
            $img = $this->images[$i];
            $compressed = function_exists('gzcompress') ? gzcompress($img['raw'], 6) : null;
            if ($compressed !== null) {
                $body[$img_base + $i] = "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} "
                    . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen((string)($compressed)) . " >>\nstream\n"
                    . $compressed . "\nendstream";
            } else {
                $raw = $img['raw'];
                $body[$img_base + $i] = "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} "
                    . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Length " . strlen((string)($raw)) . " >>\nstream\n"
                    . $raw . "\nendstream";
            }
        }

        $obj_num = $content_base;
        $kids = array();
        foreach ($this->pages as $page) {
            $content_bytes = $page['content'];
            $body[$obj_num] = "<< /Length " . strlen((string)($content_bytes)) . " >>\nstream\n" . $content_bytes . "\nendstream";
            $content_obj_num = $obj_num;
            $obj_num++;

            $page_obj_num = $obj_num;
            $body[$page_obj_num] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources 5 0 R /Contents %d 0 R >>",
                $page['w'], $page['h'], $content_obj_num
            );
            $kids[] = $page_obj_num;
            $obj_num++;
        }

        $body[2] = "<< /Type /Pages /Kids [" . implode(' ', array_map(function ($k) { return "{$k} 0 R"; }, $kids)) . "] /Count " . count($kids) . " >>";

        ksort($body);
        $max_obj = max(array_keys($body));

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array();
        for ($i = 1; $i <= $max_obj; $i++) {
            $offsets[$i] = strlen((string)($out));
            $out .= "{$i} 0 obj\n" . $body[$i] . "\nendobj\n";
        }

        $xref_offset = strlen((string)($out));
        $out .= "xref\n0 " . ($max_obj + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max_obj; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size " . ($max_obj + 1) . " /Root 1 0 R >>\nstartxref\n{$xref_offset}\n%%EOF";

        return $out;
    }
}
