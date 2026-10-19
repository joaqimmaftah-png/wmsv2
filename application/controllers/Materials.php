<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Materials — the Inventory module (dashboard, list, manual entry, stock
 * movements, Excel export / template / import).
 *
 * Three ways to get inventory data in or out, all sharing the same rules:
 *
 *   Add Manually       create() / edit()   — validated with Inventory_importer
 *   Import from Excel  import_excel()      — Inventory_importer::import()
 *   Export to Excel    export_xlsx()       — Inventory_excel::export_inventory()
 *   Download Template  template_xlsx()     — Inventory_excel::build_template()
 *
 * Stock is only ever changed through Materials_model::add_stock_movement() /
 * set_opening_stock() / log_opening_stock(), so every balance change is
 * recorded in `material_transactions`.
 */
class Materials extends MY_Controller {

    /** Import results and generated workbooks live here (writable, not web-served) */
    const IMPORT_TMP = 'uploads/tmp/';

    /** Hard row limit per import (mirrors Inventory_importer::MAX_ROWS) */
    const IMPORT_MAX_ROWS = 5000;

    /* ── Quick diagnostic — visit /materials/ping to test ── */
    public function ping() {
        $this->load->model('Materials_model');
        $ok   = $this->db->table_exists('materials');
        $count= $ok ? $this->db->count_all('materials') : 0;
        echo '<pre>';
        echo "Controller: OK\n";
        echo "DB connected: OK\n";
        echo "materials table: ".($ok?'EXISTS':'MISSING')."\n";
        echo "Row count: {$count}\n";
        echo "Has material_transactions: ".($this->db->table_exists('material_transactions')?'YES':'NO')."\n";
        echo "Has inventory_import_logs: ".($this->db->table_exists('inventory_import_logs')?'YES':'NO (optional)')."\n";
        echo "PHP: ".PHP_VERSION."\n";
        echo '</pre>';
        exit;
    }

    public function __construct() {
        parent::__construct();
        $this->load->model('Materials_model');
    }

    /* ── DASHBOARD ─────────────────────────────────────── */
    public function index() {
        $all = $this->Materials_model->get_all();

        $total_items   = count($all);
        $out_of_stock  = 0;
        $low_stock     = 0;
        $total_received= 0;
        $total_issued  = 0;
        $categories    = array();
        $by_category   = array();

        foreach ($all as $m) {
            $final = $m->initial_stock + $m->qty_received - $m->qty_sortie;
            $total_received += $m->qty_received;
            $total_issued   += $m->qty_sortie;
            if ($final <= 0)  $out_of_stock++;
            elseif ($final <= 5) $low_stock++;
            $cat = $m->category;
            if (!isset($by_category[$cat])) {
                $by_category[$cat] = array('count'=>0,'stock'=>0,'issued'=>0);
            }
            $by_category[$cat]['count']++;
            $by_category[$cat]['stock']  += $final;
            $by_category[$cat]['issued'] += $m->qty_sortie;
        }

        // Recent movements (last updated items)
        $recent = $this->Materials_model->get_recent_movements(10);

        // Low stock items
        $low_items = $this->Materials_model->get_low_stock(10);

        $data = array(
            'all_materials' => $all,
            'total_items'   => $total_items,
            'out_of_stock'  => $out_of_stock,
            'low_stock'     => $low_stock,
            'total_received'=> $total_received,
            'total_issued'  => $total_issued,
            'by_category'   => $by_category,
            'recent'        => $recent,
            'low_items'     => $low_items,
            'page_title'    => 'Inventory Dashboard',
            'role_id'    => $this->role_id,
        );
        $this->render('materials/dashboard', $data);
    }

    /* ── INVENTORY LIST ────────────────────────────────── */
    public function inventory() {
        $filter = array(
            'category' => $this->input->get('category'),
            'location' => $this->input->get('location'),
            'search'   => $this->input->get('search'),
            'stock'    => $this->input->get('stock'),
        );
        $data = array(
            'materials'  => $this->Materials_model->get_all($filter),
            'categories' => $this->Materials_model->get_categories(),
            'locations'  => $this->Materials_model->get_locations(),
            'filter'     => $filter,
            'import_result'  => $this->_load_import_summary(),
            'import_history' => $this->Materials_model->get_import_history(3),
            'can_import'     => $this->can_write(),
            'page_title' => 'Inventory',
            'role_id'    => $this->role_id,
        );
        $this->render('materials/inventory', $data);
    }

    /* ── CREATE ITEM (manual entry) ────────────────────── */
    public function create() {
        if (!$this->can_write()) redirect('materials/inventory');
        $this->load->library('inventory_importer', array('model' => $this->Materials_model));

        if ($this->input->post()) {
            $check = $this->inventory_importer->validate_manual($this->input->post(), array('is_update' => false));

            if ($check['ok']) {
                $v = $check['values'];
                $data = array(
                    'item_code'        => $v['item_code'],
                    'category'         => $v['category'] !== '' ? $v['category'] : 'CONSUMABLE',
                    'description'      => $v['description'],
                    'part_number_size' => $v['part_number_size'] !== '' ? $v['part_number_size'] : NULL,
                    'unit'             => $v['unit'] !== '' ? $v['unit'] : 'pcs',
                    'initial_stock'    => $v['initial_stock'] === null ? 0 : $v['initial_stock'],
                    'application'      => $v['application'] !== '' ? $v['application'] : NULL,
                    'location'         => $v['location'] !== '' ? $v['location'] : NULL,
                );
                $data = array_merge($data, $this->_extended_values($v));
                $id = $this->Materials_model->insert_item($data);
                if ($id) {
                    // Opening stock is part of the balance, not a "received" quantity:
                    // it is logged as a movement so the history stays traceable.
                    if ((int)$data['initial_stock'] > 0) {
                        $this->Materials_model->log_opening_stock($id, (int)$data['initial_stock'],
                            $this->user_id, 'Opening stock — manual entry');
                    }
                    $this->session->set_flashdata('success', 'Item '.$data['item_code'].' added to inventory'
                        . ((int)$data['initial_stock'] > 0
                            ? ' with an opening stock of '.$data['initial_stock'].' '.$data['unit'].'.'
                            : '.'));
                    redirect('materials/inventory');
                }
                $err = $this->Materials_model->last_db_error();
                $check['errors'][] = 'The item could not be saved'.($err ? ': '.$err : '.').' '
                                   . 'A duplicate item code or a database problem can cause this.';
            }

            $this->session->set_flashdata('error', implode(' ', $check['errors']));
            return $this->_render_form($this->_form_item($this->input->post(), null), $check);
        }

        return $this->_render_form(null, null);
    }

    /* ── EDIT ITEM ─────────────────────────────────────── */
    public function edit($id) {
        if (!$this->can_write()) redirect('materials/inventory');
        $item = $this->Materials_model->get_by_id($id);
        if (!$item) show_404();
        $this->load->library('inventory_importer', array('model' => $this->Materials_model));

        if ($this->input->post()) {
            $check = $this->inventory_importer->validate_manual($this->input->post(),
                array('is_update' => true, 'item_id' => $id));

            // The edit form also exposes the three stock columns.  They are
            // whole units (int columns); anything else is rejected instead of
            // being silently rounded by the database.
            $qtyErrors = array();
            $newInit = $this->_whole_qty($this->input->post('initial_stock'), 'Initial stock', $qtyErrors, (int)$item->initial_stock);
            $newRecv = $this->_whole_qty($this->input->post('qty_received'),  'Total received', $qtyErrors, (int)$item->qty_received);
            $newIss  = $this->_whole_qty($this->input->post('qty_sortie'),    'Total issued',   $qtyErrors, (int)$item->qty_sortie);
            $check['errors'] = array_merge($check['errors'], $qtyErrors);

            if (empty($check['errors'])) {
                $v = $check['values'];
                $data = array(
                    'item_code'        => $v['item_code'],
                    'category'         => $v['category'] !== '' ? $v['category'] : $item->category,
                    'description'      => $v['description'] !== '' ? $v['description'] : $item->description,
                    'part_number_size' => $v['part_number_size'] !== '' ? $v['part_number_size'] : NULL,
                    'unit'             => $v['unit'] !== '' ? $v['unit'] : $item->unit,
                    'application'      => $v['application'] !== '' ? $v['application'] : NULL,
                    'location'         => $v['location'] !== '' ? $v['location'] : NULL,
                    'initial_stock'    => $newInit,
                    'qty_received'     => $newRecv,
                    'qty_sortie'       => $newIss,
                );
                $data = array_merge($data, $this->_extended_values($v));
                $ok = $this->Materials_model->update_item($id, $data, true);
                if ($ok) {
                    $this->_log_stock_changes($item, $id, $newInit, $newRecv, $newIss);
                    $final = $newInit + $newRecv - $newIss;
                    $note  = $final < 0 ? ' Note: the resulting stock is negative ('.$final.').' : '';
                    $this->session->set_flashdata('success', 'Item '.$data['item_code'].' updated.'.$note);
                    redirect('materials/inventory');
                }
                $err = $this->Materials_model->last_db_error();
                $check['errors'][] = 'The item could not be updated'.($err ? ': '.$err : '.').'';
            }

            $this->session->set_flashdata('error', implode(' ', $check['errors']));
            return $this->_render_form($this->_form_item($this->input->post(), $item), $check, $item);
        }

        return $this->_render_form($item, null, $item);
    }

    /* ── RECEIVE STOCK ─────────────────────────────────── */
    public function receive($id) {
        if (!$this->can_write()) redirect('materials/inventory');
        $item = $this->Materials_model->get_by_id($id);
        if (!$item) show_404();

        if ($this->input->post()) {
            $errors = array();
            $qty    = $this->_whole_qty($this->input->post('qty'), 'Quantity', $errors, null, 1);
            $notes  = $this->input->post('notes', TRUE);
            if (empty($errors)) {
                $ctx  = $this->_movement_context(false);
                $move = $this->Materials_model->add_stock_movement($id, 'RECEIVE', $qty,
                    $notes !== '' ? $notes : 'Manual receive', $this->user_id, $ctx);
                if ($move !== false) {
                    // Pending Spare Parts Tracking: check whether a pending work
                    // order is waiting for this item and alert the maintenance team.
                    $alert_note = '';
                    $this->load->model('Spareparts_model');
                    $alerts = $this->Spareparts_model->process_receipt(
                        $id, $qty, $move['before'], $move['after'], $this->user_id, 'Manual receive');
                    if (!empty($alerts)) {
                        $refs = array();
                        foreach ($alerts as $al) $refs[] = $al->wo_number;
                        $alert_note = ' ⚡ Required by pending work order'.(count($refs) > 1 ? 's' : '')
                            .' '.implode(', ', array_unique($refs)).' — availability alert'.(count($refs) > 1 ? 's' : '').' sent.';
                    }
                    $this->session->set_flashdata('success', 'Received '.$qty.' '.$item->unit.' of '.$item->item_code
                        .'. New stock: '.$move['after'].' '.$item->unit.'.'.$alert_note);
                    redirect('materials/inventory');
                }
                $errors[] = 'The stock movement could not be saved'
                          .($this->Materials_model->last_db_error() ? ': '.$this->Materials_model->last_db_error() : '.').'';
            }
            $this->session->set_flashdata('error', implode(' ', $errors));
            redirect('materials/receive/'.$id);
        }

        $data = array(
            'item'            => $item,
            'register_fields' => $this->Materials_model->has_movement_fields(),
            'suppliers'       => $this->_supplier_options(),
            'page_title'      => 'Receive Stock — ' . $item->item_code,
        );
        $this->render('materials/transaction', $data);
    }

    /* ── ISSUE STOCK ───────────────────────────────────── */
    public function issue($id) {
        if (!$this->can_write()) redirect('materials/inventory');
        $item = $this->Materials_model->get_by_id($id);
        if (!$item) show_404();

        if ($this->input->post()) {
            $errors = array();
            $qty    = $this->_whole_qty($this->input->post('qty'), 'Quantity', $errors, null, 1);
            $notes  = $this->input->post('notes', TRUE);
            $final  = (int)$item->initial_stock + (int)$item->qty_received - (int)$item->qty_sortie;

            if (empty($errors)) {
                if ($qty > $final) {
                    $errors[] = 'Invalid quantity: only '.$final.' '.$item->unit.' of '.$item->item_code.' are available.';
                } else {
                    $ctx  = $this->_movement_context(true);
                    $move = $this->Materials_model->add_stock_movement($id, 'ISSUE', $qty,
                        $notes !== '' ? $notes : 'Manual issue', $this->user_id, $ctx);
                    if ($move !== false) {
                        $this->session->set_flashdata('success', 'Issued '.$qty.' '.$item->unit.' of '.$item->item_code
                            .'. Remaining stock: '.$move['after'].' '.$item->unit.'.');
                        redirect('materials/inventory');
                    }
                    $errors[] = 'The stock movement could not be saved'
                              .($this->Materials_model->last_db_error() ? ': '.$this->Materials_model->last_db_error() : '.').'';
                }
            }
            $this->session->set_flashdata('error', implode(' ', $errors));
            redirect('materials/issue/'.$id);
        }

        $data = array(
            'item'            => $item,
            'issue_mode'      => true,
            'register_fields' => $this->Materials_model->has_movement_fields(),
            'equipment'       => $this->_equipment_options(),
            'page_title'      => 'Issue Stock — ' . $item->item_code,
        );
        $this->render('materials/transaction', $data);
    }

    /* ── TRANSACTIONS HISTORY ──────────────────────────── */
    public function transactions() {
        $filter = array(
            'type'       => $this->input->get('type'),
            'item_code'  => $this->input->get('item_code'),
            'date_from'  => $this->input->get('date_from'),
            'date_to'    => $this->input->get('date_to'),
        );
        $data = array(
            'transactions' => $this->Materials_model->get_transactions($filter),
            'filter'       => $filter,
            'page_title'   => 'Transaction History',
            'role_id'   => $this->role_id,
        );
        $this->render('materials/transactions', $data);
    }

    /* ── REPORT — legacy alias of the Excel export ─────── */
    public function report() {
        return $this->export_xlsx();
    }

    /* ── EXPORT TO EXCEL ───────────────────────────────── */
    public function export_xlsx() {
        $filter = array(
            'category' => $this->input->get('category'),
            'location' => $this->input->get('location'),
            'search'   => $this->input->get('search'),
        );
        $rows = $this->Materials_model->get_all($filter);

        // Safety net: never hand out a silently empty export because of a
        // database problem (get_all() returns [] when the query fails).
        $total = $this->Materials_model->count_items();
        if (empty($rows) && $total > 0) {
            log_message('error', 'Inventory export: get_all() returned 0 rows but count_items() = '.$total);
            show_error('The inventory export could not read the items ('.$total.' records exist in the database). '
                     . 'Please try again — if the problem continues, check the server log.', 500);
            return;
        }

        $this->load->library('inventory_excel');
        $user      = $this->user_data;
        $fname     = 'Inventory_'.date('Ymd_His').'.xlsx';
        $tmp       = $this->_tmp_path($fname);
        $suppliers = $this->Materials_model->get_supplier_map();

        $this->inventory_excel->export_inventory($rows, $suppliers, $tmp, array(
            'title'        => 'INVENTORY STOCK LIST',
            'generated_by' => wms_user_full_name($user),
            'filter_note'  => $this->_filter_note($filter),
        ));
        $this->_send_file($tmp, $fname);
    }

    /* ── DOWNLOAD IMPORT TEMPLATE ──────────────────────── */
    public function template_xlsx() {
        $this->load->library('inventory_excel');
        $fname = 'Inventory_Import_Template.xlsx';
        $tmp   = $this->_tmp_path($fname);
        $this->inventory_excel->build_template($tmp, array(
            'categories' => $this->Materials_model->get_category_lookup(),
            'units'      => $this->Materials_model->get_unit_lookup(),
        ));
        $this->_send_file($tmp, $fname);
    }

    /* ── IMPORT FROM EXCEL ─────────────────────────────── */
    public function import_excel() {
        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'Only supervisor, technician or store users can import inventory data.');
            redirect('materials/inventory');
        }
        if ($this->input->method() !== 'post') redirect('materials/inventory');

        $opts = array(
            'duplicate_action'        => in_array($this->input->post('duplicate_action'), array('skip','update','fail'), true)
                                         ? $this->input->post('duplicate_action') : 'skip',
            'stock_action'            => in_array($this->input->post('stock_action'), array('ignore','receive','set'), true)
                                         ? $this->input->post('stock_action') : 'ignore',
            'allow_new_categories'    => $this->input->post('allow_new_categories') ? 1 : 0,
            'allow_new_units'         => $this->input->post('allow_new_units') ? 1 : 0,
            'allow_duplicate_records' => $this->input->post('allow_duplicate_records') ? 1 : 0,
            'dry_run'                 => $this->input->post('dry_run') ? 1 : 0,
            'user_id'                 => $this->user_id,
            'max_rows'                => self::IMPORT_MAX_ROWS,
        );

        /* ── 1. Upload ─────────────────────────────────────── */
        $upload_path = FCPATH.self::IMPORT_TMP;
        if (!is_dir($upload_path)) @mkdir($upload_path, 0755, true);

        $config = array(
            'upload_path'   => $upload_path,
            'allowed_types' => 'xlsx',
            'max_size'      => 8192,
            'file_name'     => 'inventory_import_'.date('YmdHis').'_'.mt_rand(1000, 9999),
            'overwrite'     => false,
        );
        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('inventory_file')) {
            $err = $this->upload->display_errors('', '');
            $this->session->set_flashdata('error', 'Import failed — '.$this->_clean_upload_error($err).' '
                .'Download the template to make sure the file is in the right format.');
            redirect('materials/inventory');
        }
        $file = $this->upload->data();
        $path = $file['full_path'];

        try {
            /* ── 2. Parse ──────────────────────────────────── */
            $this->load->library('xlsx_reader');
            $this->load->library('inventory_importer', array('model' => $this->Materials_model));

            $sheet = $this->xlsx_reader->read($path, array('Import Data', 'Failed Rows', 'Inventory', 'Materials'), 0, array(
                'header_hints' => array_keys(Inventory_importer::expected_columns()),
            ));
            if ($sheet === false) {
                throw new RuntimeException($this->xlsx_reader->last_error());
            }

            /* ── 3. Validate + import (all writes in one transaction) ── */
            $result = $this->inventory_importer->import($sheet, $opts + array('file_name' => $file['client_name']));

            /* ── 4. Park the full report, keep the summary in the session ── */
            $token = $this->_new_import_token();
            $result['report_token'] = $token;
            $this->_park_import_summary($result, $token);
            $this->Materials_model->log_import(array(
                'user_id'          => $this->user_id,
                'file_name'        => $file['client_name'],
                'mode'             => $result['mode'],
                'duplicate_action' => $result['duplicate_action'],
                'stock_action'     => $result['stock_action'],
                'total_rows'       => $result['summary']['total_rows'],
                'inserted'         => $result['summary']['inserted'],
                'updated'          => $result['summary']['updated'],
                'skipped'          => $result['summary']['skipped'],
                'failed'           => $result['summary']['failed'],
                'duplicates'       => $result['summary']['duplicates'],
            ));

            $s = $result['summary'];
            if (!empty($result['errors'])) {
                $this->session->set_flashdata('error', 'Import not started — '.implode(' ', $result['errors']));
            } elseif (!empty($result['rolled_back'])) {
                $this->session->set_flashdata('error', 'Import rolled back — '
                    .(isset($result['rollback_reason']) ? $result['rollback_reason'] : 'the database reported an error.')
                    .' Nothing was written; download the report for the details.');
            } elseif ($result['mode'] === 'validate') {
                $this->session->set_flashdata('success', sprintf(
                    'Validation finished: %d row(s) checked — %d new, %d to update, %d skipped (duplicates), '
                    .'%d with errors (%d missing required values, %d invalid values). Nothing was saved.',
                    $s['total_rows'], $s['inserted'], $s['updated'], $s['skipped'],
                    $s['failed'], $s['missing_required'], $s['invalid_values']));
            } else {
                $this->session->set_flashdata('success', sprintf(
                    'Import finished: %d new item(s), %d updated, %d skipped (duplicates), %d failed '
                    .'out of %d row(s). Duplicates detected: %d.',
                    $s['inserted'], $s['updated'], $s['skipped'], $s['failed'], $s['total_rows'], $s['duplicates']));
            }
        } catch (Exception $e) {
            $this->session->set_flashdata('error', 'Import failed — '.$e->getMessage());
        }

        @unlink($path);
        redirect('materials/inventory');
    }

    /* ── IMPORT ERROR REPORT (Excel download) ──────────── */
    public function import_report($token = '') {
        $result = $this->_read_import_report($token);
        if ($result === null) {
            $this->session->set_flashdata('error', 'That import report is no longer available — run the import again.');
            redirect('materials/inventory');
        }
        $this->load->library('inventory_excel');
        $fname = 'Inventory_Import_Report_'.date('Ymd_His').'.xlsx';
        $tmp   = $this->_tmp_path($fname);
        $this->inventory_excel->import_report($result, $tmp);
        $this->_send_file($tmp, $fname);
    }

    /* ── DELETE ────────────────────────────────────────── */
    public function delete($id) {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) redirect('materials/inventory');
        $this->Materials_model->delete($id);
        $this->session->set_flashdata('success', 'Item deleted.');
        redirect('materials/inventory');
    }

    /* ── AJAX item lookup (for WO form) ───────────────── */
    public function api_lookup() {
        $code = $this->input->get('code', TRUE);
        if (!$code) { $this->json(array('found'=>false)); return; }
        $item = $this->db->where('item_code', strtoupper($code))->get('materials')->row();
        if ($item) {
            $stock = (int)$item->initial_stock + (int)$item->qty_received - (int)$item->qty_sortie;
            $this->json(array(
                'found'       => true,
                'description' => $item->description,
                'part_number' => $item->part_number_size ?? '',
                'unit'        => $item->unit,
                'stock'       => $stock,
                'category'    => $item->category,
            ));
        } else {
            $this->json(array('found'=>false,'message'=>'Item not found in inventory.'));
        }
    }

    /* ─────────────────────────────────────────────────────
       PRIVATE helpers
    ───────────────────────────────────────────────────── */

    /** Render the item form (with the shared category/unit options). */
    private function _render_form($item, $check = null, $base = null) {
        $cats  = $this->Materials_model->get_category_lookup();
        $units = $this->Materials_model->get_unit_lookup();
        $isEdit = $item && !empty($item->id);
        $data = array(
            'item'             => $item ? $item : (object)array(),
            'base_item'        => $base,
            'categories'       => $this->Materials_model->get_categories(),
            'locations'        => $this->Materials_model->get_locations(),
            'category_options' => $cats['list'],
            'unit_options'     => $units['list'],
            'has_extended'     => $this->Materials_model->has_extended_fields(),
            'form_errors'      => ($check && !$check['ok']) ? $check['errors'] : array(),
            'form_warnings'    => ($check && !empty($check['warnings'])) ? $check['warnings'] : array(),
            'page_title'       => $isEdit ? 'Edit: '.$item->item_code : 'Add Inventory Item',
            'role_id'          => $this->role_id,
        );
        $this->render('materials/item_form', $data);
    }

    /**
     * Register fields posted with a stock movement.
     *
     * These are the columns the manual Sortie / Receipts sheets carried:
     * the business date, the requisition / MRN number, what an issue is
     * charged to, who collected it, and the supplier / price of a receipt.
     * They are silently ignored when the register columns are missing.
     */
    private function _movement_context($isIssue) {
        if (!$this->Materials_model->has_movement_fields()) return array();

        $date = $this->input->post('txn_date', TRUE);
        $d    = $date ? DateTime::createFromFormat('Y-m-d', $date) : null;
        $ctx  = array(
            'txn_date'     => ($d && $d->format('Y-m-d') === $date) ? $date : date('Y-m-d'),
            'reference_no' => $this->input->post('reference_no', TRUE),
            'source'       => 'Manual',
        );

        if ($isIssue) {
            $eqId  = (int)$this->input->post('equipment_id');
            $label = trim((string)$this->input->post('allocation_label', TRUE));
            if ($eqId > 0) {
                $row = $this->db->select('registration_no')->from('equipment')
                                ->where('id', $eqId)->get()->row();
                $ctx['equipment_id']     = $eqId;
                $ctx['allocation_type']  = 'Equipment';
                $ctx['allocation_label'] = $row ? $row->registration_no : $label;
            } elseif ($label !== '') {
                $ctx['allocation_type']  = 'Department';
                $ctx['allocation_label'] = $label;
            } else {
                $ctx['allocation_type']  = 'Other';
            }
            $ctx['person'] = $this->input->post('person', TRUE);
        } else {
            $sup = trim((string)$this->input->post('supplier_name', TRUE));
            if ($sup !== '') {
                $ctx['supplier_name'] = $sup;
                $row = $this->db->select('id')->from('suppliers')
                                ->where('company_name', $sup)->get()->row();
                if ($row) $ctx['supplier_id'] = (int)$row->id;
            }
            $appr = $this->input->post('qty_approved');
            if ($appr !== null && $appr !== '') $ctx['qty_approved'] = (float)$appr;
            $price = $this->input->post('unit_price');
            if ($price !== null && $price !== '') {
                $ctx['unit_price'] = (float)$price;
                $ctx['currency']   = 'UGX';
            }
        }
        return $ctx;
    }

    private function _equipment_options() {
        if (!$this->db->table_exists('equipment')) return array();
        return $this->db->select('id, registration_no')->from('equipment')
                        ->order_by('registration_no')->get()->result();
    }

    private function _supplier_options() {
        if (!$this->db->table_exists('suppliers')) return array();
        return $this->db->select('company_name')->from('suppliers')
                        ->order_by('company_name')->get()->result();
    }

    /** Build a row object out of submitted form values (so nothing is lost). */
    private function _form_item($post, $base) {
        $pick = function($k) use ($post, $base) {
            if (isset($post[$k]) && $post[$k] !== '') return $post[$k];
            if ($base !== null && isset($base->$k)) return $base->$k;
            return '';
        };
        return (object)array(
            'id'               => $base !== null ? $base->id : null,
            'item_code'        => $pick('item_code'),
            'category'         => $pick('category'),
            'description'      => $pick('description'),
            'part_number_size' => $pick('part_number_size'),
            'unit'             => $pick('unit'),
            'initial_stock'    => $pick('initial_stock'),
            'qty_received'     => $pick('qty_received'),
            'qty_sortie'       => $pick('qty_sortie'),
            'application'      => $pick('application'),
            'location'         => $pick('location'),
            'min_stock'        => $pick('min_stock'),
            'max_stock'        => $pick('max_stock'),
            'unit_price_ugx'   => $pick('unit_price_ugx'),
            'supplier_name'    => $pick('supplier_name'),
            'final_qty'        => $base !== null ? $base->final_qty : null,
        );
    }

    /**
     * Re-order / pricing values accepted by the item form.
     *
     * They come straight from Inventory_importer::validate_manual(), so the
     * form and the Excel import apply exactly the same rules, and they are
     * only written when the extended columns exist.
     */
    private function _extended_values($v) {
        if (!$this->Materials_model->has_extended_fields()) return array();
        return array(
            'min_stock'      => isset($v['min_stock'])      ? $v['min_stock']      : NULL,
            'max_stock'      => isset($v['max_stock'])      ? $v['max_stock']      : NULL,
            'unit_price_ugx' => isset($v['unit_price_ugx']) ? $v['unit_price_ugx'] : NULL,
            'supplier_name'  => (isset($v['supplier_name']) && $v['supplier_name'] !== '')
                                    ? $v['supplier_name'] : NULL,
        );
    }

    /**
     * Whole-number quantity from a form field.  The stock columns are INT, so a
     * decimal would be silently rounded by the database — reject it instead.
     * @return int (falls back to $default when the field is empty)
     */
    private function _whole_qty($raw, $label, &$errors, $default = null, $min = 0) {
        $raw = trim((string)$raw);
        if ($raw === '') return $default === null ? 0 : (int)$default;
        $this->load->library('inventory_importer', array('model' => $this->Materials_model));
        $n = $this->inventory_importer->parse_number($raw);
        if ($n === null) {
            $errors[] = $label.' "'.$raw.'" is not a valid number.';
            return $default === null ? 0 : (int)$default;
        }
        if (abs($n - round($n)) > 0.0000001) {
            $errors[] = $label.' must be a whole number (the stock columns store whole units).';
            return $default === null ? 0 : (int)$default;
        }
        $n = (int)round($n);
        if ($n < $min) {
            $errors[] = $label.' must be '.($min > 0 ? 'at least '.$min : 'zero or more').'.';
            return $default === null ? 0 : (int)$default;
        }
        return $n;
    }

    /** Log every stock change made through the item form as a movement. */
    private function _log_stock_changes($old, $id, $newInit, $newRecv, $newIss) {
        $changes = array(
            array('field' => 'initial_stock', 'label' => 'Opening stock',  'old' => (int)$old->initial_stock, 'new' => $newInit),
            array('field' => 'qty_received',  'label' => 'Total received', 'old' => (int)$old->qty_received,  'new' => $newRecv),
            array('field' => 'qty_sortie',    'label' => 'Total issued',   'old' => (int)$old->qty_sortie,    'new' => $newIss),
        );
        $running = (int)$old->initial_stock + (int)$old->qty_received - (int)$old->qty_sortie;
        foreach ($changes as $c) {
            $delta = (int)$c['new'] - (int)$c['old'];
            if ($delta === 0) continue;
            $signed = ($c['field'] === 'qty_sortie') ? -$delta : $delta;
            $this->Materials_model->log_transaction($id,
                $signed > 0 ? 'RECEIVE' : 'ISSUE',
                abs($signed), $running,
                $c['label'].' adjusted via item edit', $this->user_id);
            $running += $signed;
        }
    }

    /** Human readable description of the export filter (used in the workbook). */
    private function _filter_note($filter) {
        $parts = array();
        if (!empty($filter['category'])) $parts[] = 'category = '.$filter['category'];
        if (!empty($filter['location'])) $parts[] = 'location = '.$filter['location'];
        if (!empty($filter['search']))   $parts[] = 'search = '.$filter['search'];
        return implode(', ', $parts);
    }

    /* ── Import helpers ────────────────────────────────── */

    private function _clean_upload_error($msg) {
        $msg = trim(strip_tags((string)$msg));
        $msg = str_replace(array('The filetype you are attempting to upload is not allowed.',
                                 'The uploaded file exceeds the maximum allowed size.'), '', $msg);
        return trim($msg) !== '' ? trim($msg) : 'the file could not be uploaded. Save the workbook as .xlsx and try again.';
    }

    private function _tmp_path($fname) {
        $dir = FCPATH.self::IMPORT_TMP;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir.$fname;
    }

    /** Send a generated file to the browser and remove it from the server. */
    private function _send_file($path, $downloadName) {
        if (!file_exists($path)) {
            show_error('Could not generate the Excel file. Check that uploads/tmp/ is writable.', 500);
            return;
        }
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$downloadName.'"');
        header('Content-Length: '.filesize($path));
        header('Cache-Control: must-revalidate, max-age=0');
        header('Pragma: public');
        readfile($path);
        @unlink($path);
        exit;
    }

    private function _new_import_token() {
        return function_exists('random_bytes')
             ? bin2hex(random_bytes(8))
             : substr(md5(uniqid('wms_inventory_import', true)), 0, 16);
    }

    /**
     * Park the full import result in a temp file: the session only carries a
     * token + summary, so large imports cannot blow up the session store.
     */
    private function _park_import_summary($result, $token = null) {
        if ($token === null) $token = $this->_new_import_token();
        $this->_prune_import_reports();
        $payload = array(
            'user_id' => (int)$this->user_id,
            'created' => time(),
            'result'  => $result,
        );
        file_put_contents($this->_tmp_path('inventory_import_'.$token.'.json'), json_encode($payload));
        $this->session->set_flashdata('inventory_import_token', $token);
        return $token;
    }

    private function _read_import_report($token) {
        if (!preg_match('/^[a-f0-9]{16}$/', (string)$token)) return null;
        $file = $this->_tmp_path('inventory_import_'.$token.'.json');
        if (!is_file($file)) return null;
        $payload = json_decode(file_get_contents($file), true);
        if (!is_array($payload) || !isset($payload['result'])) return null;
        if ((int)$payload['user_id'] !== (int)$this->user_id) return null;
        if ($payload['created'] < time() - 86400) { @unlink($file); return null; }
        return $payload['result'];
    }

    /** Summary of the last import (token + counters) for the inventory page. */
    private function _load_import_summary() {
        $token = $this->session->flashdata('inventory_import_token');
        if (!$token) return null;
        $result = $this->_read_import_report($token);
        if ($result === null) return null;
        $result['report_token'] = $token;
        return $result;
    }

    /** Remove report files older than 24h (best effort housekeeping). */
    private function _prune_import_reports() {
        foreach (glob($this->_tmp_path('inventory_import_*.json')) as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) @unlink($f);
        }
    }
}
