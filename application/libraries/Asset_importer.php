<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Asset_importer — validated Excel import of asset register records.
 *
 * Design principles
 * ─────────────────
 *  • Never touch an existing asset unless the user explicitly chooses the
 *    "update duplicates" policy; the default is to SKIP duplicates.
 *  • A bad row never aborts the whole import: every row is validated and
 *    reported individually (imported / updated / skipped / failed + reason).
 *  • Blank cells mean "not recorded": they are ignored on update and never
 *    overwrite existing data.
 *  • Column headers are matched case/space/punctuation-insensitively and
 *    accept common aliases, so a hand-made file has a fair chance of working.
 *  • All database access goes through Asset_model (no raw SQL here).
 *
 * Usage (inside CodeIgniter):
 *   $this->load->library('asset_importer', array('model' => $this->Asset_model));
 *   $result = $this->asset_importer->import($path, $options);
 *
 * For standalone tests a model-like object can be injected through the
 * 'model' parameter; it only has to provide the methods used below.
 */
#[AllowDynamicProperties]
class Asset_importer {

    /** Hard limits (protect the server against accidental huge uploads) */
    const MAX_ROWS     = 2000;
    const MAX_NOTES    = 5000;   // text column is a TEXT field; keep sane
    const MAX_TYPES_OUT= 60;     // names listed in error messages

    /** DB column lengths (validated before insert so MySQL never truncates) */
    private $lengths = array(
        'registration_no' => 50,
        'brand'           => 100,
        'model'           => 100,
        'serial_number'   => 100,
        'engine_model'    => 100,
        'location'        => 200,
    );

    /** Header aliases: normalised header => internal field key */
    private $aliases = array(
        // machine / registration number
        'machine id' => 'reg', 'machine no' => 'reg', 'machine number' => 'reg',
        'registration no' => 'reg', 'registration number' => 'reg', 'registration' => 'reg',
        'reg no' => 'reg', 'reg' => 'reg', 'asset id' => 'reg', 'asset no' => 'reg',
        'asset number' => 'reg', 'asset code' => 'reg', 'equipment id' => 'reg',
        'equipment no' => 'reg', 'machine' => 'reg', 'fleet no' => 'reg', 'code' => 'reg',
        // type
        'type' => 'type', 'equipment type' => 'type', 'asset type' => 'type',
        'machine type' => 'type', 'class' => 'type',
        'category' => '@ignore',
        // brand / model
        'brand' => 'brand', 'make' => 'brand', 'manufacturer' => 'brand',
        'model' => 'model', 'model no' => 'model', 'model number' => 'model',
        'year' => 'year', 'year made' => 'year', 'year of manufacture' => 'year', 'yom' => 'year',
        'serial no' => 'serial', 'serial number' => 'serial', 'serial' => 'serial',
        'chassis no' => 'serial', 'chassis number' => 'serial', 'vin' => 'serial',
        'engine model' => 'engine', 'engine no' => 'engine', 'engine number' => 'engine',
        'engine' => 'engine', 'engine type' => 'engine',
        'current hours' => 'hours', 'hours' => 'hours', 'hrs' => 'hours',
        'hour meter' => 'hours', 'hourmeter' => 'hours', 'meter reading' => 'hours',
        'current km' => 'km', 'km' => 'km', 'kms' => 'km', 'kilometers' => 'km',
        'kilometres' => 'km', 'odometer' => 'km', 'mileage' => 'km',
        'location' => 'location', 'site' => 'location', 'location pit' => 'location',
        'pit' => 'location', 'current location' => 'location', 'area' => 'location',
        'status' => 'status', 'condition' => 'status', 'machine status' => 'status',
        'purchase date' => 'purchase', 'date purchased' => 'purchase',
        'purchased' => 'purchase', 'acquisition date' => 'purchase', 'acquired' => 'purchase',
        'warranty expiry' => 'warranty', 'warranty' => 'warranty',
        'warranty expiry date' => 'warranty', 'warranty end' => 'warranty', 'warranty until' => 'warranty',
        'notes' => 'notes', 'note' => 'notes', 'remarks' => 'notes',
        'remark' => 'notes', 'comment' => 'notes', 'comments' => 'notes', 'description' => 'notes',
        // accepted but ignored (system generated)
        'id' => '@ignore', 'system id' => '@ignore', 'equipment type id' => '@ignore',
        'availability' => '@ignore', 'availability %' => '@ignore', 'total wos' => '@ignore',
        'breakdown wos' => '@ignore', 'corrective wos' => '@ignore', 'completed wos' => '@ignore',
        'open wos' => '@ignore', 'downtime h' => '@ignore', 'next pm' => '@ignore',
        'pm due date' => '@ignore', 'pm due hours' => '@ignore', 'last pm done' => '@ignore',
        'pm interval' => '@ignore', 'days left' => '@ignore', 'created at' => '@ignore',
        'updated at' => '@ignore', 'warranty status' => '@ignore',
    );

    private $model;
    private $excel;          // optional Asset_excel instance (only for messages)
    private $log = array();

    public function __construct($params = array()) {
        if (isset($params['model'])) {
            $this->model = $params['model'];
        } else {
            $ci = function_exists('get_instance') ? get_instance() : null;
            if ($ci && isset($ci->Asset_model)) {
                $this->model = $ci->Asset_model;
            } elseif ($ci) {
                $ci->load->model('Asset_model');
                $this->model = $ci->Asset_model;
            } else {
                throw new RuntimeException('Asset_importer requires an Asset_model instance.');
            }
        }
        if (isset($params['excel'])) $this->excel = $params['excel'];
    }

    /**
     * The canonical column structure (shared with the template and the export).
     * @return array label => required bool
     */
    public static function expected_columns() {
        return array(
            'Machine ID'      => true,
            'Type'            => true,
            'Brand'           => false,
            'Model'           => false,
            'Year'            => false,
            'Serial No'       => false,
            'Engine Model'    => false,
            'Current Hours'   => false,
            'Current KM'      => false,
            'Location'        => false,
            'Status'          => false,
            'Purchase Date'   => false,
            'Warranty Expiry' => false,
            'Notes'           => false,
        );
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       HEADER VALIDATION
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * Normalise a header cell for matching: lowercase, single spaces, no
     * punctuation.  This is what makes "Machine ID *", "MACHINE-ID",
     * "Serial No." and "Registration  Number" all match their alias.
     */
    public function normalise_header($h) {
        $h = trim((string)$h);
        $h = preg_replace('/\([^)]*\)|\[[^\]]*\]/', ' ', $h);   // units/notes in brackets
        $h = function_exists('mb_strtolower') ? mb_strtolower($h, 'UTF-8') : strtolower((string)($h));
        $h = preg_replace('/[^a-z0-9]+/u', ' ', $h);   // drop * / . - ( ) % [ ] … entirely
        $h = preg_replace('/\s+/', ' ', $h);
        return trim((string)($h));
    }

    private function header_field($normalised) {
        if (isset($this->aliases[$normalised])) return $this->aliases[$normalised];
        return null; // unknown
    }

    /**
     * Validate the header row of a parsed sheet.
     *
     * @return array ['ok'=>bool, 'map'=>[colIdx=>field], 'errors'=>[], 'warnings'=>[], 'ignored'=>[]]
     */
    public function validate_headers($headers) {
        $map = array(); $errors = array(); $warnings = array(); $ignored = array(); $unknown = array();
        $seen = array(); $duplicates = array();

        foreach ($headers as $col => $label) {
            $norm = $this->normalise_header($label);
            if ($norm === '') continue;
            $field = $this->header_field($norm);
            if ($field === null) {
                // Column that is not part of the format
                $unknown[] = trim((string)($label));
                continue;
            }
            if ($field === '@ignore') {
                $ignored[] = trim((string)($label));
                continue;
            }
            if (isset($seen[$field])) {
                $duplicates[] = trim((string)($label)).' (same as column "'.$seen[$field].'")';
                continue;
            }
            $seen[$field] = trim((string)($label));
            $map[$col] = $field;
        }

        // Required columns present?
        $required = array('reg' => 'Machine ID', 'type' => 'Type');
        foreach ($required as $field => $label) {
            if (!in_array($field, $map, true)) {
                $errors[] = 'Required column "'.$label.'" is missing.';
            }
        }
        if (!empty($duplicates)) {
            $errors[] = 'Duplicate columns found: '.implode(', ', $duplicates).'. Keep one column per field.';
        }
        if (empty($map) && empty($errors)) {
            $errors[] = 'No recognisable column headers were found in the first row of the sheet.';
        }
        if (!empty($unknown)) {
            $warnings[] = 'Column(s) not part of the import format, ignored: '.implode(', ', $unknown).'.';
        }
        if (!empty($ignored)) {
            $warnings[] = 'System-generated column(s) ignored: '.implode(', ', $ignored).'.';
        }
        $warnings = array_values(array_unique($warnings));

        return array('ok' => empty($errors), 'map' => $map, 'errors' => $errors, 'warnings' => $warnings, 'ignored' => $ignored);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       IMPORT
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * @param array $sheet   output of Xlsx_reader::read()
     * @param array $options [
     *     'duplicate_action' => skip|update|fail,   (default skip)
     *     'create_types'     => 0|1,                (default 0)
     *     'dry_run'          => 0|1,                (default 0)
     *     'file_name'        => original file name,
     *     'max_rows'         => int (default MAX_ROWS)
     * ]
     */
    public function import($sheet, $options = array()) {
        $dupAction   = isset($options['duplicate_action']) ? $options['duplicate_action'] : 'skip';
        if (!in_array($dupAction, array('skip', 'update', 'fail'), true)) $dupAction = 'skip';
        $createTypes = !empty($options['create_types']);
        $dryRun      = !empty($options['dry_run']);
        $maxRows     = isset($options['max_rows']) ? (int)$options['max_rows'] : self::MAX_ROWS;
        $fileName    = isset($options['file_name']) ? $options['file_name'] : '';

        $result = array(
            'file_name' => $fileName,
            'sheet'     => isset($sheet['sheet']) ? $sheet['sheet'] : '',
            'mode'      => $dryRun ? 'validate' : 'import',
            'duplicate_action' => $dupAction,
            'create_types'     => $createTypes,
            'header'    => array(),
            'rows'      => array(),
            'summary'   => array(
                'total_rows' => 0, 'inserted' => 0, 'updated' => 0,
                'skipped' => 0, 'failed' => 0, 'duplicates' => 0, 'warnings' => 0,
            ),
            'errors'    => array(),
            'warnings'  => array(),
            'ok'        => false,
        );

        // 1. Headers
        $hv = $this->validate_headers($sheet['headers']);
        $result['header'] = $hv;
        if (!$hv['ok']) {
            $result['errors'] = $hv['errors'];
            $result['warnings'] = $hv['warnings'];
            return $result;   // nothing to import
        }
        $result['warnings'] = $hv['warnings'];

        // 2. Reference data
        $typeMap = $this->model->get_type_map();
        $existing = $this->model->get_existing_index();

        $rows   = $sheet['rows'];
        $total  = count($rows);
        if ($maxRows > 0 && $total > $maxRows) {
            $result['errors'][] = 'The file contains '.$total.' data rows; the maximum per import is '.$maxRows.'. '
                                . 'Split the file and import it in parts.';
            return $result;
        }
        if ($total === 0) {
            $result['errors'][] = 'No data rows found. The first row is treated as the header row, '
                                . 'so at least one asset row must follow it.';
            return $result;
        }

        // 3. Per-row processing
        $seenReg  = array();   // normalised reg => Excel row number (within file)
        $seenSer  = array();   // normalised serial => Excel row number
        $newTypes = array();   // typed name => id (created during this import)

        foreach ($rows as $r) {
            $excelRow = $r['row'];
            $st = array(
                'row' => $excelRow, 'machine_id' => '', 'action' => 'skipped',
                'errors' => array(), 'warnings' => array(), 'values' => array(),
            );
            $result['summary']['total_rows']++;

            $fields = array();
            foreach ($hv['map'] as $col => $field) {
                $fields[$field] = isset($r['cells'][$col]) ? $r['cells'][$col] : '';
            }

            // Empty row (nothing but blanks) — ignore silently
            $nonEmpty = array_filter($fields, function($v) { return trim((string)$v) !== ''; });
            if (empty($nonEmpty)) { $result['summary']['total_rows']--; continue; }

            $st['machine_id'] = trim((string)(isset($fields['reg']) ? $fields['reg'] : ''));

            // Duplicate state has to be known before validating: an "update row"
            // may legitimately leave columns out (e.g. only Hours + Notes), and a
            // blank Type must then keep the value already stored, not fail.
            $regKey    = $this->key($st['machine_id']);
            $inFileDup = isset($seenReg[$regKey]);
            $existingId = isset($existing['by_reg'][$regKey]) ? $existing['by_reg'][$regKey]['id'] : null;
            $isUpdate   = ($dupAction === 'update' && $existingId !== null);

            $v = $this->validate_row($fields, $typeMap, $newTypes, $createTypes, $st['errors'], $st['warnings'], $isUpdate);
            $st['values'] = $v;

            if (!empty($st['errors'])) {
                $st['action'] = 'failed';
                $result['summary']['failed']++;
                foreach ($st['warnings'] as $w) if ($w !== '') $result['summary']['warnings']++;
                $result['rows'][] = $st;
                continue;
            }

            $typeName = $v['type_name'];
            if (!$inFileDup) $seenReg[$regKey] = $excelRow;

            // 3c. Serial number duplicates (warning only)
            if ($v['serial_number'] !== '') {
                $serKey = $this->key($v['serial_number']);
                if (isset($seenSer[$serKey])) {
                    $st['warnings'][] = 'Serial number "'.$v['serial_number'].'" also appears on row '.$seenSer[$serKey].' of this file.';
                } else {
                    $seenSer[$serKey] = $excelRow;
                }
                if (isset($existing['by_serial'][$serKey])) {
                    $other = $existing['by_serial'][$serKey];
                    $otherIds = array();
                    foreach ($other as $o) if ($existingId === null || (int)$o['id'] !== $existingId) $otherIds[] = $o['registration_no'];
                    if (!empty($otherIds)) {
                        $st['warnings'][] = 'Serial number "'.$v['serial_number'].'" is already used by '
                                          . implode(', ', array_slice($otherIds, 0, 3))
                                          . (count($otherIds) > 3 ? ' and others' : '').'.';
                    }
                }
            }

            // Decide the action
            $isDuplicate = $inFileDup || $existingId !== null;
            if ($inFileDup && $seenReg[$regKey] !== $excelRow) {
                $dupReason = 'Duplicate of row '.$seenReg[$regKey].' in this file';
            } else {
                $dupReason = 'Machine ID already exists in the asset register';
            }

            if ($isDuplicate && $dupAction === 'fail') {
                $st['action'] = 'failed';
                $st['errors'][] = $dupReason.' (duplicate policy: reject duplicates).';
                $result['summary']['duplicates']++;
                $result['summary']['failed']++;
                $result['rows'][] = $st;
                continue;
            }
            if ($isDuplicate && $dupAction === 'skip') {
                $st['action'] = 'skipped';
                if ($inFileDup) {
                    $st['warnings'][] = $dupReason.' — skipped.';
                } elseif ($existingId !== null) {
                    $st['warnings'][] = $dupReason.' — skipped (choose "Update existing assets" to overwrite).';
                }
                $result['summary']['duplicates']++;
                $result['summary']['skipped']++;
                $result['rows'][] = $st;
                continue;
            }

            // 3d. Resolve the equipment type (may be created when allowed).
            //     On an update row a blank Type keeps the stored type untouched.
            $typeId = null;
            if ($typeName !== '') {
                $typeId = $this->resolve_type($typeName, $typeMap, $newTypes, $createTypes, $dryRun, $st['errors']);
                if ($typeId === null) {
                    $st['action'] = 'failed';
                    $result['summary']['failed']++;
                    $result['rows'][] = $st;
                    continue;
                }
            } elseif (!$isUpdate) {
                $st['action'] = 'failed';
                $st['errors'][] = 'Type is required — enter an existing equipment type (see the template Instructions sheet).';
                $result['summary']['failed']++;
                $result['rows'][] = $st;
                continue;
            }

            // 3e. Persist
            if ($dryRun) {
                $st['action'] = ($isDuplicate && $dupAction === 'update') ? 'updated' : 'inserted';
                if ($isDuplicate) $result['summary']['duplicates']++;
                if ($st['action'] === 'updated') $result['summary']['updated']++;
                else $result['summary']['inserted']++;
                $st['warnings'][] = 'Validation only — nothing was written to the database.';
                $st['values']['__preview'] = true;
                $result['rows'][] = $st;
                continue;
            }

            $payload = $this->to_db_row($v, $typeId);
            $ok = false; $dbMsg = null;
            if ($isDuplicate && $dupAction === 'update' && $existingId !== null) {
                $payload = $this->drop_blanks_for_update($payload);
                if (empty($payload)) {
                    $st['action'] = 'skipped';
                    $st['warnings'][] = 'Nothing to update — every cell in this row is blank.';
                    $result['summary']['skipped']++;
                    $result['rows'][] = $st;
                    continue;
                }
                $ok = $this->model->update_asset($existingId, $payload);
                $dbMsg = $this->model->last_db_error();
                $st['action'] = $ok ? 'updated' : 'failed';
                if ($ok) $result['summary']['updated']++;
            } elseif ($isDuplicate && $existingId === null) {
                // duplicate inside the file with update policy: update the row inserted earlier
                $ok = false;
                $st['action'] = 'failed';
                $st['errors'][] = 'This Machine ID appeared earlier in the file and was already imported. '
                                . 'Remove the repeated row or import it in a separate file.';
            } else {
                $ok = (bool)$this->model->insert_asset($payload);
                $dbMsg = $this->model->last_db_error();
                $st['action'] = $ok ? 'inserted' : 'failed';
                if ($ok) {
                    $result['summary']['inserted']++;
                    // Keep duplicate detection accurate inside the same import.
                    // id = null (not 0!): the row is in the file, not in the DB,
                    // so a repeated Machine ID is reported instead of being
                    // "updated" against a non-existent record.
                    $existing['by_reg'][$regKey] = array('id' => null, 'registration_no' => $v['registration_no']);
                }
            }

            if ($st['action'] === 'failed') {
                $result['summary']['failed']++;
                if (empty($st['errors'])) {
                    $st['errors'][] = 'Database error'.($dbMsg ? ': '.$dbMsg : ' — the row could not be saved.');
                }
            }
            foreach ($st['warnings'] as $w) $result['summary']['warnings']++;
            $result['rows'][] = $st;
        }

        if (!empty($newTypes)) {
            $result['warnings'][] = 'Equipment types created automatically: '.implode(', ', array_keys($newTypes)).'.';
        }
        $result['ok'] = ($result['summary']['failed'] === 0);
        return $result;
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       ROW VALIDATION
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * Validate one row of mapped values.
     * @param array $fields    field => raw value (from the sheet)
     * @param array $typeMap   normalised type name => id
     * @param array $newTypes  types already queued for creation in this import
     * @param bool  $createTypes
     * @param array $errors    (by reference)
     * @param array $warnings  (by reference)
     * @return array canonical values
     */
    public function validate_row($fields, $typeMap, $newTypes, $createTypes, &$errors, &$warnings, $isUpdate = false) {
        $get = function($k) use ($fields) { return isset($fields[$k]) ? trim((string)$fields[$k]) : ''; };
        $v = array(
            'registration_no' => '', 'type_name' => '', 'brand' => '', 'model' => '',
            'year' => null, 'serial_number' => '', 'engine_model' => '',
            // null/'' means "not present in the file": on update these fields are
            // left untouched instead of being overwritten with a default.
            'current_hours' => null, 'current_km' => null, 'location' => '',
            'status' => '', 'purchase_date' => null, 'warranty_expiry' => null,
            'notes' => '',
        );

        /* Machine ID — required, unique key of the row */
        $reg = $get('reg');
        if ($reg === '') {
            $errors[] = 'Machine ID is required (this column cannot be empty).';
        } else {
            $reg = preg_replace('/\s+/', ' ', $reg);
            $v['registration_no'] = strtoupper((string)($reg));
            if (strlen((string)($v['registration_no'])) > $this->lengths['registration_no']) {
                $errors[] = 'Machine ID "'.$v['registration_no'].'" is longer than '
                          . $this->lengths['registration_no'].' characters.';
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $v['registration_no'])) {
                $errors[] = 'Machine ID contains control characters that are not allowed.';
            }
        }

        /* Type — required on a new asset, optional on an update (keeps the stored type) */
        $type = $get('type');
        if ($type === '') {
            if (!$isUpdate) {
                $errors[] = 'Type is required — enter an existing equipment type (see the template Instructions sheet).';
            }
        } else {
            $v['type_name'] = $type;
        }

        /* Simple text fields with length caps */
        foreach (array('brand', 'model', 'serial_number', 'engine_model', 'location') as $f) {
            $val = $get($f === 'serial_number' ? 'serial' : ($f === 'engine_model' ? 'engine' : $f));
            if ($val !== '') {
                if (strlen((string)($val)) > $this->lengths[$f]) {
                    $errors[] = ucwords(str_replace('_', ' ', $f)).' "'.substr($val, 0, 40).'…" is longer than '
                              . $this->lengths[$f].' characters.';
                    $val = substr($val, 0, $this->lengths[$f]);
                }
                $v[$f] = $val;
            }
        }

        $notes = $get('notes');
        if ($notes !== '') {
            if (strlen((string)($notes)) > self::MAX_NOTES) {
                $warnings[] = 'Notes were truncated to '.self::MAX_NOTES.' characters.';
                $notes = substr($notes, 0, self::MAX_NOTES);
            }
            $v['notes'] = $notes;
        }

        /* Year */
        $year = $get('year');
        if ($year !== '') {
            $y = $this->parse_number($year);
            if ($y === null || (int)$y != $y) {
                $errors[] = 'Year "'.$year.'" is not a valid number (example: 2019).';
            } elseif ($y < 1950 || $y > (int)date('Y') + 1) {
                $errors[] = 'Year "'.$year.'" is outside the allowed range 1950–'.((int)date('Y') + 1).'.';
            } else {
                $v['year'] = (int)$y;
            }
        }

        /* Hours / KM */
        foreach (array('hours' => 'current_hours', 'km' => 'current_km') as $key => $col) {
            $raw = $get($key);
            if ($raw === '') continue;
            $n = $this->parse_number($raw);
            if ($n === null) {
                $errors[] = ($key === 'hours' ? 'Current Hours' : 'Current KM').' "'.$raw.'" is not a valid number.';
            } elseif ($n < 0) {
                $errors[] = ($key === 'hours' ? 'Current Hours' : 'Current KM').' cannot be negative.';
            } else {
                $v[$col] = round($n, 2);
            }
        }

        /* Status */
        $status = $get('status');
        if ($status !== '') {
            $match = null;
            foreach (Asset_model::STATUSES as $allowed) {
                if (strcasecmp($allowed, $status) === 0) { $match = $allowed; break; }
            }
            if ($match === null) {
                $errors[] = 'Status "'.$status.'" is not valid. Allowed values: '.implode(', ', Asset_model::STATUSES).'.';
            } else {
                $v['status'] = $match;
            }
        }

        /* Dates */
        foreach (array('purchase' => 'purchase_date', 'warranty' => 'warranty_expiry') as $key => $col) {
            $raw = $get($key);
            if ($raw === '') continue;
            $d = $this->parse_date($raw);
            if ($d === null) {
                $errors[] = ($key === 'purchase' ? 'Purchase Date' : 'Warranty Expiry').' "'.$raw
                          . '" is not a valid date. Use dd/mm/yyyy (e.g. 15/03/2020) or a real Excel date.';
            } else {
                $v[$col] = $d;
            }
        }

        return $v;
    }

    /**
     * Resolve (or create) the equipment type.
     * @return int|null
     */
    private function resolve_type($name, &$typeMap, &$newTypes, $createTypes, $dryRun, &$errors) {
        $key = $this->key($name);
        if (isset($typeMap[$key])) return (int)$typeMap[$key];
        if (isset($newTypes[$key])) return (int)$newTypes[$key];

        if (!$createTypes) {
            $display = method_exists($this->model, 'get_type_names') ? $this->model->get_type_names() : array();
            $hint = '';
            $close = $this->closest($key, array_keys($typeMap));
            if ($close !== null) {
                $hint = ' Did you mean "'.(isset($display[$close]) ? $display[$close] : $close).'"?';
            }
            $errors[] = 'Equipment type "'.$name.'" does not exist.'.$hint
                      . ' Add it under Equipment → Types first, or tick "create missing types".';
            return null;
        }
        if ($dryRun) {
            // reserve the name so following rows validate against it
            $newTypes[$key] = 0;
            return 0;
        }
        $id = $this->model->create_type($name);
        if (!$id) {
            $errors[] = 'Equipment type "'.$name.'" could not be created.';
            return null;
        }
        $newTypes[$key] = (int)$id;
        $typeMap[$key]   = (int)$id;
        return (int)$id;
    }

    /**
     * Build the DB payload. Only fields actually present in the file are
     * included, so an update can never wipe a value that was not supplied.
     */
    private function to_db_row($v, $typeId) {
        $row = array('registration_no' => $v['registration_no']);
        if ($typeId) $row['equipment_type_id'] = (int)$typeId;
        foreach (array('brand','model','serial_number','engine_model','location','notes') as $f) {
            if ($v[$f] !== '' && $v[$f] !== null) $row[$f] = $v[$f];
        }
        if ($v['year'] !== null) $row['year'] = $v['year'];
        if (!empty($v['purchase_date']))   $row['purchase_date'] = $v['purchase_date'];
        if (!empty($v['warranty_expiry'])) $row['warranty_expiry'] = $v['warranty_expiry'];
        if ($v['status'] !== '')           $row['status'] = $v['status'];
        if ($v['current_hours'] !== null)  $row['current_hours'] = $v['current_hours'];
        if ($v['current_km'] !== null)     $row['current_km'] = $v['current_km'];
        return $row;
    }

    /** Safety net: never write an empty value over an existing one on update. */
    private function drop_blanks_for_update($payload) {
        $out = array();
        foreach ($payload as $k => $val) {
            if ($k === 'registration_no') continue;      // identical to the matched row
            if ($val === '' || $val === null) continue;
            $out[$k] = $val;
        }
        return $out;
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       VALUE PARSERS (public so they can be unit tested)
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /**
     * "1 250", "1,250.5", "8420 h", "12 000 km", "1.234,5", "8420,5" → float | null
     *
     * Separator rules (documented in the template):
     *   • spaces / non-breaking spaces are always thousand separators
     *   • with both "." and "," the LAST one is the decimal separator
     *   • "1,250" / "1.250" (exactly one separator followed by 3 digits) is
     *     treated as a thousands separator, "8420,5" as a decimal
     */
    public function parse_number($raw) {
        $s = trim((string)$raw);
        if ($s === '') return null;
        $s = str_replace(array("\xC2\xA0", ' '), '', $s);          // non-breaking + normal spaces
        // drop a trailing unit ("8420 h", "12.5km", "900 miles") — never mid-string
        $s = preg_replace('/(h|hr|hrs|hour|hours|km|kms|kilometer|kilometers|kilometre|kilometres|mile|miles)\.?$/i', '', $s);
        $s = trim((string)($s));
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
            // "1,250,000" or "1,250" → thousands; "8420,5" → decimal
            $isThousands = (bool)preg_match('/^-?\d{1,3}(,\d{3})+$/', $s);
            $s = $isThousands ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($hasDot) {
            // "1.250.000" or "1.250" → thousands (European); "12.5" → decimal
            $isThousands = (bool)preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s);
            if ($isThousands) $s = str_replace('.', '', $s);
        }

        if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) return null;
        return (float)$s;
    }

    /**
     * Accept dd/mm/yyyy, d/m/yy, dd-mm-yyyy, dd.mm.yyyy, yyyy-mm-dd, yyyy/mm/dd,
     * "15 Mar 2020", "15 March 2020", ISO datetimes and Excel serial numbers
     * (already converted by the reader, but handled here as well).
     */
    public function parse_date($raw) {
        $s = trim((string)$raw);
        if ($s === '') return null;
        if (preg_match('/^(n\/?a|none|null|-|tbd|unknown)$/i', $s)) return null;

        // Excel serial number (e.g. 43734) — only in a plausible date range
        if (preg_match('/^\d{5}(\.\d+)?$/', $s)) {
            $serial = (float)$s;
            if ($serial > 20000 && $serial < 80000) {
                $unix = mktime(0, 0, 0, 12, 30, 1899) + (int)floor($serial) * 86400;
                return date('Y-m-d', $unix);
            }
        }

        // ISO first (unambiguous)
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $s, $m)) {
            return $this->make_date((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        // dd/mm/yyyy family (day first — matches the system display format)
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $s, $m)) {
            $y = (int)$m[3];
            if ($y < 100) $y += ($y < 70) ? 2000 : 1900;
            return $this->make_date($y, (int)$m[2], (int)$m[1]);
        }
        // 15 Mar 2020 / 15 March 2020 / Mar 15 2020
        if (preg_match('/^(\d{1,2})[\s\-]+([A-Za-z]{3,9})[\s\-,]+(\d{2,4})$/', $s, $m)) {
            $mon = $this->month_number($m[2]);
            $y = (int)$m[3]; if ($y < 100) $y += ($y < 70) ? 2000 : 1900;
            if ($mon) return $this->make_date($y, $mon, (int)$m[1]);
        }
        if (preg_match('/^([A-Za-z]{3,9})[\s\-]+(\d{1,2})[\s\-,]+(\d{2,4})$/', $s, $m)) {
            $mon = $this->month_number($m[1]);
            $y = (int)$m[3]; if ($y < 100) $y += ($y < 70) ? 2000 : 1900;
            if ($mon) return $this->make_date($y, $mon, (int)$m[2]);
        }
        // Anything else: let strtotime try (English month names, relative dates are refused)
        $ts = strtotime($s);
        if ($ts !== false && !preg_match('/\b(now|today|tomorrow|yesterday|next|last)\b/i', $s)) {
            $y = (int)date('Y', $ts);
            if ($y >= 1900 && $y <= 2100) return date('Y-m-d', $ts);
        }
        return null;
    }

    private function make_date($y, $m, $d) {
        if ($y < 1900 || $y > 2100) return null;
        if ($m < 1 || $m > 12 || $d < 1 || $d > 31) return null;
        if (!checkdate($m, $d, $y)) return null;
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    private function month_number($name) {
        $n = strtolower((string)(substr($name, 0, 3)));
        $map = array('jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,
                     'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12);
        return isset($map[$n]) ? $map[$n] : null;
    }

    /** Comparison key for duplicate detection */
    public function key($s) {
        $s = preg_replace('/\s+/', ' ', trim((string)$s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower((string)($s));
    }

    /** Closest known value (simple edit-distance hint for error messages) */
    private function closest($needle, $haystack) {
        $best = null; $bestD = 4;
        foreach ($haystack as $h) {
            $d = levenshtein(substr($needle, 0, 40), substr($h, 0, 40));
            if ($d < $bestD) { $bestD = $d; $best = $h; }
        }
        return $bestD <= 2 ? $best : null;
    }
}
