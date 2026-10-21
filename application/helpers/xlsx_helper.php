<?php
/**
 * Pure PHP XLSX Writer — no external dependencies, PHP 5.4+
 * Uses ZipArchive (bundled with PHP since 5.2)
 *
 * IMPORTANT — serialisation order
 * ------------------------------
 * Shared strings and styles are registered lazily, while the worksheets are
 * being rendered (self::_sheet()).  save() must therefore render every sheet
 * BEFORE it generates sharedStrings.xml / styles.xml, otherwise those parts are
 * written empty and every text cell in the workbook points to a non-existent
 * shared string (Excel shows blanks / "we found a problem with some content").
 */
defined('BASEPATH') OR exit('No direct script access allowed');

if (!class_exists('XlsxWriter')) {
#[AllowDynamicProperties]
class XlsxWriter {

    private $sheets = [];
    private $currentSheet = null;
    private $sharedStrings = [];
    private $stringIndex = [];
    private $styles = [];
    private $styleIndex = [];
    private $builtinFormats = [
        'general'=>0,'@'=>49,'date'=>14,'datetime'=>22,'time'=>21,
        '0'=>1,'0.00'=>2,'#,##0'=>3,'#,##0.00'=>4,'0%'=>9,'0.00%'=>10,
    ];

    public function addSheet($name) {
        $name = $this->_sheetName($name);
        $this->sheets[] = ['name'=>$name,'rows'=>[],'merges'=>[],'colWidths'=>[],'rowHeights'=>[],
                           'freeze'=>null,'autoFilter'=>null,'validations'=>[],
                           'images'=>[],'pageSetup'=>null,'pageMargins'=>null,'printArea'=>null,
                           'tables'=>[]];
        $this->currentSheet = &$this->sheets[count($this->sheets)-1];
        return $this;
    }

    /**
     * Freeze everything above/left of $cell (e.g. 'A3' freezes the first 2 rows).
     */
    public function freezePane($cell) {
        $this->currentSheet['freeze'] = $cell;
        return $this;
    }

    /** Add an AutoFilter over the given range (e.g. 'A2:P40'). */
    public function setAutoFilter($range) {
        $this->currentSheet['autoFilter'] = $range;
        return $this;
    }

    /**
     * Add a data-validation rule (dropdowns, numeric limits, ...).
     *
     * @param string $sqref   e.g. 'C3:C500'
     * @param array  $options type (list|whole|decimal|date|textLength|custom),
     *                        formula1, formula2, allowBlank, showDropDown,
     *                        prompt, promptTitle, error, errorTitle,
     *                        errorStyle (stop|warning|information)
     */
    public function addDataValidation($sqref, array $options) {
        $options['sqref'] = $sqref;
        $this->currentSheet['validations'][] = $options;
        return $this;
    }

    /** Write a row where all cells share one styleKey */
    public function writeRow(array $cells, $styleKey='') {
        $row = [];
        foreach ($cells as $val) { $row[] = ['v'=>$val,'s'=>$styleKey]; }
        $this->currentSheet['rows'][] = $row;
        return $this;
    }

    /** Write a row with per-cell styling: [['v'=>val,'s'=>styleKey], ...] */
    public function writeRowStyled(array $cells) {
        $this->currentSheet['rows'][] = $cells;
        return $this;
    }

    /**
     * Place an image on the current sheet (W4.1).
     *
     * Anchored to a single cell, the way the approved workbook anchors its own
     * logo (oneCellAnchor at D1), so it prints and moves with the header.
     *
     * @param string $file  path to a png / jpeg / gif
     * @param array  $opts  anchor ('D1'), x/y (EMU offsets inside the cell),
     *                      width/height (EMU; omitted → the image's own size at
     *                      96 dpi), name, fit ('box' => array(cx, cy) to fit
     *                      inside while keeping the aspect ratio)
     */
    public function addImage($file, array $opts = array()) {
        if (!is_file($file)) return $this;                      // nothing to embed
        $size = @getimagesize($file);
        if ($size === false) return $this;                      // not an image a reader can use

        $ext = strtolower((string)(pathinfo($file, PATHINFO_EXTENSION)));
        $allowed = array('png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif');
        if (!isset($allowed[$ext])) $ext = 'png';

        $px_w = (int)$size[0];
        $px_h = (int)$size[1];
        if ($px_w < 1 || $px_h < 1) return $this;

        // px at 96 dpi → EMU (1 inch = 914400 EMU, 1 px = 9525 EMU)
        $cx = $px_w * 9525;
        $cy = $px_h * 9525;

        if (isset($opts['fit']) && is_array($opts['fit']) && count($opts['fit']) === 2) {
            // fit inside the approved box, never distorting the logo
            $box_w = (float)$opts['fit'][0];
            $box_h = (float)$opts['fit'][1];
            if ($box_w > 0 && $box_h > 0) {
                $scale = min($box_w / $cx, $box_h / $cy);
                $cx = (int)round($cx * $scale);
                $cy = (int)round($cy * $scale);
            }
        }
        if (isset($opts['width']))  $cx = (int)$opts['width'];
        if (isset($opts['height'])) $cy = (int)$opts['height'];

        $cell = isset($opts['anchor']) ? (string)$opts['anchor'] : 'A1';
        if (!preg_match('/^([A-Za-z]{1,3})(\d+)$/', $cell, $m)) { $m = array('', 'A', '1'); }

        $this->currentSheet['images'][] = array(
            'file'   => $file,
            'ext'    => $ext,
            'type'   => $allowed[$ext],
            'col'    => $this->_colNumber(strtoupper((string)($m[1]))) - 1,   // 0-based, as the drawing wants
            'row'    => (int)$m[2] - 1,
            'x'      => isset($opts['x']) ? (int)$opts['x'] : 0,
            'y'      => isset($opts['y']) ? (int)$opts['y'] : 0,
            'cx'     => $cx,
            'cy'     => $cy,
            'name'   => isset($opts['name']) ? (string)$opts['name'] : 'Picture ' . (count($this->currentSheet['images']) + 1),
        );
        return $this;
    }

    /**
     * A real table object on a range (W4.2) — the approved workbook carries one
     * on the per-type block (`Tableau2`, `BQ3:CB20`) with an auto-filter, so the
     * block can be sorted and filtered in Excel like a table.
     *
     * @param array $opts range ('BQ3:CB20'), name ('Tableau2'), columns (list of
     *                    header labels; omitted → read from the painted header row),
     *                    style (a built-in name such as 'TableStyleLight1'),
     *                    stripes (banded rows), autoFilter (default true)
     */
    public function addTable(array $opts = array()) {
        if (empty($opts['range'])) return $this;
        $range = strtoupper((string)(str_replace('$', '', trim((string)$opts['range']))));
        if (strpos($range, ':') === false) return $this;

        $columns = (isset($opts['columns']) && is_array($opts['columns'])) ? array_values($opts['columns']) : array();
        $this->currentSheet['tables'][] = array(
            'range'      => $range,
            'name'       => isset($opts['name']) ? (string)$opts['name'] : null,
            'columns'    => $columns,
            'style'      => isset($opts['style']) ? (string)$opts['style'] : null,
            'stripes'    => !empty($opts['stripes']),
            'autoFilter' => !isset($opts['autoFilter']) || $opts['autoFilter'],
        );
        return $this;
    }

    /**
     * Print setup for the current sheet (W4.1) — the approved workbook prints
     * A4 portrait with 0.7"/0.75" margins and no fit-to-page.
     *
     * @param array $opts orientation (portrait|landscape), paperSize (9 = A4),
     *                    fitToPage, printArea ('B1:CB108' or null)
     */
    public function setPageSetup(array $opts = array()) {
        $this->currentSheet['pageSetup'] = array(
            'orientation' => isset($opts['orientation']) ? $opts['orientation'] : 'portrait',
            'paperSize'   => isset($opts['paperSize']) ? (int)$opts['paperSize'] : 9,
            'fitToPage'   => !empty($opts['fitToPage']),
            // the approved `W36 Synthese` sheet prints at scale 41, one page wide
            // and as many pages tall as the data needs (fitToHeight="0")
            'scale'       => isset($opts['scale']) ? (int)$opts['scale'] : null,
            'fitToWidth'  => isset($opts['fitToWidth'])  ? (int)$opts['fitToWidth']  : 1,
            'fitToHeight' => isset($opts['fitToHeight']) ? (int)$opts['fitToHeight'] : 1,
        );
        $this->currentSheet['pageMargins'] = array(
            'left'   => isset($opts['left'])   ? (float)$opts['left']   : 0.7,
            'right'  => isset($opts['right'])  ? (float)$opts['right']  : 0.7,
            'top'    => isset($opts['top'])    ? (float)$opts['top']    : 0.75,
            'bottom' => isset($opts['bottom']) ? (float)$opts['bottom'] : 0.75,
            'header' => isset($opts['header']) ? (float)$opts['header'] : 0.3,
            'footer' => isset($opts['footer']) ? (float)$opts['footer'] : 0.3,
        );
        $this->currentSheet['printArea'] = !empty($opts['printArea']) ? (string)$opts['printArea'] : null;
        return $this;
    }

    public function mergeCells($from, $to) {
        $this->currentSheet['merges'][] = "$from:$to";
        return $this;
    }

    /** $col = 1-based column number */
    public function setColWidth($col, $width) {
        $this->currentSheet['colWidths'][$col] = $width;
        return $this;
    }

    public function setRowHeight($row, $height) {
        if ($row === null || $row === '') {
            $row = count($this->currentSheet['rows']) + 1;
        }
        $this->currentSheet['rowHeights'][(int)$row] = $height;
        return $this;
    }

    /**
     * Register a style and return its index (call before writeRow).
     * Style key format (pipe-separated tokens):
     *   bold | italic | fontSize:12 | fontName:Calibri
     *   align:center | align:right | valign:middle | valign:center
     *   bg:1F4E79  (hex RGB, no #) | fg:FFFFFF
     *   border:thin | border:medium
     *   wrap | numFmt:0.00%
     */
    public function registerStyle($key) {
        if (isset($this->styleIndex[$key])) return $this->styleIndex[$key];
        $idx = count($this->styles);
        $this->styles[$idx] = $key;
        $this->styleIndex[$key] = $idx;
        return $idx;
    }

    /**
     * Register a style on demand and return its 1-based cellXfs index
     * (0 = workbook default).  Called from _sheet() so that style keys which
     * were never explicitly registered — e.g. an existing style plus a
     * "|numFmt:0%" suffix — are still honoured instead of being silently
     * dropped.
     */
    private function _styleIndex($key) {
        if ($key === null || $key === '') return 0;
        if (!isset($this->styleIndex[$key])) $this->registerStyle($key);
        return $this->styleIndex[$key] + 1;
    }

    public function save($filepath) {
        if (empty($this->sheets)) $this->addSheet('Sheet1');

        // 1. The sheet XML has to name its relationships (a drawing, the table
        //    objects), so allocate them before anything is rendered.
        $plan = $this->_sheetPlan();

        // 2. Render the worksheets: this is what registers shared strings,
        //    fonts/fills/numFmts and the styles referenced by the cells.
        $sheetXml = [];
        foreach ($this->sheets as $i => $sheet) {
            $sheetXml[$i] = $this->_sheet($sheet, $plan[$i]);
        }

        // 3. Only now can these parts be complete.
        $contentTypes  = $this->_contentTypes();
        $rels          = $this->_rels();
        $workbook      = $this->_workbook();
        $workbookRels  = $this->_workbookRels();
        $styles        = $this->_styles();
        $sharedStrings = $this->_sharedStrings();

        $zip = new ZipArchive();
        $opened = $zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('Unable to create XLSX file at '.$filepath);
        }
        $zip->addFromString('[Content_Types].xml',       $contentTypes);
        $zip->addFromString('_rels/.rels',               $rels);
        $zip->addFromString('xl/workbook.xml',           $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels',$workbookRels);
        $zip->addFromString('xl/styles.xml',             $styles);
        $zip->addFromString('xl/sharedStrings.xml',      $sharedStrings);
        foreach ($sheetXml as $i => $xml) {
            $zip->addFromString("xl/worksheets/sheet".($i+1).".xml", $xml);
        }

        // 4. images (W4.1): one media file per image, one drawing part per sheet
        $media = 1;
        foreach ($this->sheets as $i => $sheet) {
            if (empty($sheet['images'])) continue;

            $rels = '';
            $anchors = '';
            foreach ($sheet['images'] as $n => $img) {
                $part = 'image' . $media . '.' . $img['ext'];
                $zip->addFile($img['file'], 'xl/media/' . $part);
                $rid = 'rId' . ($n + 1);
                $rels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"'
                       . ' Target="../media/' . $part . '"/>';
                $anchors .= $this->_anchorXml($img, $rid);
                $media++;
            }

            $zip->addFromString('xl/drawings/drawing' . ($i + 1) . '.xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"'
                . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
                . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . $anchors . '</xdr:wsDr>');

            $zip->addFromString('xl/drawings/_rels/drawing' . ($i + 1) . '.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . $rels . '</Relationships>');
        }

        // 5. table objects (W4.2) and the sheet relationship parts that point at
        //    everything a sheet attaches (drawing first, then its tables)
        foreach ($this->_tableParts() as $t) {
            $zip->addFromString('xl/tables/' . $t['part'] . '.xml', $this->_tableXml($t));
        }

        foreach ($plan as $i => $sheetPlan) {
            $rels = '';
            if (!empty($sheetPlan['drawing'])) {
                $rels .= '<Relationship Id="' . $sheetPlan['drawing'] . '"'
                       . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing"'
                       . ' Target="../drawings/drawing' . ($i + 1) . '.xml"/>';
            }
            foreach ($sheetPlan['tables'] as $t) {
                $rels .= '<Relationship Id="' . $t['rid'] . '"'
                       . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table"'
                       . ' Target="../tables/' . $t['part'] . '.xml"/>';
            }
            if ($rels === '') continue;

            $zip->addFromString('xl/worksheets/_rels/sheet' . ($i + 1) . '.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . $rels . '</Relationships>');
        }

        $zip->close();
        return $filepath;
    }

    /* ─── private ─────────────────────────────────────────── */

    /** Excel sheet names: max 31 chars, no []:*?/\ characters. */
    private function _sheetName($name) {
        $name = str_replace(['[',']',':','*','?','/','\\'], ' ', (string)$name);
        $name = trim((string)($name));
        if ($name === '') $name = 'Sheet';
        if (function_exists('mb_substr')) return mb_substr($name, 0, 31, 'UTF-8');
        return substr($name, 0, 31);
    }

    /**
     * Make sure a value can be stored in XML.  Values that are not valid UTF-8
     * used to be handed to htmlspecialchars(), which returns an empty string
     * for them — i.e. the cell silently lost its data.
     */
    private function _utf8($s) {
        $s = (string)$s;
        if ($s === '') return $s;
        if (preg_match('//u', $s)) return $s; // fast path: already valid UTF-8
        if (function_exists('mb_convert_encoding')) {
            $c = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            if (is_string($c) && $c !== '' && preg_match('//u', $c)) return $c;
        }
        if (function_exists('iconv')) {
            $c = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
            if (is_string($c) && $c !== '' && preg_match('//u', $c)) return $c;
        }
        // Last resort: strip stray high bytes instead of losing the whole string.
        $c = preg_replace('/[\x80-\xFF]/', '', $s);
        return is_string($c) ? $c : '';
    }

    private function _xml($s) {
        return htmlspecialchars((string)($this->_utf8($s)), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function _str($s) {
        $s = $this->_utf8($s);
        if (!isset($this->stringIndex[$s])) {
            $this->stringIndex[$s] = count($this->sharedStrings);
            $this->sharedStrings[] = $s;
        }
        return $this->stringIndex[$s];
    }

    private function _cellRef($col, $row) {
        $col--; $letters = '';
        while ($col >= 0) {
            $letters = chr(65 + ($col % 26)) . $letters;
            $col = intdiv($col, 26) - 1;
        }
        return $letters . $row;
    }

    /** Column letters used by the images in this workbook → content-type map. */
    private function imageExtensions() {
        $map = array();
        foreach ($this->sheets as $sheet) {
            foreach ($sheet['images'] as $img) {
                $map[$img['ext']] = $img['type'];
            }
        }
        return $map;
    }

    /** One <xdr:oneCellAnchor> — the anchor the approved workbook uses for its logo. */
    private function _anchorXml($img, $rid) {
        $name = htmlspecialchars((string)($img['name']), ENT_XML1);
        return '<xdr:oneCellAnchor>'
            . '<xdr:from><xdr:col>' . $img['col'] . '</xdr:col><xdr:colOff>' . $img['x'] . '</xdr:colOff>'
            . '<xdr:row>' . $img['row'] . '</xdr:row><xdr:rowOff>' . $img['y'] . '</xdr:rowOff></xdr:from>'
            . '<xdr:ext cx="' . $img['cx'] . '" cy="' . $img['cy'] . '"/>'
            . '<xdr:pic>'
            . '<xdr:nvPicPr><xdr:cNvPr id="' . ($img['col'] . $img['row']) . '" name="' . $name . '"/>'
            . '<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
            . '<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . $rid . '" cstate="print"/>'
            . '<a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $img['cx'] . '" cy="' . $img['cy'] . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
            . '</xdr:pic>'
            . '<xdr:clientData/></xdr:oneCellAnchor>';
    }

    /** Quote a sheet-local print area the way Excel writes it: $B$1:$CB$108. */
    private function printAreaRef($range) {
        $range = trim((string)($range));
        if (strpos($range, ':') === false) $range = $range . ':' . $range;
        list($from, $to) = explode(':', $range, 2);
        return $this->_absRef($from) . ':' . $this->_absRef($to);
    }

    private function _absRef($ref) {
        if (!preg_match('/^([A-Za-z]{1,3})(\d+)$/', trim((string)($ref)), $m)) return '$A$1';
        return '$' . strtoupper((string)($m[1])) . '$' . $m[2];
    }

    /** A1 → column number (1-based), for the anchors. */
    private function _colNumber($letters) {
        $n = 0;
        foreach (str_split($letters) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n;
    }

    /**
     * Allocate the relationship ids of every attachment, per sheet: the drawing
     * first, then the table objects (rId1 is the drawing when a sheet has one).
     */
    private function _sheetPlan() {
        $parts = $this->_tableParts();          // one numbering for every consumer
        $plan  = array();
        foreach ($this->sheets as $i => $sheet) {
            $next = 1;
            $drawing = empty($sheet['images']) ? null : 'rId' . $next++;
            $tables = array();
            foreach ($parts as $t) {
                if ($t['sheet'] !== $i) continue;
                $tables[] = array('rid' => 'rId' . $next++, 'part' => $t['part']);
            }
            $plan[$i] = array('drawing' => $drawing, 'tables' => $tables);
        }
        return $plan;
    }

    /**
     * The workbook's table parts, numbered in sheet order. Every consumer
     * (content types, the part itself, the sheet rels) reads this one list, so
     * the numbering cannot drift.
     */
    private function _tableParts() {
        $parts = array();
        $n = 1;
        foreach ($this->sheets as $i => $sheet) {
            foreach ($sheet['tables'] as $k => $t) {
                $t['sheet'] = $i;
                $t['part']  = 'table' . $n;
                $t['id']    = $n;                       // the table's own id, 1-based
                $t['order'] = $k;
                $parts[] = $t;
                $n++;
            }
        }
        return $parts;
    }

    /** One `xl/tables/tableN.xml`. */
    private function _tableXml(array $t) {
        $name = $this->_tableName($t, $t['name'] !== null ? $t['name'] : 'Tableau' . $t['id']);
        $columns = $t['columns'];
        if (empty($columns)) $columns = $this->_headerLabels($t);

        $cols = '';
        foreach (array_values($columns) as $k => $label) {
            $cols .= '<tableColumn id="' . ($k + 1) . '" name="' . $this->_tableColumnName($label, $k + 1) . '"/>';
        }

        $style = '';
        if ($t['style'] !== null) {
            $style = '<tableStyleInfo name="' . htmlspecialchars((string)($t['style']), ENT_XML1) . '"'
                   . ' showFirstColumn="0" showLastColumn="0"'
                   . ' showRowStripes="' . ($t['stripes'] ? '1' : '0') . '" showColumnStripes="0"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' id="' . $t['id'] . '" name="' . $name . '" displayName="' . $name . '"'
            . ' ref="' . $t['range'] . '" totalsRowShown="0">'
            . ($t['autoFilter'] ? '<autoFilter ref="' . $t['range'] . '"/>' : '')
            . '<tableColumns count="' . count($columns) . '">' . $cols . '</tableColumns>'
            . $style . '</table>';
    }

    /** A table name Excel accepts: no spaces, unique, max 255 characters. */
    private function _tableName($t, $candidate) {
        $name = preg_replace('/[^0-9A-Za-z_.]/', '_', (string)$candidate);
        if ($name === '' || !preg_match('/^[A-Za-z_]/', $name)) $name = 'Tableau' . $t['id'];
        return substr($name, 0, 255);
    }

    /**
     * A table column name: the header cell text with the OOXML escapes Excel
     * writes for line breaks, so the table and the painted header can never
     * disagree (Excel shows the header cell's own line breaks).
     */
    private function _tableColumnName($label, $index) {
        $label = (string)$label;
        $label = str_replace(array("\r\n", "\r", "\n"), '_x000a_', $label);
        $label = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $label);
        $label = trim((string)($label));
        if ($label === '') $label = 'Column' . $index;
        return htmlspecialchars((string)(substr($label, 0, 255)), ENT_XML1);
    }

    /** The labels the sheet already carries in the table's header row. */
    private function _headerLabels(array $t) {
        $range = explode(':', $t['range']);
        $from  = $this->_splitRef($range[0]);
        $to    = $this->_splitRef(isset($range[1]) ? $range[1] : $range[0]);
        $row   = $this->currentSheetRows($t['sheet']);
        $labels = array();
        for ($c = $from[0]; $c <= $to[0]; $c++) {
            $cell = isset($row[$from[1] - 1][$c - 1]) ? $row[$from[1] - 1][$c - 1] : null;
            $labels[] = ($cell !== null && isset($cell['v'])) ? $cell['v'] : ('Column' . ($c - $from[0] + 1));
        }
        return $labels;
    }

    private function currentSheetRows($i) {
        return isset($this->sheets[$i]['rows']) ? $this->sheets[$i]['rows'] : array();
    }

    /** 'BQ3' → array(column number, row number). */
    private function _splitRef($ref) {
        if (preg_match('/^([A-Za-z]{1,3})(\d+)$/', trim((string)($ref)), $m)) {
            return array($this->_colNumber(strtoupper((string)($m[1]))), (int)$m[2]);
        }
        return array(1, 1);
    }

    private function _contentTypes() {
        $sheets = '';
        foreach ($this->sheets as $i=>$s) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($i+1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $images = ''; $drawings = '';
        foreach ($this->imageExtensions() as $ext => $type) {
            $images .= '<Default Extension="' . $ext . '" ContentType="' . $type . '"/>';
        }
        foreach ($this->sheets as $i => $s) {
            if (empty($s['images'])) continue;
            $drawings .= '<Override PartName="/xl/drawings/drawing' . ($i + 1) . '.xml"'
                       . ' ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }
        foreach ($this->_tableParts() as $t) {
            $drawings .= '<Override PartName="/xl/tables/' . $t['part'] . '.xml"'
                       . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>'.$images.'
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        .$sheets.$drawings.'</Types>';
    }

    private function _rels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    }

    private function _workbook() {
        $sheets = '';
        foreach ($this->sheets as $i=>$s) {
            $sheets .= '<sheet name="'.htmlspecialchars((string)($s['name'])).'" sheetId="'.($i+1).'" r:id="rId'.($i+2).'"/>';
        }
        // the print area lives in the workbook, as a defined name (W4.1)
        $names = '';
        foreach ($this->sheets as $i => $s) {
            if (empty($s['printArea'])) continue;
            $quoted = "'" . str_replace("'", "''", $s['name']) . "'";
            $names .= '<definedName name="_xlnm.Print_Area" localSheetId="' . $i . '">'
                    . $quoted . '!' . $this->printAreaRef($s['printArea']) . '</definedName>';
        }
        $namesXml = $names === '' ? '' : '<definedNames>' . $names . '</definedNames>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
  xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets>'.$sheets.'</sheets>'.$namesXml.'</workbook>';
    }

    private function _workbookRels() {
        $n = count($this->sheets);
        $rels  = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $rels .= '<Relationship Id="rId'.($n+2).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        foreach ($this->sheets as $i=>$s) {
            $rels .= '<Relationship Id="rId'.($i+2).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i+1).'.xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
    }

    private function _parseStyle($key) {
        $p = ['bold'=>false,'italic'=>false,'underline'=>false,
              'fontSize'=>10,'fontName'=>'Arial',
              'fg'=>'000000','bg'=>null,
              'align'=>'general','valign'=>'bottom',
              'wrapText'=>false,'border'=>null,'numFmt'=>null];
        foreach (explode('|', $key) as $part) {
            $part = trim((string)($part));
            if ($part==='bold')              $p['bold']     = true;
            elseif ($part==='italic')        $p['italic']   = true;
            elseif ($part==='wrap')          $p['wrapText'] = true;
            elseif (strpos($part,'fontSize:')===0)  $p['fontSize'] = (float)substr($part,9);
            elseif (strpos($part,'fontName:')===0)  $p['fontName'] = substr($part,9);
            elseif (strpos($part,'align:')===0)     $p['align']    = substr($part,6);
            elseif (strpos($part,'valign:')===0) {
                // OOXML only allows top|center|bottom|justify|distributed.
                // "middle" (used throughout this codebase) is NOT valid and made
                // the whole workbook unreadable for strict consumers.
                $v = strtolower((string)(substr($part,7)));
                $p['valign'] = ($v === 'middle') ? 'center' : $v;
            }
            elseif (strpos($part,'bg:')===0)        $p['bg']       = substr($part,3);
            elseif (strpos($part,'fg:')===0)        $p['fg']       = substr($part,3);
            elseif (strpos($part,'border:')===0)    $p['border']   = substr($part,7);
            elseif (strpos($part,'numFmt:')===0)    $p['numFmt']   = substr($part,7);
        }
        return $p;
    }

    private function _styles() {
        $allStyles = ['' => $this->_parseStyle('')];
        foreach ($this->styleIndex as $key=>$idx) $allStyles[$key] = $this->_parseStyle($key);

        $customNumFmtId = 164; $customFmts = []; $numFmtXml = '';
        $fonts = ['<font><sz val="10"/><name val="Arial"/></font>']; $fontMap = [];
        $fills = ['<fill><patternFill patternType="none"/></fill>','<fill><patternFill patternType="gray125"/></fill>']; $fillMap = [];
        $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>']; $borderMap = [];
        $numFmtMap = [];

        foreach ($allStyles as $key=>$p) {
            // The default style ('') must keep pointing at fontId 0, otherwise
            // cellXfs[0] is not a "default" xf any more and strict readers warn
            // that the workbook has no default style.
            if ($key === '') {
                $fontMap[$key] = 0; $fillMap[$key] = 0; $borderMap[$key] = 0; $numFmtMap[$key] = 0;
                continue;
            }
            // font
            $fxml = '<font>'.($p['bold']?'<b/>':'').($p['italic']?'<i/>':'').($p['underline']?'<u/>':'')
                   .'<sz val="'.$p['fontSize'].'"/><color rgb="FF'.$p['fg'].'"/><name val="'.$p['fontName'].'"/></font>';
            if (!in_array($fxml,$fonts)){$fontMap[$key]=count($fonts);$fonts[]=$fxml;}
            else $fontMap[$key]=array_search($fxml,$fonts);
            // fill
            if ($p['bg']) {
                $fx = '<fill><patternFill patternType="solid"><fgColor rgb="FF'.$p['bg'].'"/><bgColor indexed="64"/></patternFill></fill>';
                if (!in_array($fx,$fills)){$fillMap[$key]=count($fills);$fills[]=$fx;}
                else $fillMap[$key]=array_search($fx,$fills);
            } else $fillMap[$key]=0;
            // border
            if ($p['border']) {
                $b=$p['border'];
                $bx="<border><left style=\"$b\"><color rgb=\"FF000000\"/></left><right style=\"$b\"><color rgb=\"FF000000\"/></right><top style=\"$b\"><color rgb=\"FF000000\"/></top><bottom style=\"$b\"><color rgb=\"FF000000\"/></bottom><diagonal/></border>";
                if (!in_array($bx,$borders)){$borderMap[$key]=count($borders);$borders[]=$bx;}
                else $borderMap[$key]=array_search($bx,$borders);
            } else $borderMap[$key]=0;
            // numFmt
            // '0' is a valid, *falsy* format string: the plain-integer format
            // was silently dropped to General before this guard existed
            if ($p['numFmt'] !== null && $p['numFmt'] !== '') {
                if (isset($this->builtinFormats[$p['numFmt']])) { $numFmtMap[$key]=$this->builtinFormats[$p['numFmt']]; }
                else { $id=$customNumFmtId++;$customFmts[]=$id;$numFmtXml.='<numFmt numFmtId="'.$id.'" formatCode="'.$this->_xml($p['numFmt']).'"/>';$numFmtMap[$key]=$id; }
            } else $numFmtMap[$key]=0;
        }

        // xfs (index 0 = default/empty key, then registered styles in order)
        $xfOrder = array_merge([''], array_keys($this->styleIndex));
        $xfs = '';
        foreach ($xfOrder as $key) {
            $fIdx = isset($fontMap[$key])   ? $fontMap[$key]   : 0;
            $lIdx = isset($fillMap[$key])   ? $fillMap[$key]   : 0;
            $bIdx = isset($borderMap[$key]) ? $borderMap[$key] : 0;
            $nIdx = isset($numFmtMap[$key]) ? $numFmtMap[$key] : 0;
            $p    = $allStyles[$key];
            $al   = ($p['align']!=='general'||$p['valign']!=='bottom'||$p['wrapText'])
                  ? '<alignment horizontal="'.$p['align'].'" vertical="'.$p['valign'].'"'.($p['wrapText']?' wrapText="1"':'').'/>'
                  : '';
            $xfs .= '<xf numFmtId="'.$nIdx.'" fontId="'.$fIdx.'" fillId="'.$lIdx.'" borderId="'.$bIdx.'"'
                  .($nIdx?' applyNumberFormat="1"':'').($fIdx?' applyFont="1"':'')
                  .($lIdx?' applyFill="1"':'').($bIdx?' applyBorder="1"':'')
                  .($al?' applyAlignment="1"':'').'>'.$al.'</xf>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="'.count($customFmts).'">'.$numFmtXml.'</numFmts>
<fonts count="'.count($fonts).'">'.implode('',$fonts).'</fonts>
<fills count="'.count($fills).'">'.implode('',$fills).'</fills>
<borders count="'.count($borders).'">'.implode('',$borders).'</borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="'.count($xfOrder).'">'.$xfs.'</cellXfs>
</styleSheet>';
    }

    private function _sharedStrings() {
        $count = count($this->sharedStrings);
        $items = '';
        foreach ($this->sharedStrings as $s) {
            $items .= '<si><t xml:space="preserve">'.htmlspecialchars((string)($s), ENT_XML1).'</t></si>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.$count.'" uniqueCount="'.$count.'">'.$items.'</sst>';
    }

    private function _sheet($sheet, $plan = array()) {
        $colsXml = '';
        if (!empty($sheet['colWidths'])) {
            $colsXml = '<cols>';
            foreach ($sheet['colWidths'] as $col=>$w) {
                $colsXml .= '<col min="'.$col.'" max="'.$col.'" width="'.$w.'" customWidth="1"/>';
            }
            $colsXml .= '</cols>';
        }

        $rowsXml = '';
        foreach ($sheet['rows'] as $ri=>$row) {
            $rowNum = $ri+1;
            $ht = isset($sheet['rowHeights'][$rowNum]) ? ' ht="'.$sheet['rowHeights'][$rowNum].'" customHeight="1"' : '';
            $cells = '';
            foreach ($row as $ci=>$cell) {
                $ref  = $this->_cellRef($ci+1, $rowNum);
                $val  = $cell['v'];
                $sKey = isset($cell['s']) ? $cell['s'] : '';
                // styleIndex: 0 = default, registered styles start at 1.
                // Unknown style keys are registered on the fly (see _styleIndex).
                $sIdx = $this->_styleIndex($sKey);

                if ($val===null && isset($cell['f'])) {
                    // Formula without a cached result (Excel recalculates on open).
                    $cells .= '<c r="'.$ref.'" s="'.$sIdx.'"><f>'.$this->_xml($cell['f']).'</f></c>';
                } elseif ($val===null || $val==='') {
                    $cells .= '<c r="'.$ref.'" s="'.$sIdx.'"/>';
                } elseif (is_string($val) && strlen((string)($val))>0 && $val[0]==='=') {
                    $cells .= '<c r="'.$ref.'" s="'.$sIdx.'"><f>'.$this->_xml(substr($val,1)).'</f></c>';
                } elseif (is_bool($val)) {
                    $cells .= '<c r="'.$ref.'" t="b" s="'.$sIdx.'"><v>'.($val?'1':'0').'</v></c>';
                } elseif (is_numeric($val) && !preg_match('/^0\d/',(string)$val)) {
                    $cells .= '<c r="'.$ref.'" t="n" s="'.$sIdx.'"><v>'.$this->_xml((string)$val).'</v></c>';
                } else {
                    $si = $this->_str((string)$val);
                    $cells .= '<c r="'.$ref.'" t="s" s="'.$sIdx.'"><v>'.$si.'</v></c>';
                }
            }
            $rowsXml .= '<row r="'.$rowNum.'"'.$ht.'>'.$cells.'</row>';
        }

        $mergesXml = '';
        if (!empty($sheet['merges'])) {
            $mergesXml = '<mergeCells count="'.count($sheet['merges']).'">';
            foreach ($sheet['merges'] as $m) $mergesXml .= '<mergeCell ref="'.$m.'"/>';
            $mergesXml .= '</mergeCells>';
        }

        // NOTE: element order inside <worksheet> is fixed by the OOXML schema:
        // sheetViews → cols → sheetData → autoFilter → mergeCells → dataValidations
        $viewsXml = '';
        if (!empty($sheet['freeze'])) {
            $ref  = $sheet['freeze'];
            $pfx  = preg_replace('/\d+$/', '', $ref);   // 'A3'  -> 'A'
            $row  = (int)preg_replace('/^\D+/', '', $ref); // 'A3' -> 3
            $colN = 0;
            foreach (str_split($pfx) as $ch) $colN = $colN*26 + (ord($ch)-64);
            $pane = '';
            if ($row > 1) $pane .= ' ySplit="'.($row-1).'"';
            if ($colN > 1) $pane .= ' xSplit="'.($colN-1).'"';
            if ($pane !== '') {
                $state = ($row > 1 && $colN > 1) ? 'bottomRight' : (($row > 1) ? 'bottomLeft' : 'topRight');
                $viewsXml = '<sheetViews><sheetView workbookViewId="0">'
                          . '<pane'.$pane.' topLeftCell="'.$ref.'" activePane="'.$state.'" state="frozen"/>'
                          . '</sheetView></sheetViews>';
            }
        }
        $filterXml = !empty($sheet['autoFilter'])
                   ? '<autoFilter ref="'.$sheet['autoFilter'].'"/>' : '';

        $validXml = '';
        if (!empty($sheet['validations'])) {
            $rules = '';
            foreach ($sheet['validations'] as $v) {
                $rules .= '<dataValidation type="'.htmlspecialchars((string)($v['type']), ENT_XML1).'"';
                if (isset($v['operator']))    $rules .= ' operator="'.htmlspecialchars((string)($v['operator']), ENT_XML1).'"';
                if (!empty($v['allowBlank'])) $rules .= ' allowBlank="1"';
                if (array_key_exists('showDropDown', $v)) $rules .= ' showDropDown="'.($v['showDropDown']?"0":"1").'"';
                $rules .= ' showInputMessage="1" showErrorMessage="1"';
                if (isset($v['errorStyle']))  $rules .= ' errorStyle="'.htmlspecialchars((string)($v['errorStyle']), ENT_XML1).'"';
                if (isset($v['errorTitle']))  $rules .= ' errorTitle="'.$this->_xml($v['errorTitle']).'"';
                if (isset($v['error']))       $rules .= ' error="'.$this->_xml($v['error']).'"';
                if (isset($v['promptTitle'])) $rules .= ' promptTitle="'.$this->_xml($v['promptTitle']).'"';
                if (isset($v['prompt']))      $rules .= ' prompt="'.$this->_xml($v['prompt']).'"';
                $rules .= ' sqref="'.htmlspecialchars((string)($v['sqref']), ENT_XML1).'">';
                if (isset($v['formula1'])) $rules .= '<formula1>'.$this->_xml($v['formula1']).'</formula1>';
                if (isset($v['formula2'])) $rules .= '<formula2>'.$this->_xml($v['formula2']).'</formula2>';
                $rules .= '</dataValidation>';
            }
            $validXml = '<dataValidations count="'.count($sheet['validations']).'">'.$rules.'</dataValidations>';
        }

        // pageMargins → pageSetup → … → drawing: the order the schema fixes
        $pageXml = '';
        if (!empty($sheet['pageMargins'])) {
            $m = $sheet['pageMargins'];
            $pageXml .= '<pageMargins left="'.$m['left'].'" right="'.$m['right'].'" top="'.$m['top'].'"'
                      . ' bottom="'.$m['bottom'].'" header="'.$m['header'].'" footer="'.$m['footer'].'"/>';
        }
        if (!empty($sheet['pageSetup'])) {
            $ps = $sheet['pageSetup'];
            if ($ps['fitToPage']) {
                $pageXml .= '<pageSetup paperSize="'.$ps['paperSize'].'"'
                          . (!empty($ps['scale']) ? ' scale="'.$ps['scale'].'"' : '')
                          . ' fitToWidth="'.$ps['fitToWidth'].'" fitToHeight="'.$ps['fitToHeight'].'"'
                          . ' orientation="'.$ps['orientation'].'" horizontalDpi="0" verticalDpi="0"/>';
            } else {
                $pageXml .= '<pageSetup paperSize="'.$ps['paperSize'].'" orientation="'.$ps['orientation'].'"'
                          . ' horizontalDpi="0" verticalDpi="0"'
                          . (!empty($ps['scale']) ? ' scale="'.$ps['scale'].'"' : '') . '/>';
            }
        }
        $drawingXml = !empty($plan['drawing']) ? '<drawing r:id="' . $plan['drawing'] . '"/>' : '';
        $tablesXml  = '';
        if (!empty($plan['tables'])) {
            $parts = '';
            foreach ($plan['tables'] as $t) $parts .= '<tablePart r:id="' . $t['rid'] . '"/>';
            $tablesXml = '<tableParts count="' . count($plan['tables']) . '">' . $parts . '</tableParts>';
        }

        $sheetPrXml = (!empty($sheet['pageSetup']) && $sheet['pageSetup']['fitToPage'])
                    ? '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
  xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
'.$sheetPrXml.$viewsXml.$colsXml.'<sheetData>'.$rowsXml.'</sheetData>'.$filterXml.$mergesXml.$validXml
.$pageXml.$drawingXml.$tablesXml.'</worksheet>';
    }
}
} // end if (!class_exists('XlsxWriter'))
