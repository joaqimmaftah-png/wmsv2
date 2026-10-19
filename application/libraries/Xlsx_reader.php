<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Xlsx_reader — minimal, dependency-free .xlsx reader (ZipArchive + SimpleXML).
 *
 * Mirrors the capabilities of the project's XlsxWriter so exported files can be
 * edited and re-imported.  Supports:
 *   - shared strings, inline strings, formula string results, numbers,
 *     booleans, error cells
 *   - dates stored as Excel serial numbers (built-in 14-22/45-47 and custom
 *     date number formats), including the 1900 leap-year bug and 1904 workbooks
 *   - multi-sheet workbooks (pick a sheet by name, else the first sheet)
 *
 * Safety: rejects non-xlsx files, limits the number of zip entries and the
 * amount of uncompressed XML that may be read per file (zip-bomb guard) and
 * never resolves external XML entities (LIBXML_NONET).
 *
 * Usage:
 *   $this->load->library('xlsx_reader');
 *   $data = $this->xlsx_reader->read('/path/file.xlsx', ['Assets']);
 *   // $data['headers'][1] === 'Machine ID';  $data['rows'][0]['cells'][1] === 'EX 001'
 */
class Xlsx_reader {

    /** Zip-bomb guards */
    const MAX_ENTRIES      = 3000;      // files inside the .xlsx package
    const MAX_PART_BYTES   = 25165824;  // 24 MB uncompressed per part
    const MAX_TOTAL_BYTES  = 67108864;  // 64 MB uncompressed in total

    /** Built-in number-format ids that represent a date/time */
    private $dateFmtIds = array(14,15,16,17,18,19,20,21,22,27,28,29,30,31,32,33,34,35,36,45,46,47,50,51,52,53,54,55,56,57,58);

    private $lastError = null;

    public function __construct($params = array()) { /* no configuration required */ }

    public function last_error() { return $this->lastError; }

    /**
     * List the sheet names of a workbook (name => part path).
     */
    public function sheet_names($filePath) {
        $parts = $this->_open($filePath);
        if ($parts === false) return array();
        try {
            list($sheets) = $this->_readWorkbook($parts);
            $this->_close($parts);
            return $sheets;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->_close($parts);
            return array();
        }
    }

    /**
     * Read a worksheet into headers + rows.
     *
     * @param string $filePath     absolute path to the .xlsx file
     * @param array  $preferSheets sheet names to prefer (case-insensitive), in order
     * @param int    $maxRows      stop after this many data rows (0 = unlimited)
     * @param array  $opts         ['header_row' => int]        force a header row
     *                             ['header_hints' => string[]] labels to look for:
     *                             the first row within the first 10 rows that
     *                             contains at least 2 of them is used as the
     *                             header row (files often have a title above it)
     * @return array|false  ['sheet','sheets','headers','header_row','rows','truncated']
     *                      rows: list of ['row'=>int,'cells'=>[colIdx=>value]]
     */
    public function read($filePath, $preferSheets = array(), $maxRows = 0, $opts = array()) {
        $parts = $this->_open($filePath);
        if ($parts === false) return false;

        try {
            list($sheets, $date1904) = $this->_readWorkbook($parts);

            // Choose the worksheet to read
            $target = null; $targetName = null;
            foreach ($preferSheets as $want) {
                foreach ($sheets as $name => $path) {
                    if (strcasecmp($name, $want) === 0) { $target = $path; $targetName = $name; break 2; }
                }
            }
            if ($target === null) {
                // fall back to the first sheet that is not an Instructions/README sheet
                foreach ($sheets as $name => $path) {
                    if (stripos($name, 'instruct') === false && stripos($name, 'read') === false) {
                        $target = $path; $targetName = $name; break;
                    }
                }
            }
            if ($target === null) {
                if (empty($sheets)) throw new RuntimeException('The workbook contains no worksheets.');
                reset($sheets); $targetName = key($sheets); $target = current($sheets);
            }

            $sharedStrings = isset($parts['xl/sharedStrings.xml'])
                ? $this->_sharedStrings($this->_part($parts, 'xl/sharedStrings.xml'))
                : array();

            list($styleDateFlags, $styleDateTimes) = $this->_styles($parts);

            $xml = $this->_part($parts, $target);
            if ($xml === null) throw new RuntimeException('Worksheet "'.$targetName.'" could not be read.');

            $result = $this->_worksheet($xml, $sharedStrings, $styleDateFlags, $styleDateTimes, $date1904, $targetName, array_keys($sheets), $maxRows, $opts);
            $this->_close($parts);
            return $result;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->_close($parts);
            return false;
        }
    }

    /* ─────────────────────────────────────────────────────────────
       internals
       ───────────────────────────────────────────────────────────── */

    /** Open the package and return a map of entry name => true (with size guards). */
    private function _open($filePath) {
        if (!class_exists('ZipArchive')) {
            $this->lastError = 'The PHP zip extension is not enabled on this server.';
            return false;
        }
        if (!is_file($filePath)) {
            $this->lastError = 'File not found.';
            return false;
        }
        $head = @file_get_contents($filePath, false, null, 0, 4);
        if ($head !== 'PK' . "\x03\x04" && $head !== 'PK' . "\x05\x06") {
            $this->lastError = 'This does not look like a .xlsx file. '
                             . 'Old Excel files (.xls) and CSV files must be saved as .xlsx before importing.';
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            $this->lastError = 'The workbook could not be opened (corrupt or password protected).';
            return false;
        }
        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();
            $this->lastError = 'The workbook contains too many internal parts.';
            return false;
        }
        $entries = array(); $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) continue;
            if ($stat['size'] > self::MAX_PART_BYTES) {
                $zip->close();
                $this->lastError = 'A part of the workbook is too large to read safely.';
                return false;
            }
            $total += $stat['size'];
            if ($total > self::MAX_TOTAL_BYTES) {
                $zip->close();
                $this->lastError = 'The workbook is too large to read safely.';
                return false;
            }
            $entries[$stat['name']] = true;
        }
        $entries['__zip'] = $zip;
        return $entries;
    }

    private function _close($parts) {
        if (isset($parts['__zip']) && $parts['__zip'] instanceof ZipArchive) $parts['__zip']->close();
    }

    /** Read one entry from the package as a string. */
    private function _part($parts, $name) {
        if (!isset($parts[$name]) || !isset($parts['__zip'])) return null;
        $data = $parts['__zip']->getFromName($name);
        return ($data === false) ? null : $data;
    }

    /** Parse xl/workbook.xml + rels: returns [name => part path, date1904 bool] */
    private function _readWorkbook($parts) {
        $xml = $this->_part($parts, 'xl/workbook.xml');
        if ($xml === null) throw new RuntimeException('xl/workbook.xml is missing — not a valid .xlsx workbook.');

        $date1904 = false;
        if (preg_match('/<workbookPr[^>]*date1904="(1|true)"/i', $xml)) $date1904 = true;

        // r:id => target, from the workbook relationships
        $rels = array();
        $relsXml = $this->_part($parts, 'xl/_rels/workbook.xml.rels');
        if ($relsXml !== null && preg_match_all('/<Relationship\b[^>]*>/i', $relsXml, $m)) {
            foreach ($m[0] as $tag) {
                if (!preg_match('/Id="([^"]+)"/i', $tag, $id)) continue;
                if (!preg_match('/Target="([^"]+)"/i', $tag, $t)) continue;
                $target = str_replace('\\', '/', $t[1]);
                $target = ltrim($target, '/');
                if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
                $rels[$id[1]] = $target;
            }
        }

        $sheets = array(); $i = 0;
        if (preg_match_all('/<sheet\b[^>]*>/i', $xml, $m)) {
            foreach ($m[0] as $tag) {
                $i++;
                $name = 'Sheet'.$i;
                if (preg_match('/name="([^"]*)"/i', $tag, $n)) $name = $this->_xmlDecode($n[1]);
                $path = null;
                if (preg_match('/r:id="([^"]*)"/i', $tag, $r) && isset($rels[$r[1]])) $path = $rels[$r[1]];
                if ($path === null) $path = 'xl/worksheets/sheet'.$i.'.xml';
                if (!isset($parts[$path])) {
                    // try to find the part by suffix (some writers use absolute targets)
                    foreach ($parts as $candidate => $unused) {
                        if (substr($candidate, -strlen(basename($path))) === basename($path)) { $path = $candidate; break; }
                    }
                }
                $sheets[$name] = $path;
            }
        }
        return array($sheets, $date1904);
    }

    /** @return array list of strings (index = shared string index) */
    private function _sharedStrings($xml) {
        if ($xml === null) return array();
        $out = array();
        if (!preg_match_all('/<si\b[^>]*>(.*?)<\/si>|<si\b[^>]*\/>/s', $xml, $m, PREG_SET_ORDER)) return $out;
        foreach ($m as $si) {
            $inner = isset($si[1]) ? $si[1] : '';
            $text  = '';
            if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $inner, $t)) {
                foreach ($t[1] as $piece) $text .= $this->_xmlDecode($piece);
            } elseif (isset($si[1])) {
                $text = $this->_xmlDecode(strip_tags($inner));
            }
            $out[] = $text;
        }
        return $out;
    }

    /**
     * Parse xl/styles.xml → per-cellXfs-index flags telling whether the style
     * formats a date / a date+time.
     */
    private function _styles($parts) {
        $custom = array();
        $xml = $this->_part($parts, 'xl/styles.xml');
        if ($xml === null) return array(array(), array());

        if (preg_match_all('/<numFmt\b[^>]*\/?>/i', $xml, $m)) {
            foreach ($m[0] as $tag) {
                if (!preg_match('/numFmtId="(\d+)"/i', $tag, $id)) continue;
                if (!preg_match('/formatCode="([^"]*)"/i', $tag, $code)) continue;
                $custom[(int)$id[1]] = $this->_xmlDecode($code[1]);
            }
        }
        // cellXfs: order == style index used by cells ($cell['s'])
        $date = array(); $datetime = array();
        if (preg_match('/<cellXfs\b[^>]*>(.*?)<\/cellXfs>/is', $xml, $m)) {
            if (preg_match_all('/<xf\b[^>]*>/i', $m[1], $xfs)) {
                $idx = 0;
                foreach ($xfs[0] as $xf) {
                    $numFmtId = 0;
                    if (preg_match('/numFmtId="(\d+)"/i', $xf, $id)) $numFmtId = (int)$id[1];
                    $code = isset($custom[$numFmtId]) ? $custom[$numFmtId] : null;
                    $date[$idx]     = $this->_isDateFormat($numFmtId, $code);
                    $datetime[$idx] = $this->_hasTimePart($numFmtId, $code);
                    $idx++;
                }
            }
        }
        return array($date, $datetime);
    }

    private function _isDateFormat($numFmtId, $code) {
        if ($code === null) return in_array($numFmtId, $this->dateFmtIds, true);
        $clean = $this->_stripFormatLiterals($code);
        if ($clean === '') return in_array($numFmtId, $this->dateFmtIds, true);
        return (bool)preg_match('/[ymdhs]/i', $clean);
    }

    private function _hasTimePart($numFmtId, $code) {
        if ($code === null) return in_array($numFmtId, array(18,19,20,21,22,45,46,47), true);
        $clean = $this->_stripFormatLiterals($code);
        return (bool)preg_match('/[hs]/i', $clean);
    }

    /** Remove quoted literals and [colour]/[condition] blocks from a format code */
    private function _stripFormatLiterals($code) {
        $code = preg_replace('/"([^"]*)"/', '', $code);
        $code = preg_replace('/\[[^\]]*\]/', '', $code);
        $code = preg_replace('/\\\\./', '', $code);
        return $code;
    }

    /**
     * Convert an Excel serial date to Y-m-d / Y-m-d H:i:s.
     *  - 1900 system: serial 1 = 1900-01-01 and serial 60 is the non-existent
     *    1900-02-29 (the classic leap-year bug), hence the two epochs.
     *  - 1904 system (rare, Mac Excel): serial 0 = 1904-01-01.
     */
    private function _serialToDate($serial, $date1904, $withTime) {
        $serial = (float)$serial;
        $days   = (int)floor($serial);
        $secs   = (int)round(($serial - $days) * 86400);

        if ($date1904) {
            $unix = mktime(0, 0, 0, 1, 1, 1904) + $days * 86400;
        } else {
            $epoch = ($days >= 61) ? mktime(0, 0, 0, 12, 30, 1899)   // correct epoch
                                   : mktime(0, 0, 0, 12, 31, 1899);  // pre-1900-03-01
            $unix = $epoch + $days * 86400;
        }
        if ($withTime && $secs > 0) {
            return date('Y-m-d H:i:s', $unix + $secs);
        }
        return date('Y-m-d', $unix);
    }

    /** Parse one worksheet part into headers + rows */
    private function _worksheet($xml, $sharedStrings, $dateStyle, $timeStyle, $date1904, $sheetName, $sheetNames, $maxRows, $opts = array()) {
        // Column letter => 1-based index for cells like <c r="AB12">
        $colIndex = array();

        $reader = $this;

        $headers = array();
        $rows = array();
        $headerRowNum = 0;
        $rowNum = 0;
        $truncated = false;
        $allRows = array();       // every non-empty row, in file order

        // Stream <row> blocks (avoids DOM/SimpleXML memory blow-up on big sheets)
        if (preg_match_all('/<row\b[^>]*?\/>|<row\b[^>]*?>(.*?)<\/row>/s', $xml, $rowMatches, PREG_SET_ORDER)) {
            foreach ($rowMatches as $rm) {
                $rowNum++;
                $rowTag = $rm[0];
                if (preg_match('/\br="(\d+)"/', $rowTag, $rr)) $rowNum = (int)$rr[1];
                $inner = isset($rm[1]) ? $rm[1] : '';
                if ($inner === '') continue; // empty row

                $cells = array();
                // NOTE: the attribute group must be LAZY, otherwise "[^>]*" eats the
                // slash of an empty self-closing cell (<c r="G3" s="11"/>) and the
                // match then stretches over the following cells, shifting columns.
                if (preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $inner, $cMatches, PREG_SET_ORDER)) {
                    $autoCol = 0;
                    foreach ($cMatches as $cm) {
                        $attrs = $cm[1];
                        $body  = isset($cm[2]) ? $cm[2] : '';
                        $autoCol++;
                        $col = $autoCol;
                        if (preg_match('/\br="([A-Z]+)\d+"/i', $attrs, $cr)) {
                            $letters = strtoupper($cr[1]);
                            if (!isset($colIndex[$letters])) {
                                $n = 0;
                                foreach (str_split($letters) as $ch) $n = $n * 26 + (ord($ch) - 64);
                                $colIndex[$letters] = $n;
                            }
                            $col = $colIndex[$letters];
                            $autoCol = $col;
                        }
                        $type = '';
                        if (preg_match('/\bt="(\w+)"/', $attrs, $tr)) $type = strtolower($tr[1]);
                        $styleIdx = 0;
                        if (preg_match('/\bs="(\d+)"/', $attrs, $sr)) $styleIdx = (int)$sr[1];

                        $value = $reader->cell_value($body, $type, $styleIdx, $sharedStrings, $dateStyle, $timeStyle, $date1904);
                        if ($value !== '' && $value !== null) $cells[$col] = $value;
                    }
                }
                if (empty($cells)) continue;
                $allRows[] = array('row' => $rowNum, 'cells' => $cells);
            }
        }

        // Choose the header row: explicit > best match against the hints > first row
        $headerIdx = 0;
        if (isset($opts['header_row']) && (int)$opts['header_row'] > 0) {
            foreach ($allRows as $i => $r) {
                if ((int)$r['row'] === (int)$opts['header_row']) { $headerIdx = $i; break; }
            }
        } elseif (!empty($opts['header_hints'])) {
            $limit = min(count($allRows), 10);
            $best = 0; $bestScore = 0;
            for ($i = 0; $i < $limit; $i++) {
                $score = $this->_hint_score($allRows[$i]['cells'], $opts['header_hints']);
                if ($score > $bestScore) { $bestScore = $score; $best = $i; }
            }
            if ($bestScore >= 2) $headerIdx = $best;
        }

        if (isset($allRows[$headerIdx])) {
            $headers      = $allRows[$headerIdx]['cells'];
            $headerRowNum = $allRows[$headerIdx]['row'];
        }
        for ($i = $headerIdx + 1; $i < count($allRows); $i++) {
            $rows[] = $allRows[$i];
            if ($maxRows > 0 && count($rows) >= $maxRows) { $truncated = true; break; }
        }

        return array(
            'sheet'      => $sheetName,
            'sheets'     => $sheetNames,
            'headers'    => $headers,
            'header_row' => $headerRowNum,
            'rows'       => $rows,
            'truncated'  => $truncated,
        );
    }

    /** How many of the expected header labels appear in a candidate row */
    private function _hint_score($cells, $hints) {
        $normalise = function($s) {
            $s = (string)$s;
            $s = preg_replace('/[^A-Za-z0-9]/', '', $s);
            return strtolower($s);
        };
        $wanted = array();
        foreach ($hints as $h) { $w = $normalise($h); if ($w !== '') $wanted[$w] = true; }
        $score = 0;
        foreach ($cells as $v) {
            $n = $normalise($v);
            if (isset($wanted[$n])) $score++;
        }
        return $score;
    }

    /**
     * Turn one <c> element body into a PHP value.
     * Public so the worksheet parser can call it from a closure-free context.
     */
    public function cell_value($body, $type, $styleIdx, $sharedStrings, $dateStyle, $timeStyle, $date1904) {
        if ($body === '' || $body === null) return '';

        // <is><t>..</t></is> (inline string)  |  <v>..</v> (value)
        if ($type === 'inlinestr') {   // attribute is inlineStr; normalised to lowercase
            $text = '';
            if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $body, $t)) {
                foreach ($t[1] as $piece) $text .= $this->_xmlDecode($piece);
            }
            return trim($text);
        }
        if (!preg_match('/<v\b[^>]*>(.*?)<\/v>/s', $body, $v)) {
            // formula cell without a cached value — nothing to import
            return '';
        }
        $raw = $this->_xmlDecode($v[1]);

        switch ($type) {
            case 's':
                $idx = (int)$raw;
                return isset($sharedStrings[$idx]) ? trim($sharedStrings[$idx]) : '';
            case 'b':
                return ($raw === '1') ? 'TRUE' : 'FALSE';
            case 'e':
                return trim($raw);   // #N/A, #DIV/0!, ...
            case 'str':
                return trim($raw);   // formula string result
        }

        // numeric (default)
        $raw = trim($raw);
        if ($raw === '') return '';
        if (isset($dateStyle[$styleIdx]) && $dateStyle[$styleIdx] && is_numeric($raw)) {
            return $this->_serialToDate($raw, $date1904, !empty($timeStyle[$styleIdx]));
        }
        return $raw;
    }

    /** Decode XML entities without touching external entities */
    private function _xmlDecode($s) {
        return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
