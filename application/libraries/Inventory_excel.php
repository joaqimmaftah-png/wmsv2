<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Inventory_excel — workbook builders for the Inventory module.
 *
 *   export_inventory()   full stock listing (every stored field + supplier
 *                        information) plus an "Import Data" sheet in the exact
 *                        import layout, so export → edit → re-import works
 *   build_template()     empty import template with the canonical columns,
 *                        example rows, data validation and an instructions sheet
 *   import_report()      per-row import result + a "Failed Rows" sheet in the
 *                        import layout, ready to correct and re-import
 *
 * The column structure is not defined here: it comes from
 * Inventory_importer::column_defs(), the same definition the validator uses, so
 * template, export and import can never drift apart.
 *
 * Everything runs on the project's own XlsxWriter helper — no external
 * libraries, no new server requirements.
 */
class Inventory_excel {

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
       EXPORT — full inventory listing
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * @param array  $items     rows of `materials` (objects or arrays)
     * @param array  $suppliers supplier map from Materials_model::get_supplier_map()
     * @param string $filePath  destination (writable) path
     * @param array  $opts      ['title'=>..,'generated_by'=>..,'filter_note'=>..]
     */
    public function export_inventory($items, $suppliers, $filePath, $opts = array()) {
        $x = $this->writer();
        $s = $this->registerStyles($x);

        $title = isset($opts['title']) ? $opts['title'] : 'INVENTORY STOCK LIST';
        $stamp = date('d/m/Y H:i');
        $count = count($items);

        /* ── Sheet 1: full listing (human readable, everything stored) ── */
        $x->addSheet('Inventory');
        $cols = $this->exportColumns();
        foreach ($cols as $i => $c) $x->setColWidth($i + 1, $c['width']);
        $lastCol = $this->_colLetter(count($cols));

        $x->setRowHeight(1, 28);
        $titleRow = array(array('v' => $title.' — '.strtoupper(date('d F Y')), 's' => $s['title']));
        for ($i = 1; $i < count($cols); $i++) $titleRow[] = array('v' => '', 's' => $s['title']);
        $x->writeRowStyled($titleRow);
        $x->mergeCells('A1', $lastCol.'1');

        $subtitle = 'Generated '.$stamp
                  . (isset($opts['generated_by']) && $opts['generated_by'] !== '' ? ' by '.$opts['generated_by'] : '')
                  . '  •  '.number_format($count).' item'.($count == 1 ? '' : 's')
                  . (isset($opts['filter_note']) && $opts['filter_note'] !== '' ? '  •  filter: '.$opts['filter_note'] : '')
                  . '  •  Final Stock is the balance stored by the system'
                  . '  •  To re-import changes, edit the "Import Data" sheet (the importer reads it first)';
        $x->setRowHeight(2, 15);
        $sub = array(array('v' => $subtitle, 's' => $s['sub']));
        for ($i = 1; $i < count($cols); $i++) $sub[] = array('v' => '', 's' => $s['sub']);
        $x->writeRowStyled($sub);
        $x->mergeCells('A2', $lastCol.'2');

        $x->setRowHeight(3, 30);
        $head = array();
        foreach ($cols as $c) $head[] = array('v' => $c['label'], 's' => $s['head']);
        $x->writeRowStyled($head);

        $row = 4; $mismatches = 0;
        foreach ($items as $m) {
            $cells = array();
            foreach ($cols as $c) $cells[] = $this->_exportCell($m, $c, $s, $suppliers);
            if ($this->_mismatch($m) !== 0) $mismatches++;
            $x->setRowHeight($row, 16);
            $x->writeRowStyled($cells);
            $row++;
        }
        $firstData = 4; $lastData = $row - 1;

        /* Totals row — the column letters are derived from the definition, so
           they can never point at the wrong field. */
        if ($lastData >= $firstData) {
            $letter = array();
            foreach ($cols as $i => $c) $letter[$c['key']] = $this->_colLetter($i + 1);
            $sum = function ($key) use ($letter, $firstData, $lastData) {
                return array('v' => '=SUM('.$letter[$key].$firstData.':'.$letter[$key].$lastData.')', 's' => 'totN');
            };
            $totals = array(
                'id'             => array('v' => 'TOTAL', 's' => 'tot'),
                'item_code'      => array('v' => number_format($count).' items', 's' => 'totC'),
                'initial_stock'  => $sum('initial_stock'),
                'qty_received'   => $sum('qty_received'),
                'qty_sortie'     => $sum('qty_sortie'),
                'final_qty'      => $sum('final_qty'),
            );
            foreach ($totals as $k => $t) $totals[$k]['s'] = $s[$t['s']];
            $cells = array();
            foreach ($cols as $c) {
                $cells[] = isset($totals[$c['key']]) ? $totals[$c['key']] : array('v' => '', 's' => $s['tot']);
            }
            $x->setRowHeight($row, 18);
            $x->writeRowStyled($cells);
        }

        $x->freezePane('C4');
        if ($lastData >= $firstData) $x->setAutoFilter('A3:'.$lastCol.$lastData);

        /* ── Sheet 2: "Import Data" — the same rows in the import layout ── */
        $importCols = $this->importColumns();
        $x->addSheet('Import Data');
        foreach ($importCols as $i => $c) $x->setColWidth($i + 1, $c['width']);
        $x->setRowHeight(1, 30);
        $head = array();
        foreach ($importCols as $c) $head[] = array('v' => $c['label'], 's' => $s['head']);
        $x->writeRowStyled($head);
        $x->freezePane('A2');
        $ir = 2;
        foreach ($items as $m) {
            $cells = array();
            foreach ($importCols as $c) $cells[] = $this->_importCell($m, $c, $s);
            $x->writeRowStyled($cells);
            $ir++;
        }
        if ($ir > 2) $x->setAutoFilter('A1:'.$this->_colLetter(count($importCols)).($ir - 1));

        /* ── Sheet 3: notes / definitions ── */
        $notes = array(
            $title,
            '',
            '• Sheet "Inventory" — every field stored for each item, plus the stock status and the',
            '  supplier(s) linked to the item (Suppliers module). This is the sheet to use for',
            '  reporting, printing, backup and review.',
            '• Sheet "Import Data" — the same items in the layout used by Inventory → Import from',
            '  Excel, and the sheet the importer reads first. Edit THIS sheet (not the listing above)',
            '  and import the file back to bulk-create or bulk-update inventory records.',
            '• Re-importing this file as-is reports every row as an existing item and changes nothing',
            '  (default duplicate policy = skip, default stock handling = leave the stock unchanged).',
            '• Received/issued movements are history, not part of the import layout: a NEW item is created',
            '  with the Opening Stock column as its opening balance (logged as a movement), and the stock',
            '  of an existing item only changes when a stock action is chosen during the import.',
            '• Final Stock is the stored balance the system uses everywhere; it normally equals',
            '  Opening Stock + Qty Received − Qty Issued.',
            '• Importing the listing sheet directly also works (its system columns are ignored), but the',
            '  TOTAL line at the bottom is then reported as one failed row — use the "Import Data" sheet for',
            '  a clean import.',
            '• The "Stock Check" column is only filled when the stored Final Stock differs from',
            '  Opening Stock + Qty Received − Qty Issued, and shows the difference.',
            '• Blank cells mean "not recorded"; importing a blank cell never clears an existing value.',
            '• Items without a date show an empty Created/Updated cell (the legacy records store',
            '  0000-00-00).',
            '• Generated '.$stamp.'.',
        );
        if ($mismatches > 0) {
            $notes[] = '';
            $notes[] = '⚠ '.$mismatches.' row(s) have a stored Final Stock that differs from '
                     . 'Opening Stock + Qty Received − Qty Issued.';
            $notes[] = '  The stored value is exported (it is what the system uses); see "Stock Check".';
        }
        $this->_notesSheet($x, $s, $notes);

        return $x->save($filePath);
    }

    /** Column definition of the full listing sheet (reporting). */
    public function exportColumns() {
        return array(
            array('key' => 'id',            'label' => 'ID',            'width' => 6,  'type' => 'int'),
            array('key' => 'item_code',     'label' => 'ITEM CODE',     'width' => 16, 'type' => 'key'),
            array('key' => 'category',      'label' => 'CATEGORY',      'width' => 15, 'type' => 'text'),
            array('key' => 'description',   'label' => 'DESCRIPTION',   'width' => 36, 'type' => 'text'),
            array('key' => 'part_number_size', 'label' => 'PART NO / SIZE', 'width' => 20, 'type' => 'text'),
            array('key' => 'unit',          'label' => 'UNIT',          'width' => 9,  'type' => 'center'),
            array('key' => 'initial_stock', 'label' => 'OPENING STOCK', 'width' => 14, 'type' => 'int'),
            array('key' => 'qty_received',  'label' => 'QTY RECEIVED',  'width' => 13, 'type' => 'int'),
            array('key' => 'qty_sortie',    'label' => 'QTY ISSUED',    'width' => 12, 'type' => 'int'),
            array('key' => 'final_qty',     'label' => 'FINAL STOCK',   'width' => 12, 'type' => 'bal'),
            array('key' => 'stock_status',  'label' => 'STOCK STATUS',  'width' => 12, 'type' => 'status'),
            array('key' => 'stock_check',   'label' => 'STOCK CHECK',   'width' => 12, 'type' => 'check'),
            array('key' => 'location',      'label' => 'LOCATION',      'width' => 15, 'type' => 'text'),
            array('key' => 'application',   'label' => 'APPLICATION',   'width' => 20, 'type' => 'text'),
            array('key' => 'suppliers',     'label' => 'SUPPLIER(S)',   'width' => 26, 'type' => 'text'),
            array('key' => 'created_at',    'label' => 'CREATED AT',    'width' => 17, 'type' => 'datetime'),
            array('key' => 'updated_at',    'label' => 'UPDATED AT',    'width' => 17, 'type' => 'datetime'),
        );
    }

    /**
     * The import layout — always taken from the importer so the template,
     * the export's "Import Data" sheet and the validator agree by construction.
     */
    public function importColumns() {
        if (!class_exists('Inventory_importer')) {
            require_once APPPATH.'libraries/Inventory_importer.php';
        }
        return Inventory_importer::column_defs();
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       TEMPLATE
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * @param string $filePath destination path
     * @param array  $lookups  ['categories'=>['list'=>..,'map'=>..], 'units'=>[...]]
     */
    public function build_template($filePath, $lookups = array()) {
        $x = $this->writer();
        $s = $this->registerStyles($x);
        $cols = $this->importColumns();
        $lastCol = $this->_colLetter(count($cols));

        $categories = isset($lookups['categories']['list']) ? $lookups['categories']['list'] : array();
        $units      = isset($lookups['units']['list'])      ? $lookups['units']['list']      : array();

        /* ── Data sheet — same name/layout as the "Import Data" sheet of an
              export, so the importer reads template and export consistently ── */
        $x->addSheet('Import Data');
        foreach ($cols as $i => $c) $x->setColWidth($i + 1, $c['width']);
        $x->setRowHeight(1, 32);
        $head = array();
        foreach ($cols as $c) {
            $head[] = array('v' => $c['label'].(empty($c['required']) ? '' : ' *'), 's' => $s['head']);
        }
        $x->writeRowStyled($head);
        $x->freezePane('A2');

        // Example rows — clearly marked so nobody imports them by accident.
        $example = array(
            array('CON900', 'Cutting disc 115x1.2x22', 'CONSUMABLE', '115x1.2x22', 'pc', 25, 'WORKSHOP', 'L4'),
            array('SP900',  'Hydraulic filter',        'FILTER',     'XCMG 332109991', 'pc', 4, 'XCMG XDA45 ADT', 'S2'),
        );
        $r = 2;
        foreach ($example as $ex) {
            $cells = array();
            foreach ($cols as $i => $c) {
                $val = isset($ex[$i]) ? $ex[$i] : '';
                if ($c['key'] === 'description' && $i === 1) {
                    $val = $val.' — EXAMPLE ROW, delete before importing';
                }
                $cells[] = array('v' => $val, 's' => $s['example']);
            }
            $x->writeRowStyled($cells);
            $r++;
        }

        /* ── Data validation: keep bad data out before it reaches the system ── */
        $lastRow = 1000;
        foreach ($cols as $i => $c) {
            $col   = $this->_colLetter($i + 1);
            $range = $col.'2:'.$col.$lastRow;
            if ($c['type'] === 'category' && !empty($categories)) {
                $list = $this->_listFormula($categories);
                if ($list !== null) {
                    $x->addDataValidation($range, array('type' => 'list', 'formula1' => $list, 'allowBlank' => true,
                        'promptTitle' => 'Category', 'prompt' => 'Pick a category used by the system (see Instructions).',
                        'errorTitle' => 'Unknown category', 'error' => 'Use one of the categories listed on the Instructions sheet.',
                        'errorStyle' => 'stop'));
                }
            } elseif ($c['type'] === 'unit' && !empty($units)) {
                $list = $this->_listFormula($units);
                if ($list !== null) {
                    $x->addDataValidation($range, array('type' => 'list', 'formula1' => $list, 'allowBlank' => true,
                        'promptTitle' => 'Unit', 'prompt' => 'Pick a unit used by the system (see Instructions).',
                        'errorTitle' => 'Unknown unit', 'error' => 'Use one of the units listed on the Instructions sheet.',
                        'errorStyle' => 'stop'));
                }
            } elseif ($c['type'] === 'int') {
                $x->addDataValidation($range, array('type' => 'whole', 'operator' => 'greaterThanOrEqual',
                    'formula1' => '0', 'allowBlank' => true,
                    'promptTitle' => $c['label'], 'prompt' => 'Whole units only, e.g. 25.',
                    'errorTitle' => 'Invalid quantity',
                    'error' => 'Enter a whole number (0 or more). The stock columns store whole units.',
                    'errorStyle' => 'stop'));
            } elseif ($c['key'] === 'item_code') {
                $x->addDataValidation($range, array('type' => 'textLength', 'operator' => 'lessThanOrEqual',
                    'formula1' => '50', 'allowBlank' => true,
                    'errorTitle' => 'Item Code too long', 'error' => 'The item code must be 50 characters or fewer.',
                    'errorStyle' => 'stop'));
            } elseif ($c['key'] === 'description') {
                $x->addDataValidation($range, array('type' => 'textLength', 'operator' => 'lessThanOrEqual',
                    'formula1' => '255', 'allowBlank' => true,
                    'errorTitle' => 'Description too long', 'error' => 'The description must be 255 characters or fewer.',
                    'errorStyle' => 'stop'));
            }
        }
        $x->setAutoFilter('A1:'.$lastCol.'1');

        /* ── Instructions sheet ── */
        $this->_instructionsSheet($x, $s, $cols, $categories, $units);
        return $x->save($filePath);
    }

    /** Build the Instructions sheet (shared by the template, export and report). */
    private function _instructionsSheet(XlsxWriter $x, $s, $cols, $categories, $units) {
        $rows = array(
            'HOW TO IMPORT INVENTORY — STEP BY STEP',
            '',
            '1. Fill in the "Import Data" sheet of this workbook (one row per item).',
            '2. Delete the two EXAMPLE ROWS (marked in the Description column) and any rows you do not need.',
            '3. Save the file as Excel Workbook (.xlsx).',
            '4. In the system open Inventory → Inventory List → "Import from Excel" and upload the file.',
            '5. Choose what to do with items that already exist and how stock quantities should be handled.',
            '',
            '## REQUIRED COLUMNS (marked with *)',
        );
        foreach ($cols as $c) {
            if (!empty($c['required'])) {
                $rows[] = '   '.str_pad($c['label'].' *', 18, ' ').'— '.$c['help'];
            }
        }
        $rows[] = '';
        $rows[] = '## OPTIONAL COLUMNS — leave blank when unknown';
        $rows[] = '   A blank cell never clears an existing value when updating an item.';
        foreach ($cols as $c) {
            if (empty($c['required'])) {
                $rows[] = '   '.str_pad($c['label'], 18, ' ').'— '.$c['help'];
            }
        }
        $rows[] = '';
        $rows[] = '## WHAT HAPPENS TO AN ITEM THAT ALREADY EXISTS (chosen during import)';
        $rows[] = '   • Skip it (default)       — the existing record is left exactly as it is.';
        $rows[] = '   • Update existing items   — only the cells that are filled in are written to the master data.';
        $rows[] = '   • Reject the row          — the row is reported as an error.';
        $rows[] = '';
        $rows[] = '## STOCK QUANTITIES (Opening Stock column)';
        $rows[] = '   • A NEW item is created with the Opening Stock as its opening balance, and the balance is';
        $rows[] = '     recorded as a RECEIVE movement so the stock history stays traceable.';
        $rows[] = '   • For an item that ALREADY EXISTS the quantity is only applied when you choose a stock action:';
        $rows[] = '        Leave the stock unchanged (default) — the number in the file is ignored (and reported);';
        $rows[] = '        Add to stock as a received movement — the quantity is added as a normal RECEIVE movement;';
        $rows[] = '        Replace the opening balance — only allowed when the item has no movements yet.';
        $rows[] = '   • Stock quantities are whole units (pieces, litres, kg …). Decimals are reported as errors.';
        $rows[] = '   • Received/issued movements are not part of the import layout: a new item starts with the';
        $rows[] = '     Opening Stock in the file as its opening balance (the file cannot invent movement history).';
        $rows[] = '   • Re-importing the same file never duplicates a balance, because existing items are skipped or';
        $rows[] = '     updated without touching their stock.';
        $rows[] = '';
        $rows[] = '## DUPLICATE RECORDS';
        $rows[] = '   A row whose Description + Part No + Unit already exists under a different Item Code is';
        $rows[] = '   reported as a duplicate record (the existing code is shown). Tick "Allow duplicate records"';
        $rows[] = '   if two different codes really are the same item description.';
        $rows[] = '';
        $rows[] = '## CATEGORIES IN THIS SYSTEM';
        $rows[] = !empty($categories)
            ? '   '.implode('  •  ', array_slice($categories, 0, 40))
            : '   (no categories defined yet)';
        $rows[] = '';
        $rows[] = '## UNITS IN THIS SYSTEM';
        $rows[] = !empty($units)
            ? '   '.implode('  •  ', array_slice($units, 0, 60))
            : '   (no units defined yet)';
        $rows[] = '';
        $rows[] = '## WHAT THE SYSTEM CHECKS';
        $rows[] = '   • missing or renamed column headers      • duplicate item codes (in the file and in the database)';
        $rows[] = '   • required values (Item Code, Description)  • duplicate records (same description/part/unit)';
        $rows[] = '   • category and unit must exist           • numbers: whole, not negative, within limits';
        $rows[] = '   • text lengths (item code 50, description 255, …)';
        $rows[] = '';
        $rows[] = 'Every row is reported after the import with its Excel row number: imported, updated, skipped or';
        $rows[] = 'failed, and the reason for each failure. Good rows are imported even when other rows fail; if the';
        $rows[] = 'database itself reports an error the whole import is rolled back and nothing is written.';
        $rows[] = '';
        $rows[] = 'Template generated '.date('d/m/Y H:i');

        return $this->_notesSheet($x, $s, $rows);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       IMPORT RESULT REPORT
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function import_report($result, $filePath) {
        $x = $this->writer();
        $s = $this->registerStyles($x);
        $sum = $result['summary'];

        $x->addSheet('Import Result');
        $cols = array(
            array('label' => 'ROW',         'width' => 7,  'key' => 'row'),
            array('label' => 'ITEM CODE',   'width' => 16, 'key' => 'item_code'),
            array('label' => 'DESCRIPTION', 'width' => 30, 'key' => 'description'),
            array('label' => 'RESULT',      'width' => 12, 'key' => 'action'),
            array('label' => 'REASON / DETAIL', 'width' => 56, 'key' => 'message'),
            array('label' => 'WARNINGS',    'width' => 46, 'key' => 'warnings'),
            array('label' => 'STOCK',       'width' => 40, 'key' => 'stock'),
        );
        foreach ($cols as $i => $c) $x->setColWidth($i + 1, $c['width']);
        $lastCol = $this->_colLetter(count($cols));

        $x->setRowHeight(1, 26);
        $t = array(array('v' => 'INVENTORY IMPORT REPORT — '.($result['file_name'] ? $result['file_name'] : 'upload'), 's' => $s['title']));
        for ($i = 1; $i < count($cols); $i++) $t[] = array('v' => '', 's' => $s['title']);
        $x->writeRowStyled($t);
        $x->mergeCells('A1', $lastCol.'1');

        $line = 'Rows read: '.$sum['total_rows'].'   •   New items: '.$sum['inserted']
              . '   •   Updated: '.$sum['updated'].'   •   Skipped: '.$sum['skipped']
              . '   •   Failed: '.$sum['failed'].'   •   Duplicates: '.$sum['duplicates']
              . '   •   Missing required: '.$sum['missing_required'].'   •   Invalid values: '.$sum['invalid_values']
              . '   •   '.date('d/m/Y H:i');
        $x->setRowHeight(2, 16);
        $x->writeRowStyled(array(array('v' => $line, 's' => $s['sub'])));
        $x->mergeCells('A2', $lastCol.'2');

        $row = 3;
        if (!empty($result['rolled_back'])) {
            $x->writeRowStyled(array(array('v' => 'IMPORT ROLLED BACK — nothing was written. '
                . (isset($result['rollback_reason']) ? $result['rollback_reason'] : ''), 's' => $s['failRow'])));
            $x->mergeCells('A3', $lastCol.'3');
            $row = 4;
        } elseif ($result['mode'] === 'validate') {
            $x->writeRowStyled(array(array('v' => 'VALIDATION ONLY (dry run) — no data was written to the database.', 's' => $s['skipRow'])));
            $x->mergeCells('A3', $lastCol.'3');
            $row = 4;
        }

        $x->setRowHeight($row, 26);
        $head = array();
        foreach ($cols as $c) $head[] = array('v' => $c['label'], 's' => $s['head']);
        $x->writeRowStyled($head);
        $headRow = $row; $row++;

        foreach ($result['rows'] as $r) {
            $act = $r['action'];
            $st = ($act === 'inserted') ? $s['ok'] : (($act === 'updated') ? $s['upd']
                : (($act === 'skipped') ? $s['skip'] : $s['fail']));
            $msg = !empty($r['errors']) ? implode(' | ', $r['errors']) : ucfirst($act);
            if (!empty($r['rolled_back'])) $msg .= ' (rolled back)';
            $cells = array(
                array('v' => $r['row'],            's' => $s['cellC']),
                array('v' => $r['item_code'],      's' => $s['cellKey']),
                array('v' => $r['description'],    's' => $s['cellWrap']),
                array('v' => strtoupper($act),     's' => $st),
                array('v' => $msg,                 's' => $s['cellWrap']),
                array('v' => implode(' | ', $r['warnings']), 's' => $s['cellWrap']),
                array('v' => isset($r['stock_note']) ? $r['stock_note'] : '', 's' => $s['cellWrap']),
            );
            $x->writeRowStyled($cells);
            $row++;
        }
        $x->freezePane('A'.($headRow + 1));
        if ($row > $headRow + 1) $x->setAutoFilter('A'.$headRow.':'.$lastCol.($row - 1));

        /* ── Sheet 2: failed rows in the import layout, ready to fix ── */
        $failed = array();
        foreach ($result['rows'] as $r) {
            if ($r['action'] === 'failed') $failed[] = $r;
        }
        $importCols = $this->importColumns();
        // The sheet always exists (even with no failures) so that re-importing
        // the report file always lands on the import layout, never on the
        // result sheet.
        $x->addSheet('Failed Rows');
        $x->setColWidth(1, 46);
        foreach ($importCols as $i => $c) $x->setColWidth($i + 2, $c['width']);
        $lastFailCol = $this->_colLetter(count($importCols) + 1);

        $x->setRowHeight(1, 30);
        $head = array(array('v' => 'WHY IT FAILED', 's' => $s['head']));
        foreach ($importCols as $c) $head[] = array('v' => $c['label'], 's' => $s['head']);
        $x->writeRowStyled($head);
        $x->freezePane('B2');

        $fr = 2;
        foreach ($failed as $r) {
            $cells = array(array('v' => implode(' | ', $r['errors']), 's' => $s['cellFailWrap']));
            foreach ($importCols as $c) {
                $raw = isset($r['raw'][$c['key']]) ? $r['raw'][$c['key']] : '';
                $cells[] = array('v' => $raw, 's' => $s['cell']);
            }
            $x->writeRowStyled($cells);
            $fr++;
        }
        $x->setAutoFilter('A1:'.$lastFailCol.($fr - 1));

        if (!empty($failed)) {
            $x->addSheet('How to fix');
            $x->setColWidth(1, 118);
            $lines = array(
                'HOW TO FIX AND RE-IMPORT THE FAILED ROWS',
                '',
                '• Sheet "Failed Rows" contains exactly the rows that could not be imported, in the same',
                '  column layout as the import template. The first column shows why each row failed.',
                '• Correct the values in that sheet (or delete rows you do not need), then import the file',
                '  again through Inventory → Inventory List → "Import from Excel".',
                '• Importing the corrected file is safe: rows that were already imported are reported as',
                '  existing items (skipped by default) and their stock is never changed twice.',
                '',
                '## THE MOST COMMON REASONS',
                '   • Item Code is required / Description is required — fill the empty cell.',
                '   • Item Code already exists — use the existing code (the item is updated instead of created)',
                '     or give the new item a different code.',
                '   • Category/Unit is not used in this system — pick a value from the Instructions sheet of',
                '     the template, or import with "Allow new categories/units".',
                '   • Opening Stock must be a whole number — remove decimals, letters or a negative sign.',
                '   • Duplicate record (same Description + Part No + Unit) — use the code of the existing item.',
                '',
                'Nothing from the failed rows was written to the database.',
                '',
                'Report generated '.date('d/m/Y H:i'),
            );
            $r2 = 1;
            foreach ($lines as $line) {
                $isHead = (strpos($line, '## ') === 0);
                if ($isHead) $line = substr($line, 3);
                $style = ($r2 === 1) ? $s['title'] : ($isHead ? $s['sub'] : $s['cell']);
                $x->writeRowStyled(array(array('v' => $line, 's' => $style)));
                $r2++;
            }
        }

        return $x->save($filePath);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       internals
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    private function registerStyles(XlsxWriter $x) {
        $s = array(
            'title'      => 'bold|fontSize:13|align:center|valign:middle|bg:1F4E79|fg:FFFFFF|border:thin',
            'sub'        => 'fontSize:8|align:center|valign:middle|bg:D6E4F0|fg:1F4E79|border:thin',
            'head'       => 'bold|fontSize:8|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap',
            'cell'       => 'fontSize:9|valign:middle|border:thin',
            'cellWrap'   => 'fontSize:9|valign:middle|border:thin|wrap',
            'cellC'      => 'fontSize:9|align:center|valign:middle|border:thin',
            'cellInt'    => 'fontSize:9|align:center|valign:middle|border:thin|numFmt:#,##0',
            'cellKey'    => 'bold|fontSize:9|valign:middle|border:thin',
            'cellDate'   => 'fontSize:9|align:center|valign:middle|border:thin|numFmt:dd/mm/yyyy',
            'cellDateTime'=> 'fontSize:9|align:center|valign:middle|border:thin|numFmt:dd/mm/yyyy hh:mm',
            'example'    => 'italic|fontSize:9|valign:middle|border:thin|fg:808080',
            'balOut'     => 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:FFD7D7|fg:C00000|numFmt:#,##0',
            'balLow'     => 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:FFF2CC|fg:7F6000|numFmt:#,##0',
            'balOk'      => 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:E2EFDA|fg:375623|numFmt:#,##0',
            'stOut'      => 'bold|fontSize:8|align:center|valign:middle|border:thin|bg:FFE3E0|fg:C0392B',
            'stLow'      => 'bold|fontSize:8|align:center|valign:middle|border:thin|bg:FFF6DA|fg:7F6000',
            'stOk'       => 'fontSize:8|align:center|valign:middle|border:thin|fg:1C8C3C',
            'chk'        => 'fontSize:9|align:center|valign:middle|border:thin|fg:C00000|numFmt:+#,##0;-#,##0',
            'tot'        => 'bold|fontSize:9|valign:middle|border:thin|bg:D6E4F0',
            'totC'       => 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:D6E4F0',
            'totN'       => 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:D6E4F0|numFmt:#,##0',
            'ok'         => 'bold|fontSize:9|align:center|valign:middle|border:thin|fg:1C8C3C|bg:E8F5EC',
            'upd'        => 'bold|fontSize:9|align:center|valign:middle|border:thin|fg:1F4E79|bg:E3EEF9',
            'skip'       => 'bold|fontSize:9|align:center|valign:middle|border:thin|fg:7F6000|bg:FFF6DA',
            'fail'       => 'bold|fontSize:9|align:center|valign:middle|border:thin|fg:C0392B|bg:FFE3E0',
            'failRow'    => 'bold|fontSize:10|valign:middle|border:thin|fg:C0392B|bg:FFE3E0|wrap',
            'skipRow'    => 'bold|fontSize:10|valign:middle|border:thin|fg:7F6000|bg:FFF6DA|wrap',
            'cellFailWrap'=> 'fontSize:9|valign:middle|border:thin|wrap|fg:C0392B',
        );
        foreach ($s as $k => $v) $x->registerStyle($v);
        return $s;
    }

    /** One cell of the full listing sheet. */
    private function _exportCell($m, $c, $s, $suppliers) {
        $key = $c['key'];
        $get = function($k) use ($m) {
            if (is_array($m))   return isset($m[$k]) ? $m[$k] : null;
            return isset($m->$k) ? $m->$k : null;
        };
        switch ($key) {
            case 'id':           return array('v' => (int)$get('id'), 's' => $s['cellInt']);
            case 'item_code':    return array('v' => $get('item_code'), 's' => $s['cellKey']);
            case 'category':     return array('v' => $get('category'), 's' => $s['cell']);
            case 'description':  return array('v' => $get('description'), 's' => $s['cellWrap']);
            case 'part_number_size': return array('v' => $get('part_number_size'), 's' => $s['cell']);
            case 'unit':         return array('v' => $get('unit'), 's' => $s['cellC']);
            case 'initial_stock':return array('v' => (int)$get('initial_stock'), 's' => $s['cellInt']);
            case 'qty_received': return array('v' => (int)$get('qty_received'), 's' => $s['cellInt']);
            case 'qty_sortie':   return array('v' => (int)$get('qty_sortie'), 's' => $s['cellInt']);
            case 'final_qty':
                $qty = $this->_storedQty($m);
                $lvl = $qty <= 0 ? 'balOut' : ($qty <= 5 ? 'balLow' : 'balOk');
                return array('v' => $qty, 's' => $s[$lvl]);
            case 'stock_status':
                $qty = $this->_storedQty($m);
                $lvl = $qty <= 0 ? 'stOut' : ($qty <= 5 ? 'stLow' : 'stOk');
                return array('v' => $qty <= 0 ? 'OUT OF STOCK' : ($qty <= 5 ? 'LOW' : 'OK'), 's' => $s[$lvl]);
            case 'stock_check':
                $d = $this->_mismatch($m);
                return array('v' => $d === 0 ? '' : $d, 's' => $s['chk']);
            case 'location':     return array('v' => $get('location'), 's' => $s['cell']);
            case 'application':  return array('v' => $get('application'), 's' => $s['cell']);
            case 'suppliers':
                $id = (int)$get('id');
                $names = (isset($suppliers[$id]['suppliers']) && !empty($suppliers[$id]['suppliers']))
                       ? implode(', ', $suppliers[$id]['suppliers']) : '';
                return array('v' => $names, 's' => $s['cellWrap']);
            case 'created_at':   return $this->_dateCell($get('created_at'), $s, false);
            case 'updated_at':   return $this->_dateCell($get('updated_at'), $s, false);
        }
        return array('v' => '', 's' => $s['cell']);
    }

    /** One cell of the import-layout sheet (mirrors the template columns). */
    private function _importCell($m, $c, $s) {
        $key = $c['key'];
        $get = function($k) use ($m) {
            if (is_array($m))   return isset($m[$k]) ? $m[$k] : null;
            return isset($m->$k) ? $m->$k : null;
        };
        switch ($key) {
            case 'item_code':   return array('v' => $get('item_code'), 's' => $s['cellKey']);
            case 'description': return array('v' => $get('description'), 's' => $s['cell']);
            case 'category':    return array('v' => $get('category'), 's' => $s['cell']);
            case 'part':        return array('v' => $get('part_number_size'), 's' => $s['cell']);
            case 'unit':        return array('v' => $get('unit'), 's' => $s['cellC']);
            case 'opening':     return array('v' => (int)$get('initial_stock'), 's' => $s['cellInt']);
            case 'application': return array('v' => $get('application'), 's' => $s['cell']);
            case 'location':    return array('v' => $get('location'), 's' => $s['cell']);
            // Re-order / pricing columns (migration 007). A database without
            // them simply exports an empty cell.
            case 'min_stock':
                $n = $get('min_stock');
                return array('v' => ($n === null || $n === '') ? '' : (int)$n, 's' => $s['cellInt']);
            case 'max_stock':
                $n = $get('max_stock');
                return array('v' => ($n === null || $n === '') ? '' : (int)$n, 's' => $s['cellInt']);
            case 'unit_price_ugx':
                $n = $get('unit_price_ugx');
                return array('v' => ($n === null || $n === '') ? '' : (float)$n, 's' => $s['cell']);
            case 'supplier_name':
                return array('v' => (string)$get('supplier_name'), 's' => $s['cell']);
        }
        return array('v' => '', 's' => $s['cell']);
    }

    /** Stored balance (the column the application uses) with a safe fallback. */
    private function _storedQty($m) {
        $stored = is_array($m) ? (isset($m['final_qty']) ? $m['final_qty'] : null)
                               : (isset($m->final_qty) ? $m->final_qty : null);
        if ($stored !== null) return (int)$stored;
        $init = is_array($m) ? (int)$m['initial_stock'] : (int)$m->initial_stock;
        $recv = is_array($m) ? (int)$m['qty_received']  : (int)$m->qty_received;
        $out  = is_array($m) ? (int)$m['qty_sortie']    : (int)$m->qty_sortie;
        return $init + $recv - $out;
    }

    /** stored Final Stock − (opening + received − issued): 0 when consistent. */
    private function _mismatch($m) {
        $get = function($k) use ($m) {
            if (is_array($m))   return isset($m[$k]) ? $m[$k] : 0;
            return isset($m->$k) ? $m->$k : 0;
        };
        return $this->_storedQty($m)
             - ((int)$get('initial_stock') + (int)$get('qty_received') - (int)$get('qty_sortie'));
    }

    /** Date cell — '' for empty and for the legacy 0000-00-00 timestamps. */
    private function _dateCell($value, $s, $withTime = false) {
        $value = trim((string)$value);
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return array('v' => '', 's' => $s['cellC']);
        }
        $ts = strtotime($value);
        if ($ts === false) return array('v' => $value, 's' => $s['cellC']);
        $v = $this->_serial($value);
        if ($v === null) return array('v' => $value, 's' => $s['cellC']);
        if ($withTime && strlen($value) > 10 && strpos($value, '00:00:00') === false) {
            return array('v' => $v, 's' => $s['cellDateTime']);
        }
        return array('v' => $v, 's' => $s['cellDate']);
    }

    /** Excel date serial for a Y-m-d[ H:i:s] string (null when unparseable). */
    private function _serial($date) {
        if (empty($date)) return null;
        $ts = strtotime($date);
        if ($ts === false) return null;
        // 25569 days between 1899-12-30 and 1970-01-01 (1900 date system)
        return round(($ts - mktime(0, 0, 0, 1, 1, 1970)) / 86400 + 25569, 8);
    }

    /** Inline dropdown list, or null when Excel's 255-character limit is exceeded. */
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
     * A line may be prefixed with "## " to render it as a section heading
     * (the first line is always the title).
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
