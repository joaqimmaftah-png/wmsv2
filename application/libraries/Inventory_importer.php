<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Inventory_importer — validated Excel import of inventory (materials) records,
 * and the single set of business rules shared with manual entry.
 *
 * Design principles
 * ─────────────────
 *  • The canonical column structure lives in column_defs() and is used by the
 *    template, the export's import sheet, the import report and the validator —
 *    the format can never drift between them.
 *  • Manual entry calls validate_manual(); every rule (required fields, length
 *    caps, whole-number stock, known category/unit, duplicate code) is defined
 *    once, in validate_row(), so both paths behave identically.
 *  • A bad row never aborts the file: each row is validated and reported with
 *    its Excel row number and reason (missing required / invalid / duplicate).
 *  • Stock is never overwritten: for an existing item the quantity in the file
 *    is only applied when the user explicitly chooses a stock action (add a
 *    RECEIVE movement, or set the opening balance of an item that has no
 *    movements yet).  New items get an opening balance plus an opening-stock
 *    movement, so every balance is traceable.
 *  • All writes happen inside one database transaction; if a write fails the
 *    whole import is rolled back and reported as such — no partial data.
 *  • Blank cells mean "not recorded": they never clear an existing value.
 *
 * Usage:
 *   $this->load->library('inventory_importer', array('model' => $this->Materials_model));
 *   $result = $this->inventory_importer->import($sheet, $options);
 */
class Inventory_importer {

    const MAX_ROWS      = 5000;        // rows per import (server protection)
    const MAX_STOCK     = 100000000;   // sanity cap for a single balance
    const MAX_TEXT_LIST = 12;          // values listed before "…" in messages

    /** Column lengths from the materials table (never let MySQL truncate). */
    private $lengths = array(
        'item_code'        => 50,
        'category'         => 100,
        'description'      => 255,
        'part_number_size' => 100,
        'unit'             => 20,
        'application'      => 150,
        'location'         => 100,
        'supplier_name'    => 150,
    );

    /**
     * Re-order / pricing columns (migration 007).  They mirror the Min stock,
     * Max stock, Price UGX and Vendor columns of the manual Stock List and are
     * only written when the database really has them.
     */
    const EXTRA_KEYS = array('min_stock', 'max_stock', 'unit_price_ugx', 'supplier_name');

    /**
     * Header aliases: normalised header => internal field key.
     * '@ignore' marks the system-generated columns of an export.
     */
    private $aliases = array(
        // item code
        'item code' => 'item_code', 'itemcode' => 'item_code', 'code' => 'item_code',
        'item no' => 'item_code', 'item number' => 'item_code', 'item' => 'item_code',
        'part code' => 'item_code', 'material code' => 'item_code', 'stock code' => 'item_code',
        'item id' => 'item_code', 'sku' => 'item_code', 'product code' => 'item_code',
        'catalogue no' => 'item_code', 'catalog no' => 'item_code',
        // description
        'description' => 'description', 'item description' => 'description',
        'item name' => 'description', 'name' => 'description', 'material' => 'description',
        'material description' => 'description', 'details' => 'description',
        'part name' => 'description', 'item details' => 'description',
        // category
        'category' => 'category', 'item category' => 'category', 'group' => 'category',
        'type' => 'category', 'material group' => 'category',
        // part number / size
        'part no size' => 'part', 'part no' => 'part', 'part number' => 'part',
        'part number size' => 'part', 'part no size grade' => 'part',
        'size' => 'part', 'specification' => 'part', 'spec' => 'part',
        'part size' => 'part', 'dimensions' => 'part',
        // unit
        'unit' => 'unit', 'uom' => 'unit', 'unit of measure' => 'unit',
        'units' => 'unit', 'measure' => 'unit', 'unit of issue' => 'unit',
        // opening stock
        'opening stock' => 'opening', 'opening balance' => 'opening', 'opening qty' => 'opening',
        'initial stock' => 'opening', 'initial qty' => 'opening', 'initial quantity' => 'opening',
        'opening' => 'opening', 'stock' => 'opening', 'quantity' => 'opening', 'qty' => 'opening',
        'quantity on hand' => 'opening', 'qty on hand' => 'opening', 'balance' => 'opening',
        'stock qty' => 'opening', 'opening stock qty' => 'opening',
        // application / location
        'application' => 'application', 'usage' => 'application', 'used for' => 'application',
        'equipment' => 'application', 'machine' => 'application', 'application area' => 'application',
        'location' => 'location', 'store' => 'location', 'storage' => 'location',
        'storage location' => 'location', 'bin' => 'location', 'bin location' => 'location',
        'shelf' => 'location', 'store location' => 'location', 'rack' => 'location',
        // re-order levels, price and preferred supplier (migration 007)
        'min stock' => 'min_stock', 'minimum stock' => 'min_stock', 'min' => 'min_stock',
        'reorder level' => 'min_stock', 're order level' => 'min_stock', 'min level' => 'min_stock',
        'max stock' => 'max_stock', 'maximum stock' => 'max_stock', 'max' => 'max_stock',
        'max level' => 'max_stock', 'target stock' => 'max_stock',
        'price ugx' => 'unit_price_ugx', 'unit price ugx' => 'unit_price_ugx',
        'unit price' => 'unit_price_ugx', 'price' => 'unit_price_ugx', 'cost' => 'unit_price_ugx',
        'supplier' => 'supplier_name', 'suppliers' => 'supplier_name',
        'supplier s' => 'supplier_name', 'vendor' => 'supplier_name',
        'vendor supplier' => 'supplier_name', 'preferred supplier' => 'supplier_name',
        // system-generated columns of an export — accepted and ignored
        'id' => '@ignore', 'system id' => '@ignore', 'no' => '@ignore',
        'qty received' => '@ignore', 'received' => '@ignore', 'total received' => '@ignore',
        'qty issued' => '@ignore', 'issued' => '@ignore', 'total issued' => '@ignore',
        'final stock' => '@ignore', 'final qty' => '@ignore', 'closing stock' => '@ignore',
        'stock on hand' => '@ignore', 'stock status' => '@ignore', 'status' => '@ignore',
        'supplier details' => '@ignore',
        'created at' => '@ignore', 'created' => '@ignore',
        'updated at' => '@ignore', 'updated' => '@ignore',
        'stock check' => '@ignore', 'difference' => '@ignore',
        'value' => '@ignore', 'amount' => '@ignore', 'alerte' => '@ignore',
        'alert' => '@ignore', 'variance' => '@ignore', 'qty to order' => '@ignore',
        'price usd' => '@ignore',
        'notes' => '@ignore', 'remarks' => '@ignore',
    );

    private $model;

    public function __construct($params = array()) {
        if (isset($params['model'])) {
            $this->model = $params['model'];
        } else {
            $ci = function_exists('get_instance') ? get_instance() : null;
            if ($ci && isset($ci->Materials_model)) {
                $this->model = $ci->Materials_model;
            } elseif ($ci) {
                $ci->load->model('Materials_model');
                $this->model = $ci->Materials_model;
            } else {
                throw new RuntimeException('Inventory_importer requires a Materials_model instance.');
            }
        }
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       CANONICAL COLUMN STRUCTURE (template / export / import)
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * The one and only definition of the inventory import layout.
     *
     * @return array list of ['key','label','field','required','type','width','help']
     */
    public static function column_defs() {
        return array(
            array('key' => 'item_code',   'label' => 'Item Code',      'field' => 'item_code',
                  'required' => true,  'type' => 'text',     'width' => 16,
                  'help' => 'Unique code of the item (e.g. CON001, F009). Identifies the record.'),
            array('key' => 'description', 'label' => 'Description',    'field' => 'description',
                  'required' => true,  'type' => 'text',     'width' => 34,
                  'help' => 'What the item is (e.g. Cutting disc 115x1.2x22).'),
            array('key' => 'category',    'label' => 'Category',       'field' => 'category',
                  'required' => false, 'type' => 'category', 'width' => 16,
                  'help' => 'One of the categories already used in the system (see Instructions).'),
            array('key' => 'part',        'label' => 'Part No / Size', 'field' => 'part_number_size',
                  'required' => false, 'type' => 'text',     'width' => 20,
                  'help' => 'Part number, size or grade (e.g. 15W-40, M20, ISO46).'),
            array('key' => 'unit',        'label' => 'Unit',           'field' => 'unit',
                  'required' => false, 'type' => 'unit',     'width' => 10,
                  'help' => 'Unit of measure (pc, L, Kg, …). Defaults to pcs.'),
            array('key' => 'opening',     'label' => 'Opening Stock',  'field' => 'initial_stock',
                  'required' => false, 'type' => 'int',      'width' => 14,
                  'help' => 'Opening balance of a NEW item. For an existing item it is only applied when a stock action is chosen.'),
            array('key' => 'application', 'label' => 'Application',    'field' => 'application',
                  'required' => false, 'type' => 'text',     'width' => 22,
                  'help' => 'Where/for what the item is used (e.g. WELDING, ENGINE).'),
            array('key' => 'location',    'label' => 'Location',       'field' => 'location',
                  'required' => false, 'type' => 'text',     'width' => 16,
                  'help' => 'Storage location / bin (e.g. L4, S2, Store A).'),
            array('key' => 'min_stock',   'label' => 'Min Stock',      'field' => 'min_stock',
                  'required' => false, 'type' => 'int',      'width' => 11,
                  'help' => 'Re-order level. The Re-order Report lists every item at or below it.'),
            array('key' => 'max_stock',   'label' => 'Max Stock',      'field' => 'max_stock',
                  'required' => false, 'type' => 'int',      'width' => 11,
                  'help' => 'Target level. Quantity to order = Max Stock - current stock (calculated, never typed).'),
            array('key' => 'unit_price_ugx', 'label' => 'Price UGX',   'field' => 'unit_price_ugx',
                  'required' => false, 'type' => 'decimal',  'width' => 14,
                  'help' => 'Last known unit price in UGX. Used to value the re-order report.'),
            array('key' => 'supplier_name',  'label' => 'Supplier',    'field' => 'supplier_name',
                  'required' => false, 'type' => 'text',     'width' => 26,
                  'help' => 'Preferred vendor. Linked automatically when the name matches a supplier on file.'),
        );
    }

    /** @return array label => required (used for the reader's header hints) */
    public static function expected_columns() {
        $out = array();
        foreach (self::column_defs() as $c) $out[$c['label']] = (bool)$c['required'];
        return $out;
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       HEADER VALIDATION
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /** "ITEM CODE *", "Item-Code", "Item Code (unique)" → "item code" */
    public function normalise_header($h) {
        $h = trim((string)$h);
        $h = preg_replace('/\([^)]*\)|\[[^\]]*\]/', ' ', $h);   // drop units/notes in brackets
        $h = function_exists('mb_strtolower') ? mb_strtolower($h, 'UTF-8') : strtolower($h);
        $h = preg_replace('/[^a-z0-9]+/u', ' ', $h);
        $h = preg_replace('/\s+/', ' ', $h);
        return trim($h);
    }

    /**
     * Validate the header row.
     * @return array ['ok','map','errors','warnings','ignored','unknown']
     */
    public function validate_headers($headers) {
        $map = array(); $errors = array(); $warnings = array(); $ignored = array(); $unknown = array();
        $seen = array(); $duplicates = array();

        foreach ($headers as $col => $label) {
            $norm = $this->normalise_header($label);
            if ($norm === '') continue;
            if (!isset($this->aliases[$norm])) { $unknown[] = trim((string)$label); continue; }
            $field = $this->aliases[$norm];
            if ($field === '@ignore') { $ignored[] = trim((string)$label); continue; }
            if (isset($seen[$field])) {
                $duplicates[] = trim((string)$label).' (same as column "'.$seen[$field].'")';
                continue;
            }
            $seen[$field] = trim((string)$label);
            $map[$col] = $field;
        }

        foreach (array('item_code' => 'Item Code', 'description' => 'Description') as $field => $label) {
            if (in_array($field, $map, true)) continue;
            $hint = '';
            $want = $this->normalise_header($label);
            foreach ($headers as $h) {
                $nh = $this->normalise_header($h);
                if ($nh !== '' && $nh !== $want && $this->similar($want, $nh)
                    && !isset($this->aliases[$nh])) {
                    $hint = ' Found the column "'.trim((string)$h).'" — did you mean "'.$label.'"?';
                    break;
                }
            }
            $errors[] = 'Required column "'.$label.'" is missing.'.$hint;
        }
        if (!empty($duplicates)) {
            $errors[] = 'Duplicate columns found: '.implode(', ', $duplicates).'. Keep one column per field.';
        }
        if (empty($map) && empty($errors)) {
            $errors[] = 'No recognisable column headers were found. The first row of the sheet must be the header row '
                      . '(use the Download Template file to be sure).';
        }
        if (!empty($unknown)) {
            $warnings[] = 'Column(s) not part of the import format, ignored: '
                        . implode(', ', array_slice($unknown, 0, self::MAX_TEXT_LIST))
                        . (count($unknown) > self::MAX_TEXT_LIST ? ' …' : '').'.';
        }
        if (!empty($ignored)) {
            $warnings[] = 'System-generated column(s) ignored (quantities are never imported from them): '
                        . implode(', ', array_slice($ignored, 0, self::MAX_TEXT_LIST))
                        . (count($ignored) > self::MAX_TEXT_LIST ? ' …' : '').'.';
        }

        return array(
            'ok'       => empty($errors),
            'map'      => $map,
            'errors'   => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'ignored'  => $ignored,
            'unknown'  => $unknown,
        );
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       ROW VALIDATION (shared by the import and the manual form)
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * Validate one inventory record.
     *
     * @param array $fields   item_code, description, category, part, unit, opening, application, location (raw)
     * @param array $ctx      ['category_map','unit_map','allow_new_categories','allow_new_units'] (model lookups)
     * @param array $errors   (by reference) error messages
     * @param array $warnings (by reference)
     * @param array $kinds    (by reference) ['required'=>n,'invalid'=>n] counts for the summary
     * @param array $opts     ['is_update'=>bool] — a blank cell on an update row keeps the stored value
     * @return array canonical values
     */
    public function validate_row($fields, $ctx, &$errors, &$warnings, &$kinds = null, $opts = array()) {
        if (!is_array($kinds)) $kinds = array();
        $kinds += array('required' => 0, 'invalid' => 0);

        $get = function($k) use ($fields) { return isset($fields[$k]) ? trim((string)$fields[$k]) : ''; };
        $isUpdate = !empty($opts['is_update']);

        $categoryMap = isset($ctx['category_map']) ? $ctx['category_map'] : array();
        $unitMap     = isset($ctx['unit_map'])     ? $ctx['unit_map']     : array();
        $allowCat    = !empty($ctx['allow_new_categories']);
        $allowUnit   = !empty($ctx['allow_new_units']);

        $v = array(
            'item_code' => '', 'description' => '', 'category' => '', 'category_display' => '',
            'part_number_size' => '', 'unit' => '', 'unit_display' => '',
            'initial_stock' => null, 'application' => '', 'location' => '',
            'min_stock' => null, 'max_stock' => null,
            'unit_price_ugx' => null, 'supplier_name' => '',
        );

        /* ── Item Code — the identifying column ── */
        $code = $get('item_code');
        if ($code === '') {
            $errors[] = 'Item Code is required (this column cannot be empty).';
            $kinds['required']++;
        } else {
            $code = preg_replace('/\s+/u', ' ', $code);
            $code = function_exists('mb_strtoupper') ? mb_strtoupper($code, 'UTF-8') : strtoupper($code);
            if ($this->len($code) > $this->lengths['item_code']) {
                $errors[] = 'Item Code "'.$this->shorten($code).'" is longer than '.$this->lengths['item_code'].' characters.';
                $kinds['invalid']++;
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $code)) {
                $errors[] = 'Item Code contains control characters that are not allowed.';
                $kinds['invalid']++;
            } elseif (!preg_match('/^[A-Z0-9][A-Z0-9 ._\/()\-]*$/i', $code)) {
                $errors[] = 'Item Code "'.$this->shorten($code).'" contains characters that are not allowed. '
                          . 'Use letters, numbers, spaces and . _ - / ( ).';
                $kinds['invalid']++;
            }
            $v['item_code'] = $code;
        }

        /* ── Description ── */
        $desc = preg_replace('/\s+/u', ' ', $get('description'));
        if ($desc === '') {
            if (!$isUpdate) {
                $errors[] = 'Description is required (this column cannot be empty).';
                $kinds['required']++;
            }
        } elseif ($this->len($desc) > $this->lengths['description']) {
            $errors[] = 'Description "'.$this->shorten($desc).'" is longer than '.$this->lengths['description'].' characters.';
            $kinds['invalid']++;
        } else {
            $v['description'] = $desc;
        }

        /* ── Category — must be a known value ── */
        $cat = preg_replace('/\s+/u', ' ', $get('category'));
        if ($cat !== '') {
            if ($this->len($cat) > $this->lengths['category']) {
                $errors[] = 'Category "'.$this->shorten($cat).'" is longer than '.$this->lengths['category'].' characters.';
                $kinds['invalid']++;
            } else {
                $k = $this->key($cat);
                if (isset($categoryMap[$k])) {
                    $v['category'] = $categoryMap[$k];          // canonical spelling from the DB
                    $v['category_display'] = $categoryMap[$k];
                } elseif ($allowCat) {
                    $v['category'] = $cat;
                    $v['category_display'] = $cat;
                    $warnings[] = 'New category "'.$cat.'" will be created.';
                } else {
                    $errors[] = 'Category "'.$cat.'" is not used in this system. Allowed values: '
                              . $this->value_list($categoryMap);
                    $kinds['invalid']++;
                }
            }
        }

        /* ── Part number / size ── */
        $part = preg_replace('/\s+/u', ' ', $get('part'));
        if ($part !== '') {
            if ($this->len($part) > $this->lengths['part_number_size']) {
                $errors[] = 'Part No / Size "'.$this->shorten($part).'" is longer than '.$this->lengths['part_number_size'].' characters.';
                $kinds['invalid']++;
            } else {
                $v['part_number_size'] = $part;
            }
        }

        /* ── Unit — must be a known value ── */
        $unit = preg_replace('/\s+/u', ' ', $get('unit'));
        if ($unit !== '') {
            if ($this->len($unit) > $this->lengths['unit']) {
                $errors[] = 'Unit "'.$this->shorten($unit).'" is longer than '.$this->lengths['unit'].' characters.';
                $kinds['invalid']++;
            } else {
                $k = $this->key($unit);
                if (isset($unitMap[$k])) {
                    $v['unit'] = $unitMap[$k];                  // canonical spelling from the DB
                    $v['unit_display'] = $unitMap[$k];
                } elseif ($allowUnit) {
                    $v['unit'] = $unit;
                    $v['unit_display'] = $unit;
                    $warnings[] = 'New unit "'.$unit.'" will be created.';
                } else {
                    $errors[] = 'Unit "'.$unit.'" is not used in this system. Allowed values: '
                              . $this->value_list($unitMap);
                    $kinds['invalid']++;
                }
            }
        }

        /* ── Opening stock — whole units only, never negative ── */
        $raw = $get('opening');
        if ($raw !== '') {
            $n = $this->parse_number($raw);
            if ($n === null) {
                $errors[] = 'Opening Stock "'.$this->shorten($raw).'" is not a valid number.';
                $kinds['invalid']++;
            } elseif ($n < 0) {
                $errors[] = 'Opening Stock cannot be negative.';
                $kinds['invalid']++;
            } elseif (abs($n - round($n)) > 0.0000001) {
                $errors[] = 'Opening Stock "'.$this->shorten($raw).'" must be a whole number '
                          . '(the stock columns store whole units).';
                $kinds['invalid']++;
            } elseif ($n > self::MAX_STOCK) {
                $errors[] = 'Opening Stock "'.$this->shorten($raw).'" is larger than the allowed maximum of '
                          . number_format(self::MAX_STOCK).'.';
                $kinds['invalid']++;
            } else {
                $v['initial_stock'] = (int)round($n);
            }
        }

        /* ── Application / Location ── */
        foreach (array('application', 'location') as $f) {
            $val = preg_replace('/\s+/u', ' ', $get($f));
            if ($val === '') continue;
            if ($this->len($val) > $this->lengths[$f]) {
                $errors[] = ucfirst($f).' "'.$this->shorten($val).'" is longer than '.$this->lengths[$f].' characters.';
                $kinds['invalid']++;
            } else {
                $v[$f] = $val;
            }
        }

        /* ── Min / Max stock — whole units, never negative, min <= max ── */
        foreach (array('min_stock' => 'Min Stock', 'max_stock' => 'Max Stock') as $f => $label) {
            $raw = $get($f);
            if ($raw === '') continue;
            $n = $this->parse_number($raw);
            if ($n === null) {
                $errors[] = $label.' "'.$this->shorten($raw).'" is not a valid number.';
                $kinds['invalid']++;
            } elseif ($n < 0) {
                $errors[] = $label.' cannot be negative.';
                $kinds['invalid']++;
            } elseif (abs($n - round($n)) > 0.0000001) {
                $errors[] = $label.' "'.$this->shorten($raw).'" must be a whole number.';
                $kinds['invalid']++;
            } elseif ($n > self::MAX_STOCK) {
                $errors[] = $label.' "'.$this->shorten($raw).'" is larger than the allowed maximum.';
                $kinds['invalid']++;
            } else {
                $v[$f] = (int)round($n);
            }
        }
        if ($v['min_stock'] !== null && $v['max_stock'] !== null && $v['max_stock'] < $v['min_stock']) {
            $warnings[] = 'Max Stock ('.$v['max_stock'].') is below Min Stock ('.$v['min_stock'].') — '
                        . 'the re-order report will only top the item up to the minimum.';
        }

        /* ── Unit price ── */
        $raw = $get('unit_price_ugx');
        if ($raw !== '') {
            $n = $this->parse_number($raw);
            if ($n === null) {
                $errors[] = 'Price UGX "'.$this->shorten($raw).'" is not a valid number.';
                $kinds['invalid']++;
            } elseif ($n < 0) {
                $errors[] = 'Price UGX cannot be negative.';
                $kinds['invalid']++;
            } else {
                $v['unit_price_ugx'] = round((float)$n, 2);
            }
        }

        /* ── Preferred supplier (free text; linked by name if it exists) ── */
        $sup = preg_replace('/\s+/u', ' ', $get('supplier_name'));
        if ($sup !== '') {
            if ($this->len($sup) > $this->lengths['supplier_name']) {
                $errors[] = 'Supplier "'.$this->shorten($sup).'" is longer than '.$this->lengths['supplier_name'].' characters.';
                $kinds['invalid']++;
            } else {
                $v['supplier_name'] = $sup;
            }
        }

        return $v;
    }

    /**
     * Validate input coming from the manual Add / Edit form — exactly the same
     * rules as the Excel import, plus the code-uniqueness check.
     *
     * @param array $input item_code, description, category, part_number_size, unit,
     *                     initial_stock, application, location
     * @param array $opts  ['is_update'=>bool,'item_id'=>int|null,'allow_new_categories'=>bool,'allow_new_units'=>bool]
     * @return array ['ok','values','errors','warnings','error_kinds','existing']
     */
    public function validate_manual($input, $opts = array()) {
        $catLookup  = $this->model->get_category_lookup();
        $unitLookup = $this->model->get_unit_lookup();

        $fields = array(
            'item_code'   => isset($input['item_code'])         ? $input['item_code']         : '',
            'description' => isset($input['description'])       ? $input['description']       : '',
            'category'    => isset($input['category'])          ? $input['category']          : '',
            'part'        => isset($input['part_number_size'])  ? $input['part_number_size']  : '',
            'unit'        => isset($input['unit'])              ? $input['unit']              : '',
            'opening'     => isset($input['initial_stock'])     ? $input['initial_stock']     : '',
            'application' => isset($input['application'])       ? $input['application']       : '',
            'location'    => isset($input['location'])          ? $input['location']          : '',
            'min_stock'      => isset($input['min_stock'])      ? $input['min_stock']      : '',
            'max_stock'      => isset($input['max_stock'])      ? $input['max_stock']      : '',
            'unit_price_ugx' => isset($input['unit_price_ugx']) ? $input['unit_price_ugx'] : '',
            'supplier_name'  => isset($input['supplier_name'])  ? $input['supplier_name']  : '',
        );

        $ctx = array(
            'category_map'         => $catLookup['map'],
            'unit_map'             => $unitLookup['map'],
            'allow_new_categories' => !empty($opts['allow_new_categories']),
            'allow_new_units'      => !empty($opts['allow_new_units']),
        );

        $errors = array(); $warnings = array(); $kinds = array();
        $values = $this->validate_row($fields, $ctx, $errors, $warnings, $kinds,
            array('is_update' => !empty($opts['is_update'])));

        /* Uniqueness — the form must never reach a raw duplicate-key DB error */
        $existing = null;
        if ($values['item_code'] !== '') {
            $excludeId = isset($opts['item_id']) ? $opts['item_id'] : null;
            $existing  = $this->model->find_by_code_key($values['item_code'], $excludeId);
            if ($existing) {
                $errors[] = 'Item Code "'.$values['item_code'].'" is already used by "'.$existing->item_code.'"'
                          . ($this->isBlank($existing->description) ? '' : ' — '.$existing->description)
                          . '. Edit that item instead of adding it again.';
                $kinds['duplicate'] = (isset($kinds['duplicate']) ? $kinds['duplicate'] : 0) + 1;
                $warnings[] = 'The existing item '.$existing->item_code.' currently holds '
                            . (int)$existing->final_qty.' '.($this->isBlank($existing->unit) ? 'units' : $existing->unit)
                            . ' in stock — use Receive Stock to add more.';
            }
        }

        return array(
            'ok'          => empty($errors),
            'values'      => $values,
            'errors'      => $errors,
            'warnings'    => $warnings,
            'error_kinds' => $kinds + array('required' => 0, 'invalid' => 0, 'duplicate' => 0),
            'existing'    => $existing,
        );
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       IMPORT
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * @param array $sheet   output of Xlsx_reader::read()
     * @param array $options [
     *     'duplicate_action'        => skip|update|fail        (default skip)
     *     'stock_action'            => ignore|receive|set      (default ignore)
     *     'allow_new_categories'    => 0|1
     *     'allow_new_units'         => 0|1
     *     'allow_duplicate_records' => 0|1  (import a new code whose
     *                                  description+part+unit matches an existing item)
     *     'dry_run'                 => 0|1
     *     'file_name'               => original upload name
     *     'user_id'                 => who imports (movement log + audit log)
     *     'max_rows'                => int (default MAX_ROWS)
     * ]
     */
    public function import($sheet, $options = array()) {
        $dupAction = isset($options['duplicate_action']) ? $options['duplicate_action'] : 'skip';
        if (!in_array($dupAction, array('skip', 'update', 'fail'), true)) $dupAction = 'skip';
        $stockAction = isset($options['stock_action']) ? $options['stock_action'] : 'ignore';
        if (!in_array($stockAction, array('ignore', 'receive', 'set'), true)) $stockAction = 'ignore';

        $allowNewCats    = !empty($options['allow_new_categories']);
        $allowNewUnits   = !empty($options['allow_new_units']);
        $allowDupRecords = !empty($options['allow_duplicate_records']);
        $dryRun   = !empty($options['dry_run']);
        $maxRows  = isset($options['max_rows']) ? (int)$options['max_rows'] : self::MAX_ROWS;
        $fileName = isset($options['file_name']) ? $options['file_name'] : '';
        $userId   = isset($options['user_id']) ? $options['user_id'] : null;

        $result = array(
            'file_name' => $fileName,
            'sheet'     => isset($sheet['sheet']) ? $sheet['sheet'] : '',
            'mode'      => $dryRun ? 'validate' : 'import',
            'duplicate_action'        => $dupAction,
            'stock_action'            => $stockAction,
            'allow_new_categories'    => $allowNewCats,
            'allow_new_units'         => $allowNewUnits,
            'allow_duplicate_records' => $allowDupRecords,
            'header'    => array(),
            'rows'      => array(),
            'summary'   => array(
                'total_rows' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0,
                'failed' => 0, 'duplicates' => 0, 'missing_required' => 0,
                'invalid_values' => 0, 'warnings' => 0,
            ),
            'errors'          => array(),
            'warnings'        => array(),
            'rolled_back'     => false,
            'rollback_reason' => '',
            'transaction'     => $this->model->can_transact(),
            'ok'              => false,
        );

        /* 1. Headers */
        $hv = $this->validate_headers(isset($sheet['headers']) ? $sheet['headers'] : array());
        $result['header'] = $hv;
        if (!$hv['ok']) {
            $result['errors']   = $hv['errors'];
            $result['warnings'] = $hv['warnings'];
            return $result;                       // nothing is read or written
        }
        $result['warnings'] = $hv['warnings'];

        /* 2. Reference data (loaded once — no queries inside the row loop) */
        $catLookup  = $this->model->get_category_lookup();
        $unitLookup = $this->model->get_unit_lookup();
        $index      = $this->model->get_existing_index();
        $ctx = array(
            'category_map'         => $catLookup['map'],
            'unit_map'             => $unitLookup['map'],
            'allow_new_categories' => $allowNewCats,
            'allow_new_units'      => $allowNewUnits,
        );

        $rows  = isset($sheet['rows']) ? $sheet['rows'] : array();
        $total = count($rows);
        if ($maxRows > 0 && $total > $maxRows) {
            $result['errors'][] = 'The file contains '.number_format($total).' data rows; the maximum per import is '
                                . number_format($maxRows).'. Split the file and import it in parts.';
            return $result;
        }
        if ($total === 0) {
            $result['errors'][] = 'No data rows found. The first row is used as the header row, '
                                . 'so at least one item row must follow it.';
            return $result;
        }

        /* 3. Apply — inside one transaction so a critical failure cannot leave
              half of the file behind. */
        $inTransaction = (!$dryRun && $this->model->begin_transaction());
        $hardFail = false; $hardFailRow = 0; $hardFailMsg = '';

        $seenCode = array();     // normalised code      => Excel row number (this file)
        $seenSig  = array();     // signature            => Excel row number (this file)

        foreach ($rows as $r) {
            $excelRow = (int)$r['row'];
            $fields = array();
            foreach ($hv['map'] as $col => $field) {
                $fields[$field] = isset($r['cells'][$col]) ? $r['cells'][$col] : '';
            }

            $nonEmpty = array_filter($fields, function($val) { return trim((string)$val) !== ''; });
            if (empty($nonEmpty)) continue;                     // blank line — ignored

            $st = array(
                'row' => $excelRow, 'item_code' => '', 'description' => '',
                'action' => 'skipped', 'errors' => array(), 'warnings' => array(),
                'error_kinds' => array('required' => 0, 'invalid' => 0, 'duplicate' => 0),
                'values' => array(), 'raw' => $fields, 'stock_note' => '',
            );
            $result['summary']['total_rows']++;

            $codeKey = $this->key(isset($fields['item_code']) ? $fields['item_code'] : '');
            $st['item_code']   = trim((string)(isset($fields['item_code']) ? $fields['item_code'] : ''));
            $st['description'] = trim((string)(isset($fields['description']) ? $fields['description'] : ''));

            $inFileRow = ($codeKey !== '' && isset($seenCode[$codeKey])) ? $seenCode[$codeKey] : null;
            $existing  = ($codeKey !== '' && isset($index['by_code'][$codeKey])) ? $index['by_code'][$codeKey] : null;
            $isUpdate  = ($dupAction === 'update' && $existing !== null);

            $v = $this->validate_row($fields, $ctx, $st['errors'], $st['warnings'], $st['error_kinds'],
                                     array('is_update' => $isUpdate));
            $st['values'] = $v;
            if ($v['item_code'] !== '')   $st['item_code']   = $v['item_code'];
            if ($v['description'] !== '') $st['description'] = $v['description'];

            if (!empty($st['errors'])) {
                $st['action'] = 'failed';
                $this->count_row($result, $st);
                $result['rows'][] = $st;
                continue;
            }

            /* Duplicate inside the file */
            if ($inFileRow !== null) {
                $st['error_kinds']['duplicate']++;
                $msg = 'Duplicate of row '.$inFileRow.' of this file (same Item Code).';
                if ($dupAction === 'skip') {
                    $st['action'] = 'skipped';
                    $st['warnings'][] = $msg.' Skipped.';
                } else {
                    $st['action'] = 'failed';
                    $st['errors'][] = $msg.' Merge the two rows into one and import again.';
                }
                $this->count_row($result, $st);
                $result['rows'][] = $st;
                continue;
            }
            if ($codeKey !== '') $seenCode[$codeKey] = $excelRow;

            /* Near-duplicate record: same description + part + unit as another item */
            $sig = $this->signature($v);
            $dupRecord = null;
            if ($sig !== null) {
                if (isset($seenSig[$sig]) && $seenSig[$sig] !== $excelRow) {
                    $dupRecord = array('label' => 'row '.$seenSig[$sig].' of this file');
                } elseif (isset($index['by_signature'][$sig])) {
                    foreach ($index['by_signature'][$sig] as $o) {
                        if ($existing !== null && (int)$o['id'] === (int)$existing['id']) continue;
                        $dupRecord = array('label' => 'item "'.$o['item_code'].'"');
                        break;
                    }
                }
                if (!isset($seenSig[$sig])) $seenSig[$sig] = $excelRow;
            }

            if ($dupRecord !== null && !$allowDupRecords) {
                $st['error_kinds']['duplicate']++;
                $msg = 'An item with the same Description + Part No + Unit already exists ('.$dupRecord['label'].'). '
                     . 'Use that item code, or tick "Allow duplicate records" to import it anyway.';
                if ($dupAction === 'skip') {
                    $st['action'] = 'skipped';
                    $st['warnings'][] = $msg.' Skipped.';
                } else {
                    $st['action'] = 'failed';
                    $st['errors'][] = $msg;
                }
                $this->count_row($result, $st);
                $result['rows'][] = $st;
                continue;
            }
            if ($dupRecord !== null) {
                $st['warnings'][] = 'Possible duplicate: the same Description + Part No + Unit exists ('
                                  . $dupRecord['label'].'). Imported because "Allow duplicate records" is on.';
            }

            /* Existing item code → duplicate policy */
            if ($existing !== null) {
                $st['error_kinds']['duplicate']++;

                if ($dupAction === 'fail') {
                    $st['action'] = 'failed';
                    $st['errors'][] = 'Item Code "'.$v['item_code'].'" already exists in the inventory '
                                    . '(duplicate policy: reject duplicates).';
                    $this->count_row($result, $st);
                    $result['rows'][] = $st;
                    continue;
                }
                if ($dupAction === 'skip') {
                    $st['action'] = 'skipped';
                    $st['warnings'][] = 'Item Code "'.$v['item_code'].'" already exists ('
                                      . ($this->isBlank($existing['description']) ? 'no description' : $existing['description'])
                                      . ', stock '.$existing['final_qty'].') — skipped. '
                                      . 'Choose "Update existing items" to change its details.';
                    $this->count_row($result, $st);
                    $result['rows'][] = $st;
                    continue;
                }

                /* dupAction = update → master data only; stock handled explicitly */
                $payload = $this->master_payload($v, true);
                unset($payload['item_code']);           // the code is the match, never rewritten

                if ($dryRun) {
                    $st['action'] = 'updated';
                    $this->apply_stock_preview($st, $v, $existing, $stockAction);
                    $st['warnings'][] = 'Validation only — nothing was written to the database.';
                    $this->count_row($result, $st);
                    $result['rows'][] = $st;
                    continue;
                }

                if (empty($payload) && $v['initial_stock'] === null) {
                    $st['action'] = 'skipped';
                    $st['warnings'][] = 'Nothing to update — every cell of this row is blank.';
                    $this->count_row($result, $st);
                    $result['rows'][] = $st;
                    continue;
                }

                $ok = true; $dbMsg = null;
                if (!empty($payload)) {
                    $ok = $this->model->update_item($existing['id'], $payload, false);
                    $dbMsg = $this->model->last_db_error();
                }
                if ($ok) {
                    $stock = $this->apply_stock($st, $v, $existing, $stockAction, $userId, $fileName);
                    if ($stock === false) { $ok = false; $dbMsg = $this->model->last_db_error(); }
                }

                if ($ok) {
                    $st['action'] = 'updated';
                    $index['by_code'][$codeKey] = array_merge($existing, array(
                        'description' => isset($payload['description']) ? $payload['description'] : $existing['description'],
                        'unit'        => isset($payload['unit']) ? $payload['unit'] : $existing['unit'],
                        'part_number_size' => isset($payload['part_number_size']) ? $payload['part_number_size'] : $existing['part_number_size'],
                    ));
                } else {
                    $st['action'] = 'failed';
                    if (empty($st['errors'])) {
                        $st['errors'][] = 'Database error'.($dbMsg ? ': '.$dbMsg : ' — the row could not be saved.');
                    }
                    $hardFail = true; $hardFailRow = $excelRow; $hardFailMsg = $dbMsg ? $dbMsg : 'write failed';
                }
                $this->count_row($result, $st);
                $result['rows'][] = $st;
                continue;
            }

            /* New item */
            $payload = $this->master_payload($v, false);
            $opening = ($v['initial_stock'] === null) ? 0 : (int)$v['initial_stock'];

            if ($dryRun) {
                $st['action'] = 'inserted';
                if ($opening > 0) {
                    $st['stock_note'] = 'Opening stock '.$opening.' will be recorded as a RECEIVE movement.';
                }
                $st['warnings'][] = 'Validation only — nothing was written to the database.';
                $this->count_row($result, $st);
                $result['rows'][] = $st;
                continue;
            }

            $newId = $this->model->insert_item($payload);
            if (!$newId) {
                $dbMsg = $this->model->last_db_error();
                $st['action'] = 'failed';
                $st['errors'][] = 'Database error'.($dbMsg ? ': '.$dbMsg : ' — the item could not be saved.');
                $hardFail = true; $hardFailRow = $excelRow; $hardFailMsg = $dbMsg ? $dbMsg : 'insert failed';
            } else {
                if ($opening > 0) {
                    $logged = $this->model->log_opening_stock($newId, $opening, $userId,
                        'Opening stock — Excel import'.($fileName !== '' ? ' ('.$fileName.')' : ''));
                    if ($logged === false) {
                        $dbMsg = $this->model->last_db_error();
                        $st['action'] = 'failed';
                        $st['errors'][] = 'The opening stock movement could not be recorded'
                                        . ($dbMsg ? ': '.$dbMsg : '.').' The import was rolled back.';
                        $hardFail = true; $hardFailRow = $excelRow; $hardFailMsg = $dbMsg ? $dbMsg : 'movement failed';
                        $this->count_row($result, $st);
                        $result['rows'][] = $st;
                        continue;
                    }
                    $st['stock_note'] = 'Opening stock '.$opening.' recorded as a RECEIVE movement.';
                }
                $st['action'] = 'inserted';
                $index['by_code'][$codeKey] = array(
                    'id' => $newId, 'item_code' => $v['item_code'], 'description' => $v['description'],
                    'unit' => isset($payload['unit']) ? $payload['unit'] : 'pcs',
                    'part_number_size' => isset($payload['part_number_size']) ? $payload['part_number_size'] : '',
                    'initial_stock' => $opening, 'qty_received' => 0, 'qty_sortie' => 0,
                    'final_qty' => $opening, 'category' => isset($payload['category']) ? $payload['category'] : '',
                );
                if ($sig !== null) $index['by_signature'][$sig][] = $index['by_code'][$codeKey];
            }
            $this->count_row($result, $st);
            $result['rows'][] = $st;
        }

        /* 4. Commit or roll back */
        if ($inTransaction) {
            $statusOk = $this->model->transaction_status();
            if ($hardFail || !$statusOk) {
                $this->model->rollback_transaction();
                $this->mark_rolled_back($result, $hardFailRow, $hardFailMsg, $statusOk);
            } else {
                $this->model->commit_transaction();
            }
        } elseif (!$dryRun && !$this->model->can_transact()) {
            $result['warnings'][] = 'This database driver does not support transactions; '
                                  . 'rows were written one by one.';
        }

        $result['ok'] = ($result['summary']['failed'] === 0);
        return $result;
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       internals
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /** Build the DB payload from validated values (only the filled fields). */
    private function master_payload($v, $isUpdate) {
        $row = array();
        if ($v['item_code'] !== '')        $row['item_code'] = $v['item_code'];
        if ($v['description'] !== '')      $row['description'] = $v['description'];
        if ($v['category'] !== '')         $row['category'] = $v['category'];
        if ($v['part_number_size'] !== '') $row['part_number_size'] = $v['part_number_size'];
        if ($v['unit'] !== '')             $row['unit'] = $v['unit'];
        if ($v['application'] !== '')      $row['application'] = $v['application'];
        if ($v['location'] !== '')         $row['location'] = $v['location'];
        // Re-order / pricing columns — written only when the schema has them.
        if ($this->model->has_extended_fields()) {
            if (isset($v['min_stock'])      && $v['min_stock']      !== null) $row['min_stock']      = (int)$v['min_stock'];
            if (isset($v['max_stock'])      && $v['max_stock']      !== null) $row['max_stock']      = (int)$v['max_stock'];
            if (isset($v['unit_price_ugx']) && $v['unit_price_ugx'] !== null) $row['unit_price_ugx'] = (float)$v['unit_price_ugx'];
            if (!empty($v['supplier_name']))                                  $row['supplier_name']  = $v['supplier_name'];
        }
        if (!$isUpdate) {
            if (!isset($row['category'])) $row['category'] = 'CONSUMABLE';   // column default
            if (!isset($row['unit']))     $row['unit']     = 'pcs';          // column default
            if ($v['initial_stock'] !== null) $row['initial_stock'] = (int)$v['initial_stock'];
        }
        return $row;
    }

    /**
     * Apply the chosen stock action to an existing item.
     * @return true|false|null true = stock changed, null = nothing to do, false = failure
     */
    private function apply_stock(&$st, $v, $existing, $stockAction, $userId, $fileName) {
        if ($v['initial_stock'] === null) return null;
        $qty = (int)$v['initial_stock'];

        if ($stockAction === 'ignore') {
            $st['warnings'][] = 'Opening Stock '.$qty.' was NOT applied: the item already exists and stock '
                              . 'handling is "leave the stock unchanged". Use Receive / Issue, or choose a '
                              . 'stock action, to change the balance.';
            return null;
        }

        if ($stockAction === 'receive') {
            if ($qty <= 0) {
                $st['warnings'][] = 'Opening Stock is 0 — no movement was recorded.';
                return null;
            }
            $move = $this->model->add_stock_movement($existing['id'], 'RECEIVE', $qty,
                'Stock received — Excel import'.($fileName !== '' ? ' ('.$fileName.')' : ''), $userId);
            if ($move === false) {
                $st['errors'][] = 'The stock movement for "'.$existing['item_code'].'" could not be recorded'
                                . ($this->model->last_db_error() ? ': '.$this->model->last_db_error() : '.').'';
                return false;
            }
            $st['stock_note'] = 'Received '.$qty.' (stock '.$move['before'].' → '.$move['after']
                              . ') as a RECEIVE movement.';
            return true;
        }

        if ($stockAction === 'set') {
            if ((int)$existing['qty_received'] !== 0 || (int)$existing['qty_sortie'] !== 0) {
                $st['errors'][] = 'Opening Stock '.$qty.' cannot be set for "'.$existing['item_code'].'": the item '
                                . 'already has received/issued movements ('.$existing['qty_received'].' received, '
                                . $existing['qty_sortie'].' issued). Choose "Add to stock as a received movement" '
                                . 'or use the item\'s Receive / Issue actions.';
                return false;
            }
            $old = (int)$existing['initial_stock'];
            $ok = $this->model->set_opening_stock($existing['id'], $qty, $userId,
                'Opening stock changed by Excel import'.($fileName !== '' ? ' ('.$fileName.')' : ''));
            if ($ok === false) {
                $st['errors'][] = 'The opening stock of "'.$existing['item_code'].'" could not be changed'
                                . ($this->model->last_db_error() ? ': '.$this->model->last_db_error() : '.').'';
                return false;
            }
            $st['stock_note'] = 'Opening stock changed '.$old.' → '.$qty.' (logged as a movement).';
            return true;
        }
        return null;
    }

    /** Dry-run description of what the stock action would do (may set the row to failed). */
    private function apply_stock_preview(&$st, $v, $existing, $stockAction) {
        if ($v['initial_stock'] === null) return;
        $qty = (int)$v['initial_stock'];
        if ($stockAction === 'ignore') {
            $st['stock_note'] = 'Opening Stock '.$qty.' would be ignored (stock handling = leave unchanged).';
        } elseif ($stockAction === 'receive') {
            $st['stock_note'] = 'Would add '.$qty.' to the stock as a RECEIVE movement.';
        } elseif ($stockAction === 'set') {
            if ((int)$existing['qty_received'] !== 0 || (int)$existing['qty_sortie'] !== 0) {
                $st['action'] = 'failed';
                $st['errors'][] = 'Opening Stock '.$qty.' cannot be set for "'.$existing['item_code'].'": the item '
                                . 'already has received/issued movements ('.$existing['qty_received'].' received, '
                                . $existing['qty_sortie'].' issued).';
            } else {
                $st['stock_note'] = 'Would replace the opening balance ('.$existing['initial_stock'].' → '.$qty
                                  . ') and log the change.';
            }
        }
    }

    /** Update the summary counters for one processed row. */
    private function count_row(&$result, $st) {
        $s = &$result['summary'];
        $action = $st['action'];
        if ($action === 'inserted')     $s['inserted']++;
        elseif ($action === 'updated')  $s['updated']++;
        elseif ($action === 'skipped')  $s['skipped']++;
        else                            $s['failed']++;

        $s['duplicates'] += (int)$st['error_kinds']['duplicate'];
        if ($st['error_kinds']['required'] > 0) $s['missing_required']++;
        if ($st['error_kinds']['invalid']  > 0) $s['invalid_values']++;
        foreach ($st['warnings'] as $w) if ($w !== '') $s['warnings']++;
    }

    /** After a rollback: no row was really applied — say so instead of lying. */
    private function mark_rolled_back(&$result, $row, $msg, $statusOk) {
        $result['rolled_back'] = true;
        $result['rollback_reason'] = $row > 0
            ? 'A database error occurred while writing row '.$row.($msg ? ' ('.$msg.')' : '').'.'
            : ($statusOk ? 'The database reported an error while writing the file.'
                         : 'A database write failed.');
        $result['summary']['inserted'] = 0;
        $result['summary']['updated']  = 0;
        $result['summary']['skipped']  = 0;
        foreach ($result['rows'] as $i => $r) {
            if (in_array($r['action'], array('inserted', 'updated'), true)) {
                $result['rows'][$i]['rolled_back'] = true;
                $result['rows'][$i]['action'] = 'failed';
                $result['rows'][$i]['errors'][] = 'Rolled back — '.$result['rollback_reason'];
                $result['summary']['failed']++;
            }
        }
        $result['errors'][] = 'Nothing was imported — the change was rolled back. '.$result['rollback_reason'];
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       VALUE PARSERS (public so they can be unit tested)
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * "1 250", "1,250", "8420", "1.250,5", "1250 pcs" → float | null
     * (Same separator rules as the asset importer, plus trailing unit words.)
     */
    public function parse_number($raw) {
        $s = trim((string)$raw);
        if ($s === '') return null;
        $s = str_replace(array("\xC2\xA0", ' '), '', $s);
        // drop a trailing unit word ("1250 pcs", "50 ltr", "12 kg")
        $s = preg_replace('/[A-Za-z.]{1,12}$/', '', $s);
        $s = trim($s);
        if ($s === '') return null;

        $hasDot   = strpos($s, '.') !== false;
        $hasComma = strpos($s, ',') !== false;

        if ($hasDot && $hasComma) {
            if (strrpos($s, ',') > strrpos($s, '.')) {          // 1.234,56
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {                                            // 1,234.56
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma) {
            $isThousands = (bool)preg_match('/^-?\d{1,3}(,\d{3})+$/', $s);
            $s = $isThousands ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($hasDot) {
            $isThousands = (bool)preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s);
            if ($isThousands) $s = str_replace('.', '', $s);
        }

        if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) return null;
        return (float)$s;
    }

    /** Comparison key for duplicate detection (case/whitespace insensitive). */
    public function key($s) {
        if (method_exists($this->model, 'key')) return $this->model->key($s);
        $s = preg_replace('/\s+/', ' ', trim((string)$s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /** Signature of the record (delegates to the model when available). */
    public function signature($values) {
        if (method_exists($this->model, 'signature')) {
            return $this->model->signature(array(
                'description'      => isset($values['description']) ? $values['description'] : '',
                'part_number_size' => isset($values['part_number_size']) ? $values['part_number_size'] : '',
                'unit'             => isset($values['unit']) ? $values['unit'] : '',
            ));
        }
        return null;
    }

    /* ── small helpers ── */

    public function isBlank($s) { return trim((string)$s) === ''; }

    private function len($s) {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    private function shorten($s, $max = 40) {
        $s = (string)$s;
        if ($this->len($s) <= $max) return $s;
        $cut = function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
        return $cut.'…';
    }

    /** Comma separated list of known values, capped for readability. */
    private function value_list($map) {
        $values = array_values($map);
        if (empty($values)) return '(none defined yet).';
        $shown = array_slice($values, 0, self::MAX_TEXT_LIST);
        return implode(', ', $shown).(count($values) > self::MAX_TEXT_LIST ? ', …' : '').'.';
    }

    /** Are two normalised headers almost the same (for a "did you mean" hint)? */
    private function similar($a, $b) {
        if ($a === '' || $b === '') return false;
        return levenshtein($a, $b) <= 2;
    }
}
