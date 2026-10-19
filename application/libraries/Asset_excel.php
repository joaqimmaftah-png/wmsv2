<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Asset_excel — workbook builders for the Asset Register module.
 *
 *   export_register()    full fleet register (every stored field + KPIs) and an
 *                        "Assets" sheet that can be edited and re-imported
 *   build_template()     empty import template with the exact column structure,
 *                        example rows, dropdowns and an instructions sheet
 *   import_report()      per-row import result (successes + failures + reasons)
 *
 * Everything runs on the project's own XlsxWriter helper — no external
 * libraries, no new server requirements.
 */
class Asset_excel {

    /** Styles reused by every sheet */
    private $styles = array();

    public function __construct($params = array()) { /* stateless */ }

    /** Load the writer helper (works both inside CI and in standalone tests). */
    public function writer() {
        if (!class_exists('XlsxWriter')) {
            if (function_exists('get_instance')) {
                get_instance()->load->helper('xlsx');
            } else {
                require_once APPPATH.'helpers/xlsx_helper.php';
            }
        }
        return new XlsxWriter();
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       EXPORT — full asset register
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * @param array  $data     result of Asset_model::get_register()
     * @param string $filePath destination (writable) path
     * @param array  $opts     ['title'=>..,'generated_by'=>..,'columns'=>..]
     */
    public function export_register($data, $filePath, $opts = array()) {
        $x = $this->writer();
        $s = $this->registerStyles($x);

        $title = isset($opts['title']) ? $opts['title'] : 'ASSET REGISTER';
        $stamp = date('d/m/Y H:i');

        /* ── Sheet 1: full register (human readable, everything) ── */
        $x->addSheet('Asset Register');
        $cols = $this->fullColumns();
        foreach ($cols as $i => $c) $x->setColWidth($i + 1, $c['w']);
        $lastCol = $this->_colLetter(count($cols));

        $x->setRowHeight(1, 28);
        $titleRow = array(array('v' => $title.' — '.strtoupper(date('d F Y')), 's' => $s['title']));
        for ($i = 1; $i < count($cols); $i++) $titleRow[] = array('v' => '', 's' => $s['title']);
        $x->writeRowStyled($titleRow);
        $x->mergeCells('A1', $lastCol.'1');
        $x->setRowHeight(2, 15);
        $sub = array(array('v' => 'Generated '.$stamp
                            .(isset($opts['generated_by']) && $opts['generated_by'] !== '' ? ' by '.$opts['generated_by'] : '')
                            .'  •  '.count($data['assets']).' assets  •  Availability = (operating hours − downtime) ÷ operating hours',
                     's' => $s['sub']));
        for ($i = 1; $i < count($cols); $i++) $sub[] = array('v' => '', 's' => $s['sub']);
        $x->writeRowStyled($sub);
        $x->mergeCells('A2', $lastCol.'2');

        $x->setRowHeight(3, 32);
        $head = array();
        foreach ($cols as $c) $head[] = array('v' => $c['label'], 's' => $s['head']);
        $x->writeRowStyled($head);

        $row = 4;
        foreach ($data['assets'] as $a) {
            $cells = array();
            foreach ($cols as $c) {
                $cells[] = $this->_cell($a, $c, $s, $row);
            }
            $x->setRowHeight($row, 17);
            $x->writeRowStyled($cells);
            $row++;
        }
        $firstData = 4; $lastData = $row - 1;

        // Totals / average row.  The column letters are derived from the column
        // definition (never hard-coded) so adding or reordering a column can not
        // silently point a SUM at the wrong field.
        if ($lastData >= $firstData) {
            $x->setRowHeight($row, 18);
            $letter = array();
            foreach ($cols as $i => $c) $letter[$c['key']] = $this->_colLetter($i + 1);
            $sum  = function ($key) use ($letter, $firstData, $lastData) {
                return array('v' => '=SUM('.$letter[$key].$firstData.':'.$letter[$key].$lastData.')', 's' => 'totN');
            };
            $avg  = function ($key) use ($letter, $firstData, $lastData) {
                return array('v' => '=AVERAGE('.$letter[$key].$firstData.':'.$letter[$key].$lastData.')', 's' => 'totP');
            };
            $totals = array(
                'id'      => array('v' => 'TOTALS', 's' => 'tot'),
                'reg'     => array('v' => count($data['assets']).' assets', 's' => 'totC'),
                'hours'   => $sum('hours'),
                'km'      => $sum('km'),
                'total_wo'=> $sum('total_wo'),
                'breakdowns' => $sum('breakdowns'),
                'corrective' => $sum('corrective'),
                'completed'  => $sum('completed'),
                'open_wo'    => $sum('open_wo'),
                'down'       => $sum('down'),
                'availability' => $avg('availability'),
            );
            foreach ($totals as $k => $t) $totals[$k]['s'] = $s[$t['s']];
            $cells = array();
            foreach ($cols as $c) {
                $cells[] = isset($totals[$c['key']]) ? $totals[$c['key']] : array('v' => '', 's' => $s['tot']);
            }
            $x->writeRowStyled($cells);
            $row++;
        }

        $x->freezePane('D4');
        if ($lastData >= $firstData) $x->setAutoFilter('A3:'.$lastCol.$lastData);

        /* ── Sheet 2: "Assets" — import-compatible view of the same data ── */
        $importCols = $this->importColumns();
        $x->addSheet('Assets');
        foreach ($importCols as $i => $c) $x->setColWidth($i + 1, $c['w']);
        $x->writeRow(array_map(function($c){ return $c['label']; }, $importCols), $s['head']);
        $x->setRowHeight(1, 30);
        $x->freezePane('A2');
        $ir = 2;
        foreach ($data['assets'] as $a) {
            $cells = array();
            foreach ($importCols as $c) $cells[] = $this->_cell($a, $c, $s, $ir);
            $x->writeRowStyled($cells);
            $ir++;
        }
        if ($ir > 2) $x->setAutoFilter('A1:'.$this->_colLetter(count($importCols)).($ir - 1));

        /* ── Sheet 3: notes / definitions ── */
        $this->_notesSheet($x, $s, array(
            'ASSET REGISTER EXPORT',
            '',
            '• Sheet "Asset Register" — complete fleet listing: every field stored for each',
            '  asset plus lifetime work-order statistics and the next preventive maintenance due.',
            '• Sheet "Assets" — the same assets in the import layout. Edit this sheet and use',
            '  Asset Register → Import Excel to bulk-create or update assets.',
            '• "Breakdown WOs" counts work orders flagged as breakdown (work_orders.is_breakdown):',
            '  corrective work that took a machine out of service, including rows created before the',
            '  flag existed. "Corrective WOs" counts the type "Corrective Maintenance".',
            '• Availability % = (current hours − accumulated downtime) ÷ current hours.',
            '• Blank cells mean "not recorded" — importing a blank cell never clears an',
            '  existing value.',
            '• Generated '.$stamp.'.',
        ));

        return $x->save($filePath);
    }

    /** Column definition for the full register sheet. */
    public function fullColumns() {
        return array(
            array('key' => 'id',           'label' => 'ID',              'w' => 6,  'type' => 'int'),
            array('key' => 'reg',          'label' => 'MACHINE ID',      'w' => 15, 'type' => 'text', 'bold' => true),
            array('key' => 'type',         'label' => 'TYPE',            'w' => 15, 'type' => 'text'),
            array('key' => 'category',     'label' => 'CATEGORY',        'w' => 13, 'type' => 'text'),
            array('key' => 'brand',        'label' => 'BRAND',           'w' => 14, 'type' => 'text'),
            array('key' => 'model',        'label' => 'MODEL',           'w' => 16, 'type' => 'text'),
            array('key' => 'year',         'label' => 'YEAR',            'w' => 7,  'type' => 'int'),
            array('key' => 'serial',       'label' => 'SERIAL NO',       'w' => 18, 'type' => 'text'),
            array('key' => 'engine',       'label' => 'ENGINE MODEL',    'w' => 18, 'type' => 'text'),
            array('key' => 'hours',        'label' => 'CURRENT HOURS',   'w' => 13, 'type' => 'num', 'fmt' => '#,##0.0'),
            array('key' => 'km',           'label' => 'CURRENT KM',      'w' => 11, 'type' => 'num', 'fmt' => '#,##0.0'),
            array('key' => 'location',     'label' => 'LOCATION',        'w' => 15, 'type' => 'text'),
            array('key' => 'status',       'label' => 'STATUS',          'w' => 13, 'type' => 'status'),
            array('key' => 'purchase',     'label' => 'PURCHASE DATE',   'w' => 13, 'type' => 'date'),
            array('key' => 'warranty',     'label' => 'WARRANTY EXPIRY', 'w' => 13, 'type' => 'date'),
            array('key' => 'warranty_st',  'label' => 'WARRANTY',        'w' => 14, 'type' => 'warranty'),
            array('key' => 'warranty_d',   'label' => 'DAYS LEFT',       'w' => 9,  'type' => 'int'),
            array('key' => 'total_wo',     'label' => 'TOTAL WOs',       'w' => 9,  'type' => 'int'),
            array('key' => 'breakdowns',   'label' => 'BREAKDOWN WOs',   'w' => 10, 'type' => 'int'),
            array('key' => 'corrective',   'label' => 'CORRECTIVE WOs',  'w' => 10, 'type' => 'int'),
            array('key' => 'completed',    'label' => 'COMPLETED WOs',   'w' => 10, 'type' => 'int'),
            array('key' => 'open_wo',      'label' => 'OPEN WOs',        'w' => 9,  'type' => 'int'),
            array('key' => 'down',         'label' => 'DOWNTIME (h)',    'w' => 11, 'type' => 'num', 'fmt' => '#,##0.0'),
            array('key' => 'availability', 'label' => 'AVAILABILITY %',  'w' => 11, 'type' => 'pct'),
            array('key' => 'next_pm',      'label' => 'NEXT PM',         'w' => 22, 'type' => 'text'),
            array('key' => 'pm_date',      'label' => 'PM DUE DATE',     'w' => 13, 'type' => 'date'),
            array('key' => 'pm_hours',     'label' => 'PM DUE HOURS',    'w' => 11, 'type' => 'num', 'fmt' => '#,##0.0'),
            array('key' => 'pm_last',      'label' => 'LAST PM DONE',    'w' => 13, 'type' => 'date'),
            array('key' => 'pm_interval',  'label' => 'PM INTERVAL',     'w' => 12, 'type' => 'text'),
            array('key' => 'notes',        'label' => 'NOTES',           'w' => 34, 'type' => 'text'),
            array('key' => 'created',      'label' => 'CREATED AT',      'w' => 16, 'type' => 'datetime'),
            array('key' => 'updated',      'label' => 'UPDATED AT',      'w' => 16, 'type' => 'datetime'),
        );
    }

    /**
     * Column definition of the import layout (also used by the template).
     * 'field' is the equipment column the importer writes to.
     */
    public function importColumns() {
        return array(
            array('key' => 'reg',      'label' => 'Machine ID',     'field' => 'registration_no',   'w' => 16, 'type' => 'text', 'required' => true),
            array('key' => 'type',     'label' => 'Type',           'field' => '@type',             'w' => 16, 'type' => 'text', 'required' => true),
            array('key' => 'brand',    'label' => 'Brand',          'field' => 'brand',             'w' => 14, 'type' => 'text'),
            array('key' => 'model',    'label' => 'Model',          'field' => 'model',             'w' => 16, 'type' => 'text'),
            array('key' => 'year',     'label' => 'Year',           'field' => 'year',              'w' => 7,  'type' => 'int'),
            array('key' => 'serial',   'label' => 'Serial No',      'field' => 'serial_number',     'w' => 18, 'type' => 'text'),
            array('key' => 'engine',   'label' => 'Engine Model',   'field' => 'engine_model',      'w' => 17, 'type' => 'text'),
            array('key' => 'hours',    'label' => 'Current Hours',  'field' => 'current_hours',     'w' => 13, 'type' => 'num'),
            array('key' => 'km',       'label' => 'Current KM',     'field' => 'current_km',        'w' => 11, 'type' => 'num'),
            array('key' => 'location', 'label' => 'Location',       'field' => 'location',          'w' => 15, 'type' => 'text'),
            array('key' => 'status',   'label' => 'Status',         'field' => 'status',            'w' => 13, 'type' => 'status'),
            array('key' => 'purchase', 'label' => 'Purchase Date',  'field' => 'purchase_date',     'w' => 14, 'type' => 'date'),
            array('key' => 'warranty', 'label' => 'Warranty Expiry','field' => 'warranty_expiry',   'w' => 15, 'type' => 'date'),
            array('key' => 'notes',    'label' => 'Notes',          'field' => 'notes',             'w' => 36, 'type' => 'text'),
        );
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       TEMPLATE
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function build_template($filePath, $typeNames = array(), $statuses = array(), $opts = array()) {
        $x = $this->writer();
        $s = $this->registerStyles($x);
        $cols = $this->importColumns();
        $lastCol = $this->_colLetter(count($cols));

        /* ── Data sheet: "Assets" ── */
        $x->addSheet('Assets');
        foreach ($cols as $i => $c) $x->setColWidth($i + 1, $c['w']);
        $x->setRowHeight(1, 30);
        $head = array();
        foreach ($cols as $c) {
            $head[] = array('v' => $c['label'].(empty($c['required']) ? '' : ' *'), 's' => $s['head']);
        }
        $x->writeRowStyled($head);
        $x->freezePane('A2');

        // Example rows (clearly marked, delete them before importing)
        $example = array(
            array('EX 100', 'Excavator', 'Caterpillar', '320D', 2020, 'SN-EX-100', 'CAT C6.4', 4250, 0, 'PIT A', 'Available', '15/03/2020', '15/03/2023', 'EXAMPLE ROW — delete before importing'),
            array('LT 100', 'Light Vehicle', 'Toyota', 'Hilux', 2019, 'SN-LT-100', '', 86000, 86000, 'WORKSHOP', 'In Maintenance', '', '', 'EXAMPLE ROW — delete before importing'),
        );
        $r = 2;
        foreach ($example as $ex) {
            $cells = array();
            foreach ($cols as $i => $c) {
                $val = isset($ex[$i]) ? $ex[$i] : '';
                $cells[] = array('v' => $val, 's' => $s['example']);
            }
            $x->writeRowStyled($cells);
            $r++;
        }

        // Data validation: dropdowns + numeric/date limits keep bad data out early
        $lastRow = 1000;
        foreach ($cols as $i => $c) {
            $col = $this->_colLetter($i + 1);
            $range = $col.'2:'.$col.$lastRow;
            if ($c['key'] === 'type' && !empty($typeNames)) {
                $list = $this->_listFormula($typeNames);
                if ($list !== null) {
                    $x->addDataValidation($range, array('type' => 'list', 'formula1' => $list, 'allowBlank' => true,
                        'promptTitle' => 'Equipment Type', 'prompt' => 'Pick an existing type (see the Instructions sheet).',
                        'errorTitle' => 'Unknown type', 'error' => 'Use one of the equipment types listed on the Instructions sheet.',
                        'errorStyle' => 'stop'));
                }
            } elseif ($c['key'] === 'status' && !empty($statuses)) {
                $list = $this->_listFormula($statuses);
                if ($list !== null) {
                    $x->addDataValidation($range, array('type' => 'list', 'formula1' => $list, 'allowBlank' => true,
                        'promptTitle' => 'Status', 'prompt' => 'Available / In Maintenance / Breakdown / Standby / Retired',
                        'errorTitle' => 'Invalid status', 'error' => 'Allowed values: '.implode(', ', $statuses),
                        'errorStyle' => 'stop'));
                }
            } elseif ($c['type'] === 'int' && $c['key'] === 'year') {
                $x->addDataValidation($range, array('type' => 'whole', 'operator' => 'between',
                    'formula1' => '1950', 'formula2' => (string)((int)date('Y') + 1), 'allowBlank' => true,
                    'errorTitle' => 'Invalid year', 'error' => 'Enter a year between 1950 and '.((int)date('Y') + 1).'.',
                    'errorStyle' => 'stop'));
            } elseif ($c['type'] === 'num') {
                $x->addDataValidation($range, array('type' => 'decimal', 'operator' => 'greaterThanOrEqual',
                    'formula1' => '0', 'allowBlank' => true,
                    'errorTitle' => 'Invalid number', 'error' => 'Enter a number greater than or equal to 0.',
                    'errorStyle' => 'stop'));
            } elseif ($c['type'] === 'date') {
                $x->addDataValidation($range, array('type' => 'date', 'operator' => 'between',
                    'formula1' => '1', 'formula2' => '2958465', 'allowBlank' => true,
                    'promptTitle' => 'Date', 'prompt' => 'Use dd/mm/yyyy (e.g. 15/03/2020) or pick a date.',
                    'errorTitle' => 'Invalid date', 'error' => 'Enter a real date, e.g. 15/03/2020.',
                    'errorStyle' => 'warning'));
            }
        }

        /* ── Instructions sheet ── */
        $rows = array(
            'HOW TO IMPORT ASSETS — STEP BY STEP',
            '',
            '1. Fill in the "Assets" sheet of this workbook (one row per asset).',
            '2. Delete the two EXAMPLE ROWS and any rows you do not need.',
            '3. Save the file as Excel Workbook (.xlsx).',
            '4. In the system open Reports → Asset Register → Import Excel and upload the file.',
            '',
            '## REQUIRED COLUMNS (marked with *)',
            '   Machine ID *  — the asset registration number shown in the register (e.g. EX 001).',
            '                   It identifies the asset: a row whose Machine ID already exists is',
            '                   handled by the "duplicate" option you choose during import.',
            '   Type *        — must match an existing equipment type (list below).',
            '',
            '## OPTIONAL COLUMNS — leave blank when unknown',
            'A blank cell never clears an existing value when updating an asset.',
            '',
            '## DATES',
            '   Accepted: dd/mm/yyyy, d/m/yy, dd-mm-yyyy, dd.mm.yyyy, yyyy-mm-dd, yyyy/mm/dd,',
            '   "15 Mar 2020" or a real Excel date. An empty cell means "not recorded".',
            '',
            '## NUMBERS',
            '   Current Hours / Current KM accept plain numbers (1 250, 1250.5). Letters or an',
            '   empty mandatory number are reported as errors and that row is skipped.',
            '',
            '## STATUS (optional — default Available)',
            '   '.implode('  |  ', !empty($statuses) ? $statuses : array('Available','In Maintenance','Breakdown','Standby','Retired')),
            '',
            '## EQUIPMENT TYPES AVAILABLE IN THIS SYSTEM',
        );
        if (!empty($typeNames)) {
            $rows[] = '   '.implode('  •  ', $typeNames);
        } else {
            $rows[] = '   (no equipment types defined yet — add them under Equipment → Types first)';
        }
        $rows = array_merge($rows, array(
            '',
            '## WHAT THE SYSTEM CHECKS',
            '   • missing/renamed column headers            • duplicate Machine ID (in the file and in the system)',
            '   • unknown equipment type                    • invalid dates, years, numbers or status',
            '   • values that are too long for the database • duplicate serial numbers (reported as a warning)',
            '',
            'Every row is reported after the import: imported, updated, skipped or failed, with the',
            'reason for each failure. Failed rows never stop the good rows from being imported.',
            '',
            'Template generated '.date('d/m/Y H:i'),
        ));
        $this->_notesSheet($x, $s, $rows);

        return $x->save($filePath);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       IMPORT RESULT REPORT
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function import_report($result, $filePath) {
        $x = $this->writer();
        $s = $this->registerStyles($x);

        $x->addSheet('Import Result');
        $cols = array(
            array('label' => 'ROW',        'w' => 7,  'key' => 'row'),
            array('label' => 'MACHINE ID', 'w' => 16, 'key' => 'machine_id'),
            array('label' => 'RESULT',     'w' => 12, 'key' => 'action'),
            array('label' => 'DETAIL',     'w' => 52, 'key' => 'message'),
            array('label' => 'WARNINGS',   'w' => 44, 'key' => 'warnings'),
            array('label' => 'TYPE',       'w' => 16, 'key' => 'type'),
            array('label' => 'BRAND',      'w' => 14, 'key' => 'brand'),
            array('label' => 'MODEL',      'w' => 16, 'key' => 'model'),
            array('label' => 'SERIAL NO',  'w' => 18, 'key' => 'serial'),
            array('label' => 'STATUS',     'w' => 13, 'key' => 'status'),
        );
        foreach ($cols as $i => $c) $x->setColWidth($i + 1, $c['w']);
        $lastCol = $this->_colLetter(count($cols));

        $x->setRowHeight(1, 26);
        $t = array(array('v' => 'ASSET IMPORT REPORT — '.($result['file_name'] ? $result['file_name'] : ''), 's' => $s['title']));
        for ($i = 1; $i < count($cols); $i++) $t[] = array('v' => '', 's' => $s['title']);
        $x->writeRowStyled($t);
        $x->mergeCells('A1', $lastCol.'1');

        $sum = $result['summary'];
        $x->writeRowStyled(array(
            array('v' => 'Rows read: '.$sum['total_rows'].'   •   Imported: '.$sum['inserted']
                      .'   •   Updated: '.$sum['updated'].'   •   Skipped: '.$sum['skipped']
                      .'   •   Failed: '.$sum['failed']
                      .'   •   Duplicates: '.$sum['duplicates']
                      .'   •   '.date('d/m/Y H:i'), 's' => $s['sub']),
        ));
        $x->mergeCells('A2', $lastCol.'2');

        $x->setRowHeight(3, 26);
        $head = array();
        foreach ($cols as $c) $head[] = array('v' => $c['label'], 's' => $s['head']);
        $x->writeRowStyled($head);

        $row = 4;
        foreach ($result['rows'] as $r) {
            if ($r['action'] === 'inserted')     $st = $s['ok'];
            elseif ($r['action'] === 'updated')  $st = $s['upd'];
            elseif ($r['action'] === 'skipped')  $st = $s['skip'];
            elseif ($r['action'] === 'failed')   $st = $s['fail'];
            else                                 $st = $s['cell'];
            $msg = !empty($r['errors']) ? implode(' | ', $r['errors']) : ucfirst($r['action']);
            $cells = array(
                array('v' => $r['row'],       's' => $s['cellC']),
                array('v' => $r['machine_id'],'s' => $s['cellC']),
                array('v' => strtoupper($r['action']), 's' => $st),
                array('v' => $msg,            's' => $s['cellWrap']),
                array('v' => implode(' | ', $r['warnings']), 's' => $s['cellWrap']),
                array('v' => isset($r['values']['type_name']) ? $r['values']['type_name'] : '', 's' => $s['cell']),
                array('v' => isset($r['values']['brand']) ? $r['values']['brand'] : '', 's' => $s['cell']),
                array('v' => isset($r['values']['model']) ? $r['values']['model'] : '', 's' => $s['cell']),
                array('v' => isset($r['values']['serial_number']) ? $r['values']['serial_number'] : '', 's' => $s['cell']),
                array('v' => isset($r['values']['status']) ? $r['values']['status'] : '', 's' => $s['cellC']),
            );
            $x->writeRowStyled($cells);
            $row++;
        }
        $x->freezePane('A4');
        if ($row > 4) $x->setAutoFilter('A3:'.$lastCol.($row - 1));

        return $x->save($filePath);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       internals
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    private function registerStyles(XlsxWriter $x) {
        $s = array(
            'title'    => 'bold|fontSize:13|align:center|valign:middle|bg:1F4E79|fg:FFFFFF|border:thin',
            'sub'      => 'fontSize:8|align:center|valign:middle|bg:D6E4F0|fg:1F4E79|border:thin',
            'head'     => 'bold|fontSize:8|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap',
            'cell'     => 'fontSize:9|valign:middle|border:thin',
            'cellC'    => 'fontSize:9|align:center|valign:middle|border:thin',
            'cellWrap' => 'fontSize:9|valign:middle|border:thin|wrap',
            'cellNum'  => 'fontSize:9|align:center|valign:middle|border:thin|numFmt:#,##0.0',
            'cellInt'  => 'fontSize:9|align:center|valign:middle|border:thin|numFmt:#,##0',
            'cellDate' => 'fontSize:9|align:center|valign:middle|border:thin|numFmt:dd/mm/yyyy',
            'cellKey'  => 'bold|fontSize:9|align:center|valign:middle|border:thin',
            'example'  => 'italic|fontSize:9|valign:middle|border:thin|fg:808080',
            'pctG'     => 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:E2EFDA|fg:375623|numFmt:0.0%',
            'pctW'     => 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:FFF2CC|fg:7F6000|numFmt:0.0%',
            'pctR'     => 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:FFD7D7|fg:C00000|numFmt:0.0%',
            'wrG'      => 'fontSize:9|align:center|valign:middle|border:thin|fg:375623',
            'wrR'      => 'fontSize:9|align:center|valign:middle|border:thin|fg:C00000',
            'stAvail'  => 'fontSize:9|align:center|valign:middle|border:thin|fg:1C8C3C',
            'stBd'     => 'bold|fontSize:9|align:center|valign:middle|border:thin|fg:C0392B|bg:FFE3E0',
            'stMaint'  => 'fontSize:9|align:center|valign:middle|border:thin|fg:B7791B',
            'stRet'    => 'fontSize:9|align:center|valign:middle|border:thin|fg:8A8A8A',
            'tot'      => 'bold|fontSize:9|valign:middle|border:thin|bg:D6E4F0',
            'totC'     => 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:D6E4F0',
            'totN'     => 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:D6E4F0|numFmt:#,##0.0',
            'totP'     => 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:D6E4F0|numFmt:0.0%',
            'ok'       => 'bold|fontSize:9|align:center|valign:middle|border:thin|fg:1C8C3C|bg:E8F5EC',
            'upd'      => 'bold|fontSize:9|align:center|valign:middle|border:thin|fg:1F4E79|bg:E3EEF9',
            'skip'     => 'bold|fontSize:9|align:center|valign:middle|border:thin|fg:7F6000|bg:FFF6DA',
            'fail'     => 'bold|fontSize:9|align:center|valign:middle|border:thin|fg:C0392B|bg:FFE3E0',
        );
        foreach ($s as $k => $v) $x->registerStyle($v);
        return $s;
    }

    /** Build one export cell from an asset row */
    private function _cell($a, $c, $s, $row) {
        $key = $c['key'];
        switch ($key) {
            case 'id':          return array('v' => $a['id'], 's' => $s['cellInt']);
            case 'reg':         return array('v' => $a['registration_no'], 's' => $s['cellKey']);
            case 'type':        return array('v' => $a['type_name'] ? $a['type_name'] : $a['type'], 's' => $s['cell']);
            case 'category':    return array('v' => $a['category'], 's' => $s['cell']);
            case 'brand':       return array('v' => $a['brand'], 's' => $s['cell']);
            case 'model':       return array('v' => $a['model'], 's' => $s['cell']);
            case 'year':        return array('v' => $a['year'], 's' => $s['cellC']);
            case 'serial':      return array('v' => $a['serial_number'], 's' => $s['cellC']);
            case 'engine':      return array('v' => $a['engine_model'], 's' => $s['cell']);
            case 'hours':       return array('v' => $a['current_hours'], 's' => $s['cellNum']);
            case 'km':          return array('v' => $a['current_km'], 's' => $s['cellNum']);
            case 'location':    return array('v' => $a['location'], 's' => $s['cell']);
            case 'status':
                $map = array('Available' => 'stAvail', 'Breakdown' => 'stBd',
                             'In Maintenance' => 'stMaint', 'Retired' => 'stRet');
                $sk = isset($map[$a['status']]) ? $map[$a['status']] : 'cellC';
                return array('v' => $a['status'], 's' => $s[$sk]);
            case 'purchase':    return array('v' => $this->_serial($a['purchase_date']), 's' => $s['cellDate']);
            case 'warranty':    return array('v' => $this->_serial($a['warranty_expiry']), 's' => $s['cellDate']);
            case 'warranty_st':
                $sk = ($a['warranty_days'] === null) ? 'cellC' : ($a['warranty_days'] > 0 ? 'wrG' : 'wrR');
                return array('v' => $a['warranty_status'], 's' => $s[$sk]);
            case 'warranty_d':  return array('v' => $a['warranty_days'], 's' => $s['cellC']);
            case 'total_wo':    return array('v' => $a['total_wo'], 's' => $s['cellC']);
            case 'breakdowns':  return array('v' => $a['breakdowns'], 's' => $s['cellC']);
            case 'corrective':  return array('v' => $a['corrective_wo'], 's' => $s['cellC']);
            case 'completed':   return array('v' => $a['completed_wo'], 's' => $s['cellC']);
            case 'open_wo':     return array('v' => $a['open_wo'], 's' => $s['cellC']);
            case 'down':        return array('v' => $a['total_down'], 's' => $s['cellNum']);
            case 'availability':
                $av = $a['availability'];
                $sk = $av >= 90 ? 'pctG' : ($av >= 70 ? 'pctW' : 'pctR');
                return array('v' => round($av / 100, 4), 's' => $s[$sk]);
            case 'next_pm':     return array('v' => $a['next_pm_name'], 's' => $s['cell']);
            case 'pm_date':     return array('v' => $this->_serial($a['next_pm_date']), 's' => $s['cellDate']);
            case 'pm_hours':    return array('v' => $a['next_pm_hours'], 's' => $s['cellNum']);
            case 'pm_last':     return array('v' => $this->_serial($a['last_pm_date']), 's' => $s['cellDate']);
            case 'pm_interval': return array('v' => $a['pm_interval'], 's' => $s['cellC']);
            case 'notes':       return array('v' => $a['notes'], 's' => $s['cellWrap']);
            case 'created':     return array('v' => $a['created_at'], 's' => $s['cellC']);
            case 'updated':     return array('v' => $a['updated_at'], 's' => $s['cellC']);
        }
        return array('v' => '', 's' => $s['cell']);
    }

    /** Excel date serial for a Y-m-d[ H:i:s] string (null/empty => blank) */
    private function _serial($date) {
        if (empty($date)) return null;
        $ts = strtotime($date);
        if ($ts === false) return (string)$date;
        // 25569 days between 1899-12-30 and 1970-01-01 (1900 date system)
        return round(($ts - mktime(0, 0, 0, 1, 1, 1970)) / 86400 + 25569, 8);
    }

    /** Inline dropdown list, or null when Excel's 255-character limit is exceeded */
    private function _listFormula($values) {
        $clean = array();
        foreach ($values as $v) {
            $v = trim((string)$v);
            $v = str_replace(array('"', ',', ';'), array('', ' ', ' '), $v);
            if ($v !== '') $clean[] = $v;
        }
        $clean = array_values(array_unique($clean));
        if (empty($clean)) return null;
        $formula = '"'.implode(',', $clean).'"';
        return (strlen($formula) <= 255) ? $formula : null;
    }

    /**
     * Notes / instructions sheet.
     * $lines: list of strings.  A line may be prefixed with "## " to render it
     * as a section heading (the first line is always the title).
     */
    private function _notesSheet(XlsxWriter $x, $s, $lines) {
        $x->addSheet('Instructions');
        $x->setColWidth(1, 118);
        $r = 1;
        foreach ($lines as $line) {
            $isHeading = (strpos($line, '## ') === 0);
            if ($isHeading) $line = substr($line, 3);
            $style = ($r === 1) ? $s['title'] : ($isHeading ? $s['sub'] : $s['cell']);
            $x->writeRowStyled(array(array('v' => $line, 's' => $style)));
            $r++;
        }
        return $x;
    }

    private function _colLetter($n) {
        $letters = '';
        while ($n > 0) {
            $n--;
            $letters = chr(65 + ($n % 26)).$letters;
            $n = intdiv($n, 26);
        }
        return $letters;
    }
}
